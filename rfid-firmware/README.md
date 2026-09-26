# RFID Reader Firmware (ESP32)

Firmware for the physical MERON, WALA, and TOP-UP reader boards at a
betting kiosk. Each board is an ESP32 wired to a Wiegand RFID reader,
running the [monkeyboard Wiegand
library](https://github.com/monkeyboard/Wiegand-Protocol-Library-for-Arduino),
and talks straight to the Laravel app over WiFi — there's no PC or serial
bridge involved, unlike some Wiegand setups.

## How role assignment works

**Every board runs the identical `rfid_reader.ino` sketch.** Nothing in the
firmware says "I am the MERON reader" — it just reports its own WiFi MAC
address (its `device_id`) whenever a card is tapped. Which side that
`device_id` counts as is decided entirely in the web app:

1. Flash a board, power it on, tap any card on it.
2. In the app, go to **Superadmin → RFID Terminals** and find the terminal
   this board belongs to (the token you configured in the sketch). The
   board will have auto-registered under **Reader boards** as unassigned.
3. Pick **MERON**, **WALA**, or **TOP-UP** from its dropdown (optionally
   give it a label like "Left pad"), and save.

The board immediately starts working with that role — no re-flashing.
Swapping which physical pad is MERON vs WALA, or replacing a broken board,
is the same: just change the dropdown for the relevant `device_id`.

## Hardware

- ESP32 dev board (any variant with WiFi — this doesn't use Bluetooth).
- A Wiegand-output RFID reader (26-bit or 34-bit — the library
  auto-detects).
- Reader's D0 → ESP32 GPIO (default in the sketch: **GPIO 4**).
- Reader's D1 → ESP32 GPIO (default in the sketch: **GPIO 5**).
- Reader's GND → ESP32 GND. Power the reader per its own spec (many
  12V Wiegand readers need a separate 12V supply, not the ESP32's 3.3V/5V
  rail — check your reader's datasheet).

Change `PIN_D0` / `PIN_D1` at the top of the sketch if you wire it
differently.

## Setup

1. **Install the library**: Arduino IDE → Sketch → Include Library →
   Add .ZIP Library... and select a ZIP download of
   [monkeyboard/Wiegand-Protocol-Library-for-Arduino](https://github.com/monkeyboard/Wiegand-Protocol-Library-for-Arduino)
   (or clone it directly into your `Arduino/libraries` folder).
2. **Create a terminal** in the app (Superadmin → RFID Terminals → New
   terminal) if you haven't already, and copy its **Reader board token**.
3. **Edit the constants** at the top of `rfid_reader.ino`:
   - `WIFI_SSID` / `WIFI_PASSWORD`
   - `API_BASE_URL` — wherever the Laravel app is reachable from the
     kiosk's network (no trailing slash)
   - `TERMINAL_TOKEN` — the token you copied in step 2
4. **Flash it** to the board (Arduino IDE, correct board/port selected).
5. **Open the Serial Monitor** (115200 baud) to confirm it connects to
   WiFi and prints its `device_id` (its MAC address).
6. Tap a test card — the Serial Monitor will show the POST result. On a
   fresh board this reads "not been assigned a role yet", which is
   expected until you assign it in the admin UI (see above).
7. Repeat steps 3–6 for the other boards, reusing the **same
   `TERMINAL_TOKEN`** for every board at this station (their `device_id`
   is what tells them apart, not the token).

## Matching tag IDs with card registration

The sketch sends the scanned tag as a **plain decimal string**
(`String(wg.getCode())`). This must exactly match whatever gets typed into
**Teller → RFID Cards → Tag ID** when linking that physical card to a
player. During setup, tap a card and read the decimal value off the
Serial Monitor to know what to register.

## Running unattended

Once configured, the board reconnects to WiFi automatically if the
connection drops (retries every 5 seconds) and needs no interaction beyond
power — mount it near its reader and leave it running.

## Troubleshooting

- **Nothing happens on tap**: check the Serial Monitor — it logs every tap
  attempt and the exact reason for any rejection (WiFi not connected,
  request failed, unassigned reader, card not registered, no amount
  armed, betting closed, etc).
- **"401" logged for every scan**: `TERMINAL_TOKEN` doesn't match the
  terminal's current token (e.g. it was regenerated in Superadmin without
  updating every board) — copy the current token and re-flash.
- **Board shows up as a new/duplicate entry after a WiFi reset**: it
  shouldn't — `device_id` is the MAC address, which is stable across
  reboots. If a board's MAC genuinely changed (e.g. swapped for a
  different physical unit), the old entry is now stale and can be removed
  from the terminal's reader list in Superadmin.
