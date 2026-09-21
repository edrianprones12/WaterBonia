/**
 * WaterBonia ESP32 - Status LED Implementation
 *
 * Color scheme:
 *   RED    = Standby / no cup
 *   BLUE   = Cup detected / waiting for hand
 *   GREEN  = Dispensing
 *   YELLOW = Low water / warning
 *   FLASHING RED = Error
 */

#include "status_led.h"
#include "hardware_config.h"

static bool _led_state = false;        // For flashing
static unsigned long _last_flash = 0;
static const unsigned long FLASH_INTERVAL_MS = 250;

void led_init() {
    pinMode(PIN_LED_RED, OUTPUT);
    pinMode(PIN_LED_GREEN, OUTPUT);
    pinMode(PIN_LED_BLUE, OUTPUT);
    led_off();
}

void led_set_color(int r, int g, int b) {
    #if RGB_LED_COMMON_ANODE
        // Common anode: LOW = LED ON, invert values
        analogWrite(PIN_LED_RED,   255 - r);
        analogWrite(PIN_LED_GREEN, 255 - g);
        analogWrite(PIN_LED_BLUE,  255 - b);
    #else
        // Common cathode: HIGH = LED ON
        analogWrite(PIN_LED_RED,   r);
        analogWrite(PIN_LED_GREEN, g);
        analogWrite(PIN_LED_BLUE,  b);
    #endif
}

void led_off() {
    led_set_color(0, 0, 0);
}

void led_update(DispenserState state) {
    switch (state) {
        case STATE_INIT:
        case STATE_WIFI_CONNECTING:
            // Flashing yellow during init/connecting
            if (millis() - _last_flash >= FLASH_INTERVAL_MS) {
                _last_flash = millis();
                _led_state = !_led_state;
            }
            if (_led_state) {
                led_set_color(255, 255, 0); // Yellow
            } else {
                led_off();
            }
            break;

        case STATE_READY:
            // Solid red — standby, no cup
            led_set_color(255, 0, 0);
            break;

        case STATE_CUP_DETECTED:
            // Blue — cup detected, evaluating stability
            led_set_color(0, 0, 255);
            break;

        case STATE_WAITING_FOR_HAND:
            // Blue — cup present, waiting for hand
            led_set_color(0, 0, 255);
            break;

        case STATE_DISPENSING:
            // Green — actively dispensing
            led_set_color(0, 255, 0);
            break;

        case STATE_LOW_WATER:
            // Yellow — low water warning
            led_set_color(255, 255, 0);
            break;

        case STATE_ERROR:
            // Flashing red — error
            if (millis() - _last_flash >= FLASH_INTERVAL_MS) {
                _last_flash = millis();
                _led_state = !_led_state;
            }
            if (_led_state) {
                led_set_color(255, 0, 0);
            } else {
                led_off();
            }
            break;
    }
}
