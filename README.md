# WaterBonia: A Web- and Sensor-Based Automatic Water Dispensing System

WaterBonia combines a public dispensing interface, a PHP REST API, MySQL, and ESP32 firmware. Users adjust the dispensing threshold for each container to match its size. The HX711 load cell measures dispensed water so the pump stops at the selected amount; there is no water-level sensor. Demo Mode explicitly simulates the workflow.

The dispensing interaction follows a fixed four-step sequence:

1. **Choose** — pick Cup or Tumbler in the Execution Portal
2. **Place** — adjust the dispensing amount in mL and detect the container on the tray
3. **Dispense** — the user presses DISPENSE; the request is queued and the ESP32 runs the pump
4. **Complete** — display the target, dispensed amount, and stopped pump; Demo Mode simulates the full sequence

Hand detection (HC-SR04) is retained for diagnostics only — it is **not** a dispense trigger.

This project is developed as part of **IT 114 - Advanced Human and Computer Interaction**, focusing on user-centered design principles for IoT monitoring interfaces.

---

## IT 114 Context

WaterBonia addresses key concepts in Advanced Human and Computer Interaction:

- **Visible Interaction Sequence** — the dashboard makes the four-step flow (Choose → Place → Dispense → Complete) explicit with a persistent step rail
- **Dashboard Usability** — Real-time sensor data presented through an intuitive monitoring interface
- **Information Architecture** — Sidebar views for dispensing, container settings, device feedback, activity, and safety guidance
- **Feedback Systems** — Immediate visual feedback via RGB LED states, live dashboard polling, progress from measured volume, and alert notifications
- **Error Prevention** — the DISPENSE button stays disabled until every precondition holds, and a multi-condition pump safety invariant prevents unsafe dispensing
- **Status Visibility** — Device connection, container presence, pump status, and measured dispensing progress
- **Validated Fill Amounts** — Each selected amount is checked by the server and saved as the immutable target of its command/session
- **Responsive Interface** — Stacked controls and supporting cards, with activity entries adapted for small screens
- **User-Centered Monitoring** — Recent meaningful events with date and time in Activity Logs

See the `docs/` folder for full IT 114 HCI documentation.

---

## Key Features

- **Container Selection** — Cup defaults to 250 mL and Tumbler to 500 mL; each amount is independently adjustable
- **Container Presence Sensing** — confirms the container is placed before dispensing can be requested
- **Measured Auto-Stop** — HX711 load cell measures dispensed water and stops the pump at the target
- **Command Queue** — the web dashboard queues a request; the ESP32 polls, validates, and executes it
- **Multi-Condition Safety** — pump runs only with container present, an authorized session, valid load-cell samples, and no timeout/fault
- **Real-Time Web Dashboard** — live container, pump status, and measured dispense progress
- **Alert System** — Device-offline alerts with acknowledgement workflow; water-level alerts are retired
- **Device Management** — Register ESP32 devices, generate/revoke API tokens
- **Activity Logging** — Comprehensive event logging for all system actions
- **Analytics** — cup/tumbler session counts, measured-volume totals, and usage charts
- **RESTful API** — Secure Bearer-token authenticated API for ESP32 communication
- **Role-Based Access** — Admin, operator, and viewer permission levels

---

## System Architecture

```
        ┌─────────────────────────────────────────────┐
        │              ESP32 (device)                 │
        │  container sensor · HX711                  │
        │  load cell · relay/pump · RGB LED           │
        └───────────────┬─────────────────────────────┘
                        │  Wi-Fi (Bearer token)
                        │  • heartbeat / telemetry
                        │  • poll-command  ◄── dispense request
                        │  • dispensing start / complete
                        ▼
        ┌──────────────────────────────┐      ┌─────────────────┐
        │  PHP REST API (XAMPP)        │◄────►│  MySQL          │
        │  api/  · Bearer auth         │ PDO  │  waterbonia     │
        │  rate limits · command queue │      │  9 tables       │
        └──────────────┬───────────────┘      └─────────────────┘
                       │ session auth (CSRF, RBAC)
                       ▼
        ┌──────────────────────────────┐
        │  Web Dashboard (11 pages)    │
        │  Choose → Place → Dispense   │
        │  → Complete, with live state │
        └──────────────────────────────┘
```

