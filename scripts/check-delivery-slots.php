<?php
declare(strict_types=1);

/**
 * Delivery slot checks (#39, B3-1): CRUD, date/available filters, staff-only
 * writes, booking rules and attaching a slot to an order at checkout.
 *
 *   php scripts/check-delivery-slots.php
 *
 * Uses SCHEMA_TEST_HOST/PORT/USER/PASS like the other check scripts. Creates a
 * disposable seeded database and API server, then drops them. The application
 * database is never touched.
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

$db = 'foodwell_slots_' . bin2hex(random_bytes(4));
$server = null;
$log = null;
$router = null;
$exitCode = 0;
$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException("FAIL: $message");
    }
    ++$checks;
    echo "PASS: $message\n";
}

/** @return array{0:int,1:mixed,2:array<string,string>} status, decoded JSON, lower-case headers */
function api(string $method, string $path, mixed $body = null, ?string $token = null, array $headers = []): array
{
    global $serverPort;
    $headers += ['Accept' => 'application/json'];
    if ($body !== null) {
        $headers += ['Content-Type' => 'application/json'];
    }
    if ($token !== null) {
        $headers['Authorization'] = "Bearer $token";
    }
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "$name: $value";
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => implode("\r\n", $lines),
        'content' => $body === null ? '' : (is_string($body) ? $body : json_encode($body)),
    ]]);
    $raw = (string) file_get_contents("http://127.0.0.1:$serverPort/api/v1$path", false, $context);

    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    $responseHeaders = [];
    foreach (array_slice($http_response_header, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $responseHeaders[strtolower(trim($name))] = trim($value);
    }
    return [(int) $match[1], json_decode($raw, true), $responseHeaders];
}

/** A live session straight in the table (login itself is covered by check-auth). */
function session(PDO $pdo, int $customerId): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO auth_sessions (customer_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR)')
        ->execute([$customerId, hash('sha256', $token)]);
    return $token;
}

function ids(array $list): array
{
    return array_column($list['data'], 'id');
}

/** UTC 'Y-m-d\TH:i:s\Z' that many hours from now, on the hour. */
function hoursAhead(int $hours): string
{
    $time = new DateTimeImmutable(sprintf('%+d hours', $hours), new DateTimeZone('UTC'));   // "-3", not "+-3"
    return $time->format('Y-m-d\TH:00:00\Z');
}

function slotCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM delivery_slots')->fetchColumn();
}

