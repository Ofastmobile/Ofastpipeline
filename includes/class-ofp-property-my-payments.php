<?php
/**
 * Class OFP_Property_My_Payments
 *
 * A page for buyers and tenants who have no account: /my-payments/
 *
 *  - they type their email or phone and get a 6 digit code (email or SMS)
 *  - after the code they see every purchase and lease tied to that email or phone:
 *    totals, balance, the installment schedule, every payment with its status,
 *    and a receipt to open for each confirmed payment
 *  - on a solo company's own domain, only that company's records show, with
 *    their logo and nothing of ours
 *
 * Protection: page token, trap field, limits per visitor address, per email or
 * phone and per day, codes that expire in 10 minutes and allow 5 tries, and a
 * signed login cookie that lasts 2 hours.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_My_Payments {

	const ROUTE_VERSION = '1';
	const COOKIE        = 'ofp_mp';
	const SESSION_TTL   = 7200;
	const CODE_TTL      = 600;

	public static function init(): void {
		if ( get_option( 'ofp_my_payments_route_version' ) !== self::ROUTE_VERSION ) {
			update_option( 'ofp_my_payments_route_version', self::ROUTE_VERSION, false );
			update_option( 'ofp_flush_rewrite_rules', '1' );
		}
		add_action( 'init', [ __CLASS__, 'register_route' ] );
		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_route' ] );
		add_action( 'wp_ajax_ofp_my_receipt', [ __CLASS__, 'serve_receipt' ] );
		add_action( 'wp_ajax_nopriv_ofp_my_receipt', [ __CLASS__, 'serve_receipt' ] );
	}

	public static function register_route(): void {
		add_rewrite_rule( '^my-payments/?$', 'index.php?ofp_my_payments=1', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'ofp_my_payments';
		return $vars;
	}

	public static function url(): string {
		return home_url( '/my-payments/' );
	}

	// ------------------------------------------------------------------ identity helpers

	/** @return array|null [ 'type' => 'email'|'phone', 'ident' => normalized, 'send_to' => value to send the code to ] */
	private static function parse_identity( string $raw ): ?array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return null;
		}
		if ( strpos( $raw, '@' ) !== false ) {
			$email = strtolower( sanitize_email( $raw ) );
			return is_email( $email ) ? [ 'type' => 'email', 'ident' => $email, 'send_to' => $email ] : null;
		}
		$phone = OFP_Security::sanitize_phone( $raw );
		$digits = preg_replace( '/\D/', '', $phone );
		if ( strlen( $digits ) < 10 ) {
			return null;
		}
		return [ 'type' => 'phone', 'ident' => substr( $digits, -10 ), 'send_to' => $phone ];
	}

	private static function mask( string $type, string $ident ): string {
		if ( $type === 'email' ) {
			$parts = explode( '@', $ident, 2 );
			return substr( $parts[0], 0, 2 ) . '***@' . ( $parts[1] ?? '' );
		}
		return '******' . substr( $ident, -4 );
	}

	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'ofp_mp' );
	}

	private static function session(): ?array {
		$raw = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );
		if ( $raw === '' || strpos( $raw, '.' ) === false ) {
			return null;
		}
		list( $b64, $sig ) = explode( '.', $raw, 2 );
		$json = base64_decode( strtr( $b64, '-_', '+/' ) );
		if ( ! $json || ! hash_equals( self::sign( $json ), $sig ) ) {
			return null;
		}
		$d = json_decode( $json, true );
		if ( ! is_array( $d ) || empty( $d['i'] ) || empty( $d['t'] ) || (int) ( $d['e'] ?? 0 ) < time() ) {
			return null;
		}
		return $d;
	}

	private static function start_session( string $type, string $ident ): void {
		$json  = wp_json_encode( [ 't' => $type, 'i' => $ident, 'e' => time() + self::SESSION_TTL ] );
		$value = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ) . '.' . self::sign( $json );
		setcookie( self::COOKIE, $value, [
			'expires'  => time() + self::SESSION_TTL,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		] );
		$_COOKIE[ self::COOKIE ] = $value;
	}

	private static function end_session(): void {
		setcookie( self::COOKIE, '', [ 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/** On a solo company's own domain only that company's records are shown. */
	private static function only_client_id(): int {
		$c = ( class_exists( 'OFP_Brand' ) && OFP_Brand::is_solo_host() ) ? OFP_White_Label::current_client() : null;
		return $c ? (int) $c->id : 0;
	}

	// ------------------------------------------------------------------ data

	private static function purchases( string $type, string $ident, int $only_client ): array {
		global $wpdb;
		$p     = $wpdb->prefix;
		$where = $type === 'email'
			? $wpdb->prepare( 'pu.buyer_email = %s', $ident )
			: $wpdb->prepare( 'pu.buyer_phone LIKE %s', '%' . $wpdb->esc_like( $ident ) );
		$extra = $only_client ? $wpdb->prepare( ' AND pu.client_id = %d', $only_client ) : '';
		return (array) $wpdb->get_results(
			"SELECT pu.*, pr.title AS property_title, c.business_name, c.owner_name
			 FROM {$p}ofp_property_purchases pu
			 LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
			 LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
			 WHERE {$where}{$extra}
			 ORDER BY pu.created_at DESC LIMIT 50"
		);
	}

	private static function leases( string $type, string $ident, int $only_client ): array {
		global $wpdb;
		$p     = $wpdb->prefix;
		$where = $type === 'email'
			? $wpdb->prepare( 't.email = %s', $ident )
			: $wpdb->prepare( 't.phone LIKE %s', '%' . $wpdb->esc_like( $ident ) );
		$extra = $only_client ? $wpdb->prepare( ' AND l.client_id = %d', $only_client ) : '';
		return (array) $wpdb->get_results(
			"SELECT l.*, t.full_name, pr.title AS property_title, c.business_name, c.owner_name
			 FROM {$p}ofp_property_leases l
			 INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
			 LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
			 LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
			 WHERE {$where}{$extra}
			 ORDER BY l.created_at DESC LIMIT 50"
		);
	}

	private static function has_records( string $type, string $ident ): bool {
		return (bool) ( self::purchases( $type, $ident, self::only_client_id() ) || self::leases( $type, $ident, self::only_client_id() ) );
	}

	private static function status_label( string $status ): string {
		$map = [
			'successful'           => 'Confirmed',
			'pending_verification' => 'Pending approval',
			'rejected'             => 'Not confirmed',
			'failed'               => 'Failed',
			'cancelled'            => 'Cancelled',
			'paid'                 => 'Paid',
			'partially_paid'       => 'Part paid',
			'overdue'              => 'Overdue',
			'scheduled'            => 'Upcoming',
			'active'               => 'Active',
			'completed'            => 'Completed',
		];
		return $map[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
	}

	private static function receipt_url( string $type, int $payment_id ): string {
		$ajax = admin_url( 'admin-ajax.php' );
		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$ajax = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . wp_parse_url( $ajax, PHP_URL_PATH );
		}
		return add_query_arg( [
			'action' => 'ofp_my_receipt',
			'type'   => $type,
			'id'     => $payment_id,
			'n'      => wp_create_nonce( 'ofp_my_receipt_' . $type . '_' . $payment_id ),
		], $ajax );
	}

	// ------------------------------------------------------------------ route

	public static function handle_route(): void {
		if ( ! get_query_var( 'ofp_my_payments' ) ) {
			return;
		}
		nocache_headers();

		$msg   = '';
		$error = '';
		$step  = 'ask'; // ask | code
		$token = '';
		$hint  = '';

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			OFP_Security::check_rate_limit( OFP_Security::get_client_ip(), 'my_payments', 12, 600 );

			$nonce_ok = isset( $_POST['ofp_mp_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ofp_mp_nonce'] ) ), 'ofp_my_payments' );
			$act      = sanitize_key( wp_unslash( $_POST['mp_action'] ?? '' ) );

			if ( ! $nonce_ok ) {
				$error = 'This page has expired. Please reload it and try again.';
			} elseif ( ! empty( $_POST['ofp_website'] ) ) {
				$error = 'We could not process this request.';
			} elseif ( $act === 'logout' ) {
				self::end_session();
				wp_safe_redirect( self::url() );
				exit;
			} elseif ( $act === 'request' ) {
				$who = self::parse_identity( (string) wp_unslash( $_POST['identity'] ?? '' ) );
				if ( ! $who ) {
					$error = 'Enter the email address or phone number you used.';
				} else {
					$limited = false;
					$k_ident = 'ofp_mp_i_' . md5( $who['type'] . $who['ident'] );
					$n       = (int) get_transient( $k_ident );
					if ( $n >= 3 ) {
						$limited = true;
					} else {
						set_transient( $k_ident, $n + 1, HOUR_IN_SECONDS );
					}
					if ( $who['type'] === 'phone' ) {
						$k_day = 'ofp_mp_sms_' . gmdate( 'Y-m-d' );
						$d     = (int) get_transient( $k_day );
						if ( $d >= 300 ) {
							$limited = true;
						} elseif ( ! $limited ) {
							set_transient( $k_day, $d + 1, DAY_IN_SECONDS );
						}
					}
					if ( $limited ) {
						$error = 'Too many codes were requested. Please try again later.';
					} else {
						$token = wp_generate_password( 32, false, false );
						$code  = (string) random_int( 100000, 999999 );
						// The same message is shown whether or not records exist, so nobody can test who has an account.
						set_transient( 'ofp_mp_c_' . $token, [
							'hash'     => hash_hmac( 'sha256', $code, wp_salt( 'auth' ) ),
							'type'     => $who['type'],
							'ident'    => $who['ident'],
							'attempts' => 0,
							'real'     => self::has_records( $who['type'], $who['ident'] ),
						], self::CODE_TTL );
						if ( self::has_records( $who['type'], $who['ident'] ) ) {
							if ( $who['type'] === 'email' ) {
								OFP_Mailer::send(
									$who['send_to'],
									'there',
									'Your code to view your payments',
									'<p>Your code is <strong style="font-size:22px;letter-spacing:3px;">' . esc_html( $code ) . '</strong></p><p>It expires in 10 minutes. If you did not ask for it, ignore this message.</p>'
								);
							} else {
								OFP_SMS::send_system_sms( $who['send_to'], 'Your code to view your payments is ' . $code . '. It expires in 10 minutes.' );
							}
						}
						$step = 'code';
						$hint = self::mask( $who['type'], $who['ident'] );
						$msg  = 'If we found payments for that ' . ( $who['type'] === 'email' ? 'email address' : 'phone number' ) . ', we sent a 6 digit code to it.';
					}
				}
			} elseif ( $act === 'verify' ) {
				$token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) wp_unslash( $_POST['token'] ?? '' ) );
				$code  = preg_replace( '/\D/', '', (string) wp_unslash( $_POST['code'] ?? '' ) );
				$saved = $token ? get_transient( 'ofp_mp_c_' . $token ) : false;
				if ( ! is_array( $saved ) ) {
					$error = 'That code has expired. Please start again.';
				} elseif ( (int) $saved['attempts'] >= 5 ) {
					delete_transient( 'ofp_mp_c_' . $token );
					$error = 'Too many wrong codes. Please start again.';
				} elseif ( ! empty( $saved['real'] ) && $code !== '' && hash_equals( (string) $saved['hash'], hash_hmac( 'sha256', $code, wp_salt( 'auth' ) ) ) ) {
					delete_transient( 'ofp_mp_c_' . $token );
					self::start_session( $saved['type'], $saved['ident'] );
					wp_safe_redirect( self::url() );
					exit;
				} else {
					$saved['attempts'] = (int) $saved['attempts'] + 1;
					set_transient( 'ofp_mp_c_' . $token, $saved, self::CODE_TTL );
					$error = 'That code is not correct.';
					$step  = 'code';
					$hint  = self::mask( $saved['type'], $saved['ident'] );
				}
			}
		}

		$sess = self::session();
		self::render( $sess, $step, $token, $hint, $msg, $error );
		exit;
	}

	// ------------------------------------------------------------------ receipts

	public static function serve_receipt(): void {
		global $wpdb;
		$p    = $wpdb->prefix;
		$type = ( ( $_GET['type'] ?? '' ) === 'rent' ) ? 'rent' : 'purchase';
		$id   = absint( $_GET['id'] ?? 0 );
		$n    = sanitize_text_field( wp_unslash( $_GET['n'] ?? '' ) );
		$sess = self::session();
		if ( ! $id || ! $sess || ! wp_verify_nonce( $n, 'ofp_my_receipt_' . $type . '_' . $id ) ) {
			wp_die( 'This link has expired. Please log in again.', 'Not allowed', [ 'response' => 403 ] );
		}
		$only = self::only_client_id();
		$html = '';

		if ( $type === 'purchase' ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT py.*, pu.buyer_name, pu.buyer_email, pu.buyer_phone, pu.balance, pu.property_id, pu.client_id,
				        pr.title AS property_title, pr.listing_type,
				        c.business_name, c.owner_name, c.email AS client_email, c.phone AS client_phone, c.business_phone AS client_business_phone
				 FROM {$p}ofp_property_payments py
				 INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
				 LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
				 LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
				 WHERE py.id = %d LIMIT 1",
				$id
			) );
			$mine = $row && ( $sess['t'] === 'email'
				? strtolower( (string) $row->buyer_email ) === $sess['i']
				: substr( preg_replace( '/\D/', '', (string) $row->buyer_phone ), -10 ) === $sess['i'] );
			if ( ! $mine || $row->status !== 'successful' || ( $only && (int) $row->client_id !== $only ) ) {
				wp_die( 'Receipt not found.', 'Not found', [ 'response' => 404 ] );
			}
			$html = OFP_Property_Receipt_Generator::render_receipt(
				OFP_Property_Receipt_Generator::TYPE_PURCHASE,
				OFP_Property_Receipt_Generator::build_purchase_data( $row, (int) $row->purchase_id, $id )
			);
		} else {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT py.status, l.client_id, t.email AS t_email, t.phone AS t_phone
				 FROM {$p}ofp_property_lease_payments py
				 INNER JOIN {$p}ofp_property_leases l ON l.id = py.lease_id
				 INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
				 WHERE py.id = %d LIMIT 1",
				$id
			) );
			$mine = $row && ( $sess['t'] === 'email'
				? strtolower( (string) $row->t_email ) === $sess['i']
				: substr( preg_replace( '/\D/', '', (string) $row->t_phone ), -10 ) === $sess['i'] );
			if ( ! $mine || $row->status !== 'successful' || ( $only && (int) $row->client_id !== $only ) ) {
				wp_die( 'Receipt not found.', 'Not found', [ 'response' => 404 ] );
			}
			$saved = OFP_Property_Receipt_Generator::get_or_create_lease_receipt( $id );
			if ( $saved && ! empty( $saved['path'] ) ) {
				$uploads = wp_upload_dir();
				$file    = $uploads['basedir'] . '/' . $saved['path'];
				if ( is_file( $file ) ) {
					$html = (string) file_get_contents( $file );
				}
			}
		}

		if ( $html === '' ) {
			wp_die( 'Receipt not available.', 'Not found', [ 'response' => 404 ] );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline' https:; font-src https: data:" );
		echo $html; // Built from the receipt template, with every value escaped.
		exit;
	}

	// ------------------------------------------------------------------ page

	private static function render( ?array $sess, string $step, string $token, string $hint, string $msg, string $error ): void {
		global $wpdb;
		$p     = $wpdb->prefix;
		$solo  = class_exists( 'OFP_Brand' ) && OFP_Brand::is_solo_host();
		$brand = $solo ? OFP_White_Label::current_client() : null;
		$name  = OFP_Brand::name();
		$title = $solo ? 'My Payments' : 'My Payments | ' . $name;
		$money = static function ( $v ): string {
			return '₦' . number_format( (float) $v, 2 );
		};
		?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $title ); ?></title>
