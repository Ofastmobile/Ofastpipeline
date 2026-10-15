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

                $dup = ( $payer_reference !== '' ) ? (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_property_payments WHERE purchase_id = %d AND payer_reference = %s AND status IN ('pending_verification','successful')",
                    $purchase_id,
                    $payer_reference
                ) ) : 0;
                $open = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_property_payments WHERE purchase_id = %d AND status = 'pending_verification'",
                    $purchase_id
                ) );

                if ( (float) $purchase->balance <= 0 ) {
                    $error = 'This purchase is already fully paid.';
                } elseif ( $amount <= 0 ) {
                    $error = 'Enter a valid payment amount.';
                } elseif ( $amount > (float) $purchase->balance + 0.01 ) {
                    $error = 'The amount is more than your remaining balance of NGN ' . number_format( (float) $purchase->balance, 2 ) . '.';
                } elseif ( $dup ) {
                    $error = 'You already sent a payment notice with that reference.';
                } elseif ( $open >= 5 ) {
                    $error = 'You have several payments waiting for approval. Please wait for them to be confirmed.';
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
        <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manual Property Payment</title><?php wp_head(); ?><style>body{font-family:system-ui;background:#f5f7fb;color:#111827}.wrap{max-width:700px;margin:40px auto;padding:20px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:28px}.field{margin:0 0 16px}.field label{display:block;font-weight:600;margin-bottom:7px}.field input,.field textarea{width:100%;box-sizing:border-box;padding:11px;border:1px solid #d1d5db;border-radius:8px}.btn{border:0;border-radius:8px;padding:12px 18px;font-weight:700;background:#2563eb;color:#fff;cursor:pointer}.alert{padding:14px;border-radius:9px;margin-bottom:16px}.err{background:#fef2f2;color:#991b1b}.ok{background:#ecfdf5;color:#065f46}.meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:20px 0}.meta div{background:#f8fafc;border-radius:10px;padding:12px}.meta small{display:block;color:#64748b}</style></head><body><div class="wrap"><div class="card"><h1>Submit Manual Payment</h1><p><?php echo esc_html( $purchase ? $purchase->property_title : 'Property purchase' ); ?></p><?php if ( $error ) : ?><div class="alert err"><?php echo esc_html( $error ); ?></div><?php endif; ?><?php if ( $success ) : ?><div class="alert ok"><?php echo esc_html( $success ); ?></div><?php endif; ?><?php if ( $purchase ) : ?><div class="meta"><div><small>Buyer</small><strong><?php echo esc_html( $purchase->buyer_name ); ?></strong></div><div><small>Outstanding balance</small><strong>NGN <?php echo esc_html( number_format( (float) $purchase->balance, 2 ) ); ?></strong></div></div><?php if ( $purchase ) :
    $ofp_bank = OFP_Bank_Account::for_purchase( $purchase );
    $ofp_ref  = OFP_Bank_Account::purchase_reference( (int) $purchase_id );
    // What is due now: the initial payment if it is still unpaid, otherwise the next installment.
    $ofp_ip   = $GLOBALS['wpdb']->prefix;
    $ofp_next = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare(
        "SELECT * FROM {$ofp_ip}ofp_property_installments WHERE purchase_id = %d AND status IN ('scheduled','due','partially_paid','overdue') AND amount_paid < amount_due ORDER BY installment_no ASC LIMIT 1",
        (int) $purchase_id
    ) );
    $ofp_count   = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$ofp_ip}ofp_property_installments WHERE purchase_id = %d", (int) $purchase_id ) );
    $ofp_balance = max( 0.0, (float) $purchase->balance );
    $ofp_due     = $ofp_next ? min( $ofp_balance, max( 0.0, (float) $ofp_next->amount_due - (float) $ofp_next->amount_paid ) ) : 0.0;
    $ofp_default = $ofp_due > 0 ? $ofp_due : $ofp_balance;
    if ( $ofp_next ) {
        $ofp_is_initial = ( (float) $purchase->initial_payment > 0 && (int) $ofp_next->installment_no === 1 );
        $ofp_label      = $ofp_is_initial ? 'Initial payment' : 'Installment ' . (int) $ofp_next->installment_no . ' of ' . $ofp_count;
        $ofp_overdue    = ( $ofp_next->status === 'overdue' ) || ( strtotime( $ofp_next->due_date ) < strtotime( current_time( 'Y-m-d' ) ) );
    }
