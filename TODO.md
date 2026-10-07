# Kiosk TODO

## Problems

- [ ] **1. Open websocket on port 8080 (security).** No auth and `CheckOrigin` accepts any site (`kiosk/main.go:67`). Any browser that reaches 8080 can send `manualSwipe` to look up names and photos, or `amenitySelected` to log fake sign-ins. `log_signin_callback` records `access_granted = 1` without re-checking the card.
  - **Decision (2026-10-07): identify stations by IP address.** Add an IP field to each station on the Kiosk Stations page. The Go service looks up the station from the connection's source address (`r.RemoteAddr`, converting `::ffff:` IPv4-mapped addresses to plain IPv4) and refuses unknown addresses. This replaces the setup screen and `?auto_name=`.
  - Also: remember the validated card per connection and log that card, not the one the browser sends. Re-check the card in WordPress before logging. Rate-limit swipes per connection.
  - Each station needs a fixed address (static IP or DHCP reservation). Browsers must connect directly, with no proxy or NAT in between.
  - Home testing works over the pfSense VPN: routed, per-user addresses (e.g. `192.168.70.3`). Add home test stations on the testbed only.
  - A pairing token could be added later if IP addresses aren't enough.
- [x] **2. Direct load always fails.** `get_cardholder_by_id_callback` reads `cred.card_expiry_date`, which core's migration renamed to `expiration_date` (`includes/class-fsbhoa-kiosk-rest-api.php:267`).
  - Direct load is the cardholder list's "Sign-in at Kiosk" icon, an admin override for signing in a cardholder without a swipe. It opens `kiosk_url/?cardholder_id=N&door_number=255` in a new tab (`fsbhoa_ac_core/assets/js/fsbhoa-cardholder-admin.js:359`). With IP-based stations (#1) that tab would be refused.
  - **Decision (2026-10-07): move the override into WordPress.**
    - [x] Clicking the icon opens a dialog on the cardholder list with guest-count buttons and amenity icons.
    - [x] Choosing an amenity sends an admin-only, nonce-checked AJAX request that calls `Fsbhoa_Access_Service::process_and_write_event` directly. No Go service or kiosk page involved.
    - [x] Record it under an "Admin Override" station (a door on virtual controller `900000`) so reports and the monitor show it correctly.
    - [x] The kiosk plugin supplies the icon, dialog and AJAX handler through the core hook in #13.
    - [x] Remove the `?cardholder_id=` path from the kiosk: `handleDirectLoad` and `startSessionById` in `kiosk/web/app.js`, `startSessionById` and `validateCardholderByID` in `kiosk/main.go`, and the `/kiosk/cardholder/{id}` endpoint. This also closes #3 and #7.
    - Code done 2026-10-07 (`includes/class-fsbhoa-kiosk-admin-override.php`, `assets/js/fsbhoa-kiosk-admin-override.js`, `assets/css/fsbhoa-kiosk-admin-override.css`). The station is slot 0 on the virtual controller, created on first use.
    - [x] Test the dialog in the browser on the testbed. Passed 2026-10-07: logged as `900000` slot 0, "Amenity: Library"; shown on the monitor and in the access log.
    - [x] Install the rebuilt Go service (`./rebuild.sh install`). Done 2026-10-07.
    - [ ] Follow-up: the Admin Override station has `door_role = 'KIOSK'`, so it appears in the kiosk setup screen's gate list until #1 replaces that screen. Usage analytics also label every `900000` event as "Lobby Kiosk" (see #13).
