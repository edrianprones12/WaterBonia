# WaterBonia real hardware integration

## Current account-free system policy

The user explicitly removed human accounts from the entire system. Dispensing, dashboard, settings, devices, monitoring, and alerts now work through a CSRF-protected session without sign-in. All new requester/actor IDs remain NULL, including when old account state exists in the session. Request keys are scoped to a persistent browser-session namespace, and device-row locks preserve retry/overlap protection. No human role or login is required anywhere. ESP32 device authentication remains mandatory. Legacy login/logout URLs redirect without clearing CSRF or idempotency session state. No firmware upload or live dispensing command was needed for this policy change. Account-free behavior passed 57 isolated database/API checks, 59 full-app page/session/management checks, 20 outage/security HTTP checks, 24 readiness cases, 24 controller workflow cases and 29 sensor-validity/safety-block projection cases.

During commissioning, the user confirmed Wi-Fi/backend authentication, IR transitions on the website, active-LOW GPIO18 sensing, active-LOW GPIO23 relay behavior, a 5V pump supply, and relay/pump OFF while idle. The user uploaded the integration firmware to COM7. Device-reported result records include completed 5-second and 10-second operations and an interrupted 10-second operation stopped when the container was removed. Current local presets are Cup **10 seconds** and Tumbler **20 seconds**, at the user's request; those latest durations are not claims of measured volume or a new physical test. Local database values, Wi-Fi credentials, device token and firmware build artifacts are excluded from Git. New installations retain disabled zero-duration defaults.

## Audit baseline (historical)

The existing application now uses only the real timer controller. The user subsequently requested removal of all Demo Mode functionality; this supersedes the earlier instruction to preserve it. No simulation switch, controller, controls, generated activity history, or browser pump simulation remains in the running application.

The live `waterbonia` schema was verified, backed up, and migrated. Its earlier connection failure was caused by the restricted execution environment, not by an unavailable MySQL service. Apache serves the updated portal and status route with HTTP 200. At the initial audit handoff, the device was unassigned (`portal_device_id=0`) and both presets were zero. This was superseded by the user-directed commissioning described above.

The original preinstalled sketch was not available at the audit stage. Subsequent user tests established the GPIO18/GPIO23 active-LOW configuration, backend connectivity and idle-OFF behavior. Exact volume calibration and comprehensive failure testing remain distinct from those successful connection tests.

## Verified old versus new behavior

| Area | Old implementation verified in source | Current implementation |
|---|---|---|
| Transport | Existing PHP API, hashed bearer tokens, MySQL command queue, ESP32 polling | Same architecture and routes; explicit protocol 2 |
| Firmware | Required HX711 measurements and stopped at volume target | New Arduino sketch controls duration locally; no load cell |
| Browser | Separate simulation and real volume-based controller | Real hardware controller only |
| Presets | User-adjustable 1–2000 mL cutoff | Server-owned calibrated seconds; nominal Cup/Tumbler labels |
| Permission | Public kiosk could request a physical command with CSRF | Public CSRF-protected dispensing; all human-facing pages are account-free, with CSRF on writes |
| Telemetry | Missing booleans defaulted to false; no explicit IR health | Required health, nullable presence, boot and sequence identity |
| Queue | Claim locking existed; command completed when session created | Pending, claimed, running, final result distinguished |
| Completion | Default success reason; non-idempotent finalization | Explicit timer evidence, pump-OFF confirmation, row locks, retry-safe finalization |
| Recovery | Final report could be lost; no durable execution journal | ESP32 stores intent/result; ambiguous start aborts; reboot never resumes |
| UI | Measured-volume progress and simulated activity | Reported timer progress, explicit unknown states, server history only |

The old real controller did not use a browser timer to operate physical hardware. Its browser timers belonged to simulation. Existing device authentication and queue protections were reused rather than invented or replaced.

## Architecture and state

Execution Portal → session/CSRF-protected PHP action → MySQL command → authenticated outbound ESP32 poll → local IR/safety validation → relay timer → authenticated result → MySQL/history → portal polling.

The browser cannot choose authoritative runtime, provide device credentials, directly activate a relay, or mark a request completed. Duplicate request IDs return the original command. A device-row lock serializes different requests. Expired unstarted commands are not executable. Already-authorized starts return `execute:false` and must not run again.

`device_commands` uses pending/claimed/running/completed/failed/stopped plus rejected/cancelled/expired. Existing session status `started` represents server authorization; it is not proof that the relay energized. Actual live pump state comes from telemetry. Existing `interrupted` session status is displayed as Stopped. A missing result remains unresolved and blocks another cycle; it is never converted to success because time elapsed.

The device heartbeat is every 10 seconds and becomes offline after 30. Telemetry is every 2 seconds and stale after at most 10; the browser also invalidates its own status response after 6 seconds. Stale presence is Unknown. Administrative maintenance is preserved by heartbeat. A fixed setting binds the portal to one device instead of choosing whichever device most recently reported.

## Database migration and backups

Applied `database/timer_integration_migration.sql` once to the verified existing database after confirming zero active sessions and zero unresolved commands. The three original migrations were already represented in the live schema. The migration:

