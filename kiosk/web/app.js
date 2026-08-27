const statusMessage = document.getElementById('status-message');
const lastCardSwipeDiv = document.getElementById('last-card-swipe');
const amenityButtonsDiv = document.getElementById('amenity-buttons');
const idleScreen = document.getElementById('idle-screen');
const logoImage = document.getElementById('logo-image');
const cardDisplay = document.getElementById('card-display');
const cardPhoto = document.getElementById('card-photo');
const cardName = document.getElementById('card-name');
const cardReaderInput = document.getElementById('card-reader-input');
const amenityButtonsContainer = document.getElementById('amenity-buttons-container');
const guestButtonsContainer = document.getElementById('guest-buttons-container');
const guestButtonsDiv = document.getElementById('guest-buttons');
const splashScreen = document.getElementById('splash-screen');
const splashImage = document.getElementById('splash-image');
const AMENITY_SELECTION_TIMEOUT = 30000;

let idleTimeout;
let sessionActive = false;
let idleTimerFired = false;
let resetKioskTimer = null;
let selectedGuestCount = null;
let rfidTimeout;
let kioskConfig = {};
let lastSwipedCard = null;
let socket = null;
let audioCtx;
let hasConnectedBefore = false;
let isDirectLoad = false;
let overrideDoorNumber = null;
let rfidBuffer = "";

// Gesture Variables
let logoTapCount = 0;
let logoTapTimer = null;
let logoLongPressTimer = null;

let kioskIdentity = null;

async function initializeKiosk() {
    const urlParams = new URLSearchParams(window.location.search);
    logToScreen("Initializing Kiosk Logic...");

    if (urlParams.has('v')) {
        logToScreen(`SYSTEM: Hard Refresh detected. Build: ${urlParams.get('v')}`);
    }

    if (urlParams.has('cardholder_id') && urlParams.has('door_number')) {
        logToScreen("Step 1: Direct Load parameters detected.");
        await handleDirectLoad(urlParams);
        return;
    }

    if (urlParams.has('reset_kiosk')) {
        logToScreen("Step 2: Reset command detected.");
        localStorage.removeItem('fsbhoa_kiosk_identity');
        alert("Kiosk Configuration Cleared. Reloading...");
        window.location.href = window.location.pathname;
        return;
    }

    if (urlParams.has('auto_name')) {
        logToScreen("Step 3: Auto-Config detected.");
        const success = await handleAutoConfig(urlParams.get('auto_name'));
        if (success) {
            connect();
            return;
        }
    }

    const stored = localStorage.getItem('fsbhoa_kiosk_identity');
    if (stored) {
        logToScreen("Step 4: Found existing identity in storage.");
        try {
            kioskIdentity = JSON.parse(stored);
            logToScreen(`Identity Loaded: ${kioskIdentity.name} (ID: ${kioskIdentity.id})`);
            document.getElementById('setup-screen').style.display = 'none';
            document.getElementById('idle-screen').style.display = 'block';
            connect();
            return;
        } catch (e) {
            logToScreen("Error: Storage corrupted. Clearing. " + e.message);
            localStorage.removeItem('fsbhoa_kiosk_identity');
        }
    }

    logToScreen("Step 5: No identity found. Showing setup screen.");
    showSetupScreen();
}

async function handleDirectLoad(params) {
    const doorNum = params.get('door_number');
    const cardId = params.get('cardholder_id');
    kioskIdentity = {
        id: parseInt(doorNum, 10),
        name: "Direct Load Session",
        serial: "000000",
        door: 1
    };
    overrideDoorNumber = kioskIdentity.id;
    logToScreen(`Direct Load: Cardholder ${cardId} at Gate ${doorNum}`);
    document.getElementById('setup-screen').style.display = 'none';
    document.getElementById('idle-screen').style.display = 'block';
    connect();
}

