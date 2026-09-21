# WaterBonia ESP32 Firmware

Firmware for the ESP32-based smart water dispenser controller.

## Hardware List

| Component | Model | Purpose |
|-----------|-------|---------|
| Microcontroller | ESP32 DevKit | Main controller |
| Ultrasonic Sensor | HC-SR04 | Hand detection (2-10 cm range) |
| IR Proximity Sensor | Generic IR obstacle sensor | Cup detection |
| Water Level Sensor | TBD (analog) | Tank water level monitoring |
| Relay Module | 1-channel 5V relay | Pump switching |
| Water Pump | 5V/12V DC pump | Water dispensing |
| RGB LED | Common cathode/anode | Status indication |
| Power Supply | 5V USB or adapter | ESP32 + sensors |

## Wiring Overview

| Component | ESP32 GPIO | Notes |
|-----------|-----------|-------|
| HC-SR04 TRIG | GPIO 25 | Output |
| HC-SR04 ECHO | GPIO 26 | **Use voltage divider** (5V → 3.3V) |
| Cup IR Sensor | GPIO 27 | Digital input |
| Water Level Sensor | GPIO 34 | ADC1 (safe with Wi-Fi) |
| Pump Relay IN | GPIO 32 | Default OFF state |
| RGB LED Red | GPIO 14 | |
| RGB LED Green | GPIO 12 | |
| RGB LED Blue | GPIO 13 | |

**IMPORTANT:** HC-SR04 echo pin outputs 5V. ESP32 GPIO max is 3.3V. Use a voltage divider (e.g. 1kΩ + 2kΩ) to bring echo signal to safe level.

## GPIO Assignments

All pin assignments are in `include/hardware_config.h`. Change pins there — do NOT modify individual source files.

## Required Libraries

- Arduino core for ESP32
- HTTPClient (built-in)
- WiFi (built-in)
- Preferences (built-in for NVS)

No external libraries required for the base firmware.

## Build & Upload

### Option A: Arduino IDE
1. Install Arduino IDE
2. Add ESP32 board support (Board Manager URL: `https://dl.espressif.com/dl/package_esp32_index.json`)
3. Select board: "ESP32 Dev Module"
4. Open all `.cpp` and `.h` files from `src/` and `include/`
5. Create `secrets.h` from `secrets.example.h` with your credentials
6. Build and upload

### Option B: PlatformIO
1. Create `platformio.ini` in `esp32/` root
2. Set `board = esp32dev`, `framework = arduino`
3. Copy source files to `src/`
4. Run `pio run -t upload`

## Configuration

### Software Settings
All behavior settings are in `src/config.h`:
- `API_BASE_URL` — **CHANGE THIS** to your XAMPP computer's LAN IP
- `HEARTBEAT_INTERVAL_MS` — default 30 seconds
- `TELEMETRY_INTERVAL_MS` — default 5 seconds
- `HAND_MIN_DISTANCE_CM` / `HAND_MAX_DISTANCE_CM` — detection range
- `LOCAL_PUMP_CUTOFF_PERCENT` — ESP32 force-stops pump below this (15%)
- `SERVER_LOW_WATER_REFERENCE_PERCENT` — server-side warning threshold (20%)
- `MAX_DISPENSE_TIME_MS` — maximum pump runtime (30 seconds)

### Hardware Settings
All GPIO pins are in `include/hardware_config.h`.

### Secrets
Copy `secrets.example.h` to `secrets.h` and fill in:
- `SECRET_WIFI_SSID`
- `SECRET_WIFI_PASSWORD`
- `SECRET_DEVICE_TOKEN`

**NEVER commit `secrets.h` to version control.**

## API Base URL Configuration

**Do NOT use `localhost`** — that refers to the ESP32 itself.

The ESP32 and XAMPP computer must be on the same local network.

1. Find your XAMPP computer's LAN IP: `ipconfig` (Windows) or `ifconfig` (Mac/Linux)
2. Update `API_BASE_URL` in `config.h`:
   ```cpp
   #define API_BASE_URL "http://192.168.1.100/WaterBonia/api"
   ```

## Wi-Fi Provisioning

### First-time Setup
1. Flash firmware to ESP32
2. Open Serial Monitor (115200 baud)
3. Type `p` within 5 seconds of boot (or hold BOOT button)
4. Enter when prompted:
   - Wi-Fi SSID
   - Wi-Fi Password
   - Device Token (from web dashboard → Devices → Generate Token)
   - Device Code (e.g. WB-DEV-001)
5. ESP32 saves credentials to NVS and reboots
6. Normal operation begins

### Changing Credentials
Type `p` during boot to re-enter provisioning mode. This overwrites previous credentials.

### Factory Reset
To clear all stored credentials and enter provisioning mode, hold the BOOT button during power-on for 3+ seconds.

## Device Token Provisioning

Tokens are generated from the WaterBonia web dashboard:
1. Log in as admin
2. Go to Devices page
3. Click "Generate Token" for the target device
4. Copy the raw token immediately — it is shown only once
5. Enter the token during ESP32 serial provisioning

**The ESP32 does NOT generate tokens. Tokens come from the server.**

## State Machine

