<?php
/**
 * Property manual payment flow.
 * Buyer-facing receipt submission + client/admin verification.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Manual_Payment {

    const MAX_RECEIPT_SIZE = 5242880; // 5 MB
    const TOKEN_TTL = 2592000; // 30 days

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_routes' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_public_route' ] );
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_menu' ] );
        add_action( 'admin_post_ofp_verify_property_payment', [ __CLASS__, 'handle_admin_verify' ] );
        add_action( 'admin_post_ofp_reject_property_payment', [ __CLASS__, 'handle_admin_reject' ] );
        add_action( 'wp_ajax_ofp_client_verify_property_payment', [ __CLASS__, 'handle_client_verify' ] );
        add_action( 'wp_ajax_ofp_client_reject_property_payment', [ __CLASS__, 'handle_client_reject' ] );
        add_action( 'wp_footer', [ __CLASS__, 'inject_client_verification_nav' ], 998 );
    }

    public static function register_routes(): void {
        add_rewrite_rule( '^property-pay/?$', 'index.php?ofp_manual_payment=1', 'top' );
        add_rewrite_rule( '^property-payment-verification/?$', 'index.php?ofp_property_payment_verification=1', 'top' );
    }

    public static function register_query_vars( array $vars ): array {
        $vars[] = 'ofp_manual_payment';
        $vars[] = 'ofp_property_payment_verification';
        return $vars;
    }

    public static function payment_link( int $purchase_id ): string {
        $token = self::make_token( $purchase_id );
        return add_query_arg( 'token', rawurlencode( $token ), home_url( '/property-pay/' ) );
    }

    private static function make_token( int $purchase_id ): string {
        $expires = time() + self::TOKEN_TTL;
        $payload = $purchase_id . '.' . $expires;
        $sig = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
        return $payload . '.' . $sig;
    }

    private static function verify_token( string $token ): ?int {
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 3 ) return null;
        $purchase_id = absint( $parts[0] );
        $expires = absint( $parts[1] );
        $sig = sanitize_text_field( $parts[2] );
        if ( ! $purchase_id || ! $expires || $expires < time() || ! hash_equals( hash_hmac( 'sha256', $purchase_id . '.' . $expires, wp_salt( 'auth' ) ), $sig ) ) return null;
        return $purchase_id;
    }

    public static function handle_public_route(): void {
        if ( get_query_var( 'ofp_manual_payment' ) ) {
            self::render_public_form();
            exit;
        }
        if ( get_query_var( 'ofp_property_payment_verification' ) ) {
            self::render_client_verification_page();
            exit;
        }
    }

    private static function render_public_form(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        $purchase_id = self::verify_token( $token );
        $purchase = $purchase_id ? $wpdb->get_row( $wpdb->prepare(
            "SELECT pu.*, pr.title AS property_title FROM {$p}ofp_property_purchases pu LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id WHERE pu.id = %d LIMIT 1",
            $purchase_id
        ) ) : null;
        $error = '';
        $success = '';

        if ( ! $purchase ) {
            $error = 'This payment link is invalid or has expired.';
        } elseif ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_manual_payment_submit'] ) ) {
            if ( ! wp_verify_nonce( $_POST['ofp_manual_payment_nonce'] ?? '', 'ofp_manual_payment_' . $purchase_id ) ) {
                $error = 'Security check failed. Please try again.';
            } else {
                $amount = max( 0.0, (float) ( $_POST['amount'] ?? 0 ) );
                $payer_name = sanitize_text_field( wp_unslash( $_POST['payer_name'] ?? '' ) );
                $payer_reference = sanitize_text_field( wp_unslash( $_POST['payer_reference'] ?? '' ) );
                $note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

                if ( $amount <= 0 ) {
                    $error = 'Enter a valid payment amount.';
                } elseif ( $amount > (float) $purchase->balance * 5 && (float) $purchase->balance > 0 ) {
                    $error = 'Please confirm the payment amount before submitting.';
                } elseif ( empty( $_FILES['receipt']['name'] ) || ! empty( $_FILES['receipt']['error'] ) ) {
                    $error = 'A payment receipt is required.';
                } else {
                    $receipt = self::store_receipt( $_FILES['receipt'] );
                    if ( is_wp_error( $receipt ) ) {
                        $error = $receipt->get_error_message();
                    } else {
                        $payment_id = OFP_Property_Payment_Record::create([
                            'purchase_id' => $purchase_id,
                            'payment_method' => 'manual',
                            'amount' => $amount,
                            'status' => 'pending_verification',
                            'payer_name' => $payer_name ?: $purchase->buyer_name,
                            'payer_reference' => $payer_reference,
                            'note' => $note,
                        ]);

                        if ( is_wp_error( $payment_id ) ) {
                            self::delete_receipt( $receipt['path'] );
                            $error = $payment_id->get_error_message();
                        } else {
                            $wpdb->update(
                                "{$p}ofp_property_payments",
                                [
                                    'receipt_path' => $receipt['path'],
                                    'receipt_mime' => $receipt['mime'],
                                    'receipt_size' => $receipt['size'],
                                    'updated_at' => current_time( 'mysql' ),
                                ],
                                [ 'id' => (int) $payment_id ]
                            );
                            do_action( 'ofp_property_manual_payment_submitted', (int) $payment_id, $purchase_id );
                            $success = 'Payment submitted successfully. It is awaiting verification.';
                            $purchase = $wpdb->get_row( $wpdb->prepare( "SELECT pu.*, pr.title AS property_title FROM {$p}ofp_property_purchases pu LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id WHERE pu.id = %d LIMIT 1", $purchase_id ) );
                        }
                    }
                }
            }
        }
        ?>
        <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manual Property Payment</title><?php wp_head(); ?><style>body{font-family:system-ui;background:#f5f7fb;color:#111827}.wrap{max-width:700px;margin:40px auto;padding:20px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:28px}.field{margin:0 0 16px}.field label{display:block;font-weight:600;margin-bottom:7px}.field input,.field textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #d1d5db;border-radius:8px}.btn{border:0;border-radius:8px;padding:12px 18px;font-weight:700;background:#2563eb;color:#fff;cursor:pointer}.alert{padding:14px;border-radius:9px;margin-bottom:16px}.err{background:#fef2f2;color:#991b1b}.ok{background:#ecfdf5;color:#065f46}.meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:20px 0}.meta div{background:#f8fafc;border-radius:10px;padding:12px}.meta small{display:block;color:#64748b}</style></head><body><div class="wrap"><div class="card"><h1>Submit Manual Payment</h1><p><?php echo esc_html( $purchase ? $purchase->property_title : 'Property purchase' ); ?></p><?php if ( $error ) : ?><div class="alert err"><?php echo esc_html( $error ); ?></div><?php endif; ?><?php if ( $success ) : ?><div class="alert ok"><?php echo esc_html( $success ); ?></div><?php endif; ?><?php if ( $purchase ) : ?><div class="meta"><div><small>Buyer</small><strong><?php echo esc_html( $purchase->buyer_name ); ?></strong></div><div><small>Outstanding balance</small><strong>NGN <?php echo esc_html( number_format( (float) $purchase->balance, 2 ) ); ?></strong></div></div><form method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'ofp_manual_payment_' . $purchase_id, 'ofp_manual_payment_nonce' ); ?><input type="hidden" name="ofp_manual_payment_submit" value="1"><div class="field"><label>Amount paid</label><input type="number" name="amount" min="0.01" step="0.01" required></div><div class="field"><label>Payer name</label><input type="text" name="payer_name" value="<?php echo esc_attr( $purchase->buyer_name ); ?>"></div><div class="field"><label>Bank/payment reference</label><input type="text" name="payer_reference"></div><div class="field"><label>Receipt</label><input type="file" name="receipt" accept="image/jpeg,image/png,application/pdf" required><small>JPG, PNG or PDF. Maximum 5 MB.</small></div><div class="field"><label>Note</label><textarea name="note" rows="4"></textarea></div><button class="btn" type="submit">Submit Payment</button></form><?php endif; ?></div></div><?php wp_footer(); ?></body></html>
        <?php
    }

    private static function store_receipt( array $file ) {
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) return new WP_Error( 'receipt_invalid', 'Invalid receipt upload.' );
        if ( (int) $file['size'] > self::MAX_RECEIPT_SIZE ) return new WP_Error( 'receipt_too_large', 'Receipt must be 5 MB or smaller.' );
        $check = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ), [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf' ] );
        if ( empty( $check['type'] ) || ! in_array( $check['type'], [ 'image/jpeg', 'image/png', 'application/pdf' ], true ) ) return new WP_Error( 'receipt_type', 'Only JPG, PNG or PDF receipts are allowed.' );
        $uploads = wp_upload_dir();
        $dir = trailingslashit( $uploads['basedir'] ) . 'ofp-private-receipts';
        if ( ! wp_mkdir_p( $dir ) ) return new WP_Error( 'receipt_dir', 'Could not create secure receipt storage.' );
        $name = wp_unique_filename( $dir, wp_generate_uuid4() . '.' . strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) ) );
        $destination = trailingslashit( $dir ) . $name;
        if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) return new WP_Error( 'receipt_store', 'Could not store the receipt.' );
        return [ 'path' => $destination, 'mime' => $check['type'], 'size' => (int) $file['size'] ];
    }

    private static function delete_receipt( string $path ): void {
        if ( $path && is_file( $path ) ) @unlink( $path );
    }

    private static function can_manage_client_payment( int $payment_id, $client ): bool {
        global $wpdb;
        $p = $wpdb->prefix;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT py.id FROM {$p}ofp_property_payments py INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id WHERE py.id = %d AND pu.client_id = %d LIMIT 1",
            $payment_id,
            (int) $client->id
        ) );
    }

    public static function register_admin_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        add_submenu_page( 'edit.php?post_type=ofp_property', 'Property Payments', 'Payments', 'manage_options', 'ofp-property-payments', [ __CLASS__, 'render_admin_payments' ] );
    }

    public static function render_admin_payments(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $payments = $wpdb->get_results( "SELECT py.*, pu.buyer_name, pu.buyer_phone, pu.client_id, pr.title AS property_title, c.business_name FROM {$p}ofp_property_payments py INNER JOIN {$p}ofp_property_purchases pu ON pu.id=py.purchase_id LEFT JOIN {$p}ofp_properties pr ON pr.id=pu.property_id LEFT JOIN {$p}ofp_clients c ON c.id=pu.client_id ORDER BY py.created_at DESC, py.id DESC LIMIT 250" );
        ?><div class="wrap"><h1>Property Payments</h1><p>Review property payment records. Only pending manual payments may be verified or rejected.</p><div style="overflow-x:auto;"><table class="widefat striped" style="min-width:1300px"><thead><tr><th>ID</th><th>Buyer</th><th>Property</th><th>Owner</th><th>Amount</th><th>Method</th><th>Gateway</th><th>Reference</th><th>Status</th><th>Receipt</th><th>Date</th><th>Action</th></tr></thead><tbody><?php foreach ( $payments as $payment ) : ?><tr><td>#<?php echo esc_html( $payment->id ); ?></td><td><?php echo esc_html( $payment->buyer_name ); ?><br><small><?php echo esc_html( $payment->buyer_phone ); ?></small></td><td><?php echo esc_html( $payment->property_title ); ?></td><td><?php echo esc_html( $payment->business_name ?: 'Platform' ); ?></td><td>NGN <?php echo esc_html( number_format( (float) $payment->amount, 2 ) ); ?></td><td><?php echo esc_html( ucfirst( $payment->payment_method ) ); ?></td><td><?php echo esc_html( $payment->gateway ?: '—' ); ?></td><td><code><?php echo esc_html( $payment->gateway_reference ?: ( $payment->payer_reference ?: '—' ) ); ?></code></td><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $payment->status ) ) ); ?></td><td><?php echo $payment->receipt_path ? '<span title="Private stored receipt">Available</span>' : '—'; ?></td><td><?php echo esc_html( $payment->created_at ); ?></td><td><?php if ( 'pending_verification' === $payment->status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"><?php wp_nonce_field( 'ofp_verify_payment_' . $payment->id ); ?><input type="hidden" name="action" value="ofp_verify_property_payment"><input type="hidden" name="payment_id" value="<?php echo esc_attr( $payment->id ); ?>"><button class="button button-primary" type="submit">Verify</button></form> <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"><?php wp_nonce_field( 'ofp_reject_payment_' . $payment->id ); ?><input type="hidden" name="action" value="ofp_reject_property_payment"><input type="hidden" name="payment_id" value="<?php echo esc_attr( $payment->id ); ?>"><button class="button" type="submit">Reject</button></form><?php else : ?>—<?php endif; ?></td></tr><?php endforeach; if ( empty( $payments ) ) : ?><tr><td colspan="12">No property payments yet.</td></tr><?php endif; ?></tbody></table></div></div><?php
    }

    public static function handle_admin_verify(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        $id = absint( $_POST['payment_id'] ?? 0 );
        check_admin_referer( 'ofp_verify_payment_' . $id );
        OFP_Property_Payment_Record::success( $id, get_current_user_id() );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-payments' ) );
        exit;
    }

    public static function handle_admin_reject(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        $id = absint( $_POST['payment_id'] ?? 0 );
        check_admin_referer( 'ofp_reject_payment_' . $id );
        OFP_Property_Payment_Record::reject( $id, get_current_user_id(), 'Rejected from property payment review.' );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-payments' ) );
        exit;
    }

    private static function render_client_verification_page(): void {
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client || ! OFP_Subscription::has_active( 'listing', $client->id ) ) { wp_safe_redirect( home_url( '/dashboard' ) ); exit; }
        global $wpdb;
        $p = $wpdb->prefix;
        $payments = $wpdb->get_results( $wpdb->prepare( "SELECT py.*, pu.buyer_name, pr.title AS property_title FROM {$p}ofp_property_payments py INNER JOIN {$p}ofp_property_purchases pu ON pu.id=py.purchase_id LEFT JOIN {$p}ofp_properties pr ON pr.id=pu.property_id WHERE pu.client_id=%d ORDER BY py.created_at DESC, py.id DESC LIMIT 250", (int) $client->id ) );
        ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment Verification</title><?php wp_head(); ?><link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>"></head><body class="ofp-portal-body"><?php include OFP_PATH . 'public/templates/partials/nav.php'; ?><div class="ofp-container"><div style="padding-bottom:60px"><h1 style="font-size:22px;font-weight:700;margin:0 0 24px">Payment Verification</h1><div class="ofp-card"><p class="ofp-hint">Review manual payments submitted by buyers for your properties.</p><div style="overflow-x:auto"><table class="widefat striped" style="min-width:1100px"><thead><tr><th>ID</th><th>Buyer</th><th>Property</th><th>Amount</th><th>Reference</th><th>Status</th><th>Receipt</th><th>Action</th></tr></thead><tbody><?php foreach ( $payments as $payment ) : ?><tr><td>#<?php echo esc_html( $payment->id ); ?></td><td><?php echo esc_html( $payment->buyer_name ); ?></td><td><?php echo esc_html( $payment->property_title ); ?></td><td>NGN <?php echo esc_html( number_format( (float) $payment->amount, 2 ) ); ?></td><td><?php echo esc_html( $payment->payer_reference ?: '—' ); ?></td><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $payment->status ) ) ); ?></td><td><?php echo $payment->receipt_path ? 'Available' : '—'; ?></td><td><?php if ( 'pending_verification' === $payment->status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" style="display:inline"><input type="hidden" name="action" value="ofp_client_verify_property_payment"><input type="hidden" name="payment_id" value="<?php echo esc_attr( $payment->id ); ?>"><input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'ofp_client_payment_' . $payment->id ) ); ?>"><button class="button button-primary" type="submit">Verify</button></form> <form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" style="display:inline"><input type="hidden" name="action" value="ofp_client_reject_property_payment"><input type="hidden" name="payment_id" value="<?php echo esc_attr( $payment->id ); ?>"><input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'ofp_client_payment_' . $payment->id ) ); ?>"><button class="button" type="submit">Reject</button></form><?php else : ?>—<?php endif; ?></td></tr><?php endforeach; if ( empty( $payments ) ) : ?><tr><td colspan="8">No property payments yet.</td></tr><?php endif; ?></tbody></table></div></div></div></div><?php wp_footer(); ?></body></html><?php
    }

    private static function handle_client_action( int $payment_id, bool $approve ): void {
        check_ajax_referer( 'ofp_client_payment_' . $payment_id, 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client || ! OFP_Subscription::has_active( 'listing', $client->id ) || ! self::can_manage_client_payment( $payment_id, $client ) ) wp_send_json_error( 'Unauthorized', 403 );
        $result = $approve ? OFP_Property_Payment_Record::success( $payment_id, 0 ) : OFP_Property_Payment_Record::reject( $payment_id, 0, 'Rejected by property client.' );
        wp_send_json_success( [ 'result' => $result ] );
    }

    public static function handle_client_verify(): void { self::handle_client_action( absint( $_POST['payment_id'] ?? 0 ), true ); }
    public static function handle_client_reject(): void { self::handle_client_action( absint( $_POST['payment_id'] ?? 0 ), false ); }

    public static function inject_client_verification_nav(): void {
        if ( is_admin() ) return;
        $client = OFP_Auth::current_client();
        if ( ! $client || ! OFP_Subscription::has_active( 'listing', $client->id ) ) return;
        ?><script>(function(){function add(){var list=document.querySelector('.ofp-sidebar-nav ul');if(!list||list.querySelector('[data-ofp-nav-marker="payment-verification"]'))return;var items=list.querySelectorAll(':scope>li');var source=items[0];if(!source)return;var li=source.cloneNode(true),a=li.querySelector('a');if(!a)return;a.href=<?php echo wp_json_encode( home_url('/property-payment-verification/') ); ?>;a.removeAttribute('aria-disabled');a.classList.remove('locked');a.setAttribute('data-ofp-nav-marker','payment-verification');var icon=a.querySelector('.ofp-nav-icon');if(icon)icon.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>';var label=a.querySelector('.ofp-nav-label');if(label)label.textContent='Payment Verification';list.appendChild(li)}if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',add);else add()})();</script><?php
    }
}

OFP_Property_Manual_Payment::init();
