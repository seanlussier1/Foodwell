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
$created = false;
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
/** Private resource state; auth attempts deliberately update limiter counters. */
function securitySnapshot(PDO $pdo): array {
    $data = [];
    foreach (['customers', 'carts', 'cart_items', 'products', 'categories', 'orders', 'order_items'] as $table) {
        $data[$table] = $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
}
function rejectUnchanged(PDO $pdo, string $method, string $path, ?array $body, ?string $token, int $expected, string $label): void {
    $before = securitySnapshot($pdo);
    [$status, $json, $headers, $raw] = authRequest($method, $path, $body, $token);
    checkAuth($status === $expected && ($json['error']['status'] ?? null) === $expected, $label . ' returns ' . $expected);
    if ($expected === 401) {
        checkAuth(in_array('WWW-Authenticate: Bearer', $headers, true), $label . ' includes bearer challenge');
    }
    checkAuth(!str_contains($raw, 'password_hash') && !str_contains($raw, 'token_hash') && ($token === null || $token === '' || !str_contains($raw, $token)), $label . ' exposes no secrets');
    checkAuth(securitySnapshot($pdo) === $before, $label . ' leaves resource records unchanged');
}
try {
    $pdo->exec("CREATE DATABASE `$db`"); $created = true; $pdo->exec("USE `$db`");
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
    $duplicate = ['firstName'=>'Duplicate','lastName'=>'Fixture','email'=>$email,'password'=>'FoodwellDemo!2026'];
    rejectUnchanged($pdo, 'POST', '/customers', $duplicate, null, 409, 'duplicate email registration');
    $wrong = ['email'=>$email,'password'=>'DefinitelyWrong!2026'];
    $unknownLogin = ['email'=>'missing-fixture@example.test','password'=>'DefinitelyWrong!2026'];
    $loginSnapshot = securitySnapshot($pdo);
    $wrongResult = authRequest('POST','/auth/sessions',$wrong);
    $unknownResult = authRequest('POST','/auth/sessions',$unknownLogin);
    checkAuth($wrongResult[0]===401 && $unknownResult[0]===401 && $wrongResult[1]['error']['message']===$unknownResult[1]['error']['message'], 'wrong password and unknown email return the same generic 401');
    checkAuth(securitySnapshot($pdo) === $loginSnapshot, 'failed login attempts leave resource records unchanged');
    $ownedWrite = ['firstName'=>'Denied','lastName'=>'Change','email'=>'denied-change@example.test'];
    rejectUnchanged($pdo, 'PUT', '/customers/7', $ownedWrite, null, 401, 'missing token profile write');
    rejectUnchanged($pdo, 'PUT', '/customers/7', $ownedWrite, 'malformed', 401, 'malformed token profile write');
    rejectUnchanged($pdo, 'PUT', '/customers/7', $ownedWrite, str_repeat('a',64), 401, 'unknown token profile write');
    $expiredToken = bin2hex(random_bytes(32)); $revokedToken = bin2hex(random_bytes(32)); $inactiveToken = bin2hex(random_bytes(32));
    $fixture=$pdo->prepare('INSERT INTO auth_sessions(customer_id,token_hash,created_at,expires_at,revoked_at) VALUES(?,?,UTC_TIMESTAMP()-INTERVAL 3 HOUR,UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL)');
    $fixture->execute([7,hash('sha256',$expiredToken)]);
    $fixture=$pdo->prepare('INSERT INTO auth_sessions(customer_id,token_hash,created_at,expires_at,revoked_at) VALUES(?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 1 HOUR,UTC_TIMESTAMP())');
    $fixture->execute([7,hash('sha256',$revokedToken)]);
    $fixture=$pdo->prepare('INSERT INTO auth_sessions(customer_id,token_hash,created_at,expires_at) VALUES(?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 1 HOUR)');
    $fixture->execute([8,hash('sha256',$inactiveToken)]);
    rejectUnchanged($pdo, 'PUT', '/customers/7', $ownedWrite, $expiredToken, 401, 'expired token profile write');
    rejectUnchanged($pdo, 'POST', '/customers/7/cart/items', ['productId'=>101,'quantity'=>1], $revokedToken, 401, 'revoked token cart write');
    $pdo->exec('UPDATE customers SET is_active=0 WHERE id=8');
    rejectUnchanged($pdo, 'POST', '/customers/8/cart/items', ['productId'=>101,'quantity'=>1], $inactiveToken, 401, 'inactive account cart write');
    $pdo->exec('UPDATE customers SET is_active=1 WHERE id=8');
    rejectUnchanged($pdo, 'PUT', '/customers/8', $ownedWrite, $token, 403, 'cross-user profile write');
    rejectUnchanged($pdo, 'POST', '/customers/8/cart/items', ['productId'=>101,'quantity'=>1], $token, 403, 'cross-user cart addition');
    rejectUnchanged($pdo, 'PUT', '/customers/8/cart/items/501', ['quantity'=>1], $token, 403, 'cross-user cart item update');
    rejectUnchanged($pdo, 'DELETE', '/customers/8/cart/items/501', null, $token, 403, 'cross-user cart item removal');
    rejectUnchanged($pdo, 'PATCH', '/customers/8', ['isActive'=>false], $token, 403, 'customer role deactivation');
    $foreignCart = (int)$pdo->query('SELECT id FROM carts WHERE customer_id=8')->fetchColumn();
    $pdo->exec('INSERT INTO cart_items(cart_id,product_id,quantity) VALUES ('.$foreignCart.',101,1)');
    $foreignItem = (int)$pdo->lastInsertId();
    rejectUnchanged($pdo, 'PUT', '/customers/7/cart/items/'.$foreignItem, ['quantity'=>1], $token, 404, 'foreign item in authorized cart scope');
    rejectUnchanged($pdo, 'DELETE', '/customers/7/cart/items/'.$foreignItem, null, $token, 404, 'foreign item removal in authorized cart scope');
    rejectUnchanged($pdo, 'PUT', '/customers/7', ['firstName'=>'Alex','lastName'=>'Demo','email'=>$email,'role'=>'admin'], $token, 422, 'role injection in own profile');
    rejectUnchanged($pdo, 'POST', '/customers/7/cart/items', ['productId'=>101,'quantity'=>1,'customerId'=>8], $token, 422, 'owner injection in cart body');
    $ownerId = (int)$stored['customer_id'];
    $otherId = $ownerId === 7 ? 8 : 7;
    checkAuth(authRequest('GET', '/customers/'.$ownerId)[0]===401, 'profile rejects anonymous access');
    checkAuth(authRequest('GET', '/customers/'.$ownerId,null,$token)[0]===200, 'customer can read own profile');
    checkAuth(authRequest('GET', '/customers/'.$otherId,null,$token)[0]===403, 'customer cannot read another profile');
    checkAuth(authRequest('GET', '/customers',null,$token)[0]===403, 'customer cannot list accounts');
    checkAuth(authRequest('GET', '/customers/'.$ownerId.'/cart',null,$token)[0]===200, 'customer can read own cart');
    checkAuth(authRequest('GET', '/customers/'.$otherId.'/cart',null,$token)[0]===403, 'customer cannot read another cart');
    checkAuth(in_array(authRequest('POST','/customers/'.$ownerId.'/cart/items',['productId'=>101,'quantity'=>1],$token)[0],[200,201],true), 'customer can update own cart within authorized transaction');
    checkAuth(authRequest('PUT','/customers/'.$ownerId.'/cart/items/999999',['quantity'=>1],$token)[0]===404, 'missing cart item remains scoped to the customer');
    $before = (int)$pdo->query('SELECT COUNT(*) FROM cart_items')->fetchColumn();
    checkAuth(authRequest('POST','/customers/'.$otherId.'/cart/items',['productId'=>101,'quantity'=>1],$token)[0]===403 && (int)$pdo->query('SELECT COUNT(*) FROM cart_items')->fetchColumn()===$before, 'cross-user cart write is denied without changes');
    [, $staffLogin] = authRequest('POST','/auth/sessions',['email'=>'staff@example.com','password'=>'FoodwellDemo!2026']);
    $staffToken = $staffLogin['accessToken'] ?? '';
    rejectUnchanged($pdo, 'PUT', '/customers/'.$ownerId, $ownedWrite, $staffToken, 403, 'staff profile write for another customer');
    checkAuth(authRequest('GET','/customers',null,$staffToken)[0]===200 && authRequest('GET','/customers/'.$ownerId,null,$staffToken)[0]===403, 'staff may list customers but cannot read another profile');
    [, $adminLogin] = authRequest('POST','/auth/sessions',['email'=>'admin@example.com','password'=>'FoodwellDemo!2026']);
    $adminToken = $adminLogin['accessToken'] ?? '';
    checkAuth(authRequest('GET','/customers/'.$ownerId,null,$adminToken)[0]===200 && authRequest('GET','/customers/'.$ownerId.'/cart',null,$adminToken)[0]===200, 'administrator has explicit profile and cart access');
    rejectUnchanged($pdo, 'POST', '/orders', ['customerId'=>$otherId,'deliveryAddress'=>'123 Test Street'], $token, 403, 'cross-user checkout');
    rejectUnchanged($pdo, 'POST', '/categories', ['name'=>'Unauthorized category'], $token, 403, 'customer category mutation');
    rejectUnchanged($pdo, 'PUT', '/products/101', ['name'=>'Unauthorized change'], $token, 403, 'customer product mutation');
    checkAuth(authRequest('GET','/orders/900',null,$token)[0]===200 && authRequest('GET','/orders/901',null,$token)[0]===403, 'order detail is restricted to owner or administrator');
    checkAuth(authRequest('GET','/orders/900',null,$staffToken)[0]===403 && authRequest('GET','/orders/900',null,$adminToken)[0]===200, 'staff cannot read order detail but administrator can');
    checkAuth(authRequest('POST','/orders',['customerId'=>$otherId,'deliveryAddress'=>'Test address'],$token)[0]===403, 'checkout rejects a different customerId');
    checkAuth(authRequest('POST','/categories',['name'=>'Authorization test'],$token)[0]===403, 'customer cannot create categories');
    checkAuth(authRequest('POST','/categories',['name'=>'Authorization test'],$staffToken)[0]===201, 'staff can create categories');
    checkAuth(authRequest('POST','/products',['name'=>'Invalid'],$token)[0]===403, 'customer cannot write products');
    checkAuth(authRequest('POST','/products',['name'=>'Invalid'],$staffToken)[0]===422, 'staff reaches product validation');
    [$checkoutStatus,$checkout] = authRequest('POST','/orders',['customerId'=>$ownerId,'deliveryAddress'=>'123 Test Street'],$token);
    checkAuth($checkoutStatus===201 && ($checkout['customerId'] ?? null)===$ownerId, 'owner can complete checkout in the authorized transaction');
    checkAuth(authRequest('DELETE','/auth/sessions/current',null,$token)[0]===204 && authRequest('GET','/auth/sessions/current',null,$token)[0]===401, 'logout revokes session');
    [$status,$again]=authRequest('POST','/auth/sessions',$good); $token2=$again['accessToken'] ?? '';
    checkAuth($status===201 && $token2!==$token, 'independent login issues a different token');
    $stmt=$pdo->prepare('UPDATE auth_sessions SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND), created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR) WHERE token_hash=?'); $stmt->execute([hash('sha256',$token2)]);
    checkAuth(authRequest('GET','/auth/sessions/current',null,$token2)[0]===401 && authRequest('GET','/customers/'.$ownerId,null,$token2)[0]===401, 'expired sessions rejected by session and private routes');
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
    if ($created) { $pdo->exec("DROP DATABASE `$db`"); }
    if (isset($log) && is_file($log)) { unlink($log); }
}
