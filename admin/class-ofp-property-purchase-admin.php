<?php
/**
 * Admin property purchase creation.
 *
 * Purchase is independent from buyer accounts and from leads. A purchase may
 * reference an existing lead, but an offline buyer can be created directly.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Purchase_Admin {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_ofp_create_property_purchase', [ __CLASS__, 'handle_create_purchase' ] );
    }

    public static function register_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        add_submenu_page(
            null,
            'Add Property Purchase',
            'Add Purchase',
            'manage_options',
            'ofp-property-add-purchase',
            [ __CLASS__, 'render_create' ]
        );
    }

    public static function render_create(): void {
        $active_tab = 'add-purchase';
        ?>
        <h2 class="nav-tab-wrapper">
            <a href="?post_type=ofp_property&page=ofp-property-purchases" class="nav-tab">Purchases Table</a>
            <a href="?post_type=ofp_property&page=ofp-property-add-purchase" class="nav-tab nav-tab-active">Add Purchase</a>
        </h2>
        <?php

        OFP_Property_CPT::reconcile_live_property_records();
        global $wpdb;
        $p = $wpdb->prefix;

        $properties = $wpdb->get_results(
            "SELECT pr.id, pr.title, pr.price, pr.listing_type, pr.client_id
             FROM {$p}ofp_properties pr
             LEFT JOIN {$p}postmeta pm_status ON pm_status.post_id = pr.wp_post_id AND pm_status.meta_key = 'ofp_status'
             WHERE pr.listing_type = 'sale' AND ( pr.status = 'live' OR pm_status.meta_value = 'live' )
             ORDER BY title ASC"
        );

        $leads = $wpdb->get_results(
            "SELECT l.id, l.client_id, l.property_id, l.name, l.phone, l.email, p.title AS property_title
             FROM {$p}ofp_leads l
             LEFT JOIN {$p}ofp_properties p ON p.id = l.property_id
             ORDER BY l.created_at DESC
             LIMIT 300"
        );

        $error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        $created_id = absint( $_GET['created'] ?? 0 );
        ?>
        <div class="wrap">
            <h1>Add Property Purchase</h1>
            <p>Create a purchase for an existing lead or an offline buyer. No buyer account is created.</p>

            <?php if ( $created_id ) : ?>
                <div class="notice notice-success is-dismissible"><p>Purchase <strong>#<?php echo esc_html( $created_id ); ?></strong> created successfully.</p></div>
            <?php endif; ?>
            <?php if ( $error ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ofp_create_property_purchase' ); ?>
                <input type="hidden" name="action" value="ofp_create_property_purchase">

                <table class="form-table" role="presentation">
                    <tr><th><label for="property_id">Property</label></th><td>
                        <select name="property_id" id="property_id" required style="min-width:420px;">
                            <option value="">Select sale property</option>
                            <?php foreach ( $properties as $property ) : ?>
                                <option value="<?php echo esc_attr( $property->id ); ?>" data-price="<?php echo esc_attr( $property->price ); ?>">
                                    <?php echo esc_html( $property->title . ' — NGN ' . number_format( (float) $property->price, 0 ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th><label for="buyer_source">Buyer source</label></th><td>
                        <select name="buyer_source" id="buyer_source">
                            <option value="offline">Offline buyer</option>
                            <option value="lead">Existing lead</option>
                        </select>
                    </td></tr>
                    <tr><th><label for="lead_id">Existing lead</label></th><td>
                        <select name="lead_id" id="lead_id" style="min-width:420px;">
                            <option value="">— No lead / offline buyer —</option>
                            <?php foreach ( $leads as $lead ) : ?>
                                <option value="<?php echo esc_attr( $lead->id ); ?>" data-property="<?php echo esc_attr( $lead->property_id ); ?>" data-name="<?php echo esc_attr( $lead->name ); ?>" data-phone="<?php echo esc_attr( $lead->phone ); ?>" data-email="<?php echo esc_attr( $lead->email ); ?>">
                                    <?php echo esc_html( '#' . $lead->id . ' — ' . ( $lead->name ?: 'Unnamed' ) . ' — ' . $lead->phone . ( $lead->property_title ? ' — ' . $lead->property_title : '' ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Optional. An offline buyer does not need to exist as a lead.</p>
                    </td></tr>
                    <tr><th><label for="buyer_name">Buyer name</label></th><td><input class="regular-text" id="buyer_name" name="buyer_name" required></td></tr>
                    <tr><th><label for="buyer_phone">Buyer phone</label></th><td><input class="regular-text" id="buyer_phone" name="buyer_phone" required></td></tr>
                    <tr><th><label for="buyer_email">Buyer email</label></th><td><input type="email" class="regular-text" id="buyer_email" name="buyer_email"></td></tr>
                    <tr><th><label for="initial_payment">Initial payment</label></th><td><input type="number" step="0.01" min="0" id="initial_payment" name="initial_payment" required></td></tr>
                    <tr><th><label for="installment_amount">Installment amount</label></th><td><input type="number" step="0.01" min="0" id="installment_amount" name="installment_amount" required></td></tr>
                    <tr><th><label for="installment_count">Number of installments</label></th><td><input type="number" min="1" id="installment_count" name="installment_count" required></td></tr>
                    <tr><th><label for="amount_paid">Amount paid (NGN)</label></th><td><input type="number" step="0.01" min="0" id="amount_paid" name="amount_paid" required><p class="description">The amount already received from the buyer (e.g. initial deposit).</p></td></tr>
                    <tr><th><label for="payment_method">Payment method</label></th><td>
                        <select id="payment_method" name="payment_method" required>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="bank_deposit">Bank Deposit</option>
                            <option value="cash">Cash</option>
                            <option value="virtual_account">Virtual Account</option>
                            <option value="checkout">Checkout</option>
                            <option value="other">Other</option>
                        </select>
                    </td></tr>
                    <tr><th><label for="payment_reference">Payment reference</label></th><td><input class="regular-text" id="payment_reference" name="payment_reference"><p class="description">Receipt number, transaction ID, or any reference for this payment.</p></td></tr>
                </table>
                <?php submit_button( 'Create Purchase' ); ?>
            </form>
        </div>
        <script>
        (function(){
            var source = document.getElementById('buyer_source');
            var lead = document.getElementById('lead_id');
            var name = document.getElementById('buyer_name');
            var phone = document.getElementById('buyer_phone');
            var email = document.getElementById('buyer_email');
            var property = document.getElementById('property_id');
            var initial = document.getElementById('initial_payment');

            function syncLeadMode(){
                var isLead = source.value === 'lead';
                lead.disabled = !isLead;
                if (!isLead) lead.value = '';
            }
            function fillFromLead(){
                var opt = lead.options[lead.selectedIndex];
                if (!opt || !lead.value) return;
                name.value = opt.getAttribute('data-name') || '';
                phone.value = opt.getAttribute('data-phone') || '';
                email.value = opt.getAttribute('data-email') || '';
            }
            function syncInitialCap(){
                var opt = property.options[property.selectedIndex];
                if (!opt) return;
                var price = parseFloat(opt.getAttribute('data-price') || '0');
                initial.max = price > 0 ? Math.max(0, price - 0.01).toFixed(2) : '';
            }
            source.addEventListener('change', syncLeadMode);
            lead.addEventListener('change', fillFromLead);
            property.addEventListener('change', syncInitialCap);
            syncLeadMode();
            syncInitialCap();
        })();
        </script>
        <?php
    }

    public static function handle_create_purchase(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Access denied.', 'ofast-pipeline' ) );
        check_admin_referer( 'ofp_create_property_purchase' );

        global $wpdb;
        $p = $wpdb->prefix;

        $property_id = absint( $_POST['property_id'] ?? 0 );
        $lead_id = absint( $_POST['lead_id'] ?? 0 );
        $buyer_name = sanitize_text_field( wp_unslash( $_POST['buyer_name'] ?? '' ) );
        $buyer_phone = sanitize_text_field( wp_unslash( $_POST['buyer_phone'] ?? '' ) );
        $buyer_email = sanitize_email( wp_unslash( $_POST['buyer_email'] ?? '' ) );
        $initial_payment = max( 0.0, (float) ( $_POST['initial_payment'] ?? 0 ) );
        $installment_amount = max( 0.0, (float) ( $_POST['installment_amount'] ?? 0 ) );
        $installment_count = max( 0, absint( $_POST['installment_count'] ?? 0 ) );
        $amount_paid = max( 0.0, (float) ( $_POST['amount_paid'] ?? 0 ) );
        $payment_method = sanitize_key( $_POST['payment_method'] ?? 'bank_transfer' );
        $payment_reference = sanitize_text_field( wp_unslash( $_POST['payment_reference'] ?? '' ) );

        $allowed_methods = [ 'bank_transfer', 'bank_deposit', 'cash', 'virtual_account', 'checkout', 'other' ];

        $property = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_properties WHERE id = %d LIMIT 1", $property_id ) );
        $lead = $lead_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_leads WHERE id = %d LIMIT 1", $lead_id ) ) : null;

        $error = '';
        if ( ! $property ) $error = 'Property not found.';
        elseif ( $property->listing_type !== 'sale' ) $error = 'Only sale properties can become purchases.';
        elseif ( ! $buyer_name || ! $buyer_phone ) $error = 'Buyer name and phone are required.';
        elseif ( $buyer_email !== '' && ! is_email( $buyer_email ) ) $error = 'Buyer email is invalid.';
        elseif ( (float) $property->price <= 0 ) $error = 'Property price is invalid.';
        elseif ( $initial_payment < 0 || $initial_payment > (float) $property->price ) $error = 'Initial payment cannot exceed the property price.';
        elseif ( $initial_payment < (float) $property->price && ( $installment_amount <= 0 || $installment_count <= 0 ) ) $error = 'Installment amount and count are required for partial payments.';
        elseif ( $initial_payment < (float) $property->price && abs( ( (float) $property->price - $initial_payment ) - ( $installment_amount * $installment_count ) ) > 0.01 ) $error = 'The installment schedule must exactly cover the remaining property balance.';
        elseif ( $amount_paid <= 0 ) $error = 'Amount paid must be greater than zero.';
        elseif ( $lead_id && ! $lead ) $error = 'Selected lead could not be found.';
        elseif ( $lead && $lead->property_id && (int) $lead->property_id !== $property_id ) $error = 'Selected lead belongs to a different property.';
        elseif ( ! in_array( $payment_method, $allowed_methods, true ) ) $error = 'Invalid payment method.';

        if ( $error ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $error ), admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-add-purchase' ) ) );
            exit;
        }

        $result = OFP_Property_Purchase_Service::create([
            'property_id' => $property_id,
            'lead_id' => $lead_id ?: null,
            'buyer_name' => $buyer_name,
            'buyer_phone' => $buyer_phone,
            'buyer_email' => $buyer_email ?: null,
            'initial_payment' => $initial_payment,
            'installment_amount' => $installment_amount,
            'installment_count' => $installment_count,
            'payment_method' => $payment_method,
        ]);

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $result->get_error_message() ), admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-add-purchase' ) ) );
            exit;
        }

        $purchase_id = (int) $result;

        // Record the initial payment if amount_paid > 0.
        if ( $amount_paid > 0 && class_exists( 'OFP_Property_Payment_Record' ) ) {
            $method_label = str_replace( '_', ' ', $payment_method );
            OFP_Property_Payment_Record::create([
                'purchase_id'    => $purchase_id,
                'payment_method' => 'manual',
                'amount'         => $amount_paid,
                'status'         => 'successful',
                'payer_name'     => $buyer_name,
                'payer_reference' => $payment_reference,
                'note'           => 'Initial payment via ' . $method_label . ( $payment_reference ? ' (Ref: ' . $payment_reference . ')' : '' ),
            ]);
        }

        wp_safe_redirect( add_query_arg( 'created', $purchase_id, admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-add-purchase' ) ) );
        exit;
    }
}
