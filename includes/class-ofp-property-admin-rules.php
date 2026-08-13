<?php
/**
 * Property admin ownership rules.
 *
 * Completes the single property-engine ownership model:
 * - owner_type = platform|client
 * - owner_id   = NULL for platform, client ID for client-owned properties
 * - legacy client_id remains synchronized for backward compatibility
 * - new client listings require an active paid listing subscription
 * - blank ownership is never a valid saved/published property
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_Admin_Rules {

    public static function init(): void {
        add_action( 'admin_footer-post.php', [ __CLASS__, 'admin_owner_ui' ] );
        add_action( 'admin_footer-post-new.php', [ __CLASS__, 'admin_owner_ui' ] );
        add_action( 'save_post_ofp_property', [ __CLASS__, 'validate_owner_on_save' ], 99, 3 );
        add_action( 'save_post_ofp_property', [ __CLASS__, 'sync_formal_owner' ], 110, 3 );
        add_action( 'init', [ __CLASS__, 'ensure_schema' ], 20 );
    }

    /**
     * Add the formal ownership columns and migrate existing rows once.
     */
    public static function ensure_schema(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ofp_properties';

        $owner_type_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'owner_type'" );
        if ( empty( $owner_type_exists ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN owner_type VARCHAR(20) NOT NULL DEFAULT 'client' AFTER client_id" );
        }

        $owner_id_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'owner_id'" );
        if ( empty( $owner_id_exists ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN owner_id BIGINT UNSIGNED NULL AFTER owner_type" );
        }

        // Migrate legacy ownership exactly once. client_id=0 is the existing
        // platform/admin representation; positive client_id values are client-owned.
        if ( ! get_option( 'ofp_property_owner_model_migrated' ) ) {
            $wpdb->query(
                "UPDATE {$table}
                 SET owner_type = CASE WHEN client_id = 0 THEN 'platform' ELSE 'client' END,
                     owner_id   = CASE WHEN client_id = 0 THEN NULL ELSE client_id END"
            );
            update_option( 'ofp_property_owner_model_migrated', '1', false );
        }
    }

    public static function admin_owner_ui(): void {
        if ( ! current_user_can( 'edit_posts' ) || ( $_GET['post_type'] ?? '' ) !== 'ofp_property' ) {
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $eligible_ids = $wpdb->get_col(
            "SELECT DISTINCT c.id
             FROM {$p}ofp_clients c
             INNER JOIN {$p}ofp_subscriptions s ON s.client_id = c.id
             WHERE c.status = 'active'
               AND s.type = 'listing'
               AND s.status = 'paid'
               AND (s.period_end IS NULL OR s.period_end >= CURDATE())"
        );

        $eligible_ids = array_map( 'intval', $eligible_ids );
        ?>
        <script>
        (function () {
            var eligibleClientIds = <?php echo wp_json_encode( $eligible_ids ?: [] ); ?>;
            var select = document.querySelector('select[name="ofp_client_id"]');
            if (!select) return;

            var platform = select.querySelector('option[data-ofp-platform="1"]');
            if (!platform) {
                platform = document.createElement('option');
                platform.value = '0';
                platform.textContent = 'OFast Pipeline / Admin (Platform)';
                platform.setAttribute('data-ofp-platform', '1');
                select.insertBefore(platform, select.options[1] || null);
            }

            var selectedValue = select.value;
            Array.prototype.forEach.call(select.options, function (option) {
                if (!option.value || option.getAttribute('data-ofp-platform') === '1') return;
                var id = parseInt(option.value, 10);
                if (eligibleClientIds.indexOf(id) === -1) {
                    if (String(option.value) === String(selectedValue)) {
                        option.textContent += ' (Listing plan expired/inactive)';
                        option.setAttribute('data-ofp-expired-owner', '1');
                    } else {
                        option.remove();
                    }
                }
            });

            var marker = document.getElementById('ofp_owner_selected_marker');
            if (!marker) {
                marker = document.createElement('input');
                marker.type = 'hidden';
                marker.name = 'ofp_owner_selected';
                marker.id = 'ofp_owner_selected_marker';
                select.form.appendChild(marker);
            }

            function syncOwnerMarker() {
                marker.value = select.value !== '' ? '1' : '';
            }
            select.addEventListener('change', syncOwnerMarker);
            syncOwnerMarker();

            if (select.form) {
                select.form.addEventListener('submit', function (event) {
                    if (select.value === '') {
                        event.preventDefault();
                        alert('Please select a property owner: a client with an active listing plan, or OFast Pipeline / Admin (Platform).');
                        select.focus();
                    }

                    var expired = select.options[select.selectedIndex] && select.options[select.selectedIndex].getAttribute('data-ofp-expired-owner') === '1';
                    if (expired) {
                        event.preventDefault();
                        alert('This client’s listing plan is expired or inactive. Select an active client or OFast Pipeline / Admin (Platform).');
                        select.focus();
                    }
                });
            }
        })();
        </script>
        <?php
    }

    public static function validate_owner_on_save( int $post_id, WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $owner_selected = isset( $_POST['ofp_owner_selected'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['ofp_owner_selected'] ) );
        $client_id = isset( $_POST['ofp_client_id'] ) ? absint( $_POST['ofp_client_id'] ) : 0;

        if ( ! $owner_selected ) {
            if ( 'publish' === $post->post_status ) {
                remove_action( 'save_post_ofp_property', [ __CLASS__, 'validate_owner_on_save' ], 99 );
                wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
                add_action( 'save_post_ofp_property', [ __CLASS__, 'validate_owner_on_save' ], 99, 3 );
            }
            update_post_meta( $post_id, 'ofp_owner_validation', 'missing' );
            return;
        }

        if ( 0 === $client_id ) {
            update_post_meta( $post_id, 'ofp_client_id', 0 );
            update_post_meta( $post_id, 'ofp_owner_type', 'platform' );
            update_post_meta( $post_id, 'ofp_owner_id', '' );
            update_post_meta( $post_id, 'ofp_owner_validation', 'valid' );
            return;
        }

        if ( ! OFP_Subscription::has_active( 'listing', $client_id ) ) {
            update_post_meta( $post_id, 'ofp_owner_validation', 'inactive_listing_subscription' );
            if ( 'publish' === $post->post_status ) {
                remove_action( 'save_post_ofp_property', [ __CLASS__, 'validate_owner_on_save' ], 99 );
                wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
                add_action( 'save_post_ofp_property', [ __CLASS__, 'validate_owner_on_save' ], 99, 3 );
            }
            return;
        }

        update_post_meta( $post_id, 'ofp_owner_type', 'client' );
        update_post_meta( $post_id, 'ofp_owner_id', $client_id );
        update_post_meta( $post_id, 'ofp_owner_validation', 'valid' );
    }

    /**
     * Synchronize the formal owner fields and legacy client_id into the
     * plugin-table source of truth after the CPT's own save handler runs.
     */
    public static function sync_formal_owner( int $post_id, WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $owner_type = get_post_meta( $post_id, 'ofp_owner_type', true );
        $client_id  = absint( get_post_meta( $post_id, 'ofp_client_id', true ) );

        if ( $owner_type === 'platform' || 0 === $client_id ) {
            $owner_type = 'platform';
            $owner_id   = null;
            $legacy_client_id = 0;
        } else {
            $owner_type = 'client';
            $owner_id   = $client_id > 0 ? $client_id : null;
            $legacy_client_id = $client_id;
        }

        update_post_meta( $post_id, 'ofp_owner_type', $owner_type );
        update_post_meta( $post_id, 'ofp_owner_id', null === $owner_id ? '' : $owner_id );

        global $wpdb;
        $table = $wpdb->prefix . 'ofp_properties';
        $row_exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE wp_post_id = %d LIMIT 1", $post_id )
        );

        if ( $row_exists ) {
            $wpdb->update(
                $table,
                [
                    'client_id'  => $legacy_client_id,
                    'owner_type' => $owner_type,
                    'owner_id'   => $owner_id,
                    'updated_at' => current_time( 'mysql' ),
                ],
                [ 'wp_post_id' => $post_id ],
                [ '%d', '%s', null === $owner_id ? null : '%d', '%s' ],
                [ '%d' ]
            );
        } else {
            // The CPT's existing sync handler is responsible for creating the
            // full row. This fallback only prevents ownership from remaining
            // unset if the row was created by another path.
            OFP_Property_CPT::sync_to_plugin_table( $post_id, $legacy_client_id );
            $wpdb->update(
                $table,
                [ 'owner_type' => $owner_type, 'owner_id' => $owner_id ],
                [ 'wp_post_id' => $post_id ]
            );
        }
    }
}
