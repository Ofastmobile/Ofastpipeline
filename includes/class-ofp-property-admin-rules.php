<?php
/**
 * Property admin ownership rules.
 *
 * Keeps the existing property CPT UI intact while enforcing:
 * - every property must have an explicit owner selection;
 * - admin/platform-owned properties use the explicit Platform owner;
 * - clients shown as owners must have an active paid listing subscription;
 * - an existing property owned by an expired client is not silently stripped
 *   of its owner while editing, but cannot be used for a new listing.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_Admin_Rules {

    public static function init(): void {
        add_action( 'admin_footer-post.php', [ __CLASS__, 'admin_owner_ui' ] );
        add_action( 'admin_footer-post-new.php', [ __CLASS__, 'admin_owner_ui' ] );
        add_action( 'save_post_ofp_property', [ __CLASS__, 'validate_owner_on_save' ], 99, 3 );
    }

    public static function admin_owner_ui(): void {
        if ( ! current_user_can( 'edit_posts' ) || ( $_GET['post_type'] ?? '' ) !== 'ofp_property' ) {
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        // New properties: only clients with a currently active paid listing
        // subscription are eligible. Existing selected owner is preserved in
        // the edit form so an expired subscription does not orphan old data.
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
        $eligible_json = wp_json_encode( $eligible_ids );
        ?>
        <script>
        (function () {
            var eligibleClientIds = <?php echo $eligible_json ?: '[]'; ?>;
            var select = document.querySelector('select[name="ofp_client_id"]');
            if (!select) return;

            // Explicit platform/admin owner. Empty remains an invalid/unassigned state.
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
                    // Preserve the current owner on an existing property so editing
                    // does not silently detach ownership; prevent it on new listings.
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
                marker.value = '';
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
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $owner_selected = isset( $_POST['ofp_owner_selected'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['ofp_owner_selected'] ) );
        $client_id = isset( $_POST['ofp_client_id'] ) ? absint( $_POST['ofp_client_id'] ) : 0;

        // A blank owner selection is never a valid saved/published property.
        if ( ! $owner_selected ) {
            if ( 'publish' === $post->post_status ) {
                remove_action( 'save_post_ofp_property', [ __CLASS__, 'validate_owner_on_save' ], 99 );
                wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
                add_action( 'save_post_ofp_property', [ __CLASS__, 'validate_owner_on_save' ], 99, 3 );
            }
            update_post_meta( $post_id, 'ofp_owner_validation', 'missing' );
            return;
        }

        // Platform/admin ownership is explicitly represented by client_id 0.
        if ( 0 === $client_id ) {
            update_post_meta( $post_id, 'ofp_client_id', 0 );
            update_post_meta( $post_id, 'ofp_owner_type', 'platform' );
            OFP_Property_CPT::sync_to_plugin_table( $post_id, 0 );
            update_post_meta( $post_id, 'ofp_owner_validation', 'valid' );
            return;
        }

        // Client ownership requires an active, paid listing subscription.
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
        update_post_meta( $post_id, 'ofp_owner_validation', 'valid' );
    }
}
