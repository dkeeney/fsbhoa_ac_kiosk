This plugin extends fsbhoa_ac_core.  @/home/pi/fsbhoa_ac_core/other_docs/ARCHITECTURE.md

## Purpose

This plugin implements a web-based kiosk for the lodge.

- Cardholders who enter the lodge during business hours swipe their MIFARE RFID photo ID on a card reader attached by USB to the access control machine.
- The kiosk runs on a touchscreen display that is locked to this kiosk.
- After swiping, the cardholder enters the number of guests and touches the icon for the amenity they are going to visit.

## Access

The kiosk also works in any web browser at:

- Production: `access.fsbhoa.com:8080`
- Testbed: `testbed.fsbhoa.com:8080`
