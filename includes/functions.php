<?php
/**
 * WaterBonia - Shared Functions
 */

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/demo.php';
require_once __DIR__ . '/../config/portal.php';

/* ── Base URL ────────────────────────────────────────── */
// Auto-detect: functions.php is at WaterBonia/includes/
// dirname(__DIR__) = WaterBonia/, basename = WaterBonia
define('BASE_URL', '/' . basename(dirname(__DIR__)));

/* ── Security Helpers ─────────────────────────────────── */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && is_string($expected) && $expected !== ''
        && hash_equals($expected, $token);
}

function redirect(string $url): void
{
    // Absolute URLs (http/https) — use as-is
    if (preg_match('#^https?://#i', $url)) {
        header('Location: ' . $url);
    } else {
        // Relative paths — prepend BASE_URL
        header('Location: ' . BASE_URL . $url);
    }
    exit;
}

function base_url(string $path = ''): string
{
    return BASE_URL . $path;
}

function sanitize(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/* ── Authentication ───────────────────────────────────── */

function logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function require_login(): void
{
    if (!logged_in()) {
        redirect('/auth/login.php');
    }
}

function current_user(): ?array
{
    if (!logged_in()) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT id, username, email, full_name, role, status, last_login_at, created_at FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active') {
            session_destroy();
            $user = null;
            redirect('/auth/login.php');
        }
    }
    return $user;
}

function current_role(): string
{
    $u = current_user();
    return $u ? $u['role'] : '';
}

function is_admin(): bool
{
    return current_role() === 'admin';
}

function can_edit(): bool
{
    return in_array(current_role(), ['admin', 'operator'], true);
}

/* ── Database Helpers ─────────────────────────────────── */

