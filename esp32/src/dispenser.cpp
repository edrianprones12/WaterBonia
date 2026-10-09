/**
 * WaterBonia ESP32 - Dispenser State Machine Implementation
 *
 * Safety-first design:
 * - Pump OFF is the default and safe state
 * - Local sensors control pump, not the server
 * - Non-blocking timing throughout
 * - Sensor errors and load-cell faults force pump OFF
 *
 * v2: dispensing is measured with a load cell and stops at the
 * target volume. The website only REQUESTS dispensing — the local
 * validation below always has the final say.
 *
 * PUMP SAFETY INVARIANT (must hold at ALL times):
 *   Pump ON is permitted ONLY when:
 *     container_detected == true
 *     AND an accepted dispense request is active
 *     AND load cell samples are valid (see ALLOW_DISPENSE_WITHOUT_LOAD_CELL)
 *     AND dispense timeout has not been exceeded
 *     AND no emergency stop is active
 *   All other conditions require Pump OFF.
 */

#include "dispenser.h"
#include "sensors.h"
#include "load_cell.h"
#include "api_client.h"
#include "hardware_config.h"

#include <string.h>

// ── State ────────────────────────────────────────────────

static DispenserState _state = STATE_INIT;
static DispenserState _prev_state = STATE_INIT;

// Sensor readings (latest)
static bool _container = false;
static bool _hand = false;

// Pump
static bool _pump_on = false;
static unsigned long _pump_start_ms = 0;

// Pending request (from server command or local trigger)
static bool _pending_request = false;
static char _pending_container[12] = "";
static int  _pending_target_ml = 0;
static long _pending_command_id = 0;

// Active dispense
static bool  _active = false;
static char  _active_container[12] = "";
static int   _target_ml = 0;
static long  _command_id = 0;
static int   _measured_ml = 0;
static bool  _load_cell_valid = false;

// Timing
static unsigned long _last_state_change = 0;
static unsigned long _container_since = 0;
static unsigned long _complete_since = 0;

// API session tracking
static long _active_session_id = 0;

// ── Pump Control ─────────────────────────────────────────

static void pump_on() {
    if (_pump_on) return;

    #if RELAY_ACTIVE_LOW
        digitalWrite(PIN_PUMP_RELAY, LOW);  // LOW = relay ON
    #else
        digitalWrite(PIN_PUMP_RELAY, HIGH); // HIGH = relay ON
    #endif

    _pump_on = true;
    _pump_start_ms = millis();
    Serial.println("[PUMP] ON");
}

static void pump_off() {
    if (!_pump_on) return;

    #if RELAY_ACTIVE_LOW
        digitalWrite(PIN_PUMP_RELAY, HIGH); // HIGH = relay OFF
    #else
        digitalWrite(PIN_PUMP_RELAY, LOW);  // LOW = relay OFF
    #endif

    _pump_on = false;
    Serial.println("[PUMP] OFF");
}

// ── State Helpers ────────────────────────────────────────

static void set_state(DispenserState new_state) {
    if (new_state == _state) return;
    _prev_state = _state;
    _state = new_state;
    _last_state_change = millis();
    Serial.printf("[STATE] %s -> %s\n",
                  dispenser_state_name(_prev_state),
                  dispenser_state_name(new_state));
}

static void reset_request() {
    _pending_request = false;
    _pending_container[0] = '\0';
    _pending_target_ml = 0;
    _pending_command_id = 0;

    _active = false;
    _active_container[0] = '\0';
    _target_ml = 0;
    _command_id = 0;
    _measured_ml = 0;
    _load_cell_valid = false;
}

// ── Session / Command reporting ──────────────────────────
// All API writes are compiled out in TEST_MODE unless
// ENABLE_API_TEST_REQUESTS is explicitly set.

static void finish_session(const char *stop_reason) {
    int measured = _measured_ml;

    pump_off();

    #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
    if (_active_session_id > 0) {
        api_dispensing_complete(_active_session_id, stop_reason, measured);
    }
    if (_command_id > 0) {
        // 'completed' only on target reached; everything else is a local stop.
        const char *result = (strcmp(stop_reason, "target_reached") == 0) ? "completed" : "rejected";
        api_command_result(_command_id, result, _active_session_id, stop_reason);
    }
    #else
    Serial.printf("[API] Test mode — session complete skipped (%s).\n", stop_reason);
    #endif

    _active_session_id = 0;
    reset_request();
}

// ── Init ─────────────────────────────────────────────────

void dispenser_init() {
    pump_off(); // CRITICAL: default safe state
    _state = STATE_INIT;
    _prev_state = STATE_INIT;
    _container = false;
    _hand = false;
    _active_session_id = 0;
    reset_request();
    load_cell_begin();
    Serial.println("[DISPENSER] Init complete. Pump OFF.");
}

