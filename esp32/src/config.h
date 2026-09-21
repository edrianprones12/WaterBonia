/**
 * WaterBonia ESP32 - Software Configuration
 *
 * All behavior/timing settings centralized here.
 * Hardware pins are in hardware_config.h — do NOT put pins here.
 */

#ifndef CONFIG_H
#define CONFIG_H

// ── Firmware Identity ────────────────────────────────────
#define FIRMWARE_VERSION       "0.1.0"
#define SYSTEM_NAME            "WaterBonia"

// ── Wi-Fi Settings ───────────────────────────────────────
#define WIFI_TIMEOUT_MS        15000    // Max time to wait for Wi-Fi connection
#define WIFI_RETRY_DELAY_MS    5000     // Delay between Wi-Fi reconnect attempts
#define WIFI_MAX_RETRIES       10       // Max retries before entering long backoff

// ── API Settings ─────────────────────────────────────────
// CHANGE THIS to your XAMPP computer's LAN IP address.
// Example: "http://192.168.1.100/WaterBonia/api"
// Do NOT use "localhost" — that refers to the ESP32 itself.
#define API_BASE_URL           "http://192.168.1.100/WaterBonia/api"
#define API_TIMEOUT_MS         5000     // HTTP request timeout
#define API_MAX_RETRIES        3        // Max retries for failed API calls
#define API_RETRY_BASE_MS      1000     // Base retry delay (exponential backoff)

// ── Timing Intervals ─────────────────────────────────────
// Heartbeat: 30 seconds. Server offline timeout: 120 seconds.
// This allows multiple missed heartbeats before device is marked offline.
#define HEARTBEAT_INTERVAL_MS  30000    // Heartbeat every 30 seconds
#define TELEMETRY_INTERVAL_MS  5000     // Sensor reading every 5 seconds

// ── Hand Detection (HC-SR04) ─────────────────────────────
// Detection range in centimeters.
// Original concept: 2-10 cm range for hand proximity.
#define HAND_MIN_DISTANCE_CM   2.0f
#define HAND_MAX_DISTANCE_CM   10.0f

// ── Cup Detection ────────────────────────────────────────
// Set to 1 if IR sensor outputs LOW when cup is detected.
#define CUP_SENSOR_ACTIVE_LOW  0

// ── Debounce / Stability ─────────────────────────────────
// Number of consecutive readings required to confirm a state change.
#define SENSOR_DEBOUNCE_COUNT  3

// ── Water Level Thresholds ───────────────────────────────
// Two distinct thresholds with different purposes:

// SERVER WARNING THRESHOLD (reference only):
// When water_level <= this, the WaterBonia server may show a LOW WATER warning.
// This value is informational — the server-side system_settings.low_water_threshold
// is the authoritative value for server alerts.
#define SERVER_LOW_WATER_REFERENCE_PERCENT  20

// LOCAL PUMP SAFETY CUTOFF (authoritative on ESP32):
// When water_level <= this, the ESP32 MUST force pump OFF.
// This works even when Wi-Fi and the server are unavailable.
// This is an independent safety threshold — do NOT depend on the server value.
#define LOCAL_PUMP_CUTOFF_PERCENT           15

// ADC range for water level sensor (adjust per sensor).
#define WATER_LEVEL_ADC_MIN    0
#define WATER_LEVEL_ADC_MAX    4095

// ── Pump Safety ──────────────────────────────────────────
// Maximum continuous pump runtime in milliseconds.
// The pump MUST stop after this duration regardless of sensor state.
#define MAX_DISPENSE_TIME_MS   30000    // 30 seconds max

// ── Relay Logic ──────────────────────────────────────────
// Set to 1 if relay module is active-low (LOW = pump ON).
#define RELAY_ACTIVE_LOW       1

// ── RGB LED Logic ────────────────────────────────────────
// Set to 1 if RGB LED is common-anode (LOW = LED ON).
#define RGB_LED_COMMON_ANODE   0

// ── State Machine ────────────────────────────────────────
// Dispenser states
enum DispenserState {
    STATE_INIT,
    STATE_WIFI_CONNECTING,
    STATE_READY,              // No cup — pump OFF
    STATE_CUP_DETECTED,       // Cup present — pump OFF, evaluate stability
    STATE_WAITING_FOR_HAND,   // Cup present, hand not yet detected — pump OFF
    STATE_DISPENSING,         // Cup + hand + sufficient water — pump ON
    STATE_LOW_WATER,          // Water too low — pump OFF
    STATE_ERROR               // Sensor or system error — pump OFF
};

// ── Test Mode ────────────────────────────────────────────
// Set to 1 for development/test mode.
// In test mode:
//   - Sensors can be simulated via serial commands
//   - State machine can be tested locally
//   - Pump/LED behavior can be verified
//   - NO production telemetry API writes
//   - NO production dispensing-session API writes
//   - NO production alert creation through the API
// The existing api/test.php is responsible for API testing.
// Do NOT enable in production.
#define TEST_MODE              0

// Separate flag for developer-only API test requests.
// This defaults to false. Even if TEST_MODE is 1, API writes
// are only performed when this flag is also explicitly set to 1.
// Do not enable in production.
#define ENABLE_API_TEST_REQUESTS  0

#endif // CONFIG_H
