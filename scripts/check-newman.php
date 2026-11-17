<?php
declare(strict_types=1);

// Isolated collection runner: never load the application's .env.
$root = dirname(__DIR__);
$host = getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SCHEMA_TEST_PORT') ?: '3306';
$user = getenv('SCHEMA_TEST_USER') ?: 'root';
$pass = getenv('SCHEMA_TEST_PASS') ?: '';
$pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = 'foodwell_newman_' . bin2hex(random_bytes(6));
$created = false; $server = null; $files = []; $exitCode = 1;
try {
    $pdo->exec("CREATE DATABASE `$db`"); $created = true; $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents($root . '/database/schema.sql'));
    $pdo->exec(file_get_contents($root . '/database/seed.sql'));
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) { throw new RuntimeException('Cannot reserve a local port.'); }
    $serverPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
    $router = tempnam(sys_get_temp_dir(), 'foodwell-router-'); $files[] = $router;
    file_put_contents($router, '<?php ' . ($pass === '' ? 'putenv("DB_PASS=");' : '') . 'return require ' . var_export($root . '/public/index.php', true) . ';');
    $log = tempnam(sys_get_temp_dir(), 'foodwell-newman-'); $files[] = $log;
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $env = array_merge(getenv(), ['DB_HOST'=>$host, 'DB_PORT'=>$port, 'DB_NAME'=>$db, 'DB_USER'=>$user, 'DB_PASS'=>$pass, 'APP_BASE_PATH'=>'', 'AUTH_LIMITER_SECRET'=>bin2hex(random_bytes(32))]);
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', "127.0.0.1:$serverPort", '-t', $root . '/public', $router], [0=>['file',$null,'r'], 1=>['file',$null,'w'], 2=>['file',$log,'w']], $pipes, $root, $env);
    if (!is_resource($server)) { throw new RuntimeException('Cannot start API server.'); }
    $ready = false;
    for ($i=0; $i<50; $i++) { $connection=@fsockopen('127.0.0.1',$serverPort); if ($connection) { fclose($connection); $ready=true; break; } usleep(100000); }
    if (!$ready) { throw new RuntimeException('API server did not start.'); }
    $newman = $root . '/node_modules/newman/bin/newman.js';
    if (!is_file($newman)) { throw new RuntimeException('Newman is missing. Run npm ci first.'); }
    $cmd = escapeshellarg('node') . ' ' . escapeshellarg($newman) . ' run ' . escapeshellarg($root . '/requests/Foodwell_Customer_Auth_Cart.postman_collection.json') . ' --env-var ' . escapeshellarg("baseUrl=http://127.0.0.1:$serverPort/api/v1") . ' --bail --disable-unicode --color off';
    passthru($cmd, $exitCode);
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($created) { $pdo->exec("DROP DATABASE `$db`"); }
    foreach ($files as $file) { if (is_file($file)) { unlink($file); } }
}
exit($exitCode);
