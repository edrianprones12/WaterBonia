/**
 * WaterBonia ESP32 - Hardware Configuration
 *
 * All GPIO pin assignments centralized here.
 * Change pin numbers here — do NOT scatter them through the firmware.
 *
 * IMPORTANT: ESP32 GPIO voltage tolerance:
 * - Most GPIOs are 3.3V only
 * - HC-SR04 echo may output 5V — use voltage divider
 * - IR sensors typically output 3.3V or 5V — verify your module
 */

#ifndef HARDWARE_CONFIG_H
#define HARDWARE_CONFIG_H

// ── HC-SR04 Ultrasonic (Hand Detection) ──────────────────
// Note: Echo line may output 5V. Use a voltage divider (e.g. 1kΩ + 2kΩ)
//       to bring echo signal to 3.3V safe level for ESP32 GPIO.
#define PIN_HCSR04_TRIG        25
#define PIN_HCSR04_ECHO        26

// ── IR Proximity Sensor (Cup Detection) ──────────────────
// Connect IR sensor digital output here.
// If sensor is active-low, set CUP_SENSOR_ACTIVE_LOW in config.h
#define PIN_CUP_SENSOR         27

// ── Water Level Sensor ───────────────────────────────────
// Placeholder pins — adjust when final sensor is selected.
// For analog sensors: use ADC-capable pins (GPIO 32-39 on ESP32).
// For digital/serial sensors: adjust pins and protocol accordingly.
#define PIN_WATER_LEVEL_ANALOG 34   // ADC1 channel (safe for Wi-Fi)

// ── Relay / Pump Control ─────────────────────────────────
// Relay module IN pin. Default safe state: OFF (relay inactive).
// Most relay modules are active-low (LOW = relay ON).
#define PIN_PUMP_RELAY         32

// ── RGB LED (Common Anode or Common Cathode) ─────────────
// Set RGB_LED_COMMON_ANODE in config.h if using common-anode LED.
#define PIN_LED_RED            14
#define PIN_LED_GREEN          12
#define PIN_LED_BLUE           13

#endif // HARDWARE_CONFIG_H
