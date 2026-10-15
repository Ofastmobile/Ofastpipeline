<?php
/**
 * Property payment receipt viewer with secure file delivery.
 * Supports both purchase installment payments and lease/rent payments.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Receipt_Viewer {

    private static array $mime_whitelist = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'text/html',
        'text/html; charset=UTF-8',
        'text/html; charset=utf-8',
    ];

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_routes' ] );
        add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_public_route' ] );
        add_action( 'wp_ajax_ofp_view_receipt', [ __CLASS__, 'handle_view_receipt' ] );
        add_action( 'wp_ajax_nopriv_ofp_view_receipt', [ __CLASS__, 'handle_view_receipt' ] );
    }

    public static function register_routes(): void {
        add_rewrite_rule( '^property-receipt/?$', 'index.php?ofp_view_receipt=1', 'top' );
    }

    public static function register_query_vars( array $vars ): array {
        $vars[] = 'ofp_view_receipt';
        return $vars;
    }

    public static function handle_public_route(): void {
        $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        if ( '1' === (string) get_query_var( 'ofp_view_receipt' ) || 'property-receipt' === $path ) {
            self::handle_view_receipt();
            exit;
        }
    }

    /**
     * Helper to generate a secure receipt URL using the public SaaS endpoint.
     * Prevents exposing wp-admin/admin-ajax.php to clients, buyers, or tenants.
     */
    public static function get_receipt_url( int $payment_id, string $type = 'rent', ?string $token = null ): string {
        $params = [
            'payment_id' => $payment_id,
            'type'       => $type,
        ];
        if ( ! empty( $token ) ) {
            $params['token'] = $token;
        } else {
            $params['nonce'] = wp_create_nonce( 'ofp_view_receipt_' . $payment_id );
        }
        return add_query_arg( $params, home_url( '/property-receipt/' ) );
    }

    public static function handle_view_receipt(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $payment_id = isset( $_REQUEST['payment_id'] ) ? (int) $_REQUEST['payment_id'] : 0;
        if ( ! $payment_id ) {
            wp_die( 'Invalid payment ID.', 'Invalid Request', [ 'response' => 400 ] );
        }

        $type  = isset( $_REQUEST['type'] ) ? sanitize_key( $_REQUEST['type'] ) : 'rent';
        $token = isset( $_REQUEST['token'] ) ? sanitize_text_field( $_REQUEST['token'] ) : '';
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( $_REQUEST['nonce'] ) : '';

        // Load payment record
        if ( 'purchase' === $type ) {
            $payment = $wpdb->get_row( $wpdb->prepare(
                "SELECT py.*, pu.client_id, 'purchase' AS receipt_category
                 FROM {$p}ofp_property_payments py
                 INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
                 WHERE py.id = %d LIMIT 1",
                $payment_id
            ) );
        } else {
            $payment = $wpdb->get_row( $wpdb->prepare(
                "SELECT py.*, l.client_id, l.offer_token_hash, 'rent' AS receipt_category
                 FROM {$p}ofp_property_lease_payments py
                 INNER JOIN {$p}ofp_property_leases l ON l.id = py.lease_id
                 WHERE py.id = %d LIMIT 1",
                $payment_id
            ) );
        }

        if ( ! $payment ) {
            wp_die( 'Payment not found.', 'Not Found', [ 'response' => 404 ] );
        }

        // Authorize access
        if ( ! self::authorize_access( $payment, $nonce, $token ) ) {
            wp_die( 'Access denied or link expired.', 'Forbidden', [ 'response' => 403 ] );
        }

        // Auto-generate rent receipt on demand if file is missing or not yet generated
        $uploads_dir = wp_upload_dir();
        $base_dir    = $uploads_dir['basedir'];
        $receipt_raw = (string) ( $payment->receipt_path ?? '' );

        if ( $receipt_raw !== '' && file_exists( $receipt_raw ) ) {
            $full_path = $receipt_raw;
        } elseif ( $receipt_raw !== '' && file_exists( $base_dir . '/' . ltrim( $receipt_raw, '/\\' ) ) ) {
            $full_path = $base_dir . '/' . ltrim( $receipt_raw, '/\\' );
        } else {
            $full_path = $receipt_raw !== '' ? ( $base_dir . '/' . $receipt_raw ) : '';
        }

        if ( 'rent' === $payment->receipt_category && ( empty( $payment->receipt_path ) || ! file_exists( $full_path ) ) ) {
            if ( class_exists( 'OFP_Property_Receipt_Generator' ) ) {
                $generated = OFP_Property_Receipt_Generator::get_or_create_lease_receipt( $payment_id );
                if ( $generated ) {
                    $payment->receipt_path = $generated['path'];
                    $payment->receipt_mime = $generated['mime'];
                    $payment->receipt_size = $generated['size'];
                    $full_path             = $base_dir . '/' . $payment->receipt_path;
                }
            }
        }

        // Check receipt exists
        if ( empty( $payment->receipt_path ) ) {
            wp_die( 'No receipt available for this payment.', 'Not Found', [ 'response' => 404 ] );
        }

        // Verify MIME type is whitelisted
        $mime_clean = trim( strtolower( explode( ';', (string) $payment->receipt_mime )[0] ) );
        $whitelist_clean = array_map( function( $m ) { return trim( strtolower( explode( ';', $m )[0] ) ); }, self::$mime_whitelist );

        if ( ! in_array( $mime_clean, $whitelist_clean, true ) ) {
            wp_die( 'Invalid receipt MIME type: ' . esc_html( $payment->receipt_mime ), 'Forbidden', [ 'response' => 403 ] );
        }

        // Deliver file
        self::deliver_receipt( $payment );
    }

    private static function authorize_access( object $payment, string $nonce, string $token ): bool {
        // Admin can view any receipt
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Tenant access via matching lease offer token
        if ( ! empty( $token ) && ! empty( $payment->offer_token_hash ) ) {
            if ( hash_equals( (string) $payment->offer_token_hash, hash( 'sha256', $token ) ) ) {
                return true;
            }
        }

        // Nonce verification: secure time-limited signed token generated for this payment
        if ( ! empty( $nonce ) && wp_verify_nonce( $nonce, 'ofp_view_receipt_' . $payment->id ) ) {
            return true;
        }

        // Session check for logged-in portal clients
        $client = class_exists( 'OFP_Auth' ) ? OFP_Auth::current_client() : null;
        if ( $client && (int) $payment->client_id === (int) $client->id ) {
            return true;
        }

        return false;
    }

    private static function deliver_receipt( object $payment ): void {
        $receipt_path = (string) $payment->receipt_path;
        $uploads_dir  = wp_upload_dir();
        $base_dir     = $uploads_dir['basedir'];

        if ( $receipt_path !== '' && file_exists( $receipt_path ) ) {
            $full_path = $receipt_path;
        } elseif ( $receipt_path !== '' && file_exists( $base_dir . '/' . ltrim( $receipt_path, '/\\' ) ) ) {
            $full_path = $base_dir . '/' . ltrim( $receipt_path, '/\\' );
        } else {
            $full_path = $base_dir . '/' . $receipt_path;
        }

        $real_path = realpath( $full_path );
        $real_base = realpath( $base_dir );

        // Normalize slashes and casing for cross-platform (Windows & Linux) validation
        $norm_path = $real_path ? str_replace( '\\', '/', strtolower( $real_path ) ) : '';
        $norm_base = $real_base ? str_replace( '\\', '/', strtolower( $real_base ) ) : '';

        // Verify path is within uploads directory
        if ( ! $norm_path || ! $norm_base || ! str_starts_with( $norm_path, $norm_base ) ) {
            wp_die( 'Invalid receipt path.', 'Forbidden', [ 'response' => 403 ] );
        }

        // Verify file exists and is readable
        if ( ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
            wp_die( 'Receipt file not found on disk.', 'Not Found', [ 'response' => 404 ] );
        }

        $actual_size = filesize( $real_path );
        $mime = ! empty( $payment->receipt_mime ) ? $payment->receipt_mime : 'application/octet-stream';
        $is_html = str_contains( strtolower( $mime ), 'html' );
        $ext = pathinfo( $real_path, PATHINFO_EXTENSION ) ?: ( $is_html ? 'html' : 'pdf' );

        // Set security headers
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . (int) $actual_size );
        header( 'Content-Disposition: inline; filename="receipt-' . (int) $payment->id . '.' . $ext . '"' );
        header( 'Cache-Control: private, max-age=3600' );

        // Deliver file
        readfile( $real_path );
        exit;
    }
}

// Auto-initialize
OFP_Property_Receipt_Viewer::init();
