package main 

import (
    "bytes"
    "crypto/tls"
    "encoding/json"
    "fmt"
    "io"
    "log"
    "net"
    "net/http"
    "net/url"
    "os"
    "strings"
    "sync"
    "time"

"github.com/gorilla/websocket"
)

// --- Data Structs ---
type Config struct {
	WordPressAPIBaseURL string `json:"wordpress_api_base_url"`
        APIKey              string `json:"api_key"`
	SSLCertPath         string `json:"ssl_cert_path"`
	SSLKeyPath          string `json:"ssl_key_path"`
	Port                string `json:"port"`
	LogFile             string `json:"log_file"`
        MaxGuests           int    `json:"max_guests"`
        MonitorServiceURL   string `json:"monitor_service_url"`
        EventServiceURL     string `json:"event_service_url"`
}

type Amenity struct {
	Name     string `json:"name"`
	ImageURL string `json:"image_url"`
}

type KioskConfig struct {
	LogoURL   string    `json:"logo_url"`
        SplashURL string    `json:"splash_url"`
	Amenities []Amenity `json:"amenities"`
        MaxGuests int       `json:"max_guests"`
}

type SocketMessage struct {
	Event   string      `json:"event"`
	Payload interface{} `json:"payload"`
}

type SignInPayload struct {
	RFID    string `json:"rfid"`
	Amenity string `json:"amenity"`
        Guests  int    `json:"guests"`
        SerialNumber string `json:"serial_number"` // using string to match "900000" or int? 
        DoorNumber   int    `json:"door_number"`
}


// Station is the kiosk station registered in WordPress for a browser's IP address.
type Station struct {
	DoorRecordID int    `json:"door_record_id"`
	Name         string `json:"name"`
	SerialNumber string `json:"serial_number"`
	DoorNumber   int    `json:"door_number"`
}

// Limits on card lookups per connection, so card numbers can't be guessed in bulk.
const maxRFIDsPerScan = 5
const maxLookupsPerMinute = 10

type ValidationResponse struct {
	IsValid    bool        `json:"isValid"`
	Message    string      `json:"message"`
	Cardholder interface{} `json:"cardholder"`
}

// --- Global variables ---
var config Config
var kioskConfig KioskConfig
// The default origin check only accepts pages served by this service.
var upgrader = websocket.Upgrader{}
var clients = make(map[*websocket.Conn]int)
var clientsMutex = sync.Mutex{}


// --- Core Functions ---

// loadConfiguration reads settings from the central config file.
func loadConfiguration() {
	file, err := os.Open("/var/lib/fsbhoa/kiosk.json")
	if err != nil {
		log.Fatalf("FATAL: Could not open config file: %v", err)
	}
	defer file.Close()
	decoder := json.NewDecoder(file)
	err = decoder.Decode(&config)
	if err != nil {
		log.Fatalf("FATAL: Could not parse config file: %v", err)
	}
        log.Printf("Kiosk service starting. Config loaded. API Key Found: '%s'", config.APIKey)
}

// setupLogging configures the log output to a file if specified.
func setupLogging() {
	if config.LogFile != "" {
		file, err := os.OpenFile(config.LogFile, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0664)
		if err != nil {
			log.Printf("WARNING: Could not open log file %s: %v. Logging to console.", config.LogFile, err)
			return
		}
		log.SetOutput(file)
		log.Println("--- Kiosk Service Starting ---")
	}
}

