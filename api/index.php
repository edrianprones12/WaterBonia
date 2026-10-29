<?php
/**
 * WaterBonia - API Router
 *
 * Central entry point for all /api/* requests.
 * Routes to appropriate handlers based on URL path.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_exception_handler(function (Throwable $error): void {
    http_response_code(503); echo json_encode(['success'=>false,'error'=>'Device service unavailable.']);
});

// Handle CORS for future use
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/heartbeat.php';
require_once __DIR__ . '/sensor_reading.php';
require_once __DIR__ . '/dispensing.php';
require_once __DIR__ . '/device_command.php';

// ── Parse route ─────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Strip the project base prefix (e.g. "/WaterBonia") so the API works
// whether it is served from /WaterBonia/api/… or /api/…
$project_prefix = '/' . basename(dirname(__DIR__));
if ($project_prefix !== '/' && strpos($uri, $project_prefix) === 0) {
    $uri = substr($uri, strlen($project_prefix));
}

$segments = array_values(array_filter(explode('/', $uri), fn($s) => $s !== ''));

// Remove 'api' prefix if present
if (isset($segments[0]) && $segments[0] === 'api') {
    array_shift($segments);
}

// Remove the router filename if rewritten (e.g. /api/index.php/device/…)
if (isset($segments[0]) && str_ends_with($segments[0], '.php')) {
    array_shift($segments);
}

$resource = $segments[0] ?? '';
$action   = $segments[1] ?? '';

// ── Public endpoints (no auth required) ─────────────────
if ($resource === 'status' && $action === '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') api_error('Method not allowed.',405);
    api_success([
        'system'    => 'WaterBonia',
        'version'   => 'timer-2.0',
        'api_version' => '2.0.0',
        'timestamp' => date('c'),
        'endpoints' => [
            'status'            => 'GET  /api/status',
            'heartbeat'         => 'POST /api/device/heartbeat',
            'sensor_reading'    => 'POST /api/device/sensor-reading',
            'dispensing_start'  => 'POST /api/device/dispensing/start',
            'dispensing_complete'=> 'POST /api/device/dispensing/complete',
            'poll_command'      => 'POST /api/device/poll-command',
            'command_result'    => 'POST /api/device/command-result',
        ],
    ]);
}

// ── Device endpoints (require Bearer token) ─────────────
if ($resource === 'device') {
    $auth = authenticate_device();

    $route = $action;
    if ($route === '') {
        // Accept full path like /api/device/heartbeat
        $route = $segments[1] ?? '';
    }

    // Handle nested paths: /api/device/dispensing/start
    if ($route === 'dispensing' && isset($segments[2])) {
        $route = 'dispensing_' . $segments[2];
    }

    // Normalize dashes to underscores for matching
    $route_key = str_replace('-', '_', $route);

    switch ($route_key) {
        case 'heartbeat':
            handle_heartbeat($auth);
            break;
        case 'sensor_reading':
            handle_sensor_reading($auth);
            break;
        case 'dispensing_start':
            handle_dispensing_start($auth);
            break;
        case 'dispensing_complete':
            handle_dispensing_complete($auth);
            break;
        case 'poll_command':
            handle_poll_command($auth);
            break;
        case 'command_result':
            handle_command_result($auth);
            break;
        default:
            api_error('Unknown device endpoint: ' . $route, 404);
            break;
    }
}

// ── Unknown routes ──────────────────────────────────────
if ($resource === '') {
    api_error('No API endpoint specified.', 404);
}

api_error('Endpoint not found: /' . $resource . ($action ? '/' . $action : ''), 404);