- Adds device boot identity, report sequence and a separate heartbeat timestamp.
- Adds IR health and report identity, active command and local elapsed time to telemetry; presence can be null.
- Adds command duration, request idempotency and claim boot identity, plus execution/failure/stopped states.
- Adds configured/local elapsed times and optional physical wall-clock/report-receipt fields to sessions; expands stop reasons.
- Creates unique request/sample indexes and zero-valued device/duration settings.
- Preserves existing users, tokens, telemetry, logs and history. No new tables or fake operations are created.

Backups outside the web root:

```text
C:\Users\Edrian\.codex\visualizations\2026\10\29\01a18739-ba4e-7ec2-9e78-0ef97dd18e28\WaterBonia-before-timer.zip
C:\Users\Edrian\.codex\visualizations\2026\10\29\01a18739-ba4e-7ec2-9e78-0ef97dd18e28\waterbonia-before-timer.sql
```

The SQL backup was verified before applying DDL. Treat it as private because it contains user data and authentication hashes. It was not printed or served publicly.

`started_at` is backend authorization time; `ended_at`/`result_received_at` are backend final-report receipt times. Physical wall-clock timestamps remain null because the device has no verified synchronized clock. `pump_elapsed_ms` is device-reported monotonic time. New actual-volume and reservoir fields stay null; nominal targets are never copied into measured fields.

## Firmware setup and physical commissioning

1. Obtain the original sketch or inspect the wiring with power disconnected. Confirm actual GPIOs, sensor output voltage/polarity, relay activation level, and safe OFF behavior during boot/reset. Do not infer these from COM7 or use the old repository defaults as verified wiring.
2. Confirm the 5V pump receives an appropriate regulated supply. A relay is a switch, not a converter. Do not apply 9V directly to the listed 5V pump.
3. In Arduino IDE open `esp32/WaterBoniaTimer/WaterBoniaTimer.ino`. The installed ESP32 core's cJSON is used; no added JSON/load-cell/LED library is needed.
4. Copy `config.example.h` to `config.local.h` in that sketch folder. Supply verified pins/levels, Wi-Fi settings, device token and actual base URL. Set hardware verification true only after the physical checks. Unset pins are -1 and hardware verification is false; this default never configures a GPIO.
5. Select ESP32 Dev Module and the confirmed programming port. This implementation compiled for `esp32:esp32:esp32` with ESP32 Arduino core 3.3.12. It has not been uploaded.
6. Generate a token for the verified actual device through the existing Devices page; put the raw token only in local device configuration. Do not use database credentials in firmware or embed the token in web JavaScript.
7. First verify Wi-Fi, successful authenticated heartbeat and actual IR transitions with the pump electrically disabled. Check fresh Online/Detected/Not Detected and unplugged-device Unknown/Offline states in the portal.
8. Assign that verified device ID through Settings. Leave both duration settings zero until supervised calibration is planned. No sign-in is required for dispensing; settings changes still require CSRF.
9. Calibrate Cup and Tumbler durations experimentally using an external reference container and controlled, supervised short trials. No final duration is invented by this change. Accepted configured duration is a whole 1–30 seconds; zero disables. The firmware independently caps runtime at 30 seconds.
10. Supervise removal, normal timer stop, Wi-Fi loss, browser close, reboot and maximum-runtime tests. Confirm relay OFF physically; software tests cannot establish electrical behavior. Never leave a pump running unattended or wet exposed electronics.

The safety task samples every 2 ms, requires stable arrival for 100 ms, and stops on removal without waiting for a network call. These are scheduling settings, not certified worst-case electrical cutoff guarantees. A basic digital IR module cannot reliably distinguish every disconnected or stuck signal; healthy means configured input acquisition, not a complete physical sensor self-test. External fail-safe wiring remains necessary.

NVS is journaled before requesting execution. After an uncertain start, no second pump cycle is attempted. Completed/stopped results retry until acknowledged. Reboot aborts and reports `device_reset`, never resumes. A corrupted journal or persistent storage/reporting failure locks execution and needs inspection; do not erase it to bypass an unresolved operation. A reboot can lose precise elapsed time; failure is reported without inventing a successful result.

## LAN configuration and connection tests

Run `ipconfig` on the XAMPP computer. Find the IPv4 address for its active Wi-Fi/Ethernet adapter, excluding loopback and unrelated virtual adapters. Configure:

```text
http://<actual-computer-LAN-IPv4>/WaterBonia/api
```

Never use localhost or 127.0.0.1 on the ESP32 to reach the computer. No LAN address was assumed or written into device configuration. For isolated HTTP development, explicitly opt in with `WB_ALLOW_ISOLATED_HTTP`; otherwise use HTTPS and a valid `WB_TLS_ROOT_CA`. TLS verification is not bypassed. Wi-Fi SSID/password and token must be supplied locally.

