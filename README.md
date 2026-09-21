# WaterBonia

**IoT-Based Smart Water Dispenser with Automatic Cup Detection and Web Monitoring**

WaterBonia is an integrated Internet of Things (IoT) system that combines embedded hardware, a PHP REST API, and a web-based monitoring dashboard to provide real-time water dispensing control and analytics. The system uses ultrasonic and infrared sensors to detect cup placement and hand proximity, automatically activating a water pump only when safe conditions are met.

This project is developed as part of **IT 114 - Advanced Human and Computer Interaction**, focusing on user-centered design principles for IoT monitoring interfaces.

---

## IT 114 Context

WaterBonia addresses key concepts in Advanced Human and Computer Interaction:

- **Dashboard Usability** — Real-time sensor data presented through an intuitive monitoring interface
- **Information Architecture** — Structured navigation across dashboard, device management, alerts, analytics, and system settings
- **Feedback Systems** — Immediate visual feedback via RGB LED states, web dashboard updates, and alert notifications
- **Error Prevention** — Multi-condition pump safety invariant prevents unsafe water dispensing
- **Status Visibility** — Device online/offline status, water level monitoring, and active alert counts always visible
- **Responsive Interface** — Mobile-friendly sidebar navigation and adaptive layouts
- **User-Centered Monitoring** — Role-based access (admin/operator/viewer) with appropriate information density per role

See the `docs/` folder for full IT 114 HCI documentation.

---

## Key Features

- **Automatic Cup Detection** — IR sensor identifies cup placement before enabling pump
- **Hand Proximity Detection** — HC-SR04 ultrasonic sensor verifies hand presence
- **Multi-Condition Safety** — Pump activates only when cup + hand + sufficient water + no faults + no timeout
- **Real-Time Web Dashboard** — Live sensor readings, water level gauge, dispensing history
- **Alert System** — Automatic low-water and device-offline alerts with acknowledgement workflow
- **Device Management** — Register ESP32 devices, generate/revoke API tokens
- **Activity Logging** — Comprehensive event logging for all system actions
- **Analytics** — Charts and reporting for water usage patterns
- **RESTful API** — Secure Bearer-token authenticated API for ESP32 communication
- **Role-Based Access** — Admin, operator, and viewer permission levels

---

## System Architecture

```
┌──────────────┐     Wi-Fi      ┌──────────────────┐     HTTP/JSON     ┌─────────────────┐
│   ESP32      │ ◄────────────► │  PHP REST API    │ ◄──────────────► │  MySQL Database  │
│  (Sensors)   │                │  (XAMPP Server)  │                   │  (waterbonia)    │
│  HC-SR04     │                │  api/             │                   │  8 tables        │
│  IR Sensor   │                │  Bearer Auth      │                   │  InnoDB/utf8mb4  │
│  Water Level │                │  Rate Limiting    │                   │                  │
│  Relay/Pump  │                └──────────────────┘                   └─────────────────┘
│  RGB LED     │                         │
└──────────────┘                         │ PHP PDO
                                         ▼
                                ┌──────────────────┐
                                │  Web Dashboard   │
                                │  (HTML/CSS/JS)   │
                                │  10 pages        │
                                │  Login/Auth      │
                                │  Sidebar Nav     │
                                └──────────────────┘
```

---

## Web Application

The web dashboard provides a complete monitoring and management interface:

| Page | Purpose |
|------|---------|
| Dashboard | System overview — water level, pump status, cup/hand detection, alerts |
| Water Level | Detailed water level monitoring with historical data |
| Dispensing | Dispensing session history with filtering |
| Device Status | Connected device details and sensor readings |
| Activity Logs | Searchable event log with type/level/date filtering |
| Alerts | Alert management with acknowledge/resolve workflow |
| Analytics | Usage charts and reporting |
| Devices | ESP32 device registration and token management |
| Settings | System configuration (thresholds, timezone, display name) |
| Help / About | Application information |

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

- **Sensor reading** — HC-SR04 (hand), IR (cup), analog (water level)
- **State machine** — INIT → WIFI_CONNECTING → READY → CUP_DETECTED → WAITING_FOR_HAND → DISPENSING
- **Pump control** — Relay activation with multi-condition safety invariant
- **API communication** — Heartbeat, telemetry, dispensing sessions
- **Status indication** — RGB LED with color-coded states
- **NVS provisioning** — Wi-Fi and device token storage

### State Machine