async function handleAutoConfig(gateName) {
    try {
        logToScreen(`Attempting Auto-Config for Gate: '${gateName}'...`);
        const doors = await fetchGateList();
        const match = doors.find(d => d.friendly_name === gateName);
        if (match) {
            saveIdentityToStorage(match);
            logToScreen(`Auto-Config Success. ID derived: ${match.door_record_id}`);
            window.history.replaceState({}, document.title, window.location.pathname);
            return true;
        } else {
            logToScreen(`Error: No gate found with name '${gateName}'`);
            alert(`Auto-Config Failed: Gate '${gateName}' not found.`);
            return false;
        }
    } catch (err) {
        logToScreen("Auto-Config Network Error: " + err.message);
        return false;
    }
}

function showSetupScreen() {
    document.getElementById('idle-screen').style.display = 'none';
    document.getElementById('setup-screen').style.display = 'block';
    fetchGateList()
        .then(doors => {
            const select = document.getElementById('kiosk-location-select');
            select.innerHTML = '<option value="">-- Select Gate Location --</option>';
            doors.forEach(door => {
                const valObj = {
                    id: door.door_record_id,
                    name: door.friendly_name,
                    serial: door.uhppoted_device_id,
                    door: door.door_number_on_controller
                };
                const opt = document.createElement('option');
                opt.value = JSON.stringify(valObj);
                opt.textContent = door.friendly_name;
                select.appendChild(opt);
            });
        })
        .catch(err => {
            logToScreen("Error fetching gate list: " + err.message);
        });
}

async function fetchGateList() {
    const targetEndpoint = '/fsbhoa/v1/monitor/gates?role=KIOSK';
    const response = await fetch(`/api/proxy?endpoint=${encodeURIComponent(targetEndpoint)}`);
    if (!response.ok) throw new Error(`API Error: ${response.status}`);
    return await response.json();
}

function saveIdentityToStorage(doorRecord) {
    const config = {
        id: doorRecord.door_record_id,
        name: doorRecord.friendly_name,
        serial: doorRecord.uhppoted_device_id,
        door: doorRecord.door_number_on_controller
    };
    localStorage.setItem('fsbhoa_kiosk_identity', JSON.stringify(config));
    kioskIdentity = config;
}

window.saveKioskIdentity = function() {
    const select = document.getElementById('kiosk-location-select');
    if (select.value) {
        localStorage.setItem('fsbhoa_kiosk_identity', select.value);
        location.reload();
    } else {
        alert("Please select a location.");
    }
};

function logToScreen(message) {
    console.log(message);
    const logContainer = document.getElementById('debug-log');
    if (logContainer) {
        const timestamp = new Date().toLocaleTimeString();
        const newMessage = document.createElement('div');
        newMessage.textContent = `${timestamp}: ${message}`;
        logContainer.appendChild(newMessage);
        while (logContainer.childNodes.length > 200) {
            logContainer.removeChild(logContainer.firstChild);
        }
        logContainer.scrollTop = logContainer.scrollHeight;
    }
}

function beep(count, volume, duration) {
    if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    for (let i = 0; i < count; i++) {
        setTimeout(() => {
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            gainNode.gain.value = volume;
            oscillator.frequency.value = 880;
            oscillator.type = 'sine';
            oscillator.start(audioCtx.currentTime);
            oscillator.stop(audioCtx.currentTime + (duration / 1000));
        }, i * (duration + 100));
    }
}

