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

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'OFP_VERSION', '2.1.0' );
define( 'OFP_PATH', plugin_dir_path( __FILE__ ) );
define( 'OFP_URL', plugin_dir_url( __FILE__ ) );
define( 'OFP_PLUGIN_FILE', __FILE__ );
define( 'OFP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
// class-ofp-property-cpt.php is namespaced and refers to OFP_PLUGIN_DIR.
// Keep the legacy namespaced constant mapped to the canonical plugin path.
if ( ! defined( 'OFP\\OFP_PLUGIN_DIR' ) ) {
    define( 'OFP\\OFP_PLUGIN_DIR', OFP_PATH );
}

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
require_once OFP_PATH . 'includes/class-ofp-property-cpt.php';
require_once OFP_PATH . 'includes/class-ofp-host-router.php';
require_once OFP_PATH . 'includes/class-ofp-notification.php';
require_once OFP_PATH . 'includes/class-ofp-logger.php';
require_once OFP_PATH . 'includes/class-ofp-pipeline-audio.php';
require_once OFP_PATH . 'includes/class-ofp-property-commerce.php';
require_once OFP_PATH . 'includes/class-ofp-property-commerce-migration.php';
require_once OFP_PATH . 'includes/class-ofp-property-admin-rules.php';

// Payment gateway — interface + provider adapters.
require_once OFP_PATH . 'includes/class-ofp-payment.php';
require_once OFP_PATH . 'includes/gateways/class-ofp-gateway-monnify.php';
require_once OFP_PATH . 'includes/gateways/class-ofp-gateway-paystack.php';
require_once OFP_PATH . 'includes/gateways/class-ofp-gateway-flutterwave.php';

// Admin
require_once OFP_PATH . 'admin/class-ofp-admin-menu.php';
require_once OFP_PATH . 'admin/class-ofp-admin-settings.php';
require_once OFP_PATH . 'admin/class-ofp-property-commerce-admin.php';
require_once OFP_PATH . 'admin/class-ofp-property-commerce-actions.php';

// Public / REST / Client portal
require_once OFP_PATH . 'public/class-ofp-rest-api.php';
require_once OFP_PATH . 'public/class-ofp-client-portal.php';
require_once OFP_PATH . 'public/class-ofp-property-sales.php';
require_once OFP_PATH . 'public/class-ofp-property-sales-client-ui.php';

// Cron
require_once OFP_PATH . 'cron/class-ofp-cron-handler.php';

register_activation_hook( OFP_PLUGIN_FILE, [ 'OFP_Activator', 'activate' ] );
register_deactivation_hook( OFP_PLUGIN_FILE, [ 'OFP_Deactivator', 'deactivate' ] );

add_filter( 'cron_schedules', function ( array $schedules ): array {
    $schedules['ofp_five_minutes'] = [
        'interval' => 300,
        'display'  => __( 'Every 5 Minutes (OFast Pipeline)', 'ofast-pipeline' ),
    ];
    return $schedules;
} );

add_action( 'plugins_loaded', function (): void {
    OFP_Mailer::configure_smtp();

    new OFP_Admin_Menu();
    new OFP_Admin_Settings();
    new OFP_Property_Commerce_Admin();
    OFP_Property_Commerce_Actions::init();
    new OFP_REST_API();
    new OFP_Client_Portal();
    new OFP_Cron_Handler();
    new OFP_Property_CPT();
    OFP_Host_Router::init();
    OFP_Property_Commerce::init();
    OFP_Property_Commerce_Migration::init();
    OFP_Property_Sales::init();
    OFP_Property_Sales_Client_UI::init();
    OFP_Property_Admin_Rules::init();
} );

add_action( 'init', function (): void {
    if ( get_option( 'ofp_flush_rewrite_rules' ) ) {
        flush_rewrite_rules();
        delete_option( 'ofp_flush_rewrite_rules' );
    }
}, 999 );

add_action( 'wp_head', function (): void {
    $global_pixel = get_option( 'ofp_global_pixel_id', '' );
    if ( ! empty( $global_pixel ) ) {
        ?>
        <script>
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
            n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script',
            'https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', '<?php echo esc_js( $global_pixel ); ?>');fbq('track', 'PageView');
        </script>
        <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo esc_attr( $global_pixel ); ?>&ev=PageView&noscript=1" /></noscript>
        <?php
    }
} );
