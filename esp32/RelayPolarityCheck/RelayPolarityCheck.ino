/*
 * Supervised bench diagnostic only; not production controller firmware.
 * Disconnect the pump and remove its power source before this test.
 * Use only the relay module's verified rated control supply and common ground.
 * Neither HIGH nor LOW is assumed to be relay OFF.
 * GPIO23 remains INPUT until the user explicitly arms the diagnostic.
 * No Wi-Fi, backend requests, or dispensing records are created.
 */
#include <Arduino.h>

constexpr int RELAY_INPUT_PIN = 23;
bool armed = false;
String command;

void setup() {
  pinMode(RELAY_INPUT_PIN, INPUT);
  Serial.begin(115200);
  Serial.println("Relay polarity check: GPIO23. Pump MUST be disconnected.");
  Serial.println("An INPUT pin is not a guaranteed relay-OFF signal.");
  Serial.println("After physically disconnecting pump power, enter: PUMP DISCONNECTED");
  Serial.println("Then enter H for HIGH, or L for LOW. Use Newline line ending.");
}

void handleCommand() {
  command.trim();
  command.toUpperCase();
  if (command == "PUMP DISCONNECTED") {
    armed = true;
    Serial.println("Bench test armed. H/L set signal level, not a presumed ON/OFF state.");
  } else if ((command == "H" || command == "L") && armed) {
    const int level = command == "H" ? HIGH : LOW;
    digitalWrite(RELAY_INPUT_PIN, level);
    pinMode(RELAY_INPUT_PIN, OUTPUT);
    Serial.println(level == HIGH ? "GPIO23 = HIGH. Observe relay indicator/contact state."
                                : "GPIO23 = LOW. Observe relay indicator/contact state.");
  } else if (command.length()) {
    Serial.println("Disconnect pump power, type PUMP DISCONNECTED, then H or L.");
  }
  command = "";
}

void loop() {
  while (Serial.available()) {
    const char c = (char)Serial.read();
    if (c == '\n') handleCommand();
    else if (c != '\r') {
      if (command.length() < 40) command += c;
      else command = "";
    }
  }
  delay(10);
}
