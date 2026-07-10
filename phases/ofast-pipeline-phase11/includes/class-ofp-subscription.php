<?php
/**
 * Class OFP_Subscription
 *
 * Handles CRM + Listing subscription lifecycle: creation, active-status
 * checks, the daily expiry/grace/suspension sweep, renewal reminder
 * emails, and — as of Phase 11 — fully editable plan pricing.
 *
 * PHASE 11 CHANGE SUMMARY:
 * Plan prices, setup fees, and the listing fee used to live as a
 * hardcoded $plan_prices array duplicated across this class, the
 * signup template, the Monnify webhook, and the admin client form.
 * They now live in wp_options (managed via Settings > Plans & Pricing)
 * and are read through the getters below. Every other file that needs
 * a price should call into THIS class rather than hardcoding a number
 * — that includes all three payment gateway adapters
 * (class-ofp-gateway-monnify.php, -paystack.php, -flutterwave.php),
 * which should call get_expected_monthly_total() when matching an
 * incoming webhook payment amount.
 *
 * No database schema change is required for this phase — wp_options
 * is a plain key/value store, so there is nothing to add to
 * maybe_upgrade_schema() and no deactivate/reactivate is needed.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Subscription {

	/**
	 * Canonical list of CRM plan keys. If a 4th plan is ever added,
	 * add its key here and to the two DEFAULT_* arrays below —
	 * the Settings UI, signup.php, and resolve_amount() all iterate
	 * this list dynamically, so nothing else needs to change.
	 */
	const PLAN_KEYS = [ 'starter', 'growth', 'pro' ];

	/**
	 * Fallback values. These are ONLY ever used the very first time
	 * a given option is read on a fresh install, before an admin has
	 * saved the Plans & Pricing form even once. Once
	 * OFP_Subscription::save_pricing() has run, wp_options is the
	 * sole source of truth for that key and these are not consulted
	 * again for it.
	 */
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

	/* -----------------------------------------------------------
	 * PRICING (Phase 11)
	 * --------------------------------------------------------- */

	/**
	 * Returns all three CRM monthly plan prices.
	 *
	 * @return array ['starter' => float, 'growth' => float, 'pro' => float]
	 */
	public static function get_plan_prices(): array {
		$prices = [];
		foreach ( self::PLAN_KEYS as $plan ) {
			$prices[ $plan ] = (float) get_option(
				"ofp_plan_price_{$plan}",
				self::DEFAULT_PLAN_PRICES[ $plan ]
			);
		}
		return $prices;
	}

	/**
	 * Returns all three one-time setup fees, same shape as get_plan_prices().
	 *
	 * @return array
	 */
	public static function get_setup_fees(): array {
		$fees = [];
		foreach ( self::PLAN_KEYS as $plan ) {
			$fees[ $plan ] = (float) get_option(
				"ofp_plan_setup_fee_{$plan}",
				self::DEFAULT_SETUP_FEES[ $plan ]
			);
		}
		return $fees;
	}

	/**
	 * Single-plan monthly price lookup. Returns 0.0 for a null or
	 * unrecognised plan key rather than throwing — callers (payment
	 * webhooks especially) should degrade safely rather than fatal
	 * on bad or legacy data.
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
	 * Single-plan setup fee lookup. Same safety behaviour as get_plan_price().
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
	 * The monthly per-property listing fee (v2.1 amendment, Section A4.1).
	 * This option already existed pre-Phase 11 — surfaced here as a
	 * getter purely so callers have one consistent entry point for
	 * every price in the system.
	 *
	 * @return float
	 */
	public static function get_listing_fee(): float {
		return (float) get_option( 'ofp_listing_fee_monthly', self::DEFAULT_LISTING_FEE );
	}

	/**
	 * Persists the full pricing set in one call. This is the ONLY
	 * method that should ever call update_option() on pricing keys —
	 * called exclusively from the Settings > Plans & Pricing form
	 * handler (admin/class-ofp-admin-menu.php::handle_save_plan_pricing()).
	 *
	 * Validates and clamps every value server-side (non-negative
	 * floats) rather than trusting the caller has already sanitised —
	 * this method is the last line of defence before these numbers
	 * start driving real invoicing.
	 *
	 * @param array $plan_prices ['starter' => float, 'growth' => float, 'pro' => float]
	 * @param array $setup_fees  same shape
	 * @param float $listing_fee
	 * @return true
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

	/**
	 * Sum of whatever subscription types are currently active for a
	 * client (crm and/or listing). This is the single method all
	 * three payment gateway adapters should call when matching an
	 * incoming webhook payment amount against what's actually owed —
	 * centralising it here means a pricing change in Settings applies
	 * to amount-matching everywhere with zero gateway code changes.
	 *
	 * NOTE: this does not yet resolve the underpayment-handling open
	 * decision (continuation blueprint Section 4, item 1) — it only
	 * answers "what should the client be paying", not "what to do
	 * if they pay less than that".
	 *
	 * @param int $client_id
	 * @return float
	 */
	public static function get_expected_monthly_total( int $client_id ): float {
		$client = OFP_Client::get( $client_id );
		if ( ! $client ) return 0.0;

		$total = 0.0;

		if ( self::has_active( 'crm', $client_id ) ) {
			$total += self::get_plan_price( $client->plan );
		}
		if ( self::has_active( 'listing', $client_id ) ) {
			$total += self::get_listing_fee();
		}

		return $total;
	}

	/* -----------------------------------------------------------
	 * v2.1 amendment methods (subscription types) — unchanged
	 * behaviour, resolve_amount() now delegates to the pricing
	 * getters above instead of a hardcoded array.
	 * --------------------------------------------------------- */

	/**
	 * Creates a new subscription row for a client (type 'crm' or 'listing').
	 * Also seeds a default ofp_pipeline_configs row the first time a
	 * client gets a 'crm' subscription — listing-only clients never
	 * get one, since they run no SMS/voice follow-up sequence.
	 *
	 * @param int         $client_id
	 * @param string      $type 'crm'|'listing'
	 * @param string|null $plan only meaningful when $type === 'crm'
	 * @return int the new subscription row's ID
	 */
	public static function create( int $client_id, string $type, ?string $plan = null ): int {
		global $wpdb;

		$amount = self::resolve_amount( $type, $plan );

		$wpdb->insert( $wpdb->prefix . 'ofp_subscriptions', [
			'client_id'      => $client_id,
			'type'           => $type,
			'plan'           => $plan,
			'amount'         => $amount,
			'payment_method' => 'pending',
			'status'         => 'pending',
			'created_at'     => current_time( 'mysql' ),
		] );

		$subscription_id = $wpdb->insert_id;

		if ( $type === 'crm' ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}ofp_pipeline_configs WHERE client_id = %d",
				$client_id
			) );
			if ( ! $exists ) {
				$wpdb->insert( $wpdb->prefix . 'ofp_pipeline_configs', [ 'client_id' => $client_id ] );
			}
		}

		return $subscription_id;
	}

	/**
	 * Whether a client currently has a paid, non-expired subscription
	 * of the given type.
	 *
	 * @param string $type 'crm'|'listing'
	 * @param int    $client_id
	 * @return bool
	 */
	public static function has_active( string $type, int $client_id ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "
			SELECT * FROM {$wpdb->prefix}ofp_subscriptions
			WHERE client_id = %d AND type = %s AND status = 'paid'
			AND (period_end IS NULL OR period_end >= CURDATE())
			ORDER BY period_end DESC LIMIT 1
		", $client_id, $type ) );
		return (bool) $row;
	}

	/**
	 * Resolves the amount owed for a given subscription type/plan
	 * combination. Phase 11: reads live from the pricing getters
	 * above instead of a hardcoded array — the ONLY behavioural
	 * change in this method versus the original v2.1 amendment code.
	 *
	 * @param string      $type
	 * @param string|null $plan
	 * @return float
	 */
	private static function resolve_amount( string $type, ?string $plan ): float {
		if ( $type === 'crm' )     return self::get_plan_price( $plan );
		if ( $type === 'listing' ) return self::get_listing_fee();
		return 0.0;
	}

	/* -----------------------------------------------------------
	 * v2.0 original methods — unchanged
	 * --------------------------------------------------------- */

	/**
	 * Daily cron sweep: sends 7-day and 3-day renewal reminders, moves
	 * expired clients into grace, moves grace clients past 5 days into
	 * suspended, and moves suspended clients past 35 days into cancelled.
	 */
	public static function run_daily_check(): void {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$clients = $wpdb->get_results( "
			SELECT * FROM {$prefix}ofp_clients
			WHERE subscription_expires = DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status = 'active'
		" );
		foreach ( $clients as $client ) self::send_reminder( $client, 7 );

		$clients = $wpdb->get_results( "
			SELECT * FROM {$prefix}ofp_clients
			WHERE subscription_expires = DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND status = 'active'
		" );
		foreach ( $clients as $client ) self::send_reminder( $client, 3 );

		$wpdb->query( "
			UPDATE {$prefix}ofp_clients SET status = 'grace'
			WHERE subscription_expires < CURDATE() AND status = 'active'
		" );

		$wpdb->query( "
			UPDATE {$prefix}ofp_clients SET status = 'suspended'
			WHERE status = 'grace' AND subscription_expires < DATE_SUB(CURDATE(), INTERVAL 5 DAY)
		" );

		$wpdb->query( "
			UPDATE {$prefix}ofp_clients SET status = 'cancelled'
			WHERE status = 'suspended' AND subscription_expires < DATE_SUB(CURDATE(), INTERVAL 35 DAY)
		" );
	}

	/**
	 * Sends a single renewal reminder email.
	 *
	 * @param object $client
	 * @param int    $days_left
	 */
	private static function send_reminder( object $client, int $days_left ): void {
		OFP_Mailer::send(
			$client->email,
			$client->owner_name,
			"Your OFast Pipeline subscription expires in {$days_left} days",
			"Hi {$client->owner_name}, your subscription expires in {$days_left} days.
			 Please renew via your virtual account: {$client->virtual_bank_name} -- {$client->virtual_account_number}"
		);
	}

	/**
	 * Manual admin override of a client's subscription status. If
	 * setting to 'active', also pushes subscription_expires 30 days out.
	 *
	 * @param int    $client_id
	 * @param string $status
	 */
	public static function manual_toggle( int $client_id, string $status ): void {
		OFP_Client::update_status( $client_id, $status );
		if ( $status === 'active' ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'ofp_clients', [
				'subscription_expires' => date( 'Y-m-d', strtotime( '+30 days' ) ),
			], [ 'id' => $client_id ] );
		}
	}
}
