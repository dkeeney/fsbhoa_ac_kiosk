<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Kiosk_Stations_Page {

    public function render_page() {
        global $wpdb;

        // Clean the current URL of any action parameters so forms submit cleanly
        $current_page_url = remove_query_arg( [ 'action', 'station_id', '_wpnonce' ] );

        // Only doors on the kiosk's own virtual controller are kiosk stations. Other
        // VIRTUAL_KIOSK controllers (e.g. 900001 for QR scans) belong to other plugins.
        $controller_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT controller_record_id FROM ac_controllers WHERE type = %s AND uhppoted_device_id = %d LIMIT 1",
            'VIRTUAL_KIOSK',
            Fsbhoa_AC_Kiosk::VIRTUAL_CONTROLLER_SERIAL
        ) );

        // 1. Handle form submission for ADDING a new kiosk station
        // Specifically look for 'fsbhoa_add_nonce' to avoid collisions
        if ( isset( $_POST['submit_kiosk'] ) && check_admin_referer( 'fsbhoa_save_kiosk_station', 'fsbhoa_add_nonce' ) ) {
            $kiosk_name  = sanitize_text_field( $_POST['kiosk_friendly_name'] );
            $kiosk_notes = isset( $_POST['kiosk_notes'] ) ? sanitize_textarea_field( $_POST['kiosk_notes'] ) : '';

            if ( $controller_id && ! empty( $kiosk_name ) ) {
                $max_door_num = $wpdb->get_var( $wpdb->prepare(
                    "SELECT MAX(door_number_on_controller) FROM ac_doors WHERE controller_record_id = %d",
                    $controller_id
                ) );
                $next_door_num = $max_door_num ? absint( $max_door_num ) + 1 : 1;

                $inserted = $wpdb->insert( 'ac_doors', [
                    'controller_record_id'      => $controller_id,
                    'door_number_on_controller' => $next_door_num,
                    'friendly_name'             => $kiosk_name,
                    'door_role'                 => 'KIOSK',
                    'notes'                     => $kiosk_notes,
                ] );

                if ( false !== $inserted ) {
                    echo '<div class="updated"><p>Kiosk station added successfully with Slot #' . esc_html( $next_door_num ) . '.</p></div>';
                } else {
                    echo '<div class="error"><p>Error adding kiosk station: ' . esc_html( $wpdb->last_error ) . '</p></div>';
                }
            }
        }

        // 2. Handle form submission for UPDATING an existing kiosk station
        // Specifically look for 'fsbhoa_edit_nonce' 
        if ( isset( $_POST['update_kiosk'] ) && check_admin_referer( 'fsbhoa_update_kiosk_station', 'fsbhoa_edit_nonce' ) ) {
            $station_id  = absint( $_POST['station_id'] );
            $kiosk_name  = sanitize_text_field( $_POST['kiosk_friendly_name'] );
            $kiosk_notes = isset( $_POST['kiosk_notes'] ) ? sanitize_textarea_field( $_POST['kiosk_notes'] ) : '';

            if ( $station_id && ! empty( $kiosk_name ) ) {
                $updated = $wpdb->update( 'ac_doors', [
                    'friendly_name' => $kiosk_name,
                    'notes'         => $kiosk_notes,
                ], [ 'door_record_id' => $station_id, 'controller_record_id' => $controller_id ], [ '%s', '%s' ], [ '%d', '%d' ] );

                if ( false !== $updated ) {
                    echo '<div class="updated"><p>Kiosk station updated successfully.</p></div>';
                } else {
                    echo '<div class="error"><p>Error updating kiosk station: ' . esc_html( $wpdb->last_error ) . '</p></div>';
                }
            }
        }

        // 3. Handle DELETION of a kiosk station
        // This uses a GET request, so it safely uses the default URL _wpnonce
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_station' && isset( $_GET['station_id'] ) ) {
            $station_id = absint( $_GET['station_id'] );
            
            if ( check_admin_referer( 'fsbhoa_delete_station_' . $station_id ) ) {
                $station_info = $wpdb->get_row( $wpdb->prepare( 
                    "SELECT d.*, c.uhppoted_device_id FROM ac_doors d JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id WHERE d.door_record_id = %d AND d.controller_record_id = %d", 
                    $station_id,
                    $controller_id
                ));

                $has_logs = false;
                if ( $station_info ) {
                    $log_count = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM ac_access_log WHERE controller_identifier = %s AND door_number = %d",
                        $station_info->uhppoted_device_id,
                        $station_info->door_number_on_controller
                    ));
                    $has_logs = ( $log_count > 0 );
                }

                if ( ! $station_info ) {
                    echo '<div class="error"><p>Kiosk station not found.</p></div>';
                } elseif ( $has_logs ) {
                    echo '<div class="notice notice-error"><p><strong>Cannot Delete Station:</strong> This kiosk station has historical access logs associated with it. Retaining the record preserves your community audit trail.</p></div>';
                } else {
                    $deleted = $wpdb->delete( 'ac_doors', [ 'door_record_id' => $station_id, 'controller_record_id' => $controller_id ], [ '%d', '%d' ] );
                    if ( false !== $deleted && $deleted > 0 ) {
                        echo '<div class="updated"><p>Kiosk station deleted successfully.</p></div>';
                    } else {
                        echo '<div class="error"><p>Error deleting kiosk station. It may have already been removed.</p></div>';
                    }
                }
            }
        }

        // --- RENDER EDIT VIEW ---
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit_station' && isset( $_GET['station_id'] ) ) {
            $station_id = absint( $_GET['station_id'] );
            $station = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM ac_doors WHERE door_record_id = %d AND controller_record_id = %d", $station_id, $controller_id ) );
            
            if ( $station ) {
                ?>
                <div class="wrap">
                    <h1>Edit Kiosk Station</h1>
                    <a href="<?php echo esc_url( $current_page_url ); ?>" class="button">&larr; Back to Kiosk Stations</a>
                    <br><br>
                    <form method="post" action="<?php echo esc_url( $current_page_url ); ?>">
                        <?php wp_nonce_field( 'fsbhoa_update_kiosk_station', 'fsbhoa_edit_nonce' ); ?>
                        <input type="hidden" name="station_id" value="<?php echo esc_attr( $station->door_record_id ); ?>">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="kiosk_friendly_name">Station Name</label></th>
                                <td><input type="text" name="kiosk_friendly_name" id="kiosk_friendly_name" class="regular-text" value="<?php echo esc_attr( $station->friendly_name ); ?>" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="door_number">Slot / Door Number</label></th>
                                <td>
                                    <input type="number" name="door_number" id="door_number" value="<?php echo esc_attr( $station->door_number_on_controller ); ?>" class="small-text" readonly>
                                    <p class="description">Slot numbers are auto-assigned and locked to preserve historical audit trails.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="kiosk_notes">Notes</label></th>
                                <td><input type="text" name="kiosk_notes" id="kiosk_notes" class="regular-text" value="<?php echo esc_attr( $station->notes ); ?>"></td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit" name="update_kiosk" class="button button-primary" value="Save Changes">
                        </p>
                    </form>
                </div>
                <?php
                return; // Stop rendering the rest of the page
            }
        }

        // --- RENDER DEFAULT LIST VIEW ---
        $stations = $wpdb->get_results( $wpdb->prepare( "
            SELECT d.* FROM ac_doors d
            WHERE d.controller_record_id = %d
            ORDER BY d.door_number_on_controller ASC
        ", $controller_id ) );
        ?>
        <div class="wrap">
            <h1>Kiosk Stations</h1>
            <p>Manage physical touchscreen kiosks deployed across the community.</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="manage-column" style="width: 80px;">Actions</th>
                        <th>Station Name</th>
                        <th>Door / Slot Number</th>
                        <th>Role</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $stations ) ) : ?>
                        <tr><td colspan="5">No kiosk stations configured yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $stations as $station ) : 
                            $delete_nonce = wp_create_nonce( 'fsbhoa_delete_station_' . $station->door_record_id );
                            
                            $edit_url = add_query_arg( [
                                'action'     => 'edit_station',
                                'station_id' => absint( $station->door_record_id )
                            ], $current_page_url );
                            
                            $delete_url = add_query_arg( [
                                'action'     => 'delete_station',
                                'station_id' => absint( $station->door_record_id ),
                                '_wpnonce'   => $delete_nonce
                            ], $current_page_url );
                        ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url( $edit_url ); ?>" title="Edit Station" style="text-decoration: none; margin-right: 8px;">
                                        <span class="dashicons dashicons-edit"></span>
                                    </a>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" title="Delete Station" style="text-decoration: none;" onclick="return confirm('Are you sure you want to delete this kiosk station?');">
                                        <span class="dashicons dashicons-trash" style="color: #d63638;"></span>
                                    </a>
                                </td>
                                <td><strong><?php echo esc_html( $station->friendly_name ); ?></strong></td>
                                <td><?php echo esc_html( $station->door_number_on_controller ); ?></td>
                                <td><?php echo esc_html( $station->door_role ); ?></td>
                                <td><?php echo esc_html( $station->notes ?? '' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top: 2em;">Add New Kiosk Station</h2>
            <form method="post" action="<?php echo esc_url( $current_page_url ); ?>">
                <?php wp_nonce_field( 'fsbhoa_save_kiosk_station', 'fsbhoa_add_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="kiosk_friendly_name">Station Name</label></th>
                        <td><input type="text" name="kiosk_friendly_name" id="kiosk_friendly_name" class="regular-text" placeholder="e.g., Pool Kiosk" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kiosk_notes">Notes</label></th>
                        <td><input type="text" name="kiosk_notes" id="kiosk_notes" class="regular-text" placeholder="e.g., Located at the main clubhouse entrance"></td>
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

