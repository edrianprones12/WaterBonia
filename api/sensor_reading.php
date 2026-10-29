<?php
/** No measured-volume or reservoir telemetry is accepted by the timer protocol. */
function handle_sensor_reading(array $auth): void {
    $body=require_json_body(); timer_require_protocol($body); validate_device_identity($auth,$body);
    rate_limit_check($auth['device_id'],'sensor_reading',120,60);
    $seq=portal_integer($body['report_sequence']??null); $health=$body['sensor_status']??null;
    if(!$seq || !in_array($health,['ok','unavailable','fault'],true) || !is_bool($body['pump_status']??null)
        || !array_key_exists('container_detected',$body) || ($health==='ok' ? !is_bool($body['container_detected']) : $body['container_detected']!==null)) api_error('Explicit sensor health, nullable presence, pump state and sequence required.',422);
    $active=portal_integer($body['active_command_id']??0); $elapsed=portal_integer($body['pump_elapsed_ms']??0);
    if($active===null || $elapsed===null || $elapsed>TIMER_HARD_MAX_MS+1000 || ($body['pump_status'] && (!$active || $health!=='ok' || !$body['container_detected']))) api_error('Invalid operation telemetry.',422);
    try {
        db()->beginTransaction(); $device=db_fetch('SELECT * FROM devices WHERE id=? FOR UPDATE',[$auth['device_id']]);
        if(($body['boot_id']??null)!==($device['boot_id']??null) || !$device['boot_id'] || $seq<=(int)$device['report_sequence']) {db()->rollBack(); api_error('Old, duplicate or wrong-boot telemetry.',409);}
        if($active) {
            $command=db_fetch("SELECT * FROM device_commands WHERE id=? AND device_id=? AND status='running' AND claim_boot_id=?",[$active,$auth['device_id'],$device['boot_id']]);
            if(!$command) {db()->rollBack(); api_error('Active command mismatch.',409);}
        }
        $prev=db_fetch('SELECT * FROM sensor_readings WHERE device_id=? ORDER BY recorded_at DESC,id DESC LIMIT 1',[$auth['device_id']]);
        $present=$health==='ok' && $body['container_detected'];
        $id=db_insert('sensor_readings',['device_id'=>$auth['device_id'],'water_level_percent'=>null,'cup_detected'=>$present?1:0,'container_detected'=>$health==='ok'?($present?1:0):null,
            'hand_detected'=>0,'pump_status'=>$body['pump_status']?1:0,'battery_percent'=>null,'sensor_status'=>$health,'boot_id'=>$device['boot_id'],'report_sequence'=>$seq,
            'active_command_id'=>$active?:null,'pump_elapsed_ms'=>$active?$elapsed:null,'recorded_at'=>date('Y-m-d H:i:s')]);
        db_update('devices',['report_sequence'=>$seq,'last_seen_at'=>date('Y-m-d H:i:s')],'id=?',[$auth['device_id']]);
        if($health==='ok' && (!$prev || $prev['sensor_status']!=='ok' || (bool)$prev['container_detected']!==$present)) log_event($auth['device_id'],null,$present?'container_detected':'container_removed','info',$present?'Container detected.':'No container detected.');
        if($health!=='ok' && (!$prev || $prev['sensor_status']!==$health)) log_event($auth['device_id'],null,'sensor_error','error','IR sensor status: '.$health);
        db()->commit(); api_success(['reading_id'=>$id,'recorded_at'=>date('c')]);
    } catch(Throwable $e) {if(db()->inTransaction()) db()->rollBack(); api_error('Telemetry unavailable.',503);}
}
