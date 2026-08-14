<?php
/**
 * Buyer payment entry UI.
 * Adds the online-checkout option to the existing secure manual-payment page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Payment_Entry_UI {

    public static function init(): void {
        add_action( 'wp_footer', [ __CLASS__, 'render_online_option' ], 1001 );
    }

    public static function render_online_option(): void {
        $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        if ( 'property-pay' !== $path ) return;

        $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        if ( ! $token ) return;

        $parts = explode( '.', $token );
        if ( count( $parts ) !== 3 ) return;
        $purchase_id = absint( $parts[0] );
        $expires     = absint( $parts[1] );
        $signature   = sanitize_text_field( $parts[2] );
        $expected    = hash_hmac( 'sha256', $purchase_id . '.' . $expires, wp_salt( 'auth' ) );
        if ( ! $purchase_id || ! $expires || $expires < time() || ! hash_equals( $expected, $signature ) ) return;

        global $wpdb;
        $p = $wpdb->prefix;
        $installment = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, amount_due, amount_paid FROM {$p}ofp_property_installments
             WHERE purchase_id = %d
               AND status IN ('scheduled','due','partially_paid','overdue')
               AND amount_paid < amount_due
             ORDER BY installment_no ASC LIMIT 1",
            $purchase_id
        ) );
        if ( ! $installment ) return;

        $checkout_url = add_query_arg(
            [
                'token'       => rawurlencode( $token ),
                'installment' => (int) $installment->id,
            ],
            home_url( '/property-checkout/' )
        );
        ?>
        <style>
            .ofp-payment-online-option{margin:20px 0;padding:18px;border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc}
            .ofp-payment-online-option p{margin:0 0 12px;color:#4b5563}
            .ofp-payment-online-btn{display:inline-block;padding:11px 18px;border-radius:8px;background:#2563eb;color:#fff!important;text-decoration:none;font-weight:700}
            .ofp-payment-online-btn:hover{opacity:.92}
        </style>
        <script>
        (function(){
            function add(){
                var form=document.querySelector('form[enctype="multipart/form-data"]');
                if(!form || document.querySelector('[data-ofp-online-payment]')) return;
                var box=document.createElement('div');
                box.className='ofp-payment-online-option';
                box.setAttribute('data-ofp-online-payment','1');
                box.innerHTML=<?php echo wp_json_encode(
                    '<strong>Prefer to pay online?</strong>' .
                    '<p>Use Paystack or Flutterwave for secure online payment. You do not need an account.</p>' .
                    '<a class="ofp-payment-online-btn" href="' . esc_url( $checkout_url ) . '">Pay Online</a>'
                ); ?>;
                form.parentNode.insertBefore(box,form);
            }
            if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',add); else add();
        })();
        </script>
        <?php
    }
}

OFP_Property_Payment_Entry_UI::init();
