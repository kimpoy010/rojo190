/*
 * Pool Sabong RFID reader firmware (ESP32 + Wiegand)
 *
 * Flash this EXACT SAME sketch onto every reader board — MERON, WALA, and
 * TOP-UP alike. Nothing in this file decides which side a board is: each
 * board only ever reports its own WiFi MAC address as its device_id, and
 * a superadmin assigns MERON / WALA / TOP-UP to that device_id in the web
 * app (Superadmin -> RFID Terminals). Swapping which physical pad is
 * which, or replacing a board, is then just a dropdown change there —
 * never a re-flash.
 *
 * Library used: monkeyboard/Wiegand-Protocol-Library-for-Arduino
 *   https://github.com/monkeyboard/Wiegand-Protocol-Library-for-Arduino
 * Install it via Arduino IDE -> Sketch -> Include Library ->
 * Add .ZIP Library... (download the repo as a ZIP), or clone it into
 * your Arduino/libraries folder.
 *
 * See README.md in this folder for wiring and full setup steps.
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <WIEGAND.h>

// ---- Fill these in for your network / deployment ----
const char *WIFI_SSID = "YOUR_WIFI_SSID";
const char *WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
const char *API_BASE_URL = "http://192.168.1.50:8000"; // no trailing slash
const char *TERMINAL_TOKEN = "PASTE_THE_TERMINAL_TOKEN_HERE"; // Superadmin -> RFID Terminals

// ---- Wiegand D0/D1 wiring — change if your board's wiring differs ----
const int PIN_D0 = 4;
const int PIN_D1 = 5;

// Ignore repeat reads while a card is held against the reader.
const unsigned long SCAN_COOLDOWN_MS = 1500;

// How often to retry a WiFi reconnect if the connection drops.
const unsigned long WIFI_RETRY_MS = 5000;

WIEGAND wg;
unsigned long lastScanAt = 0;
unsigned long lastWifiAttemptAt = 0;
String deviceId;

void connectWiFi() {
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  Serial.print("Connecting to WiFi");
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) {
    delay(500);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print("Connected. IP: ");
    Serial.println(WiFi.localIP());
  } else {
    Serial.println("WiFi connect timed out — will keep retrying in the loop.");
  }
}

void setup() {
  Serial.begin(115200);
  delay(200);

  connectWiFi();

  // Stable, unique per board — this is the ONLY identity the backend
  // ever sees for this reader. No manual configuration needed here.
  deviceId = WiFi.macAddress();
  Serial.print("Device ID (WiFi MAC): ");
  Serial.println(deviceId);
  Serial.println("Register this device's role under Superadmin -> RFID Terminals.");

  wg.begin(PIN_D0, PIN_D1);
}

/*
 * Formats the scanned code as a plain decimal string. This is what gets
 * sent as tag_uid — it must exactly match whatever a teller enters when
 * linking that same physical card to a player under
 * Teller -> RFID Cards -> "Tag ID". Tap a card and check the Serial
 * monitor output during setup to see the exact value to use there.
 */
String tagUidFromWiegand() {
  return String(wg.getCode());
}

void postScan(const String &tagUid) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi not connected — dropping this scan.");
    return;
  }

  HTTPClient http;
  String url = String(API_BASE_URL) + "/api/rfid/scan";
  http.begin(url);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", String("Bearer ") + TERMINAL_TOKEN);

  String body = String("{\"device_id\":\"") + deviceId + "\",\"tag_uid\":\"" + tagUid + "\"}";
  int status = http.POST(body);

  Serial.print("POST /api/rfid/scan -> ");
  Serial.println(status);
  if (status > 0) {
    // On a fresh/unassigned device this will read back "not been assigned
    // a role yet" — expected until it's set up in Superadmin. Any other
    // rejection reason (no amount armed, card not registered, betting
    // closed...) also shows up here, same as it does on the kiosk screen.
    Serial.println(http.getString());
  } else {
    Serial.print("Request failed: ");
    Serial.println(http.errorToString(status));
  }

  http.end();
}

void loop() {
  if (WiFi.status() != WL_CONNECTED && millis() - lastWifiAttemptAt >= WIFI_RETRY_MS) {
    lastWifiAttemptAt = millis();
    connectWiFi();
  }

  if (wg.available()) {
    unsigned long now = millis();
    if (now - lastScanAt >= SCAN_COOLDOWN_MS) {
      lastScanAt = now;
      String tagUid = tagUidFromWiegand();
      Serial.print("Tag scanned: ");
      Serial.println(tagUid);
      postScan(tagUid);
    }
  }
}
