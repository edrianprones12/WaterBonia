<?php
/**
 * WaterBonia API - Sensor Reading Endpoint
 *
 * POST /api/device/sensor-reading
 *
 * Receives telemetry from ESP32 and stores it.
 * Includes low-water alert logic.
 */

function handle_sensor_reading(array $auth): void
{
    rate_limit_check($auth['device_id'], 'sensor_reading', 60, 60);

    $body = require_json_body();
    validate_device_identity($auth, $body);

    $device_id = $auth['device_id'];
    $errors = [];

    // ── Validate water_level_percent ──
    if (array_key_exists('water_level_percent', $body)) {
        $wl = $body['water_level_percent'];
        if ($wl !== null && $wl !== '') {
            $err = validate_range($wl, 0, 100, 'water_level_percent');
            if ($err) $errors['water_level_percent'] = $err;
            else $body['water_level_percent'] = (int)$wl;
        } else {
            $body['water_level_percent'] = null;
        }
    }

    // ── Validate battery_percent ──
    if (array_key_exists('battery_percent', $body)) {
        $bp = $body['battery_percent'];
        if ($bp !== null && $bp !== '') {
            $err = validate_range($bp, 0, 100, 'battery_percent');
            if ($err) $errors['battery_percent'] = $err;
            else $body['battery_percent'] = (int)$bp;
        } else {
            $body['battery_percent'] = null;
        }
    }

    // ── Validate booleans ──
    foreach (['cup_detected', 'hand_detected', 'pump_status'] as $field) {
        if (array_key_exists($field, $body)) {
            $err = validate_bool($body[$field], $field);
            if ($err) $errors[$field] = $err;
            else $body[$field] = (bool)$body[$field];
        } else {
            $body[$field] = false;
        }
    }

    if (!empty($errors)) {
        api_error('Validation failed', 422, $errors);
    }

    // ── Store the reading ──
    $reading_id = db_insert('sensor_readings', [
        'device_id'           => $device_id,
        'water_level_percent' => $body['water_level_percent'] ?? null,
        'cup_detected'        => $body['cup_detected'] ? 1 : 0,
        'hand_detected'       => $body['hand_detected'] ? 1 : 0,
        'pump_status'         => $body['pump_status'] ? 1 : 0,
        'battery_percent'     => $body['battery_percent'] ?? null,
        'recorded_at'         => date('Y-m-d H:i:s'),
    ]);

    // ── Update device last_seen_at ──
    $current_device = db_fetch("SELECT status FROM devices WHERE id = ?", [$device_id]);
    $device_update = ['last_seen_at' => date('Y-m-d H:i:s')];
    if ($current_device && in_array($current_device['status'], ['offline', 'maintenance'])) {
        $device_update['status'] = 'online';
    }
    db_update('devices', $device_update, 'id = ?', [$device_id]);

    // ── Low-water alert logic ──
    $water_level = $body['water_level_percent'] ?? null;
    if ($water_level !== null) {
        $threshold = (int)get_setting('low_water_threshold', '20');
        check_low_water_alert($device_id, $water_level, $threshold);
    }

    // ── Activity log (sparingly) ──
    $last_log = db_fetch(
        "SELECT created_at FROM activity_logs WHERE device_id = ? AND event_type = 'sensor_reading_received' ORDER BY created_at DESC LIMIT 1",
        [$device_id]
    );
    if (!$last_log || (time() - strtotime($last_log['created_at'])) > 300) {
        log_event($device_id, null, 'sensor_reading_received', 'info',
            "Sensor data received: water={$body['water_level_percent']}%");
    }

    api_success([
        'reading_id' => $reading_id,
        'recorded_at' => date('c'),
    ], 'Sensor reading accepted.');
}

/**
 * Check for low-water condition and manage alerts without flooding.
 */
function check_low_water_alert(int $device_id, int $water_level, int $threshold): void
{
    $device = db_fetch("SELECT device_name FROM devices WHERE id = ?", [$device_id]);
    $device_name = $device ? $device['device_name'] : "Device #{$device_id}";

    if ($water_level <= $threshold) {
        // Check if an active low_water alert already exists for this device
        $existing = db_fetch(
            "SELECT id FROM alerts WHERE device_id = ? AND alert_type = 'low_water' AND status = 'active' LIMIT 1",
            [$device_id]
        );

        if (!$existing) {
            $severity = $water_level <= 10 ? 'critical' : 'warning';
            create_alert(
                $device_id,
                'low_water',
                $severity,
                'Low Water Level',
                "Water level at {$water_level}% on {$device_name} (threshold: {$threshold}%)."
            );
            log_event($device_id, null, 'low_water', 'warning',
                "Low water alert triggered: {$water_level}% on {$device_name}");
        }
    } else {
        // Water is above threshold — auto-resolve any active low_water alert
        $active = db_fetch(
            "SELECT id FROM alerts WHERE device_id = ? AND alert_type = 'low_water' AND status = 'active' LIMIT 1",
            [$device_id]
        );
        if ($active) {
            db_update('alerts', [
                'status'      => 'resolved',
                'resolved_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$active['id']]);

            log_event($device_id, null, 'low_water_resolved', 'info',
                "Water level recovered to {$water_level}% on {$device_name}.");
        }
    }
}
