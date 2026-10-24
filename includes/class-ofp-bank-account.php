<?php
/**
 * Class OFP_Bank_Account
 *
 * Bank accounts that a property owner (client) shows to buyers and tenants who
 * pay by transfer. An owner can keep up to 5 accounts, one is the default, and
 * each property can pick which account it uses.
 *
 * Also holds the switch for online (Paystack) card payments by buyers and
 * tenants. That switch is OFF by default: money goes to the owner's account.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Bank_Account {

	const SCHEMA_OPTION  = 'ofp_bank_accounts_schema';
	const SCHEMA_VERSION = '1';
	const MAX_PER_CLIENT = 5;

	public static function init(): void {
		self::ensure_schema();
		add_action( 'wp_ajax_ofp_resolve_bank_account', [ __CLASS__, 'ajax_resolve_account' ] );
		add_action( 'wp_ajax_nopriv_ofp_resolve_bank_account', [ __CLASS__, 'ajax_resolve_account' ] );
		add_action( 'wp_ajax_ofp_get_bank_list', [ __CLASS__, 'ajax_get_bank_list' ] );
		add_action( 'wp_ajax_nopriv_ofp_get_bank_list', [ __CLASS__, 'ajax_get_bank_list' ] );
	}

	/** True only when the admin has switched online card payments on for buyers and tenants. */
	public static function online_payments_enabled(): bool {
		return get_option( 'ofp_online_buyer_payments', '0' ) === '1';
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ofp_bank_accounts';
	}

	public static function ensure_schema(): void {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			client_id BIGINT UNSIGNED NOT NULL,
			bank_name VARCHAR(80) NOT NULL,
			account_number VARCHAR(20) NOT NULL,
			account_name VARCHAR(120) NOT NULL,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY client_id (client_id)
		) {$charset};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
		}
		if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'bank_code'" ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN bank_code VARCHAR(20) DEFAULT '' AFTER bank_name" );
		}
	}

	public static function list( int $client_id ): array {
		global $wpdb;
		if ( $client_id <= 0 ) {
			return [];
		}
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE client_id = %d ORDER BY is_default DESC, id ASC",
			$client_id
		) );
	}

	public static function get( int $id, int $client_id = 0 ): ?object {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ) );
		if ( ! $row || ( $client_id > 0 && (int) $row->client_id !== $client_id ) ) {
			return null;
		}
		return $row;
	}

	/** @return int|WP_Error new id */
	public static function add( int $client_id, string $bank_name, string $account_number, string $account_name ) {
		global $wpdb;
		$bank_name      = trim( sanitize_text_field( $bank_name ) );
		$account_number = preg_replace( '/\D/', '', $account_number );
		$account_name   = trim( sanitize_text_field( $account_name ) );

		if ( strlen( $bank_name ) < 2 || strlen( $bank_name ) > 80 ) {
			return new WP_Error( 'bank', 'Enter the bank name.' );
		}
		if ( strlen( $account_number ) !== 10 ) {
			return new WP_Error( 'bank', 'The account number must be exactly 10 digits.' );
		}
		if ( strlen( $account_name ) < 2 || strlen( $account_name ) > 120 ) {
			return new WP_Error( 'bank', 'Enter the account name.' );
		}
		$existing = self::list( $client_id );
		if ( count( $existing ) >= self::MAX_PER_CLIENT ) {
			return new WP_Error( 'bank', 'You can keep up to ' . self::MAX_PER_CLIENT . ' bank accounts. Remove one first.' );
		}
		foreach ( $existing as $row ) {
			if ( $row->account_number === $account_number && strcasecmp( $row->bank_name, $bank_name ) === 0 ) {
				return new WP_Error( 'bank', 'That account is already saved.' );
			}
		}

		$wpdb->insert( self::table(), [
			'client_id'      => $client_id,
			'bank_name'      => $bank_name,
			'account_number' => $account_number,
			'account_name'   => $account_name,
			'is_default'     => $existing ? 0 : 1,
			'created_at'     => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	public static function delete( int $id, int $client_id ): bool {
		global $wpdb;
		$row = self::get( $id, $client_id );
		if ( ! $row ) {
			return false;
		}
		$wpdb->delete( self::table(), [ 'id' => $id ] );
		if ( (int) $row->is_default === 1 ) {
			$next = self::list( $client_id );
			if ( $next ) {
				$wpdb->update( self::table(), [ 'is_default' => 1 ], [ 'id' => (int) $next[0]->id ] );
			}
		}
		return true;
	}

	public static function set_default( int $id, int $client_id ): bool {
		global $wpdb;
		if ( ! self::get( $id, $client_id ) ) {
			return false;
		}
		$wpdb->update( self::table(), [ 'is_default' => 0 ], [ 'client_id' => $client_id ] );
		$wpdb->update( self::table(), [ 'is_default' => 1 ], [ 'id' => $id ] );
		return true;
	}

	/** The account a buyer should pay into for this purchase. */
	public static function for_purchase( object $purchase ): ?object {
		global $wpdb;
		$client_id = (int) ( $purchase->client_id ?? 0 );
		$post_id   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT wp_post_id FROM {$wpdb->prefix}ofp_properties WHERE id = %d LIMIT 1",
			(int) ( $purchase->property_id ?? 0 )
		) );
		return self::for_post( $post_id, $client_id );
	}

	/** The account for a property post: the one picked on the property, else the default. */
	public static function for_post( int $post_id, int $client_id ): ?object {
		if ( $post_id > 0 ) {
			$bank_id = (int) get_post_meta( $post_id, 'ofp_bank_account_id', true );
			if ( $bank_id ) {
				$row = self::get( $bank_id, $client_id );
				if ( $row ) {
					return $row;
				}
			}
		}
		$list = self::list( $client_id );
		return $list ? $list[0] : null;
	}

	/** Short reference the payer writes in the transfer note. */
	public static function purchase_reference( int $purchase_id ): string {
		return 'PUR-' . $purchase_id;
	}

	/** Bank details as an email block. */
	public static function email_block( object $acct, string $reference ): string {
		return sprintf(
			'<p><strong>Pay by bank transfer</strong><br>Bank: <strong>%s</strong><br>Account number: <strong>%s</strong><br>Account name: <strong>%s</strong><br>Write this in the transfer note: <strong>%s</strong></p>',
			esc_html( $acct->bank_name ),
			esc_html( $acct->account_number ),
			esc_html( $acct->account_name ),
			esc_html( $reference )
		);
	}

	/**
	 * Return list of banks in Nigeria.
	 * Queries Paystack API (cached 7 days) with robust offline fallback.
	 */
	public static function get_banks(): array {
		$cached = get_transient( 'ofp_paystack_banks_ng' );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$secret_encrypted = get_option( 'ofp_paystack_secret_key', '' );
		$secret = class_exists( 'OFP_Security' ) ? OFP_Security::decrypt( $secret_encrypted ) : '';

		$headers = [ 'Accept' => 'application/json' ];
		if ( ! empty( $secret ) ) {
			$headers['Authorization'] = 'Bearer ' . $secret;
		}

		$res = wp_remote_get( 'https://api.paystack.co/bank?country=nigeria&perPage=100', [
			'timeout' => 8,
			'headers' => $headers,
		] );

		if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! empty( $body['status'] ) && ! empty( $body['data'] ) && is_array( $body['data'] ) ) {
				$banks = [];
				foreach ( $body['data'] as $b ) {
					if ( ! empty( $b['active'] ) && ! empty( $b['name'] ) && ! empty( $b['code'] ) ) {
						$banks[] = [
							'name' => trim( (string) $b['name'] ),
							'code' => trim( (string) $b['code'] ),
							'slug' => trim( (string) ( $b['slug'] ?? '' ) ),
						];
					}
				}
				if ( ! empty( $banks ) ) {
					usort( $banks, fn( $x, $y ) => strcasecmp( $x['name'], $y['name'] ) );
					set_transient( 'ofp_paystack_banks_ng', $banks, 7 * DAY_IN_SECONDS );
					return $banks;
				}
			}
		}

		// Curated fallback of major Nigerian commercial, merchant & fintech banks
		$fallback = [
			[ 'name' => 'Access Bank', 'code' => '044' ],
			[ 'name' => 'Access Bank (Diamond)', 'code' => '063' ],
			[ 'name' => 'Citibank Nigeria', 'code' => '023' ],
			[ 'name' => 'Ecobank Nigeria', 'code' => '050' ],
			[ 'name' => 'Fidelity Bank', 'code' => '070' ],
			[ 'name' => 'First Bank of Nigeria', 'code' => '011' ],
			[ 'name' => 'First City Monument Bank (FCMB)', 'code' => '214' ],
			[ 'name' => 'Guaranty Trust Bank (GTBank)', 'code' => '058' ],
			[ 'name' => 'Heritage Bank', 'code' => '030' ],
			[ 'name' => 'Jaiz Bank', 'code' => '301' ],
			[ 'name' => 'Keystone Bank', 'code' => '082' ],
			[ 'name' => 'Kuda Bank', 'code' => '50211' ],
			[ 'name' => 'Moniepoint MFB', 'code' => '50515' ],
			[ 'name' => 'OPay (PayCom)', 'code' => '999992' ],
			[ 'name' => 'PalmPay', 'code' => '999991' ],
			[ 'name' => 'Polaris Bank', 'code' => '076' ],
			[ 'name' => 'Providus Bank', 'code' => '101' ],
			[ 'name' => 'Stanbic IBTC Bank', 'code' => '221' ],
			[ 'name' => 'Standard Chartered Bank', 'code' => '068' ],
			[ 'name' => 'Sterling Bank', 'code' => '232' ],
			[ 'name' => 'Suntrust Bank', 'code' => '100' ],
			[ 'name' => 'TAJ Bank', 'code' => '302' ],
			[ 'name' => 'Titan Trust Bank', 'code' => '102' ],
			[ 'name' => 'Union Bank of Nigeria', 'code' => '032' ],
			[ 'name' => 'United Bank For Africa (UBA)', 'code' => '033' ],
			[ 'name' => 'Unity Bank', 'code' => '215' ],
			[ 'name' => 'Wema Bank', 'code' => '035' ],
			[ 'name' => 'Zenith Bank', 'code' => '057' ],
			[ 'name' => '9PSB', 'code' => '120001' ],
		];
		usort( $fallback, fn( $x, $y ) => strcasecmp( $x['name'], $y['name'] ) );
		return $fallback;
	}

	/**
	 * Resolve 10-digit NUBAN and Bank Code via Paystack API.
	 */
	public static function resolve_account( string $account_number, string $bank_code ): array {
		$account_number = preg_replace( '/\D/', '', $account_number );
		$bank_code      = trim( sanitize_text_field( $bank_code ) );

		if ( strlen( $account_number ) !== 10 ) {
			return [ 'success' => false, 'message' => 'Account number must be exactly 10 digits.' ];
		}
		if ( empty( $bank_code ) ) {
			return [ 'success' => false, 'message' => 'Please select a bank first.' ];
		}

		$secret_encrypted = get_option( 'ofp_paystack_secret_key', '' );
		$secret = class_exists( 'OFP_Security' ) ? OFP_Security::decrypt( $secret_encrypted ) : '';

		if ( empty( $secret ) ) {
			return [ 'success' => false, 'message' => 'Paystack secret key is not configured in Admin Settings.' ];
		}

		$url = sprintf(
			'https://api.paystack.co/bank/resolve?account_number=%s&bank_code=%s',
			rawurlencode( $account_number ),
			rawurlencode( $bank_code )
		);

		$res = wp_remote_get( $url, [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'Bearer ' . $secret,
				'Accept'        => 'application/json',
			],
		] );

		if ( is_wp_error( $res ) ) {
			return [ 'success' => false, 'message' => 'Unable to reach verification service. Check internet connection.' ];
		}

		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code === 200 && ! empty( $body['status'] ) && ! empty( $body['data']['account_name'] ) ) {
			return [
				'success'        => true,
				'account_name'   => trim( (string) $body['data']['account_name'] ),
				'account_number' => $account_number,
				'bank_code'      => $bank_code,
			];
		}

		$msg = ! empty( $body['message'] ) ? $body['message'] : 'Account number could not be resolved for selected bank.';
		return [ 'success' => false, 'message' => $msg ];
	}

	/**
	 * Only a logged-in client (or a WordPress admin) may use the bank tools.
	 * These calls spend the platform's Paystack key and reveal the name on a bank
	 * account, so they must never be open to anonymous visitors. The only screen
	 * that uses them is the client account page.
	 */
	private static function caller_allowed(): bool {
		return ( class_exists( 'OFP_Auth' ) && OFP_Auth::current_client() ) || current_user_can( 'manage_options' );
	}

	public static function ajax_resolve_account(): void {
		if ( ! self::caller_allowed() ) {
			wp_send_json_error( [ 'message' => 'Please log in again to verify a bank account.' ], 401 );
		}
		if ( ! check_ajax_referer( 'ofp_bank_lookup', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'This page has expired. Please reload it and try again.' ], 403 );
		}

		// 20 lookups per 10 minutes for one client, and the same for one IP.
		// check_rate_limit() answers in JSON when OFP_JSON_API is defined.
		if ( ! defined( 'OFP_JSON_API' ) ) {
			define( 'OFP_JSON_API', true );
		}
		$client = class_exists( 'OFP_Auth' ) ? OFP_Auth::current_client() : null;
		if ( $client && ! empty( $client->id ) ) {
			OFP_Security::check_rate_limit( 'client:' . (int) $client->id, 'bank_resolve', 20, 600 );
		}
		OFP_Security::check_rate_limit( OFP_Security::get_client_ip(), 'bank_resolve', 20, 600 );

		$account_number = sanitize_text_field( $_POST['account_number'] ?? '' );
		$bank_code      = sanitize_text_field( $_POST['bank_code'] ?? '' );

		$result = self::resolve_account( $account_number, $bank_code );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	public static function ajax_get_bank_list(): void {
		if ( ! self::caller_allowed() ) {
			wp_send_json_error( [ 'message' => 'Please log in again.' ], 401 );
		}
		wp_send_json_success( [ 'banks' => self::get_banks() ] );
	}
}

