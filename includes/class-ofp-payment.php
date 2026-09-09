<?php
/**
 * OFP_Payment
 *
 * Provider-agnostic payment gateway interface.
 *
 * ARCHITECTURE:
 *  This class is the ONLY payment entry point for the rest of the plugin.
 *  OFP_Client, OFP_Subscription, OFP_REST_API all call OFP_Payment methods.
 *  They never talk to a gateway class directly.
 *
 *  The active provider is set in wp-admin → OFast Pipeline → Settings.
 *
 * SUPPORTED GATEWAYS:
 *  - paystack     (Paystack Dedicated Virtual Accounts, cards, and transfer)
 *
 * ADDING A NEW GATEWAY:
 *  1. Create includes/gateways/class-ofp-gateway-{slug}.php
 *  2. Implement the OFP_Gateway_Interface methods
 *  3. Add the slug to SUPPORTED_GATEWAYS
 *  4. Add its credentials to the Settings page
 *  That is all. No other file needs to change.
 *
 * VIRTUAL ACCOUNT STANDARD:
 *  create_virtual_account() always returns a stdClass with:
 *   ->account_number  (string)
 *   ->bank_name       (string)
 *  Or null on failure. All gateway adapters normalise to this format.
 *
 * Depends on: gateway adapter classes, wp_options for provider config.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Payment {

    const SUPPORTED_GATEWAYS = [ 'paystack' ];

    // ─────────────────────────────────────────────────────────────────────────
    // GATEWAY RESOLVER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get an instance of the configured gateway adapter.
     *
     * @return OFP_Gateway_Interface|null  Null if provider not configured or unsupported.
     */
    private static function get_gateway(): ?object {
        $provider = 'paystack';

        if ( ! in_array( $provider, self::SUPPORTED_GATEWAYS, true ) ) {
            error_log( "[OFP_Payment] Unsupported provider: {$provider}" );
            return null;
        }

        $class = 'OFP_Gateway_' . ucfirst( $provider );

        if ( ! class_exists( $class ) ) {
            error_log( "[OFP_Payment] Gateway class not found: {$class}" );
            return null;
        }

        return new $class();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC INTERFACE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Initiate a self-serve credit top-up checkout with the active gateway.
     *
     * @param int    $client_id
     * @param string $channel
     * @param float  $amount
     * @return string|null
     */
    public static function initiate_credit_topup( int $client_id, string $channel, float $amount ): ?string {
        if ( ! in_array( $channel, [ 'sms', 'voice' ], true ) ) {
            return null;
        }

        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return null;
        }

        $reference = self::generate_credit_topup_reference( $client_id, $channel );
        $gateway   = self::get_gateway();

        if ( ! $gateway || ! method_exists( $gateway, 'initiate_transaction' ) ) {
            error_log( 'OFP_Payment::initiate_credit_topup — active gateway missing initiate_transaction().' );
            return null;
        }

        return $gateway->initiate_transaction( [
            'client_id'    => $client_id,
            'amount'       => $amount,
            'reference'    => $reference,
            'email'        => $client->email,
            'name'         => $client->owner_name,
            'phone'        => $client->phone,
            'description'  => ucfirst( $channel ) . ' Credit Top-Up',
            'redirect_url' => home_url( '/credits?topup_status=pending' ),
        ] );
    }

    /**
     * Build a unique credit top-up reference.
     *
     * @param int    $client_id
     * @param string $channel
     * @return string
     */
    public static function generate_credit_topup_reference( int $client_id, string $channel ): string {
        return sprintf( 'ofp_credit_%s_%d_%s', $channel, $client_id, wp_generate_password( 8, false, false ) );
    }

    /**
     * Check whether a reference is for a self-serve credit top-up.
     *
     * @param string $reference
     * @return bool
     */
    public static function is_credit_topup_reference( string $reference ): bool {
        return (bool) preg_match( '/^ofp_credit_(sms|voice)_(\d+)_/', $reference );
    }

    /**
     * Parse a credit top-up reference into its channel and client id.
     *
     * @param string $reference
     * @return array|null
     */
    public static function parse_credit_topup_reference( string $reference ): ?array {
        if ( ! preg_match( '/^ofp_credit_(sms|voice)_(\d+)_/', $reference, $matches ) ) {
            return null;
        }

        return [
            'channel'   => $matches[1],
            'client_id' => (int) $matches[2],
        ];
    }

    /**
     * Confirm a top-up payment and credit the client balance.
     *
     * @param string $reference
     * @param float  $amount_paid
     * @param string $provider_ref
     * @return bool
     */
    public static function confirm_credit_topup( string $reference, float $amount_paid, string $provider_ref = '' ): bool {
        $parsed = self::parse_credit_topup_reference( $reference );
        if ( ! $parsed ) {
            return false;
        }

        $client = OFP_Client::get( $parsed['client_id'] );
        if ( ! $client ) {
            error_log( "OFP_Payment::confirm_credit_topup — reference {$reference} points to a missing client" );
            return false;
        }

        global $wpdb;

        $already_processed = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_credit_transactions WHERE reference = %s AND type = 'topup' LIMIT 1",
            $reference
        ) );

        if ( $already_processed ) {
            return true;
        }

        if ( $amount_paid <= 0 ) {
            error_log( "OFP_Payment::confirm_credit_topup — reference {$reference} had non-positive amount {$amount_paid}" );
            return false;
        }

        OFP_Credit::topup( $parsed['client_id'], $parsed['channel'], $amount_paid, $reference );

        // Phase 19: let the client know their top-up landed. Previously this
        // was silent — the balance updated but nothing told the client it
        // had happened, which was confusing given the checkout redirect
        // just sends them back to a "pending" page with no follow-up.
        if ( class_exists( 'OFP_Notification' ) ) {
            OFP_Notification::create(
                $parsed['client_id'],
                'credit_topup_confirmed',
                ucfirst( $parsed['channel'] ) . ' credit top-up confirmed',
                'Your top-up of NGN ' . number_format( $amount_paid, 2 ) . ' has been received and added to your '
                . strtoupper( $parsed['channel'] ) . ' credit balance.'
            );
        }

        return true;
    }

    /**
     * Build a unique subscription checkout reference.
     *
     * The expected amount (in kobo) is embedded in the reference itself so
     * confirm_subscription_checkout() can verify the exact amount that was
     * expected at initiation time, without re-deriving it from current
     * pricing (which may have changed) or trusting the gateway amount blindly.
     *
     * @param  int         $client_id
     * @param  string      $type    'crm' or 'listing'.
     * @param  string|null $plan    Required for 'listing' (bronze|silver|gold).
     * @param  float       $amount  The exact amount (NGN) this checkout expects.
     * @return string
     */
    public static function generate_subscription_checkout_reference( int $client_id, string $type, ?string $plan = null, float $amount = 0.0 ): string {
        $amount_kobo = (int) round( $amount * 100 );
        if ( $type === 'listing' && $plan ) {
            return sprintf( 'ofp_sub_listing_%d_%s_%d_%s', $client_id, $plan, $amount_kobo, wp_generate_password( 8, false, false ) );
        }
        return sprintf( 'ofp_sub_crm_%d_%d_%s', $client_id, $amount_kobo, wp_generate_password( 8, false, false ) );
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
     * @return array|null { type, client_id, plan, expected_amount }
     */
    public static function parse_subscription_checkout_reference( string $reference ): ?array {
        if ( preg_match( '/^ofp_sub_crm_(\d+)_(\d+)_/', $reference, $m ) ) {
            return [ 'type' => 'crm', 'client_id' => (int) $m[1], 'plan' => null, 'expected_amount' => ( (int) $m[2] ) / 100 ];
        }
        if ( preg_match( '/^ofp_sub_listing_(\d+)_(bronze|free|silver|gold)_(\d+)_/', $reference, $m ) ) {
            return [
                'type'            => 'listing',
                'client_id'       => (int) $m[1],
                'plan'            => OFP_Subscription::normalize_plan( $m[2] ),
                'expected_amount' => ( (int) $m[3] ) / 100,
            ];
        }
        return null;
    }

    /**
     * Initiate a hosted checkout for a CRM or Listing subscription payment.
     *
     * Tier-lock rule: a client already on Silver or Gold can only pick a
     * plan (renew same tier, or switch tiers) inside the 7-day window before
     * their subscription_expires date. Free clients can pick any plan anytime.
     * This is enforced here (not just hidden in the UI) so it can't be bypassed
     * by posting the form directly. The one exception is $override_amount —
     * that's used to pay off an existing underpayment shortfall, which is
     * always allowed regardless of the window.
     *
     * @param  int         $client_id
     * @param  string      $type            'crm' or 'listing'.
     * @param  string|null $plan            Required for 'listing'.
     * @param  float|null  $override_amount Phase 22: pay this exact amount instead
     *                                      of the full plan price — used to let a
     *                                      client pay off an underpayment shortfall
     *                                      without re-charging the whole plan.
     * @return string|null                  Checkout URL, or null on failure.
     */
    public static function initiate_subscription_checkout( int $client_id, string $type, ?string $plan = null, ?float $override_amount = null ): ?string {
        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return null;
        }

        if ( $type === 'crm' ) {
            // Product is one plan. Old CRM checkouts become a listing-plan
            // payment at the mapped Free/Silver/Gold tier.
            $type = 'listing';
            $plan = OFP_Subscription::normalize_plan( $plan ?: ( $client->plan ?? 'silver' ) );
            if ( $plan === 'free' ) {
                $plan = 'silver';
            }
        }

        if ( $type === 'listing' ) {
            if ( ! $plan || ! in_array( $plan, OFP_Property_CPT::PLAN_KEYS, true ) ) {
                return null;
            }

            if ( $override_amount === null && class_exists( 'OFP_Subscription' )
                && OFP_Subscription::client_plan( $client_id ) !== 'free'
                && ! OFP_Subscription::in_renewal_window( $client_id ) ) {
                // Mid-cycle on a paid plan, outside the 7-day renewal window —
                // block plan selection entirely (renew or switch).
                return null;
            }

            $amount      = $override_amount ?? OFP_Property_CPT::get_plan_price( $plan );
            $description = ucfirst( $plan ) . ' Plan Payment';
        } else {
            return null;
        }

        if ( $amount <= 0 ) {
            return null;
        }

        $reference = self::generate_subscription_checkout_reference( $client_id, $type, $plan, $amount );
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
     * Verifies the amount actually received against the amount this exact
     * checkout expected (embedded in the reference at initiation time):
     *  - Paid the expected amount (within a 1 NGN rounding tolerance) → activate normally.
     *  - Paid MORE → still activate (client shouldn't be denied service they paid
     *    for), but flag the excess for manual admin review.
     *  - Paid LESS → do NOT activate. Record as underpaid so it shows in the
     *    admin Billing page and the client's Funding page with the exact
     *    shortfall, same treatment as the old virtual-account underpayment flow.
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

        $expected = $parsed['expected_amount'] ?? 0.0;
        $tolerance = 1.00; // 1 NGN, covers gateway rounding, not a real shortfall/excess.

        if ( $expected > 0 && $amount_paid < ( $expected - $tolerance ) ) {
            // Underpaid — do not activate. Flag it instead.
            OFP_Subscription::record_underpayment(
                $parsed['client_id'],
                $amount_paid,
                $expected,
                $reference,
                'checkout',
                $parsed['plan']
            );
            return true;
        }

        OFP_Subscription::record_payment(
            $parsed['client_id'],
            $parsed['type'],
            $amount_paid,
            $reference,
            'checkout',
            $parsed['plan'],
            $expected > 0 ? $expected : null
        );

        if ( $expected > 0 && $amount_paid > ( $expected + $tolerance ) ) {
            // Overpaid — plan is active, but flag the excess for manual review.
            OFP_Subscription::flag_overpayment( $parsed['client_id'], $amount_paid, $expected, $reference );
        }

        return true;
    }

    /**
     * Handle an incoming payment webhook from the configured gateway.
     *
     * Called by OFP_REST_API::payment_webhook().
     * Each gateway verifies its own signature before processing.
     *
     * @param  WP_REST_Request $request  The incoming webhook request.
     * @return WP_REST_Response
     */
    public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $gateway = self::get_gateway();

        if ( ! $gateway ) {
            return new WP_REST_Response( [ 'error' => 'No payment provider configured.' ], 500 );
        }

        return $gateway->handle_webhook( $request );
    }

    /**
     * Get the name of the currently configured payment provider.
     *
     * @return string  e.g. 'paystack'
     */
    public static function get_active_provider(): string {
        return 'paystack';
    }

    /**
     * Check whether payment is fully configured and ready.
     * Used by the Settings page to show a status indicator.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        $gateway = self::get_gateway();
        if ( ! $gateway ) return false;
        return $gateway->is_configured();
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// GATEWAY INTERFACE
// Defines the contract every gateway adapter must fulfil.
// ─────────────────────────────────────────────────────────────────────────────

interface OFP_Gateway_Interface {

    /**
     * Create a dedicated virtual account for a client.
     *
     * @param  array $client_data  Business name, owner name, email.
     * @param  int   $client_id    OFP client ID used as the account reference.
     * @return object|null         stdClass { account_number, bank_name } or null.
     */

    /**
     * Handle and verify an incoming webhook from this gateway.
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response;

    /**
     * Check if this gateway has its required credentials configured.
     *
     * @return bool
     */
    public function is_configured(): bool;
}
