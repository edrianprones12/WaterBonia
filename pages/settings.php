<?php
/**
 * WaterBonia - Settings Page
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();

$page_title = 'Settings';

if (!can_edit()) {
    echo '<div class="alert alert-danger">You do not have permission to modify settings.</div>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$success = '';
$error = '';

if (is_post() && verify_csrf()) {
    $setting_key = $_POST['setting_key'] ?? '';
    $setting_value = $_POST['setting_value'] ?? '';

    $valid_keys = ['system_name','timezone','low_water_threshold','dispensing_timeout_seconds','device_offline_timeout_seconds','default_water_capacity_ml'];

    if (!in_array($setting_key, $valid_keys)) {
        $error = 'Invalid setting.';
    } elseif ($setting_value === '') {
        $error = 'Setting value cannot be empty.';
    } else {
        // Validate numeric settings
        $numeric_keys = ['low_water_threshold','dispensing_timeout_seconds','device_offline_timeout_seconds','default_water_capacity_ml'];
        if (in_array($setting_key, $numeric_keys) && !is_numeric($setting_value)) {
            $error = 'This setting requires a numeric value.';
        } else {
            $old_value = get_setting($setting_key);
            db_update('system_settings', ['setting_value' => $setting_value], 'setting_key = ?', [$setting_key]);
            $user = current_user();
            log_event(null, $user['id'], 'configuration_changed', 'info', "Setting changed: {$setting_key} from '{$old_value}' to '{$setting_value}'");
            $success = 'Setting updated successfully.';
        }
    }
}

$settings = db_fetch_all("SELECT ss.*, u.username as updated_by_name FROM system_settings ss LEFT JOIN users u ON ss.updated_by = u.id ORDER BY ss.id");

include __DIR__ . '/../includes/header.php';
?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= sanitize($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h3>System Settings</h3></div>
    <div class="card-body-np">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Setting</th>
                        <th>Value</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Last Updated</th>
                        <th>By</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($settings as $s): ?>
                    <tr>
                        <td style="font-weight:500"><?= sanitize($s['setting_key']) ?></td>
                        <td>
                            <form method="POST" action="<?= base_url('/pages/settings.php') ?>" style="display:flex;gap:0.4rem;align-items:end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="setting_key" value="<?= sanitize($s['setting_key']) ?>">
                                <?php if ($s['value_type'] === 'string'): ?>
                                    <input type="text" name="setting_value" class="form-control" value="<?= sanitize($s['setting_value']) ?>" style="width:160px">
                                <?php else: ?>
                                    <input type="number" name="setting_value" class="form-control" value="<?= sanitize($s['setting_value']) ?>" style="width:100px">
                                <?php endif; ?>
                                <button type="submit" class="btn btn-sm btn-primary">Save</button>
                            </form>
                        </td>
                        <td><span class="badge badge-secondary"><?= $s['value_type'] ?></span></td>
                        <td class="text-sm text-muted"><?= sanitize($s['description']) ?></td>
                        <td class="text-sm"><?= format_datetime($s['updated_at']) ?></td>
                        <td class="text-sm"><?= $s['updated_by_name'] ? sanitize($s['updated_by_name']) : '—' ?></td>
                        <td></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
