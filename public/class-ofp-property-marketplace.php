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
        // Only load on the marketplace route, property single pages, property archive, or homepage
        if ( '1' === (string) get_query_var( 'ofp_marketplace', '' ) || is_singular( 'ofp_property' ) || is_front_page() || is_home() || is_post_type_archive( 'ofp_property' ) || is_page( [ 'home', 'homepage', 'marketplace', 'properties' ] ) ) {
            wp_enqueue_script( 'tailwind-js', 'https://cdn.tailwindcss.com', [], null, false );
            $tailwind_cfg = "window.tailwind = window.tailwind || {}; window.tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['\"Segoe UI\"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'Arial', 'sans-serif'], system: ['\"Segoe UI\"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'Arial', 'sans-serif'] } } };";
            wp_add_inline_script( 'tailwind-js', $tailwind_cfg, 'before' );
            wp_add_inline_script( 'tailwind-js', "if (typeof tailwind !== 'undefined') { " . $tailwind_cfg . " }", 'after' );
            wp_enqueue_script( 'alpine-js', 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js', [], null, true );
            wp_enqueue_style( 'ofp-alpine-tailwind', OFP_URL . 'assets/css/alpine-tailwind-overrides.css', [], OFP_VERSION );
            
            // Pass AJAX URL to JS
            wp_localize_script( 'alpine-js', 'ofp_marketplace', [
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

        $template = OFP_PATH . 'public/templates/property-marketplace.php';

        if ( ! file_exists( $template ) ) {
            status_header( 404 );
            wp_die( esc_html__( 'Property marketplace template not found.', 'ofast-pipeline' ) );
        }

        include $template;
        exit;
    }
}
