<?php
/**
 * WaterBonia API - Device Heartbeat Endpoint
 *
 * POST /api/device/heartbeat
 *
 * Allows ESP32 to report alive status and update device info.
 */

function handle_heartbeat(array $auth): void
{
    rate_limit_check($auth['device_id'], 'heartbeat', 30, 60);

    $body = require_json_body();
    validate_device_identity($auth, $body);

    $device_id = $auth['device_id'];

    // Update device fields if supplied
    $update = ['last_seen_at' => date('Y-m-d H:i:s')];

    if (isset($body['firmware_version']) && is_string($body['firmware_version'])) {
        $update['firmware_version'] = mb_substr($body['firmware_version'], 0, 30);
    }
    if (isset($body['ip_address']) && is_string($body['ip_address'])) {
        $update['ip_address'] = mb_substr($body['ip_address'], 0, 45);
    }
    if (isset($body['wifi_ssid']) && is_string($body['wifi_ssid'])) {
        $update['wifi_ssid'] = mb_substr($body['wifi_ssid'], 0, 100);
    }

    // If device was offline or in maintenance, bring it online
    $current = db_fetch("SELECT status FROM devices WHERE id = ?", [$device_id]);
    if ($current && in_array($current['status'], ['offline', 'maintenance'])) {
        $update['status'] = 'online';
    }

    db_update('devices', $update, 'id = ?', [$device_id]);

    // Log heartbeat sparingly — only log if device was previously offline
    if ($current && $current['status'] === 'online') {
        // Device was already online — just log occasionally (every ~5 minutes)
        $last_log = db_fetch(
            "SELECT created_at FROM activity_logs WHERE device_id = ? AND event_type = 'device_heartbeat' ORDER BY created_at DESC LIMIT 1",
            [$device_id]
        );
        if (!$last_log || (time() - strtotime($last_log['created_at'])) > 300) {
            log_event($device_id, null, 'device_heartbeat', 'info', "Heartbeat from {$auth['device_code']}");
        }
    } else {
        log_event($device_id, null, 'device_connected', 'info', "Device {$auth['device_code']} came online.");
    }

    api_success([
        'device_id' => $device_id,
        'status'    => $update['status'] ?? $current['status'],
        'timestamp' => date('c'),
    ], 'Heartbeat accepted.');
}
