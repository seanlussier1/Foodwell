<?php
declare(strict_types=1);

/**
 * Representation matrix checks (#65, B3-17).
 *
 *   php scripts/check-representations.php
 *
 * The agreed matrix (docs/REPRESENTATION_MATRIX.md):
 *   - Catalog routes (products, categories, delivery slots) ANSWER in JSON,
 *     XML or YAML, chosen by the Accept header. Anything else is 406.
 *   - Request BODIES are JSON on every route. Any other Content-Type is 415.
 *   - All other routes (customers, carts, orders, sessions, delivery options)
 *     answer in JSON only: XML/YAML gets a 406, never a silent JSON fallback.
 *
 * The main idea: for each catalog request, ask for the SAME thing in JSON,
 * XML and YAML, read each answer with a parser, and check they carry the same
 * ids, text, numbers (quantities and prices), booleans, nulls and lists. Real
 * responses are compared, not documentation examples.
 *
 * How each format is read:
 *   JSON  json_decode()
 *   XML   PHP's DOM parser. XML has no types, so each value is compared with
 *         the JSON value it should represent: null = nil="true", booleans =
 *         "true"/"false", numbers must be numerically equal, lists = one child
 *         element per item.
 *   YAML  readYaml() below: a small STRICT reader for the YAML subset the API
 *         writes. It refuses anything it doesn't understand, so a formatting
 *         change in the API makes this script fail instead of passing quietly.
 *
 * Uses the SCHEMA_TEST_* settings like the other check scripts; creates and
 * drops its own database and server. Your real database is never touched.
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
$db = 'foodwell_representations_' . bin2hex(random_bytes(4));
$server = null;
$router = null;
$exitCode = 0;
$checks = 0;

const JSON_TYPE = 'application/json';
const XML_TYPE = 'application/xml';
const YAML_TYPE = 'application/yaml';

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
 * One HTTP request. $body is sent exactly as given (so XML/YAML bodies can be tested).
 *
 * @return array{0:int,1:array<string,string>,2:string} status, headers (lower-case names), raw body
 */
function http(string $method, string $path, string $accept = JSON_TYPE, ?string $token = null, ?string $body = null,
              string $contentType = JSON_TYPE): array
{
    global $serverPort;
    $headers = ["Accept: $accept"];
    if ($body !== null) {
        $headers[] = "Content-Type: $contentType";
    }
    if ($token !== null) {
        $headers[] = "Authorization: Bearer $token";
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => implode("\r\n", $headers), 'content' => $body ?? '',
    ]]);
    $raw = (string) file_get_contents("http://127.0.0.1:$serverPort/api/v1$path", false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    $responseHeaders = [];
    foreach (array_slice($http_response_header, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $name = strtolower(trim($name));
        // Some headers (like Vary) can appear more than once: keep them all.
        $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ', ' . trim($value) : trim($value);
    }
    return [(int) $match[1], $responseHeaders, $raw];
}

// ----------------------------------------------------------------- XML

/**
 * True if XML element $node represents the same value as the JSON value $json.
 * $item is the element name used for list items (e.g. "product").
 * On a mismatch, $why says where.
 */
function xmlMatches(DOMElement $node, mixed $json, string $item, string &$why, string $path = ''): bool
{
    $children = [];
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $children[] = $child;
        }
    }
    $text = $children === [] ? $node->textContent : null;
    $where = $path === '' ? $node->nodeName : $path;

    if ($json === null) {
        $ok = $node->getAttribute('nil') === 'true' && $children === [] && $node->textContent === '';
        $why = $ok ? $why : "$where should be nil=\"true\"";
        return $ok;
    }
    if ($node->hasAttribute('nil')) {
        $why = "$where is nil but JSON has a value";
        return false;
    }
    if (is_bool($json)) {
        $ok = $text === ($json ? 'true' : 'false');
        $why = $ok ? $why : "$where should be " . ($json ? 'true' : 'false') . ", got " . var_export($text, true);
        return $ok;
    }
    if (is_int($json) || is_float($json)) {
        // Quantities and prices: the text must be a number with exactly the same value.
        $ok = $text !== null && is_numeric($text) && (float) $text === (float) $json
            && (!is_int($json) || (string) $json === $text);
        $why = $ok ? $why : "$where should be the number $json, got " . var_export($text, true);
        return $ok;
    }
    if (is_string($json)) {
        $ok = $text === $json;
        $why = $ok ? $why : "$where should be " . var_export($json, true) . ', got ' . var_export($text, true);
        return $ok;
    }
    // An array: a list (keys 0, 1, 2...) or an object (named keys).
    if ($json === []) {
        // An empty list/object has no children.
        $ok = $children === [];
        $why = $ok ? $why : "$where should be empty";
        return $ok;
    }
    $isList = array_keys($json) === range(0, count($json) - 1);
    if (count($children) !== count($json)) {
        $why = "$where has " . count($children) . ' children, JSON has ' . count($json);
        return false;
    }
    $i = 0;
    foreach ($json as $key => $value) {
        $child = $children[$i++];
        $expectedName = $isList ? $item : (string) $key;
        if ($child->nodeName !== $expectedName) {
            $why = "$where child #$i is <{$child->nodeName}>, expected <$expectedName>";
            return false;
        }
        if (!xmlMatches($child, $value, $item, $why, "$where/$expectedName")) {
            return false;
        }
    }
    return true;
}

