<?php
declare(strict_types=1);

/**
 * Demo rehearsal for Will's section: catalog, categories, delivery slots (#44)
 * and inventory adjustments (#113, added for the final candidate).
 *
 *   php scripts/demo-catalog-rehearsal.php
 *
 * It plays the demo in the same order you would present it, prints every
 * request with its status code, and prints the database state at the key
 * moments, so you can see that each step really changed (or didn't change)
 * the data. Every step also checks the expected result; the script stops at
 * the first surprise and exits with 1.
 *
 * Like the check scripts, it uses the SCHEMA_TEST_HOST / PORT / USER / PASS
 * settings, creates its own throwaway seeded database and API server, and
 * deletes them at the end. Your real foodwell database is never touched.
 * Login uses the real POST /auth/sessions with the synthetic seed accounts.
 *
 * To run catalog requests with Newman, see the testing guide in docs/TESTING.md.
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
$db = 'foodwell_demo_' . bin2hex(random_bytes(4));
$server = null;
$router = null;
$exitCode = 1;

/** Sends one request, prints "METHOD path => status", and returns [status, json, headers, raw body]. */
function call(string $method, string $path, ?array $body = null, ?string $token = null, string $accept = 'application/json'): array
{
    global $serverPort;
    $headers = ["Accept: $accept"];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = "Authorization: Bearer $token";
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body),
    ]]);
    $raw = (string) file_get_contents("http://127.0.0.1:$serverPort/api/v1$path", false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    $responseHeaders = [];
    foreach (array_slice($http_response_header, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $responseHeaders[strtolower(trim($name))] = trim($value);
    }
    $status = (int) $match[1];
    echo "$method $path => $status\n";
    return [$status, json_decode($raw, true), $responseHeaders, $raw];
}

/** Stops the rehearsal if something isn't what the demo expects. */
function expect(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException("UNEXPECTED: $what");
    }
    echo "  ok: $what\n";
}

