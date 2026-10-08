<?php
/**
 * WaterBonia - Demo Mode (presentation layer only)
 *
 * PURPOSE
 *   The ESP32 / load cell / sensors / pump are not built yet. Demo Mode lets
 *   the full HCI flow (Choose -> Place -> Dispense -> Complete) be presented
 *   without hardware, using SIMULATED state only.
 *
 *   Demo Mode is a presentation layer. It never:
 *     - writes simulated telemetry into sensor_readings
 *     - creates fake dispensing_sessions, activity_logs or alerts
 *     - sends fake ESP32 telemetry through the production API
 *     - changes the real device command / safety architecture
 *   All simulation state lives in the browser (assets/js/dispenser.js).
 *
 * SWITCHING
 *   config/demo.php : $DEMO_MODE = true   -> Demo Mode active (presentation)
 *                     $DEMO_MODE = false  -> real hardware mode (production)
 *   The DEMO_MODE environment variable overrides the file value, e.g.
 *     SetEnv DEMO_MODE false      (Apache httpd.conf / .htaccess)
 *
 *   Demo Mode is always announced in the UI by the "DEMO MODE" banner, so it
 *   can never be mistaken for a real connected device.
 */

$DEMO_MODE = true; // ← flip to false for the real hardware system

if (!defined('WATERBONIA_DEMO_MODE')) {
    $env = getenv('DEMO_MODE');
    define(
        'WATERBONIA_DEMO_MODE',
        $env !== false ? (bool)filter_var($env, FILTER_VALIDATE_BOOLEAN) : (bool)$DEMO_MODE
    );
}

/**
 * Header availability pill while Demo Mode is active.
 * Never reports ESP32 ONLINE / SYSTEM AVAILABLE.
 */
function demo_availability(): array
{
    return [
        'key'   => 'demo',
        'label' => 'DEMO MODE',
        'tone'  => 'warning',
        'hint'  => 'Simulation — no physical hardware connected.',
    ];
}

/**
 * RGB LED card while Demo Mode is active.
 * Keys: blue | cyan | green | complete | yellow | red
 *
 * @return array{color:string,hex:string,label:string,description:string,confirmed:bool,simulated:bool}
 */
function demo_led(string $color = 'blue'): array
{
    $map = [
        'blue' => [
            'color'       => 'blue',
            'hex'         => '#2563eb',
            'label'       => 'BLUE — READY / WAITING',
            'description' => 'Simulated: the dispenser is idle and waiting for a container.',
        ],
        'cyan' => [
            'color'       => 'cyan',
            'hex'         => '#06b6d4',
            'label'       => 'CYAN — CONTAINER DETECTED',
            'description' => 'Simulated container detected on the dispenser platform.',
        ],
        'green' => [
            'color'       => 'green',
            'hex'         => '#16a34a',
            'label'       => 'GREEN — DISPENSING',
            'description' => 'Simulated dispensing: the pump is running toward the target volume.',
        ],
        'complete' => [
            'color'       => 'complete',
            'hex'         => '#22c55e',
            'label'       => 'WHITE / GREEN — COMPLETE',
            'description' => 'Simulated: the pump stopped because the target volume was reached.',
        ],
        'yellow' => [
            'color'       => 'yellow',
            'hex'         => '#eab308',
            'label'       => 'YELLOW — LOW WATER',
            'description' => 'Simulated low water: refill required before dispensing can continue.',
        ],
        'red' => [
            'color'       => 'red',
            'hex'         => '#ef4444',
            'label'       => 'RED — ERROR / INTERRUPTED',
            'description' => 'Simulated fault: dispensing stopped or the device is unavailable.',
        ],
    ];

    if (!isset($map[$color])) {
        $color = 'blue';
    }

    return $map[$color] + ['confirmed' => false, 'simulated' => true];
}
