# WaterBonia ESP32 Firmware

Firmware for the ESP32-based automatic water dispenser controller (**v0.2.0, dispensing v2**).

The firmware executes the four-step HCI flow: it waits for the container to be
placed, polls the server for a dispense request chosen by the web user, runs the
pump under a local safety invariant, and auto-stops when the load cell reaches
the server-authoritative target volume.

## Hardware List

| Component | Model | Purpose |
|-----------|-------|---------|
| Microcontroller | ESP32 DevKit | Main controller |
| Container Sensor | IR / breakbeam / limit switch | Container **presence** only (does not classify cup vs tumbler) |
| Load Cell + Amplifier | Strain gauge + **HX711** | Measures dispensed water → auto-stop at target |
| Ultrasonic Sensor | HC-SR04 | **Diagnostics only** — not a dispense trigger |
| Water Level Sensor | TBD (analog) | Tank water level monitoring |
| Relay Module | 1-channel 5V relay | Pump switching |
| Water Pump | 5V/12V DC pump | Water dispensing |
| RGB LED | Common cathode/anode | Status indication |
| Power Supply | 5V USB or adapter | ESP32 + sensors |

## Wiring Overview

| Component | ESP32 GPIO | Notes |
|-----------|-----------|-------|
| Container sensor | GPIO 27 | Digital input; active polarity in `config.h` (`CONTAINER_SENSOR_ACTIVE_LOW`) |
| HX711 DOUT | GPIO 19 | **PLACEHOLDER — not yet wired** |
| HX711 SCK | GPIO 18 | **PLACEHOLDER — not yet wired** |
| HC-SR04 TRIG | GPIO 25 | Output (diagnostic) |
| HC-SR04 ECHO | GPIO 26 | **Use voltage divider** (5V → 3.3V) |
| Water Level Sensor | GPIO 34 | ADC1 (safe with Wi-Fi) |
| Pump Relay IN | GPIO 32 | Default OFF state |
| RGB LED Red | GPIO 14 | |
| RGB LED Green | GPIO 12 | |
| RGB LED Blue | GPIO 13 | |

**IMPORTANT:**
- HC-SR04 echo pin outputs 5V. ESP32 GPIO max is 3.3V. Use a voltage divider (e.g. 1kΩ + 2kΩ).
- The HX711 GPIO assignments are **placeholders**. Confirm them against your final
  schematic before powering hardware, and never claim the load cell as working
  until it has been calibrated.

## GPIO Assignments

All pin assignments are in `include/hardware_config.h`. Change pins there — do NOT modify individual source files.

## Required Libraries

- Arduino core for ESP32
- HTTPClient (built-in)
- WiFi (built-in)
- Preferences (built-in for NVS)
- **HX711_ADC** (or any HX711 library) — required for measured dispensing

The load cell code is guarded with `__has_include(<HX711_ADC.h>)`, so the
firmware still compiles without the library — but `ALLOW_DISPENSE_WITHOUT_LOAD_CELL`
is `0`, which means **dispensing will be rejected** without a working load cell.

## Build & Upload

### Option A: Arduino IDE
1. Install Arduino IDE
2. Add ESP32 board support (Board Manager URL: `https://dl.espressif.com/dl/package_esp32_index.json`)
3. Install the **HX711_ADC** library from the Library Manager
4. Select board: "ESP32 Dev Module"
5. Open all `.cpp` and `.h` files from `src/` and `include/`
6. Create `secrets.h` from `secrets.example.h` with your credentials
7. Build and upload

### Option B: PlatformIO
1. Create `platformio.ini` in `esp32/` root
2. Set `board = esp32dev`, `framework = arduino`
3. Add `HX711_ADC` to `lib_deps`
4. Copy source files to `src/`
5. Run `pio run -t upload`

## Configuration

