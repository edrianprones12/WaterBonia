/**
 * WaterBonia ESP32 - Wi-Fi Manager Implementation
 */

#include "wifi_manager.h"
#include "config.h"
#include <WiFi.h>

static String _ssid;
static String _password;
static bool _connected = false;
static bool _ever_connected = false;
static unsigned long _last_reconnect_attempt = 0;
static int _retry_count = 0;

bool wifi_init(const String &ssid, const String &password) {
    _ssid = ssid;
    _password = password;
    _connected = false;
    _retry_count = 0;

    Serial.printf("[WIFI] Connecting to %s\n", _ssid.c_str());

    WiFi.mode(WIFI_STA);
    WiFi.begin(_ssid.c_str(), _password.c_str());

    unsigned long start = millis();
    while (WiFi.status() != WL_CONNECTED && (millis() - start) < WIFI_TIMEOUT_MS) {
        delay(500);
        Serial.print(".");
    }
    Serial.println();

    if (WiFi.status() == WL_CONNECTED) {
        _connected = true;
        _ever_connected = true;
        _retry_count = 0;
        Serial.printf("[WIFI] Connected. IP: %s\n", WiFi.localIP().toString().c_str());
        return true;
    }

    Serial.println("[WIFI] Connection failed. Will retry in background.");
    return false;
}

bool wifi_is_connected() {
    return WiFi.status() == WL_CONNECTED;
}

void wifi_loop() {
    bool now_connected = (WiFi.status() == WL_CONNECTED);

    if (now_connected != _connected) {
        if (now_connected) {
            Serial.printf("[WIFI] Connected. IP: %s\n", WiFi.localIP().toString().c_str());
            _ever_connected = true;
            _retry_count = 0;
        } else {
            Serial.println("[WIFI] Disconnected.");
        }
        _connected = now_connected;
    }

    // Reconnection logic with bounded exponential backoff
    if (!_connected) {
        unsigned long delay_ms = WIFI_RETRY_DELAY_MS * (1UL << min(_retry_count, 4));
        if (delay_ms > 60000) delay_ms = 60000; // Cap at 60 seconds

        if (millis() - _last_reconnect_attempt >= delay_ms) {
            _last_reconnect_attempt = millis();
            _retry_count++;

            Serial.printf("[WIFI] Reconnect attempt %d (delay %lums)\n", _retry_count, delay_ms);

            WiFi.disconnect();
            WiFi.begin(_ssid.c_str(), _password.c_str());
        }
    }
}

void wifi_reconnect() {
    _last_reconnect_attempt = 0; // Force immediate attempt
}

bool wifi_was_ever_connected() {
    return _ever_connected;
}

String wifi_get_ip() {
    if (_connected) return WiFi.localIP().toString();
    return "0.0.0.0";
}

String wifi_get_ssid() {
    return _ssid;
}
