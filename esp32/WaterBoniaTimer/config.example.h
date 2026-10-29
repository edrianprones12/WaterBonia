#pragma once
// Copy to config.local.h beside WaterBoniaTimer.ino. This local file is ignored.
// Obtain these values from wiring inspection/original sketch; do not guess.
#define WB_HARDWARE_VERIFIED false
// Sensor-only commissioning is independent of relay/pump authorization.
#define WB_SENSOR_VERIFIED false
#define WB_IR_PIN -1
#define WB_RELAY_PIN -1
#define WB_IR_PRESENT_LEVEL -1
#define WB_RELAY_ON_LEVEL -1
#define WB_WIFI_SSID ""
#define WB_WIFI_PASSWORD ""
#define WB_DEVICE_TOKEN ""
// Example SHAPE only: http://<actual-computer-LAN-IPv4>/WaterBonia/api
#define WB_API_BASE ""
// Keep false for HTTPS. HTTP is opt-in for an isolated development LAN only.
#define WB_ALLOW_ISOLATED_HTTP false
// PEM root CA for your HTTPS server; never disable certificate validation.
#define WB_TLS_ROOT_CA ""
