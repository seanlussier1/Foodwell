<?php
declare(strict_types=1);

/**
 * Catalog and delivery slot permission checks (#40, B3-2).
 *
 *   php scripts/check-catalog-authorization.php
 *
 * The rule we are proving:
 *   - Anyone can READ products, categories and delivery slots (no login needed).
 *   - Only staff or admin accounts can CHANGE them (create, update, delete).
 *   - No login, or a login that doesn't work anymore  -> 401 Unauthorized
 *   - Logged in, but as a customer                     -> 403 Forbidden
 *   - A refused request never changes anything in the database.
 *
 * Like the other check scripts, this builds a throwaway copy of the database,
 * starts its own API server on it, runs every check and then deletes it all.
 * Your real foodwell database is never touched. It uses the same
 * SCHEMA_TEST_HOST / PORT / USER / PASS settings as the other scripts.
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

$db = 'foodwell_catalog_auth_' . bin2hex(random_bytes(4));
$server = null;
$log = null;
$router = null;
$exitCode = 0;
$checks = 0;

/** Prints PASS, or stops the whole script at the first thing that's wrong. */
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
 * Sends one HTTP request to our temporary API server.
 * $token is the login token to send as "Authorization: Bearer ..." (null = not logged in).
 *
 * @return array{0:int,1:mixed,2:array<string,string>,3:string} status code, JSON body, headers (lower-case names), raw body
 */
function api(string $method, string $path, ?array $body = null, ?string $token = null, ?string $rawAuthorization = null): array
{
    global $serverPort;
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer $token";
    } elseif ($rawAuthorization !== null) {
        $headers[] = "Authorization: $rawAuthorization";   // for testing broken headers
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : json_encode($body),
    ]]);
    $raw = (string) file_get_contents("http://127.0.0.1:$serverPort/api/v1$path", false, $context);

    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    $responseHeaders = [];
    foreach (array_slice($http_response_header, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $responseHeaders[strtolower(trim($name))] = trim($value);
    }
    return [(int) $match[1], json_decode($raw, true), $responseHeaders, $raw];
}

/**
 * Creates a login session for an account directly in the database and returns
 * its token. (Logging in through POST /auth/sessions is already tested by
 * check-auth.php, so here we skip that step.) The database only ever stores a
 * SHA-256 fingerprint of the token, exactly like the real login does.
 */
function session(PDO $pdo, int $accountId, string $expires = '+ INTERVAL 1 HOUR', ?string $revokedAt = null): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_sessions (customer_id, token_hash, created_at, expires_at, revoked_at)
                   VALUES (?, ?, UTC_TIMESTAMP() - INTERVAL 2 HOUR, UTC_TIMESTAMP() $expires, " . ($revokedAt ?? 'NULL') . ')')
        ->execute([$accountId, hash('sha256', $token)]);
    return $token;
}

/** A copy of every catalog table, so we can prove a refused request changed nothing. */
function catalogSnapshot(PDO $pdo): array
{
    $copy = [];
    foreach (['products', 'categories', 'delivery_slots'] as $table) {
        $copy[$table] = $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll();
    }
    return $copy;
}

/** UTC time this many hours from now, on the hour, in the format the API expects. */
function hoursAhead(int $hours): string
{
    return (new DateTimeImmutable(sprintf('%+d hours', $hours), new DateTimeZone('UTC')))->format('Y-m-d\TH:00:00\Z');
}

