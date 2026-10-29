<?php
/**
 * WaterBonia - Devices Management Page
 * Device provisioning + token management
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../api/auth.php';
require_valid_csrf();

$page_title = 'Devices';

$success = '';
$error = '';

// ── Handle POST actions ─────────────────────────────────
if (is_post() && verify_csrf()) {
    $form_action = $_POST['form_action'] ?? '';

    // ── Add new device ──
    if ($form_action === 'add_device') {
        $device_code = trim($_POST['device_code'] ?? '');
        $device_name = trim($_POST['device_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $esp32_id = trim($_POST['esp32_identifier'] ?? '');
        $firmware = trim($_POST['firmware_version'] ?? '');

        if ($device_code === '' || $esp32_id === '') {
            $error = 'Device code and ESP32 identifier are required.';
        } else {
            // Check uniqueness
            $exists = db_fetch("SELECT id FROM devices WHERE device_code = ? OR esp32_identifier = ?", [$device_code, $esp32_id]);
            if ($exists) {
                $error = 'A device with this code or ESP32 identifier already exists.';
            } else {
                $new_id = db_insert('devices', [
                    'device_code'      => $device_code,
                    'device_name'      => $device_name,
                    'location'         => $location,
                    'esp32_identifier' => $esp32_id,
                    'firmware_version' => $firmware,
                    'status'           => 'offline',
                ]);
                log_event($new_id, null, 'device_registered', 'info',
                    "Device {$device_code} ({$esp32_id}) registered through device management.");
                $success = "Device \"{$device_code}\" registered successfully. You can now generate an API token for it.";
            }
        }
    }

    // ── Generate token ──
    if ($form_action === 'generate_token') {
        $target_device_id = (int)($_POST['device_id'] ?? 0);
        $token_name = trim($_POST['token_name'] ?? 'api-token');

        $device = db_fetch("SELECT * FROM devices WHERE id = ?", [$target_device_id]);
        if (!$device) {
            $error = 'Device not found.';
        } else {
            // Revoke any existing active tokens for this device first
            db()->prepare("UPDATE device_tokens SET status = 'revoked', revoked_at = NOW() WHERE device_id = ? AND status = 'active'")
                ->execute([$target_device_id]);

            $result = generate_device_token($target_device_id, $token_name);
            log_event($target_device_id, null, 'device_token_created', 'info',
                "New API token generated for {$device['device_code']}.");

            // Pass token to view via session (shown only once)
            $_SESSION['new_token'] = [
                'raw_token' => $result['raw_token'],
                'device_code' => $device['device_code'],
                'token_name' => $token_name,
                'expires' => time() + 60, // Available for 60 seconds
            ];
            $success = 'Token generated. Copy it now — it will not be shown again.';
        }
    }

    // ── Revoke token ──
    if ($form_action === 'revoke_token') {
        $token_id = (int)($_POST['token_id'] ?? 0);
        $device_id_for_log = (int)($_POST['device_id_for_log'] ?? 0);

        if ($token_id > 0) {
            $revoked = revoke_device_token($token_id);
            if ($revoked) {
                $device = db_fetch("SELECT device_code FROM devices WHERE id = ?", [$device_id_for_log]);
                log_event($device_id_for_log, null, 'device_token_revoked', 'warning',
                    "API token revoked for " . ($device ? $device['device_code'] : 'device'));
                $success = 'Token revoked successfully.';
            } else {
                $error = 'Token not found or already revoked.';
            }
        }
    }

    // ── Delete device  ──
    if ($form_action === 'delete_device') {
        $target_device_id = (int)($_POST['device_id'] ?? 0);
        $device = db_fetch("SELECT * FROM devices WHERE id = ?", [$target_device_id]);
        if ($device) {
            // Check for dependent records
            $has_readings = db_count("SELECT COUNT(*) FROM sensor_readings WHERE device_id = ?", [$target_device_id]);
            $has_sessions = db_count("SELECT COUNT(*) FROM dispensing_sessions WHERE device_id = ?", [$target_device_id]);

            if ($has_readings > 0 || $has_sessions > 0) {
                $error = "Cannot delete device with existing sensor readings ({$has_readings}) or dispensing sessions ({$has_sessions}). Disable it instead.";
            } else {
                db()->prepare("DELETE FROM device_tokens WHERE device_id = ?")->execute([$target_device_id]);
                db()->prepare("DELETE FROM devices WHERE id = ?")->execute([$target_device_id]);
                log_event(null, null, 'device_deleted', 'warning',
                    "Device {$device['device_code']} deleted through device management.");
                $success = 'Device deleted.';
            }
        }
    }
}

// Clear token display after viewing
$show_token = null;
if (isset($_SESSION['new_token']) && $_SESSION['new_token']['expires'] > time()) {
    $show_token = $_SESSION['new_token'];
} else {
    unset($_SESSION['new_token']);
}

// Clear token display if acknowledged
if (isset($_GET['dismiss_token'])) {
    unset($_SESSION['new_token']);
    $show_token = null;
}

// ── Filters ─────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';

$where = '1=1';
$params = [];

if ($search !== '') {
    $where .= ' AND (device_name LIKE ? OR device_code LIKE ? OR esp32_identifier LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filter_status !== '') {
    $where .= ' AND status = ?';
    $params[] = $filter_status;
}

$p = paginate('devices', $where, $params, 15);
$devices = db_fetch_all(
    "SELECT * FROM devices WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$p['per_page'], $p['offset']])
);

// Fetch token info for all listed devices
$device_tokens = [];
foreach ($devices as $d) {
    $tokens = db_fetch_all(
        "SELECT * FROM device_tokens WHERE device_id = ? ORDER BY created_at DESC",
        [$d['id']]
    );
    $device_tokens[$d['id']] = $tokens;
}

$base_url = base_url('/pages/devices.php?' . http_build_query(array_filter(['search' => $search, 'status' => $filter_status])));

include __DIR__ . '/../includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= sanitize($error) ?></div>
<?php endif; ?>

<?php if ($show_token): ?>
<div class="card mb-3" style="border:2px solid var(--success);background:#f0fdf4">
    <div class="card-header" style="background:var(--success-light)"><h3 style="color:#15803d">New Device Token</h3></div>
    <div class="card-body">
        <div class="alert alert-warning" style="margin-bottom:1rem">
            <strong>Copy this token now.</strong> It will not be shown again after you close this message.
        </div>
        <table style="width:100%;margin-bottom:1rem">
            <tr><td class="text-muted" style="width:30%">Device</td><td style="font-weight:500"><?= sanitize($show_token['device_code']) ?></td></tr>
            <tr><td class="text-muted">Token Name</td><td><?= sanitize($show_token['token_name']) ?></td></tr>
            <tr><td class="text-muted">Token</td><td><code style="font-size:0.82rem;word-break:break-all;background:#fff;padding:0.3rem 0.5rem;border:1px solid var(--border-color);border-radius:4px;display:inline-block"><?= sanitize($show_token['raw_token']) ?></code></td></tr>
        </table>
        <div style="display:flex;gap:0.5rem;align-items:center">
            <button type="button" class="btn btn-sm btn-outline" onclick="navigator.clipboard.writeText(this.dataset.token).then(()=>this.textContent='Copied!')" data-token="<?= sanitize($show_token['raw_token']) ?>">Copy Token</button>
            <a href="<?= base_url('/pages/devices.php?dismiss_token=1') ?>" class="btn btn-sm btn-primary">I have copied it — dismiss</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add Device Form  -->

<div class="card mb-3">
    <div class="card-header"><h3>Register New Device</h3></div>
    <div class="card-body">
        <form method="POST" action="<?= base_url('/pages/devices.php') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="add_device">
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:end">
                <div class="form-group" style="margin:0;flex:1;min-width:140px">
                    <label>Device Code *</label>
                    <input type="text" name="device_code" class="form-control" placeholder="e.g. WB-DEV-002" required>
                </div>
                <div class="form-group" style="margin:0;flex:1;min-width:140px">
                    <label>ESP32 Identifier *</label>
                    <input type="text" name="esp32_identifier" class="form-control" placeholder="e.g. ESP32-WB-002" required>
                </div>
                <div class="form-group" style="margin:0;flex:1;min-width:140px">
                    <label>Device Name</label>
                    <input type="text" name="device_name" class="form-control" placeholder="e.g. WaterBonia Dispenser Beta">
                </div>
                <div class="form-group" style="margin:0;flex:1;min-width:120px">
                    <label>Location</label>
                    <input type="text" name="location" class="form-control" placeholder="e.g. Kitchen">
                </div>
                <div class="form-group" style="margin:0;min-width:120px">
                    <label>Firmware</label>
                    <input type="text" name="firmware_version" class="form-control" placeholder="e.g. 0.1.0">
                </div>
                <div class="form-group" style="margin:0">
                    <button type="submit" class="btn btn-primary">Register Device</button>
                </div>
            </div>
        </form>
    </div>
</div>


<div class="card mb-3">
    <div class="card-header"><h3>Filter</h3></div>
    <div class="card-body">
        <form method="GET" action="<?= base_url('/pages/devices.php') ?>">
            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:end">
                <div class="form-group" style="margin:0;flex:1;min-width:180px">
                    <label>Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Name, code, or ESP32 ID…" value="<?= sanitize($search) ?>">
                </div>
                <div class="form-group" style="margin:0;min-width:140px">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="">All</option>
                        <?php foreach (['online','offline','maintenance','disabled'] as $s): ?>
                            <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="<?= base_url('/pages/devices.php') ?>" class="btn btn-outline">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body-np">
        <?php if (empty($devices)): ?>
            <div class="empty-state">
                <div class="empty-icon">📡</div>
                <div class="empty-title">No Devices Found</div>
                <div class="empty-desc"><?= $search || $filter_status ? 'No devices match the current filters.' : 'No devices have been registered yet.' ?></div>
            </div>
        <?php else: ?>
            <?php foreach ($devices as $d): ?>
            <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border-color)">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:0.75rem">
                    <div style="flex:1;min-width:200px">
                        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.4rem">
                            <span style="font-weight:600;font-size:1rem"><?= sanitize($d['device_name'] ?: $d['device_code']) ?></span>
                            <?= status_badge($d['status']) ?>
                        </div>
                        <table style="width:100%;font-size:0.82rem">
                            <tr><td class="text-muted" style="width:35%;padding:0.15rem 0">Code</td><td style="padding:0.15rem 0"><code><?= sanitize($d['device_code']) ?></code></td></tr>
                            <tr><td class="text-muted" style="padding:0.15rem 0">ESP32 ID</td><td style="padding:0.15rem 0"><code><?= sanitize($d['esp32_identifier']) ?></code></td></tr>
                            <tr><td class="text-muted" style="padding:0.15rem 0">Location</td><td style="padding:0.15rem 0"><?= sanitize($d['location'] ?: '—') ?></td></tr>
                            <tr><td class="text-muted" style="padding:0.15rem 0">Firmware</td><td style="padding:0.15rem 0"><?= sanitize($d['firmware_version'] ?: '—') ?></td></tr>
                            <tr><td class="text-muted" style="padding:0.15rem 0">Last Seen</td><td style="padding:0.15rem 0"><?= time_ago($d['last_seen_at']) ?></td></tr>
                            <tr><td class="text-muted" style="padding:0.15rem 0">IP</td><td style="padding:0.15rem 0"><?= sanitize($d['ip_address'] ?: '—') ?></td></tr>
                        </table>
                    </div>

                    <div style="min-width:250px">
                        <div style="font-weight:600;font-size:0.8rem;color:var(--text-secondary);margin-bottom:0.5rem;text-transform:uppercase;letter-spacing:0.05em">API Tokens</div>
                        <?php
                        $tokens = $device_tokens[$d['id']] ?? [];
                        $active_tokens = array_filter($tokens, fn($t) => $t['status'] === 'active');
                        $other_tokens = array_filter($tokens, fn($t) => $t['status'] !== 'active');
                        ?>
                        <?php if (empty($active_tokens) && empty($other_tokens)): ?>
                            <div class="text-sm text-muted" style="margin-bottom:0.5rem">No tokens generated yet.</div>
                        <?php else: ?>
                            <?php foreach ($active_tokens as $t): ?>
                            <div style="display:flex;justify-content:space-between;align-items:center;padding:0.3rem 0;border-bottom:1px solid var(--border-color)">
                                <div>
                                    <span class="badge badge-success">Active</span>
                                    <span class="text-sm" style="margin-left:0.3rem"><?= sanitize($t['token_name'] ?: 'default') ?></span>
                                    <span class="text-muted text-sm">(<?= time_ago($t['created_at']) ?>)</span>
                                </div>

                                <form method="POST" action="<?= base_url('/pages/devices.php') ?>" style="display:inline" onsubmit="return confirm('Revoke this token? The device will stop working until a new token is generated.')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form_action" value="revoke_token">
                                    <input type="hidden" name="token_id" value="<?= $t['id'] ?>">
                                    <input type="hidden" name="device_id_for_log" value="<?= $d['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                                </form>

                            </div>
                            <?php endforeach; ?>
                            <?php foreach ($other_tokens as $t): ?>
                            <div style="display:flex;justify-content:space-between;align-items:center;padding:0.3rem 0;border-bottom:1px solid var(--border-color)">
                                <div>
                                    <?= status_badge($t['status']) ?>
                                    <span class="text-sm" style="margin-left:0.3rem"><?= sanitize($t['token_name'] ?: 'default') ?></span>
                                    <span class="text-muted text-sm">(<?= time_ago($t['created_at'] ?? $t['revoked_at']) ?>)</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>


                        <div style="margin-top:0.5rem">
                            <form method="POST" action="<?= base_url('/pages/devices.php') ?>" style="display:flex;gap:0.4rem;align-items:end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="generate_token">
                                <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                <input type="text" name="token_name" class="form-control" placeholder="Token name" style="width:120px;font-size:0.8rem">
                                <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Generate a new token? Any existing active token will be revoked.')">Generate Token</button>
                            </form>
                        </div>

                    </div>


                    <div style="min-width:80px;text-align:right">
                        <form method="POST" action="<?= base_url('/pages/devices.php') ?>" onsubmit="return confirm('Delete this device? This cannot be undone.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="delete_device">
                            <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
            <?= render_pagination($p, $base_url) ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
