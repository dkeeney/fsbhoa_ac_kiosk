# Lobby kiosk on a dedicated Raspberry Pi

The lobby touchscreen and card reader run on their own Raspberry Pi 5 instead of the desktop of
the access control server. Why: both lobby outages (2026-10-29, 2026-11-01) were Ubuntu pop-ups
on the server's desktop taking the card reader's keystrokes. The Pi shows nothing but the kiosk
page, and its SD card is read-only.

The Pi is only a browser. The kiosk service, stations and sign-ins stay on the server.

## The Pi (as built 2026-11-18)

| | |
|---|---|
| Hardware | Raspberry Pi 5, 8 GB, 32 GB SD card |
| OS | Raspberry Pi OS Lite 64-bit (Debian 13 trixie), no desktop |
| Name / address | `kiosk`, 192.168.42.64 (VLAN 42, DHCP reservation in pfSense) |
| Login | `pi`, SSH keys only (home, IT_PC, Boardroom, testbed); passwordless sudo |
| Screen | HDMI; Chromium full screen under `cage` on tty1 |
| Reader | FisaiD M303, USB 413d:2107 (keyboard mode) |

## What runs

| Unit | What it does |
|---|---|
| `kiosk-browser.service` | `cage` (one full-screen app, no desktop, no console switching) running `kiosk-browser`: waits until the page answers, then Chromium in kiosk mode with a fresh profile. Restarts if it exits. |
| `kiosk-swipe-watch.service` | Reads the reader's key events without grabbing them; writes the time of the last swipe to `/run/fsbhoa-kiosk/last-swipe`. Never logs card numbers. |
| `kiosk-watchdog.timer` (every minute) | Server up but no websocket from the page for 3 runs: restart the browser. Reader missing: notice. 7:00-21:00 and no swipe for 10 min (IDLE_MIN; was 30 until 2026-11-19, when a hung reader sat dead 8 min): power-cycle the reader's USB port (a hung reader looks connected). |
| `kiosk-update.timer` (03:00) | If OS updates are waiting: leave `/boot/firmware/kiosk-update-pending`, switch read-only off, reboot. |
| `kiosk-update-boot.service` | At boot with that note: remove it, install the updates, switch read-only back on, reboot. About 5 minutes, only on nights with updates. |

Discord notices (plain, no @everyone) go to core's Access Control channel through `kiosk-notify`.
The webhook URL is in `/etc/fsbhoa-kiosk/discord.url` (root only; never in git or on the NAS).

## Settings: `/etc/fsbhoa-kiosk/kiosk.conf`

`KIOSK_URL` is the page:

| Server | URL | Station is picked by |
|---|---|---|
| Testbed (V2) | `https://testbed.fsbhoa.com:8080/` | the Pi's IP (station "Lobby Pi", slot 2) |
| Production V1 | `https://access.fsbhoa.com:8080/?auto_name=LobbyKiosk` | `auto_name` |
| Production V2 | `https://access.fsbhoa.com:8080/` | the Pi's IP: register 192.168.42.64 on Kiosk Stations first |

Also: `SCANNER_ID`, open hours and idle minutes for the reader reset, `NOCONN_RUNS`, `KIOSK_NAME`.

## Read-only SD card

Raspberry Pi OS's overlay (`overlayroot=tmpfs` in `/boot/firmware/cmdline.txt`). Changes while
read-only live in memory and are gone at the next reboot. The boot partition stays writable.

```bash
kiosk-mode                   # mode now and after the next boot
sudo kiosk-mode writable     # reboots writable: change settings, run install.sh
sudo kiosk-mode readonly     # reboots read-only again
```

If the Pi is left writable, the nightly job skips updates and sends a notice.

## Install or update

From a checkout of this repo on the Pi (or copy this folder to it), with the SD card writable:

```bash
sudo ./install.sh            # packages, scripts, units; keeps kiosk.conf
sudo kiosk-mode readonly     # last
```

## Checks

```bash
sudo kiosk-watchdog --check            # page connected? reader OK? last swipe?
sudo kiosk-watchdog --reset-scanner    # power-cycle the reader now
journalctl -t kiosk-watchdog -t kiosk-update -t kiosk-notify
journalctl -u kiosk-browser -u kiosk-swipe-watch
```

The journal is kept only for the current boot while read-only.

## In the lobby (2026-11-18)

Live on production V1 since 2026-11-18. Password SSH login is off
(`/etc/ssh/sshd_config.d/10-kiosk-keys-only.conf`).

- **Screen:** the lobby touchscreen ("RTK", 1920x1080 at 60 Hz) on HDMI-A-2 through a micro-HDMI
  adapter. No EDID comes through the adapter, so the Pi fell back to 1024x768. Fixed by adding
  `video=HDMI-A-1:1920x1080@60 video=HDMI-A-2:1920x1080@60` to `/boot/firmware/cmdline.txt`
  (one line; the boot partition is writable even in read-only mode).
- **Touch:** wch.cn TouchScreen (USB 27c0:0859) works with no setup under cage. It is powered
  through the screen: when the screen restarts or its cables are replugged, the touch controller
  drops off USB and comes back (cage picks it up again). If touch is dead, check `lsusb` for
  27c0:0859 and reseat the screen's USB cable.
- **Mouse pointer:** cage draws a pointer in the middle of the screen whenever any input device
  claims to be a mouse. Four do: the touchscreen's mouse-emulation interface, **the card reader's
  second (mouse) interface**, and the two HDMI CEC inputs ("pointing stick"). Fixed by
  `90-kiosk-no-pointer.rules` (udev, `LIBINPUT_IGNORE_DEVICE` for every mouse / pointing-stick
  device; check with `ls -l /proc/$(pgrep -x cage)/fd | grep input`). Belt and braces: a local
  Chromium add-on, `nocursor/` (`/usr/local/share/fsbhoa-kiosk/nocursor`, `--load-extension`),
  sets `cursor: none` on every page; add-ons don't run in incognito, so no `--incognito` (the
  profile is still wiped at every start). A blank cursor theme did not help.
  A USB mouse plugged in for maintenance is ignored too.
- **access:** its own kiosk browser is stopped and its desktop autostart
  (`~/.config/autostart/start_kiosk.sh.desktop`) is set to `X-GNOME-Autostart-enabled=false`.
  To go back: move the screen and reader to access, set it to `true`, and reboot access or run
  `~/fsbhoa_ac/start_kiosk.sh` from its desktop.
- **V2 cutover:** register 192.168.42.64 on the Kiosk Stations page, then `sudo kiosk-mode writable`,
  set `KIOSK_URL="https://access.fsbhoa.com:8080/"`, `sudo kiosk-mode readonly`.
