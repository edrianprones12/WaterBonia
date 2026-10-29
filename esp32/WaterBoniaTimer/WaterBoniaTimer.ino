/**
 * Arduino ESP32 timer protocol v2. No upload or pump activation is automatic.
 * Uses the ESP32 Arduino core's bundled cJSON. No load cell or RGB LED.
 * Network calls run on the Arduino task; a separate safety task owns pump OFF.
 * Hardware is disabled until verified configuration is supplied.
 */
#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include "timer_json.h"
#include <esp_system.h>
#include "timer_rules.h"
#if __has_include("config.local.h")
#include "config.local.h"
#else
#include "config.example.h"
#endif

#ifndef WB_SENSOR_VERIFIED
#define WB_SENSOR_VERIFIED false
#endif

static Preferences store;
static String bootId;
static uint32_t sequenceNo = 0, deviceId = 0;
static bool configured = false, storageOk = false, heartbeatOk = false;
static bool sensorConfigured = false;
static portMUX_TYPE guard = portMUX_INITIALIZER_UNLOCKED;
static volatile bool present = false, healthy = false, pumping = false, startRequested = false, stopped = false;
static volatile uint32_t durationMs = 0, elapsedMs = 0, activeCommand = 0, startMs = 0;
static const char* finalReason = "error";
static uint32_t lastHeartbeat = 0, lastTelemetry = 0, lastPoll = 0, lastReconnect = 0;

static bool safePins() {
    // Classic ESP32-30P: reject flash, serial, strapping pins and input-only relay pins.
    const int inputPins[] = {4,13,14,16,17,18,19,21,22,23,25,26,27,32,33,34,35,36,39};
    bool ir = false, relay = false;
    for (int pin : inputPins) { if(pin == WB_IR_PIN) ir = true; if(pin == WB_RELAY_PIN && pin < 34) relay = true; }
    return ir && relay && WB_IR_PIN != WB_RELAY_PIN && (WB_IR_PRESENT_LEVEL == LOW || WB_IR_PRESENT_LEVEL == HIGH)
        && (WB_RELAY_ON_LEVEL == LOW || WB_RELAY_ON_LEVEL == HIGH);
}
static void relayOff() { if(configured) digitalWrite(WB_RELAY_PIN, !WB_RELAY_ON_LEVEL); }

