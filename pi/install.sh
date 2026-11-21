#!/bin/bash
# install.sh - set up a Raspberry Pi (Raspberry Pi OS Lite) as the FSBHOA lobby kiosk.
# Run on the Pi from this folder:  sudo ./install.sh
# Safe to run again (updates the scripts and units; keeps /etc/fsbhoa-kiosk/kiosk.conf).
# Does NOT switch the SD card to read-only; do that last with: sudo kiosk-mode readonly
set -e
[ "$EUID" -eq 0 ] || { echo "Run with sudo."; exit 1; }
if grep -q overlayroot=tmpfs /proc/cmdline; then
    echo "The SD card is read-only now; anything installed would be lost at reboot."
    echo "First: sudo kiosk-mode writable   (reboots), then run this again."
    exit 1
fi
SRC="$(cd "$(dirname "$0")" && pwd)"

echo "--- packages"
apt-get update -q
DEBIAN_FRONTEND=noninteractive apt-get install -y -q cage chromium python3-evdev jq curl fonts-dejavu-core overlayroot uhubctl rsyslog

echo "--- scripts and units"
for f in kiosk-browser kiosk-swipe-watch kiosk-watchdog kiosk-notify kiosk-mode kiosk-update; do
    install -m 755 -o root -g root "$SRC/$f" /usr/local/bin/
done
for u in kiosk-browser.service kiosk-swipe-watch.service kiosk-watchdog.service kiosk-watchdog.timer \
         kiosk-update.service kiosk-update.timer kiosk-update-boot.service; do
    install -m 644 -o root -g root "$SRC/$u" /etc/systemd/system/
done

install -m 644 "$SRC/rsyslog-to-nas.conf" /etc/rsyslog.d/00-to-nas.conf   # whole log to the NAS, nothing local
systemctl restart rsyslog

install -m 644 "$SRC/90-kiosk-no-pointer.rules" /etc/udev/rules.d/   # no mouse devices for cage
udevadm control --reload
udevadm trigger --subsystem-match=input --action=change
install -d /usr/local/share/fsbhoa-kiosk/nocursor        # Chromium add-on: hide the pointer
install -m 644 "$SRC/nocursor/manifest.json" "$SRC/nocursor/nocursor.css" /usr/local/share/fsbhoa-kiosk/nocursor/

mkdir -p /etc/fsbhoa-kiosk
if [ ! -f /etc/fsbhoa-kiosk/kiosk.conf ]; then
    install -m 644 "$SRC/kiosk.conf.example" /etc/fsbhoa-kiosk/kiosk.conf
    echo "Created /etc/fsbhoa-kiosk/kiosk.conf (testbed URL). Edit KIOSK_URL as needed."
fi
[ -f /etc/fsbhoa-kiosk/discord.url ] && chmod 600 /etc/fsbhoa-kiosk/discord.url

echo "--- services"
systemctl daemon-reload
systemctl disable --now getty@tty1.service 2>/dev/null || true
systemctl enable --now kiosk-swipe-watch.service kiosk-watchdog.timer kiosk-update.timer
systemctl enable kiosk-browser.service kiosk-update-boot.service
systemctl restart kiosk-browser.service
systemctl --no-pager --lines=0 status kiosk-browser kiosk-swipe-watch kiosk-watchdog.timer kiosk-update.timer || true
echo "Done. Check: sudo kiosk-watchdog --check ; kiosk-mode"
