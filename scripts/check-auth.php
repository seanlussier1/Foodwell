<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$host = getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1';
$port = getenv('SCHEMA_TEST_PORT') ?: '3306';
$user = getenv('SCHEMA_TEST_USER') ?: 'root';
$pass = getenv('SCHEMA_TEST_PASS') ?: '';
$pdo = new PDO("mysql:host=$host;port=$port", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db = 'foodwell_auth_' . bin2hex(random_bytes(5));
$server = null;
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) { throw new RuntimeException('Cannot reserve a local port.'); }
$serverPort = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
fclose($socket);
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
function checkAuth(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } echo "PASS: $label\n"; }
function authRequest(string $method, string $path, ?array $body = null, ?string $token = null): array {
    global $serverPort;
    $headers = "Accept: application/json\r\nContent-Type: application/json\r\n" . ($token === null ? '' : "Authorization: Bearer $token\r\n");
    $ctx = stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'timeout'=>5,'header'=>$headers,'content'=>$body === null ? '' : json_encode($body)]]);
    $raw = file_get_contents("http://127.0.0.1:$serverPort/api/v1" . $path, false, $ctx);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), json_decode((string)$raw, true), $http_response_header, (string)$raw];
}
try {
    $pdo->exec("CREATE DATABASE `$db`"); $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents($root . '/database/schema.sql'));
    $pdo->exec(file_get_contents($root . '/database/seed.sql'));
    $log = tempnam(sys_get_temp_dir(), 'foodwell-auth-');
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', "127.0.0.1:$serverPort", '-t', "$root/public", "$root/public/index.php"], [0=>['file',$null,'r'],1=>['file',$null,'w'],2=>['file',$log,'w']], $pipes, $root, array_merge(getenv(), ['DB_HOST'=>$host,'DB_PORT'=>$port,'DB_NAME'=>$db,'DB_USER'=>$user,'DB_PASS'=>$pass,'AUTH_LIMITER_SECRET'=>str_repeat('s',40)]));
    $ready = false; for ($i=0;$i<50;$i++) { $sock=@fsockopen('127.0.0.1',$serverPort); if ($sock) { fclose($sock); $ready=true; break; } usleep(100000); }
    checkAuth($ready, 'API test server starts');
    $email = $pdo->query("SELECT email FROM customers WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $good = ['email'=>$email, 'password'=>'FoodwellDemo!2026'];
    [$status,$login,$headers,$raw] = authRequest('POST','/auth/sessions',$good);
    checkAuth($status===201 && preg_match('/^[0-9a-f]{64}$/', $login['accessToken'] ?? '')===1 && ($login['tokenType'] ?? '')==='Bearer', 'login returns a fresh opaque token');
    $token=$login['accessToken'];
    checkAuth(in_array('Cache-Control: no-store',$headers,true) && !str_contains($raw,'password_hash'), 'login is not cached and contains no password hash');
    $stmt=$pdo->prepare('SELECT token_hash, customer_id FROM auth_sessions WHERE token_hash = ?'); $stmt->execute([hash('sha256',$token)]); $stored=$stmt->fetch(PDO::FETCH_ASSOC);
    checkAuth($stored!==false && $stored['token_hash']!==$token, 'database stores only token digest');
    [$status,$session,$headers,$raw]=authRequest('GET','/auth/sessions/current',null,$token);
    checkAuth($status===200 && !str_contains($raw,$token) && in_array('Cache-Control: no-store',$headers,true), 'current session returns no token and is not cached');
    checkAuth(authRequest('GET','/auth/sessions/current')[0]===401 && authRequest('GET','/auth/sessions/current',null,'invalid')[0]===401, 'missing and malformed tokens rejected');
    checkAuth(authRequest('DELETE','/auth/sessions/current',null,$token)[0]===204 && authRequest('GET','/auth/sessions/current',null,$token)[0]===401, 'logout revokes session');
    [$status,$again]=authRequest('POST','/auth/sessions',$good); $token2=$again['accessToken'] ?? '';
    checkAuth($status===201 && $token2!==$token, 'independent login issues a different token');
    $stmt=$pdo->prepare('UPDATE auth_sessions SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND), created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR) WHERE token_hash=?'); $stmt->execute([hash('sha256',$token2)]);
    checkAuth(authRequest('GET','/auth/sessions/current',null,$token2)[0]===401, 'expired sessions rejected');
    [$status,$again]=authRequest('POST','/auth/sessions',$good); $token3=$again['accessToken'] ?? '';
    $pdo->exec('UPDATE customers SET is_active=0 WHERE id='.(int)$stored['customer_id']);
    checkAuth(authRequest('GET','/auth/sessions/current',null,$token3)[0]===401, 'deactivated account invalidates existing session');
    $bad=authRequest('POST','/auth/sessions',$good); $unknown=authRequest('POST','/auth/sessions',['email'=>'unknown@example.test','password'=>'wrong']);
    checkAuth($bad[0]===401 && $unknown[0]===401 && $bad[1]['error']['message']===$unknown[1]['error']['message'], 'inactive and unknown accounts return same error');
    for ($i=0;$i<4;$i++) { authRequest('POST','/auth/sessions',['email'=>'unknown@example.test','password'=>'wrong']); }
    [$status,,$headers]=authRequest('POST','/auth/sessions',['email'=>'unknown@example.test','password'=>'wrong']);
    checkAuth($status===429 && count(array_filter($headers,fn($h)=>stripos($h,'Retry-After:')===0))===1, 'repeated failures are throttled with retry time');
    checkAuth(!str_contains((string)file_get_contents($log),$token) && !str_contains((string)file_get_contents($log),'FoodwellDemo!2026'), 'server log contains neither token nor password');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo->exec("DROP DATABASE IF EXISTS `$db`");
    if (isset($log) && is_file($log)) { unlink($log); }
}
