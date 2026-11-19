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
function callApi(int $port, string $method, string $path, ?string $token = null, ?string $body = null): array {
    $headers = "Accept: application/json\r\nContent-Type: application/json\r\n" . ($token === null ? '' : "Authorization: Bearer $token\r\n");
    $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'ignore_errors' => true, 'timeout' => 5, 'content' => $body ?? '']]);
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
    $address = json_encode(['label'=>'Home','recipientName'=>'Synthetic User','addressLine1'=>'1 Example St','city'=>'Montreal','province'=>'QC','postalCode'=>'H3N 1X1','countryCode'=>'CA']);
    verify(callApi($httpPort, 'GET', '/customers/7/addresses')[0] === 401, 'Anonymous address access denied');
    verify(callApi($httpPort, 'GET', '/customers/7/addresses', $tokens[8])[0] === 403, 'Other customer address access denied');
    verify(callApi($httpPort, 'GET', '/customers/7/addresses', $tokens[10])[0] === 403, 'Admin cannot access another customer address');
    [$status,$body] = callApi($httpPort, 'POST', '/customers/7/addresses', $tokens[7], $address);
    verify($status === 201 && isset($body['id']), 'Customer creates address'); $id=$body['id'];
    verify(callApi($httpPort, 'PATCH', "/customers/7/addresses/$id", $tokens[7], '{"city":"Laval"}')[1]['city'] === 'Laval', 'Owner updates address');
    [$status, $order] = callApi($httpPort, 'POST', '/orders', $tokens[7], json_encode(['customerId'=>7, 'addressId'=>$id]));
    verify($status === 201 && str_contains($order['deliveryAddress'] ?? '', 'Laval'), 'Checkout copies the owned saved address');
    $orderId = $order['id'];
    verify(callApi($httpPort, 'PATCH', "/customers/7/addresses/$id", $tokens[7], '{"city":"Montreal"}')[0] === 200, 'Saved address can be updated after checkout');
    verify(str_contains(callApi($httpPort, 'GET', "/orders/$orderId", $tokens[7])[1]['deliveryAddress'] ?? '', 'Laval'), 'Order keeps its original address snapshot');
    verify(callApi($httpPort, 'DELETE', "/customers/8/addresses/$id", $tokens[8])[0] === 404, 'Other customer cannot delete address');
    verify(callApi($httpPort, 'DELETE', "/customers/7/addresses/$id", $tokens[7])[0] === 204, 'Owner deletes address');
    verify(callApi($httpPort, 'GET', "/customers/7/addresses/$id", $tokens[7])[0] === 404, 'Deleted address is unavailable');
    [$status,$body] = callApi($httpPort, 'POST', '/products/102/reviews', $tokens[7], '{"rating":5,"comment":"Synthetic review"}');
    verify($status === 201 && isset($body['id']), 'Customer creates review'); $id=$body['id'];
    verify(callApi($httpPort, 'GET', "/reviews/$id")[0] === 200, 'Review is public');
    verify(callApi($httpPort, 'PATCH', "/reviews/$id", $tokens[8], '{"rating":1}')[0] === 403, 'Other customer cannot edit review');
    verify(callApi($httpPort, 'PATCH', "/reviews/$id", $tokens[7], '{"rating":4}')[1]['rating'] === 4, 'Author edits review');
    verify(callApi($httpPort, 'POST', '/products/102/reviews', $tokens[7], '{"rating":5,"comment":"Duplicate"}')[0] === 409, 'Duplicate review rejected');
    verify(callApi($httpPort, 'DELETE', "/reviews/$id", $tokens[10])[0] === 204, 'Admin can moderate a review');
    verify(callApi($httpPort, 'GET', "/reviews/$id")[0] === 404, 'Deleted review is unavailable');
    echo "$checks peer checks passed. Application database was not used.\n";

} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo->exec("DROP DATABASE IF EXISTS `$db`");
    foreach ($logs as $log) if (is_file($log)) unlink($log);
}
