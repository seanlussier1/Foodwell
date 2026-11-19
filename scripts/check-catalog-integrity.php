<?php
declare(strict_types=1);

/**
 * Catalog failure and integrity checks (#41, B3-3).
 *
 *   php scripts/check-catalog-integrity.php
 *
 * Where check-products.php and check-catalog-authorization.php test that the
 * catalog WORKS, this script tests what happens when things go WRONG:
 *
 *   1. Bad list queries: wrong page/limit/sort/category/search values,
 *      repeated keys, unknown keys                                   -> 400
 *   2. Paging: walking every page (with tied prices and names) returns each
 *      product exactly once, in the right order, with correct links
 *   3. Broken request bodies: bad JSON, arrays, wrong Content-Type    -> 400 / 415
 *   4. Invalid fields on products and categories                     -> 422
 *   5. Media types and methods: unsupported Accept, wrong method      -> 406 / 405
 *   6. Foreign keys: a missing category, a category still in use, a
 *      product with stock history, and "race" versions of each where
 *      the data changes between our check and our write             -> 422 / 409
 *   7. A real server error: the client gets a 500 with a request id,
 *      the log has the same id, and nothing secret is logged
 *
 * After EVERY refused write, the script compares the catalog tables with a
 * copy taken before the request, to prove nothing was changed.
 *
 * Like the other check scripts, it builds a throwaway copy of the database,
 * starts its own API server, runs everything, then deletes it all. Your real
 * foodwell database is never touched. It uses the same SCHEMA_TEST_HOST /
 * PORT / USER / PASS settings as the other scripts.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$host = getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SCHEMA_TEST_PORT') ?: '3306';
$user = getenv('SCHEMA_TEST_USER') ?: 'root';
$pass = getenv('SCHEMA_TEST_PASS') ?: '';

$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$db = 'foodwell_catalog_integrity_' . bin2hex(random_bytes(4));
$server = null;
$log = null;
$router = null;
$exitCode = 0;
$checks = 0;
$staff = null;          // staff login token, set once the database exists
$errorsSeen = 0;        // how many error responses we checked the request id of

/** Prints PASS, or stops the whole script at the first failure. */
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
 * Sends one HTTP request to the temporary API server.
 *
 * $body can be an array (sent as JSON) or a string (sent exactly as given,
 * so we can send broken JSON on purpose). Writes are sent as the staff
 * account unless $headers sets its own Authorization.
 *
 * Every error response (400 and up) is also checked here: its JSON body
 * must carry a requestId equal to the X-Request-ID header. That way the
 * "request-correlated" rule is checked on every failure in the script.
 *
 * @return array{0:int,1:mixed,2:array<string,string>,3:string} status, decoded JSON, headers, raw body
 */
function api(string $method, string $path, array|string|null $body = null, array $headers = []): array
{
    global $serverPort, $staff, $errorsSeen;
    $headers += ['Accept' => 'application/json'];
    if ($body !== null) {
        $headers += ['Content-Type' => 'application/json'];
    }
    if ($method !== 'GET' && $staff !== null) {
        $headers += ['Authorization' => "Bearer $staff"];
    }
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "$name: $value";
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => implode("\r\n", $lines),
        'content' => is_array($body) ? json_encode($body) : (string) $body,
    ]]);
    $raw = (string) file_get_contents("http://127.0.0.1:$serverPort/api/v1$path", false, $context);

    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    $status = (int) $match[1];
    $responseHeaders = [];
    foreach (array_slice($http_response_header, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $responseHeaders[strtolower(trim($name))] = trim($value);
    }
    $json = json_decode($raw, true);

    // Request correlation: every JSON error must repeat the X-Request-ID header.
    if ($status >= 400 && is_array($json)) {
        $id = $responseHeaders['x-request-id'] ?? '';
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1 || ($json['error']['requestId'] ?? null) !== $id) {
            throw new RuntimeException("FAIL: $method $path error body requestId does not match X-Request-ID");
        }
        ++$errorsSeen;
    }
    return [$status, $json, $responseHeaders, $raw];
}

/**
 * A copy of every table a catalog write could touch. Comparing two copies
 * proves a refused request changed nothing at all.
 */
