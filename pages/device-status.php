<?php
/**
 * WaterBonia - Device Status Page
 */
require_once __DIR__ . '/../includes/functions.php';

$page_title = 'Device Status';
$device = get_active_device();

$latest_reading = null;
if ($device) {
    $latest_reading = db_fetch(
        "SELECT * FROM sensor_readings WHERE device_id = ? ORDER BY recorded_at DESC LIMIT 1",
        [$device['id']]
    );
}

include __DIR__ . '/../includes/header.php';
?>

<?php if (!$device): ?>
<div class="dev-notice"><span>⚠</span><span><strong>No Device Registered</strong> Register an ESP32 device to begin monitoring.</span></div>

<div class="empty-state">
    <div class="empty-icon">📡</div>
    <div class="empty-title">No Device Registered</div>
    <div class="empty-desc">Register an ESP32 device in the Devices page to view its status here.</div>
    <a href="<?= base_url('/pages/devices.php') ?>" class="btn btn-primary mt-2">Go to Devices</a>
</div>
<?php else: ?>

<div class="stats-grid mb-3">
    <div class="stat-card">
        <div class="stat-icon <?= $device['status'] === 'online' ? 'green' : 'gray' ?>">
            <?= $device['status'] === 'online' ? '🟢' : ($device['status'] === 'maintenance' ? '🔧' : '⚫') ?>
        </div>
        <div class="stat-content">
            <div class="stat-label">Device Status</div>
            <div class="stat-value" style="font-size:1.1rem"><?= status_badge($device['status']) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">📡</div>
        <div class="stat-content">
            <div class="stat-label">Last Communication</div>
            <div class="stat-value" style="font-size:1rem"><?= $device['last_seen_at'] ? format_datetime($device['last_seen_at']) : 'Never' ?></div>
            <div class="stat-sub"><?= time_ago($device['last_seen_at']) ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon gray">🔧</div>
        <div class="stat-content">
            <div class="stat-label">Firmware Version</div>
            <div class="stat-value" style="font-size:1.1rem"><?= sanitize($device['firmware_version'] ?: 'Unknown') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon gray">📶</div>
        <div class="stat-content">
            <div class="stat-label">IP Address</div>
            <div class="stat-value" style="font-size:1rem"><?= sanitize($device['ip_address'] ?: 'Not connected') ?></div>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Device Details</h3></div>
        <div class="card-body">
            <table style="width:100%">
                <tr><td class="text-muted" style="width:40%">Device Name</td><td style="font-weight:500"><?= sanitize($device['device_name']) ?></td></tr>
                <tr><td class="text-muted">Device Code</td><td><code><?= sanitize($device['device_code']) ?></code></td></tr>
                <tr><td class="text-muted">ESP32 Identifier</td><td><code><?= sanitize($device['esp32_identifier']) ?></code></td></tr>
                <tr><td class="text-muted">Location</td><td><?= sanitize($device['location'] ?: 'Not specified') ?></td></tr>
                <tr><td class="text-muted">Status</td><td><?= status_badge($device['status']) ?></td></tr>
                <tr><td class="text-muted">Firmware Version</td><td><?= sanitize($device['firmware_version'] ?: 'Unknown') ?></td></tr>
                <tr><td class="text-muted">IP Address</td><td><?= sanitize($device['ip_address'] ?: 'Not connected') ?></td></tr>
                <tr><td class="text-muted">Wi-Fi SSID</td><td><?= sanitize($device['wifi_ssid'] ?: 'Not configured') ?></td></tr>
                <tr><td class="text-muted">Last Seen</td><td><?= format_datetime($device['last_seen_at']) ?></td></tr>
                <tr><td class="text-muted">Registered</td><td><?= format_datetime($device['created_at']) ?></td></tr>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Latest Sensor Data</h3></div>
        <div class="card-body">
            <?php if ($latest_reading): ?>
                <table style="width:100%">
                    <tr><td class="text-muted" style="width:40%">Water Level</td><td><?= $latest_reading['water_level_percent'] !== null ? $latest_reading['water_level_percent'] . '%' : '—' ?></td></tr>
                    <tr><td class="text-muted">Container Detected</td><td><?= !empty($latest_reading['container_detected']) || !empty($latest_reading['cup_detected']) ? 'Yes' : 'No' ?></td></tr>
                    <tr><td class="text-muted">Hand (diagnostic)</td><td><?= !empty($latest_reading['hand_detected']) ? 'Yes' : 'No' ?></td></tr>
                    <tr><td class="text-muted">Pump Status</td><td><?= $latest_reading['pump_status'] ? 'ON' : 'OFF' ?></td></tr>
                    <tr><td class="text-muted">Battery</td><td><?= $latest_reading['battery_percent'] !== null ? $latest_reading['battery_percent'] . '%' : '—' ?></td></tr>
                    <tr><td class="text-muted">Recorded At</td><td><?= format_datetime($latest_reading['recorded_at']) ?></td></tr>
                </table>
            <?php else: ?>
                <div class="empty-state" style="padding:1.5rem">
                    <div class="empty-icon">📊</div>
                    <div class="empty-title">No Sensor Data</div>
                    <div class="empty-desc">The device has not sent any sensor readings yet.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
