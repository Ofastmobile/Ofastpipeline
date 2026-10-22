<?php
/**
 * Class OFP_Property_Tenant_Transfer
 *
 * Tenants pay rent by bank transfer, then tell the owner with proof. The owner
 * checks the bank, then approves or rejects from the Tenants page.
 *
 *  - tenant submits (amount, sender name, proof) from the lease page
 *  - payment is saved as "pending_verification"
 *  - the owner is told by email and SMS, the tenant gets a pending notice
 *  - on approval the existing rent code applies it to the lease and makes the receipt
 *  - the tenant is told (email and SMS) when it is confirmed or rejected
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Tenant_Transfer {

	const SCHEMA_OPTION = 'ofp_lease_proof_schema';

	public static function init(): void {
		self::ensure_schema();
		add_action( 'ofp_property_lease_payment_processed', [ __CLASS__, 'notify_confirmed' ], 20, 4 );
		add_action( 'wp_ajax_ofp_lease_proof', [ __CLASS__, 'serve_proof' ] );
		add_action( 'wp_ajax_nopriv_ofp_lease_proof', [ __CLASS__, 'serve_proof' ] );
	}

	/** Adds the columns that hold the tenant's uploaded proof. */
	public static function ensure_schema(): void {
		if ( get_option( self::SCHEMA_OPTION ) === '1' ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'ofp_property_lease_payments';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return;
		}
		$cols = [
			'proof_path' => 'VARCHAR(500) NULL',
			'proof_mime' => 'VARCHAR(100) NULL',
			'proof_size' => 'INT UNSIGNED NULL',
		];
		$all = true;
		foreach ( $cols as $name => $def ) {
			if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE '{$name}'" ) ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$def}" );
			}
			if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE '{$name}'" ) ) ) {
				$all = false;
			}
		}
		if ( $all ) {
			update_option( self::SCHEMA_OPTION, '1', true );
		}
	}

	public static function ready(): bool {
		return get_option( self::SCHEMA_OPTION ) === '1';
	}

	/** Short reference the tenant writes in the transfer note. */
	public static function reference_for( int $lease_id ): string {
		return 'RENT-' . $lease_id;
	}

	// ------------------------------------------------------------------ submit

	/**
	 * @param object $lease A lease row (needs id, balance).
	 * @return int|WP_Error The new payment id.
	 */
	public static function submit( object $lease, array $post, array $files ) {
		global $wpdb;
		$p = $wpdb->prefix;

		if ( ! self::ready() ) {
			self::ensure_schema();
			if ( ! self::ready() ) {
				return new WP_Error( 'transfer_not_ready', 'Payments by transfer are not ready yet. Please try again shortly.' );
			}
		}

		$lease_id = (int) $lease->id;
		$amount   = round( (float) ( $post['amount'] ?? 0 ), 2 );
		$name     = sanitize_text_field( wp_unslash( $post['payer_name'] ?? '' ) );
		$ref      = sanitize_text_field( wp_unslash( $post['payer_reference'] ?? '' ) );
		$note     = sanitize_text_field( wp_unslash( $post['note'] ?? '' ) );

		if ( (float) $lease->balance <= 0 ) {
			return new WP_Error( 'paid', 'There is nothing left to pay on this lease cycle.' );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'amount', 'Enter the amount you sent.' );
		}
		if ( $amount > (float) $lease->balance + 0.01 ) {
			return new WP_Error( 'amount', 'The amount is more than your balance of NGN ' . number_format( (float) $lease->balance, 2 ) . '.' );
		}
		if ( strlen( $name ) < 2 ) {
			return new WP_Error( 'name', 'Enter the name on the account you sent from.' );
		}
		if ( $ref !== '' ) {
			$dup = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}ofp_property_lease_payments WHERE lease_id = %d AND payer_reference = %s AND status IN ('pending_verification','successful')",
				$lease_id,
				$ref
			) );
			if ( $dup ) {
				return new WP_Error( 'dup', 'You already sent a payment notice with that reference.' );
			}
		}
		$open = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}ofp_property_lease_payments WHERE lease_id = %d AND status = 'pending_verification'",
			$lease_id
		) );
		if ( $open >= 5 ) {
			return new WP_Error( 'open', 'You have several payments waiting for approval. Please wait for them to be confirmed.' );
		}

		$file = $files['proof'] ?? null;
		if ( ! $file || empty( $file['name'] ) || (int) ( $file['error'] ?? 0 ) !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'proof', 'Please upload your proof of transfer (a photo, screenshot or PDF).' );
		}
		$stored = OFP_Property_Manual_Payment::store_receipt( $file );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$payment_id = OFP_Property_Lease_Payment::create( [
			'lease_id'        => $lease_id,
			'payment_method'  => 'manual',
			'amount'          => $amount,
			'status'          => 'pending_verification',
			'payer_name'      => $name,
			'payer_reference' => $ref,
			'note'            => $note,
		] );
		if ( is_wp_error( $payment_id ) ) {
			OFP_Property_Manual_Payment::delete_receipt( $stored['path'] );
			return $payment_id;
		}

		$wpdb->update( "{$p}ofp_property_lease_payments", [
			'proof_path' => $stored['path'],
			'proof_mime' => $stored['mime'],
			'proof_size' => $stored['size'],
		], [ 'id' => (int) $payment_id ] );

		self::notify_submitted( (int) $payment_id );
		return (int) $payment_id;
	}

	// ------------------------------------------------------------------ owner decisions

	/** @return true|WP_Error */
	public static function approve( int $payment_id, int $client_id ) {
		$row = self::owned_payment( $payment_id, $client_id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Payment not found.' );
		}
		if ( $row->status !== 'pending_verification' ) {
			return new WP_Error( 'state', 'This payment is not waiting for approval.' );
		}
		$result = OFP_Property_Lease_Payment::success( $payment_id, 0 );
		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'apply', $result['error'] ?? 'Could not apply this payment.' );
		}
		return true;
	}

	/** @return true|WP_Error */
	public static function reject( int $payment_id, int $client_id, string $reason = '' ) {
		global $wpdb;
		$row = self::owned_payment( $payment_id, $client_id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Payment not found.' );
		}
		if ( $row->status !== 'pending_verification' ) {
			return new WP_Error( 'state', 'This payment is not waiting for approval.' );
		}
		$reason = $reason !== '' ? $reason : 'The owner could not confirm it.';
		$wpdb->update( $wpdb->prefix . 'ofp_property_lease_payments', [
			'status'     => 'rejected',
			'note'       => trim( (string) $row->note . ' | Rejected: ' . $reason, ' |' ),
			'updated_at' => current_time( 'mysql' ),
		], [ 'id' => $payment_id ] );
		self::notify_rejected( $payment_id, $reason );
		return true;
	}

	private static function owned_payment( int $payment_id, int $client_id ): ?object {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT py.* FROM {$p}ofp_property_lease_payments py
			 INNER JOIN {$p}ofp_property_leases l ON l.id = py.lease_id
			 WHERE py.id = %d AND l.client_id = %d LIMIT 1",
			$payment_id,
			$client_id
		) ) ?: null;
	}

	// ------------------------------------------------------------------ proof file

	public static function proof_url( int $payment_id ): string {
		$ajax = admin_url( 'admin-ajax.php' );
		if ( ! is_admin() && ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$ajax = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . wp_parse_url( $ajax, PHP_URL_PATH );
		}
		return add_query_arg( [
			'action'     => 'ofp_lease_proof',
			'payment_id' => $payment_id,
			'n'          => wp_create_nonce( 'ofp_lease_proof_' . $payment_id ),
		], $ajax );
	}

	public static function serve_proof(): void {
		global $wpdb;
		$p          = $wpdb->prefix;
		$payment_id = absint( $_GET['payment_id'] ?? 0 );
		$nonce      = sanitize_text_field( wp_unslash( $_GET['n'] ?? '' ) );
		if ( ! $payment_id || ! wp_verify_nonce( $nonce, 'ofp_lease_proof_' . $payment_id ) ) {
			wp_die( 'This link has expired.', 'Not allowed', [ 'response' => 403 ] );
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT py.proof_path, py.proof_mime, l.client_id
			 FROM {$p}ofp_property_lease_payments py
			 INNER JOIN {$p}ofp_property_leases l ON l.id = py.lease_id
			 WHERE py.id = %d LIMIT 1",
			$payment_id
		) );
		$allowed = false;
		if ( $row ) {
			if ( current_user_can( 'manage_options' ) ) {
				$allowed = true;
			} else {
				$client  = OFP_Auth::current_client();
				$allowed = $client && (int) $client->id === (int) $row->client_id;
			}
		}
		if ( ! $allowed || empty( $row->proof_path ) || ! is_file( $row->proof_path ) ) {
			wp_die( 'File not found.', 'Not found', [ 'response' => 404 ] );
		}
		nocache_headers();
		header( 'Content-Type: ' . ( $row->proof_mime ?: 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $row->proof_path ) );
		header( 'Content-Disposition: inline; filename="proof-' . $payment_id . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $row->proof_path );
		exit;
	}

	// ------------------------------------------------------------------ notices

	private static function context( int $payment_id ): ?object {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT py.amount, py.payer_name, py.payer_reference, py.lease_id,
			        t.full_name AS tenant_name, t.email AS tenant_email, t.phone AS tenant_phone,
			        pr.title AS property_title,
			        l.client_id, l.offer_token, c.email AS owner_email, c.owner_name, c.phone AS owner_phone, c.sms_provider
			 FROM {$p}ofp_property_lease_payments py
			 INNER JOIN {$p}ofp_property_leases l ON l.id = py.lease_id
			 INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
			 LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
			 LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
			 WHERE py.id = %d LIMIT 1",
			$payment_id
		) ) ?: null;
	}

	/** Links the tenant can use: their lease page (to pay and see receipts) and My Payments. */
	private static function link_html( object $row, string $mp_label = 'View or Download Receipt', string $button_color = '#16a34a' ): string {
		$html = '';
		$hub_url = class_exists( 'OFP_Property_My_Payments' )
			? OFP_Property_My_Payments::url()
			: home_url( '/my-payments/' );
		$html .= '<p style="margin:22px 0 10px;"><a href="' . esc_url( $hub_url ) . '" style="display:inline-block;background:' . esc_attr( $button_color ) . ';color:#ffffff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;">' . esc_html( $mp_label ) . '</a></p>';
		if ( ! empty( $row->offer_token ) ) {
			$html .= '<p style="margin:0 0 16px;"><a href="' . esc_url( home_url( '/tenant-lease/' . rawurlencode( $row->offer_token ) . '/' ) ) . '" style="color:#2563eb;text-decoration:underline;font-size:14px;">Open your lease page</a></p>';
		}
		return $html;
	}

	private static function sms_to( object $row, string $phone, string $message ): void {
		if ( $phone === '' || empty( $row->client_id ) || ! OFP_SMS::ready() ) {
			return;
		}
		$client_id = (int) $row->client_id;
		$cost      = OFP_Property_Comms_Bridge::SMS_COST;
		if ( ! OFP_Credit::has_balance( $client_id, 'sms', $cost ) ) {
			return;
		}
		$sms    = OFP_SMS::for_client( (int) $client_id );
		$result = $sms->send( $phone, $message );
		if ( ! empty( $result['success'] ) ) {
			OFP_Credit::deduct( $client_id, 'sms', $cost );
		}
	}

	private static function notify_submitted( int $payment_id ): void {
		$row = self::context( $payment_id );
		if ( ! $row ) return;

		$title  = $row->property_title ?: 'the property';
		$amount = number_format( (float) $row->amount, 2 );
		$who    = $row->payer_name ?: $row->tenant_name;

		if ( $row->client_id && class_exists( 'OFP_Notification' ) ) {
			OFP_Notification::create( (int) $row->client_id, 'tenant_transfer_submitted', 'Rent payment waiting for your approval', sprintf( '%s says they paid ₦%s for %s. Check your bank, then approve or reject on the Tenants page.', $who, $amount, $title ) );
		}
		if ( ! empty( $row->owner_email ) ) {
			OFP_Mailer::send(
				$row->owner_email,
				$row->owner_name ?: 'there',
				'Rent payment waiting for your approval — ' . $title,
				sprintf(
					'<p>Hello %s,</p><p><strong>%s</strong> says they paid <strong>₦%s</strong> rent for <strong>%s</strong>.</p><p>Reference: <strong>%s</strong></p><p>Check your bank account. If the money is there, approve it. If not, reject it with a reason.</p><p><a href="%s">Open the Tenants page</a></p>',
					esc_html( $row->owner_name ?: 'there' ), esc_html( $who ), esc_html( $amount ), esc_html( $title ), esc_html( $row->payer_reference ?: 'none given' ), esc_url( add_query_arg( 'tab', 'payments', home_url( '/tenants' ) ) )
				)
			);
		}
		self::sms_to( $row, (string) $row->owner_phone, sprintf( 'Rent to approve: %s says N%s paid for %s. Check your bank, then approve on the Tenants page.', $who, $amount, $title ) );

		if ( ! empty( $row->tenant_email ) ) {
			OFP_Mailer::send(
				$row->tenant_email,
				$row->tenant_name ?: 'there',
				'We received your payment notice — ' . $title,
				sprintf( '<p>Hello %s,</p><p>We received your notice of <strong>₦%s</strong> for <strong>%s</strong>.</p><p>It is <strong>pending approval</strong>. The owner will confirm it after checking their bank account. You will get a message when it is confirmed.</p>', esc_html( $row->tenant_name ?: 'there' ), esc_html( $amount ), esc_html( $title ) ) . self::link_html( $row, 'View Payment Status', '#2563eb' )
			);
		}
		self::sms_to( $row, (string) $row->tenant_phone, sprintf( 'We got your rent payment notice of N%s for %s. It is pending approval. You will be told when it is confirmed.', $amount, $title ) );
	}

	/** Runs for every confirmed rent payment (transfer, cash or online). */
	public static function notify_confirmed( int $lease_id, int $payment_id, float $amount, string $method ): void {
		$row = self::context( $payment_id );
		if ( ! $row ) return;

		$title = $row->property_title ?: 'your rental';
		$paid  = number_format( $amount, 2 );

		if ( ! empty( $row->tenant_email ) ) {
			OFP_Mailer::send(
				$row->tenant_email,
				$row->tenant_name ?: 'there',
				'Rent payment confirmed — ' . $title,
				sprintf( '<p>Hello %s,</p><p>Your rent payment of <strong>₦%s</strong> for <strong>%s</strong> is <strong>confirmed</strong>.</p><p>You can see your payment history and download your official receipt below.</p>', esc_html( $row->tenant_name ?: 'there' ), esc_html( $paid ), esc_html( $title ) ) . self::link_html( $row, 'View or Download Receipt', '#16a34a' )
			);
		}
		$hub_url = class_exists( 'OFP_Property_My_Payments' ) ? OFP_Property_My_Payments::url() : home_url( '/my-payments/' );
		self::sms_to( $row, (string) $row->tenant_phone, sprintf( 'Your rent payment of N%s for %s is confirmed. Receipt: %s', $paid, $title, $hub_url ) );
	}

	private static function notify_rejected( int $payment_id, string $reason ): void {
		$row = self::context( $payment_id );
		if ( ! $row ) return;

		$title  = $row->property_title ?: 'your rental';
		$amount = number_format( (float) $row->amount, 2 );

		if ( ! empty( $row->tenant_email ) ) {
			OFP_Mailer::send(
				$row->tenant_email,
				$row->tenant_name ?: 'there',
				'Rent payment not confirmed — ' . $title,
				sprintf( '<p>Hello %s,</p><p>Your payment notice of <strong>₦%s</strong> for <strong>%s</strong> was <strong>not confirmed</strong>.</p><p>Reason: %s</p><p>If you did pay, open your lease link and send the notice again with the right details and proof.</p>', esc_html( $row->tenant_name ?: 'there' ), esc_html( $amount ), esc_html( $title ), esc_html( $reason ) ) . self::link_html( $row, 'View Payment Status', '#2563eb' )
			);
		}
		self::sms_to( $row, (string) $row->tenant_phone, sprintf( 'Your rent payment notice of N%s for %s was not confirmed. Reason: %s. Open your lease link to send it again.', $amount, $title, $reason ) );
	}
}
