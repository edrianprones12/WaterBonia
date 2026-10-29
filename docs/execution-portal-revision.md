> Historical design/test record. Superseded by [the real hardware integration](timer-hardware-integration.md); simulation is no longer available.

# WaterBonia dispensing revision

The public interface uses **Water Dispensing System** beneath the WATERBONIA brand. The sidebar opens one content view at a time: Dispense Water, Container Settings, Device Status, Activity Logs, Safety Sequence, and Demo Controls when Demo Mode is enabled. Navigation preserves an active fill, supports URL fragments and browser history, and provides a collapsible menu on small screens. See [Layout optimization report](execution-portal-layout-optimization.md) for layout details and visual-verification limits.

## Adjustable dispensing thresholds

There is no water-level sensor. A threshold means **the amount to dispense into the selected container**, measured in mL by the existing HX711 load cell. It does not represent water available in a tank.

| Container | Initial amount | Initial permitted range |
| --- | ---: | ---: |
| Cup | 250 mL | 1–2000 whole mL |
| Tumbler | 500 mL | 1–2000 whole mL |

Container Settings provides independent numeric inputs for Cup and Tumbler. The Place step also edits the selected container's amount. The cards, confirmation, progress denominator, and completion summary use the selected amount. For example, a Cup set to 150 mL fills to 150 mL; a Tumbler set to 650 mL fills to 650 mL. A value below the initial default is permitted.

The server validates each selection with CSRF protection and stores it under the new PHP session key `portal_fill_volumes`. Existing reservoir preferences are not reinterpreted as dispensing amounts. Public selections do not update global settings.

`config/portal.php` reads `container_<cup|tumbler>_fill_<min|max|default>_ml` from the existing settings table. Old `container_*_target_ml` values supply initial defaults when no new default exists. Legacy reservoir range/calibration settings are ignored. Negative, zero, fractional, missing, boolean, array, nonnumeric, unsupported-container, and out-of-range input is rejected. Invalid range configuration blocks dispensing.

The browser retains legacy request/control names such as `threshold_ml` for compatibility, but their meaning is a fill amount. The selection response returns equal `threshold_ml` and `target_amount_ml` values. A dispensing request stores the validated amount in `device_commands.target_amount_ml` with `fill_volume_ml` metadata. Changing preferences does not change that accepted command or a started session. Controls are locked while an operation is pending or active.

`database/execution_portal_migration.sql` adds only fill defaults/ranges and telemetry-age settings using `INSERT IGNORE`. It creates no tables and deletes no history. The migration was not applied to the user's database; configuration fallbacks work without it.

## Device and API behavior

The browser requires fresh telemetry, detected container presence, an online dispenser, valid pump state, no relevant device/measurement error, and no competing queued/claimed/active operation. Detection alone never starts dispensing: the user must press Dispense Water.

Queuing, claiming, and session authorization use device-row locking. Claiming preserves the queued amount. The session-start endpoint requires a claimed command belonging to the authenticated device, with matching container and amount, and rechecks safety before creating a session. A missing, replayed, or mismatched command cannot create a dispensing session.

The ESP32 no longer reads an analog tank sensor, emits tank-level telemetry, enters a LOW_WATER state, or accepts the old WATER= serial simulation command. Its production pump stays OFF until the server authorizes a session. Local container presence, load-cell validity, command lease, timeout, and emergency-stop checks remain. The pump stops when HX711 measured volume reaches the accepted target. Load-cell calibration is still necessary for accurate physical dispensing.

