<?php
function handle_heartbeat(array $auth): void {
    $body=require_json_body(); timer_require_protocol($body); validate_device_identity($auth,$body);
    rate_limit_check($auth['device_id'],'heartbeat',60,60);
    $boot=$body['boot_id']??null;
    if(!is_string($boot) || !preg_match('/^[a-f0-9]{32}$/D',$boot)) api_error('32-character boot identity required.',422);
    try {
        db()->beginTransaction(); $device=db_fetch('SELECT * FROM devices WHERE id=? FOR UPDATE',[$auth['device_id']]);
        $update=['last_seen_at'=>date('Y-m-d H:i:s'),'last_heartbeat_at'=>date('Y-m-d H:i:s'),'boot_id'=>$boot];
        if(($device['boot_id']??null)!==$boot) $update['report_sequence']=0;
        if($device['status']==='offline') $update['status']='online';
        foreach(['firmware_version'=>30,'ip_address'=>45,'wifi_ssid'=>100] as $key=>$limit) if(isset($body[$key]) && is_string($body[$key])) $update[$key]=mb_substr($body[$key],0,$limit);
        db_update('devices',$update,'id=?',[$auth['device_id']]);
        if($device['status']==='offline') {
            log_event($auth['device_id'],null,'device_connected','info','Authenticated timer device heartbeat received.');
            db()->prepare("UPDATE alerts SET status='resolved',resolved_at=NOW() WHERE device_id=? AND alert_type='device_offline' AND status='active'")->execute([$auth['device_id']]);
        }
        db()->commit(); api_success(['device_id'=>$auth['device_id'],'protocol_version'=>2,'status'=>$update['status']??$device['status'],'server_time'=>time(),'heartbeat_interval_ms'=>10000,'telemetry_interval_ms'=>2000]);
    } catch(Throwable $e) {if(db()->inTransaction()) db()->rollBack(); api_error('Heartbeat unavailable.',503);}
}