Apache's inspected configuration uses Listen 80, document root `C:/xampp/htdocs`, enabled rewriting and document-root overrides. The clean `/api/status` route works on the computer. Test that same status URL from another device using the actual LAN address; cross-device reachability has not been tested. Check Windows inbound Apache policy, subnet routing and guest/client Wi-Fi isolation if blocked. No firewall rule, network binding, public exposure or port forwarding was changed. MySQL remains local to PHP; the ESP32 never connects to MySQL.

Verified locally after migration: portal HTTP 200, status HTTP 200, real controller loaded, no simulation controls, guest permission false, online false and container null for the unassigned device. Direct HTTP access to config, database SQL and firmware directories returns 403. No physical command was sent during these checks.

## API contracts

See [the exact protocol contracts](../api/README.md). Device routes are unchanged and now require protocol 2:

| Endpoint | Purpose |
|---|---|
| POST `/api/device/heartbeat` | Authenticated boot/heartbeat and server identity |
| POST `/api/device/sensor-reading` | Strict actual IR/pump status and sequence |
| POST `/api/device/poll-command` | Claim/retry an authorized unstarted command |
| POST `/api/device/dispensing/start` | One-time backend authorization; no proof of physical start |
| POST `/api/device/dispensing/complete` | Explicit device-confirmed final result; idempotent retries |
| POST `/api/device/command-result` | Reject/cancel an unstarted command, never declare completion |
| GET/POST `/pages/dispenser-status.php` | Public sanitized status and session/CSRF/role-protected requests |

Browser request body: `action=request_dispense`, `container_type`, `request_id`, `csrf_token`. The response is HTTP 202 with command ID/message. Device results include original boot and command ID, pump OFF, local elapsed ms and explicit reason. Only `duration_elapsed` with plausible configured timing can succeed. Timer completion proves a reported timed operation, not measured water delivery.

## Test results and limits

Executed automated checks:

- All PHP application files and new JavaScript pass syntax checks.
- 83 legacy volume-validation/shared readiness cases and 14 activity-projection cases pass.
- 24 real timer readiness cases pass.
- 24 real controller DOM/workflow cases pass, including no success from elapsed display alone and loss-of-connection lockout.
- 18 navigation cases pass after removal of the simulation section.
- All five schema/migration files apply to a disposable MariaDB. 57 timer API checks cover heartbeat, missing/wrong/duplicate telemetry, account-free attribution and session-scoped retry handling, disabled calibration, device binding, idempotency, overlap, claim retry, distinct running state, no second start, early/invalid completion, repeat/conflicting results, stop/failure/reset, expiry and stale heartbeat.
- 20 isolated outage HTTP checks and 59 full-app account-free page/session/CSRF/management checks pass.
- 29 state-projection checks confirm that a fresh healthy sensor remains visible while an unresolved interruption still blocks dispensing.
- Arduino sketch compiled successfully for ESP32 Dev Module. Compilation does not prove physical operation.
- Live Apache/DB smoke checks described above pass. User-reported sensor/relay checks and device-confirmed operation results are described above; Wi-Fi loss, power-failure/reboot shutdown and precise delivered volume have not been comprehensively validated on hardware.

The disposable runners bind only loopback, stop their own services in cleanup, and never address COM7 or the live database. Their data/build outputs are ignored under `tmp/`. They may need execution outside Windows' restricted environment to launch the installed native tools.

## Files and rollback

Application changes: `.htaccess`, `.gitignore`, `config/database.php`, `config/portal.php`, new `config/timer.php`; `includes/functions.php`, `includes/portal-header.php`; `api/auth.php`, `api/bootstrap.php`, `api/index.php`, `api/heartbeat.php`, `api/sensor_reading.php`, `api/device_command.php`, `api/dispensing.php`; `pages/dispense.php`, `pages/dispenser-status.php`, `pages/settings.php`, `pages/dashboard.php`, `pages/dispensing.php`; new `assets/js/timer-dispenser.js`.

New SQL: `database/timer_integration_migration.sql`, `database/timer_integration_rollback.sql`. New directory protection: `config/.htaccess`, `database/.htaccess`, `esp32/.htaccess`.

New Arduino files: `esp32/WaterBoniaTimer/WaterBoniaTimer.ino`, `config.example.h`, `timer_rules.h`, `timer_json.h`. Legacy firmware is retained without assuming it matches the board.

Removed simulation files: `config/demo.php`, `assets/js/dispenser.js`, `_demo_test.php`, `_portal_flow_test.php`. Updated documentation and existing regression entry points; added timer API/readiness/DOM tests and disposable DB/HTTP runners. No historical database records were deleted.

Safe rollback first disables both duration settings and clears the device assignment, as shown in `database/timer_integration_rollback.sql`. This retains evidence and new columns. Confirm the physical pump is OFF and all operations resolved before restoring code. Restore the source ZIP if necessary. A full SQL restore should be done only in maintenance after exporting current data, because it replaces later records. Restoring old code can restore the previous simulation behavior; do not mistake it for the current real controller. Restoring PHP/database backups does not restore ESP32 firmware. Keep durations disabled during rollback; upload a previously validated compatible sketch only if one is available. The original preinstalled sketch was not supplied.
