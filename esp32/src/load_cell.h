/**
 * WaterBonia ESP32 - Load Cell (HX711) Module
 *
 * Measures dispensed water weight; grams ≈ millilitres.
 *
 * SAFETY: dispensing auto-stops at the target using this module.
 *   - If the load cell cannot provide readings, dispensing is refused
 *     (unless ALLOW_DISPENSE_WITHOUT_LOAD_CELL is explicitly set for bench tests).
 *   - This firmware does NOT claim a calibrated measurement: the
 *     calibration factor in config.h is a placeholder until the
 *     physical cell is calibrated with a known reference weight.
 *
 * Library: HX711_ADC (https://github.com/olkal/HX711_ADC)
 * Optional at compile time — install it before enabling dispensing.
 */

#ifndef LOAD_CELL_H
#define LOAD_CELL_H

#include <Arduino.h>

// True when the HX711 library is present and the sensor initialised.
bool load_cell_available();

// Initialise and tare the HX711. True only after a fresh, finite sample.
// Sample readiness does not establish physical calibration accuracy.
bool load_cell_begin();

// Poll for a new sample (call frequently, non-blocking).
void load_cell_update();

// Latest validated filtered reading in grams. NAN if invalid or stale.
float load_cell_read_grams();

// Tare at the current weight with the pump OFF, then wait up to 500ms
// for a fresh finite sample. Failed tare leaves load_cell_ok() false.
void load_cell_tare();

// True only with a successful tare and a fresh finite reading.
bool load_cell_ok();

// Human-readable status for serial diagnostics.
const char* load_cell_status();

#endif // LOAD_CELL_H