<?php if ( $solo ) { OFP_Brand::print_head( null ); } ?>
<style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f1f5f9;color:#0f172a}
.wrap{max-width:860px;margin:0 auto;padding:20px 14px 60px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;gap:10px;flex-wrap:wrap}
.brand{font-weight:800;font-size:18px}.brand img{max-height:40px;max-width:180px;display:block}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 6px}h2{font-size:17px;margin:0 0 4px}h3{font-size:14px;margin:16px 0 6px;color:#475569;text-transform:uppercase;letter-spacing:.04em}
p.m{color:#64748b;margin:0 0 12px;font-size:14px}
input[type=text],input[type=email]{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font-size:16px}
button,.btn{display:inline-block;background:#2563eb;color:#fff;border:0;border-radius:10px;padding:11px 18px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none}
.btn.alt{background:#e2e8f0;color:#0f172a}.btn.sm{padding:6px 12px;font-size:13px}
.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:14px}
.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:14px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:12px 0}
.stat{background:#f8fafc;border-radius:10px;padding:10px}.stat b{display:block;font-size:17px}.stat span{font-size:12px;color:#64748b}
.bar{height:8px;background:#e2e8f0;border-radius:6px;overflow:hidden;margin:6px 0 4px}.bar i{display:block;height:100%;background:#16a34a}
table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:top}th{color:#64748b;font-weight:600}
.tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;background:#e2e8f0}.tag.g{background:#dcfce7;color:#166534}.tag.y{background:#fef9c3;color:#854d0e}.tag.r{background:#fee2e2;color:#991b1b}
.scroll{overflow-x:auto}
@media(max-width:560px){.grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body><div class="wrap">
<div class="top">
	<div class="brand"><?php
	if ( $solo ) {
		echo ( $brand && ! empty( $brand->logo_url ) ) ? '<img src="' . esc_url( $brand->logo_url ) . '" alt="Logo">' : '';
	} else {
		echo esc_html( $name );
	}
	?></div>
	<?php if ( $sess ) : ?>
	<form method="post" style="margin:0;">
		<?php wp_nonce_field( 'ofp_my_payments', 'ofp_mp_nonce' ); ?>
		<input type="hidden" name="mp_action" value="logout">
		<button class="btn alt sm" type="submit">Log out</button>
	</form>
	<?php endif; ?>
</div>

<?php if ( $error ) : ?><div class="err"><?php echo esc_html( $error ); ?></div><?php endif; ?>
<?php if ( $msg ) : ?><div class="ok"><?php echo esc_html( $msg ); ?></div><?php endif; ?>

<?php if ( ! $sess ) : ?>
	<div class="card">
		<h1>My Payments</h1>
		<p class="m">See your payments, installments and receipts. No account needed.</p>
		<?php if ( $step === 'code' ) : ?>
			<form method="post" autocomplete="off">
				<?php wp_nonce_field( 'ofp_my_payments', 'ofp_mp_nonce' ); ?>
				<input type="hidden" name="mp_action" value="verify">
				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
				<div style="position:absolute;left:-9999px;" aria-hidden="true"><input type="text" name="ofp_website" tabindex="-1" autocomplete="off"></div>
				<p class="m">Enter the 6 digit code sent to <strong><?php echo esc_html( $hint ); ?></strong>. It works for 10 minutes.</p>
				<input type="text" name="code" inputmode="numeric" maxlength="6" placeholder="123456" required autofocus style="font-size:24px;letter-spacing:6px;text-align:center;margin-bottom:12px;">
				<button type="submit" style="width:100%;">View my payments</button>
			</form>
			<p class="m" style="margin-top:12px;"><a href="<?php echo esc_url( self::url() ); ?>">Start again</a></p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'ofp_my_payments', 'ofp_mp_nonce' ); ?>
				<input type="hidden" name="mp_action" value="request">
				<div style="position:absolute;left:-9999px;" aria-hidden="true"><input type="text" name="ofp_website" tabindex="-1" autocomplete="off"></div>
				<label style="display:block;font-weight:600;margin-bottom:6px;">Your email address or phone number</label>
				<input type="text" name="identity" placeholder="you@example.com or 0801 234 5678" required style="margin-bottom:12px;">
				<button type="submit" style="width:100%;">Send me a code</button>
			</form>
		<?php endif; ?>
	</div>
<?php else :
	$only      = self::only_client_id();
	$purchases = self::purchases( $sess['t'], $sess['i'], $only );
	$leases    = self::leases( $sess['t'], $sess['i'], $only );
	?>
	<div class="card">
		<h1>My Payments</h1>
		<p class="m" style="margin:0;">Showing records for <strong><?php echo esc_html( self::mask( $sess['t'], $sess['i'] ) ); ?></strong></p>
	</div>

	<?php if ( ! $purchases && ! $leases ) : ?>
		<div class="card"><p class="m" style="margin:0;">We could not find any payments for this contact.</p></div>
	<?php endif; ?>

	<?php foreach ( $purchases as $pu ) :
		$insts = (array) $wpdb->get_results( $wpdb->prepare( "SELECT installment_no, due_date, amount_due, amount_paid, status FROM {$p}ofp_property_installments WHERE purchase_id = %d ORDER BY installment_no ASC", (int) $pu->id ) );
		$pays  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, amount, status, payment_method, payer_reference, created_at FROM {$p}ofp_property_payments WHERE purchase_id = %d ORDER BY created_at DESC LIMIT 100", (int) $pu->id ) );
		$pct   = (float) $pu->total_price > 0 ? min( 100, round( (float) $pu->amount_paid / (float) $pu->total_price * 100 ) ) : 0;
		?>
		<div class="card">
			<h2><?php echo esc_html( $pu->property_title ?: 'Property purchase' ); ?></h2>
			<p class="m" style="margin:0;">Seller: <?php echo esc_html( $pu->business_name ?: $pu->owner_name ?: '' ); ?> · Status: <span class="tag"><?php echo esc_html( self::status_label( (string) $pu->status ) ); ?></span></p>
			<div class="grid">
				<div class="stat"><b><?php echo esc_html( $money( $pu->total_price ) ); ?></b><span>Total price</span></div>
				<div class="stat"><b><?php echo esc_html( $money( $pu->amount_paid ) ); ?></b><span>Paid so far</span></div>
				<div class="stat"><b><?php echo esc_html( $money( $pu->balance ) ); ?></b><span>Balance</span></div>
			</div>
			<div class="bar"><i style="width:<?php echo (int) $pct; ?>%"></i></div>
			<p class="m"><?php echo (int) $pct; ?>% paid</p>
			<?php if ( (float) $pu->balance > 0 && $pu->status === 'active' ) : ?>
				<p><a class="btn" href="<?php echo esc_url( OFP_Property_Manual_Payment::payment_link( (int) $pu->id ) ); ?>">Pay by bank transfer</a></p>
			<?php endif; ?>

			<?php if ( $insts ) : ?>
				<h3>Installment schedule</h3>
				<div class="scroll"><table><tr><th>#</th><th>Due date</th><th>Amount</th><th>Paid</th><th>Status</th></tr>
				<?php foreach ( $insts as $in ) : $cls = in_array( $in->status, [ 'paid' ], true ) ? 'g' : ( $in->status === 'overdue' ? 'r' : '' ); ?>
					<tr><td><?php echo (int) $in->installment_no; ?></td><td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $in->due_date ) ) ); ?></td><td><?php echo esc_html( $money( $in->amount_due ) ); ?></td><td><?php echo esc_html( $money( $in->amount_paid ) ); ?></td><td><span class="tag <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( self::status_label( (string) $in->status ) ); ?></span></td></tr>
				<?php endforeach; ?></table></div>
			<?php endif; ?>

			<h3>Payments</h3>
			<?php if ( ! $pays ) : ?><p class="m">No payments yet.</p><?php else : ?>
			<div class="scroll"><table><tr><th>Date</th><th>Amount</th><th>Reference</th><th>Status</th><th>Receipt</th></tr>
			<?php foreach ( $pays as $py ) : $cls = $py->status === 'successful' ? 'g' : ( $py->status === 'pending_verification' ? 'y' : 'r' ); ?>
				<tr><td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $py->created_at ) ) ); ?></td><td><?php echo esc_html( $money( $py->amount ) ); ?></td><td><?php echo esc_html( $py->payer_reference ?: '—' ); ?></td><td><span class="tag <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( self::status_label( (string) $py->status ) ); ?></span></td>
				<td><?php if ( $py->status === 'successful' ) : ?><a class="btn sm" target="_blank" rel="noopener" href="<?php echo esc_url( self::receipt_url( 'purchase', (int) $py->id ) ); ?>">Receipt</a><?php else : ?>—<?php endif; ?></td></tr>
			<?php endforeach; ?></table></div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<?php foreach ( $leases as $ls ) :
		$pays = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, amount, status, payment_method, payer_reference, created_at FROM {$p}ofp_property_lease_payments WHERE lease_id = %d ORDER BY created_at DESC LIMIT 100", (int) $ls->id ) );
		?>
		<div class="card">
			<h2><?php echo esc_html( $ls->property_title ?: 'Rental' ); ?></h2>
			<p class="m" style="margin:0;">Landlord: <?php echo esc_html( $ls->business_name ?: $ls->owner_name ?: '' ); ?> · Rent: <?php echo esc_html( $money( $ls->rent_amount ) ); ?> <?php echo esc_html( OFP_Property_Rent::period_label( $ls->rent_period, (int) $ls->custom_days ) ); ?></p>
			<div class="grid">
				<div class="stat"><b><?php echo esc_html( $money( $ls->amount_paid ) ); ?></b><span>Paid this cycle</span></div>
				<div class="stat"><b><?php echo esc_html( $money( $ls->balance ) ); ?></b><span>Balance</span></div>
				<div class="stat"><b><?php echo esc_html( $ls->end_date ? wp_date( 'M j, Y', strtotime( $ls->end_date ) ) : '—' ); ?></b><span>Cycle ends</span></div>
			</div>
			<?php if ( ! empty( $ls->offer_token ) ) : ?>
				<p><a class="btn" href="<?php echo esc_url( home_url( '/tenant-lease/' . rawurlencode( $ls->offer_token ) . '/' ) ); ?>">Open my lease and pay rent</a></p>
			<?php endif; ?>
			<h3>Payments</h3>
			<?php if ( ! $pays ) : ?><p class="m">No payments yet.</p><?php else : ?>
			<div class="scroll"><table><tr><th>Date</th><th>Amount</th><th>Reference</th><th>Status</th><th>Receipt</th></tr>
			<?php foreach ( $pays as $py ) : $cls = $py->status === 'successful' ? 'g' : ( $py->status === 'pending_verification' ? 'y' : 'r' ); ?>
				<tr><td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $py->created_at ) ) ); ?></td><td><?php echo esc_html( $money( $py->amount ) ); ?></td><td><?php echo esc_html( $py->payer_reference ?: '—' ); ?></td><td><span class="tag <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( self::status_label( (string) $py->status ) ); ?></span></td>
				<td><?php if ( $py->status === 'successful' ) : ?><a class="btn sm" target="_blank" rel="noopener" href="<?php echo esc_url( self::receipt_url( 'rent', (int) $py->id ) ); ?>">Receipt</a><?php else : ?>—<?php endif; ?></td></tr>
			<?php endforeach; ?></table></div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
<?php endif; ?>
</div></body></html>
<?php
	}
}