// ----------------------------------------------------------------- YAML

/**
 * Reads the YAML the API writes into PHP values. Supported:
 *   key: scalar | key: (block below) | - scalar | - key: ... (list of maps)
 *   scalars: null, true, false, integers, decimals, "double-quoted strings"
 *   [] and {} for empty lists/maps
 * Anything else throws, on purpose.
 */
function readYaml(string $text): mixed
{
    $lines = array_values(array_filter(explode("\n", rtrim($text, "\n")), static fn($l) => $l !== ''));
    if (count($lines) === 1 && in_array(trim($lines[0]), ['[]', '{}'], true)) {
        return [];
    }
    $position = 0;
    $value = yamlBlock($lines, $position, 0);
    if ($position !== count($lines)) {
        throw new RuntimeException('YAML: unexpected line ' . ($position + 1) . ': ' . $lines[$position]);
    }
    return $value;
}

/** Reads the block of lines that all start at $indent spaces. */
function yamlBlock(array $lines, int &$i, int $indent): array
{
    $result = [];
    $isList = null;
    while ($i < count($lines)) {
        $line = $lines[$i];
        $lead = strlen($line) - strlen(ltrim($line, ' '));
        if ($lead < $indent) {
            break;                                   // the block is over
        }
        if ($lead > $indent) {
            throw new RuntimeException('YAML: unexpected indentation at line ' . ($i + 1));
        }
        $content = substr($line, $indent);
        $listItem = str_starts_with($content, '- ');
        if ($isList === null) {
            $isList = $listItem;
        } elseif ($isList !== $listItem) {
            throw new RuntimeException('YAML: list and map mixed at line ' . ($i + 1));
        }

        if ($listItem) {
            $rest = substr($content, 2);
            if (preg_match('/^("(?:[^"\\\\]|\\\\.)*"|[A-Za-z_][A-Za-z0-9_]*):( |$)/', $rest) === 1) {
                // "- key: value": a map inside a list. Its other keys sit 2 spaces deeper.
                $lines[$i] = str_repeat(' ', $indent + 2) . $rest;
                $result[] = yamlBlock($lines, $i, $indent + 2);
            } else {
                $result[] = yamlScalar($rest, $i);
                $i++;
            }
            continue;
        }

        if (preg_match('/^("(?:[^"\\\\]|\\\\.)*"|[A-Za-z_][A-Za-z0-9_]*):(?: (.*))?$/', $content, $m) !== 1) {
            throw new RuntimeException('YAML: cannot read line ' . ($i + 1) . ': ' . $line);
        }
        $key = $m[1][0] === '"' ? json_decode($m[1], false, 512, JSON_THROW_ON_ERROR) : $m[1];
        $i++;
        if (isset($m[2]) && $m[2] !== '') {
            $result[$key] = yamlScalar($m[2], $i - 1);
        } else {
            $result[$key] = yamlBlock($lines, $i, $indent + 2);   // nested block
        }
    }
    return $result;
}