### Software Settings
All behavior settings are in `src/config.h`:
- `API_BASE_URL` — **CHANGE THIS** to your XAMPP computer's LAN IP
- `HEARTBEAT_INTERVAL_MS` — default 30 seconds
- `TELEMETRY_INTERVAL_MS` — default 5 seconds
- `COMMAND_POLL_INTERVAL_MS` — default 2 seconds (dispense command polling)
- `CONTAINER_STABLE_MS` — presence must be stable 300 ms before proceeding
- `LOCAL_PUMP_CUTOFF_PERCENT` — ESP32 force-stops pump at/below this (15%)
- `SERVER_LOW_WATER_REFERENCE_PERCENT` — server-side warning threshold (20%)
- `MAX_DISPENSE_TIME_MS` — maximum pump runtime (30 seconds)
- `DEFAULT_TARGET_ML` / `MAX_TARGET_ML` — **offline fallback only**; the server is authoritative
- `LOAD_CELL_CALIBRATION_FACTOR` — **placeholder 1.0, not calibrated**
- `ALLOW_DISPENSE_WITHOUT_LOAD_CELL` — must stay `0` in production

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

## Dispensing v2 Flow

```
1. Choose   web user selects cup (250 mL) or tumbler (500 mL)
2. Place    container sensor confirms presence → LED turns cyan
3. Dispense web queues a command → ESP32 polls /device/poll-command
            → claims it → dispenser_request_dispense() runs local checks
            → dispensing/start opens the session
            → pump ON, HX711 measures, telemetry reports measured_ml
4. Complete measured >= target → pump OFF → dispensing/complete
            → LED flashes white/green → return to READY
```

- **Container sensor = presence only.** It never classifies cup vs tumbler.
- **Target volume is decided by the server**, not the browser. The device adopts
  the `target_amount_ml` returned with the polled command.
- **Hand detection (HC-SR04) is diagnostic only** — it never triggers dispensing.
- **The website only requests dispensing.** The ESP32's local safety checks
  always have the final word.

## State Machine

```
INIT → WIFI_CONNECTING → READY
                          ↓
              CONTAINER_DETECTED ──(container removed)──► READY
                          ↓
               WAITING_FOR_COMMAND ──(container removed)──► READY
                          ↓  (accepted dispense request + local checks pass)
                     DISPENSING
                    ↙          ↘
        target reached      container removed / low water /
                    ↓       timeout / load-cell fault
                 COMPLETE ──────────────────────► READY
Any state → LOW_WATER → READY (when water recovers)
Any state → ERROR     → READY (when sensors recovers)
```

| State | LED | Pump | Meaning |
|-------|-----|------|---------|
| INIT | Flash Yellow | OFF | Booting |
| WIFI_CONNECTING | Flash Yellow | OFF | Connecting to Wi-Fi |
| READY | Solid Blue | OFF | No container — waiting for the user to place one |
| CONTAINER_DETECTED | Solid Cyan | OFF | Container present, stabilising |
| WAITING_FOR_COMMAND | Solid Cyan | OFF | Container ready — awaiting the web dispense command |
| DISPENSING | Solid Green | ON | Pump active, load cell measuring toward target |
| COMPLETE | Flash White/Green | OFF | Target reached — hold, then return to READY |
| LOW_WATER | Solid Yellow | OFF | Water level too low |
| ERROR | Flash Red | OFF | Sensor / system fault |

## Pump Safety Invariant

The pump is permitted to turn ON **only** when ALL of the following are true:
1. Container is detected
2. A dispense request has been **accepted** (web command or local test trigger)
3. `water_level > LOCAL_PUMP_CUTOFF_PERCENT` (15%)
4. Load-cell samples are valid (see `ALLOW_DISPENSE_WITHOUT_LOAD_CELL`)
5. Dispense timeout not exceeded (30 s)
6. Emergency stop is not active

Any violation forces pump OFF immediately.

## Water Level Thresholds

Two distinct thresholds with different purposes:

| Threshold | Value | Purpose |
|-----------|-------|---------|
| `LOCAL_PUMP_CUTOFF_PERCENT` | 15% | **ESP32 enforces locally** — force-stops pump even without Wi-Fi |
| `SERVER_LOW_WATER_REFERENCE_PERCENT` | 20% | Informational — server may display LOW WATER warning |

