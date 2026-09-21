/**
 * WaterBonia ESP32 - NVS Storage Implementation
 */

#include "storage.h"
#include <Preferences.h>

static Preferences prefs;

// Namespace for WaterBonia credentials
static const char* NS = "waterbonia";

bool storage_init() {
    // Preferences library initializes on first use
    // No explicit init needed
    return true;
}

bool storage_is_provisioned() {
    String token;
    String ssid;
    return storage_get_device_token(token) &&
           storage_get_wifi_ssid(ssid) &&
           token.length() > 0 && ssid.length() > 0;
}

// ── Wi-Fi ────────────────────────────────────────────────

bool storage_get_wifi_ssid(String &ssid) {
    prefs.begin(NS, true); // read-only
    ssid = prefs.getString("wifi_ssid", "");
    prefs.end();
    return ssid.length() > 0;
}

bool storage_get_wifi_password(String &password) {
    prefs.begin(NS, true);
    password = prefs.getString("wifi_pass", "");
    prefs.end();
    return password.length() > 0;
}

bool storage_set_wifi(const String &ssid, const String &password) {
    prefs.begin(NS, false); // read-write
    prefs.putString("wifi_ssid", ssid);
    prefs.putString("wifi_pass", password);
    prefs.end();
    return true;
}

// ── Device Token ─────────────────────────────────────────

bool storage_get_device_token(String &token) {
    prefs.begin(NS, true);
    token = prefs.getString("dev_token", "");
    prefs.end();
    return token.length() > 0;
}

bool storage_set_device_token(const String &token) {
    prefs.begin(NS, false);
    prefs.putString("dev_token", token);
    prefs.end();
    return true;
}

// ── Device Code ──────────────────────────────────────────

bool storage_get_device_code(String &code) {
    prefs.begin(NS, true);
    code = prefs.getString("dev_code", "");
    prefs.end();
    return code.length() > 0;
}

bool storage_set_device_code(const String &code) {
    prefs.begin(NS, false);
    prefs.putString("dev_code", code);
    prefs.end();
    return true;
}

// ── Clear All ────────────────────────────────────────────

bool storage_clear_all() {
    prefs.begin(NS, false);
    prefs.clear();
    prefs.end();
    Serial.println("[STORAGE] All credentials cleared.");
    return true;
}

// ── Serial Provisioning ──────────────────────────────────

void storage_serial_provision() {
    Serial.println("\n========================================");
    Serial.println("  WaterBonia Device Provisioning");
    Serial.println("========================================");
    Serial.println("Enter Wi-Fi SSID:");
    Serial.print("> ");
    while (!Serial.available()) delay(10);
    String ssid = Serial.readStringUntil('\n');
    ssid.trim();

    Serial.println("Enter Wi-Fi Password:");
    Serial.print("> ");
    while (!Serial.available()) delay(10);
    String password = Serial.readStringUntil('\n');
    password.trim();

    Serial.println("Enter Device Token (from web dashboard):");
    Serial.print("> ");
    while (!Serial.available()) delay(10);
    String token = Serial.readStringUntil('\n');
    token.trim();

    Serial.println("Enter Device Code (e.g. WB-DEV-001):");
    Serial.print("> ");
    while (!Serial.available()) delay(10);
    String code = Serial.readStringUntil('\n');
    code.trim();

    if (ssid.length() == 0 || token.length() == 0) {
        Serial.println("[ERROR] SSID and token are required. Provisioning cancelled.");
        return;
    }

    storage_set_wifi(ssid, password);
    storage_set_device_token(token);
    if (code.length() > 0) {
        storage_set_device_code(code);
    }

    Serial.println("[STORAGE] Credentials saved to NVS.");
    Serial.println("[STORAGE] Rebooting in 3 seconds...");
    delay(3000);
    ESP.restart();
}
