<?php
/**
 * OFP_Admin_Settings
 *
 * Registers OFast Pipeline wp_options and handles the test email action.
 * The Settings page view and save handler live in OFP_Admin_Menu.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Admin_Settings {

    public function __construct() {
        add_action( 'admin_post_ofp_test_email', [ $this, 'handle_test_email' ] );
    }

    /**
     * Send a test email to the currently logged-in WordPress user.
     * Confirms SMTP is configured and working correctly.
     */
    public function handle_test_email(): void {

        if (
            ! check_admin_referer( 'ofp_test_email' ) ||
            ! OFP_Auth::is_super_admin()
        ) {
            wp_die( 'Access denied.', 403 );
        }

        $current_user = wp_get_current_user();

        $sent = OFP_Mailer::send(
            $current_user->user_email,
            $current_user->display_name,
            OFP_Brand::name() . ' — Test Email',
            '<h2>✅ Test Email Successful</h2>
             <p>If you are reading this, your SMTP configuration is working correctly.</p>
             <p><strong>Sent at:</strong> ' . current_time( 'mysql' ) . '</p>'
        );

        $message = $sent
            ? '✅ Test email sent to ' . $current_user->user_email . '. Check your inbox.'
            : '❌ Test email failed. Check your SMTP settings and server error logs.';

        set_transient(
            'ofp_admin_message_' . get_current_user_id(),
            [ 'text' => $message, 'type' => $sent ? 'success' : 'error' ],
            60
        );

        wp_safe_redirect( admin_url( 'admin.php?page=ofp-settings' ) );
        exit;
    }
}
