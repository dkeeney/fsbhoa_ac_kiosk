package main 

import (
    "bytes"
    "crypto/tls"
    "encoding/json"
    "fmt"
    "io"
    "log"
    "net/http"
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


type ValidationResponse struct {
	IsValid    bool        `json:"isValid"`
	Message    string      `json:"message"`
	Cardholder interface{} `json:"cardholder"`
}

// --- Global variables ---
var config Config
var kioskConfig KioskConfig
var upgrader = websocket.Upgrader{CheckOrigin: func(r *http.Request) bool { return true }}
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


// handleConnections is the WebSocket handler for browser UI communication.
func handleConnections(w http.ResponseWriter, r *http.Request) {
        // Parse Door ID from the connection URL
	// The frontend will connect to: ws://host/ws?doorId=33
	doorIDStr := r.URL.Query().Get("doorId")
	doorID := 0
	if doorIDStr != "" {
		fmt.Sscanf(doorIDStr, "%d", &doorID)
	}

	log.Println("==> Received a request to upgrade to WebSocket")
	ws, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Printf("ERROR: Failed to upgrade WebSocket connection: %v", err)
		return
	}
        //  Notify Monitor: "Kiosk is Online"
	// We treat "Connected" as "Intermediate" (Yellow/Controlled) status
	if doorID > 0 {
		log.Printf("Kiosk client connected for Door %d. Notifying Monitor Service.", doorID)
		notifyMonitorService(doorID, "intermediate")
	}

	clientsMutex.Lock()
	clients[ws] = doorID
	clientsMutex.Unlock()
        log.Printf("Client Connected. Received DoorID param: '%s'", doorIDStr)

	// Send initial kiosk config to the newly connected client.
	ws.WriteJSON(SocketMessage{Event: "kioskConfig", Payload: kioskConfig})

	// Variables to manage the wallet scan debouncing for this specific connection.
	var walletScanTimer *time.Timer
	var scannedRFIDs []string
	var rfidMutex sync.Mutex // Protects access to the scannedRFIDs slice

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
		rfidMutex.Unlock()

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
			if doorID > 0 {
				log.Printf("Kiosk client disconnected for Door %d. Notifying Monitor Service.", doorID)
				notifyMonitorService(doorID, "down")
			}

			// Stop any pending timer when client disconnects
			if walletScanTimer != nil {
				walletScanTimer.Stop()
			}
			break // Exit the read loop
		}

		log.Printf("GO DEBUG 1: Received WebSocket message with event type: %s", msg.Event)

		switch msg.Event {
                case "amenitySelected":
                        log.Println("GO DEBUG 2: Entered 'amenitySelected' case.")
                        if payload, ok := msg.Payload.(map[string]interface{}); ok {
                                // 1. Extract standard fields with safety checks
                                rfid, okR := payload["rfid"].(string)
                                amenity, okA := payload["amenity"].(string)
                                guestsFloat, okG := payload["guests"].(float64)

                                // 2. Extract new Identity fields (Optional - default if missing)
                                // We don't fail if these are missing, we just default to "0" to handle legacy clients.
                                
                                var serialStr string = "0"
                                if serialRaw, exists := payload["serial_number"]; exists {
                                    // JSON numbers come as float64, strings as string. Handle both.
                                    if s, ok := serialRaw.(string); ok {
                                        serialStr = s
                                    } else if f, ok := serialRaw.(float64); ok {
                                        serialStr = fmt.Sprintf("%.0f", f)
                                    }
                                }

                                var doorInt int = 0
                                if doorRaw, exists := payload["door_number"]; exists {
                                     if f, ok := doorRaw.(float64); ok {
                                         doorInt = int(f)
                                     }
                                }

                                // 3. Execute only if core data is valid
                                if okR && okA && okG {
                                        guests := int(guestsFloat)
                                        go logSignInToWordPress(rfid, amenity, guests, serialStr, doorInt)
                                } else {
                                        log.Printf("ERROR: Missing required fields in amenitySelected. R=%v A=%v G=%v", okR, okA, okG)
                                }
                        }

                case "startSessionById":
                    if payload, ok := msg.Payload.(map[string]interface{}); ok {
                    	if id, okID := payload["id"].(string); okID {
                                log.Printf("KIOSK DEBUG: 'startSessionById' event received. Triggering validation for ID %s.", id)
                            go func() {
                                var finalMessage SocketMessage
                                vResponse, err := validateCardholderByID(id)
                                if err == nil {
                                    rfid, _ := vResponse.Cardholder.(map[string]interface{})["rfid"].(string)
                                    finalPayload := map[string]interface{}{
                                        "isValid":    vResponse.IsValid,
                                        "message":    vResponse.Message,
                                        "cardholder": vResponse.Cardholder,
                                        "rfid":       rfid,
                                    }
                                    // Send only to the originating client 'ws'
                                    finalMessage = SocketMessage{Event: "cardSwiped", Payload: finalPayload}
                                    clientsMutex.Lock()
                                    if err := ws.WriteJSON(finalMessage); err != nil {
                                         log.Printf("Error sending startSessionById result to client: %v", err)
                                    }
                                    clientsMutex.Unlock()
                                } else {
                                    log.Printf("Error validating cardholder by ID %s: %v", id, err)

                                    // Construct a failure payload similar to a failed swipe
                                    errorPayload := map[string]interface{}{
                                        "isValid": false,
                                        // Use the message from the PHP response if available, otherwise use a generic message
                                        "message": "Error validating cardholder. Please try swiping.",
                                    }
                                    // It's possible vResponse has the error message even if err is not nil
                                    if vResponse.Message != "" {
                                        errorPayload["message"] = vResponse.Message
                                    }

                                    finalMessage = SocketMessage{Event: "cardSwiped", Payload: errorPayload}
                                }
                            }()
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
					// Start a new 250ms timer. If another swipe comes in, this will be
					// stopped and reset. If not, processWalletScan will run.
					walletScanTimer = time.AfterFunc(400*time.Millisecond, processWalletScan)
					rfidMutex.Unlock()
				}
			}
		}
	}
}