static void safetyTask(void*) {
    bool wasPresent = false; uint32_t stableSince = millis();
    for (;;) {
        const uint32_t now = millis();
        const bool raw = sensorConfigured && digitalRead(WB_IR_PIN) == WB_IR_PRESENT_LEVEL;
        if(raw != wasPresent) { wasPresent = raw; stableSince = now; }
        portENTER_CRITICAL(&guard);
        // Stable arrival; immediate removal. Digital IR cannot diagnose a stuck signal.
        healthy = sensorConfigured;
        present = raw && (uint32_t)(now - stableSince) >= 100;
        if(startRequested) {
            startRequested = false;
            if(configured && present && healthy && wb::validDuration(durationMs)) {
                startMs = now; elapsedMs = 0; pumping = true;
                digitalWrite(WB_RELAY_PIN, WB_RELAY_ON_LEVEL);
            } else { relayOff(); finalReason = "container_removed"; elapsedMs = 0; stopped = true; }
        }
        if(pumping) {
            elapsedMs = now - startMs;
            const char* reason = wb::stopReason(raw, healthy, elapsedMs, durationMs);
            if(reason) { relayOff(); pumping = false; finalReason = reason; stopped = true; }
        }
        portEXIT_CRITICAL(&guard);
        vTaskDelay(pdMS_TO_TICKS(2));
    }
}
static bool saveJournal(JsonDocument& doc) {
    if ((doc["command_id"] | 0U) == 0 || String(doc["boot_id"] | "").length() != 32) return false;
    String json; serializeJson(doc, json);
    return storageOk && json.length() > 0 && store.putString("operation", json) == json.length();
}
static bool readJournal(JsonDocument& doc) {
    const String json = storageOk ? store.getString("operation", "") : "";
    if(json.isEmpty()) return false;
    if(deserializeJson(doc, json)) { Serial.println("Journal unreadable; hardware remains locked."); return true; }
    return true;
}
static int post(const char* route, JsonDocument& body, JsonDocument& answer) {
    if(WiFi.status() != WL_CONNECTED) return -1;
    body["protocol_version"] = 2;
    const String url = String(WB_API_BASE) + route;
    // HTTPClient retains a pointer to its transport. Declare it last so it is
    // destroyed first, while the underlying clients are still alive.
    WiFiClient plain; WiFiClientSecure tls; HTTPClient http;
    if(url.startsWith("https://")) {
        if(String(WB_TLS_ROOT_CA).isEmpty()) return -1;
        tls.setCACert(WB_TLS_ROOT_CA);
        if(!http.begin(tls, url)) return -1;
    } else {
        if(!WB_ALLOW_ISOLATED_HTTP || !url.startsWith("http://")) return -1;
        if(!http.begin(plain, url)) return -1;
    }
    // LAN response latency can exceed 2 s. Local relay safety runs separately.
    // Close each connection explicitly rather than reuse an uncertain socket.
    http.setReuse(false);
    http.setConnectTimeout(3000); http.setTimeout(5000);
    http.addHeader("Connection", "close");
    http.addHeader("Content-Type", "application/json"); http.addHeader("Authorization", "Bearer " + String(WB_DEVICE_TOKEN));
    String json; serializeJson(body, json);
    const uint32_t sentAt = millis();
    const int code = http.POST(json);
    if(code < 0) Serial.printf("Network response unavailable: code %d, elapsed %lu ms, Wi-Fi RSSI %d dBm.\n",code,(unsigned long)(millis()-sentAt),WiFi.RSSI());
    if(code > 0 && deserializeJson(answer, http.getString())) { http.end(); return -1; }
    http.end(); return code;
}
static bool accepted(int code, JsonDocument& response) { return code >= 200 && code < 300 && response["success"] == true; }
static void heartbeat() {
    JsonDocument body, response; body["boot_id"] = bootId; body["firmware_version"] = "timer-2.0";
    body["ip_address"] = WiFi.localIP().toString();
    const int code = post("/device/heartbeat", body, response);
    heartbeatOk = accepted(code, response);
    if(heartbeatOk) deviceId = response["data"]["device_id"] | 0U;
    if(heartbeatOk) Serial.println("Backend connected: authenticated heartbeat accepted.");
    else Serial.printf("Backend connection not ready (HTTP/result code %d).\n", code);
}
static void telemetry() {
    bool p,h,on; uint32_t command,elapsed;
    portENTER_CRITICAL(&guard); p=present; h=healthy; on=pumping; command=activeCommand; elapsed=elapsedMs; portEXIT_CRITICAL(&guard);
    JsonDocument body,response; body["boot_id"]=bootId; body["report_sequence"]=++sequenceNo;
    body["sensor_status"]=h?"ok":"unavailable";
    if(h) body["container_detected"]=p; else body["container_detected"]=nullptr;
    body["pump_status"]=on; body["active_command_id"]=command; body["pump_elapsed_ms"]=elapsed;
    const int code = post("/device/sensor-reading",body,response);
    if(accepted(code,response)) {
        static int previousPresence = -1;
        const int currentPresence = h ? (p ? 1 : 0) : -1;
        if(currentPresence != previousPresence) {
            Serial.println(!h ? "IR report accepted: sensor UNAVAILABLE." : p ? "IR report accepted: container DETECTED." : "IR report accepted: container NOT DETECTED.");
            previousPresence = currentPresence;
        }
    } else Serial.printf("IR report acknowledgement unavailable (HTTP/result code %d); delivery is unconfirmed.\n",code);
}
static void reportResult(JsonDocument& journal) {
    if(journal["command_id"].isNull()) return; // Corrupt journal stays locked for inspection.
    JsonDocument body,response;
    body["command_id"]=journal["command_id"]; body["boot_id"]=journal["boot_id"];
    body["pump_status"]=false; body["pump_elapsed_ms"]=journal["elapsed_ms"] | 0U;
    body["stop_reason"]=journal["reason"] | "device_reset";
    if(accepted(post("/device/dispensing/complete",body,response),response)) {
        if(store.remove("operation")) {
            portENTER_CRITICAL(&guard); activeCommand=0; stopped=false; elapsedMs=0; portEXIT_CRITICAL(&guard);
            Serial.println("Operation result acknowledged. Actual volume not measured.");
        }
    }
}
static void pollCommand() {
    bool ready; portENTER_CRITICAL(&guard); ready=present && healthy && !pumping && !startRequested && !stopped; portEXIT_CRITICAL(&guard);
    if(!ready || !configured || !storageOk || !heartbeatOk || deviceId==0) return;
    JsonDocument journal; if(readJournal(journal)) return;
    JsonDocument body,response; body["boot_id"]=bootId;
    const uint32_t sentAt=millis();
    if(!accepted(post("/device/poll-command",body,response),response)) return;
    JsonVariant cmd=response["data"]["command"]; if(cmd.isNull()) return;
    const uint32_t command=cmd["command_id"] | 0U, ms=cmd["duration_ms"] | 0U, lease=cmd["expires_in_ms"] | 0U;
    const String type=cmd["container_type"] | "";
    if(cmd["protocol_version"]!=2 || (cmd["device_id"] | 0U)!=deviceId || String(cmd["boot_id"] | "")!=bootId
        || (type!="cup" && type!="tumbler") || command==0 || !wb::validDuration(ms) || lease==0 || lease>30000 || millis()-sentAt>=lease) return;
    // Write intent BEFORE start request: an ambiguous HTTP response must never repeat a pump cycle.
    journal["command_id"]=command; journal["boot_id"]=bootId; journal["duration_ms"]=ms; journal["phase"]="intent";
    if(!saveJournal(journal)) return;
    JsonDocument start,answer; start["command_id"]=command; start["boot_id"]=bootId;
    portENTER_CRITICAL(&guard); ready=present && healthy; portEXIT_CRITICAL(&guard);
    start["container_detected"]=ready; start["sensor_status"]=ready?"ok":"unavailable";
    const int code=post("/device/dispensing/start",start,answer);
    if(!accepted(code,answer) || answer["data"]["execute"]!=true || (answer["data"]["duration_ms"] | 0U)!=ms || millis()-sentAt>=lease) {
        journal["phase"]="result"; journal["reason"]="device_reset"; journal["elapsed_ms"]=0; saveJournal(journal); return;
    }
    journal["phase"]="running";
    if(!saveJournal(journal)) return;
    portENTER_CRITICAL(&guard); durationMs=ms; activeCommand=command; elapsedMs=0; startRequested=true; portEXIT_CRITICAL(&guard);
}
void setup() {
    // No Serial/USB wait before setting the verified relay's safe state.
    configured=WB_HARDWARE_VERIFIED && safePins();
    sensorConfigured=(WB_SENSOR_VERIFIED || configured) && safePins();
    if(sensorConfigured) pinMode(WB_IR_PIN,INPUT);
    if(configured) { digitalWrite(WB_RELAY_PIN,!WB_RELAY_ON_LEVEL); pinMode(WB_RELAY_PIN,OUTPUT); relayOff(); }
    Serial.begin(115200);
    storageOk=store.begin("wb_timer_v2",false);
    char boot[33]; snprintf(boot,sizeof(boot),"%08lx%08lx%08lx%08lx",(unsigned long)esp_random(),(unsigned long)esp_random(),(unsigned long)esp_random(),(unsigned long)esp_random()); bootId=boot;
    JsonDocument journal;
    if(readJournal(journal) && !journal["command_id"].isNull() && String(journal["phase"] | "")!="result") {
        journal["phase"]="result"; journal["reason"]="device_reset"; journal["elapsed_ms"]=0;
        if(!saveJournal(journal)) storageOk=false;
    }
    if(xTaskCreatePinnedToCore(safetyTask,"pump-safety",4096,nullptr,3,nullptr,1)!=pdPASS) { relayOff(); configured=false; sensorConfigured=false; Serial.println("Safety task unavailable; locked."); }
    WiFi.mode(WIFI_STA);
    if(String(WB_WIFI_SSID).length()) WiFi.begin(WB_WIFI_SSID,WB_WIFI_PASSWORD);
    Serial.println(configured ? "Verified hardware configured. No local pump trigger." : "Hardware locked: confirm wiring and config.local.h before activation.");
    if(sensorConfigured && !configured) Serial.println("Sensor-only mode: GPIO18 IR reporting enabled; relay control and command polling remain disabled.");
}
void loop() {
    const uint32_t now=millis();
    static bool wasConnected = false;
    const bool connected = WiFi.status() == WL_CONNECTED;
    if(connected && !wasConnected) Serial.println("Wi-Fi connected. Checking WaterBonia backend...");
    if(!connected && wasConnected) Serial.println("Wi-Fi disconnected. Local hardware lock/safety remains in effect.");
    wasConnected = connected;
    if(WiFi.status()!=WL_CONNECTED) { heartbeatOk=false; if(now-lastReconnect>=10000) {lastReconnect=now; WiFi.reconnect();} delay(10); return; }
    // Retry on the same bounded schedule after failure; never flood the backend.
    if(lastHeartbeat==0 || now-lastHeartbeat>=10000) {lastHeartbeat=now; heartbeat();}
    bool on,stop; uint32_t elapsed; const char* reason;
    portENTER_CRITICAL(&guard); on=pumping || startRequested; stop=stopped; elapsed=elapsedMs; reason=finalReason; portEXIT_CRITICAL(&guard);
    JsonDocument journal; const bool pending=readJournal(journal);
    if(stop && pending && String(journal["phase"] | "")!="result") {
        journal["phase"]="result"; journal["reason"]=reason; journal["elapsed_ms"]=elapsed;
        if(!saveJournal(journal)) { delay(50); return; }
    }
    if(!on && pending) {
        // Any unresolved intent is safe to abort, never safe to execute again.
        if(String(journal["phase"] | "")!="result") {journal["phase"]="result"; journal["reason"]="device_reset"; journal["elapsed_ms"]=0; if(!saveJournal(journal)) return;}
        if(now-lastPoll>=2000) {lastPoll=now; reportResult(journal);}
    }
    if(heartbeatOk && now-lastTelemetry>=2000) {lastTelemetry=now; telemetry();}
    if(!on && !pending && now-lastPoll>=2000) {lastPoll=now; pollCommand();}
    delay(5);
}