---

## Web Application

Open `http://localhost/WaterBonia/` to reach `pages/dispense.php` without logging in. The Execution Portal retains the four-step flow, container cards, RGB feedback, progress, and completion layout. Its header and final copyright footer contain no administrative navigation. The retired `/admin/` entry also returns to dispensing; existing protected maintenance code, authentication, and database history are preserved.

Cup fills default to **250 mL** and Tumbler fills to **500 mL**. Users can independently choose a whole-number dispensing threshold, initially **1–2000 mL**, in Container Settings or the Place step. For example, a 150 mL Cup setting dispenses 150 mL rather than retaining the 250 mL default. The server validates selections, saves them as session preferences, and uses the selected amount as the command/session target. Changes apply to the next fill; an active operation keeps its accepted target.

The compact public sidebar opens dedicated views for Dispense Water, Container Settings, Device Status, Activity Logs, Safety Sequence, and Demo Controls while Demo Mode is enabled. Only the selected view is shown. Container Settings provides separate Cup and Tumbler inputs; Device Status shows connection, container detection, pump, and RGB feedback. One sidebar brand with **Water Dispensing System** as its subtitle replaces the repeated branding; the top toolbar shows the selected view and compact system/demo indicators. On smaller screens a collapsible section menu keeps every link available. Navigation supports deep links and browser history while preserving the current dispensing flow.

Activity Logs have their own sidebar view, with date and time displayed as `YYYY-MM-DD HH:mm:ss`. Demo history stays in browser storage, and every demo entry is marked simulated. Saved timestamps remain available after refresh; hardware timestamps retain the server's recorded date and time. Reset Demo preserves this history; Clear Demo History affects only that browser's demo history. Hardware activity comes from an allowlisted, device-scoped view of existing backend records.

Import `database/execution_portal_migration.sql` after the existing migrations to add configurable fill defaults/ranges using `INSERT IGNORE`. The migration creates no new tables and preserves existing values. Defaults also work in Demo Mode without MySQL. Hardware dispensing requires fresh telemetry, container presence, an online device, an available pump, and valid local load-cell measurements. The command claim and session start recheck authorization under a device lock. Tank-level readings and tank calibration are no longer required. Existing telemetry does not report physical RGB state, so hardware mode labels it unavailable.

`pages/dispenser-status.php` provides CSRF-protected selection validation and live state. Hardware mode polls it every 2 seconds; Demo Mode does not poll hardware or send simulated data to device APIs.

The ESP32 defers blocking network requests while its pump runs so local measurement and cutoff checks continue. Hardware progress readings pause during a fill; the final measured result and queued telemetry are sent after pump OFF. Demo Mode retains continuous simulated progress. A reading captured before session start is not presented as current pump state during that session.

See [Execution Portal implementation and verification](docs/execution-portal-revision.md) for configuration, regression coverage, and remaining hardware dependencies.
See [Layout optimization report](docs/execution-portal-layout-optimization.md) for the compact layout audit, changed files, checks, and viewport-verification limits.

---

## ESP32 Firmware

The firmware runs on ESP32 DevKit boards and manages:

- **Sensor reading** — container presence (IR/breakbeam), HC-SR04 (diagnostic only)
- **Load cell measurement** — HX711 reading converted to millilitres for auto-stop at target
- **State machine** — INIT → WIFI_CONNECTING → READY → CONTAINER_DETECTED → WAITING_FOR_COMMAND → DISPENSING → COMPLETE
- **Command polling** — claims dispense requests queued by the web dashboard
- **Pump control** — Relay activation with multi-condition safety invariant
- **API communication** — Heartbeat, telemetry, command polling, dispensing sessions
- **Status indication** — RGB LED with color-coded states
- **NVS provisioning** — Wi-Fi and device token storage

