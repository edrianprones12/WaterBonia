/**
 * WaterBonia ESP32 - Load Cell (HX711) Implementation
 *
 * Uses the HX711_ADC library when it is installed.
 * If the library is missing the firmware still compiles, but
 * load_cell_available() returns false and dispensing is refused —
 * no fabricated measurements are ever reported.
 */

#include "load_cell.h"
#include "config.h"
#include "hardware_config.h"
#include <math.h>
#include <limits.h>

#if defined(__has_include)
    #if __has_include(<HX711_ADC.h>)
        #include <HX711_ADC.h>
        #define WB_HX711_PRESENT 1
    #endif
#endif

#ifndef WB_HX711_PRESENT
#define WB_HX711_PRESENT 0
#endif

#if WB_HX711_PRESENT
static HX711_ADC _load_cell(PIN_HX711_DOUT, PIN_HX711_SCK);
#endif

static bool _initialised = false;
static bool _sample_ok = false;
static bool _tare_ok = false;
static float _latest_grams = NAN;
static unsigned long _last_sample_ms = 0;
static const unsigned long SAMPLE_STALE_MS = 3000;  // no sample for 3s = fault
static const unsigned long FRESH_SAMPLE_WAIT_MS = 500;

#if WB_HX711_PRESENT
// The dispenser reports integer mL, so out-of-range conversion is a fault too.
static bool valid_measurement(float grams) {
    return isfinite(grams) && (double)grams <= INT_MAX && (double)grams >= INT_MIN;
}

// Only used while the pump is OFF, during initialization or container tare.
// A successful tare is not itself a fresh post-tare measurement.
static bool wait_for_fresh_sample() {
    const unsigned long started = millis();
    while (millis() - started < FRESH_SAMPLE_WAIT_MS) {
        load_cell_update();
        if (load_cell_ok()) return true;
        yield();
    }
    return false;
}
#endif

bool load_cell_available() {
    return WB_HX711_PRESENT && _initialised;
}

bool load_cell_begin() {
#if WB_HX711_PRESENT
    if (_initialised) return load_cell_ok();

    const float calibration = LOAD_CELL_CALIBRATION_FACTOR;
    if (!isfinite(calibration) || calibration == 0) {
        Serial.println("[LOADCELL] Invalid calibration factor; measurements unavailable.");
        return false;
    }

    _load_cell.begin();
    // Blocking tare + settle at boot (2 s). Kept out of the main loop.
    _load_cell.start(2000, true);
    _load_cell.setCalFactor(calibration);

    _tare_ok = !_load_cell.getTareTimeoutFlag() && !_load_cell.getSignalTimeoutFlag();
    _initialised = _tare_ok;
    _sample_ok = false;
    _latest_grams = NAN;
    _last_sample_ms = 0;
    const bool ready = _initialised && wait_for_fresh_sample();

    Serial.println(ready
        ? "[LOADCELL] HX711 samples ready; physical calibration must still be verified."
        : "[LOADCELL] HX711 has no valid post-tare sample — check wiring/DOUT/SCK pins.");
    return ready;
#else
    _initialised = false;
    _sample_ok = false;
    _tare_ok = false;
    _latest_grams = NAN;
    Serial.println("[LOADCELL] HX711_ADC library not found. Install 'HX711_ADC' to enable measured dispensing.");
    return false;
#endif
}

void load_cell_update() {
#if WB_HX711_PRESENT
    if (!_initialised) return;

    const bool new_sample = _load_cell.update() != 0;
    if (!_tare_ok || _load_cell.getTareTimeoutFlag() || _load_cell.getSignalTimeoutFlag()) {
        _sample_ok = false;
        _latest_grams = NAN;
        return;
    }
    if (new_sample) {
        const float grams = _load_cell.getData();
        _sample_ok = valid_measurement(grams);
        _latest_grams = _sample_ok ? grams : NAN;
        if (_sample_ok) _last_sample_ms = millis();
    } else if (millis() - _last_sample_ms > SAMPLE_STALE_MS) {
        _sample_ok = false;
        _latest_grams = NAN;
    }
#endif
}

float load_cell_read_grams() {
#if WB_HX711_PRESENT
    return load_cell_ok() ? _latest_grams : NAN;
#else
    return NAN;
#endif
}

void load_cell_tare() {
#if WB_HX711_PRESENT
    if (!_initialised) return;
    Serial.println("[LOADCELL] Taring…");
    _sample_ok = false;
    _tare_ok = false;
    _latest_grams = NAN;
    _last_sample_ms = 0;
    _load_cell.tare();
    _tare_ok = !_load_cell.getTareTimeoutFlag() && !_load_cell.getSignalTimeoutFlag();
    if (!_tare_ok || !wait_for_fresh_sample()) {
        Serial.println("[LOADCELL] Tare failed or no valid new sample; pump must remain OFF.");
    }
#endif
}

bool load_cell_ok() {
    if (!load_cell_available()) return false;
    if (!_tare_ok || !_sample_ok || !isfinite(_latest_grams)) return false;
    if (millis() - _last_sample_ms > SAMPLE_STALE_MS) return false;
    return true;
}

const char* load_cell_status() {
#if !WB_HX711_PRESENT
    return "library_missing";
#else
    if (!_initialised) return "not_initialised";
    if (!_tare_ok) return "tare_failed";
    if (!_sample_ok) return "no_samples";
    if (millis() - _last_sample_ms > SAMPLE_STALE_MS) return "stale";
    return "ok";
#endif
}
