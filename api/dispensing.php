<?php
/**
 * WaterBonia API - Dispensing Session Endpoints
 *
 * POST /api/device/dispensing/start
 * POST /api/device/dispensing/complete
 */

function handle_dispensing_start(array $auth): void
{
    rate_limit_check($auth['device_id'], 'dispensing_start', 30, 60);

    $body = require_json_body();
    validate_device_identity($auth, $body);

    $device_id = $auth['device_id'];
    $errors = [];

    // Validate optional booleans
    if (isset($body['cup_detected'])) {
        $err = validate_bool($body['cup_detected'], 'cup_detected');
        if ($err) $errors['cup_detected'] = $err;
        else $body['cup_detected'] = (bool)$body['cup_detected'];
    }
    if (isset($body['container_detected'])) {
        $err = validate_bool($body['container_detected'], 'container_detected');
        if ($err) $errors['container_detected'] = $err;
        else $body['container_detected'] = (bool)$body['container_detected'];
    }

    // Validate container_type
    if (isset($body['container_type']) && $body['container_type'] !== null) {
        if (!in_array($body['container_type'], ['cup', 'tumbler'], true)) {
            $errors['container_type'] = 'Must be "cup" or "tumbler".';
        }
    }

    $command_id = portal_integer($body['command_id'] ?? null);
    if (!$command_id) $errors['command_id'] = 'A claimed dispensing command is required.';
    $requested_target = portal_integer($body['target_amount_ml'] ?? null);
    if ($requested_target === null || $requested_target < 1 || $requested_target > 2000) $errors['target_amount_ml'] = 'Must be a whole-number fill amount between 1 and 2000 mL.';

    if (!empty($errors)) {
        api_error('Validation failed', 422, $errors);
    }

    // Lock in the same device-first order as queue/claim. A session can only
    // consume its authenticated device's claimed, matching immutable command.
    db()->beginTransaction();
    try {
        $device = db_fetch('SELECT * FROM devices WHERE id = ? FOR UPDATE', [$device_id]);
        $cmd = db_fetch('SELECT * FROM device_commands WHERE id = ? AND device_id = ? FOR UPDATE', [$command_id, $device_id]);
        [$authorized, $reason, $target_ml] = portal_session_start_target($cmd, $device_id, $body['container_type'] ?? null, $requested_target);
        if (!$authorized) {
            db()->rollBack();
            api_error($reason, 409);
        }
        $container_type = $cmd['container_type'];
        $reading = db_fetch('SELECT * FROM sensor_readings WHERE device_id = ? ORDER BY recorded_at DESC,id DESC LIMIT 1', [$device_id]);
        $active = db_fetch("SELECT id FROM dispensing_sessions WHERE device_id = ? AND status = 'started' LIMIT 1", [$device_id]);
        $other = db_fetch("SELECT id FROM device_commands WHERE device_id = ? AND id <> ? AND status IN ('pending','claimed') AND (status = 'claimed' OR expires_at > NOW()) LIMIT 1", [$device_id, $command_id]);
        $reason = portal_safety_reason($device, $reading, $active, $other, portal_device_safety_alert($device_id), [], $target_ml);
        if (($body['container_detected'] ?? $body['cup_detected'] ?? null) === false) $reason = 'The device reports that the container is absent.';
        if ($reason !== null) {
            db()->rollBack();
            api_error($reason, 409);
        }
        $session_id = db_insert('dispensing_sessions', [
            'device_id' => $device_id, 'started_at' => date('Y-m-d H:i:s'),
            'cup_detected' => 1, 'hand_detected' => 0, 'water_level_before' => null,
            'container_type' => $container_type, 'target_amount_ml' => $target_ml, 'status' => 'started',
        ]);
        db_update('device_commands', ['status' => 'completed', 'session_id' => $session_id, 'completed_at' => date('Y-m-d H:i:s')],
            "id = ? AND device_id = ? AND status = 'claimed'", [$command_id, $device_id]);
        log_event($device_id, null, 'dispensing_started', 'info',
            "Dispensing session #{$session_id} started (container: {$container_type}, target: {$target_ml}mL).",
            ['command_id' => $command_id, 'container_type' => $container_type, 'target_amount_ml' => $target_ml, 'fill_amount_ml' => $target_ml, 'threshold_semantics' => 'fill_volume_ml']);
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        api_error('Could not authorize the dispensing session.', 500);
    }

    api_success([
        'session_id'      => $session_id,
        'started_at'      => date('c'),
        'container_type'  => $container_type,
        'target_amount_ml'=> $target_ml,
    ], 'Dispensing session started.', 201);
}

