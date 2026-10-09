<?php
/**
 * WaterBonia API Test Script
 *
 * Run from CLI:      php C:\xampp\htdocs\WaterBonia\api\test.php
 *
 * This script tests the API endpoints against a running XAMPP MySQL.
 * It creates its own test device and token, runs all tests, then cleans up.
 *
 * SAFETY: This script creates and deletes test data.
 *         It does NOT modify existing production records.
 *         CLI only — it is never executable from the browser in production.
 */

$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This test suite runs from the command line only:\n  php " . __FILE__ . "\n");
}

function out(string $msg, string $class = ''): void
{
    global $is_cli;
    if ($is_cli) {
        echo ($class === 'fail' ? 'FAIL' : ($class === 'pass' ? 'PASS' : 'INFO')) . ': ' . strip_tags($msg) . "\n";
    } else {
        echo '<div class="' . $class . '">' . $msg . '</div>';
    }
}

function out_pre(string $label, mixed $data): void
{
    global $is_cli;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($is_cli) {
        echo "  {$label}:\n{$json}\n";
    } else {
        echo "<div class='info'>{$label}:</div><pre>{$json}</pre>";
    }
}

function api_call(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = "Authorization: Bearer {$token}";

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => json_decode($response, true) ?: ['raw' => $response],
    ];
}

/** Queue a custom fill only for this suite's newly created device. */
function queue_test_fill(PDO $pdo, int $device_id, string $container, int $amount): int
{
    $stmt = $pdo->prepare("INSERT INTO device_commands (device_id, command_type, container_type, target_amount_ml, status, requested_at, expires_at) VALUES (?, 'dispense', ?, ?, 'pending', NOW(), DATE_ADD(NOW(), INTERVAL 30 SECOND))");
    $stmt->execute([$device_id, $container, $amount]);
    return (int)$pdo->lastInsertId();
}

// ── Setup ───────────────────────────────────────────────
$base = 'http://localhost/WaterBonia/api';

// First, get the test device ID from the database
require_once __DIR__ . '/../config/database.php';

out('<h2>Setup</h2>');

try {
    $pdo = db();
} catch (Exception $e) {
    out('Cannot connect to database: ' . $e->getMessage(), 'fail');
    if (!$is_cli) echo '</body></html>';
    exit(1);
}

// Create a test device
$test_code = 'WB-API-TEST-' . time();
$test_esp32 = 'ESP32-TEST-' . bin2hex(random_bytes(4));
$stmt = $pdo->prepare("INSERT INTO devices (device_code, device_name, esp32_identifier, status, firmware_version) VALUES (?, ?, ?, 'offline', '0.0.0-test')");
$stmt->execute([$test_code, 'API Test Device', $test_esp32]);
$test_device_id = (int)$pdo->lastInsertId();
out("Created test device: {$test_code} (ID: {$test_device_id})", 'info');

$passed = 0;
$failed = 0;
$total = 0;

function test(string $name, bool $result, string $detail = ''): void
{
    global $passed, $failed, $total;
    $total++;
    if ($result) { $passed++; out("PASS: {$name}", 'pass'); }
    else { $failed++; out("FAIL: {$name} {$detail}", 'fail'); }
}

// ═══════════════════════════════════════════════════════
// TEST 1: Missing token
// ═══════════════════════════════════════════════════════
out('<h2>1. Authentication Tests</h2>');

$r = api_call('POST', "{$base}/device/heartbeat", ['firmware_version' => '0.1.0']);
test('Missing token returns 401', $r['status'] === 401, "got {$r['status']}");
test('Error response format', $r['body']['success'] === false);

$r = api_call('POST', "{$base}/device/heartbeat", ['firmware_version' => '0.1.0'], 'invalid-token-12345');
test('Invalid token returns 401', $r['status'] === 401, "got {$r['status']}");

// ═══════════════════════════════════════════════════════
// TEST 2: Generate token
// ═══════════════════════════════════════════════════════
out('<h2>2. Token Generation</h2>');