try {
    // --------------------------------------------------------- set-up
    // 1) A fresh database with the normal demo data.
    $pdo->exec("CREATE DATABASE `$db`");
    $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents("$root/database/schema.sql"));
    $pdo->exec(file_get_contents("$root/database/seed.sql"));

    // 2) Ask the operating system for a free port, then start the API on it.
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $serverPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    // A tiny router file. An empty DB_PASS in the environment isn't picked up
    // by Env::get, so we set it explicitly (same trick as check-products.php).
    $router = tempnam(sys_get_temp_dir(), 'catalog-auth-router-');
    file_put_contents($router, '<?php ' . ($pass === '' ? 'putenv("DB_PASS=");' : '')
        . ' return require ' . var_export("$root/public/index.php", true) . ';');
    $log = tempnam(sys_get_temp_dir(), 'catalog-auth-log-');
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $server = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', "error_log=$log", '-S', "127.0.0.1:$serverPort", '-t', "$root/public", $router],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $log, 'a']],
        $pipes, $root,
        array_merge(getenv(), ['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $db, 'DB_USER' => $user,
            'DB_PASS' => $pass, 'APP_BASE_PATH' => ''])
    );
    // Wait (up to 5 seconds) until the server accepts connections.
    $ready = false;
    for ($i = 0; $i < 50 && !$ready; $i++) {
        $probe = @fsockopen('127.0.0.1', $serverPort);
        if ($probe) { fclose($probe); $ready = true; } else { usleep(100000); }
    }
    check($ready, 'Temporary database and API server started');

    // 3) The people we test with. The seed data has these accounts:
    //    7 = Alex (customer), 9 = Taylor (staff), 10 = Jordan (admin).
    $customer = session($pdo, 7);
    $staff = session($pdo, 9);
    $admin = session($pdo, 10);

    // Tokens that look real but must not work:
    $expired = session($pdo, 9, '- INTERVAL 1 MINUTE');                       // staff, but the session ran out
    $revoked = session($pdo, 9, '+ INTERVAL 1 HOUR', 'UTC_TIMESTAMP()');      // staff, but logged out
    $pdo->exec("INSERT INTO customers (id, first_name, last_name, email, password_hash, role, is_active)
                VALUES (50, 'Former', 'Staff', 'former.staff@example.com', 'x', 'staff', 0)");
    $deactivated = session($pdo, 50);                                       // staff account that was switched off
    $unknown = bin2hex(random_bytes(32));                                   // never existed

    // ===================================================== 1. the code itself
    echo "\n-- Every catalog/slot write route goes through the staff check\n";

    // Read public/index.php and find every route that CHANGES products,
    // categories or delivery slots. Each one must be wrapped in
    // $authorization->protect(..., 'staff'). This catches someone adding a
    // new write route later and forgetting the permission check.
    $source = file_get_contents("$root/public/index.php");
    preg_match_all(
        "#\\\$router->(post|put|patch|delete)\(API_PREFIX \. '(/(?:products|categories|delivery-slots)[^']*)',\s*(.+?)\);#",
        $source, $routes, PREG_SET_ORDER
    );
    check(count($routes) === 8, 'index.php registers 8 catalog/slot write routes (3 product, 2 category, 3 slot)');
    foreach ($routes as [, $method, $path, $handler]) {
        check(str_starts_with($handler, '$authorization->protect(') && str_ends_with($handler, "'staff')"),
            strtoupper($method) . " $path is wrapped in protect(..., 'staff')");
    }

    // ===================================================== 2. reads stay public
    echo "\n-- Anyone can read the catalog (no login needed)\n";

    foreach (['/products', '/products/101', '/categories', '/categories/1', '/delivery-slots', '/delivery-slots/304'] as $path) {
        check(api('GET', $path)[0] === 200, "GET $path works without logging in");
    }
    // Sending a broken or customer token on a public read is simply ignored.
    check(api('GET', '/products/101', null, $unknown)[0] === 200, 'A public read ignores an invalid token');
    check(api('GET', '/delivery-slots', null, $customer)[0] === 200, 'A customer can read delivery slots');

    // ===================================================== 3. refused writes
    echo "\n-- Writes are refused without a working staff/admin login\n";

    // One example request for each write route. The bodies are valid, so the
    // ONLY reason for a refusal is the permission check.
    $slotBody = ['startsAt' => hoursAhead(100), 'endsAt' => hoursAhead(102), 'capacity' => 2];
    $writes = [
        ['POST', '/products', ['name' => 'Blocked Product', 'price' => 1.5, 'categoryId' => 1]],
        ['PUT', '/products/104', ['name' => 'Hacked Apples', 'price' => 0.01, 'categoryId' => 1]],
        ['DELETE', '/products/110', null],
        ['POST', '/categories', ['name' => 'Blocked Category']],
        ['DELETE', '/categories/4', null],
        ['POST', '/delivery-slots', $slotBody],
        ['PUT', '/delivery-slots/304', $slotBody],
        ['DELETE', '/delivery-slots/304', null],
    ];

    // Each "caller" below must get the listed status code on EVERY write route.
    $callers = [
        'no login at all' => [null, null, 401],
        'a token for no session' => [$unknown, null, 401],
        'an expired staff session' => [$expired, null, 401],
        'a logged-out staff session' => [$revoked, null, 401],
        'a deactivated staff account' => [$deactivated, null, 401],
        'a header that is not "Bearer <64 hex>"' => [null, 'Bearer not-a-real-token', 401],
        'a customer' => [$customer, null, 403],
    ];

    foreach ($callers as $who => [$token, $rawHeader, $expected]) {
        foreach ($writes as [$method, $path, $body]) {
            $before = catalogSnapshot($pdo);
            [$status, $json, $headers, $raw] = api($method, $path, $body, $token, $rawHeader);

            check($status === $expected && ($json['error']['status'] ?? null) === $expected,
                "$method $path with $who returns $expected");
            check(catalogSnapshot($pdo) === $before, "...and nothing in the catalog changed");
            if ($expected === 401) {
                // The standard way to tell a client "you need to log in with a Bearer token".
                check(($headers['www-authenticate'] ?? '') === 'Bearer', '...and the response asks for a Bearer token');
            }
            // A refusal must not leak secrets.
            check(!str_contains($raw, 'password_hash') && !str_contains($raw, 'token_hash')
                && ($token === null || !str_contains($raw, $token)), '...and the response leaks no secrets');
        }
    }

    // The permission check happens BEFORE the body is looked at, so a customer
    // can't use validation messages to learn anything about the data.
    [$status, $json] = api('POST', '/products', ['name' => '', 'price' => -5, 'categoryId' => 999], $customer);
    check($status === 403 && !isset($json['error']['details']), 'A customer sending a bad body still just gets 403 (no validation details)');
    [$status] = api('PUT', '/delivery-slots/999999', $slotBody, $customer);
    check($status === 403, 'A customer gets 403 even for a slot that does not exist (no 404 hint)');

    // ===================================================== 4. allowed writes
    echo "\n-- Staff and admin can change the catalog\n";

    // Staff: the full create -> update -> delete cycle on each resource.
    [$status, $product] = api('POST', '/products', ['name' => 'Staff Oat Milk', 'price' => 3.49, 'categoryId' => 2], $staff);
    check($status === 201, 'Staff creates a product (201)');
    [$status, $changed] = api('PUT', "/products/{$product['id']}", ['name' => 'Staff Oat Milk 2L', 'price' => 5.99, 'categoryId' => 2], $staff);
    check($status === 200 && $changed['name'] === 'Staff Oat Milk 2L', 'Staff updates a product (200)');
    check(api('DELETE', "/products/{$product['id']}", null, $staff)[0] === 204, 'Staff deletes a product (204)');

    [$status, $category] = api('POST', '/categories', ['name' => 'Staff Frozen'], $staff);
    check($status === 201, 'Staff creates a category (201)');
    check(api('DELETE', "/categories/{$category['id']}", null, $staff)[0] === 200, 'Staff deletes an empty category (200)');

    [$status, $slot] = api('POST', '/delivery-slots', $slotBody, $staff);
    check($status === 201, 'Staff creates a delivery slot (201)');
    check(api('PUT', "/delivery-slots/{$slot['id']}", $slotBody + ['isActive' => false], $staff)[0] === 200, 'Staff updates a delivery slot (200)');
    check(api('DELETE', "/delivery-slots/{$slot['id']}", null, $staff)[0] === 204, 'Staff deletes a delivery slot (204)');

    // Admin: same permission as staff for the catalog.
    [$status, $product] = api('POST', '/products', ['name' => 'Admin Bagels', 'price' => 4.25, 'categoryId' => 3], $admin);
    check($status === 201, 'Admin creates a product (201)');
    check(api('PUT', "/products/{$product['id']}", ['name' => 'Admin Bagels 6pk', 'price' => 4.5, 'categoryId' => 3], $admin)[0] === 200,
        'Admin updates a product (200)');
    check(api('DELETE', "/products/{$product['id']}", null, $admin)[0] === 204, 'Admin deletes a product (204)');
    [$status, $category] = api('POST', '/categories', ['name' => 'Admin Snacks'], $admin);
    check($status === 201 && api('DELETE', "/categories/{$category['id']}", null, $admin)[0] === 200, 'Admin creates and deletes a category');
    [$status, $slot] = api('POST', '/delivery-slots', ['startsAt' => hoursAhead(120), 'endsAt' => hoursAhead(121), 'capacity' => 1], $admin);
    check($status === 201 && api('DELETE', "/delivery-slots/{$slot['id']}", null, $admin)[0] === 204, 'Admin creates and deletes a delivery slot');

    // A staff request that fails for a normal reason (bad data) is still a
    // normal 422, which shows the login worked and the body was then checked.
    [$status, $json] = api('POST', '/products', ['name' => '', 'price' => -5, 'categoryId' => 1], $staff);
    check($status === 422 && isset($json['error']['details']['price']), 'Staff with a bad body gets the normal 422 validation error');

    echo "\n$checks catalog authorization checks passed. Application database was not used.\n";
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
