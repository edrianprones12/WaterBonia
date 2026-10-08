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

// ── Internal: Minimal JSON field parsing ────────────────
// Kept dependency-free (no ArduinoJson). Only supports the flat
// shapes returned by the WaterBonia API.

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

static bool parse_json_string(const String &json, const String &key, char *out, size_t out_len) {
    int idx = json.indexOf("\"" + key + "\"");
    if (idx < 0) return false;
    idx = json.indexOf(":", idx);
    if (idx < 0) return false;
    idx = json.indexOf("\"", idx);   // opening quote of the value
    if (idx < 0) return false;
    idx++;
    int end = json.indexOf("\"", idx);
    if (end < 0) return false;

    int len = end - idx;
    if (len <= 0) return false;
    if ((size_t)len >= out_len) len = out_len - 1;
    json.substring(idx, idx + len).toCharArray(out, len + 1);
    return true;
}

// True when a JSON key holds an explicit null (e.g. "command":null)
static bool json_value_is_null(const String &json, const String &key) {
    int idx = json.indexOf("\"" + key + "\"");
    if (idx < 0) return false;
    idx = json.indexOf(":", idx);
    if (idx < 0) return false;
    idx++;
    while (idx < (int)json.length() && (json[idx] == ' ' || json[idx] == '\n' || json[idx] == '\r' || json[idx] == '\t')) idx++;
    return json.substring(idx, idx + 4) == "null";
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
                             bool container_detected,
                             bool hand_detected,
                             bool pump_status,
                             int battery_percent,
                             int measured_ml) {
    String body = "{";
    body += "\"water_level_percent\":" + String(water_level_percent) + ",";
    body += "\"container_detected\":" + String(container_detected ? "true" : "false") + ",";
    body += "\"cup_detected\":" + String(container_detected ? "true" : "false") + ",";
    body += "\"hand_detected\":" + String(hand_detected ? "true" : "false") + ",";
    body += "\"pump_status\":" + String(pump_status ? "true" : "false") + ",";
    if (measured_ml >= 0) {
        body += "\"measured_ml\":" + String(measured_ml) + ",";
    }
    if (battery_percent >= 0) {
        body += "\"battery_percent\":" + String(battery_percent);
    } else {
        body += "\"battery_percent\":null";
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

// ── Command Polling ──────────────────────────────────────

bool api_poll_command(DispenseCommand &cmd) {
    String response;
    int code = http_post("/device/poll-command", "{}", response);

    if (code == -2) return false;   // backoff
    if (code == -1) return false;   // no Wi-Fi

    if (code == 401) {
        Serial.println("[API] Token rejected (401) while polling.");
        _in_backoff = true;
        _backoff_until = millis() + 300000;
        return false;
    }

    if (code < 200 || code >= 300) {
        Serial.printf("[API] Command poll failed: %d\n", code);
        return false;
    }

    // "command":null → nothing to do
    if (json_value_is_null(response, "command")) {
        return false;
    }

    cmd.command_id = parse_json_long(response, "command_id");
    cmd.target_amount_ml = (int)parse_json_long(response, "target_amount_ml");
    cmd.container_type[0] = '\0';
    parse_json_string(response, "container_type", cmd.container_type, sizeof(cmd.container_type));

    if (cmd.command_id <= 0 || cmd.target_amount_ml <= 0 || cmd.container_type[0] == '\0') {
        Serial.println("[API] Malformed command payload — ignoring.");
        return false;
    }

    Serial.printf("[API] Command #%ld: dispense %d mL (%s)\n",
                  cmd.command_id, cmd.target_amount_ml, cmd.container_type);
    return true;
}

bool api_command_result(long command_id,
                        const String &result,
                        long session_id,
                        const String &reason) {
    if (command_id <= 0) return false;

    String body = "{";
    body += "\"command_id\":" + String(command_id) + ",";
    body += "\"result\":\"" + result + "\",";
    body += "\"session_id\":" + String(session_id) + ",";
    body += "\"reason\":\"" + reason + "\"";
    body += "}";

    String response;
    int code = http_post("/device/command-result", body, response);
    return (code >= 200 && code < 300);
}

// ── Dispensing Start ─────────────────────────────────────

long api_dispensing_start(bool container_detected,
                          int water_level_before,
                          const String &container_type,
                          long command_id,
                          int &target_ml) {
    String body = "{";
    body += "\"container_detected\":" + String(container_detected ? "true" : "false") + ",";
    body += "\"cup_detected\":" + String(container_detected ? "true" : "false") + ",";
    body += "\"container_type\":\"" + container_type + "\",";
    body += "\"target_amount_ml\":" + String(target_ml) + ",";
    body += "\"water_level_before\":" + String(water_level_before) + ",";
    body += "\"command_id\":" + String(command_id);
    body += "}";

    String response;
    int code = http_post("/device/dispensing/start", body, response);

    if (code >= 200 && code < 300) {
        long session_id = parse_json_long(response, "session_id");
        if (session_id > 0) {
            Serial.printf("[API] Session started: #%ld\n", session_id);

            // Adopt the server's authoritative target when provided.
            long server_target = parse_json_long(response, "target_amount_ml");
            if (server_target > 0 && server_target <= MAX_TARGET_ML) {
                target_ml = (int)server_target;
                Serial.printf("[API] Server target adopted: %d mL\n", target_ml);
            }
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
                             const String &stop_reason,
                             int measured_ml) {
    if (session_id <= 0) {
        // No valid session to complete
        Serial.println("[API] No valid session ID. Skipping complete.");
        return false;
    }

    String body = "{";
    body += "\"session_id\":" + String(session_id) + ",";
    body += "\"water_level_after\":" + String(water_level_after) + ",";
    body += "\"stop_reason\":\"" + stop_reason + "\",";
    body += "\"measured_amount_ml\":" + String(measured_ml) + ",";
    body += "\"amount_dispensed_ml\":" + String(measured_ml);
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
