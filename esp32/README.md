# WaterBonia ESP32 Firmware

Firmware for the ESP32-based automatic water dispenser controller (**v0.2.0, dispensing v2**).

The firmware executes the four-step HCI flow: it waits for the container to be
placed, polls the server for a dispense request chosen by the web user, runs the
pump under a local safety invariant, and auto-stops when the load cell reaches
the selected, server-authoritative fill cutoff in millilitres. The current
firmware uses an HX711 load cell to measure dispensed water; it does not contain
a flow-counter implementation or a reservoir water-level sensor.

## Hardware List

| Component | Model | Purpose |
|-----------|-------|---------|
| Microcontroller | ESP32 DevKit | Main controller |
| Container Sensor | IR / breakbeam / limit switch | Container **presence** only (does not classify cup vs tumbler) |
| Load Cell + Amplifier | Strain gauge + **HX711** | Measures dispensed water → auto-stop at target |
| Ultrasonic Sensor | HC-SR04 | **Diagnostics only** — not a dispense trigger |
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
- `MAX_DISPENSE_TIME_MS` — maximum pump runtime (30 seconds)
- `MAX_TARGET_ML` — local upper limit, 2,000 mL; production uses the selected server-command cutoff
- `DEFAULT_CONTAINER_TYPE` / `DEFAULT_TARGET_ML` — local `TEST_MODE` serial defaults only, never an offline production fallback
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
1. Choose   web user selects cup or tumbler and its fill cutoff in mL
2. Place    container sensor confirms presence → LED turns cyan
3. Dispense web queues a command → ESP32 polls /device/poll-command
            → claims it → dispenser_request_dispense() runs local checks
            → dispensing/start opens the session
            → pump ON, HX711 measures locally (network requests deferred)
4. Complete measured >= target → pump OFF → dispensing/complete
            → LED flashes white/green → return to READY
```

- **Container sensor = presence only.** It never classifies cup vs tumbler.
- **Target volume is decided by the server**, not the browser. The device adopts
  the validated `target_amount_ml` returned with the polled command and confirmed
  by session start. Each request keeps its selected cutoff through completion.
- **Hand detection (HC-SR04) is diagnostic only** — it never triggers dispensing.
- **The website only requests dispensing.** The ESP32's local safety checks
  always have the final word.
- **Production pump start requires an accepted API session.** A rejected or
  expired command, failed session start, or lost local guard keeps the pump OFF.
- **No reservoir reading is fabricated.** Telemetry and session payloads omit
  the retired water-level fields; the backend keeps those fields null in new records.
- **Network requests are deferred while the pump is ON.** The synchronous HTTP
  client cannot delay local presence, measurement or timeout checks during a
  fill. An OFF telemetry reading and any heartbeat due during the fill are
  queued for the first connected loop after the pump stops. Command polling
  resumes when the next container is stable; session completion reports the
  final measured amount. Hardware progress
  telemetry is unavailable during the fill and may become stale on longer fills.
  The portal must not describe an old sample as a live measurement. Demo Mode's
  browser simulation continues to show its own progress.

- **Completion retry remains unimplemented.** If the completion/result POST fails,
  the pump is already OFF, but the backend can retain an active session and block
  later fills. Network retry/reconciliation must be implemented and tested before
  relying on recovery from interrupted connectivity.

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
        target reached      container removed /
                    ↓       timeout / load-cell fault
                 COMPLETE ──────────────────────► READY
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
| ERROR | Flash Red | OFF | Sensor / system fault |

## Pump Safety Invariant

The pump is permitted to turn ON **only** when ALL of the following are true:
1. Container is detected
2. A dispense request has been **accepted** (web command or local test trigger)
3. The production session start is accepted by the server
4. Load-cell samples are valid (see `ALLOW_DISPENSE_WITHOUT_LOAD_CELL`)
5. Dispense timeout not exceeded (30 s)
6. Emergency stop is not active

Any violation forces pump OFF immediately.

## Fill Cutoff and Reservoir Monitoring

The per-container water threshold is the dispensed-volume cutoff in mL. For
example, an accepted cup command with `target_amount_ml: 300` stops when the
measured dispensed amount reaches 300 mL. This value is unrelated to reservoir
capacity or a minimum tank-water requirement.

This hardware has no reservoir level sensor. It does not read ADC water levels,
report reservoir percentages, reject requests for a missing reservoir sensor,
or detect an empty tank automatically. Container detection, load-cell validity,
the measured cutoff, the maximum runtime, and emergency stop remain active.

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
2. Max timeout reached (30 seconds)
3. Load-cell fault / invalid samples detected
4. Target volume reached
5. Emergency stop triggered

**Default boot state: PUMP OFF**

## Test Mode

Set `TEST_MODE 1` in `src/config.h` to enable development mode.

### What Changes in Test Mode
- Serial commands can simulate sensor states (container, diagnostic hand, errors)
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
DISPENSE        — local dispense trigger (runs all local safety checks)
DISPENSE=tumbler— local trigger using the configured TEST_MODE target (not a production default)
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

[CONTAINER OFF]
[TEST] Simulated container: NOT DETECTED
[SAFETY] Container removed — stopping pump.
[PUMP] OFF
[STATE] DISPENSING → READY
```

**Do NOT enable `TEST_MODE` in production.**

## Serial Output Format

```
[BOOT] WaterBonia ESP32 Firmware v0.2.0
[MODE] TEST MODE - API writes disabled
[WIFI] Connected. IP: 192.168.1.20
[DISPENSER] Init complete. Pump OFF.
[STATE] INIT → READY
[STATUS] State: READY | Container: NO | Measured: 0/0 mL | Pump: OFF | LoadCell: ok | Wi-Fi: OK
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
| Pump won't start | Session start rejected or local presence/measurement guard failed | Check command expiry, API connection, container placement and HX711 status |
| Pump runs then stops early | Tare drift / `LOAD_CELL_MIN_VALID_G` | Recalibrate and re-tare |
| Pump runs indefinitely | Max timeout disabled | Check `MAX_DISPENSE_TIME_MS` |
| API errors (401) | Invalid token | Generate new token on web dashboard |
| API errors (429) | Rate limited | Wait for backoff to expire |
| LED not working | Wrong common mode | Set `RGB_LED_COMMON_ANODE` correctly |
| Sensor errors | Wiring issue | Check all connections, voltage levels |
