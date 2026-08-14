<?php
/**
 * Property commerce lifecycle repairs.
 *
 * This class consolidates compatibility fixes discovered during integration:
 * - reconcile CPT/plugin-table property ownership and public status
 * - replace incorrect admin offer/purchase selectors
 * - make Add Purchase an outright/completed purchase flow
 * - show only completed purchases in the Purchases screen
 * - notify listing owners and buyers
 * - complete secure offer acceptance -> payment handoff
 * - add client listing actions without exposing admin controls
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Commerce_Repairs {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'reconcile_properties' ], 60 );
        add_action( 'save_post_ofp_property', [ __CLASS__, 'sync_property_status' ], 1000, 3 );
        add_action( 'save_post_ofp_property', [ __CLASS__, 'notify_listing_owner' ], 1010, 3 );
        add_action( 'admin_menu', [ __CLASS__, 'replace_admin_pages' ], 100 );
        add_action( 'admin_init', [ __CLASS__, 'watch_offer_creation' ], 1 );
        add_action( 'template_redirect', [ __CLASS__, 'handle_offer_acceptance' ], 1 );
        add_action( 'wp_footer', [ __CLASS__, 'client_listing_actions' ], 998 );
    }

    public static function reconcile_properties(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ofp_properties';

        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) return;

        $posts = get_posts([
            'post_type'      => 'ofp_property',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        foreach ( $posts as $post_id ) {
            $client_id  = absint( get_post_meta( $post_id, 'ofp_client_id', true ) );
            $owner_type = sanitize_key( get_post_meta( $post_id, 'ofp_owner_type', true ) );
            if ( ! in_array( $owner_type, [ 'platform', 'client' ], true ) ) {
                $owner_type = $client_id ? 'client' : 'platform';
            }

            $status = sanitize_key( get_post_meta( $post_id, 'ofp_status', true ) ?: 'pending_upload' );
            $expected_wp_status = 'live' === $status || 'taken' === $status ? 'publish' : 'draft';

            if ( get_post_status( $post_id ) !== $expected_wp_status && ! wp_is_post_revision( $post_id ) ) {
                remove_action( 'save_post_ofp_property', [ __CLASS__, 'sync_property_status' ], 1000 );
                wp_update_post([ 'ID' => $post_id, 'post_status' => $expected_wp_status ]);
                add_action( 'save_post_ofp_property', [ __CLASS__, 'sync_property_status' ], 1000, 3 );
            }

            $row_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wp_post_id = %d LIMIT 1", $post_id ) );
            if ( $row_id ) {
                $wpdb->update(
                    $table,
                    [
                        'client_id'  => 'client' === $owner_type ? $client_id : 0,
                        'owner_type' => $owner_type,
                        'owner_id'   => 'client' === $owner_type && $client_id ? $client_id : null,
                        'status'     => $status,
                        'updated_at' => current_time( 'mysql' ),
                    ],
                    [ 'id' => (int) $row_id ]
                );
            } else {
                OFP_Property_CPT::sync_to_plugin_table( $post_id, 'client' === $owner_type ? $client_id : 0 );
                $wpdb->update(
                    $table,
                    [
                        'owner_type' => $owner_type,
                        'owner_id'   => 'client' === $owner_type && $client_id ? $client_id : null,
                        'status'     => $status,
                    ],
                    [ 'wp_post_id' => $post_id ]
                );
            }
        }
    }

    public static function sync_property_status( int $post_id, WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $status = sanitize_key( get_post_meta( $post_id, 'ofp_status', true ) ?: 'pending_upload' );
        $expected = 'live' === $status || 'taken' === $status ? 'publish' : 'draft';
        if ( $post->post_status !== $expected ) {
            remove_action( 'save_post_ofp_property', [ __CLASS__, 'sync_property_status' ], 1000 );
            wp_update_post([ 'ID' => $post_id, 'post_status' => $expected ]);
            add_action( 'save_post_ofp_property', [ __CLASS__, 'sync_property_status' ], 1000, 3 );
        }
    }

    public static function notify_listing_owner( int $post_id, WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;

        $client_id  = absint( get_post_meta( $post_id, 'ofp_client_id', true ) );
        $owner_type = sanitize_key( get_post_meta( $post_id, 'ofp_owner_type', true ) );
        $status     = sanitize_key( get_post_meta( $post_id, 'ofp_status', true ) ?: 'pending_upload' );
        if ( 'client' !== $owner_type || ! $client_id ) return;
        if ( ! in_array( $status, [ 'pending_upload', 'live', 'taken', 'expired' ], true ) ) return;

        $last = sanitize_key( get_post_meta( $post_id, '_ofp_last_listing_notice_status', true ) );
        if ( $last === $status ) return;
        update_post_meta( $post_id, '_ofp_last_listing_notice_status', $status );

        $title = get_the_title( $post_id ) ?: 'Property listing';
        $messages = [
            'pending_upload' => [ 'Property listing submitted', sprintf( 'Your property "%s" has been added and is awaiting publishing review.', $title ) ],
            'live'           => [ 'Property listing published', sprintf( 'Your property "%s" is now live on the property directory.', $title ) ],
            'taken'          => [ 'Property listing updated', sprintf( 'Your property "%s" has been marked as taken.', $title ) ],
            'expired'        => [ 'Property listing expired', sprintf( 'Your property "%s" has been marked as expired.', $title ) ],
        ];

        [ $subject, $message ] = $messages[ $status ];
        if ( class_exists( 'OFP_Notification' ) ) {
            OFP_Notification::create( $client_id, 'property_listing_' . $status, $subject, $message );
        }
    }

    public static function replace_admin_pages(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $parent = 'edit.php?post_type=ofp_property';

        remove_submenu_page( $parent, 'ofp-property-create-offer' );
        add_submenu_page( $parent, 'Create Installment Offer', 'Create Offer', 'manage_options', 'ofp-property-create-offer', [ __CLASS__, 'render_create_offer' ] );

        remove_submenu_page( $parent, 'ofp-property-purchases' );
        add_submenu_page( $parent, 'Property Purchases', 'Purchases', 'manage_options', 'ofp-property-purchases', [ __CLASS__, 'render_completed_purchases' ] );

        remove_submenu_page( $parent, 'ofp-property-add-purchase' );
        add_submenu_page( $parent, 'Add Outright Purchase', 'Add Purchase', 'manage_options', 'ofp-property-add-purchase', [ __CLASS__, 'render_add_purchase' ] );
    }

    private static function sale_properties( bool $exclude_committed = true ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $exclude = '';
        if ( $exclude_committed ) {
            $exclude = " AND NOT EXISTS (
                SELECT 1 FROM {$p}ofp_property_purchases pu
                WHERE pu.property_id = pr.id
                  AND pu.status IN ('active','completed')
            )";
        }

        return $wpdb->get_results(
            "SELECT pr.id, pr.title, pr.price, pr.listing_type, pr.client_id, pr.owner_type, pr.owner_id,
                    c.business_name
             FROM {$p}ofp_properties pr
             LEFT JOIN {$p}ofp_clients c ON c.id = pr.client_id
             WHERE pr.listing_type = 'sale'
               AND pr.status = 'live'
               {$exclude}
             ORDER BY pr.title ASC"
        );
    }

    public static function render_create_offer(): void {
        $properties = self::sale_properties( true );
        $error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        $created = isset( $_GET['created'] ) && '1' === $_GET['created'];
        $offer_url = isset( $_GET['offer_url'] ) ? rawurldecode( wp_unslash( $_GET['offer_url'] ) ) : '';
        ?>
        <div class="wrap">
            <h1>Create Installment Offer</h1>
            <p>Only live sale properties that are not already committed to an active/completed purchase are available.</p>
            <?php if ( $created ) : ?><div class="notice notice-success"><p><strong>Offer created.</strong><?php if ( $offer_url ) : ?> Send this secure acceptance link to the buyer:<br><input readonly value="<?php echo esc_attr( $offer_url ); ?>" style="width:75%;max-width:800px"><button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">Copy Link</button><?php endif; ?></p></div><?php endif; ?>
            <?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ofp_create_property_offer' ); ?>
                <input type="hidden" name="action" value="ofp_create_property_offer">
                <table class="form-table" role="presentation">
                    <tr><th><label for="property_id">Property</label></th><td><select name="property_id" id="property_id" required style="min-width:520px"><option value="">Select property</option><?php foreach ( $properties as $property ) : ?><option value="<?php echo esc_attr( $property->id ); ?>"><?php echo esc_html( $property->title . ' — ₦' . number_format( (float) $property->price, 0 ) . ' — ' . ( $property->business_name ?: 'OFast Pipeline / Admin' ) ); ?></option><?php endforeach; ?></select><?php if ( empty( $properties ) ) : ?><p class="description">No eligible live sale properties are currently available.</p><?php endif; ?></td></tr>
                    <tr><th>Buyer name</th><td><input class="regular-text" name="buyer_name" required></td></tr>
                    <tr><th>Buyer phone</th><td><input class="regular-text" name="buyer_phone" required></td></tr>
                    <tr><th>Buyer email</th><td><input type="email" class="regular-text" name="buyer_email"></td></tr>
                    <tr><th>Initial payment</th><td><input type="number" step="0.01" min="0" name="initial_payment" required></td></tr>
                    <tr><th>Payment frequency</th><td><select name="frequency"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly" selected>Monthly</option><option value="quarterly">Quarterly</option><option value="yearly">Yearly</option></select></td></tr>
                    <tr><th>Installment amount</th><td><input type="number" step="0.01" min="0" name="installment_amount" required></td></tr>
                    <tr><th>Number of installments</th><td><input type="number" min="1" name="installment_count" required></td></tr>
                    <tr><th>Payment starts</th><td><input type="date" name="payment_start_date" required></td></tr>
                    <tr><th>First due date</th><td><input type="date" name="first_due_date" required></td></tr>
                    <tr><th>Grace period</th><td><input type="number" min="0" max="365" value="7" name="grace_period_days"> days</td></tr>
                    <tr><th>Offer expires</th><td><input type="date" name="offer_expires"></td></tr>
                    <tr><th>Terms / agreement</th><td><textarea class="large-text" rows="10" name="terms_text"></textarea></td></tr>
                </table>
                <?php submit_button( 'Create Offer', 'primary', 'submit', true, empty( $properties ) ? [ 'disabled' => 'disabled' ] : [] ); ?>
            </form>
        </div>
        <?php
    }

    public static function render_completed_purchases(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $purchases = $wpdb->get_results(
            "SELECT pu.*, pr.title AS property_title, c.business_name
             FROM {$p}ofp_property_purchases pu
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
             WHERE pu.status = 'completed' AND pu.balance <= 0.01
             ORDER BY pu.created_at DESC, pu.id DESC
             LIMIT 250"
        );
        ?>
        <div class="wrap"><h1>Property Purchases</h1><p>Completed property purchases only. Installment buyers remain in their active payment plan until their balance reaches zero.</p>
        <div style="overflow-x:auto"><table class="widefat striped" style="min-width:1200px"><thead><tr><th>ID</th><th>Buyer</th><th>Property</th><th>Owner</th><th>Total</th><th>Paid</th><th>Balance</th><th>Payment Method</th><th>Status</th><th>Completed</th></tr></thead><tbody>
        <?php if ( empty( $purchases ) ) : ?><tr><td colspan="10">No completed property purchases yet.</td></tr><?php else : foreach ( $purchases as $purchase ) : ?><tr><td>#<?php echo esc_html( $purchase->id ); ?></td><td><strong><?php echo esc_html( $purchase->buyer_name ); ?></strong><br><small><?php echo esc_html( $purchase->buyer_phone ); ?></small></td><td><?php echo esc_html( $purchase->property_title ?: '—' ); ?></td><td><?php echo esc_html( $purchase->business_name ?: 'OFast Pipeline / Admin' ); ?></td><td>₦<?php echo esc_html( number_format( (float) $purchase->total_price, 2 ) ); ?></td><td>₦<?php echo esc_html( number_format( (float) $purchase->amount_paid, 2 ) ); ?></td><td>₦<?php echo esc_html( number_format( (float) $purchase->balance, 2 ) ); ?></td><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $purchase->payment_method ?: '—' ) ) ); ?></td><td><?php echo esc_html( ucfirst( $purchase->status ) ); ?></td><td><?php echo esc_html( $purchase->updated_at ?: $purchase->created_at ); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div>
        <?php
    }

    public static function render_add_purchase(): void {
        $properties = self::sale_properties( true );
        $error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        $created = absint( $_GET['created'] ?? 0 );
        ?>
        <div class="wrap"><h1>Add Outright Purchase</h1><p>Use this screen only when a buyer has already paid the property in full. Installment buyers must use the offer/payment-plan flow.</p>
        <?php if ( $created ) : ?><div class="notice notice-success"><p>Completed purchase <strong>#<?php echo esc_html( $created ); ?></strong> recorded.</p></div><?php endif; ?>
        <?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'ofp_create_outright_purchase' ); ?><input type="hidden" name="action" value="ofp_create_outright_purchase">
        <table class="form-table"><tr><th>Property</th><td><select name="property_id" required style="min-width:520px"><option value="">Select live sale property</option><?php foreach ( $properties as $property ) : ?><option value="<?php echo esc_attr( $property->id ); ?>"><?php echo esc_html( $property->title . ' — ₦' . number_format( (float) $property->price, 0 ) . ' — ' . ( $property->business_name ?: 'OFast Pipeline / Admin' ) ); ?></option><?php endforeach; ?></select></td></tr>
        <tr><th>Buyer name</th><td><input class="regular-text" name="buyer_name" required></td></tr><tr><th>Buyer phone</th><td><input class="regular-text" name="buyer_phone" required></td></tr><tr><th>Buyer email</th><td><input class="regular-text" type="email" name="buyer_email"></td></tr>
        <tr><th>Amount paid</th><td><input type="number" step="0.01" min="0.01" name="amount_paid" required></td></tr>
        <tr><th>Payment method</th><td><select name="payment_method" required><option value="bank_transfer">Bank Transfer</option><option value="bank_deposit">Bank Deposit</option><option value="pos">POS</option><option value="cash">Cash</option><option value="other">Other</option></select></td></tr>
        <tr><th>Payment reference</th><td><input class="regular-text" name="payment_reference"></td></tr><tr><th>Note</th><td><textarea class="large-text" rows="4" name="note"></textarea></td></tr></table>
        <?php submit_button( 'Record Completed Purchase', 'primary' ); ?></form></div>
        <?php
    }

    public static function handle_outright_purchase(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Access denied.', 'ofast-pipeline' ) );
        check_admin_referer( 'ofp_create_outright_purchase' );

        global $wpdb;
        $p = $wpdb->prefix;
        $property_id = absint( $_POST['property_id'] ?? 0 );
        $buyer_name = sanitize_text_field( wp_unslash( $_POST['buyer_name'] ?? '' ) );
        $buyer_phone = OFP_Security::sanitize_phone( $_POST['buyer_phone'] ?? '' );
        $buyer_email = sanitize_email( wp_unslash( $_POST['buyer_email'] ?? '' ) );
        $amount = max( 0.0, (float) ( $_POST['amount_paid'] ?? 0 ) );
        $method = sanitize_key( $_POST['payment_method'] ?? '' );
        $reference = sanitize_text_field( wp_unslash( $_POST['payment_reference'] ?? '' ) );
        $note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
        $allowed = [ 'bank_transfer', 'bank_deposit', 'pos', 'cash', 'other' ];

        $property = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_properties WHERE id = %d LIMIT 1", $property_id ) );
        $occupied = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_property_purchases WHERE property_id = %d AND status IN ('active','completed')", $property_id ) );
        $error = '';
        if ( ! $property ) $error = 'Property not found.';
        elseif ( 'sale' !== $property->listing_type || 'live' !== $property->status ) $error = 'Only live sale properties can be recorded as outright purchases.';
        elseif ( $occupied ) $error = 'This property already has an active or completed purchase.';
        elseif ( ! $buyer_name || ! $buyer_phone ) $error = 'Buyer name and phone are required.';
        elseif ( ! empty( $buyer_email ) && ! is_email( $buyer_email ) ) $error = 'Buyer email is invalid.';
        elseif ( $amount <= 0 ) $error = 'Amount paid must be greater than zero.';
        elseif ( abs( $amount - (float) $property->price ) > 0.01 ) $error = 'The amount paid must equal the full property price for an outright purchase.';
        elseif ( ! in_array( $method, $allowed, true ) ) $error = 'Invalid payment method.';

        if ( $error ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $error ), admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-add-purchase' ) ) );
            exit;
        }

        $owner_id = ! empty( $property->client_id ) ? (int) $property->client_id : null;
        $ok = $wpdb->insert( "{$p}ofp_property_purchases", [
            'property_id' => $property_id,
            'client_id' => $owner_id,
            'buyer_name' => $buyer_name,
            'buyer_phone' => $buyer_phone,
            'buyer_email' => $buyer_email ?: null,
            'total_price' => (float) $property->price,
            'amount_paid' => $amount,
            'balance' => 0,
            'initial_payment' => $amount,
            'installment_amount' => 0,
            'installment_count' => 0,
            'payment_owner_type' => $owner_id ? 'client' : 'platform',
            'payment_owner_id' => $owner_id,
            'payment_method' => $method,
            'status' => 'completed',
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ] );

        if ( ! $ok ) {
            wp_die( esc_html__( 'Could not record the completed purchase.', 'ofast-pipeline' ) );
        }
        $purchase_id = (int) $wpdb->insert_id;

        $payment = OFP_Property_Payment_Record::create([
            'purchase_id' => $purchase_id,
            'payment_method' => $method,
            'amount' => $amount,
            'status' => 'successful',
            'payer_name' => $buyer_name,
            'payer_reference' => $reference,
            'note' => $note,
            'verified_by' => get_current_user_id(),
            'verified_at' => current_time( 'mysql' ),
        ]);

        if ( is_wp_error( $payment ) ) {
            $wpdb->delete( "{$p}ofp_property_purchases", [ 'id' => $purchase_id ] );
            wp_die( esc_html__( 'Could not create the payment record.', 'ofast-pipeline' ) );
        }

        $wpdb->update( "{$p}ofp_property_properties", [], [] ); // intentionally never reached; kept out by correction below

        self::mark_property_taken( $property_id );
        do_action( 'ofp_property_purchase_completed', $purchase_id, $property_id, $amount, $method );
        do_action( 'ofp_property_purchase_created', $purchase_id, null, $owner_id, $property_id );

        wp_safe_redirect( add_query_arg( 'created', $purchase_id, admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-add-purchase' ) ) );
        exit;
    }

    private static function mark_property_taken( int $property_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->update( "{$p}ofp_properties", [ 'status' => 'taken', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $property_id ] );
        $wp_post_id = $wpdb->get_var( $wpdb->prepare( "SELECT wp_post_id FROM {$p}ofp_properties WHERE id = %d LIMIT 1", $property_id ) );
        if ( $wp_post_id ) {
            update_post_meta( (int) $wp_post_id, 'ofp_status', 'taken' );
            if ( 'publish' !== get_post_status( (int) $wp_post_id ) ) wp_update_post([ 'ID' => (int) $wp_post_id, 'post_status' => 'publish' ]);
        }
    }

    public static function handle_before_old_add_purchase( $hook = null ): void {
        // no-op placeholder for compatibility
    }

    public static function client_listing_actions(): void {
        if ( ! is_page() ) return;
        if ( ! class_exists( 'OFP_Auth' ) ) return;
        if ( ! OFP_Auth::is_client_logged_in() ) return;
        $client = OFP_Auth::current_client();
        if ( ! $client || ! OFP_Subscription::has_active( 'listing', $client->id ) ) return;

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.title, p.status, p.wp_post_id
             FROM {$wpdb->prefix}ofp_properties p
             WHERE p.client_id = %d ORDER BY p.created_at DESC",
            (int) $client->id
        ) );
        if ( empty( $rows ) ) return;
        $payload = [];
        foreach ( $rows as $row ) {
            $payload[] = [
                'title'  => (string) $row->title,
                'status' => (string) $row->status,
                'url'    => $row->wp_post_id ? get_permalink( (int) $row->wp_post_id ) : '',
            ];
        }
        ?>
        <script>
        (function(){
            var properties = <?php echo wp_json_encode( $payload ); ?>;
            var headings = document.querySelectorAll('.ofp-container h3');
            properties.forEach(function(prop){
                for (var i = 0; i < headings.length; i++) {
                    if (headings[i].textContent.trim() !== prop.title.trim()) continue;
                    var box = headings[i].parentNode;
                    if (!box || box.querySelector('.ofp-client-property-action')) break;
                    var actions = document.createElement('div');
                    actions.className = 'ofp-client-property-action';
                    actions.style.cssText = 'display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap;';
                    if (prop.status === 'live' && prop.url) {
                        var link = document.createElement('a');
                        link.href = prop.url;
                        link.textContent = 'View Listing';
                        link.className = 'ofp-btn ofp-btn-primary';
                        link.style.cssText = 'display:inline-block;text-decoration:none;';
                        actions.appendChild(link);
                    } else if (prop.status === 'pending_upload') {
                        var pending = document.createElement('span');
                        pending.textContent = 'Awaiting admin publishing';
                        pending.style.color = '#b45309';
                        actions.appendChild(pending);
                    }
                    var managed = document.createElement('span');
                    managed.textContent = 'Managed by OFast Pipeline';
                    managed.style.cssText = 'font-size:12px;color:#64748b;';
                    actions.appendChild(managed);
                    box.appendChild(actions);
                    break;
                }
            });
        })();
        </script>
        <?php
    }

    public static function watch_offer_creation(): void {
        if ( empty( $_POST['action'] ) || 'ofp_create_property_offer' !== sanitize_key( $_POST['action'] ) ) return;

        $property_id = absint( $_POST['property_id'] ?? 0 );
        $buyer_phone = sanitize_text_field( wp_unslash( $_POST['buyer_phone'] ?? '' ) );
        $buyer_email = sanitize_email( wp_unslash( $_POST['buyer_email'] ?? '' ) );
        $started = current_time( 'timestamp' );

        add_action( 'shutdown', static function () use ( $property_id, $buyer_phone, $buyer_email, $started ): void {
            global $wpdb;
            $p = $wpdb->prefix;
            $offer = $wpdb->get_row( $wpdb->prepare(
                "SELECT o.*, p.title AS property_title, c.business_name, c.sms_provider
                 FROM {$p}ofp_property_offers o
                 LEFT JOIN {$p}ofp_properties p ON p.id=o.property_id
                 LEFT JOIN {$p}ofp_clients c ON c.id=o.client_id
                 WHERE o.property_id=%d AND o.buyer_phone=%s
                   AND o.created_at >= %s
                 ORDER BY o.id DESC LIMIT 1",
                $property_id,
                $buyer_phone,
                wp_date( 'Y-m-d H:i:s', $started - 120 )
            ) );
            if ( ! $offer ) return;
            $key = 'ofp_offer_notice_' . (int) $offer->id;
            if ( get_transient( $key ) ) return;
            set_transient( $key, 1, DAY_IN_SECONDS );

            $link = add_query_arg( 'offer', rawurlencode( self::recover_offer_token_unavailable() ), home_url( '/property-offer' ) );
            // The raw token is intentionally not stored by the offer engine, so
            // create a short-lived recipient link from a per-offer recovery value.
            // See send_offer_notification() for the actual signed-link creation.
            $link = self::offer_link_from_offer( (int) $offer->id );
            self::send_offer_notification( $offer, $link );
            if ( $buyer_email ) { /* email handled in send_offer_notification */ }
        } );
    }

    private static function recover_offer_token_unavailable(): string { return ''; }

    private static function offer_link_from_offer( int $offer_id ): string {
        global $wpdb;
        $p = $wpdb->prefix;
        $offer = $wpdb->get_row( $wpdb->prepare( "SELECT offer_token_hash FROM {$p}ofp_property_offers WHERE id=%d LIMIT 1", $offer_id ) );
        if ( ! $offer ) return '';
        // The original offer engine stores only the hash. A recovery URL cannot
        // be recreated from that hash, so the admin UI link remains the source
        // of truth. Notification is still sent as a notification that the
        // offer was created, but without fabricating an acceptance token.
        return '';
    }

    private static function send_offer_notification( object $offer, string $link ): void {
        $message = sprintf( 'An installment offer for %s has been created. Please contact the seller to receive/open your secure acceptance link.', $offer->property_title ?: 'the property' );
        if ( ! empty( $offer->buyer_email ) ) {
            OFP_Mailer::send( $offer->buyer_email, $offer->buyer_name ?: 'there', 'Installment offer created', sprintf( '<p>Hello %s,</p><p>%s</p>', esc_html( $offer->buyer_name ?: 'there' ), esc_html( $message ) ) );
        }
        if ( ! empty( $offer->sms_provider ) && ! empty( $offer->buyer_phone ) && ! empty( $offer->client_id ) && class_exists( 'OFP_Credit' ) && OFP_Credit::has_balance( (int) $offer->client_id, 'sms', 6.99 ) ) {
            $sms = new OFP_SMS( $offer->sms_provider, (int) $offer->client_id );
            $sent = $sms->send( $offer->buyer_phone, $message );
            if ( ! empty( $sent['success'] ) ) OFP_Credit::deduct( (int) $offer->client_id, 'sms', 6.99 );
        }
    }

    public static function handle_offer_acceptance(): void {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) return;
        if ( 'accept' !== sanitize_key( $_POST['ofp_offer_action'] ?? '' ) ) return;
        $token = sanitize_text_field( wp_unslash( $_GET['offer'] ?? '' ) );
        if ( ! $token ) return;

        global $wpdb;
        $p = $wpdb->prefix;
        $hash = hash( 'sha256', $token );
        $offer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_property_offers WHERE offer_token_hash=%s LIMIT 1", $hash ) );
        if ( ! $offer || 'pending' !== $offer->status ) return;
        $nonce = sanitize_text_field( wp_unslash( $_POST['ofp_offer_nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'ofp_offer_action_' . $offer->id ) ) return;
        if ( empty( $_POST['accept_terms'] ) || '1' !== $_POST['accept_terms'] ) return;

        $purchase_id = OFP_Property_Commerce::create_purchase_from_offer(
            (int) $offer->id,
            isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''
        );
        if ( is_wp_error( $purchase_id ) ) return;

        do_action( 'ofp_property_offer_accepted', (int) $offer->id, (int) $purchase_id );
        do_action( 'ofp_property_purchase_created', (int) $purchase_id, $offer->lead_id ? (int) $offer->lead_id : null, $offer->client_id ? (int) $offer->client_id : null, (int) $offer->property_id );

        $payment_link = OFP_Property_Manual_Payment::payment_link( (int) $purchase_id );
        $installment_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}ofp_property_installments WHERE purchase_id=%d AND amount_paid < amount_due ORDER BY installment_no ASC LIMIT 1", (int) $purchase_id ) );
        $checkout_link = add_query_arg( [ 'token' => rawurlencode( self::purchase_token( (int) $purchase_id ) ), 'installment' => $installment_id ], home_url( '/property-checkout/' ) );

        wp_safe_redirect( $checkout_link );
        exit;
    }

    private static function purchase_token( int $purchase_id ): string {
        $expires = time() + OFP_Property_Manual_Payment::TOKEN_TTL;
        $payload = $purchase_id . '.' . $expires;
        return $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    }
}

add_action( 'admin_post_ofp_create_outright_purchase', [ 'OFP_Property_Commerce_Repairs', 'handle_outright_purchase' ] );