```
INIT → WIFI_CONNECTING → READY → CUP_DETECTED → WAITING_FOR_HAND → DISPENSING
              ↓                                       ↓                ↓
            READY ←──────────────────────────────────┘                 ↓
              ↓                                                        ↓
         LOW_WATER → READY (when water recovers)     ←────────────────┘
              ↓
         ERROR → READY (when sensor recovers)
```

See `esp32/README.md` for full firmware documentation.

---

## Sensors and Hardware

| Component | Model | GPIO | Purpose |
|-----------|-------|------|---------|
| Ultrasonic | HC-SR04 | 25 (TRIG), 26 (ECHO) | Hand detection (2–10 cm) |
| IR Proximity | Generic obstacle sensor | 27 | Cup detection |
| Water Level | Analog sensor | 34 (ADC1) | Tank level monitoring |
| Pump Relay | 1-channel 5V | 32 | DC pump switching |
| RGB LED | Common cathode/anode | 14 (R), 12 (G), 13 (B) | Status indication |

> **Note:** HC-SR04 echo pin outputs 5V. Use a voltage divider (1kΩ + 2kΩ) to safely interface with ESP32's 3.3V GPIO.

---

## PHP REST API

The API provides secure endpoints for ESP32 device communication.

**Base URL:** `http://localhost/WaterBonia/api/`

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `/api/status` | GET | None | API status |
| `/api/device/heartbeat` | POST | Bearer | Device alive signal |
| `/api/device/sensor-reading` | POST | Bearer | Telemetry data |
| `/api/device/dispensing/start` | POST | Bearer | Start session |
| `/api/device/dispensing/complete` | POST | Bearer | Complete session |

### Security Features

- Bearer token authentication (SHA-256 hashed, never stored plaintext)
- Prepared statements for all database queries
- Rate limiting per endpoint
- CORS headers
- JSON error responses without PHP leakage

See `api/README.md` for complete API documentation.

---

## Database

MySQL database with 8 tables using InnoDB/utf8mb4:

| Table | Purpose |
|-------|---------|
| `users` | Web application accounts (admin/operator/viewer) |
| `devices` | Registered ESP32 dispenser units |
| `sensor_readings` | Live telemetry from ESP32 sensors |
| `dispensing_sessions` | Water dispensing operation records |
| `activity_logs` | System and device event logging |
| `alerts` | Conditions requiring attention |
| `system_settings` | Configurable key-value settings |
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

4. **Access the application:**
   ```
   http://localhost/WaterBonia/
   ```

5. **Login** with the default admin account (see `database/README.md`).

### ESP32 Firmware

1. Copy `esp32/secrets.example.h` to `esp32/secrets.h`
2. Fill in your Wi-Fi credentials and device token
3. Update `API_BASE_URL` in `esp32/src/config.h` with your XAMPP LAN IP
4. Flash firmware using Arduino IDE or PlatformIO

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
│   ├── bootstrap.php           # API context bootstrap
│   ├── auth.php                # Device token authentication
│   ├── heartbeat.php           # Heartbeat endpoint
│   ├── sensor_reading.php      # Sensor reading + alert logic
│   ├── dispensing.php          # Dispensing session endpoints
│   ├── test.php                # API test suite
│   └── README.md               # API documentation
│
├── assets/
│   ├── css/style.css           # Main stylesheet
│   └── js/app.js               # Client-side JavaScript
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
│   └── README.md               # Database documentation
│
├── esp32/                      # ESP32 firmware
│   ├── src/                    # Firmware source code
│   ├── include/                # Hardware config
│   ├── secrets.example.h       # Secrets template (safe to commit)
│   └── README.md               # Firmware documentation
│
├── includes/
│   ├── functions.php           # Shared PHP utilities
│   ├── header.php              # Page header + layout
│   ├── sidebar.php             # Sidebar navigation
│   └── footer.php              # Layout end
│
├── pages/
│   ├── dashboard.php           # Main dashboard
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
| Database Schema | Complete |
| Web Dashboard | Complete (10 pages) |
| Authentication & RBAC | Complete |
| PHP REST API | Complete |
| ESP32 Firmware | Complete (software-verified, not yet tested on physical hardware) |
| Alert System | Complete |
| Activity Logging | Complete |
| Device Token Management | Complete |

> **Note:** The ESP32 firmware has been software-verified but has not yet been tested on physical hardware. The `TEST_MODE` flag in `config.h` allows local testing without sensors.

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