// This helper function validates a cardholder by their primary ID against WordPress.
// Used when signing in via a WordPress originated request.
func validateCardholderByID(id string) (ValidationResponse, error) {
    var vResponse ValidationResponse
    validationURL := fmt.Sprintf("%s/wp-json/fsbhoa/v1/kiosk/cardholder/%s", config.WordPressAPIBaseURL, id)
    req, err := http.NewRequest("GET", validationURL, nil)
    if err != nil {
        log.Printf("Error creating validation request for ID %s: %v", id, err)
        return vResponse, err
    }

    req.Header.Set("X-API-KEY", config.APIKey)
    httpClient := &http.Client{Timeout: 5 * time.Second}
    resp, err := httpClient.Do(req)
    if err != nil {
        log.Printf("Network error validating ID %s: %v", id, err)
        return vResponse, err
    }
    defer resp.Body.Close()

    if resp.StatusCode != http.StatusOK {
        bodyBytes, _ := io.ReadAll(resp.Body)
        log.Printf("Non-200 status for ID %s: %s - %s", id, resp.Status, string(bodyBytes))
        return vResponse, fmt.Errorf("bad status: %s", resp.Status)
    }

    if err := json.NewDecoder(resp.Body).Decode(&vResponse); err != nil {
        log.Printf("Error decoding validation response for ID %s: %v", id, err)
        return vResponse, err
    }

    return vResponse, nil
}


// apiProxyHandler  acts as a bridge between the Kiosk JS and the WordPress API.
// Allowed paths that the frontend is permitted to request via the proxy.
// This prevents the frontend from accessing sensitive WP endpoints (like /users).
var allowedProxyPaths = map[string]bool{
    "/fsbhoa/v1/monitor/gates": true,
    // Add future paths here, e.g.:
    // "/fsbhoa/v1/amenities/status": true,
}

func apiProxyHandler(w http.ResponseWriter, r *http.Request) {
    // 1. Get the requested path from the query string (e.g. ?endpoint=/fsbhoa/v1/monitor/gates)
    endpoint := r.URL.Query().Get("endpoint")
    
    // 2. SECURITY CHECK: Is this path allowed?
    // We strip query parameters from the map check (e.g. "?role=KIOSK")
    // This is a simple check; for production, you might need more robust parsing.
    basePath := endpoint
    if idx := strings.Index(endpoint, "?"); idx != -1 {
        basePath = endpoint[:idx]
    }

    if !allowedProxyPaths[basePath] {
        log.Printf("SECURITY: Blocked proxy attempt to unauthorized path: %s", endpoint)
        http.Error(w, "Forbidden", http.StatusForbidden)
        return
    }

    // 3. Construct the full URL
    // We pass the raw 'endpoint' string which includes the query params from the frontend
    targetURL := fmt.Sprintf("%s/wp-json%s", config.WordPressAPIBaseURL, endpoint)

    // 4. Create Request
    req, err := http.NewRequest("GET", targetURL, nil)
    if err != nil {
        log.Printf("PROXY ERROR: Could not create request: %v", err)
        http.Error(w, "Internal Server Error", http.StatusInternalServerError)
        return
    }

    req.Header.Set("X-API-KEY", config.APIKey)

    // 5. Send Request
    client := &http.Client{Timeout: 5 * time.Second}
    resp, err := client.Do(req)
    if err != nil {
        log.Printf("PROXY ERROR: Backend unreachable: %v", err)
        http.Error(w, "Bad Gateway", http.StatusBadGateway)
        return
    }
    defer resp.Body.Close()

    // 6. Forward Response
    w.Header().Set("Content-Type", resp.Header.Get("Content-Type"))
    w.WriteHeader(resp.StatusCode)
    io.Copy(w, resp.Body)
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
        http.HandleFunc("/api/proxy", apiProxyHandler)
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
