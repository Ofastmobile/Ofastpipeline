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
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-container">
        <div class="ofp-page-header">
            <h1>API Settings & Integration</h1>
            <p>Manage your webhook credentials, forms, and custom domain integrations.</p>
        </div>

        <div class="ofp-grid-2" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 24px;">
            
            <!-- API Credentials Card -->
            <div class="ofp-card">
                <div class="ofp-card-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
                    <div style="background: rgba(16, 185, 129, 0.1); padding: 12px; border-radius: 12px; display: flex;">
                        <svg width="24" height="24" fill="none" stroke="var(--accent-green)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline>
                        </svg>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 16px; color: var(--text-main);">Landing Page Integration</h3>
                        <p style="margin: 0; font-size: 13px; color: var(--text-muted);">Elementor form credentials</p>
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em;">Your Client ID</label>
                    <div style="display: flex; justify-content: space-between; align-items: center; background: #0f172a; border: 1px solid var(--border-color); padding: 12px 16px; border-radius: 8px;">
                        <code style="color: var(--accent-green); font-size: 14px; font-weight: 600; font-family: monospace;"><?php echo esc_html( $client->id ); ?></code>
                        <button onclick="navigator.clipboard.writeText('<?php echo esc_js($client->id); ?>'); this.innerHTML='Copied!';" style="background: transparent; border: none; color: var(--text-muted); cursor: pointer; font-size: 12px; font-weight: 600; transition: color 0.2s;" onmouseover="this.style.color='var(--text-main)'" onmouseout="this.style.color='var(--text-muted)'">COPY</button>
                    </div>
                </div>

                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em;">Lead Capture Endpoint</label>
                    <div style="display: flex; justify-content: space-between; align-items: center; background: #0f172a; border: 1px solid var(--border-color); padding: 12px 16px; border-radius: 8px;">
                        <code style="color: var(--text-main); font-size: 13px; font-family: monospace; word-break: break-all;"><?php echo esc_html( home_url( '/wp-json/ofp/v1/capture-lead' ) ); ?></code>
                        <button onclick="navigator.clipboard.writeText('<?php echo esc_js(home_url('/wp-json/ofp/v1/capture-lead')); ?>'); this.innerHTML='Copied!';" style="background: transparent; border: none; color: var(--text-muted); cursor: pointer; font-size: 12px; font-weight: 600; transition: color 0.2s; margin-left: 12px;" onmouseover="this.style.color='var(--text-main)'" onmouseout="this.style.color='var(--text-muted)'">COPY</button>
                    </div>
                </div>

                <div class="ofp-alert ofp-alert-info" style="margin: 0; display: flex; align-items: flex-start; gap: 12px; padding: 12px 16px;">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink: 0; margin-top: 2px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                    <div style="font-size: 13px; line-height: 1.5;">
                        Set <strong>client_id</strong> as a hidden field in your Elementor form with the Client ID value above.
                    </div>
                </div>
            </div>

            <!-- Domain Card -->
            <?php
            $base_domain      = get_option( 'ofp_crm_base_domain', '' );
            $client_subdomain = ! empty( $client->subdomain ) ? trim( $client->subdomain ) : '';
            ?>
            <div class="ofp-card">
                <div class="ofp-card-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
                    <div style="background: rgba(59, 130, 246, 0.1); padding: 12px; border-radius: 12px; display: flex;">
                        <svg width="24" height="24" fill="none" stroke="var(--accent-blue)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 16px; color: var(--text-main);">Custom Domain</h3>
                        <p style="margin: 0; font-size: 13px; color: var(--text-muted);">Your dedicated business URL</p>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 24px; background: var(--bg-body); border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 24px;">
                    <?php if ( $client_subdomain && $base_domain ) :
                        $full_domain = $client_subdomain . '.' . $base_domain;
                    ?>
                        <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 12px;">Active Domain</div>
                        <a href="<?php echo esc_url( 'https://' . $full_domain ); ?>" target="_blank"
                           style="display: inline-flex; align-items: center; gap: 8px; font-size: 18px; font-weight: 700; color: var(--accent-blue); text-decoration: none; padding: 10px 20px; background: rgba(59, 130, 246, 0.1); border-radius: 32px; transition: all 0.2s ease; border: 1px solid rgba(59, 130, 246, 0.2);"
                           onmouseover="this.style.transform='scale(1.05)'; this.style.background='rgba(59, 130, 246, 0.15)';" onmouseout="this.style.transform='scale(1)'; this.style.background='rgba(59, 130, 246, 0.1)';">
                            <?php echo esc_html( $full_domain ); ?>
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                        </a>
                    <?php elseif ( $client_subdomain ) : ?>
                        <div style="font-size: 12px; color: var(--accent-orange); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 12px;">Pending Setup</div>
                        <span style="font-size: 20px; font-weight: 600; color: var(--text-main);">
                            <?php echo esc_html( $client_subdomain ); ?>
                        </span>
                    <?php else : ?>
                        <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 12px;">Status</div>
                        <span style="font-size: 16px; font-weight: 600; color: var(--text-muted);">Not assigned yet</span>
                    <?php endif; ?>
                </div>

                <p style="font-size: 13px; color: var(--text-muted); line-height: 1.6; margin: 0; text-align: center;">
                    This is your dedicated business landing page URL. Share this with your clients and on marketing materials. Contact your admin if you need to request a change.
                </p>
            </div>
        </div>
    </div>
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
