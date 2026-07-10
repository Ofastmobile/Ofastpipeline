<?php
/**
 * Template: /api-settings
 * Client API Settings and Webhook Credentials.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Settings — OFast Pipeline</title>
    <!-- Dark theme script to avoid FOUC -->
    <script>
        (function() {
            var currentTheme = localStorage.getItem('ofp_theme') || 'dark';
            if (currentTheme === 'light') { document.documentElement.setAttribute('data-theme', 'light'); }
        })();
    </script>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-container">

        <div class="ofp-page-header">
            <h1>API Settings</h1>
            <p>Manage your webhook credentials and endpoint integrations.</p>
        </div>

        <!-- Client ID and endpoint — needed for landing page form setup -->
        <div class="ofp-card" style="border-left: 4px solid var(--accent-green); margin-bottom: 24px;">
            <h3 style="color:var(--accent-green); font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;">
                Landing Page Form Credentials
            </h3>
            <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-color);">
                <span style="font-size:13px; color:var(--text-muted);">Your Client ID</span>
                <code style="font-size:13px; font-weight:700; color:var(--text-main); background:var(--bg-body); padding:4px 8px; border-radius:4px;">
                    <?php echo esc_html( $client->id ); ?>
                </code>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:flex-start; padding:8px 0;">
                <span style="font-size:13px; color:var(--text-muted);">Lead Capture Endpoint</span>
                <code style="font-size:11px; color:var(--text-main); background:var(--bg-body); padding:4px 8px; border-radius:4px; word-break:break-all; max-width:280px; text-align:right;">
                    <?php echo esc_html( home_url( '/wp-json/ofp/v1/capture-lead' ) ); ?>
                </code>
            </div>
            <p class="ofp-hint" style="margin-top:12px;">
                Use these in your Elementor landing page form. Set <strong>client_id</strong>
                as a hidden field with your Client ID value above.
            </p>
        </div>

        <!-- Client's assigned domain -->
        <?php
        $base_domain      = get_option( 'ofp_crm_base_domain', '' );
        $client_subdomain = ! empty( $client->subdomain ) ? trim( $client->subdomain ) : '';
        ?>
        <div class="ofp-card" style="border-left: 4px solid var(--accent-blue, #3b82f6); margin-bottom: 24px;">
            <h3 style="color:var(--accent-blue, #3b82f6); font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;">
                Your Domain
            </h3>
            <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border-color);">
                <span style="font-size:13px; color:var(--text-muted);">Branded URL</span>
                <?php if ( $client_subdomain && $base_domain ) :
                    $full_domain = $client_subdomain . '.' . $base_domain;
                ?>
                    <a href="<?php echo esc_url( 'https://' . $full_domain ); ?>" target="_blank"
                       style="font-size:13px; font-weight:700; color:var(--accent-blue, #3b82f6); text-decoration:none;">
                        <?php echo esc_html( $full_domain ); ?>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:12px;height:12px;vertical-align:middle;margin-left:4px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                        </svg>
                    </a>
                <?php elseif ( $client_subdomain ) : ?>
                    <span style="font-size:13px; color:var(--text-muted);">
                        <?php echo esc_html( $client_subdomain ); ?> <em>(domain pending setup)</em>
                    </span>
                <?php else : ?>
                    <span style="font-size:13px; color:var(--text-muted);">Not assigned yet</span>
                <?php endif; ?>
            </div>
            <p class="ofp-hint" style="margin-top:12px;">
                This is your dedicated business landing page URL. Share this with your clients and
                on marketing materials. Contact your admin if you need it changed.
            </p>
        </div>

</div>
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
