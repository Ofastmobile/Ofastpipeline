<?php
/**
 * Public property marketplace route.
 *
 * /marketplace/ is the local/testing and production fallback URL for the
 * public property archive. It intentionally does not interfere with the
 * existing client-portal /properties/ route (My Properties).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_Marketplace {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_route' ] );
        add_action( 'template_redirect', [ __CLASS__, 'render_route' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_var' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
    }

    public static function enqueue_scripts(): void {
        // Only load on the marketplace route
        if ( '1' === (string) get_query_var( 'ofp_marketplace', '' ) ) {
            wp_enqueue_style( 'ofp-marketplace-css', OFP_URL . 'assets/css/marketplace.css', [], OFP_VERSION );
            wp_enqueue_script( 'ofp-marketplace-js', OFP_URL . 'assets/js/marketplace.js', [], OFP_VERSION, true );
            
            // Pass AJAX URL to JS
            wp_localize_script( 'ofp-marketplace-js', 'ofp_marketplace', [
                'ajax_url' => admin_url( 'admin-ajax.php' )
            ] );
        }
    }

    public static function register_route(): void {
        add_rewrite_rule(
            '^marketplace/?$',
            'index.php?ofp_marketplace=1',
            'top'
        );
    }

    public static function register_query_var( array $vars ): array {
        $vars[] = 'ofp_marketplace';
        return $vars;
    }

    public static function render_route(): void {
        if ( '1' !== (string) get_query_var( 'ofp_marketplace', '' ) ) {
            return;
        }

        $template = OFP_PATH . 'public/templates/property-archive.php';

        if ( ! file_exists( $template ) ) {
            status_header( 404 );
            wp_die( esc_html__( 'Property marketplace template not found.', 'ofast-pipeline' ) );
        }

        include $template;
        exit;
    }
}