function connect() {
    let doorParam = '';
    if (kioskIdentity && kioskIdentity.id) {
        doorParam = `?doorId=${kioskIdentity.id}`;
    }
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const socketURL = `${protocol}//${window.location.host}/ws${doorParam}`;

    logToScreen(`Connecting to WebSocket at: ${socketURL}`);
    socket = new WebSocket(socketURL);

    socket.addEventListener('open', () => {
        if (!hasConnectedBefore) {
            hasConnectedBefore = true;
            resetKiosk();
        }
        const urlParams = new URLSearchParams(window.location.search);
        const cardholderId = urlParams.get('cardholder_id');
        const doorNumberParam = urlParams.get('door_number');
        const doorNumber = doorNumberParam ? parseInt(doorNumberParam, 10) : 0;

        if (cardholderId) {
            isDirectLoad = true;
            const payload = { id: cardholderId };
            if (overrideDoorNumber !== null) {
                payload.door_number = overrideDoorNumber;
            }
            logToScreen(`Direct load requested for cardholder ID: ${cardholderId}, Door: ${doorNumber}`);
            socket.send(JSON.stringify({
                event: 'startSessionById',
                payload: payload
            }));
        } else {
            isDirectLoad = false;
        }
    });

    socket.addEventListener('message', (event) => {
        try {
            let message = JSON.parse(event.data);
            let loggable = JSON.parse(JSON.stringify(message));
            if (loggable.payload && loggable.payload.cardholder && loggable.payload.cardholder.photo) {
                loggable.payload.cardholder.photo = "[IMAGE DATA TRUNCATED]";
            }
            logToScreen("Received: " + JSON.stringify(loggable));

            if (message.event === 'kioskConfig') {
                kioskConfig = message.payload;
                if (kioskConfig.logo_url) {
                    logoImage.src = kioskConfig.logo_url;
                    logoImage.style.visibility = 'visible';
                    logoImage.style.display = 'block';
                }
                if (kioskConfig.splash_url) {
                    splashImage.src = kioskConfig.splash_url;
                }
            } else if (message.event === 'cardSwiped') {
                const swipeData = message.payload;
                if (swipeData.isValid) {
                    sessionActive = true;
                    lastSwipedCard = swipeData.rfid;
                    stopFocusCapture();
                    idleScreen.style.display = 'none';
                    document.getElementById('main-layout-table').style.display = 'table';
                    statusMessage.textContent = '';
                    cardName.textContent = swipeData.cardholder.name;
                    if (swipeData.cardholder.photo) {
                        cardPhoto.src = `data:image/jpeg;base64,${swipeData.cardholder.photo}`;
                    } else {
                        cardPhoto.src = '';
                    }
                    cardDisplay.className = 'card-display-visible';
                    selectedGuestCount = 0;
                    guestButtonsContainer.style.display = 'block';
                    amenityButtonsContainer.style.display = 'block';
                    createGuestButtons();
                    createAmenityButtons(kioskConfig.amenities);
                    const zeroButton = document.querySelector('.guest-button[data-count="0"]');
                    if(zeroButton) zeroButton.classList.add('selected');
                    idleTimerFired = false;
                    clearTimeout(idleTimeout);
                    idleTimeout = setTimeout(handleIdleTimeout, AMENITY_SELECTION_TIMEOUT);
                } else {
                    statusMessage.textContent = swipeData.message;
                    statusMessage.style.color = 'red';
                    beep(2, 0.1, 150);
                    setTimeout(resetKiosk, 4000);
                }
            }
        } catch (e) {
            console.error("Failed to parse incoming message:", e);
        }
    });

    socket.addEventListener('close', () => {
        logToScreen('WebSocket connection closed. Retrying in 5 seconds.');
        statusMessage.textContent = 'Status: Disconnected. Attempting to reconnect...';
        statusMessage.style.color = 'orange';
        setTimeout(connect, 5000);
    });
}

function createGuestButtons() {
    while (guestButtonsDiv.firstChild) {
        guestButtonsDiv.removeChild(guestButtonsDiv.firstChild);
    }
    const maxGuests = kioskConfig.max_guests !== undefined ? kioskConfig.max_guests : 6;
    for (let i = 0; i <= maxGuests; i++) {
        const button = document.createElement('button');
        button.className = 'guest-button';
        button.textContent = i;
        button.dataset.count = i;
        button.addEventListener('click', function() {
            selectedGuestCount = parseInt(this.dataset.count, 10);
            document.querySelectorAll('.guest-button').forEach(btn => btn.classList.remove('selected'));
            this.classList.add('selected');
        });
        guestButtonsDiv.appendChild(button);
    }
}

