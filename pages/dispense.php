<?php
/** WaterBonia execution portal: public, kiosk-facing dispensing flow. */
require_once __DIR__ . '/../includes/functions.php';

// A backend outage must leave the public portal unavailable, without exposing
// connection errors or presenting fallback hardware readings.
set_exception_handler(function (Throwable $error): void {
    http_response_code(503);
    $page_title = 'Water Dispensing System';
    $availability = ['tone' => 'warning', 'label' => 'SYSTEM UNAVAILABLE', 'hint' => 'Dispenser state is temporarily unavailable.'];
    include __DIR__ . '/../includes/portal-header.php';
    echo '<div class="execution-notice is-error">Dispenser state is temporarily unavailable. Dispensing is blocked. Please try again later.</div>';
    usort($portal_navigation_items, fn(array $a, array $b): int => ($a['anchor'] === 'activity-logs') <=> ($b['anchor'] === 'activity-logs'));
    foreach ($portal_navigation_items as $item) {
        echo '<section class="portal-section portal-content-card" id="' . $item['anchor'] . '" data-portal-section="' . $item['anchor'] . '" data-portal-title="' . sanitize($item['label']) . '" tabindex="-1"' . ($item['anchor'] === 'dispense-workflow' ? '' : ' hidden') . '><div class="portal-view-heading"><h1>' . sanitize($item['label']) . '</h1><p>' . ($item['anchor'] === 'activity-logs' ? 'Activity records are temporarily unavailable.' : 'This section is temporarily unavailable. Please try again later.') . '</p></div></section>';
    }
    include __DIR__ . '/../includes/portal-footer.php';
});

$demo = WATERBONIA_DEMO_MODE;
if (is_post() && !verify_csrf()) {
    http_response_code(403);
    exit('Invalid CSRF token. Reload the portal and try again.');
}
$device = $demo ? null : get_active_device();
$actor_id = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
if (is_post() && isset($_POST['action'])) {
    if ($_POST['action'] === 'request_dispense') {
        $ctype = is_string($_POST['container_type'] ?? null) ? $_POST['container_type'] : '';
        [$threshold_ok, $threshold_msg] = validate_water_threshold($ctype, $_POST['threshold_ml'] ?? null);
        [$ok, $msg, $command_id] = $threshold_ok
            ? request_dispense((int) ($device['id'] ?? 0), $ctype, $actor_id, $_POST['threshold_ml'])
            : [false, $threshold_msg, null];
        if ($ok) $_SESSION['portal_command_id'] = $command_id;
        $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg];
        redirect('/pages/dispense.php');
    }
    if (!$demo && $_POST['action'] === 'cancel_command' && portal_integer($_POST['command_id'] ?? null)
        && portal_integer($_POST['command_id']) === portal_integer($_SESSION['portal_command_id'] ?? null)) {
        $cmd = db_fetch("SELECT * FROM device_commands WHERE id = ? AND status = 'pending'", [portal_integer($_POST['command_id'])]);
        $_SESSION['flash'] = ['ok' => !!($cmd && $device && $cmd['device_id'] == $device['id']), 'msg' => 'Command no longer pending.'];
        if ($cmd && $device && $cmd['device_id'] == $device['id']) {
            $cancelled = db_update('device_commands', ['status' => 'cancelled', 'completed_at' => date('Y-m-d H:i:s')], "id = ? AND device_id = ? AND status = 'pending'", [$cmd['id'], $device['id']]);
            $_SESSION['flash'] = ['ok' => $cancelled === 1, 'msg' => $cancelled === 1 ? 'Dispensing request cancelled.' : 'Command no longer pending.'];
            if ($cancelled === 1) log_event((int) $device['id'], $actor_id, 'dispensing_request_cancelled', 'info', 'The user cancelled a pending dispensing request.');
        }
        redirect('/pages/dispense.php');
    }
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$live = get_live_dispenser_state($device);
$target_cup = container_target_ml('cup'); $target_tumbler = container_target_ml('tumbler');
$thresholds = portal_threshold_config();
$selected_thresholds = portal_selected_thresholds();
$activities = $demo ? [] : portal_activity_records($device ? (int) $device['id'] : null);
$availability = $demo
    ? ['tone' => 'success', 'label' => 'SYSTEM AVAILABLE', 'hint' => 'Demo prototype is ready for presentation.']
    : dispenser_availability($device, $live);
