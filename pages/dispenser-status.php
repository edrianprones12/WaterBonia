<?php
/**
 * WaterBonia - Dispenser Status (AJAX)
 *
 * GET  /pages/dispenser-status.php
 *   Returns live dispenser state for the Execution Portal polling loop.
 *   Public (kiosk) access: the Execution Portal itself is public and this
 *   endpoint only returns dispenser state — never admin data.
 *
 * POST /pages/dispenser-status.php  (CSRF)
 *   { action: "request_dispense", container_type: "cup"|"tumbler" }
 *   Server validates and queues a command for the ESP32.
 */

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$actor_id = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$device = get_active_device();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $token = (string)($input['csrf_token'] ?? ($_POST['csrf_token'] ?? ''));
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    $action = $input['action'] ?? '';

    if ($action === 'request_dispense') {
        $ctype = (string)($input['container_type'] ?? '');
        [$ok, $msg, $command_id] = request_dispense((int)($device['id'] ?? 0), $ctype, $actor_id);
        if ($ok) {
            log_event($device ? $device['id'] : null, $actor_id, 'dispensing_requested', 'info',
                "Dispensing requested: {$ctype}.");
        }
        echo json_encode(['success' => $ok, 'message' => $msg, 'command_id' => $command_id]);
        exit;
    }

    if ($action === 'restart') {
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

// GET — live state
$live = get_live_dispenser_state($device);
$reading = $device ? db_fetch(
    "SELECT * FROM sensor_readings WHERE device_id = ? ORDER BY recorded_at DESC LIMIT 1",
    [$device['id']]
) : null;
$session = $live['active_session'];

$last_session = $device
    ? db_fetch("SELECT * FROM dispensing_sessions
                 WHERE device_id = ? AND status IN ('completed','interrupted','failed')
                 ORDER BY ended_at DESC LIMIT 1", [$device['id']])
    : null;

echo json_encode([
    'success' => true,
    'state' => [
        'device_online'      => $live['device_online'],
        'device'             => [
            'registered'  => (bool)$device,
            'name'        => $device['device_name'] ?? null,
            'status'      => $device['status'] ?? null,
            'last_seen_at'=> $device['last_seen_at'] ?? null,
        ],
        'availability'       => dispenser_availability($device, $live),
        'led'                => dispenser_led($device, $live),
        'low_water_real'     => $live['water_level'] !== null
            && $live['water_level'] <= (int)get_setting('low_water_threshold', '20'),
        'state_label'        => $live['state_label'],
        'status_text'        => $live['status_text'],
        'step'               => $live['step'],
        'container_detected' => $live['container_detected'],
        'pump_status'        => $live['pump_status'],
        'water_level'        => $live['water_level'],
        'has_session'        => (bool)$session,
        'session'            => $session ? [
            'id'                 => (int)$session['id'],
            'container_type'     => $session['container_type'],
            'target_amount_ml'   => $session['target_amount_ml'] !== null ? (int)$session['target_amount_ml'] : null,
            'measured_amount_ml' => ($session['measured_amount_ml'] ?? null) !== null ? (int)$session['measured_amount_ml'] : 0,
        ] : null,
        'has_pending_command' => (bool)$live['pending_command'],
        'pending_command'     => $live['pending_command'] ? [
            'id'               => (int)$live['pending_command']['id'],
            'container_type'   => $live['pending_command']['container_type'],
            'target_amount_ml' => $live['pending_command']['target_amount_ml'] !== null ? (int)$live['pending_command']['target_amount_ml'] : null,
        ] : null,
        'last_session'       => $last_session ? [
            'status'             => $last_session['status'],
            'container_type'     => $last_session['container_type'],
            'target_amount_ml'   => $last_session['target_amount_ml'] !== null ? (int)$last_session['target_amount_ml'] : null,
            'measured_amount_ml' => ($last_session['measured_amount_ml'] ?? null) !== null
                ? (int)$last_session['measured_amount_ml']
                : (($last_session['amount_dispensed_ml'] ?? null) !== null ? (int)$last_session['amount_dispensed_ml'] : null),
            'stop_reason'        => $last_session['stop_reason'],
            'ended_at'           => $last_session['ended_at'],
        ] : null,
        'recorded_at' => $reading['recorded_at'] ?? null,
        'targets' => [
            'cup'     => container_target_ml('cup'),
            'tumbler' => container_target_ml('tumbler'),
        ],
    ],
]);
