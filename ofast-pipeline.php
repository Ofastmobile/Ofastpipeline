<?php
/**
 * Plugin Name: OFast Pipeline
 * Plugin URI:  https://ofastpipeline.com
 * Description: Done-for-you lead pipeline and CRM automation engine for SMB clients in Nigeria.
 * Version:     2.1.0
 * Author:      Olabode / Bofast World
 * Author URI:  https://bofastworld.com
 * Text Domain: ofast-pipeline
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

// ─── Hard stop if accessed directly ───────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Plugin constants ──────────────────────────────────────────────────────────
define( 'OFP_VERSION',     '2.1.0' );
define( 'OFP_PATH',        plugin_dir_path( __FILE__ ) );
define( 'OFP_URL',         plugin_dir_url( __FILE__ ) );
define( 'OFP_PLUGIN_FILE', __FILE__ );
define( 'OFP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ─── Autoload all class files ──────────────────────────────────────────────────
// Core / shared
require_once OFP_PATH . 'includes/class-ofp-activator.php';
require_once OFP_PATH . 'includes/class-ofp-deactivator.php';
require_once OFP_PATH . 'includes/class-ofp-security.php';
require_once OFP_PATH . 'includes/class-ofp-auth.php';
require_once OFP_PATH . 'includes/class-ofp-mailer.php';
require_once OFP_PATH . 'includes/class-ofp-client.php';
require_once OFP_PATH . 'includes/class-ofp-lead.php';
require_once OFP_PATH . 'includes/class-ofp-queue.php';
require_once OFP_PATH . 'includes/class-ofp-sms.php';
require_once OFP_PATH . 'includes/class-ofp-voice.php';
require_once OFP_PATH . 'includes/class-ofp-ivr.php';
require_once OFP_PATH . 'includes/class-ofp-credit.php';
require_once OFP_PATH . 'includes/class-ofp-subscription.php';
require_once OFP_PATH . 'includes/class-ofp-csv.php';
require_once OFP_PATH . 'includes/class-ofp-property-cpt.php';   // v2.1 — property listing CPT
require_once OFP_PATH . 'includes/class-ofp-host-router.php';    // Phase 16 — app/property subdomain routing
require_once OFP_PATH . 'includes/class-ofp-notification.php';   // Phase 17 — notifications

// Payment gateway — interface + provider adapters.
// OFP_Payment is the only entry point; adapters are loaded here so the
// interface is available before any adapter is instantiated.
require_once OFP_PATH . 'includes/class-ofp-payment.php';
require_once OFP_PATH . 'includes/gateways/class-ofp-gateway-monnify.php';
require_once OFP_PATH . 'includes/gateways/class-ofp-gateway-paystack.php';
require_once OFP_PATH . 'includes/gateways/class-ofp-gateway-flutterwave.php';

// Admin
require_once OFP_PATH . 'admin/class-ofp-admin-menu.php';
require_once OFP_PATH . 'admin/class-ofp-admin-settings.php';

// Public / REST / Client portal
require_once OFP_PATH . 'public/class-ofp-rest-api.php';
require_once OFP_PATH . 'public/class-ofp-client-portal.php';

// Cron
require_once OFP_PATH . 'cron/class-ofp-cron-handler.php';

// ─── Activation / deactivation hooks ──────────────────────────────────────────
register_activation_hook( OFP_PLUGIN_FILE, [ 'OFP_Activator', 'activate' ] );
register_deactivation_hook( OFP_PLUGIN_FILE, [ 'OFP_Deactivator', 'deactivate' ] );

// ─── Custom cron interval (every 5 minutes) ────────────────────────────────────
add_filter( 'cron_schedules', function ( array $schedules ): array {
    $schedules['ofp_five_minutes'] = [
        'interval' => 300,
        'display'  => __( 'Every 5 Minutes (OFast Pipeline)', 'ofast-pipeline' ),
    ];
    return $schedules;
} );

// ─── Boot all plugin components after WP + plugins are loaded ─────────────────
add_action( 'plugins_loaded', function (): void {

    // Configure Brevo SMTP globally so ALL wp_mail() calls route through it.
    OFP_Mailer::configure_smtp();

    // Spin up each component.
    new OFP_Admin_Menu();
    new OFP_Admin_Settings();
    new OFP_REST_API();
    new OFP_Client_Portal();
    new OFP_Cron_Handler();
    new OFP_Property_CPT();   // v2.1
    OFP_Host_Router::init();  // Phase 16 — app/property subdomain routing

} );

// ─── Deferred rewrite rule flush (fixes the activation-timing bug) ────────────
// OFP_Activator::activate() cannot safely call flush_rewrite_rules() directly
// (see the detailed comment in class-ofp-activator.php). Instead it sets the
// 'ofp_flush_rewrite_rules' option, and we check for it here on 'init' at a
// LATE priority — after OFP_Client_Portal::register_rewrite_rules() (default
// priority 10) and OFP_Property_CPT::register_post_type() (default priority
// 10) have both already run for this request, so the flush captures every
// custom route correctly.
add_action( 'init', function (): void {
    if ( get_option( 'ofp_flush_rewrite_rules' ) ) {
        flush_rewrite_rules();
        delete_option( 'ofp_flush_rewrite_rules' );
    }
}, 999 );
