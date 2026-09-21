/**
 * WaterBonia ESP32 - Secrets Example
 *
 * Copy this file to secrets.h and fill in your actual values.
 * NEVER commit secrets.h to version control.
 * NEVER print these values in serial logs.
 *
 * The device token is obtained from the WaterBonia web dashboard
 * (Devices page → Generate Token). It is shown only once.
 */

#ifndef SECRETS_H
#define SECRETS_H

// Wi-Fi network credentials
#define SECRET_WIFI_SSID       "YourWiFiNetworkName"
#define SECRET_WIFI_PASSWORD   "YourWiFiPassword"

// WaterBonia device token (obtained during provisioning)
// This is a 96-character hex string shown once when generated.
#define SECRET_DEVICE_TOKEN    "YOUR_DEVICE_TOKEN_HERE"

#endif // SECRETS_H
