<?php
/**
 * WaterBonia API - Device Command Queue
 *
 * POST /api/device/poll-command  (Bearer token)
 *   ESP32 polls for pending commands from the web UI.
 *   Atomically claims the oldest pending, non-expired command.
 *
 * The web dashboard inserts commands into `device_commands`
 * directly (same server, session auth). The ESP32 consumes
 * them here.
 *
 * Command lifecycle:
 *   PENDING  → created by the web UI (request_dispense), TTL enforced
 *   CLAIMED  → dispatched here (transaction + FOR UPDATE); also means
 *              ACCEPTED / DISPENSING — the ESP32 owns it now
 *   COMPLETED→ linked to a session by dispensing/start, or reported by
 *              /api/device/command-result
 *   REJECTED → device refused (malformed target, safety preconditions)
 *   EXPIRED  → TTL elapsed while still pending
 *   CANCELLED→ user cancelled from the dashboard before it was claimed
 */

function handle_poll_command(array $auth): void
{
    rate_limit_check($auth['device_id'], 'poll_command', 120, 60);

    $body = require_json_body();
    validate_device_identity($auth, $body);

    $device_id = $auth['device_id'];

    // Expire any stale pending commands first (PENDING → EXPIRED), recording
    // exactly one activity event and one deduplicated alert per expiry wave.
    $expired_stmt = db()->prepare(
        "SELECT id, container_type, target_amount_ml FROM device_commands
          WHERE device_id = ? AND status = 'pending' AND expires_at < NOW()"
    );
    $expired_stmt->execute([$device_id]);
    $expired = $expired_stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($expired) {
        db_update(
            'device_commands',
            ['status' => 'expired'],
            "device_id = ? AND status = 'pending' AND expires_at < NOW()",
            [$device_id]
        );

        foreach ($expired as $stale) {
            log_event($device_id, null, 'command_expired', 'warning',
                "Command #{$stale['id']} expired before the device claimed it " .
                "(container: {$stale['container_type']}, target: {$stale['target_amount_ml']}mL).");
        }

        $open = db_fetch(
            "SELECT id FROM alerts WHERE device_id = ? AND alert_type = 'command_expired' AND status = 'active' LIMIT 1",
            [$device_id]
        );
        if (!$open) {
            create_alert($device_id, 'command_expired', 'medium',
                'Dispensing request expired',
                'A queued dispensing request expired before the device claimed it. Check the device connection and try again.');
        }
    }

    // Claim the oldest pending command atomically (transaction + row lock)
    db()->beginTransaction();
    try {
        $cmd = db_fetch(
            "SELECT * FROM device_commands
              WHERE device_id = ? AND status = 'pending'
              ORDER BY requested_at ASC
              LIMIT 1
              FOR UPDATE",
            [$device_id]
        );

        if (!$cmd || $cmd['command_type'] !== 'dispense') {
            db()->commit();
            api_success(['command' => null], 'No pending commands.');
        }

        // Server-authoritative target
        $container_type = $cmd['container_type'];
        $target_ml = (int)get_setting('container_' . $container_type . '_target_ml',
            $container_type === 'tumbler' ? '500' : '250');

        db_update('device_commands', [
            'status'     => 'claimed',
            'claimed_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$cmd['id']]);
        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        api_error('Failed to claim command.', 500);
    }

    log_event($device_id, null, 'dispensing_command_accepted', 'info',
        "Command #{$cmd['id']} accepted by device (container: {$container_type}, target: {$target_ml}mL).");

    api_success([
        'command' => [
            'command_id'       => (int)$cmd['id'],
            'command_type'     => 'dispense',
            'container_type'   => $container_type,
            'target_amount_ml' => $target_ml,
            'requested_at'     => $cmd['requested_at'],
            'expires_at'       => $cmd['expires_at'],
        ],
    ], 'Command dispatched.');
}

/**
 * POST /api/device/command-result  (Bearer token)
 * ESP32 reports the outcome of a claimed command.
 */
function handle_command_result(array $auth): void
{
    rate_limit_check($auth['device_id'], 'command_result', 60, 60);

    $body = require_json_body();
    validate_device_identity($auth, $body);

    $device_id = $auth['device_id'];

    if (empty($body['command_id'])) {
        api_error('command_id is required', 422);
    }

    $cmd = db_fetch(
        "SELECT * FROM device_commands WHERE id = ?",
        [(int)$body['command_id']]
    );
    if (!$cmd || (int)$cmd['device_id'] !== $device_id) {
        api_error('Command not found.', 404);
    }

    // Only a still-claimed command can be resolved here. Commands already
    // marked completed by dispensing/start keep their session linkage.
    if ($cmd['status'] !== 'claimed') {
        api_success(['command_id' => (int)$cmd['id'], 'status' => $cmd['status']],
            'Command already ' . $cmd['status'] . '.');
    }

    $result = $body['result'] ?? 'completed';
    if (!in_array($result, ['completed', 'rejected', 'cancelled'], true)) {
        api_error('result must be completed, rejected, or cancelled.', 422);
    }

    $data = ['status' => $result, 'completed_at' => date('Y-m-d H:i:s')];
    if (!empty($body['session_id'])) {
        $data['session_id'] = (int)$body['session_id'];
    }
    if (!empty($body['reason'])) {
        $data['reject_reason'] = (string)$body['reason'];
    }

    db_update('device_commands', $data, "id = ? AND status = 'claimed'", [$cmd['id']]);

    api_success(['command_id' => (int)$cmd['id'], 'status' => $result], 'Command result recorded.');
}
