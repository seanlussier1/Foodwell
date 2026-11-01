<?php
declare(strict_types=1);

// Disposable fixtures only. Do not load the application's .env.
require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$host = getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SCHEMA_TEST_PORT') ?: '3306';
$user = getenv('SCHEMA_TEST_USER') ?: 'root';
$pass = getenv('SCHEMA_TEST_PASS') ?: '';
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$databases = $servers = $temporaryFiles = [];
$checks = 0;
function verifyCart(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks; echo "PASS: $message\n";
}
function cartRequest(int $port, string $method, string $path, ?string $body = null, string $type = 'application/json', array $extra = []): array {
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => "Content-Type: $type\r\nAccept: application/json\r\n" . implode("\r\n", $extra), 'content' => $body ?? '']]);
    $raw = file_get_contents("http://127.0.0.1:$port/api/v1$path", false, $ctx);
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], json_decode((string) $raw, true), $raw, $http_response_header];
}
function cartSnapshot(PDO $pdo): array {
    return [$pdo->query('SELECT * FROM carts ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM cart_items ORDER BY id')->fetchAll()];
}
try {
    foreach ([true, false] as $enabled) {
        $db = 'foodwell_cart_' . bin2hex(random_bytes(6));
        $pdo->exec("CREATE DATABASE `$db`"); $databases[] = $db;
        $pdo->exec("USE `$db`");
        $pdo->exec(file_get_contents("$root/database/schema.sql"));
        $pdo->exec(file_get_contents("$root/database/seed.sql"));
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) { throw new RuntimeException('Cannot reserve test port.'); }
        $serverPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
        $router = tempnam(sys_get_temp_dir(), 'cart-router-'); $temporaryFiles[] = $router;
        // Explicit blank value survives Windows child-process environment handling.
        file_put_contents($router, '<?php ' . ($pass === '' ? 'putenv("DB_PASS=");' : '') . ' return require ' . var_export("$root/public/index.php", true) . ';');
        $log = tempnam(sys_get_temp_dir(), 'cart-log-'); $temporaryFiles[] = $log;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $env = array_merge(getenv(), ['DB_HOST' => $host, 'DB_PORT' => $port, 'DB_NAME' => $db, 'DB_USER' => $user, 'DB_PASS' => $pass,
            'APP_CART_DEMO' => $enabled ? '1' : '0', 'APP_BASE_PATH' => '', 'CORS_ALLOWED_ORIGINS' => 'http://localhost:8081']);
        $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', "error_log=$log", '-S', "127.0.0.1:$serverPort", '-t', "$root/public", $router],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $log, 'a']], $pipes, $root, $env);
        if (!is_resource($server)) { throw new RuntimeException('Cannot start temporary API server.'); }
        $servers[] = $server;
        $ready = false;
        for ($i = 0; $i < 50; $i++) { $s = @fsockopen('127.0.0.1', $serverPort); if ($s) { fclose($s); $ready = true; break; } usleep(100000); }
        verifyCart($ready, 'Temporary API starts');
        if (!$enabled) {
            $snapshot = cartSnapshot($pdo);
            foreach ([['GET', '/customers/7/cart', null], ['POST', '/customers/7/cart/items', '{"productId":101,"quantity":1}'],
                ['PUT', '/customers/7/cart/items/501', '{"quantity":1}'], ['DELETE', '/customers/7/cart/items/501', null]] as [$method, $path, $body]) {
                verifyCart(cartRequest($serverPort, $method, $path, $body)[0] === 403, "$method cart route requires explicit demo opt-in");
            }
            verifyCart(cartSnapshot($pdo) === $snapshot, 'Disabled demo preserves all cart records');
            continue;
        }
        $stockBefore = $pdo->query('SELECT id, stock FROM products ORDER BY id')->fetchAll();
        [$status, $cart] = cartRequest($serverPort, 'GET', '/customers/7/cart');
        verifyCart($status === 200 && $cart['id'] === 30 && count($cart['items']) === 2 && $cart['subtotal'] === 12.97 && $cart['totalQuantity'] === 3, 'GET reads seeded cart with current-price subtotal');
        verifyCart($cart['items'][0]['id'] === 501 && $cart['items'][0]['lineTotal'] === 9.98 && $cart['items'][0]['availableStock'] === 42, 'Cart lines expose item IDs, server prices and available stock');
        verifyCart(cartRequest($serverPort, 'GET', '/customers/8/cart')[1]['items'] === [], 'Existing empty cart returns an empty array');
        $hash = $pdo->query('SELECT password_hash FROM customers WHERE id=7')->fetchColumn();
        $account = $pdo->prepare("INSERT INTO customers(first_name,last_name,email,password_hash) VALUES('Cart','Fixture','cart.fixture@example.com',:hash)");
        $account->execute(['hash' => $hash]); $newCustomer = (int) $pdo->lastInsertId();
        $snapshot = cartSnapshot($pdo);
        [$status, $empty] = cartRequest($serverPort, 'GET', "/customers/$newCustomer/cart");
        verifyCart($status === 200 && $empty['id'] === null && $empty['items'] === [] && $empty['subtotal'] === 0, 'Customer without a persisted cart receives a virtual empty cart');
        verifyCart(cartSnapshot($pdo) === $snapshot, 'GET never creates cart rows');
        foreach ([999999, 0, '999999999999999999999'] as $unknown) { verifyCart(cartRequest($serverPort, 'GET', "/customers/$unknown/cart")[0] === 404, 'Missing or invalid customer ID returns 404'); }
        $staff = (int) $pdo->query("SELECT id FROM customers WHERE role='staff' LIMIT 1")->fetchColumn();
        verifyCart(cartRequest($serverPort, 'GET', "/customers/$staff/cart")[0] === 403, 'Staff accounts cannot be cart owners');
        [$status, $cart] = cartRequest($serverPort, 'POST', '/customers/7/cart/items', '{"productId":102,"quantity":2}');
        verifyCart($status === 201 && $cart['itemCount'] === 3 && $cart['subtotal'] === 27.95, 'POST adds a distinct product and returns persisted totals');
        $line = array_values(array_filter($cart['items'], fn($i) => $i['productId'] === 102))[0]; $itemId = $line['id'];
        [$status, $cart] = cartRequest($serverPort, 'POST', '/customers/7/cart/items', '{"productId":102,"quantity":1}');
        $line = array_values(array_filter($cart['items'], fn($i) => $i['productId'] === 102))[0];
        verifyCart($status === 200 && $line['id'] === $itemId && $line['quantity'] === 3 && $cart['itemCount'] === 3, 'Repeated product add increases quantity without duplicate lines');
        verifyCart((int) $pdo->query('SELECT quantity FROM cart_items WHERE id=' . $itemId)->fetchColumn() === 3, 'Added quantity persists in MariaDB');
        [$status, $cart] = cartRequest($serverPort, 'PUT', "/customers/7/cart/items/$itemId", '{"quantity":4}');
        verifyCart($status === 200 && $cart['subtotal'] === 42.93, 'PUT sets an absolute quantity and recalculates totals');
        verifyCart(cartRequest($serverPort, 'GET', '/customers/7/cart')[1] === $cart, 'GET reads back the exact successful PUT response');
        verifyCart(cartRequest($serverPort, 'PUT', "/customers/7/cart/items/$itemId", '{"quantity":4}')[0] === 200, 'Repeated absolute PUT remains successful');
        $snapshot = cartSnapshot($pdo);
        verifyCart(cartRequest($serverPort, 'POST', '/customers/7/cart/items', '{"productId":102,"quantity":22}')[0] === 409, 'Add checks combined quantity against stock');
        verifyCart(cartRequest($serverPort, 'PUT', "/customers/7/cart/items/$itemId", '{"quantity":26}')[0] === 409, 'PUT rejects quantity exceeding stock');
        foreach (['0','-1','1.5','"2"','true','null','1000001'] as $quantity) {
            verifyCart(cartRequest($serverPort, 'POST', '/customers/7/cart/items', '{"productId":101,"quantity":' . $quantity . '}')[0] === 422, "POST rejects invalid quantity $quantity");
            verifyCart(cartRequest($serverPort, 'PUT', "/customers/7/cart/items/$itemId", '{"quantity":' . $quantity . '}')[0] === 422, "PUT rejects invalid quantity $quantity");
        }
        foreach (['{}','{"productId":"101","quantity":1}','{"productId":101,"quantity":1,"unitPrice":0}','{"productId":101,"quantity":1,"customerId":8}'] as $body) {
            [$status, $invalid] = cartRequest($serverPort, 'POST', '/customers/7/cart/items', $body);
            verifyCart($status === 422 && isset($invalid['error']['details']), 'POST rejects missing, incorrectly typed or server-controlled fields');
        }
        verifyCart(cartRequest($serverPort, 'PUT', "/customers/7/cart/items/$itemId", '{"productId":101,"quantity":1}')[0] === 422, 'PUT cannot switch the cart item product');
        foreach (['{','[]','null','1',''] as $body) { verifyCart(cartRequest($serverPort, 'POST', '/customers/7/cart/items', $body)[0] === 400, 'Malformed/non-object JSON returns 400'); }
        verifyCart(cartRequest($serverPort, 'POST', '/customers/7/cart/items', '{}', 'text/plain')[0] === 415, 'Unsupported request media returns 415');
        verifyCart(cartRequest($serverPort, 'POST', '/customers/7/cart/items', '{"productId":999999,"quantity":1}')[0] === 404, 'Unknown product returns 404');
        verifyCart(cartSnapshot($pdo) === $snapshot, 'All rejected input/stock writes leave every cart record unchanged');
        [$status, $other] = cartRequest($serverPort, 'POST', '/customers/8/cart/items', '{"productId":101,"quantity":1}');
        $foreignItem = $other['items'][0]['id']; $snapshot = cartSnapshot($pdo);
        verifyCart(cartRequest($serverPort, 'PUT', "/customers/7/cart/items/$foreignItem", '{"quantity":2}')[0] === 404, 'PUT rejects an item belonging to another cart');
        verifyCart(cartRequest($serverPort, 'DELETE', "/customers/7/cart/items/$foreignItem")[0] === 404, 'DELETE rejects an item belonging to another cart');
        verifyCart(cartSnapshot($pdo) === $snapshot, 'Nested foreign-item rejections preserve both customers carts');
        $pdo->exec('UPDATE products SET is_active=0 WHERE id=102'); $snapshot = cartSnapshot($pdo);
        verifyCart(cartRequest($serverPort, 'POST', '/customers/7/cart/items', '{"productId":102,"quantity":1}')[0] === 409, 'Inactive products cannot be added');
        verifyCart(cartRequest($serverPort, 'PUT', "/customers/7/cart/items/$itemId", '{"quantity":1}')[0] === 409, 'Inactive product quantity cannot be updated');
        verifyCart(cartSnapshot($pdo) === $snapshot, 'Inactive-product rejection preserves cart data');
        [$status, , $raw] = cartRequest($serverPort, 'DELETE', "/customers/7/cart/items/$itemId");
        verifyCart($status === 204 && $raw === '', 'DELETE removes even an inactive product line and returns no body');
        verifyCart((int) $pdo->query("SELECT COUNT(*) FROM cart_items WHERE id=$itemId")->fetchColumn() === 0, 'Deletion persists in MariaDB');
        verifyCart(cartRequest($serverPort, 'DELETE', "/customers/7/cart/items/$itemId")[0] === 404, 'Repeated removal reports missing item');
        verifyCart(cartRequest($serverPort, 'PUT', '/customers/7/cart/items/999999', '{"quantity":1}')[0] === 404, 'Unknown item update returns 404');
        $pdo->exec('UPDATE products SET is_active=1 WHERE id=102');
        $pdo->exec('UPDATE products SET stock=0 WHERE id=101');
        $stale = cartRequest($serverPort, 'GET', '/customers/7/cart')[1];
        verifyCart($stale['items'][0]['quantity'] === 2 && $stale['items'][0]['availableStock'] === 0, 'GET reports reduced stock without silently rewriting quantities');
        $snapshot = cartSnapshot($pdo);
        verifyCart(cartRequest($serverPort, 'POST', "/customers/$newCustomer/cart/items", '{"productId":101,"quantity":1}')[0] === 409, 'Out-of-stock first addition is rejected');
        verifyCart(cartRequest($serverPort, 'POST', "/customers/$newCustomer/cart/items", '{"productId":999999,"quantity":1}')[0] === 404, 'Unknown first product is rejected');
        verifyCart(cartSnapshot($pdo) === $snapshot, 'Failed first additions do not leave an empty persisted cart behind');
        $pdo->exec('UPDATE products SET stock=42 WHERE id=101');
        $pdo->exec('UPDATE products SET price=5.29 WHERE id=101');
        $repriced = cartRequest($serverPort, 'GET', '/customers/7/cart')[1];
        verifyCart($repriced['items'][0]['unitPrice'] === 5.29 && $repriced['subtotal'] === 13.57, 'Cart prices follow the current catalog instead of locking an old price');
        $pdo->exec('UPDATE products SET price=4.99 WHERE id=101');
        [$status, $created] = cartRequest($serverPort, 'POST', "/customers/$newCustomer/cart/items", '{"productId":101,"quantity":1}');
        $newCart = $created['id']; $newItem = $created['items'][0]['id'];
        verifyCart($status === 201 && is_int($newCart), 'First successful add creates one reusable cart');
        cartRequest($serverPort, 'DELETE', "/customers/$newCustomer/cart/items/$newItem");
        $emptied = cartRequest($serverPort, 'GET', "/customers/$newCustomer/cart")[1];
        verifyCart($emptied['id'] === $newCart && $emptied['items'] === [] && $emptied['subtotal'] === 0, 'Removing final line retains an empty reusable cart');
        $pdo->exec("UPDATE customers SET is_active=0 WHERE id=$newCustomer");
        verifyCart(cartRequest($serverPort, 'GET', "/customers/$newCustomer/cart")[0] === 403, 'Inactive customer cannot read their cart');
        verifyCart(cartRequest($serverPort, 'POST', "/customers/$newCustomer/cart/items", '{"productId":101,"quantity":1}')[0] === 403, 'Inactive customer cannot change their cart');
        $pdo->exec("UPDATE customers SET is_active=1 WHERE id=$newCustomer");
        verifyCart($pdo->query('SELECT id, stock FROM products ORDER BY id')->fetchAll() === $stockBefore, 'Cart edits never reserve or decrement stock');
        [$corsStatus, , , $headers] = cartRequest($serverPort, 'OPTIONS', '/customers/7/cart/items/501', null, 'application/json', ['Origin: http://localhost:8081', 'Access-Control-Request-Method: DELETE']);
        verifyCart($corsStatus === 204 && in_array('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS', $headers, true), 'Browser preflight permits cart DELETE');
        verifyCart(cartRequest($serverPort, 'GET', '/products/101')[0] === 200, 'Original product item still works');
        verifyCart(cartRequest($serverPort, 'GET', '/products')[0] === 200, 'Product collection still works');

        // Two independent workers wait on the same customer lock, then compete
        // to add the final available unit. The API itself is single-worker on Windows.
        $pdo->exec('UPDATE products SET stock=1 WHERE id=103');
        $worker = tempnam(sys_get_temp_dir(), 'cart-worker-'); $temporaryFiles[] = $worker;
        file_put_contents($worker, '<?php require ' . var_export("$root/vendor/autoload.php", true) . ';'
            . '$pdo=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_NAME").";charset=utf8mb4",getenv("DB_USER"),getenv("DB_PASS")?:"",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);'
            . 'try{(new Foodwell\\Repositories\\CartRepository($pdo))->add((int)$argv[1],103,1);echo "ok";}catch(Foodwell\\Repositories\\CartException $e){echo $e->status;}');
        $pdo->beginTransaction(); $pdo->query("SELECT id FROM customers WHERE id=$newCustomer FOR UPDATE")->fetch();
        $workers = [];
        try {
            for ($i=0;$i<2;$i++) {
                $process=proc_open([PHP_BINARY,'-d','xdebug.mode=off',$worker,(string)$newCustomer],[0=>['file',$null,'r'],1=>['pipe','w'],2=>['file',$log,'a']],$wpipes,$root,$env);
                if (!is_resource($process)) { throw new RuntimeException('Concurrent worker could not start.'); }
                $workers[]=[$process,$wpipes[1]];
            }
            usleep(300000); $pdo->commit();
            $outcomes=[];
            foreach($workers as [$process,$output]) { $outcomes[]=trim(stream_get_contents($output)); fclose($output); verifyCart(proc_close($process)===0,'Independent cart worker exits successfully'); }
            $workers=[]; sort($outcomes);
            verifyCart($outcomes===['409','ok'],'Concurrent additions serialize: one succeeds and one exceeds combined stock');
            verifyCart((int)$pdo->query("SELECT COUNT(*) FROM cart_items WHERE cart_id=$newCart AND product_id=103")->fetchColumn()===1 && (int)$pdo->query("SELECT quantity FROM cart_items WHERE cart_id=$newCart AND product_id=103")->fetchColumn()===1,'Concurrent adds preserve cart/product uniqueness and quantity');
            verifyCart((int)$pdo->query('SELECT stock FROM products WHERE id=103')->fetchColumn()===1,'Concurrent cart edits still do not reserve stock');
        } finally {
            if($pdo->inTransaction())$pdo->rollBack();
            foreach($workers as [$process,$output]){fclose($output);proc_terminate($process);proc_close($process);}
        }
    }
    echo "$checks cart checks passed. Application databases were not used.\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($servers as $server){proc_terminate($server);proc_close($server);}
    foreach($databases as $db){$pdo->exec("DROP DATABASE `$db`");}
    foreach($temporaryFiles as $file){unlink($file);}
}
