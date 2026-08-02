Good — since your gateway abstraction is already provider-agnostic (every gateway implements the same interface), I built it generically. It'll work on Monnify now and just works if you ever add/switch providers later, no extra effort.

While building this I found one more real bug directly relevant to what you asked about — listing plan tier was being silently lost on auto-matched virtual account payments. record_payment() always set plan = null for listing subscriptions, so if a client's virtual account payment auto-matched instead of going through manual approval, their Bronze/Silver/Gold tier info vanished and get_active_listing_plan() would return nothing. I fixed that as part of this same phase since it's the same code path.

Manifest — Phase 20
File	Action
includes/class-ofp-subscription.php	REPLACE (record_payment() + new helper method)
includes/class-ofp-payment.php	REPLACE (4 new methods)
includes/gateways/class-ofp-gateway-monnify.php	REPLACE (handle_webhook() — one new check)
includes/gateways/class-ofp-gateway-paystack.php	REPLACE (handle_webhook() — one new check)
includes/gateways/class-ofp-gateway-flutterwave.php	REPLACE (handle_webhook() — one new check)
public/templates/funding.php	REPLACE (add handler + 2 Pay Now cards)
1. Fix the plan-loss bug + support explicit plan on checkout

includes/class-ofp-subscription.php — replace record_payment():

php
    /**
     * Record a confirmed payment and activate / renew the subscription.
     *
     * Called by OFP_Monnify::handle_webhook() after a payment is verified,
     * and by OFP_Payment::confirm_subscription_checkout() (Phase 20).
     *
     * @param  int         $client_id     Client ID.
     * @param  string      $type          'crm' or 'listing'.
     * @param  float       $amount        Amount paid in NGN.
     * @param  string      $payment_ref   Gateway transaction reference.
     * @param  string      $method        Payment method (e.g. 'virtual_account', 'checkout').
     * @param  string|null $plan_override Explicit plan tier — pass this when the caller
     *                                    already knows it (e.g. checkout flow encodes the
     *                                    tier in the reference). Only relevant for 'listing'.
     * @return void
     */
    public static function record_payment(
        int $client_id,
        string $type,
        float $amount,
        string $payment_ref,
        string $method = 'virtual_account',
        ?string $plan_override = null
    ): void {
        global $wpdb;

        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return;
        }

        $period_start = gmdate( 'Y-m-d' );
        $period_end   = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

        if ( $type === 'crm' ) {
            $plan = $client->plan;
        } elseif ( $type === 'listing' ) {
            // Phase 20 fix: this used to always record plan = null for listing
            // payments, silently losing which tier (bronze/silver/gold) the
            // client was paying for — breaking get_active_listing_plan() and
            // the property cap check right after an auto-matched payment.
            // Use the explicit override when known (checkout flow), otherwise
            // fall back to the client's most recent listing subscription row
            // so tier continuity is preserved for virtual-account auto-matches.
            $plan = $plan_override ?: self::get_latest_listing_plan( $client_id );
        } else {
            $plan = null;
        }

        // Insert a new paid subscription record for this payment cycle.
        $wpdb->insert(
            $wpdb->prefix . 'ofp_subscriptions',
            [
                'client_id'      => $client_id,
                'type'           => $type,
                'plan'           => $plan,
                'amount'         => $amount,
                'payment_method' => $method,
                'payment_ref'    => sanitize_text_field( $payment_ref ),
                'status'         => 'paid',
                'period_start'   => $period_start,
                'period_end'     => $period_end,
                'paid_at'        => current_time( 'mysql' ),
                'created_at'     => current_time( 'mysql' ),
            ]
        );

        // Extend the client's subscription_expires date in ofp_clients.
        // Uses GREATEST() so a payment processed slightly late still gives a
        // full 30 days from today, not from the already-past expiry date.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}ofp_clients
                 SET status               = 'active',
                     subscription_expires = DATE_ADD(
                         GREATEST( subscription_expires, CURDATE() ),
                         INTERVAL 30 DAY
                     ),
                     updated_at = NOW()
                 WHERE id = %d",
                $client_id
            )
        );

        // Send payment confirmation email.
        OFP_Mailer::send_payment_confirmed( $client, $amount, $type );
    }

    /**
     * Most recent listing subscription's plan tier for a client, regardless
     * of payment status — used to preserve tier continuity when a payment
     * is recorded without an explicit plan (e.g. virtual account auto-match).
     *
     * @param  int $client_id
     * @return string|null
     */
    private static function get_latest_listing_plan( int $client_id ): ?string {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT plan FROM {$wpdb->prefix}ofp_subscriptions
                 WHERE client_id = %d AND type = 'listing' AND plan IS NOT NULL
                 ORDER BY created_at DESC LIMIT 1",
                $client_id
            )
        );
    }

    /**
     * Public accessor for the client's most recent listing plan tier,
     * regardless of payment status. Used by the /funding UI (Phase 20)
     * to know which tier to build a checkout payment for.
     *
     * @param  int $client_id
     * @return string|null
     */
    public static function get_latest_listing_plan_for_client( int $client_id ): ?string {
        return self::get_latest_listing_plan( $client_id );
    }

