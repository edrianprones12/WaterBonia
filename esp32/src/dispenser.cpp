/**
 * WaterBonia ESP32 - Dispenser State Machine Implementation
 *
 * Safety-first design:
 * - Pump OFF is the default and safe state
 * - Local sensors control pump, not the server
 * - Non-blocking timing throughout
 * - Sensor errors force pump OFF
 *
 * PUMP SAFETY INVARIANT (must hold at ALL times):
 *   Pump ON is permitted ONLY when:
 *     cup_detected == true
 *     AND hand_detected == true
 *     AND water_level > LOCAL_PUMP_CUTOFF_PERCENT
 *     AND no sensor safety fault exists
 *     AND dispense timeout has not been exceeded
 *     AND emergency stop is not active
 *   All other conditions require Pump OFF.
 */

#include "dispenser.h"
#include "sensors.h"
#include "api_client.h"
#include "hardware_config.h"

// ── State ────────────────────────────────────────────────

static DispenserState _state = STATE_INIT;
static DispenserState _prev_state = STATE_INIT;

// Sensor readings (latest)
static bool _cup = false;
static bool _hand = false;
static int  _water_level = -1;

// Pump
static bool _pump_on = false;
static unsigned long _pump_start_ms = 0;

// Timing
static unsigned long _last_state_change = 0;

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
    Serial.printf("[STATE] %s → %s\n",
                  dispenser_state_name(_prev_state),
                  dispenser_state_name(new_state));
}

// ── Init ─────────────────────────────────────────────────

void dispenser_init() {
    pump_off(); // CRITICAL: default safe state
    _state = STATE_INIT;
    _prev_state = STATE_INIT;
    _cup = false;
    _hand = false;
    _water_level = -1;
    _active_session_id = 0;
    Serial.println("[DISPENSER] Init complete. Pump OFF.");
}

// ── Main Loop ────────────────────────────────────────────

DispenserState dispenser_loop() {
    // ── Read sensors ─────────────────────────────────
    _cup = sensors_read_cup();
    _hand = sensors_read_hand();
    _water_level = sensors_read_water_level();

    // ── Emergency: sensor error → pump OFF ───────────
    if (_water_level < 0 || sensors_has_error()) {
        if (_pump_on) {
            Serial.println("[SAFETY] Sensor error — stopping pump.");
            pump_off();
            if (_active_session_id > 0) {
                #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
                api_dispensing_complete(_active_session_id, _water_level >= 0 ? _water_level : 0, "error");
                #endif
                _active_session_id = 0;
            }
        }
        set_state(STATE_ERROR);
        return _state;
    }

    // ── Emergency: max timeout → pump OFF ────────────
    if (_pump_on && (millis() - _pump_start_ms >= MAX_DISPENSE_TIME_MS)) {
        Serial.println("[SAFETY] Max dispense timeout — stopping pump.");
        pump_off();
        if (_active_session_id > 0) {
            #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
            api_dispensing_complete(_active_session_id, _water_level, "timeout");
            #endif
            _active_session_id = 0;
        }
        set_state(STATE_READY);
        return _state;
    }

    // ── Low water check ──────────────────────────────
    if (_water_level <= LOCAL_PUMP_CUTOFF_PERCENT) {
        if (_pump_on) {
            Serial.println("[SAFETY] Low water — stopping pump.");
            pump_off();
            if (_active_session_id > 0) {
                #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
                api_dispensing_complete(_active_session_id, _water_level, "low_water");
                #endif
                _active_session_id = 0;
            }
        }
        set_state(STATE_LOW_WATER);
        return _state;
    }

    // ── Normal state logic ───────────────────────────
    switch (_state) {
        case STATE_INIT:
            set_state(STATE_READY);
            break;

        case STATE_WIFI_CONNECTING:
            // Handled in main.cpp — stay here until Wi-Fi connects
            break;

        case STATE_READY:
            if (_cup) {
                set_state(STATE_CUP_DETECTED);
            }
            break;

        case STATE_CUP_DETECTED:
            if (!_cup) {
                // Cup removed before hand — back to ready
                set_state(STATE_READY);
            } else {
                // Cup stable, now waiting for hand
                set_state(STATE_WAITING_FOR_HAND);
            }
            break;

        case STATE_WAITING_FOR_HAND:
            if (!_cup) {
                // Cup removed — back to ready
                set_state(STATE_READY);
            } else if (_hand) {
                // Both cup and hand — start dispensing
                pump_on();
                #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
                _active_session_id = api_dispensing_start(_cup, _hand, _water_level);
                #else
                _active_session_id = 0;
                Serial.println("[API] Test mode — dispensing start skipped.");
                #endif
                set_state(STATE_DISPENSING);
            }
            break;

        case STATE_DISPENSING:
            // Safety checks during dispensing — ALL must hold for pump to stay ON
            if (!_cup) {
                Serial.println("[SAFETY] Cup removed — stopping pump.");
                pump_off();
                if (_active_session_id > 0) {
                    #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
                    api_dispensing_complete(_active_session_id, _water_level, "cup_removed");
                    #endif
                    _active_session_id = 0;
                }
                set_state(STATE_READY);
            } else if (!_hand) {
                Serial.println("[SAFETY] Hand removed — stopping pump.");
                pump_off();
                if (_active_session_id > 0) {
                    #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
                    api_dispensing_complete(_active_session_id, _water_level, "hand_removed");
                    #endif
                    _active_session_id = 0;
                }
                set_state(STATE_WAITING_FOR_HAND);
            }
            break;

        case STATE_LOW_WATER:
            if (_water_level > LOCAL_PUMP_CUTOFF_PERCENT) {
                set_state(STATE_READY);
            }
            break;

        case STATE_ERROR:
            if (!sensors_has_error() && _water_level >= 0) {
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
        case STATE_INIT:              return "INIT";
        case STATE_WIFI_CONNECTING:   return "WIFI_CONNECTING";
        case STATE_READY:             return "READY";
        case STATE_CUP_DETECTED:      return "CUP_DETECTED";
        case STATE_WAITING_FOR_HAND:  return "WAITING_FOR_HAND";
        case STATE_DISPENSING:        return "DISPENSING";
        case STATE_LOW_WATER:         return "LOW_WATER";
        case STATE_ERROR:             return "ERROR";
        default:                      return "UNKNOWN";
    }
}

DispenserState dispenser_get_state()     { return _state; }
int  dispenser_get_water_level()         { return _water_level; }
bool dispenser_get_cup()                 { return _cup; }
bool dispenser_get_hand()                { return _hand; }
bool dispenser_get_pump()                { return _pump_on; }
long dispenser_get_session_id()          { return _active_session_id; }

void dispenser_emergency_stop() {
    pump_off();
    if (_active_session_id > 0) {
        #if !TEST_MODE || ENABLE_API_TEST_REQUESTS
        api_dispensing_complete(_active_session_id, _water_level >= 0 ? _water_level : 0, "manual_stop");
        #endif
        _active_session_id = 0;
    }
    set_state(STATE_READY);
    Serial.println("[DISPENSER] Emergency stop.");
}

void dispenser_set_state(DispenserState new_state) {
    set_state(new_state);
}
