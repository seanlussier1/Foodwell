<?php
declare(strict_types=1);

// Never load .env: all fixtures live in randomly named temporary databases.
$root = getenv('FOODWELL_TEST_ROOT') ?: dirname(__DIR__);
require $root . '/vendor/autoload.php';
$host = getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SCHEMA_TEST_PORT') ?: '3306';
$user = getenv('SCHEMA_TEST_USER') ?: 'root';
$pass = getenv('SCHEMA_TEST_PASS') ?: '';
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$databases = [];
$servers = [];
$checks = 0;
$serverLogs = [];
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
    echo "PASS: $message\n";
}
function request(int $port, string $method, string $path, ?string $body = null, string $type = 'application/json', array $headers = [], bool $api = true): array {
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 5,
        'header' => "Content-Type: $type\r\nAccept: application/json\r\n" . implode("\r\n", $headers),
        'content' => $body ?? '',
    ]]);
    $prefix = $api ? '/api/v1' : '';
    $raw = file_get_contents("http://127.0.0.1:$port$prefix$path", false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], json_decode((string) $raw, true), $raw, $http_response_header];
}
try {
    foreach ([true, false] as $seeded) {
        $db = 'foodwell_registration_' . bin2hex(random_bytes(6));
        $pdo->exec("CREATE DATABASE `$db`");
        $databases[] = $db;
        $pdo->exec("USE `$db`");
        $pdo->exec(file_get_contents($root . '/database/schema.sql'));
        if ($seeded) { $pdo->exec(file_get_contents($root . '/database/seed.sql')); }
        // Ask the OS for a free loopback port rather than use the app's port.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) { throw new RuntimeException('Cannot reserve a test port.'); }
        $serverPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $serverLog = tempnam(sys_get_temp_dir(), 'foodwell-test-');
        $serverLogs[] = $serverLog;
        $errorLog = tempnam(sys_get_temp_dir(), 'foodwell-errors-');
        $serverLogs[] = $errorLog;
        $router = "$root/public/index.php";
        if ($pass === '') {
            // Windows can omit empty proc_open environment values. Set the
            // intentional blank password inside PHP, before .env is loaded.
            $router = tempnam(sys_get_temp_dir(), 'foodwell-router-');
            $serverLogs[] = $router;
            file_put_contents($router, '<?php putenv("DB_PASS="); return require ' . var_export("$root/public/index.php", true) . ';');
        }
        $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$errorLog", '-S', "127.0.0.1:$serverPort", '-t', "$root/public", $router],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $serverLog, 'w']], $pipes, $root,
            array_merge(getenv(), ['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $db, 'DB_USER' => $user, 'DB_PASS' => $pass, 'APP_BASE_PATH' => '', 'APP_CUSTOMER_PROFILE_DEMO' => $seeded ? '1' : '0', 'CORS_ALLOWED_ORIGINS' => 'http://localhost:8081,http://127.0.0.1:8081']));
        if (!is_resource($server)) { throw new RuntimeException('Cannot start test server.'); }
        $servers[] = $server;
        $ready = false;
        for ($i = 0; $i < 50; ++$i) {
            $connection = @fsockopen('127.0.0.1', $serverPort);
            if ($connection) { fclose($connection); $ready = true; break; }
            usleep(100000);
        }
        check($ready, 'Temporary server starts');
        [$clientStatus, , $clientHtml] = request($serverPort, 'GET', '/client/', null, 'application/json', [], false);
        check($clientStatus === 200 && str_contains($clientHtml, 'Customer workspace'), 'Front controller serves the client HTML');
        [$clientStatus, , $clientJs] = request($serverPort, 'GET', '/client/app.js', null, 'application/json', [], false);
        check($clientStatus === 200 && str_contains($clientJs, 'loadCustomers'), 'Front controller serves only explicitly mapped client assets');
        $origin = ['Origin: http://localhost:8081'];
        [$corsStatus, , $corsBody, $corsHeaders] = request($serverPort, 'OPTIONS', '/customers', null, 'application/json', array_merge($origin, ['Access-Control-Request-Method: PATCH', 'Access-Control-Request-Headers: Content-Type']));
        check($corsStatus === 204 && $corsBody === '' && in_array('Access-Control-Allow-Origin: http://localhost:8081', $corsHeaders, true), 'OPTIONS supports allowed origin without entering resource handlers');
        check(in_array('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, OPTIONS', $corsHeaders, true), 'Preflight advertises the implemented write methods');
        [$badOrigin, , , $badHeaders] = request($serverPort, 'GET', '/customers', null, 'application/json', ['Origin: https://not-allowed.example']);
        check($badOrigin === 403 && !in_array('Access-Control-Allow-Origin: https://not-allowed.example', $badHeaders, true), 'Unknown browser origin is rejected without permissive CORS headers');
        check(request($serverPort, 'OPTIONS', '/customers', null, 'application/json', array_merge($origin, ['Access-Control-Request-Method: DELETE']))[0] === 405, 'Unsupported preflight method is rejected');
        check(request($serverPort, 'OPTIONS', '/customers', null, 'application/json', array_merge($origin, ['Access-Control-Request-Headers: X-Unknown']))[0] === 400, 'Unsupported preflight header is rejected');
        [$status, $body] = request($serverPort, 'GET', '/products');
        if ($status !== 200 || !isset($body['data']) || count($body['data']) !== ($seeded ? 10 : 0)) {
            echo 'Product response: HTTP ' . $status . '; ' . json_encode($body) . "\n";
            echo file_get_contents($serverLog);
            echo file_get_contents($errorLog);
        }
        check($status === 200 && count($body['data']) === ($seeded ? 10 : 0), 'Original populated/empty product list is preserved');
        if (!$seeded) {
            check(request($serverPort, 'GET', '/customers')[0] === 403, 'Customer collection requires explicit local demo opt-in');
            check(request($serverPort, 'GET', '/customers/7')[0] === 403, 'Profile reads are disabled without explicit demo opt-in');
            check(request($serverPort, 'PUT', '/customers/7', '{}')[0] === 403, 'Profile writes are disabled without explicit demo opt-in');
            check(request($serverPort, 'PATCH', '/customers/7', '{"isActive":false}')[0] === 403, 'Deactivation requires explicit demo opt-in');
            continue;
        }
        [$status, $body] = request($serverPort, 'GET', '/products/101');
        check($status === 200 && $body['id'] === 101, 'Original product item remains available');
        check(request($serverPort, 'GET', '/products/999999')[0] === 404, 'Unknown product remains 404');
        check(request($serverPort, 'PUT', '/products/101')[0] === 405, 'Existing method rejection remains 405');
        $before = (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        $input = ['firstName' => '  Sean  ', 'lastName' => ' Example ', 'email' => '  LAB.CUSTOMER@EXAMPLE.COM ', 'password' => 'ExamplePassword2026!', 'address' => '  Demo address  ', 'phone' => null];
        [$status, $body, $raw] = request($serverPort, 'POST', '/customers', json_encode($input), 'application/json; charset=utf-8');
        check($status === 201 && is_int($body['id']), 'Valid registration returns 201 and numeric ID');
        check($body['firstName'] === 'Sean' && $body['email'] === 'lab.customer@example.com' && $body['address'] === 'Demo address', 'Response normalizes names/email/address');
        check(array_keys($body) === ['id', 'firstName', 'lastName', 'email', 'address', 'phone'], 'Response exposes only the documented customer fields');
        $row = $pdo->query('SELECT * FROM customers WHERE id=' . $body['id'])->fetch();
        check($row['email'] === $body['email'] && $row['first_name'] === 'Sean', 'Successful registration persists the returned profile');
        check($row['password_hash'] !== $input['password'] && password_verify($input['password'], $row['password_hash']), 'Stored password is a verifiable hash');
        check($row['role'] === 'customer' && (int) $row['is_active'] === 1, 'Registration uses server-assigned customer role/activation');
        check(!str_contains($raw, $input['password']) && !str_contains($raw, $row['password_hash']), 'Response does not leak password or hash');
        $duplicate = $input;
        $duplicate['email'] = 'lab.customer@example.com';
        check(request($serverPort, 'POST', '/customers', json_encode($duplicate))[0] === 409, 'Case/whitespace-equivalent duplicate returns 409');
        $invalid = $input;
        $invalid['email'] = 'not-an-email'; $invalid['firstName'] = ''; $invalid['password'] = 'short';
        [$status, $body] = request($serverPort, 'POST', '/customers', json_encode($invalid));
        check($status === 422 && isset($body['error']['details']['firstName'], $body['error']['details']['email'], $body['error']['details']['password']), '422 reports multiple field errors together');
        foreach (['role' => 'admin', 'isActive' => false, 'password_hash' => 'fake', 'unexpected' => 1] as $field => $value) {
            $invalid = $input; $invalid[$field] = $value;
            [$status, $body] = request($serverPort, 'POST', '/customers', json_encode($invalid));
            check($status === 422 && isset($body['error']['details'][$field]), "Registration rejects $field");
        }
        foreach (['{', '[]', 'null', '"text"', '1', 'true', ''] as $invalidJson) {
            check(request($serverPort, 'POST', '/customers', $invalidJson)[0] === 400, 'Malformed or non-object JSON returns 400');
        }
        check(request($serverPort, 'POST', '/customers', '{}')[0] === 422, 'Empty object returns field validation errors');
        check(request($serverPort, 'POST', '/customers', json_encode($input), 'text/plain')[0] === 415, 'Unsupported Content-Type returns 415');
        foreach ([['firstName', []], ['phone', 42], ['address', str_repeat('a', 501)], ['lastName', str_repeat('a', 101)], ['password', str_repeat('a', 73)], ['password', "eight\0chars"]] as [$field, $value]) {
            $invalid = $input; $invalid[$field] = $value;
            check(request($serverPort, 'POST', '/customers', json_encode($invalid))[0] === 422, "Invalid type/length for $field returns 422");
        }
        check((int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() === $before + 1, 'All rejected registrations preserve customer rows');
        $unchanged = $pdo->query('SELECT * FROM customers WHERE id=' . $row['id'])->fetch();
        check($unchanged === $row, 'Rejected registrations leave the created account unchanged');
        $minimal = ['firstName' => 'Example', 'lastName' => 'Customer', 'email' => 'minimal@example.com', 'password' => '  spaced password  '];
        [$status, $body] = request($serverPort, 'POST', '/customers', json_encode($minimal));
        check($status === 201 && $body['address'] === null && $body['phone'] === null, 'Optional fields default to null');
        $hash = $pdo->query('SELECT password_hash FROM customers WHERE id=' . $body['id'])->fetchColumn();
        check(password_verify($minimal['password'], $hash) && !password_verify(trim($minimal['password']), $hash), 'Passwords retain leading/trailing spaces');
        [$status, $collection] = request($serverPort, 'GET', '/customers');
        $expectedCustomers = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE role='customer'")->fetchColumn();
        check($status === 200 && $collection['metadata']['page'] === 1 && $collection['metadata']['limit'] === 10 && $collection['metadata']['total'] === $expectedCustomers, 'Customer collection uses documented defaults and excludes staff/admin accounts');
        $id = (int) $row['id'];
        [$status, $profile] = request($serverPort, 'GET', "/customers/$id");
        check($status === 200 && $profile['email'] === $row['email'], 'Profile GET reads a saved customer');
        check(array_keys($profile) === ['id', 'firstName', 'lastName', 'email', 'address', 'phone'], 'Profile GET excludes passwords, roles and activation');
        $update = ['firstName' => '  Updated ', 'lastName' => ' Customer ', 'email' => ' UPDATED@EXAMPLE.COM ', 'address' => ' New address ', 'phone' => ' 514-555-0100 '];
        [$status, $profile] = request($serverPort, 'PUT', "/customers/$id", json_encode($update));
        check($status === 200 && $profile['firstName'] === 'Updated' && $profile['email'] === 'updated@example.com', 'PUT updates and normalizes profile without requiring a password');
        $saved = $pdo->query("SELECT * FROM customers WHERE id=$id")->fetch();
        check($saved['address'] === 'New address' && $saved['phone'] === '514-555-0100', 'Profile changes persist in the database');
        check($saved['password_hash'] === $row['password_hash'] && $saved['role'] === $row['role'] && $saved['is_active'] === $row['is_active'], 'Profile update preserves credentials and privileged fields');
        check(request($serverPort, 'GET', "/customers/$id")[1] === $profile, 'GET reads back the exact PUT result');
        foreach (['password' => 'DifferentPassword!', 'role' => 'admin', 'isActive' => false, 'id' => 7] as $field => $value) {
            $invalid = $update; $invalid[$field] = $value;
            [$status, $body] = request($serverPort, 'PUT', "/customers/$id", json_encode($invalid));
            check($status === 422 && isset($body['error']['details'][$field]), "Profile update rejects $field");
        }
        $invalid = $update; $invalid['firstName'] = ''; $invalid['email'] = 'bad';
        [$status, $body] = request($serverPort, 'PUT', "/customers/$id", json_encode($invalid));
        check($status === 422 && isset($body['error']['details']['firstName'], $body['error']['details']['email']), 'Profile validation returns multiple field errors');
        $duplicate = $update; $duplicate['email'] = 'minimal@example.com';
        check(request($serverPort, 'PUT', "/customers/$id", json_encode($duplicate))[0] === 409, 'Profile update rejects duplicate email');
        check($pdo->query("SELECT * FROM customers WHERE id=$id")->fetch() === $saved, 'Rejected profile updates preserve all account fields');
        check(request($serverPort, 'PUT', "/customers/$id", '{')[0] === 400, 'PUT rejects malformed JSON');
        check(request($serverPort, 'PUT', "/customers/$id", '[]')[0] === 400, 'PUT rejects a non-object body');
        check(request($serverPort, 'PUT', "/customers/$id", json_encode($update), 'text/plain')[0] === 415, 'PUT rejects unsupported media types');
        check(request($serverPort, 'GET', '/customers/999999')[0] === 404, 'GET unknown customer returns 404');
        check(request($serverPort, 'PUT', '/customers/999999', json_encode($update))[0] === 404, 'PUT unknown customer returns 404');
        check(request($serverPort, 'GET', '/customers/999999999999999999999')[0] === 404, 'Oversized customer ID does not overflow');
        check(request($serverPort, 'PUT', "/customers/$id", json_encode($update))[0] === 200, 'Repeated unchanged PUT remains successful');
        unset($update['address'], $update['phone']);
        [$status, $profile] = request($serverPort, 'PUT', "/customers/$id", json_encode($update));
        check($status === 200 && $profile['address'] === null && $profile['phone'] === null, 'PUT clears omitted optional profile fields');
        check(request($serverPort, 'POST', "/customers/$id", '{}')[0] === 405, 'Profile method rejection remains 405');
        $fixture = $pdo->prepare("INSERT INTO customers(first_name,last_name,email,password_hash,is_active) VALUES('Paging','Same',:email,:hash,:active)");
        $fixtureIds = [];
        foreach ([1, 1, 0] as $i => $active) {
            $fixture->execute(['email' => "paging$i@example.com", 'hash' => $hash, 'active' => $active]);
            $fixtureIds[] = (int) $pdo->lastInsertId();
        }
        [$status, $list] = request($serverPort, 'GET', '/customers?page=1&limit=1&search=Paging&isActive=true');
        check($status === 200 && $list['metadata']['total'] === 2 && $list['metadata']['totalPages'] === 2 && $list['metadata']['previous'] === null, 'Both filters combine with correct count and first-page metadata');
        check($list['data'][0]['id'] === $fixtureIds[0] && $list['data'][0]['isActive'] === true, 'Collection uses stable ID ordering and boolean activation');
        check(array_keys($list['data'][0]) === ['id', 'firstName', 'lastName', 'email', 'address', 'phone', 'isActive'], 'Collection excludes passwords, hashes, roles and session fields');
        parse_str((string) parse_url($list['metadata']['next'], PHP_URL_QUERY), $nextQuery);
        check($nextQuery === ['page' => '2', 'limit' => '1', 'search' => 'Paging', 'isActive' => 'true'], 'Next link preserves both filters and limit');
        [$status, $second] = request($serverPort, 'GET', substr($list['metadata']['next'], strlen('/api/v1')));
        check($status === 200 && $second['data'][0]['id'] === $fixtureIds[1] && $second['metadata']['next'] === null, 'Following next returns a distinct record and final-page boundary');
        [$status, $first] = request($serverPort, 'GET', substr($second['metadata']['previous'], strlen('/api/v1')));
        check($status === 200 && $first['data'] === $list['data'], 'Following previous returns the original page');
        [$status, $inactive] = request($serverPort, 'GET', '/customers?search=Paging&isActive=false');
        check($status === 200 && $inactive['metadata']['total'] === 1 && $inactive['data'][0]['id'] === $fixtureIds[2] && $inactive['data'][0]['isActive'] === false, 'Inactive-status filter returns only inactive matches');
        check(request($serverPort, 'GET', '/customers?search=paging')[1]['metadata']['total'] === 3, 'Search is case-insensitive and status is optional');
        check(request($serverPort, 'GET', '/customers?search=Same')[1]['metadata']['total'] === 3, 'Search matches last names');
        [$status, $none] = request($serverPort, 'GET', '/customers?search=definitely-no-matches');
        check($status === 200 && $none['data'] === [] && $none['metadata']['totalPages'] === 0 && $none['metadata']['next'] === null && $none['metadata']['previous'] === null, 'No matches returns 200, empty data and no links');
        [$status, $beyond] = request($serverPort, 'GET', '/customers?page=99&limit=1&search=Paging');
        check($status === 200 && $beyond['data'] === [] && $beyond['metadata']['total'] === 3 && $beyond['metadata']['next'] === null && $beyond['metadata']['previous'] !== null, 'Beyond-final page retains total and links back to final page');
        foreach (['page=0', 'page=-1', 'page=1.5', 'page=99999999999999999999999', 'limit=0', 'limit=101', 'limit=abc', 'page[]=1', 'search[]=name', 'isActive=yes', 'isActive[]=true', 'sort=email', 'search=' . str_repeat('a',101), 'search=%FF'] as $query) {
            [$status, $error] = request($serverPort, 'GET', '/customers?' . $query);
            check($status === 400 && isset($error['error']['details']), "Invalid collection query returns structured 400: $query");
        }
        check(request($serverPort, 'GET', '/customers?search=%25')[1]['metadata']['total'] === 0, 'Search treats percent as literal text, not a wildcard');
        check(request($serverPort, 'GET', '/customers?search=' . rawurlencode("' OR 1=1 --"))[1]['metadata']['total'] === 0, 'Search input is parameterized rather than interpreted as SQL');
        check(request($serverPort, 'GET', '/customers?limit=100&page=2147483647')[0] === 200, 'Largest valid page avoids offset overflow');
        [$corsStatus, , , $corsHeaders] = request($serverPort, 'GET', '/customers?page=0', null, 'application/json', $origin);
        check($corsStatus === 400 && in_array('Access-Control-Allow-Origin: http://localhost:8081', $corsHeaders, true), 'Allowed browser origin can read structured API errors');
        $deactivateId = $fixtureIds[0];
        $session = $pdo->prepare("INSERT INTO auth_sessions(customer_id,token_hash,expires_at) VALUES(:id,:hash,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 1 DAY))");
        $session->execute(['id' => $deactivateId, 'hash' => hash('sha256', 'synthetic-session-token')]);
        $sessionId = (int) $pdo->lastInsertId();
        $beforeDeactivate = $pdo->query("SELECT * FROM customers WHERE id=$deactivateId")->fetch();
        foreach (['{}', '{"isActive":true}', '{"isActive":"false"}', '{"isActive":false,"role":"admin"}'] as $invalidPatch) {
            check(request($serverPort, 'PATCH', "/customers/$deactivateId", $invalidPatch)[0] === 422, 'Deactivation rejects unsupported or privileged patch fields');
        }
        check($pdo->query("SELECT * FROM customers WHERE id=$deactivateId")->fetch() === $beforeDeactivate, 'Rejected PATCH requests preserve account data');
        [$patchStatus, $patched] = request($serverPort, 'PATCH', "/customers/$deactivateId", '{"isActive":false}', 'application/json', $origin);
        check($patchStatus === 200 && $patched['id'] === $deactivateId && $patched['isActive'] === false, 'PATCH deactivates a customer and returns safe state');
        $afterDeactivate = $pdo->query("SELECT * FROM customers WHERE id=$deactivateId")->fetch();
        check((int) $afterDeactivate['is_active'] === 0 && $afterDeactivate['password_hash'] === $beforeDeactivate['password_hash'] && $afterDeactivate['email'] === $beforeDeactivate['email'], 'Deactivation retains profile and password data');
        check($pdo->query("SELECT revoked_at FROM auth_sessions WHERE id=$sessionId")->fetchColumn() !== null, 'Deactivation revokes existing sessions');
        check(request($serverPort, 'PATCH', "/customers/$deactivateId", '{"isActive":false}')[0] === 200, 'Repeated deactivation remains successful');
        check(request($serverPort, 'PATCH', '/customers/999999', '{"isActive":false}')[0] === 404, 'Deactivation of missing customer returns 404');
        $staffId = (int) $pdo->query("SELECT id FROM customers WHERE role='staff' LIMIT 1")->fetchColumn();
        check(request($serverPort, 'PATCH', "/customers/$staffId", '{"isActive":false}')[0] === 403, 'Customer deactivation cannot disable staff accounts');
        check(request($serverPort, 'PATCH', "/customers/$deactivateId", '{')[0] === 400, 'PATCH rejects malformed JSON');
        check(request($serverPort, 'PATCH', "/customers/$deactivateId", '{}', 'text/plain')[0] === 415, 'PATCH rejects unsupported request media');
    }
    echo "$checks checks passed. Application database was not used.\n";
} finally {
    foreach ($servers as $server) { proc_terminate($server); proc_close($server); }
    foreach ($databases as $db) { $pdo->exec("DROP DATABASE `$db`"); }
    foreach ($serverLogs as $serverLog) { unlink($serverLog); }
}
