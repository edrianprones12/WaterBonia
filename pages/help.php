<?php
/**
 * WaterBonia - Help / About Page
 */
require_once __DIR__ . '/../includes/functions.php';

$page_title = 'Help / About';

include __DIR__ . '/../includes/header.php';
?>

<div class="card mb-3">
    <div class="card-header"><h3>About WaterBonia</h3></div>
    <div class="card-body">
        <p style="font-size:1rem;margin-bottom:1rem">
            <strong>WaterBonia</strong> is an IoT-based smart water dispenser monitoring and management system.
            It provides real-time monitoring of water levels, dispensing operations, and device health through a professional web dashboard.
        </p>
        <p class="text-muted">Version 0.1.0-dev</p>
    </div>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h3>How the System Works</h3></div>
        <div class="card-body">
            <ol style="padding-left:1.2rem;line-height:2">
                <li>An <strong>ESP32 microcontroller</strong> is connected to sensors (ultrasonic water level, IR hand/cup detection, pump relay).</li>
                <li>The ESP32 reads sensor data and communicates via <strong>Wi-Fi</strong>.</li>
                <li>Sensor data is sent to the <strong>WaterBonia server</strong> through a PHP API.</li>
                <li>The <strong>web dashboard</strong> displays real-time and historical data.</li>
                <li><strong>Alerts</strong> are generated when conditions require attention (low water, device offline, etc.).</li>
            </ol>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Sensor Overview</h3></div>
        <div class="card-body">
            <table style="width:100%">
                <tr><td style="font-weight:500;padding:0.4rem 0">Water Level</td><td class="text-sm">Measures tank water level (0-100%)</td></tr>
                <tr><td style="font-weight:500;padding:0.4rem 0">Cup Detection</td><td class="text-sm">IR sensor detects cup placement</td></tr>
                <tr><td style="font-weight:500;padding:0.4rem 0">Hand Detection</td><td class="text-sm">IR/proximity sensor detects hand presence</td></tr>
                <tr><td style="font-weight:500;padding:0.4rem 0">Pump Control</td><td class="text-sm">Relay controls water pump on/off</td></tr>
                <tr><td style="font-weight:500;padding:0.4rem 0">Battery (Optional)</td><td class="text-sm">Monitors battery level if battery-powered</td></tr>
            </table>
        </div>
    </div>
</div>

<div class="grid-2 mb-3">
    <div class="card">
        <div class="card-header"><h3>Dispensing Workflow</h3></div>
        <div class="card-body">
            <ol style="padding-left:1.2rem;line-height:2">
                <li>User places a cup under the dispenser.</li>
                <li>Cup is detected by the IR sensor.</li>
                <li>User places hand near the sensor area.</li>
                <li>Hand is detected — pump activates.</li>
                <li>Water dispenses into the cup.</li>
                <li>Hand or cup is removed — pump stops.</li>
                <li>Session is recorded in the database.</li>
            </ol>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Device Monitoring</h3></div>
        <div class="card-body">
            <ul style="padding-left:1.2rem;line-height:2">
                <li><strong>Online/Offline status</strong> tracked via last communication time.</li>
                <li><strong>Firmware version</strong> displayed for update tracking.</li>
                <li><strong>IP address</strong> and <strong>Wi-Fi SSID</strong> shown for network diagnostics.</li>
                <li><strong>Activity logs</strong> record all device events.</li>
                <li><strong>Alerts</strong> notify when devices go offline or encounter errors.</li>
            </ul>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Development Status</h3></div>
    <div class="card-body">
        <table style="width:100%">
            <tr>
                <td style="font-weight:500;padding:0.5rem 0;width:40%">Database Schema</td>
                <td><span class="badge badge-success">Complete</span></td>
            </tr>
            <tr>
                <td style="font-weight:500;padding:0.5rem 0">Web Dashboard</td>
                <td><span class="badge badge-success">Foundation</span></td>
            </tr>
            <tr>
                <td style="font-weight:500;padding:0.5rem 0">Authentication</td>
                <td><span class="badge badge-success">Complete</span></td>
            </tr>
            <tr>
                <td style="font-weight:500;padding:0.5rem 0">PHP API for ESP32</td>
                <td><span class="badge badge-warning">Planned</span></td>
            </tr>
            <tr>
                <td style="font-weight:500;padding:0.5rem 0">ESP32 Firmware</td>
                <td><span class="badge badge-warning">Planned</span></td>
            </tr>
            <tr>
                <td style="font-weight:500;padding:0.5rem 0">Real-time Updates</td>
                <td><span class="badge badge-warning">Planned</span></td>
            </tr>
        </table>
        <p class="text-sm text-muted mt-2">
            The web dashboard currently displays development data. Actual ESP32 integration is a future stage of development.
            Once the ESP32 firmware and PHP API are implemented, the dashboard will display live sensor data.
        </p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
