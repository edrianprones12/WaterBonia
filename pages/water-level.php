<?php
/**
 * WaterBonia - Water Level Page
 */
require_once __DIR__ . '/../includes/functions.php';

$page_title = 'Water Level';
$device = get_active_device();

$latest = null;
$water_level = null;
$low_threshold = (int)get_setting('low_water_threshold', '20');
$readings = [];

if ($device) {
    $latest = db_fetch(
        "SELECT * FROM sensor_readings WHERE device_id = ? AND water_level_percent IS NOT NULL ORDER BY recorded_at DESC LIMIT 1",
        [$device['id']]
    );
    if ($latest) {
        $water_level = $latest['water_level_percent'];
    }
    $readings = db_fetch_all(
        "SELECT water_level_percent, recorded_at FROM sensor_readings WHERE device_id = ? AND water_level_percent IS NOT NULL ORDER BY recorded_at DESC LIMIT 20",
        [$device['id']]
    );
    $readings = array_reverse($readings);
}

// Determine status
$water_status = 'No Data';
$status_class = '';
if ($water_level !== null) {
    if ($water_level <= 10) { $water_status = 'Critical'; $status_class = 'badge-danger'; }
    elseif ($water_level <= $low_threshold) { $water_status = 'Low'; $status_class = 'badge-warning'; }
    else { $water_status = 'Normal'; $status_class = 'badge-success'; }
}

include __DIR__ . '/../includes/header.php';
?>

<?php if (!$device): ?>
<div class="dev-notice"><span>⚠</span><span><strong>No Device Registered</strong> Water-level readings will appear once an ESP32 device sends telemetry.</span></div>
<?php endif; ?>

<div class="stats-grid mb-3">
    <div class="stat-card">
        <div class="stat-icon blue">💧</div>
        <div class="stat-content">
            <div class="stat-label">Current Water Level</div>
            <div class="stat-value"><?= $water_level !== null ? $water_level . '%' : '—' ?></div>
            <div class="stat-sub"><?= $water_status ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow">⚠</div>
        <div class="stat-content">
            <div class="stat-label">Low-Water Threshold</div>
            <div class="stat-value"><?= $low_threshold ?>%</div>
            <div class="stat-sub">Alert triggers below this</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon <?= $water_level !== null ? ($water_status === 'Normal' ? 'green' : ($water_status === 'Low' ? 'yellow' : 'red')) : 'gray' ?>">
            <?= $water_level !== null ? ($water_status === 'Normal' ? '✅' : ($water_status === 'Low' ? '⚠' : '🔴')) : '—' ?>
        </div>
        <div class="stat-content">
            <div class="stat-label">Tank Status</div>
            <div class="stat-value" style="font-size:1.1rem"><?= $water_level !== null ? $water_status : 'Unknown' ?></div>
            <div class="stat-sub"><?= $water_level !== null ? ($water_level <= $low_threshold ? 'Refill recommended' : 'Sufficient water') : 'No readings available' ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon gray">🕐</div>
        <div class="stat-content">
            <div class="stat-label">Last Reading</div>
            <div class="stat-value" style="font-size:1rem"><?= $latest ? format_datetime($latest['recorded_at']) : '—' ?></div>
            <div class="stat-sub"><?= $latest ? time_ago($latest['recorded_at']) : 'No readings yet' ?></div>
        </div>
    </div>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h3>Water Level</h3></div>
        <div class="card-body text-center">
            <?php if ($water_level !== null): ?>
                <div class="water-gauge <?= water_level_class($water_level) ?>" data-level="<?= $water_level ?>">
                    <div class="water-fill" style="height:0%"></div>
                    <span class="water-percent"><?= $water_level ?>%</span>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">💧</div>
                    <div class="empty-title">No Water-Level Data</div>
                    <div class="empty-desc">No water-level readings available yet. The device has not supplied telemetry.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Water-Level Trend</h3></div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="waterTrendChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Recent Water-Level Readings</h3></div>
    <div class="card-body-np">
        <?php if (empty($readings)): ?>
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                <div class="empty-title">No Readings</div>
                <div class="empty-desc">Water-level readings will appear here once the device reports sensor data.</div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Time</th><th>Water Level</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach (array_reverse($readings) as $r): ?>
                        <tr>
                            <td><?= format_datetime($r['recorded_at']) ?></td>
                            <td><?= $r['water_level_percent'] ?>%</td>
                            <td>
                                <?php
                                    $pct = $r['water_level_percent'];
                                    if ($pct <= 10) echo '<span class="badge badge-danger">Critical</span>';
                                    elseif ($pct <= $low_threshold) echo '<span class="badge badge-warning">Low</span>';
                                    else echo '<span class="badge badge-success">Normal</span>';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var labels = <?= json_encode(array_map(fn($r) => date('H:i', strtotime($r['recorded_at'])), $readings)) ?>;
    var data = <?= json_encode(array_map(fn($r) => $r['water_level_percent'], $readings)) ?>;
    WBChart.line('waterTrendChart', labels, data, { color: '#0284c7', height: 220 });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
