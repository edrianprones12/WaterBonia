/**
 * WaterBonia ESP32 - API Client
 *
 * Handles all communication with the WaterBonia PHP server.
 * Uses Bearer token authentication.
 * Handles HTTP errors with bounded exponential backoff.
 * Does NOT block physical safety behavior.
 */

#ifndef API_CLIENT_H
#define API_CLIENT_H

#include <Arduino.h>

// Initialize API client with device token
bool api_init(const String &device_token);

// Send heartbeat to server
bool api_send_heartbeat(const String &firmware_version,
                        const String &ip_address,
                        const String &wifi_ssid);

// Send sensor reading
bool api_send_sensor_reading(int water_level_percent,
                             bool cup_detected,
                             bool hand_detected,
                             bool pump_status,
                             int battery_percent);

// Start a dispensing session, returns session ID (0 on failure)
long api_dispensing_start(bool cup_detected,
                          bool hand_detected,
                          int water_level_before);

// Complete a dispensing session
bool api_dispensing_complete(long session_id,
                             int water_level_after,
                             const String &stop_reason);

// Check if API client is in a retry-backoff state
bool api_in_backoff();

// Get last HTTP status code
int api_last_status();

#endif // API_CLIENT_H
