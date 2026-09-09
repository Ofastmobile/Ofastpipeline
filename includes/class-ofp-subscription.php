<?php
/**
 * OFP_Subscription
 *
 * Client plan + payment lifecycle.
 *
 * UNIFIED PLAN (current product):
 *  One client plan: free | silver | gold.
 *  CRM and listings are features of that plan, not two products.
 *  Use OFP_Subscription::client_plan( $client_id ) for every feature gate.
 *
 * LEGACY (still in the database — do not SQL-rename yet):
 *  ofp_subscriptions.type = 'crm'     used starter / growth / pro
 *  ofp_subscriptions.type = 'listing' used free / silver / gold
 *  get_expected_monthly_total() used to ADD both prices (double charge).
 *  That is stopped. New checkouts write one listing-plan payment.
 *  Old keys are mapped in place: starter/bronze→free, growth→silver, pro→gold.
 *
 * Depends on: OFP_Mailer, OFP_Client, OFP_Property_CPT, wp_options for pricing.
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

    /** Canonical product plans. Do not add CRM aliases here. */
    const UNIFIED_PLANS = [ 'free', 'silver', 'gold' ];

    const UNIFIED_TIER_ORDER = [
        'free'   => 1,
        'silver' => 2,
        'gold'   => 3,
    ];

    /**
     * Map every historical plan key onto free|silver|gold.
     * Database rows are left as-is; this is display + gating only.
     */
    const PLAN_ALIAS_MAP = [
        'starter' => 'free',
        'bronze'  => 'free',
        'free'    => 'free',
        'growth'  => 'silver',
        'silver'  => 'silver',
        'pro'     => 'gold',
        'gold'    => 'gold',
    ];

    /**
     * Normalize any stored plan key to free|silver|gold.
     */
    public static function normalize_plan( ?string $plan ): string {
        $key = strtolower( trim( (string) $plan ) );
        return self::PLAN_ALIAS_MAP[ $key ] ?? 'free';
    }

    /**
     * Numeric rank for comparing tiers. Higher = more features.
     */
    public static function plan_rank( ?string $plan ): int {
        $normalized = self::normalize_plan( $plan );
        return self::UNIFIED_TIER_ORDER[ $normalized ] ?? 1;
    }

    /**
     * Canonical client plan: free|silver|gold.
     *
     * Takes the highest of:
     *  - ofp_clients.plan (legacy CRM key or unified key)
     *  - ofp_clients.listing_plan (if the column exists)
     *  - the active listing subscription row
     *
     * Does not rename database values.
     */
    public static function client_plan( int $client_id ): string {
        if ( $client_id <= 0 ) {
            return 'free';
        }

        $best = 'free';

        $client = OFP_Client::get( $client_id );
        if ( $client ) {
            $best = self::higher_plan( $best, $client->plan ?? null );
            if ( isset( $client->listing_plan ) ) {
                $best = self::higher_plan( $best, $client->listing_plan );
            }
        }

        $listing = self::get_active_listing_plan( $client_id );
        if ( $listing ) {
            $best = self::higher_plan( $best, $listing );
        }

        return $best;
    }

    /**
     * True when the client's unified plan is at least $min (free|silver|gold).
     */
    public static function client_plan_at_least( int $client_id, string $min ): bool {
        return self::plan_rank( self::client_plan( $client_id ) ) >= self::plan_rank( $min );
    }

    public static function has_paid_plan( int $client_id ): bool {
        return self::client_plan_at_least( $client_id, 'silver' );
    }

    public static function allows_email_templates( int $client_id ): bool {
        return self::client_plan_at_least( $client_id, 'silver' );
    }

    public static function allows_installments( int $client_id ): bool {
        return self::client_plan( $client_id ) === 'gold';
    }

    /**
     * Client can use the product (CRM, listings, payments) — not suspended.
     * Replaces has_active('crm') / has_active('listing') as a feature gate.
     * Those methods still mean "a paid row of that legacy type exists".
     */
    public static function has_platform_access( int $client_id ): bool {
        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return false;
        }
        return ! in_array( $client->status, [ 'suspended', 'cancelled', 'trash' ], true );
    }

    /**
     * Team seat cap for a plan. Free = 0.
     */
    public static function team_member_limit( ?string $plan ): int {
        return match ( self::normalize_plan( $plan ) ) {
            'silver' => 2,
            'gold'   => 3,
            default  => 0,
        };
    }

    /**
     * Monthly price for the unified plan. Uses listing-plan option prices
     * (what Funding already charges), not the old CRM price list.
     */
    public static function unified_plan_price( ?string $plan ): float {
        $plan = self::normalize_plan( $plan );
        if ( $plan === 'free' || ! class_exists( 'OFP_Property_CPT' ) ) {
            return 0.0;
        }
        return OFP_Property_CPT::get_plan_price( $plan );
    }

    /**
     * Return the higher of two plan keys.
     */
    public static function higher_plan( ?string $a, ?string $b ): string {
        $na = self::normalize_plan( $a );
        $nb = self::normalize_plan( $b );
        return self::plan_rank( $nb ) > self::plan_rank( $na ) ? $nb : $na;
    }

    /**
     * Write unified keys onto ofp_clients.plan and listing_plan.
     * Does not rewrite historical ofp_subscriptions rows.
     */
    public static function sync_client_plan_columns( int $client_id, ?string $plan ): void {
        global $wpdb;

        $unified = self::normalize_plan( $plan );
        if ( $client_id <= 0 ) {
            return;
        }

        $data = [
            'plan'       => $unified,
            'updated_at' => current_time( 'mysql' ),
        ];

        $listing_col = $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'listing_plan'" );
        if ( $listing_col ) {
            $data['listing_plan'] = $unified;
        }

        $wpdb->update(
            $wpdb->prefix . 'ofp_clients',
            $data,
            [ 'id' => $client_id ]
        );
    }

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
     * Persist pricing values.
     *
     * @param array $plan_prices
     * @param array $setup_fees
     * @return bool
     */
    public static function save_pricing( array $plan_prices, array $setup_fees ): bool {
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

        if ( $type === 'listing' && $plan ) {
            $plan = self::normalize_plan( $plan );
        }

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
     * True when a client is inside the 7-day window before their
     * subscription expires — the only time a paid (Silver/Gold) client is
     * allowed to renew or switch tiers. Free clients bypass this check
     * entirely wherever it's used (they can pick any plan anytime).
     */
    public static function in_renewal_window( int $client_id ): bool {
        $client = OFP_Client::get( $client_id );
        if ( ! $client || empty( $client->subscription_expires ) ) {
            return true; // No expiry on record yet — don't block.
        }

        $expiry = strtotime( $client->subscription_expires );
        if ( ! $expiry ) {
            return true;
        }

        $days_to_expiry = ( $expiry - time() ) / DAY_IN_SECONDS;
        return $days_to_expiry <= 7;
    }

    /**
     * Expected monthly amount for unmatched VA / webhook payments.
     *
     * ONE plan price only. Previously this added CRM + listing and could
     * double-charge after the product became a single Free/Silver/Gold app.
     */
    public static function get_expected_monthly_total( int $client_id ): float {
        $plan = self::client_plan( $client_id );
        if ( $plan === 'free' ) {
            return 0.0;
        }

        return self::unified_plan_price( $plan );
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
            WHERE client_id = %d AND type = 'listing' AND status IN ('paid', 'pending')
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

        // ── Renewal window reminders — every day from 7 days out to the last
        // day before expiry, escalating in urgency as the date gets closer.
        // (Previously this only fired once at exactly 7 days and once at
        // exactly 3 days — a client who missed those two emails got nothing
        // else until they actually expired.)
        $clients_expiring = $wpdb->get_results(
            "SELECT *, DATEDIFF( subscription_expires, CURDATE() ) AS days_left
             FROM {$p}ofp_clients
             WHERE subscription_expires BETWEEN CURDATE() AND DATE_ADD( CURDATE(), INTERVAL 7 DAY )
               AND status = 'active'
               AND plan IN ('silver', 'gold')"
        );
        foreach ( $clients_expiring as $client ) {
            self::send_reminder( $client, (int) $client->days_left );
        }

        // ── Daily upgrade nudge for clients currently on the free plan ───────
        // Covers both clients who never upgraded and clients who dropped back
        // to free after a paid plan expired without renewal.
        self::send_free_plan_nudges();

        // ── active → free (expired yesterday or earlier) ─────────────────────
        // If a paid plan expires, we drop them down to the free tier instead of suspending.
        $expired_clients = $wpdb->get_results(
            "SELECT id, plan, listing_plan FROM {$p}ofp_clients
             WHERE subscription_expires < CURDATE()
               AND status = 'active'
               AND (plan != 'free' OR listing_plan != 'free')"
        );

        foreach ( $expired_clients as $expired ) {
            $wpdb->update(
                $p . 'ofp_clients',
                [ 'plan' => 'free', 'listing_plan' => 'free' ],
                [ 'id' => $expired->id ]
            );

            if ( class_exists( 'OFP_Logger' ) ) {
                OFP_Logger::log( 'Plan expired — dropped to Free', (int) $expired->id, [
                    'previous_plan' => $expired->plan,
                ] );
            }
        }

        // ── Clean expired session tokens ──────────────────────────────────────
        OFP_Auth::purge_expired_sessions();
    }

    /**
     * Daily FOMO/upgrade nudge for every active client currently on the
     * free plan — whether they signed up on free, or dropped back to it
     * after a paid plan lapsed. Runs once a day via the daily cron, so each
     * client gets at most one of these a day.
     */
    public static function send_free_plan_nudges(): void {
        global $wpdb;

        $free_clients = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ofp_clients
             WHERE status = 'active' AND plan = 'free'"
        );

        foreach ( $free_clients as $client ) {
            OFP_Mailer::send_free_plan_nudge( $client );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAYMENT RECORDING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Record a confirmed payment and activate / renew the subscription.
     *
     * Called by OFP_Monnify::handle_webhook() after a payment is verified,
     * and by OFP_Payment::confirm_subscription_checkout() (Phase 20).
     *
     * @param  int         $client_id      Client ID.
     * @param  string      $type           'crm' or 'listing'.
     * @param  float       $amount         Amount paid in NGN.
     * @param  string      $payment_ref    Gateway transaction reference.
     * @param  string      $method         Payment method (e.g. 'virtual_account', 'checkout').
     * @param  string|null $plan_override  Explicit plan tier — pass this when the caller
     *                                     already knows it (e.g. checkout flow encodes the
     *                                     tier in the reference). Only relevant for 'listing'.
     * @param  float|null  $expected_amount What this checkout expected to receive — stored so
     *                                     an overpaid row still shows the right baseline in Billing.
     * @return void
     */
    public static function record_payment(
        int $client_id,
        string $type,
        float $amount,
        string $payment_ref,
        string $method = 'virtual_account',
        ?string $plan_override = null,
        ?float $expected_amount = null
    ): void {
        global $wpdb;

        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return;
        }

        $period_start = gmdate( 'Y-m-d' );
        $period_end   = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

        if ( $type === 'crm' ) {
            $plan = $plan_override ?: $client->plan;
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

        if ( $type === 'listing' && $plan ) {
            $plan = self::normalize_plan( $plan );
        }

        // Insert a new paid subscription record for this payment cycle.
        $wpdb->insert(
            $wpdb->prefix . 'ofp_subscriptions',
            [
                'client_id'       => $client_id,
                'type'            => $type,
                'plan'            => $plan,
                'amount'          => $amount,
                'expected_amount' => $expected_amount,
                'payment_method'  => $method,
                'payment_ref'     => sanitize_text_field( $payment_ref ),
                'status'          => 'paid',
                'period_start'    => $period_start,
                'period_end'      => $period_end,
                'paid_at'         => current_time( 'mysql' ),
                'created_at'      => current_time( 'mysql' ),
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

        // Keep ofp_clients.plan / listing_plan on the unified key so feature
        // gates (templates, listing caps, installments) see one plan.
        $sync_plan = $plan ?: ( $client->plan ?? 'free' );
        self::sync_client_plan_columns( $client_id, $sync_plan );

        // Send payment confirmation email.
        OFP_Mailer::send_payment_confirmed( $client, $amount, $type );

        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( 'Payment successful', $client_id, [
                'amount'    => $amount,
                'type'      => $type,
                'plan'      => $plan,
                'method'    => $method,
                'reference' => $payment_ref
            ] );
        }
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

    /**
     * Central payment-processing entry point for ALL gateway adapters.
     *
     * Phase 18: previously each gateway (Monnify, Paystack, Flutterwave)
     * duplicated its own amount-checking logic, and all three had the same
     * bug — an underpaid amount was either silently ignored (Paystack,
     * Flutterwave: nothing recorded, nothing logged) or logged and dropped
     * (Monnify: error_log() only). In every case the payment was effectively
     * lost — the client's money moved, but nothing in the system reflected it.
     *
     * Now every gateway calls this ONE method after verifying its webhook
     * signature. It decides paid vs underpaid in one place, so the fix
     * only ever needs to happen here.
     *
     * @param  int    $client_id    Client ID extracted from the payment reference.
     * @param  float  $amount       Amount actually received, in NGN.
     * @param  string $payment_ref  Gateway's transaction reference.
     * @param  string $method       e.g. 'monnify_virtual_account', 'paystack_virtual_account'.
     * @return void
     */
    public static function process_gateway_payment(
        int $client_id,
        float $amount,
        string $payment_ref,
        string $method
    ): void {
        $expected = self::get_expected_monthly_total( $client_id );

        if ( $amount >= $expected && $expected > 0 ) {
            self::apply_full_payment( $client_id, $amount, $payment_ref, $method );
            return;
        }

        if ( $expected <= 0 ) {
            // No CRM/listing subscription currently expects a payment at all
            // (e.g. a stray/duplicate webhook). Record as underpaid so it's
            // visible rather than silently vanishing, but don't guess a type.
            self::record_underpayment( $client_id, $amount, $expected, $payment_ref, $method );
            return;
        }

        self::record_underpayment( $client_id, $amount, $expected, $payment_ref, $method );
    }

    /**
     * Apply a payment that meets or exceeds the expected monthly total.
     * Records ONE listing-plan row at the client's unified tier.
     * Does not also write a CRM subscription row.
     */
    private static function apply_full_payment(
        int $client_id,
        float $amount,
        string $payment_ref,
        string $method
    ): void {
        $plan = self::client_plan( $client_id );
        if ( $plan === 'free' ) {
            $plan = 'silver';
        }

        self::record_payment( $client_id, 'listing', $amount, $payment_ref, $method, $plan );
    }

    /**
     * Record an underpaid transaction instead of silently dropping it.
     *
     * Inserts a subscription row with status = 'underpaid' so it shows up
     * in the admin Billing view with both the amount received and the
     * amount expected, and notifies both the admin and the client so
     * nobody is left wondering why their pipeline didn't renew.
     *
     * Admin resolves this manually via the existing "Mark Paid" action on
     * the Billing page (ofp_mark_subscription_paid already accepts any
     * non-paid row, underpaid included) — or by contacting the client for
     * the balance first.
     *
     * @param  int    $client_id
     * @param  float  $amount_paid
     * @param  float  $expected
     * @param  string $payment_ref
     * @param  string $method
     * @return void
     */
    public static function record_underpayment(
        int $client_id,
        float $amount_paid,
        float $expected,
        string $payment_ref,
        string $method,
        ?string $plan_override = null
    ): void {
        global $wpdb;

        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            error_log( "[OFP_Subscription] record_underpayment: client {$client_id} not found." );
            return;
        }

        $plan = $plan_override ? self::normalize_plan( $plan_override ) : $client->plan;

        $wpdb->insert(
            $wpdb->prefix . 'ofp_subscriptions',
            [
                'client_id'       => $client_id,
                'type'            => 'listing',
                'plan'            => $plan,
                'amount'          => $amount_paid,
                'expected_amount' => $expected,
                'payment_method'  => $method,
                'payment_ref'     => sanitize_text_field( $payment_ref ),
                'status'          => 'underpaid',
                'created_at'      => current_time( 'mysql' ),
            ]
        );

        $shortfall = max( 0, $expected - $amount_paid );

        // Notify admin — this needs a human decision, it can't self-resolve.
        OFP_Mailer::send(
            get_option( 'admin_email' ),
            'Admin',
            'Underpayment Received — ' . $client->business_name,
            '<h2>⚠️ Underpayment Received</h2>'
            . '<p><strong>Client:</strong> ' . esc_html( $client->business_name ) . '</p>'
            . '<p><strong>Plan:</strong> ' . esc_html( ucfirst( $plan ) ) . '</p>'
            . '<p><strong>Amount received:</strong> NGN ' . number_format( $amount_paid, 2 ) . '</p>'
            . '<p><strong>Amount expected:</strong> NGN ' . number_format( $expected, 2 ) . '</p>'
            . '<p><strong>Shortfall:</strong> NGN ' . number_format( $shortfall, 2 ) . '</p>'
            . '<p><strong>Reference:</strong> ' . esc_html( $payment_ref ) . '</p>'
            . '<p>Review this in wp-admin → OFast Pipeline → Billing before it renews automatically.</p>'
        );

        // Let the client know too, via their own notification preference.
        if ( class_exists( 'OFP_Notification' ) ) {
            OFP_Notification::create(
                $client_id,
                'underpayment_received',
                'Payment received — balance still due',
                'We received NGN ' . number_format( $amount_paid, 2 ) . ', but your plan requires '
                . 'NGN ' . number_format( $expected, 2 ) . '. Please pay the remaining '
                . 'NGN ' . number_format( $shortfall, 2 ) . ' to activate your subscription.'
            );
        }

        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( 'Underpayment received', $client_id, [
                'amount_paid' => $amount_paid,
                'expected'    => $expected,
                'plan'        => $plan,
                'method'      => $method,
                'reference'   => $payment_ref
            ] );
        }
    }

    /**
     * A checkout payment came in for MORE than the plan it was for.
     * The plan is already activated by the time this runs (record_payment
     * already ran) — this just puts the excess in front of an admin to
     * decide what to do with it (refund, credit, ignore). It never blocks
     * or delays activation.
     */
    public static function flag_overpayment(
        int $client_id,
        float $amount_paid,
        float $expected,
        string $payment_ref
    ): void {
        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return;
        }

        $excess = max( 0, $amount_paid - $expected );

        OFP_Mailer::send(
            get_option( 'admin_email' ),
            'Admin',
            'Overpayment Received — ' . $client->business_name,
            '<h2>💰 Overpayment Received</h2>'
            . '<p><strong>Client:</strong> ' . esc_html( $client->business_name ) . '</p>'
            . '<p><strong>Amount received:</strong> NGN ' . number_format( $amount_paid, 2 ) . '</p>'
            . '<p><strong>Plan price:</strong> NGN ' . number_format( $expected, 2 ) . '</p>'
            . '<p><strong>Excess:</strong> NGN ' . number_format( $excess, 2 ) . '</p>'
            . '<p><strong>Reference:</strong> ' . esc_html( $payment_ref ) . '</p>'
            . '<p>The plan is already active — this is just the extra amount, decide '
            . 'whether to refund it or credit it toward their next renewal in wp-admin → '
            . 'OFast Pipeline → Billing.</p>'
        );

        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( 'Overpayment received', $client_id, [
                'amount_paid' => $amount_paid,
                'expected'    => $expected,
                'excess'      => $excess,
                'reference'   => $payment_ref
            ] );
        }
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
            return OFP_Property_CPT::get_plan_price( $plan );
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
    /**
     * Activate or extend a subscription from an admin-approved manual
     * (bank transfer) funding request.
     *
     * Bug fix: this used to only touch status/amount/period_end and never
     * wrote the plan anywhere (not on ofp_clients.plan, not on the
     * ofp_subscriptions row it updated). client_plan() and
     * get_active_listing_plan() both depend on that plan value, so a
     * manually-approved Silver/Gold payment would extend the expiry date
     * but the client would still show as 'free' everywhere else — the
     * Funding page kept asking them to pay even though they were "active".
     *
     * @param  int         $client_id
     * @param  string      $type        'listing' (or legacy 'crm', mapped through).
     * @param  float       $amount_paid
     * @param  string|null $plan        The tier this payment activates: silver|gold.
     *                                  Required for a correct plan sync; if omitted,
     *                                  falls back to the client's current plan so old
     *                                  callers don't fatal, but won't fix a mismatch.
     */
    public static function activate_from_manual_payment(
        int $client_id,
        string $type,
        float $amount_paid,
        ?string $plan = null
    ): void {
        global $wpdb;

        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return;
        }

        // Product is unified — CRM manual approvals become a listing payment too.
        $type = 'listing';
        $plan = $plan ? self::normalize_plan( $plan ) : ( $client->plan ?? 'silver' );
        if ( $plan === 'free' ) {
            $plan = 'silver';
        }

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
                    'status'          => 'paid',
                    'plan'            => $plan,
                    'payment_method'  => 'manual',
                    'amount'          => $amount_paid,
                    'expected_amount' => null,
                    'period_end'      => $period_end,
                ],
                [ 'id' => $existing->id ]
            );
        } else {
            // No row at all — create and immediately activate.
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
        if ( in_array( $client->status, $needs_activation, true ) ) {
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

        // The fix: keep ofp_clients.plan / listing_plan in sync so client_plan(),
        // the Funding page, and every menu/feature gate all agree immediately.
        self::sync_client_plan_columns( $client_id, $plan );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PHASE 22 — UNDERPAID HELPERS + FREE-TIER ACTIVATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * All subscription rows currently sitting at 'underpaid' for a client.
     * Used by the client dashboard banner (Phase 22) to show exactly what's
     * still owed, rather than a generic "you have something unpaid" message.
     *
     * @param  int $client_id
     * @return array
     */
    public static function get_underpaid_for_client( int $client_id ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_subscriptions
                 WHERE client_id = %d AND status = 'underpaid'
                 ORDER BY created_at DESC",
                $client_id
            )
        );
    }

    /**
     * Whether a client has any subscription row still needing payment
     * (pending, or underpaid). Distinct from has_active(), which only
     * checks for a currently paid+valid subscription.
     *
     * @param  int $client_id
     * @return bool
     */
    public static function has_unpaid( int $client_id ): bool {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_subscriptions
                 WHERE client_id = %d AND status IN ('pending','underpaid')
                 LIMIT 1",
                $client_id
            )
        );
    }

    /**
     * Activate a listing (or CRM) subscription immediately at zero cost —
     * for free tiers like Bronze. Skips checkout entirely since there's
     * nothing to pay. Mirrors activate_from_manual_payment() but tagged
     * as 'free_tier' in payment_method for clear billing-log attribution.
     *
     * @param  int    $client_id
     * @param  string $type  'crm' or 'listing'.
     * @param  string|null $plan
     * @return void
     */
    public static function activate_free_tier( int $client_id, string $type, ?string $plan = null ): void {
        global $wpdb;

        $period_end = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

        $wpdb->insert(
            $wpdb->prefix . 'ofp_subscriptions',
            [
                'client_id'      => $client_id,
                'type'           => $type,
                'plan'           => $plan,
                'amount'         => 0,
                'payment_method' => 'free_tier',
                'status'         => 'paid',
                'period_start'   => gmdate( 'Y-m-d' ),
                'period_end'     => $period_end,
                'paid_at'        => current_time( 'mysql' ),
                'created_at'     => current_time( 'mysql' ),
            ]
        );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}ofp_clients
             SET status = 'active',
                 subscription_expires = GREATEST( subscription_expires, %s ),
                 updated_at = NOW()
             WHERE id = %d",
            $period_end, $client_id
        ) );
    }
}
