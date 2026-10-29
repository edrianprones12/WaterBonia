<?php
/**
 * WaterBonia - Settings Page
 */
require_once __DIR__ . '/../includes/functions.php';
require_valid_csrf();

$page_title = 'Settings';

$success = '';
$error = '';

if (is_post() && verify_csrf()) {
    $setting_key = $_POST['setting_key'] ?? '';
    $setting_value = $_POST['setting_value'] ?? '';

    $valid_keys = ['portal_device_id','container_cup_duration_seconds','container_tumbler_duration_seconds','system_name','timezone','low_water_threshold','dispensing_timeout_seconds','device_offline_timeout_seconds','default_water_capacity_ml','container_cup_target_ml','container_tumbler_target_ml','dispense_command_ttl_sec'];

    if (in_array($setting_key, ['portal_device_id','container_cup_duration_seconds','container_tumbler_duration_seconds'], true) && (portal_integer($setting_value) === null || ($setting_key !== 'portal_device_id' && (int)$setting_value > 30))) {
        $error = 'Duration must be a whole number from 0 to 30 seconds; 0 disables dispensing.';
    } elseif (!in_array($setting_key, $valid_keys)) {
        $error = 'Invalid setting.';
    } elseif ($setting_value === '') {
        $error = 'Setting value cannot be empty.';
    } else {
        // Validate numeric settings
        $numeric_keys = ['low_water_threshold','dispensing_timeout_seconds','device_offline_timeout_seconds','default_water_capacity_ml','container_cup_target_ml','container_tumbler_target_ml','dispense_command_ttl_sec'];
        $error = '';
        if (in_array($setting_key, $numeric_keys) && !is_numeric($setting_value)) {
            $error = 'This setting requires a numeric value.';
        } elseif ($error === '') {
            $value = (int)$setting_value;
            if (in_array($setting_key, ['container_cup_target_ml','container_tumbler_target_ml']) && ($value < 1 || $value > 2000)) {
                $error = 'Dispense target must be between 1 and 2000 mL.';
            } elseif ($setting_key === 'dispense_command_ttl_sec' && ($value < 5 || $value > 300)) {
                $error = 'Command TTL must be between 5 and 300 seconds.';
            } elseif ($setting_key === 'low_water_threshold' && ($value < 0 || $value > 100)) {
                $error = 'Low water threshold must be between 0 and 100 percent.';
            }
        }
        if ($error === '') {
            $old_value = get_setting($setting_key);
            db_update('system_settings', ['setting_value' => $setting_value, 'updated_by' => null], 'setting_key = ?', [$setting_key]);
            log_event(null, null, 'configuration_changed', 'info', "Setting changed: {$setting_key} from '{$old_value}' to '{$setting_value}'");
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
