<?php
/**
 * Property payment receipt viewer with secure file delivery.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Receipt_Viewer {

    private static $mime_whitelist = [ 'application/pdf', 'image/jpeg', 'image/png' ];

    public static function init(): void {
        add_action( 'wp_ajax_ofp_view_receipt', [ __CLASS__, 'handle_view_receipt' ] );
        add_action( 'wp_ajax_nopriv_ofp_view_receipt', [ __CLASS__, 'handle_view_receipt' ] );
    }

    public static function handle_view_receipt(): void {
        // Get payment ID
        $payment_id = isset( $_REQUEST['payment_id'] ) ? (int) $_REQUEST['payment_id'] : 0;
        if ( ! $payment_id ) {
            wp_die( 'Invalid payment ID.', 'Invalid Request', [ 'response' => 400 ] );
        }

        // Verify nonce
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( $_REQUEST['nonce'] ) : '';
        if ( ! wp_verify_nonce( $nonce, 'ofp_view_receipt_' . $payment_id ) ) {
            wp_die( 'Security check failed.', 'Forbidden', [ 'response' => 403 ] );
        }

        // Load payment with client_id
        global $wpdb;
        $p = $wpdb->prefix;
        $payment = $wpdb->get_row( $wpdb->prepare(
            "SELECT py.*, pu.client_id FROM {$p}ofp_property_payments py
             INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
             WHERE py.id = %d",
            $payment_id
        ) );
        if ( ! $payment ) {
            wp_die( 'Payment not found.', 'Not Found', [ 'response' => 404 ] );
        }

        // Check receipt exists
        if ( empty( $payment->receipt_path ) ) {
            wp_die( 'No receipt available for this payment.', 'Not Found', [ 'response' => 404 ] );
        }

        // Verify MIME type is whitelisted
        if ( ! in_array( $payment->receipt_mime, self::$mime_whitelist, true ) ) {
            wp_die( 'Invalid receipt MIME type.', 'Forbidden', [ 'response' => 403 ] );
        }

        // Verify receipt_size is set
        if ( empty( $payment->receipt_size ) || (int) $payment->receipt_size <= 0 ) {
            wp_die( 'Receipt size not set.', 'Server Error', [ 'response' => 500 ] );
        }

        // Authorize access
        if ( ! self::authorize_access( $payment ) ) {
            wp_die( 'Access denied.', 'Forbidden', [ 'response' => 403 ] );
        }

        // Deliver file
        self::deliver_receipt( $payment );
    }

    private static function authorize_access( object $payment ): bool {
        // Admin can view any receipt
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Client must have active listing subscription
        $client = OFP_Auth::current_client();
        if ( ! $client ) {
            return false;
        }

        // Verify client owns this payment
        if ( (int) $payment->client_id !== (int) $client->id ) {
            return false;
        }

        // Verify client has active listing subscription
        if ( ! OFP_Subscription::has_active( 'listing', $client->id ) ) {
            return false;
        }

        return true;
    }

    private static function deliver_receipt( object $payment ): void {
        // Resolve path securely
        $receipt_path = $payment->receipt_path;
        $uploads_dir = wp_upload_dir();
        $base_dir = $uploads_dir['basedir'];
        $full_path = $base_dir . '/' . $receipt_path;

        // Normalize paths for comparison
        $real_path = realpath( $full_path );
        $real_base = realpath( $base_dir );

        // Verify path is within uploads directory
        if ( ! $real_path || ! $real_base || strpos( $real_path, $real_base ) !== 0 ) {
            wp_die( 'Invalid receipt path.', 'Forbidden', [ 'response' => 403 ] );
        }

        // Verify file exists and is readable
        if ( ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
            wp_die( 'Receipt file not found.', 'Not Found', [ 'response' => 404 ] );
        }

        // Verify file size matches stored value
        $actual_size = filesize( $real_path );
        if ( $actual_size !== (int) $payment->receipt_size ) {
            wp_die( 'Receipt integrity check failed.', 'Server Error', [ 'response' => 500 ] );
        }

        // Set security headers
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: ' . $payment->receipt_mime );
        header( 'Content-Length: ' . (int) $payment->receipt_size );
        header( 'Content-Disposition: inline; filename="receipt-' . $payment->id . '.pdf"' );
        header( 'Cache-Control: private, max-age=3600' );

        // Prevent direct access to sensitive data
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'X-XSS-Protection: 1; mode=block' );

        // Deliver file
        readfile( $real_path );
        exit;
    }
}

// Auto-initialize
OFP_Property_Receipt_Viewer::init();
