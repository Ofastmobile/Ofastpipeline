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



    const PLAN_KEYS = [ 'starter', 'growth', 'pro' ];

    const DEFAULT_PLAN_PRICES = [
        'starter' => 25000.00,
        'growth'  => 45000.00,
        'pro'     => 75000.00,
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
    /**
     * True when a client's paid period has run out. The plan then counts as
     * Free right away, even before the daily job has reset the saved plan.
     * Solo companies never expire.
     */
    public static function is_plan_expired( ?object $client ): bool {
        if ( ! $client ) {
            return false;
        }
        // A yearly white label that has run out counts as Free. Old white label
        // clients (no billing set) and lifetime clients never expire.
        if ( ! empty( $client->is_white_label ) ) {
            return OFP_White_Label::yearly_expired( $client );
        }
        if ( empty( $client->subscription_expires ) ) {
            return false;
        }
        return strtotime( $client->subscription_expires ) < strtotime( current_time( 'Y-m-d' ) );
    }

    public static function client_plan( int $client_id ): string {
        if ( $client_id <= 0 ) {
            return 'free';
        }

        $best = 'free';

        $client = OFP_Client::get( $client_id );
        if ( self::is_plan_expired( $client ) ) {
            return 'free';
        }
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
        return self::plan_rank( self::feature_plan( $client_id ) ) >= self::plan_rank( $min );
    }

    /**
     * A white label (solo company) has no limits at all. They are billed by
     * the admin outside the plan system, so every feature gate treats them as
     * Gold with unlimited listings and team seats.
     * Billing code keeps using client_plan(), so nothing is charged for this.
     */
    public static function is_unlimited( int $client_id ): bool {
        static $memo = [];
        if ( $client_id <= 0 ) {
            return false;
        }
        if ( ! isset( $memo[ $client_id ] ) ) {
            $client = OFP_Client::get( $client_id );
            // Unlimited while white label benefits are active. A yearly plan that ended is on Free limits.
            $memo[ $client_id ] = $client && OFP_White_Label::has_full_benefits( $client );
        }
        return $memo[ $client_id ];
    }

    /** The plan to use for FEATURE gates. Never use this for billing. */
    public static function feature_plan( int $client_id ): string {
        return self::is_unlimited( $client_id ) ? 'gold' : self::client_plan( $client_id );
    }

    /** Team seats for a client. Unlimited for a solo company. */
    public static function team_limit_for_client( int $client_id ): int {
        if ( self::is_unlimited( $client_id ) ) {
            return 100000;
        }
        return self::team_member_limit( self::client_plan( $client_id ) );
    }

    public static function has_paid_plan( int $client_id ): bool {
        return self::client_plan_at_least( $client_id, 'silver' );
    }

    public static function allows_email_templates( int $client_id ): bool {
        return self::client_plan_at_least( $client_id, 'silver' );
    }

    public static function allows_installments( int $client_id ): bool {
        return self::feature_plan( $client_id ) === 'gold';
    }

    /** Rent management follows the same Gold-only server-side gate as installments. */
    public static function allows_rent_management( int $client_id ): bool {
        return self::feature_plan( $client_id ) === 'gold';
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

        // Plan history: write down every real plan change and what caused it,
        // so "who changed this plan?" can always be answered from the Activity Log.
        $before = $wpdb->get_row( $wpdb->prepare( "SELECT plan, listing_plan FROM {$wpdb->prefix}ofp_clients WHERE id = %d", $client_id ) );
        if ( $before && class_exists( 'OFP_Logger' ) && ( self::normalize_plan( $before->plan ) !== $unified ) ) {
            $trace  = [];
            foreach ( array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 ), 1, 4 ) as $frame ) {
                $trace[] = ( $frame['class'] ?? '' ) . '::' . ( $frame['function'] ?? '' );
            }
            $who = function_exists( 'wp_get_current_user' ) && wp_get_current_user()->exists() ? wp_get_current_user()->user_login : 'system or client';
            OFP_Logger::log( 'Plan changed', $client_id, [
                'from'   => self::normalize_plan( $before->plan ),
                'to'     => $unified,
                'by'     => $who,
                'caused' => implode( ' < ', $trace ),
            ] );
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
     * Persist pricing values.
     *
     * @param array $plan_prices
     * @return bool
     */
    public static function save_pricing( array $plan_prices ): bool {
        foreach ( self::PLAN_KEYS as $plan ) {
            $price = isset( $plan_prices[ $plan ] )
                ? max( 0.0, (float) $plan_prices[ $plan ] )
                : self::DEFAULT_PLAN_PRICES[ $plan ];

            update_option( "ofp_plan_price_{$plan}", $price );
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
    /**
     * Like in_renewal_window(), but a client with no expiry date is NOT in the
     * window. Used to decide whether a lower plan may replace a higher one.
     */
    public static function in_renewal_window_strict( int $client_id ): bool {
        $client = OFP_Client::get( $client_id );
        if ( ! $client || empty( $client->subscription_expires ) ) {
            return false;
        }
        $expiry = strtotime( $client->subscription_expires );
        if ( ! $expiry ) {
            return false;
        }
        return ( ( $expiry - time() ) / DAY_IN_SECONDS ) <= 7;
    }

    /**
     * True when paying for $plan would LOWER a higher plan that is still running
     * and the client is not yet in the last 7 days before it ends.
     */
    public static function would_lower_running_plan( int $client_id, ?string $plan ): bool {
        if ( ! $plan ) {
            return false;
        }
        $current = self::client_plan( $client_id );
        if ( $current === 'free' || self::is_unlimited( $client_id ) ) {
            return false;
        }
        if ( self::plan_rank( $plan ) >= self::plan_rank( $current ) ) {
            return false;
        }
        return ! self::in_renewal_window_strict( $client_id );
    }

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
        if ( self::is_plan_expired( OFP_Client::get( $client_id ) ) ) {
            return null;
        }
        // Only a row that has actually been PAID counts as active access.
        // 'pending' rows are created the moment a client merely SELECTS a
        // plan on the Properties page, before they've paid a cent, and they
        // never get a period_end (nothing to expire yet) — so treating
        // 'pending' as equivalent to 'paid' here meant selecting Gold alone,
        // with no payment ever completed, granted permanent Gold access
        // that the daily expiry cron could never touch (period_end IS NULL
        // matches forever). This was the root cause of plans that never
        // demoted back to Free after expiring.
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
               AND plan IN ('silver', 'gold')" . OFP_White_Label::not_solo_sql()
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
               AND (plan != 'free' OR listing_plan != 'free')" . OFP_White_Label::not_solo_sql()
        );

        foreach ( $expired_clients as $expired ) {
            $wpdb->update(
                $p . 'ofp_clients',
                [ 'plan' => 'free', 'listing_plan' => 'free' ],
                [ 'id' => $expired->id ]
            );

            // A paid row with no end date, or one that ends after the client's
            // expiry, must not keep the old plan alive. Close it today.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}ofp_subscriptions
                 SET period_end = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                 WHERE client_id = %d AND status = 'paid' AND ( period_end IS NULL OR period_end >= CURDATE() )",
                (int) $expired->id
            ) );

            if ( class_exists( 'OFP_Notification' ) ) {
                OFP_Notification::create(
                    (int) $expired->id,
                    'plan_expired_demoted',
                    'Your plan has expired',
                    'Your subscription expired and your plan has dropped to Free. You can still view your existing listings, but editing them, Team, and Sales & Installments are locked until you renew.'
                );
            }

            if ( class_exists( 'OFP_Logger' ) ) {
                OFP_Logger::log( 'Plan expired — dropped to Free', (int) $expired->id, [
                    'previous_plan' => $expired->plan,
                ] );
            }
        }

        // ── White label yearly / lifetime billing reminders and expiry ────────
        OFP_White_Label::run_daily_billing_check();

        // ── Clean expired session tokens ──────────────────────────────────────
        OFP_Auth::purge_expired_sessions();
    }

    /**
     * Daily FOMO/upgrade nudge for every active client currently on the
     * free plan — whether they signed up on free, or dropped back to it
     * after a paid plan lapsed. Only actually sends on 4 days a week
     * (Mon/Wed/Fri/Sun), so clients aren't emailed every single day —
     * the cron itself still runs daily, this just gates the send.
     */
    public static function send_free_plan_nudges(): void {
        global $wpdb;

        // 1=Mon ... 7=Sun. Four evenly-spread days a week.
        if ( ! in_array( (int) gmdate( 'N' ), [ 1, 3, 5, 7 ], true ) ) {
            return;
        }

        $free_clients = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}ofp_clients
             WHERE status = 'active' AND plan = 'free'" . OFP_White_Label::not_solo_sql()
        );

        foreach ( $free_clients as $client ) {
            OFP_Mailer::send_free_plan_nudge( $client );

            if ( class_exists( 'OFP_Notification' ) ) {
                OFP_Notification::create(
                    (int) $client->id,
                    'free_plan_nudge',
                    'Upgrade to unlock more',
                    'You\'re on the Free plan. Upgrade to Silver or Gold to list more properties, add team members, and use installment plans.'
                );
            }
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
        ?float $expected_amount = null,
        int $months = 1
    ): void {
        global $wpdb;

        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return;
        }

        $months = OFP_Property_CPT::normalize_months( $months );
        // The new period starts today, or when the running one ends (never lose paid days).
        [ $period_start, $period_end ] = self::compute_period( $client->subscription_expires ?? null, $months );

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
        $row = [
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
        ];
        if ( OFP_White_Label::schema_ready() ) {
            $row['months'] = $months;
        }
        $wpdb->insert( $wpdb->prefix . 'ofp_subscriptions', $row );

        // A payment for a LOWER plan must not lower a higher plan that is still
        // running. The money is recorded above. The plan and the end date are left
        // alone, and the admin is told so it can be settled by hand.
        if ( in_array( $type, [ 'listing', 'crm' ], true ) && self::would_lower_running_plan( $client_id, $plan ?: ( $client->plan ?? null ) ) ) {
            if ( class_exists( 'OFP_Logger' ) ) {
                OFP_Logger::log( 'Lower plan payment held', $client_id, [
                    'paid_plan'    => $plan,
                    'running_plan' => self::client_plan( $client_id ),
                    'amount'       => $amount,
                    'reference'    => $payment_ref,
                    'note'         => 'Payment recorded. Plan and end date unchanged. Settle by hand.',
                ] );
            }
            wp_mail(
                get_option( 'admin_email' ),
                'A payment for a lower plan needs your attention',
                sprintf(
                    "%s paid NGN %s for the %s plan, but they are still on %s which has not ended.\n\nThe payment (%s) is recorded. Their plan and end date were not changed.\nPlease refund it, or change their plan by hand.",
                    $client->business_name ?: $client->owner_name,
                    number_format( $amount, 2 ),
                    ucfirst( (string) $plan ),
                    ucfirst( self::client_plan( $client_id ) ),
                    $payment_ref
                )
            );
            return;
        }

        // Extend the client's subscription_expires date in ofp_clients.
        // The new end date was worked out above from the later of today and the
        // running end date, so a late payment still gets the full period.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}ofp_clients
                 SET status               = 'active',
                     subscription_expires = %s,
                     updated_at = NOW()
                 WHERE id = %d",
                $period_end,
                $client_id
            )
        );

        // Keep ofp_clients.plan / listing_plan on the unified key so feature
        // gates (templates, listing caps, installments) see one plan.
        $sync_plan = $plan ?: ( $client->plan ?? 'free' );
        self::sync_client_plan_columns( $client_id, $sync_plan );

        // Send payment confirmation email.
        OFP_Mailer::send_payment_confirmed( $client, $amount, $type, $sync_plan, $months, $period_end );

        // Clean up stale 'pending' rows for the same client+type so the
        // dashboard "Pay Now" banner no longer fires after a real payment.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ofp_subscriptions
             WHERE client_id = %d AND type = %s AND status = 'pending'",
            $client_id,
            $type
        ) );

        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( 'Payment successful', $client_id, [
                'amount'    => $amount,
                'type'      => $type,
                'plan'      => $plan,
                'months'    => $months,
                'valid_to'  => $period_end,
                'method'    => $method,
                'reference' => $payment_ref
            ] );
        }
    }

    /**
     * Start and end dates of a new paid period.
     * Starts today, or when the running period ends if that is later.
     * One month is 30 days (as before). 3, 6 and 12 months are calendar months.
     *
     * @return array{0:string,1:string} [ start, end ] as Y-m-d.
     */
    public static function compute_period( ?string $current_expiry, int $months ): array {
        $months = OFP_Property_CPT::normalize_months( $months );
        $today  = current_time( 'Y-m-d' );
        $start  = ( $current_expiry && $current_expiry > $today ) ? substr( $current_expiry, 0, 10 ) : $today;
        $end    = $months === 1
            ? gmdate( 'Y-m-d', strtotime( $start . ' +30 days' ) )
            : gmdate( 'Y-m-d', strtotime( $start . ' +' . $months . ' months' ) );
        return [ $start, $end ];
    }

    /**
     * Reads a funding request channel such as plan_silver or plan_gold_6.
     * @return array{plan:string,months:int}|null  null when it is not a plan payment.
     */
    public static function parse_plan_channel( string $channel ): ?array {
        if ( preg_match( '/^plan_(silver|gold)(?:_(\d+))?$/', $channel, $m ) ) {
            return [
                'plan'   => $m[1],
                'months' => OFP_Property_CPT::normalize_months( isset( $m[2] ) && $m[2] !== '' ? (int) $m[2] : 1 ),
            ];
        }
        return null;
    }

    /** A readable name for a funding request channel. */
    public static function channel_label( string $channel ): string {
        $plan = self::parse_plan_channel( $channel );
        if ( $plan ) {
            return ucfirst( $plan['plan'] ) . ' plan, ' . self::period_label( $plan['months'] );
        }
        return $channel === 'sms' ? 'SMS credit' : ( $channel === 'voice' ? 'Voice credit' : ucfirst( $channel ) );
    }

    /** Type and plan words for a payment row, in plain words, for every payment list. */
    public static function row_labels( object $sub ): array {
        if ( ( $sub->type ?? '' ) === 'whitelabel' ) {
            $plan = [ 'wl_yearly' => 'Yearly', 'wl_lifetime' => 'Lifetime', 'wl_maint' => 'Maintenance' ][ $sub->plan ?? '' ] ?? 'White label';
            return [ 'type' => 'WHITE LABEL', 'plan' => strtoupper( $plan ) ];
        }
        $plan   = strtoupper( (string) ( $sub->plan ?: '—' ) );
        $months = (int) ( $sub->months ?? 1 );
        if ( $months > 1 ) {
            $plan .= ' · ' . $months . ' MONTHS';
        }
        return [ 'type' => strtoupper( (string) $sub->type ), 'plan' => $plan ];
    }

    /** "1 month", "3 months" ... */
    public static function period_label( int $months ): string {
        $months = OFP_Property_CPT::normalize_months( $months );
        return $months === 1 ? '1 month' : $months . ' months';
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
        ?string $plan_override = null,
        int $months = 1
    ): void {
        global $wpdb;

        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            error_log( "[OFP_Subscription] record_underpayment: client {$client_id} not found." );
            return;
        }

        $plan = $plan_override ? self::normalize_plan( $plan_override ) : $client->plan;

        $under_row = [
            'client_id'       => $client_id,
            'type'            => 'listing',
            'plan'            => $plan,
            'amount'          => $amount_paid,
            'expected_amount' => $expected,
            'payment_method'  => $method,
            'payment_ref'     => sanitize_text_field( $payment_ref ),
            'status'          => 'underpaid',
            'created_at'      => current_time( 'mysql' ),
        ];
        if ( OFP_White_Label::schema_ready() ) {
            $under_row['months'] = OFP_Property_CPT::normalize_months( $months );
        }
        $wpdb->insert( $wpdb->prefix . 'ofp_subscriptions', $under_row );

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
            . '<p>Review this in wp-admin → ' . esc_html( OFP_Brand::name() ) . ' → Billing before it renews automatically.</p>'
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
            . esc_html( OFP_Brand::name() ) . ' → Billing.</p>'
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
     * Listing fee is configurable via wp-admin → Settings → Plans & Pricing.
     * Legacy CRM type returns 0 — CRM subscriptions are no longer created.
     *
     * @param  string      $type  'listing' (or legacy 'crm').
     * @param  string|null $plan  Plan tier.
     * @return float              Monthly fee in NGN.
     */
    public static function resolve_amount( string $type, ?string $plan ): float {
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

        if ( class_exists( 'OFP_Notification' ) ) {
            $when = $days_left <= 0 ? 'today' : ( $days_left === 1 ? 'tomorrow' : "in {$days_left} days" );
            OFP_Notification::create(
                (int) $client->id,
                'subscription_expiring',
                'Your plan expires ' . $when,
                'Renew before it expires to keep your current plan active — there is no grace period, it drops to Free immediately.'
            );
        }
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
        ?string $plan = null,
        int $months = 1
    ): void {
        global $wpdb;

        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return;
        }

        $months = OFP_Property_CPT::normalize_months( $months );

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

        // From the later of today and the running end date, so no paid days are lost.
        [ $period_start, $period_end ] = self::compute_period( $client->subscription_expires ?? null, $months );

        $paid_row = [
            'status'          => 'paid',
            'plan'            => $plan,
            'payment_method'  => 'manual',
            'amount'          => $amount_paid,
            'expected_amount' => null,
            'period_start'    => $period_start,
            'period_end'      => $period_end,
            'paid_at'         => current_time( 'mysql' ),
        ];
        if ( OFP_White_Label::schema_ready() ) {
            $paid_row['months'] = $months;
        }

        if ( $existing && $existing->status !== 'paid' ) {
            // A waiting (pending/underpaid) row becomes the paid one.
            $wpdb->update( $wpdb->prefix . 'ofp_subscriptions', $paid_row, [ 'id' => $existing->id ] );
        } else {
            // No row, or the last one is an older paid period: add a new row so payment history is kept.
            $wpdb->insert( $wpdb->prefix . 'ofp_subscriptions', $paid_row + [
                'client_id'  => $client_id,
                'type'       => $type,
                'created_at' => current_time( 'mysql' ),
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

        // A paid plan clears any other waiting "pay now" row, so the client is never asked to pay again.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ofp_subscriptions
             WHERE client_id = %d AND type = %s AND status = 'pending'",
            $client_id,
            $type
        ) );

        // Tell the client, the same as a gateway payment does.
        OFP_Mailer::send_payment_confirmed( $client, $amount_paid, 'listing', $plan, $months, $period_end );
        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( 'Payment successful', $client_id, [
                'amount'   => $amount_paid,
                'type'     => 'listing',
                'plan'     => $plan,
                'months'   => $months,
                'valid_to' => $period_end,
                'method'   => 'manual',
            ] );
        }
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
                 WHERE client_id = %d AND status = 'underpaid' AND type = 'listing'
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
    /**
     * One time cleanup. Earlier signups on the Free plan (and solo companies)
     * were given pending CRM and listing rows that showed as "Awaiting Payment".
     * Pending rows for the free tier are removed. A pending upgrade to a paid
     * plan is never touched.
     */
    public static function cleanup_free_pending_once(): void {
        $free_done    = get_option( 'ofp_free_pending_cleanup' ) === '1';
        $review_done  = get_option( 'ofp_pending_review_cleanup' ) === '1';
        $crm_done     = get_option( 'ofp_crm_pending_cleanup' ) === '1';
        $paid_done    = get_option( 'ofp_paid_pending_cleanup' ) === '1';

        if ( $free_done && $review_done && $crm_done && $paid_done ) {
            return;
        }
        global $wpdb;
        $p = $wpdb->prefix;

        // Self-serve signups no longer wait for review. Move any old ones that
        // are still waiting to active, on the Free plan they signed up with.
        if ( ! $review_done ) {
            $wpdb->query( "UPDATE {$p}ofp_clients SET status = 'active', updated_at = NOW() WHERE status = 'pending_review'" );
            update_option( 'ofp_pending_review_cleanup', '1', false );
        }

        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $p . 'ofp_subscriptions' ) ) ) ) {
            return;
        }

        if ( ! $free_done ) {
            $wpdb->query(
                "DELETE s FROM {$p}ofp_subscriptions s
                 INNER JOIN {$p}ofp_clients c ON c.id = s.client_id
                 WHERE s.status = 'pending'
                   AND s.payment_method = 'pending'
                   AND ( s.plan IS NULL OR s.plan IN ('', 'free', 'bronze') )
                   AND c.plan = 'free'"
            );
            update_option( 'ofp_free_pending_cleanup', '1', false );
        }

        // CRM is no longer a separate product (unified plan). Purge all
        // orphan CRM-type pending rows so they don't trigger "Pay Now".
        if ( ! $crm_done ) {
            $wpdb->query(
                "DELETE FROM {$p}ofp_subscriptions
                 WHERE type = 'crm' AND status IN ('pending','underpaid')
                   AND payment_method = 'pending'"
            );
            update_option( 'ofp_crm_pending_cleanup', '1', false );
        }

        // Clients who already paid still had orphan 'pending' listing rows
        // left behind by record_payment() (which inserted a new 'paid' row
        // without deleting the old 'pending' one). Remove them.
        if ( ! $paid_done ) {
            $wpdb->query(
                "DELETE pend FROM {$p}ofp_subscriptions pend
                 WHERE pend.type = 'listing' AND pend.status = 'pending'
                   AND EXISTS (
                       SELECT 1 FROM (
                           SELECT client_id FROM {$p}ofp_subscriptions
                           WHERE type = 'listing' AND status = 'paid'
                       ) paid_clients
                       WHERE paid_clients.client_id = pend.client_id
                   )"
            );
            update_option( 'ofp_paid_pending_cleanup', '1', false );
        }
    }

    public static function has_unpaid( int $client_id ): bool {
        global $wpdb;

        // Only listing rows matter — CRM is no longer a separate product.
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_subscriptions
                 WHERE client_id = %d AND status IN ('pending','underpaid') AND type = 'listing'
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
