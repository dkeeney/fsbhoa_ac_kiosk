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
let notRegistered = false;
let rfidBuffer = "";

// Gesture Variables
let logoTapCount = 0;
let logoTapTimer = null;
let logoLongPressTimer = null;

// The server decides which station this is from the browser's IP address,
// so the page has no station setup of its own.
function initializeKiosk() {
    const urlParams = new URLSearchParams(window.location.search);
    logToScreen("Initializing Kiosk Logic...");

    if (urlParams.has('v')) {
        logToScreen(`SYSTEM: Hard Refresh detected. Build: ${urlParams.get('v')}`);
    }

    // Clear the station identity older versions kept in the browser.
    try {
        localStorage.removeItem('fsbhoa_kiosk_identity');
    } catch (e) {
        // Storage unavailable; nothing to clear.
    }

    connect();
}

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
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const socketURL = `${protocol}//${window.location.host}/ws`;

    logToScreen(`Connecting to WebSocket at: ${socketURL}`);
    socket = new WebSocket(socketURL);

    socket.addEventListener('open', () => {
        notRegistered = false;
        if (!hasConnectedBefore) {
            hasConnectedBefore = true;
            resetKiosk();
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

            if (message.event === 'notRegistered') {
                notRegistered = true;
                stopFocusCapture();
                statusMessage.textContent = `This device (${message.payload.ip}) is not registered as a kiosk station. ` +
                    'An administrator can add this address on the Kiosk Stations page.';
                statusMessage.style.color = 'red';
            } else if (message.event === 'stationError') {
                statusMessage.textContent = 'Cannot reach the access control server. Retrying...';
                statusMessage.style.color = 'orange';
            } else if (message.event === 'station') {
                logToScreen(`Station: ${message.payload.name} (${message.payload.ip})`);
                // Connected and registered: clear any "not registered" or "disconnected" message.
                if (!sessionActive) {
                    resetKiosk();
                }
            } else if (message.event === 'kioskConfig') {
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
                        // Fallback generic silhouette using an inline SVG
                        cardPhoto.src = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23aaaaaa"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>';
                        //cardPhoto.style.padding = '20px'; // Give the icon some breathing room
                        cardPhoto.style.backgroundColor = '#f0f0f1'; // Light grey background
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
        if (notRegistered) {
            // Keep the "not registered" message up and check again less often.
            logToScreen('Not registered. Checking again in 30 seconds.');
            setTimeout(connect, 30000);
            return;
        }
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
            try {
                // The server logs the card it validated for this connection and this station.
                const messageToSend = {
                    event: 'amenitySelected',
                    payload: {
                        amenity: selectedAmenityName,
                        guests: selectedGuestCount
                    }
                };
                // Add this line so we can see it in the debug window!
                logToScreen(`Sending amenitySelected: ${JSON.stringify(messageToSend)}`);
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
            amenity: 'Lobby',
            guests: selectedGuestCount
        }
    }));
    statusMessage.textContent = 'Sign-in for Lobby recorded due to inactivity.';
    setTimeout(resetKiosk, 3000);
}

function resetKiosk() {
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
    // If the debug log is open, stop fighting the user for the cursor!
    if (localStorage.getItem('kioskLogVisible') === 'true') {
        return;
    }
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
    const wrapper = document.getElementById('debug-wrapper');
    if (window.getComputedStyle(wrapper).display === 'none') {
        wrapper.style.display = 'block';
        localStorage.setItem('kioskLogVisible', 'true');
        logToScreen('Debug log enabled.');
    } else {
        wrapper.style.display = 'none';
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

window.triggerManualSwipe = function() {
    const cardNum = "11111111";
    logToScreen(`Simulating swipe for: ${cardNum}`);

    socket.send(JSON.stringify({
        event: 'manualSwipe',
        payload: { rfid: cardNum }
    }));
};



setInterval(() => {
    if (!sessionActive) {
        // Suspend the watchdog while debugging so you can highlight text
        if (localStorage.getItem('kioskLogVisible') === 'true') {
            return;
        }

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