// fetchKioskConfig gets the kiosk UI config from the WordPress server.
func fetchKioskConfig() {
	url := fmt.Sprintf("%s/wp-json/fsbhoa/v1/kiosk/config", config.WordPressAPIBaseURL)
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		log.Fatalf("FATAL: Could not create request for kiosk config: %v", err)
	}
	req.Header.Set("X-API-KEY", config.APIKey)
	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil { // Check for non-nil error from the Do call
		log.Fatalf("FATAL: Could not fetch kiosk config: %v", err)
	}
	if resp.StatusCode != http.StatusOK {
		bodyBytes, _ := io.ReadAll(resp.Body)
		log.Fatalf("FATAL: WordPress returned non-200 status for kiosk config: %s - %s", resp.Status, string(bodyBytes))
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		log.Fatalf("FATAL: Could not read response body: %v", err)
	}
	err = json.Unmarshal(body, &kioskConfig)
	if err != nil {
		log.Fatalf("FATAL: Could not parse kiosk config JSON: %v", err)
	}
        kioskConfig.MaxGuests = config.MaxGuests
	log.Printf("Successfully fetched config. Logo: '%s', Amenities: %d, MaxGuest: %d", kioskConfig.LogoURL, len(kioskConfig.Amenities), kioskConfig.MaxGuests)
}

// logSignInToWordPress sends the final amenity selection event to WordPress.
func logSignInToWordPress(rfid, amenity string, guests int, serial string, door int) {
        log.Println("GO DEBUG 3: logSignInToWordPress function has started.")
        log.Printf("LOGGING TO WORDPRESS: Card %s, Amenity %s, Guest %d, Serial %s, Door %d\n", rfid, amenity, guests, serial, door)
	payload := SignInPayload{
            RFID: rfid, 
            Amenity: amenity, 
            Guests: guests,
            SerialNumber: serial,
            DoorNumber: door,}
	jsonData, err := json.Marshal(payload)
	if err != nil {
		log.Printf("Error marshalling JSON for sign-in: %v", err)
		return
	}
	url := fmt.Sprintf("%s/wp-json/fsbhoa/v1/kiosk/log-signin", config.WordPressAPIBaseURL)
	req, err := http.NewRequest("POST", url, bytes.NewBuffer(jsonData))
	if err != nil {
		log.Printf("Error creating request: %v", err)
		return
	}
        req.Header.Set("X-API-KEY", config.APIKey)
	req.Header.Set("Content-Type", "application/json")
	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		log.Printf("Error sending sign-in to WordPress: %v", err)
		return
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		log.Printf("WordPress API returned non-200 status: %s", resp.Status)
	} else {
		log.Println("Successfully logged sign-in to WordPress.")
	}
}

// notifyMonitorService acts as a "Virtual Controller" heartbeat.
// It tells the central Monitor Service that this Kiosk Door is Online/Offline.
func notifyMonitorService(doorID int, status string) {
    // Run in background (fire-and-forget) so we don't block the Kiosk
    go func() {
        log.Printf("DEBUG POLL: Sending status '%s' for DoorID %d to Monitor...", status, doorID)

        // URL of the Monitor Service (running on the same machine)
        // If you ever split servers, this would need to be in config.json
        targetURL := config.MonitorServiceURL
        if targetURL == "" {
             targetURL = "https://127.0.0.1:8082" // Fallback
        }
        targetURL = strings.TrimRight(targetURL, "/")
        url := fmt.Sprintf("%s/update-gate-status", targetURL)

        payload := map[string]interface{}{
            "doorRecordId": doorID,
            "status":       status, // "intermediate" (Online/Yellow) or "down" (Offline/Black)
        }

        jsonPayload, err := json.Marshal(payload)
        if err != nil {
            log.Printf("Error marshalling monitor notification: %v", err)
            return
        }

        // Send asynchronously so we don't block the Kiosk if Monitor is down

        // Configure Client
        tr := &http.Transport{
            TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
        }
        client := &http.Client{Transport: tr, Timeout: 5 * time.Second}

        // 4. Send Request
        resp, err := client.Post(url, "application/json", bytes.NewBuffer(jsonPayload))
        if err != nil {
            log.Printf("Warning: Failed to notify Monitor Service: %v", err)
            return
        }
        defer resp.Body.Close()

        if resp.StatusCode != http.StatusOK {
            log.Printf("Warning: Monitor Service returned status: %s", resp.Status)
        }

    }()
}

