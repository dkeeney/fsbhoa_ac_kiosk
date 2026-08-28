<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Kiosk_Stations_Page {

    public function render_page() {
        global $wpdb;

        // Handle form submission for adding a new kiosk station
        if ( isset( $_POST['submit_kiosk'] ) && check_admin_referer( 'fsbhoa_save_kiosk_station' ) ) {
            $kiosk_name = sanitize_text_field( $_POST['kiosk_friendly_name'] );
            $door_num   = absint( $_POST['door_number'] );

            // Get the VIRTUAL_KIOSK controller ID
            $controller_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT controller_record_id FROM ac_controllers WHERE type = %s LIMIT 1",
                'VIRTUAL_KIOSK'
            ) );

            if ( $controller_id && ! empty( $kiosk_name ) ) {
                $wpdb->insert( 'ac_doors', [
                    'controller_record_id'      => $controller_id,
                    'door_number_on_controller' => $door_num,
                    'friendly_name'             => $kiosk_name,
                    'door_role'                 => 'KIOSK',
                ] );
                echo '<div class="updated"><p>Kiosk station added successfully.</p></div>';
            }
        }

        // Fetch existing virtual kiosk stations
        $stations = $wpdb->get_results( "
            SELECT d.* FROM ac_doors d
            JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id
            WHERE c.type = 'VIRTUAL_KIOSK'
            ORDER BY d.door_number_on_controller ASC
        " );

        ?>
        <div class="wrap">
            <h1>Kiosk Stations</h1>
            <p>Manage physical touchscreen kiosks deployed across the community.</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Station Name</th>
                        <th>Controller ID</th>
                        <th>Door / Slot Number</th>
                        <th>Role</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $stations ) ) : ?>
                        <tr><td colspan="4">No kiosk stations configured yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $stations as $station ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $station->friendly_name ); ?></strong></td>
                                <td>Virtual Kiosk Controller (900000)</td>
                                <td><?php echo esc_html( $station->door_number_on_controller ); ?></td>
                                <td><?php echo esc_html( $station->door_role ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2>Add New Kiosk Station</h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'fsbhoa_save_kiosk_station' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="kiosk_friendly_name">Station Name</label></th>
                        <td><input type="text" name="kiosk_friendly_name" id="kiosk_friendly_name" class="regular-text" placeholder="e.g., Pool Kiosk" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="door_number">Slot / Door Number</label></th>
                        <td><input type="number" name="door_number" id="door_number" value="2" min="1" max="10" class="small-text" required></td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="submit_kiosk" id="submit_kiosk" class="button button-primary" value="Add Kiosk Station">
                </p>
            </form>
        </div>
        <?php
    }
}

