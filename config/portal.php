<?php
require_once __DIR__ . '/timer.php';
/**
 * Execution Portal safety/configuration shared by the web and device API.
 * Settings stay in system_settings; public selections only change the session.
 * A per-container selection is the dispensing cutoff in mL, not reservoir water.
 */

function portal_integer(mixed $value): ?int
{
    if (is_int($value)) return $value >= 0 ? $value : null;
    if (!is_string($value) || !preg_match('/^[0-9]+$/D', $value)) return null;
    $digits = ltrim($value, '0');
    if ($digits === '') return 0;
    $limit = (string)PHP_INT_MAX;
    if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) return null;
    return (int)$digits;
}

function portal_container_target_ml(string $type): int
{
    if (!in_array($type, ['cup', 'tumbler'], true)) return 0;
    return portal_integer(get_setting("container_{$type}_target_ml", $type === 'cup' ? '250' : '500')) ?? 0;
}

function portal_threshold_config(): array
{
    $result = [];
    foreach (['cup', 'tumbler'] as $type) {
        // Dedicated keys prevent legacy reservoir minima from limiting small containers.
        $min = portal_integer(get_setting("container_{$type}_fill_min_ml", '1')) ?? 0;
        $max = portal_integer(get_setting("container_{$type}_fill_max_ml", '2000')) ?? 0;
        $configured = $min >= 1 && $max >= $min && $max <= 2000;
        $preferred = portal_integer(get_setting("container_{$type}_fill_default_ml", (string)portal_container_target_ml($type)));
        $default = $configured ? (($preferred !== null && $preferred >= $min && $preferred <= $max) ? $preferred : $min) : null;
        $result[$type] = ['options' => [], 'default' => $default, 'min' => $min, 'max' => $max, 'semantics' => 'fill_volume_ml'];
    }
    return $result;
}

/** Returns [valid, user-facing reason, validated integer or null]. */
function validate_water_threshold(string $type, mixed $raw): array
{
    if (!in_array($type, ['cup', 'tumbler'], true)) return [false, 'Choose a valid container.', null];
    $value = portal_integer($raw);
    if ($value === null || $value < 1) return [false, 'Choose a positive whole-number fill amount in mL.', null];
    $config = portal_threshold_config()[$type];
    if ($config['default'] === null) return [false, 'The container fill range needs valid configuration.', null];
    if ($value < $config['min'] || $value > $config['max']) return [false, "Choose a fill amount between {$config['min']} and {$config['max']} mL.", null];
    return [true, 'Fill amount selected.', $value];
}

function portal_selected_thresholds(): array
{
    $result = [];
    foreach (portal_threshold_config() as $type => $config) {
        $stored = $_SESSION['portal_fill_volumes'][$type] ?? null;
        [$valid, , $value] = validate_water_threshold($type, $stored);
        $result[$type] = $valid ? $value : $config['default'];
    }
    return $result;
}

function portal_fresh_timestamp(?string $timestamp, int $timeout): bool
{
    $parsed = $timestamp ? strtotime($timestamp) : false;
    if ($parsed === false) return false;
    $age = time() - $parsed;
    return $age >= -5 && $age <= $timeout;
}

function portal_telemetry_timeout(): int
{
    return max(1, min(10, portal_integer(get_setting('portal_telemetry_max_age_seconds', '10')) ?? 10));
}

/** A sample from before session start cannot describe the pump/container now. */
function portal_reading_current_for_session(?array $reading, ?array $active): bool
{
    if (!$active) return true;
    $sample_at = isset($reading['recorded_at']) ? strtotime($reading['recorded_at']) : false;
    $started_at = isset($active['started_at']) ? strtotime($active['started_at']) : false;
    return $sample_at !== false && $started_at !== false && $sample_at > $started_at;
}