// broadcast sends a message to all connected browser clients.
func broadcast(message SocketMessage) {
	clientsMutex.Lock()
	defer clientsMutex.Unlock()
	log.Printf("Broadcasting message to %d client(s).", len(clients))
	for client := range clients {
		if err := client.WriteJSON(message); err != nil {
			log.Printf("error broadcasting message: %v", err)
			client.Close()
			delete(clients, client)
		}
	}
}




// This helper function cleanly validates a single RFID against WordPress.
// It's called by the wallet scan logic. It returns the response, the validated RFID, and any error.
func validateRFID(rfid string) (ValidationResponse, string, error) {
	var vResponse ValidationResponse
	validationURL := fmt.Sprintf("%s/wp-json/fsbhoa/v1/kiosk/validate-card/%s", config.WordPressAPIBaseURL, rfid)
	req, err := http.NewRequest("GET", validationURL, nil)
	if err != nil {
		log.Printf("Error creating validation request for %s: %v", rfid, err)
		return vResponse, rfid, err
	}

	req.Header.Set("X-API-KEY", config.APIKey)
	httpClient := &http.Client{Timeout: 5 * time.Second}
	resp, err := httpClient.Do(req)
	if err != nil {
		log.Printf("Network error validating %s: %v", rfid, err)
		return vResponse, rfid, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		bodyBytes, _ := io.ReadAll(resp.Body)
		log.Printf("Non-200 status for %s: %s - %s", rfid, resp.Status, string(bodyBytes))
		return vResponse, rfid, fmt.Errorf("bad status: %s", resp.Status)
	}

	if err := json.NewDecoder(resp.Body).Decode(&vResponse); err != nil {
		log.Printf("Error decoding validation response for %s: %v", rfid, err)
		return vResponse, rfid, err
	}

	return vResponse, rfid, nil
}


// clientIP returns the connection's source address, with IPv4-mapped IPv6
// addresses (::ffff:192.168.1.5) converted to plain IPv4.
func clientIP(r *http.Request) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		host = r.RemoteAddr
	}
	ip := net.ParseIP(host)
	if ip == nil {
		return host
	}
	if v4 := ip.To4(); v4 != nil {
		return v4.String()
	}
	return ip.String()
}

