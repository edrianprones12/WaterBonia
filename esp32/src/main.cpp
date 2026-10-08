/**
 * WaterBonia ESP32 - Main Firmware
 *
 * Architecture:
 *   PHYSICAL SENSORS → ESP32 LOCAL CONTROL → LOCAL SAFETY → PUMP
 *   ESP32 → Wi-Fi → WaterBonia PHP API → MySQL → Web Dashboard
 *
 * The API is for monitoring, records, and dispense REQUESTS.
 * The ESP32 is responsible for immediate physical control and safety:
 * the website can only queue a command, and local validation decides
 * whether the pump may run.
 *
 * v2 flow:
 *   1. Web user chooses container (cup / tumbler)
 *   2. Container sensor reports presence
 *   3. Web user presses DISPENSE → server queues a command
 *   4. This firmware polls the command, validates locally, tares
 *      the load cell, runs the pump and stops at the target volume
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
#include "load_cell.h"
#include "dispenser.h"
#include "status_led.h"

// ── Timing ───────────────────────────────────────────────

static unsigned long _last_heartbeat = 0;
static unsigned long _last_telemetry = 0;
static unsigned long _last_command_poll = 0;

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
    if (water < 0) return;   // never report a fabricated level

    bool container = dispenser_get_container();
    bool hand = dispenser_get_hand();
    bool pump = dispenser_get_pump();
    int measured = dispenser_get_pump() ? dispenser_get_measured_ml() : -1;

    api_send_sensor_reading(water, container, hand, pump, -1, measured);
}

// ── Poll for a dispense command (web → ESP32) ───────────

static void poll_commands() {
    if (!wifi_is_connected()) return;
    if (dispenser_get_state() == STATE_DISPENSING) return;
    if (dispenser_get_state() == STATE_WIFI_CONNECTING) return;
    if (dispenser_has_pending_request()) return;
    if (!dispenser_get_container()) return;   // nothing to fill

    DispenseCommand cmd;
    if (api_poll_command(cmd)) {
        bool accepted = dispenser_request_dispense(
            cmd.container_type, cmd.target_amount_ml, cmd.command_id);

        if (!accepted) {
            // Tell the server so the queued command does not sit there
            #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
            api_command_result(cmd.command_id, "rejected", 0, "local_validation_failed");
            #endif
        }
    }
}

// ── Test Mode: Serial Command Handler ────────────────────
#if TEST_MODE

static void handle_test_serial() {
    if (!Serial.available()) return;

    String cmd = Serial.readStringUntil('\n');
    cmd.trim();

    if (cmd == "CONTAINER ON" || cmd == "CONTAINER=1" ||
        cmd == "CUP ON" || cmd == "CUP=1") {
        sensors_set_sim_container(true);
        Serial.println("[TEST] Simulated container: DETECTED");
    } else if (cmd == "CONTAINER OFF" || cmd == "CONTAINER=0" ||
               cmd == "CUP OFF" || cmd == "CUP=0") {
        sensors_set_sim_container(false);
        Serial.println("[TEST] Simulated container: NOT DETECTED");
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
    } else if (cmd.startsWith("DISPENSE")) {
        // Local test trigger — bypasses the server, still runs all
        // local safety checks inside dispenser_request_dispense().
        const char *ctype = DEFAULT_CONTAINER_TYPE;
        int sep = cmd.indexOf('=');
        if (sep > 0) {
            String arg = cmd.substring(sep + 1);
            arg.trim();
            arg.toLowerCase();
            if (arg == "tumbler") ctype = "tumbler";
        }
        bool ok = dispenser_request_dispense(ctype, DEFAULT_TARGET_ML, 0);
        Serial.printf("[TEST] Dispense request (%s): %s\n",
                      ctype, ok ? "ACCEPTED" : "REJECTED");
    } else if (cmd == "SENSOR ERROR" || cmd == "SENSOR=ERROR") {
        sensors_set_sim_error(true);
        Serial.println("[TEST] Simulated sensor error active");
    } else if (cmd == "SENSOR OK" || cmd == "SENSOR=CLEAR") {
        sensors_set_sim_error(false);
        Serial.println("[TEST] Simulated sensor error cleared");
    } else if (cmd == "HELP") {
        Serial.println("\n=== Test Mode Commands ===");
        Serial.println("  CONTAINER ON / OFF    — simulate container presence");
        Serial.println("  HAND ON / OFF         — simulate hand detection (diagnostic)");
        Serial.println("  WATER=<0-100>         — simulate water level");
        Serial.println("  DISPENSE[=cup|tumbler]— local dispense trigger (runs local safety)");
        Serial.println("  SENSOR ERROR          — simulate sensor error");
        Serial.println("  SENSOR OK             — clear sensor error");
        Serial.println("  HELP                  — show this help");
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
        _last_command_poll = millis();
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
        _last_command_poll = now;
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

    // Claim any dispense command queued by the web UI
    if (wifi_is_connected() && (now - _last_command_poll >= COMMAND_POLL_INTERVAL_MS)) {
        _last_command_poll = now;
        poll_commands();
    }
    #endif

    // ── 5. Serial diagnostics ────────────────────────
    static unsigned long _last_print = 0;
    if (now - _last_print >= 5000) {
        _last_print = now;
        Serial.printf("[STATUS] State: %s | Container: %s | Water: %d%% | Measured: %d/%d mL | Pump: %s | LoadCell: %s | Wi-Fi: %s\n",
                      dispenser_state_name(state),
                      dispenser_get_container() ? "YES" : "NO",
                      dispenser_get_water_level(),
                      dispenser_get_measured_ml(),
                      dispenser_get_target_ml(),
                      dispenser_get_pump() ? "ON" : "OFF",
                      load_cell_status(),
                      wifi_is_connected() ? "OK" : "DOWN");
    }
}
