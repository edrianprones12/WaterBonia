<?php
/** Authenticated timer protocol; retrying a claim never creates another command. */
function timer_require_protocol(array $body): void {
    if (($body['protocol_version'] ?? null) !== TIMER_PROTOCOL) api_error('Timer protocol 2 required.',422);
}
function handle_poll_command(array $auth): void {
    $body=require_json_body(); timer_require_protocol($body); validate_device_identity($auth,$body);
    rate_limit_check($auth['device_id'],'poll_command',120,60); $id=$auth['device_id'];
    try {
        db()->beginTransaction();
        $device=db_fetch('SELECT * FROM devices WHERE id=? FOR UPDATE',[$id]);
        if (!$device || ($body['boot_id']??null)!==$device['boot_id']) {db()->rollBack(); api_error('Boot identity mismatch.',409);}
        $stmt=db()->prepare("UPDATE device_commands SET status='expired',completed_at=NOW(),reject_reason='command_expired' WHERE device_id=? AND status IN ('pending','claimed') AND session_id IS NULL AND expires_at<=NOW()"); $stmt->execute([$id]);
        if($stmt->rowCount()) log_event($id,null,'command_expired','warning','Unstarted timed request expired.');
        $cmd=db_fetch("SELECT * FROM device_commands WHERE device_id=? AND status IN ('pending','claimed') AND expires_at>NOW() ORDER BY id LIMIT 1 FOR UPDATE",[$id]);
        if(!$cmd) {db()->commit(); api_success(['command'=>null]);}
        $reading=db_fetch('SELECT * FROM sensor_readings WHERE device_id=? ORDER BY recorded_at DESC,id DESC LIMIT 1',[$id]);
        $active=db_fetch("SELECT id FROM dispensing_sessions WHERE device_id=? AND status='started' LIMIT 1",[$id]);
        $reason=portal_safety_reason($device,$reading,$active,null,portal_device_safety_alert($id),[]);
        if($cmd['command_type']!=='dispense' || !in_array($cmd['container_type'], ['cup','tumbler'], true)) $reason='Unsupported command or container.';
        if(!timer_valid_duration($cmd['duration_ms']??null)) $reason='Invalid calibrated duration.';
        if($cmd['status']==='claimed' && $cmd['claim_boot_id']!==$device['boot_id']) $reason='Device restarted before execution.';
        if($reason!==null) {
            db_update('device_commands',['status'=>'rejected','completed_at'=>date('Y-m-d H:i:s'),'reject_reason'=>$reason],'id=?',[$cmd['id']]);
            log_event($id,$cmd['user_id'],'dispensing_command_rejected','warning',$reason,['command_id'=>(int)$cmd['id']]);
            db()->commit(); api_success(['command'=>null],'Request blocked.');
        }
        if($cmd['status']==='pending') {
            $cmd['claim_boot_id']=$device['boot_id'];
            db_update('device_commands',['status'=>'claimed','claimed_at'=>date('Y-m-d H:i:s'),'claim_boot_id'=>$device['boot_id']],'id=?',[$cmd['id']]);
            log_event($id,$cmd['user_id'],'dispensing_command_accepted','info','Device claimed timed request.',['command_id'=>(int)$cmd['id']]);
        }
        db()->commit(); api_success(['command'=>['protocol_version'=>2,'command_id'=>(int)$cmd['id'],'device_id'=>$id,'boot_id'=>$cmd['claim_boot_id'],
            'command_type'=>'dispense','container_type'=>$cmd['container_type'],'nominal_target_ml'=>(int)$cmd['target_amount_ml'],'duration_ms'=>(int)$cmd['duration_ms'],
            'expires_in_ms'=>max(0,min(30000,(strtotime($cmd['expires_at'])-time())*1000)),'expires_at'=>$cmd['expires_at']]]);
    } catch(Throwable $e) {if(db()->inTransaction()) db()->rollBack(); api_error('Unable to claim command.',503);}
}
function handle_command_result(array $auth): void {
    $body=require_json_body(); timer_require_protocol($body); validate_device_identity($auth,$body);
    rate_limit_check($auth['device_id'],'command_result',60,60);
    $id=portal_integer($body['command_id']??null); $result=$body['result']??null; $reason=$body['reason']??null;
    if(!$id || !in_array($result,['rejected','cancelled'],true) || !is_string($reason) || strlen($reason)<1 || strlen($reason)>150) api_error('Explicit unstarted rejection/cancellation and bounded reason required.',422);
    try {
        db()->beginTransaction(); db_fetch('SELECT id FROM devices WHERE id=? FOR UPDATE',[$auth['device_id']]);
        $cmd=db_fetch('SELECT * FROM device_commands WHERE id=? AND device_id=? FOR UPDATE',[$id,$auth['device_id']]);
        if(!$cmd) {db()->rollBack(); api_error('Command not found.',404);}
        if(($body['boot_id']??null)!==$cmd['claim_boot_id']) {db()->rollBack(); api_error('Boot identity mismatch.',409);}
        if($cmd['status']===$result && $cmd['reject_reason']===$reason) {db()->commit(); api_success(['command_id'=>$id,'status'=>$result]);}
        if($cmd['status']!=='claimed' || $cmd['session_id']!==null) {db()->rollBack(); api_error('Command already consumed.',409);}
        db_update('device_commands',['status'=>$result,'reject_reason'=>$reason,'completed_at'=>date('Y-m-d H:i:s')],'id=?',[$id]);
        log_event($auth['device_id'],$cmd['user_id'],'dispensing_command_rejected','warning','Device declined unstarted request: '.$reason,['command_id'=>$id]);
        db()->commit(); api_success(['command_id'=>$id,'status'=>$result]);
    } catch(Throwable $e) {if(db()->inTransaction()) db()->rollBack(); api_error('Unable to record rejection.',503);}
}
