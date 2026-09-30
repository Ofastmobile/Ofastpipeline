<?php
/**
 * Property manual payment flow.
 * Buyer-facing receipt submission only. Verification (admin + client) is
 * handled canonically by OFP_Property_Payment_Records — see that class.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Manual_Payment {

    const MAX_RECEIPT_SIZE = 5242880; // 5 MB
    const TOKEN_TTL = 2592000; // 30 days

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_routes' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_public_route' ] );
    }

    public static function register_routes(): void {
        add_rewrite_rule( '^property-pay/?$', 'index.php?ofp_manual_payment=1', 'top' );
    }

    public static function register_query_vars( array $vars ): array {
        $vars[] = 'ofp_manual_payment';
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

    public static function store_receipt( array $file ) {
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

    public static function delete_receipt( string $path ): void {
        if ( $path && is_file( $path ) ) @unlink( $path );
    }
}

OFP_Property_Manual_Payment::init();
