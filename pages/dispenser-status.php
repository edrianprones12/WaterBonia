<?php
/** Public kiosk state and CSRF-protected, session-scoped selections. */
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function portal_json(array $response, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function portal_session_view(?array $session): ?array
{
    if (!$session) return null;
    $measured = $session['measured_amount_ml'] ?? $session['amount_dispensed_ml'] ?? null;
    return [
        'configured_duration_ms' => isset($session['configured_duration_ms']) ? (int)$session['configured_duration_ms'] : null,
        'pump_elapsed_ms' => isset($session['pump_elapsed_ms']) ? (int)$session['pump_elapsed_ms'] : null,
        'id' => (int)$session['id'], 'status' => $session['status'], 'container_type' => $session['container_type'],
        'target_amount_ml' => $session['target_amount_ml'] !== null ? (int)$session['target_amount_ml'] : null,
        'measured_amount_ml' => $measured !== null ? (int)$measured : null,
        'threshold_ml' => array_key_exists('threshold_ml', $session) ? $session['threshold_ml'] : portal_session_threshold_ml((int)$session['device_id'], (int)$session['id']),
        'stop_reason' => $session['stop_reason'] ?? null, 'started_at' => $session['started_at'] ?? null, 'ended_at' => $session['ended_at'] ?? null,
    ];
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor_id = null;
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST;
        $token = $input['csrf_token'] ?? '';
        $expected_token = $_SESSION['csrf_token'] ?? '';
        if (!is_string($token) || $token === '' || !is_string($expected_token) || $expected_token === '' || !hash_equals($expected_token, $token)) {
            portal_json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }
        $action = $input['action'] ?? '';
        if (!is_string($action)) portal_json(['success' => false, 'error' => 'Unknown action.'], 400);
        if ($action === 'select_threshold') portal_json(['success'=>false,'error'=>'Real hardware uses configured duration presets.'],422);
        if ($action === 'select_container') {
            $type=$input['container_type']??null;
            if (!is_string($type) || !in_array($type,CONTAINER_TYPES,true)) portal_json(['success'=>false,'error'=>'Choose Cup or Tumbler.'],422);
            $_SESSION['portal_container_type']=$type;
            portal_json(['success'=>true,'container_type'=>$type]);
        }
        if ($action === 'request_dispense') {
            $type = $input['container_type'] ?? '';
            if (!is_string($type)) portal_json(['success' => false, 'error' => 'Choose a valid container.'], 422);
            $device = get_active_device();
            $request_key = $input['request_id'] ?? null;
            if (!is_string($request_key)) portal_json(['success'=>false,'error'=>'Unique request identifier required.'],422);
            [$ok, $message, $id] = request_dispense((int)($device['id'] ?? 0), $type, $actor_id, null, $request_key);
            if ($ok) $_SESSION['portal_command_id'] = $id;
            portal_json(['success' => $ok, 'message' => $message, 'command_id' => $id], $ok ? 202 : 409);
        }
        if ($action === 'restart') {
            unset($_SESSION['portal_container_type']);
            portal_json(['success' => true]);
        }
        portal_json(['success' => false, 'error' => 'Unknown action.'], 400);
    }
    if ($method !== 'GET') portal_json(['success' => false, 'error' => 'Method not allowed.'], 405);

    // Status comes only from authenticated device reports.
    $device = get_active_device();
    $live = get_live_dispenser_state($device);
    $request_id = portal_integer($_SESSION['portal_command_id'] ?? null);
    $request = $device && $request_id ? db_fetch('SELECT id,status,container_type,target_amount_ml,duration_ms,session_id,requested_at,expires_at FROM device_commands WHERE id = ? AND device_id = ?', [$request_id, $device['id']]) : null;
    if ($request && in_array($request['status'], ['pending','claimed'], true) && empty($request['session_id']) && strtotime($request['expires_at']) <= time()) {
        $expired = db_update('device_commands', ['status' => 'expired'], "id = ? AND device_id = ? AND status IN ('pending','claimed') AND session_id IS NULL AND expires_at <= NOW()", [$request_id, $device['id']]);
        if ($expired) log_event((int)$device['id'], null, 'command_expired', 'warning', 'A session-owned dispensing request expired before the device claimed it.');
        $request = db_fetch('SELECT id,status,container_type,target_amount_ml,duration_ms,session_id,requested_at,expires_at FROM device_commands WHERE id = ? AND device_id = ?', [$request_id, $device['id']]);
    }
    $last_session = $device ? db_fetch("SELECT * FROM dispensing_sessions WHERE device_id = ? AND status IN ('completed','interrupted','failed') ORDER BY ended_at DESC,id DESC LIMIT 1", [$device['id']]) : null;
    if ($device && !empty($request['session_id'])) {
        $last_session = db_fetch("SELECT * FROM dispensing_sessions WHERE id = ? AND device_id = ? AND status IN ('completed','interrupted','failed')", [$request['session_id'], $device['id']]);
    }
    $pending = $live['pending_command'];
    portal_json([
        'success' => true,
        'activities' => $device ? portal_activity_records((int)$device['id']) : [],
        'state' => [
            'protocol_version' => 2, 'presets' => timer_presets(),
            'can_dispense' => true,
            'sensor_status' => $live['telemetry_fresh'] ? ($live['_reading']['sensor_status'] ?? 'unavailable') : 'unavailable',
            'pump_elapsed_ms' => $live['telemetry_valid'] ? ($live['_reading']['pump_elapsed_ms'] ?? null) : null,
            'active_command_id' => $live['telemetry_valid'] ? ($live['_reading']['active_command_id'] ?? null) : null,
            'device_online' => $live['device_online'],
            'device' => ['registered' => (bool)$device, 'name' => $device['device_name'] ?? null,
                'status' => $device['status'] ?? null, 'last_seen_at' => $device['last_seen_at'] ?? null],
            'availability' => dispenser_availability($device, $live),
            'led' => dispenser_led($device, $live),
            'state_label' => $live['state_label'], 'status_text' => $live['status_text'], 'step' => $live['step'],
            'container_detected' => $live['container_detected'], 'pump_status' => $live['pump_status'],
            'telemetry_fresh' => $live['telemetry_fresh'], 'telemetry_valid' => $live['telemetry_valid'],
            'safety_blocked' => $live['safety_blocked'], 'safety_message' => $live['safety_message'],
            'has_session' => (bool)$live['active_session'], 'session' => portal_session_view($live['active_session']),
            'has_pending_command' => (bool)$pending,
            'pending_command' => $pending ? ['id' => (int)$pending['id'], 'status' => $pending['status'],
                'duration_ms' => (int)$pending['duration_ms'], 'container_type' => $pending['container_type'], 'target_amount_ml' => $pending['target_amount_ml'] !== null ? (int)$pending['target_amount_ml'] : null,
                'threshold_ml' => $pending['threshold_ml'],
                'requested_at' => $pending['requested_at'], 'expires_at' => $pending['expires_at']] : null,
            'request_command' => $request ? ['id' => (int)$request['id'], 'status' => $request['status'],
                'duration_ms' => (int)$request['duration_ms'], 'container_type' => $request['container_type'], 'target_amount_ml' => $request['target_amount_ml'] !== null ? (int)$request['target_amount_ml'] : null,
                'threshold_ml' => portal_command_threshold_ml((int)$device['id'], (int)$request['id']),
                'session_id' => $request['session_id'] !== null ? (int)$request['session_id'] : null,
                'requested_at' => $request['requested_at'], 'expires_at' => $request['expires_at'],
                'message' => match ($request['status']) {
                    'rejected' => 'A safety check blocked this dispensing request.',
                    'expired' => 'The device did not accept the request before it expired.',
                    'cancelled' => 'This dispensing request was cancelled.',
                    default => null,
                }] : null,
            'last_session' => portal_session_view($last_session),
            'recorded_at' => $live['_reading']['recorded_at'] ?? null,
            'targets' => ['cup' => container_target_ml('cup'), 'tumbler' => container_target_ml('tumbler')],
            'thresholds' => portal_threshold_config(), 'selected_thresholds' => portal_selected_thresholds(),
        ],
    ]);
} catch (Throwable $e) {
    portal_json(['success' => false, 'error' => 'Dispenser state is temporarily unavailable. Dispensing is blocked.'], 503);
}
