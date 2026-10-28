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
$expansion = file_get_contents($root . '/database/migrations/003_project_expansion.sql');
$legacySchema = substr($schema, 0, strpos($schema, 'CREATE TABLE customer_addresses'));
$legacySchema = preg_replace('/^    (is_active BOOLEAN NOT NULL DEFAULT TRUE,|CONSTRAINT chk_products_active CHECK \(is_active IN \(0, 1\)\),)\R/m', '', $legacySchema, 1);
// Only remove the product activation column; other tables already use is_active.
$legacySchema = str_replace('    CONSTRAINT chk_products_active CHECK (is_active IN (0, 1)),', '', $legacySchema);
$legacySeed = substr($seed, 0, strpos($seed, '-- Expanded project examples.')) . "\nCOMMIT;\n";
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
function definitions(PDO $pdo): array {
    $result = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $ddl = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        $lines = explode("\n", preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl));
        $lines = array_map(fn($line) => rtrim(trim($line), ','), $lines);
        sort($lines);
        $result[$table] = $lines;
    }
    ksort($result);
    return $result;
}
try {
    $pdo->exec("CREATE DATABASE `$db`");
    $created = true;
    $pdo->exec("USE `$db`");
    // Original Build 1 definitions are deliberately preserved verbatim.
    $start = strpos($legacySchema, 'CREATE TABLE categories');
    $end = strpos($legacySchema, '-- Customer,');
    $pdo->exec(substr($legacySchema, $start, $end - $start));
    $pdo->exec($catalogSeed);
    $before = $pdo->query('SELECT id,name,price,unit,stock,category_id,image_url FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $pdo->exec($migration);
    verify($pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before,
        'Migration preserves every seeded product field');
    verify((int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 4,
        'Migration preserves categories');
    verify(count($pdo->query('SHOW TABLES')->fetchAll()) === 9, 'Migration creates all nine tables');
    $pdo->exec($legacySchema);
    $pdo->exec($legacySeed);
    $oldRows = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $oldRows[$table] = $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
    $pdo->exec($expansion);
    foreach ($oldRows as $table => $rows) {
        $current = $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        if ($table === 'products') {
            foreach ($current as &$product) { unset($product['is_active']); }
            unset($product);
        }
        verify($current === $rows, "Expansion preserves existing $table records");
    }
    verify((int) $pdo->query("SELECT COUNT(*) FROM order_status_events WHERE source='migration_baseline'")->fetchColumn() === 5,
        'Migration labels existing order states as baseline events');
    $upgraded = definitions($pdo);
    $pdo->exec($schema);
    verify(definitions($pdo) === $upgraded, 'Fresh and migrated table definitions agree');
    $pdo->exec($seed);
    verify($pdo->query('SELECT id,name,price,unit,stock,category_id,image_url FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before,
        'Full seed preserves unchanged Build 1 catalog');
    $expectedCounts = ['categories' => 4, 'products' => 10, 'customers' => 4,
        'auth_sessions' => 2, 'carts' => 2, 'cart_items' => 2,
        'delivery_slots' => 4, 'orders' => 5, 'order_items' => 6,
        'customer_addresses' => 2, 'customer_favorites' => 3, 'product_reviews' => 2,
        'inventory_adjustments' => 1, 'order_status_events' => 5];
    foreach ($expectedCounts as $table => $count) {
        verify((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() === $count,
            "Seed contains $count $table records");
    }
    require_once $root . '/src/Repositories/ProductRepository.php';
    $products = new \Foodwell\Repositories\ProductRepository($pdo);
    verify(count($products->findAll()) === 10, 'Existing product repository reads expanded schema');
    verify(array_keys($products->findById(101)) === ['id','name','price','unit','stock','categoryId','categoryName','imageUrl'],
        'Existing product response fields remain unchanged');
    rejects($pdo, 'UPDATE products SET is_active=2 WHERE id=101', 4025, 'Invalid activation flag rejected');
    rejects($pdo, "INSERT INTO customer_addresses (customer_id,label,recipient_name,address_line1,city,province,postal_code,country_code)
        VALUES (7,'Test','Demo','Street','City','Province','Code','US')", 4025, 'Non-Canadian saved address rejected');
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO inventory_adjustments (product_id,actor_id,quantity_change,stock_before,stock_after,reason)
        VALUES (101,9,-2,42,40,'damage')");
    verify((int) $pdo->query('SELECT COUNT(*) FROM inventory_adjustments WHERE quantity_change=-2')->fetchColumn() === 1,
        'Signed subtraction accepts a valid negative stock adjustment');
    $pdo->rollBack();
    rejects($pdo, "INSERT INTO order_status_events (order_id,previous_status,new_status,changed_by)
        VALUES (900,'pending','pending',9)", 4025, 'Unchanged status event rejected');
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
    rejects($pdo, 'INSERT INTO customer_favorites (customer_id,product_id) VALUES (7,101)', 1062,
        'Duplicate favorite rejected');
    rejects($pdo, "INSERT INTO product_reviews (product_id,customer_id,rating,comment) VALUES (101,8,6,'Bad')", 4025,
        'Rating outside one to five rejected');
    rejects($pdo, "INSERT INTO product_reviews (product_id,customer_id,rating,comment) VALUES (101,7,4,'Duplicate')", 1062,
        'Duplicate customer review rejected');
    rejects($pdo, "INSERT INTO inventory_adjustments (product_id,actor_id,quantity_change,stock_before,stock_after,reason) VALUES (101,9,-2,1,0,'damage')", 4025,
        'Inconsistent stock adjustment rejected');
    rejects($pdo, "INSERT INTO inventory_adjustments (product_id,actor_id,quantity_change,stock_before,stock_after,reason) VALUES (101,9,0,1,1,'correction')", 4025,
        'Zero stock adjustment rejected');
    rejects($pdo, 'DELETE FROM products WHERE id=109', 1451, 'Inventory audit product protected');
    rejects($pdo, 'DELETE FROM customers WHERE id=9', 1451, 'Inventory actor protected');
    $addressBefore = $pdo->query('SELECT delivery_address FROM orders WHERE id=900')->fetchColumn();
    $pdo->exec("UPDATE customer_addresses SET address_line1='Changed address' WHERE id=1");
    verify($pdo->query('SELECT delivery_address FROM orders WHERE id=900')->fetchColumn() === $addressBefore,
        'Saved address edits preserve historical order address');
    $pdo->exec('DELETE FROM products WHERE id=101');
    verify((int) $pdo->query('SELECT COUNT(*) FROM customer_favorites WHERE product_id=101')->fetchColumn() === 0,
        'Product deletion removes favorites');
    verify((int) $pdo->query('SELECT COUNT(*) FROM product_reviews WHERE product_id=101')->fetchColumn() === 0,
        'Product deletion removes reviews');
    rejects($pdo, "INSERT INTO order_status_events (order_id,new_status,source) VALUES (900,'packed','application')", 4025,
        'Application status events require an actor');
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
    verify(count($pdo->query('SHOW TABLES')->fetchAll()) === 14,
        'Development reset succeeds with related order and customer records');
    verify((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0,
        'Schema-only empty fixture remains supported');
    echo "$checks schema checks passed.\n";
} finally {
    if ($created) { $pdo->exec("DROP DATABASE `$db`"); }
}
