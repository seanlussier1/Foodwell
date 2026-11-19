<?php
declare(strict_types=1);

/**
 * Checks the inventory-adjustment routes against a throwaway copy of the database.
 *
 *   SCHEMA_TEST_HOST=127.0.0.1 SCHEMA_TEST_USER=root SCHEMA_TEST_PASS= php scripts/check-inventory-adjustments.php
 *
 * It creates a random database (foodwell_inventory_xxxx), loads schema.sql and
 * seed.sql into it, starts a PHP server pointed at it, runs every check, then
 * drops the database. Your normal "foodwell" database is never touched.
 *
 * Seed accounts used: 7 = customer Alex, 9 = staff Taylor, 10 = admin Jordan.
 * Product 109 already has one seeded adjustment (stock 35 -> 40).
 */

$root = dirname(__DIR__);
$host = getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SCHEMA_TEST_PORT') ?: '3306';
$user = getenv('SCHEMA_TEST_USER') ?: 'root';
$pass = getenv('SCHEMA_TEST_PASS') ?: '';
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = 'foodwell_inventory_' . bin2hex(random_bytes(6));
$server = null;
$logs = [];
$checks = 0;

function verify(bool $result, string $message): void
{
    global $checks;
    if (!$result) {
        throw new RuntimeException("FAIL: $message");
    }
    ++$checks;
    echo "PASS: $message\n";
}

/** Sends one request. $body may be an array (sent as JSON) or a raw string. Returns [status, decoded JSON, headers]. */
function callApi(int $port, string $method, string $path, ?string $token = null, array|string|null $body = null, string $type = 'application/json'): array
{
    $headers = "Accept: application/json\r\n";
    if ($token !== null) {
        $headers .= "Authorization: Bearer $token\r\n";
    }
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 10];
    if ($body !== null) {
        $headers .= "Content-Type: $type\r\n";
        $options['content'] = is_string($body) ? $body : json_encode($body);
    }
    $options['header'] = $headers;
    $raw = file_get_contents("http://127.0.0.1:$port/api/v1$path", false, stream_context_create(['http' => $options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], json_decode((string) $raw, true), $http_response_header];
}

function header_value(array $headers, string $name): ?string
{
    foreach ($headers as $line) {
        if (stripos($line, "$name:") === 0) {
            return trim(substr($line, strlen($name) + 1));
        }
    }
    return null;
}

function stock(PDO $pdo, int $id): int
{
    return (int) $pdo->query("SELECT stock FROM products WHERE id = $id")->fetchColumn();
}

function adjustmentCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn();
}

