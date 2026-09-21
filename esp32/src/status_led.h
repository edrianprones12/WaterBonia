/**
 * WaterBonia ESP32 - Status LED Module
 *
 * RGB LED indicates local dispenser state.
 * Modular — easy to change colors/behavior.
 * LED is NOT a safety mechanism.
 */

#ifndef STATUS_LED_H
#define STATUS_LED_H

#include <Arduino.h>
#include "config.h"

// Initialize LED pins
void led_init();

// Update LED based on dispenser state (call from main loop)
void led_update(DispenserState state);

// Set LED color directly (for custom states)
void led_set_color(int r, int g, int b);

// Turn off all LEDs
void led_off();

#endif // STATUS_LED_H
