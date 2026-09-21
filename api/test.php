<?php
/**
 * WaterBonia API Test Script
 *
 * Run from browser:  http://localhost/WaterBonia/api/test.php
 * Run from CLI:      php C:\xampp\htdocs\WaterBonia\api\test.php
 *
 * This script tests the API endpoints against a running XAMPP MySQL.
 * It creates its own test device and token, runs all tests, then cleans up.
 *
 * SAFETY: This script creates and deletes test data.
 *         It does NOT modify existing production records.
 */

$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    echo '<!DOCTYPE html><html><head><title>WaterBonia API Tests</title>';
    echo '<style>body{font-family:monospace;background:#1e293b;color:#e2e8f0;padding:1.5rem;line-height:1.6}';
    echo '.pass{color:#4ade80}.fail{color:#f87171}.info{color:#60a5fa}.warn{color:#fbbf24}';
    echo 'h2{color:#38bdf8;border-bottom:1px solid #334155;padding-bottom:0.5rem}';
    echo 'pre{background:#0f172a;padding:0.75rem;border-radius:6px;overflow-x:auto;font-size:0.82rem}';
    echo '.summary{background:#0f172a;padding:1rem;border-radius:8px;margin-top:1rem;border:1px solid #334155}';
    echo '</style></head><body>';
    echo '<h1>WaterBonia API Test Suite</h1>';
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
    'water_level_percent' => 75,
    'cup_detected' => false,
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
test('Water level stored correctly', $sr_data['water_level_percent'] === 75);
test('Battery stored correctly', $sr_data['battery_percent'] === 90);

// ═══════════════════════════════════════════════════════
// TEST 5: Invalid water level
// ═══════════════════════════════════════════════════════
out('<h2>5. Validation</h2>');

$r = api_call('POST', "{$base}/device/sensor-reading", [
    'water_level_percent' => 150,
], $raw_token);
test('Water level >100 returns 422', $r['status'] === 422, "got {$r['status']}");
test('Error includes details', isset($r['body']['details']));

$r = api_call('POST', "{$base}/device/sensor-reading", [
    'water_level_percent' => -5,
], $raw_token);
test('Water level <0 returns 422', $r['status'] === 422, "got {$r['status']}");

// ═══════════════════════════════════════════════════════
// TEST 6: Low-water alert
// ═══════════════════════════════════════════════════════
out('<h2>6. Low-Water Alert</h2>');

// Send reading below threshold (default threshold is 20%)
$r = api_call('POST', "{$base}/device/sensor-reading", [
    'water_level_percent' => 15,
], $raw_token);
test('Low-water reading accepted', $r['status'] === 200);

$alert = $pdo->prepare("SELECT * FROM alerts WHERE device_id = ? AND alert_type = 'low_water' AND status = 'active' LIMIT 1");
$alert->execute([$test_device_id]);
$alert_data = $alert->fetch();
test('Low-water alert created', $alert_data !== false);
test('Alert severity is warning', $alert_data['severity'] === 'warning' || $alert_data['severity'] === 'critical');

// Send duplicate — should NOT create a second alert
$r = api_call('POST', "{$base}/device/sensor-reading", [
    'water_level_percent' => 12,
], $raw_token);
$alert_count = $pdo->prepare("SELECT COUNT(*) as cnt FROM alerts WHERE device_id = ? AND alert_type = 'low_water' AND status = 'active'");
$alert_count->execute([$test_device_id]);
$count = $alert_count->fetch()['cnt'];
test('No duplicate alert created', $count == 1, "got {$count} alerts");

// Recovery — above threshold
$r = api_call('POST', "{$base}/device/sensor-reading", [
    'water_level_percent' => 50,
], $raw_token);
test('Recovery reading accepted', $r['status'] === 200);

$alert_check = $pdo->prepare("SELECT status FROM alerts WHERE device_id = ? AND alert_type = 'low_water' AND id = ?");
$alert_check->execute([$test_device_id, $alert_data['id']]);
$resolved = $alert_check->fetch();
test('Low-water alert auto-resolved', $resolved && $resolved['status'] === 'resolved');

// ═══════════════════════════════════════════════════════
// TEST 7: Dispensing session
// ═══════════════════════════════════════════════════════
out('<h2>7. Dispensing Session</h2>');

$r = api_call('POST', "{$base}/device/dispensing/start", [
    'cup_detected' => true,
    'hand_detected' => true,
    'water_level_before' => 50,
], $raw_token);
test('Dispensing start returns 201', $r['status'] === 201, "got {$r['status']}");
$session_id = $r['body']['data']['session_id'] ?? null;
test('Session ID returned', $session_id !== null);

// Try to start another — should fail 409
$r = api_call('POST', "{$base}/device/dispensing/start", [
    'cup_detected' => true,
], $raw_token);
test('Duplicate active session rejected (409)', $r['status'] === 409, "got {$r['status']}");

// Complete the session
sleep(1); // Wait for measurable duration
$r = api_call('POST', "{$base}/device/dispensing/complete", [
    'session_id' => $session_id,
    'water_level_after' => 45,
    'stop_reason' => 'completed',
], $raw_token);
test('Dispensing complete returns 200', $r['status'] === 200, "got {$r['status']}");
test('Duration calculated', ($r['body']['data']['duration_seconds'] ?? 0) >= 1);

// Verify session record
$ds = $pdo->prepare("SELECT * FROM dispensing_sessions WHERE id = ?");
$ds->execute([$session_id]);
$ds_data = $ds->fetch();
test('Session status is completed', $ds_data['status'] === 'completed');
test('Session has ended_at', $ds_data['ended_at'] !== null);

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

// Start a session on the other device
$r = api_call('POST', "{$base}/device/dispensing/start", [], $other_raw);
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
$pdo->prepare("DELETE FROM sensor_readings WHERE device_id = ?")->execute([$test_device_id]);
$pdo->prepare("DELETE FROM dispensing_sessions WHERE device_id IN (?, ?)")->execute([$test_device_id, $other_id]);
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
