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
    foreach (['cup_detected', 'hand_detected'] as $field) {
        if (array_key_exists($body[$field] ?? null, $body)) {
            // already set from default
        }
    }
    if (isset($body['cup_detected'])) {
        $err = validate_bool($body['cup_detected'], 'cup_detected');
        if ($err) $errors['cup_detected'] = $err;
        else $body['cup_detected'] = (bool)$body['cup_detected'];
    }
    if (isset($body['hand_detected'])) {
        $err = validate_bool($body['hand_detected'], 'hand_detected');
        if ($err) $errors['hand_detected'] = $err;
        else $body['hand_detected'] = (bool)$body['hand_detected'];
    }

    // Validate water_level_before
    if (isset($body['water_level_before']) && $body['water_level_before'] !== null) {
        $err = validate_range($body['water_level_before'], 0, 100, 'water_level_before');
        if ($err) $errors['water_level_before'] = $err;
        else $body['water_level_before'] = (int)$body['water_level_before'];
    }

    if (!empty($errors)) {
        api_error('Validation failed', 422, $errors);
    }

    // Check if there's already an active session for this device
    $active = db_fetch(
        "SELECT id FROM dispensing_sessions WHERE device_id = ? AND status = 'started' LIMIT 1",
        [$device_id]
    );
    if ($active) {
        api_error('An active dispensing session already exists. Complete or interrupt it first.', 409);
    }

    $session_id = db_insert('dispensing_sessions', [
        'device_id'          => $device_id,
        'started_at'         => date('Y-m-d H:i:s'),
        'cup_detected'       => $body['cup_detected'] ?? false ? 1 : 0,
        'hand_detected'      => $body['hand_detected'] ?? false ? 1 : 0,
        'water_level_before' => $body['water_level_before'] ?? null,
        'status'             => 'started',
    ]);

    log_event($device_id, null, 'dispensing_started', 'info',
        "Dispensing session #{$session_id} started.");

    api_success([
        'session_id' => $session_id,
        'started_at' => date('c'),
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
    if ($session['device_id'] !== $device_id) {
        api_error('This session belongs to another device.', 403);
    }
    if ($session['status'] !== 'started') {
        api_error('This session is not active (status: ' . $session['status'] . ').', 409);
    }

    // Validate inputs
    if (isset($body['water_level_after']) && $body['water_level_after'] !== null) {
        $err = validate_range($body['water_level_after'], 0, 100, 'water_level_after');
        if ($err) $errors['water_level_after'] = $err;
        else $body['water_level_after'] = (int)$body['water_level_after'];
    }
    if (isset($body['amount_dispensed_ml']) && $body['amount_dispensed_ml'] !== null) {
        if (!is_numeric($body['amount_dispensed_ml']) || (int)$body['amount_dispensed_ml'] < 0) {
            $errors['amount_dispensed_ml'] = 'Must be a non-negative number.';
        } else {
            $body['amount_dispensed_ml'] = (int)$body['amount_dispensed_ml'];
        }
    }

    $valid_stop_reasons = ['cup_removed','hand_removed','timeout','low_water','manual_stop','completed','error'];
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
    if (in_array($stop_reason, ['cup_removed','hand_removed','timeout','low_water','manual_stop'])) {
        $final_status = 'interrupted';
    } elseif ($stop_reason === 'error') {
        $final_status = 'failed';
    }

    // Use transaction for consistency
    db()->beginTransaction();
    try {
        $update_data = [
            'ended_at'            => date('Y-m-d H:i:s'),
            'duration_seconds'    => $duration,
            'water_level_after'   => $body['water_level_after'] ?? $session['water_level_after'],
            'amount_dispensed_ml' => $body['amount_dispensed_ml'] ?? $session['amount_dispensed_ml'],
            'stop_reason'         => $stop_reason,
            'status'              => $final_status,
        ];
        db_update('dispensing_sessions', $update_data, 'id = ?', [$session_id]);

        // Log the event
        $log_type = $final_status === 'completed' ? 'dispensing_completed' : 'dispensing_interrupted';
        $log_level = $final_status === 'failed' ? 'error' : 'info';
        log_event($device_id, null, $log_type, $log_level,
            "Session #{$session_id} {$final_status} after {$duration}s. Reason: {$stop_reason}");

        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        api_error('Failed to complete session.', 500);
    }

    api_success([
        'session_id'     => $session_id,
        'status'         => $final_status,
        'duration_seconds' => $duration,
        'ended_at'       => date('c'),
    ], 'Dispensing session ' . $final_status . '.');
}