### State Machine

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

### Pump Safety Invariant

The pump may be ON **only** when all of these hold:

1. Container detected
2. An accepted dispense request is active
3. Server session start succeeded for the claimed command and selected amount
4. Load-cell samples are valid
5. Dispense timeout (`MAX_DISPENSE_TIME_MS`, 30 s) not exceeded
6. No emergency stop active

Local checks always win: the website can only *request* dispensing.

See `esp32/README.md` for full firmware documentation.

---

## Sensors and Hardware

| Component | Model | GPIO | Purpose |
|-----------|-------|------|---------|
| Container Sensor | IR / breakbeam / limit switch | 27 | Container presence (not type classification) |
| Load Cell | HX711 + load cell amplifier | 19 (DOUT), 18 (SCK) | Measured dispensed volume — **pins not yet finalized** |
| Ultrasonic | HC-SR04 | 25 (TRIG), 26 (ECHO) | Diagnostics only — not a dispense trigger |
| Pump Relay | 1-channel 5V | 32 | DC pump switching |
| RGB LED | Common cathode/anode | 14 (R), 12 (G), 13 (B) | Status indication |

> **Notes:**
> - The container sensor reports *presence only*; cup vs tumbler is chosen on the web dashboard.
> - HC-SR04 echo pin outputs 5V. Use a voltage divider (1kΩ + 2kΩ) to safely interface with ESP32's 3.3V GPIO.
> - HX711 GPIO assignments are placeholders and the load cell has **not been calibrated** — dispensing cannot be claimed as physically measured until calibration with a known reference weight is performed.

---

## PHP REST API

The API provides secure endpoints for ESP32 device communication.

**Base URL:** `http://localhost/WaterBonia/api/`

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `/api/status` | GET | None | API status |
| `/api/device/heartbeat` | POST | Bearer | Device alive signal |
| `/api/device/sensor-reading` | POST | Bearer | Telemetry (incl. live measured volume) |
| `/api/device/poll-command` | POST | Bearer | Claim a queued dispense command |
| `/api/device/command-result` | POST | Bearer | Report command outcome |
| `/api/device/dispensing/start` | POST | Bearer | Start session (server sets target) |
| `/api/device/dispensing/complete` | POST | Bearer | Complete session with measured volume |

### Security Features

- Bearer token authentication (SHA-256 hashed, never stored plaintext)
- Prepared statements for all database queries
- Server-authoritative target volume (browser value is never trusted blindly)
- Command claiming is atomic (transaction + row lock) and TTL-bounded
- Rate limiting per endpoint
- JSON error responses without PHP leakage

See `api/README.md` for complete API documentation.

---

## Database

MySQL database with 9 tables using InnoDB/utf8mb4:

| Table | Purpose |
|-------|---------|
| `users` | Web application accounts (admin/operator/viewer) |
| `devices` | Registered ESP32 dispenser units |
| `sensor_readings` | Live telemetry from ESP32 sensors |
| `dispensing_sessions` | Dispensing records (container, target, measured) |
| `device_commands` | Web → ESP32 dispense command queue |
| `activity_logs` | System and device event logging |
| `alerts` | Conditions requiring attention |
| `system_settings` | Configurable key-value settings (incl. container targets) |
| `device_tokens` | API authentication tokens for ESP32 |

See `database/README.md` for schema details and import instructions.

---

## Security