function yamlScalar(string $value, int $line): mixed
{
    return match (true) {
        $value === 'null' => null,
        $value === 'true' => true,
        $value === 'false' => false,
        $value === '[]', $value === '{}' => [],
        preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1 => (int) $value,
        preg_match('/^-?(0|[1-9][0-9]*)\.[0-9]+([eE][-+]?[0-9]+)?$/', $value) === 1 => (float) $value,
        // The API writes strings as JSON strings, which are valid YAML double-quoted strings.
        str_starts_with($value, '"') => json_decode($value, false, 512, JSON_THROW_ON_ERROR),
        default => throw new RuntimeException('YAML: unknown value at line ' . ($line + 1) . ': ' . $value),
    };
}

// ----------------------------------------------------------------- comparing formats

/**
 * Asks for $path in JSON, XML and YAML and checks all three say the same.
 * Returns the JSON body. $root/$item are the XML element names.
 */
function sameInAllFormats(string $label, string $method, string $path, string $root, string $item,
                          ?string $token = null, ?callable $body = null, int $expected = 200): ?array
{
    $answers = [];
    foreach ([JSON_TYPE, XML_TYPE, YAML_TYPE] as $type) {
        [$status, $headers, $raw] = http($method, $path, $type, $token, $body === null ? null : $body($type));
        $answers[$type] = [$status, $headers, $raw];
        check($status === $expected, "$label as " . substr($type, 12) . " -> $expected" . ($status === $expected ? '' : " (got $status)"));
        check(str_starts_with($headers['content-type'] ?? '', "$type; charset=utf-8"), "...Content-Type is $type; charset=utf-8");
        if ($status < 400) {
            check(str_contains($headers['vary'] ?? '', 'Accept'), '...and Vary: Accept is sent (caches keep formats apart)');
        }
    }

    $json = json_decode($answers[JSON_TYPE][2], true, 512, JSON_THROW_ON_ERROR);

    // Every request gets its OWN request id, so errors can't share one across
    // formats. Instead: each error's requestId must equal its own X-Request-ID
    // header, and everything else must be identical. $expected() puts the
    // right id into the JSON value before comparing with XML or YAML.
    $expected = static function (string $type) use ($json, $answers, $root): array {
        if ($root !== 'error') {
            return $json;
        }
        $value = $json;
        $value['error']['requestId'] = $answers[$type][1]['x-request-id'] ?? '';
        return $value;
    };
    if ($root === 'error') {
        check(($json['error']['requestId'] ?? null) === ($answers[JSON_TYPE][1]['x-request-id'] ?? ''),
            "$label: the JSON error's requestId matches its X-Request-ID header");
    }

    $doc = new DOMDocument();
    check($doc->loadXML($answers[XML_TYPE][2], LIBXML_NONET) === true, "$label: the XML parses");
    // Errors are <error>...</error> in XML but {"error": {...}} in JSON/YAML.
    [$xmlRoot, $xmlValue] = $root === 'error' ? ['error', $expected(XML_TYPE)['error']] : [$root, $json];
    $why = '';
    check($doc->documentElement->nodeName === $xmlRoot && xmlMatches($doc->documentElement, $xmlValue, $item, $why),
        "$label: XML <$xmlRoot> has the same ids, text, numbers, booleans, nulls and lists as JSON" . ($why === '' ? '' : " [$why]"));

    $yaml = readYaml($answers[YAML_TYPE][2]);
    check($yaml === $expected(YAML_TYPE), "$label: YAML has exactly the same values and types as JSON");
    return $json;
}

