/**
 * WaterBonia ESP32 - Sensor Module
 *
 * Handles all sensor reading and debouncing.
 * Reads container presence and optional diagnostic hand detection.
 * Reservoir water level is not measured by this hardware.
 *
 * v2: container presence is the sensor that gates dispensing.
 *     It reports PRESENCE ONLY — cup vs tumbler is chosen on the web.
 */

#ifndef SENSORS_H
#define SENSORS_H

#include <Arduino.h>
#include "config.h"

// Initialize all sensor pins
void sensors_init();

// Read container presence (IR / breakbeam / limit switch).
// Presence only — does NOT classify cup vs tumbler.
bool sensors_read_container();

// Legacy alias — older code and telemetry used the name "cup".
bool sensors_read_cup();

// Read hand detection (HC-SR04 ultrasonic) — diagnostic only.
// NOT a dispense trigger in v2.
bool sensors_read_hand();

// Get raw distance from HC-SR04 in cm (for diagnostics)
float sensors_read_distance_cm();

// Check if any sensor is in an error state
bool sensors_has_error();

// Clear sensor error state
void sensors_clear_error();

// ── Test Mode Simulation (TEST_MODE=1 only) ──────────────
// These functions set simulated sensor values for testing.
// They have no effect when TEST_MODE=0.
// Simulated values are NEVER sent to the production database.
#if TEST_MODE
void sensors_set_sim_container(bool detected);
void sensors_set_sim_cup(bool detected);      // alias of container
void sensors_set_sim_hand(bool detected);
void sensors_set_sim_error(bool error);
#endif

#endif // SENSORS_H
