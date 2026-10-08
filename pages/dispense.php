<?php
/** WaterBonia execution portal: public, kiosk-facing dispensing flow. */
require_once __DIR__ . '/../includes/functions.php';

$device = get_active_device();
$actor_id = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
if (is_post() && verify_csrf() && isset($_POST['action'])) {
    if ($_POST['action'] === 'request_dispense') {
        $ctype = (string) ($_POST['container_type'] ?? '');
        [$ok, $msg] = request_dispense((int) ($device['id'] ?? 0), $ctype, $actor_id);
        $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg];
        if ($ok) log_event($device ? $device['id'] : null, $actor_id, 'dispensing_requested', 'info', "Dispensing requested: {$ctype}.");
        redirect('/pages/dispense.php');
    }
    if ($_POST['action'] === 'cancel_command' && !empty($_POST['command_id'])) {
        $cmd = db_fetch("SELECT * FROM device_commands WHERE id = ? AND status = 'pending'", [(int) $_POST['command_id']]);
        $_SESSION['flash'] = ['ok' => !!($cmd && $device && $cmd['device_id'] == $device['id']), 'msg' => 'Command no longer pending.'];
        if ($cmd && $device && $cmd['device_id'] == $device['id']) {
            db_update('device_commands', ['status' => 'cancelled', 'completed_at' => date('Y-m-d H:i:s')], 'id = ?', [$cmd['id']]);
            $_SESSION['flash'] = ['ok' => true, 'msg' => 'Dispensing request cancelled.'];
        }
        redirect('/pages/dispense.php');
    }
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$latest_reading = null; $water_level = null; $container_detected = false; $pump_status = false;
if ($device) {
    $latest_reading = db_fetch("SELECT * FROM sensor_readings WHERE device_id = ? ORDER BY recorded_at DESC LIMIT 1", [$device['id']]);
    if ($latest_reading) {
        $water_level = $latest_reading['water_level_percent'];
        $container_detected = !empty($latest_reading['container_detected']) || !empty($latest_reading['cup_detected']);
        $pump_status = (bool) $latest_reading['pump_status'];
    }
}
$live = get_live_dispenser_state($device);
$target_cup = container_target_ml('cup'); $target_tumbler = container_target_ml('tumbler');
$demo = WATERBONIA_DEMO_MODE;
$availability = $demo
    ? ['tone' => 'success', 'label' => 'SYSTEM AVAILABLE', 'hint' => 'Demo prototype is ready for presentation.']
    : dispenser_availability($device, $live);
