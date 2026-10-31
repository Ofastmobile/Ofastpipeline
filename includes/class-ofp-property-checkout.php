<?php
/**
 * Old buyer checkout links.
 * Buyers pay the owner's own bank account, so every link goes to the bank
 * transfer page. Old emailed links to /property-checkout/ still work: they
 * are sent on to the bank transfer page.
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

        $old_token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        wp_safe_redirect( add_query_arg( 'token', rawurlencode( $old_token ), home_url( '/property-pay/' ) ) );
        exit;
    }

    /**
     * Link a buyer opens to pay. It always goes to the bank transfer page.
     * The $ttl argument is kept so older callers keep working.
     */
    public static function payment_link( int $purchase_id, int $ttl = 2592000 ): string {
        if ( class_exists( 'OFP_Property_Manual_Payment' ) ) {
            return OFP_Property_Manual_Payment::payment_link( $purchase_id );
        }
        return home_url( '/property-pay/' );
    }
}
