/**
 * WaterBonia ESP32 - API Client Implementation
 */

#include "api_client.h"
#include "config.h"
#include <WiFi.h>
#include <HTTPClient.h>

static String _device_token;
static int _last_status = 0;
static bool _in_backoff = false;
static unsigned long _backoff_until = 0;
static int _consecutive_failures = 0;

bool api_init(const String &device_token) {
    _device_token = device_token;
    _last_status = 0;
    _in_backoff = false;
    _consecutive_failures = 0;
    return _device_token.length() > 0;
}

// ── Internal: Execute HTTP POST ──────────────────────────

static int http_post(const String &path, const String &json_body, String &response) {
    if (!WiFi.isConnected()) {
        response = "Wi-Fi not connected";
        return -1;
    }

    // Respect backoff
    if (_in_backoff && millis() < _backoff_until) {
        response = "In backoff period";
        return -2;
    }
    _in_backoff = false;

    String url = String(API_BASE_URL) + path;

    HTTPClient http;
    http.begin(url);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Authorization", "Bearer " + _device_token);
    http.setTimeout(API_TIMEOUT_MS);

    int httpCode = http.POST(json_body);

    if (httpCode > 0) {
        response = http.getString();
        _last_status = httpCode;

        if (httpCode >= 200 && httpCode < 300) {
            _consecutive_failures = 0;
        } else {
            _consecutive_failures++;
        }
    } else {
        response = http.errorToString(httpCode);
        _last_status = httpCode;
        _consecutive_failures++;
    }

    http.end();

    // Apply backoff after consecutive failures
    if (_consecutive_failures >= 3) {
        unsigned long backoff_ms = API_RETRY_BASE_MS * (1UL << min(_consecutive_failures - 3, 4));
        if (backoff_ms > 120000) backoff_ms = 120000; // Cap at 2 minutes
        _backoff_until = millis() + backoff_ms;
        _in_backoff = true;
        Serial.printf("[API] Backoff: %lums after %d failures\n", backoff_ms, _consecutive_failures);
    }

    return httpCode;
}

// ── Internal: Parse JSON field from response ─────────────

static long parse_json_long(const String &json, const String &key) {
    int idx = json.indexOf("\"" + key + "\"");
    if (idx < 0) return 0;
    idx = json.indexOf(":", idx);
    if (idx < 0) return 0;
    idx++;
    while (idx < (int)json.length() && json[idx] == ' ') idx++;
    String numStr = "";
    while (idx < (int)json.length() && (json[idx] >= '0' && json[idx] <= '9')) {
        numStr += json[idx++];
    }
    return numStr.toInt();
}

// ── Heartbeat ────────────────────────────────────────────

bool api_send_heartbeat(const String &firmware_version,
                        const String &ip_address,
                        const String &wifi_ssid) {
    String body = "{";
    body += "\"firmware_version\":\"" + firmware_version + "\",";
    body += "\"ip_address\":\"" + ip_address + "\",";
    body += "\"wifi_ssid\":\"" + wifi_ssid + "\"";
    body += "}";

    String response;
    int code = http_post("/device/heartbeat", body, response);

    if (code >= 200 && code < 300) {
        Serial.println("[API] Heartbeat OK");
        return true;
    }

    if (code == 401) {
        Serial.println("[API] ERROR: Token rejected (401). Check device token.");
        // Do NOT continuously retry invalid token
        _in_backoff = true;
        _backoff_until = millis() + 300000; // 5 minutes
        return false;
    }

    Serial.printf("[API] Heartbeat failed: %d\n", code);
    return false;
}

// ── Sensor Reading ───────────────────────────────────────

bool api_send_sensor_reading(int water_level_percent,
                             bool cup_detected,
                             bool hand_detected,
                             bool pump_status,
                             int battery_percent) {
    String body = "{";
    body += "\"water_level_percent\":" + String(water_level_percent) + ",";
    body += "\"cup_detected\":" + String(cup_detected ? "true" : "false") + ",";
    body += "\"hand_detected\":" + String(hand_detected ? "true" : "false") + ",";
    body += "\"pump_status\":" + String(pump_status ? "true" : "false");
    if (battery_percent >= 0) {
        body += ",\"battery_percent\":" + String(battery_percent);
    } else {
        body += ",\"battery_percent\":null";
    }
    body += "}";

    String response;
    int code = http_post("/device/sensor-reading", body, response);

    if (code >= 200 && code < 300) {
        return true;
    }

    if (code == 429) {
        Serial.println("[API] Rate limited (429). Backing off.");
        return false;
    }

    if (code == 401) {
        Serial.println("[API] Token rejected (401).");
        _in_backoff = true;
        _backoff_until = millis() + 300000;
        return false;
    }

    Serial.printf("[API] Sensor reading failed: %d\n", code);
    return false;
}

// ── Dispensing Start ─────────────────────────────────────

long api_dispensing_start(bool cup_detected,
                          bool hand_detected,
                          int water_level_before) {
    String body = "{";
    body += "\"cup_detected\":" + String(cup_detected ? "true" : "false") + ",";
    body += "\"hand_detected\":" + String(hand_detected ? "true" : "false") + ",";
    body += "\"water_level_before\":" + String(water_level_before);
    body += "}";

    String response;
    int code = http_post("/device/dispensing/start", body, response);

    if (code >= 200 && code < 300) {
        long session_id = parse_json_long(response, "session_id");
        if (session_id > 0) {
            Serial.printf("[API] Session started: #%ld\n", session_id);
            return session_id;
        }
    }

    if (code == 409) {
        Serial.println("[API] Active session already exists (409).");
        return 0;
    }

    Serial.printf("[API] Dispensing start failed: %d\n", code);
    return 0; // Return 0 — local dispensing continues regardless
}

// ── Dispensing Complete ──────────────────────────────────

bool api_dispensing_complete(long session_id,
                             int water_level_after,
                             const String &stop_reason) {
    if (session_id <= 0) {
        // No valid session to complete
        Serial.println("[API] No valid session ID. Skipping complete.");
        return false;
    }

    String body = "{";
    body += "\"session_id\":" + String(session_id) + ",";
    body += "\"water_level_after\":" + String(water_level_after) + ",";
    body += "\"stop_reason\":\"" + stop_reason + "\",";
    body += "\"amount_dispensed_ml\":null";
    body += "}";

    String response;
    int code = http_post("/device/dispensing/complete", body, response);

    if (code >= 200 && code < 300) {
        Serial.printf("[API] Session #%ld completed\n", session_id);
        return true;
    }

    Serial.printf("[API] Dispensing complete failed: %d\n", code);
    return false;
}

// ── Status ───────────────────────────────────────────────

bool api_in_backoff() {
    return _in_backoff && millis() < _backoff_until;
}

int api_last_status() {
    return _last_status;
}
