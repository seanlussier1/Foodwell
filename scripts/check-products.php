<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$host = getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SCHEMA_TEST_PORT') ?: '3306';
$user = getenv('SCHEMA_TEST_USER') ?: 'root';
$pass = getenv('SCHEMA_TEST_PASS') ?: '';

$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$databases = [];
$exitCode = 0;
$servers = [];
$temporaryFiles = [];
$checks = 0;

/** Stops at the first failure so the message points at the exact problem. */
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException("FAIL: $message");
    }
    ++$checks;
    echo "PASS: $message\n";
}

/**
 * One HTTP request to a temporary server.
 * @return array{0:int,1:mixed,2:string,3:array<string,string>} [status, decoded JSON, raw body, headers]
 */
function api(int $port, string $method, string $path, ?string $body = null, array $headers = []): array
{
    $headers += ['Accept' => 'application/json'];
    if ($body !== null) {
        $headers += ['Content-Type' => 'application/json'];
    }
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "$name: $value";
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => implode("\r\n", $lines), 'content' => $body ?? '',
    ]]);
    $url = str_starts_with($path, '/api/') ? "http://127.0.0.1:$port$path" : "http://127.0.0.1:$port/api/v1$path";
    $raw = (string) file_get_contents($url, false, $context);

    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    $responseHeaders = [];
    foreach (array_slice($http_response_header, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $responseHeaders[strtolower(trim($name))] = trim($value);
    }
    return [(int) $match[1], json_decode($raw, true), $raw, $responseHeaders];
}

function ids(array $list): array
{
    return array_column($list['data'], 'id');
}

/** Creates a disposable database (seeded or empty) and starts an API server on it. Returns the port. */
function startApi(PDO $pdo, string $label, bool $seed): array
{
    global $root, $host, $port, $user, $pass, $databases, $servers, $temporaryFiles;

    $db = "foodwell_products_{$label}_" . bin2hex(random_bytes(4));
    $pdo->exec("CREATE DATABASE `$db`");
    $databases[] = $db;
    $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents("$root/database/schema.sql"));
    if ($seed) {
        $pdo->exec(file_get_contents("$root/database/seed.sql"));
    }

    // Free port: let the OS pick one, then release it for the server.
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $serverPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $router = tempnam(sys_get_temp_dir(), 'products-router-');
    $temporaryFiles[] = $router;

    file_put_contents($router, '<?php ' . ($pass === '' ? 'putenv("DB_PASS=");' : '')
        . ' return require ' . var_export("$root/public/index.php", true) . ';');
    $log = tempnam(sys_get_temp_dir(), 'products-log-');
    $temporaryFiles[] = $log;
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $env = array_merge(getenv(), [
        'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $db, 'DB_USER' => $user, 'DB_PASS' => $pass,
        'APP_BASE_PATH' => '', 'CORS_ALLOWED_ORIGINS' => 'http://localhost:8081',
    ]);
    $server = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', "error_log=$log", '-S', "127.0.0.1:$serverPort", '-t', "$root/public", $router],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $log, 'a']],
        $pipes, $root, $env
    );
    $servers[] = $server;

    for ($i = 0; $i < 50; $i++) {
        $probe = @fsockopen('127.0.0.1', $serverPort);
        if ($probe) {
            fclose($probe);
            return [$serverPort, $db];
        }
        usleep(100000);
    }
    throw new RuntimeException("Temporary $label API did not start.");
}