try {
    // ---------------------------------------------------------- fixtures
    $pdo->exec("CREATE DATABASE `$db`");
    $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents("$root/database/schema.sql"));
    $pdo->exec(file_get_contents("$root/database/seed.sql"));

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $serverPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    // DB_PASS="" in the environment is not enough for Env::get; force it like check-products does.
    $router = tempnam(sys_get_temp_dir(), 'slots-router-');
    file_put_contents($router, '<?php ' . ($pass === '' ? 'putenv("DB_PASS=");' : '')
        . ' return require ' . var_export("$root/public/index.php", true) . ';');
    $log = tempnam(sys_get_temp_dir(), 'slots-log-');
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $server = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-d', "error_log=$log", '-S', "127.0.0.1:$serverPort", '-t', "$root/public", $router],
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
    check($ready, 'Disposable seeded database and API server started');

    $customer = session($pdo, 7);   // Alex, customer
    $staff = session($pdo, 9);      // Taylor, staff
    $admin = session($pdo, 10);     // Jordan, admin

    // Seed: 301 tomorrow cap 2 (2 booked, full) · 302 in 2 days cap 1 (full)
    //       303 yesterday, inactive · 304 in 3 days cap 3 (free)
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $day = fn(int $offset): string => $today->modify("+$offset day")->format('Y-m-d');

    // ===================================================== reads (public)
    echo "\n-- Reads and filters (no session needed)\n";

    [$status, $list, $headers] = api('GET', '/delivery-slots');
    check($status === 200 && str_starts_with($headers['content-type'], 'application/json'), 'GET /delivery-slots returns 200 JSON without a session');
    check(ids($list) === [303, 301, 302, 304], 'List holds every slot, ordered by start time');

    [$status, $slot] = api('GET', '/delivery-slots/301');
    $row = $pdo->query('SELECT starts_at, ends_at, capacity FROM delivery_slots WHERE id = 301')->fetch();
    check($status === 200 && $slot['startsAt'] === str_replace(' ', 'T', $row['starts_at']) . 'Z'
        && $slot['capacity'] === (int) $row['capacity'], 'Item matches its database row, times as UTC ISO 8601');
    check($slot['bookedCount'] === 2 && $slot['availableCapacity'] === 0 && $slot['isAvailable'] === false,
        'Full slot 301: 2 booked, 0 left, not available');
    check(api('GET', '/delivery-slots/304')[1]['isAvailable'] === true, 'Free future slot 304 is available');
    [, $past] = api('GET', '/delivery-slots/303');
    check($past['isActive'] === false && $past['isAvailable'] === false, 'Past inactive slot 303 is not available');

    [$status, $body] = api('GET', '/delivery-slots/999999');
    check($status === 404 && $body['error']['message'] === 'Delivery slot 999999 not found.', 'Unknown slot returns 404');

    check(ids(api('GET', '/delivery-slots?available=true')[1]) === [304], 'available=true keeps only bookable slots');
    check(ids(api('GET', '/delivery-slots?available=false')[1]) === [303, 301, 302], 'available=false returns the rest');
    check(ids(api('GET', '/delivery-slots?date=' . $day(1))[1]) === [301], 'date=tomorrow returns the slot starting that UTC day');
    check(ids(api('GET', '/delivery-slots?date=' . $day(3) . '&available=true')[1]) === [304], 'date and available combine (AND)');
    check(ids(api('GET', '/delivery-slots?date=' . $day(30))[1]) === [], 'A day without slots returns 200 and an empty list');
    check(ids(api('GET', '/delivery-slots?date=&available=')[1]) === [303, 301, 302, 304], 'Empty filter values are ignored');

    foreach ([
        '?date=2026-02-30' => 'date', '?date=20-11-2026' => 'date', '?available=yes' => 'available',
        '?available=1' => 'available', '?sort=date' => 'sort', '?date[]=2026-11-20' => 'date',
    ] as $query => $field) {
        [$status, $body] = api('GET', "/delivery-slots$query");
        check($status === 400 && isset($body['error']['details'][$field]), "Bad query $query returns 400 naming $field");
    }

    // ===================================================== permissions
    echo "\n-- Writes need a staff or admin session\n";

    $new = ['startsAt' => hoursAhead(48), 'endsAt' => hoursAhead(50), 'capacity' => 2];
    $before = slotCount($pdo);
    [$status, , $headers] = api('POST', '/delivery-slots', $new);
    check($status === 401 && ($headers['www-authenticate'] ?? '') === 'Bearer', 'POST without a session returns 401 with a Bearer challenge');
    check(api('POST', '/delivery-slots', $new, $customer)[0] === 403, 'POST as a customer returns 403');
    check(api('PUT', '/delivery-slots/304', $new, $customer)[0] === 403, 'PUT as a customer returns 403');
    check(api('DELETE', '/delivery-slots/304', null, $customer)[0] === 403, 'DELETE as a customer returns 403');
    check(slotCount($pdo) === $before && api('GET', '/delivery-slots/304')[1]['capacity'] === 3, 'Refused writes changed nothing');

    // ===================================================== create
    echo "\n-- Create\n";

    [$status, $created, $headers] = api('POST', '/delivery-slots', $new, $staff);
    check($status === 201 && $created['startsAt'] === $new['startsAt'] && $created['endsAt'] === $new['endsAt']
        && $created['capacity'] === 2 && $created['isActive'] === true && $created['bookedCount'] === 0
        && $created['isAvailable'] === true, 'Staff creates a slot: 201, active by default, nothing booked');
    $newId = $created['id'];
    check(($headers['location'] ?? '') === "/api/v1/delivery-slots/$newId", 'Location header points at the new slot');
    check(api('GET', "/delivery-slots/$newId")[1] == $created, 'GET returns the same slot that POST returned');

    // A Montreal time (UTC-5 in November) is stored in UTC.
    $local = (new DateTimeImmutable('+5 days', new DateTimeZone('America/Toronto')))->setTime(9, 0);
    [$status, $offsetSlot] = api('POST', '/delivery-slots', [
        'startsAt' => $local->format('Y-m-d\TH:i:sP'), 'endsAt' => $local->modify('+2 hours')->format('Y-m-d\TH:iP'),
        'capacity' => 1, 'isActive' => false,
    ], $admin);
    $expectedUtc = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    check($status === 201 && $offsetSlot['startsAt'] === $expectedUtc && $offsetSlot['isActive'] === false,
        'Admin may create; a -05:00 time (and no seconds) is converted to UTC');

    [$status, $body] = api('POST', '/delivery-slots', $new, $staff);
    check($status === 409 && str_contains($body['error']['message'], 'same startsAt and endsAt'), 'Same window twice returns 409');

    $invalid = [
        'missing everything' => [new stdClass(), ['startsAt', 'endsAt', 'capacity']],
        'end before start' => [['startsAt' => hoursAhead(30), 'endsAt' => hoursAhead(29), 'capacity' => 1], ['endsAt']],
        'equal start and end' => [['startsAt' => hoursAhead(30), 'endsAt' => hoursAhead(30), 'capacity' => 1], ['endsAt']],
        'window over 12 hours' => [['startsAt' => hoursAhead(30), 'endsAt' => hoursAhead(43), 'capacity' => 1], ['endsAt']],
        'start in the past' => [['startsAt' => hoursAhead(-3), 'endsAt' => hoursAhead(-1), 'capacity' => 1], ['startsAt']],
        'over a year ahead' => [['startsAt' => hoursAhead(24 * 400), 'endsAt' => hoursAhead(24 * 400 + 2), 'capacity' => 1], ['startsAt']],
        'no time zone' => [['startsAt' => '2027-01-10T14:00:00', 'endsAt' => '2027-01-10T16:00:00Z', 'capacity' => 1], ['startsAt']],
        'impossible date' => [['startsAt' => '2027-02-30T14:00:00Z', 'endsAt' => '2027-02-30T16:00:00Z', 'capacity' => 1], ['startsAt', 'endsAt']],
        'capacity 0' => [['capacity' => 0] + $new, ['capacity']],
        'capacity as text' => [['capacity' => '2'] + $new, ['capacity']],
        'capacity 2.5' => [['capacity' => 2.5] + $new, ['capacity']],
        'isActive as text' => [$new + ['isActive' => 'yes'], ['isActive']],
        'unknown field' => [$new + ['availableCapacity' => 9], ['availableCapacity']],
    ];
    foreach ($invalid as $label => [$input, $fields]) {
        [$status, $body] = api('POST', '/delivery-slots', $input, $staff);
        $details = array_keys($body['error']['details'] ?? []);
        sort($details);
        sort($fields);
        check($status === 422 && $details === $fields, "POST $label returns 422 naming " . implode(', ', $fields));
    }
    check(api('POST', '/delivery-slots', '{"startsAt":', $staff)[0] === 400, 'Malformed JSON returns 400');
    check(api('POST', '/delivery-slots', '[1,2]', $staff)[0] === 400, 'A JSON array instead of an object returns 400');
    check(api('POST', '/delivery-slots', json_encode($new), $staff, ['Content-Type' => 'text/plain'])[0] === 415, 'Wrong Content-Type returns 415');
    check(slotCount($pdo) === $before + 2, 'Only the two valid POSTs created rows');

    // ===================================================== attach to an order
    echo "\n-- Attaching a slot to an order (POST /orders deliverySlotId)\n";

    $pdo->exec('UPDATE products SET stock = 50 WHERE id IN (101, 102)');
    $cart = fn() => api('POST', '/customers/7/cart/items', ['productId' => 101, 'quantity' => 1], $customer);
    check(in_array($cart()[0], [200, 201], true), 'Customer adds a product to the cart');
    [$status, $order] = api('POST', '/orders', ['customerId' => 7, 'deliveryAddress' => '123 Demo Street, Montreal, QC',
        'deliverySlotId' => $newId], $customer);
    check($status === 201 && $order['deliverySlotId'] === $newId, 'Checkout attaches the new slot to the order');
    [, $slot] = api('GET', "/delivery-slots/$newId");
    check($slot['bookedCount'] === 1 && $slot['availableCapacity'] === 1, 'The slot now counts 1 booked order, 1 place left');

    $cart();
    [$status, $body] = api('POST', '/orders', ['customerId' => 7, 'deliveryAddress' => 'x', 'deliverySlotId' => 302], $customer);
    check($status === 409 && str_contains($body['error']['message'], 'slot is unavailable'), 'Checkout refuses a full slot (409)');
    [$status] = api('POST', '/orders', ['customerId' => 7, 'deliveryAddress' => 'x', 'deliverySlotId' => $offsetSlot['id']], $customer);
    check($status === 409, 'Checkout refuses an inactive slot (409)');

    // ===================================================== update
    echo "\n-- Update\n";

    $booked = ['startsAt' => $new['startsAt'], 'endsAt' => $new['endsAt']];
    [$status, $body] = api('PUT', "/delivery-slots/$newId", $booked + ['capacity' => 0], $staff);
    check($status === 422, 'Capacity 0 on PUT is a 422 validation error');
    check(api('PUT', "/delivery-slots/$newId", $booked + ['capacity' => 1], $staff)[0] === 200, 'Capacity can drop to exactly the booked count');
    [, $slot] = api('GET', "/delivery-slots/$newId");
    check($slot['availableCapacity'] === 0 && $slot['isAvailable'] === false, '...which makes the slot full');
    [$status, $body] = api('PUT', '/delivery-slots/301', [
        'startsAt' => api('GET', '/delivery-slots/301')[1]['startsAt'], 'endsAt' => api('GET', '/delivery-slots/301')[1]['endsAt'],
        'capacity' => 1,
    ], $staff);
    check($status === 409 && str_contains($body['error']['message'], 'cannot be lower'), 'Capacity below the booked orders returns 409');

    [$status, $body] = api('PUT', "/delivery-slots/$newId", ['startsAt' => hoursAhead(60), 'endsAt' => hoursAhead(62), 'capacity' => 4], $staff);
    check($status === 409 && str_contains($body['error']['message'], 'cannot change'), 'Moving a window that has bookings returns 409');
    check(api('GET', "/delivery-slots/$newId")[1]['startsAt'] === $new['startsAt'], '...and the window stayed put');

    [$status, $slot] = api('PUT', "/delivery-slots/$newId", $booked + ['capacity' => 3, 'isActive' => false], $staff);
    check($status === 200 && $slot['isActive'] === false && $slot['isAvailable'] === false && $slot['bookedCount'] === 1,
        'Deactivating keeps existing bookings and stops new ones');
    [$status, $slot] = api('PUT', "/delivery-slots/$newId", $booked + ['capacity' => 3], $staff);
    check($status === 200 && $slot['isActive'] === false, 'Leaving isActive out keeps the current value');
    $order0 = $pdo->query("SELECT delivery_slot_id FROM orders WHERE id = {$order['id']}")->fetchColumn();
    check((int) $order0 === $newId, 'The order still points at its slot');

    $free = $offsetSlot['id'];
    $moved = ['startsAt' => hoursAhead(80), 'endsAt' => hoursAhead(81), 'capacity' => 5, 'isActive' => true];
    [$status, $slot] = api('PUT', "/delivery-slots/$free", $moved, $admin);
    check($status === 200 && $slot['startsAt'] === $moved['startsAt'] && $slot['capacity'] === 5 && $slot['isAvailable'] === true,
        'A slot with no bookings can be moved and reactivated');
    [$status] = api('PUT', "/delivery-slots/$free", ['startsAt' => hoursAhead(-5), 'endsAt' => hoursAhead(-4), 'capacity' => 5], $staff);
    check($status === 422, 'Moving a slot into the past returns 422');
    [, $s304] = api('GET', '/delivery-slots/304');
    [$status] = api('PUT', "/delivery-slots/$free", ['startsAt' => $s304['startsAt'], 'endsAt' => $s304['endsAt'], 'capacity' => 1], $staff);
    check($status === 409, "Moving onto another slot's exact window returns 409");
    check(api('PUT', '/delivery-slots/999999', $moved, $staff)[0] === 404, 'PUT on an unknown slot returns 404');

    // Past slot edits that don't move the window still work (e.g. fixing capacity).
    [$status] = api('PUT', '/delivery-slots/303', ['startsAt' => $past['startsAt'], 'endsAt' => $past['endsAt'], 'capacity' => 3], $staff);
    check($status === 200, 'A past slot can be edited if its window stays the same');

    // ===================================================== delete
    echo "\n-- Delete\n";

    [$status, $body] = api('DELETE', "/delivery-slots/$newId", null, $staff);
    check($status === 409 && str_contains($body['error']['message'], 'Deactivate it instead'), 'Deleting a slot used by an order returns 409');
    check(api('DELETE', '/delivery-slots/303', null, $staff)[0] === 409, 'A slot with only delivered orders is history too (409)');
    $count = slotCount($pdo);
    [$status, , $headers] = api('DELETE', "/delivery-slots/$free", null, $admin);
    check($status === 204, 'Deleting an unused slot returns 204');
    check(slotCount($pdo) === $count - 1 && api('GET', "/delivery-slots/$free")[0] === 404, 'The slot is gone afterwards');
    check(api('DELETE', "/delivery-slots/$free", null, $staff)[0] === 404, 'Deleting it again returns 404');

    echo "\n$checks delivery slot checks passed. Application database was not used.\n";
} catch (Throwable $e) {
    echo "\n" . $e->getMessage() . "\n";
    if ($log !== null && is_file($log) && filesize($log) > 0) {
        // Only PHP messages; the development server also logs every connection.
        $lines = preg_grep('/Accepted|Closing|Closed without|started$|\[\d{3}\]: /', file($log), PREG_GREP_INVERT);
        echo $lines === [] ? '' : "Server log:\n" . implode('', $lines) . "\n";
    }
    $exitCode = 1;
} finally {
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
