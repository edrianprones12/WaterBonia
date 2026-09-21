/**
 * WaterBonia ESP32 - Dispenser State Machine
 *
 * Local-first safety: the ESP32 decides pump ON/OFF locally.
 * Server communication is for monitoring only.
 * Pump safety does NOT depend on Wi-Fi or server availability.
 *
 * State Machine:
 *   INIT → WIFI_CONNECTING → READY
 *   READY → CUP_DETECTED → WAITING_FOR_HAND → DISPENSING
 *   any state → LOW_WATER (when water below cutoff)
 *   any state → ERROR (on sensor failure)
 *   LOW_WATER → READY (when water recovers)
 *   ERROR → READY (when sensor recovers)
 *
 * Pump ON is permitted ONLY when:
 *   cup_detected == true
 *   AND hand_detected == true
 *   AND water_level > LOCAL_PUMP_CUTOFF_PERCENT
 *   AND no sensor safety fault exists
 *   AND dispense timeout has not been exceeded
 *   AND emergency stop is not active
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

// Get current state name for logging
const char* dispenser_state_name(DispenserState state);

// Get current state (for external queries)
DispenserState dispenser_get_state();

// Get current water level (last reading)
int dispenser_get_water_level();

// Get current cup detection state
bool dispenser_get_cup();

// Get current hand detection state
bool dispenser_get_hand();

// Get current pump state
bool dispenser_get_pump();

// Get active session ID (0 if no active session)
long dispenser_get_session_id();

// Force pump OFF (emergency stop)
void dispenser_emergency_stop();

// Set state externally (e.g., from main after Wi-Fi event)
void dispenser_set_state(DispenserState new_state);

#endif // DISPENSER_H