// ── Request Entry Point ──────────────────────────────────

bool dispenser_request_dispense(const char *container_type, int target_ml, long command_id) {
    if (_pump_on || _active || _state == STATE_DISPENSING) {
        Serial.println("[DISPENSER] Request rejected: dispensing already in progress.");
        return false;
    }
    if (_state == STATE_ERROR) {
        Serial.printf("[DISPENSER] Request rejected: state is %s.\n",
                      dispenser_state_name(_state));
        return false;
    }
    if (!_container) {
        Serial.println("[DISPENSER] Request rejected: no container detected.");
        return false;
    }
    if (container_type == NULL || container_type[0] == '\0') {
        Serial.println("[DISPENSER] Request rejected: missing container type.");
        return false;
    }
    if (target_ml <= 0 || target_ml > MAX_TARGET_ML) {
        Serial.printf("[DISPENSER] Request rejected: target %d mL out of range.\n", target_ml);
        return false;
    }
    #if !ALLOW_DISPENSE_WITHOUT_LOAD_CELL
    if (!load_cell_ok()) {
        Serial.printf("[DISPENSER] Request rejected: load cell not ready (%s).\n",
                      load_cell_status());
        return false;
    }
    #endif

    strncpy(_pending_container, container_type, sizeof(_pending_container) - 1);
    _pending_container[sizeof(_pending_container) - 1] = '\0';
    _pending_target_ml = target_ml;
    _pending_command_id = command_id;
    _pending_request = true;

    Serial.printf("[DISPENSER] Request queued: %s → %d mL (command #%ld)\n",
                  _pending_container, _pending_target_ml, _pending_command_id);
    return true;
}

// ── Main Loop ────────────────────────────────────────────

