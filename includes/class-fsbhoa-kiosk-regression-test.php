<?php
/**
 * Adds kiosk checks to core's Diagnostics test suite (filter 'fsbhoa_regression_test_steps').
 * They call the kiosk's own REST endpoints with its API key, the way the kiosk service does.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Kiosk_Regression_Test {

    // Core's System test card ("Kiosk Test User").
    const TEST_RFID = '22222222';

    public function __construct() {
        add_filter( 'fsbhoa_regression_test_steps', array( $this, 'add_steps' ) );
    }

    public function add_steps( $steps ) {
        $steps[] = [ 'id' => 'kiosk_service_running', 'label' => 'Kiosk: checking the kiosk service answers...',
                     'callback' => [ $this, 'check_service_running' ] ];
        $steps[] = [ 'id' => 'kiosk_config',          'label' => 'Kiosk: fetching the kiosk config (amenities)...',
                     'callback' => [ $this, 'check_config' ] ];
        $steps[] = [ 'id' => 'kiosk_validate_card',   'label' => 'Kiosk: checking the test card is accepted...',
                     'callback' => [ $this, 'check_validate_card' ] ];
        $steps[] = [ 'id' => 'kiosk_signin',          'label' => 'Kiosk: signing in the test card via REST API...',
                     'callback' => [ $this, 'run_signin' ] ];
        $steps[] = [ 'id' => 'kiosk_verify_signin',   'label' => 'Kiosk: verifying the sign-in was logged at a kiosk station...',
                     'callback' => [ $this, 'verify_signin' ] ];
        return $steps;
    }

    /**
     * The kiosk page is served by the Go service; any HTTP answer means it is up.
     */
    public function check_service_running() {
        $port = absint( get_option( 'fsbhoa_kiosk_port', 8080 ) );
        $host = wp_parse_url( get_site_url(), PHP_URL_HOST );
        $url  = sprintf( 'https://%s:%d/', $host, $port );

        $response = wp_remote_get( $url, [ 'timeout' => 5, 'sslverify' => false ] );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'kiosk_down', 'Kiosk service did not answer at ' . $url . ': ' . $response->get_error_message() );
        }
        return 'Kiosk service answered at ' . $url . ' (HTTP ' . wp_remote_retrieve_response_code( $response ) . ').';
    }

    public function check_config() {
        $data = $this->call_api( 'GET', '/kiosk/config' );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $count = isset( $data['amenities'] ) ? count( $data['amenities'] ) : 0;
        if ( ! $count ) {
            return new WP_Error( 'no_amenities', 'Kiosk config has no active amenities.' );
        }
        $idle = ! empty( $data['idle_amenity'] ) ? $data['idle_amenity'] : 'None';
        return sprintf( 'Kiosk config: %d active amenities; inactivity sign-in: %s after %ds.', $count, $idle, $data['idle_timeout_seconds'] ?? 0 );
    }

    public function check_validate_card() {
        $station = $this->get_test_station();
        if ( is_wp_error( $station ) ) {
            return $station;
        }
        $data = $this->call_api( 'GET', '/kiosk/validate-card/' . self::TEST_RFID . '?door_number=' . $station->door_number_on_controller );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( empty( $data['isValid'] ) ) {
            return new WP_Error( 'card_rejected', 'Test card ' . self::TEST_RFID . ' was rejected: ' . ( $data['message'] ?? 'no reason' ) );
        }
        return 'Test card ' . self::TEST_RFID . ' accepted (' . ( $data['cardholder']['name'] ?? '' ) . ').';
    }

    public function run_signin() {
        $station = $this->get_test_station();
        if ( is_wp_error( $station ) ) {
            return $station;
        }
        $data = $this->call_api( 'POST', '/kiosk/log-signin', [
            'rfid'          => self::TEST_RFID,
            'amenity'       => 'Test Amenity',
            'guests'        => 0,
            'serial_number' => (string) Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL,
            'door_number'   => (int) $station->door_number_on_controller,
        ] );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        return 'Kiosk sign-in sent for station "' . $station->friendly_name . '".';
    }

    public function verify_signin() {
        global $wpdb;
        $log = $wpdb->get_row( $wpdb->prepare(
            "SELECT log_id, door_number FROM ac_access_log
             WHERE rfid_id = %s AND controller_identifier = %s AND access_granted = 1
               AND event_timestamp > NOW() - INTERVAL 30 SECOND
             ORDER BY log_id DESC LIMIT 1",
            self::TEST_RFID,
            (string) Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL
        ) );
        if ( ! $log ) {
            return new WP_Error( 'not_logged', 'No kiosk sign-in for the test card found at controller ' . Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL . ' in the last 30 seconds.' );
        }
        return sprintf( 'Kiosk sign-in logged (log %d, station %d).', $log->log_id, $log->door_number );
    }

    /**
     * The first real station (slot 1 and up) on the kiosk's virtual controller.
     */
    private function get_test_station() {
        global $wpdb;
        $station = $wpdb->get_row( $wpdb->prepare(
            "SELECT d.door_number_on_controller, d.friendly_name
             FROM ac_doors d JOIN ac_controllers c ON c.controller_record_id = d.controller_record_id
             WHERE c.uhppoted_device_id = %d AND d.door_number_on_controller > 0
             ORDER BY d.door_number_on_controller ASC LIMIT 1",
            Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL
        ) );
        if ( ! $station ) {
            return new WP_Error( 'no_station', 'No kiosk station is set up. Add one on the Kiosk Stations page.' );
        }
        return $station;
    }

    /**
     * Calls a kiosk REST endpoint with the kiosk API key. Returns the decoded body or a WP_Error.
     */
    private function call_api( $method, $path, $body = null ) {
        $api_key = get_option( 'fsbhoa_ac_kiosk_api_key', '' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_key', 'Kiosk API key is not configured (Kiosk Settings).' );
        }

        $args = [
            'method'  => $method,
            'headers' => [ 'X-API-KEY' => $api_key, 'Content-Type' => 'application/json' ],
            'timeout' => 10,
        ];
        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( get_site_url() . '/wp-json/fsbhoa/v1' . $path, $args );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'request_failed', 'Request to ' . $path . ' failed: ' . $response->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $code ) {
            $message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : wp_remote_retrieve_body( $response );
            return new WP_Error( 'bad_status', $path . ' returned HTTP ' . $code . ': ' . $message );
        }
        return is_array( $data ) ? $data : [];
    }
}