function db_fetch(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function db_fetch_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_count(string $sql, array $params = []): int
{
    $row = db_fetch($sql, $params);
    return $row ? (int) reset($row) : 0;
}

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

/* ── Activity Logging ─────────────────────────────────── */

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

/* ── Alert Helpers ────────────────────────────────────── */

function active_alert_count(): int
{
    return db_count("SELECT COUNT(*) FROM alerts WHERE status = 'active'");
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

/* ── Pagination ───────────────────────────────────────── */

function paginate(string $table, string $where = '1=1', array $params = [], int $per_page = 20, int $page = 0): array
{
    if ($page < 1) {
        $page = max(1, (int)($_GET['page'] ?? 1));
    }
    $total = db_count("SELECT COUNT(*) FROM `$table` WHERE $where", $params);
    $total_pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;
    return [
        'page'        => $page,
        'per_page'    => $per_page,
        'total'       => $total,
        'total_pages' => $total_pages,
        'offset'      => $offset,
    ];
}

function render_pagination(array $p, string $base_url): string
{
    if ($p['total_pages'] <= 1) return '';
    $html = '<nav class="pagination">';
    if ($p['page'] > 1) {
        $html .= '<a href="' . $base_url . '&page=' . ($p['page'] - 1) . '" class="page-btn">&laquo; Prev</a>';
    }
    for ($i = max(1, $p['page'] - 2); $i <= min($p['total_pages'], $p['page'] + 2); $i++) {
        $active = $i === $p['page'] ? ' active' : '';
        $html .= '<a href="' . $base_url . '&page=' . $i . '" class="page-btn' . $active . '">' . $i . '</a>';
    }
    if ($p['page'] < $p['total_pages']) {
        $html .= '<a href="' . $base_url . '&page=' . ($p['page'] + 1) . '" class="page-btn">Next &raquo;</a>';
    }
    $html .= '<span class="page-info">Page ' . $p['page'] . ' of ' . $p['total_pages'] . ' (' . $p['total'] . ' records)</span>';
    $html .= '</nav>';
    return $html;
}

/* ── Formatting ───────────────────────────────────────── */

function format_datetime(?string $dt): string
{
    if (!$dt) return '—';
    return date('M d, Y H:i', strtotime($dt));
}

function time_ago(?string $dt): string
{
    if (!$dt) return 'Never';
    $diff = time() - strtotime($dt);
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

function status_badge(string $status): string
{
    $class = match($status) {
        'online','active','completed','info','resolved' => 'badge-success',
        'offline','inactive','failed','critical'        => 'badge-danger',
        'maintenance','warning','acknowledged','interrupted' => 'badge-warning',
        'disabled','error'                              => 'badge-error',
        'started'                                       => 'badge-info',
        default                                         => 'badge-secondary',
    };
    return '<span class="badge ' . $class . '">' . sanitize(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

function event_level_badge(string $level): string
{
    $class = match($level) {
        'info'     => 'badge-info',
        'warning'  => 'badge-warning',
        'error'    => 'badge-error',
        'critical' => 'badge-danger',
        default    => 'badge-secondary',
    };
    return '<span class="badge ' . $class . '">' . sanitize(ucwords($level)) . '</span>';
}

function water_level_class(?int $percent): string
{
    if ($percent === null) return 'water-unknown';
    if ($percent <= 10) return 'water-critical';
    if ($percent <= 25) return 'water-low';
    return 'water-normal';
}

/* ── Device Helpers ───────────────────────────────────── */

function get_active_device(): ?array
{
    return db_fetch("SELECT * FROM devices WHERE status != 'disabled' ORDER BY last_seen_at DESC LIMIT 1");
}

function get_all_devices(): array
{
    return db_fetch_all("SELECT * FROM devices ORDER BY created_at DESC");
}

function get_setting(string $key, string $default = ''): string
{
    static $demo_db_unavailable = false;
    if ($demo_db_unavailable && WATERBONIA_DEMO_MODE) return $default;
    try {
        $row = db_fetch("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
        return $row ? $row['setting_value'] : $default;
    } catch (PDOException $e) {
        // Presentation defaults are allowed without MySQL. Real operation fails closed.
        if (WATERBONIA_DEMO_MODE) {
            $demo_db_unavailable = true;
            return $default;
        }
        throw $e;
    }
}

/* ── Container / Dispensing v2 Helpers ─────────────────── */

const CONTAINER_TYPES = ['cup', 'tumbler'];

function container_target_ml(string $container_type): int
{
    return portal_container_target_ml($container_type);
}

function container_label(string $container_type): string
{
    return $container_type === 'tumbler' ? 'Tumbler' : 'Cup';
}

/**
 * Create a pending dispense command for a device.
 * Returns [ok, message] — command is queued for ESP32 polling.
 */
function request_dispense(int $device_id, string $container_type, ?int $user_id = null, mixed $threshold_ml = null): array
{
    if (WATERBONIA_DEMO_MODE) return [false, 'Demo dispensing runs only in the simulated presentation.', null];
    if (!in_array($container_type, CONTAINER_TYPES, true)) {
        return [false, 'Invalid container type.', null];
    }

    $threshold_ml ??= portal_selected_thresholds()[$container_type];
    [$valid, $validation_message, $threshold] = validate_water_threshold($container_type, $threshold_ml);
    if (!$valid) return [false, $validation_message, null];

    $err = null;
    $message = null;
    $command_id = null;

    // The device row lock serialises concurrent requests for the same device,
    // so two simultaneous clicks can never queue two commands.
    db()->beginTransaction();
    try {
        $device = db_fetch("SELECT * FROM devices WHERE id = ? FOR UPDATE", [$device_id]);
        $active = db_fetch(
                "SELECT id FROM dispensing_sessions WHERE device_id = ? AND status = 'started' LIMIT 1",
                [$device_id]
            );
        $queued = db_fetch(
                "SELECT id FROM device_commands
                  WHERE device_id = ? AND status IN ('pending','claimed')
                    AND (status = 'claimed' OR expires_at > NOW())
                  LIMIT 1",
                [$device_id]
            );
        $latest = db_fetch(
                "SELECT * FROM sensor_readings WHERE device_id = ? ORDER BY recorded_at DESC,id DESC LIMIT 1",
                [$device_id]
            );
        $err = portal_safety_reason($device, $latest, $active, $queued,
            portal_device_safety_alert($device_id), [], $threshold);

        if ($err === null) {
            $ttl = max(1, min(120, portal_integer(get_setting('dispense_command_ttl_sec', '30')) ?? 30));
            $target = $threshold;

            $command_id = db_insert('device_commands', [
                'device_id'        => $device_id,
                'user_id'          => $user_id,
                'command_type'     => 'dispense',
                'container_type'   => $container_type,
                'target_amount_ml' => $target,
                'status'           => 'pending',
                'requested_at'     => date('Y-m-d H:i:s'),
                'expires_at'       => date('Y-m-d H:i:s', time() + $ttl),
            ]);

            log_event($device_id, $user_id, 'dispensing_command_created', 'info',
                "Dispensing command #{$command_id} created: {$container_type} ({$target}mL), expires in {$ttl}s.",
                ['command_id' => $command_id, 'container_type' => $container_type, 'target_amount_ml' => $target, 'fill_amount_ml' => $target, 'threshold_ml' => $target, 'threshold_semantics' => 'fill_volume_ml']);

            $message = "Dispensing requested — {$container_type} ({$target}mL).";
            db()->commit();
        } else {
            db()->rollBack();
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return [false, 'Could not queue the dispensing request.', null];
    }

    if ($err !== null) {
        // One event for an explicit failed request; polling never records requests.
        log_event($device ? $device_id : null, $user_id,
            'dispensing_blocked', 'warning', $err,
            ['container_type' => $container_type, 'target_amount_ml' => $threshold, 'fill_amount_ml' => $threshold, 'threshold_semantics' => 'fill_volume_ml']);
        return [false, $err, null];
    }

    $_SESSION['portal_command_id'] = $command_id;
    return [true, $message, $command_id];
}

/**
 * Detect a device that stopped reporting and record it truthfully:
 * device row → offline, one activity event, one (deduplicated) alert.
 * Called on live-state reads; only ever fires on an actual online→offline
 * transition because the UPDATE is guarded by status = 'online'.
 */
function refresh_device_offline_state(?array $device): void
{
    if (!$device || ($device['status'] ?? '') !== 'online') {
        return;
    }

    $timeout = (int)get_setting('device_offline_timeout_seconds', '120');
    if ($device['last_seen_at'] && (time() - strtotime($device['last_seen_at'])) <= $timeout) {
        return;
    }

    $updated = db_update('devices',
        ['status' => 'offline'],
        'id = ? AND status = ?', [(int)$device['id'], 'online']);

    if ($updated < 1) {
        return; // another request already recorded the transition
    }
    $device['status'] = 'offline';

    log_event((int)$device['id'], null, 'device_offline', 'warning',
        "Device {$device['device_name']} stopped reporting (no telemetry for {$timeout}s). Last seen: " .
        ($device['last_seen_at'] ?: 'never') . '.');

    $open = db_fetch(
        "SELECT id FROM alerts WHERE device_id = ? AND alert_type = 'device_offline' AND status = 'active' LIMIT 1",
        [(int)$device['id']]
    );
    if (!$open) {
        create_alert((int)$device['id'], 'device_offline', 'warning',
            'Device offline',
            "Device {$device['device_name']} has not reported for {$timeout}s. Last seen: " .
            ($device['last_seen_at'] ?: 'never') . '.');
    }
}

/**
 * Latest dispenser state for the dashboard (live view).
 */
function get_live_dispenser_state(?array $device): array
{
    $state = [
        'container_detected' => false,
        'pump_status'        => false,
        'active_session'     => null,
        'pending_command'    => null,
        'device_online'      => false,
        'state_label'        => 'offline',
        'status_text'        => 'Waiting for the dispenser',
        'step'               => 1,
        'telemetry_fresh'    => false,
        'telemetry_valid'    => false,
        'safety_blocked'     => true,
        'safety_message'     => 'No physical dispenser is registered.',
        '_reading'           => null,
    ];
    if (!$device) return $state;

    refresh_device_offline_state($device);

    $state['device_online'] = $device['status'] === 'online'
        && portal_fresh_timestamp($device['last_seen_at'] ?? null, max(1, portal_integer(get_setting('device_offline_timeout_seconds', '120')) ?? 120));

    $reading = db_fetch(
        "SELECT * FROM sensor_readings WHERE device_id = ? ORDER BY recorded_at DESC,id DESC LIMIT 1",
        [$device['id']]
    );
    $state['_reading'] = $reading;
    $state['telemetry_fresh'] = $state['device_online'] && $reading !== null
        && portal_fresh_timestamp($reading['recorded_at'] ?? null, portal_telemetry_timeout());
    $safety_alert = portal_device_safety_alert((int)$device['id']);
    $state['telemetry_valid'] = $state['telemetry_fresh'] && !$safety_alert
        && array_key_exists('pump_status', $reading)
        && in_array($reading['pump_status'], [0, 1, '0', '1', false, true], true);
    if ($reading && $state['telemetry_valid']) {
        $state['container_detected'] = !empty($reading['container_detected']) || !empty($reading['cup_detected']);
        $state['pump_status']    = !empty($reading['pump_status']);
    }

    $state['active_session'] = db_fetch(
        "SELECT * FROM dispensing_sessions WHERE device_id = ? AND status = 'started' ORDER BY started_at DESC LIMIT 1",
        [$device['id']]
    );
    $state['pending_command'] = db_fetch(
        "SELECT * FROM device_commands
          WHERE device_id = ? AND status IN ('pending','claimed') AND (status = 'claimed' OR expires_at > NOW())
          ORDER BY requested_at DESC LIMIT 1",
        [$device['id']]
    );
    if ($state['active_session']) {
        $state['active_session']['threshold_ml'] = portal_session_threshold_ml((int)$device['id'], (int)$state['active_session']['id']);
        if (!portal_reading_current_for_session($reading, $state['active_session'])) {
            $state['telemetry_valid'] = false;
            $state['telemetry_fresh'] = false;
        }
        if (!$state['telemetry_valid']) {
            $state['container_detected'] = null;
            $state['pump_status'] = null;
        }
    }
    if ($state['pending_command']) {
        $state['pending_command']['threshold_ml'] = portal_command_threshold_ml((int)$device['id'], (int)$state['pending_command']['id']);
    }

    $selected = $_SESSION['portal_container_type'] ?? null;
    $selected_threshold = in_array($selected, CONTAINER_TYPES, true) ? portal_selected_thresholds()[$selected] : null;
    $reason = portal_safety_reason($device, $reading, $state['active_session'], $state['pending_command'], $safety_alert, [], $selected_threshold);
    $state['safety_blocked'] = $reason !== null;
    $state['safety_message'] = $reason;

    // 4-step progression (internal machine label — the UI shows status_text)
    if ($state['active_session']) {
        $state['step'] = 3;
        $state['state_label'] = $state['pump_status'] === false ? 'finishing' : 'dispensing';
    } elseif ($state['pending_command']) {
        $state['step'] = 3;
        $state['state_label'] = 'waiting_for_device';
    } elseif (!$state['device_online']) {
        $state['step'] = 1;
        $state['state_label'] = 'offline';
    } elseif ($state['container_detected']) {
        $state['step'] = 2;
        $state['state_label'] = 'container_placed';
    } else {
        $state['step'] = 1;
        $state['state_label'] = 'choose_container';
    }

    $state['status_text'] = dispenser_status_text($state);

    return $state;
}

/**
 * User-facing sentence for the current dispenser state.
 * Internal machine labels (state_label) are never shown to the user.
 */
function dispenser_status_text(array $state): string
{
    if (!$state['device_online']) {
        return 'Waiting for the dispenser';
    }
    if (!empty($state['active_session'])) {
        if ($state['pump_status'] === null) return 'Session active — live readings unavailable';
        return $state['pump_status'] ? 'Dispensing' : 'Finishing up';
    }
    if (!empty($state['pending_command'])) {
        return 'Request sent to dispenser';
    }
    if (!empty($state['container_detected'])) {
        return 'Container detected';
    }
    return 'Ready — choose your container';
}

/* ── Status presentation (shared by page render + AJAX) ── */

/**
 * Map live state to the header availability pill.
 * Reflects REAL device/system state only — every label states what the
 * system actually knows (device connectivity and installed sensor telemetry).
 * "SYSTEM AVAILABLE" is deliberately not used: the website cannot claim
 * availability the hardware has not reported.
 *
 * @return array{key:string,label:string,tone:string,hint:string}
 */
function dispenser_availability(?array $device, array $state): array
{
    if (!$device) {
        return [
            'key'   => 'no_device',
            'label' => 'NO DEVICE',
            'tone'  => 'neutral',
            'hint'  => 'The physical dispenser has not reported device data.',
        ];
    }

    if ($device['status'] === 'disabled') {
        return [
            'key'   => 'disabled',
            'label' => 'DEVICE DISABLED',
            'tone'  => 'danger',
            'hint'  => 'This physical dispenser is disabled.',
        ];
    }

    if (empty($state['device_online'])) {
        return [
            'key'   => 'offline',
            'label' => 'DEVICE OFFLINE',
            'tone'  => 'danger',
            'hint'  => 'Last seen: ' . ($device['last_seen_at'] ?: 'never'),
        ];
    }

    if (empty($state['telemetry_valid'])) {
        return ['key' => 'no_data', 'label' => 'TELEMETRY UNAVAILABLE', 'tone' => 'warning', 'hint' => $state['safety_message'] ?? 'Fresh, valid physical sensor data is required.'];
    }
    return [
        'key'   => 'available',
        'label' => 'DEVICE ONLINE',
        'tone'  => 'success',
        'hint'  => !empty($state['safety_blocked']) ? ($state['safety_message'] ?? 'Dispensing is currently blocked.') : 'Fresh device and container telemetry is available.',
    ];
}

/**
 * Existing telemetry has no RGB state field. Show unknown rather than infer
 * a physical LED colour or declare completion from a stopped pump.
 *
 * @return array{color:string,hex:string,label:string,description:string,confirmed:bool}
 */
function dispenser_led(?array $device, array $state): array
{
    return [
        'color'       => 'gray',
        'hex'         => '#94a3b8',
        'label'       => 'PHYSICAL LED STATE UNAVAILABLE',
        'description' => 'Current device telemetry does not report the RGB LED state.',
        'confirmed'   => false,
    ];
}

/* ── CSRF-protected form for alerts actions ───────────── */

function alert_action_button(int $alert_id, string $action, string $label): string
{
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
    $target = base_url("/pages/alerts.php?action={$action}&id={$alert_id}");
    return '<form method="POST" action="' . $target . '" style="display:inline">' .
           csrf_field() .
           '<button type="submit" class="btn btn-sm btn-' . ($action === 'resolve' ? 'success' : 'warning') . '">' . sanitize($label) . '</button></form>';
}
