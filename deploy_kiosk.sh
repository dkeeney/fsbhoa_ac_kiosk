#!/bin/bash

# deploy_kiosk.sh
# CONFIGURATION: Compiles and deploys the Kiosk Go service and its WordPress plugin.

INSTALL_BIN="/usr/local/bin"
INSTALL_ASSETS="/var/lib/fsbhoa"
PLUGIN_DIR="/var/www/html/wp-content/plugins/fsbhoa_ac_kiosk"
REPO_DIR="/home/pi/fsbhoa_ac_kiosk"

echo "--- Starting Kiosk Deployment ---"

# 1. BUILD KIOSK SERVICE
echo "[1/2] Building Kiosk Binary..."
cd "$REPO_DIR/kiosk" || exit 1
go build -o fsbhoa_kiosk main.go
sudo systemctl stop fsbhoa_kiosk
sudo cp fsbhoa_kiosk "$INSTALL_BIN/"
sudo chmod +x "$INSTALL_BIN/fsbhoa_kiosk"

# Deploy Kiosk Web Assets
echo "    Deploying Web Assets..."
sudo mkdir -p "$INSTALL_ASSETS/kiosk/web"
sudo cp -r web/* "$INSTALL_ASSETS/kiosk/web/"
sudo chown -R root:www-data "$INSTALL_ASSETS/kiosk"
sudo chmod -R 2775 "$INSTALL_ASSETS/kiosk"
cd "$REPO_DIR" || exit 1

# 2. DEPLOY KIOSK WORDPRESS PLUGIN
echo "[2/2] Deploying Kiosk WordPress Plugin..."
sudo mkdir -p "$PLUGIN_DIR"
sudo rsync -av --delete "$REPO_DIR/" "$PLUGIN_DIR/" --exclude '.git' --exclude 'deploy_kiosk.sh' --exclude 'kiosk'
sudo chown -R www-data:www-data "$PLUGIN_DIR"

echo "--- Restarting Kiosk Service ---"
sudo systemctl daemon-reload
sudo systemctl start fsbhoa_kiosk

echo "Kiosk Deployment Complete."
echo "Check status: sudo systemctl status fsbhoa_kiosk"