$led_state = $demo ? demo_led('blue') : dispenser_led($device, $live);
$low_water_real = !$demo && $live['water_level'] !== null && $live['water_level'] <= (int) get_setting('low_water_threshold', '20');
$page_title = 'Execution Portal';
include __DIR__ . '/../includes/portal-header.php';
?>
<div class="execution-page">
    <?php if (!$demo && !$device): ?><div class="execution-notice">No device is registered, so dispensing is unavailable.</div><?php elseif (!$demo && !$latest_reading): ?><div class="execution-notice">Waiting for the first sensor reading from the dispenser.</div><?php endif; ?>
    <?php if ($flash): ?><div class="execution-notice <?= $flash['ok'] ? 'is-success' : 'is-error' ?>"><?= sanitize($flash['msg']) ?></div><?php endif; ?>
    <nav class="execution-stepper" id="dispense-steps" aria-label="Dispensing progress">
        <div class="dispense-step" data-step="1"><span class="step-num">1</span><span class="step-label">Choose</span></div><span class="step-connector" data-step="2"></span>
        <div class="dispense-step" data-step="2"><span class="step-num">2</span><span class="step-label">Place</span></div><span class="step-connector" data-step="3"></span>
        <div class="dispense-step" data-step="3"><span class="step-num">3</span><span class="step-label">Dispense</span></div><span class="step-connector" data-step="4"></span>
        <div class="dispense-step" data-step="4"><span class="step-num">4</span><span class="step-label">Complete</span></div>
    </nav>
    <div class="execution-grid">
        <section class="execution-primary" aria-live="polite">
            <div class="alert-banner is-warning" id="low-water-banner"<?= $low_water_real ? '' : ' hidden' ?>><strong>LOW WATER</strong><span>Refill the dispenser before starting a new fill.</span></div>
            <section class="dispense-panel" data-panel="1">
                <span class="execution-kicker">STEP 1 — CHOOSE</span><h1>What would you like to fill?</h1><p class="execution-lede">Choose your container to set the dispensing amount.</p>
                <div class="container-options" role="radiogroup" aria-label="Container type">
                    <label class="execution-container-card container-card" data-container="cup"><input type="radio" name="container_type" value="cup" class="sr-only"><span class="container-art" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3"><path d="M14 17h31v30a9 9 0 0 1-9 9H23a9 9 0 0 1-9-9V17Z"/><path d="M45 24h4a8 8 0 0 1 0 16h-4"/><path d="M11 17h37"/></svg></span><span class="container-name">Cup</span><strong class="container-volume"><?= $target_cup ?> mL</strong><span class="container-help">Select cup</span><span class="card-check">✓</span></label>
                    <label class="execution-container-card container-card" data-container="tumbler"><input type="radio" name="container_type" value="tumbler" class="sr-only"><span class="container-art" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3"><path d="M18 11h28l-3 44a7 7 0 0 1-7 6H28a7 7 0 0 1-7-6l-3-44Z"/><path d="M17 18h30"/><path d="M23 29h18"/></svg></span><span class="container-name">Tumbler</span><strong class="container-volume"><?= $target_tumbler ?> mL</strong><span class="container-help">Select tumbler</span><span class="card-check">✓</span></label>
                </div><p class="execution-footnote">Volumes are set by the system administrator.</p>
            </section>
            <section class="dispense-panel" data-panel="2" hidden>
                <span class="execution-kicker">STEP 2 — PLACE</span><h1 id="place-heading">Place your container</h1><p class="execution-lede" id="place-description">Place your selected cup on the dispenser platform.</p>
                <div class="execution-state-grid" id="place-indicator"><article class="execution-info-tile"><span class="tile-label">SELECTED CONTAINER</span><strong><span class="check-mark">✓</span> <span id="place-container-name">Cup</span></strong><span id="place-container-target">Target: <?= $target_cup ?> mL</span></article><article class="execution-info-tile sensor-tile"><span class="tile-label">CONTAINER STATUS</span><strong><span class="status-dot"></span> <span id="container-sensor-status">Waiting for container</span></strong><span>Presence sensor on dispenser platform</span></article></div>
                <p class="dispense-hint" id="dispense-hint">Place a container to continue.</p><button type="button" class="execution-dispense-button" id="dispense-btn" disabled>DISPENSE WATER</button>
            </section>
            <section class="dispense-panel" data-panel="3" hidden>
                <span class="execution-kicker">STEP 3 — DISPENSE</span><h1>Dispensing...</h1><p class="execution-lede">Pump running. WaterBonia stops automatically at the target amount.</p>
                <div class="execution-state-grid compact"><article class="execution-info-tile"><span class="tile-label">SELECTED CONTAINER</span><strong id="dispense-container">Cup</strong><span id="dispense-target-label">Target: <?= $target_cup ?> mL</span></article><article class="execution-info-tile sensor-tile"><span class="tile-label">CONTAINER STATUS</span><strong><span class="status-dot is-on"></span> Container detected</strong><span>Safe to dispense</span></article></div>
                <div class="execution-progress-card" id="dispense-progress"><div><span class="tile-label">CURRENT</span><strong class="current-volume"><span id="measured-value">0</span> mL <small>/ <span id="target-value"><?= $target_cup ?></span> mL</small></strong></div><strong class="progress-percent" id="progress-percent">0%</strong><div class="progress-bar"><span class="progress-fill" id="progress-fill" style="width:0%"></span></div></div>
                <div class="execution-summary"><div><span>CONTAINER</span><strong id="ro-container">—</strong></div><div><span>TARGET</span><strong id="ro-target">— mL</strong></div><div><span>PUMP</span><strong id="ro-pump">OFF</strong></div><div><span>RGB LED</span><strong id="ro-system">READY</strong></div></div><p class="dispensing-status" id="dispensing-status">DISPENSING IN PROGRESS...</p>
            </section>
            <section class="dispense-panel" data-panel="4" hidden><div class="execution-complete-card" id="complete-readout"><span class="complete-icon" id="complete-icon">✓</span><span class="execution-kicker" id="complete-title">DISPENSING COMPLETE</span><h1 id="complete-heading">Your water is ready.</h1><p class="execution-lede" id="complete-detail">Your target amount has been reached.</p><div class="execution-summary completion"><div><span>CONTAINER</span><strong id="co-container">Cup</strong></div><div><span>TARGET</span><strong id="co-target">— mL</strong></div><div><span>MEASURED</span><strong id="co-measured">— mL</strong></div><div><span>PUMP</span><strong id="co-pump">OFF</strong></div><div><span>RGB</span><strong id="co-reason">WHITE / GREEN FLASH</strong></div></div><p class="complete-note" id="complete-note" hidden>The pump stopped automatically at the target.</p><div class="complete-buttons"><button type="button" class="btn btn-outline" id="done-btn">DONE</button><button type="button" class="btn btn-primary" id="restart-btn">DISPENSE AGAIN</button></div></div></section>
            <?php if ($live['pending_command']): ?><form method="POST" class="execution-cancel"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_command"><input type="hidden" name="command_id" value="<?= (int) $live['pending_command']['id'] ?>"><button type="submit" class="btn btn-outline">Cancel pending request</button></form><?php endif; ?>
        </section>
        <aside class="execution-sidebar">
            <section class="execution-device-card" id="led-card"><div class="side-eyebrow">PHYSICAL DEVICE STATUS</div><h2>RGB LED feedback</h2><span class="execution-led" id="led-dot" style="background:<?= sanitize($led_state['hex']) ?>"></span><strong class="led-label" id="led-label"><?= sanitize($led_state['label']) ?></strong><p class="side-desc" id="led-desc"><?= sanitize($led_state['description']) ?></p><div class="led-legend"><span><i class="blue"></i>Blue — Ready / waiting</span><span><i class="cyan"></i>Cyan — Container detected</span><span><i class="green"></i>Green — Dispensing</span><span><i class="white"></i>White — Complete</span><span><i class="yellow"></i>Yellow — Low water</span><span><i class="red"></i>Red — Error / interrupted</span></div></section>
            <?php if ($demo): ?><section class="execution-demo-card" id="demo-card"><span class="demo-label">DEMO</span><h2>Demo Prototype Controls</h2><p>These controls simulate hardware behavior for presentation purposes. They are not part of the production WaterBonia interface.</p><div class="demo-grid"><button type="button" class="demo-btn" data-demo="container_on">Simulate container detected</button><button type="button" class="demo-btn" data-demo="container_off">Simulate container removed</button><button type="button" class="demo-btn" data-demo="next">Skip to next step</button><button type="button" class="demo-btn" data-demo="target">Simulate target reached (backup)</button><button type="button" class="demo-btn" data-demo="low_water">Simulate low water</button><button type="button" class="demo-btn" data-demo="offline">Simulate device offline</button><button type="button" class="demo-btn is-reset" data-demo="reset">Reset demo</button></div></section><?php endif; ?>
            <section class="execution-safety-card"><span class="side-eyebrow">SAFETY SEQUENCE</span><ol><li>Select container</li><li>Detect container</li><li>User starts dispensing</li><li>Measure water</li><li>Stop automatically at target</li></ol></section>
        </aside>
    </div>
</div>
<script>window.WaterBoniaDispenser={statusUrl:'<?= base_url('/pages/dispenser-status.php') ?>',csrfToken:'<?= csrf_token() ?>',lowThreshold:<?= (int) get_setting('low_water_threshold','20') ?>,targets:{cup:<?= $target_cup ?>,tumbler:<?= $target_tumbler ?>},availability:<?= json_encode($availability) ?>,led:<?= json_encode($led_state) ?>,lowWaterReal:<?= $low_water_real ? 'true':'false' ?>,demoMode:<?= $demo ? 'true':'false' ?>};</script>
<script src="<?= base_url('/assets/js/dispenser.js?v=' . filemtime(__DIR__ . '/../assets/js/dispenser.js')) ?>"></script>
<?php include __DIR__ . '/../includes/portal-footer.php'; ?>
