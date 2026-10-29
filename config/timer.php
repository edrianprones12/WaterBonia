<?php
/** Timer protocol v2. Zero duration means uncalibrated: never energize the relay. */
const TIMER_PROTOCOL = 2;
const TIMER_HARD_MAX_MS = 30000;

function timer_duration_ms(string $type): ?int
{
    if (!in_array($type, ['cup', 'tumbler'], true)) return null;
    $seconds = portal_integer(get_setting("container_{$type}_duration_seconds", '0'));
    return $seconds !== null && $seconds >= 1 && $seconds <= 30 ? $seconds * 1000 : null;
}

function timer_presets(): array
{
    $out = [];
    foreach (['cup', 'tumbler'] as $type) $out[$type] = ['duration_ms' => timer_duration_ms($type), 'nominal_target_ml' => portal_container_target_ml($type)];
    return $out;
}

function timer_valid_duration(mixed $value): bool
{
    $n = portal_integer($value);
    return $n !== null && $n >= 1000 && $n <= TIMER_HARD_MAX_MS;
}

function timer_sensor_valid(?array $reading, ?array $device): bool
{
    return $reading !== null && $device !== null
        && ($reading['sensor_status'] ?? '') === 'ok'
        && ($reading['boot_id'] ?? '') !== ''
        && ($reading['boot_id'] ?? '') === ($device['boot_id'] ?? null)
        && in_array($reading['container_detected'] ?? null, [0,1,'0','1'], true)
        && in_array($reading['pump_status'] ?? null, [0,1,'0','1'], true);
}

function timer_result_status(string $reason, int $elapsed, int $configured): ?string
{
    if ($elapsed < 0 || $elapsed > TIMER_HARD_MAX_MS + 1000 || !timer_valid_duration($configured)) return null;
    if ($reason === 'duration_elapsed') return $elapsed >= $configured && $elapsed <= $configured + 500 ? 'completed' : null;
    if (in_array($reason, ['container_removed','manual_stop'], true)) return 'interrupted';
    if (in_array($reason, ['sensor_fault','max_runtime_exceeded','device_reset','error'], true)) return 'failed';
    return null;
}
