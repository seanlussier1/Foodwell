<?php
declare(strict_types=1);

// Isolated MariaDB schema checks; never use the application's database.
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4',
    getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1', getenv('SCHEMA_TEST_PORT') ?: '3306'),
    getenv('SCHEMA_TEST_USER') ?: 'root', getenv('SCHEMA_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root = dirname(__DIR__);
$schema = file_get_contents($root . '/database/schema.sql');
$seed = file_get_contents($root . '/database/seed.sql');
$catalogSeed = substr($seed, 0, strpos($seed, '-- Full project demo data')) . "\nCOMMIT;\n";
$migration = file_get_contents($root . '/database/migrations/002_full_project.sql');
$db = 'foodwell_schema_test_' . bin2hex(random_bytes(6));
$created = false;
$checks = 0;
function verify(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
    echo "PASS: $message\n";
}
function rejects(PDO $pdo, string $sql, int $expectedCode, string $message): void {
    try { $pdo->exec($sql); } catch (PDOException $e) {
        verify((int) $e->errorInfo[1] === $expectedCode, $message);
        return;
    }
    throw new RuntimeException("Expected rejection: $message");
}
try {
    $pdo->exec("CREATE DATABASE `$db`");
    $created = true;
    $pdo->exec("USE `$db`");
    // Original Build 1 definitions are deliberately preserved verbatim.
    $start = strpos($schema, 'CREATE TABLE categories');
    $end = strpos($schema, '-- Customer,');
    $pdo->exec(substr($schema, $start, $end - $start));
    $pdo->exec($catalogSeed);
    $before = $pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $pdo->exec($migration);
    verify($pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before,
        'Migration preserves every seeded product field');
    verify((int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 4,
        'Migration preserves categories');
    verify(count($pdo->query('SHOW TABLES')->fetchAll()) === 9, 'Migration creates all nine tables');
    $pdo->exec($schema);
    $pdo->exec($seed);
    verify($pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before,
        'Full seed preserves unchanged Build 1 catalog');
    $expectedCounts = ['categories' => 4, 'products' => 10, 'customers' => 4,
        'auth_sessions' => 2, 'carts' => 2, 'cart_items' => 2,
        'delivery_slots' => 4, 'orders' => 5, 'order_items' => 6];
    foreach ($expectedCounts as $table => $count) {
        verify((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() === $count,
            "Seed contains $count $table records");
    }
    foreach ($pdo->query('SELECT password_hash FROM customers') as $customer) {
        verify(password_verify('FoodwellDemo!2026', $customer['password_hash']),
            'Demo password verifies against stored hash');
    }
    verify((int) $pdo->query('SELECT COUNT(DISTINCT status) FROM orders')->fetchColumn() === 5,
        'Seed demonstrates all five order statuses');
    verify((int) $pdo->query('SELECT COUNT(*) FROM orders o LEFT JOIN
        (SELECT order_id, SUM(quantity * unit_price) AS amount FROM order_items GROUP BY order_id) i
        ON i.order_id=o.id WHERE i.amount IS NULL OR i.amount <> o.total')->fetchColumn() === 0,
        'Every seeded order total matches its purchase lines');
    verify((int) $pdo->query("SELECT COUNT(*) FROM auth_sessions
        WHERE revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()")->fetchColumn() === 0,
        'Seed grants no active authentication sessions');
    verify((int) $pdo->query("SELECT COUNT(*) FROM delivery_slots s WHERE
        (SELECT COUNT(*) FROM orders o WHERE o.delivery_slot_id=s.id AND o.status <> 'cancelled')
        > s.capacity")->fetchColumn() === 0, 'Seed respects delivery slot capacity');
    verify((int) $pdo->query("SELECT COUNT(*) FROM delivery_slots s WHERE s.is_active=1
        AND s.starts_at > UTC_TIMESTAMP() AND
        (SELECT COUNT(*) FROM orders o WHERE o.delivery_slot_id=s.id AND o.status <> 'cancelled')
        < s.capacity")->fetchColumn() > 0, 'Seed includes an available future delivery slot');
    verify((int) $pdo->query('SELECT COUNT(*) FROM carts c WHERE NOT EXISTS
        (SELECT 1 FROM cart_items i WHERE i.cart_id=c.id)')->fetchColumn() === 1,
        'Seed includes an empty cart');
    // Isolate destructive constraint checks from the demo records.
    $pdo->exec($schema);
    $pdo->exec($catalogSeed);
    $pdo->exec("INSERT INTO customers (first_name,last_name,email,password_hash)
        VALUES ('Test','Customer','test@example.com','test-hash-not-a-login')");
    verify($pdo->query('SELECT role FROM customers WHERE id=1')->fetchColumn() === 'customer',
        'New accounts default to customer role');
    rejects($pdo, "INSERT INTO customers (first_name,last_name,email,password_hash)
        VALUES ('Test','Duplicate','TEST@example.com','hash')", 1062, 'Email uniqueness ignores case');
    $pdo->exec('INSERT INTO carts (customer_id) VALUES (1)');
    rejects($pdo, 'INSERT INTO carts (customer_id) VALUES (1)', 1062, 'One cart per customer');
    rejects($pdo, 'INSERT INTO carts (customer_id) VALUES (999)', 1452, 'Unknown customer rejected');
    $pdo->exec('INSERT INTO cart_items (cart_id,product_id,quantity) VALUES (1,101,2)');
    rejects($pdo, 'INSERT INTO cart_items (cart_id,product_id,quantity) VALUES (1,101,1)', 1062,
        'One product row per cart');
    rejects($pdo, 'INSERT INTO cart_items (cart_id,product_id,quantity) VALUES (1,102,0)', 4025,
        'Zero cart quantity rejected');
    rejects($pdo, "INSERT INTO delivery_slots (starts_at,ends_at,capacity)
        VALUES ('2030-01-01 10:00:00','2030-01-01 09:00:00',2)", 4025, 'Invalid delivery window rejected');
    rejects($pdo, "INSERT INTO delivery_slots (starts_at,ends_at,capacity)
        VALUES ('2030-01-01 09:00:00','2030-01-01 10:00:00',0)", 4025, 'Zero capacity rejected');
    $pdo->exec("INSERT INTO delivery_slots (starts_at,ends_at,capacity)
        VALUES ('2030-01-01 09:00:00','2030-01-01 10:00:00',2)");
    $slot = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO orders (customer_id,delivery_slot_id,total,delivery_address)
        VALUES (1,$slot,9.98,'Synthetic address')");
    rejects($pdo, "INSERT INTO orders (customer_id,total,delivery_address) VALUES (1,-1,'Test')", 4025,
        'Negative order total rejected');
    $pdo->exec("INSERT INTO order_items (order_id,product_id,product_name,unit,quantity,unit_price)
        VALUES (1,101,'Whole Milk 2L','each',2,4.99)");
    rejects($pdo, 'DELETE FROM customers WHERE id=1', 1451, 'Customer order history protected');
    rejects($pdo, "DELETE FROM delivery_slots WHERE id=$slot", 1451, 'Booked slot protected');
    rejects($pdo, 'DELETE FROM orders WHERE id=1', 1451, 'Order lines protected');
    $pdo->exec('DELETE FROM products WHERE id=101');
    verify((int) $pdo->query('SELECT COUNT(*) FROM cart_items')->fetchColumn() === 0,
        'Deleted product removed from carts');
    $line = $pdo->query('SELECT * FROM order_items WHERE order_id=1')->fetch(PDO::FETCH_ASSOC);
    verify($line['product_id'] === null && $line['product_name'] === 'Whole Milk 2L'
        && $line['unit_price'] === '4.99', 'Purchased details survive catalog deletion');
    $pdo->exec($schema);
    verify(count($pdo->query('SHOW TABLES')->fetchAll()) === 9,
        'Development reset succeeds with related order and customer records');
    verify((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0,
        'Schema-only empty fixture remains supported');
    echo "$checks schema checks passed.\n";
} finally {
    if ($created) { $pdo->exec("DROP DATABASE `$db`"); }
}