No changes needed to apply_full_payment() — it already calls record_payment() without an override, so the virtual-account auto-match path picks up the fix automatically.

2. Subscription checkout methods

includes/class-ofp-payment.php — add these four methods, right after confirm_credit_topup():

php
    /**
     * Build a unique subscription checkout reference.
     *
     * @param  int         $client_id
     * @param  string      $type  'crm' or 'listing'.
     * @param  string|null $plan  Required for 'listing' (bronze|silver|gold).
     * @return string
     */
    public static function generate_subscription_checkout_reference( int $client_id, string $type, ?string $plan = null ): string {
        if ( $type === 'listing' && $plan ) {
            return sprintf( 'ofp_sub_listing_%d_%s_%s', $client_id, $plan, wp_generate_password( 8, false, false ) );
        }
        return sprintf( 'ofp_sub_crm_%d_%s', $client_id, wp_generate_password( 8, false, false ) );
    }

    /**
     * Check whether a reference is for a subscription checkout payment.
     *
     * @param  string $reference
     * @return bool
     */
    public static function is_subscription_checkout_reference( string $reference ): bool {
        return (bool) preg_match( '/^ofp_sub_(crm|listing)_/', $reference );
    }

    /**
     * Parse a subscription checkout reference.
     *
     * @param  string $reference
     * @return array|null { type, client_id, plan }
     */
    public static function parse_subscription_checkout_reference( string $reference ): ?array {
        if ( preg_match( '/^ofp_sub_crm_(\d+)_/', $reference, $m ) ) {
            return [ 'type' => 'crm', 'client_id' => (int) $m[1], 'plan' => null ];
        }
        if ( preg_match( '/^ofp_sub_listing_(\d+)_(bronze|silver|gold)_/', $reference, $m ) ) {
            return [ 'type' => 'listing', 'client_id' => (int) $m[1], 'plan' => $m[2] ];
        }
        return null;
    }

    /**
     * Initiate a hosted checkout for a CRM or Listing subscription payment.
     *
     * @param  int         $client_id
     * @param  string      $type  'crm' or 'listing'.
     * @param  string|null $plan  Required for 'listing'.
     * @return string|null        Checkout URL, or null on failure.
     */
    public static function initiate_subscription_checkout( int $client_id, string $type, ?string $plan = null ): ?string {
        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return null;
        }

        if ( $type === 'crm' ) {
            $amount      = OFP_Subscription::get_plan_price( $client->plan );
            $description = 'CRM Plan Payment — ' . ucfirst( (string) $client->plan );
        } elseif ( $type === 'listing' ) {
            if ( ! $plan || ! in_array( $plan, OFP_Property_CPT::PLAN_KEYS, true ) ) {
                return null;
            }
            $amount      = OFP_Property_CPT::get_plan_price( $plan );
            $description = 'Listing Plan Payment — ' . ucfirst( $plan );
        } else {
            return null;
        }

        if ( $amount <= 0 ) {
            return null;
        }

        $reference = self::generate_subscription_checkout_reference( $client_id, $type, $plan );
        $gateway   = self::get_gateway();

        if ( ! $gateway || ! method_exists( $gateway, 'initiate_transaction' ) ) {
            error_log( 'OFP_Payment::initiate_subscription_checkout — active gateway missing initiate_transaction().' );
            return null;
        }

        return $gateway->initiate_transaction( [
            'client_id'    => $client_id,
            'amount'       => $amount,
            'reference'    => $reference,
            'email'        => $client->email,
            'name'         => $client->owner_name,
            'phone'        => $client->phone,
            'description'  => $description,
            'redirect_url' => home_url( '/funding?sub_status=pending' ),
        ] );
    }

    /**
     * Confirm a subscription checkout payment and activate/renew accordingly.
     *
     * @param  string $reference
     * @param  float  $amount_paid
     * @param  string $provider_ref
     * @return bool
     */
    public static function confirm_subscription_checkout( string $reference, float $amount_paid, string $provider_ref = '' ): bool {
        $parsed = self::parse_subscription_checkout_reference( $reference );
        if ( ! $parsed ) {
            return false;
        }

        global $wpdb;

        $already_processed = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_subscriptions WHERE payment_ref = %s LIMIT 1",
            $reference
        ) );

        if ( $already_processed ) {
            return true;
        }

        if ( $amount_paid <= 0 ) {
            error_log( "OFP_Payment::confirm_subscription_checkout — reference {$reference} had non-positive amount {$amount_paid}" );
            return false;
        }

        OFP_Subscription::record_payment(
            $parsed['client_id'],
            $parsed['type'],
            $amount_paid,
            $reference,
            'checkout',
            $parsed['plan']
        );

        return true;
    }