function snapshot(PDO $pdo): array
{
    $copy = [];
    foreach (['products', 'categories', 'inventory_adjustments', 'cart_items', 'order_items'] as $table) {
        $copy[$table] = $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll();
    }
    return $copy;
}

/**
 * Sends a request that should be refused and checks:
 *   - the status code is $expected
 *   - the error message contains $message (when given)
 *   - the database is exactly the same afterwards
 * Returns the decoded JSON so the caller can check more details.
 */
function refused(PDO $pdo, int $expected, string $method, string $path, array|string|null $body, string $label,
                 ?string $message = null, array $headers = []): ?array
{
    $before = snapshot($pdo);
    [$status, $json] = api($method, $path, $body, $headers);
    check($status === $expected && ($message === null || str_contains($json['error']['message'] ?? '', $message)),
        "$label -> $expected" . ($status === $expected ? '' : " (got $status)"));
    check(snapshot($pdo) === $before, '...and the database is unchanged');
    return $json;
}

/** Follows the "next" links from page 1 and returns every product id seen, page by page. */
function walkPages(string $firstPath): array
{
    $pages = [];
    $path = $firstPath;
    $guard = 0;
    while ($path !== null && ++$guard < 100) {
        [$status, $body] = api('GET', $path);
        if ($status !== 200) {
            throw new RuntimeException("FAIL: GET $path returned $status while paging");
        }
        $pages[] = ['meta' => $body['meta'], 'ids' => array_column($body['data'], 'id')];
        // The links start with /api/v1 (or a subfolder); our api() adds /api/v1 itself.
        $path = $body['meta']['next'] === null ? null : substr($body['meta']['next'], strlen('/api/v1'));
    }
    return $pages;
}

