<?php
/**
 * WaterBonia API - Bootstrap
 *
 * Loads configuration and provides minimal helpers for the API context.
 * Does NOT start a web session (API uses token auth, not cookies).
 * Does NOT load functions.php (which calls session_start).
 */

// Suppress session_start for API context
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/api';

// Load database config (defines constants and db())
require_once __DIR__ . '/../config/database.php';

/* ── API Response Helpers ─────────────────────────────── */

function api_success(mixed $data = null, string $message = '', int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => true];
    if ($message !== '') $response['message'] = $message;
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $error, int $code = 400, array $details = []): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => false, 'error' => $error];
    if (!empty($details)) $response['details'] = $details;
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) api_error('Invalid JSON body', 422);
    return $data;
}

/* ── Rate Limiting (per-device, file-based) ────────────── */

function rate_limit_check(int $device_id, string $action, int $max_requests = 60, int $window_seconds = 60): void
{
    $dir = sys_get_temp_dir() . '/waterbonia_ratelimit';
    if (!is_dir($dir)) mkdir($dir, 0700, true);

    $key = $device_id . '_' . $action;
    $file = $dir . '/' . md5($key) . '.json';

    $now = time();
    $data = ['window_start' => $now, 'count' => 1];

    if (file_exists($file)) {
        $stored = json_decode(file_get_contents($file), true);
        if ($stored && ($now - $stored['window_start']) < $window_seconds) {
            if ($stored['count'] >= $max_requests) {
                api_error('Rate limit exceeded. Try again later.', 429);
            }
            $data = ['window_start' => $stored['window_start'], 'count' => $stored['count'] + 1];
        }
    }

    file_put_contents($file, json_encode($data), LOCK_EX);
}

/* ── Input Helpers ────────────────────────────────────── */

function require_json_method(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Method not allowed', 405);
    }
}

function require_json_body(): array
{
    require_json_method();
    return api_json_input();
}

/* ── Validation Helpers ───────────────────────────────── */

function validate_required(array $data, array $fields): array
{
    $errors = [];
    foreach ($fields as $field => $label) {
        if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
            $errors[$field] = "{$label} is required.";
        }
    }
    return $errors;
}

function validate_range(mixed $value, int $min, int $max, string $field): ?string
{
    if ($value === null || $value === '') return null;
    $val = (int)$value;
    if ($val < $min || $val > $max) {
        return "{$field} must be between {$min} and {$max}.";
    }
    return null;
}

function validate_bool(mixed $value, string $field): ?string
{
    if ($value === null) return null;
    if (!is_bool($value) && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') {
        return "{$field} must be a boolean.";
    }
    return null;
}

/* ── Minimal DB Helpers (API-specific) ────────────────── */
/* These mirror functions from includes/functions.php but  */
/* do NOT depend on session or web context.               */

function db_insert(string $table, array $data): int
{
    $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($data)));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $sql = "INSERT INTO `$table` ($cols) VALUES ($placeholders)";
    $stmt = db()->prepare($sql);
    $stmt->execute(array_values($data));
    return (int) db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $where_params = []): int
{
    $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
    $sql = "UPDATE `$table` SET $set WHERE $where";
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge(array_values($data), $where_params));
    return $stmt->rowCount();
}

function db_fetch(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function db_count(string $sql, array $params = []): int
{
    $row = db_fetch($sql, $params);
    return $row ? (int) reset($row) : 0;
}

function log_event(?int $device_id, ?int $user_id, string $event_type, string $level, string $message, ?array $metadata = null): void
{
    db_insert('activity_logs', [
        'device_id'   => $device_id,
        'user_id'     => $user_id,
        'event_type'  => $event_type,
        'event_level' => $level,
        'message'     => $message,
        'metadata'    => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function get_setting(string $key, string $default = ''): string
{
    $row = db_fetch("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
    return $row ? $row['setting_value'] : $default;
}

function create_alert(?int $device_id, string $alert_type, string $severity, string $title, string $message): int
{
    return db_insert('alerts', [
        'device_id'    => $device_id,
        'alert_type'   => $alert_type,
        'severity'     => $severity,
        'title'        => $title,
        'message'      => $message,
        'status'       => 'active',
        'triggered_at' => date('Y-m-d H:i:s'),
    ]);
}
