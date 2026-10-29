# ESP32 timer controller

Use [WaterBoniaTimer/WaterBoniaTimer.ino](WaterBoniaTimer/WaterBoniaTimer.ino) for the actual IR + relay + pump hardware. The original `src` tree is retained for reference and is not the current hardware controller.

The new sketch uses the installed ESP32 Arduino core and its bundled cJSON; no external Arduino JSON, load-cell, or LED library is required. Copy `WaterBoniaTimer/config.example.h` to `config.local.h` beside the sketch and supply verified wiring, Wi-Fi credentials, device token, and the actual backend LAN address. Configuration defaults do not touch any GPIO. Hardware remains locked until `WB_HARDWARE_VERIFIED` is explicitly true and the pins/levels pass validation.

Do not infer pins from COM7 or an upload screenshot. There is no generic safe way to recover the original Arduino source or wiring constants from a board-selection screenshot. Inspect the actual wiring or obtain the original sketch. Confirm relay OFF during reset, boot and power failure before a water test. The relay does not reduce 9V to 5V.

HTTP is disabled unless `WB_ALLOW_ISOLATED_HTTP` is explicitly enabled for an isolated LAN. HTTPS uses `WB_TLS_ROOT_CA`; certificate verification is never bypassed. The base URL must use the computer's reachable address, not localhost. Long-lived credentials are local-only and never printed.

IR arrival must stabilize for 100 ms; removal stops the pump immediately on the safety task's next sample (scheduled every 2 ms, not a certified timing guarantee). A simple digital IR output cannot prove detection of every disconnected/stuck-signal fault. Validate electrical fail-safe behavior; reported health means the configured input was read, not a complete sensor self-test.

The local safety task controls the relay independently of synchronous networking. It stops for absence, errors, configured duration, or the hard 30-second limit. Network loss never extends the timer. No serial pump-on command or local simulation exists in this sketch. Pending results and execution intent are journaled in NVS; reboot aborts instead of resuming. An unreadable/unacknowledged journal locks new commands.

See [commissioning, API contracts, tests and rollback](../docs/timer-hardware-integration.md). The user uploaded this firmware during commissioning on COM7. The assistant only compiles; it does not automatically upload or issue pump commands.

## Sensor-only commissioning

Set `WB_SENSOR_VERIFIED true` only after verifying the IR connection and detection polarity. Keep `WB_HARDWARE_VERIFIED false` to report the actual sensor without configuring the relay or retrieving dispensing commands. The local commissioning configuration uses GPIO18, active LOW; relay GPIO23 is active LOW based on the user's bench observations. In sensor-only mode pump control remains disabled, and the pump must stay physically disconnected during these uploads. The local installation subsequently enabled verified relay control after the user confirmed a 5V supply and idle-OFF behavior; this private configuration is not committed. Serial Monitor at 115200 reports accepted IR state changes. Firmware compilation is not a physical sensor test.

The HTTP transport objects now outlive HTTPClient during cleanup, preventing a dangling transport pointer. The earlier networking crash must still be monitored on the device; its root cause has not been conclusively established.

## Response timeout diagnostics

The HTTP response timeout is 5 seconds and connect timeout is 3 seconds; each request closes its connection explicitly. Heartbeat retries are limited to the 10-second schedule. Failed acknowledgements do not prove rejection: Apache can have accepted telemetry before the ESP32 times out. Error logs include elapsed request time and Wi-Fi RSSI without credentials. Device API JSON now supplies an explicit Content-Length. The user uploaded these changes and subsequently reported successful heartbeats and both IR transitions. Long-duration/network-failure reliability still requires physical testing. Local safety timing is unchanged.

## Commissioned installation

For the user's tested wiring: IR OUT GPIO18 (LOW means detected), relay IN GPIO23 (LOW activates). The local database presets are Cup 10 seconds / Tumbler 20 seconds. These are selected runtimes, not measured-volume guarantees. Repository configuration examples stay unconfigured and disabled, and the local Wi-Fi/device token file is never committed.
