<?php
/**
 * WaterBonia API - Device Authentication Middleware
 *
 * Authenticates ESP32 devices via Bearer token.
 * Never exposes raw tokens. Only hashes are stored and compared.
 */

function authenticate_device(): array
{
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
        api_error('Missing or invalid Authorization header. Expected: Bearer <token>', 401);
    }

    $raw_token = trim($m[1]);
    if ($raw_token === '' || strlen($raw_token) < 32) {
        api_error('Invalid device token format.', 401);
    }

    // Hash the provided token and look it up
    $token_hash = hash('sha256', $raw_token);

    $stmt = db()->prepare("
        SELECT dt.id, dt.device_id, dt.token_hash, dt.status, dt.expires_at,
               d.id AS dev_id, d.device_code, d.device_name, d.esp32_identifier, d.status AS device_status
        FROM device_tokens dt
        JOIN devices d ON dt.device_id = d.id
        WHERE dt.token_hash = ?
        LIMIT 1
    ");
    $stmt->execute([$token_hash]);
    $token_row = $stmt->fetch();

    if (!$token_row) {
        // Log invalid attempt without revealing which token was tried
        log_event(null, null, 'invalid_device_request', 'warning', 'API request with unknown device token rejected.');
        api_error('Invalid device token.', 401);
    }

    if ($token_row['status'] !== 'active') {
        if ($token_row['status'] === 'revoked') {
            log_event($token_row['device_id'], null, 'device_token_revoked', 'warning',
                "Revoked token used against device {$token_row['device_code']}.");
        }
        api_error('Device token is ' . $token_row['status'] . '.', 401);
    }

    // Check expiry
    if ($token_row['expires_at'] !== null && strtotime($token_row['expires_at']) < time()) {
        db()->prepare("UPDATE device_tokens SET status = 'expired' WHERE id = ?")->execute([$token_row['id']]);
        api_error('Device token has expired.', 401);
    }

    // Check device status
    if ($token_row['device_status'] === 'disabled') {
        api_error('This device has been disabled by an administrator.', 403);
    }

    // Update last_used_at
    db()->prepare("UPDATE device_tokens SET last_used_at = NOW() WHERE id = ?")->execute([$token_row['id']]);

    return [
        'token_id'   => (int)$token_row['id'],
        'device_id'  => (int)$token_row['device_id'],
        'device_code'=> $token_row['device_code'],
        'device_name'=> $token_row['device_name'],
        'esp32_identifier' => $token_row['esp32_identifier'],
    ];
}

function validate_device_identity(array $auth, array $body): void
{
    if (isset($body['device_id']) && portal_integer($body['device_id']) !== $auth['device_id']) api_error('Device identity mismatch.',403);
    // If the body contains device identifiers, verify they match the authenticated device
    if (isset($body['device_code']) && $body['device_code'] !== $auth['device_code']) {
        api_error('Device code does not match authenticated device.', 403);
    }
    if (isset($body['esp32_identifier']) && $body['esp32_identifier'] !== $auth['esp32_identifier']) {
        api_error('ESP32 identifier does not match authenticated device.', 403);
    }
}

/**
 * Generate a new device token. Returns the raw token (shown once).
 */
function generate_device_token(int $device_id, string $name = ''): array
{
    // Generate 48-byte cryptographically random token
    $raw_token = bin2hex(random_bytes(48));
    $token_hash = hash('sha256', $raw_token);

    $token_id = db_insert('device_tokens', [
        'device_id'  => $device_id,
        'token_hash' => $token_hash,
        'token_name' => $name,
        'status'     => 'active',
    ]);

    return [
        'token_id' => $token_id,
        'raw_token' => $raw_token,
    ];
}

/**
 * Revoke a device token.
 */
function revoke_device_token(int $token_id): bool
{
    $stmt = db()->prepare("UPDATE device_tokens SET status = 'revoked', revoked_at = NOW() WHERE id = ? AND status = 'active'");
    $stmt->execute([$token_id]);
    return $stmt->rowCount() > 0;
}
