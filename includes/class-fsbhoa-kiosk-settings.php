<?php
if ( ! defined( 'ABSPATH' ) ) { die; }

class Fsbhoa_Kiosk_Settings {

    private $kiosk_config_path = '/var/lib/fsbhoa/kiosk.json';

    public function __construct() {
        add_action( 'admin_init', array( $this, 'settings_api_init' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_kiosk_assets' ) );
        
        // AJAX handlers for saving and generating keys
        add_action( 'wp_ajax_fsbhoa_save_kiosk_settings', array( $this, 'ajax_save_kiosk_settings' ) );
        add_action( 'wp_ajax_fsbhoa_generate_kiosk_api_key', array( $this, 'ajax_generate_api_key' ) );
        
        // Re-generate config if a user saves settings on this specific page
        add_action( 'update_option', array( $this, 'trigger_config_update_on_save' ), 10, 3 );

        // THE CONNECTION HOOK: Listen to Core plugin's broadcast
        // Whenever Core saves its general settings, it fires this action.
        add_action( 'fsbhoa_update_service_configs', array( $this, 'update_kiosk_service_config' ) );
    }


    public function settings_api_init() {
        $kiosk_option_group = 'fsbhoa_kiosk_options';
        $kiosk_page_slug = 'fsbhoa_kiosk_settings';

        add_settings_section('fsbhoa_ac_kiosk_logo_section', 'Display & Operational Settings', null, $kiosk_page_slug);

        // Logo URL
        add_settings_field('fsbhoa_kiosk_logo_url_field', 'Kiosk Logo URL', array($this, 'render_media_uploader_field'), $kiosk_page_slug, 'fsbhoa_ac_kiosk_logo_section', [
            'id' => 'fsbhoa_kiosk_logo_url', 'type' => 'url', 'desc' => 'URL for the logo displayed in the heading of the kiosk idle screen.'
        ]);
        register_setting($kiosk_option_group, 'fsbhoa_kiosk_logo_url', 'esc_url_raw');

        // Splash URL
        add_settings_field('fsbhoa_kiosk_splash_url_field', 'Kiosk Splash Image', array($this, 'render_media_uploader_field'), $kiosk_page_slug, 'fsbhoa_ac_kiosk_logo_section', [
            'id' => 'fsbhoa_kiosk_splash_url', 'type' => 'url', 'desc' => 'Image displayed after a resident makes a selection. If blank, displays selected amenity icon.'
        ]);
        register_setting($kiosk_option_group, 'fsbhoa_kiosk_splash_url', 'esc_url_raw');

        // Port
        add_settings_field('fsbhoa_kiosk_port_field', 'Kiosk Service Port', array($this, 'render_field_callback'), $kiosk_page_slug, 'fsbhoa_ac_kiosk_logo_section', [
            'id' => 'fsbhoa_kiosk_port', 'type' => 'number', 'default' => 8080, 'desc' => 'The port the kiosk service listens on for secure HTTPS connections.'
        ]);
        register_setting($kiosk_option_group, 'fsbhoa_kiosk_port', 'absint');

        // API Key
        add_settings_field('fsbhoa_ac_kiosk_api_key_field', 'Kiosk API Key', array($this, 'render_api_key_field'), $kiosk_page_slug, 'fsbhoa_ac_kiosk_logo_section', [
            'id' => 'fsbhoa_ac_kiosk_api_key', 'desc' => 'Secret key used by the Kiosk service to authorize with WordPress.'
        ]);
        register_setting($kiosk_option_group, 'fsbhoa_ac_kiosk_api_key', 'sanitize_text_field');

        // Log File
        add_settings_field('fsbhoa_kiosk_log_file_field', 'Kiosk Log File Path', array($this, 'render_field_callback'), $kiosk_page_slug, 'fsbhoa_ac_kiosk_logo_section', [
            'id' => 'fsbhoa_kiosk_log_file', 'type' => 'text', 'default' => '/var/log/fsbhoa/kiosk.log', 'desc' => 'Full server path to the kiosk service log file.'
        ]);
        register_setting($kiosk_option_group, 'fsbhoa_kiosk_log_file', 'sanitize_text_field');

        // Max Guests
        add_settings_field('fsbhoa_kiosk_max_guests_field', 'Max Guests', array($this, 'render_field_callback'), $kiosk_page_slug, 'fsbhoa_ac_kiosk_logo_section', [
            'id' => 'fsbhoa_kiosk_max_guests', 'type' => 'number', 'default' => 8, 'desc' => 'The maximum number of guests a resident can sign in.'
        ]);
        register_setting($kiosk_option_group, 'fsbhoa_kiosk_max_guests', 'absint');
    }

    public function render_field_callback($args) {
        $id      = $args['id'];
        $type    = $args['type'] ?? 'text';
        $default = $args['default'] ?? '';
        $desc    = $args['desc'] ?? '';
        $value   = get_option($id, $default);

        echo "<input type='{$type}' name='{$id}' value='" . esc_attr($value) . "' class='regular-text' />";
        if ($desc) { echo "<p class='description'>" . esc_html($desc) . "</p>"; }
    }

    public function render_media_uploader_field($args) {
        $id    = $args['id'];
        $value = get_option($id, '');
        $desc  = $args['desc'] ?? '';
        ?>
        <input type="text" name="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <button type="button" class="button" id="<?php echo esc_attr($id); ?>-button">Upload/Select Image</button>
        <p class="description"><?php echo esc_html($desc); ?></p>
        <?php
    }

    public function render_api_key_field($args) {
        $id    = $args['id'];
        $desc  = $args['desc'] ?? '';
        $value = get_option($id, '');
        ?>
        <input type="text" name="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" readonly="readonly" placeholder="Click generate to create a new key" />
        <button type="button" class="button" id="fsbhoa-generate-kiosk-api-key-button">Generate New Key</button>
        <p class="description"><?php echo esc_html($desc); ?></p>
        <?php
    }

    public function render_kiosk_settings_page() {
        ?>
        <div class="wrap" id="fsbhoa-kiosk-settings-page">
            <h1>Kiosk Settings</h1>
            <?php do_settings_sections('fsbhoa_kiosk_settings'); ?>
            <p class="submit">
                <button type="button" id="fsbhoa-save-kiosk-settings-button" class="button button-primary">Save Kiosk Settings</button>
                <span id="fsbhoa-save-feedback" style="display: none; margin-left: 10px; vertical-align: middle;"></span>
            </p>
        </div>
        <?php
    }

    public function enqueue_kiosk_assets($hook) {
        if ($hook === 'fsbhoa-ac_page_fsbhoa_kiosk_settings') {
            wp_enqueue_media();
            // Enqueue the new script we will create in the next step
            wp_enqueue_script('fsbhoa-kiosk-settings-js', plugin_dir_url(dirname(__FILE__)) . 'assets/js/fsbhoa-kiosk-settings.js', array('jquery'), '1.0.0', true);
            
            wp_localize_script('fsbhoa-kiosk-settings-js', 'fsbhoa_kiosk_vars', array(
                'ajax_url'    => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('fsbhoa_kiosk_settings_nonce'),
                'key_nonce'   => wp_create_nonce('fsbhoa_generate_kiosk_key_nonce')
            ));
        }
    }

    public function ajax_save_kiosk_settings() {
        check_ajax_referer('fsbhoa_kiosk_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permission denied.', 403); }

        // Only the fields on this page may be saved, each with its own sanitizer.
        $allowed = [
            'fsbhoa_kiosk_logo_url'   => 'esc_url_raw',
            'fsbhoa_kiosk_splash_url' => 'esc_url_raw',
            'fsbhoa_kiosk_port'       => 'absint',
            'fsbhoa_ac_kiosk_api_key' => 'sanitize_text_field',
            'fsbhoa_kiosk_log_file'   => 'sanitize_text_field',
            'fsbhoa_kiosk_max_guests' => 'absint',
        ];

        $options = isset($_POST['options']) && is_array($_POST['options']) ? wp_unslash($_POST['options']) : [];
        foreach ($options as $option) {
            $name = isset($option['name']) ? sanitize_key($option['name']) : '';
            if (!isset($allowed[$name])) {
                error_log('KIOSK SETTINGS: Ignored unknown option in save request: ' . $name);
                continue;
            }
            update_option($name, call_user_func($allowed[$name], $option['value'] ?? ''));
        }

        $this->update_kiosk_service_config();
        wp_send_json_success('Kiosk settings saved successfully.');
    }

    public function ajax_generate_api_key() {
        check_ajax_referer('fsbhoa_generate_kiosk_key_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permission denied.', 403); }

        $new_key = base64_encode(random_bytes(32));
        wp_send_json_success(['api_key' => $new_key]);
    }

