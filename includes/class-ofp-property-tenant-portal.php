<?php
/** Secure, no-login lease acceptance and payment page for tenants. */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Tenant_Portal {
    const ROUTE_VERSION = '1';

    public static function init(): void {
        if ( get_option( 'ofp_tenant_lease_route_version' ) !== self::ROUTE_VERSION ) {
            update_option( 'ofp_tenant_lease_route_version', self::ROUTE_VERSION, false );
            update_option( 'ofp_flush_rewrite_rules', '1' );
        }
        add_action( 'init', [ __CLASS__, 'register_route' ] );
        add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_route' ] );
    }

    public static function register_route(): void {
        add_rewrite_rule( '^tenant-lease/([^/]+)/?$', 'index.php?ofp_tenant_lease=$matches[1]', 'top' );
    }

    public static function query_vars( array $vars ): array {
        $vars[] = 'ofp_tenant_lease';
        return $vars;
    }

    public static function handle_route(): void {
        $token = sanitize_text_field( get_query_var( 'ofp_tenant_lease', '' ) );
        if ( ! $token ) return;
        self::render( $token );
        exit;
    }

    private static function render( string $token ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $lease = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.*, t.full_name, t.email, pr.title AS property_title, c.business_name
             FROM {$p}ofp_property_leases l
             INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
             LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
             WHERE l.offer_token_hash = %s LIMIT 1",
            hash( 'sha256', $token )
        ) );
        $error = '';
        $notice = '';
        if ( ! $lease ) $error = 'This lease link is invalid.';
        if ( $lease && $lease->status === 'pending_offer' && $lease->offer_expires_at && strtotime( $lease->offer_expires_at ) < time() ) $error = 'This lease offer has expired.';

        if ( $lease && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_accept_lease'] ) ) {
            if ( ! wp_verify_nonce( $_POST['ofp_tenant_lease_nonce'] ?? '', 'ofp_tenant_lease_' . $lease->id ) ) {
                $error = 'Security check failed. Please try again.';
            } elseif ( empty( $_POST['accept_terms'] ) ) {
                $error = 'Please accept the lease terms before continuing.';
            } else {
                $accepted = OFP_Property_Rent::accept_lease_offer( $token, $_SERVER['REMOTE_ADDR'] ?? '' );
                if ( is_wp_error( $accepted ) ) $error = $accepted->get_error_message();
                else { $notice = 'Lease accepted. Your first verified payment activates the tenancy.'; $lease = $wpdb->get_row( $wpdb->prepare( "SELECT l.*, t.full_name, t.email, pr.title AS property_title, c.business_name FROM {$p}ofp_property_leases l INNER JOIN {$p}ofp_property_tenants t ON t.id=l.tenant_id LEFT JOIN {$p}ofp_properties pr ON pr.id=l.property_id LEFT JOIN {$p}ofp_clients c ON c.id=l.client_id WHERE l.id=%d", $lease->id ) ); }
            }
        }
        if ( $lease && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_tenant_checkout'] ) && $lease->accepted_at ) {
            if ( ! wp_verify_nonce( $_POST['ofp_tenant_checkout_nonce'] ?? '', 'ofp_tenant_checkout_' . $lease->id ) ) $error = 'Security check failed. Please try again.';
            else {
                $checkout = OFP_Property_Lease_Payment::initiate_checkout( (int) $lease->id, (float) $_POST['amount'] );
                if ( is_wp_error( $checkout ) ) $error = $checkout->get_error_message(); else { wp_redirect( $checkout ); exit; }
            }
        }
        $va = $lease && $lease->accepted_at ? OFP_Property_Lease_Payment::ensure_virtual_account( (int) $lease->id ) : null;
        ?>
        <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lease offer</title><?php wp_head(); ?><style>body{font-family:system-ui;background:#f5f7fb;color:#172033;margin:0}.wrap{max-width:700px;margin:40px auto;padding:20px}.card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:20px 0}.meta div{background:#f8fafc;border-radius:10px;padding:12px}.meta small{display:block;color:#64748b}.btn{border:0;border-radius:8px;padding:12px 18px;font-weight:700;background:#2563eb;color:#fff;cursor:pointer}.field{margin:16px 0}.field input{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:8px}.alert{padding:14px;border-radius:9px;margin:16px 0}.err{background:#fef2f2;color:#991b1b}.ok{background:#ecfdf5;color:#065f46}.terms{padding:14px;background:#f8fafc;border-radius:8px;white-space:pre-wrap;line-height:1.5}.bank{padding:15px;background:#eff6ff;border-radius:10px;margin-top:16px}</style></head><body><main class="wrap"><section class="card"><p style="color:#64748b;margin-top:0"><?php echo esc_html( $lease->business_name ?? 'Property manager' ); ?></p><h1 style="margin:0">Lease offer</h1><?php if($error): ?><div class="alert err"><?php echo esc_html($error); ?></div><?php elseif($lease): ?><div class="meta"><div><small>Tenant</small><strong><?php echo esc_html($lease->full_name); ?></strong></div><div><small>Property</small><strong><?php echo esc_html($lease->property_title ?: 'Property'); ?></strong></div><div><small>Rent period</small><strong><?php echo esc_html(OFP_Property_Rent::period_label($lease->rent_period,(int)$lease->custom_days)); ?></strong></div><div><small>Cycle amount</small><strong>NGN <?php echo esc_html(number_format((float)$lease->rent_amount,2)); ?></strong></div></div><?php if($notice): ?><div class="alert ok"><?php echo esc_html($notice); ?></div><?php endif; ?><?php if(!$lease->accepted_at): ?><div class="terms"><?php echo esc_html($lease->terms_text ?: 'No additional lease terms were provided.'); ?></div><form method="post"><div class="field"><label><input type="checkbox" name="accept_terms" value="1"> I have read and accept these lease terms.</label></div><?php wp_nonce_field('ofp_tenant_lease_'.$lease->id,'ofp_tenant_lease_nonce'); ?><button class="btn" name="ofp_accept_lease" value="1">Accept lease</button></form><?php else: ?><p>Your offer is accepted. Outstanding for this cycle: <strong>NGN <?php echo esc_html(number_format((float)$lease->balance,2)); ?></strong>.</p><?php if((float)$lease->balance>0 && $lease->email): ?><form method="post"><div class="field"><label>Pay online (NGN)</label><input type="number" name="amount" min="0.01" max="<?php echo esc_attr($lease->balance); ?>" step="0.01" value="<?php echo esc_attr($lease->balance); ?>"></div><?php wp_nonce_field('ofp_tenant_checkout_'.$lease->id,'ofp_tenant_checkout_nonce'); ?><button class="btn" name="ofp_tenant_checkout" value="1">Pay securely with Paystack</button></form><?php endif; ?><?php if($va): ?><div class="bank"><strong>Bank transfer</strong><br><?php echo esc_html($va->bank_name); ?><br><strong style="font-size:22px"><?php echo esc_html($va->account_number); ?></strong><br><small>Transfers to this account are automatically applied to this lease.</small></div><?php endif; ?><p style="color:#64748b;font-size:14px">If you paid cash, ask your property manager to record the payment.</p><?php endif; ?><?php endif; ?></section></main><?php wp_footer(); ?></body></html>
        <?php
    }
}
