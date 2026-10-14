# FSBHOA Access Control: Lodge Kiosk

An extension plugin for the [FSBHOA Access Control system](https://github.com/dkeeney/fsbhoa_ac_core). It runs the sign-in kiosk in the lodge lobby, where residents swipe their photo ID and say which amenity they are going to use.

For what the whole system does, how it is put together, and how the plugins relate to each other, start with the core plugin: **[fsbhoa_ac_core](https://github.com/dkeeney/fsbhoa_ac_core)**. This plugin requires core and does nothing without it.

## What it does

Everyone entering the lobby during business hours is asked to swipe. It isn't required to get in, but nearly everyone does, and the receptionist reminds people who walk past.

1. The resident swipes their MIFARE photo ID on a USB card reader at the lobby touchscreen.
2. The screen shows their photo and name, buttons for the number of guests (0 up to **Max Guests**, 8 by default), and an icon for each amenity.
3. The resident touches the amenity they are going to. The sign-in is written to the access log and shows on the live monitor, which the receptionist watches.

The kiosk **unlocks nothing**. The lobby is locked outside business hours, so the kiosk has no hours of its own. The log is compiled into usage reports for the board of directors, so they can see how much each amenity is used.

The amenity is the resident's stated intent, not proof of where they went: most amenities have no gate of their own. When the resident later swipes at an inner gate for the same amenity, core's access service lets that swipe take over the amenity and guest count.

A resident without their card can be signed in by the receptionist with **Admin Override** on the cardholder list. Non-residents must come in as a resident's guest; the resident is responsible for their guests and must stay with them.

## How it works

```
Kiosk browser  <──wss :8080──>  fsbhoa_kiosk (Go service)  <──REST fsbhoa/v1/kiosk/* (X-API-KEY)──>  WordPress
(touchscreen,                   serves the kiosk page,                                              access service,
 card reader)                   checks cards, logs sign-ins                                         access log, monitor
```

- The **Go service** `fsbhoa_kiosk` serves the kiosk page (`kiosk/web/`) over HTTPS and runs a websocket at `/ws`. It reads its settings from `/var/lib/fsbhoa/kiosk.json`, which WordPress writes when the Kiosk Settings are saved.
- The **REST endpoints** (`includes/class-fsbhoa-kiosk-rest-api.php`) give the service the kiosk's settings and amenities, look up stations, check cards and log sign-ins. They require core's **Access Verification API Key** in the `X-API-KEY` header.
- **The browser can't choose what gets logged.** It never sends a station or card number with a sign-in. The service logs only the card it checked on that connection, and only once. WordPress checks the card again before logging.
- Sign-ins, and rejected cards, are logged through core's access service, so they appear on the live monitor and in reports like any gate event.

### Stations

Each kiosk browser is a **station**: a door with the role `KIOSK` on the virtual controller `900000`, managed on the **Kiosk Stations** page.

- The service identifies a station by the browser's **IP address**. A browser at an unregistered address gets a "not registered" screen showing its IP, and is checked again every 30 seconds.
- Each station needs a fixed address (a static IP or a DHCP reservation), and the browser must connect directly, with no proxy or NAT in between.
- **Slot 0 is the Admin Override station.** It is created on first use and has no IP address, so no browser can connect as it. Real stations are numbered from 1.
- In practice there is one station: the lobby touchscreen.

### The card reader

The reader is a **FisaiD M303** USB MIFARE reader in keyboard mode, set to "2hex->3dec + 4hex->5dec", so each card types an **8-digit number with leading zeros**. The kiosk page reads the keystrokes in a hidden field; a pause of 50 ms ends a card.

When several cards are near it, the reader reads only one: the card **furthest from the reader**, even if that card isn't valid. An RFID-blocking wallet or sleeve stops the reading. What to tell residents: take the photo ID out of the wallet to swipe.

To stop card numbers being guessed in bulk, each connection may look up at most 5 cards per scan and 10 cards a minute; after that the screen says "Too many attempts".

### The kiosk screen

- **Rejected cards:** the screen says only "Card not found". The access log and the monitor get the full reason (for example, an expired badge or an archived cardholder).
- **Inactivity:** if the resident doesn't choose within the **Inactivity Timeout** (30 seconds by default), they are signed in to the **Inactivity Amenity**. If that is set to None, nothing is recorded.
- **Settings changes** (amenities, Max Guests, the logo and the rest) reach open kiosk screens within about 10 seconds, with no restart. The port, certificates and log file still need a service restart.
- **Logo gestures:** tapping the logo three times reloads the page; holding it for 2 seconds shows or hides an on-screen debug log.

### Admin Override

The cardholder list has a kiosk icon for each cardholder (through core's `fsbhoa_cardholder_list_action_icons` hook). It opens a dialog with the same guest buttons and amenity icons, and records the sign-in at the Admin Override station. It doesn't involve the Go service or the kiosk page. Only administrators (`manage_options`) can use it.

### Using a browser elsewhere

The kiosk page works in any web browser at the server's address on port 8080 (`https://access.fsbhoa.com:8080/` in production), as long as the browser's IP address is registered as a station. For testing, a PC connected over the VPN can be registered on the testbed.

## Testbed and production

The service connects only to its own server's WordPress, taken from core's **WordPress API Host** setting. If that setting is blank, the plugin keeps the existing `kiosk.json` rather than guess a host, and logs why, so a testbed can never be pointed at production.

A testbed refresh copies `ac_doors` from production, which replaces the testbed's station IP addresses. Register the testbed stations again after a refresh.

## Requirements

- WordPress with the [fsbhoa_ac_core](https://github.com/dkeeney/fsbhoa_ac_core) plugin active, with the **WordPress API Host** and the **Access Verification API Key** set in core's General settings.
- Go (1.18 or later) to build the service.
- A TLS certificate for the server's host name (Let's Encrypt), readable by the service.
- On the kiosk machine: a touchscreen, the FisaiD M303 reader, and Firefox installed at `/opt/firefox/firefox` (not the snap version).

## Installation

1. Put the repository in WordPress's plugin folder, or symlink it there, then activate **FSBHOA Access Control - Kiosk**:
   ```bash
   ln -s /home/pi/fsbhoa_ac_kiosk /var/www/html/wp-content/plugins/fsbhoa_ac_kiosk
   ```
2. Create the systemd unit `/etc/systemd/system/fsbhoa_kiosk.service`:
   ```ini
   [Unit]
   Description=FSBHOA Kiosk Service
   After=network.target

   [Service]
   Type=simple
   User=pi
   Group=www-data
   # The service serves the kiosk page from kiosk/web below this folder
   WorkingDirectory=/home/pi/fsbhoa_ac_kiosk/kiosk
   ExecStart=/usr/local/bin/fsbhoa_kiosk
   Restart=always
   RestartSec=5

   [Install]
   WantedBy=multi-user.target
   ```
   Then `sudo systemctl daemon-reload` and `sudo systemctl enable fsbhoa_kiosk`.
3. Build and install the service. `./rebuild.sh` only builds; `./rebuild.sh install` also copies it to `/usr/local/bin` and restarts it:
   ```bash
   ./rebuild.sh install
   ```
4. In **Kiosk Settings**, set the logo, splash image, service port (8080), Max Guests, Inactivity Timeout and Inactivity Amenity, and save. This writes `/var/lib/fsbhoa/kiosk.json`.
5. On the **Kiosk Stations** page, add a station with the kiosk browser's IP address. To see which address a browser connects from, open the kiosk page in it and read the "not registered" screen, or run `ss -tn state established '( sport = :8080 )'` on the server.
6. On the kiosk machine, run `start_kiosk.sh` from the desktop's autostart. It starts Firefox in kiosk mode on the kiosk page when the service is up, closes it when the service is down, and restarts it when the service restarts.

The service logs to journald: `journalctl -u fsbhoa_kiosk`. PHP and JavaScript changes take effect immediately; changes to the Go service need `./rebuild.sh install`.

## Diagnostics

The plugin adds five steps to core's Diagnostics test suite (through the `fsbhoa_regression_test_steps` filter), using core's System test card `22222222`:

1. **Service:** the kiosk service answers on its port.
2. **Config:** `/kiosk/config` returns the amenities and settings.
3. **Card check:** the test card is accepted.
4. **Sign-in:** a sign-in through the REST API succeeds.
5. **Logged:** the sign-in was written to the access log at a kiosk station.

## License

GPL v2 or later, the same as [fsbhoa_ac_core](https://github.com/dkeeney/fsbhoa_ac_core).
