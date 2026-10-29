<?php
/** State projection fixtures only: no live database, telemetry or device access. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
const CONTAINER_TYPES = ['cup','tumbler'];
$_SESSION = [];
function get_setting(string $key, string $default=''): string { return $default; }
require __DIR__.'/../config/portal.php';
function refresh_device_offline_state(?array $device): void {}
function dispenser_status_text(array $state): string { return 'fixture'; }
function db_fetch(string $query, array $params=[]): ?array {
    global $reading, $alert;
    if (str_contains($query,'FROM sensor_readings')) return $reading;
    if (str_contains($query,'FROM alerts')) return $alert ? ['id'=>99] : null;
    if (str_contains($query,'FROM dispensing_sessions') || str_contains($query,'FROM device_commands')) return null;
    throw new RuntimeException('Unexpected fixture query.');
}
$source=file_get_contents(__DIR__.'/../includes/functions.php');
$start=strpos($source,'function get_live_dispenser_state(');
$end=strpos($source,'/**',$start);
eval(substr($source,$start,$end-$start));
$count=0;
function check(bool $ok,string $message): void { global $count; $count++; if(!$ok) throw new RuntimeException($message); }
$device=['id'=>7,'status'=>'online','boot_id'=>'fixture-boot','last_heartbeat_at'=>date('Y-m-d H:i:s')];
$fresh=['device_id'=>7,'sensor_status'=>'ok','container_detected'=>1,'pump_status'=>0,'boot_id'=>'fixture-boot','recorded_at'=>date('Y-m-d H:i:s')];
foreach ([true,false] as $alert) foreach ([0,1] as $present) {
    $reading=array_replace($fresh,['container_detected'=>$present]);
    $state=get_live_dispenser_state($device);
    check($state['telemetry_valid']===true,'A safety alert must not invalidate a fresh healthy sensor report.');
    check($state['container_detected']===(bool)$present,'Actual presence remains visible.');
    check($state['pump_status']===false,'Reported pump OFF remains visible.');
    if($alert) check($state['safety_blocked']===true,'Outstanding alert still blocks dispensing.');
    else check($state['safety_blocked']===!((bool)$present),'Normal readiness still requires presence.');
}
$alert=true;
foreach ([array_replace($fresh,['recorded_at'=>date('Y-m-d H:i:s',time()-11)]),array_replace($fresh,['sensor_status'=>'fault']),array_replace($fresh,['boot_id'=>'another-boot']),null] as $reading) {
    $state=get_live_dispenser_state($device);
    check($state['telemetry_valid']===false,'Unavailable/stale/invalid telemetry remains invalid.');
    check($state['container_detected']===null,'Untrusted presence remains Unknown.');
    check($state['safety_blocked']===true,'Untrusted telemetry blocks dispensing.');
}
$reading=$fresh;
$state=get_live_dispenser_state(array_replace($device,['last_heartbeat_at'=>date('Y-m-d H:i:s',time()-31)]));
check($state['device_online']===false && $state['container_detected']===null && $state['safety_blocked']===true,'Offline device still has unknown presence and blocks dispensing.');
echo "PASS: {$count} independent sensor-validity/safety-block projection checks. No live database or hardware.\n";