try {
    // --------------------------------------------------------- set-up
    // 1) A fresh database with the normal demo data.
    $pdo->exec("CREATE DATABASE `$db`");
    $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents("$root/database/schema.sql"));
    $pdo->exec(file_get_contents("$root/database/seed.sql"));

    // 2) Extra products with TIED prices and names. Ties are where paging
    //    goes wrong: without a tie-breaker in ORDER BY, MySQL may return
    //    tied rows in a different order on each page, so a product can show
    //    up twice or never. Here, 3 products cost 2.99 (Gala Apples is
    //    already 2.99) and 2 share the name "Gala Apples".
    $pdo->exec("INSERT INTO products (id, name, price, unit, stock, category_id) VALUES
        (111, 'Gala Apples', 2.99, 'lb', 5, 1),
        (112, 'Pears', 2.99, 'lb', 5, 1),
        (113, '100% Juice', 3.25, 'each', 5, 4),
        (114, 'snake_case Snacks', 2.99, 'each', 5, 4)");

    // 3) Start the API on a free port, with PHP errors going to $log so we
    //    can read the log afterwards.
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $serverPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $router = tempnam(sys_get_temp_dir(), 'catalog-integrity-router-');
    file_put_contents($router, '<?php ' . ($pass === '' ? 'putenv("DB_PASS=");' : '')
        . ' return require ' . var_export("$root/public/index.php", true) . ';');
    $log = tempnam(sys_get_temp_dir(), 'catalog-integrity-log-');
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $server = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$log",
            '-S', "127.0.0.1:$serverPort", '-t', "$root/public", $router],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $log, 'a']],
        $pipes, $root,
        array_merge(getenv(), ['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $db, 'DB_USER' => $user,
            'DB_PASS' => $pass, 'APP_BASE_PATH' => ''])
    );
    $ready = false;
    for ($i = 0; $i < 50 && !$ready; $i++) {
        $probe = @fsockopen('127.0.0.1', $serverPort);
        if ($probe) { fclose($probe); $ready = true; } else { usleep(100000); }
    }
    check($ready, 'Temporary database and API server started');

    // 4) A staff login (account 9 in the seed). Only its fingerprint is stored.
    $staff = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO auth_sessions (customer_id, token_hash, expires_at) VALUES (9, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR)')
        ->execute([hash('sha256', $staff)]);

    // ===================================================== 1. bad list queries
    echo "\n-- 1. Bad list queries get 400 with a clear reason\n";

    $badQueries = [
        'page=0' => 'page must be', 'page=-1' => 'page must be', 'page=abc' => 'page must be',
        'page=1.5' => 'page must be', 'page=100001' => 'page must be', 'page=%00' => 'page must be',
        'limit=0' => 'limit must be', 'limit=101' => 'limit must be', 'limit=ten' => 'limit must be',
        'category=0' => 'category must be', 'category=abc' => 'category must be', 'category=4294967296' => 'category must be',
        'sort=stock' => 'sort must be', 'sort=PRICE' => 'sort must be', 'sort=price,name' => 'sort must be',
        'sort[]=price' => 'sort must be',
        'search=' . str_repeat('a', 101) => 'search must be at most 100',
        'search[]=milk' => 'search must be text',
        // Repeated keys: PHP would quietly keep only the last value.
        'sort=price&sort=name' => 'sort was given 2 times',
        'page=1&page=2' => 'page was given 2 times',
        'sort[]=price&sort[]=name' => 'sort was given 2 times',
        // Unknown or misspelled keys would be silently ignored.
        'Sort=price' => 'Unknown query parameter "Sort"',
        'foo=bar' => 'Unknown query parameter "foo"',
        'pagee=2' => 'Unknown query parameter "pagee"',
    ];
    foreach ($badQueries as $query => $reason) {
        [$status, $json] = api('GET', "/products?$query");
        check($status === 400 && str_contains($json['error']['message'], $reason),
            "GET /products?" . (strlen($query) > 40 ? substr($query, 0, 37) . '...' : $query) . " -> 400 ($reason)");
    }

    // Things that look odd but are fine.
    foreach (['page=1&limit=100', 'search=&sort=', 'page=1&&limit=5', 'category=999'] as $query) {
        check(api('GET', "/products?$query")[0] === 200, "GET /products?$query is allowed (200)");
    }
    check(api('GET', '/products?category=999')[1]['meta']['total'] === 0, 'A category with no products gives an empty list, not an error');

    // % and _ are wildcards inside SQL LIKE. The search must treat them as
    // normal characters, or "%" would match every product.
    [, $json] = api('GET', '/products?search=%25');
    check(array_column($json['data'], 'id') === [113], 'search=% finds only "100% Juice" (% is not a wildcard)');
    [, $json] = api('GET', '/products?search=_');
    check(array_column($json['data'], 'id') === [114], 'search=_ finds only "snake_case Snacks" (_ is not a wildcard)');
    [, $json] = api('GET', '/products?search=' . rawurlencode("' OR 1=1 --"));
    check($json['meta']['total'] === 0, 'A SQL-injection-looking search is just text (0 matches, no error)');

    // ===================================================== 2. paging
    echo "\n-- 2. Paging returns every product exactly once, in order\n";

    // The expected order, worked out directly in SQL with the same tie-breaker
    // (id) the API should use. The API's pages must add up to exactly this.
    $orders = [
        '' => 'id ASC',
        'sort=name' => 'name ASC, id ASC', 'sort=-name' => 'name DESC, id DESC',
        'sort=price' => 'price ASC, id ASC', 'sort=-price' => 'price DESC, id DESC',
    ];
    foreach ($orders as $sort => $orderBy) {
        $expected = array_map('intval', $pdo->query("SELECT id FROM products ORDER BY $orderBy")->fetchAll(PDO::FETCH_COLUMN));
        $pages = walkPages('/products?' . ($sort === '' ? '' : "$sort&") . 'limit=3');
        $seen = array_merge(...array_column($pages, 'ids'));
        $label = $sort === '' ? 'default order' : $sort;

        check($seen === $expected, "$label, 3 per page: every product exactly once, in the right order");
        check(count($pages) === (int) ceil(count($expected) / 3) && $pages[0]['meta']['totalPages'] === count($pages),
            "$label: totalPages matches the number of pages walked (" . count($pages) . ')');
        check(end($pages)['meta']['next'] === null && $pages[0]['meta']['previous'] === null,
            "$label: first page has no previous link, last page has no next link");
    }

    // Filters keep working across pages, and the links keep the filters.
    $pages = walkPages('/products?category=1&sort=price&limit=2');
    $expected = array_map('intval', $pdo->query('SELECT id FROM products WHERE category_id = 1 ORDER BY price, id')->fetchAll(PDO::FETCH_COLUMN));
    check(array_merge(...array_column($pages, 'ids')) === $expected, 'category=1&sort=price, 2 per page: correct products in order');
    check(str_contains($pages[0]['meta']['next'], 'category=1') && str_contains($pages[0]['meta']['next'], 'sort=price'),
        '...and the next link keeps the category and sort filters');

    // Past the last page: an empty page (not an error), pointing back to the last real page.
    [$status, $json] = api('GET', '/products?limit=5&page=99');
    $lastPage = (int) ceil(count($pdo->query('SELECT id FROM products')->fetchAll()) / 5);
    check($status === 200 && $json['data'] === [] && $json['meta']['next'] === null
        && str_contains((string) $json['meta']['previous'], "page=$lastPage"),
        "page=99 is an empty 200 whose previous link goes to the last page ($lastPage)");
    [, $json] = api('GET', '/products?limit=100');
    check(count($json['data']) === $json['meta']['total'] && $json['meta']['totalPages'] === 1, 'limit=100 (the maximum) returns everything on one page');

    // ===================================================== 3. broken bodies
    echo "\n-- 3. Broken request bodies are refused and change nothing\n";

    $broken = [
        ['{"name":', 400, 'Malformed JSON body'],
        ['', 400, 'Malformed JSON body'],
        ['[]', 400, 'must be a JSON object'],
        ['null', 400, 'must be a JSON object'],
        ['"just text"', 400, 'must be a JSON object'],
        ['42', 400, 'must be a JSON object'],
        [str_repeat('[', 600) . str_repeat(']', 600), 400, 'Malformed JSON body'],   // nested too deep
    ];
    foreach ([['POST', '/products'], ['PUT', '/products/101'], ['POST', '/categories']] as [$method, $path]) {
        foreach ($broken as [$body, $status, $message]) {
            $short = strlen($body) > 12 ? substr($body, 0, 9) . '...' : ($body === '' ? '(empty)' : $body);
            refused($pdo, $status, $method, $path, $body, "$method $path with body $short", $message);
        }
        refused($pdo, 415, $method, $path, '{"name":"Tea","price":3,"categoryId":4}', "$method $path with Content-Type text/plain",
            'Content-Type: application/json', ['Content-Type' => 'text/plain']);
        refused($pdo, 415, $method, $path, '{"name":"Tea"}', "$method $path with form encoding",
            'Content-Type: application/json', ['Content-Type' => 'application/x-www-form-urlencoded']);
    }
    // "application/json; charset=utf-8" is still JSON and must be accepted.
    [$status, $tea] = api('POST', '/products', ['name' => 'Green Tea', 'price' => 3.0, 'categoryId' => 4],
        ['Content-Type' => 'application/json; charset=utf-8']);
    check($status === 201, 'Content-Type "application/json; charset=utf-8" is accepted');

    // ===================================================== 4. invalid fields
    echo "\n-- 4. Invalid fields get 422 naming each field, and change nothing\n";

    $good = ['name' => 'Oat Milk', 'price' => 3.49, 'categoryId' => 2];
    $badProducts = [
        'no fields' => [new stdClass(), ['name', 'price', 'categoryId']],
        'empty name' => [['name' => ''] + $good, ['name']],
        'name of 151 characters' => [['name' => str_repeat('n', 151)] + $good, ['name']],
        'name as a number' => [['name' => 5] + $good, ['name']],
        'price as text' => [['price' => '3.49'] + $good, ['price']],
        'negative price' => [['price' => -1] + $good, ['price']],
        'price with 3 decimals' => [['price' => 1.999] + $good, ['price']],
        'huge price' => [['price' => 1e20] + $good, ['price']],
        'categoryId as text' => [['categoryId' => '2'] + $good, ['categoryId']],
        'categoryId 0' => [['categoryId' => 0] + $good, ['categoryId']],
        'negative stock' => [$good + ['stock' => -3], ['stock']],
        'stock 1.5' => [$good + ['stock' => 1.5], ['stock']],
        'an id in the body' => [$good + ['id' => 500], ['id']],
        'several problems at once' => [['name' => '', 'price' => -1, 'categoryId' => '2'], ['name', 'price', 'categoryId']],
    ];
    foreach ($badProducts as $label => [$body, $fields]) {
        $json = refused($pdo, 422, 'POST', '/products', is_array($body) ? $body : json_encode($body), "POST /products with $label");
        $named = array_keys($json['error']['details'] ?? []);
        sort($named);
        sort($fields);
        check($named === $fields, '...and the error names exactly: ' . implode(', ', $fields));
    }
    refused($pdo, 422, 'PUT', '/products/101', ['price' => 1], 'PUT /products/101 missing name and categoryId');

    $badCategories = [
        'no name' => new stdClass(), 'empty name' => ['name' => ''], 'only spaces' => ['name' => '   '],
        'name as a number' => ['name' => 5], 'name of 101 characters' => ['name' => str_repeat('c', 101)],
    ];
    foreach ($badCategories as $label => $body) {
        $json = refused($pdo, 422, 'POST', '/categories', is_array($body) ? $body : json_encode($body), "POST /categories with $label");
        check(isset($json['error']['details']['name']), '...and the error names the name field');
    }
    // Duplicate names, case and spaces ignored (the column's collation is case-insensitive).
    foreach (['Dairy', 'dairy', '  DAIRY  '] as $name) {
        refused($pdo, 409, 'POST', '/categories', ['name' => $name], "POST /categories \"$name\" (already exists)", 'already exists');
    }
    // 60 accented letters is 60 characters but 120 bytes: it must be allowed.
    [$status, $accented] = api('POST', '/categories', ['name' => str_repeat('é', 60)]);
    check($status === 201 && mb_strlen($accented['name']) === 60, 'A category name of 60 accented letters is accepted (characters, not bytes)');
    refused($pdo, 422, 'POST', '/categories', ['name' => str_repeat('é', 101)], 'A category name of 101 accented letters');

    // ===================================================== 5. media types and methods
    echo "\n-- 5. Unsupported formats and methods\n";

    foreach ([['/products', 'text/html'], ['/products/101', 'image/png'], ['/categories', 'application/xml'], ['/categories/1', 'text/csv']]
             as [$path, $accept]) {
        [$status, , $headers] = api('GET', $path, null, ['Accept' => $accept]);
        check($status === 406 && str_starts_with($headers['content-type'], 'application/json'), "GET $path with Accept $accept -> 406 (JSON error)");
    }
    refused($pdo, 406, 'POST', '/products', $good, 'POST /products asking for an XML response', 'Not acceptable', ['Accept' => 'application/xml']);

    // Errors come back in the format the client asked for, when that format is supported.
    [$status, , $headers, $raw] = api('GET', '/products/999', null, ['Accept' => 'application/xml']);
    check($status === 404 && str_starts_with($headers['content-type'], 'application/xml') && str_contains($raw, '<requestId>' . $headers['x-request-id']),
        'A 404 asked for as XML is XML and carries the same request id');
    [$status, , $headers, $raw] = api('GET', '/products?page=0', null, ['Accept' => 'application/yaml']);
    check($status === 400 && str_contains($headers['content-type'], 'yaml') && str_contains($raw, $headers['x-request-id']),
        'A 400 asked for as YAML is YAML and carries the same request id');

    foreach ([['PATCH', '/products/101'], ['DELETE', '/products'], ['PUT', '/categories/1'], ['PATCH', '/categories/1'], ['POST', '/products/101']]
             as [$method, $path]) {
        refused($pdo, 405, $method, $path, '{"name":"x"}', "$method $path (method not supported)");
    }
    foreach (['/products/abc', '/products/-1', '/products/1.5', '/categories/abc'] as $path) {
        check(api('GET', $path)[0] === 404, "GET $path -> 404 (not a valid id)");
    }
    foreach (['/products/999', '/products/0', '/categories/999', '/categories/0'] as $path) {
        check(api('GET', $path)[0] === 404, "GET $path -> 404 (no such row)");
    }
    refused($pdo, 404, 'PUT', '/products/999', $good, 'PUT /products/999 (no such product)');
    refused($pdo, 404, 'DELETE', '/products/999', null, 'DELETE /products/999 (no such product)');
    refused($pdo, 404, 'DELETE', '/categories/999', null, 'DELETE /categories/999 (no such category)');

    // ===================================================== 6. foreign keys
    echo "\n-- 6. Foreign keys: missing parents and rows still in use\n";

    // A product's category must exist.
    refused($pdo, 422, 'POST', '/products', ['categoryId' => 999] + $good, 'POST /products with a category that does not exist', 'Validation failed');
    refused($pdo, 422, 'PUT', '/products/101', ['categoryId' => 999] + $good, 'PUT /products/101 moving it to a category that does not exist');

    // A category with products can't be deleted.
    refused($pdo, 409, 'DELETE', '/categories/1', null, 'DELETE /categories/1 (Produce still has products)', 'still has products');

    // A product with stock history (inventory_adjustments) can't be deleted.
    refused($pdo, 409, 'DELETE', '/products/109', null, 'DELETE /products/109 (it has inventory history)', 'inventory history');

    // A product that was ORDERED can be deleted: the order keeps its own copy
    // of the name and price (order_items.product_name / unit_price), and only
    // the link to the product is cleared (ON DELETE SET NULL).
    $line = $pdo->query('SELECT product_name, unit_price FROM order_items WHERE id = 1006')->fetch();
    check(api('DELETE', '/products/110')[0] === 204, 'DELETE /products/110 (only in a past order) is allowed (204)');
    $after = $pdo->query('SELECT product_id, product_name, unit_price FROM order_items WHERE id = 1006')->fetch();
    check($after['product_id'] === null && $after['product_name'] === $line['product_name'] && $after['unit_price'] === $line['unit_price'],
        '...and the order line keeps its name and price, with the product link cleared');

    // A product in someone's cart can be deleted: the cart line goes with it (ON DELETE CASCADE).
    check(api('DELETE', '/products/104')[0] === 204, 'DELETE /products/104 (in a cart) is allowed (204)');
    check((int) $pdo->query('SELECT COUNT(*) FROM cart_items WHERE product_id = 104')->fetchColumn() === 0,
        '...and its cart line is removed with it');

    // "Race" versions. The controller checks first ("does the category
    // exist?", "is it in use?"), then writes. If another request changes the
    // data between those two steps, the DATABASE has to refuse, and the API
    // must turn that refusal into the same clean 4xx (not a 500).
    //
    // We can't reliably time two real requests, so a temporary trigger does
    // "the other request's change" at exactly the wrong moment, every time.
    echo "\n-- 6b. The same foreign keys when the data changes mid-request\n";

    // (a) Category deleted between the check and the INSERT: the trigger
    //     swaps in a category id that doesn't exist, so the FK refuses (1452).
    $pdo->exec("CREATE TRIGGER race_product_insert BEFORE INSERT ON products FOR EACH ROW
                IF NEW.name = 'Race Product' THEN SET NEW.category_id = 999999; END IF");
    refused($pdo, 422, 'POST', '/products', ['name' => 'Race Product'] + $good,
        'POST /products whose category disappears mid-request', 'Validation failed');
    $pdo->exec('DROP TRIGGER race_product_insert');

    $pdo->exec("CREATE TRIGGER race_product_update BEFORE UPDATE ON products FOR EACH ROW
                IF NEW.name = 'Race Product' THEN SET NEW.category_id = 999999; END IF");
    refused($pdo, 422, 'PUT', '/products/101', ['name' => 'Race Product'] + $good,
        'PUT /products/101 whose new category disappears mid-request', 'Validation failed');
    $pdo->exec('DROP TRIGGER race_product_update');

    // (b) Stock history added between the check and the DELETE (FK refuses with 1451).
    $pdo->exec("CREATE TRIGGER race_product_delete BEFORE DELETE ON products FOR EACH ROW
                INSERT INTO inventory_adjustments (product_id, actor_id, quantity_change, stock_before, stock_after, reason)
                VALUES (OLD.id, 9, 1, OLD.stock, OLD.stock + 1, 'restock')");
    refused($pdo, 409, 'DELETE', '/products/103', null, 'DELETE /products/103 when stock history appears mid-request', 'inventory history');
    $pdo->exec('DROP TRIGGER race_product_delete');

    // (c) A product added to the category between the "in use?" check and the DELETE.
    $pdo->exec("CREATE TRIGGER race_category_delete BEFORE DELETE ON categories FOR EACH ROW
                INSERT INTO products (name, price, category_id) VALUES ('Sneaky Product', 1.00, OLD.id)");
    [, $empty] = api('POST', '/categories', ['name' => 'Seasonal']);
    refused($pdo, 409, 'DELETE', "/categories/{$empty['id']}", null,
        'DELETE an empty category when a product is added mid-request', 'still has products');
    $pdo->exec('DROP TRIGGER race_category_delete');
    check(api('DELETE', "/categories/{$empty['id']}")[0] === 200, '...and once nothing interferes, the empty category deletes normally');

    // ===================================================== 7. server errors and logs
    echo "\n-- 7. A real server error is safe and traceable\n";

    // Make the database fail on one specific insert, like a disk or
    // connection problem would. SIGNAL raises a custom database error.
    $pdo->exec("CREATE TRIGGER fail_product_insert BEFORE INSERT ON products FOR EACH ROW
                IF NEW.name = 'Synthetic Failure' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic database failure';
                END IF");
    $before = snapshot($pdo);
    [$status, $json, $headers, $raw] = api('POST', '/products', ['name' => 'Synthetic Failure'] + $good);
    $pdo->exec('DROP TRIGGER fail_product_insert');
    $requestId = $headers['x-request-id'];

    check($status === 500 && $json['error']['message'] === 'Internal server error.', 'A database failure gives the client a plain 500');
    check($json['error']['requestId'] === $requestId, '...whose requestId matches the X-Request-ID header');
    check(!str_contains($raw, 'synthetic') && !str_contains($raw, 'SQLSTATE') && !str_contains($raw, 'INSERT'),
        '...and the client does not see the database error or SQL');
    check(snapshot($pdo) === $before, '...and nothing was saved');

    usleep(200000);   // let the server finish writing its log
    $logText = (string) file_get_contents($log);
    $entry = null;
    foreach (explode("\n", $logText) as $line) {
        $start = strpos($line, '{');
        $decoded = $start === false ? null : json_decode(substr($line, $start), true);
        if (is_array($decoded) && ($decoded['requestId'] ?? null) === $requestId) {
            $entry = $decoded;
        }
    }
    check($entry !== null && $entry['level'] === 'error' && str_contains($entry['errorMessage'] ?? '', 'synthetic database failure'),
        'The server log has an error entry with the SAME request id and the real cause');

    // The whole log, after everything this script did, must not contain secrets.
    $hashes = $pdo->query('SELECT password_hash FROM customers')->fetchAll(PDO::FETCH_COLUMN);
    $leaks = array_filter(array_merge([$staff, hash('sha256', $staff), 'FoodwellDemo!2026'], $hashes),
        static fn(string $secret): bool => $secret !== '' && str_contains($logText, $secret));
    check($leaks === [], 'The server log contains no login token, token fingerprint, password or password hash');

    check($errorsSeen > 100, "Every one of the $errorsSeen error responses carried a requestId matching its X-Request-ID header");

    echo "\n$checks catalog integrity checks passed. Application database was not used.\n";
} catch (Throwable $e) {
    echo "\n" . $e->getMessage() . "\n";
    if ($log !== null && is_file($log)) {
        // Show only real PHP messages, not the server's "connection opened/closed" lines.
        $lines = preg_grep('/Accepted|Closing|Closed without|started$|\[\d{3}\]: /', file($log), PREG_GREP_INVERT);
        echo $lines === [] ? '' : "Server log:\n" . implode('', $lines) . "\n";
    }
    $exitCode = 1;
} finally {
    // Always clean up, even when a check failed.
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $pdo->exec("DROP DATABASE IF EXISTS `$db`");
    foreach ([$log, $router] as $file) {
        if ($file !== null) {
            @unlink($file);
        }
    }
}
exit($exitCode);