function handle_dispensing_complete(array $auth): void
{
    rate_limit_check($auth['device_id'], 'dispensing_complete', 30, 60);

    $body = require_json_body();
    validate_device_identity($auth, $body);

    $device_id = $auth['device_id'];
    $errors = [];

    // session_id is required
    if (empty($body['session_id'])) {
        $errors['session_id'] = 'session_id is required.';
    } else {
        $session_id = (int)$body['session_id'];
    }

    if (!empty($errors)) {
        api_error('Validation failed', 422, $errors);
    }

    // Fetch session and verify ownership
    $session = db_fetch("SELECT * FROM dispensing_sessions WHERE id = ?", [$session_id]);
    if (!$session) {
        api_error('Dispensing session not found.', 404);
    }
    if ((int)$session['device_id'] !== $device_id) {
        api_error('This session belongs to another device.', 403);
    }
    if ($session['status'] !== 'started') {
        api_error('This session is not active (status: ' . $session['status'] . ').', 409);
    }

    // Validate inputs
    if (isset($body['amount_dispensed_ml']) && $body['amount_dispensed_ml'] !== null) {
        if (!is_numeric($body['amount_dispensed_ml']) || (int)$body['amount_dispensed_ml'] < 0) {
            $errors['amount_dispensed_ml'] = 'Must be a non-negative number.';
        } else {
            $body['amount_dispensed_ml'] = (int)$body['amount_dispensed_ml'];
        }
    }
    if (isset($body['measured_amount_ml']) && $body['measured_amount_ml'] !== null) {
        if (!is_numeric($body['measured_amount_ml']) || (int)$body['measured_amount_ml'] < 0) {
            $errors['measured_amount_ml'] = 'Must be a non-negative number.';
        } else {
            $body['measured_amount_ml'] = (int)$body['measured_amount_ml'];
        }
    }

    $valid_stop_reasons = ['target_reached','container_removed','timeout','low_water','manual_stop','completed','error','command_expired'];
    if (isset($body['stop_reason']) && !in_array($body['stop_reason'], $valid_stop_reasons)) {
        $errors['stop_reason'] = 'Invalid stop_reason. Valid: ' . implode(', ', $valid_stop_reasons);
    }

    if (!empty($errors)) {
        api_error('Validation failed', 422, $errors);
    }

    // Calculate duration
    $started = strtotime($session['started_at']);
    $ended = time();
    $duration = $ended - $started;

    // Determine final status
    $stop_reason = $body['stop_reason'] ?? 'completed';
    $final_status = 'completed';
    if (in_array($stop_reason, ['container_removed','timeout','low_water','manual_stop','command_expired'])) {
        $final_status = 'interrupted';
    } elseif ($stop_reason === 'error') {
        $final_status = 'failed';
    }

    // Measured amount (load cell) — preferred over legacy amount field
    $measured = $body['measured_amount_ml']
        ?? $body['amount_dispensed_ml']
        ?? $session['measured_amount_ml'];

    // Use transaction for consistency
    db()->beginTransaction();
    try {
        $update_data = [
            'ended_at'            => date('Y-m-d H:i:s'),
            'duration_seconds'    => $duration,
            'water_level_after'   => null,
            'amount_dispensed_ml' => $measured ?? $session['amount_dispensed_ml'],
            'measured_amount_ml'  => $measured,
            'stop_reason'         => $stop_reason,
            'status'              => $final_status,
        ];
        db_update('dispensing_sessions', $update_data, 'id = ?', [$session_id]);

        // Log the event
        $log_type = $final_status === 'completed' ? 'dispensing_completed' : 'dispensing_interrupted';
        $log_level = $final_status === 'failed' ? 'error' : 'info';
        $measured_str = $measured !== null ? ", measured {$measured}mL" : '';
        log_event($device_id, null, $log_type, $log_level,
            "Session #{$session_id} {$final_status} after {$duration}s. Reason: {$stop_reason}{$measured_str}");

        // One alert per non-clean end of a session (a session can only end once).
        if ($final_status !== 'completed') {
            $is_timeout = $stop_reason === 'timeout';
            create_alert($device_id,
                $is_timeout ? 'dispensing_timeout' : 'dispensing_interrupted',
                $final_status === 'failed' ? 'critical' : 'warning',
                $is_timeout ? 'Dispensing timed out' : 'Dispensing interrupted',
                "Session #{$session_id} ended after {$duration}s — reason: " .
                str_replace('_', ' ', $stop_reason) . '.');
        }

        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        api_error('Failed to complete session.', 500);
    }

    api_success([
        'session_id'          => $session_id,
        'status'              => $final_status,
        'duration_seconds'    => $duration,
        'measured_amount_ml'  => $measured ?? null,
        'ended_at'            => date('c'),
    ], 'Dispensing session ' . $final_status . '.');
}