// lookupStation asks WordPress which kiosk station is registered for an IP address.
// Returns found=false if no station has that address.
func lookupStation(ip string) (Station, bool, error) {
	var station Station
	stationURL := fmt.Sprintf("%s/wp-json/fsbhoa/v1/kiosk/station?ip=%s", config.WordPressAPIBaseURL, url.QueryEscape(ip))
	req, err := http.NewRequest("GET", stationURL, nil)
	if err != nil {
		return station, false, err
	}
	req.Header.Set("X-API-KEY", config.APIKey)
	httpClient := &http.Client{Timeout: 5 * time.Second}
	resp, err := httpClient.Do(req)
	if err != nil {
		return station, false, err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusNotFound {
		return station, false, nil
	}
	if resp.StatusCode != http.StatusOK {
		bodyBytes, _ := io.ReadAll(resp.Body)
		return station, false, fmt.Errorf("bad status: %s - %s", resp.Status, string(bodyBytes))
	}
	if err := json.NewDecoder(resp.Body).Decode(&station); err != nil {
		return station, false, err
	}
	return station, true, nil
}

// handleConnections is the WebSocket handler for browser UI communication.
// The station is decided by the connection's source IP address, never by the browser.
func handleConnections(w http.ResponseWriter, r *http.Request) {
	ip := clientIP(r)

	log.Printf("==> Received a request to upgrade to WebSocket from %s", ip)
	ws, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Printf("ERROR: Failed to upgrade WebSocket connection: %v", err)
		return
	}

	station, found, err := lookupStation(ip)
	if err != nil {
		log.Printf("ERROR: Station lookup failed for %s: %v", ip, err)
		ws.WriteJSON(SocketMessage{Event: "stationError", Payload: map[string]interface{}{"ip": ip}})
		ws.Close()
		return
	}
	if !found {
		log.Printf("SECURITY: Refused kiosk connection from unregistered address %s", ip)
		ws.WriteJSON(SocketMessage{Event: "notRegistered", Payload: map[string]interface{}{"ip": ip}})
		ws.Close()
		return
	}
	doorID := station.DoorRecordID

	//  Notify Monitor: "Kiosk is Online"
	// We treat "Connected" as "Intermediate" (Yellow/Controlled) status
	log.Printf("Kiosk client connected from %s as station '%s' (Door %d). Notifying Monitor Service.", ip, station.Name, doorID)
	notifyMonitorService(doorID, "intermediate")

	clientsMutex.Lock()
	clients[ws] = doorID
	clientsMutex.Unlock()

	// Send initial kiosk config and station identity to the newly connected client.
	ws.WriteJSON(SocketMessage{Event: "kioskConfig", Payload: kioskConfig})
	ws.WriteJSON(SocketMessage{Event: "station", Payload: map[string]interface{}{"name": station.Name, "ip": ip}})

	// Variables to manage the wallet scan debouncing for this specific connection.
	var walletScanTimer *time.Timer
	var scannedRFIDs []string
	var rfidMutex sync.Mutex // Protects scannedRFIDs, sessionRFID and lookupTimes
	// The card validated by the last swipe. Sign-ins are logged for this card only,
	// whatever card number the browser sends.
	var sessionRFID string
	var lookupTimes []time.Time

	// This is the function that will be called when the wallet scan timer fires.
	processWalletScan := func() {
		rfidMutex.Lock()
		// De-duplicate the collected RFIDs
		uniqueRFIDs := make(map[string]bool)
		var finalRFIDs []string
		for _, rfid := range scannedRFIDs {
			if !uniqueRFIDs[rfid] {
				uniqueRFIDs[rfid] = true
				finalRFIDs = append(finalRFIDs, rfid)
			}
		}
		scannedRFIDs = []string{} // Clear the slice for the next scan
		sessionRFID = ""          // A new swipe replaces any earlier one

		if len(finalRFIDs) > maxRFIDsPerScan {
			finalRFIDs = finalRFIDs[:maxRFIDsPerScan]
		}

		// Rate limit: drop lookups older than a minute, then check the budget.
		now := time.Now()
		recent := lookupTimes[:0]
		for _, t := range lookupTimes {
			if now.Sub(t) < time.Minute {
				recent = append(recent, t)
			}
		}
		lookupTimes = recent
		rateLimited := len(lookupTimes)+len(finalRFIDs) > maxLookupsPerMinute
		if !rateLimited {
			for range finalRFIDs {
				lookupTimes = append(lookupTimes, now)
			}
		}
		rfidMutex.Unlock()

		if rateLimited {
			log.Printf("SECURITY: Rate limit hit for station '%s' (%s). Ignoring %d card(s).", station.Name, ip, len(finalRFIDs))
			clientsMutex.Lock()
			ws.WriteJSON(SocketMessage{Event: "cardSwiped", Payload: map[string]interface{}{
				"isValid": false,
				"message": "Too many attempts. Please wait a minute and try again.",
			}})
			clientsMutex.Unlock()
			return
		}

		log.Printf("Wallet scan complete. Found %d unique RFIDs to check.", len(finalRFIDs))

		var validCardResponse ValidationResponse
		var validRFID string
		foundValid := false

		// Check each unique RFID until we find a valid one.
		for _, rfid := range finalRFIDs {
			vResponse, checkedRFID, err := validateRFID(rfid)
			if err == nil && vResponse.IsValid {
				log.Printf("Found valid card in wallet: %s", checkedRFID)
				validCardResponse = vResponse
				validRFID = checkedRFID
				foundValid = true
				break // Stop checking once a valid card is found
			}
		}

		var finalMessage SocketMessage
		if foundValid {
			rfidMutex.Lock()
			sessionRFID = validRFID
			rfidMutex.Unlock()

			// Construct the success payload
			finalPayload := map[string]interface{}{
				"isValid":    validCardResponse.IsValid,
				"message":    validCardResponse.Message,
				"cardholder": validCardResponse.Cardholder,
				"rfid":       validRFID, // Include the specific RFID that was validated
			}
			finalMessage = SocketMessage{Event: "cardSwiped", Payload: finalPayload}
		} else {
			// Construct the failure payload
			log.Printf("No valid cards found in wallet scan.")
			finalMessage = SocketMessage{Event: "cardSwiped", Payload: map[string]interface{}{
				"isValid": false,
				"message": "Card not found or invalid.",
			}}
		}

		// Safely write the single, final response back to the client.
		clientsMutex.Lock()
		if err := ws.WriteJSON(finalMessage); err != nil {
			log.Printf("Error sending wallet scan result: %v", err)
		}
		clientsMutex.Unlock()
	}

	// This is now the ONLY read loop for this connection.
	for {
		var msg SocketMessage
		if err := ws.ReadJSON(&msg); err != nil {
			log.Println("Client disconnected.")
			clientsMutex.Lock()
			delete(clients, ws)
			clientsMutex.Unlock()

			//  Notify Monitor: "Kiosk is Offline"
			// We treat "Disconnected" as "Down" (Black) status
			log.Printf("Kiosk client disconnected for Door %d. Notifying Monitor Service.", doorID)
			notifyMonitorService(doorID, "down")

			// Stop any pending timer when client disconnects
			rfidMutex.Lock()
			if walletScanTimer != nil {
				walletScanTimer.Stop()
			}
			rfidMutex.Unlock()
			break // Exit the read loop
		}

		log.Printf("GO DEBUG 1: Received WebSocket message with event type: %s", msg.Event)

		switch msg.Event {
		case "amenitySelected":
			log.Println("GO DEBUG 2: Entered 'amenitySelected' case.")
			if payload, ok := msg.Payload.(map[string]interface{}); ok {
				amenity, okA := payload["amenity"].(string)
				guestsFloat, okG := payload["guests"].(float64)

				// Use the card this connection validated, and use it only once.
				rfidMutex.Lock()
				rfid := sessionRFID
				sessionRFID = ""
				rfidMutex.Unlock()

				if rfid == "" {
					log.Printf("SECURITY: amenitySelected from station '%s' with no validated swipe. Ignored.", station.Name)
				} else if okA && okG && amenity != "" {
					guests := int(guestsFloat)
					if guests < 0 {
						guests = 0
					}
					if kioskConfig.MaxGuests > 0 && guests > kioskConfig.MaxGuests {
						guests = kioskConfig.MaxGuests
					}
					go logSignInToWordPress(rfid, amenity, guests, station.SerialNumber, station.DoorNumber)
				} else {
					log.Printf("ERROR: Missing required fields in amenitySelected. A=%v G=%v", okA, okG)
				}
			}

		case "manualSwipe":
			if payload, ok := msg.Payload.(map[string]interface{}); ok {
				if rfid, okR := payload["rfid"].(string); okR {
					rfidMutex.Lock()
					scannedRFIDs = append(scannedRFIDs, rfid)
					// If a timer is already running, stop it. We'll start a new one.
					if walletScanTimer != nil {
						walletScanTimer.Stop()
					}
					// Start a new 400ms timer. If another swipe comes in, this will be
					// stopped and reset. If not, processWalletScan will run.
					walletScanTimer = time.AfterFunc(400*time.Millisecond, processWalletScan)
					rfidMutex.Unlock()
				}
			}
		}
	}
}

