<?php
/**
 * WaterBonia - Activity Logs Page
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();

$page_title = 'Activity Logs';

// Filters
$search = trim($_GET['search'] ?? '');
$event_type = $_GET['event_type'] ?? '';
$event_level = $_GET['event_level'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where = '1=1';
$params = [];

if ($search !== '') {
    $where .= ' AND al.message LIKE ?';
    $params[] = "%{$search}%";
}
if ($event_type !== '') {
    $where .= ' AND al.event_type = ?';
    $params[] = $event_type;
}
if ($event_level !== '') {
    $where .= ' AND al.event_level = ?';
    $params[] = $event_level;
}
if ($date_from !== '') {
    $where .= ' AND al.created_at >= ?';
    $params[] = $date_from . ' 00:00:00';
}
if ($date_to !== '') {
    $where .= ' AND al.created_at <= ?';
    $params[] = $date_to . ' 23:59:59';
}

$count_where = str_replace('al.', '', $where);
$p = paginate('activity_logs', $count_where, $params, 25);
$logs = db_fetch_all(
    "SELECT al.*, d.device_name, u.username FROM activity_logs al LEFT JOIN devices d ON al.device_id = d.id LEFT JOIN users u ON al.user_id = u.id WHERE $where ORDER BY al.created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$p['per_page'], $p['offset']])
);

$event_types = db_fetch_all("SELECT DISTINCT event_type FROM activity_logs ORDER BY event_type");

$base_url = base_url('/pages/activity-logs.php?')
    . http_build_query(array_filter([
        'search'     => $search,
        'event_type' => $event_type,
        'event_level'=> $event_level,
        'date_from'  => $date_from,
        'date_to'    => $date_to,
    ]));

include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-3">
    <div class="card-header"><h3>Filter Logs</h3></div>
    <div class="card-body">
        <form method="GET" action="<?= base_url('/pages/activity-logs.php') ?>">
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:end">
                <div class="form-group" style="margin:0;flex:1;min-width:150px">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control" placeholder="Search messages…" value="<?= sanitize($search) ?>">
                </div>
                <div class="form-group" style="margin:0;min-width:140px">
                    <label for="event_type">Event Type</label>
                    <select id="event_type" name="event_type" class="form-control">
                        <option value="">All Types</option>
                        <?php foreach ($event_types as $et): ?>
                            <option value="<?= sanitize($et['event_type']) ?>" <?= $event_type === $et['event_type'] ? 'selected' : '' ?>><?= sanitize($et['event_type']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;min-width:120px">
                    <label for="event_level">Level</label>
                    <select id="event_level" name="event_level" class="form-control">
                        <option value="">All Levels</option>
                        <?php foreach (['info','warning','error','critical'] as $lv): ?>
                            <option value="<?= $lv ?>" <?= $event_level === $lv ? 'selected' : '' ?>><?= ucfirst($lv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;min-width:130px">
                    <label for="date_from">From</label>
                    <input type="date" id="date_from" name="date_from" class="form-control" value="<?= sanitize($date_from) ?>">
                </div>
                <div class="form-group" style="margin:0;min-width:130px">
                    <label for="date_to">To</label>
                    <input type="date" id="date_to" name="date_to" class="form-control" value="<?= sanitize($date_to) ?>">
                </div>
                <div class="form-group" style="margin:0">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="<?= base_url('/pages/activity-logs.php') ?>" class="btn btn-outline">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Activity Log</h3>
        <span class="text-sm text-muted"><?= $p['total'] ?> records</span>
    </div>
    <div class="card-body-np">
        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <div class="empty-title">No Activity Logs</div>
                <div class="empty-desc"><?= $search || $event_type || $event_level || $date_from || $date_to ? 'No logs match the current filters.' : 'Activity will appear once the system is operational.' ?></div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>Device</th>
                            <th>User</th>
                            <th>Event Type</th>
                            <th>Level</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-sm"><?= format_datetime($log['created_at']) ?></td>
                            <td class="text-sm"><?= $log['device_name'] ? sanitize($log['device_name']) : '<span class="text-muted">System</span>' ?></td>
                            <td class="text-sm"><?= $log['username'] ? sanitize($log['username']) : '<span class="text-muted">—</span>' ?></td>
                            <td><code style="font-size:0.78rem"><?= sanitize($log['event_type']) ?></code></td>
                            <td><?= event_level_badge($log['event_level']) ?></td>
                            <td class="text-sm"><?= sanitize(mb_strimwidth($log['message'], 0, 80, '…')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= render_pagination($p, $base_url) ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
