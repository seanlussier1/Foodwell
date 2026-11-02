<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
use Foodwell\Services\PostalAreaClient;
use Foodwell\Services\PostalLookupException;

$root = dirname(__DIR__); $checks = 0; $files = $servers = [];
$cache = sys_get_temp_dir() . '/foodwell-postal-' . bin2hex(random_bytes(5));
mkdir($cache,0700);
function deliveryCheck(bool $ok, string $message): void { global $checks; if(!$ok)throw new RuntimeException($message); ++$checks; echo "PASS: $message\n"; }
function expectPostalReason(callable $fn,string $reason,string $message):void {
    try{$fn();}catch(PostalLookupException $e){deliveryCheck($e->reason===$reason,$message);return;}
    throw new RuntimeException('Expected postal failure: '.$message);
}
function deliveryRequest(int $port,string $query):array {
    $ctx=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>10,'header'=>"Accept: application/json\r\n"]]);
    $raw=file_get_contents("http://127.0.0.1:$port/api/v1/delivery-options?$query",false,$ctx);
    preg_match('/\s(\d{3})\s/',$http_response_header[0],$match);
    return [(int)$match[1],json_decode((string)$raw,true),$raw];
}
$pdo=null; $db=null;
try {
    $payload=['country abbreviation'=>'CA','post code'=>'H3N','places'=>[
        ['place name'=>'Fixture area','state'=>'Quebec','state abbreviation'=>'QC'],
        ['place name'=>'Second fixture place','state'=>'Quebec','state abbreviation'=>'QC']]];
    $calls=0;
    $client=new PostalAreaClient($cache,86400,'',static function($prefix)use(&$calls,$payload){++$calls;return ['status'=>200,'body'=>json_encode($payload)];});
    $one=$client->lookup('H3N'); $two=$client->lookup('H3N');
    deliveryCheck($one['source']==='test-transport' && $two['source']==='cache' && $calls===1,'Validated successful response is cached; cache avoids another upstream call');
    deliveryCheck(count($one['places'])===2,'Multiple returned places are preserved');
    file_put_contents("$cache/ca-H3N.json",'{');$client->lookup('H3N');
    deliveryCheck($calls===2,'Corrupt cache is ignored and refreshed');
    file_put_contents("$cache/ca-H3N.json",json_encode(['fetchedAt'=>time()-90000,'payload'=>$payload]));$client->lookup('H3N');
    deliveryCheck($calls===3,'Expired cache is refreshed');
    file_put_contents("$cache/ca-H3N.json",json_encode(['fetchedAt'=>time()+90000,'payload'=>$payload]));$client->lookup('H3N');
    deliveryCheck($calls===4,'Future-dated cache is not trusted');
    foreach([[404,'{}','not-found'],[429,'{}','rate-limited'],[503,'{}','upstream-error'],[302,'{}','upstream-error'],[200,'{','invalid-response'],[200,'null','invalid-response'],[200,str_repeat('x',65537),'invalid-response']] as [$status,$body,$reason]) {
        $fake=new PostalAreaClient($cache,0,'',fn($prefix)=>['status'=>$status,'body'=>$body]);
        expectPostalReason(fn()=>$fake->lookup('H3N'),$reason,"Provider $status/$reason is controlled");
    }
    foreach([
        array_merge($payload,['post code'=>'H3S']),array_merge($payload,['country abbreviation'=>'US']),array_merge($payload,['places'=>[]]),
        array_merge($payload,['places'=>[['place name'=>'Missing province']]])
    ] as $invalid){
        $fake=new PostalAreaClient($cache,0,'',fn($prefix)=>['status'=>200,'body'=>json_encode($invalid)]);
        expectPostalReason(fn()=>$fake->lookup('H3N'),'invalid-response','Unexpected provider schema/country/prefix is rejected');
    }
    $timeout=new PostalAreaClient($cache,0,'',static function($prefix){throw new PostalLookupException('timeout');});
    expectPostalReason(fn()=>$timeout->lookup('H3N'),'timeout','Bounded timeout reason propagates for fallback');
    expectPostalReason(fn()=>$client->lookup('../../etc'),'invalid-prefix','Provider client rejects unvalidated path input');

    $host=getenv('SCHEMA_TEST_HOST')?:'127.0.0.1';$port=getenv('SCHEMA_TEST_PORT')?:'3306';$user=getenv('SCHEMA_TEST_USER')?:'root';$pass=getenv('SCHEMA_TEST_PASS')?:'';
    $pdo=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db='foodwell_delivery_'.bin2hex(random_bytes(6));$pdo->exec("CREATE DATABASE `$db`");$pdo->exec("USE `$db`");
    $pdo->exec(file_get_contents("$root/database/schema.sql"));$pdo->exec(file_get_contents("$root/database/seed.sql"));
    $initial=[$pdo->query('SELECT * FROM delivery_slots ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll()];
    $modes=['demo-success'=>true,'demo-timeout'=>true,'demo-malformed'=>true,'demo-not-found'=>true,'demo-success-blocked'=>false];
    if(in_array('--live',$argv,true))$modes['live']=false;
    foreach($modes as $testMode=>$demoAllowed){
        $mode=$testMode==='demo-success-blocked'?'demo-success':$testMode;
        $sock=stream_socket_server('tcp://127.0.0.1:0',$errno,$err);if(!$sock)throw new RuntimeException('Port unavailable.');
        $httpPort=(int)substr(strrchr(stream_socket_get_name($sock,false),':'),1);fclose($sock);
        $router=tempnam(sys_get_temp_dir(),'delivery-router-');$files[]=$router;
        file_put_contents($router,'<?php '.($pass===''?'putenv("DB_PASS=");':'').'return require '.var_export("$root/public/index.php",true).';');
        $log=tempnam(sys_get_temp_dir(),'delivery-log-');$files[]=$log;
        $env=array_merge(getenv(),['DB_HOST'=>$host,'DB_PORT'=>$port,'DB_NAME'=>$db,'DB_USER'=>$user,'DB_PASS'=>$pass,'APP_BASE_PATH'=>'',
            'POSTAL_LOOKUP_MODE'=>$mode,'APP_POSTAL_DEMO'=>$demoAllowed?'1':'0','DELIVERY_AREA_PREFIXES'=>'H3N,H3S,H3R']);
        $null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
        $server=proc_open([PHP_BINARY,'-d','display_errors=0','-d',"error_log=$log",'-S',"127.0.0.1:$httpPort",'-t',"$root/public",$router],
            [0=>['file',$null,'r'],1=>['file',$null,'w'],2=>['file',$log,'a']],$pipes,$root,$env);
        if(!is_resource($server))throw new RuntimeException('Could not start API fixture.');$servers[]=$server;
        $ready=false;for($i=0;$i<50;$i++){$s=@fsockopen('127.0.0.1',$httpPort);if($s){fclose($s);$ready=true;break;}usleep(100000);}
        deliveryCheck($ready,"Temporary server starts for $testMode");
        [$status,$result]=deliveryRequest($httpPort,'postalCode=h3n1x1');
        deliveryCheck($status===200 && $result['postalCode']==='H3N 1X1' && $result['area']['prefix']==='H3N',"$testMode returns normalized composite envelope");
        deliveryCheck($result['delivery']['bookingConfirmed']===false,'Lookup never claims a confirmed booking');
        if($testMode==='demo-success' || $testMode==='live'){
            deliveryCheck($result['status']==='complete' && $result['delivery']['eligible']===true && $result['delivery']['status']==='available','Known served area combines external location with internal availability');
            deliveryCheck(array_column($result['delivery']['slots'],'id')===[304] && $result['delivery']['slots'][0]['remainingCapacity']===3,'Full, inactive and past slots excluded; internal free capacity retained');
            if($testMode==='demo-success'){
                deliveryCheck($result['area']['source']==='demo' && in_array('demo-data',array_column($result['warnings'],'code'),true),'Controlled success is explicitly labeled demo data');
                [$status,$outside]=deliveryRequest($httpPort,'postalCode=M5V1X1');
                deliveryCheck($status===200 && $outside['delivery']['eligible']===false && $outside['delivery']['slots']===[] && $outside['delivery']['status']==='outside-area','Known area outside configured prefixes offers no slots');
                foreach(['','postalCode=','postalCode=H3N','postalCode=123456','postalCode=D3N1X1','postalCode[]=H3N1X1','postalCode=H3N1X1&url=https://example.com','postalCode=H3N1X1&mode=live'] as $query){deliveryCheck(deliveryRequest($httpPort,$query)[0]===400,'Invalid/array/extra query parameters return 400');}
                $pdo->exec("UPDATE orders SET status='cancelled' WHERE id=900");
                $available=deliveryRequest($httpPort,'postalCode=H3N1X1')[1];
                deliveryCheck(array_column($available['delivery']['slots'],'id')===[301,304] && $available['delivery']['slots'][0]['remainingCapacity']===1,'Cancelled orders release capacity; windows are sorted');
                $pdo->exec('UPDATE delivery_slots SET is_active=0 WHERE id=304');
                deliveryCheck(array_column(deliveryRequest($httpPort,'postalCode=H3N1X1')[1]['delivery']['slots'],'id')===[301],'Inactive future windows excluded');
                $pdo->exec("UPDATE orders SET status='pending' WHERE id=900");
                $none=deliveryRequest($httpPort,'postalCode=H3N1X1')[1];
                deliveryCheck($none['delivery']['eligible']===true && $none['delivery']['status']==='no-slots' && $none['delivery']['slots']===[],'Served area with no capacity is distinguished from outside service area');
                $pdo->exec('UPDATE delivery_slots SET is_active=1 WHERE id=304');
                // Restore timestamps changed by the test's own capacity toggles.
                $restore=$pdo->prepare('UPDATE delivery_slots SET updated_at=:stamp WHERE id=:id');
                foreach($initial[0] as $slot)$restore->execute(['stamp'=>$slot['updated_at'],'id'=>$slot['id']]);
                $restoreOrder=$pdo->prepare('UPDATE orders SET updated_at=:stamp WHERE id=:id');
                foreach($initial[1] as $order)$restoreOrder->execute(['stamp'=>$order['updated_at'],'id'=>$order['id']]);
                // Exercise the PHP consumer against this disposable composite server.
                $consumer=proc_open([PHP_BINARY,'-d','xdebug.mode=off',"$root/scripts/delivery-client.php",'H3N 1X1'],
                    [0=>['file',$null,'r'],1=>['pipe','w'],2=>['file',$log,'a']],$consumerPipes,$root,array_merge($env,['DELIVERY_API_BASE_URL'=>"http://127.0.0.1:$httpPort/api/v1"]));
                if(!is_resource($consumer))throw new RuntimeException('PHP consumer unavailable.');
                $consumerBody=stream_get_contents($consumerPipes[1]);fclose($consumerPipes[1]);$rc=proc_close($consumer);
                deliveryCheck($rc===0 && str_contains($consumerBody,'HTTP 200') && str_contains($consumerBody,'demo-data'),'PHP consumer calls the composite API and retains fixture labels');
            }else{
                deliveryCheck(in_array($result['area']['source'],['live','cache'],true) && $result['area']['places'][0]['provinceCode']==='QC','Live/cached provider area is labeled correctly and matches Quebec');
                echo 'LIVE COMPOSITE: '.$result['area']['source'].'; '.$result['area']['places'][0]['name']."\n";
            }
        }else{
            deliveryCheck($result['status']==='partial' && $result['delivery']['eligible']===null && $result['delivery']['slots']===[] && $result['delivery']['status']==='unknown',"$testMode returns honest unknown-availability fallback");
            $expected=['demo-timeout'=>'timeout','demo-malformed'=>'invalid-response','demo-not-found'=>'not-found','demo-success-blocked'=>'configuration'][$testMode];
            deliveryCheck(in_array($expected,array_column($result['warnings'],'code'),true),"$testMode has explicit failure reason");
            deliveryCheck($result['area']['source']===($demoAllowed?'demo':'unavailable'),'Demo failure cannot be mistaken for live provider data');
        }
    }
    deliveryCheck([$pdo->query('SELECT * FROM delivery_slots ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll()]===$initial,'Lookups/consumers create no bookings or persistent slot/order changes');
    if(in_array('--live',$argv,true)){
        $live=new PostalAreaClient($cache,0,getenv('POSTAL_CA_BUNDLE')?:'');$lookup=$live->lookup('H3N');
        deliveryCheck($lookup['source']==='live' && $lookup['places'][0]['provinceCode']==='QC','Uncached real HTTPS provider request succeeds with TLS verification');
        echo 'LIVE PROVIDER: '.$lookup['places'][0]['name']."\n";
    }
    echo "$checks delivery checks passed. ".(in_array('--live',$argv,true)?'Includes live provider requests.':'Provider behavior uses labeled test doubles; no live provider calls.')." Application databases were not used.\n";
}finally{
    foreach($servers as $server){proc_terminate($server);proc_close($server);}
    if($pdo!==null && $db!==null)$pdo->exec("DROP DATABASE `$db`");
    foreach($files as $file)@unlink($file);
    foreach(glob($cache.'/*')?:[] as $file)@unlink($file);@rmdir($cache);
}
