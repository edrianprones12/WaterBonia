<?php
/**
 * WaterBonia - Analytics Page
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();

$page_title = 'Analytics';

$device = get_active_device();
$device_id = $device ? $device['id'] : null;

// Dispensing stats
$dispensing_total = $device_id ? db_count("SELECT COUNT(*) FROM dispensing_sessions WHERE device_id = ?", [$device_id]) : 0;
$dispensing_completed = $device_id ? db_count("SELECT COUNT(*) FROM dispensing_sessions WHERE device_id = ? AND status = 'completed'", [$device_id]) : 0;
$dispensing_avg_duration = $device_id
    ? db_fetch("SELECT AVG(duration_seconds) as avg_dur FROM dispensing_sessions WHERE device_id = ? AND duration_seconds IS NOT NULL", [$device_id])
    : null;

// Water level stats
$water_stats = $device_id
    ? db_fetch("SELECT AVG(water_level_percent) as avg_level, MIN(water_level_percent) as min_level, MAX(water_level_percent) as max_level FROM sensor_readings WHERE device_id = ? AND water_level_percent IS NOT NULL", [$device_id])
    : null;

// Alerts by severity
$alerts_by_severity = db_fetch_all("SELECT severity, COUNT(*) as cnt FROM alerts GROUP BY severity ORDER BY FIELD(severity, 'critical', 'warning', 'info')");

// Alerts by type
$alerts_by_type = db_fetch_all("SELECT alert_type, COUNT(*) as cnt FROM alerts GROUP BY alert_type ORDER BY cnt DESC LIMIT 8");

// Dispensing last 7 days
$dispensing_by_day = $device_id
    ? db_fetch_all("SELECT DATE(started_at) as day, COUNT(*) as cnt FROM dispensing_sessions WHERE device_id = ? AND started_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(started_at) ORDER BY day", [$device_id])
    : [];

// Device availability
$device_stats = db_fetch_all("SELECT status, COUNT(*) as cnt FROM devices GROUP BY status");

// Sensor readings last 24h
$sensor_trend = $device_id
    ? db_fetch_all("SELECT water_level_percent, recorded_at FROM sensor_readings WHERE device_id = ? AND water_level_percent IS NOT NULL AND recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY recorded_at", [$device_id])
    : [];

include __DIR__ . '/../includes/header.php';
?>

<?php if (!$device): ?>
<div class="dev-notice"><span>⚠</span><span><strong>Development Mode</strong> Analytics will be more meaningful once a device provides data.</span></div>
<?php endif; ?>

<div class="stats-grid mb-3">
    <div class="stat-card">
        <div class="stat-icon blue">📊</div>
        <div class="stat-content">
            <div class="stat-label">Total Dispensing Sessions</div>
            <div class="stat-value"><?= $dispensing_total ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">✅</div>
        <div class="stat-content">
            <div class="stat-label">Completed Sessions</div>
            <div class="stat-value"><?= $dispensing_completed ?></div>
            <div class="stat-sub"><?= $dispensing_total > 0 ? round($dispensing_completed / $dispensing_total * 100) . '% completion rate' : 'No data' ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow">⏱</div>
        <div class="stat-content">
            <div class="stat-label">Avg. Duration</div>
            <div class="stat-value"><?= $dispensing_avg_duration && $dispensing_avg_duration['avg_dur'] ? round($dispensing_avg_duration['avg_dur'], 1) . 's' : '—' ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">💧</div>
        <div class="stat-content">
            <div class="stat-label">Avg. Water Level</div>
            <div class="stat-value"><?= $water_stats && $water_stats['avg_level'] ? round($water_stats['avg_level']) . '%' : '—' ?></div>
            <div class="stat-sub"><?= $water_stats && $water_stats['min_level'] !== null ? $water_stats['min_level'] . '% – ' . $water_stats['max_level'] . '%' : 'No data' ?></div>
        </div>
    </div>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h3>Dispensing Activity (Last 7 Days)</h3></div>
        <div class="card-body">
            <div class="chart-container"><canvas id="dispensingChart"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Water-Level Trend (24h)</h3></div>
        <div class="card-body">
            <div class="chart-container"><canvas id="waterTrendChart"></canvas></div>
        </div>
    </div>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h3>Alerts by Severity</h3></div>
        <div class="card-body">
            <?php if (empty($alerts_by_severity)): ?>
                <div class="empty-state" style="padding:1.5rem">
                    <div class="empty-title">No Alerts</div>
                    <div class="empty-desc">No alert data available yet.</div>
                </div>
            <?php else: ?>
                <?php foreach ($alerts_by_severity as $row): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;border-bottom:1px solid var(--border-color)">
                        <div><?= status_badge($row['severity']) ?></div>
                        <div style="font-weight:600;font-size:1.1rem"><?= $row['cnt'] ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Alerts by Type</h3></div>
        <div class="card-body">
            <?php if (empty($alerts_by_type)): ?>
                <div class="empty-state" style="padding:1.5rem">
                    <div class="empty-title">No Alerts</div>
                    <div class="empty-desc">No alert data available yet.</div>
                </div>
            <?php else: ?>
                <?php foreach ($alerts_by_type as $row): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;border-bottom:1px solid var(--border-color)">
                        <div class="text-sm"><?= sanitize(ucwords(str_replace('_', ' ', $row['alert_type']))) ?></div>
                        <div style="font-weight:600"><?= $row['cnt'] ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Device Availability</h3></div>
    <div class="card-body">
        <?php if (empty($device_stats)): ?>
            <div class="empty-state" style="padding:1.5rem">
                <div class="empty-title">No Devices</div>
            </div>
        <?php else: ?>
            <?php foreach ($device_stats as $row): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;border-bottom:1px solid var(--border-color)">
                    <div><?= status_badge($row['status']) ?></div>
                    <div style="font-weight:600"><?= $row['cnt'] ?> device<?= $row['cnt'] > 1 ? 's' : '' ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Dispensing by day
    var dLabels = <?= json_encode(array_map(fn($r) => date('M d', strtotime($r['day'])), $dispensing_by_day)) ?>;
    var dData = <?= json_encode(array_map(fn($r) => $r['cnt'], $dispensing_by_day)) ?>;
    WBChart.line('dispensingChart', dLabels, dData, { color: '#16a34a', height: 200 });

    // Water trend
    var wLabels = <?= json_encode(array_map(fn($r) => date('H:i', strtotime($r['recorded_at'])), $sensor_trend)) ?>;
    var wData = <?= json_encode(array_map(fn($r) => $r['water_level_percent'], $sensor_trend)) ?>;
    WBChart.line('waterTrendChart', wLabels, wData, { color: '#0284c7', height: 200 });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