// ---  Event Service Listener ---

// EventServiceMessage defines the structure of messages from the Event Service
type EventServiceMessage struct {
	MessageType string      `json:"messageType"`
	Payload     interface{} `json:"payload"`
}

// listenToEventService maintains a persistent WebSocket connection to the Event Service
// to listen for "trigger_poll" commands.
func listenToEventService() {
	for {
		if config.EventServiceURL == "" {
			log.Println("WARNING: No event_service_url configured. Polling listener disabled.")
			return
		}

                targetURL := config.EventServiceURL
		if !strings.HasSuffix(targetURL, "/ws") {
			targetURL = fmt.Sprintf("%s/ws", strings.TrimRight(targetURL, "/"))
		}
                log.Printf("DEBUG POLL: Attempting connection to Event Service at %s...", targetURL)

		// Create a custom dialer that skips SSL verification (internal traffic safety net)
		dialer := websocket.Dialer{
			TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
		}

		conn, _, err := dialer.Dial(targetURL, nil)
		if err != nil {
			log.Printf("ERROR: Failed to connect to Event Service: %v. Retrying in 10s...", err)
			time.Sleep(10 * time.Second)
			continue
		}

		log.Println("SUCCESS: Connected to Event Service. Listening for commands.")

		for {
			var msg EventServiceMessage
			// Read JSON message
			err := conn.ReadJSON(&msg)
			if err != nil {
				log.Printf("ERROR: Connection to Event Service lost: %v", err)
				break // Break inner loop to reconnect
			}
                        log.Printf("DEBUG POLL: Received message from Event Service. Type: '%s'", msg.MessageType)

			// Check for the Poll Command
			if msg.MessageType == "trigger_poll" {
				log.Println("RECEIVED COMMAND: 'trigger_poll'. Reporting status for all connected kiosks...")
				reportAllConnectedKiosks()
			}
		}
		conn.Close()
		time.Sleep(5 * time.Second)
	}
}

