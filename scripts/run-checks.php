<?php
declare(strict_types=1);

/**
 * Build 1 focused checks: item 200, item 404, list 200, list 200 (empty).
 *
 *   php scripts/run-checks.php
 *
 * Re-seeds the main database, builds an isolated empty database
 * (foodwell_empty), starts two throwaway PHP servers against them, prints an
 * expected/actual table, and exits non-zero if any check fails.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Foodwell\Database;
use Foodwell\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

const EMPTY_DB = 'foodwell_empty';
$php = escapeshellarg(PHP_BINARY);

// 1. Fixtures: restore the seed, and create the isolated empty database.
passthru("$php " . escapeshellarg($root . '/scripts/db-setup.php'), $rc1);
passthru("$php " . escapeshellarg($root . '/scripts/db-setup.php') . ' ' . EMPTY_DB . ' --empty', $rc2);
if ($rc1 !== 0 || $rc2 !== 0) {
    fwrite(STDERR, "Fixture setup failed.\n");
    exit(1);
}

// 2. Throwaway servers (main DB on 8090, empty DB on 8091).
function startServer(int $port, string $db, string $root): mixed
{
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/public/index.php"],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
        $pipes,
        $root,
        array_merge(getenv(), ['DB_NAME' => $db])
    );
    for ($i = 0; $i < 30; $i++) {
        $sock = @fsockopen('127.0.0.1', $port);
        if ($sock) {
            fclose($sock);
            return $proc;
        }
        usleep(100000);
    }
    fwrite(STDERR, "Server on port $port did not start.\n");
    exit(1);
}

$main  = startServer(8090, Env::get('DB_NAME', 'foodwell'), $root);
$empty = startServer(8091, EMPTY_DB, $root);

/** @return array{0:int,1:string,2:string} status, content-type, body */
function get(string $url): array
{
    $ctx  = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true]]);
    $body = (string) @file_get_contents($url, false, $ctx);
    $status = 0;
    $type = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        } elseif (stripos($h, 'Content-Type:') === 0) {
            $type = trim(substr($h, 13));
        }
    }
    return [$status, $type, $body];
}

// 3. Ground truth straight from the database.
$pdo = Database::connection();
$row = $pdo->query('SELECT id, name FROM products ORDER BY id LIMIT 1')->fetch();
$dbCount = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$id = (int) $row['id'];

$results = [];
$record = function (string $name, string $expected, string $actual, bool $ok) use (&$results): void {
    $results[] = [$name, $expected, $actual, $ok];
};

// Check 1: item 200
[$st, $ct, $body] = get("http://127.0.0.1:8090/api/v1/products/$id");
$item = json_decode($body, true);
$record(
    "GET /products/$id",
    "200, application/json, id=$id, name=\"{$row['name']}\"",
    "$st, " . strtok($ct, ';') . ', id=' . ($item['id'] ?? '-') . ', name="' . ($item['name'] ?? '-') . '"',
    $st === 200 && str_starts_with($ct, 'application/json') && ($item['id'] ?? null) === $id && ($item['name'] ?? null) === $row['name']
);

// Check 2: item 404
[$st, $ct, $body] = get('http://127.0.0.1:8090/api/v1/products/999999');
$err = json_decode($body, true);
$record(
    'GET /products/999999',
    '404, JSON error body',
    "$st, " . (isset($err['error']['message']) ? "error=\"{$err['error']['message']}\"" : 'no error body'),
    $st === 404 && isset($err['error']['message'])
);

// Check 3: populated list 200 (and it agrees with the item + the DB)
[$st, $ct, $body] = get('http://127.0.0.1:8090/api/v1/products');
$list = json_decode($body, true);
$n = is_array($list['data'] ?? null) ? count($list['data']) : -1;
$first = $list['data'][0] ?? [];
$record(
    'GET /products (seeded)',
    "200, data has $dbCount items (= DB rows), first item matches item response",
    "$st, data has $n items, first id=" . ($first['id'] ?? '-'),
    $st === 200 && $n === $dbCount && $n > 1 && $first === $item
);

// Check 4: empty list 200 in the same shape
[$st, $ct, $body] = get('http://127.0.0.1:8091/api/v1/products');
$record(
    'GET /products (empty DB)',
    '200, {"data":[]}',
    "$st, " . $body,
    $st === 200 && json_decode($body, true) === ['data' => []]
);

proc_terminate($main);
proc_terminate($empty);

// 4. Report.
echo "\nBuild 1 checks\n" . str_repeat('=', 14) . "\n";
$failed = 0;
foreach ($results as $i => [$name, $expected, $actual, $ok]) {
    printf("%d. %s  [%s]\n   expected: %s\n   actual:   %s\n", $i + 1, $name, $ok ? 'PASS' : 'FAIL', $expected, $actual);
    $failed += $ok ? 0 : 1;
}
echo "\n" . (count($results) - $failed) . '/' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