$led_state = $demo ? demo_led('blue') : dispenser_led($device, $live);
$page_title = 'Water Dispensing System';
include __DIR__ . '/../includes/portal-header.php';
?>
<div class="execution-page">
    <noscript><div class="execution-notice">Enable JavaScript to use the dispensing controls and live activity display.</div></noscript>
    <?php if (!$demo && !$device): ?><div class="execution-notice">No device is registered, so dispensing is unavailable.</div><?php endif; ?>
    <?php if ($flash): ?><div class="execution-notice <?= $flash['ok'] ? 'is-success' : 'is-error' ?>"><?= sanitize($flash['msg']) ?></div><?php endif; ?>
    <section class="portal-section" id="dispense-workflow" data-portal-section="dispense-workflow" data-portal-title="Dispense Water" aria-label="Dispense Water" tabindex="-1">
    <nav class="execution-stepper" id="dispense-steps" aria-label="Dispensing progress">
        <div class="dispense-step" data-step="1"><span class="step-num">1</span><span class="step-label">Choose</span></div><span class="step-connector" data-step="2"></span>
        <div class="dispense-step" data-step="2"><span class="step-num">2</span><span class="step-label">Place</span></div><span class="step-connector" data-step="3"></span>
        <div class="dispense-step" data-step="3"><span class="step-num">3</span><span class="step-label">Dispense</span></div><span class="step-connector" data-step="4"></span>
        <div class="dispense-step" data-step="4"><span class="step-num">4</span><span class="step-label">Complete</span></div>
    </nav>
        <section class="execution-primary" aria-live="polite">
            <section class="dispense-panel" data-panel="1">
                <span class="execution-kicker">STEP 1 — CHOOSE</span><h1>What would you like to fill?</h1><p class="execution-lede">Choose your container to set the dispensing amount.</p>
                <div class="container-options" role="radiogroup" aria-label="Container type">
                    <label class="execution-container-card container-card" data-container="cup"><input type="radio" name="container_type" value="cup" class="sr-only"><span class="container-art" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3"><path d="M14 17h31v30a9 9 0 0 1-9 9H23a9 9 0 0 1-9-9V17Z"/><path d="M45 24h4a8 8 0 0 1 0 16h-4"/><path d="M11 17h37"/></svg></span><span class="container-name">Cup</span><strong class="container-volume" id="cup-target-volume"><?= $selected_thresholds['cup'] !== null ? (int) $selected_thresholds['cup'] . ' mL' : 'Not configured' ?></strong><span class="container-help">Select cup</span><span class="card-check">✓</span></label>
                    <label class="execution-container-card container-card" data-container="tumbler"><input type="radio" name="container_type" value="tumbler" class="sr-only"><span class="container-art" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3"><path d="M18 11h28l-3 44a7 7 0 0 1-7 6H28a7 7 0 0 1-7-6l-3-44Z"/><path d="M17 18h30"/><path d="M23 29h18"/></svg></span><span class="container-name">Tumbler</span><strong class="container-volume" id="tumbler-target-volume"><?= $selected_thresholds['tumbler'] !== null ? (int) $selected_thresholds['tumbler'] . ' mL' : 'Not configured' ?></strong><span class="container-help">Select tumbler</span><span class="card-check">✓</span></label>
                </div><p class="execution-footnote">Adjust the dispensing threshold in the next step or in Container Settings to match your container's size.</p>
            </section>
            <section class="dispense-panel" data-panel="2" hidden>
                <span class="execution-kicker">STEP 2 — PLACE</span><h1 id="place-heading">Place your container</h1><p class="execution-lede" id="place-description">Place your selected cup on the dispenser platform.</p>
                <div class="execution-state-grid" id="place-indicator"><article class="execution-info-tile"><span class="tile-label">SELECTED CONTAINER</span><strong><span class="check-mark">✓</span> <span id="place-container-name">Cup</span></strong><span id="place-container-target">Target: <?= $target_cup ?> mL</span></article><article class="execution-info-tile sensor-tile"><span class="tile-label">CONTAINER STATUS</span><strong><span class="status-dot"></span> <span id="container-sensor-status">Waiting for container</span></strong><span>Presence sensor on dispenser platform</span></article></div>
                <div class="execution-threshold"><label for="water-threshold-select">Dispensing threshold (mL) <span id="threshold-container-name">Cup</span></label><input type="number" id="water-threshold-select" min="1" max="2000" step="1" aria-describedby="threshold-help" disabled><p id="threshold-help">Dispense <strong id="threshold-value">—</strong> mL into this container. Adjust this amount to match its size.</p></div>
                <p class="dispense-hint" id="dispense-hint">Place a container to continue.</p><div class="execution-place-actions"><button type="button" class="execution-dispense-button" id="dispense-btn" disabled>DISPENSE WATER</button>
                <button type="button" class="execution-change-container" id="change-container-btn">Change container</button></div>
            </section>
            <section class="dispense-panel" data-panel="3" hidden>
                <span class="execution-kicker">STEP 3 — DISPENSE</span><h1>Dispensing...</h1><p class="execution-lede" id="dispense-description">WaterBonia stops automatically at the target amount.</p>
                <div class="execution-state-grid compact"><article class="execution-info-tile"><span class="tile-label">SELECTED CONTAINER</span><strong id="dispense-container">Cup</strong><span id="dispense-target-label">Target: <?= $target_cup ?> mL</span></article><article class="execution-info-tile sensor-tile"><span class="tile-label">CONTAINER STATUS</span><strong id="dispense-sensor-indicator"><span class="status-dot"></span> <span id="dispense-sensor-status">Container status unavailable</span></strong><span id="dispense-sensor-note">Waiting for valid device status</span></article></div>
                <div class="execution-progress-card" id="dispense-progress"><div><span class="tile-label">CURRENT</span><strong class="current-volume"><span id="measured-value">0</span> mL <small>/ <span id="target-value"><?= $target_cup ?></span> mL</small></strong></div><strong class="progress-percent" id="progress-percent">0%</strong><div class="progress-bar"><span class="progress-fill" id="progress-fill" style="width:0%"></span></div></div>
                <div class="execution-summary"><div><span>CONTAINER</span><strong id="ro-container">—</strong></div><div><span>TARGET</span><strong id="ro-target">— mL</strong></div><div><span>PUMP</span><strong id="ro-pump">OFF</strong></div><div><span>RGB LED</span><strong id="ro-system">READY</strong></div></div><p class="dispensing-status" id="dispensing-status">DISPENSING IN PROGRESS...</p>
            </section>
            <section class="dispense-panel" data-panel="4" hidden><div class="execution-complete-card" id="complete-readout"><span class="complete-icon" id="complete-icon">✓</span><span class="execution-kicker" id="complete-title">DISPENSING COMPLETE</span><h1 id="complete-heading">Your water is ready.</h1><p class="execution-lede" id="complete-detail">Your target amount has been reached.</p><div class="execution-summary completion"><div><span>CONTAINER</span><strong id="co-container">Cup</strong></div><div><span>TARGET</span><strong id="co-target">— mL</strong></div><div><span>MEASURED</span><strong id="co-measured">— mL</strong></div><div><span>PUMP</span><strong id="co-pump">OFF</strong></div><div><span>RGB</span><strong id="co-reason">WHITE / GREEN FLASH</strong></div></div><p class="complete-note" id="complete-note" hidden>The pump stopped automatically at the target.</p><div class="complete-buttons"><button type="button" class="btn btn-outline" id="done-btn">DONE</button><button type="button" class="btn btn-primary" id="restart-btn">DISPENSE AGAIN</button></div></div></section>
            <?php if ($live['pending_command'] && (int) $live['pending_command']['id'] === (int) ($_SESSION['portal_command_id'] ?? 0)): ?><form method="POST" class="execution-cancel"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_command"><input type="hidden" name="command_id" value="<?= (int) $live['pending_command']['id'] ?>"><button type="submit" class="btn btn-outline">Cancel pending request</button></form><?php endif; ?>
        </section>
    </section>
    <section class="portal-section portal-content-card" id="container-settings" data-portal-section="container-settings" data-portal-title="Container Settings" aria-labelledby="container-settings-heading" tabindex="-1" hidden>
        <div class="portal-view-heading"><span class="side-eyebrow">ADJUST YOUR FILL</span><h1 id="container-settings-heading">Container Settings</h1><p>Set a dispensing threshold for each container. The pump stops when that amount has been dispensed.</p></div>
        <div class="portal-info-grid">
            <?php foreach (['cup' => ['Cup', $target_cup], 'tumbler' => ['Tumbler', $target_tumbler]] as $type => [$name, $target]): ?>
            <article class="portal-info-card"><h2><?= $name ?></h2><div class="container-fill-control"><label for="<?= $type ?>-fill-volume-input">Dispensing threshold (mL)</label><input type="number" id="<?= $type ?>-fill-volume-input" min="<?= (int) $thresholds[$type]['min'] ?>" max="<?= (int) $thresholds[$type]['max'] ?>" step="1" value="<?= $selected_thresholds[$type] !== null ? (int) $selected_thresholds[$type] : '' ?>" aria-describedby="<?= $type ?>-fill-volume-help <?= $type ?>-fill-volume-feedback"><p id="<?= $type ?>-fill-volume-help">Choose <?= (int) $thresholds[$type]['min'] ?>–<?= (int) $thresholds[$type]['max'] ?> mL to suit your <?= strtolower($name) ?>.</p><p class="container-fill-feedback" id="<?= $type ?>-fill-volume-feedback" role="status" hidden></p></div><dl><div><dt>Selected fill amount</dt><dd id="water-<?= $type ?>-threshold"><?= $selected_thresholds[$type] !== null ? (int) $selected_thresholds[$type] . ' mL' : 'Not configured' ?></dd></div></dl></article>
            <?php endforeach; ?>
        </div>
        <p class="portal-view-note">You can also adjust the selected container in the Place step. Changes apply to the next fill.</p>
        <a class="btn btn-outline portal-return-link" href="<?= base_url('/pages/dispense.php#dispense-workflow') ?>" data-portal-link="dispense-workflow" aria-controls="dispense-workflow">Return to dispensing</a>
    </section>
    <section class="portal-section execution-device-card" id="led-card" data-portal-section="led-card" data-portal-title="Device Status" aria-labelledby="device-status-heading" tabindex="-1" hidden>
        <div class="portal-view-heading"><span class="side-eyebrow"><?= $demo ? 'SIMULATED DEVICE STATUS' : 'PHYSICAL DEVICE STATUS' ?></span><h1 id="device-status-heading">Device Status</h1><p>Connection, container detection, pump, and RGB feedback.</p></div>
        <div class="portal-device-layout">
        <div class="portal-status-grid" aria-live="polite"><article class="execution-info-tile"><span class="tile-label">CONNECTION</span><strong id="device-status-connection"><?= $demo ? 'SIMULATED ONLINE' : 'UNKNOWN' ?></strong></article><article class="execution-info-tile"><span class="tile-label">CONTAINER SENSOR</span><strong id="device-status-container"><?= $demo ? 'SIMULATED NOT DETECTED' : 'UNKNOWN' ?></strong></article><article class="execution-info-tile"><span class="tile-label">PUMP</span><strong id="device-status-pump"><?= $demo ? 'SIMULATED OFF' : 'UNKNOWN' ?></strong></article></div>
        <div class="portal-led-feedback"><h2>RGB LED feedback</h2><span class="execution-led" id="led-dot" style="background:<?= sanitize($led_state['hex']) ?>"></span><strong class="led-label" id="led-label"><?= sanitize($led_state['label']) ?></strong><p class="side-desc" id="led-desc"><?= sanitize($led_state['description']) ?></p><div class="led-legend"><span><i class="blue"></i>Blue — Ready / waiting</span><span><i class="cyan"></i>Cyan — Container detected</span><span><i class="green"></i>Green — Dispensing</span><span><i class="white"></i>White — Complete</span><span><i class="red"></i>Red — Error / interrupted</span></div></div>
        </div>
    </section>
    <section class="portal-section execution-safety-card" id="safety-sequence" data-portal-section="safety-sequence" data-portal-title="Safety Sequence" aria-labelledby="safety-heading" tabindex="-1" hidden>
        <div class="portal-view-heading"><span class="side-eyebrow">SAFE DISPENSING</span><h1 id="safety-heading">Safety Sequence</h1><p>Follow these steps each time you fill a container.</p></div>
        <ol><li><strong>Set your dispensing threshold</strong><span>Choose Cup or Tumbler and adjust the fill amount to match its size.</span></li><li><strong>Detect container</strong><span>Place the container on the platform and wait for the presence sensor.</span></li><li><strong>User starts dispensing</strong><span>Confirm the selected amount, then press Dispense Water.</span></li><li><strong>Measure water</strong><span>Keep the container in place while WaterBonia tracks the dispensed amount.</span></li><li><strong>Stop automatically at the threshold</strong><span>Wait for the completion message before removing the container.</span></li></ol>
        <p class="portal-view-note">Removing the container or a device, measurement, or timeout error interrupts the fill. Check the status before trying again.</p>
        <a class="btn btn-outline portal-return-link" href="<?= base_url('/pages/dispense.php#dispense-workflow') ?>" data-portal-link="dispense-workflow" aria-controls="dispense-workflow">Return to dispensing</a>
    </section>
    <?php if ($demo): ?>
    <section class="portal-section execution-demo-card" id="demo-card" data-portal-section="demo-card" data-portal-title="Demo Controls" aria-labelledby="demo-heading" tabindex="-1" hidden>
        <div class="portal-view-heading"><span class="demo-label">DEMO</span><h1 id="demo-heading">Demo Controls</h1><p>Simulate container detection, filling to the selected threshold, and interruptions for presentation.</p></div>
        <div class="demo-grid"><button type="button" class="demo-btn" data-demo="container_on">Simulate container detected</button><button type="button" class="demo-btn" data-demo="container_off">Simulate container removed</button><button type="button" class="demo-btn" data-demo="next">Skip to next step</button><button type="button" class="demo-btn" data-demo="target">Simulate threshold reached (backup)</button><button type="button" class="demo-btn" data-demo="offline">Simulate device offline</button><button type="button" class="demo-btn is-reset" data-demo="reset">Reset demo</button></div>
        <a class="btn btn-outline portal-return-link" href="<?= base_url('/pages/dispense.php#dispense-workflow') ?>" data-portal-link="dispense-workflow" aria-controls="dispense-workflow">Return to dispensing</a>
    </section>
    <?php endif; ?>
    <section class="portal-section execution-activity" id="activity-logs" data-portal-section="activity-logs" data-portal-title="Activity Logs" aria-labelledby="activity-heading" tabindex="-1" hidden>
        <div class="activity-header"><div><span class="side-eyebrow">ACTIVITY LOGS</span><h1 id="activity-heading">Recent WaterBonia activity</h1><p id="activity-log-label"><?= $demo ? 'Demo Activity Logs — simulated activity stored in this browser only.' : 'Device activity — recorded by the server.' ?></p></div><?php if ($demo): ?><button type="button" class="btn btn-outline" id="clear-demo-history">Clear Demo History</button><?php endif; ?></div>
        <div class="activity-table-wrap"><table class="execution-activity-table"><thead><tr><th scope="col">Date &amp; time</th><th scope="col">Activity</th><th scope="col">Details</th><th scope="col">Status</th></tr></thead><tbody id="activity-log-body">
        <?php foreach (array_slice($activities, 0, 10) as $entry): ?>
            <tr><td data-label="Date &amp; time"><?php if ($entry['date_time'] !== 'Date & time unavailable'): ?><time datetime="<?= sanitize(str_replace(' ', 'T', $entry['created_at'])) ?>"><?= sanitize($entry['date_time']) ?></time><?php else: ?>Date &amp; time unavailable<?php endif; ?></td><td data-label="Activity"><?= sanitize($entry['event']) ?></td><td data-label="Details"><?= sanitize($entry['details']) ?></td><td data-label="Status"><span class="activity-status"><?= sanitize($entry['status']) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <p class="activity-empty" id="activity-empty"<?= $activities ? ' hidden' : '' ?>>No activity has been recorded yet.</p>
        <button type="button" class="execution-change-container" id="view-more-activity" hidden>View More</button>
    </section>
</div>
<script>window.WaterBoniaDispenser=<?= json_encode([
    'statusUrl' => base_url('/pages/dispenser-status.php'), 'csrfToken' => csrf_token(),
    'targets' => ['cup' => $target_cup, 'tumbler' => $target_tumbler],
    'thresholds' => $thresholds, 'selectedThresholds' => $selected_thresholds,
    'availability' => $availability, 'led' => $led_state, 'demoMode' => $demo,
    'initialActivities' => $activities,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= base_url('/assets/js/dispenser.js?v=' . filemtime(__DIR__ . '/../assets/js/dispenser.js')) ?>"></script>
<?php include __DIR__ . '/../includes/portal-footer.php'; ?>
