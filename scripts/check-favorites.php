<?php
declare(strict_types=1);

// All fixtures and mutations are confined to a random database on the configured test server.
$root = dirname(__DIR__);
$host = getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SCHEMA_TEST_PORT') ?: '3306';
$user = getenv('SCHEMA_TEST_USER') ?: 'root';
$pass = getenv('SCHEMA_TEST_PASS') ?: '';
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = 'foodwell_favorites_' . bin2hex(random_bytes(6));
$server = null;
$logs = [];
$checks = 0;
function verify(bool $result, string $message): void {
    global $checks;
    if (!$result) throw new RuntimeException($message);
    ++$checks; echo "PASS: $message\n";
}
function callApi(int $port, string $method, string $path, ?string $token = null): array {
    $headers = "Accept: application/json\r\n" . ($token === null ? '' : "Authorization: Bearer $token\r\n");
    $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'ignore_errors' => true, 'timeout' => 5]]);
    $raw = file_get_contents("http://127.0.0.1:$port/api/v1$path", false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int)$match[1], json_decode((string)$raw, true), $raw];
}
try {
    $pdo->exec("CREATE DATABASE `$db`");
    $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents("$root/database/schema.sql"));
    $pdo->exec(file_get_contents("$root/database/seed.sql"));
    $session = $pdo->prepare('INSERT INTO auth_sessions(customer_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR)');
    $tokens = [];
    foreach ([7, 8, 9, 10] as $id) { $tokens[$id] = bin2hex(random_bytes(32)); $session->execute([$id, hash('sha256', $tokens[$id])]); }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Cannot reserve test port.');
    $httpPort = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $serverLog = tempnam(sys_get_temp_dir(), 'favorites-server-'); $logs[] = $serverLog;
    $errorLog = tempnam(sys_get_temp_dir(), 'favorites-error-'); $logs[] = $errorLog;
    $router = "$root/public/index.php";
    if ($pass === '') { $router = tempnam(sys_get_temp_dir(), 'favorites-router-'); $logs[] = $router; file_put_contents($router, '<?php putenv("DB_PASS="); return require ' . var_export("$root/public/index.php", true) . ';'); }
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$errorLog", '-S', "127.0.0.1:$httpPort", '-t', "$root/public", $router], [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $serverLog, 'w']], $pipes, $root, array_merge(getenv(), ['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $db, 'DB_USER' => $user, 'DB_PASS' => $pass, 'APP_BASE_PATH' => '']));
    if (!is_resource($server)) throw new RuntimeException('Cannot start test server.');
    $ready = false;
    for ($i = 0; $i < 50; ++$i) { $connection = @fsockopen('127.0.0.1', $httpPort); if ($connection) { fclose($connection); $ready = true; break; } usleep(100000); }
    verify($ready, 'Test server starts');
    $path = '/customers/7/favorites';
    verify(callApi($httpPort, 'GET', $path)[0] === 401, 'Anonymous access denied');
    foreach ([8, 9, 10] as $id) foreach (['GET', 'PUT', 'DELETE'] as $method) {
        verify(callApi($httpPort, $method, $path . ($method === 'GET' ? '' : '/102'), $tokens[$id])[0] === 403, 'Other customers, staff and admin are denied ' . $method);
    }
    verify((int)$pdo->query('SELECT COUNT(*) FROM customer_favorites WHERE customer_id=7 AND product_id=102')->fetchColumn() === 0, 'Denied requests leave favorites unchanged');
    [$status, $body] = callApi($httpPort, 'GET', $path . '?limit=1&page=1', $tokens[7]);
    verify($status === 200 && count($body['data']) === 1 && $body['metadata']['total'] === 2 && $body['metadata']['totalPages'] === 2 && $body['data'][0]['productId'] === 101, 'Owner sees paginated favorites in product order');
    verify(callApi($httpPort, 'GET', $path . '?limit=1&page=2', $tokens[7])[1]['data'][0]['productId'] === 109, 'Second page contains remaining favorite');
    foreach (['?page=0', '?limit=101', '?page[]=1', '?other=1'] as $query) verify(callApi($httpPort, 'GET', $path . $query, $tokens[7])[0] === 400, 'Invalid pagination rejected');
    verify(callApi($httpPort, 'PUT', $path . '/102', $tokens[7])[0] === 204, 'Owner saves a product');
    verify(callApi($httpPort, 'PUT', $path . '/102', $tokens[7])[0] === 204 && (int)$pdo->query('SELECT COUNT(*) FROM customer_favorites WHERE customer_id=7 AND product_id=102')->fetchColumn() === 1, 'Repeated save remains idempotent');
    verify(callApi($httpPort, 'PUT', $path . '/999999', $tokens[7])[0] === 404, 'Unknown product returns 404');
    verify(callApi($httpPort, 'PUT', $path . '/4294967296', $tokens[7])[0] === 404, 'Out-of-range product IDs are rejected');
    verify(callApi($httpPort, 'DELETE', $path . '/102', $tokens[7])[0] === 204, 'Owner removes a favorite');
    verify(callApi($httpPort, 'DELETE', $path . '/102', $tokens[7])[0] === 204, 'Repeated removal is idempotent');
    verify((int)$pdo->query('SELECT COUNT(*) FROM customer_favorites WHERE customer_id=8 AND product_id=107')->fetchColumn() === 1, 'Other customer favorites remain unchanged');
    $pdo->exec('UPDATE products SET is_active = 0 WHERE id = 101');
    [$status, $body] = callApi($httpPort, 'GET', $path, $tokens[7]);
    verify($status === 200 && $body['data'][0]['product']['isActive'] === false, 'Inactive saved products remain visible and marked unavailable');
    $pdo->exec("UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = '" . hash('sha256', $tokens[7]) . "'");
    verify(callApi($httpPort, 'GET', $path, $tokens[7])[0] === 401, 'Revoked sessions are rejected');
    echo "$checks favorite checks passed. Application database was not used.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo->exec("DROP DATABASE IF EXISTS `$db`");
    foreach ($logs as $log) if (is_file($log)) unlink($log);
}
