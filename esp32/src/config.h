/**
 * WaterBonia ESP32 - Software Configuration
 *
 * All behavior/timing settings centralized here.
 * Hardware pins are in hardware_config.h — do NOT put pins here.
 *
 * Dispensing v2:
 *   Choose container (web) → Place container (sensor)
 *   → Dispense (web command) → auto-stop at target (load cell)
 */

#ifndef CONFIG_H
#define CONFIG_H

// ── Firmware Identity ────────────────────────────────────
#define FIRMWARE_VERSION       "0.2.0"
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

// How often the ESP32 polls the server for a dispense command.
// The web UI queues a command; the ESP32 claims it here.
#define COMMAND_POLL_INTERVAL_MS 2000

// ── Container Detection ──────────────────────────────────
// Presence-only sensor (IR / IR-breakbeam / limit switch).
// It does NOT classify cup vs tumbler — the web user chooses the type.
// Set to 1 if the sensor outputs LOW when a container is present.
#define CONTAINER_SENSOR_ACTIVE_LOW 0

// Legacy alias: older code and telemetry used the name "cup_detected".
#define CUP_SENSOR_ACTIVE_LOW  CONTAINER_SENSOR_ACTIVE_LOW

// ── Hand Detection (HC-SR04, optional / diagnostic only) ──
// NOTE: Hand detection is NOT a dispense trigger in v2.
// It is kept for diagnostics and future optional gestures.
#define HAND_MIN_DISTANCE_CM   2.0f
#define HAND_MAX_DISTANCE_CM   10.0f

// ── Debounce / Stability ─────────────────────────────────
// Number of consecutive readings required to confirm a state change.
#define SENSOR_DEBOUNCE_COUNT  3

// Container presence must be stable this long before READY → CONTAINER_DETECTED.
#define CONTAINER_STABLE_MS    300

// ── Dispense Targets ─────────────────────────────────────
// Production uses the selected target_amount_ml in the claimed server command.
// No offline production request substitutes a fixed target.
// Defaults below are for the local TEST_MODE serial trigger only.
#define DEFAULT_CONTAINER_TYPE "cup"
#define DEFAULT_TARGET_ML      250
#define MAX_TARGET_ML          2000

// ── Load Cell (HX711) ────────────────────────────────────
// Measured water weight in grams ≈ millilitres (density ≈ 1 g/mL).
// CALIBRATION FACTOR IS HARDWARE-SPECIFIC.
// The placeholder below is NOT a calibrated value — replace it after
// calibrating with a known reference weight. Tare is performed at the
// start of every dispensing session.
#define LOAD_CELL_CALIBRATION_FACTOR  1.0f

// Reading considered "stable enough" to compare against target (grams).
#define LOAD_CELL_SETTLE_MS     150
#define LOAD_CELL_SAMPLE_MS     20

// Minimum measured weight before the target check engages.
// Guards against tare drift starting an instant "completion".
#define LOAD_CELL_MIN_VALID_G   1.0f

// If the load cell reports an error, the pump MUST NOT run.
// Set to 1 only for bench testing without a load cell installed.
// NEVER enable in production — auto-stop at target depends on it.
#define ALLOW_DISPENSE_WITHOUT_LOAD_CELL  0

// ── Pump Safety ──────────────────────────────────────────
// Maximum continuous pump runtime in milliseconds.
// The pump MUST stop after this duration regardless of sensor state.
#define MAX_DISPENSE_TIME_MS   30000    // 30 seconds max

// ── Complete State Hold ──────────────────────────────────
// How long the "complete" LED/status is shown before returning to READY.
#define COMPLETE_HOLD_MS       4000

// ── Relay Logic ──────────────────────────────────────────
// Set to 1 if relay module is active-low (LOW = pump ON).
#define RELAY_ACTIVE_LOW       1

// ── RGB LED Logic ────────────────────────────────────────
// Set to 1 if RGB LED is common-anode (LOW = LED ON).
#define RGB_LED_COMMON_ANODE   0

// ── State Machine ────────────────────────────────────────
// Dispenser states (v2)
enum DispenserState {
    STATE_INIT,
    STATE_WIFI_CONNECTING,
    STATE_READY,                // No container — pump OFF
    STATE_CONTAINER_DETECTED,   // Container present, stabilising — pump OFF
    STATE_WAITING_FOR_COMMAND,  // Container ready, awaiting dispense command — pump OFF
    STATE_DISPENSING,           // Pump ON, load cell measuring to target
    STATE_COMPLETE,             // Target reached — pump OFF, brief hold
    STATE_ERROR                 // Sensor/system fault — pump OFF
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
