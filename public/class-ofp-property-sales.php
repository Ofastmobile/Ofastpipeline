<?php
/**
 * Property sales / buyer offer routes.
 *
 * Buyer offer pages are public and accountless. The offer token is the
 * capability that authorizes access to the specific offer.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_Sales {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_routes' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_routes' ] );
    }

    public static function register_routes(): void {
        add_rewrite_rule(
            '^property-sales/?$',
            'index.php?ofp_property_sales=1',
            'top'
        );

        add_rewrite_rule(
            '^property-purchases/?$',
            'index.php?ofp_property_purchases=1',
            'top'
        );

        add_rewrite_rule(
            '^property-offer/?$',
            'index.php?ofp_property_offer=1',
            'top'
        );

        if ( get_option( 'ofp_property_sales_routes_v2' ) !== '1' ) {
            update_option( 'ofp_property_sales_routes_v2', '1', false );
            flush_rewrite_rules( false );
        }
    }

    public static function handle_routes(): void {
        if ( '1' === get_query_var( 'ofp_property_offer' ) ) {
            $template = OFP_PATH . 'public/templates/property-offer.php';
            if ( file_exists( $template ) ) {
                require $template;
                exit;
            }
            wp_die( esc_html__( 'Property offer page is unavailable.', 'ofast-pipeline' ) );
        }

        if ( '1' === get_query_var( 'ofp_property_purchases' ) ) {
            OFP_Auth::require_client_login();
            $client = OFP_Auth::current_client();

            if ( ! $client || ! OFP_Subscription::has_active( 'listing', $client->id ) ) {
                wp_safe_redirect( home_url( '/dashboard' ) );
                exit;
            }

            $template = OFP_PATH . 'public/templates/property-purchases.php';
            if ( file_exists( $template ) ) {
                require $template;
                exit;
            }
            wp_die( esc_html__( 'Property purchases page is unavailable.', 'ofast-pipeline' ) );
        }

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
    $vars[] = 'ofp_property_purchases';
    $vars[] = 'ofp_property_offer';
    return $vars;
} );
