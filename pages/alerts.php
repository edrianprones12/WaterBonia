<?php
/**
 * WaterBonia - Alerts Page
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();

$page_title = 'Alerts';

// Handle actions
if (is_post() && verify_csrf()) {
    $action = $_GET['action'] ?? '';
    $alert_id = (int)($_GET['id'] ?? 0);
    $user = current_user();

    if ($alert_id > 0 && in_array($action, ['acknowledge', 'resolve'])) {
        $alert = db_fetch("SELECT * FROM alerts WHERE id = ?", [$alert_id]);
        if ($alert) {
            if ($action === 'acknowledge' && $alert['status'] === 'active') {
                db_update('alerts', [
                    'status'           => 'acknowledged',
                    'acknowledged_at'  => date('Y-m-d H:i:s'),
                    'acknowledged_by'  => $user['id'],
                ], 'id = ?', [$alert_id]);
                log_event($alert['device_id'], $user['id'], 'alert_acknowledged', 'info', 'Alert acknowledged: ' . $alert['title']);
            } elseif ($action === 'resolve' && in_array($alert['status'], ['active', 'acknowledged'])) {
                db_update('alerts', [
                    'status'     => 'resolved',
                    'resolved_at'=> date('Y-m-d H:i:s'),
                ], 'id = ?', [$alert_id]);
                log_event($alert['device_id'], $user['id'], 'alert_resolved', 'info', 'Alert resolved: ' . $alert['title']);
            }
        }
        redirect('/pages/alerts.php');
    }
}

// Filters
$filter_status = $_GET['status'] ?? '';
$filter_severity = $_GET['severity'] ?? '';
$filter_type = $_GET['type'] ?? '';

$where = '1=1';
$params = [];

if ($filter_status !== '') {
    $where .= ' AND a.status = ?';
    $params[] = $filter_status;
}
if ($filter_severity !== '') {
    $where .= ' AND a.severity = ?';
    $params[] = $filter_severity;
}
if ($filter_type !== '') {
    $where .= ' AND a.alert_type = ?';
    $params[] = $filter_type;
}

$count_where = str_replace('a.', '', $where);
$p = paginate('alerts', $count_where, $params, 20);
$alerts = db_fetch_all(
    "SELECT a.*, d.device_name, u.username as acknowledged_by_name FROM alerts a LEFT JOIN devices d ON a.device_id = d.id LEFT JOIN users u ON a.acknowledged_by = u.id WHERE $where ORDER BY a.triggered_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$p['per_page'], $p['offset']])
);

$alert_types = db_fetch_all("SELECT DISTINCT alert_type FROM alerts ORDER BY alert_type");

$base_url = base_url('/pages/alerts.php?' . http_build_query(array_filter(['status' => $filter_status, 'severity' => $filter_severity, 'type' => $filter_type])));

include __DIR__ . '/../includes/header.php';
?>

<div class="stats-grid mb-3">
    <div class="stat-card">
        <div class="stat-icon red">🔴</div>
        <div class="stat-content">
            <div class="stat-label">Active</div>
            <div class="stat-value"><?= db_count("SELECT COUNT(*) FROM alerts WHERE status = 'active'") ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow">🟡</div>
        <div class="stat-content">
            <div class="stat-label">Acknowledged</div>
            <div class="stat-value"><?= db_count("SELECT COUNT(*) FROM alerts WHERE status = 'acknowledged'") ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">🟢</div>
        <div class="stat-content">
            <div class="stat-label">Resolved</div>
            <div class="stat-value"><?= db_count("SELECT COUNT(*) FROM alerts WHERE status = 'resolved'") ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon gray">📊</div>
        <div class="stat-content">
            <div class="stat-label">Total</div>
            <div class="stat-value"><?= db_count("SELECT COUNT(*) FROM alerts") ?></div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h3>Filter Alerts</h3></div>
    <div class="card-body">
        <form method="GET" action="<?= base_url('/pages/alerts.php') ?>">
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:end">
                <div class="form-group" style="margin:0;min-width:140px">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="">All</option>
                        <?php foreach (['active','acknowledged','resolved'] as $s): ?>
                            <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;min-width:130px">
                    <label>Severity</label>
                    <select name="severity" class="form-control">
                        <option value="">All</option>
                        <?php foreach (['info','warning','critical'] as $sv): ?>
                            <option value="<?= $sv ?>" <?= $filter_severity === $sv ? 'selected' : '' ?>><?= ucfirst($sv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;min-width:150px">
                    <label>Type</label>
                    <select name="type" class="form-control">
                        <option value="">All</option>
                        <?php foreach ($alert_types as $at): ?>
                            <option value="<?= sanitize($at['alert_type']) ?>" <?= $filter_type === $at['alert_type'] ? 'selected' : '' ?>><?= sanitize($at['alert_type']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="<?= base_url('/pages/alerts.php') ?>" class="btn btn-outline">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body-np">
        <?php if (empty($alerts)): ?>
            <div class="empty-state">
                <div class="empty-icon">🔔</div>
                <div class="empty-title">No Alerts</div>
                <div class="empty-desc"><?= $filter_status || $filter_severity || $filter_type ? 'No alerts match the current filters.' : 'No alerts have been triggered yet.' ?></div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Alert</th>
                            <th>Device</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Triggered</th>
                            <th>Acknowledged</th>
                            <th>Resolved</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alerts as $a): ?>
                        <tr>
                            <td>
                                <div style="font-weight:500"><?= sanitize($a['title']) ?></div>
                                <div class="text-sm text-muted"><?= sanitize(mb_strimwidth($a['message'], 0, 50, '…')) ?></div>
                            </td>
                            <td class="text-sm"><?= $a['device_name'] ? sanitize($a['device_name']) : 'System' ?></td>
                            <td><?= status_badge($a['severity']) ?></td>
                            <td><?= status_badge($a['status']) ?></td>
                            <td class="text-sm"><?= format_datetime($a['triggered_at']) ?></td>
                            <td class="text-sm">
                                <?php if ($a['acknowledged_at']): ?>
                                    <?= format_datetime($a['acknowledged_at']) ?>
                                    <br><span class="text-muted">by <?= sanitize($a['acknowledged_by_name'] ?? '—') ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="text-sm"><?= $a['resolved_at'] ? format_datetime($a['resolved_at']) : '—' ?></td>
                            <td>
                                <?php if (can_edit()): ?>
                                    <?php if ($a['status'] === 'active'): ?>
                                        <?= alert_action_button($a['id'], 'acknowledge', 'Acknowledge') ?>
                                    <?php endif; ?>
                                    <?php if (in_array($a['status'], ['active', 'acknowledged'])): ?>
                                        <?= alert_action_button($a['id'], 'resolve', 'Resolve') ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted text-sm">—</span>
                                <?php endif; ?>
                            </td>
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