// -------------------------------------------------------------------- checks
try {
    [$emptyPort] = startApi($pdo, 'empty', false);
    [$apiPort, $db] = startApi($pdo, 'seeded', true);   // leaves $pdo using the seeded database
    check(true, 'Two disposable databases and API servers started');

    $countProducts = fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();

    // ===================================================== Build 1 continuity
    echo "\n-- Build 1 continuity\n";

    [$status, $item, , $headers] = api($apiPort, 'GET', '/products/101');
    check($status === 200 && str_starts_with($headers['content-type'], 'application/json'), 'GET item 101 returns 200 JSON');
    $row = $pdo->query('SELECT name, price, stock FROM products WHERE id = 101')->fetch();
    check($item['id'] === 101 && $item['name'] === $row['name'] && $item['price'] === (float) $row['price']
        && $item['stock'] === (int) $row['stock'], 'Item response matches its database row (not hard-coded)');

    [$status, $body] = api($apiPort, 'GET', '/products/999999');
    check($status === 404 && $body['error']['status'] === 404 && $body['error']['message'] === 'Product 999999 not found.',
        'Unknown item returns 404 with the standard error body');

    [$status, $list] = api($apiPort, 'GET', '/products');
    check($status === 200 && ids($list) === [101, 102, 103, 104, 105, 106, 107, 108, 109, 110],
        'GET list returns all 10 seeded products in Build 1 (ID) order');
    check($list['data'][0] === $item, 'First list entry equals the item response');

    [$status, $empty] = api($emptyPort, 'GET', '/products');
    check($status === 200 && $empty['data'] === [] && $empty['meta']['total'] === 0 && $empty['meta']['totalPages'] === 0
        && $empty['meta']['next'] === null && $empty['meta']['previous'] === null, 'Empty database: 200 with empty data in the same envelope');

    // ============================================== #29 collection controls
    echo "\n-- #29 filters, sorting, pagination\n";

    check(ids(api($apiPort, 'GET', '/products?category=2')[1]) === [101, 102, 103], 'category filter returns only that category');
    check(ids(api($apiPort, 'GET', '/products?search=MILK')[1]) === [101], 'search is case-insensitive');
    check(ids(api($apiPort, 'GET', '/products?category=2&search=ch')[1]) === [102], 'Filters combine with AND');
    check(api($apiPort, 'GET', '/products?search=%25')[1]['meta']['total'] === 0, 'A % in search is matched literally, not as a wildcard');
    check(api($apiPort, 'GET', '/products?search=%20%20%20')[1]['meta']['total'] === 10, 'A blank search means no search');
    check(api($apiPort, 'GET', '/products?category=999')[0] === 200 && api($apiPort, 'GET', '/products?category=999')[1]['data'] === [],
        'A category with no products returns 200 and an empty page');

    $prices = array_column(api($apiPort, 'GET', '/products?sort=-price')[1]['data'], 'price');
    $sorted = $prices;
    rsort($sorted);
    check($prices === $sorted, 'sort=-price orders by price descending');
    $names = array_column(api($apiPort, 'GET', '/products?sort=name')[1]['data'], 'name');
    $sortedNames = $names;
    sort($sortedNames, SORT_STRING | SORT_FLAG_CASE);
    check($names === $sortedNames, 'sort=name orders by name ascending');

    // Tie-breaker: two more products with the same price as 101 ($4.99).
    $pdo->exec("INSERT INTO products (id, name, price, unit, stock, category_id)
                VALUES (201, 'Tie Test A', 4.99, 'each', 1, 2), (202, 'Tie Test B', 4.99, 'each', 1, 2)");
    $walk = [];
    for ($page = 1; $page <= 12; $page++) {
        $walk[] = ids(api($apiPort, 'GET', "/products?sort=price&limit=1&page=$page")[1])[0];
    }
    $again = [];
    for ($page = 1; $page <= 12; $page++) {
        $again[] = ids(api($apiPort, 'GET', "/products?sort=price&limit=1&page=$page")[1])[0];
    }
    check(count(array_unique($walk)) === 12, 'Walking every page shows each product exactly once (equal prices included)');
    check($walk === $again, 'Equal prices keep the same order on every request (ID tie-breaker)');
    $tied = array_values(array_filter($walk, fn($id) => in_array($id, [101, 201, 202], true)));
    check($tied === [101, 201, 202], 'Products with equal prices are ordered by ID');
    $pdo->exec('DELETE FROM products WHERE id IN (201, 202)');

    [, $page2] = api($apiPort, 'GET', '/products?limit=3&page=2');
    check(ids($page2) === [104, 105, 106] && $page2['meta']['page'] === 2 && $page2['meta']['totalPages'] === 4
        && $page2['meta']['total'] === 10, 'limit/page select the right rows and metadata');

    [, $filtered] = api($apiPort, 'GET', '/products?category=2&search=e&sort=price&limit=1&page=1');
    $next = $filtered['meta']['next'];
    check($next === '/api/v1/products?category=2&search=e&sort=price&page=2&limit=1', 'next link keeps the filters, sort and limit');
    [$status, $followed] = api($apiPort, 'GET', $next);
    check($status === 200 && $followed['meta']['page'] === 2
        && $followed['meta']['previous'] === '/api/v1/products?category=2&search=e&sort=price&page=1&limit=1',
        'Following next works and its previous link returns to page 1');
    check($followed['meta']['next'] === null, 'next is null on the last page');
    check(api($apiPort, 'GET', '/products')[1]['meta']['previous'] === null, 'previous is null on page 1');

    [$status, $beyond] = api($apiPort, 'GET', '/products?limit=4&page=9');
    check($status === 200 && $beyond['data'] === [] && $beyond['meta']['previous'] === '/api/v1/products?page=3&limit=4',
        'A page past the end is 200, empty, with previous pointing to the real last page');

    foreach ([
        'page=0' => 'page', 'page=1.5' => 'page', 'limit=0' => 'limit', 'limit=101' => 'limit',
        'category=abc' => 'category', 'sort=stock' => 'sort', 'page%5B%5D=1' => 'page',
    ] as $query => $parameter) {
        [$status, $body] = api($apiPort, 'GET', "/products?$query");
        check($status === 400 && str_contains($body['error']['message'], $parameter), "Invalid $query returns 400 naming $parameter");
    }
    [$status, $body] = api($apiPort, 'GET', '/products?page=0&limit=500');
    check($status === 400 && str_contains($body['error']['message'], 'page') && str_contains($body['error']['message'], 'limit'),
        'Several invalid parameters are reported in one 400');
    check(api($apiPort, 'GET', '/products?sort=' . rawurlencode('price; DROP TABLE products'))[0] === 400 && $countProducts() === 10,
        'SQL in sort is rejected by the allowlist; the table is untouched');
    check(api($apiPort, 'GET', '/products?limit=100&unknown=1')[0] === 200, 'limit=100 is accepted and unknown parameters are ignored');

    // ======================================================= #27 writes
    echo "\n-- #27 create, update, delete\n";

    [$status, $created, , $headers] = api($apiPort, 'POST', '/products',
        '{"name":"  Oat Milk 1L ","price":3.49,"unit":"each","stock":20,"categoryId":2}');
    $newId = $created['id'] ?? 0;
    check($status === 201 && $headers['location'] === "/api/v1/products/$newId", 'POST returns 201 with a Location header');
    check($created['name'] === 'Oat Milk 1L' && $created['categoryName'] === 'Dairy' && $created['isActive'] === true,
        'POST returns the saved product (name trimmed, category joined)');
    check(api($apiPort, 'GET', "/products/$newId")[1] === $created, 'API read-back equals the POST response');
    $saved = $pdo->query("SELECT name, price, stock, category_id, is_active FROM products WHERE id = $newId")->fetch();
    check($saved === ['name' => 'Oat Milk 1L', 'price' => '3.49', 'stock' => 20, 'category_id' => 2, 'is_active' => 1],
        'Database row matches the created product');

    [$status, $minimal] = api($apiPort, 'POST', '/products', '{"name":"Free Sample","price":0,"categoryId":4}');
    check($status === 201 && $minimal['unit'] === 'each' && $minimal['stock'] === 0 && $minimal['imageUrl'] === null,
        'POST applies defaults (unit each, stock 0, no image) and accepts price 0');

    $before = $countProducts();
    [$status, $body] = api($apiPort, 'POST', '/products',
        '{"name":"","price":-1,"unit":"","stock":-5,"categoryId":99,"imageUrl":"ftp://x","color":"red"}');
    $fields = array_keys($body['error']['details'] ?? []);
    sort($fields);
    check($status === 422 && $body['error']['message'] === 'Validation failed.'
        && $fields === ['categoryId', 'color', 'imageUrl', 'name', 'price', 'stock', 'unit'],
        'POST with 7 problems returns one 422 listing every field');
    check($body['error']['details']['categoryId'] === 'This category does not exist.', 'Unknown category is a 422 field error');
    [$status, $body] = api($apiPort, 'POST', '/products', '{"name":5,"price":"4.99","stock":1.5,"categoryId":"2"}');
    check($status === 422 && count($body['error']['details']) === 4, 'Wrong JSON types are rejected (price as text, stock 1.5)');
    check(api($apiPort, 'POST', '/products', '{"name":"X","price":1.999,"categoryId":1}')[1]['error']['details']['price']
        === 'Use at most 2 decimals.', 'Prices with 3 decimals are rejected');
    check(api($apiPort, 'POST', '/products', '{"name":')[0] === 400, 'Malformed JSON returns 400');
    check(api($apiPort, 'POST', '/products', '[1,2]')[0] === 400, 'A JSON array body returns 400');
    check(api($apiPort, 'POST', '/products', '{"name":"X"}', ['Content-Type' => 'text/plain'])[0] === 415, 'A non-JSON Content-Type returns 415');
    check($countProducts() === $before, 'Rejected POSTs saved nothing');

    [$status, $updated] = api($apiPort, 'PUT', '/products/104',
        '{"name":"Gala Apples","price":3.19,"unit":"lb","categoryId":1,"imageUrl":null}');
    check($status === 200 && $updated['price'] === 3.19 && $updated['imageUrl'] === null, 'PUT returns 200 with the saved product');
    check($pdo->query('SELECT price, stock FROM products WHERE id = 104')->fetch() === ['price' => '3.19', 'stock' => 120],
        'Database has the new price and the stock is unchanged');

    $snapshot = $pdo->query('SELECT * FROM products WHERE id = 104')->fetch();
    [$status, $body] = api($apiPort, 'PUT', '/products/104', '{"name":"Gala Apples","price":3.19,"categoryId":1,"stock":5}');
    check($status === 422 && str_contains($body['error']['details']['stock'], 'inventory adjustment'), 'PUT rejects stock (422)');
    check(api($apiPort, 'PUT', '/products/104', '{"name":"Changed","price":9,"categoryId":42}')[0] === 422,
        'PUT with an unknown category returns 422');
    check($pdo->query('SELECT * FROM products WHERE id = 104')->fetch() === $snapshot, 'Rejected PUTs changed nothing');

    check(api($apiPort, 'PUT', '/products/109', '{"name":"Basmati Rice 2kg","price":11.99,"categoryId":4,"isActive":false}')[1]['isActive']
        === false, 'PUT can deactivate a product');
    check(api($apiPort, 'PUT', '/products/109', '{"name":"Basmati Rice 2kg","price":12.49,"categoryId":4}')[1]['isActive'] === false,
        'Omitting isActive keeps the current status');
    check(api($apiPort, 'PUT', '/products/9999', '{}')[0] === 404, 'PUT on an unknown product returns 404');

    [$status, $body] = api($apiPort, 'DELETE', '/products/109');
    check($status === 409 && str_contains($body['error']['message'], 'inventory history'), 'DELETE of a product with stock history returns 409');
    check((int) $pdo->query('SELECT COUNT(*) FROM products WHERE id = 109')->fetchColumn() === 1, 'The 409 product still exists');

    check((int) $pdo->query('SELECT COUNT(*) FROM cart_items WHERE product_id = 101')->fetchColumn() > 0, 'Product 101 is in a cart before deleting');
    [$status, , $raw] = api($apiPort, 'DELETE', '/products/101');
    check($status === 204 && $raw === '', 'DELETE returns 204 with no body');
    check((int) $pdo->query('SELECT COUNT(*) FROM products WHERE id = 101')->fetchColumn() === 0, 'The product row is gone');
    check((int) $pdo->query('SELECT COUNT(*) FROM cart_items WHERE product_id = 101')->fetchColumn() === 0, 'Its cart lines were removed (CASCADE)');
    check($pdo->query('SELECT product_id, product_name FROM order_items WHERE id = 1001')->fetch()
        === ['product_id' => null, 'product_name' => 'Whole Milk 2L'], 'Past order lines keep their snapshot (SET NULL)');
    check(api($apiPort, 'DELETE', '/products/101')[0] === 404, 'Deleting again returns 404');
    check(api($apiPort, 'PATCH', '/products/102', '{}')[0] === 405, 'PATCH on a product returns 405');

    // ================================================ #33 representations
    echo "\n-- #33 formats and negotiation\n";

    [, $json] = api($apiPort, 'GET', '/products/102');
    [$status, , $xmlRaw, $headers] = api($apiPort, 'GET', '/products/102', null, ['Accept' => 'application/xml']);
    check($status === 200 && str_starts_with($headers['content-type'], 'application/xml'), 'Accept application/xml returns XML');
    check(str_contains($headers['vary'] ?? '', 'Accept'), 'Negotiated responses send Vary: Accept');
    $xml = simplexml_load_string($xmlRaw);
    $fromXml = [];
    foreach ($xml->children() as $name => $value) {
        $fromXml[$name] = (string) $value;
    }
    $asText = array_map(fn($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $json);
    $asText['imageUrl'] = (string) $json['imageUrl'];
    check($xml->getName() === 'product' && $fromXml == $asText, 'XML carries the same fields and values as JSON');

    [$status, , $yaml, $headers] = api($apiPort, 'GET', '/products/102', null, ['Accept' => 'application/yaml']);
    check($status === 200 && str_starts_with($headers['content-type'], 'application/yaml'), 'Accept application/yaml returns YAML');
    check(str_contains($yaml, 'id: 102') && str_contains($yaml, 'name: "Old Cheddar 400g"') && str_contains($yaml, 'isActive: true'),
        'YAML carries the same values (strings quoted)');

    [, , $listXml] = api($apiPort, 'GET', '/products?limit=2', null, ['Accept' => 'application/xml']);
    $listDoc = simplexml_load_string($listXml);
    $jsonTotal = api($apiPort, 'GET', '/products?limit=2')[1]['meta']['total'];
    check($listDoc->getName() === 'products' && count($listDoc->data->product) === 2 && (string) $listDoc->meta->total === (string) $jsonTotal,
        'The list in XML is <products> with meta and <product> items');

    $content = fn(string $accept): string => api($apiPort, 'GET', '/products/102', null, ['Accept' => $accept])[3]['content-type'] ?? '';
    check(str_starts_with(api($apiPort, 'GET', '/products/102', null, ['Accept' => ''])[3]['content-type'], 'application/json'),
        'No Accept preference gives JSON');
    check(str_starts_with($content('text/xml'), 'application/xml') && str_starts_with($content('application/x-yaml'), 'application/yaml'),
        'Aliases text/xml and application/x-yaml are understood');
    check(str_starts_with($content('application/xml;q=0.5, application/yaml;q=0.9'), 'application/yaml'), 'The highest q-value wins');
    check(str_starts_with($content('application/json;q=0, */*'), 'application/xml'), 'q=0 excludes a format');

    [$status, $body] = api($apiPort, 'GET', '/products/102', null, ['Accept' => 'text/html']);
    check($status === 406 && str_contains($body['error']['message'], 'application/yaml'), 'Unsupported Accept returns 406 listing the formats');
    check(api($apiPort, 'POST', '/products', '{}', ['Accept' => 'application/xml'])[0] === 406, 'Writes are JSON-only: POST with Accept XML is 406');
    check(api($apiPort, 'GET', '/customers', null, ['Accept' => 'application/xml'])[0] === 406, 'JSON-only routes (customers) return 406 for XML');
    check(api($apiPort, 'GET', '/nope', null, ['Accept' => 'application/xml'])[0] === 404, 'Unknown route stays 404 whatever Accept says');
    [$status, , $errorXml] = api($apiPort, 'GET', '/products/999999', null, ['Accept' => 'application/xml']);
    check($status === 404 && (string) simplexml_load_string($errorXml)->message === 'Product 999999 not found.',
        'Errors use the negotiated format (404 as XML)');

    echo "\n$checks product checks passed. Application database was not used.\n";
} catch (Throwable $e) {
    echo "\n" . $e->getMessage() . "\n";
    $exitCode = 1;  // exit() inside catch would skip the cleanup in finally
} finally {
    foreach ($servers as $server) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ($databases as $database) {
        $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    }
    foreach ($temporaryFiles as $file) {
        @unlink($file);
    }
}
exit($exitCode);