OFP_Subscription::record_payment() already sends the payment-confirmed email at the end — no extra notification needed here, unlike credit top-up which had none before.

3. Webhook handlers — one new check per gateway

The check goes before the virtual-account matching block, same position as the existing credit-topup check.

includes/gateways/class-ofp-gateway-monnify.php — in handle_webhook(), right after the existing credit-topup block and before $account_ref = ...:

php
        if ( $topup_reference && OFP_Payment::is_subscription_checkout_reference( $topup_reference ) ) {
            $amount_paid = (float) ( $data->eventData->amountPaid ?? 0 );
            OFP_Payment::confirm_subscription_checkout(
                $topup_reference,
                $amount_paid,
                (string) ( $data->eventData->transactionReference ?? '' )
            );
            return new WP_REST_Response( [ 'status' => 'subscription_checkout_processed' ], 200 );
        }

includes/gateways/class-ofp-gateway-paystack.php — right after the existing credit-topup block and before $client_id = (int) ( $data->data->metadata...:

php
        if ( $reference && OFP_Payment::is_subscription_checkout_reference( $reference ) ) {
            $amount_paid = ( (float) ( $data->data->amount ?? 0 ) ) / 100;
            OFP_Payment::confirm_subscription_checkout( $reference, $amount_paid, (string) ( $data->data->id ?? '' ) );
            return new WP_REST_Response( [ 'status' => 'subscription_checkout_processed' ], 200 );
        }

includes/gateways/class-ofp-gateway-flutterwave.php — right after the existing credit-topup block and before $amount = (float) ( $data->data->amount ...:

php
        if ( $tx_ref && OFP_Payment::is_subscription_checkout_reference( $tx_ref ) ) {
            $amount_paid = (float) ( $data->data->amount ?? 0 );
            OFP_Payment::confirm_subscription_checkout( $tx_ref, $amount_paid, (string) ( $data->data->id ?? '' ) );
            return new WP_REST_Response( [ 'status' => 'subscription_checkout_processed' ], 200 );
        }
4. /funding — Pay Now cards

public/templates/funding.php — add a handler block right after the existing credit-topup handler block:

php
/* -----------------------------------------------------------
 * Handle: subscription checkout via hosted payment page (Phase 20)
 * --------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_initiate_subscription_checkout'] ) ) {

    if ( ! wp_verify_nonce( $_POST['ofp_sub_checkout_nonce'] ?? '', 'ofp_sub_checkout_action' ) ) {
        $error = 'Security check failed — please try again.';
    } elseif ( ! class_exists( 'OFP_Payment' ) ) {
        $error = 'Payment gateway is not configured yet. Please contact support.';
    } else {

        if ( class_exists( 'OFP_Security' ) ) {
            OFP_Security::check_rate_limit( $_SERVER['REMOTE_ADDR'] ?? '', 'sub_checkout_init', 5, 600 );
        }

        $sub_type = sanitize_text_field( $_POST['sub_type'] ?? '' );
        $sub_plan = sanitize_text_field( $_POST['sub_plan'] ?? '' );

        if ( ! in_array( $sub_type, [ 'crm', 'listing' ], true ) ) {
            $error = 'Please choose a valid subscription to pay for.';
        } else {
            $checkout_url = OFP_Payment::initiate_subscription_checkout(
                $client->id,
                $sub_type,
                $sub_type === 'listing' ? $sub_plan : null
            );

            if ( $checkout_url ) {
                wp_redirect( $checkout_url );
                exit;
            }

            $error = 'Could not start payment right now — please try again shortly or use manual transfer below.';
        }
    }
}

Add the status banner right after the credit-topup pending banner:

php
if ( isset( $_GET['sub_status'] ) && $_GET['sub_status'] === 'pending' ) {
    $success = 'Your payment is being processed. Your subscription will activate automatically within a few minutes once confirmed.';
}

Add two cards inside <div id="tab-auto" class="ofp-funding-pane active">, right after the Quick Credit Top-Up card from Phase 19:

php
    <?php if ( ! empty( $client->plan ) ) :
        $crm_price = OFP_Subscription::get_plan_price( $client->plan );
    ?>
    <div class="ofp-funding-card">
        <div class="ofp-funding-card-label">Automatic</div>
        <div class="ofp-funding-card-title">Pay CRM Plan — <?php echo esc_html( ucfirst( $client->plan ) ); ?></div>
        <div class="ofp-funding-card-desc">
            NGN <?php echo esc_html( number_format( $crm_price, 2 ) ); ?>/month. Pay instantly via secure checkout —
            your subscription activates automatically once confirmed.
        </div>
        <form method="POST" action="">
            <?php wp_nonce_field( 'ofp_sub_checkout_action', 'ofp_sub_checkout_nonce' ); ?>
            <input type="hidden" name="sub_type" value="crm">
            <button type="submit" name="ofp_initiate_subscription_checkout" value="1" class="ofp-submit-btn">
                Pay NGN <?php echo esc_html( number_format( $crm_price, 0 ) ); ?> Now
            </button>
        </form>
    </div>
    <?php endif; ?>

    <?php
    $pending_listing_plan = OFP_Subscription::get_latest_listing_plan_for_client( $client->id );
    if ( $pending_listing_plan ) :
        $listing_price = OFP_Property_CPT::get_plan_price( $pending_listing_plan );
    ?>
    <div class="ofp-funding-card">
        <div class="ofp-funding-card-label">Automatic</div>
        <div class="ofp-funding-card-title">Pay Listing Plan — <?php echo esc_html( ucfirst( $pending_listing_plan ) ); ?></div>
        <div class="ofp-funding-card-desc">
            NGN <?php echo esc_html( number_format( $listing_price, 2 ) ); ?>/month. Pay instantly via secure checkout —
            your listing plan activates automatically once confirmed.
        </div>
        <form method="POST" action="">
            <?php wp_nonce_field( 'ofp_sub_checkout_action', 'ofp_sub_checkout_nonce' ); ?>
            <input type="hidden" name="sub_type" value="listing">
            <input type="hidden" name="sub_plan" value="<?php echo esc_attr( $pending_listing_plan ); ?>">
            <button type="submit" name="ofp_initiate_subscription_checkout" value="1" class="ofp-submit-btn">
                Pay NGN <?php echo esc_html( number_format( $listing_price, 0 ) ); ?> Now
            </button>
        </form>
    </div>
    <?php endif; ?>

Note on the listing card: it only shows once the client has already picked a tier on /properties (which creates the pending subscription row get_latest_listing_plan_for_client() reads from). That matches your existing flow — plan selection happens on /properties, payment happens on /funding. If they haven't chosen a tier yet, no card shows, which is correct since there'd be nothing to pay for.

One dependency to flag clearly, since you mentioned pasting these into Claude Code: Phase 20's OFP_Payment methods call OFP_Subscription::record_payment() with the new 6th parameter — so the Subscription file change must land together with the Payment file change, or you'll get a fatal argument-count mismatch. Apply all six files in this phase as one unit.