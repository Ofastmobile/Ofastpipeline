<?php
/**
 * Property buyer checkout flow.
 * Buyers do not need an OFast Pipeline account.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Checkout {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_routes' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_route' ] );
    }

    public static function register_routes(): void {
        add_rewrite_rule( '^property-checkout/?$', 'index.php?ofp_property_checkout=1', 'top' );
    }

    public static function register_query_vars( array $vars ): array {
        $vars[] = 'ofp_property_checkout';
        return $vars;
    }

    public static function handle_route(): void {
        if ( ! get_query_var( 'ofp_property_checkout' ) ) return;

        $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        $purchase_id = self::verify_purchase_token( $token );
        if ( ! $purchase_id ) {
            wp_die( esc_html__( 'This payment link is invalid or has expired.', 'ofast-pipeline' ) );
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $purchase = $wpdb->get_row( $wpdb->prepare(
            "SELECT pu.*, pr.title AS property_title FROM {$p}ofp_property_purchases pu LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id WHERE pu.id = %d LIMIT 1",
            $purchase_id
        ) );
        if ( ! $purchase ) wp_die( esc_html__( 'Purchase not found.', 'ofast-pipeline' ) );

        $installment_id = absint( $_GET['installment'] ?? 0 );
        $installment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_installments WHERE id = %d AND purchase_id = %d LIMIT 1",
            $installment_id,
            $purchase_id
        ) );

        if ( ! $installment ) {
            $installment = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}ofp_property_installments WHERE purchase_id = %d AND status IN ('scheduled','due','partially_paid','overdue') AND amount_paid < amount_due ORDER BY installment_no ASC LIMIT 1",
                $purchase_id
            ) );
        }

        if ( ! $installment ) wp_die( esc_html__( 'No outstanding installment is available for payment.', 'ofast-pipeline' ) );

        $configured = self::configured_gateways();
        $requested_gateway = sanitize_key( $_GET['gateway'] ?? '' );
        $gateway_slug = in_array( $requested_gateway, $configured, true ) ? $requested_gateway : ( in_array( 'paystack', $configured, true ) ? 'paystack' : ( $configured[0] ?? 'paystack' ) );

        $checkout_return = sanitize_key( $_GET['checkout'] ?? '' ) === 'return';
        $error = '';
        $notice = '';

        if ( $checkout_return ) {
            $notice = 'Your checkout has been returned to this page. Final payment confirmation comes from the gateway notification, so your payment may still be processing.';
        }

        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            if ( ! wp_verify_nonce( $_POST['checkout_nonce'] ?? '', 'ofp_property_checkout_' . $installment->id ) ) {
                $error = 'Security check failed. Please try again.';
            } elseif ( empty( $configured ) ) {
                $error = 'Online checkout is not currently available. Please use Manual Payment or contact the property owner.';
            } else {
                $amount = max( 0.0, (float) ( $_POST['amount'] ?? 0 ) );
                $requested = sanitize_key( $_POST['gateway'] ?? $gateway_slug );
                $gateway_slug = in_array( $requested, $configured, true ) ? $requested : ( in_array( 'paystack', $configured, true ) ? 'paystack' : $configured[0] );
                $outstanding = max( 0.0, (float) $installment->amount_due - (float) $installment->amount_paid );

                if ( $amount <= 0 || $amount > $outstanding + 0.00001 ) {
                    $error = 'Enter an amount up to the outstanding installment balance.';
                } elseif ( empty( $purchase->buyer_email ) || ! is_email( $purchase->buyer_email ) ) {
                    $error = 'A valid buyer email is required for online checkout.';
                } else {
                    $reference = OFP_Property_Payment_Context::generate_reference( (int) $installment->id );
                    $payment_id = OFP_Property_Payment_Record::create([
                        'purchase_id'       => (int) $purchase_id,
                        'payment_method'    => 'checkout',
                        'gateway'           => $gateway_slug,
                        'gateway_reference' => $reference,
                        'amount'            => $amount,
                        'status'            => 'pending_verification',
                        'payer_name'        => $purchase->buyer_name,
                        'payer_reference'   => $reference,
                    ]);

                    if ( is_wp_error( $payment_id ) ) {
                        $error = $payment_id->get_error_message();
                    } else {
                        $gateway = self::gateway( $gateway_slug );
                        $url = $gateway ? $gateway->initiate_transaction([
                            'client_id'    => (int) $purchase->client_id,
                            'amount'       => $amount,
                            'reference'    => $reference,
                            'email'        => $purchase->buyer_email,
                            'name'         => $purchase->buyer_name,
                            'phone'        => $purchase->buyer_phone,
                            'description'  => 'Property installment — ' . ( $purchase->property_title ?: 'Property Purchase' ),
                            'redirect_url' => add_query_arg([
                                'token'       => rawurlencode( $token ),
                                'installment' => (int) $installment->id,
                                'gateway'     => $gateway_slug,
                                'checkout'    => 'return',
                            ], home_url( '/property-checkout/' ) ),
                        ]) : null;

                        if ( $url ) {
                            wp_safe_redirect( $url );
                            exit;
                        }

                        $wpdb->update(
                            "{$p}ofp_property_payments",
                            [
                                'status'     => 'failed',
                                'note'       => 'Gateway initialization failed.',
                                'updated_at' => current_time( 'mysql' ),
                            ],
                            [ 'id' => (int) $payment_id ]
                        );
                        $error = 'Unable to start checkout. Please try again or use Manual Payment.';
                    }
                }
            }
        }

        $outstanding = max( 0.0, (float) $installment->amount_due - (float) $installment->amount_paid );
        ?>
        <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Property Checkout</title><?php wp_head(); ?><style>body{font-family:system-ui;background:#f5f7fb;color:#111827}.wrap{max-width:700px;margin:40px auto;padding:20px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:28px}.field{margin-bottom:16px}.field label{display:block;font-weight:600;margin-bottom:7px}.field input,.field select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #d1d5db;border-radius:8px}.btn{border:0;border-radius:8px;padding:12px 18px;font-weight:700;background:#2563eb;color:#fff;cursor:pointer}.error{padding:14px;border-radius:9px;background:#fef2f2;color:#991b1b;margin-bottom:16px}.notice{padding:14px;border-radius:9px;background:#eff6ff;color:#1e40af;margin-bottom:16px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:20px 0}.meta div{background:#f8fafc;border-radius:10px;padding:12px}.meta small{display:block;color:#64748b}.muted{color:#64748b;font-size:13px}</style></head><body><div class="wrap"><div class="card"><h1>Property Checkout</h1><p><?php echo esc_html( $purchase->property_title ?: 'Property Purchase' ); ?></p><?php if ( $error ) : ?><div class="error"><?php echo esc_html( $error ); ?></div><?php endif; ?><?php if ( $notice ) : ?><div class="notice"><?php echo esc_html( $notice ); ?></div><?php endif; ?><div class="meta"><div><small>Buyer</small><strong><?php echo esc_html( $purchase->buyer_name ); ?></strong></div><div><small>Outstanding installment</small><strong>NGN <?php echo esc_html( number_format( $outstanding, 2 ) ); ?></strong></div></div><?php if ( empty( $configured ) ) : ?><p class="muted">Online gateways are not currently configured. You can use the Manual Payment option from your secure payment link.</p><?php else : ?><form method="post"><?php wp_nonce_field( 'ofp_property_checkout_' . $installment->id, 'checkout_nonce' ); ?><div class="field"><label>Amount to pay</label><input type="number" name="amount" min="0.01" step="0.01" max="<?php echo esc_attr( max( 0.01, $outstanding ) ); ?>" value="<?php echo esc_attr( number_format( $outstanding, 2, '.', '' ) ); ?>" required></div><div class="field"><label>Payment gateway</label><select name="gateway"><?php foreach ( $configured as $slug ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $gateway_slug, $slug ); ?>><?php echo esc_html( 'paystack' === $slug ? 'Paystack' : 'Flutterwave' ); ?><?php echo 'paystack' === $slug ? ' — recommended' : ''; ?></option><?php endforeach; ?></select></div><p class="muted">You can choose either configured online gateway. The payment record will keep the actual gateway used.</p><button class="btn" type="submit">Continue to Secure Checkout</button></form><?php endif; ?></div></div><?php wp_footer(); ?></body></html>
        <?php exit;
    }

    private static function configured_gateways(): array {
        $configured = [];
        foreach ( [ 'paystack', 'flutterwave' ] as $slug ) {
            $class = 'OFP_Gateway_' . ucfirst( $slug );
            if ( ! class_exists( $class ) ) continue;
            $gateway = new $class();
            if ( method_exists( $gateway, 'is_configured' ) && $gateway->is_configured() && method_exists( $gateway, 'initiate_transaction' ) ) {
                $configured[] = $slug;
            }
        }
        return $configured;
    }

    private static function verify_purchase_token( string $token ): ?int {
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 3 ) return null;
        $purchase_id = absint( $parts[0] );
        $expires = absint( $parts[1] );
        $signature = sanitize_text_field( $parts[2] );
        $expected = hash_hmac( 'sha256', $purchase_id . '.' . $expires, wp_salt( 'auth' ) );
        if ( ! $purchase_id || ! $expires || $expires < time() || ! hash_equals( $expected, $signature ) ) return null;
        return $purchase_id;
    }

    private static function gateway( string $slug ): ?object {
        $class = 'OFP_Gateway_' . ucfirst( $slug );
        if ( ! class_exists( $class ) ) return null;
        $gateway = new $class();
        return method_exists( $gateway, 'initiate_transaction' ) ? $gateway : null;
    }
}
