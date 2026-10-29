/*
 * WaterBonia sensor-only diagnostic.
 * User-supplied wiring table: IR OUT -> GPIO18, relay IN -> GPIO23.
 * Disconnect relay and pump before uploading. This sketch never configures
 * or writes GPIO23 and does not determine the relay's activation polarity.
 * Confirm the sensor's supply and output are ESP32-compatible first.
 */
#include <Arduino.h>

constexpr int IR_SENSOR_PIN = 18;

void setup() {
  Serial.begin(115200);
  pinMode(IR_SENSOR_PIN, INPUT);
  Serial.println("WaterBonia IR check: GPIO18. Relay control is not initialized.");
  Serial.println("Compare readings with the tray empty and a container present.");
  Serial.println("Raw HIGH/LOW only; detection polarity is not assumed.");
}

void loop() {
  Serial.print("GPIO18 = ");
  Serial.println(digitalRead(IR_SENSOR_PIN) == HIGH ? "HIGH (1)" : "LOW (0)");
  delay(500);
}