    public function trigger_config_update_on_save($option_name, $old_value, $new_value) {
        $kiosk_options = [
            'fsbhoa_kiosk_logo_url', 'fsbhoa_kiosk_port', 'fsbhoa_kiosk_log_file', 'fsbhoa_ac_kiosk_api_key'
        ];
        if (in_array($option_name, $kiosk_options)) {
            $this->update_kiosk_service_config();
        }
    }

    /**
     * This function generates kiosk.json. It pulls its own settings AND reads global
     * settings directly from the Core database entries (TLS paths, hostnames).
     */
    public function update_kiosk_service_config() {
        // Read Global Core Settings
        $tls_cert_path  = get_option('fsbhoa_ac_tls_cert_path', '');
        $tls_key_path   = get_option('fsbhoa_ac_tls_key_path', '');
        $wp_host        = trim((string) get_option('fsbhoa_ac_wp_host', ''));
        $websocket_port = get_option('fsbhoa_ac_websocket_port', 8083);
        $monitor_port   = get_option('fsbhoa_ac_monitor_port', 8082);
        $protocol       = (!empty($tls_cert_path) && !empty($tls_key_path)) ? 'https' : 'http';

        // Fail closed: without this server's own host name, the monitor and event URLs
        // could point at another system (e.g. a testbed reaching production). Keep the
        // existing kiosk.json rather than guess.
        if ($wp_host === '') {
            error_log('KIOSK CONFIG: Not writing ' . $this->kiosk_config_path . ': the WordPress host name (fsbhoa_ac_wp_host) is not set in the FSBHOA AC settings.');
            return;
        }

        // Read Kiosk Specific Settings
        $kiosk_port = get_option('fsbhoa_kiosk_port', 8080);
        
        $kiosk_config = [
            'wordpress_api_base_url' => get_site_url(),
            'api_key'                => get_option('fsbhoa_ac_kiosk_api_key', ''),
            'ssl_cert_path'          => sanitize_text_field($tls_cert_path),
            'ssl_key_path'           => sanitize_text_field($tls_key_path),
            'port'                   => ':' . absint($kiosk_port),
            'log_file'               => sanitize_text_field(get_option('fsbhoa_kiosk_log_file', '/var/log/fsbhoa/kiosk.log')),
            'max_guests'             => (int) get_option('fsbhoa_kiosk_max_guests', 8),
            'monitor_service_url'    => sprintf('%s://%s:%d', $protocol, $wp_host, absint($monitor_port)),
            'event_service_url'      => sprintf('%s://%s:%d', ($protocol === 'https' ? 'wss' : 'ws'), $wp_host, absint($websocket_port)),
        ];

        $json_data = json_encode($kiosk_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_dir(dirname($this->kiosk_config_path))) {
            mkdir(dirname($this->kiosk_config_path), 0755, true);
        }
        file_put_contents($this->kiosk_config_path, $json_data);
    }
}

