<?php
declare(strict_types=1);

// Creates and drops only a uniquely named disposable test database.
$root=dirname(__DIR__); $host=getenv('SCHEMA_TEST_HOST') ?: '127.0.0.1'; $port=getenv('SCHEMA_TEST_PORT') ?: '3306';
$user=getenv('SCHEMA_TEST_USER') ?: 'root'; $pass=getenv('SCHEMA_TEST_PASS') ?: '';
$pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db='foodwell_order_test_'.bin2hex(random_bytes(6)); $created=false; $servers=[]; $files=[];
try {
    $pdo->exec("CREATE DATABASE `$db`"); $created=true; $pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents("$root/database/schema.sql")); $pdo->exec(file_get_contents("$root/database/seed.sql"));
    $tokens=[]; $hash=$pdo->query('SELECT password_hash FROM customers WHERE id=8')->fetchColumn(); $ids=[];
    foreach ([101,102] as $product) {
        $q=$pdo->prepare('INSERT INTO customers(first_name,last_name,email,password_hash) VALUES(?,?,?,?)');
        $q->execute(['Race','Fixture',uniqid('race',true).'@example.test',$hash]); $id=(int)$pdo->lastInsertId(); $ids[]=$id; $token=bin2hex(random_bytes(32)); $tokens[]=$token; $session=$pdo->prepare("INSERT INTO auth_sessions(customer_id, token_hash, created_at, expires_at) VALUES(?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 2 HOUR)"); $session->execute([$id,hash("sha256",$token)]);
        $pdo->exec("INSERT INTO carts(customer_id) VALUES ($id)"); $cart=(int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO cart_items(cart_id,product_id,quantity) VALUES ($cart,$product,1)");
    }
    $pdo->exec('INSERT INTO delivery_slots(starts_at,ends_at,capacity,is_active) VALUES (UTC_TIMESTAMP() + INTERVAL 20 DAY,UTC_TIMESTAMP() + INTERVAL 20 DAY + INTERVAL 1 HOUR,1,1)'); $slot=(int)$pdo->lastInsertId();
    $ports=[]; $null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
    for($i=0;$i<2;$i++) {
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); if(!$socket) throw new RuntimeException($error);
        $serverPort=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1); fclose($socket); $ports[]=$serverPort;
        $router=tempnam(sys_get_temp_dir(),'order-router-'); $files[]=$router;
        file_put_contents($router,'<?php '.($pass===''?'putenv("DB_PASS=");':'').' return require '.var_export("$root/public/index.php",true).';');
        $log=tempnam(sys_get_temp_dir(),'order-log-'); $files[]=$log;
        $env=array_merge(getenv(),['DB_HOST'=>$host,'DB_PORT'=>$port,'DB_NAME'=>$db,'DB_USER'=>$user,'DB_PASS'=>$pass,'APP_CART_DEMO'=>'1','APP_BASE_PATH'=>'']);
        $server=proc_open([PHP_BINARY,'-d','display_errors=0','-d',"error_log=$log",'-S',"127.0.0.1:$serverPort",'-t',"$root/public",$router],[0=>['file',$null,'r'],1=>['file',$null,'w'],2=>['file',$log,'a']],$pipes,$root,$env);
        if(!is_resource($server)) throw new RuntimeException('Could not start test server.'); $servers[]=$server;
        $ready=false; for($j=0;$j<50;$j++){ $s=@fsockopen('127.0.0.1',$serverPort); if($s){fclose($s);$ready=true;break;} usleep(100000); } if(!$ready) throw new RuntimeException('Test server did not start.');
    }
    $pdo->beginTransaction(); $pdo->query("SELECT id FROM delivery_slots WHERE id=$slot FOR UPDATE")->fetch();
    $worker= <<<'CODE'
$ctx=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'header'=>"Content-Type: application/json\r\nAuthorization: Bearer ".$argv[4]."\r\n",'content'=>json_encode(['customerId'=>(int)$argv[2],'deliveryAddress'=>'123 Test Street','deliverySlotId'=>(int)$argv[3]])]]); $body=file_get_contents('http://127.0.0.1:'.$argv[1].'/api/v1/orders',false,$ctx); echo $http_response_header[0]." ".$body;
CODE;
    $children=[]; foreach($ports as $i=>$serverPort){$p=proc_open([PHP_BINARY,'-d','xdebug.mode=off','-r',$worker,(string)$serverPort,(string)$ids[$i],(string)$slot,$tokens[$i]],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); if(!is_resource($p)) throw new RuntimeException('Could not start checkout worker.'); fclose($pipes[0]); $children[]=[$p,$pipes];}
    sleep(2); $pdo->commit(); $results=[];
    foreach($children as [$p,$pipes]){$results[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($err);}
    $orders=(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE delivery_slot_id=$slot AND status <> 'cancelled'")->fetchColumn();
    $remaining=(int)$pdo->query('SELECT COUNT(*) FROM cart_items WHERE cart_id IN (SELECT id FROM carts WHERE customer_id IN ('.implode(',',$ids).'))')->fetchColumn();
    if(count(array_filter($results,fn($s)=>str_contains($s,' 201 ')))!==1 || count(array_filter($results,fn($s)=>str_contains($s,' 409 ')))!==1 || $orders!==1 || $remaining!==1) throw new RuntimeException('Competing checkout failed: '.implode(' | ',$results));
    echo "PASS: simultaneous checkouts: one 201, one 409, one booking, rejected cart retained.\n";
    $raceToken=bin2hex(random_bytes(32));
    $session=$pdo->prepare('INSERT INTO auth_sessions(customer_id,token_hash,created_at,expires_at) VALUES(7,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 2 HOUR)');
    $session->execute([hash('sha256',$raceToken)]);
    $before=(int)$pdo->query('SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE cart_id=(SELECT id FROM carts WHERE customer_id=7)')->fetchColumn();
    $pdo->beginTransaction();
    $pdo->exec('UPDATE customers SET is_active=0 WHERE id=7');
    $pdo->exec('UPDATE auth_sessions SET revoked_at=UTC_TIMESTAMP() WHERE customer_id=7 AND revoked_at IS NULL');
    $cartWorker= <<<'CODE'
$ctx=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'header'=>"Content-Type: application/json\r\nAuthorization: Bearer ".$argv[2]."\r\n",'content'=>'{"productId":101,"quantity":1}']]); file_get_contents('http://127.0.0.1:'.$argv[1].'/api/v1/customers/7/cart/items',false,$ctx); echo $http_response_header[0];
CODE;
    $process=proc_open([PHP_BINARY,'-d','xdebug.mode=off','-r',$cartWorker,(string)$ports[0],$raceToken],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)) throw new RuntimeException('Cannot start deactivation race worker.');
    fclose($pipes[0]); usleep(500000); $pdo->commit();
    $response=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if(proc_close($process)!==0) throw new RuntimeException($err);
    $after=(int)$pdo->query('SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE cart_id=(SELECT id FROM carts WHERE customer_id=7)')->fetchColumn();
    if(!str_contains($response,' 401 ') || $after!==$before) throw new RuntimeException('Deactivation race did not reject cart write: '.$response);
    echo "PASS: deactivation racing a cart write rejects the request and preserves the cart.\n";
} finally {
    if($pdo->inTransaction()) $pdo->rollBack();
    foreach($servers as $server){proc_terminate($server);proc_close($server);}
    if($created) $pdo->exec("DROP DATABASE `$db`");
    foreach($files as $file) @unlink($file);
}
