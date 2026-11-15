#!/bin/bash
# build.sh (Kiosk): builds the kiosk service (fsbhoa_kiosk).
# Usage: ./build.sh           build only
#        ./build.sh install   also install the binaries in /usr/local/bin and the systemd units
#                             (systemd/), and restart the services that changed
# Runs from any folder. The services run as the user who runs it, with group www-data.

# binary | source folder | systemd unit (blank: not a service) | config file the service needs
SERVICES=(
    "fsbhoa_kiosk|kiosk|fsbhoa_kiosk|/var/lib/fsbhoa/kiosk.json"
)

# ---------------------------------------------------------------------------------------
# The same in every service plugin (core, kiosk, uhppote, vehicle, zebra); only the
# SERVICES list above differs. deploy-production.sh in core runs each one's "build.sh install".
# ---------------------------------------------------------------------------------------
set -euo pipefail
BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BIN_DIR=/usr/local/bin
UNIT_DIR=/etc/systemd/system
RUN_USER="$(id -un)"   # the services run as the user who deploys them, group www-data

if [ "$(id -u)" -eq 0 ]; then
    echo "Run build.sh as the user the services run as (it uses sudo itself), not as root."
    exit 1
fi
MODE="${1:-}"
if [ -n "$MODE" ] && [ "$MODE" != install ]; then
    echo "Usage: $0 [install]"
    exit 1
fi

echo "--- $(basename "$BASE_DIR"): building with $(go version | awk '{print $3}') ---"
for s in "${SERVICES[@]}"; do
    IFS='|' read -r bin src unit conf <<< "$s"
    if ( cd "$BASE_DIR/$src" && go build -o "$bin" . ); then
        echo "   [OK] $bin built"
    else
        echo "   [FAIL] $bin did not build"
        exit 1
    fi
done
if [ "$MODE" != install ]; then
    echo "Built only. To install and restart: $0 install"
    exit 0
fi

echo "--- Installing (sudo) ---"
reload=0
restart=()
for s in "${SERVICES[@]}"; do
    IFS='|' read -r bin src unit conf <<< "$s"
    changed=0
    # Binary: keep the one it replaces as .bak (for going back), then rename the new one into
    # place, so a running service keeps its old file until the restart.
    if ! cmp -s "$BASE_DIR/$src/$bin" "$BIN_DIR/$bin"; then
        if [ -f "$BIN_DIR/$bin" ]; then
            sudo cp -p "$BIN_DIR/$bin" "$BIN_DIR/$bin.bak"
        fi
        sudo install -o root -g root -m 755 "$BASE_DIR/$src/$bin" "$BIN_DIR/$bin.new"
        sudo mv -f "$BIN_DIR/$bin.new" "$BIN_DIR/$bin"
        echo "   [BIN] $bin installed (the previous one is $bin.bak)"
        changed=1
    fi
    [ -n "$unit" ] || continue
    # Unit: systemd/<unit>.service with this server's user and plugin folder filled in
    tmp="$(mktemp)"
    sed -e "s|@USER@|$RUN_USER|g" -e "s|@PLUGIN_DIR@|$BASE_DIR|g" "$BASE_DIR/systemd/$unit.service" > "$tmp"
    if ! cmp -s "$tmp" "$UNIT_DIR/$unit.service"; then
        sudo install -o root -g root -m 644 "$tmp" "$UNIT_DIR/$unit.service"
        echo "   [UNIT] $unit.service installed"
        reload=1
        changed=1
    fi
    rm -f "$tmp"
    if ! systemctl is-enabled --quiet "$unit" 2>/dev/null; then
        restart+=("$unit|$conf|enable")
    elif [ "$changed" = 1 ] || ! systemctl is-active --quiet "$unit"; then
        restart+=("$unit|$conf|")
    else
        echo "   [SAME] $unit unchanged and running"
    fi
done
if [ "$reload" = 1 ]; then
    sudo systemctl daemon-reload
fi
for r in "${restart[@]+"${restart[@]}"}"; do
    IFS='|' read -r unit conf enable <<< "$r"
    if [ -n "$enable" ]; then
        sudo systemctl enable --quiet "$unit"
    fi
    # The settings pages write the configs; without one the service can't start
    if [ -n "$conf" ] && [ ! -f "$conf" ]; then
        echo "   [WAIT] $unit not started: $conf is missing. Save the plugin's settings page, then: sudo systemctl restart $unit"
        continue
    fi
    sudo systemctl restart "$unit"
    echo "   [RESTART] $unit"
done
sleep 1
for s in "${SERVICES[@]}"; do
    IFS='|' read -r bin src unit conf <<< "$s"
    [ -n "$unit" ] || continue
    printf '   %-16s %s\n' "$unit" "$(systemctl is-active "$unit" 2>/dev/null || true)"
done
