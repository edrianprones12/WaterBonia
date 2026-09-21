/**
 * WaterBonia ESP32 - Main Firmware
 *
 * Architecture:
 *   PHYSICAL SENSORS → ESP32 LOCAL CONTROL → LOCAL SAFETY → PUMP
 *   ESP32 → Wi-Fi → WaterBonia PHP API → MySQL → Web Dashboard
 *
 * The API is for monitoring and records.
 * The ESP32 is responsible for immediate physical control and safety.
 *
 * IMPORTANT: This firmware does NOT generate device tokens.
 * Tokens are obtained from the WaterBonia web dashboard and stored in NVS.
 *
 * TEST_MODE:
 *   TEST_MODE=0: Normal hardware operation, normal API communication.
 *   TEST_MODE=1: Simulated/local sensor states, state machine testing,
 *                pump/LED behavior verification.
 *                NO production telemetry API writes.
 *                NO production dispensing-session API writes.
 *                NO production alert creation through the API.
 *                API writes only if ENABLE_API_TEST_REQUESTS=1 (separate flag).
 */

#include <Arduino.h>

#include "config.h"
#include "hardware_config.h"
#include "storage.h"
#include "wifi_manager.h"
#include "api_client.h"
#include "sensors.h"
#include "dispenser.h"
#include "status_led.h"

// ── Timing ───────────────────────────────────────────────

static unsigned long _last_heartbeat = 0;
static unsigned long _last_telemetry = 0;

// ── Test Mode Simulation State ───────────────────────────
// Test mode uses sensors_set_sim_*() functions from sensors.h
// to set simulated values. No local state needed.

// ── Serial Provisioning Trigger ──────────────────────────

static void check_provisioning_trigger() {
    unsigned long start = millis();
    while (millis() - start < 5000) {
        if (Serial.available()) {
            char c = Serial.read();
            if (c == 'p' || c == 'P') {
                storage_serial_provision();
                return;
            }
        }
        delay(10);
    }

    // Check if button is held (GPIO 0 = BOOT button on most ESP32)
    pinMode(0, INPUT_PULLUP);
    if (digitalRead(0) == LOW) {
        Serial.println("[BOOT] Button held — entering provisioning.");
        storage_serial_provision();
    }
}

// ── Send Heartbeat ───────────────────────────────────────

static void send_heartbeat() {
    if (!wifi_is_connected()) return;

    api_send_heartbeat(
        FIRMWARE_VERSION,
        wifi_get_ip(),
        wifi_get_ssid()
    );
}

// ── Send Telemetry ───────────────────────────────────────

static void send_telemetry() {
    if (!wifi_is_connected()) return;

    int water = dispenser_get_water_level();
    bool cup = dispenser_get_cup();
    bool hand = dispenser_get_hand();
    bool pump = dispenser_get_pump();

    if (water >= 0) {
        api_send_sensor_reading(water, cup, hand, pump, -1);
    }
}

// ── Test Mode: Serial Command Handler ────────────────────
#if TEST_MODE

static void handle_test_serial() {
    if (!Serial.available()) return;

    String cmd = Serial.readStringUntil('\n');
    cmd.trim();

    if (cmd == "CUP ON" || cmd == "CUP=1") {
        sensors_set_sim_cup(true);
        Serial.println("[TEST] Simulated cup: DETECTED");
    } else if (cmd == "CUP OFF" || cmd == "CUP=0") {
        sensors_set_sim_cup(false);
        Serial.println("[TEST] Simulated cup: NOT DETECTED");
    } else if (cmd == "HAND ON" || cmd == "HAND=1") {
        sensors_set_sim_hand(true);
        Serial.println("[TEST] Simulated hand: DETECTED");
    } else if (cmd == "HAND OFF" || cmd == "HAND=0") {
        sensors_set_sim_hand(false);
        Serial.println("[TEST] Simulated hand: NOT DETECTED");
    } else if (cmd.startsWith("WATER=")) {
        int val = cmd.substring(6).toInt();
        if (val >= 0 && val <= 100) {
            sensors_set_sim_water_level(val);
            Serial.printf("[TEST] Simulated water level: %d%%\n", val);
        } else {
            Serial.println("[TEST] Invalid water level. Use WATER=0 to WATER=100");
        }
    } else if (cmd == "SENSOR ERROR" || cmd == "SENSOR=ERROR") {
        sensors_set_sim_error(true);
        Serial.println("[TEST] Simulated sensor error active");
    } else if (cmd == "SENSOR OK" || cmd == "SENSOR=CLEAR") {
        sensors_set_sim_error(false);
        Serial.println("[TEST] Simulated sensor error cleared");
    } else if (cmd == "HELP") {
        Serial.println("\n=== Test Mode Commands ===");
        Serial.println("  CUP ON / CUP OFF     — simulate cup detection");
        Serial.println("  HAND ON / HAND OFF   — simulate hand detection");
        Serial.println("  WATER=<0-100>        — simulate water level");
        Serial.println("  SENSOR ERROR         — simulate sensor error");
        Serial.println("  SENSOR OK            — clear sensor error");
        Serial.println("  HELP                 — show this help");
        Serial.println("  (Use newline or Enter after each command)");
        Serial.println("==========================\n");
    } else if (cmd.length() > 0) {
        Serial.printf("[TEST] Unknown command: '%s'. Type HELP for commands.\n", cmd.c_str());
    }
}

