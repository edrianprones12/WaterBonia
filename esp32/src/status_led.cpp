/**
 * WaterBonia ESP32 - Status LED Implementation
 *
 * v2 color scheme:
 *   BLUE                        = ready (no container)
 *   CYAN                        = container detected / awaiting dispense command
 *   GREEN                       = dispensing
 *   WHITE flashing (with green) = dispense complete
 *   YELLOW flashing             = boot / Wi-Fi connection
 *   RED flashing                = error
 *
 * The LED is an indicator only — it is NOT a safety mechanism.
 */

#include "status_led.h"
#include "hardware_config.h"

static bool _led_state = false;        // For flashing
static unsigned long _last_flash = 0;
static const unsigned long FLASH_INTERVAL_MS = 250;
static bool _flash_phase = false;      // For the two-color complete flash

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
            // Solid blue — ready, waiting for a container
            led_set_color(0, 0, 255);
            break;

        case STATE_CONTAINER_DETECTED:
        case STATE_WAITING_FOR_COMMAND:
            // Cyan — container in place, awaiting dispense command
            led_set_color(0, 255, 255);
            break;

        case STATE_DISPENSING:
            // Green — actively dispensing
            led_set_color(0, 255, 0);
            break;

        case STATE_COMPLETE:
            // Alternating white / green flash — dispensing finished
            if (millis() - _last_flash >= FLASH_INTERVAL_MS) {
                _last_flash = millis();
                _flash_phase = !_flash_phase;
            }
            if (_flash_phase) {
                led_set_color(255, 255, 255); // White
            } else {
                led_set_color(0, 255, 0);     // Green
            }
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
