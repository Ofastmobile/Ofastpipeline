<?php
/**
 * Template: /api-settings
 * Client API Settings, Domain & Website Integration Hub.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

$base_domain      = get_option( 'ofp_crm_base_domain', '' );
$client_subdomain = ! empty( $client->subdomain ) ? trim( $client->subdomain ) : '';
$custom_domain    = ! empty( $client->custom_domain ) ? trim( $client->custom_domain ) : '';

if ( $custom_domain ) {
    $api_host         = 'https://' . $custom_domain;
    $lead_endpoint    = $api_host . '/api/v1/inquire';
    $embed_script_url = $api_host . '/widget.js';
    $embed_code       = '<div id="ofp-property-widget" data-limit="6"></div>' . "\n" . '<script src="' . esc_url( $embed_script_url ) . '" async></script>';
} elseif ( $client_subdomain && $base_domain ) {
    $subdomain_host   = 'https://' . $client_subdomain . '.' . $base_domain;
    $lead_endpoint    = $subdomain_host . '/api/v1/inquire';
    $embed_script_url = 'https://' . $base_domain . '/widget.js';
    $embed_code       = '<div id="ofp-property-widget" data-client-id="' . esc_attr( $client->id ) . '" data-limit="6"></div>' . "\n" . '<script src="' . esc_url( $embed_script_url ) . '" async></script>';
} else {
    $lead_endpoint    = home_url( '/api/v1/inquire' );
    $embed_script_url = home_url( '/widget.js' );
    $embed_code       = '<div id="ofp-property-widget" data-client-id="' . esc_attr( $client->id ) . '" data-limit="6"></div>' . "\n" . '<script src="' . esc_url( $embed_script_url ) . '" async></script>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Integrations & API', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-settings-shell">

    <!-- ── Header Section ──────────────────────────────────────────────── -->
    <header class="ofp-settings-header">
        <div>
            <div class="ofp-header-meta">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                </svg>
                Developer &amp; Integration Hub
            </div>
            <h1 class="ofp-header-title">Integrations &amp; API</h1>
            <p class="ofp-header-desc">Manage your lead capture endpoints, agency portal routing, and embeddable property feeds for external websites.</p>
        </div>

        <div style="display: flex; align-items: center; gap: 10px;">
            <span class="ofp-pill ofp-pill-green">
                <span class="ofp-pulse-dot"></span> API Active
            </span>
            <span class="ofp-pill ofp-pill-blue">v1.0 REST</span>
        </div>
    </header>

    <div class="ofp-settings-layout">

        <!-- ── Left Sidebar Navigation ──────────────────────────────────── -->
        <aside class="ofp-settings-sidebar">
            <div class="ofp-sidebar-group-label">Channels &amp; Tools</div>

            <button type="button" class="ofp-tab-btn is-active" data-target="panel-lead-capture">
                <span class="ofp-tab-icon">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline>
                    </svg>
                </span>
                <span class="ofp-tab-text">
                    <span class="ofp-tab-title">Lead Capture Form</span>
                    <span class="ofp-tab-sub">Webhooks &amp; Elementor</span>
                </span>
            </button>

            <button type="button" class="ofp-tab-btn" data-target="panel-domain">
                <span class="ofp-tab-icon">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                    </svg>
                </span>
                <span class="ofp-tab-text">
                    <span class="ofp-tab-title">Portal &amp; Domain</span>
                    <span class="ofp-tab-sub">Subdomain &amp; Routing</span>
                </span>
            </button>

            <button type="button" class="ofp-tab-btn" data-target="panel-property-widget">
                <span class="ofp-tab-icon">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7"></rect>
                        <rect x="14" y="3" width="7" height="7"></rect>
                        <rect x="14" y="14" width="7" height="7"></rect>
                        <rect x="3" y="14" width="7" height="7"></rect>
                    </svg>
                </span>
                <span class="ofp-tab-text">
                    <span class="ofp-tab-title">Property Feed Widget</span>
                    <span class="ofp-tab-sub">Embed Listings on Sites</span>
                </span>
            </button>
        </aside>

        <!-- ── Right Main Area ──────────────────────────────────────────── -->
        <main>

            <!-- ── PANEL 1: Lead Capture Form ────────────────────────────── -->
            <div id="panel-lead-capture" class="ofp-tab-panel is-active">
                <div class="ofp-saas-card">
                    <div class="ofp-panel-header">
                        <div class="ofp-panel-header-left">
                            <div class="ofp-panel-icon-wrap" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                                <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline>
                                </svg>
                            </div>
                            <div>
                                <h2 class="ofp-panel-title">Form Ingestion Endpoint</h2>
                                <p class="ofp-panel-desc">Direct HTTP POST endpoint to ingest inquiries from Elementor, Contact Form 7, or custom forms into your pipeline.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Client ID Field -->
                    <div class="ofp-param-group">
                        <div class="ofp-param-label">
                            <span>Your Client Identifier</span>
                            <span>Parameter: <code>client_id</code></span>
                        </div>
                        <div class="ofp-display-box">
                            <span class="ofp-code-val" style="color: #10b981; font-weight: 700;"><?php echo esc_html( $client->id ); ?></span>
                            <button type="button" class="ofp-btn-action ofp-btn-copy" data-copy="<?php echo esc_attr( $client->id ); ?>">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                                <span>Copy ID</span>
                            </button>
                        </div>
                    </div>

                    <!-- Endpoint URL Field -->
                    <div class="ofp-param-group">
                        <div class="ofp-param-label">
                            <span>Ingestion Endpoint URL</span>
                            <span class="ofp-pill ofp-pill-blue" style="font-size: 10px; padding: 1px 7px;">POST</span>
                        </div>
                        <div class="ofp-display-box">
                            <span class="ofp-code-val"><?php echo esc_html( $lead_endpoint ); ?></span>
                            <button type="button" class="ofp-btn-action ofp-btn-copy" data-copy="<?php echo esc_attr( $lead_endpoint ); ?>">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                                <span>Copy URL</span>
                            </button>
                        </div>
                    </div>

                    <!-- Supported Parameters Table -->
                    <div style="margin-top: 24px;">
                        <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 8px;">
                            Supported Form Fields
                        </div>
                        <table class="ofp-saas-table">
                            <thead>
                                <tr>
                                    <th style="width: 130px;">Field Key</th>
                                    <th style="width: 100px;">Requirement</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>client_id</code></td>
                                    <td><span style="color: <?php echo ! empty( $custom_domain ) ? 'var(--text-muted)' : '#ef4444; font-weight: 700;'; ?>"><?php echo ! empty( $custom_domain ) ? 'Optional' : 'Required'; ?></span></td>
                                    <td>Your agency identifier (<code><?php echo esc_html( $client->id ); ?></code>). <?php echo ! empty( $custom_domain ) ? 'Automatically detected from your custom domain if omitted.' : 'Pass as a hidden input.'; ?></td>
                                </tr>
                                <tr>
                                    <td><code>name</code></td>
                                    <td><span style="color: #ef4444; font-weight: 700;">Required</span></td>
                                    <td>Full name of the lead.</td>
                                </tr>
                                <tr>
                                    <td><code>phone</code></td>
                                    <td><span style="color: #ef4444; font-weight: 700;">Required</span></td>
                                    <td>Lead contact phone number for SMS and voice pipelines.</td>
                                </tr>
                                <tr>
                                    <td><code>email</code></td>
                                    <td><span style="color: var(--text-muted);">Optional</span></td>
                                    <td>Email address of the prospect.</td>
                                </tr>
                                <tr>
                                    <td><code>message</code></td>
                                    <td><span style="color: var(--text-muted);">Optional</span></td>
                                    <td>Custom inquiry note or message from the prospect.</td>
                                </tr>
                                <tr>
                                    <td><code>property_id</code></td>
                                    <td><span style="color: var(--text-muted);">Optional</span></td>
                                    <td>ID or title of the property the inquiry relates to.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- HTML Example Block -->
                    <div class="ofp-code-card">
                        <div class="ofp-code-bar">
                            <span>Ready-to-Use HTML Snippet</span>
                            <button type="button" class="ofp-btn-action ofp-btn-copy" data-copy-target="#ofp-form-sample-code">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                                <span>Copy Code</span>
                            </button>
                        </div>
                        <pre class="ofp-code-body" id="ofp-form-sample-code">&lt;form action="<?php echo esc_html( $lead_endpoint ); ?>" method="POST"&gt;
    &lt;input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>"&gt;
    &lt;input type="text" name="name" placeholder="Full Name" required&gt;
    &lt;input type="tel" name="phone" placeholder="Phone Number" required&gt;
    &lt;input type="email" name="email" placeholder="Email Address"&gt;
    &lt;button type="submit"&gt;Send Inquiry&lt;/button&gt;
&lt;/form&gt;</pre>
                    </div>

                    <div class="ofp-saas-notice">
                        <div class="ofp-notice-icon">
                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>
                            </svg>
                        </div>
                        <div>
                            Submitted leads are automatically tagged with source <code>form</code> and immediately trigger your automated SMS pipeline according to your settings.
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── PANEL 2: Portal & Domain ──────────────────────────────── -->
            <div id="panel-domain" class="ofp-tab-panel">
                <div class="ofp-saas-card">
                    <div class="ofp-panel-header">
                        <div class="ofp-panel-header-left">
                            <div class="ofp-panel-icon-wrap" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                                <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                                </svg>
                            </div>
                            <div>
                                <h2 class="ofp-panel-title">Dedicated Agency Portal</h2>
                                <p class="ofp-panel-desc">Your dedicated team access point for logging into the CRM, managing leads, and viewing performance.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Domain Status Card -->
                    <?php if ( $client_subdomain && $base_domain ) :
                        $full_domain = $client_subdomain . '.' . $base_domain;
                        $portal_url  = 'https://' . $full_domain;
                    ?>
                        <div style="background: rgba(59, 130, 246, 0.04); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 12px; padding: 24px; margin-bottom: 24px;">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                                <span class="ofp-pill ofp-pill-green">
                                    <span class="ofp-pulse-dot"></span> Active &amp; Routing
                                </span>
                                <span style="font-size: 12px; color: var(--text-muted);">Assigned Subdomain</span>
                            </div>

                            <div style="font-size: 22px; font-weight: 700; color: var(--text-main); font-family: 'JetBrains Mono', ui-monospace, monospace; margin-bottom: 16px; word-break: break-all;">
                                <?php echo esc_html( $full_domain ); ?>
                            </div>

                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <a href="<?php echo esc_url( $portal_url . '/login' ); ?>" target="_blank" class="ofp-btn-action" style="background: var(--brand-color, #3b82f6); color: #fff; padding: 9px 16px; font-size: 13px;">
                                    <span>Open Team Login</span>
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line>
                                    </svg>
                                </a>

                                <button type="button" class="ofp-btn-action ofp-btn-copy" data-copy="<?php echo esc_attr( $portal_url ); ?>" style="padding: 9px 16px; font-size: 13px;">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                    <span>Copy Portal URL</span>
                                </button>
                            </div>
                        </div>
                    <?php else : ?>
                        <div style="background: rgba(245, 158, 11, 0.06); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 12px; padding: 20px; margin-bottom: 24px;">
                            <div style="font-size: 13.5px; font-weight: 600; color: #f59e0b; margin-bottom: 4px;">Subdomain Pending Setup</div>
                            <div style="font-size: 13px; color: var(--text-muted);">Please configure a subdomain in your agency profile to activate your custom URL.</div>
                        </div>
                    <?php endif; ?>

                    <!-- Architecture Overview -->
                    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 10px;">
                        Routing Architecture
                    </div>
                    <div class="ofp-spec-grid">
                        <div class="ofp-spec-tile">
                            <div class="ofp-spec-title">
                                <svg width="15" height="15" fill="none" stroke="var(--accent-blue)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                Agent &amp; Team Login
                            </div>
                            <p class="ofp-spec-desc">
                                Routes directly to <code>/login</code> on your subdomain. Dedicated exclusively for you and your staff.
                            </p>
                        </div>

                        <div class="ofp-spec-tile">
                            <div class="ofp-spec-title">
                                <svg width="15" height="15" fill="none" stroke="var(--accent-green)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline>
                                </svg>
                                Single Property Listings
                            </div>
                            <p class="ofp-spec-desc">
                                Public property cards link to individual listing pages with your contact numbers and lead capture forms.
                            </p>
                        </div>
                    </div>

                    <!-- White Label Custom Domain -->
                    <?php if ( ! empty( $client->is_white_label ) ) : ?>
                    <div style="margin-top: 28px; padding-top: 22px; border-top: 1px solid var(--border-color, rgba(255, 255, 255, 0.08));">
                        <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 12px;">
                            White-Label Custom Domain (Enterprise)
                        </div>

                        <?php if ( ! empty( $client->custom_domain ) ) : ?>
                            <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 10px; padding: 18px;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                                    <span class="ofp-pill ofp-pill-green">Verified</span>
                                    <span style="font-size: 14px; font-weight: 700; color: #10b981; font-family: monospace;"><?php echo esc_html( $client->custom_domain ); ?></span>
                                </div>
                                <p style="font-size: 13px; color: var(--text-muted); margin: 0;">
                                    All portal routes and property listings are fully mapped under your corporate domain.
                                </p>
                            </div>
                        <?php else : ?>
                            <div class="ofp-spec-tile" style="padding: 18px;">
                                <div style="font-size: 13.5px; font-weight: 600; color: var(--text-main); margin-bottom: 6px;">
                                    Connect Your Own Domain (e.g., <code>portal.yourcompany.com</code>)
                                </div>
                                <p style="font-size: 13px; color: var(--text-muted); line-height: 1.5; margin: 0 0 14px 0;">
                                    White-label subscribers can map their own branded domain. Add a CNAME record at your DNS provider pointing to our base host.
                                </p>
                                <table class="ofp-saas-table" style="margin: 0;">
                                    <thead>
                                        <tr>
                                            <th>Record Type</th>
                                            <th>Host / Name</th>
                                            <th>Target / Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><code>CNAME</code></td>
                                            <td><code>portal</code> (or subdomain)</td>
                                            <td><code><?php echo esc_html( $base_domain ? $base_domain : 'mypropertyhq.ng' ); ?></code></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── PANEL 3: Property Feed Widget ─────────────────────────── -->
            <div id="panel-property-widget" class="ofp-tab-panel">
                <div class="ofp-saas-card">
                    <div class="ofp-panel-header">
                        <div class="ofp-panel-header-left">
                            <div class="ofp-panel-icon-wrap" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                                <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="14" width="7" height="7"></rect>
                                    <rect x="3" y="14" width="7" height="7"></rect>
                                </svg>
                            </div>
                            <div>
                                <h2 class="ofp-panel-title">Embeddable Property Widget</h2>
                                <p class="ofp-panel-desc">Embed live property cards on WordPress, Elementor, Wix, Squarespace, or custom websites with one script tag.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Embed Code Display -->
                    <div class="ofp-param-group">
                        <div class="ofp-param-label">
                            <span>Ready-to-Use Embed Code</span>
                            <span class="ofp-pill ofp-pill-blue" style="font-size: 10px; padding: 1px 7px;">HTML + JS</span>
                        </div>
                        <div class="ofp-code-card" style="margin-top: 0;">
                            <div class="ofp-code-bar">
                                <span>Copy into any HTML widget on your website</span>
                                <button type="button" class="ofp-btn-action ofp-btn-copy" data-copy-target="#ofp-embed-snippet-raw">
                                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                    <span>Copy Snippet</span>
                                </button>
                            </div>
                            <pre class="ofp-code-body" id="ofp-embed-snippet-raw"><?php echo esc_html( $embed_code ); ?></pre>
                        </div>
                    </div>

                    <!-- Configuration Options -->
                    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin: 24px 0 10px 0;">
                        Widget Attributes &amp; Customization
                    </div>
                    <div class="ofp-spec-grid">
                        <div class="ofp-spec-tile">
                            <div class="ofp-spec-title">
                                <span style="font-family: monospace; color: var(--accent-blue);">data-limit</span>
                                <span class="ofp-pill ofp-pill-blue" style="font-size: 9px; padding: 1px 6px;">Optional</span>
                            </div>
                            <p class="ofp-spec-desc">
                                Number of properties to render in the grid (e.g., <code>data-limit="3"</code>, <code>data-limit="6"</code>, or <code>data-limit="12"</code>). Default: 6.
                            </p>
                        </div>

                        <div class="ofp-spec-tile">
                            <div class="ofp-spec-title">
                                <span style="font-family: monospace; color: var(--accent-blue);">data-type</span>
                                <span class="ofp-pill ofp-pill-blue" style="font-size: 9px; padding: 1px 6px;">Optional</span>
                            </div>
                            <p class="ofp-spec-desc">
                                Filter listings by category. Options: <code>sale</code>, <code>rent</code>, or <code>land</code>. Default: all active listings.
                            </p>
                        </div>
                    </div>

                    <div class="ofp-saas-notice">
                        <div class="ofp-notice-icon">
                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>
                            </svg>
                        </div>
                        <div>
                            Zero dependencies required. The script automatically isolates its styling, fetches live active properties with your prices and bed/bath counts, and renders responsive cards on any page.
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<?php wp_footer(); ?>
</body>
</html>
