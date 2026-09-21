<?php
/**
 * WaterBonia - Dashboard
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();

$page_title = 'Dashboard';
$device = get_active_device();

// Live sensor data from device
$latest_reading = null;
$water_level = null;
$cup_detected = false;
$hand_detected = false;
$pump_status = false;

if ($device) {
    $latest_reading = db_fetch(
        "SELECT * FROM sensor_readings WHERE device_id = ? ORDER BY recorded_at DESC LIMIT 1",
        [$device['id']]
    );
    if ($latest_reading) {
        $water_level = $latest_reading['water_level_percent'];
        $cup_detected = (bool)$latest_reading['cup_detected'];
        $hand_detected = (bool)$latest_reading['hand_detected'];
        $pump_status = (bool)$latest_reading['pump_status'];
    }
}

// Today's dispensing count
$today = date('Y-m-d');
$dispensing_today = $device
    ? db_count("SELECT COUNT(*) FROM dispensing_sessions WHERE device_id = ? AND DATE(started_at) = ?", [$device['id'], $today])
    : 0;

// Active alerts
$active_alerts = db_fetch_all("SELECT a.*, d.device_name FROM alerts a LEFT JOIN devices d ON a.device_id = d.id WHERE a.status = 'active' ORDER BY a.triggered_at DESC LIMIT 5");
$active_alert_count = active_alert_count();

// Recent activity
$recent_activity = db_fetch_all(
    "SELECT al.*, d.device_name FROM activity_logs al LEFT JOIN devices d ON al.device_id = d.id ORDER BY al.created_at DESC LIMIT 8"
);

// Recent dispensing sessions
$recent_sessions = $device
    ? db_fetch_all("SELECT * FROM dispensing_sessions WHERE device_id = ? ORDER BY started_at DESC LIMIT 5", [$device['id']])
    : [];

include __DIR__ . '/../includes/header.php';
?>

<?php if (!$device): ?>
<div class="dev-notice">
    <span>⚠</span>
    <span><strong>Development Mode</strong> No device is registered. Register an ESP32 device in the Devices page to begin receiving live data.</span>
</div>
<?php elseif (!$latest_reading): ?>
<div class="dev-notice">
    <span>ℹ</span>
    <span><strong>No Sensor Data</strong> Device "<?= sanitize($device['device_name']) ?>" has not sent any sensor readings yet.</span>
</div>
<?php endif; ?>

<!-- Summary Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon <?= $device && $device['status'] === 'online' ? 'green' : 'gray' ?>">
            <?= $device && $device['status'] === 'online' ? '🟢' : '⚫' ?>
        </div>
        <div class="stat-content">
            <div class="stat-label">System Status</div>
            <div class="stat-value" style="font-size:1.1rem">
                <?= $device ? ucfirst($device['status']) : 'No Device' ?>
            </div>
            <div class="stat-sub"><?= $device ? sanitize($device['device_name']) : 'No device registered' ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon blue">💧</div>
        <div class="stat-content">
            <div class="stat-label">Water Level</div>
            <div class="stat-value"><?= $water_level !== null ? $water_level . '%' : '—' ?></div>
            <div class="stat-sub"><?php
                if ($water_level !== null) {
                    $cls = water_level_class($water_level);
                    $label = $cls === 'water-normal' ? 'Normal' : ($cls === 'water-low' ? 'Low' : 'Critical');
                    echo $label;
                } else {
                    echo 'No data';
                }
            ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon <?= $pump_status ? 'green' : 'gray' ?>">
            <?= $pump_status ? '⚡' : '⏻' ?>
        </div>
        <div class="stat-content">
            <div class="stat-label">Pump Status</div>
            <div class="stat-value" style="font-size:1.1rem"><?= $pump_status ? 'ON' : 'OFF' ?></div>
            <div class="stat-sub"><?= $pump_status ? 'Actively pumping' : 'Standby' ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon <?= $cup_detected ? 'blue' : 'gray' ?>">☕</div>
        <div class="stat-content">
            <div class="stat-label">Cup Status</div>
            <div class="stat-value" style="font-size:1.1rem"><?= $cup_detected ? 'Detected' : 'Not Detected' ?></div>
            <div class="stat-sub"><?= $cup_detected ? 'Cup is in position' : 'No cup detected' ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon <?= $hand_detected ? 'yellow' : 'gray' ?>">🖐️</div>
        <div class="stat-content">
            <div class="stat-label">Hand Detection</div>
            <div class="stat-value" style="font-size:1.1rem"><?= $hand_detected ? 'Detected' : 'Not Detected' ?></div>
            <div class="stat-sub"><?= $hand_detected ? 'Hand detected' : 'No hand detected' ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon blue">📊</div>
        <div class="stat-content">
            <div class="stat-label">Today's Dispensing</div>
            <div class="stat-value"><?= $dispensing_today ?></div>
            <div class="stat-sub">sessions completed today</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon <?= $active_alert_count > 0 ? 'red' : 'green' ?>">🔔</div>
        <div class="stat-content">
            <div class="stat-label">Active Alerts</div>
            <div class="stat-value"><?= $active_alert_count ?></div>
            <div class="stat-sub"><?= $active_alert_count > 0 ? 'Requires attention' : 'All clear' ?></div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon gray">📡</div>
        <div class="stat-content">
            <div class="stat-label">Connected Devices</div>
            <div class="stat-value"><?= db_count("SELECT COUNT(*) FROM devices WHERE status = 'online'") ?></div>
            <div class="stat-sub">of <?= db_count("SELECT COUNT(*) FROM devices") ?> registered</div>
        </div>
    </div>
</div>

<!-- Water Level Gauge + Alerts -->
<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header">
            <h3>Water Level</h3>
            <?php if ($device): ?>
                <span class="text-sm text-muted">Last: <?= time_ago($latest_reading['recorded_at'] ?? null) ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body text-center">
            <?php if ($water_level !== null): ?>
                <div class="water-gauge <?= water_level_class($water_level) ?>" data-level="<?= $water_level ?>">
                    <div class="water-fill" style="height:0%"></div>
                    <span class="water-percent"><?= $water_level ?>%</span>
                </div>
                <p class="text-sm text-muted mt-2">
                    Low threshold: <?= get_setting('low_water_threshold', '20') ?>%
                </p>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">💧</div>
                    <div class="empty-title">No Water Data</div>
                    <div class="empty-desc">No sensor readings available yet. The device has not supplied telemetry.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Active Alerts</h3>
            <a href="<?= base_url('/pages/alerts.php') ?>" class="text-sm">View all →</a>
        </div>
        <div class="card-body-np">
            <?php if (empty($active_alerts)): ?>
                <div class="empty-state" style="padding:2rem">
                    <div class="empty-icon">✅</div>
                    <div class="empty-title">No Active Alerts</div>
                    <div class="empty-desc">All systems operating normally.</div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Alert</th>
                                <th>Severity</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_alerts as $alert): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:500"><?= sanitize($alert['title']) ?></div>
                                    <div class="text-sm text-muted"><?= sanitize($alert['device_name'] ?? 'System') ?></div>
                                </td>
                                <td><?= status_badge($alert['severity']) ?></td>
                                <td class="text-sm text-muted"><?= time_ago($alert['triggered_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Activity + Device Info -->
<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h3>Recent Activity</h3>
            <a href="<?= base_url('/pages/activity-logs.php') ?>" class="text-sm">View all →</a>
        </div>
        <div class="card-body-np">
            <?php if (empty($recent_activity)): ?>
                <div class="empty-state" style="padding:2rem">
                    <div class="empty-icon">📋</div>
                    <div class="empty-title">No Activity Yet</div>
                    <div class="empty-desc">Activity will appear once the system is operational.</div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Level</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activity as $log): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:500"><?= sanitize($log['event_type']) ?></div>
                                    <div class="text-sm text-muted"><?= mb_strimwidth(sanitize($log['message']), 0, 60, '…') ?></div>
                                </td>
                                <td><?= event_level_badge($log['event_level']) ?></td>
                                <td class="text-sm text-muted"><?= time_ago($log['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Device Information</h3>
            <a href="<?= base_url('/pages/device-status.php') ?>" class="text-sm">Details →</a>
        </div>
        <div class="card-body">
            <?php if ($device): ?>
                <table style="width:100%">
                    <tr><td class="text-muted" style="width:40%">Device Name</td><td style="font-weight:500"><?= sanitize($device['device_name']) ?></td></tr>
                    <tr><td class="text-muted">Device Code</td><td><?= sanitize($device['device_code']) ?></td></tr>
                    <tr><td class="text-muted">ESP32 ID</td><td><?= sanitize($device['esp32_identifier']) ?></td></tr>
                    <tr><td class="text-muted">Status</td><td><?= status_badge($device['status']) ?></td></tr>
                    <tr><td class="text-muted">Firmware</td><td><?= sanitize($device['firmware_version'] ?: 'Unknown') ?></td></tr>
                    <tr><td class="text-muted">Last Seen</td><td><?= time_ago($device['last_seen_at']) ?></td></tr>
                </table>
            <?php else: ?>
                <div class="empty-state" style="padding:1.5rem">
                    <div class="empty-icon">📡</div>
                    <div class="empty-title">No Device Registered</div>
                    <div class="empty-desc">Register an ESP32 device to begin monitoring.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Dispensing Sessions -->
<?php if (!empty($recent_sessions)): ?>
<div class="card mt-2">
    <div class="card-header">
        <h3>Recent Dispensing Sessions</h3>
        <a href="<?= base_url('/pages/dispensing.php') ?>" class="text-sm">View all →</a>
    </div>
    <div class="card-body-np">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Started</th>
                        <th>Duration</th>
                        <th>Water Before</th>
                        <th>Water After</th>
                        <th>Volume</th>
                        <th>Stop Reason</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_sessions as $s): ?>
                    <tr>
                        <td class="text-sm"><?= format_datetime($s['started_at']) ?></td>
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
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
