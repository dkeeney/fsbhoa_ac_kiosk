<?php
/**
 * Handles Kiosk-specific REST API endpoints.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Kiosk_REST_API {

    private $namespace = 'fsbhoa/v1';

    public function register_routes() {
        register_rest_route( $this->namespace, '/kiosk/config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_kiosk_config_callback' ),
            'permission_callback' => array( 'Fsbhoa_Verification_REST_API', 'api_key_permission_check' ),
        ) );

        register_rest_route( $this->namespace, '/kiosk/log-signin', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'log_signin_callback' ),
            'permission_callback' => array( 'Fsbhoa_Verification_REST_API', 'api_key_permission_check' ),
        ) );

        register_rest_route( $this->namespace, '/kiosk/validate-card/(?P<rfid>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'validate_card_callback' ),
            'permission_callback' => array( 'Fsbhoa_Verification_REST_API', 'api_key_permission_check' ),
        ) );

        register_rest_route( $this->namespace, '/kiosk/station', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_station_callback' ),
            'permission_callback' => array( 'Fsbhoa_Verification_REST_API', 'api_key_permission_check' ),
        ) );
    }
    /**
     * Returns the kiosk configuration: logo URL and active amenities.
     */
    public function get_kiosk_config_callback( WP_REST_Request $request ) {
        global $wpdb;
        $table_name = 'ac_amenities';

        $amenities = $wpdb->get_results(
            "SELECT name, image_url FROM {$table_name} WHERE is_active = 1 ORDER BY display_order ASC, name ASC"
        );
        if ($wpdb->last_error) { return new WP_Error( 'db_error', 'Database error getting amenities.', ['status' => 500] ); }

        // Amenity to sign in to when the cardholder doesn't choose one. Blank (or no longer
        // active) means the kiosk records nothing on timeout.
        $idle_amenity = get_option('fsbhoa_kiosk_idle_amenity', '');
        if ($idle_amenity !== '' && ! in_array($idle_amenity, wp_list_pluck($amenities, 'name'), true)) {
            error_log("KIOSK CONFIG: Inactivity amenity '{$idle_amenity}' is not an active amenity. Timeouts will not sign in.");
            $idle_amenity = '';
        }
        $idle_timeout = absint(get_option('fsbhoa_kiosk_idle_timeout', 30));

        $response_data = [
            'logo_url' => get_option('fsbhoa_kiosk_logo_url', ''),
            'splash_url' => get_option('fsbhoa_kiosk_splash_url', ''),
            'amenities' => $amenities,
            'idle_amenity' => $idle_amenity,
            'idle_timeout_seconds' => $idle_timeout > 0 ? $idle_timeout : 30,
        ];

        return new WP_REST_Response( $response_data, 200 );
    }

    /**
     * Looks up the kiosk station registered for a browser's IP address.
     * The kiosk service calls this when a browser connects, so the station is decided
     * by where the connection comes from, not by anything the browser claims.
     */
    public function get_station_callback( WP_REST_Request $request ) {
        global $wpdb;

        $ip = trim( (string) $request->get_param( 'ip' ) );
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return new WP_Error( 'bad_request', 'A valid ip parameter is required.', ['status' => 400] );
        }

        $station = $wpdb->get_row( $wpdb->prepare(
            "SELECT d.door_record_id, d.friendly_name, d.door_number_on_controller, c.uhppoted_device_id
             FROM ac_doors d JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id
             WHERE c.type = 'VIRTUAL_KIOSK' AND c.uhppoted_device_id = %d
               AND d.door_role = 'KIOSK' AND d.ip_address = %s
             LIMIT 1",
            Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL,
            $ip
        ) );
        if ( $wpdb->last_error ) { return new WP_Error( 'db_error', 'Database error looking up station.', ['status' => 500] ); }

        if ( ! $station ) {
            return new WP_Error( 'not_registered', 'No kiosk station is registered for this address.', ['status' => 404] );
        }

        return new WP_REST_Response( [
            'door_record_id' => (int) $station->door_record_id,
            'name'           => $station->friendly_name,
            'serial_number'  => (string) $station->uhppoted_device_id,
            'door_number'    => (int) $station->door_number_on_controller,
        ], 200 );
    }

    /**
     * This is called when kiosk has collected an amenity for a valid cardholder.
     * UPDATED: Delegates all processing to Fsbhoa_Access_Service::process_and_write_event.
     */
    public function log_signin_callback( WP_REST_Request $request ) {
        $params = $request->get_json_params();
        
        $rfid = isset($params['rfid']) ? sanitize_text_field($params['rfid']) : '';
        $amenity_name = isset($params['amenity']) ? sanitize_text_field($params['amenity']) : '';
        $guests = isset($params['guests']) ? absint($params['guests']) : 0;

        // Identity Logic: Default to '900000' (Virtual) if not provided
        $controller_sn = isset($params['serial_number']) && !empty($params['serial_number']) 
            ? sanitize_text_field($params['serial_number']) 
            : (string) Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL;
            
        $door_num = isset($params['door_number']) && $params['door_number'] > 0 
            ? absint($params['door_number']) 
            : 1;

        if (empty($rfid) || empty($amenity_name)) {
            return new WP_Error( 'bad_request', 'Missing rfid or amenity name.', ['status' => 400] );
        }

        // Re-check the card here rather than trusting the earlier swipe validation.
        $card = $this->check_card( $rfid );
        if ( is_wp_error( $card ) ) {
            return new WP_REST_Response( ['status' => 'failure', 'message' => 'System error validating card.'], 500 );
        }

        // Prepare the standardized log data array
        // We do NOT need to look up cardholder_id here; the service does it.
        $log_data = [
            'event_timestamp'       => current_time('mysql'),
            'controller_identifier' => $controller_sn,
            'door_number'           => $door_num,
            'rfid_id'               => $rfid,
            'event_type_code'       => 100, // Code for "Access Granted" / Kiosk Sign-in
            'access_granted'        => 1,   // Kiosk implies they were validated on the frontend
            'guest_count'           => $guests,
            'amenity_name'          => $amenity_name,
            // We pass the amenity name, and the Service will handle the description logic based on the 'KIOSK' role
        ];

        // A rejected sign-in is still logged (as denied) so there is an audit trail.
        if ( ! $card['is_valid'] ) {
            $log_data['event_type_code']   = 101; // Failure
            $log_data['access_granted']    = 0;
            $log_data['event_description'] = 'Kiosk sign-in rejected: ' . $card['message'];
            unset( $log_data['amenity_name'] );
            $log_data['guest_count'] = 0;
            Fsbhoa_Access_Service::process_and_write_event( $log_data );
            return new WP_REST_Response( ['status' => 'failure', 'message' => $card['message']], 403 );
        }

        // --- HAND OFF TO CENTRAL SERVICE ---
        $result = Fsbhoa_Access_Service::process_and_write_event( $log_data );

        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            $message = $result->get_error_message();

            // Special handling: If rate limited, we tell the Kiosk "Success" so it doesn't show an error.
            // This happens if they double-tap the button.
            if ( $error_code === 'rate_limit' ) {
                return new WP_REST_Response( ['status' => 'success', 'message' => 'Sign-in logged (duplicate).'], 200 );
            }

            error_log("KIOSK LOGGING FAILED: " . $message);
            return new WP_REST_Response( ['status' => 'failure', 'message' => 'System error logging sign-in.'], 500 );
        }

        return new WP_REST_Response( ['status' => 'success', 'message' => 'Sign-in logged.'], 200 );
    }


    /**
     * This is called when kiosk has had a card swipe.
     * The card id should be checked against the database and confirm this cardholder is valid.
     */
    public function validate_card_callback( WP_REST_Request $request ) {
        $rfid = sanitize_text_field($request['rfid']);

        // The station where the card was swiped, so a failure is logged at that station.
        $door_number = absint( $request->get_param('door_number') );
        if ( ! $door_number ) {
            return new WP_Error( 'bad_request', 'Missing door_number for the swiping station.', ['status' => 400] );
        }

        $card = $this->check_card( $rfid );
        if ( is_wp_error( $card ) ) { return $card; }

        $is_valid   = $card['is_valid'];
        $message    = $card['message'];
        $cardholder = $card['cardholder'];

        if ($is_valid) {
            // If the card is valid, send the cardholder data to the kiosk UI
            $cardholder_data = [
                'name'  => trim($cardholder->first_name . ' ' . $cardholder->last_name),
                'photo' => !empty($cardholder->photo) ? base64_encode($cardholder->photo) : null,
            ];
            $response = ['isValid' => true, 'message' => $message, 'cardholder' => $cardholder_data];
        } else {
            // If the card is NOT valid, log the failure at the station. The access service
            // writes the event and notifies the monitor.
            $result = Fsbhoa_Access_Service::process_and_write_event( [
                'event_timestamp'       => current_time('mysql'),
                'controller_identifier' => (string) Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL,
                'door_number'           => $door_number,
                'rfid_id'               => $rfid,
                'event_type_code'       => 101, // Failure
                'event_description'     => 'Kiosk Validation: ' . $message,
                'access_granted'        => 0,
            ] );
            if ( is_wp_error( $result ) && $result->get_error_code() !== 'rate_limit' ) {
                error_log( 'KIOSK: Failed to log rejected swipe: ' . $result->get_error_message() );
            }
            $response = ['isValid' => false, 'message' => $message];
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * Checks that a badge is active, belongs to an active cardholder, and has not expired.
     * A badge with no expiration date never expires.
     * @param string $rfid The badge number.
     * @return array|WP_Error ['is_valid' => bool, 'message' => string, 'cardholder' => object|null]
     */
    private function check_card( $rfid ) {
        global $wpdb;

        $cardholder = $wpdb->get_row($wpdb->prepare(
            "SELECT first_name, last_name, photo, ch.cardholder_status, cred.status AS credential_status, 
               cred.expiration_date AS card_expiry_date 
             FROM ac_cardholders ch JOIN ac_credentials cred ON ch.id = cred.cardholder_id
             WHERE cred.credential_value = %s AND cred.credential_type = 'MIFARE_BADGE' AND cred.status = 'active' AND ch.cardholder_status = 'active'",
            $rfid
        ));
        if ($wpdb->last_error) { return new WP_Error('db_error', 'Database error validating card.', ['status' => 500]); }

        $is_valid = true;
        $message = 'Card is valid.';

        if (!$cardholder) {
            $is_valid = false;
            $message = 'Card not found.';
        } elseif ($cardholder->cardholder_status !== 'active' ) {
            $is_valid = false;
            $message = 'Cardholder is not active.';
        } elseif ($cardholder->credential_status !== 'active') {
            $is_valid = false;
            $message = 'Card is not active.';
        } elseif (!empty($cardholder->card_expiry_date) && strtotime($cardholder->card_expiry_date) < current_time('timestamp')) {
            $is_valid = false;
            $message = 'Card has expired.';
        }

        return ['is_valid' => $is_valid, 'message' => $message, 'cardholder' => $cardholder];
    }

}

