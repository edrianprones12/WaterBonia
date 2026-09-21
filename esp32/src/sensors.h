/**
 * WaterBonia ESP32 - Sensor Module
 *
 * Handles all sensor reading and debouncing.
 * Water-level sensor implementation is isolated for easy replacement.
 * Sensor errors are reported cleanly — never assume tank is full on error.
 */

#ifndef SENSORS_H
#define SENSORS_H

#include <Arduino.h>

// Initialize all sensor pins
void sensors_init();

// Read hand detection (HC-SR04 ultrasonic)
// Returns true if an object is within the configured detection range.
bool sensors_read_hand();

// Read cup detection (IR sensor)
// Returns true if a cup is detected.
bool sensors_read_cup();

// Read water level as percentage (0-100)
// Returns -1 on sensor error.
int sensors_read_water_level();

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
void sensors_set_sim_cup(bool detected);
void sensors_set_sim_hand(bool detected);
void sensors_set_sim_water_level(int percent);
void sensors_set_sim_error(bool error);
#endif

#endif // SENSORS_H
