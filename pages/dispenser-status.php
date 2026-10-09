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
        'id' => (int)$session['id'], 'status' => $session['status'], 'container_type' => $session['container_type'],
        'target_amount_ml' => $session['target_amount_ml'] !== null ? (int)$session['target_amount_ml'] : null,
        'measured_amount_ml' => $measured !== null ? (int)$measured : null,
        'threshold_ml' => array_key_exists('threshold_ml', $session) ? $session['threshold_ml'] : portal_session_threshold_ml((int)$session['device_id'], (int)$session['id']),
        'stop_reason' => $session['stop_reason'] ?? null, 'started_at' => $session['started_at'] ?? null, 'ended_at' => $session['ended_at'] ?? null,
    ];
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actor_id = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
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
        if ($action === 'select_threshold' || $action === 'select_container') {
            $type = $input['container_type'] ?? '';
            if (!is_string($type) || !in_array($type, CONTAINER_TYPES, true)) {
                portal_json(['success' => false, 'error' => 'Choose a valid container.'], 422);
            }
            if ($action === 'select_threshold') {
                [$valid, $message, $threshold] = validate_water_threshold($type, $input['threshold_ml'] ?? null);
                if (!$valid) portal_json(['success' => false, 'error' => $message, 'message' => $message], 422);
                $changed = ($_SESSION['portal_fill_volumes'][$type] ?? null) !== $threshold;
                $_SESSION['portal_fill_volumes'][$type] = $threshold;
                if (!WATERBONIA_DEMO_MODE && $changed) {
                    $device = get_active_device();
                    log_event($device ? (int)$device['id'] : null, $actor_id, 'fill_volume_selected', 'info',
                        "Fill amount selected for {$type}: {$threshold} mL.",
                        ['container_type' => $type, 'target_amount_ml' => $threshold, 'fill_amount_ml' => $threshold, 'threshold_ml' => $threshold, 'threshold_semantics' => 'fill_volume_ml']);
                }
                portal_json(['success' => true, 'message' => $message, 'container_type' => $type,
                    'threshold_ml' => $threshold, 'target_amount_ml' => $threshold, 'threshold_semantics' => 'fill_volume_ml', 'thresholds' => portal_threshold_config(), 'selected_thresholds' => portal_selected_thresholds()]);
            }
            $_SESSION['portal_container_type'] = $type;
            if (!WATERBONIA_DEMO_MODE) {
                $device = get_active_device();
                log_event($device ? (int)$device['id'] : null, $actor_id, 'container_selected', 'info',
                    'Container selected: ' . container_label($type) . '.',
                    ['container_type' => $type, 'target_amount_ml' => portal_selected_thresholds()[$type], 'threshold_semantics' => 'fill_volume_ml']);
            }
            portal_json(['success' => true, 'message' => 'Container selected.', 'container_type' => $type]);
        }
        if ($action === 'request_dispense') {
            if (WATERBONIA_DEMO_MODE) portal_json(['success' => false, 'error' => 'Demo dispensing runs only in the simulated presentation.'], 409);
            $type = $input['container_type'] ?? '';
            if (!is_string($type)) portal_json(['success' => false, 'error' => 'Choose a valid container.'], 422);
            [$valid, $message, $threshold] = validate_water_threshold($type, $input['threshold_ml'] ?? null);
            if (!$valid) portal_json(['success' => false, 'error' => $message, 'message' => $message], 422);
            $_SESSION['portal_container_type'] = $type;
            $_SESSION['portal_fill_volumes'][$type] = $threshold;
            $device = get_active_device();
            [$ok, $message, $id] = request_dispense((int)($device['id'] ?? 0), $type, $actor_id, $threshold);
            if ($ok) $_SESSION['portal_command_id'] = $id;
            portal_json(['success' => $ok, 'message' => $message, 'command_id' => $id], $ok ? 200 : 409);
        }
        if ($action === 'restart') {
            unset($_SESSION['portal_container_type']);
            portal_json(['success' => true]);
        }
        portal_json(['success' => false, 'error' => 'Unknown action.'], 400);
    }
    if ($method !== 'GET') portal_json(['success' => false, 'error' => 'Method not allowed.'], 405);

    // Demo polling never refreshes real offline state, writes alerts, or loads history.
    $device = WATERBONIA_DEMO_MODE ? null : get_active_device();
    $live = get_live_dispenser_state($device);
    $request_id = portal_integer($_SESSION['portal_command_id'] ?? null);
    $request = $device && $request_id ? db_fetch('SELECT id,status,container_type,target_amount_ml,session_id,requested_at,expires_at FROM device_commands WHERE id = ? AND device_id = ?', [$request_id, $device['id']]) : null;
    if ($request && $request['status'] === 'pending' && strtotime($request['expires_at']) <= time()) {
        $expired = db_update('device_commands', ['status' => 'expired'], "id = ? AND device_id = ? AND status = 'pending' AND expires_at <= NOW()", [$request_id, $device['id']]);
        if ($expired) log_event((int)$device['id'], null, 'command_expired', 'warning', 'A session-owned dispensing request expired before the device claimed it.');
        $request = db_fetch('SELECT id,status,container_type,target_amount_ml,session_id,requested_at,expires_at FROM device_commands WHERE id = ? AND device_id = ?', [$request_id, $device['id']]);
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
            'device_online' => $live['device_online'],
            'device' => ['registered' => (bool)$device, 'name' => $device['device_name'] ?? null,
                'status' => $device['status'] ?? null, 'last_seen_at' => $device['last_seen_at'] ?? null],
            'availability' => WATERBONIA_DEMO_MODE ? demo_availability() : dispenser_availability($device, $live),
            'led' => WATERBONIA_DEMO_MODE ? demo_led() : dispenser_led($device, $live),
            'state_label' => $live['state_label'], 'status_text' => $live['status_text'], 'step' => $live['step'],
            'container_detected' => $live['container_detected'], 'pump_status' => $live['pump_status'],
            'telemetry_fresh' => $live['telemetry_fresh'], 'telemetry_valid' => $live['telemetry_valid'],
            'safety_blocked' => $live['safety_blocked'], 'safety_message' => $live['safety_message'],
            'has_session' => (bool)$live['active_session'], 'session' => portal_session_view($live['active_session']),
            'has_pending_command' => (bool)$pending,
            'pending_command' => $pending ? ['id' => (int)$pending['id'], 'status' => $pending['status'],
                'container_type' => $pending['container_type'], 'target_amount_ml' => $pending['target_amount_ml'] !== null ? (int)$pending['target_amount_ml'] : null,
                'threshold_ml' => $pending['threshold_ml'],
                'requested_at' => $pending['requested_at'], 'expires_at' => $pending['expires_at']] : null,
            'request_command' => $request ? ['id' => (int)$request['id'], 'status' => $request['status'],
                'container_type' => $request['container_type'], 'target_amount_ml' => $request['target_amount_ml'] !== null ? (int)$request['target_amount_ml'] : null,
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
