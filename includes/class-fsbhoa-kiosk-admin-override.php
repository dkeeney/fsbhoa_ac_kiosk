<?php
/**
 * Admin Override: lets staff sign in a cardholder from the cardholder list
 * without a card swipe. Runs entirely in WordPress (no kiosk service involved).
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Kiosk_Admin_Override {

    // Reserved slot on the virtual kiosk controller. Real stations are auto-numbered from 1.
    const STATION_DOOR_NUMBER = 0;
    const STATION_NAME        = 'Admin Override';

    // Cardholder IDs that have an active MIFARE badge, loaded once per page.
    private $badge_holder_ids = null;

    public function __construct() {
        add_action( 'fsbhoa_cardholder_list_action_icons', array( $this, 'render_action_icon' ) );
        add_action( 'fsbhoa_enqueue_cardholder_assets', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_fsbhoa_kiosk_admin_signin', array( $this, 'ajax_admin_signin' ) );
        add_filter( 'fsbhoa_monitor_map_gates', array( $this, 'hide_from_map' ) );
    }

    /**
     * Removes the Admin Override station from core's gate map. It has no physical
     * location: it is whichever browser staff use for the sign-in.
     */
    public function hide_from_map( $gates ) {
        return array_filter( $gates, function ( $gate ) {
            return ! ( (int) $gate['uhppoted_device_id'] === Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL
                && (int) $gate['door_number_on_controller'] === self::STATION_DOOR_NUMBER );
        } );
    }

    /**
     * Renders the "Sign-in (Admin Override)" icon in a cardholder list row.
     */
    public function render_action_icon( $cardholder ) {
        $cardholder_id = absint( $cardholder['id'] );
        $name          = trim( ( $cardholder['first_name'] ?? '' ) . ' ' . ( $cardholder['last_name'] ?? '' ) );

        $disabled_reason = '';
        if ( $cardholder['cardholder_status'] !== 'active' ) {
            $disabled_reason = __( 'Account not active', 'fsbhoa-ac' );
        } elseif ( ! in_array( $cardholder_id, $this->get_badge_holder_ids(), true ) ) {
            $disabled_reason = __( 'No active badge', 'fsbhoa-ac' );
        }

        if ( $disabled_reason === '' ) {
            printf(
                '<a href="#" class="fsbhoa-action-icon fsbhoa-kiosk-signin-link" data-id="%d" data-name="%s" title="%s"><span class="dashicons dashicons-external"></span></a>',
                $cardholder_id,
                esc_attr( $name ),
                esc_attr__( 'Sign-in (Admin Override)', 'fsbhoa-ac' )
            );
        } else {
            printf(
                '<span class="dashicons dashicons-external fsbhoa-action-disabled" title="%s"></span>',
                esc_attr( __( 'Sign-in (Admin Override)', 'fsbhoa-ac' ) . ' (' . $disabled_reason . ')' )
            );
        }
    }

    /**
     * Enqueues the sign-in dialog on the cardholder list page.
     */
    public function enqueue_assets() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );
        wp_enqueue_style( 'wp-jquery-ui-dialog' );
        wp_enqueue_style( 'fsbhoa-kiosk-admin-override', $plugin_url . 'assets/css/fsbhoa-kiosk-admin-override.css', array(), '1.0.0' );
        wp_enqueue_script( 'fsbhoa-kiosk-admin-override', $plugin_url . 'assets/js/fsbhoa-kiosk-admin-override.js', array( 'jquery', 'jquery-ui-dialog' ), '1.0.0', true );

        wp_localize_script( 'fsbhoa-kiosk-admin-override', 'fsbhoa_kiosk_override_vars', array(
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'fsbhoa_kiosk_admin_signin' ),
            'amenities'  => $this->get_active_amenities(),
            'max_guests' => $this->get_max_guests(),
        ) );
    }

    /**
     * AJAX: records an admin override sign-in through the core access service.
     */
    public function ajax_admin_signin() {
        check_ajax_referer( 'fsbhoa_kiosk_admin_signin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.', 403 );
        }

        global $wpdb;

        $cardholder_id = isset( $_POST['cardholder_id'] ) ? absint( $_POST['cardholder_id'] ) : 0;
        $amenity_name  = isset( $_POST['amenity'] ) ? sanitize_text_field( wp_unslash( $_POST['amenity'] ) ) : '';
        $guests        = isset( $_POST['guests'] ) ? absint( $_POST['guests'] ) : 0;

        if ( ! $cardholder_id || $amenity_name === '' ) {
            wp_send_json_error( 'Missing cardholder or amenity.', 400 );
        }
        if ( $guests > $this->get_max_guests() ) {
            wp_send_json_error( 'Too many guests.', 400 );
        }
        if ( ! in_array( $amenity_name, wp_list_pluck( $this->get_active_amenities(), 'name' ), true ) ) {
            wp_send_json_error( 'Unknown or inactive amenity.', 400 );
        }

        // The access service identifies the cardholder by badge, so use their active MIFARE badge.
        $badge = $wpdb->get_row( $wpdb->prepare(
            "SELECT cred.credential_value, cred.expiration_date
             FROM ac_credentials cred JOIN ac_cardholders ch ON ch.id = cred.cardholder_id
             WHERE ch.id = %d AND ch.cardholder_status = 'active'
               AND cred.credential_type = 'MIFARE_BADGE' AND cred.status = 'active'
             ORDER BY cred.id DESC LIMIT 1",
            $cardholder_id
        ) );
        if ( $wpdb->last_error ) {
            error_log( 'KIOSK ADMIN OVERRIDE: DB error looking up badge: ' . $wpdb->last_error );
            wp_send_json_error( 'Database error.', 500 );
        }
        if ( ! $badge ) {
            wp_send_json_error( 'Cardholder is not active or has no active badge.', 400 );
        }
        if ( ! empty( $badge->expiration_date ) && strtotime( $badge->expiration_date ) < current_time( 'timestamp' ) ) {
            wp_send_json_error( 'Badge has expired.', 400 );
        }

        $station = $this->get_station();
        if ( ! $station ) {
            wp_send_json_error( 'Admin Override station could not be created.', 500 );
        }

        $log_data = array(
            'event_timestamp'       => current_time( 'mysql' ),
            'controller_identifier' => (string) $station['serial'],
            'door_number'           => $station['door_number'],
            'rfid_id'               => $badge->credential_value,
            'event_type_code'       => 100, // Access Granted / Kiosk Sign-in
            'access_granted'        => 1,
            'guest_count'           => $guests,
            'amenity_name'          => $amenity_name,
        );

        $result = Fsbhoa_Access_Service::process_and_write_event( $log_data );

        if ( is_wp_error( $result ) ) {
            if ( $result->get_error_code() === 'rate_limit' ) {
                wp_send_json_success( 'Already signed in recently (duplicate ignored).' );
            }
            error_log( 'KIOSK ADMIN OVERRIDE FAILED: ' . $result->get_error_message() );
            wp_send_json_error( 'System error logging sign-in.', 500 );
        }

        wp_send_json_success( sprintf( 'Signed in to %s with %d guest(s).', $amenity_name, $guests ) );
    }

    /**
     * Finds the Admin Override station (reserved slot on the virtual kiosk controller),
     * creating it if needed.
     * @return array|null ['serial' => controller serial, 'door_number' => slot] or null on failure.
     */
    private function get_station() {
        global $wpdb;

        $controller_id = Fsbhoa_AC_Kiosk::ensure_virtual_kiosk_controller();
        if ( ! $controller_id ) {
            error_log( 'KIOSK ADMIN OVERRIDE: Virtual kiosk controller not available.' );
            return null;
        }

        $door_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT door_record_id FROM ac_doors WHERE controller_record_id = %d AND door_number_on_controller = %d LIMIT 1",
            $controller_id,
            self::STATION_DOOR_NUMBER
        ) );

        if ( ! $door_id ) {
            $inserted = $wpdb->insert( 'ac_doors', array(
                'controller_record_id'      => $controller_id,
                'door_number_on_controller' => self::STATION_DOOR_NUMBER,
                'friendly_name'             => self::STATION_NAME,
                'door_role'                 => 'KIOSK',
                'notes'                     => 'Auto-created for staff sign-ins from the cardholder list.',
            ) );
            if ( false === $inserted ) {
                error_log( 'KIOSK ADMIN OVERRIDE: Could not create station: ' . $wpdb->last_error );
                return null;
            }
        }

        return array(
            'serial'      => Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL,
            'door_number' => self::STATION_DOOR_NUMBER,
        );
    }

    private function get_badge_holder_ids() {
        global $wpdb;
        if ( null === $this->badge_holder_ids ) {
            $ids = $wpdb->get_col(
                "SELECT DISTINCT cardholder_id FROM ac_credentials WHERE credential_type = 'MIFARE_BADGE' AND status = 'active'"
            );
            $this->badge_holder_ids = array_map( 'intval', $ids );
        }
        return $this->badge_holder_ids;
    }

    private function get_active_amenities() {
        global $wpdb;
        $amenities = $wpdb->get_results(
            "SELECT name, image_url FROM ac_amenities WHERE is_active = 1 ORDER BY display_order ASC, name ASC",
            ARRAY_A
        );
        return $amenities ? $amenities : array();
    }

    private function get_max_guests() {
        return (int) get_option( 'fsbhoa_kiosk_max_guests', 8 );
    }
}
