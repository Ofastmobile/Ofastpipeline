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
 *  Switching from Monnify to Paystack = changing one setting, zero code changes.
 *
 * SUPPORTED GATEWAYS:
 *  - monnify      (Monnify Virtual Accounts)
 *  - paystack     (Paystack Dedicated Virtual Accounts)
 *  - flutterwave  (Flutterwave Virtual Account Numbers)
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

    const SUPPORTED_GATEWAYS = [ 'monnify', 'paystack', 'flutterwave' ];

    // ─────────────────────────────────────────────────────────────────────────
    // GATEWAY RESOLVER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get an instance of the configured gateway adapter.
     *
     * @return OFP_Gateway_Interface|null  Null if provider not configured or unsupported.
     */
    private static function get_gateway(): ?object {
        $provider = get_option( 'ofp_payment_provider', 'monnify' );

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
     * Create a dedicated virtual bank account for a client.
     *
     * Called by OFP_Client::create() during onboarding.
     * Returns a normalised object regardless of which gateway handled it.
     *
     * @param  array $client_data {
     *     @type string $business_name
     *     @type string $owner_name
     *     @type string $email
     * }
     * @param  int   $client_id  The OFP client ID (used as account reference).
     * @return object|null       stdClass with ->account_number and ->bank_name, or null.
     */
    public static function create_virtual_account( array $client_data, int $client_id ): ?object {
        $gateway = self::get_gateway();
        if ( ! $gateway ) return null;

        return $gateway->create_virtual_account( $client_data, $client_id );
    }

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
            $amount      = $override_amount ?? OFP_Subscription::get_plan_price( $client->plan );
            $description = 'CRM Plan Payment — ' . ucfirst( (string) $client->plan );
        } elseif ( $type === 'listing' ) {
            if ( ! $plan || ! in_array( $plan, OFP_Property_CPT::PLAN_KEYS, true ) ) {
                return null;
            }
            $amount      = $override_amount ?? OFP_Property_CPT::get_plan_price( $plan );
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
     * @return string  e.g. 'monnify', 'paystack', 'flutterwave'
     */
    public static function get_provider(): string {
        return get_option( 'ofp_payment_provider', 'monnify' );
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
    public function create_virtual_account( array $client_data, int $client_id ): ?object;

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
