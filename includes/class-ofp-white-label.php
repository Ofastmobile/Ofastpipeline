<?php
/**
 * Class OFP_White_Label
 *
 * Blueprint v2 Section 2.4 and 2.5.
 *
 * A "white label" (solo company) client has their own domain and their own
 * private property pages. This class does three jobs in one place:
 *
 *  1. Makes sure the clients table has the custom_domain and is_white_label
 *     columns, even on sites that were activated before they existed.
 *  2. Scopes every public property query (marketplace, homepage, similar
 *     properties, agent page, REST API):
 *       - on a white label client's own domain: ONLY that client's properties.
 *       - everywhere else: white label clients' properties are hidden.
 *  3. Keeps every link on a white label client's domain on that same domain,
 *     so visitors are never sent back to the main site.
 *
 * Client dashboard queries are untouched (they pass ofp_include_occupied).
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_White_Label {

	const SCHEMA_OPTION  = 'ofp_white_label_schema';
	const SCHEMA_VERSION = '4';

	public static function init(): void {
		self::ensure_schema();
		add_filter( 'posts_clauses', [ __CLASS__, 'scope_property_queries' ], 11, 2 );
		add_filter( 'home_url', [ __CLASS__, 'keep_links_on_white_label_host' ], 20, 2 );
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	/**
	 * Adds custom_domain, is_white_label and the branding columns to ofp_clients when missing.
	 * Runs once, then remembers it in an option, so no reactivation is needed.
	 */
	public static function ensure_schema(): void {
		if ( self::schema_ready() ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ofp_clients';

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return; // Plugin tables not created yet.
		}

		$columns = [
			'custom_domain'  => 'VARCHAR(150) DEFAULT NULL AFTER subdomain',
			'is_white_label' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER custom_domain',
			'brand_color'    => 'VARCHAR(20) DEFAULT NULL AFTER is_white_label',
			'favicon_url'    => 'VARCHAR(255) DEFAULT NULL AFTER brand_color',
			'support_email'  => 'VARCHAR(150) DEFAULT NULL AFTER favicon_url',
			'support_phone'  => 'VARCHAR(30) DEFAULT NULL AFTER support_email',
			'sidebar_color'  => 'VARCHAR(20) DEFAULT NULL AFTER support_phone',
			'menu_hover_color' => 'VARCHAR(20) DEFAULT NULL AFTER sidebar_color',
			// v4: white-label billing set by the admin. NULL = old client, stays unlimited.
			'wl_billing'      => 'VARCHAR(10) DEFAULT NULL AFTER menu_hover_color',
			'wl_paid_at'      => 'DATETIME DEFAULT NULL AFTER wl_billing',
			'maintenance_due' => 'DATE DEFAULT NULL AFTER wl_paid_at',
		];

		$all = true;
		foreach ( $columns as $name => $definition ) {
			if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE '{$name}'" ) ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$definition}" );
			}
			if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE '{$name}'" ) ) ) {
				$all = false;
				update_option( 'ofp_white_label_schema_error', $name . ': ' . $wpdb->last_error, false );
			}
		}

		// v4: how many months a subscription payment covers.
		$subs = $wpdb->prefix . 'ofp_subscriptions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $subs ) ) ) ) {
			if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$subs} LIKE 'months'" ) ) ) {
				$wpdb->query( "ALTER TABLE {$subs} ADD COLUMN months TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER plan" );
			}
			if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$subs} LIKE 'months'" ) ) ) {
				$all = false;
				update_option( 'ofp_white_label_schema_error', 'months: ' . $wpdb->last_error, false );
			}
		}

		if ( $all ) {
			delete_option( 'ofp_white_label_schema_error' );
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
		}
	}

	/**
	 * SQL to leave solo companies out of billing jobs (renewal reminders, plan
	 * expiry, upgrade nudges). Empty until the column exists.
	 */
	public static function not_solo_sql( string $alias = '' ): string {
		if ( ! self::schema_ready() ) {
			return '';
		}
		return ' AND ' . ( $alias !== '' ? $alias . '.' : '' ) . 'is_white_label = 0';
	}

	public static function schema_ready(): bool {
		return get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION;
	}

	// -------------------------------------------------------------------------
	// Billing (yearly or lifetime), set by the admin and paid by hand
	// -------------------------------------------------------------------------

	const BILLING_TYPES = [ 'yearly', 'lifetime' ];

	/** '' (old client, no billing set: stays unlimited), 'yearly' or 'lifetime'. */
	public static function billing_type( ?object $c ): string {
		if ( ! $c || empty( $c->is_white_label ) ) {
			return '';
		}
		$t = (string) ( $c->wl_billing ?? '' );
		return in_array( $t, self::BILLING_TYPES, true ) ? $t : '';
	}

	/** A yearly client whose paid year has run out (or was never paid). */
	public static function yearly_expired( ?object $c ): bool {
		if ( self::billing_type( $c ) !== 'yearly' ) {
			return false;
		}
		if ( empty( $c->subscription_expires ) ) {
			return true;
		}
		return strtotime( (string) $c->subscription_expires ) < strtotime( current_time( 'Y-m-d' ) );
	}

	/** True when the client gets the full white label benefits (no limits). */
	public static function has_full_benefits( ?object $c ): bool {
		if ( ! $c || empty( $c->is_white_label ) ) {
			return false;
		}
		return ! self::yearly_expired( $c );
	}

	/** Days until a date (negative when it has passed). */
	public static function days_until( ?string $date ): ?int {
		if ( ! $date ) {
			return null;
		}
		$d = strtotime( substr( $date, 0, 10 ) );
		if ( ! $d ) {
			return null;
		}
		return (int) round( ( $d - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
	}

	/** Starting credit for each of SMS and voice. Admin can change it with the options. */
	public static function starting_credit( string $type ): float {
		$default = $type === 'lifetime' ? 100000 : 50000;
		$v = (float) get_option( 'ofp_wl_credit_' . $type, $default );
		return $v > 0 ? $v : (float) $default;
	}

	/**
	 * What the client sees about their billing, in plain words.
	 *
	 * @return array{type:string,title:string,badge:string,valid:string,note:string,tone:string,due:?string,expires:?string}
	 */
	public static function summary( ?object $c ): array {
		$out = [ 'type' => '', 'title' => 'PREMIUM', 'badge' => 'Lifetime Access', 'valid' => 'Unlimited', 'note' => 'Never Expires', 'tone' => 'ok', 'due' => null, 'expires' => null ];
		$type = self::billing_type( $c );
		if ( $type === 'yearly' ) {
			$days = self::days_until( $c->subscription_expires ?? null );
			$out['type']    = 'yearly';
			$out['expires'] = $c->subscription_expires ?? null;
			$out['title']   = 'WHITE LABEL';
			$out['valid']   = ! empty( $c->subscription_expires ) ? gmdate( 'M j, Y', strtotime( $c->subscription_expires ) ) : '—';
			if ( self::yearly_expired( $c ) ) {
				$out['badge'] = 'Yearly plan expired';
				$out['note']  = 'Renewal due. Dashboard is on Free limits';
				$out['tone']  = 'bad';
			} else {
				$out['badge'] = 'Yearly plan';
				$out['note']  = $days !== null && $days >= 0 ? $days . ' days remaining' : 'Active';
				$out['tone']  = ( $days !== null && $days <= 7 ) ? 'warn' : 'ok';
			}
		} elseif ( $type === 'lifetime' ) {
			$out['type']  = 'lifetime';
			$out['title'] = 'WHITE LABEL';
			$out['badge'] = 'Lifetime Access';
			$out['valid'] = 'Lifetime';
			$out['due']   = $c->maintenance_due ?? null;
			$days         = self::days_until( $out['due'] );
			if ( $days === null ) {
				$out['note'] = 'Never Expires';
			} elseif ( $days < 0 ) {
				$out['note'] = 'Maintenance fee overdue since ' . gmdate( 'M j, Y', strtotime( $out['due'] ) );
				$out['tone'] = 'bad';
			} else {
				$out['note'] = 'Maintenance fee due ' . gmdate( 'M j, Y', strtotime( $out['due'] ) );
				$out['tone'] = $days <= 7 ? 'warn' : 'ok';
			}
		}
		return $out;
	}

	/**
	 * Admin marks a yearly or lifetime payment as received.
	 * Sets the type and dates, writes the payment row, adds the starting credits
	 * (when asked), tells the client by email, SMS and bell, and writes the log.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function mark_paid( int $client_id, string $type, float $amount, bool $give_credits, string $note = '' ): array {
		global $wpdb;

		self::ensure_schema();
		$client = OFP_Client::get( $client_id );
		if ( ! self::schema_ready() ) {
			return [ 'ok' => false, 'message' => 'The database is not ready for white label billing yet.' ];
		}
		if ( ! $client ) {
			return [ 'ok' => false, 'message' => 'Client not found.' ];
		}
		if ( empty( $client->is_white_label ) ) {
			OFP_Client::update( $client_id, [ 'is_white_label' => 1 ] );
			$client->is_white_label = 1;
		}
		if ( ! in_array( $type, self::BILLING_TYPES, true ) ) {
			return [ 'ok' => false, 'message' => 'Choose Yearly or Lifetime.' ];
		}
		if ( $amount <= 0 ) {
			return [ 'ok' => false, 'message' => 'Enter the amount the client paid.' ];
		}

		$today     = current_time( 'Y-m-d' );
		$was       = self::billing_type( $client );
		$update    = [ 'wl_billing' => $type, 'status' => 'active' ];
		$row_start = $today;
		$row_end   = null;
		$valid_to  = null;
		$due       = null;

		if ( $type === 'yearly' ) {
			// Renewing early keeps the days left: the new year starts when the old one ends.
			$base = ( $was === 'yearly' && ! empty( $client->subscription_expires ) && $client->subscription_expires > $today )
				? substr( $client->subscription_expires, 0, 10 ) : $today;
			$row_start = $base;
			$row_end   = gmdate( 'Y-m-d', strtotime( $base . ' +365 days' ) );
			$valid_to  = $row_end;
			$update['subscription_expires'] = $row_end;
			$update['maintenance_due']      = null;
		} else {
			$update['subscription_expires'] = null;
			// Maintenance starts in the 2nd year. Keep the date if they were already lifetime.
			if ( $was === 'lifetime' && ! empty( $client->maintenance_due ) ) {
				$due = substr( $client->maintenance_due, 0, 10 );
			} else {
				$due = gmdate( 'Y-m-d', strtotime( $today . ' +365 days' ) );
			}
			$update['maintenance_due'] = $due;
		}
		if ( empty( $client->wl_paid_at ) || $was !== $type ) {
			$update['wl_paid_at'] = current_time( 'mysql' );
		}
		OFP_Client::update( $client_id, $update );

		$ref = 'manual_wl_' . $type . '_' . current_time( 'timestamp' );
		$wpdb->insert( $wpdb->prefix . 'ofp_subscriptions', [
			'client_id'      => $client_id,
			'type'           => 'whitelabel',
			'plan'           => 'wl_' . $type,
			'months'         => $type === 'yearly' ? 12 : 0,
			'amount'         => $amount,
			'payment_method' => 'manual',
			'payment_ref'    => $ref,
			'status'         => 'paid',
			'period_start'   => $row_start,
			'period_end'     => $row_end,
			'paid_at'        => current_time( 'mysql' ),
			'created_at'     => current_time( 'mysql' ),
		] );

		$credit_each = 0.0;
		if ( $give_credits && class_exists( 'OFP_Credit' ) ) {
			$credit_each = self::starting_credit( $type );
			OFP_Credit::topup( $client_id, 'sms', $credit_each, $ref );
			OFP_Credit::topup( $client_id, 'voice', $credit_each, $ref );
		}

		$label = $type === 'yearly' ? 'Yearly' : 'Lifetime';
		$lines = [ 'We have received your payment of NGN ' . number_format( $amount, 2 ) . ' for your ' . $label . ' white label plan.' ];
		if ( $type === 'yearly' ) {
			$lines[] = 'Your plan is active until ' . gmdate( 'F j, Y', strtotime( $valid_to ) ) . '.';
		} else {
			$lines[] = 'Your lifetime access has no end date. The yearly maintenance fee starts in the 2nd year, from ' . gmdate( 'F j, Y', strtotime( $due ) ) . '.';
		}
		if ( $credit_each > 0 ) {
			$lines[] = 'Starting credit added: NGN ' . number_format( $credit_each, 0 ) . ' SMS and NGN ' . number_format( $credit_each, 0 ) . ' voice.';
		}
		self::tell_client( $client, 'wl_payment_received', 'Payment received: ' . $label . ' plan', $lines, 'Payment received. ' . $label . ' white label plan'
			. ( $type === 'yearly' ? ' is active until ' . gmdate( 'M j, Y', strtotime( $valid_to ) ) . '.' : ' is active for life. Maintenance starts ' . gmdate( 'M j, Y', strtotime( $due ) ) . '.' )
			. ( $credit_each > 0 ? ' Credit added: NGN ' . number_format( $credit_each, 0 ) . ' SMS and voice.' : '' ) );

		if ( class_exists( 'OFP_Logger' ) ) {
			OFP_Logger::log( 'White label payment recorded', $client_id, [
				'billing'  => $type,
				'amount'   => $amount,
				'valid_to' => $valid_to,
				'maintenance_due' => $due,
				'credits_each'    => $credit_each,
				'method'   => 'manual',
				'note'     => $note,
			] );
		}

		return [ 'ok' => true, 'message' => $label . ' payment recorded.' . ( $valid_to ? ' Active until ' . $valid_to . '.' : '' ) ];
	}

	/** Admin marks a lifetime client's yearly maintenance fee as paid. */
	public static function mark_maintenance_paid( int $client_id, float $amount, string $note = '' ): array {
		global $wpdb;

		$client = OFP_Client::get( $client_id );
		if ( ! self::schema_ready() || self::billing_type( $client ) !== 'lifetime' ) {
			return [ 'ok' => false, 'message' => 'Maintenance applies to lifetime white label clients only.' ];
		}
		if ( $amount <= 0 ) {
			return [ 'ok' => false, 'message' => 'Enter the maintenance amount the client paid.' ];
		}

		$today = current_time( 'Y-m-d' );
		$old   = ! empty( $client->maintenance_due ) ? substr( $client->maintenance_due, 0, 10 ) : $today;
		// Paid on time keeps the yearly date. Paid late starts a year from today.
		$base  = $old > $today ? $old : $today;
		$new   = gmdate( 'Y-m-d', strtotime( $base . ' +365 days' ) );

		OFP_Client::update( $client_id, [ 'maintenance_due' => $new ] );

		$ref = 'manual_wl_maint_' . current_time( 'timestamp' );
		$wpdb->insert( $wpdb->prefix . 'ofp_subscriptions', [
			'client_id'      => $client_id,
			'type'           => 'whitelabel',
			'plan'           => 'wl_maint',
			'months'         => 12,
			'amount'         => $amount,
			'payment_method' => 'manual',
			'payment_ref'    => $ref,
			'status'         => 'paid',
			'period_start'   => $old,
			'period_end'     => $new,
			'paid_at'        => current_time( 'mysql' ),
			'created_at'     => current_time( 'mysql' ),
		] );

		$when = gmdate( 'F j, Y', strtotime( $new ) );
		self::tell_client( $client, 'wl_maintenance_paid', 'Maintenance fee received', [
			'We have received your maintenance fee of NGN ' . number_format( $amount, 2 ) . '.',
			'Your next maintenance fee is due on ' . $when . '.',
		], 'Maintenance fee received. Next one is due ' . gmdate( 'M j, Y', strtotime( $new ) ) . '.' );

		if ( class_exists( 'OFP_Logger' ) ) {
			OFP_Logger::log( 'White label maintenance paid', $client_id, [ 'amount' => $amount, 'next_due' => $new, 'method' => 'manual', 'note' => $note ] );
		}
		return [ 'ok' => true, 'message' => 'Maintenance recorded. Next due ' . $new . '.' ];
	}

	/** Back to the old setup: unlimited, no billing, nothing to track. */
	public static function clear_billing( int $client_id ): array {
		if ( ! self::schema_ready() ) {
			return [ 'ok' => false, 'message' => 'The database is not ready yet.' ];
		}
		OFP_Client::update( $client_id, [ 'wl_billing' => null, 'maintenance_due' => null, 'subscription_expires' => null ] );
		if ( class_exists( 'OFP_Logger' ) ) {
			OFP_Logger::log( 'White label billing cleared', $client_id, [ 'note' => 'Back to unlimited with no billing.' ] );
		}
		return [ 'ok' => true, 'message' => 'Billing removed. This client is unlimited again.' ];
	}

	/**
	 * Email + SMS + bell message to a white label client.
	 * The SMS is paid by the platform (admin), not from the client's credit.
	 */
	public static function tell_client( object $client, string $kind, string $title, array $lines, string $sms_text ): void {
		if ( class_exists( 'OFP_Notification' ) ) {
			OFP_Notification::create( (int) $client->id, $kind, $title, implode( ' ', $lines ) );
		}
		if ( class_exists( 'OFP_Mailer' ) ) {
			OFP_Mailer::send_wl_billing_notice( $client, $title, $lines, in_array( $kind, [ 'wl_expired', 'wl_maintenance_overdue' ], true ) );
		}
		$phone = trim( (string) ( $client->phone ?? '' ) );
		if ( $phone !== '' && class_exists( 'OFP_SMS' ) ) {
			try {
				OFP_SMS::send_system_sms( $phone, $sms_text );
			} catch ( \Throwable $e ) {
				error_log( '[OFP_White_Label] SMS failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Daily job for yearly and lifetime clients. Same reminder rhythm as other
	 * plans: every day in the last 7 days before the date.
	 */
	public static function run_daily_billing_check(): void {
		if ( ! self::schema_ready() ) {
			return;
		}
		global $wpdb;
		$p = $wpdb->prefix;

		// Yearly: reminders, every day from 7 days out up to the last day.
		$due_soon = $wpdb->get_results(
			"SELECT *, DATEDIFF( subscription_expires, CURDATE() ) AS days_left FROM {$p}ofp_clients
			 WHERE is_white_label = 1 AND wl_billing = 'yearly' AND status = 'active'
			   AND subscription_expires BETWEEN CURDATE() AND DATE_ADD( CURDATE(), INTERVAL 7 DAY )"
		);
		foreach ( $due_soon as $c ) {
			$d    = (int) $c->days_left;
			$when = $d <= 0 ? 'today' : ( $d === 1 ? 'tomorrow' : "in {$d} days" );
			self::tell_client( $c, 'wl_expiring', 'Your yearly plan ends ' . $when, [
				'Your yearly white label plan ends on ' . gmdate( 'F j, Y', strtotime( $c->subscription_expires ) ) . '.',
				'Please contact us to renew. After it ends, your dashboard goes back to Free limits. Your website and properties keep working.',
			], 'Your yearly plan ends ' . $when . ' (' . gmdate( 'M j', strtotime( $c->subscription_expires ) ) . '). Contact us to renew. After that your dashboard goes to Free limits. Your site stays online.' );
		}

		// Yearly: it ended yesterday. Tell them once. The site and domain stay on.
		$ended = $wpdb->get_results(
			"SELECT * FROM {$p}ofp_clients
			 WHERE is_white_label = 1 AND wl_billing = 'yearly' AND status = 'active'
			   AND subscription_expires = DATE_SUB( CURDATE(), INTERVAL 1 DAY )"
		);
		foreach ( $ended as $c ) {
			self::tell_client( $c, 'wl_expired', 'Your yearly plan has ended', [
				'Your yearly white label plan has ended, so your dashboard is now on Free limits.',
				'Your website, domain and properties are still online. Contact us to renew and get your full benefits back.',
			], 'Your yearly white label plan has ended. Your dashboard is on Free limits. Your site stays online. Contact us to renew.' );
			if ( class_exists( 'OFP_Logger' ) ) {
				OFP_Logger::log( 'White label plan expired. Back to Free limits', (int) $c->id, [ 'ended' => $c->subscription_expires ] );
			}
			wp_mail( get_option( 'admin_email' ), 'White label plan ended: ' . $c->business_name, $c->business_name . ' did not renew their yearly white label plan. Their dashboard is on Free limits. The website and domain still work.' );
		}

		// Lifetime: maintenance fee reminders in the last 7 days.
		$maint_soon = $wpdb->get_results(
			"SELECT *, DATEDIFF( maintenance_due, CURDATE() ) AS days_left FROM {$p}ofp_clients
			 WHERE is_white_label = 1 AND wl_billing = 'lifetime' AND status = 'active'
			   AND maintenance_due BETWEEN CURDATE() AND DATE_ADD( CURDATE(), INTERVAL 7 DAY )"
		);
		foreach ( $maint_soon as $c ) {
			$d    = (int) $c->days_left;
			$when = $d <= 0 ? 'today' : ( $d === 1 ? 'tomorrow' : "in {$d} days" );
			self::tell_client( $c, 'wl_maintenance_due', 'Maintenance fee due ' . $when, [
				'Your yearly maintenance fee is due on ' . gmdate( 'F j, Y', strtotime( $c->maintenance_due ) ) . '.',
				'Please contact us to pay it. Your lifetime access stays active.',
			], 'Your yearly maintenance fee is due ' . $when . ' (' . gmdate( 'M j', strtotime( $c->maintenance_due ) ) . '). Please contact us to pay.' );
		}

		// Lifetime: the fee became overdue yesterday. Tell the client and the admin once.
		$overdue = $wpdb->get_results(
			"SELECT * FROM {$p}ofp_clients
			 WHERE is_white_label = 1 AND wl_billing = 'lifetime' AND status = 'active'
			   AND maintenance_due = DATE_SUB( CURDATE(), INTERVAL 1 DAY )"
		);
		foreach ( $overdue as $c ) {
			self::tell_client( $c, 'wl_maintenance_overdue', 'Maintenance fee overdue', [
				'Your yearly maintenance fee was due on ' . gmdate( 'F j, Y', strtotime( $c->maintenance_due ) ) . ' and has not been paid.',
				'Please contact us to pay. Your lifetime access has not been switched off.',
			], 'Your yearly maintenance fee is overdue (was due ' . gmdate( 'M j', strtotime( $c->maintenance_due ) ) . '). Please contact us to pay.' );
			wp_mail( get_option( 'admin_email' ), 'Maintenance fee overdue: ' . $c->business_name, $c->business_name . ' has not paid the yearly maintenance fee that was due on ' . $c->maintenance_due . '.' );
		}
	}

	// -------------------------------------------------------------------------
	// Who is the visitor? (white label client for this host, if any)
	// -------------------------------------------------------------------------

	/**
	 * The white label client that owns the current host, or null.
	 * Null on the main site, on app/property hosts, on normal client
	 * subdomains, in wp-admin and in cron.
	 */
	public static function current_client(): ?object {
		static $memo = false;
		if ( $memo !== false ) {
			return $memo;
		}
		$memo = null;

		if ( is_admin() || ! self::schema_ready() || ! class_exists( 'OFP_Host_Router' ) ) {
			return $memo;
		}

		$client = OFP_Host_Router::current_client_record();
		if ( $client && ! empty( $client->is_white_label ) ) {
			$memo = $client;
		}
		return $memo;
	}

	// -------------------------------------------------------------------------
	// Central property filter (Blueprint 2.5)
	// -------------------------------------------------------------------------

	public static function scope_property_queries( array $clauses, WP_Query $query ): array {
		if ( is_admin() || wp_doing_cron() || $query->get( 'ofp_include_occupied' ) || $query->get( 'ofp_all_clients' ) ) {
			return $clauses;
		}
		if ( ! self::schema_ready() ) {
			return $clauses;
		}

		// A "pure" property query asks for ofp_property and nothing else.
		// A "mixed" query can also return properties without naming the type:
		// the default site search (empty post_type + ?s=), post_type=any, or an
		// array such as ['post','ofp_property']. Those must be scoped too, or a
		// white label listing shows up in the main site's search results.
		$types = array_values( array_filter( (array) $query->get( 'post_type' ) ) );
		$pure  = ( $types === [ 'ofp_property' ] );
		$mixed = ! $pure && (
			in_array( 'ofp_property', $types, true )
			|| in_array( 'any', $types, true )
			|| ( empty( $types ) && $query->is_search() )
		);
		if ( ! $pure && ! $mixed ) {
			return $clauses;
		}

		global $wpdb;
		$properties = $wpdb->prefix . 'ofp_properties';
		$clients    = $wpdb->prefix . 'ofp_clients';
		$posts      = $wpdb->posts;

		$white_label = self::current_client();

		if ( $white_label ) {
			// On their own domain: only their own properties.
			// In a mixed query other post types (pages, posts) stay visible.
			$exists = $wpdb->prepare(
				"EXISTS (
					SELECT 1 FROM {$properties} ofp_wl_p
					WHERE ofp_wl_p.wp_post_id = {$posts}.ID
					  AND ofp_wl_p.client_id = %d
				)",
				(int) $white_label->id
			);
			$clauses['where'] .= $mixed
				? " AND ( {$posts}.post_type <> 'ofp_property' OR {$exists} )"
				: " AND {$exists}";
		} else {
			// Everywhere else: white label properties never appear.
			$clauses['where'] .= " AND NOT EXISTS (
				SELECT 1 FROM {$properties} ofp_wl_p
				INNER JOIN {$clients} ofp_wl_c ON ofp_wl_c.id = ofp_wl_p.client_id
				WHERE ofp_wl_p.wp_post_id = {$posts}.ID
				  AND ofp_wl_c.is_white_label = 1
			)";
		}

		return $clauses;
	}

	// -------------------------------------------------------------------------
	// Which host does a white label client's content live on?
	// -------------------------------------------------------------------------

	/**
	 * The public host of a white label client: their custom domain when set,
	 * otherwise {subdomain}.{base_domain}. Null for non white label clients,
	 * or when no host can be built (no custom domain and no base domain).
	 *
	 * @param object|null $client Row with is_white_label, custom_domain, subdomain.
	 */
	public static function host_for_client( $client ): ?string {
		if ( ! $client || empty( $client->is_white_label ) ) {
			return null;
		}
		if ( ! empty( $client->custom_domain ) ) {
			$host = self::normalize_domain( (string) $client->custom_domain );
			if ( $host !== '' ) {
				return $host;
			}
		}
		$base = strtolower( trim( (string) get_option( 'ofp_crm_base_domain', '' ) ) );
		$sub  = strtolower( trim( (string) ( $client->subdomain ?? '' ) ) );
		if ( $base !== '' && $sub !== '' ) {
			return $sub . '.' . $base;
		}
		return null;
	}

	/**
	 * Public host for the white label client that owns a property post,
	 * or null when the property belongs to a normal subscriber or to the
	 * platform. Memoised, because permalinks are built many times per page.
	 */
	public static function host_for_post( int $post_id ): ?string {
		static $memo    = [];
		static $clients = [];

		if ( array_key_exists( $post_id, $memo ) ) {
			return $memo[ $post_id ];
		}
		$memo[ $post_id ] = null;

		if ( $post_id <= 0 || ! self::schema_ready() ) {
			return null;
		}
		$owner = (int) get_post_meta( $post_id, 'ofp_client_id', true );
		if ( $owner <= 0 ) {
			return null;
		}
		if ( ! array_key_exists( $owner, $clients ) ) {
			global $wpdb;
			$clients[ $owner ] = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, is_white_label, custom_domain, subdomain FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
				$owner
			) );
		}
		$memo[ $post_id ] = self::host_for_client( $clients[ $owner ] );
		return $memo[ $post_id ];
	}

	// -------------------------------------------------------------------------
	// Keep every link on the white label domain
	// -------------------------------------------------------------------------

	public static function keep_links_on_white_label_host( string $url, string $path = '' ): string {
		if ( is_admin() ) {
			return $url;
		}
		if ( ! self::current_client() ) {
			return $url;
		}
		$host = OFP_Host_Router::current_client_host();
		if ( ! $host ) {
			return $url;
		}
		return preg_replace( '#^https?://[^/]+#i', 'https://' . $host, $url, 1 );
	}

	// -------------------------------------------------------------------------
	// Domain helpers (used by the admin client screen)
	// -------------------------------------------------------------------------

	/**
	 * "https://www.CRM.SupremeHomes.com/path" becomes "crm.supremehomes.com".
	 */
	public static function normalize_domain( string $raw ): string {
		$d = strtolower( trim( $raw ) );
		$d = preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $d );
		$d = preg_replace( '#[/?\#].*$#', '', $d );
		$d = preg_replace( '/:\d+$/', '', $d );
		$d = rtrim( $d, '.' );
		if ( strpos( $d, 'www.' ) === 0 ) {
			$d = substr( $d, 4 );
		}
		return $d;
	}

	public static function is_valid_domain( string $domain ): bool {
		return (bool) preg_match( '/^(?=.{4,150}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain );
	}

	public static function custom_domain_exists( string $domain, int $exclude_id = 0 ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}ofp_clients WHERE custom_domain IN (%s, %s) AND id != %d LIMIT 1",
			$domain,
			'www.' . $domain,
			$exclude_id
		) );
	}

	/**
	 * Returns an error message, or an empty string when the domain is fine.
	 * An empty domain is allowed (it clears the field).
	 */
	public static function validate_custom_domain( string $domain, int $client_id ): string {
		if ( $domain === '' ) {
			return '';
		}
		if ( ! self::is_valid_domain( $domain ) ) {
			return 'Enter a valid domain such as crm.supremehomes.com (no spaces, no path).';
		}

		$base = strtolower( (string) get_option( 'ofp_crm_base_domain', '' ) );
		if ( $base && ( $domain === $base || str_ends_with( $domain, '.' . $base ) ) ) {
			return 'That address belongs to your own base domain. Use the Subdomain field for it instead.';
		}

		$site_host = strtolower( (string) parse_url( get_option( 'siteurl' ), PHP_URL_HOST ) );
		$site_host = preg_replace( '/^www\./', '', $site_host );
		if ( $site_host && $domain === $site_host ) {
			return 'That is the address of this main site. A client cannot use it.';
		}

		if ( self::custom_domain_exists( $domain, $client_id ) ) {
			return 'That domain is already used by another client.';
		}

		return '';
	}
}
