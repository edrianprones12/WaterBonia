/**
 * WaterBonia ESP32 - API Client
 *
 * Handles all communication with the WaterBonia PHP server.
 * Uses Bearer token authentication.
 * Handles HTTP errors with bounded exponential backoff.
 * Does NOT block physical safety behavior.
 *
 * v2: adds command polling — the web UI queues a dispense command
 * and the ESP32 claims it here, then runs the local state machine.
 */

#ifndef API_CLIENT_H
#define API_CLIENT_H

#include <Arduino.h>

// A dispense command claimed from the server.
struct DispenseCommand {
    long command_id;
    char container_type[12];
    int  target_amount_ml;
};

// Initialize API client with device token
bool api_init(const String &device_token);

// Send heartbeat to server
bool api_send_heartbeat(const String &firmware_version,
                        const String &ip_address,
                        const String &wifi_ssid);

// Send sensor reading (measured_ml = live load-cell reading, -1 if none)
bool api_send_sensor_reading(int water_level_percent,
                             bool container_detected,
                             bool hand_detected,
                             bool pump_status,
                             int battery_percent,
                             int measured_ml);

// Poll for a pending dispense command.
// Returns true and fills `cmd` when a command was claimed.
bool api_poll_command(DispenseCommand &cmd);

// Report the outcome of a claimed command.
bool api_command_result(long command_id,
                        const String &result,
                        long session_id,
                        const String &reason);

// Start a dispensing session, returns session ID (0 on failure).
// `target_ml` is passed in and updated to the SERVER's authoritative
// target when the server responds with one.
long api_dispensing_start(bool container_detected,
                          int water_level_before,
                          const String &container_type,
                          long command_id,
                          int &target_ml);

// Complete a dispensing session with the measured volume
bool api_dispensing_complete(long session_id,
                             int water_level_after,
                             const String &stop_reason,
                             int measured_ml);

// Check if API client is in a retry-backoff state
bool api_in_backoff();

// Get last HTTP status code
int api_last_status();

#endif // API_CLIENT_H