?>
<?php if ( $ofp_balance > 0 ) : ?>
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:16px;margin:0 0 18px;">
<strong style="display:block;margin-bottom:8px;">What you need to pay</strong>
<?php if ( $ofp_next && $ofp_due > 0 ) : ?>
<div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline;"><div><?php echo esc_html( $ofp_label ); ?><br><small style="color:#64748b;">Due <?php echo esc_html( wp_date( 'M j, Y', strtotime( $ofp_next->due_date ) ) ); ?><?php if ( $ofp_overdue ) : ?> <strong style="color:#b91c1c;">(overdue)</strong><?php endif; ?></small></div><strong style="font-size:22px;">NGN <?php echo esc_html( number_format( $ofp_due, 2 ) ); ?></strong></div>
<?php endif; ?>
<div style="margin-top:8px;color:#475569;">Remaining balance: <strong>NGN <?php echo esc_html( number_format( $ofp_balance, 2 ) ); ?></strong></div>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
<?php if ( $ofp_next && $ofp_due > 0 && $ofp_due < $ofp_balance - 0.01 ) : ?>
<button type="button" onclick="document.getElementById('ofp-pay-amount').value='<?php echo esc_js( number_format( $ofp_due, 2, '.', '' ) ); ?>';" style="padding:9px 14px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-weight:600;cursor:pointer;">Pay amount due (NGN <?php echo esc_html( number_format( $ofp_due, 2 ) ); ?>)</button>
<?php endif; ?>
<button type="button" onclick="document.getElementById('ofp-pay-amount').value='<?php echo esc_js( number_format( $ofp_balance, 2, '.', '' ) ); ?>';" style="padding:9px 14px;border:1px solid #2563eb;border-radius:8px;background:#fff;color:#2563eb;font-weight:600;cursor:pointer;">Pay full balance (NGN <?php echo esc_html( number_format( $ofp_balance, 2 ) ); ?>)</button>
</div>
<small style="display:block;margin-top:8px;color:#64748b;">The amount is filled in for you below. You can change it. Anything above the amount due goes to your next installments.</small>
</div>
<?php endif; ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:16px;margin:0 0 18px;">
<strong style="display:block;margin-bottom:8px;">Step 1. Send the money by bank transfer</strong>
<?php if ( $ofp_bank ) : ?>
<div style="line-height:1.9;">Bank: <strong><?php echo esc_html( $ofp_bank->bank_name ); ?></strong><br>Account number: <strong id="ofp-acct-no" style="font-size:20px;letter-spacing:1px;"><?php echo esc_html( $ofp_bank->account_number ); ?></strong> <button type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?php echo esc_js( $ofp_bank->account_number ); ?>');this.textContent='Copied';" style="margin-left:6px;padding:3px 10px;border:1px solid #16a34a;background:#fff;border-radius:6px;cursor:pointer;">Copy</button><br>Account name: <strong><?php echo esc_html( $ofp_bank->account_name ); ?></strong><br>Write this in the transfer note: <strong><?php echo esc_html( $ofp_ref ); ?></strong></div>
<p style="margin:10px 0 0;font-size:13px;color:#166534;">Send only to this account. Then complete Step 2 below so the owner can confirm your payment.</p>
<p style="margin:6px 0 0;font-size:13px;"><a href="<?php echo esc_url( OFP_Property_My_Payments::url() ); ?>">See all my payments and receipts</a></p>
<?php else : ?>
<p style="margin:0;color:#b45309;">The owner has not added bank details yet. Please contact them for the account before you pay.</p>
<?php endif; ?>
</div>
<strong style="display:block;margin-bottom:8px;">Step 2. Tell us you have paid</strong>
<?php endif; ?><form method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'ofp_manual_payment_' . $purchase_id, 'ofp_manual_payment_nonce' ); ?><input type="hidden" name="ofp_manual_payment_submit" value="1"><div class="field"><label>Amount paid</label><input type="number" id="ofp-pay-amount" name="amount" min="0.01" step="0.01" <?php if ( isset( $ofp_default ) ) : ?>max="<?php echo esc_attr( number_format( $ofp_balance, 2, '.', '' ) ); ?>" value="<?php echo esc_attr( number_format( $ofp_default, 2, '.', '' ) ); ?>"<?php endif; ?> required></div><div class="field"><label>Payer name</label><input type="text" name="payer_name" value="<?php echo esc_attr( $purchase->buyer_name ); ?>"></div><div class="field"><label>Bank/payment reference</label><input type="text" name="payer_reference"></div><div class="field"><label>Receipt</label><input type="file" name="receipt" accept="image/jpeg,image/png,application/pdf" required><small>JPG, PNG or PDF. Maximum 5 MB.</small></div><div class="field"><label>Note</label><textarea name="note" rows="4"></textarea></div><button class="btn" type="submit">Submit Payment</button></form><?php endif; ?></div></div><?php wp_footer(); ?></body></html>
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
        self::protect_receipt_dir( $dir );
        $name = wp_unique_filename( $dir, wp_generate_uuid4() . '.' . strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) ) );
        $destination = trailingslashit( $dir ) . $name;
        if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) return new WP_Error( 'receipt_store', 'Could not store the receipt.' );
        return [ 'path' => $destination, 'mime' => $check['type'], 'size' => (int) $file['size'] ];
    }

    public static function delete_receipt( string $path ): void {
        if ( $path && is_file( $path ) ) @unlink( $path );
    }

    public static function protect_receipt_dir( string $dir ): void {
        $htaccess = trailingslashit( $dir ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "# Deny all direct web access to private receipts\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\nOptions -Indexes\n" );
        }
        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\nhttp_response_code(403);\nexit;\n" );
        }
    }
}

OFP_Property_Manual_Payment::init();
