<?php
/**
 * Plugin Name: FSBHOA Access Control - Kiosk Module
 * Description: Touchscreen Kiosk UI and validation service for FSBHOA amenities. Requires FSBHOA Core.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

class Fsbhoa_AC_Kiosk {

    private static $instance = null;

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

        $this->includes();
        $this->instantiate_classes();
    }

    private function includes() {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-kiosk-rest-api.php';
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-kiosk-settings.php';
        // require_once plugin_dir_path( __FILE__ ) . 'includes/class-fsbhoa-kiosk-stations.php'; // Future step
    }

    private function instantiate_classes() {
        // 1. Initialize API Routes
        $api = new Fsbhoa_Kiosk_REST_API();
        add_action( 'rest_api_init', array( $api, 'register_routes' ) );

        // 2. Instantiate Settings
        if ( is_admin() ) {
            new Fsbhoa_Kiosk_Settings();
        }
        // 3. Inject Kiosk Service into Core's System Status page
        add_filter( 'fsbhoa_system_services', function( $services ) {
            $services['fsbhoa_kiosk'] = 'Kiosk Service';
            return $services;
        });

        // 4. Register Admin Menus
        add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
    }

    public function register_admin_menus() {
        // Add Amenities under the Core's main menu slug ('fsbhoa-ac-core')
        $amenity_page = new Fsbhoa_Amenity_Admin_Page();
        add_submenu_page(
            'fsbhoa-ac-core',           // Parent slug from Core
            'Amenities',                // Page Title
            'Amenities',                // Menu Title
            'manage_options',           // Capability
            'fsbhoa-ac-amenities',      // Menu Slug
            array( $amenity_page, 'render_page' )
        );
    }

    public function core_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>FSBHOA Kiosk Plugin:</strong> The FSBHOA Core plugin is missing or deactivated. The Kiosk module will not function.</p></div>';
    }
}

// Start the plugin
Fsbhoa_AC_Kiosk::get_instance();

