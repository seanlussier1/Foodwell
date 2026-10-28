<?php
declare(strict_types=1);

/**
 * Create (if needed) and load a Foodwell database.
 *
 *   php scripts/db-setup.php                      # DB_NAME from .env, schema + seed
 *   php scripts/db-setup.php foodwell_empty --empty   # schema only (empty-list fixture)
 *
 * DEVELOPMENT RESET: drops all project tables, including accounts and orders,
 * then restores the original synthetic product/category seed.
 * Existing Build 1 data: use database/migrations/002_full_project.sql instead.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Foodwell\Env;

Env::load(dirname(__DIR__) . '/.env');

$args  = array_slice($argv, 1);
$empty = in_array('--empty', $args, true);
$names = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')));
$db    = $names[0] ?? Env::get('DB_NAME', 'foodwell');

if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
    fwrite(STDERR, "Invalid database name: $db\n");
    exit(1);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '3306')),
        Env::get('DB_USER', 'foodwell'),
        Env::get('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    if (!$empty) {
        $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/seed.sql'));
    }
} catch (PDOException $e) {
    fwrite(STDERR, 'Database setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
echo "Database `$db` ready: $count product(s)" . ($empty ? " (empty fixture)\n" : " (seeded)\n");
