This plugin extends fsbhoa_ac_core.  @/home/pi/fsbhoa_ac_core/other_docs/ARCHITECTURE.md

## Purpose

This plugin implements a web-based kiosk for the lodge.

- Cardholders who enter the lodge during business hours swipe their MIFARE RFID photo ID on a card reader attached by USB to the access control machine.
- The kiosk runs on a touchscreen display that is locked to this kiosk.
- After swiping, the cardholder enters the number of guests and touches the icon for the amenity they are going to visit.

How it is used:

- Everyone entering the lobby is asked to swipe. It isn't required to get in, but nearly everyone does, and the receptionist reminds people who walk past.
- The kiosk **unlocks nothing**. It writes to the access log and shows the sign-in on the real-time monitor, which the receptionist watches. The lobby is locked outside business hours, so the kiosk has no hours logic of its own.
- The log is compiled into usage reports for the board of directors, so they can see how much each amenity is used.
- A resident without their card can be signed in by the receptionist with the **Admin Override** on the cardholder list. Non-residents must be escorted by a resident as a guest.
- Guest rule: at most **8 guests per resident** (Kiosk Settings, Max Guests). The resident is responsible for their guests and must stay with them the whole time.

## Access

The kiosk also works in any web browser at:

- Production: `access.fsbhoa.com:8080`
- Testbed: `testbed.fsbhoa.com:8080`

Only browsers on registered station IP addresses can use it (see Stations).

## Amenities are a guess

There is no one-to-one match between gates and amenities, so the amenity a cardholder picks is their stated intent, not proof of where they went. The gate hierarchy:

- Residents get in through the **lobby** (when open, via this kiosk) or the **west gate** (UHPPOTE), which also gives access to the other gates after hours.
- Inside, there are 5 gates into the pool area and 1 into the exercise area. Most of the courts have no gate of their own.
- Amenity gates are open 5am to 10pm.
- Pedestrian gates control the community's outer perimeter.

Core's access service (`Fsbhoa_Access_Service::process_amenity_logic`) combines these. A kiosk sign-in (door role `KIOSK`) is kept as stated. A later inner-gate swipe for the same amenity takes over its amenity and guest count.

## How it fits together

`[Kiosk browser] <=> wss :8080 [Go service kiosk/main.go] <=> REST fsbhoa/v1/kiosk/* (X-API-KEY) [WordPress]`

- **Go service** `fsbhoa_kiosk` (systemd unit of the same name, `WorkingDirectory=/home/pi/fsbhoa_ac_kiosk/kiosk`):
  - Serves `kiosk/web/` over HTTPS.
  - Runs a websocket at `/ws`.
  - Reads `/var/lib/fsbhoa/kiosk.json`, which Kiosk Settings generates.
  - Logs to journald (`journalctl -u fsbhoa_kiosk`) because `log_file` is blank.
- **REST endpoints** (`includes/class-fsbhoa-kiosk-rest-api.php`), all requiring the Access Verification API Key from core's General settings (`X-API-KEY`, checked by `Fsbhoa_Verification_REST_API::api_key_permission_check`; the kiosk no longer has its own key, and `kiosk.json` gets the core key when settings are saved):

  | Endpoint | Returns or does |
  |---|---|
  | `GET /kiosk/config` | Amenities, logo, splash image, inactivity amenity and timeout |
  | `GET /kiosk/station?ip=` | The station registered for an address |
  | `GET /kiosk/validate-card/{rfid}?door_number=N` | Checks a card. A rejected card is logged at that station. |
  | `POST /kiosk/log-signin` | Re-checks the card, then logs through `Fsbhoa_Access_Service` |

- **Websocket messages:**
  - Browser to service: `manualSwipe {rfid}` and `amenitySelected {amenity, guests}`.
  - Service to browser: `kioskConfig`, `station`, `cardSwiped`, `notRegistered` and `stationError`.
- **The browser can't choose what gets logged.** It never sends a station or card number with a sign-in. The service logs only the card it validated on that connection, and only once.
- **Monitor status:**
  - The service tells core's monitor service when a station connects or disconnects.
  - It reports again when uhppote's event service broadcasts `trigger_poll`. Without that event service, station status only changes on connect and disconnect.
