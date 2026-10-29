<?php
/** Guards and actual API handlers with in-memory fixtures; no DB/device writes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$settings = [];
$_SESSION = [];
function get_setting(string $key, string $default = ''): string { global $settings; return $settings[$key] ?? $default; }
require_once __DIR__ . '/../config/portal.php';
$count = 0;
function check(bool $condition, string $description): void {
    global $count; $count++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
}
$config = portal_threshold_config();
check($config['cup']['min'] === 1 && $config['cup']['max'] === 2000 && $config['cup']['default'] === 250, 'Cup fill range/default');
check($config['tumbler']['default'] === 500 && $config['tumbler']['options'] === [], 'Tumbler accepts custom fills');
foreach (['cup', 'tumbler'] as $type) foreach ([1, 137, 249, 251, 725, 2000] as $amount) {
    check(validate_water_threshold($type, $amount)[2] === $amount, 'custom fill including below baseline');
    check(validate_water_threshold($type, (string)$amount)[2] === $amount, 'integer form input');
}
foreach ([-1, '-1', 0, 2001, '', null, false, true, 250.0, 250.9, '250.0', '2.5e2', '250x', ' 250 ', [], '999999999999999999999999999'] as $invalid) check(!validate_water_threshold('cup', $invalid)[0], 'malformed/out-of-range rejected');
check(!validate_water_threshold('bottle', 100)[0], 'invalid container rejected');
$settings = ['container_cup_threshold_min_ml' => '250', 'container_cup_threshold_max_ml' => '400', 'container_cup_threshold_options_ml' => 'invalid-json'];
check(validate_water_threshold('cup', 125)[0], 'legacy reservoir settings ignored');
$settings = ['container_cup_fill_min_ml' => '50', 'container_cup_fill_max_ml' => '600', 'container_cup_fill_default_ml' => '300'];
check(!validate_water_threshold('cup', 49)[0] && validate_water_threshold('cup', 599)[0] && !validate_water_threshold('cup', 601)[0], 'fill range enforced');
check(portal_threshold_config()['cup']['default'] === 300, 'fill default configured');
foreach ([['container_cup_fill_min_ml' => '700', 'container_cup_fill_max_ml' => '600'], ['container_cup_fill_max_ml' => '2001'], ['container_cup_fill_min_ml' => 'abc']] as $invalid_config) {
    $settings = $invalid_config;
    check(!validate_water_threshold('cup', 250)[0], 'bad configuration fails closed');
}
$settings = [];
$_SESSION = ['portal_thresholds' => ['cup' => 350, 'tumbler' => 750]];
check(portal_selected_thresholds() === ['cup' => 250, 'tumbler' => 500], 'legacy preferences not reinterpreted');
$_SESSION['portal_fill_volumes'] = ['cup' => 137, 'tumbler' => 725];
check(portal_selected_thresholds() === ['cup' => 137, 'tumbler' => 725], 'independent fill preferences persist');
$settings['container_cup_fill_min_ml'] = '150';
check(portal_selected_thresholds()['cup'] === 250, 'session selection revalidates');
$settings = [];
$device = ['id' => 7, 'status' => 'online', 'last_seen_at' => date('Y-m-d H:i:s'), 'last_heartbeat_at' => date('Y-m-d H:i:s'), 'boot_id' => 'abc'];
$reading = ['sensor_status' => 'ok', 'boot_id' => 'abc', 'device_id' => 7, 'recorded_at' => date('Y-m-d H:i:s'), 'water_level_percent' => null, 'container_detected' => 1, 'cup_detected' => 0, 'pump_status' => 0];
check(portal_safety_reason($device, $reading, null, null, false, [], 137) === null, 'fresh telemetry needs no level sensor');
foreach ([null, '0', '10', '101', 'invalid'] as $legacy_water) check(portal_safety_reason($device, array_replace($reading, ['water_level_percent' => $legacy_water]), null, null, false, [], 137) === null, 'legacy water never gates fill');
$stale = array_replace($reading, ['recorded_at' => date('Y-m-d H:i:s', time() - 31)]);
$active_sample = ['started_at' => $reading['recorded_at']];
check(!portal_reading_current_for_session($reading, $active_sample), 'pre-session/same-second telemetry cannot describe active pump');
check(portal_reading_current_for_session(array_replace($reading, ['recorded_at' => date('Y-m-d H:i:s', strtotime($reading['recorded_at']) + 1)]), $active_sample), 'strictly newer session telemetry may report state');
check(!portal_reading_current_for_session($reading, ['started_at' => 'invalid']), 'invalid session timestamp fails closed for live state');
check(portal_safety_reason($device, $stale, null, null, false, [], 137) !== null, 'stale telemetry blocked');
check(portal_safety_reason($device, null, null, null, false, [], 137) !== null, 'missing telemetry blocked');
check(portal_safety_reason(null, $reading, null, null, false, [], 137) !== null, 'missing device blocked');
foreach (['offline', 'disabled', 'maintenance'] as $status) check(portal_safety_reason(array_replace($device, ['status' => $status]), $reading, null, null, false, [], 137) !== null, 'unavailable device blocked');
check(portal_safety_reason(array_replace($device, ['last_heartbeat_at' => date('Y-m-d H:i:s', time() - 31)]), $reading, null, null, false, [], 137) !== null, 'stale heartbeat blocked');
check(portal_safety_reason($device, array_replace($reading, ['device_id' => 8]), null, null, false, [], 137) !== null, 'other-device telemetry blocked');
check(portal_safety_reason($device, $reading, ['id' => 1], null, false, [], 137) !== null, 'active session blocked');
check(portal_safety_reason($device, $reading, null, ['id' => 1], false, [], 137) !== null, 'queued/claimed concurrency blocked');
check(portal_safety_reason($device, $reading, null, null, true, [], 137) !== null, 'sensor/device errors blocked');
check(portal_safety_reason($device, array_replace($reading, ['pump_status' => 1]), null, null, false, [], 137) !== null, 'running pump blocked');
$missing_pump = $reading; unset($missing_pump['pump_status']);
check(portal_safety_reason($device, $missing_pump, null, null, false, [], 137) !== null, 'missing pump fails closed');
check(portal_safety_reason($device, array_replace($reading, ['container_detected' => 0]), null, null, false, [], 137) !== null, 'missing container blocked');
$command = ['id' => 11, 'device_id' => 7, 'command_type' => 'dispense', 'status' => 'claimed', 'claimed_at' => date('Y-m-d H:i:s'), 'container_type' => 'cup', 'target_amount_ml' => 137, 'requested_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + 30)];
check(portal_session_start_target($command, 7, 'cup', 137)[2] === 137, 'stored target authoritative');
$settings['container_cup_target_ml'] = '400'; $_SESSION['portal_fill_volumes']['cup'] = 600;
check(portal_session_start_target($command, 7, 'cup', 137)[2] === 137, 'preferences/defaults cannot mutate command');
$settings = [];
foreach ([[null, 7, 'cup', 137], [$command, 8, 'cup', 137], [$command, 7, 'tumbler', 137], [$command, 7, 'cup', 250], [$command, 7, 'cup', 137.0], [array_replace($command, ['status' => 'pending']), 7, 'cup', 137]] as $attempt) check(!portal_session_start_target(...$attempt)[0], 'command mismatch/replay rejected');

echo "PASS: {$count} legacy volume validation and shared readiness checks. No database/device writes.\n";