#endif // TEST_MODE

// ── Setup ────────────────────────────────────────────────

void setup() {
    Serial.begin(115200);
    delay(1000);

    Serial.println("\n========================================");
    Serial.println("  WaterBonia ESP32 Firmware v" FIRMWARE_VERSION);
    #if TEST_MODE
    Serial.println("  [MODE] TEST MODE - API writes disabled");
    if (ENABLE_API_TEST_REQUESTS) {
        Serial.println("  [MODE] ENABLE_API_TEST_REQUESTS=1 — test API writes active");
    }
    #endif
    Serial.println("========================================");

    // Initialize hardware — pump OFF first (safety)
    sensors_init();
    dispenser_init();
    led_init();
    storage_init();

    Serial.println("[BOOT] Hardware initialized. Pump OFF.");

    // Check provisioning
    check_provisioning_trigger();

    // Check if device is provisioned
    if (!storage_is_provisioned()) {
        Serial.println("[BOOT] Device not provisioned.");
        Serial.println("[BOOT] Send 'p' in serial to enter provisioning mode.");
        dispenser_set_state(STATE_WIFI_CONNECTING);
        led_update(STATE_WIFI_CONNECTING);
        return;
    }

    // Load credentials
    String ssid, password, device_token;
    storage_get_wifi_ssid(ssid);
    storage_get_wifi_password(password);
    storage_get_device_token(device_token);

    // Initialize API client
    api_init(device_token);
    Serial.println("[BOOT] API client initialized.");

    // Connect to Wi-Fi
    dispenser_set_state(STATE_WIFI_CONNECTING);
    led_update(STATE_WIFI_CONNECTING);

    Serial.println("[BOOT] Connecting to Wi-Fi...");
    if (wifi_init(ssid, password)) {
        Serial.println("[BOOT] Wi-Fi connected.");
        dispenser_set_state(STATE_READY);

        // Send initial heartbeat (only if API writes enabled)
        #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
        send_heartbeat();
        #endif
        _last_heartbeat = millis();
        _last_telemetry = millis();
    } else {
        Serial.println("[BOOT] Wi-Fi connection failed. Operating in local-only mode.");
        dispenser_set_state(STATE_READY); // Still operate locally
    }

    #if TEST_MODE
    Serial.println("[BOOT] Type HELP for test mode commands.\n");
    #endif

    Serial.println("[BOOT] WaterBonia ready.\n");
}

// ── Main Loop ────────────────────────────────────────────

void loop() {
    unsigned long now = millis();

    // ── 0. Test mode serial commands ──────────────────
    #if TEST_MODE
    handle_test_serial();
    #endif

    // ── 1. Wi-Fi management ──────────────────────────
    wifi_loop();

    // If we were in WIFI_CONNECTING and now connected, transition
    if (dispenser_state_name(dispenser_get_state()) == "WIFI_CONNECTING" &&
        wifi_is_connected()) {
        dispenser_set_state(STATE_READY);
        #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
        send_heartbeat();
        #endif
        _last_heartbeat = now;
        _last_telemetry = now;
    }

    // ── 2. Local dispenser logic (ALWAYS runs) ───────
    DispenserState state = dispenser_loop();

    // ── 3. Status LED ────────────────────────────────
    led_update(state);

    // ── 4. API communication (non-blocking) ──────────
    #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
    if (wifi_is_connected() && (now - _last_heartbeat >= HEARTBEAT_INTERVAL_MS)) {
        _last_heartbeat = now;
        send_heartbeat();
    }

    if (wifi_is_connected() && (now - _last_telemetry >= TELEMETRY_INTERVAL_MS)) {
        _last_telemetry = now;
        send_telemetry();
    }
    #endif

    // ── 5. Serial diagnostics ────────────────────────
    static unsigned long _last_print = 0;
    if (now - _last_print >= 5000) {
        _last_print = now;
        Serial.printf("[STATUS] State: %s | Cup: %s | Hand: %s | Water: %d%% | Pump: %s | Wi-Fi: %s\n",
                      dispenser_state_name(state),
                      dispenser_get_cup() ? "YES" : "NO",
                      dispenser_get_hand() ? "YES" : "NO",
                      dispenser_get_water_level(),
                      dispenser_get_pump() ? "ON" : "OFF",
                      wifi_is_connected() ? "OK" : "DOWN");
    }
}