/** Prints the database facts the audience cares about at this moment. */
function state(PDO $pdo, string $moment, array $extra = []): void
{
    $facts = [
        'products' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
        'categories' => (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
        'slots' => (int) $pdo->query('SELECT COUNT(*) FROM delivery_slots')->fetchColumn(),
    ];
    echo "  [database] $moment " . json_encode($facts + $extra) . "\n";
}

/** The UTC time this many hours from now, on the hour. */
function hoursAhead(int $hours): string
{
    return (new DateTimeImmutable(sprintf('%+d hours', $hours), new DateTimeZone('UTC')))->format('Y-m-d\TH:00:00\Z');
}

try {
    // ------------------------------------------------------------- set-up
    $pdo->exec("CREATE DATABASE `$db`");
    $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents("$root/database/schema.sql"));
    $pdo->exec(file_get_contents("$root/database/seed.sql"));

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $serverPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $router = tempnam(sys_get_temp_dir(), 'demo-router-');
    file_put_contents($router, '<?php ' . ($pass === '' ? 'putenv("DB_PASS=");' : '')
        . ' return require ' . var_export("$root/public/index.php", true) . ';');
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    // Login needs AUTH_LIMITER_SECRET; a random one is fine for a throwaway server.
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$serverPort", '-t', "$root/public", $router],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
        $pipes, $root,
        array_merge(getenv(), ['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $db, 'DB_USER' => $user,
            'DB_PASS' => $pass, 'APP_BASE_PATH' => '', 'AUTH_LIMITER_SECRET' => bin2hex(random_bytes(32))])
    );
    for ($i = 0, $ready = false; $i < 50 && !$ready; $i++) {
        $probe = @fsockopen('127.0.0.1', $serverPort);
        if ($probe) { fclose($probe); $ready = true; } else { usleep(100000); }
    }
    expect($ready, 'disposable database and API server started');
    state($pdo, 'start');

    // =============================================== 1. anyone can browse
    echo "\n== 1. Browsing the catalog (no login)\n";
    [$status, $page] = call('GET', '/products?category=2&sort=-price&limit=2');
    expect($status === 200 && array_column($page['data'], 'id') === [102, 101] && $page['meta']['total'] === 3,
        'Dairy sorted by price, page 1 of 2 (Old Cheddar, Whole Milk), total 3');
    expect(str_contains((string) $page['meta']['next'], 'page=2'), 'the next link keeps the filters and points to page 2');
    [$status, , $headers, $raw] = call('GET', '/products/101', null, null, 'application/xml');
    expect($status === 200 && str_contains($headers['content-type'], 'xml') && str_contains($raw, '<name>Whole Milk 2L</name>'),
        'the same product as XML when the client asks for XML');
    [$status, $slots] = call('GET', '/delivery-slots?available=true');
    expect($status === 200 && array_column($slots['data'], 'id') === [304], 'only slot 304 can be booked right now (the others are full or past)');

    // =============================================== 2. who may change it
    echo "\n== 2. Logins and permissions\n";
    [, $login] = call('POST', '/auth/sessions', ['email' => 'alex@example.com', 'password' => 'FoodwellDemo!2026']);
    $alex = $login['accessToken'];
    [, $login] = call('POST', '/auth/sessions', ['email' => 'staff@example.com', 'password' => 'FoodwellDemo!2026']);
    $staff = $login['accessToken'];
    expect(is_string($alex) && is_string($staff), 'signed in as Alex (customer) and Taylor (staff)');

    $product = ['name' => 'Frozen Peas 750g', 'price' => 2.79, 'categoryId' => 1, 'stock' => 30];
    [$status, , $headers] = call('POST', '/products', $product);
    expect($status === 401 && $headers['www-authenticate'] === 'Bearer', 'no login: 401 and the API asks for a Bearer token');
    [$status] = call('POST', '/products', $product, $alex);
    expect($status === 403, 'a customer is logged in but not allowed: 403');
    state($pdo, 'after the refused requests (nothing changed)');

    // =============================================== 3. staff manages the catalog
    echo "\n== 3. Staff manages products and categories\n";
    [$status, $category] = call('POST', '/categories', ['name' => 'Frozen'], $staff);
    expect($status === 201, "new category Frozen (id {$category['id']})");
    [$status] = call('POST', '/categories', ['name' => '  FROZEN '], $staff);
    expect($status === 409, 'the same name again (any case, extra spaces) is a 409 duplicate');

    [$status, $created, $headers] = call('POST', '/products', ['categoryId' => $category['id']] + $product, $staff);
    expect($status === 201 && $created['categoryName'] === 'Frozen' && str_ends_with($headers['location'], "/products/{$created['id']}"),
        "new product {$created['id']} in Frozen, with a Location header");
    [$status, $changed] = call('PUT', "/products/{$created['id']}",
        ['name' => 'Frozen Peas 750g', 'price' => 2.49, 'categoryId' => $category['id'], 'isActive' => false], $staff);
    expect($status === 200 && $changed['price'] === 2.49 && $changed['isActive'] === false && $changed['stock'] === 30,
        'price lowered and product deactivated; stock untouched (it only changes through inventory adjustments)');
    state($pdo, 'after creating the category and product');

    [$status, $error] = call('DELETE', "/categories/{$category['id']}", null, $staff);
    expect($status === 409, 'Frozen still has a product, so it cannot be deleted: 409');
    [$status, $error] = call('DELETE', '/products/109', null, $staff);
    expect($status === 409 && str_contains($error['error']['message'], 'Deactivate it instead'),
        'Basmati Rice has stock history, so it cannot be deleted: 409 (deactivate instead)');
    [$status] = call('DELETE', "/products/{$created['id']}", null, $staff);
    expect($status === 204, 'the new product (no history) is deleted: 204');
    [$status] = call('DELETE', "/categories/{$category['id']}", null, $staff);
    expect($status === 200, 'now the empty category can be deleted');
    state($pdo, 'back to the seeded catalog');

    // =============================================== 4. slots and checkout
    echo "\n== 4. Delivery slots and checkout\n";
    $window = ['startsAt' => hoursAhead(120), 'endsAt' => hoursAhead(122)];
    [$status, $slot] = call('POST', '/delivery-slots', $window + ['capacity' => 2], $staff);
    expect($status === 201 && $slot['isAvailable'] === true && $slot['bookedCount'] === 0,
        "new 2-hour slot {$slot['id']} in 5 days, capacity 2, bookable");

    [$status, $order] = call('POST', '/orders',
        ['customerId' => 7, 'deliveryAddress' => '123 Demo Street, Montreal, QC', 'deliverySlotId' => $slot['id']], $alex);
    expect($status === 201 && $order['deliverySlotId'] === $slot['id'], "Alex checks out with that slot: order {$order['id']}");
    [, $slot] = call('GET', "/delivery-slots/{$slot['id']}");
    expect($slot['bookedCount'] === 1 && $slot['availableCapacity'] === 1, 'the slot now shows 1 booked, 1 place left');
    $booked = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE delivery_slot_id = {$slot['id']} AND status <> 'cancelled'")->fetchColumn();
    state($pdo, 'after checkout', ['ordersOnNewSlot' => $booked]);

    [$status, $error] = call('PUT', "/delivery-slots/{$slot['id']}", ['startsAt' => hoursAhead(124), 'endsAt' => hoursAhead(126), 'capacity' => 2], $staff);
    expect($status === 409 && str_contains($error['error']['message'], 'cannot change'), 'moving a booked window is refused: 409');
    [$status, $slot] = call('PUT', "/delivery-slots/{$slot['id']}", $window + ['capacity' => 2, 'isActive' => false], $staff);
    expect($status === 200 && $slot['isAvailable'] === false && $slot['bookedCount'] === 1,
        'deactivating is allowed: no new bookings, and Alex keeps the booking');
    [$status] = call('DELETE', "/delivery-slots/{$slot['id']}", null, $staff);
    expect($status === 409, 'a slot used by an order cannot be deleted: 409');
    $kept = $pdo->query("SELECT delivery_slot_id FROM orders WHERE id = {$order['id']}")->fetchColumn();
    state($pdo, 'after the slot changes', ['order' . $order['id'] . 'Slot' => (int) $kept]);

    // =============================================== 5. bad input
    echo "\n== 5. Bad input gets a clear answer\n";
    [$status, $error, $headers] = call('GET', '/products?sort=price&sort=name');
    expect($status === 400 && str_contains($error['error']['message'], 'sort was given 2 times'),
        'a repeated sort key is a 400 that says what is wrong');
    expect($error['error']['requestId'] === $headers['x-request-id'], 'the error carries the same request id as the X-Request-ID header (for finding it in the log)');
    [$status, $error] = call('POST', '/delivery-slots', ['startsAt' => hoursAhead(50), 'endsAt' => hoursAhead(48), 'capacity' => 0], $staff);
    expect($status === 422 && isset($error['error']['details']['endsAt'], $error['error']['details']['capacity']),
        'a slot ending before it starts, with capacity 0: one 422 naming both fields');

    // =============================================== 6. inventory adjustments
    echo "\n== 6. Stock changes go through inventory adjustments\n";
    $stockOf = static fn (int $id): int => (int) $pdo->query("SELECT stock FROM products WHERE id = $id")->fetchColumn();
    $auditRows = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn();
    $start = $stockOf(101); // read from the database: the checkout above may have used some milk
    state($pdo, 'before the adjustments', ['wholeMilkStock' => $start, 'auditRows' => $auditRows()]);

    [$status] = call('POST', '/products/101/inventory-adjustments', ['quantityChange' => 12, 'reason' => 'restock'], $alex);
    expect($status === 403, 'a customer cannot change stock: 403');
    [$status, $restock, $headers] = call('POST', '/products/101/inventory-adjustments',
        ['quantityChange' => 12, 'reason' => 'restock', 'note' => 'Tuesday delivery'], $staff);
    expect($status === 201 && $restock['stockBefore'] === $start && $restock['stockAfter'] === $start + 12 && $restock['actorId'] === 9,
        "restock +12: stock $start -> " . ($start + 12) . ', recorded as staff account 9 by the server');
    expect(str_ends_with($headers['location'], "/inventory-adjustments/{$restock['id']}") && $stockOf(101) === $start + 12,
        'Location points at the new audit row, and the product row really changed');

    [$status, $error] = call('POST', '/products/101/inventory-adjustments',
        ['quantityChange' => -($start + 13), 'reason' => 'damage'], $staff);
    expect($status === 409 && str_contains($error['error']['message'], 'below 0') && $stockOf(101) === $start + 12,
        'removing more than the stock is a 409 and changes nothing (stock never goes below 0)');
    [$status, $error] = call('POST', '/products/101/inventory-adjustments', ['quantityChange' => 3, 'reason' => 'damage'], $staff);
    expect($status === 422 && isset($error['error']['details']['quantityChange']), 'damage that adds stock is a 422 naming quantityChange');
    [$status, $error] = call('POST', '/products/101/inventory-adjustments',
        ['quantityChange' => 1, 'reason' => 'correction', 'actorId' => 10], $staff);
    expect($status === 422 && isset($error['error']['details']['actorId']), 'the client cannot choose who made the change: 422');

    [$status, $history] = call('GET', '/products/101/inventory-adjustments?limit=5', null, $staff);
    expect($status === 200 && $history['metadata']['total'] === 1 && $history['data'][0]['id'] === $restock['id'],
        'the history lists exactly the one saved change (refused requests left no rows)');
    [$status] = call('DELETE', "/inventory-adjustments/{$restock['id']}", null, $staff);
    expect($status === 405, 'audit rows cannot be deleted: 405');
    [$status] = call('DELETE', '/products/101', null, $staff);
    expect($status === 409, 'Whole Milk now has stock history, so it cannot be deleted either: 409');
    state($pdo, 'after the adjustments', ['wholeMilkStock' => $stockOf(101), 'auditRows' => $auditRows()]);

    echo "\nPASS: catalog, delivery slot and inventory demo rehearsal. The disposable database is dropped now.\n";
    $exitCode = 0;
} catch (Throwable $e) {
    echo "\n" . $e->getMessage() . "\n";
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
