<?php
declare(strict_types=1);

// Isolated collection runner: never load the application's .env.
$root = dirname(__DIR__);
$orders = in_array('--orders', $argv, true);
$artifactDir = $orders ? ($root . '/.issue-53-tests') : sys_get_temp_dir();
if (!is_dir($artifactDir) && !mkdir($artifactDir, 0700, true) && !is_dir($artifactDir)) {
    throw new RuntimeException('Cannot create the test artifact directory.');
}
$host = getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SCHEMA_TEST_PORT') ?: '3306';
$user = getenv('SCHEMA_TEST_USER') ?: 'root';
$pass = getenv('SCHEMA_TEST_PASS') ?: '';
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = 'foodwell_newman_' . bin2hex(random_bytes(6));
$created = false; $servers = []; $files = []; $exitCode = 1;
try {
    $pdo->exec("CREATE DATABASE `$db`"); $created = true; $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents($root . '/database/schema.sql'));
    $pdo->exec(file_get_contents($root . '/database/seed.sql'));
    $ports = [];
    $env = array_merge(getenv(), ['DB_HOST'=>$host, 'DB_PORT'=>$port, 'DB_NAME'=>$db, 'DB_USER'=>$user, 'DB_PASS'=>$pass, 'APP_BASE_PATH'=>'', 'AUTH_LIMITER_SECRET'=>bin2hex(random_bytes(32))]);
    for ($worker = 0; $worker < ($orders ? 2 : 1); $worker++) {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) { throw new RuntimeException('Cannot reserve a local port.'); }
        $serverPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
        $ports[] = $serverPort;
        $router = tempnam($artifactDir, 'foodwell-router-'); $files[] = $router;
        file_put_contents($router, '<?php ' . ($pass === '' ? 'putenv("DB_PASS=");' : '') . 'return require ' . var_export($root . '/public/index.php', true) . ';');
        $log = tempnam($artifactDir, 'foodwell-newman-');
        if (!$orders) { $files[] = $log; }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', "127.0.0.1:$serverPort", '-t', $root . '/public', $router], [0=>['file',$null,'r'], 1=>['file',$null,'w'], 2=>['file',$log,'w']], $pipes, $root, $env);
        if (!is_resource($server)) { throw new RuntimeException('Cannot start API server.'); }
        $servers[] = $server;
        $ready = false;
        for ($i=0; $i<50; $i++) { $connection=@fsockopen('127.0.0.1',$serverPort); if ($connection) { fclose($connection); $ready=true; break; } usleep(100000); }
        if (!$ready) { throw new RuntimeException('API server did not start.'); }
    }
    $newman = $root . '/node_modules/newman/bin/newman.js';
    if (!is_file($newman)) { throw new RuntimeException('Newman is missing. Run npm ci first.'); }
    if ($orders) {
        $phpunit = $root . '/vendor/phpunit/phpunit/phpunit';
        if (!is_file($phpunit)) { throw new RuntimeException('PHPUnit is missing. Run composer install first.'); }
        $env['FOODWELL_ORDER_TEST_PORTS'] = implode(',', $ports);
        runTest([PHP_BINARY, $phpunit, '--no-configuration', '--do-not-cache-result',
            '--bootstrap', "$root/vendor/autoload.php", '--log-junit', "$artifactDir/phpunit-stock.xml",
            "$root/tests/orders/CheckoutStockTest.php"], $root, $env);
        runTest([PHP_BINARY, $phpunit, '--no-configuration', '--do-not-cache-result',
            '--log-junit', "$artifactDir/phpunit.xml", $root . '/tests/orders/CheckoutIntegrityTest.php'], $root, $env);
        $pdo->exec(file_get_contents($root . '/database/schema.sql'));
        $pdo->exec(file_get_contents($root . '/database/seed.sql'));
    }
    $collection = $orders ? 'Foodwell_Orders_Checkout' : 'Foodwell_Customer_Auth_Cart';
    $command = ['node', $newman, 'run', "$root/requests/$collection.postman_collection.json",
        '--env-var', "baseUrl=http://127.0.0.1:{$ports[0]}/api/v1", '--bail', '--disable-unicode', '--color', 'off'];
    if ($orders) {
        array_push($command, '--reporters', 'cli,junit', '--reporter-junit-export', "$artifactDir/newman.xml");
    }
    runTest($command, $root, $env);
    $exitCode = 0;
} finally {
    foreach ($servers as $server) { proc_terminate($server); proc_close($server); }
    if ($created) { $pdo->exec("DROP DATABASE `$db`"); }
    foreach ($files as $file) { if (is_file($file)) { unlink($file); } }
}
exit($exitCode);

function runTest(array $command, string $root, array $environment): void
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, $environment);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Test command failed: ' . basename($command[1]));
    }
}