$raw_token = bin2hex(random_bytes(48));
$token_hash = hash('sha256', $raw_token);
$stmt = $pdo->prepare("INSERT INTO device_tokens (device_id, token_hash, token_name, status) VALUES (?, ?, 'test-token', 'active')");
$stmt->execute([$test_device_id, $token_hash]);
$test_token_id = (int)$pdo->lastInsertId();
out("Generated test token (hash: " . substr($token_hash, 0, 16) . "...)", 'info');

// ═══════════════════════════════════════════════════════
// TEST 3: Valid heartbeat
// ═══════════════════════════════════════════════════════
out('<h2>3. Heartbeat</h2>');

$r = api_call('POST', "{$base}/device/heartbeat", [
    'firmware_version' => '0.1.0-test',
    'ip_address' => '192.168.1.100',
    'wifi_ssid' => 'TestWiFi',
], $raw_token);

test('Heartbeat returns 200', $r['status'] === 200, "got {$r['status']}");
test('Heartbeat success=true', $r['body']['success'] === true);

// Verify last_seen_at was updated
$dev = $pdo->prepare("SELECT last_seen_at, firmware_version, ip_address, wifi_ssid, status FROM devices WHERE id = ?");
$dev->execute([$test_device_id]);
$dev_data = $dev->fetch();
test('last_seen_at updated', $dev_data['last_seen_at'] !== null);
test('firmware_version updated', $dev_data['firmware_version'] === '0.1.0-test');
test('ip_address updated', $dev_data['ip_address'] === '192.168.1.100');
test('wifi_ssid updated', $dev_data['wifi_ssid'] === 'TestWiFi');
test('status set to online', $dev_data['status'] === 'online');

// ═══════════════════════════════════════════════════════
// TEST 4: Sensor reading
// ═══════════════════════════════════════════════════════
out('<h2>4. Sensor Reading</h2>');

$r = api_call('POST', "{$base}/device/sensor-reading", [
    'cup_detected' => false,
    'container_detected' => false,
    'hand_detected' => false,
    'pump_status' => false,
    'battery_percent' => 90,
], $raw_token);

test('Sensor reading returns 200', $r['status'] === 200, "got {$r['status']}");
test('Reading has reading_id', isset($r['body']['data']['reading_id']));

// Verify stored
$sr = $pdo->prepare("SELECT * FROM sensor_readings WHERE device_id = ? ORDER BY id DESC LIMIT 1");
$sr->execute([$test_device_id]);
$sr_data = $sr->fetch();
test('Missing reservoir sensor stored as NULL', $sr_data['water_level_percent'] === null);
test('Battery stored correctly', (int)$sr_data['battery_percent'] === 90);

// ═══════════════════════════════════════════════════════
// TEST 5: Installed sensor validation
// ═══════════════════════════════════════════════════════
out('<h2>5. Validation</h2>');

$r = api_call('POST', "{$base}/device/sensor-reading", [
    'battery_percent' => 150,
], $raw_token);
test('Battery >100 returns 422', $r['status'] === 422, "got {$r['status']}");
test('Error includes details', isset($r['body']['details']));

$r = api_call('POST', "{$base}/device/sensor-reading", [
    'pump_status' => 'invalid',
], $raw_token);
test('Malformed pump status returns 422', $r['status'] === 422, "got {$r['status']}");

// ═══════════════════════════════════════════════════════
// TEST 6: Retired reservoir telemetry
// ═══════════════════════════════════════════════════════
out('<h2>6. Retired Reservoir Telemetry</h2>');

// Legacy level values are ignored and must never generate a water alert.
$r = api_call('POST', "{$base}/device/sensor-reading", [
    'water_level_percent' => 15,
    'container_detected' => true,
    'pump_status' => false,
], $raw_token);
test('Legacy level input accepted and ignored', $r['status'] === 200);

$r = api_call('POST', "{$base}/device/sensor-reading", [
    'water_level_percent' => 'retired-value',
    'container_detected' => true,
    'pump_status' => false,
], $raw_token);
test('Malformed retired level input is ignored', $r['status'] === 200);
$sr->execute([$test_device_id]);
test('Retired level remains NULL in stored telemetry', $sr->fetch()['water_level_percent'] === null);
$alert_count = $pdo->prepare("SELECT COUNT(*) as cnt FROM alerts WHERE device_id = ? AND alert_type = 'low_water' AND status = 'active'");
$alert_count->execute([$test_device_id]);
$count = $alert_count->fetch()['cnt'];
test('No reservoir alerts generated', (int)$count === 0, "got {$count} alerts");