/** A pure guard used both when queuing and when claiming a command. */
function portal_safety_reason(?array $device, ?array $reading, ?array $active, ?array $queued, bool $safety_alert, array $reservoir, ?int $threshold = null): ?string
{
    if (!$device) return 'No physical dispenser is registered.';
    if (($device['status'] ?? '') !== 'online') return ($device['status'] ?? '') === 'disabled' ? 'The dispenser is disabled.' : 'The dispenser is offline or unavailable.';
    $timeout = 30;
    if (!portal_fresh_timestamp($device['last_heartbeat_at'] ?? null, $timeout)) return 'The dispenser heartbeat is stale or unavailable.';
    if (!$reading || !portal_fresh_timestamp($reading['recorded_at'] ?? null, portal_telemetry_timeout())) return 'Fresh device telemetry is required before dispensing.';
    if (!timer_sensor_valid($reading, $device)) return 'The container sensor is unavailable or has reported a fault.';
    if (isset($reading['device_id']) && (int)$reading['device_id'] !== (int)$device['id']) return 'Telemetry belongs to another device.';
    if ($safety_alert) return 'A device or sensor safety error blocks dispensing.';
    if ($active) return 'A dispensing operation is already active.';
    if ($queued) return 'A dispensing request is already queued or claimed.';
    if (!array_key_exists('pump_status', $reading) || !in_array($reading['pump_status'], [0, 1, '0', '1', false, true], true)) return 'Valid pump telemetry is required before dispensing.';
    if (!empty($reading['pump_status'])) return 'The physical pump is already running.';
    if (empty($reading['container_detected'])) return 'Place a container on the dispenser tray first.';
    return null;
}

function portal_device_safety_alert(int $device_id): bool
{
    return (bool)db_fetch("SELECT id FROM alerts WHERE device_id = ? AND status IN ('active','acknowledged') AND alert_type IN ('sensor_error','device_error','pump_error','system_error','dispensing_timeout','dispensing_interrupted') LIMIT 1", [$device_id]);
}

/** The immutable queued target, never a legacy reservoir minimum or current preference. */
function portal_command_threshold_ml(int $device_id, ?int $command_id): ?int
{
    if (!$command_id) return null;
    $row = db_fetch('SELECT target_amount_ml FROM device_commands WHERE id = ? AND device_id = ?', [$command_id, $device_id]);
    $value = portal_integer($row['target_amount_ml'] ?? null);
    return $value !== null && $value > 0 ? $value : null;
}

/** Pure command authorization shared by claim/start and regression fixtures. */
function portal_authorized_command_target(?array $command, int $device_id, string $required_status): array
{
    if (!$command || (int)($command['device_id'] ?? 0) !== $device_id || ($command['command_type'] ?? '') !== 'dispense') return [false, 'Dispensing command not found for this device.', null];
    if (($command['status'] ?? '') !== $required_status) return [false, 'The dispensing command is not ' . $required_status . '.', null];
    if ($required_status === 'claimed') {
        $lease = max(1, min(120, portal_integer(get_setting('dispense_command_ttl_sec', '30')) ?? 30));
        if (!portal_fresh_timestamp($command['claimed_at'] ?? null, $lease)) return [false, 'The claimed dispensing request expired. Please request a new fill.', null];
    } elseif ($required_status === 'pending' && (!isset($command['expires_at']) || strtotime($command['expires_at']) <= time())) {
        return [false, 'The dispensing request expired.', null];
    }
    return validate_water_threshold((string)($command['container_type'] ?? ''), $command['target_amount_ml'] ?? null);
}

function portal_session_start_target(?array $command, int $device_id, mixed $container_type, mixed $target_amount_ml): array
{
    [$valid, $reason, $target] = portal_authorized_command_target($command, $device_id, 'claimed');
    if (!$valid) return [false, $reason, null];
    if (!is_string($container_type) || $container_type !== $command['container_type']) return [false, 'Container type does not match the claimed command.', null];
    if (portal_integer($target_amount_ml) !== $target) return [false, 'Fill amount does not match the claimed command.', null];
    return [true, 'Claimed dispensing command authorized.', $target];
}

function portal_session_threshold_ml(int $device_id, int $session_id): ?int
{
    $command = db_fetch('SELECT id FROM device_commands WHERE device_id = ? AND session_id = ? ORDER BY id DESC LIMIT 1', [$device_id, $session_id]);
    return portal_command_threshold_ml($device_id, $command ? (int)$command['id'] : null);
}

