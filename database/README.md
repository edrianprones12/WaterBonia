# Timer integration update

For an existing database, verify the installed schema before applying `timer_integration_migration.sql` **once**, after the original API, v2 dispensing, and execution-portal migrations. Export the live database first. Do not import `waterbonia.sql` over existing records. New presets are disabled at zero, and `portal_device_id` is unassigned at zero. See [the setup and rollback guide](../docs/timer-hardware-integration.md). The live schema was verified outside the restricted test environment, backed up, and migrated after the disposable migration/API checks passed. Do not reapply it on this installation.

# WaterBonia Database

MySQL database for the WaterBonia IoT smart water dispenser system.

**Database name:** `waterbonia`
**Engine:** InnoDB | **Charset:** utf8mb4

---

## Tables

| Table | Purpose | Key source |
|---|---|---|
| `users` | Web application user accounts (admin, operator, viewer) | Web app |
| `devices` | Registered ESP32 dispenser units | Web app / setup |
| `sensor_readings` | Live telemetry from ESP32 sensors | ESP32 |
| `dispensing_sessions` | One record per water dispensing operation | ESP32 |
| `activity_logs` | Important system and device events | ESP32 + Web app |
| `alerts` | Conditions requiring human attention | System / ESP32 |
| `system_settings` | Configurable key-value settings | Web app |
| `device_tokens` | API authentication tokens for ESP32 devices | API / Web app |

---

## Relationships

```
users
  ├─ activity_logs      (user_id, SET NULL on delete)
  └─ alerts             (acknowledged_by, SET NULL on delete)
  └─ system_settings    (updated_by, SET NULL on delete)

devices
  ├─ sensor_readings       (device_id, RESTRICT on delete)
  ├─ dispensing_sessions   (device_id, RESTRICT on delete)
  ├─ activity_logs         (device_id, SET NULL on delete)
  ├─ alerts                (device_id, SET NULL on delete)
  └─ device_tokens         (device_id, CASCADE on delete)
```

Historical records (sensor readings, dispensing sessions, activity logs, alerts) are **never cascade-deleted**. Removing a device sets referencing foreign keys to NULL in log/alert tables but does not erase history.

---

## Data Origins

### From ESP32 (via API)
- `sensor_readings` — water level, cup/hand detection, pump status, battery
- `dispensing_sessions` — session start/end, stop reason, water levels
- `activity_logs` — device events (pump started, cup detected, errors, etc.)
- `alerts` — low water, device offline, sensor/pump failures

### From Web Application
- `users` — account management
- `devices` — device registration and configuration
- `system_settings` — threshold values, system name, timezone
- `activity_logs` — user-initiated config changes
- `alerts` — manual acknowledgement/resolution

---

## Historical Tables

The following tables store historical data that must be preserved for reporting and auditing:

- `sensor_readings` — raw sensor telemetry over time
- `dispensing_sessions` — every dispensing operation
- `activity_logs` — all significant events
- `alerts` — all triggered alerts (active, acknowledged, resolved)

These tables grow over time. Do not truncate or cascade-delete from them.

---

## Importing via phpMyAdmin

1. Open phpMyAdmin (`http://localhost/phpmyadmin`).
2. Click the **Import** tab.
3. Click **Choose File** and select `waterbonia.sql`.
4. Ensure **Format** is set to **SQL**.
5. Click **Go**.
6. For API support, also import `api_migration.sql` (creates the `device_tokens` table and adds unique constraint on `esp32_identifier`).

The main file creates the `waterbonia` database, all tables, indexes, constraints, and sample data.

---

## Future PHP API Interaction

The PHP backend should:

- Use **PDO** with `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`.
- Use **prepared statements** for every query.
- Reference `devices.id` and `users.id` as integers; never trust client-supplied IDs.
- Write to `sensor_readings` and `dispensing_sessions` using the ESP32 API endpoint.
- Read from `system_settings` to configure threshold logic.
- Log important actions to `activity_logs`.
- Create rows in `alerts` when thresholds are breached.
- Store **never** plaintext credentials in `users.password_hash` or any metadata column.
- The ESP32 should send its `device_code` or `esp32_identifier` in API requests; the backend maps this to `devices.id`.

---

## Security Notes

| Field | Rule |
|---|---|
| `users.password_hash` | Must contain a bcrypt/argon2 hash. Never plaintext. |
| `device_tokens.token_hash` | SHA-256 hash of the raw token. Raw token shown only once at generation. |
| `devices.wifi_ssid` | SSID only. Never store Wi-Fi passwords in the database. |
| `activity_logs.metadata` | JSON column. Never embed credentials or API keys. |
| `system_settings.setting_value` | Never store secrets here. |

---

## Assumptions & Future Work

- ESP32 firmware will POST sensor data to a PHP API endpoint.
- The PHP API authenticates devices via Bearer tokens (SHA-256 hashed, stored in `device_tokens`).
- Volume measurement (`amount_dispensed_ml`) is nullable — the hardware may not support it initially.
- Battery monitoring is optional; `battery_percent` may remain NULL.
- A cron job or background process will check `devices.last_seen_at` to auto-mark devices offline.
- Device tokens are generated by admins via the web interface and shown only once.
