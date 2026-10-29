<?php
/** Uses an explicitly supplied isolated database; never the configured live database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('WATERBONIA_TEST_PORT') !== '33287') throw new RuntimeException('Run through the isolated database test runner.');
$pdo = new PDO('mysql:host=127.0.0.1;port=33287;dbname=waterbonia;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET time_zone = '.$pdo->quote(date('P')));
function db(): PDO { global $pdo; return $pdo; }
function db_fetch(string $sql,array $params=[]): ?array { $s=db()->prepare($sql);$s->execute($params);return $s->fetch()?:null; }
function db_insert(string $table,array $data): int { $s=db()->prepare('INSERT INTO '.$table.' (`'.implode('`,`',array_keys($data)).'`) VALUES ('.implode(',',array_fill(0,count($data),'?')).')');$s->execute(array_values($data));return (int)db()->lastInsertId(); }
function db_update(string $table,array $data,string $where,array $params=[]): int { $s=db()->prepare('UPDATE '.$table.' SET '.implode(',',array_map(fn($k)=>'`'.$k.'`=?',array_keys($data))).' WHERE '.$where);$s->execute(array_merge(array_values($data),$params));return $s->rowCount(); }
function get_setting(string $key,string $default=''): string { return db_fetch('SELECT setting_value FROM system_settings WHERE setting_key=?',[$key])['setting_value']??$default; }
function log_event(?int $device,?int $user,string $event,string $level,string $message,?array $metadata=null): void {db_insert('activity_logs',['device_id'=>$device,'user_id'=>$user,'event_type'=>$event,'event_level'=>$level,'message'=>$message,'metadata'=>$metadata?json_encode($metadata):null]);}
function create_alert(?int $device,string $type,string $severity,string $title,string $message): int {return db_insert('alerts',['device_id'=>$device,'alert_type'=>$type,'severity'=>$severity,'title'=>$title,'message'=>$message]);}
function rate_limit_check(int $id,string $action,int $max,int $window): void {}
function require_json_body(): array {global $body; return $body;}
function validate_device_identity(array $auth,array $input): void {if(isset($input['device_code']) && $input['device_code']!==$auth['device_code']) api_error('Device mismatch.',403);}
function api_success(mixed $data=null,string $message='',int $status=200): never {Fiber::suspend(['success'=>true,'data'=>$data,'status'=>$status]);throw new LogicException('Do not resume');}
function api_error(string $error,int $status=400,array $details=[]): never {Fiber::suspend(['success'=>false,'error'=>$error,'status'=>$status]);throw new LogicException('Do not resume');}
require __DIR__.'/../config/portal.php';
require __DIR__.'/../api/device_command.php';
require __DIR__.'/../api/heartbeat.php';
require __DIR__.'/../api/sensor_reading.php';
require __DIR__.'/../api/dispensing.php';
$count=0;
function check(bool $ok,string $label): void {global $count; $count++; if(!$ok) throw new RuntimeException('FAIL '.$label);}
$device=(int)db_fetch('SELECT id FROM devices ORDER BY id LIMIT 1')['id'];
// Historical FK fixture only; the application itself creates/uses no accounts.
if (!db_fetch('SELECT id FROM users LIMIT 1')) db_insert('users',['username'=>'legacy_fixture','email'=>'fixture@example.invalid','password_hash'=>'not-a-login-credential','full_name'=>'Historical fixture','role'=>'viewer','status'=>'inactive']);
$user=(int)db_fetch('SELECT id FROM users ORDER BY id LIMIT 1')['id'];
$auth=['device_id'=>$device,'device_code'=>db_fetch('SELECT device_code FROM devices WHERE id=?',[$device])['device_code']];
$boot=str_repeat('a',32);
function call_handler(string $handler,array $input): array {global $body,$auth; $body=$input; $fiber=new Fiber(fn()=>$handler($auth)); $r=$fiber->start(); if(db()->inTransaction()) db()->rollBack(); return $r;}
$base=['protocol_version'=>2,'boot_id'=>$boot];
check(call_handler('handle_heartbeat',$base)['success'],'heartbeat');
check(!call_handler('handle_sensor_reading',$base+['report_sequence'=>1,'pump_status'=>false])['success'],'missing sensor fields');
$sample=$base+['report_sequence'=>1,'pump_status'=>false,'sensor_status'=>'ok','container_detected'=>true];
check(call_handler('handle_sensor_reading',$sample)['success'],'IR telemetry');
check(!call_handler('handle_sensor_reading',$sample)['success'],'duplicate sample rejected');
check(!call_handler('handle_sensor_reading',array_replace($sample,['boot_id'=>str_repeat('b',32),'report_sequence'=>2]))['success'],'wrong boot rejected');
check(timer_duration_ms('cup')===null,'duration disabled by default');
foreach([0,999,30001,'abc',false,1.5] as $duration) check(!timer_valid_duration($duration),'duration range');
function queue_test(int $duration=1000): int {global $device,$user;return db_insert('device_commands',['device_id'=>$device,'user_id'=>$user,'command_type'=>'dispense','container_type'=>'cup','target_amount_ml'=>250,'duration_ms'=>$duration,'status'=>'pending','requested_at'=>date('Y-m-d H:i:s'),'expires_at'=>date('Y-m-d H:i:s',time()+30),'request_id'=>bin2hex(random_bytes(16))]);}
// Exercise the real public kiosk command service against isolated SQL/locks.
$source=file_get_contents(__DIR__.'/../includes/functions.php');
$begin=strpos($source,'function request_dispense('); $end=strpos($source,'/**',$begin);
eval(substr($source,$begin,$end-$begin));
$_SESSION=[];
db_update('system_settings',['setting_value'=>(string)$device],'setting_key=?',['portal_device_id']);
check(!request_dispense($device,'cup',null,null,str_repeat('x',16))[0],'guest still blocked by uncalibrated preset');
db_update('system_settings',['setting_value'=>'1'],'setting_key=?',['container_cup_duration_seconds']);
$requested=request_dispense($device,'cup',$user,null,str_repeat('x',16));check($requested[0],'guest request queued without login');
$stored=db_fetch('SELECT * FROM device_commands WHERE id=?',[$requested[2]]);
check($stored['user_id']===null,'guest not attributed to caller-supplied user');
check(strlen($stored['request_id'])===64 && $stored['request_id']!==str_repeat('x',16),'request key scoped server-side');
$retried=request_dispense($device,'cup',null,null,str_repeat('x',16));check($retried[0] && $retried[2]===$requested[2],'guest retry idempotent with NULL user');
$_SESSION['user_id']=$user;
$retried=request_dispense($device,'cup',null,null,str_repeat('x',16));check($retried[0] && $retried[2]===$requested[2],'obsolete session identity does not create another command');
unset($_SESSION['user_id']);
$owner_session=$_SESSION;$_SESSION=[];
check(!request_dispense($device,'cup',null,null,str_repeat('x',16))[0],'other browser cannot adopt active request with same client key');
$_SESSION=$owner_session;
check(!request_dispense($device,'cup',null,null,str_repeat('y',16))[0],'overlapping guest request denied');
check(!request_dispense($device+1,'cup',null,null,str_repeat('z',16))[0],'wrong bound device denied');
$id=$requested[2];
$claim=call_handler('handle_poll_command',$base); if(($claim['data']['command']['command_id']??0)!==$id) echo json_encode(['response'=>$claim,'command'=>db_fetch('SELECT status,reject_reason FROM device_commands WHERE id=?',[$id])]).PHP_EOL; check(($claim['data']['command']['command_id']??0)===$id,'claim command');
check(call_handler('handle_poll_command',$base)['data']['command']['command_id']===$id,'lost claim response retry returns same');
$start=$base+['command_id'=>$id,'container_detected'=>true,'sensor_status'=>'ok'];
check(call_handler('handle_dispensing_start',$start)['data']['execute']===true,'start authorized');
check(db_fetch('SELECT status FROM device_commands WHERE id=?',[$id])['status']==='running','start not completed');
check(call_handler('handle_dispensing_start',$start)['data']['execute']===false,'duplicate start must not execute');
$result=$base+['command_id'=>$id,'pump_status'=>false,'pump_elapsed_ms'=>1001,'stop_reason'=>'duration_elapsed'];
check(!call_handler('handle_dispensing_complete',array_replace($result,['pump_elapsed_ms'=>500]))['success'],'early completion rejected');
check(!call_handler('handle_dispensing_complete',array_replace($result,['pump_status'=>true]))['success'],'pump on completion rejected');
check(call_handler('handle_dispensing_complete',$result)['success'],'device-confirmed timer completion');
$logs=db_fetch("SELECT COUNT(*) n FROM activity_logs WHERE event_type='dispensing_completed'")['n'];
check(call_handler('handle_dispensing_complete',$result)['success'],'completion retry');
check(db_fetch("SELECT COUNT(*) n FROM activity_logs WHERE event_type='dispensing_completed'")['n']===$logs,'no duplicate event');
check(!call_handler('handle_dispensing_complete',array_replace($result,['stop_reason'=>'error']))['success'],'conflicting final report');
check(db_fetch("SELECT user_id FROM activity_logs WHERE event_type='dispensing_completed' ORDER BY id DESC LIMIT 1")['user_id']===null,'guest final log preserves anonymous attribution');
$session=db_fetch('SELECT * FROM dispensing_sessions ORDER BY id DESC LIMIT 1');
check($session['measured_amount_ml']===null && $session['amount_dispensed_ml']===null && $session['water_level_after']===null,'no fabricated measurements');
check($session['device_started_at']===null && $session['device_finished_at']===null,'no invented physical wall timestamps');
// Same-browser retries remain consumed after completion. Another browser's key is independent.
$retried=request_dispense($device,'cup',null,null,str_repeat('x',16));
check($retried[0] && $retried[2]===$requested[2],'completed guest request cannot be re-executed by retry');
$_SESSION=[];
$other=request_dispense($device,'cup',null,null,str_repeat('x',16));
check($other[0] && $other[2]!==$requested[2],'another browser has independent request scope');
db_update('device_commands',['status'=>'cancelled'],'id=?',[$other[2]]);
db_update('users',['role'=>'viewer'],'id=?',[$user]);$_SESSION['user_id']=$user;
$viewer=request_dispense($device,'cup',null,null,str_repeat('v',16));
check($viewer[0],'obsolete account state does not block account-free dispensing');
check(db_fetch('SELECT user_id FROM device_commands WHERE id=?',[$viewer[2]])['user_id']===null,'obsolete session account is never used for attribution');
db_update('device_commands',['status'=>'cancelled'],'id=?',[$viewer[2]]);
$_SESSION=[];
foreach(['container_removed'=>'interrupted','sensor_fault'=>'failed','device_reset'=>'failed','max_runtime_exceeded'=>'failed'] as $reason=>$status) {
    $id=queue_test(); check(call_handler('handle_poll_command',$base)['success'],'claim stop case');
    check(call_handler('handle_dispensing_start',array_replace($start,['command_id'=>$id]))['success'],'start stop case');
    $r=call_handler('handle_dispensing_complete',array_replace($result,['command_id'=>$id,'pump_elapsed_ms'=>10,'stop_reason'=>$reason]));
    check(($r['data']['status']??null)===$status,'truthful stop result');
    db()->exec("UPDATE alerts SET status='resolved'");
}
$id=queue_test();call_handler('handle_poll_command',$base);
check(call_handler('handle_dispensing_complete',array_replace($result,['command_id'=>$id,'pump_elapsed_ms'=>0,'stop_reason'=>'device_reset']))['success'],'ambiguous start safely abandoned');
$id=queue_test();db_update('device_commands',['expires_at'=>date('Y-m-d H:i:s',time()-1)],'id=?',[$id]);
check(call_handler('handle_poll_command',$base)['data']['command']===null,'expired command not dispatched');
check(db_fetch('SELECT status FROM device_commands WHERE id=?',[$id])['status']==='expired','expiry stored');
$id=queue_test();db_update('devices',['last_heartbeat_at'=>date('Y-m-d H:i:s',time()-31)],'id=?',[$device]);
check(call_handler('handle_poll_command',$base)['data']['command']===null,'stale heartbeat blocks claim');
$id=queue_test();db_update('device_commands',['command_type'=>'sync'],'id=?',[$id]);
call_handler('handle_heartbeat',$base);
check(call_handler('handle_poll_command',$base)['data']['command']===null,'unsupported command not dispatched as dispense');
check(db_fetch('SELECT status FROM device_commands WHERE id=?',[$id])['status']==='rejected','unsupported command rejected');
echo "PASS: {$count} isolated MariaDB migration and timer API checks. No live DB or hardware accessed.\n";
