<?php
/** Server start is authorization, not proof of physical pump activation. */
function handle_dispensing_start(array $auth): void {
    $body=require_json_body(); timer_require_protocol($body); validate_device_identity($auth,$body);
    rate_limit_check($auth['device_id'],'dispensing_start',60,60);
    $id=portal_integer($body['command_id']??null);
    if(!$id || ($body['container_detected']??null)!==true || ($body['sensor_status']??null)!=='ok') api_error('Command and current local presence checks required.',422);
    try {
        db()->beginTransaction(); $device=db_fetch('SELECT * FROM devices WHERE id=? FOR UPDATE',[$auth['device_id']]);
        $cmd=db_fetch('SELECT * FROM device_commands WHERE id=? AND device_id=? FOR UPDATE',[$id,$auth['device_id']]);
        if(!$cmd || ($body['boot_id']??null)!==$cmd['claim_boot_id'] || ($body['boot_id']??null)!==($device['boot_id']??null)) {db()->rollBack(); api_error('Command identity mismatch.',409);}
        if($cmd['session_id']) {db()->commit(); api_success(['session_id'=>(int)$cmd['session_id'],'execute'=>false,'status'=>$cmd['status']],'Already authorized; do not execute again.');}
        $reading=db_fetch('SELECT * FROM sensor_readings WHERE device_id=? ORDER BY recorded_at DESC,id DESC LIMIT 1',[$auth['device_id']]);
        $active=db_fetch("SELECT id FROM dispensing_sessions WHERE device_id=? AND status='started' LIMIT 1",[$auth['device_id']]);
        $reason=portal_safety_reason($device,$reading,$active,null,portal_device_safety_alert($auth['device_id']),[]);
        if($cmd['status']!=='claimed' || strtotime($cmd['expires_at'])<=time() || !timer_valid_duration($cmd['duration_ms']) || $reason!==null) {db()->rollBack(); api_error($reason??'Command expired, consumed, or unauthorized.',409);}
        $session=db_insert('dispensing_sessions',['device_id'=>$auth['device_id'],'started_at'=>date('Y-m-d H:i:s'),'cup_detected'=>1,'hand_detected'=>0,'water_level_before'=>null,
            'container_type'=>$cmd['container_type'],'target_amount_ml'=>$cmd['target_amount_ml'],'configured_duration_ms'=>$cmd['duration_ms'],'status'=>'started']);
        db_update('device_commands',['status'=>'running','session_id'=>$session],'id=?',[$id]);
        log_event($auth['device_id'],$cmd['user_id'],'dispensing_started','info','Timed operation authorized; physical start not yet confirmed.',
            ['command_id'=>$id,'session_id'=>$session,'duration_ms'=>(int)$cmd['duration_ms'],'mode'=>'hardware']);
        db()->commit(); api_success(['session_id'=>$session,'execute'=>true,'duration_ms'=>(int)$cmd['duration_ms'],'status'=>'started'],'Execution authorized.',201);
    } catch(Throwable $e) {if(db()->inTransaction()) db()->rollBack(); api_error('Unable to authorize operation.',503);}
}
function handle_dispensing_complete(array $auth): void {
    $body=require_json_body(); timer_require_protocol($body); validate_device_identity($auth,$body);
    rate_limit_check($auth['device_id'],'dispensing_complete',60,60);
    $id=portal_integer($body['command_id']??null); $elapsed=portal_integer($body['pump_elapsed_ms']??null); $reason=$body['stop_reason']??null;
    if(!$id || $elapsed===null || !is_string($reason) || ($body['pump_status']??null)!==false) api_error('Command, elapsed timer, explicit stop reason and pump OFF confirmation required.',422);
    try {
        db()->beginTransaction(); db_fetch('SELECT id FROM devices WHERE id=? FOR UPDATE',[$auth['device_id']]);
        $cmd=db_fetch('SELECT * FROM device_commands WHERE id=? AND device_id=? FOR UPDATE',[$id,$auth['device_id']]);
        if(!$cmd || ($body['boot_id']??null)!==$cmd['claim_boot_id']) {db()->rollBack(); api_error('Operation not found for device and original boot.',404);}
        if (!$cmd['session_id']) {
            if ($reason !== 'device_reset' || $elapsed !== 0 || !in_array($cmd['status'], ['claimed','expired','rejected','cancelled'], true)) { db()->rollBack(); api_error('No authorized operation exists.',409); }
            if ($cmd['status'] === 'claimed') {
                db_update('device_commands',['status'=>'cancelled','reject_reason'=>'device_reset','completed_at'=>date('Y-m-d H:i:s')],'id=?',[$id]);
                log_event($auth['device_id'],$cmd['user_id'],'dispensing_request_cancelled','warning','Ambiguous start abandoned without repeating pump activation.',['command_id'=>$id]);
            }
            db()->commit(); api_success(['command_id'=>$id,'status'=>'cancelled','session_id'=>null],'No physical completion asserted.');
        }
        $session=db_fetch('SELECT * FROM dispensing_sessions WHERE id=? AND device_id=? FOR UPDATE',[$cmd['session_id'],$auth['device_id']]);
        $status=timer_result_status($reason,$elapsed,(int)$cmd['duration_ms']);
        if(!$session || !$status) {db()->rollBack(); api_error('Invalid timed result.',422);}
        if($session['status']!=='started') {
            if($session['stop_reason']!==$reason || (int)$session['pump_elapsed_ms']!==$elapsed) {db()->rollBack(); api_error('Conflicting final report.',409);}
            db()->commit(); api_success(['session_id'=>(int)$session['id'],'status'=>$session['status']],'Already recorded.');
        }
        db_update('dispensing_sessions',['status'=>$status,'ended_at'=>date('Y-m-d H:i:s'),'result_received_at'=>date('Y-m-d H:i:s'),'duration_seconds'=>(int)ceil($elapsed/1000),
            'pump_elapsed_ms'=>$elapsed,'stop_reason'=>$reason,'amount_dispensed_ml'=>null,'measured_amount_ml'=>null,'water_level_after'=>null],'id=? AND status=?',[$session['id'],'started']);
        $result=$status==='interrupted'?'stopped':$status;
        db_update('device_commands',['status'=>$result,'completed_at'=>date('Y-m-d H:i:s'),'reject_reason'=>$status==='completed'?null:$reason],'id=?',[$id]);
        log_event($auth['device_id'],$cmd['user_id'],$status==='completed'?'dispensing_completed':'dispensing_interrupted',$status==='failed'?'error':'info',
            "Device reported timed operation {$result}: {$reason}; pump elapsed {$elapsed} ms. Volume not measured.",
            ['command_id'=>$id,'session_id'=>(int)$session['id'],'container_type'=>$cmd['container_type'],'duration_ms'=>(int)$cmd['duration_ms'],'pump_elapsed_ms'=>$elapsed,'stop_reason'=>$reason,'mode'=>'hardware']);
        if($status!=='completed') create_alert($auth['device_id'],'dispensing_interrupted','warning','Timed operation stopped',str_replace('_',' ',$reason));
        db()->commit(); api_success(['session_id'=>(int)$session['id'],'status'=>$status,'pump_elapsed_ms'=>$elapsed]);
    } catch(Throwable $e) {if(db()->inTransaction()) db()->rollBack(); api_error('Unable to record result; retry the same report.',503);}
}
