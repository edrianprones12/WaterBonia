/**
 * WaterBonia ESP32 - NVS Storage Manager
 *
 * Manages non-volatile storage for Wi-Fi credentials and device token.
 * Values survive reboot. Token is never printed in normal serial logs.
 */

#ifndef STORAGE_H
#define STORAGE_H

#include <Arduino.h>

bool storage_init();
bool storage_is_provisioned();

// Wi-Fi
bool storage_get_wifi_ssid(String &ssid);
bool storage_get_wifi_password(String &password);
bool storage_set_wifi(const String &ssid, const String &password);

// Device token
bool storage_get_device_token(String &token);
bool storage_set_device_token(const String &token);

// Device code
bool storage_get_device_code(String &code);
bool storage_set_device_code(const String &code);

// Clear all stored credentials (factory reset)
bool storage_clear_all();

// Provision via serial input
void storage_serial_provision();

#endif // STORAGE_H