Synchronous network requests are deferred while the pump runs so HTTP timeouts cannot delay local cutoff checks. Hardware progress readings pause during a fill; final measured volume is sent after the pump stops, followed by queued telemetry. Pre-session readings are not presented as current pump/container state during an active session. Demo progress remains continuous. The load-cell adapter uses the [HX711_ADC API](https://raw.githubusercontent.com/olkal/HX711_ADC/master/src/HX711_ADC.h), verifies tare/sample readiness, and rejects nonfinite, overflowing, or stale readings.

Telemetry and session endpoints accept the absence of water-level fields and store null in retained legacy columns. Historical readings and alerts remain in the database; obsolete water-level alerts do not block new fills. Other device/error guards remain. Physical RGB telemetry is unavailable in the current protocol, so hardware mode labels it unavailable rather than inferring a confirmed LED state.

Hardware progress and completion use recorded command/session targets, measured amounts, and explicit statuses. A stopped pump alone does not establish completion. Failed status updates disable dispensing and show unknown values; backend outages return safe unavailable views and JSON without fabricated readings.

Known firmware limitation: completion/result POSTs are not retried after a network failure. The local pump stops, but a lost completion response can leave the backend session marked active and block another fill. Completion retry/reconciliation still needs implementation and device testing.

## Demo activity and isolation

Demo Controls simulate container detection/removal, selected-amount completion, offline interruption, and reset. Reservoir controls and low-water simulations are removed. Automatic demo progress reaches 100% at the selected amount and reports pump OFF.

Activity Logs display `YYYY-MM-DD HH:mm:ss` with event, details, and status. Ten entries appear initially; View More exposes additional loaded entries. Small screens use labeled rows.

New simulated history stores up to 100 entries under `waterbonia.demo.activities.v2`. Saved timestamps survive refresh. The previous v1 history remains untouched as an archive and is not presented as current sensor behavior. Reset Demo preserves current history; Clear Demo History removes only v2. Every demo entry is labeled Simulated, and browser-storage failure falls back to in-memory history.

Hardware activity is device-scoped and allowlisted from existing backend records. Administrative messages, credentials, and arbitrary metadata are not exposed. New selection logs describe the actual fill amount; old threshold events are labeled historical. Demo selection requests validate session preferences only. Simulated dispensing never sends hardware commands/telemetry or inserts production records.

## Layout and routing

One compact sidebar brand and a title/status toolbar replace repeated branding. Container Settings replaces Water Availability. Each section scrolls naturally when needed; hidden sections remain mounted so navigation cannot reset the controller or timers. Date/time logs and the short copyright footer remain.

The main URL and retired `/admin/` entry route redirect to public dispensing. Protected maintenance code and history are preserved. Development helpers `_admin_shot.php` and `_demo_test.php` are CLI-only.

## Verification

The revised model passed 51 guest HTTP checks against the running XAMPP Demo Mode application, 40 controller checks, 18 navigation checks, 103 backend safety/API handler checks, and 14 activity projections. They cover independent custom amounts, a value below the former Cup default, numeric bounds, automatic completion, immutable operation targets, invalid inputs, status failures, history isolation/timestamps, dedicated views, and CSRF validation. Modified PHP and JavaScript pass syntax checks; whitespace checks pass.

The isolated PHP suites also exercise actual device API handlers with in-memory fixtures: accepted targets, claimed-command ownership/status/lease, mismatches, stale/error/concurrent states, and omitted or obsolete level fields. They perform no database or device writes. The database-backed API integration suite is not run against the user's production data.

Run the dependency-free regression suites with:

```powershell
& 'C:\xampp\php\php.exe' tests/portal_safety_test.php
& 'C:\xampp\php\php.exe' tests/portal_activity_test.php
& 'C:\xampp\php\php.exe' tests/portal_http_test.php http://localhost/WaterBonia
& 'C:\Program Files\nodejs\node.exe' tests/portal_controller_test.cjs
& 'C:\Program Files\nodejs\node.exe' tests/portal_navigation_test.cjs
```

The HTTP suite uses a separate guest session and rejects production commands in Demo Mode. Its optional second URL tests hardware mode with an unavailable database. PHP safety/activity tests use isolated fixtures. They do not operate physical hardware or impersonate an administrator.

Final visual viewport checks remain unavailable because no browser automation surface was connected. The supplied screenshot informed the design but predates these edits. Actual MySQL transaction behavior, firmware compilation, and physical dispensing are not verified by the web/fixture tests. No firmware was flashed; the installed environment has no Arduino/PlatformIO toolchain.