// ================================================================= the checks
try {
    $pdo->exec("CREATE DATABASE `$db`");
    $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents("$root/database/schema.sql"));
    $pdo->exec(file_get_contents("$root/database/seed.sql"));

    // A product with tricky text and values: quotes, &, <, #, :, accents, an
    // emoji, a name that is literally "null", a null imageUrl, isActive false,
    // and a price with cents. If any format mangles these, the comparison fails.
    $pdo->exec("INSERT INTO products (id, name, price, unit, stock, category_id, image_url, is_active) VALUES
        (120, 'Café \"Spécial\" & <Crème> #1: été 🍓', 12.30, 'each', 0, 4, NULL, 0),
        (121, 'null', 0.05, 'g', 1000000, 4, 'https://example.com/a.jpg?x=1&y=2', 1)");

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $serverPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $router = tempnam(sys_get_temp_dir(), 'representations-router-');
    file_put_contents($router, '<?php ' . ($pass === '' ? 'putenv("DB_PASS=");' : '')
        . ' return require ' . var_export("$root/public/index.php", true) . ';');
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$serverPort", '-t', "$root/public", $router],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
        $pipes, $root,
        array_merge(getenv(), ['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $db, 'DB_USER' => $user,
            'DB_PASS' => $pass, 'APP_BASE_PATH' => '', 'APP_POSTAL_DEMO' => '0'])
    );
    for ($i = 0, $ready = false; $i < 50 && !$ready; $i++) {
        $probe = @fsockopen('127.0.0.1', $serverPort);
        if ($probe) { fclose($probe); $ready = true; } else { usleep(100000); }
    }
    check($ready, 'Temporary database and API server started');

    // Logins stored straight in the table (login itself is tested by check-auth.php).
    $session = static function (int $id) use ($pdo): string {
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO auth_sessions (customer_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR)')
            ->execute([$id, hash('sha256', $token)]);
        return $token;
    };
    $staff = $session(9);
    $customer = $session(7);
    $admin = $session(10);
    $hoursAhead = static fn(int $h): string => (new DateTimeImmutable(sprintf('%+d hours', $h), new DateTimeZone('UTC')))->format('Y-m-d\TH:00:00\Z');

    // ============================================= 1. catalog reads, 3 formats
    echo "\n-- 1. Catalog reads: the same data in JSON, XML and YAML\n";
    $list = sameInAllFormats('GET /products (full list)', 'GET', '/products?limit=100', 'products', 'product');
    check(count($list['data']) === 12, '...covering all 12 products, including the tricky ones');
    $tricky = sameInAllFormats('GET /products/120 (quotes, &, <, emoji, null image, inactive, stock 0)', 'GET', '/products/120', 'product', 'item');
    check($tricky['imageUrl'] === null && $tricky['isActive'] === false && $tricky['price'] === 12.3 && $tricky['stock'] === 0,
        '...whose null, false, price 12.30 and stock 0 were compared in every format');
    sameInAllFormats('GET /products/121 (name "null", price 0.05, stock 1000000, & in URL)', 'GET', '/products/121', 'product', 'item');
    sameInAllFormats('GET /products?category=999 (empty list)', 'GET', '/products?category=999', 'products', 'product');
    sameInAllFormats('GET /categories', 'GET', '/categories', 'categories', 'category');
    sameInAllFormats('GET /categories/2', 'GET', '/categories/2', 'category', 'item');
    $slots = sameInAllFormats('GET /delivery-slots', 'GET', '/delivery-slots', 'deliverySlots', 'deliverySlot');
    check(count($slots['data']) === 4, '...covering all 4 seeded slots (full, past/inactive and bookable)');
    sameInAllFormats('GET /delivery-slots/304', 'GET', '/delivery-slots/304', 'deliverySlot', 'item');

    // ============================================= 2. catalog writes, 3 formats
    echo "\n-- 2. Catalog writes answer in the format asked for (bodies are always JSON)\n";
    // Each format creates its own row (the names differ), then we compare
    // what came back with a JSON read of the same row.
    foreach ([JSON_TYPE, XML_TYPE, YAML_TYPE] as $type) {
        $short = substr($type, 12);
        $created = null;
        [$status, $headers, $raw] = http('POST', '/products', $type, $staff,
            json_encode(['name' => "Rep $short", 'price' => 19.99, 'categoryId' => 3, 'stock' => 7]));
        check($status === 201 && str_starts_with($headers['content-type'], $type) && isset($headers['location']),
            "POST /products answered in $short with 201 and a Location header");
        $id = (int) basename($headers['location']);
        [, , $asJson] = http('GET', "/products/$id");
        $why = '';
        if ($type === XML_TYPE) {
            $doc = new DOMDocument();
            $doc->loadXML($raw);
            check(xmlMatches($doc->documentElement, json_decode($asJson, true), 'item', $why), "...the XML is the saved product [$why]");
        } elseif ($type === YAML_TYPE) {
            check(readYaml($raw) === json_decode($asJson, true), '...the YAML is the saved product');
        } else {
            check(json_decode($raw, true) === json_decode($asJson, true), '...the JSON is the saved product');
        }
        [$status, $headers] = http('PUT', "/products/$id", $type, $staff,
            json_encode(['name' => "Rep $short 2", 'price' => 21.5, 'categoryId' => 3, 'isActive' => false]));
        check($status === 200 && str_starts_with($headers['content-type'], $type), "PUT /products/$id answered in $short (200)");
        [$status, $headers, $raw] = http('DELETE', "/products/$id", $type, $staff);
        check($status === 204 && $raw === '', "DELETE /products/$id is 204 with no body in every format ($short)");
    }
    sameInAllFormats('PUT /products/101 (same body each time)', 'PUT', '/products/101', 'product', 'item', $staff,
        static fn() => json_encode(['name' => 'Whole Milk 2L', 'price' => 4.99, 'categoryId' => 2]));

    foreach ([JSON_TYPE, XML_TYPE, YAML_TYPE] as $n => $type) {
        $short = substr($type, 12);
        [$status, $headers, $raw] = http('POST', '/categories', $type, $staff, json_encode(['name' => "Rep Category $n"]));
        check($status === 201 && str_starts_with($headers['content-type'], $type), "POST /categories answered in $short (201)");
        $id = $type === JSON_TYPE ? json_decode($raw, true)['id']
            : ($type === XML_TYPE ? (int) simplexml_load_string($raw)->id : readYaml($raw)['id']);
        [$status, $headers, $raw] = http('DELETE', "/categories/$id", $type, $staff);
        $deleted = $type === JSON_TYPE ? json_decode($raw, true)
            : ($type === XML_TYPE ? ['message' => (string) simplexml_load_string($raw)->message] : readYaml($raw));
        check($status === 200 && str_starts_with($headers['content-type'], $type) && $deleted['message'] === 'Category deleted successfully.',
            "DELETE /categories/$id answered in $short (200 with a message)");
    }

    foreach ([JSON_TYPE, XML_TYPE, YAML_TYPE] as $n => $type) {
        $short = substr($type, 12);
        $window = ['startsAt' => $hoursAhead(200 + 3 * $n), 'endsAt' => $hoursAhead(201 + 3 * $n)];
        [$status, $headers] = http('POST', '/delivery-slots', $type, $staff, json_encode($window + ['capacity' => 3]));
        check($status === 201 && str_starts_with($headers['content-type'], $type) && isset($headers['location']),
            "POST /delivery-slots answered in $short (201 with Location)");
        $id = (int) basename($headers['location']);
        $put = sameInAllFormats("PUT /delivery-slots/$id (deactivate)", 'PUT', "/delivery-slots/$id", 'deliverySlot', 'item', $staff,
            static fn() => json_encode($window + ['capacity' => 5, 'isActive' => false]));
        check($put['isActive'] === false && $put['isAvailable'] === false && $put['capacity'] === 5,
            '...booleans and the new capacity are the same in every format');
        [$status, , $raw] = http('DELETE', "/delivery-slots/$id", $type, $staff);
        check($status === 204 && $raw === '', "DELETE /delivery-slots/$id is 204 with no body ($short)");
    }

    // ============================================= 3. errors, 3 formats
    echo "\n-- 3. Errors use the requested format, with the same fields\n";
    $errorCases = [
        ['404 unknown product', 'GET', '/products/999', null, null, 404],
        ['404 unknown category', 'GET', '/categories/999', null, null, 404],
        ['404 unknown slot', 'GET', '/delivery-slots/999', null, null, 404],
        ['400 bad product query', 'GET', '/products?page=0&limit=500', null, null, 400],
        ['400 bad slot query (details map)', 'GET', '/delivery-slots?date=2026-02-30&available=maybe', null, null, 400],
        ['401 no login (WWW-Authenticate)', 'POST', '/products', null, '{}', 401],
        ['403 customer login', 'POST', '/categories', $customer, '{"name":"x"}', 403],
        ['400 malformed JSON body', 'POST', '/products', $staff, '{"name":', 400],
        ['422 invalid fields (details map)', 'POST', '/products', $staff, '{"name":"","price":-1,"categoryId":"x"}', 422],
        ['409 category in use', 'DELETE', '/categories/1', $staff, null, 409],
        ['409 slot window moved while booked', 'PUT', '/delivery-slots/301', $staff,
            json_encode(['startsAt' => $hoursAhead(300), 'endsAt' => $hoursAhead(301), 'capacity' => 5]), 409],
    ];
    foreach ($errorCases as [$label, $method, $path, $token, $body, $status]) {
        $before = $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() . '/' . $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
        $error = sameInAllFormats($label, $method, $path, 'error', 'item', $token, $body === null ? null : static fn() => $body, $status);
        check($error['error']['status'] === $status && preg_match('/^[a-f0-9]{32}$/', $error['error']['requestId']) === 1,
            '...status and requestId are present in every format');
        $after = $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() . '/' . $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
        check($before === $after, '...and nothing was saved');
    }
    [, $headers] = http('POST', '/products', XML_TYPE, null, '{}');
    check(($headers['www-authenticate'] ?? '') === 'Bearer', 'An XML 401 still sends WWW-Authenticate: Bearer');

    // ============================================= 4. Accept negotiation
    echo "\n-- 4. How the format is chosen (Accept)\n";
    $type = static fn(string $accept, string $path = '/categories/1') => http('GET', $path, $accept);
    $cases = [
        ['', JSON_TYPE, 'no Accept header: JSON (the default)'],
        ['*/*', JSON_TYPE, '*/*: JSON'],
        ['application/*', JSON_TYPE, 'application/*: JSON'],
        ['text/xml', 'text/xml', 'text/xml is returned with its requested media type'],
        ['application/x-yaml', 'application/x-yaml', 'application/x-yaml is returned with its requested media type'],
        ['text/yaml', 'text/yaml', 'text/yaml is returned with its requested media type'],
        ['application/xml;q=0, text/xml;q=1', 'text/xml', 'an accepted XML alias is not blocked by an excluded canonical type'],
        ['application/yaml;q=0, text/yaml;q=1', 'text/yaml', 'an accepted YAML alias is not blocked by an excluded canonical type'],
        ['text/xml;q=0, application/xml;q=1', XML_TYPE, 'an excluded XML alias does not block the canonical type'],
        ['application/xml;q=0.5, application/yaml;q=0.9', YAML_TYPE, 'the highest q wins (YAML 0.9 over XML 0.5)'],
        ['application/yaml, application/xml', YAML_TYPE, 'equal q: the client\'s order decides'],
        ['application/json;q=0, */*', XML_TYPE, 'q=0 rules JSON out, so the next format (XML) is used'],
        ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', XML_TYPE, 'a browser\'s Accept gets XML'],
        ['application/xml;q=abc, application/yaml', YAML_TYPE, 'an invalid q makes that entry ignored'],
        ['application/json; charset=utf-8', JSON_TYPE, 'parameters other than q are ignored'],
    ];
    foreach ([['/products/101', 'product read'], ['/categories/1', 'category read'], ['/delivery-slots/304', 'slot read']] as [$path, $what]) {
        foreach ($cases as [$accept, $expected, $why]) {
            [$status, $headers] = $type($accept, $path);
            check($status === 200 && str_starts_with($headers['content-type'] ?? '', $expected), "$what, Accept \"$accept\": $why");
        }
    }
    foreach (['text/html', 'application/json;q=0', 'image/png', 'application/xml;q=0, application/yaml;q=0, application/json;q=0'] as $accept) {
        foreach (['/products', '/categories', '/delivery-slots'] as $path) {
            [$status, $headers, $raw] = http('GET', $path, $accept);
            $body = json_decode($raw, true);
            check($status === 406 && str_starts_with($headers['content-type'], JSON_TYPE)
                && str_contains($body['error']['message'], 'application/json, application/xml, application/yaml'),
                "GET $path with Accept \"$accept\": 406 (as JSON) listing the three formats");
        }
    }
    check(http('GET', '/nope', XML_TYPE)[0] === 404 && http('PATCH', '/categories/1', XML_TYPE, $staff, '{}')[0] === 405,
        'Unknown route (404) and wrong method (405) are decided before Accept');

    // ============================================= 5. request bodies: JSON only
    echo "\n-- 5. Request bodies are JSON only, on every route (415 otherwise)\n";
    $xmlProduct = '<product><name>Xml Bread</name><price>3.5</price><categoryId>3</categoryId></product>';
    $yamlProduct = "name: \"Yaml Bread\"\nprice: 3.5\ncategoryId: 3\n";
    $writes = [
        ['POST', '/products', $staff], ['PUT', '/products/101', $staff], ['POST', '/categories', $staff],
        ['POST', '/delivery-slots', $staff], ['PUT', '/delivery-slots/304', $staff],
        ['POST', '/customers', null], ['PUT', '/customers/7', $customer],
        ['POST', '/customers/7/cart/items', $customer], ['PUT', '/customers/7/cart/items/501', $customer],
        ['PATCH', '/customers/8', $admin], ['POST', '/orders', $customer], ['PATCH', '/orders/900', $staff],
        ['POST', '/auth/sessions', null],
    ];
    foreach ($writes as [$method, $path, $token]) {
        foreach ([[XML_TYPE, $xmlProduct], ['text/xml', $xmlProduct], [YAML_TYPE, $yamlProduct], ['application/x-www-form-urlencoded', 'name=x'], ['text/plain', '{}']]
                 as [$contentType, $body]) {
            $before = $pdo->query('SELECT (SELECT COUNT(*) FROM products) + (SELECT COUNT(*) FROM categories) + (SELECT COUNT(*) FROM delivery_slots) + (SELECT COUNT(*) FROM customers) + (SELECT COUNT(*) FROM orders) + (SELECT COUNT(*) FROM cart_items)')->fetchColumn();
            [$status, , $raw] = http($method, $path, JSON_TYPE, $token, $body, $contentType);
            $after = $pdo->query('SELECT (SELECT COUNT(*) FROM products) + (SELECT COUNT(*) FROM categories) + (SELECT COUNT(*) FROM delivery_slots) + (SELECT COUNT(*) FROM customers) + (SELECT COUNT(*) FROM orders) + (SELECT COUNT(*) FROM cart_items)')->fetchColumn();
            // POST /orders reads customerId from the JSON body for its permission
            //    check, so a non-JSON body is refused one step earlier: 422.
            // Either way the request is refused and nothing is saved.
            $expected = $path === '/orders' ? 422 : 415;
            check(($status === $expected || ($path === '/orders' && $status === 415)) && $before === $after,
                "$method $path with Content-Type $contentType: $expected, nothing saved");
        }
    }
    [$status, $headers, $raw] = http('POST', '/products', XML_TYPE, $staff, $xmlProduct, XML_TYPE);
    check($status === 415 && str_starts_with($headers['content-type'], XML_TYPE) && str_contains($raw, 'application/json'),
        'A client that sends and wants XML gets the 415 in XML, naming application/json');
    check(http('POST', '/categories', JSON_TYPE, $staff, '{"name":"Charset Ok"}', 'application/json; charset=utf-8')[0] === 201,
        'application/json; charset=utf-8 is accepted');

    // ============================================= 6. JSON-only routes say so
    echo "\n-- 6. Other routes answer in JSON only: 406 for XML/YAML, never a silent fallback\n";
    $jsonOnly = [
        ['GET', '/customers', $admin], ['GET', '/customers/7', $customer], ['GET', '/customers/7/cart', $customer],
        ['GET', '/customers/7/orders', $customer], ['GET', '/orders/900', $admin], ['GET', '/orders/900/status-events', $admin],
        ['GET', '/auth/sessions/current', $customer], ['GET', '/delivery-options?postalCode=H3N%201X1', null],
    ];
    foreach ($jsonOnly as [$method, $path, $token]) {
        [$status, $headers] = http($method, $path, JSON_TYPE, $token);
        check(in_array($status, [200, 502, 503], true) && str_starts_with($headers['content-type'], JSON_TYPE), "$method $path in JSON works ($status)");
        foreach ([XML_TYPE, YAML_TYPE] as $type) {
            [$status, $headers, $raw] = http($method, $path, $type, $token);
            check($status === 406 && str_starts_with($headers['content-type'], JSON_TYPE)
                && str_contains(json_decode($raw, true)['error']['message'], 'can return: application/json.'),
                "$method $path asked for " . substr($type, 12) . ': 406 saying only JSON is available');
        }
    }

    echo "\n$checks representation checks passed. Application database was not used.\n";
} catch (Throwable $e) {
    echo "\n" . $e->getMessage() . "\n";
    $exitCode = 1;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $pdo->exec("DROP DATABASE IF EXISTS `$db`");
    if ($router !== null) {
        @unlink($router);
    }
}
exit($exitCode);
