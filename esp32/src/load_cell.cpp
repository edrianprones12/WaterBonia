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
static unsigned long _last_sample_ms = 0;
static const unsigned long SAMPLE_STALE_MS = 3000;  // no sample for 3s = fault

bool load_cell_available() {
    return WB_HX711_PRESENT && _initialised;
}

bool load_cell_begin() {
#if WB_HX711_PRESENT
    if (_initialised) return true;

    _load_cell.begin();
    _load_cell.setCalibrationFactor(LOAD_CELL_CALIBRATION_FACTOR);
    // Blocking tare + settle at boot (2 s). Kept out of the main loop.
    bool ok = _load_cell.start(2000);

    _initialised = ok;
    _sample_ok = ok;
    _last_sample_ms = ok ? millis() : 0;

    Serial.println(ok
        ? "[LOADCELL] HX711 initialised (calibration factor is a PLACEHOLDER until calibrated)."
        : "[LOADCELL] HX711 init failed — check wiring/DOUT/SCK pins.");
    return ok;
#else
    _initialised = false;
    _sample_ok = false;
    Serial.println("[LOADCELL] HX711_ADC library not found. Install 'HX711_ADC' to enable measured dispensing.");
    return false;
#endif
}

void load_cell_update() {
#if WB_HX711_PRESENT
    if (!_initialised) return;

    if (_load_cell.update()) {
        _sample_ok = true;
        _last_sample_ms = millis();
    } else if (millis() - _last_sample_ms > SAMPLE_STALE_MS) {
        _sample_ok = false;
    }
#endif
}

float load_cell_read_grams() {
#if WB_HX711_PRESENT
    if (!_initialised || !_sample_ok) return NAN;
    float grams = _load_cell.getData();
    if (isnan(grams)) {
        _sample_ok = false;
        return NAN;
    }
    return grams;
#else
    return NAN;
#endif
}

void load_cell_tare() {
#if WB_HX711_PRESENT
    if (!_initialised) return;
    Serial.println("[LOADCELL] Taring…");
    _load_cell.tare(4);
    _sample_ok = true;
    _last_sample_ms = millis();
#endif
}

bool load_cell_ok() {
    if (!load_cell_available()) return false;
    if (!_sample_ok) return false;
    if (millis() - _last_sample_ms > SAMPLE_STALE_MS) return false;
    return true;
}

const char* load_cell_status() {
#if !WB_HX711_PRESENT
    return "library_missing";
#else
    if (!_initialised) return "not_initialised";
    if (!_sample_ok) return "no_samples";
    if (millis() - _last_sample_ms > SAMPLE_STALE_MS) return "stale";
    return "ok";
#endif
}
