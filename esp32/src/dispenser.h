/**
 * WaterBonia ESP32 - Dispenser State Machine
 *
 * Local-first safety: the ESP32 decides pump ON/OFF locally.
 * The website can only REQUEST dispensing; local sensors always
 * have the final say. Pump safety never depends on Wi-Fi or server.
 *
 * v2 flow (mirrors the 4-step web UI):
 *   Choose container (web)  → command queue
 *   Place container         → READY → CONTAINER_DETECTED → WAITING_FOR_COMMAND
 *   Dispense                → dispenser_request_dispense() → DISPENSING
 *   Complete                → load cell reaches target → pump OFF → COMPLETE
 *
 * State Machine:
 *   INIT → WIFI_CONNECTING → READY
 *   READY → CONTAINER_DETECTED → WAITING_FOR_COMMAND → DISPENSING
 *   DISPENSING → COMPLETE (target reached) → READY
 *   any state → LOW_WATER (water below local cutoff)
 *   any state → ERROR (sensor/load-cell fault)
 *   LOW_WATER / ERROR → READY (when recovered)
 *
 * PUMP SAFETY INVARIANT (must hold at ALL times):
 *   Pump ON is permitted ONLY when:
 *     container_detected == true
 *     AND an accepted dispense request is active
 *     AND water_level > LOCAL_PUMP_CUTOFF_PERCENT
 *     AND the load cell is reporting valid samples
 *           (unless ALLOW_DISPENSE_WITHOUT_LOAD_CELL is set for bench tests)
 *     AND dispense timeout has not been exceeded
 *     AND no emergency stop is active
 *   All other conditions require Pump OFF.
 */

#ifndef DISPENSER_H
#define DISPENSER_H

#include <Arduino.h>
#include "config.h"

// Initialize dispenser (sets pump to safe state)
void dispenser_init();

// Main loop — reads sensors, manages state, controls pump
// Returns current state for LED and API use
DispenserState dispenser_loop();

// Queue a dispense request (from a server command in main.cpp).
// container_type: "cup" or "tumbler"; target_ml: server-authoritative target.
// command_id: device_commands.id (0 for a local trigger).
// Returns false if the request is rejected immediately (bad input / not allowed).
bool dispenser_request_dispense(const char *container_type, int target_ml, long command_id);

// Get current state name for logging
const char* dispenser_state_name(DispenserState state);

// Get current state (for external queries)
DispenserState dispenser_get_state();

// Container presence (sensor)
bool dispenser_get_container();

// Hand presence (diagnostic only — never a dispense trigger)
bool dispenser_get_hand();

// Current water level (last reading), -1 on error
int dispenser_get_water_level();

// Current pump state
bool dispenser_get_pump();

// Latest measured dispensed volume in mL (load cell)
int dispenser_get_measured_ml();

// Active target in mL (0 when idle)
int dispenser_get_target_ml();

// Container type of the active/idle request ("cup", "tumbler", or "")
const char* dispenser_get_container_type();

// Active API session ID (0 if none)
long dispenser_get_session_id();

// Active device_commands id (0 if none)
long dispenser_get_command_id();

// True while a request is queued but not yet dispensing
bool dispenser_has_pending_request();

// Force pump OFF (emergency stop)
void dispenser_emergency_stop();

// Set state externally (e.g., from main after Wi-Fi event)
void dispenser_set_state(DispenserState new_state);

#endif // DISPENSER_H
