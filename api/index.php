<?php
/**
 * WaterBonia - API Router
 *
 * Central entry point for all /api/* requests.
 * Routes to appropriate handlers based on URL path.
 */

header('Content-Type: application/json; charset=utf-8');

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

// ── Parse route ─────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$segments = array_values(array_filter(explode('/', $uri)));

// Remove 'api' prefix if present
if (isset($segments[0]) && $segments[0] === 'api') {
    array_shift($segments);
}

$resource = $segments[0] ?? '';
$action   = $segments[1] ?? '';

// ── Public endpoints (no auth required) ─────────────────
if ($resource === 'status' && $action === '') {
    api_success([
        'system'    => 'WaterBonia',
        'version'   => '0.1.0-dev',
        'api_version' => '1.0.0',
        'timestamp' => date('c'),
        'endpoints' => [
            'status'            => 'GET  /api/status',
            'heartbeat'         => 'POST /api/device/heartbeat',
            'sensor_reading'    => 'POST /api/device/sensor-reading',
            'dispensing_start'  => 'POST /api/device/dispensing/start',
            'dispensing_complete'=> 'POST /api/device/dispensing/complete',
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

    switch ($route) {
        case 'heartbeat':
            handle_heartbeat($auth);
            break;
        case 'sensor-reading':
        case 'sensor_reading':
            handle_sensor_reading($auth);
            break;
        case 'dispensing_start':
            handle_dispensing_start($auth);
            break;
        case 'dispensing_complete':
            handle_dispensing_complete($auth);
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
