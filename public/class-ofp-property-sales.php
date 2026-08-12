<?php
/**
 * Property sales portal routes.
 *
 * Kept separate from the existing client portal router so the first
 * installment UI can be introduced without rewriting the established routes.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_Sales {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_route' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_route' ] );
    }

    public static function register_route(): void {
        add_rewrite_rule(
            '^property-sales/?$',
            'index.php?ofp_property_sales=1',
            'top'
        );
    }

    public static function handle_route(): void {
        if ( '1' !== get_query_var( 'ofp_property_sales' ) ) {
            return;
        }

        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();

        if ( ! $client ) {
            return;
        }

        if ( ! OFP_Subscription::has_active( 'listing', $client->id ) ) {
            wp_safe_redirect( home_url( '/dashboard' ) );
            exit;
        }

        $template = OFP_PATH . 'public/templates/property-sales.php';
        if ( file_exists( $template ) ) {
            require $template;
            exit;
        }

        wp_die( esc_html__( 'Property sales page is unavailable.', 'ofast-pipeline' ) );
    }
}

add_filter( 'query_vars', function ( array $vars ): array {
    $vars[] = 'ofp_property_sales';
    return $vars;
} );
