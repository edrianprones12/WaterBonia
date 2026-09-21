/**
 * WaterBonia ESP32 - Wi-Fi Manager
 *
 * Handles connection, reconnection, and status.
 * Non-blocking retry with bounded backoff.
 * Dispenser continues local safety behavior when Wi-Fi is unavailable.
 */

#ifndef WIFI_MANAGER_H
#define WIFI_MANAGER_H

#include <Arduino.h>

bool wifi_init(const String &ssid, const String &password);
bool wifi_is_connected();
void wifi_loop();  // Call from main loop for reconnection logic
String wifi_get_ip();
String wifi_get_ssid();

// Force a reconnection attempt
void wifi_reconnect();

// Check if Wi-Fi has been connected at least once
bool wifi_was_ever_connected();

#endif // WIFI_MANAGER_H