- **Password Hashing** — bcrypt via `password_verify()`
- **Token Authentication** — SHA-256 hashed Bearer tokens for API
- **CSRF Protection** — Token-based form protection
- **Prepared Statements** — PDO with emulated prepares disabled
- **Output Escaping** — `htmlspecialchars()` on all user output
- **Role-Based Access** — Admin/operator/viewer permission levels
- **Rate Limiting** — Per-endpoint request throttling
- **No Plaintext Secrets** — Credentials never stored or logged in plaintext

---

## Local Development Setup

### Prerequisites

- [XAMPP](https://www.apachefriends.org/) (Apache + MySQL + PHP 8.x)
- Git
- Arduino IDE or PlatformIO (for ESP32)

### Web Application

1. **Clone the repository:**
   ```bash
   git clone https://github.com/YOUR_USERNAME/WaterBonia.git
   ```

2. **Place in XAMPPhtdocs:**
   ```
   C:\xampp\htdocs\WaterBonia\
   ```

3. **Import the database:**
   - Open phpMyAdmin (`http://localhost/phpmyadmin`)
   - Import `database/waterbonia.sql`
   - Import `database/api_migration.sql`
   - Import `database/dispensing_v2_migration.sql` (container type, target/measured volume, `device_commands` queue)
   - Import `database/execution_portal_migration.sql` (configurable container fill defaults/ranges)

4. **Access the application:**
   ```
   http://localhost/WaterBonia/
   ```

5. **Use the Execution Portal** directly. With Demo Mode enabled, choose a container and use the simulation controls to present the full workflow. No login is required.

### ESP32 Firmware

1. Copy `esp32/secrets.example.h` to `esp32/secrets.h`
2. Fill in your Wi-Fi credentials and device token
3. Update `API_BASE_URL` in `esp32/src/config.h` with your XAMPP LAN IP
4. Install the `HX711_ADC` library (required for measured dispensing)
5. Confirm the HX711 GPIO pins in `esp32/include/hardware_config.h` (**placeholder — not yet wired**)
6. Calibrate `LOAD_CELL_CALIBRATION_FACTOR` in `esp32/src/config.h` with a known reference weight
7. Flash firmware using Arduino IDE or PlatformIO

See `esp32/README.md` for detailed firmware setup instructions.

---

## Project Structure

```
WaterBonia/
├── README.md                   # This file
├── .gitignore                  # Git ignore rules
│
├── api/                        # PHP REST API for ESP32
│   ├── index.php               # API router
│   ├── .htaccess               # Routes /api/* to the router
│   ├── bootstrap.php           # API context bootstrap
│   ├── auth.php                # Device token authentication
│   ├── heartbeat.php           # Heartbeat endpoint
│   ├── sensor_reading.php      # Sensor reading + alert logic
│   ├── dispensing.php          # Dispensing session endpoints
│   ├── device_command.php      # Command queue: poll + result
│   ├── test.php                # API test suite (CLI only)
│   └── README.md               # API documentation
│
├── assets/
│   ├── css/style.css           # Main stylesheet + dispensing flow styles
│   ├── js/app.js               # Client-side JavaScript
│   └── js/dispenser.js         # 4-step dispensing flow + polling
│
├── auth/
│   └── login.php               # Web login page
│
├── config/
│   └── database.php            # PDO connection config
│
├── database/
│   ├── waterbonia.sql          # MySQL schema + sample data
│   ├── api_migration.sql       # device_tokens table + constraints
│   ├── dispensing_v2_migration.sql  # container/target/measured + command queue
│   └── README.md               # Database documentation
│
├── esp32/                      # ESP32 firmware
│   ├── src/                    # Firmware source code (incl. load_cell.*)
│   ├── include/                # Hardware config
│   ├── secrets.example.h       # Secrets template (safe to commit)
│   └── README.md               # Firmware documentation
│
├── includes/
│   ├── functions.php           # Shared PHP utilities + dispense helpers
│   ├── header.php              # Page header + layout
│   ├── sidebar.php             # Sidebar navigation
│   └── footer.php              # Layout end
│
├── pages/
│   ├── dashboard.php           # Dispense flow (Choose → Place → Dispense → Complete)
│   ├── dispenser-status.php    # JSON endpoint polled by the dashboard
│   ├── water-level.php         # Water level monitoring
│   ├── dispensing.php          # Dispensing history
│   ├── device-status.php       # Device details
│   ├── activity-logs.php       # Event logs
│   ├── alerts.php              # Alert management
│   ├── analytics.php           # Charts & reporting
│   ├── devices.php             # Device management + tokens
│   ├── settings.php            # System settings
│   └── help.php                # Help / About
│
├── docs/                       # Documentation
│   └── (IT 114 HCI docs)
│
├── index.php                   # Main router
└── logout.php                  # Session logout
```

---

## Documentation

| Document | Location | Contents |
|----------|----------|----------|
| API Reference | `api/README.md` | Endpoints, authentication, request/response formats |
| Database Schema | `database/README.md` | Table definitions, relationships, import instructions |
| ESP32 Firmware | `esp32/README.md` | Hardware setup, wiring, provisioning, state machine |
| IT 114 HCI | `docs/` | Human-computer interaction analysis and documentation |

---

## Development Status

| Component | Status |
|-----------|--------|
| Database Schema | Complete (+ v2 migration for container/target/measured and command queue) |
| Web Dashboard | Complete (10 pages + dispense flow endpoint) |
| Authentication & RBAC | Complete |
| PHP REST API | Complete (incl. command polling) |
| Dispensing v2 flow | Complete (software-verified end to end: queue → claim → session → measured complete) |
| ESP32 Firmware | Complete in software — **not yet tested on physical hardware** |
| HX711 Load Cell | Implemented in software — **pins and calibration not yet finalized** |
| Alert System | Complete |
| Activity Logging | Complete |
| Device Token Management | Complete |

> **Note:** The latest firmware revision has not been compiled or tested on physical hardware in this workspace. Target volumes recorded during bench simulation are not calibrated measurements. Use `TEST_MODE` in `config.h` for local testing without sensors; `ALLOW_DISPENSE_WITHOUT_LOAD_CELL` must remain `0` in production. The HX711 still needs physical calibration for accurate mL cutoff.

---

## Future Improvements

- Physical ESP32 hardware testing and calibration
- Real-time WebSocket updates for live sensor data
- Email/SMS notification integration
- Historical data export (CSV/PDF)
- Multi-device dashboard views
- Water consumption analytics and forecasting
- Mobile app companion
- Cloud deployment beyond local XAMPP

---

## Environment-Specific Configuration

This project is designed for local XAMPP development. The following values are environment-specific and should be changed for deployment:

| File | Setting | Default | Notes |
|------|---------|---------|-------|
| `config/database.php` | `DB_USER`, `DB_PASS` | `root`, `''` | XAMPP default; change for production |
| `esp32/src/config.h` | `API_BASE_URL` | `http://192.168.1.100/WaterBonia/api` | Must match your XAMPP LAN IP |
| `esp32/secrets.h` | Wi-Fi credentials | — | Created from `secrets.example.h`; never committed |

---

## Security Warning

**Never commit real credentials to version control.**

- `esp32/secrets.h` is gitignored — create it from `secrets.example.h`
- Database credentials in `config/database.php` are XAMPP defaults — change for production
- The default admin password is documented in `database/README.md`, not in source code
- API tokens are SHA-256 hashed in the database — raw tokens shown only once at generation

If you accidentally commit credentials:
1. Rotate the exposed credential immediately
2. Remove the file from Git history using `git filter-branch` or BFG Repo-Cleaner
3. Consider the credential compromised until rotated

---

## License

This project is developed for educational purposes as part of IT 114 coursework.

---

## Acknowledgments

- **IT 114 - Advanced Human and Computer Interaction** course faculty
- ESP32 and Arduino communities
- XAMPP development environment
- PHP and MySQL documentation