// ═══════════════════════════════════════════════════════
// TEST 7: Dispensing session
// ═══════════════════════════════════════════════════════
out('<h2>7. Dispensing Session</h2>');

$command_id = queue_test_fill($pdo, $test_device_id, 'cup', 137);
$r = api_call('POST', "{$base}/device/poll-command", [], $raw_token);
test('Queued custom Cup command is claimed', ($r['body']['data']['command']['command_id'] ?? null) === $command_id);
test('Claim preserves custom 137 mL cutoff', ($r['body']['data']['command']['target_amount_ml'] ?? null) === 137);

$r = api_call('POST', "{$base}/device/dispensing/start", [
    'command_id' => $command_id, 'container_type' => 'cup', 'target_amount_ml' => 250,
    'container_detected' => true,
], $raw_token);
test('Mismatched command target rejected (409)', $r['status'] === 409, "got {$r['status']}");

$r = api_call('POST', "{$base}/device/dispensing/start", [
    'command_id' => $command_id,
    'container_type' => 'cup',
    'target_amount_ml' => 137,
    'cup_detected' => true,
    'container_detected' => true,
], $raw_token);
test('Dispensing start returns 201', $r['status'] === 201, "got {$r['status']}");
$session_id = $r['body']['data']['session_id'] ?? null;
test('Session ID returned', $session_id !== null);

// The consumed command cannot start another session.
$r = api_call('POST', "{$base}/device/dispensing/start", [
    'command_id' => $command_id, 'container_type' => 'cup', 'target_amount_ml' => 137,
    'cup_detected' => true,
], $raw_token);
test('Consumed command replay rejected (409)', $r['status'] === 409, "got {$r['status']}");

// Complete the session
sleep(1); // Wait for measurable duration
$r = api_call('POST', "{$base}/device/dispensing/complete", [
    'session_id' => $session_id,
    'measured_amount_ml' => 137,
    'stop_reason' => 'target_reached',
], $raw_token);
test('Dispensing complete returns 200', $r['status'] === 200, "got {$r['status']}");
test('Duration calculated', ($r['body']['data']['duration_seconds'] ?? 0) >= 1);

// Verify session record
$ds = $pdo->prepare("SELECT * FROM dispensing_sessions WHERE id = ?");
$ds->execute([$session_id]);
$ds_data = $ds->fetch();
test('Session status is completed', $ds_data['status'] === 'completed');
test('Session has ended_at', $ds_data['ended_at'] !== null);
test('Session preserves selected fill amount', (int)$ds_data['target_amount_ml'] === 137);
test('Measured fill stored', (int)$ds_data['measured_amount_ml'] === 137);
test('Session has no reservoir readings', $ds_data['water_level_before'] === null && $ds_data['water_level_after'] === null);

// ═══════════════════════════════════════════════════════
// TEST 8: Session from another device
// ═══════════════════════════════════════════════════════
out('<h2>8. Session Ownership</h2>');

// Create another device + token
$stmt = $pdo->prepare("INSERT INTO devices (device_code, device_name, esp32_identifier, status) VALUES (?, ?, ?, 'offline')");
$stmt->execute(['WB-OTHER-' . time(), 'Other Device', 'ESP32-OTHER-' . bin2hex(random_bytes(4))]);
$other_id = (int)$pdo->lastInsertId();
$other_raw = bin2hex(random_bytes(48));
$stmt = $pdo->prepare("INSERT INTO device_tokens (device_id, token_hash, status) VALUES (?, ?, 'active')");
$stmt->execute([$other_id, hash('sha256', $other_raw)]);