function createAmenityButtons(amenities) {
    while (amenityButtonsDiv.firstChild) {
        amenityButtonsDiv.removeChild(amenityButtonsDiv.firstChild);
    }
    if (!amenities) return;
    amenities.forEach(amenity => {
        const button = document.createElement('button');
        button.className = 'amenity-button';
        button.dataset.name = amenity.name;
        if (amenity.image_url) {
            const img = document.createElement('img');
            img.src = amenity.image_url;
            button.appendChild(img);
        }
        const span = document.createElement('span');
        span.textContent = amenity.name;
        button.appendChild(span);

        const handleAmenitySelection = function(event) {
            event.preventDefault();
            if (!sessionActive) return;
            sessionActive = false;
            clearTimeout(idleTimeout);
            clearTimeout(resetKioskTimer);
            const selectedAmenityName = this.dataset.name;
            let finalDoorNumber = 1;
            let finalSerial = '900000';
            if (kioskIdentity) {
                finalDoorNumber = kioskIdentity.door;
                finalSerial = kioskIdentity.serial;
            }
            if (overrideDoorNumber !== null) finalDoorNumber = overrideDoorNumber;
            try {
                const messageToSend = {
                    event: 'amenitySelected',
                    payload: {
                        rfid: lastSwipedCard,
                        amenity: selectedAmenityName,
                        guests: selectedGuestCount,
                        serial_number: finalSerial,
                        door_number: finalDoorNumber
                    }
                };
                socket.send(JSON.stringify(messageToSend));
            } catch (e) {
                logToScreen(`CRITICAL ERROR: Send failed. ${e.message}`);
            }
            if (kioskConfig.splash_url) {
                splashImage.src = kioskConfig.splash_url;
            } else {
                const selectedAmenity = kioskConfig.amenities.find(a => a.name === selectedAmenityName);
                splashImage.src = (selectedAmenity && selectedAmenity.image_url) ? selectedAmenity.image_url : kioskConfig.logo_url;
            }
            statusMessage.textContent = `Thank you for signing in to ${this.dataset.name}!`;
            idleScreen.style.display = 'block';
            document.getElementById('main-layout-table').style.display = 'none';
            splashScreen.style.display = 'flex';
            resetKioskTimer = setTimeout(resetKiosk, 2000);
        };
        button.addEventListener('click', handleAmenitySelection);
        button.addEventListener('touchstart', handleAmenitySelection);
        amenityButtonsDiv.appendChild(button);
    });
}

function handleIdleTimeout() {
    if (!sessionActive) return;
    sessionActive = false;
    idleTimerFired = true;
    if (!lastSwipedCard) {
        resetKiosk();
        return;
    }
    socket.send(JSON.stringify({
        event: 'amenitySelected',
        payload: {
            rfid: lastSwipedCard,
            amenity: 'Lobby',
            guests: selectedGuestCount,
            serial_number: kioskIdentity ? kioskIdentity.serial : '900000',
            door_number: kioskIdentity ? kioskIdentity.door : 1
        }
    }));
    statusMessage.textContent = 'Sign-in for Lobby recorded due to inactivity.';
    setTimeout(resetKiosk, 3000);
}

function resetKiosk() {
    if (isDirectLoad) {
        isDirectLoad = false;
        window.close();
        return;
    }
    clearTimeout(idleTimeout);
    clearTimeout(resetKioskTimer);
    splashScreen.style.display = 'none';
    document.getElementById('main-layout-table').style.display = 'none';
    idleScreen.style.display = 'block';
    guestButtonsContainer.style.display = 'none';
    amenityButtonsContainer.style.display = 'none';
    cardDisplay.className = 'card-display-hidden';
    lastSwipedCard = null;
    lastCardSwipeDiv.textContent = '';
    amenityButtonsDiv.innerHTML = '';
    statusMessage.textContent = 'Please Swipe Your Card';
    statusMessage.style.color = '';
    cardReaderInput.value = '';
    startFocusCapture();
}

