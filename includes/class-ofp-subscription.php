<?php
/**
 * OFP_Subscription
 *
 * Manages client subscription lifecycle for both CRM and Listing subscription types.
 *
 * SUBSCRIPTION TYPE SYSTEM (v2.1):
 *  A single client can hold up to TWO active subscription rows simultaneously:
 *   - type = 'crm'     : Starter / Growth / Pro plan (lead automation pipeline)
 *   - type = 'listing' : Property listing directory fee
 *
 *  Each type is independently priced, independently renewed, and independently
 *  toggled. Both are paid into the same Monnify virtual account — the webhook
 *  handler (OFP_Monnify) sums the expected amounts when matching payments.
 *
 * SUBSCRIPTION LIFECYCLE:
 *  pending → paid → (30 days) → expiring_soon → grace → suspended → cancelled
 *
 *  Status transitions are driven by run_daily_check(), which fires via WP-Cron
 *  every day at midnight. Manual overrides are possible via manual_toggle().
 *
 * PIPELINE CONFIG:
 *  A pipeline_config row is ONLY created when type = 'crm'. Listing-only clients
 *  have no SMS/voice sequence — they just get a listing page and lead capture form.
 *
 * Depends on: OFP_Mailer, OFP_Client, wp_options for pricing.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Subscription {

    /**
     * CRM plan pricing in NGN.
     * Stored here as the canonical reference. Monnify webhook uses these same values.
     */
    const CRM_PRICES = [
        'starter' => 25000,
        'growth'  => 45000,
        'pro'     => 75000,
    ];

    const PLAN_KEYS = [ 'starter', 'growth', 'pro' ];

    const DEFAULT_PLAN_PRICES = [
        'starter' => 25000.00,
        'growth'  => 45000.00,
        'pro'     => 75000.00,
    ];

    const DEFAULT_SETUP_FEES = [
        'starter' => 15000.00,
        'growth'  => 25000.00,
        'pro'     => 40000.00,
    ];

    const DEFAULT_LISTING_FEE = 7500.00;

    /**
     * Returns all CRM monthly plan prices.
     *
     * @return array
     */
    public static function get_plan_prices(): array {
        $prices = [];
        foreach ( self::PLAN_KEYS as $plan ) {
            $prices[ $plan ] = (float) get_option( "ofp_plan_price_{$plan}", self::DEFAULT_PLAN_PRICES[ $plan ] );
        }

        return $prices;
    }

    /**
     * Returns all CRM setup fees.
     *
     * @return array
     */
    public static function get_setup_fees(): array {
        $fees = [];
        foreach ( self::PLAN_KEYS as $plan ) {
            $fees[ $plan ] = (float) get_option( "ofp_plan_setup_fee_{$plan}", self::DEFAULT_SETUP_FEES[ $plan ] );
        }

        return $fees;
    }

    /**
     * Get a single plan monthly price.
     *
     * @param string|null $plan
     * @return float
     */
    public static function get_plan_price( ?string $plan ): float {
        if ( ! $plan || ! in_array( $plan, self::PLAN_KEYS, true ) ) {
            return 0.0;
        }

        return (float) get_option( "ofp_plan_price_{$plan}", self::DEFAULT_PLAN_PRICES[ $plan ] );
    }

    /**
     * Get a single setup fee.
     *
     * @param string|null $plan
     * @return float
     */
    public static function get_setup_fee( ?string $plan ): float {
        if ( ! $plan || ! in_array( $plan, self::PLAN_KEYS, true ) ) {
            return 0.0;
        }

        return (float) get_option( "ofp_plan_setup_fee_{$plan}", self::DEFAULT_SETUP_FEES[ $plan ] );
    }

    /**
     * Get the listing fee.
     *
     * @return float
     */
    public static function get_listing_fee(): float {
        return (float) get_option( 'ofp_listing_fee_monthly', self::DEFAULT_LISTING_FEE );
    }

    /**
     * Persist pricing values.
     *
     * @param array $plan_prices
     * @param array $setup_fees
     * @param float $listing_fee
     * @return bool
     */
    public static function save_pricing( array $plan_prices, array $setup_fees, float $listing_fee ): bool {
        foreach ( self::PLAN_KEYS as $plan ) {
            $price = isset( $plan_prices[ $plan ] )
                ? max( 0.0, (float) $plan_prices[ $plan ] )
                : self::DEFAULT_PLAN_PRICES[ $plan ];

            $fee = isset( $setup_fees[ $plan ] )
                ? max( 0.0, (float) $setup_fees[ $plan ] )
                : self::DEFAULT_SETUP_FEES[ $plan ];

            update_option( "ofp_plan_price_{$plan}", $price );
            update_option( "ofp_plan_setup_fee_{$plan}", $fee );
        }

        update_option( 'ofp_listing_fee_monthly', max( 0.0, $listing_fee ) );

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a new subscription row for a client.
     *
     * For CRM subscriptions, also creates the pipeline_config row if one
     * doesn't already exist — this is the only place pipeline_config is
     * auto-created. Listing-only clients never get a pipeline_config.
     *
     * @param  int         $client_id  The client's ID.
     * @param  string      $type       'crm' or 'listing'.
     * @param  string|null $plan       CRM plan: 'starter'|'growth'|'pro'. Null for listing.
     * @return int                     The new subscription row ID.
     */
    public static function create( int $client_id, string $type, ?string $plan = null ): int {
        global $wpdb;

        $amount = self::resolve_amount( $type, $plan );

        $wpdb->insert(
            $wpdb->prefix . 'ofp_subscriptions',
            [
                'client_id'      => $client_id,
                'type'           => sanitize_text_field( $type ),
                'plan'           => $plan ? sanitize_text_field( $plan ) : null,
                'amount'         => $amount,
                'payment_method' => 'pending',
                'status'         => 'pending',
                'created_at'     => current_time( 'mysql' ),
            ]
        );

        $subscription_id = (int) $wpdb->insert_id;

        // Only CRM subscriptions get a pipeline config.
        if ( $type === 'crm' ) {
            $config_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ofp_pipeline_configs WHERE client_id = %d LIMIT 1",
                    $client_id
                )
            );

            if ( ! $config_exists ) {
                // Use admin-configured defaults from Settings if available,
                // otherwise fall back to hardcoded defaults.
                $wpdb->insert(
                    $wpdb->prefix . 'ofp_pipeline_configs',
                    [
                        'client_id'              => $client_id,
                        'instant_sms_enabled'    => 1,
                        'instant_sms_message'    => get_option( 'ofp_default_instant_sms', self::default_instant_sms() ),
                        'followup_1_delay_hours' => 1,
                        'followup_1_type'        => 'sms',
                        'followup_1_message'     => get_option( 'ofp_default_followup_1', self::default_followup_sms() ),
                        'followup_2_delay_hours' => 24,
                        'followup_2_type'        => 'voice',
                        'followup_2_message'     => get_option( 'ofp_default_followup_2', self::default_ivr_message() ),
                        'followup_3_delay_hours' => 72,
                        'followup_3_type'        => 'sms',
                        'followup_3_message'     => get_option( 'ofp_default_followup_3', self::default_followup_3_sms() ),
                        'max_followups'          => 3,
                        'ivr_option_1_action'    => 'transfer',
                        'ivr_option_2_action'    => 'sms',
                        'ivr_option_3_action'    => 'schedule',
                    ]
                );
            }
        }

        return $subscription_id;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // READ / CHECK
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if a client has an active paid subscription of a given type.
     *
     * "Active" means: status = 'paid' AND period_end is either NULL or in the future.
     * NULL period_end means no expiry date set yet (e.g. first payment pending).
     *
     * @param  string $type       'crm' or 'listing'.
     * @param  int    $client_id  Client ID.
     * @return bool               True if an active subscription exists.
     */
    public static function has_active( string $type, int $client_id ): bool {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_subscriptions
                 WHERE client_id = %d
                   AND type      = %s
                   AND status    = 'paid'
                   AND ( period_end IS NULL OR period_end >= CURDATE() )
                 ORDER BY period_end DESC
                 LIMIT 1",
                $client_id,
                $type
            )
        );

        return (bool) $row;
    }

    /**
     * Get the active subscription row for a given type and client.
     *
     * @param  string      $type       'crm' or 'listing'.
     * @param  int         $client_id  Client ID.
     * @return object|null             Subscription row or null.
     */
    public static function get_active( string $type, int $client_id ): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_subscriptions
                 WHERE client_id = %d
                   AND type      = %s
                   AND status    = 'paid'
                   AND ( period_end IS NULL OR period_end >= CURDATE() )
                 ORDER BY period_end DESC
                 LIMIT 1",
                $client_id,
                $type
            )
        );
    }

    /**
     * Get all subscription rows for a client (both types, all statuses).
     *
     * @param  int   $client_id  Client ID.
     * @return array             Array of subscription rows.
     */
    public static function get_all_for_client( int $client_id ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_subscriptions
                 WHERE client_id = %d
                 ORDER BY created_at DESC",
                $client_id
            )
        );
    }

    /**
     * Calculate the total expected monthly payment for a client.
     * Used by OFP_Monnify when matching incoming webhook payments.
     *
     * @param  int   $client_id  Client ID.
     * @return float             Total NGN amount expected per month.
     */
    public static function get_expected_monthly_total( int $client_id ): float {
        global $wpdb;

        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT plan FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $client_id
            )
        );

        $total = 0.0;

        // Add CRM plan cost if client has an active or pending CRM subscription.
        $has_crm = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_subscriptions
                 WHERE client_id = %d AND type = 'crm'
                   AND status IN ('paid','pending')
                 LIMIT 1",
                $client_id
            )
        );

        if ( $has_crm && $client ) {
            $total += self::get_plan_price( $client->plan );
        }

        // Add listing fee if client has an active or pending listing subscription.
        $has_listing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_subscriptions
                 WHERE client_id = %d AND type = 'listing'
                   AND status IN ('paid','pending')
                 LIMIT 1",
                $client_id
            )
        );

        if ( $has_listing ) {
            $total += OFP_Property_CPT::get_plan_price( self::get_active_listing_plan( $client_id ) );
        }

        return $total;
    }

    /**
     * The client's currently active listing plan tier ('bronze'|'silver'|'gold'),
     * or null if they have no active listing subscription at all.
     *
     * Phase 14: listing subscriptions now carry a plan tier in the same
     * `plan` column CRM subscriptions already use.
     *
     * @param int $client_id
     * @return string|null
     */
    public static function get_active_listing_plan( int $client_id ): ?string {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "
            SELECT plan FROM {$wpdb->prefix}ofp_subscriptions
            WHERE client_id = %d AND type = 'listing' AND status = 'paid'
            AND (period_end IS NULL OR period_end >= CURDATE())
            ORDER BY period_end DESC LIMIT 1
        ", $client_id ) );

        return $row ? $row->plan : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DAILY LIFECYCLE CHECK (WP-CRON)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run the daily subscription lifecycle check.
     *
     * Called by OFP_Cron_Handler on the 'ofp_daily_subscription_check' hook.
     *
     * Actions taken in order:
     *  1. Send 7-day expiry reminder emails
     *  2. Send 3-day expiry reminder emails
     *  3. Move expired active clients to 'grace' status
     *  4. Move grace clients (5+ days past expiry) to 'suspended'
     *  5. Move long-suspended clients (35+ days past expiry) to 'cancelled'
     *
     * @return void
     */
    public static function run_daily_check(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // ── 7-day reminder ────────────────────────────────────────────────────
        $clients_7 = $wpdb->get_results(
            "SELECT * FROM {$p}ofp_clients
             WHERE subscription_expires = DATE_ADD( CURDATE(), INTERVAL 7 DAY )
               AND status = 'active'"
        );
        foreach ( $clients_7 as $client ) {
            self::send_reminder( $client, 7 );
        }

        // ── 3-day reminder ────────────────────────────────────────────────────
        $clients_3 = $wpdb->get_results(
            "SELECT * FROM {$p}ofp_clients
             WHERE subscription_expires = DATE_ADD( CURDATE(), INTERVAL 3 DAY )
               AND status = 'active'"
        );
        foreach ( $clients_3 as $client ) {
            self::send_reminder( $client, 3 );
        }

        // ── active → grace (expired yesterday or earlier) ─────────────────────
        $wpdb->query(
            "UPDATE {$p}ofp_clients
             SET status = 'grace'
             WHERE subscription_expires < CURDATE()
               AND status = 'active'"
        );

        // ── grace → suspended (5+ days in grace) ─────────────────────────────
        $wpdb->query(
            "UPDATE {$p}ofp_clients
             SET status = 'suspended'
             WHERE status = 'grace'
               AND subscription_expires < DATE_SUB( CURDATE(), INTERVAL 5 DAY )"
        );

        // ── suspended → cancelled (35+ days total past expiry) ────────────────
        $wpdb->query(
            "UPDATE {$p}ofp_clients
             SET status = 'cancelled'
             WHERE status = 'suspended'
               AND subscription_expires < DATE_SUB( CURDATE(), INTERVAL 35 DAY )"
        );

        // ── Clean expired session tokens ──────────────────────────────────────
        OFP_Auth::purge_expired_sessions();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAYMENT RECORDING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Record a confirmed payment and activate / renew the subscription.
     *
     * Called by OFP_Monnify::handle_webhook() after a payment is verified.
     *
     * @param  int    $client_id    Client ID.
     * @param  string $type         'crm' or 'listing'.
     * @param  float  $amount       Amount paid in NGN.
     * @param  string $payment_ref  Monnify transaction reference.
     * @param  string $method       Payment method (e.g. 'virtual_account').
     * @return void
     */
    public static function record_payment(
        int $client_id,
        string $type,
        float $amount,
        string $payment_ref,
        string $method = 'virtual_account'
    ): void {
        global $wpdb;

        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return;
        }

        $period_start = gmdate( 'Y-m-d' );
        $period_end   = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
        $plan         = $type === 'crm' ? $client->plan : null;

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

    // ─────────────────────────────────────────────────────────────────────────
    // MANUAL ADMIN CONTROLS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Manually toggle a client's status (admin action from wp-admin).
     *
     * If setting to 'active', also extends subscription_expires by 30 days
     * from today so the client gets a full billing cycle.
     *
     * @param  int    $client_id  Client ID.
     * @param  string $status     Target status.
     * @return void
     */
    public static function manual_toggle( int $client_id, string $status ): void {
        global $wpdb;

        OFP_Client::update_status( $client_id, $status );

        if ( $status === 'active' ) {
            $wpdb->update(
                $wpdb->prefix . 'ofp_clients',
                [ 'subscription_expires' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) ],
                [ 'id' => $client_id ]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRICING RESOLVER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the monthly fee for a given subscription type and plan.
     *
     * CRM prices are defined as class constants above.
     * Listing fee is configurable via wp-admin → Settings → Listing Fee.
     *
     * @param  string      $type  'crm' or 'listing'.
     * @param  string|null $plan  CRM plan name (only relevant when type = 'crm').
     * @return float              Monthly fee in NGN.
     */
    public static function resolve_amount( string $type, ?string $plan ): float {
        if ( $type === 'crm' ) {
            return self::get_plan_price( $plan );
        }

        if ( $type === 'listing' ) {
            return self::get_listing_fee();
        }

        return 0.0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send a subscription expiry reminder email via OFP_Mailer.
     *
     * @param  object $client    Full ofp_clients row.
     * @param  int    $days_left Days until expiry.
     * @return void
     */
    private static function send_reminder( object $client, int $days_left ): void {
        OFP_Mailer::send_subscription_reminder( $client, $days_left );
    }

    /**
     * Default instant SMS message used when a new CRM client's pipeline_config is created.
     * The client can customise this from their dashboard → Pipeline Settings.
     *
     * @return string
     */
    private static function default_instant_sms(): string {
        return 'Hi {{name}}, thank you for your interest! We received your request and will be in touch very shortly. - {{business_name}}';
    }

    /**
     * Default 1-hour follow-up SMS message.
     *
     * @return string
     */
    private static function default_followup_sms(): string {
        return 'Hi {{name}}, just checking in — did you get our earlier message? We would love to help. Reply to this SMS or call us directly. - {{business_name}}';
    }

    /**
     * Default 24-hour IVR voice call script.
     *
     * @return string
     */
    private static function default_ivr_message(): string {
        return 'Hello, this is a message from {{business_name}}. You recently showed interest in our services. Press 1 to speak with us now. Press 2 to receive our WhatsApp contact. Press 3 for us to call you back later.';
    }

    /**
     * Default 72-hour follow-up SMS message.
     *
     * @return string
     */
    private static function default_followup_3_sms(): string {
        return 'Hi {{name}}, we have been trying to reach you. We would love to show you how {{business_name}} can help. Call or message us anytime. - {{business_name}}';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PHASE 17b — MANUAL PAYMENT ACTIVATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Activates or extends a subscription when admin approves a manual plan payment.
     *
     * Different from create() which makes a new pending row — this finds the
     * most recent pending or expired row for that client and type, sets it to
     * 'paid', and pushes the period_end date 30 days forward from today.
     *
     * If no existing row is found, it creates one and immediately activates it,
     * so admin approval always results in an active subscription regardless of
     * what state the data was in before.
     *
     * @param int    $client_id
     * @param string $type         'crm' or 'listing'
     * @param float  $amount_paid  The amount the client actually paid (stored for reference)
     */
    public static function activate_from_manual_payment(
        int $client_id,
        string $type,
        float $amount_paid
    ): void {
        global $wpdb;

        $existing = $wpdb->get_row( $wpdb->prepare( "
            SELECT * FROM {$wpdb->prefix}ofp_subscriptions
            WHERE client_id = %d AND type = %s
            ORDER BY created_at DESC LIMIT 1
        ", $client_id, $type ) );

        $period_end = date( 'Y-m-d', strtotime( '+30 days' ) );

        if ( $existing ) {
            $wpdb->update(
                $wpdb->prefix . 'ofp_subscriptions',
                [
                    'status'         => 'paid',
                    'payment_method' => 'manual',
                    'amount'         => $amount_paid,
                    'period_end'     => $period_end,
                ],
                [ 'id' => $existing->id ]
            );
        } else {
            // No row at all — create and immediately activate.
            $client = OFP_Client::get( $client_id );
            $plan   = ( $type === 'crm' ) ? ( $client->plan ?? 'starter' ) : null;

            $wpdb->insert( $wpdb->prefix . 'ofp_subscriptions', [
                'client_id'      => $client_id,
                'type'           => $type,
                'plan'           => $plan,
                'amount'         => $amount_paid,
                'payment_method' => 'manual',
                'status'         => 'paid',
                'period_end'     => $period_end,
                'created_at'     => current_time( 'mysql' ),
            ] );
        }

        // Bring the client status back to 'active' if they were pending/suspended/etc.
        $needs_activation = [ 'pending_review', 'pending', 'grace', 'suspended' ];
        $client = OFP_Client::get( $client_id );
        if ( $client && in_array( $client->status, $needs_activation, true ) ) {
            OFP_Client::update_status( $client_id, 'active' );
        }

        // Also extend subscription_expires on the clients table (same as record_payment).
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}ofp_clients
             SET status               = 'active',
                 subscription_expires = %s,
                 updated_at           = NOW()
             WHERE id = %d",
            $period_end,
            $client_id
        ) );
    }
}