// This second test device needs its own fresh telemetry and claimed fill.
$r = api_call('POST', "{$base}/device/heartbeat", [], $other_raw);
test('Other test device is online', $r['status'] === 200);
$r = api_call('POST', "{$base}/device/sensor-reading", [
    'container_detected' => true, 'pump_status' => false,
], $other_raw);
test('Other device telemetry needs no reservoir level', $r['status'] === 200);
$other_command = queue_test_fill($pdo, $other_id, 'tumbler', 725);
$r = api_call('POST', "{$base}/device/poll-command", [], $other_raw);
test('Independent Tumbler command is claimed', ($r['body']['data']['command']['command_id'] ?? null) === $other_command);
$r = api_call('POST', "{$base}/device/dispensing/start", [
    'command_id' => $other_command, 'container_type' => 'tumbler', 'target_amount_ml' => 725,
    'container_detected' => true,
], $other_raw);
test('Independent Tumbler session starts', $r['status'] === 201);
$other_session = $r['body']['data']['session_id'] ?? null;

// Try to complete it with our token — should fail 403
$r = api_call('POST', "{$base}/device/dispensing/complete", [
    'session_id' => $other_session,
    'stop_reason' => 'completed',
], $raw_token);
test('Cannot complete another device session (403)', $r['status'] === 403, "got {$r['status']}");

// ═══════════════════════════════════════════════════════
// TEST 9: Invalid session ID
// ═══════════════════════════════════════════════════════
out('<h2>9. Invalid Session</h2>');

$r = api_call('POST', "{$base}/device/dispensing/complete", [
    'session_id' => 999999,
    'stop_reason' => 'completed',
], $raw_token);
test('Non-existent session returns 404', $r['status'] === 404, "got {$r['status']}");

// ═══════════════════════════════════════════════════════
// TEST 10: Revoked token
// ═══════════════════════════════════════════════════════
out('<h2>10. Revoked Token</h2>');

$stmt = $pdo->prepare("UPDATE device_tokens SET status = 'revoked', revoked_at = NOW() WHERE id = ?");
$stmt->execute([$test_token_id]);

$r = api_call('POST', "{$base}/device/heartbeat", [], $raw_token);
test('Revoked token returns 401', $r['status'] === 401, "got {$r['status']}");

// ═══════════════════════════════════════════════════════
// TEST 11: Public status endpoint
// ═══════════════════════════════════════════════════════
out('<h2>11. Public Status</h2>');

$r = api_call('GET', "{$base}/status");
test('Status endpoint returns 200', $r['status'] === 200, "got {$r['status']}");
test('Status includes system name', ($r['body']['data']['system'] ?? '') === 'WaterBonia');

// ═══════════════════════════════════════════════════════
// TEST 12: Unknown endpoint
// ═══════════════════════════════════════════════════════
out('<h2>12. Unknown Endpoint</h2>');

$r = api_call('GET', "{$base}/nonexistent");
test('Unknown endpoint returns 404', $r['status'] === 404, "got {$r['status']}");

// ═══════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════
out('<h2>Cleanup</h2>');

$pdo->prepare("DELETE FROM activity_logs WHERE device_id IN (?, ?)")->execute([$test_device_id, $other_id]);
$pdo->prepare("DELETE FROM alerts WHERE device_id IN (?, ?)")->execute([$test_device_id, $other_id]);
$pdo->prepare("DELETE FROM sensor_readings WHERE device_id IN (?, ?)")->execute([$test_device_id, $other_id]);
$pdo->prepare("DELETE FROM dispensing_sessions WHERE device_id IN (?, ?)")->execute([$test_device_id, $other_id]);
$pdo->prepare("DELETE FROM device_commands WHERE device_id IN (?, ?)")->execute([$test_device_id, $other_id]);
$pdo->prepare("DELETE FROM device_tokens WHERE device_id IN (?, ?)")->execute([$test_device_id, $other_id]);
$pdo->prepare("DELETE FROM devices WHERE id IN (?, ?)")->execute([$test_device_id, $other_id]);
out("Removed test device and all related records.", 'info');

// ═══════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════
out('<div class="summary">');
out("<strong>Results: {$passed}/{$total} passed</strong>, {$failed} failed");
if ($failed === 0) {
    out('All tests passed!', 'pass');
} else {
    out("{$failed} test(s) failed.", 'fail');
}
out('</div>');

if (!$is_cli) echo '</body></html>';
exit($failed > 0 ? 1 : 0);
