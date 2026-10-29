<?php
/** Full-app accountless HTTP checks. Isolated loopback services only; never dispense. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('WATERBONIA_TEST_PORT') !== '33287' || getenv('WATERBONIA_DB_PORT') !== '33287') {
    throw new RuntimeException('Use tests/run_timer_db_test.py; live application tests are prohibited.');
}
$base = rtrim($argv[1] ?? 'http://127.0.0.1:33390/WaterBonia', '/');
if ($base !== 'http://127.0.0.1:33390/WaterBonia') throw new RuntimeException('Isolated HTTP port required.');
$root = dirname(__DIR__);
$session_path = realpath(getenv('WATERBONIA_TEST_SESSION_PATH') ?: '');
$tmp_path = realpath($root . '/tmp');
if (!$session_path || !$tmp_path || !str_starts_with(strtolower(str_replace('\\', '/', $session_path)), strtolower(str_replace('\\', '/', $tmp_path)) . '/')) {
    throw new RuntimeException('Isolated session directory required.');
}
$pdo = new PDO('mysql:host=127.0.0.1;port=33287;dbname=waterbonia;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('SET time_zone = ' . $pdo->quote(date('P')));
$count = 0;
function check(bool $ok, string $label): void {
    global $count;
    $count++;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
}
function row(string $sql, array $params = []): ?array {
    global $pdo;
    $statement = $pdo->prepare($sql); $statement->execute($params);
    return $statement->fetch() ?: null;
}
function sql(string $query, array $params = []): void {
    global $pdo;
    $statement = $pdo->prepare($query); $statement->execute($params);
}
function request(string $path, ?array $form = null): array {
    global $base, $cookie;
    $headers = 'Cookie: ' . $cookie . "\r\n" . 'Content-Type: application/x-www-form-urlencoded';
    $options = ['http' => ['method' => $form === null ? 'GET' : 'POST', 'header' => $headers,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]];
    if ($form !== null) $options['http']['content'] = http_build_query($form);
    $body = file_get_contents($base . $path, false, stream_context_create($options));
    if ($body === false) throw new RuntimeException('Isolated server unavailable.');
    foreach ($http_response_header as $header) if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) $cookie = $match[1];
    preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $match);
    return [(int)$match[1], $body, $http_response_header];
}
function clean_response(string $body): bool {
    return !preg_match('/SQLSTATE|PDOException|Fatal error|Stack trace|(?:<b>)?Warning:/i', $body);
}
function no_account_ui(string $body): bool {
    return !preg_match('/(?:href|action)=["\'][^"\']*(?:auth\/login\.php|logout\.php)["\']|class=["\'](?:header-user|user-avatar|user-role|btn-logout)["\']|name=["\'](?:username|password)["\']/i', $body);
}
function session_snapshot(): array {
    $_SESSION = [];
    session_start(['read_and_close' => true]);
    return $_SESSION;
}

// Legacy account rows remain historical data. Seed stale session identity to prove
// the new UI does not depend on it and does not destroy unrelated kiosk state.
$legacy_user = row('SELECT id FROM users ORDER BY id LIMIT 1');
$users_before = (int)row('SELECT COUNT(*) AS n FROM users')['n'];
session_save_path($session_path);
session_id(bin2hex(random_bytes(16)));
session_start();
$csrf = bin2hex(random_bytes(32));
$_SESSION = ['csrf_token' => $csrf, 'portal_request_namespace' => 'accountless-scope-fixture',
    'fixture_session_marker' => 'preserve-me', 'user_id' => (int)$legacy_user['id'], 'user_role' => 'admin', 'login_time' => time()];
$cookie = session_name() . '=' . session_id();
session_write_close();

$pages = ['dashboard', 'water-level', 'dispensing', 'device-status', 'activity-logs', 'alerts', 'analytics', 'devices', 'settings', 'help', 'dispense'];
foreach ($pages as $page) {
    [$status, $body] = request('/pages/' . $page . '.php');
    check($status === 200, $page . ' opens without login');
    check(clean_response($body), $page . ' contains no PHP/database failure');
    check(no_account_ui($body), $page . ' contains no account/login/avatar/logout UI');
}
foreach (['/auth/login.php', '/logout.php'] as $path) {
    [$status, $body, $headers] = request($path);
    check($status === 302 && in_array('Location: /WaterBonia/pages/dispense.php', $headers, true), 'retired account route redirects to portal');
    $session = session_snapshot();
    check(($session['csrf_token'] ?? '') === $csrf && ($session['fixture_session_marker'] ?? '') === 'preserve-me'
        && ($session['portal_request_namespace'] ?? '') === 'accountless-scope-fixture', 'compatibility route preserves CSRF and kiosk request scope');
}
[$status] = request('/auth/login.php', ['username' => 'unused-fixture', 'password' => 'unused-fixture']);
check($status === 302, 'retired login POST does not process an account');

foreach ([
    ['/pages/settings.php', ['setting_key' => 'system_name', 'setting_value' => 'blocked-without-csrf']],
    ['/pages/devices.php', ['form_action' => 'add_device', 'device_code' => 'BLOCKED-CSRF', 'esp32_identifier' => 'BLOCKED-CSRF']],
    ['/pages/alerts.php?action=acknowledge&id=1', []],
] as [$path, $form]) {
    [$status] = request($path, $form);
    check($status === 403, 'accountless mutation rejects missing CSRF');
}
check(row("SELECT id FROM devices WHERE device_code='BLOCKED-CSRF'") === null, 'missing-CSRF registration made no record');

// Change a harmless fixture setting, not a duration or a device assignment.
sql('UPDATE system_settings SET updated_by=? WHERE setting_key=?', [$legacy_user['id'], 'system_name']);
[$status, $body] = request('/pages/settings.php', ['csrf_token' => $csrf, 'setting_key' => 'system_name', 'setting_value' => 'WaterBonia accountless fixture']);
check($status === 200 && clean_response($body), 'accountless settings save succeeds');
$setting = row('SELECT setting_value,updated_by FROM system_settings WHERE setting_key=?', ['system_name']);
check($setting['setting_value'] === 'WaterBonia accountless fixture' && $setting['updated_by'] === null, 'settings updater is truthfully NULL');
check(row("SELECT user_id FROM activity_logs WHERE event_type='configuration_changed' ORDER BY id DESC LIMIT 1")['user_id'] === null, 'settings activity actor is NULL');

// Register and manage tokens only for this newly created, non-networked fixture.
$suffix = bin2hex(random_bytes(5));
$code = 'HTTP-ACCOUNTLESS-' . $suffix;
[$status, $body] = request('/pages/devices.php', ['csrf_token' => $csrf, 'form_action' => 'add_device', 'device_code' => $code,
    'device_name' => 'Isolated accountless test', 'esp32_identifier' => 'HTTP-ESP-' . $suffix, 'location' => 'Disposable test DB']);
check($status === 200 && clean_response($body), 'accountless device registration succeeds');
$device = row('SELECT id FROM devices WHERE device_code=?', [$code]);
check($device !== null, 'isolated fixture device created');
$device_id = (int)$device['id'];
check(row("SELECT user_id FROM activity_logs WHERE device_id=? AND event_type='device_registered' ORDER BY id DESC LIMIT 1", [$device_id])['user_id'] === null, 'registration activity actor is NULL');
[$status, $body] = request('/pages/devices.php', ['csrf_token' => $csrf, 'form_action' => 'generate_token', 'device_id' => $device_id, 'token_name' => 'isolated-fixture']);
check($status === 200 && clean_response($body), 'accountless fixture token generation succeeds');
$token = row('SELECT id,status,token_hash FROM device_tokens WHERE device_id=? ORDER BY id DESC LIMIT 1', [$device_id]);
check($token !== null && $token['status'] === 'active', 'fixture device receives an active token');
preg_match('/\b[a-f0-9]{96}\b/', $body, $raw_token);
check(isset($raw_token[0]) && hash_equals($token['token_hash'], hash('sha256', $raw_token[0])), 'one-time fixture token matches stored hash');
unset($raw_token, $body); // Credentials are never printed or sent to an ESP32.
[$status, $body] = request('/pages/devices.php', ['csrf_token' => $csrf, 'form_action' => 'revoke_token', 'token_id' => (int)$token['id'], 'device_id_for_log' => $device_id]);
check($status === 200 && clean_response($body), 'accountless fixture token revocation succeeds');
check(row('SELECT status FROM device_tokens WHERE id=?', [$token['id']])['status'] === 'revoked', 'fixture token revoked');
check(row("SELECT user_id FROM activity_logs WHERE device_id=? AND event_type='device_token_revoked' ORDER BY id DESC LIMIT 1", [$device_id])['user_id'] === null, 'token activity actor is NULL');

sql("INSERT INTO alerts(device_id,alert_type,severity,title,message,status) VALUES (?,'device_error','warning','Isolated accountless fixture','No physical hardware involved','active')", [$device_id]);
$alert_id = (int)$pdo->lastInsertId();
[$status] = request('/pages/alerts.php?action=acknowledge&id=' . $alert_id, ['csrf_token' => $csrf]);
check($status === 302, 'accountless alert acknowledge succeeds');
$alert = row('SELECT status,acknowledged_by FROM alerts WHERE id=?', [$alert_id]);
check($alert['status'] === 'acknowledged' && $alert['acknowledged_by'] === null, 'alert acknowledgement actor is NULL');
[$status] = request('/pages/alerts.php?action=resolve&id=' . $alert_id, ['csrf_token' => $csrf]);
check($status === 302 && row('SELECT status FROM alerts WHERE id=?', [$alert_id])['status'] === 'resolved', 'accountless alert resolve succeeds');
check(row("SELECT user_id FROM activity_logs WHERE device_id=? AND event_type='alert_resolved' ORDER BY id DESC LIMIT 1", [$device_id])['user_id'] === null, 'alert activity actor is NULL');
check((int)row('SELECT COUNT(*) AS n FROM users')['n'] === $users_before, 'historical account records preserved');
echo "PASS: {$count} isolated accountless full-page/session/CSRF/management checks. No live DB, dispensing request or hardware accessed.\n";