```
INIT → WIFI_CONNECTING → READY → CUP_DETECTED → WAITING_FOR_HAND → DISPENSING
              ↓                                       ↓                ↓
            READY ←──────────────────────────────────┘                 ↓
              ↓                                                        ↓
         LOW_WATER → READY (when water recovers)     ←────────────────┘
              ↓
         ERROR → READY (when sensor recovers)
```

| State | LED | Pump | Meaning |
|-------|-----|------|---------|
| INIT | Flash Yellow | OFF | Booting |
| WIFI_CONNECTING | Flash Yellow | OFF | Connecting to Wi-Fi |
| READY | Red | OFF | Standby, waiting for cup |
| CUP_DETECTED | Blue | OFF | Cup present, evaluating stability |
| WAITING_FOR_HAND | Blue | OFF | Cup present, waiting for hand |
| DISPENSING | Green | ON | Pump active |
| LOW_WATER | Yellow | OFF | Water level too low |
| ERROR | Flash Red | OFF | Sensor error |

## Pump Safety Invariant

The pump is permitted to turn ON **only** when ALL of the following are true:
1. `cup_detected == true`
2. `hand_detected == true`
3. `water_level > LOCAL_PUMP_CUTOFF_PERCENT` (15%)
4. No sensor safety fault exists
5. Dispense timeout not exceeded (30s)
6. Emergency stop is not active

All other conditions require pump OFF.

## Water Level Thresholds

Two distinct thresholds with different purposes:

| Threshold | Value | Purpose |
|-----------|-------|---------|
| `LOCAL_PUMP_CUTOFF_PERCENT` | 15% | **ESP32 enforces locally** — force-stops pump even without Wi-Fi |
| `SERVER_LOW_WATER_REFERENCE_PERCENT` | 20% | Informational — server may display LOW WATER warning |

The ESP32 cutoff is authoritative. The server threshold is advisory.

## Safety Rules

Pump MUST stop when:
1. Cup is removed
2. Hand is removed
3. Water level below local cutoff (15%)
4. Max timeout reached (30 seconds)
5. Sensor error detected
6. Emergency stop triggered

**Default boot state: PUMP OFF**

## Test Mode

Set `TEST_MODE 1` in `src/config.h` to enable development mode.

### What Changes in Test Mode
- Serial commands can simulate sensor states (cup, hand, water level, errors)
- Pump and LED behavior can be verified locally
- State machine transitions can be tested without hardware sensors

### What Is Disabled in Test Mode
- **NO** production telemetry API writes (no sensor readings sent to server)
- **NO** production dispensing-session API writes
- **NO** production alert creation through the API
- The existing `api/test.php` is responsible for API testing

### Separate API Test Flag
`ENABLE_API_TEST_REQUESTS` (defaults to 0) is an independent flag. Even with `TEST_MODE=1`, API writes only occur if `ENABLE_API_TEST_REQUESTS=1`. This prevents accidental production writes during hardware testing.

### Test Mode Serial Commands

```
CUP ON        — simulate cup detected
CUP OFF       — simulate cup removed
HAND ON       — simulate hand detected
HAND OFF      — simulate hand removed
WATER=50      — set simulated water level (0-100)
SENSOR ERROR  — simulate sensor error
SENSOR OK     — clear simulated sensor error
HELP          — show available commands
```

Example session:
```
[CUP ON]
[TEST] Simulated cup: DETECTED
[STATE] READY → CUP_DETECTED → WAITING_FOR_HAND

[HAND ON]
[TEST] Simulated hand: DETECTED
[STATE] WAITING_FOR_HAND → DISPENSING
[PUMP] ON

[WATER=10]
[TEST] Simulated water level: 10%
[SAFETY] Low water — stopping pump.
[PUMP] OFF
[STATE] DISPENSING → LOW_WATER
```

**Do NOT enable `TEST_MODE` in production.**

## Serial Output Format

```
[BOOT] WaterBonia ESP32 Firmware v0.1.0
[MODE] TEST MODE - API writes disabled
[WIFI] Connected. IP: 192.168.1.20
[SENSOR] Init complete. Pump OFF.
[STATE] INIT → READY
[STATUS] State: READY | Cup: NO | Hand: NO | Water: 72% | Pump: OFF | Wi-Fi: OK
[SENSOR] Cup: YES
[STATE] READY → CUP_DETECTED
[STATE] CUP_DETECTED → WAITING_FOR_HAND
[SENSOR] Hand: YES
[PUMP] ON
[STATE] WAITING_FOR_HAND → DISPENSING
[API] Session started: #42
[PUMP] OFF
[API] Session #42 completed
```

## Troubleshooting

| Symptom | Possible Cause | Solution |
|---------|---------------|----------|
| Stays in WIFI_CONNECTING | Wrong SSID/password | Re-enter provisioning |
| Pump won't start | Cup not detected | Check IR sensor wiring |
| Pump won't start | Hand out of range | Adjust HAND_MIN/MAX in config.h |
| Pump stops immediately | Low water | Refill tank or check LOCAL_PUMP_CUTOFF_PERCENT |
| Pump runs indefinitely | Max timeout disabled | Check MAX_DISPENSE_TIME_MS |
| API errors (401) | Invalid token | Generate new token on web dashboard |
| API errors (429) | Rate limited | Wait for backoff to expire |
| LED not working | Wrong common mode | Set RGB_LED_COMMON_ANODE correctly |
| Sensor errors | Wiring issue | Check all connections, voltage levels |