- **Config reload:** the service checks `kiosk.json`'s timestamp every 10 seconds. When it changes, the service reloads and sends the new config to open screens; no restart.
  - WordPress rewrites the file when Kiosk Settings are saved, and on core's `fsbhoa_core_amenities_changed` hook.
  - Port, certificates, log file and event service URL still need a restart.

## Stations

- Each kiosk browser is a **station**: a door with role `KIOSK` on the virtual controller `900000` (`Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL`). Stations are managed on the Kiosk Stations page.
- The service identifies the station from the browser's **source IP address** (`ac_doors.ip_address`). An unregistered address gets a "not registered" screen showing its IP.
- Each station needs a fixed address. Connections must come straight from the browser, with no proxy or NAT in between.
- Any browser on a registered address can be a station.
- In practice there is one: the lobby touchscreen. On production it is the desktop of access.fsbhoa.com itself. Moving it to a dedicated Raspberry Pi 3B is being considered, because the kiosk sometimes hangs or misbehaves, for example when a browser update is offered.
- **Slot 0 is the Admin Override station.** It is created on first use and has no IP address, so no browser can connect as it. Real stations are numbered from 1.
- Controller `900001` ("QR Scan") belongs to `fsbhoa_light`, so always match the kiosk controller by serial, not by type alone.
- Testing: the testbed has a station for a home PC over the pfSense VPN (`192.168.70.3`). A browser on the testbed machine itself connects from `127.0.0.1`, because `testbed.fsbhoa.com` resolves to `127.0.0.1` there.

## Card reader

- The reader is a **FisaiD M303** USB MIFARE reader in keyboard mode, set to "2hex->3dec + 4hex->5dec". Each card types an **8-digit decimal number with leading zeros**. Badge numbers in `ac_credentials` are stored the same way.
- The page captures keystrokes in a hidden input. A pause of 50 ms ends a card. It keeps the last 8 digits and doesn't rely on the trailing CR/LF.
- The service treats swipes within **400 ms** as one wallet scan and uses the first valid card.
- Limits per connection: 5 cards per scan and 10 card lookups a minute, so card numbers can't be guessed in bulk.

## Kiosk screen

- **Swipe flow:** after a valid swipe, the screen shows the photo and name, guest buttons (0 to Max Guests) and amenity icons.
- **Inactivity:** with no choice within the Inactivity Timeout (30 s by default), the cardholder is signed in to the Inactivity Amenity. If that is None, nothing is recorded.
- **Rejected cards:** the screen says only "Card not found." The log and monitor get the full reason, from core's `Fsbhoa_Access_Service::describe_credential()`.
- **Logo gestures:** triple-tap reloads the page; a 2-second press shows or hides the on-screen debug log.

## Admin Override

The cardholder list's action icon (added through core's `fsbhoa_cardholder_list_action_icons` hook) opens a WordPress dialog with guest and amenity buttons. It needs `manage_options`.

The dialog records the sign-in through the access service at the Admin Override station (slot 0). It doesn't involve the Go service or the kiosk page. See `includes/class-fsbhoa-kiosk-admin-override.php`.

## Kiosk machine setup

- `start_kiosk.sh` runs Firefox (`/opt/firefox/firefox`, not the snap) in kiosk mode with its own profile, opening `<wordpress host>:<port>/` from `kiosk.json`.
- It loops: it starts Firefox when the service is up, closes it when the service is down, and restarts it when `/tmp/restart_kiosk_browser` appears. The service creates that file at startup.
- On production it is started by the desktop autostart, `~/.config/autostart/fsbhoa_kiosk.desktop`. That still points at the old monolithic path, `/home/pi/fsbhoa_ac/start_kiosk.sh`. The testbed has no autostart.

## Building and testing

- **Build and install:** `./rebuild.sh` builds `kiosk/fsbhoa_kiosk`. `./rebuild.sh install` copies it to `/usr/local/bin` and restarts the service. PHP and JS changes are live straight away (the repo is symlinked into WordPress).
- **The binary isn't committed** (`.gitignore`).
- **Diagnostics:** core's Diagnostics page runs this plugin's checks (`includes/class-fsbhoa-kiosk-regression-test.php`, via `fsbhoa_regression_test_steps`). They check that the service answers, the config, the card check, a REST sign-in, and that it was logged at a station. They use core's System test card `22222222`.
- **Open work and deployment notes** are in `TODO.md`. Production deployment is on hold until all the plugins are done.
