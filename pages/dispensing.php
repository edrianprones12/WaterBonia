<?php
/**
 * WaterBonia - Dispensing Page
 */
require_once __DIR__ . '/../includes/functions.php';

$page_title = 'Dispensing';
$device = get_active_device();

$active_session = null;
$sessions = [];
$session_count = 0;
$latest_reading = null;
$container_detected = false;
$pump_status = false;

if ($device) {
    $active_session = db_fetch(
        "SELECT * FROM dispensing_sessions WHERE device_id = ? AND status = 'started' ORDER BY started_at DESC LIMIT 1",
        [$device['id']]
    );
    $session_count = db_count("SELECT COUNT(*) FROM dispensing_sessions WHERE device_id = ?", [$device['id']]);
    $sessions = db_fetch_all(
        "SELECT * FROM dispensing_sessions WHERE device_id = ? ORDER BY started_at DESC LIMIT 20",
        [$device['id']]
    );
    $latest_reading = db_fetch(
        "SELECT * FROM sensor_readings WHERE device_id = ? ORDER BY recorded_at DESC LIMIT 1",
        [$device['id']]
    );
    if ($latest_reading) {
        $container_detected = !empty($latest_reading['container_detected'])
            || !empty($latest_reading['cup_detected']);
        $pump_status = (bool)$latest_reading['pump_status'];
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?php if (!$device): ?>
<div class="dev-notice"><span>⚠</span><span><strong>No Device Registered</strong> Dispensing data will appear once an ESP32 device begins reporting sessions.</span></div>
<?php endif; ?>

<div class="stats-grid mb-3">
    <div class="stat-card">
        <div class="stat-icon <?= $active_session ? 'green' : 'gray' ?>">🔄</div>
        <div class="stat-content">
            <div class="stat-label">Dispensing State</div>
            <div class="stat-value" style="font-size:1.1rem"><?= $active_session ? 'Dispensing' : 'Idle' ?></div>
            <div class="stat-sub"><?= $active_session ? 'Session active' : 'No active session' ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?= $container_detected ? 'blue' : 'gray' ?>">🥤</div>
        <div class="stat-content">
            <div class="stat-label">Container</div>
            <div class="stat-value" style="font-size:1.1rem"><?= $container_detected ? 'Detected' : 'Not Detected' ?></div>
            <div class="stat-sub">presence sensor</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">⚖</div>
        <div class="stat-content">
            <div class="stat-label">Measured volume (not fitted)</div>
            <div class="stat-value" style="font-size:1.1rem">
                <?= $active_session && ($active_session['measured_amount_ml'] ?? null) !== null
                    ? (int)$active_session['measured_amount_ml'] . ' mL'
                    : '—' ?>
            </div>
            <div class="stat-sub">target
                <?= $active_session && ($active_session['target_amount_ml'] ?? null) !== null
                    ? (int)$active_session['target_amount_ml'] . ' mL'
                    : '—' ?>
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?= $pump_status ? 'green' : 'gray' ?>">⚡</div>
        <div class="stat-content">
            <div class="stat-label">Pump Status</div>
            <div class="stat-value" style="font-size:1.1rem"><?= $pump_status ? 'ON' : 'OFF' ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">📊</div>
        <div class="stat-content">
            <div class="stat-label">Total Sessions</div>
            <div class="stat-value"><?= $session_count ?></div>
            <div class="stat-sub">all time</div>
        </div>
    </div>
</div>

<?php if ($active_session): ?>
<div class="card mb-3">
    <div class="card-header">
        <h3>Current Dispensing Session</h3>
        <span class="badge badge-info">Active</span>
    </div>
    <div class="card-body">
        <table style="width:100%">
            <tr><td class="text-muted" style="width:35%">Session ID</td><td>#<?= $active_session['id'] ?></td></tr>
            <tr><td class="text-muted">Backend authorization time</td><td><?= format_datetime($active_session['started_at']) ?></td></tr>
            <tr><td class="text-muted">Container Type</td><td><?= ($active_session['container_type'] ?? null) ? sanitize(ucfirst($active_session['container_type'])) : '—' ?></td></tr>
            <tr><td class="text-muted">Container Detected</td><td><?= !empty($active_session['container_detected']) || !empty($active_session['cup_detected']) ? 'Yes' : 'No' ?></td></tr>
            <tr><td class="text-muted">Target</td><td><?= ($active_session['target_amount_ml'] ?? null) !== null ? (int)$active_session['target_amount_ml'] . ' mL' : '—' ?></td></tr>
            <tr><td class="text-muted">Historical measured volume</td><td><?= ($active_session['measured_amount_ml'] ?? null) !== null ? (int)$active_session['measured_amount_ml'] . ' mL' : 'Not measured' ?></td></tr>
            <tr><td class="text-muted">Water Level Before</td><td><?= $active_session['water_level_before'] !== null ? $active_session['water_level_before'] . '%' : '—' ?></td></tr>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3>Dispensing History</h3></div>
    <div class="card-body-np">
        <?php if (empty($sessions)): ?>
            <div class="empty-state">
                <div class="empty-icon">🔄</div>
                <div class="empty-title">No Dispensing Sessions</div>
                <div class="empty-desc">Sessions will appear here once the device begins dispensing operations.</div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Started</th>
                            <th>Container</th>
                            <th>Nominal target</th>
                            <th>Historical measured volume</th>
                            <th>Duration</th>
                            <th>Stop Reason</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td>#<?= $s['id'] ?></td>
                            <td class="text-sm"><?= format_datetime($s['started_at']) ?></td>
                            <td><?= ($s['container_type'] ?? null) ? sanitize(ucfirst($s['container_type'])) : '—' ?></td>
                            <td><?= ($s['target_amount_ml'] ?? null) !== null ? (int)$s['target_amount_ml'] . ' mL' : '—' ?></td>
                            <td><?= ($s['measured_amount_ml'] ?? null) !== null
                                ? (int)$s['measured_amount_ml'] . ' mL'
                                : (($s['amount_dispensed_ml'] ?? null) !== null ? (int)$s['amount_dispensed_ml'] . ' mL' : '—') ?></td>
                            <td><?= $s['duration_seconds'] !== null ? $s['duration_seconds'] . 's' : '—' ?></td>
                            <td class="text-sm"><?= $s['stop_reason'] ? ucwords(str_replace('_', ' ', $s['stop_reason'])) : '—' ?></td>
                            <td><?= status_badge($s['status']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