DispenserState dispenser_loop() {
    // ── Read sensors ─────────────────────────────────
    _container = sensors_read_container();
    _hand = sensors_read_hand();

    // Keep the load cell sampling while idle so a request can start immediately.
    load_cell_update();

    // ── Emergency: sensor error → pump OFF ───────────────
    if (sensors_has_error()) {
        if (_pump_on) {
            Serial.println("[SAFETY] Sensor error — stopping pump.");
            finish_session("error");
        }
        set_state(STATE_ERROR);
        return _state;
    }

    // ── Emergency: max timeout → pump OFF ────────────
    if (_pump_on && (millis() - _pump_start_ms >= MAX_DISPENSE_TIME_MS)) {
        Serial.println("[SAFETY] Max dispense timeout — stopping pump.");
        finish_session("timeout");
        set_state(STATE_COMPLETE);
        _complete_since = millis();
        return _state;
    }

    // ── Load cell fault while pumping → pump OFF ─────
    #if !ALLOW_DISPENSE_WITHOUT_LOAD_CELL
    if (_pump_on && !load_cell_ok()) {
        Serial.println("[SAFETY] Load cell fault — stopping pump.");
        finish_session("error");
        set_state(STATE_ERROR);
        return _state;
    }
    #endif

    // ── Normal state logic ───────────────────────────
    switch (_state) {
        case STATE_INIT:
            set_state(STATE_READY);
            break;

        case STATE_WIFI_CONNECTING:
            // Handled in main.cpp — stay here until Wi-Fi connects
            break;

        case STATE_READY:
            if (_container) {
                _container_since = millis();
                set_state(STATE_CONTAINER_DETECTED);
            }
            break;

        case STATE_CONTAINER_DETECTED:
            if (!_container) {
                if (_pending_request && _pending_command_id > 0) {
                    #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
                    api_command_result(_pending_command_id, "cancelled", 0, "container_removed");
                    #endif
                }
                reset_request();
                _container_since = 0;
                set_state(STATE_READY);
            } else if (millis() - _container_since >= CONTAINER_STABLE_MS) {
                set_state(STATE_WAITING_FOR_COMMAND);
            }
            break;

        case STATE_WAITING_FOR_COMMAND:
            if (!_container) {
                // Container removed before dispensing — cancel the request.
                if (_pending_request && _pending_command_id > 0) {
                    #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
                    api_command_result(_pending_command_id, "cancelled", 0, "container_removed");
                    #endif
                }
                reset_request();
                set_state(STATE_READY);
            } else if (_pending_request) {
                // Validate + start
                strncpy(_active_container, _pending_container, sizeof(_active_container) - 1);
                _active_container[sizeof(_active_container) - 1] = '\0';
                _target_ml = _pending_target_ml;
                _command_id = _pending_command_id;
                _pending_request = false;

                _active = true;
                _measured_ml = 0;

                // Tare with the container in place so only added water is measured.
                load_cell_tare();
                load_cell_update();

                // Open the session; the server confirms the claimed command's target.
                #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
                int reported_target = _target_ml;
                _active_session_id = api_dispensing_start(
                    _container, _active_container, _command_id, reported_target);
                if (_active_session_id <= 0) {
                    Serial.println("[SAFETY] Session start rejected — pump remains OFF.");
                    if (_command_id > 0) api_command_result(_command_id, "rejected", 0, "session_start_failed");
                    reset_request();
                    set_state(STATE_ERROR);
                    break;
                }
                if (reported_target > 0 && reported_target <= MAX_TARGET_ML) {
                    _target_ml = reported_target;
                }
                #else
                _active_session_id = 0;
                Serial.println("[API] Test mode — dispensing start skipped.");
                #endif

                // Tare/API calls may take time; refresh local guards before pump ON.
                _container = sensors_read_container();
                load_cell_update();
                if (!_container || sensors_has_error()
                    #if !ALLOW_DISPENSE_WITHOUT_LOAD_CELL
                    || !load_cell_ok()
                    #endif
                ) {
                    finish_session(_container ? "error" : "container_removed");
                    set_state(_container ? STATE_ERROR : STATE_READY);
                    break;
                }
                pump_on();
                set_state(STATE_DISPENSING);
                Serial.printf("[DISPENSER] Dispensing %s → %d mL\n",
                              _active_container, _target_ml);
            }
            break;

        case STATE_DISPENSING: {
            // Safety: container must stay in place
            if (!_container) {
                Serial.println("[SAFETY] Container removed — stopping pump.");
                finish_session("container_removed");
                set_state(STATE_READY);
                break;
            }

            // Measure (grams ≈ mL)
            float grams = load_cell_read_grams();
            if (!isnan(grams)) {
                if (grams < 0) grams = 0;
                _measured_ml = (int)grams;
                _load_cell_valid = true;
            }

            // Auto-stop when the target is reached
            #if ALLOW_DISPENSE_WITHOUT_LOAD_CELL
            // Bench-test only: no measured volume, rely on the timeout.
            #else
            if (_load_cell_valid
                && _measured_ml >= LOAD_CELL_MIN_VALID_G
                && _measured_ml >= _target_ml) {
                Serial.printf("[DISPENSER] Target reached: %d / %d mL\n",
                              _measured_ml, _target_ml);
                finish_session("target_reached");
                set_state(STATE_COMPLETE);
                _complete_since = millis();
            }
            #endif
            break;
        }

        case STATE_COMPLETE:
            if (!_container) {
                set_state(STATE_READY);
            } else if (millis() - _complete_since >= COMPLETE_HOLD_MS) {
                set_state(STATE_READY);
            }
            break;

        case STATE_ERROR:
            if (!sensors_has_error()
                #if !ALLOW_DISPENSE_WITHOUT_LOAD_CELL
                && load_cell_ok()
                #endif
            ) {
                sensors_clear_error();
                set_state(STATE_READY);
            }
            break;
    }

    return _state;
}

// ── Public Helpers ───────────────────────────────────────

const char* dispenser_state_name(DispenserState state) {
    switch (state) {
        case STATE_INIT:                 return "INIT";
        case STATE_WIFI_CONNECTING:      return "WIFI_CONNECTING";
        case STATE_READY:                return "READY";
        case STATE_CONTAINER_DETECTED:   return "CONTAINER_DETECTED";
        case STATE_WAITING_FOR_COMMAND:  return "WAITING_FOR_COMMAND";
        case STATE_DISPENSING:           return "DISPENSING";
        case STATE_COMPLETE:             return "COMPLETE";
        case STATE_ERROR:                return "ERROR";
        default:                         return "UNKNOWN";
    }
}

DispenserState dispenser_get_state()   { return _state; }
bool dispenser_get_container()         { return _container; }
bool dispenser_get_hand()              { return _hand; }
bool dispenser_get_pump()              { return _pump_on; }
int  dispenser_get_measured_ml()       { return _measured_ml; }
int  dispenser_get_target_ml()        { return _target_ml; }
long dispenser_get_session_id()        { return _active_session_id; }
long dispenser_get_command_id()        { return _command_id; }
bool dispenser_has_pending_request()   { return _pending_request; }

const char* dispenser_get_container_type() {
    if (_active) return _active_container;
    if (_pending_request) return _pending_container;
    return "";
}

void dispenser_emergency_stop() {
    if (_pump_on || _active_session_id > 0 || _active) {
        finish_session("manual_stop");
    } else {
        reset_request();
    }
    set_state(STATE_READY);
    Serial.println("[DISPENSER] Emergency stop.");
}

void dispenser_set_state(DispenserState new_state) {
    set_state(new_state);
}
