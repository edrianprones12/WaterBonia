# WaterBonia — real hardware controller

WaterBonia is the existing PHP/PDO/MySQL application with an authenticated ESP32 timer controller. The Execution Portal remains at `/WaterBonia/pages/dispense.php`; administrative monitoring remains at `/WaterBonia/pages/dashboard.php`.

The application now supports **real hardware only**. Browser simulation, its controls and switch, and simulated activity storage have been removed. Historical database records are preserved.

## Current hardware model

ESP32 + IR container sensor + relay + 5V pump. There is no flow, volume, reservoir-level, load-cell, or battery measurement in the new protocol. Cup 250 mL and Tumbler 500 mL are nominal labels. Settings store experimentally calibrated runtime in seconds; default zero disables the preset. The firmware hard maximum is 30 seconds.

The browser sends a CSRF-protected request to PHP. PHP checks the CSRF-protected browser session, assigned device, configured time preset, fresh heartbeat and IR report, and absence of conflicting operations. The entire application is account-free: dispensing, dashboard, settings, devices and alerts need no human account. CSRF and device-token authentication remain active. The ESP32 polls with a device token, independently validates the command and local IR signal, and controls its relay with a local safety task. Only explicit firmware results finalize an operation. A browser countdown never controls or proves completion.

## Setup and operating guide

Read [the implementation and commissioning report](docs/timer-hardware-integration.md) before applying the migration or uploading firmware. **The local database was backed up and migrated, and the user uploaded the new firmware to the ESP32 through COM7.** Wi-Fi authentication, live IR status on the website, active-LOW relay behavior and idle pump-OFF were confirmed during commissioning. Device result records show completed timed operations and a local container-removal stop. No exact water volume has been verified.

1. XAMPP Apache/MySQL and the live baseline schema were verified; the live database backup and timer migration are complete.
2. Do not reapply the timer migration on this installation. For another existing installation, verify prerequisites and apply it once after a backup; never re-import the base schema over user data.
3. Register the actual device and generate a device token through Devices. Set `portal_device_id` to that device's ID in Settings.
4. Confirm wiring/polarity and safe power regulation. Configure `esp32/WaterBoniaTimer/config.local.h` using its example. Pins and LAN address have no assumed defaults.
5. Compile the new Arduino sketch for ESP32 Dev Module. Upload only after hardware verification and supervised commissioning. COM7 is the user's reported programming port, not the network destination.
6. Open the Execution Portal directly; no sign-in is needed to dispense. System configuration also works without sign-in. The local runtime settings are Cup **10 seconds** and Tumbler **20 seconds**, as requested. These database values are not exported to Git; fresh installations default both durations to zero until configured. Neither preset proves a measured water volume.

The earlier `esp32/src` tree is preserved as legacy source; it expects different hardware and is **not the firmware for this timer integration**. Use `esp32/WaterBoniaTimer/WaterBoniaTimer.ino`.

## Validation

```text
php tests/portal_safety_test.php
php tests/portal_activity_test.php
php tests/timer_sensor_state_test.php
node tests/timer_controller_test.cjs
node tests/timer_ui_test.cjs
node tests/portal_navigation_test.cjs
python tests/run_timer_db_test.py
python tests/run_timer_http_test.py
```

The two Python runners use disposable loopback services and never connect to the live database or ESP32. Database tests use port 33287 and full-app page tests use 33390; outage HTTP tests use 33388 and an unavailable database port 33389. They stop their services afterward and retain ignored test artifacts under `tmp/`. Installed XAMPP binaries are expected at `C:\xampp`.

Database port defaults to 3306; an optional server-side `WATERBONIA_DB_PORT` environment variable supports isolated testing or an alternate local port. No device credential belongs in frontend JavaScript. Apache access rules deny direct downloads from config, SQL, and firmware directories; verify these rules when deploying with a different web server.

## Account-free operation

Human sign-in, logout, role checks and account/profile UI have been removed throughout the system. Old login/logout URLs redirect to the portal and preserve the browser session used for CSRF and request idempotency. New operations, settings changes and alert acknowledgements use NULL actor IDs, regardless of stale account cookies. Existing historical account records and related history are retained, but no login account is seeded on fresh setup. ESP32 bearer tokens are device credentials, not human accounts, and remain required. The Dashboard link in the portal opens system management directly.

Account-free management was verified with 59 full-app HTTP/session/CSRF/fixture-management checks in a disposable database. No live pump requests were sent by these tests.