try {
    // ---------- Setup: database, login tokens, test server ----------
    $pdo->exec("CREATE DATABASE `$db`");
    $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents("$root/database/schema.sql"));
    $pdo->exec(file_get_contents("$root/database/seed.sql"));

    $session = $pdo->prepare('INSERT INTO auth_sessions(customer_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR)');
    $tokens = [];
    foreach ([7, 9, 10] as $id) {
        $tokens[$id] = bin2hex(random_bytes(32));
        $session->execute([$id, hash('sha256', $tokens[$id])]);
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) {
        throw new RuntimeException('Cannot reserve test port.');
    }
    $httpPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $serverLog = tempnam(sys_get_temp_dir(), 'inventory-server-'); $logs[] = $serverLog;
    $errorLog = tempnam(sys_get_temp_dir(), 'inventory-error-'); $logs[] = $errorLog;
    $router = "$root/public/index.php";
    if ($pass === '') {
        // An empty DB_PASS would be ignored by Env, so force it explicitly.
        $router = tempnam(sys_get_temp_dir(), 'inventory-router-'); $logs[] = $router;
        file_put_contents($router, '<?php putenv("DB_PASS="); return require ' . var_export("$root/public/index.php", true) . ';');
    }
    // PHP_CLI_SERVER_WORKERS lets the built-in server answer several requests
    // at once (Linux/macOS), which the "parallel requests" check needs.
    $env = array_merge(getenv(), [
        'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $db, 'DB_USER' => $user, 'DB_PASS' => $pass,
        'APP_BASE_PATH' => '', 'PHP_CLI_SERVER_WORKERS' => '6',
    ]);
    $server = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$errorLog", '-S', "127.0.0.1:$httpPort", '-t', "$root/public", $router],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $serverLog, 'w']],
        $pipes, $root, $env
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Cannot start test server.');
    }
    $ready = false;
    for ($i = 0; $i < 50; ++$i) {
        $connection = @fsockopen('127.0.0.1', $httpPort);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    verify($ready, 'Test server starts');

    $staff = $tokens[9];
    $admin = $tokens[10];
    $list = '/products/101/inventory-adjustments';
    $restock = ['quantityChange' => 8, 'reason' => 'restock', 'note' => 'Check restock'];

    // ---------- 1. Who may use the routes ----------
    $before = adjustmentCount($pdo);
    $stock101 = stock($pdo, 101);
    foreach (['GET' => $list, 'POST' => $list, 'GET ' => '/inventory-adjustments/1'] as $method => $path) {
        $method = trim($method);
        $body = $method === 'POST' ? $restock : null;
        verify(callApi($httpPort, $method, $path, null, $body)[0] === 401, "No login: $method $path gives 401");
        verify(callApi($httpPort, $method, $path, str_repeat('a', 64), $body)[0] === 401, "Unknown token: $method $path gives 401");
        verify(callApi($httpPort, $method, $path, $tokens[7], $body)[0] === 403, "Customer: $method $path gives 403");
    }
    verify(adjustmentCount($pdo) === $before && stock($pdo, 101) === $stock101, 'Refused requests change neither the stock nor the audit log');

    // ---------- 2. Recording an adjustment ----------
    [$status, $body, $headers] = callApi($httpPort, 'POST', $list, $staff, $restock);
    verify($status === 201, 'Staff restock returns 201');
    verify($body['productId'] === 101 && $body['actorId'] === 9 && $body['quantityChange'] === 8, 'Response has the product, the signed-in staff member as actor, and the change');
    verify($body['stockBefore'] === $stock101 && $body['stockAfter'] === $stock101 + 8, 'Response shows stock before and after');
    verify($body['reason'] === 'restock' && $body['note'] === 'Check restock', 'Response keeps reason and note');
    verify((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $body['createdAt']), 'createdAt is an ISO UTC date');
    verify(header_value($headers, 'Location') === '/api/v1/inventory-adjustments/' . $body['id'], 'Location header points at the new adjustment');
    verify(stock($pdo, 101) === $stock101 + 8, 'Product stock in the database really changed');
    $firstId = $body['id'];

    [$status, $body] = callApi($httpPort, 'POST', $list, $admin, ['quantityChange' => -3, 'reason' => 'damage']);
    verify($status === 201 && $body['actorId'] === 10 && $body['stockAfter'] === $stock101 + 5 && $body['note'] === null, 'Admin can record damage; missing note is saved as null');
    [$status, $body] = callApi($httpPort, 'POST', $list, $staff, ['quantityChange' => -1, 'reason' => 'correction', 'note' => '   ']);
    verify($status === 201 && $body['note'] === null, 'A correction may go down; a blank note becomes null');
    verify(callApi($httpPort, 'POST', $list, $staff, ['quantityChange' => 2, 'reason' => 'correction'])[0] === 201, 'A correction may go up');

    // A client cannot pick the actor or the balances.
    [$status, $body] = callApi($httpPort, 'POST', $list, $staff, $restock + ['actorId' => 10, 'stockAfter' => 999]);
    verify($status === 422 && isset($body['error']['details']['actorId'], $body['error']['details']['stockAfter']), 'actorId / stockAfter in the body are refused (422)');

    // ---------- 3. Bad bodies ----------
    $before = adjustmentCount($pdo);
    $stockNow = stock($pdo, 101);
    $badBodies = [
        'missing quantityChange' => ['reason' => 'restock'],
        'quantityChange 0' => ['quantityChange' => 0, 'reason' => 'correction'],
        'quantityChange as text' => ['quantityChange' => '5', 'reason' => 'restock'],
        'quantityChange decimal' => ['quantityChange' => 1.5, 'reason' => 'restock'],
        'quantityChange too large' => ['quantityChange' => 1000001, 'reason' => 'restock'],
        'unknown reason' => ['quantityChange' => 1, 'reason' => 'gift'],
        'restock going down' => ['quantityChange' => -1, 'reason' => 'restock'],
        'damage going up' => ['quantityChange' => 1, 'reason' => 'damage'],
        'note not text' => ['quantityChange' => 1, 'reason' => 'restock', 'note' => 5],
        'note too long' => ['quantityChange' => 1, 'reason' => 'restock', 'note' => str_repeat('x', 501)],
    ];
    foreach ($badBodies as $label => $bad) {
        [$status, $body] = callApi($httpPort, 'POST', $list, $staff, $bad);
        verify($status === 422 && isset($body['error']['details']) && isset($body['error']['requestId']), "422 with details for: $label");
    }
    verify(callApi($httpPort, 'POST', $list, $staff, '{"quantityChange": 1,')[0] === 400, 'Broken JSON gives 400');
    verify(callApi($httpPort, 'POST', $list, $staff, '[1, 2]')[0] === 400, 'A JSON array instead of an object gives 400');
    verify(callApi($httpPort, 'POST', $list, $staff, 'quantityChange=1', 'application/x-www-form-urlencoded')[0] === 415, 'Non-JSON Content-Type gives 415');
    verify(adjustmentCount($pdo) === $before && stock($pdo, 101) === $stockNow, 'Bad bodies change nothing');

    // ---------- 4. Stock limits (409) ----------
    $stockNow = stock($pdo, 101);
    [$status, $body] = callApi($httpPort, 'POST', $list, $staff, ['quantityChange' => -($stockNow + 1), 'reason' => 'damage']);
    verify($status === 409 && stock($pdo, 101) === $stockNow, 'Removing more than the stock gives 409 and changes nothing');
    [$status, $body] = callApi($httpPort, 'POST', $list, $staff, ['quantityChange' => -$stockNow, 'reason' => 'correction', 'note' => 'Count to zero']);
    verify($status === 201 && $body['stockAfter'] === 0, 'Going exactly to 0 is allowed');
    verify(callApi($httpPort, 'POST', $list, $staff, ['quantityChange' => 1000000, 'reason' => 'restock'])[0] === 201, 'Going exactly to the 1000000 maximum is allowed');
    verify(callApi($httpPort, 'POST', $list, $staff, ['quantityChange' => 1, 'reason' => 'restock'])[0] === 409, 'Going over the maximum gives 409');

    // ---------- 5. Unknown things (404) ----------
    verify(callApi($httpPort, 'POST', '/products/999999/inventory-adjustments', $staff, $restock)[0] === 404, 'Unknown product on POST gives 404');
    verify(callApi($httpPort, 'GET', '/products/999999/inventory-adjustments', $staff)[0] === 404, 'Unknown product on GET list gives 404');
    verify(callApi($httpPort, 'GET', '/products/4294967296/inventory-adjustments', $staff)[0] === 404, 'Out-of-range product id gives 404');
    verify(callApi($httpPort, 'GET', '/inventory-adjustments/999999', $staff)[0] === 404, 'Unknown adjustment gives 404');
    verify(callApi($httpPort, 'GET', '/inventory-adjustments/0', $staff)[0] === 404, 'Adjustment id 0 gives 404');

    // ---------- 6. Reading ----------
    [$status, $body] = callApi($httpPort, 'GET', "/inventory-adjustments/$firstId", $staff);
    verify($status === 200 && $body['id'] === $firstId && $body['quantityChange'] === 8, 'GET one adjustment returns it');
    [$status, $body] = callApi($httpPort, 'GET', '/inventory-adjustments/1', $admin);
    verify($status === 200 && $body['productId'] === 109 && $body['stockBefore'] === 35 && $body['stockAfter'] === 40, 'The seeded adjustment on product 109 is readable');

    $total = (int) $pdo->query('SELECT COUNT(*) FROM inventory_adjustments WHERE product_id = 101')->fetchColumn();
    [$status, $body] = callApi($httpPort, 'GET', "$list?limit=2", $staff);
    verify($status === 200 && count($body['data']) === 2 && $body['metadata']['total'] === $total
        && $body['metadata']['totalPages'] === (int) ceil($total / 2) && $body['metadata']['page'] === 1, 'List is paginated with correct metadata');
    verify($body['data'][0]['id'] > $body['data'][1]['id'], 'List is newest first');
    [, $page2] = callApi($httpPort, 'GET', "$list?limit=2&page=2", $staff);
    verify($page2['data'][0]['id'] < $body['data'][1]['id'], 'Page 2 continues where page 1 stopped');
    [$status, $body] = callApi($httpPort, 'GET', '/products/102/inventory-adjustments', $staff);
    verify($status === 200 && $body['data'] === [] && $body['metadata']['total'] === 0 && $body['metadata']['totalPages'] === 0, 'A product with no history gives an empty 200 list');
    foreach (['?page=0', '?limit=101', '?limit=abc', '?page[]=1', '?sort=id'] as $query) {
        verify(callApi($httpPort, 'GET', $list . $query, $staff)[0] === 400, "Bad query $query gives 400");
    }

    // ---------- 7. The audit log cannot be edited ----------
    foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
        verify(callApi($httpPort, $method, "/inventory-adjustments/$firstId", $staff)[0] === 405, "$method on an adjustment gives 405");
    }

    // ---------- 8. Every row in the log adds up ----------
    $broken = (int) $pdo->query('SELECT COUNT(*) FROM inventory_adjustments WHERE stock_after <> stock_before + quantity_change')->fetchColumn();
    verify($broken === 0, 'Every audit row satisfies stockAfter = stockBefore + quantityChange');
    $last = $pdo->query('SELECT stock_after FROM inventory_adjustments WHERE product_id = 101 ORDER BY id DESC LIMIT 1')->fetchColumn();
    verify((int) $last === stock($pdo, 101), "The newest audit row matches the product's real stock");

    // ---------- 9. Parallel requests do not lose updates ----------
    // Product 103 is set to 5. Then 10 requests try to remove 1 each, all at once.
    // With the row lock exactly 5 succeed, 5 get 409, and the stock ends at 0.
    $pdo->exec('UPDATE products SET stock = 5 WHERE id = 103');
    $multi = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < 10; ++$i) {
        $h = curl_init("http://127.0.0.1:$httpPort/api/v1/products/103/inventory-adjustments");
        curl_setopt_array($h, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['quantityChange' => -1, 'reason' => 'damage', 'note' => "parallel $i"]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', "Authorization: Bearer $staff"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_multi_add_handle($multi, $h);
        $handles[] = $h;
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi);
    } while ($running > 0);
    $codes = array_map(static fn($h) => curl_getinfo($h, CURLINFO_RESPONSE_CODE), $handles);
    foreach ($handles as $h) { curl_multi_remove_handle($multi, $h); }
    curl_multi_close($multi);
    $counts = array_count_values($codes);
    verify(($counts[201] ?? 0) === 5 && ($counts[409] ?? 0) === 5, '10 parallel removals on stock 5: exactly 5 succeed and 5 get 409');
    verify(stock($pdo, 103) === 0, 'Stock ends at exactly 0, never negative');
    $chain = $pdo->query('SELECT stock_before, stock_after FROM inventory_adjustments WHERE product_id = 103 ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    $expected = [[5, 4], [4, 3], [3, 2], [2, 1], [1, 0]];
    verify(array_map(static fn($r) => [(int) $r[0], (int) $r[1]], $chain) === $expected, 'The 5 audit rows form an unbroken chain 5->4->3->2->1->0');

    // ---------- 10. History blocks deleting a product (existing rule) ----------
    verify(callApi($httpPort, 'DELETE', '/products/103', $staff)[0] === 409, 'A product that now has history cannot be deleted (409)');

    // ---------- 11. Sessions ----------
    $pdo->exec("UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = '" . hash('sha256', $staff) . "'");
    verify(callApi($httpPort, 'GET', $list, $staff)[0] === 401, 'A logged-out staff session is refused');

    echo "$checks inventory-adjustment checks passed. Application database was not used.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $pdo->exec("DROP DATABASE IF EXISTS `$db`");
    foreach ($logs as $log) {
        if (is_file($log)) {
            unlink($log);
        }
    }
}