The ESP32 cutoff is authoritative. The server threshold is advisory.

## Load Cell (HX711)

- Measured weight in grams ≈ millilitres (water density ≈ 1 g/mL).
- **Tare is performed at the start of every dispensing session.**
- `LOAD_CELL_SETTLE_MS` / `LOAD_CELL_SAMPLE_MS` gate when a reading is compared
  against the target; `LOAD_CELL_MIN_VALID_G` guards against tare drift causing
  an instant completion.
- If the load cell reports an error or stale data, the pump must not run.
- **The calibration factor is a placeholder.** Calibrate with a known reference
  weight before recording any real dispensing volumes.

## Safety Rules

Pump MUST stop when:
1. Container is removed
2. Water level at/below local cutoff (15%)
3. Max timeout reached (30 seconds)
4. Load-cell fault / invalid samples detected
5. Target volume reached
6. Emergency stop triggered

**Default boot state: PUMP OFF**

## Test Mode

Set `TEST_MODE 1` in `src/config.h` to enable development mode.

### What Changes in Test Mode
- Serial commands can simulate sensor states (container, hand, water level, errors)
- A local `DISPENSE` trigger runs the full local safety path without a server command
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
CONTAINER ON    — simulate container detected (CUP ON is a legacy alias)
CONTAINER OFF   — simulate container removed
HAND ON / OFF   — simulate hand detection (diagnostic only)
WATER=50        — set simulated water level (0-100)
DISPENSE        — local dispense trigger (runs all local safety checks)
DISPENSE=tumbler— local dispense trigger for the tumbler default target
SENSOR ERROR    — simulate sensor error
SENSOR OK       — clear simulated sensor error
HELP            — show available commands
```

Example session:
```
[CONTAINER ON]
[TEST] Simulated container: DETECTED
[STATE] READY → CONTAINER_DETECTED → WAITING_FOR_COMMAND

[DISPENSE]
[TEST] Dispense request (cup): ACCEPTED
[STATE] WAITING_FOR_COMMAND → DISPENSING
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
[BOOT] WaterBonia ESP32 Firmware v0.2.0
[MODE] TEST MODE - API writes disabled
[WIFI] Connected. IP: 192.168.1.20
[DISPENSER] Init complete. Pump OFF.
[STATE] INIT → READY
[STATUS] State: READY | Container: NO | Hand: NO | Water: 72% | Pump: OFF | Wi-Fi: OK
[SENSOR] Container: YES
[STATE] READY → CONTAINER_DETECTED → WAITING_FOR_COMMAND
[API] Command #5: dispense cup → 250 mL
[STATE] WAITING_FOR_COMMAND → DISPENSING
[API] Session started: #456 (target 250 mL)
[STATE] DISPENSING → COMPLETE
[PUMP] OFF
[API] Session #456 completed: measured 252 mL (target_reached)
```

## Troubleshooting

| Symptom | Possible Cause | Solution |
|---------|---------------|----------|
| Stays in WIFI_CONNECTING | Wrong SSID/password | Re-enter provisioning |
| Never leaves READY | Container sensor not detecting | Check wiring; set `CONTAINER_SENSOR_ACTIVE_LOW` correctly |
| Stays in WAITING_FOR_COMMAND | No command queued | Press DISPENSE on the dashboard; check device is online |
| Request rejected: load cell not ready | HX711 missing/uncalibrated | Install HX711 library, check DOUT/SCK wiring |
| Pump won't start | Water below 15% cutoff | Refill tank or check `LOCAL_PUMP_CUTOFF_PERCENT` |
| Pump runs then stops early | Tare drift / `LOAD_CELL_MIN_VALID_G` | Recalibrate and re-tare |
| Pump runs indefinitely | Max timeout disabled | Check `MAX_DISPENSE_TIME_MS` |
| API errors (401) | Invalid token | Generate new token on web dashboard |
| API errors (429) | Rate limited | Wait for backoff to expire |
| LED not working | Wrong common mode | Set `RGB_LED_COMMON_ANODE` correctly |
| Sensor errors | Wiring issue | Check all connections, voltage levels |