- [x] **3. Kiosk hangs when direct load fails.** _Goes away with #2._ The `startSessionById` error branch builds a failure message but never sends it (`kiosk/main.go:466`).
- [ ] **4. Testbed can point at production.** `update_kiosk_service_config` falls back to `access.fsbhoa.com` when `fsbhoa_ac_wp_host` is missing. Should fail closed per `ARCHITECTURE.md`.
- [ ] **5. API key written to the log** at startup (`kiosk/main.go:86`).
- [ ] **6. No expiration date counts as expired.** `strtotime(null) < time()` is true; `expiration_date` can be `NULL`.
- [x] **7. Direct-load sign-ins use serial `"000000"`** instead of the virtual controller `900000` (`kiosk/web/app.js:96`). _Goes away with #2._
- [ ] **8. Failed swipes logged inconsistently.** Written directly to `ac_access_log` as controller `'kiosk'`, door 0, bypassing `Fsbhoa_Access_Service`.
- [ ] **9. Inactivity timeout signs in to hardcoded `'Lobby'`** (`kiosk/web/app.js:417`), which may not exist in `ac_amenities`.
- [ ] **10. Amenity and max-guest changes need a service restart.** The Go service fetches config only at startup.
- [ ] **11. `deploy_kiosk.sh` conflicts with the symlink setup.** It rsyncs the repo onto itself, runs `chown -R www-data` on the repo, and copies `web/` to an unused location. Fix or remove; `rebuild.sh` matches reality.
- [ ] **12. Housekeeping**
  - [ ] Remove the committed binary `kiosk/fsbhoa_kiosk` and add a `.gitignore`.
  - [ ] Whitelist option names in `ajax_save_kiosk_settings`.
  - [ ] Stop `ensure_virtual_kiosk_controller` running queries on every request.
  - [ ] Remove or wire up the `fsbhoa_kiosk_name` setting. (`handle_delete_kiosk_station` was removed in #14.)
- [ ] **13. Core references the kiosk plugin (breaks core rule 1).** Changes go in `fsbhoa_ac_core`.
  - [x] Add a hook in the cardholder list's actions column, e.g. `do_action( 'fsbhoa_cardholder_list_action_icons', $cardholder )` (`includes/cardholder/views/view-cardholder-list.php`).
  - [x] Remove the hardcoded kiosk icon (`view-cardholder-list.php:130`), the `kiosk_url` built from `fsbhoa_kiosk_port` (`includes/class-fsbhoa-shortcodes.php:104`), and the `.fsbhoa-kiosk-signin-link` click handler (`assets/js/fsbhoa-cardholder-admin.js:355`).
  - Done 2026-10-07. Also added `do_action( 'fsbhoa_enqueue_cardholder_assets' )` in `class-fsbhoa-shortcodes.php` so extensions can load assets on the cardholder page, and listed both hooks in `ARCHITECTURE.md`.
  - [ ] Check core's other kiosk references: the `fsbhoa_kiosk_name` fallback in `includes/monitor/class-fsbhoa-monitor-rest-api.php`, `'Lobby Kiosk'` in `includes/reports/class-fsbhoa-reports-rest-api.php`, the kiosk test in `includes/admin/class-fsbhoa-test-suite-actions.php`, and `fsbhoa_ac_kiosk_api_key` used by `includes/api/class-fsbhoa-verification-rest-api.php`.
- [x] **14. The plugin assumes only one `VIRTUAL_KIOSK` controller.** The testbed has two: `900000` (kiosk) and `900001` ("QR Scan", used by `fsbhoa_light` for court gates). Code that finds the controller with `WHERE type = 'VIRTUAL_KIOSK' LIMIT 1` can pick the wrong one.
  - [x] Admin Override station lookup now matches serial `900000` (2026-10-07).
  - [x] `ensure_virtual_kiosk_controller` and the Add Station form in `class-fsbhoa-kiosk-stations-page.php` use `LIMIT 1` on the type.
  - [x] The Kiosk Stations list shows every door on any `VIRTUAL_KIOSK` controller, so the QR court gates appear as kiosk stations and can be edited or deleted there.
  - [x] `handle_delete_kiosk_station` deletes any `VIRTUAL_KIOSK` controller (and cascades its doors), including `900001`.
  - [x] Share one serial constant across the plugin instead of repeating `900000`.
  - Done 2026-10-07. `Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL` is used by the controller seeding, the Stations page, the Admin Override and the REST default. The Stations page's add, edit, delete and list now only touch doors on that controller. `handle_delete_kiosk_station` was unused and has been removed. The `'900000'` fallbacks in `kiosk/web/app.js` remain until #1 replaces the station identity code.
- [ ] **15. (Low priority) Check how `fsbhoa_light` manages QR code stations.** When it adds or deletes a QR station, make sure the station is created or removed as a door on its virtual controller (`900001`), so QR scans are logged as access control events like any other gate. Work is in `/home/pi/fsbhoa_light` (e.g. `lighting-service/remote_client.go`, `includes/admin-settings.php`).

## Document in CLAUDE.md

- [ ] Architecture: Go service on 8080, websocket events, the `fsbhoa/v1/kiosk/*` endpoints, `kiosk.json`.
- [ ] Station identity: `?auto_name=`, setup screen and localStorage, `?reset_kiosk`, the Kiosk Stations page and virtual controller `900000`.
- [ ] Card reader: USB keyboard input, last 8 digits, 400 ms wallet scan.
- [ ] Kiosk machine setup: `start_kiosk.sh`, Firefox kiosk mode, restart signal file, logo gestures.
- [ ] Admin Override: the cardholder list icon opens a WordPress dialog that records the sign-in at the "Admin Override" station (slot 0).
- [ ] Which build/deploy script is canonical.
- [ ] Business rules: business hours, guest limits, inactivity sign-in, who uses the browser URL.
