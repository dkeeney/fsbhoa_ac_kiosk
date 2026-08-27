#!/bin/bash
# rebuild.sh
# Purpose: Compile this service with strict fsbhoa_xxxx naming.
# Usage: ./rebuild.sh       (Builds only)
#        ./rebuild.sh install (Builds, Installs to /usr/local/bin, and Restarts services)

BASE_DIR="$(pwd)"
echo "--- Starting Build Process ---"

# --- 2. KIOSK SERVICE ---
echo "2. Building Kiosk Service..."
cd "$BASE_DIR/kiosk" || exit
go build -o fsbhoa_kiosk .
if [ $? -eq 0 ]; then echo "   [OK] fsbhoa_kiosk built"; else echo "   [FAIL] Kiosk build failed"; exit 1; fi


# --- OPTIONAL: INSTALL ---
if [ "$1" == "install" ]; then
    echo ""
    echo "--- Installing and Restarting Services (Sudo Required) ---"
    
    # 1. STOP services to release the file lock
    echo "Stopping services..."
    sudo systemctl stop fsbhoa_kiosk
    
    # 2. COPY new binaries
    echo "Copying binaries..."
    sudo cp "$BASE_DIR/kiosk/fsbhoa_kiosk" /usr/local/bin/
    
    # 3. SET PERMISSIONS (Root ownership is safer for system binaries)
    echo "Setting permissions..."
    sudo chown root:root /usr/local/bin/fsbhoa_*
    sudo chmod 755 /usr/local/bin/fsbhoa_*
    
    # 4. START services
    echo "Starting Systemd Services..."
    sudo systemctl start fsbhoa_kiosk
    
    echo "--- Install Complete ---"
    # Brief pause to let them startup before checking status
    sleep 1
    sudo systemctl status fsbhoa_* --no-pager | grep "Active:"
else
    echo ""
    echo "--- Build Complete (No Install) ---"
    echo "To install and restart services, run: ./rebuild.sh install"
fi