// reportAllConnectedKiosks iterates through connected browsers and sends their status to Monitor
func reportAllConnectedKiosks() {
	clientsMutex.Lock()
	defer clientsMutex.Unlock()

	count := 0
        
	for _, doorID := range clients {
		if doorID > 0 {
			// Reuse the existing notification logic
			log.Printf("Reporting status for active Door %d", doorID)
			notifyMonitorService(doorID, "intermediate")
			count++
		}
	}
	log.Printf("Reported status for %d active kiosk clients.", count)
}


// main is the application entry point.

func main() {
	loadConfiguration()
	setupLogging()
        // Signal the wrapper script to restart the browser
        log.Println("SIGNAL: Touching trigger file to restart Kiosk Browser...")
        triggerFile, err := os.Create("/tmp/restart_kiosk_browser")
        if err != nil {
            log.Printf("WARNING: Failed to create restart trigger: %v", err)
        } else {
            triggerFile.Close()
        }
	fetchKioskConfig()
        go listenToEventService()


	fs := http.FileServer(http.Dir("./web"))
	http.Handle("/", fs)
        http.HandleFunc("/ws", func(w http.ResponseWriter, r *http.Request) { handleConnections(w, r) })

        if config.SSLCertPath != "" && config.SSLKeyPath != "" {
            log.Printf("STARTUP CHECK: Attempting to start HTTPS server with Cert='%s', Key='%s'", config.SSLCertPath, config.SSLKeyPath)
            log.Printf("INFO: Starting kiosk HTTPS server on port %s", config.Port)
            err := http.ListenAndServeTLS(config.Port, config.SSLCertPath, config.SSLKeyPath, nil)
            if err != nil { log.Fatalf("FATAL: Secure server error: %v", err) }
        } else {
            log.Printf("INFO: Starting kiosk HTTP server on port %s", config.Port)
            err := http.ListenAndServe(config.Port, nil)
            if err != nil { log.Fatalf("FATAL: Insecure server error: %v", err) }
        }
}