function forceFocus() {
    cardReaderInput.focus();
}

function startFocusCapture() {
    cardReaderInput.readOnly = true;
    cardReaderInput.removeEventListener('blur', forceFocus);
    cardReaderInput.addEventListener('blur', forceFocus);
    cardReaderInput.onkeydown = (e) => {
        if (e.key >= '0' && e.key <= '9') {
            e.preventDefault();
            rfidBuffer += e.key;
            clearTimeout(rfidTimeout);
            rfidTimeout = setTimeout(processStealthBuffer, 50);
        }
    };
    forceFocus();
}

function processStealthBuffer() {
    const finalRfid = rfidBuffer.trim();
    if (finalRfid.length >= 8) {
        const processedId = finalRfid.slice(-8);
        logToScreen(`Stealth Captured: ${processedId}`);
        socket.send(JSON.stringify({
            event: 'manualSwipe',
            payload: { rfid: processedId }
        }));
    } else if (finalRfid.length > 0) {
        logToScreen(`Discarding incomplete: ${finalRfid}`);
    }
    rfidBuffer = "";
}

function stopFocusCapture() {
    cardReaderInput.removeEventListener('blur', forceFocus);
    cardReaderInput.onkeydown = null;
    clearTimeout(rfidTimeout);
    rfidBuffer = "";
}

function toggleLogVisibility() {
    const logContainer = document.getElementById('debug-log');
    if (window.getComputedStyle(logContainer).display === 'none') {
        logContainer.style.display = 'block';
        localStorage.setItem('kioskLogVisible', 'true');
        logToScreen('Debug log enabled.');
    } else {
        logContainer.style.display = 'none';
        localStorage.setItem('kioskLogVisible', 'false');
    }
}

function setupLogoGestures() {
    if (!logoImage) {
        console.error("GESTURE ERROR: logoImage not found.");
        return;
    }
    logoImage.style.pointerEvents = "auto";
    logoImage.style.position = "relative";
    logoImage.style.zIndex = "20000";
    logoImage.style.touchAction = "none";
    logoImage.draggable = false;

    logToScreen("SYSTEM: Binding Logo Gestures...");

    const handleGesture = (e) => {
        if (e.type === 'touchstart') e.preventDefault();

        logoTapCount++;
        logoImage.style.opacity = "0.3";
        setTimeout(() => { logoImage.style.opacity = "1"; }, 150);
        logToScreen(`Logo Interaction: ${logoTapCount}/3`);

        clearTimeout(logoTapTimer);
        logoTapTimer = setTimeout(() => { logoTapCount = 0; }, 800);

        if (logoTapCount >= 3) {
            logToScreen("ACTION: Triple-tap! Refreshing...");
            const cleanUrl = window.location.origin + window.location.pathname;
            window.location.href = cleanUrl + "?v=" + Date.now();
            return;
        }

        clearTimeout(logoLongPressTimer);
        logoLongPressTimer = setTimeout(() => {
            logToScreen("ACTION: Long-press detected. Toggling Log.");
            toggleLogVisibility();
            logoTapCount = 0;
        }, 2000);
    };

    logoImage.addEventListener('touchstart', handleGesture, { passive: false });
    logoImage.addEventListener('mousedown', handleGesture);
    logoImage.addEventListener('touchend', () => clearTimeout(logoLongPressTimer));
    logoImage.addEventListener('mouseup', () => clearTimeout(logoLongPressTimer));
}

setInterval(() => {
    if (!sessionActive) {
        if (document.activeElement !== cardReaderInput || !cardReaderInput.readOnly) {
            logToScreen("Kiosk WATCHDOG: Restoring focus.");
            cardReaderInput.readOnly = true;
            cardReaderInput.focus();
            forceFocus();
        }
    }
}, 3000);

setupLogoGestures();
initializeKiosk();

