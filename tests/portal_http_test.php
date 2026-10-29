<?php
/** Isolated HTTP checks. Never run against the live pump-enabled portal. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('WATERBONIA_DB_PORT')!=='33389') throw new RuntimeException('Use tests/run_timer_http_test.py; live dispensing tests are prohibited.');
$base=rtrim($argv[1]??'http://127.0.0.1:33388/WaterBonia','/');$cookie='';$count=0;
if($base!=='http://127.0.0.1:33388/WaterBonia') throw new RuntimeException('Only the isolated HTTP runner is supported.');
function request(string $path,?array $body=null): array {
    global $base,$cookie;
    $headers='Cookie: '.$cookie."\r\n".'Content-Type: application/json';
    $opts=['http'=>['method'=>$body?'POST':'GET','header'=>$headers,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]];
    if($body)$opts['http']['content']=json_encode($body);
    $text=file_get_contents($base.$path,false,stream_context_create($opts));
    foreach($http_response_header as $header)if(preg_match('/^Set-Cookie: ([^;]+)/i',$header,$m))$cookie=$m[1];
    preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0],$m);return [(int)$m[1],$text,$http_response_header];
}
function check(bool $ok,string $label): void {global $count;$count++;if(!$ok)throw new RuntimeException('FAIL '.$label);}
[$status,$text]=request('/pages/dispenser-status.php',['action'=>'select_container','container_type'=>'cup']);check($status===403,'missing CSRF denied for account-free session');
[$status,$text]=request('/pages/dispenser-status.php',['action'=>'request_dispense','container_type'=>'cup','csrf_token'=>'invalid']);check($status===403,'invalid CSRF denied');
[$status]=request('/auth/login.php');check($status===302,'retired sign-in URL redirects');
[$status]=request('/logout.php');check($status===302,'retired sign-out URL redirects');
$csrf='invalid';
[$status,$text]=request('/pages/dispenser-status.php',['action'=>'request_dispense','container_type'=>'cup','request_id'=>str_repeat('a',32),'csrf_token'=>$csrf]);
check($status===403 && json_decode($text,true)['success']===false,'account-free dispensing still validates CSRF before database access');
[$status,$text]=request('/pages/dispenser-status.php',['action'=>'request_dispense','container_type'=>'cup']);check($status===403,'missing CSRF denied');
[$status,$text,$headers]=request('/api/index.php/device/heartbeat',['protocol_version'=>2]);check($status===401,'device authentication required');
check(in_array('Content-Length: '.strlen($text),$headers,true),'error JSON length explicit');
[$status,$text,$headers]=request('/api/index.php/status');check($status===200,'public API metadata available');
check(in_array('Content-Length: '.strlen($text),$headers,true),'success JSON length explicit');
foreach(['/pages/settings.php','/pages/devices.php','/pages/dashboard.php'] as $path){[$status]=request($path);check($status===503,'account-free management fails closed on unavailable database');}
[$status,$html]=request('/pages/dispense.php');check($status===503,'DB outage fails closed');
check(!preg_match('/DEMO MODE|SIMULATED|data-demo|id="dispense-btn"/',$html),'outage has no simulation or active button');
check(!preg_match('/SQLSTATE|PDOException|Stack trace|Fatal error/',$html),'errors sanitized');
[$status,$text]=request('/pages/dispenser-status.php');$json=json_decode($text,true);check($status===503 && $json['success']===false && !isset($json['state']),'no fabricated hardware telemetry');
foreach(['/assets/js/dispenser.js','/_demo_test.php','/_portal_flow_test.php'] as $path){[$status]=request($path);check($status===404,'removed simulation path');}
echo "PASS: {$count} hardware-only HTTP outage/authentication checks. No hardware commands or records created.\n";
