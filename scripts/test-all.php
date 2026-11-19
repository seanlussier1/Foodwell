<?php
declare(strict_types=1);

// The original product regression resets DB_NAME and foodwell_empty. Never run
// this suite against a shared or production server.
if (getenv('FOODWELL_DISPOSABLE_TESTS') !== '1') {
    fwrite(STDERR, "Set FOODWELL_DISPOSABLE_TESTS=1 only for a dedicated disposable database server.\n");
    exit(1);
}
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'SCHEMA_TEST_HOST', 'SCHEMA_TEST_PORT', 'SCHEMA_TEST_USER'] as $name) {
    if (getenv($name) === false || getenv($name) === '') {
        fwrite(STDERR, "Set $name for the disposable test server.\n");
        exit(1);
    }
}
$root = dirname(__DIR__);
$php = PHP_BINARY;
// Avoid coverage/debugger overhead in the many short-lived PHP workers.
putenv('XDEBUG_MODE=off');
$checks = [
    'Original product regressions' => [$php, 'scripts/run-checks.php'],
    'Customer API collection' => [$php, 'scripts/check-newman.php'],
    'Orders tests and collection' => [$php, 'scripts/check-newman.php', '--orders'],
    'Catalog tests and collection' => [$php, 'scripts/check-newman.php', '--catalog'],
    'Inventory collection' => [$php, 'scripts/check-newman.php', '--inventory'],
];
foreach (['schema', 'upgrade-paths', 'products', 'auth', 'authorization', 'registration', 'cart', 'delivery', 'delivery-slots', 'catalog-authorization', 'catalog-integrity', 'order-concurrency', 'representations', 'favorites', 'inventory-adjustments', 'address-review-peer'] as $name) {
    $checks[$name] = [$php, "scripts/check-$name.php"];
}
$failures = [];
foreach ($checks as $name => $command) {
    echo "\n=== $name ===\n";
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root);
    $status = is_resource($process) ? proc_close($process) : 1;
    if ($status !== 0) $failures[$name] = $status;
}
if ($failures !== []) {
    fwrite(STDERR, "\nFailed checks: " . implode(', ', array_keys($failures)) . "\n");
    exit(1);
}
echo "\nAll " . count($checks) . " integration checks passed.\n";
