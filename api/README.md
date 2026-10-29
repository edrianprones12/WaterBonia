# Timer device API — protocol 2

All device actions use POST JSON through `/WaterBonia/api/device/...` (or `/WaterBonia/api/index.php/device/...` without rewriting) and `Authorization: Bearer <device token>`. All bodies require integer `protocol_version: 2`. Tokens are provisioned through Devices and stored hashed server-side. Missing/expired/revoked tokens or disabled devices are rejected. The browser never receives the device token.

Responses use `{ "success": true, "data": ... }` or `{ "success": false, "error": "..." }`. Status codes: 401 token invalid; 403 device/identity disabled; 409 stale/duplicate/conflicting state; 422 malformed payload; 429 rate limited; 503 backend unavailable. Treat non-success as no execution authorization. GET `/api/status` is public informational metadata.

| Route suffix | Body beyond protocol_version | Returned data |
|---|---|---|
| `heartbeat` | `boot_id`: new random 32 lowercase hex chars per boot; optional firmware/IP/SSID | device ID, protocol version, server epoch, status, intervals |
| `sensor-reading` | same boot; increasing positive `report_sequence`; `sensor_status`: ok/unavailable/fault; boolean `container_detected` if ok, otherwise null; boolean `pump_status`; optional active command ID and elapsed ms | reading ID and backend receipt timestamp |
| `poll-command` | current `boot_id` | command null, or command ID, device ID, original boot, container, nominal mL label, duration ms, remaining claim lifetime ms and expiry timestamp |
| `dispensing/start` | original boot, command ID, `container_detected:true`, `sensor_status:ok` | session ID; `execute:true` on first authorization only; duration. Repeats return `execute:false` and must never run again |
| `dispensing/complete` | original boot and command ID, `pump_status:false`, `pump_elapsed_ms`, explicit `stop_reason` | final session/status. Identical retry succeeds once; conflict rejected |
| `command-result` | original boot, command ID, explicit result rejected/cancelled and bounded reason | unstarted command outcome; cannot declare success |

Stop reasons: `duration_elapsed` is the only success reason and must match the configured local duration within the permitted reporting tolerance. `container_removed` and `manual_stop` are stopped (`interrupted` in the existing session enum). `sensor_fault`, `max_runtime_exceeded`, `device_reset`, and `error` are failures. A reset/ambiguous start with no created session can cancel an unstarted command; it does not create a successful operation.

Pending/claimed commands expire after 30 seconds. A claim retry returns the same unstarted command for the same boot. A consumed command is not reissued. Firmware subtracts request round-trip time from the returned lease and rechecks it after start authorization. Local runtime is separately bounded to 30 seconds.

Heartbeat every 10 seconds; offline after 30. Telemetry every 2 seconds; stale after at most 10. Sequence duplicates/old boot samples cannot refresh sensor freshness. PHP timestamps are receipt/authorization timestamps. Device wall-clock start/finish fields remain null when unavailable; pump elapsed time is the local monotonic interval. No new volume/reservoir measurement is stored.

Browser API remains `/WaterBonia/pages/dispenser-status.php`. GET returns public sanitized hardware state/history, presets and current session authorization; it can record offline/expiry transitions. POST requires CSRF. `request_dispense` permits guests without sign-in and requires a valid bound device, configured time preset, and unique 16–64 character alphanumeric/underscore/hyphen `request_id`. The browser sends container choice, not authoritative duration or measured volume. A random server-side browser-session namespace is hashed with the client request ID before storage. Same-session repeated request IDs return the original command, including for NULL guest user IDs; overlapping different requests are blocked by a device-row transaction lock. `select_threshold` is rejected: real hardware uses time presets.

Use HTTPS with server certificate verification outside isolated development. Protect LAN access; never expose XAMPP publicly. The device bearer token authenticates reports but does not make an untrusted LAN confidential.

Public dispensing policy: all new requests are stored with NULL user_id; stale account state is ignored. Device claim/start revalidate hardware safety rather than user roles. Device/settings/alert management and the dashboard are also account-free; form writes retain CSRF protection. The retired login/logout routes preserve the session; actual session expiry creates a new request scope. Tests use isolated services only; never POST a test dispensing request to the live device.
