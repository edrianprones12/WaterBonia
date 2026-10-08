/**
 * WaterBonia ESP32 - Sensor Implementation
 *
 * Water-level sensor is abstracted for easy replacement.
 * Current implementation: analog water-level sensor on ADC pin.
 * To use a different sensor model, replace the functions below.
 *
 * v2: the container sensor reports PRESENCE ONLY. It does not
 *     distinguish a cup from a tumbler — the web user chooses the
 *     container type, and the server authorises the target volume.
 *
 * TEST_MODE: When TEST_MODE=1, sensors_read_* functions return
 * simulated values set via main.cpp serial commands instead of
 * reading real hardware. This allows state machine and pump/LED
 * testing without physical sensors. Simulated values are NEVER
 * sent to the production database.
 */

#include "sensors.h"
#include "config.h"
#include "hardware_config.h"

// ── Debounce State ───────────────────────────────────────

static bool _hand_stable = false;
static int _hand_count = 0;
static bool _container_stable = false;
static int _container_count = 0;
static bool _sensor_error = false;

// ── Test Mode Simulation State ───────────────────────────
// These are set externally by main.cpp when TEST_MODE=1.
// They are NOT used in normal (TEST_MODE=0) operation.
#if TEST_MODE
static bool _sim_container = false;
static bool _sim_hand = false;
static int  _sim_water_level = 50;
static bool _sim_error = false;

void sensors_set_sim_container(bool detected)  { _sim_container = detected; }
void sensors_set_sim_cup(bool detected)        { _sim_container = detected; }
void sensors_set_sim_hand(bool detected)       { _sim_hand = detected; }
void sensors_set_sim_water_level(int pct)      { _sim_water_level = constrain(pct, 0, 100); }
void sensors_set_sim_error(bool error)         { _sim_error = error; }
#endif

// ── Init ─────────────────────────────────────────────────

void sensors_init() {
    // HC-SR04 (diagnostic only)
    pinMode(PIN_HCSR04_TRIG, OUTPUT);
    pinMode(PIN_HCSR04_ECHO, INPUT);
    digitalWrite(PIN_HCSR04_TRIG, LOW);

    // Container presence sensor
    pinMode(PIN_CONTAINER_SENSOR, INPUT);

    // Water level analog
    // ESP32 ADC pins don't need explicit pinMode for analogRead

    // Pump relay — safe default OFF
    pinMode(PIN_PUMP_RELAY, OUTPUT);
    #if RELAY_ACTIVE_LOW
        digitalWrite(PIN_PUMP_RELAY, HIGH); // HIGH = relay OFF for active-low
    #else
        digitalWrite(PIN_PUMP_RELAY, LOW);  // LOW = relay OFF for active-high
    #endif

    // RGB LED
    pinMode(PIN_LED_RED, OUTPUT);
    pinMode(PIN_LED_GREEN, OUTPUT);
    pinMode(PIN_LED_BLUE, OUTPUT);

    _hand_stable = false;
    _hand_count = 0;
    _container_stable = false;
    _container_count = 0;
    _sensor_error = false;

    Serial.println("[SENSOR] Init complete. Pump OFF.");
}

// ── HC-SR04 Ultrasonic (diagnostic / optional) ──────────

float sensors_read_distance_cm() {
    // Send 10µs trigger pulse
    digitalWrite(PIN_HCSR04_TRIG, HIGH);
    delayMicroseconds(10);
    digitalWrite(PIN_HCSR04_TRIG, LOW);

    // Read echo pulse duration (with timeout)
    unsigned long duration = pulseIn(PIN_HCSR04_ECHO, HIGH, 30000); // 30ms timeout

    if (duration == 0) {
        // No echo received — out of range or sensor error
        return -1.0f;
    }

    // Calculate distance: speed of sound = 343 m/s = 0.0343 cm/µs
    // Distance = (duration / 2) * 0.0343
    float distance = (duration * 0.0343f) / 2.0f;

    // Validate reading (HC-SR04 range: 2cm - 400cm)
    if (distance < 1.0f || distance > 400.0f) {
        return -1.0f;
    }

    return distance;
}

bool sensors_read_hand() {
    #if TEST_MODE
    return _sim_hand;
    #else
    float distance = sensors_read_distance_cm();

    bool detected = false;
    if (distance > 0) {
        detected = (distance >= HAND_MIN_DISTANCE_CM && distance <= HAND_MAX_DISTANCE_CM);
    }

    // Debounce
    if (detected) {
        _hand_count = min(_hand_count + 1, SENSOR_DEBOUNCE_COUNT);
    } else {
        _hand_count = max(_hand_count - 1, 0);
    }

    if (_hand_count >= SENSOR_DEBOUNCE_COUNT) {
        _hand_stable = true;
    } else if (_hand_count <= 0) {
        _hand_stable = false;
    }

    return _hand_stable;
    #endif
}

// ── Container Presence Sensor ───────────────────────────
// Presence ONLY — cup vs tumbler is chosen by the web user.

bool sensors_read_container() {
    #if TEST_MODE
    return _sim_container;
    #else
    int raw = digitalRead(PIN_CONTAINER_SENSOR);

    bool detected;
    #if CONTAINER_SENSOR_ACTIVE_LOW
        detected = (raw == LOW);
    #else
        detected = (raw == HIGH);
    #endif

    // Debounce
    if (detected) {
        _container_count = min(_container_count + 1, SENSOR_DEBOUNCE_COUNT);
    } else {
        _container_count = max(_container_count - 1, 0);
    }

    if (_container_count >= SENSOR_DEBOUNCE_COUNT) {
        _container_stable = true;
    } else if (_container_count <= 0) {
        _container_stable = false;
    }

    return _container_stable;
    #endif
}

bool sensors_read_cup() {
    return sensors_read_container();
}

// ── Water Level Sensor ───────────────────────────────────
// REPLACE this implementation when the final sensor model is selected.
// Current: simple analog water-level sensor.
//
// For different sensor types:
// - Ultrasonic water-level: use distance measurement
// - Float switch: use digital read with thresholds
// - Capacitive: use analog with calibration curve
// - Serial/UART sensor: use protocol-specific read

int sensors_read_water_level() {
    #if TEST_MODE
    return _sim_water_level;
    #else
    int raw = analogRead(PIN_WATER_LEVEL_ANALOG);

    // Validate ADC reading
    if (raw < WATER_LEVEL_ADC_MIN || raw > WATER_LEVEL_ADC_MAX) {
        _sensor_error = true;
        Serial.println("[SENSOR] Water level reading out of range.");
        return -1;
    }

    // Map ADC value to percentage
    // NOTE: Calibrate these values for your specific sensor.
    // Different water-level sensors have different output curves.
    int percent = map(raw, WATER_LEVEL_ADC_MIN, WATER_LEVEL_ADC_MAX, 0, 100);
    percent = constrain(percent, 0, 100);

    return percent;
    #endif
}

// ── Error State ──────────────────────────────────────────

bool sensors_has_error() {
    #if TEST_MODE
    return _sim_error;
    #else
    int water = sensors_read_water_level();
    if (water < 0) {
        _sensor_error = true;
    }
    return _sensor_error;
    #endif
}

void sensors_clear_error() {
    _sensor_error = false;
    #if TEST_MODE
    _sim_error = false;
    #endif
}
