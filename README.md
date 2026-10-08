# WaterBonia: A Web- and Sensor-Based Automatic Water Dispensing System

WaterBonia is an integrated Internet of Things (IoT) system that combines embedded hardware, a PHP REST API, and a web-based monitoring dashboard to control water dispensing safely and transparently.

The dispensing interaction follows a fixed four-step sequence:

1. **Choose** — the user picks a container (cup or tumbler) on the web dashboard
2. **Place** — the container-presence sensor confirms the container is on the tray
3. **Dispense** — the user presses DISPENSE; the request is queued and the ESP32 runs the pump
4. **Complete** — a load cell measures the dispensed water and the pump auto-stops at the target volume

Hand detection (HC-SR04) is retained for diagnostics only — it is **not** a dispense trigger.

This project is developed as part of **IT 114 - Advanced Human and Computer Interaction**, focusing on user-centered design principles for IoT monitoring interfaces.

---

## IT 114 Context

WaterBonia addresses key concepts in Advanced Human and Computer Interaction:

- **Visible Interaction Sequence** — the dashboard makes the four-step flow (Choose → Place → Dispense → Complete) explicit with a persistent step rail
- **Dashboard Usability** — Real-time sensor data presented through an intuitive monitoring interface
- **Information Architecture** — Structured navigation across dashboard, device management, alerts, analytics, and system settings
- **Feedback Systems** — Immediate visual feedback via RGB LED states, live dashboard polling, progress from measured volume, and alert notifications
- **Error Prevention** — the DISPENSE button stays disabled until every precondition holds, and a multi-condition pump safety invariant prevents unsafe dispensing
- **Status Visibility** — Device online/offline status, water level monitoring, container presence, and active alert counts always visible
- **Single Source of Truth** — target volumes are read from system settings, never hard-coded in the UI
- **Responsive Interface** — Mobile-friendly sidebar navigation and adaptive layouts
- **User-Centered Monitoring** — Role-based access (admin/operator/viewer) with appropriate information density per role

See the `docs/` folder for full IT 114 HCI documentation.

---

## Key Features

- **Container Selection** — cup (250 mL) or tumbler (500 mL), configured in system settings
- **Container Presence Sensing** — confirms the container is placed before dispensing can be requested
- **Measured Auto-Stop** — HX711 load cell measures dispensed water and stops the pump at the target
- **Command Queue** — the web dashboard queues a request; the ESP32 polls, validates, and executes it
- **Multi-Condition Safety** — pump runs only with container present, accepted request, sufficient water, valid load-cell samples, and no timeout/fault
- **Real-Time Web Dashboard** — live container, water level, pump status, and dispense progress
- **Alert System** — Automatic low-water and device-offline alerts with acknowledgement workflow
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
        │  container sensor · water level · HX711     │
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

The web dashboard provides a complete monitoring and management interface:

| Page | Purpose |
|------|---------|
| Dashboard | **Dispense flow** — Choose container → Place → Dispense → Complete, plus water level, pump, container and alert overview |
| Water Level | Detailed water level monitoring with historical data |
| Dispensing | Active session (target vs measured) and dispensing history |
| Device Status | Connected device details and sensor readings |
| Activity Logs | Searchable event log with type/level/date filtering |
| Alerts | Alert management with acknowledge/resolve workflow |
| Analytics | Cup/tumbler counts, measured volume, usage charts |
| Devices | ESP32 device registration and token management |
| Settings | System configuration (thresholds, container targets, timezone, display name) |
| Help / About | Application information |

`pages/dispenser-status.php` is a JSON endpoint polled by the dashboard every 2 seconds for live state.

### Default Login Credentials

| Field | Value |
|-------|-------|
| Username | `admin_waterbonia` |
| Password | See `database/README.md` |
| Role | admin |

> The password is stored as a bcrypt hash. See database documentation for setup details.

---

## ESP32 Firmware

The firmware runs on ESP32 DevKit boards and manages:

- **Sensor reading** — container presence (IR/breakbeam), analog water level, HC-SR04 (diagnostic only)
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
        target reached      container removed / low water /
                    ↓       timeout / load-cell fault
                 COMPLETE ──────────────────────► READY
Any state → LOW_WATER → READY (when water recovers)
Any state → ERROR     → READY (when sensors recovers)
```

### Pump Safety Invariant

The pump may be ON **only** when all of these hold:

1. Container detected
2. An accepted dispense request is active
3. Water level > `LOCAL_PUMP_CUTOFF_PERCENT` (15%)
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
| Water Level | Analog sensor | 34 (ADC1) | Tank level monitoring |
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

4. **Access the application:**
   ```
   http://localhost/WaterBonia/
   ```

5. **Login** with the default admin account (see `database/README.md`).

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

> **Note:** The ESP32 firmware compiles as software only and has **not** been tested on physical hardware. Target volumes recorded during bench simulation are not calibrated measurements. Use `TEST_MODE` in `config.h` for local testing without sensors; `ALLOW_DISPENSE_WITHOUT_LOAD_CELL` must remain `0` in production.

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
