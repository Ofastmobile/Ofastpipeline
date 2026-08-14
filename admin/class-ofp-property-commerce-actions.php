<?php
/**
 * Property commerce creation actions.
 *
 * Adds an admin "Create Installment Offer" page under Properties and a
 * client-accessible helper link target. Existing offer/purchase list screens
 * remain in OFP_Property_Commerce_Admin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Commerce_Actions {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_ofp_create_property_offer', [ __CLASS__, 'handle_create_offer' ] );
    }

    public static function register_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        add_submenu_page(
            'edit.php?post_type=ofp_property',
            'Create Installment Offer',
            'Create Offer',
            'manage_options',
            'ofp-property-create-offer',
            [ __CLASS__, 'render_create_offer' ]
        );
    }

    public static function render_create_offer(): void {
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
        $message = isset( $_GET['created'] ) ? 'Installment offer created successfully.' : '';
        $error   = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        ?>
        <div class="wrap">
            <h1>Create Installment Offer</h1>
            <p>Create an offer for a buyer against an existing sale property. No buyer account or payment account is created at this stage.</p>

            <?php if ( $message ) : ?><div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div><?php endif; ?>
            <?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ofp_create_property_offer' ); ?>
                <input type="hidden" name="action" value="ofp_create_property_offer">

                <table class="form-table" role="presentation">
                    <tr><th><label for="property_id">Property</label></th><td>
                        <select name="property_id" id="property_id" required style="min-width:360px;">
                            <option value="">Select property</option>
                            <?php foreach ( $properties as $property ) : ?>
                                <option value="<?php echo esc_attr( $property->id ); ?>">
                                    <?php echo esc_html( $property->title . ' — NGN ' . number_format( (float) $property->price, 0 ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                    <tr><th><label for="buyer_name">Buyer name</label></th><td><input class="regular-text" id="buyer_name" name="buyer_name" required></td></tr>
                    <tr><th><label for="buyer_phone">Buyer phone</label></th><td><input class="regular-text" id="buyer_phone" name="buyer_phone" required></td></tr>
                    <tr><th><label for="buyer_email">Buyer email</label></th><td><input type="email" class="regular-text" id="buyer_email" name="buyer_email"></td></tr>
                    <tr><th><label for="initial_payment">Initial payment</label></th><td><input type="number" step="0.01" min="0" id="initial_payment" name="initial_payment" required></td></tr>
                    <tr><th><label for="frequency">Payment frequency</label></th><td>
                        <select id="frequency" name="frequency">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly" selected>Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </td></tr>
                    <tr><th><label for="installment_amount">Installment amount</label></th><td><input type="number" step="0.01" min="0" id="installment_amount" name="installment_amount" required></td></tr>
                    <tr><th><label for="installment_count">Number of installments</label></th><td><input type="number" min="1" id="installment_count" name="installment_count" required></td></tr>
                    <tr><th><label for="payment_start_date">Payment starts</label></th><td><input type="date" id="payment_start_date" name="payment_start_date" required></td></tr>
                    <tr><th><label for="first_due_date">First due date</label></th><td><input type="date" id="first_due_date" name="first_due_date" required></td></tr>
                    <tr><th><label for="grace_period_days">Grace period (days)</label></th><td><input type="number" min="0" max="365" value="7" id="grace_period_days" name="grace_period_days"></td></tr>
                    <tr><th><label for="offer_expires">Offer expires</label></th><td><input type="date" id="offer_expires" name="offer_expires"></td></tr>
                    <tr><th><label for="terms_text">Installment terms / agreement</label></th><td><textarea class="large-text" rows="10" id="terms_text" name="terms_text"></textarea><p class="description">Use approved seller/legal wording. The accepted version is stored with the purchase.</p></td></tr>
                </table>

                <?php submit_button( 'Create Offer' ); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_create_offer(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Access denied.', 'ofast-pipeline' ) );
        check_admin_referer( 'ofp_create_property_offer' );

        global $wpdb;
        $p = $wpdb->prefix;

        $property_id        = absint( $_POST['property_id'] ?? 0 );
        $buyer_name         = sanitize_text_field( wp_unslash( $_POST['buyer_name'] ?? '' ) );
        $buyer_phone        = sanitize_text_field( wp_unslash( $_POST['buyer_phone'] ?? '' ) );
        $buyer_email        = sanitize_email( wp_unslash( $_POST['buyer_email'] ?? '' ) );
        $initial_payment    = max( 0.0, (float) ( $_POST['initial_payment'] ?? 0 ) );
        $frequency          = sanitize_key( $_POST['frequency'] ?? 'monthly' );
        $installment_amount = max( 0.0, (float) ( $_POST['installment_amount'] ?? 0 ) );
        $installment_count  = max( 0, absint( $_POST['installment_count'] ?? 0 ) );
        $payment_start_date = sanitize_text_field( wp_unslash( $_POST['payment_start_date'] ?? '' ) );
        $first_due_date     = sanitize_text_field( wp_unslash( $_POST['first_due_date'] ?? '' ) );
        $grace_days         = min( 365, max( 0, absint( $_POST['grace_period_days'] ?? 7 ) ) );
        $expiry_date        = sanitize_text_field( wp_unslash( $_POST['offer_expires'] ?? '' ) );
        $terms_text         = wp_kses_post( wp_unslash( $_POST['terms_text'] ?? '' ) );

        $allowed_frequencies = [ 'daily', 'weekly', 'monthly', 'quarterly', 'yearly' ];
        if ( ! in_array( $frequency, $allowed_frequencies, true ) ) {
            $frequency = 'monthly';
        }

        $property = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_properties WHERE id = %d LIMIT 1",
            $property_id
        ) );

        $error = '';
        if ( ! $property ) $error = 'Property not found.';
        elseif ( $property->listing_type !== 'sale' ) $error = 'Installment offers are only available for sale properties.';
        elseif ( ! $buyer_name || ! $buyer_phone ) $error = 'Buyer name and phone are required.';
        elseif ( $buyer_email !== '' && ! is_email( $buyer_email ) ) $error = 'Buyer email is invalid.';
        elseif ( (float) $property->price <= 0 ) $error = 'Property price is invalid.';
        elseif ( $initial_payment < 0 || $initial_payment >= (float) $property->price ) $error = 'Initial payment must be less than the property price.';
        elseif ( $installment_amount <= 0 || $installment_count <= 0 ) $error = 'Installment amount and count are required.';
        elseif ( abs( ( (float) $property->price - $initial_payment ) - ( $installment_amount * $installment_count ) ) > 0.01 ) $error = 'The installment schedule must exactly cover the remaining property balance.';
        elseif ( ! $payment_start_date || strtotime( $payment_start_date ) === false ) $error = 'Payment start date is required.';
        elseif ( ! $first_due_date || strtotime( $first_due_date ) === false ) $error = 'First due date is required.';
        elseif ( strtotime( $first_due_date ) < strtotime( $payment_start_date ) ) $error = 'First due date cannot be before the payment start date.';

        if ( $error ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $error ), admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-create-offer' ) ) );
            exit;
        }

        [ $raw_token, $token_hash ] = OFP_Property_Commerce::create_offer_token();

        $wpdb->insert(
            "{$p}ofp_property_offers",
            [
                'property_id'        => $property_id,
                'client_id'          => $property->client_id ? (int) $property->client_id : null,
                'buyer_name'         => $buyer_name,
                'buyer_phone'        => $buyer_phone,
                'buyer_email'        => $buyer_email ?: null,
                'total_price'        => (float) $property->price,
                'initial_payment'    => $initial_payment,
                'installment_amount' => $installment_amount,
                'frequency'          => $frequency,
                'installment_count'  => $installment_count,
                'payment_start_date' => $payment_start_date,
                'first_due_date'     => $first_due_date,
                'grace_period_days'  => $grace_days,
                'reminder_days'      => '7,3,1',
                'terms_text'         => $terms_text ?: null,
                'terms_version'      => '1',
                'offer_token_hash'   => $token_hash,
                'status'             => 'pending',
                'expires_at'         => $expiry_date ? $expiry_date . ' 23:59:59' : null,
                'created_at'         => current_time( 'mysql' ),
                'updated_at'         => current_time( 'mysql' ),
            ]
        );

        if ( ! $wpdb->insert_id ) {
            wp_die( esc_html__( 'The installment offer could not be created.', 'ofast-pipeline' ) );
        }

        $offer_url = add_query_arg( 'offer', rawurlencode( $raw_token ), home_url( '/property-offer' ) );

        if ( $buyer_email && class_exists( 'OFP_Mailer' ) ) {
            $subject = 'Property Installment Offer - ' . $property->title;
            $message = "Hello {$buyer_name},<br><br>An installment payment plan has been created for the property: <strong>{$property->title}</strong>.<br><br>";
            $message .= "Total Price: NGN " . number_format( $property->price, 2 ) . "<br>";
            $message .= "Initial Payment: NGN " . number_format( $initial_payment, 2 ) . "<br><br>";
            $message .= "Please review and accept the offer here:<br>";
            $message .= "<a href=\"" . esc_url( $offer_url ) . "\">Accept Installment Offer</a><br><br>";
            $message .= "If you have any questions, please contact us.";
            OFP_Mailer::send( $buyer_email, $buyer_name, $subject, $message );
        }

        if ( $property->client_id && class_exists( 'OFP_Notification' ) ) {
            OFP_Notification::create( (int) $property->client_id, 'property_offer_created', 'Installment offer created', sprintf( 'An installment offer was created for %s.', $property->title ) );
        }

        wp_safe_redirect( add_query_arg(
            [ 'created' => 1, 'offer_id' => (int) $wpdb->insert_id, 'offer_url' => rawurlencode( $offer_url ) ],
            admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-offers' )
        ) );
        exit;
    }
}