/** Public allowlist: no admin messages, metadata, credentials, or user details. */
function portal_recent_activities(?int $device_id, int $limit = 25): array
{
    if ($device_id === null) return [];
    $labels = [
        'container_selected' => ['Container selected', 'Completed', 'Container selection confirmed.'],
        'water_threshold_selected' => ['Historical threshold selected', 'Completed', 'A legacy threshold selection was recorded.'],
        'fill_volume_selected' => ['Fill amount selected', 'Completed', 'Dispensing cutoff selected.'],
        'container_detected' => ['Container detected', 'Real', 'Container detected on the dispenser tray.'],
        'container_removed' => ['Container removed', 'Real', 'Container removed from the dispenser tray.'],
        'dispensing_command_created' => ['Dispensing requested', 'Pending', 'A dispensing command was queued.'],
        'dispensing_command_accepted' => ['Dispensing request accepted', 'Real', 'The device accepted the dispensing command.'],
        'dispensing_started' => ['Dispensing started', 'Real', 'A physical dispensing session started.'],
        'dispensing_completed' => ['Dispensing completed', 'Completed', 'The device reported a completed dispensing session.'],
        'dispensing_interrupted' => ['Dispensing interrupted', 'Interrupted', 'The device reported an interrupted dispensing session.'],
        'dispensing_blocked' => ['Dispensing blocked', 'Blocked', 'A safety condition prevented the dispensing request.'],
        'device_offline' => ['Device offline', 'Warning', 'The physical dispenser stopped reporting.'],
        'device_connected' => ['Device connected', 'Real', 'The physical dispenser reported a connection.'],
        'sensor_error' => ['Sensor error', 'Error', 'A physical sensor reported an error.'],
        'device_error' => ['Device error', 'Error', 'The physical device reported an error.'],
        'command_expired' => ['Request expired', 'Warning', 'The device did not claim the queued request in time.'],
        'dispensing_command_rejected' => ['Dispensing blocked', 'Blocked', 'Safety checks blocked a queued request.'],
        'dispensing_request_cancelled' => ['Dispensing request cancelled', 'Cancelled', 'The user cancelled a pending dispensing request.'],
    ];
    $types = array_keys($labels);
    $limit = max(1, min(50, $limit));
    $stmt = db()->prepare('SELECT id,event_type,event_level,message,metadata,created_at FROM activity_logs WHERE device_id = ? AND event_type IN (' . implode(',', array_fill(0, count($types), '?')) . ') ORDER BY created_at DESC,id DESC LIMIT ' . $limit);
    $stmt->execute(array_merge([$device_id], $types));
    $events = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        [$event, $status, $details] = $labels[$row['event_type']];
        $metadata = json_decode($row['metadata'] ?? '', true);
        if (is_array($metadata) && in_array($metadata['container_type'] ?? '', ['cup','tumbler'], true)) {
            $type = ucfirst($metadata['container_type']);
            $target = portal_integer($metadata['target_amount_ml'] ?? null);
            if ($target !== null) $details = "{$type} — {$target} mL " . (($metadata['threshold_semantics'] ?? '') === 'fill_volume_ml' ? 'fill amount' : 'target');
        } elseif (preg_match('/target:\s*([0-9]+)mL/', $row['message'], $match)) {
            $details .= ' Target: ' . (int)$match[1] . ' mL.';
        } elseif (preg_match('/measured\s+([0-9]+)mL/', $row['message'], $match)) {
            $details .= ' Measured: ' . (int)$match[1] . ' mL.';
        }
        if (is_array($metadata) && isset($metadata['duration_ms'])) {
            $details = 'Configured time: ' . ((int)$metadata['duration_ms'] / 1000) . ' s; actual water volume not measured.';
            if (isset($metadata['pump_elapsed_ms'])) $details .= ' Reported pump time: ' . ((int)$metadata['pump_elapsed_ms'] / 1000) . ' s.';
            if (isset($metadata['stop_reason'])) $details .= ' ' . str_replace('_', ' ', (string)$metadata['stop_reason']) . '.';
        }
        if (in_array($row['event_level'], ['error','critical'], true)) $status = 'Error';
        $created_at = (string)($row['created_at'] ?? '');
        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $created_at);
        // Keep the database wall time; invalid records must not become today's date or the Unix epoch.
        $valid_timestamp = $timestamp !== false && $timestamp->format('Y-m-d H:i:s') === $created_at;
        $events[] = ['id' => (int)$row['id'], 'time' => $valid_timestamp ? $timestamp->format('H:i:s') : 'Unavailable',
            'date_time' => $valid_timestamp ? $timestamp->format('Y-m-d H:i:s') : 'Date & time unavailable',
            'created_at' => $created_at, 'event' => $event, 'details' => $details, 'status' => $status];
    }
    return $events;
}

function portal_activity_records(?int $device_id, int $limit = 25): array
{
    return portal_recent_activities($device_id, $limit);
}
