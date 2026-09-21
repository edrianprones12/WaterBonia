<?php
/**
 * WaterBonia - Dispensing Page
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();

$page_title = 'Dispensing';
$device = get_active_device();

$active_session = null;
$sessions = [];
$session_count = 0;
$latest_reading = null;
$cup_detected = false;
$hand_detected = false;
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
        $cup_detected = (bool)$latest_reading['cup_detected'];
        $hand_detected = (bool)$latest_reading['hand_detected'];
        $pump_status = (bool)$latest_reading['pump_status'];
    }
}

include __DIR__ . '/../includes/header.php';
?>

<?php if (!$device): ?>
<div class="dev-notice"><span>⚠</span><span><strong>Development Mode</strong> No device is registered. Dispensing data will appear once an ESP32 device begins reporting sessions.</span></div>
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
        <div class="stat-icon <?= $cup_detected ? 'blue' : 'gray' ?>">☕</div>
        <div class="stat-content">
            <div class="stat-label">Cup Detection</div>
            <div class="stat-value" style="font-size:1.1rem"><?= $cup_detected ? 'Detected' : 'Not Detected' ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?= $hand_detected ? 'yellow' : 'gray' ?>">🖐️</div>
        <div class="stat-content">
            <div class="stat-label">Hand Detection</div>
            <div class="stat-value" style="font-size:1.1rem"><?= $hand_detected ? 'Detected' : 'Not Detected' ?></div>
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
            <tr><td class="text-muted">Started At</td><td><?= format_datetime($active_session['started_at']) ?></td></tr>
            <tr><td class="text-muted">Cup Detected</td><td><?= $active_session['cup_detected'] ? 'Yes' : 'No' ?></td></tr>
            <tr><td class="text-muted">Hand Detected</td><td><?= $active_session['hand_detected'] ? 'Yes' : 'No' ?></td></tr>
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
                            <th>Ended</th>
                            <th>Duration</th>
                            <th>Water Before</th>
                            <th>Water After</th>
                            <th>Volume</th>
                            <th>Stop Reason</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td>#<?= $s['id'] ?></td>
                            <td class="text-sm"><?= format_datetime($s['started_at']) ?></td>
                            <td class="text-sm"><?= $s['ended_at'] ? format_datetime($s['ended_at']) : '—' ?></td>
                            <td><?= $s['duration_seconds'] !== null ? $s['duration_seconds'] . 's' : '—' ?></td>
                            <td><?= $s['water_level_before'] !== null ? $s['water_level_before'] . '%' : '—' ?></td>
                            <td><?= $s['water_level_after'] !== null ? $s['water_level_after'] . '%' : '—' ?></td>
                            <td><?= $s['amount_dispensed_ml'] !== null ? $s['amount_dispensed_ml'] . ' ml' : '—' ?></td>
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
