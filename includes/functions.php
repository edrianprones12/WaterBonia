<?php
/**
 * WaterBonia - Shared Functions
 */

session_start();

require_once __DIR__ . '/../config/database.php';

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
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
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
    $row = db_fetch("SELECT setting_value FROM system_settings WHERE setting_key = ?", [$key]);
    return $row ? $row['setting_value'] : $default;
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
