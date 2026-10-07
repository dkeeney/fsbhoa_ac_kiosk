<?php
/**
 * Plugin Name: FSBHOA Access Control - Kiosk 
 * Description: Touchscreen Kiosk UI and validation service for FSBHOA amenities. Requires FSBHOA Core.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

class Fsbhoa_AC_Kiosk {

    // Serial of the kiosk's virtual controller. Other plugins also create VIRTUAL_KIOSK
    // controllers (e.g. 900001 for QR scans), so always match on this serial, not the type alone.
    const VIRTUAL_CONTROLLER_SERIAL = 900000;

    private static $instance = null;
    private $settings_page = null; // Store settings instance

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Wait for Core to load first
        add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
    }

    public function init_plugin() {
        // Enforce Core Dependency
        if ( ! class_exists( 'Fsbhoa_Access_Service' ) ) {
            add_action( 'admin_notices', array( $this, 'core_missing_notice' ) );
            return;
        }

        // Ensure virtual kiosk controller & gates exist in the database
        $this->ensure_virtual_kiosk_controller();

        $this->includes();
        $this->instantiate_classes();
    }

    private function includes() {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-kiosk-rest-api.php';
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-kiosk-settings.php';
        require_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-fsbhoa-kiosk-stations-page.php';
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-kiosk-admin-override.php';
    }

    private function instantiate_classes() {
        // 1. Initialize API Routes
        $api = new Fsbhoa_Kiosk_REST_API();
        add_action( 'rest_api_init', array( $api, 'register_routes' ) );

        // 2. Instantiate Settings (and store reference so we don't duplicate)
        if ( is_admin() ) {
            $this->settings_page = new Fsbhoa_Kiosk_Settings();
        }

        // 3. Admin Override sign-in on the cardholder list (front-end page and AJAX)
        new Fsbhoa_Kiosk_Admin_Override();

        // 4. Inject Kiosk Service into Core's System Status page
        add_filter( 'fsbhoa_system_services', function( $services ) {
            $services['fsbhoa_kiosk'] = 'Kiosk Service';
            return $services;
        });

        // 5. Register Admin Menus
        add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
    }

    public function register_admin_menus() {
        // Adding submenu for Kiosk Settings using the stored instance
        if ( $this->settings_page ) {
            add_submenu_page(
                'fsbhoa_ac_main_menu',
                'Kiosk Settings',
                'Kiosk Settings',
                'manage_options',
                'fsbhoa_kiosk_settings',
                array( $this->settings_page, 'render_kiosk_settings_page' )
            );
        }

        // Kiosk Stations Page under Core's main menu slug
        $stations_page = new Fsbhoa_Kiosk_Stations_Page();
        add_submenu_page(
            'fsbhoa_ac_main_menu',
            'Kiosk Stations',
            'Kiosk Stations',
            'manage_options',
            'fsbhoa-ac-kiosk-stations',
            array( $stations_page, 'render_page' )
        );
    }

    public function core_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>FSBHOA Kiosk Plugin:</strong> The FSBHOA Core plugin is missing or deactivated. The Kiosk module will not function.</p></div>';
    }

    private function ensure_virtual_kiosk_controller() {
        global $wpdb;

        $controllers_table = 'ac_controllers';
        $doors_table = 'ac_doors';

        // 1. Check if the VIRTUAL_KIOSK controller already exists (matching 900000)
        $controller_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT controller_record_id FROM {$controllers_table} WHERE type = %s AND uhppoted_device_id = %d LIMIT 1",
            'VIRTUAL_KIOSK',
            self::VIRTUAL_CONTROLLER_SERIAL
        ) );

        // If not, create it
        if ( ! $controller_id ) {
            $wpdb->insert( $controllers_table, [
                'uhppoted_device_id' => self::VIRTUAL_CONTROLLER_SERIAL,
                'friendly_name'      => 'Virtual Kiosk Controller',
                'notes'              => 'Auto-seeded by Kiosk module for virtual check-ins.',
                'type'               => 'VIRTUAL_KIOSK',
            ] );
            $controller_id = $wpdb->insert_id;
        }

        // 2. Ensure a default door/gate exists for this controller
        $door_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT door_record_id FROM {$doors_table} WHERE controller_record_id = %d AND door_number_on_controller = %d LIMIT 1",
            $controller_id,
            1
        ) );

        if ( ! $door_id ) {
            $wpdb->insert( $doors_table, [
                'controller_record_id'      => $controller_id,
                'door_number_on_controller' => 1,
                'friendly_name'             => 'Test Kiosk',
                'door_role'                 => 'KIOSK',
            ] );
        }
    }

}

// Start the plugin
Fsbhoa_AC_Kiosk::get_instance();
