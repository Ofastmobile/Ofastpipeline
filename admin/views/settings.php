<?php
/**
 * Admin View: Settings (Super Admin Only) — Redesigned Experience
 *
 * Modern, tabbed configuration dashboard for OFast Pipeline.
 * Features:
 * - High-precision colored SVG icon system (no emojis)
 * - Real-time system health status bar
 * - Intuitive sidebar navigation with persisted tabs
 * - Interactive eyeball password toggles for sensitive credentials
 * - One-click Webhook URL copy-to-clipboard
 * - Click-to-insert placeholder chips for SMS/Voice templates
 * - Live SMS character count & segment tracker
 * - Interactive Bank Account card preview
 * - Unified one-click saving with sticky action bar
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_super_admin() ) wp_die( 'Access denied.' );

// ── State & Config Values ────────────────────────────────────────────────────
$smtp_mode          = get_option( 'ofp_smtp_mode', 'default' );
$crm_domain         = get_option( 'ofp_crm_base_domain', '' );
$app_name           = get_option( 'ofp_app_name', '' );
$use_app_host       = get_option( 'ofp_use_app_host', '1' );
$online_payments    = get_option( 'ofp_online_buyer_payments', '0' );
$hide_admin_bar     = get_option( 'ofp_hide_admin_bar', '1' );
$watermark_enabled  = get_option( 'ofp_watermark_enabled', '1' );
$watermark_text     = get_option( 'ofp_watermark_text', '' );
$pixel_id           = get_option( 'ofp_global_pixel_id', '' );

// SMTP
$smtp_host          = get_option( 'ofp_smtp_host', '' );
$smtp_port          = get_option( 'ofp_smtp_port', 587 );
$smtp_user          = get_option( 'ofp_smtp_username', '' );
$smtp_enc           = get_option( 'ofp_smtp_encryption', 'tls' );
$smtp_from_email    = get_option( 'ofp_smtp_from_email', '' );
$smtp_from_name     = get_option( 'ofp_smtp_from_name', '' );
$has_smtp_pass      = ! empty( get_option( 'ofp_smtp_password', '' ) );

// Paystack
$has_paystack_key   = ! empty( get_option( 'ofp_paystack_secret_key', '' ) );
$paystack_key_raw   = OFP_Security::decrypt( get_option( 'ofp_paystack_secret_key', '' ) );
$paystack_is_live   = str_starts_with( $paystack_key_raw, 'sk_live_' );
$paystack_is_test   = str_starts_with( $paystack_key_raw, 'sk_test_' );

// SMS Providers
$at_user            = get_option( 'ofp_at_username', '' );
$has_at_key         = ! empty( get_option( 'ofp_at_api_key', '' ) );
$at_sender          = get_option( 'ofp_at_sender_id', '' );
$at_phone           = get_option( 'ofp_at_phone_number', '' );

$has_bsmsn_key      = ! empty( get_option( 'ofp_bsmsn_api_key', '' ) );
$bsmsn_sender       = get_option( 'ofp_bsmsn_sender_id', '' );

$has_smartsms_key   = ! empty( get_option( 'ofp_smartsms_api_key', '' ) );
$smartsms_sender    = get_option( 'ofp_smartsms_sender_id', '' );
$smartsms_routing   = get_option( 'ofp_smartsms_routing', '3' );

// Turnstile
$turnstile_site     = get_option( 'ofp_turnstile_site_key', '' );
$has_turnstile_sec  = ! empty( get_option( 'ofp_turnstile_secret', '' ) );

// Prembly (Identitypass)
$prembly_app_id     = get_option( 'ofp_prembly_app_id', '' );
$prembly_env        = get_option( 'ofp_prembly_env', 'test' );
$has_prembly_sec    = ! empty( get_option( 'ofp_prembly_secret_key', '' ) );

// Bank Details
$bank_name          = get_option( 'ofp_company_bank_name', '' );
$bank_acc_no        = get_option( 'ofp_company_account_no', '' );
$bank_acc_name      = get_option( 'ofp_company_account_name', '' );
$has_bank_set       = ! empty( $bank_name ) && ! empty( $bank_acc_no );

// Social Media Channels
$social_facebook    = get_option( 'ofp_social_facebook', '' );
$social_instagram   = get_option( 'ofp_social_instagram', '' );
$social_twitter     = get_option( 'ofp_social_twitter', '' );
$social_linkedin    = get_option( 'ofp_social_linkedin', '' );
$social_youtube     = get_option( 'ofp_social_youtube', '' );
$social_tiktok      = get_option( 'ofp_social_tiktok', '' );
$social_whatsapp    = get_option( 'ofp_social_whatsapp', '' );
$social_telegram    = get_option( 'ofp_social_telegram', '' );

// Plans & Pricing
$listing_prices     = OFP_Property_CPT::get_plan_prices();
$listing_caps       = OFP_Property_CPT::get_plan_caps();
$pricing_cards      = class_exists( 'OFP_Property_CPT' ) ? OFP_Property_CPT::get_pricing_cards() : [];


// Encryption Vault Status
$has_vault_key      = ! empty( get_option( 'ofp_encryption_key', '' ) );

$is_portal = ( isset( $_GET['tab'] ) || strpos( ( $_SERVER['REQUEST_URI'] ?? '' ), 'admin-portal' ) !== false );

if ( ! $is_portal && file_exists( OFP_PATH . 'admin/views/partials/header.php' ) ) {
    include OFP_PATH . 'admin/views/partials/header.php';
}
?>

<div class="ofp-settings-wrap <?php echo $is_portal ? 'is-portal-mode' : 'is-standalone-mode'; ?>">

    <?php if ( ! $is_portal ) : ?>
    <!-- ── Standalone SaaS Command Header (WP Admin Only) ────────────────────── -->
    <div class="ofp-saas-header">
        <div class="ofp-saas-header-content">
            <div class="ofp-saas-breadcrumbs">
                <span class="ofp-crumb-app">OFast Pipeline</span>
                <span class="ofp-crumb-sep">/</span>
                <span class="ofp-crumb-section">Administration</span>
                <span class="ofp-crumb-sep">/</span>
                <span class="ofp-crumb-current">Platform Settings</span>
            </div>

            <div class="ofp-saas-title-row">
                <div class="ofp-saas-title-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                </div>
                <div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <h1 class="ofp-saas-title">Platform Infrastructure &amp; Settings</h1>
                        <span class="ofp-saas-badge ofp-badge-super">Super Admin</span>
                        <span class="ofp-saas-badge ofp-badge-live"><span class="ofp-pulse-ring"></span> Production Active</span>
                        <span class="ofp-saas-badge ofp-badge-vault">AES-256 Vault</span>
                    </div>
                    <p class="ofp-saas-subtitle">
                        Configure pipeline relays, payment gateways, multi-provider SMS engines, SMTP mailers, anti-bot security, and pricing models.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Master Settings Form ─────────────────────────────────────────── -->
    <form id="ofp-master-settings-form" method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'ofp_save_settings' ); ?>
        <input type="hidden" name="action" value="ofp_save_settings">
        <input type="hidden" name="ofp_active_tab" id="ofp_active_tab" value="tab-general">

        <!-- ── Settings SaaS Header Toolbar: Telemetry & Actions ────────────── -->
        <div class="ofp-settings-toolbar">
            <div class="ofp-settings-telemetry-strip">
                <span class="ofp-telemetry-badge-title">TELEMETRY</span>
                <div class="ofp-telemetry-chips">
                    <!-- Email Status -->
                    <button type="button" class="ofp-telemetry-chip ofp-nav-trigger" data-target="tab-email" title="Click to view Email settings">
                        <span class="ofp-status-dot <?php echo $smtp_mode === 'custom' ? 'dot-green' : 'dot-blue'; ?>"></span>
                        <span class="ofp-telemetry-label">Mail:</span>
                        <span class="ofp-telemetry-val"><?php echo $smtp_mode === 'custom' ? 'Custom SMTP' : 'WP Mail'; ?></span>
                    </button>

                    <!-- Paystack Status -->
                    <button type="button" class="ofp-telemetry-chip ofp-nav-trigger" data-target="tab-payments" title="Click to view Paystack settings">
                        <span class="ofp-status-dot <?php echo $paystack_is_live ? 'dot-green' : ( $paystack_is_test ? 'dot-yellow' : ( $has_paystack_key ? 'dot-green' : 'dot-gray' ) ); ?>"></span>
                        <span class="ofp-telemetry-label">Paystack:</span>
                        <span class="ofp-telemetry-val"><?php echo $paystack_is_live ? 'Live' : ( $paystack_is_test ? 'Sandbox' : ( $has_paystack_key ? 'Ready' : 'Setup' ) ); ?></span>
                    </button>

                    <!-- SMS Status -->
                    <button type="button" class="ofp-telemetry-chip ofp-nav-trigger" data-target="tab-sms" title="Click to view SMS settings">
                        <span class="ofp-status-dot <?php echo ( $has_at_key || $has_smartsms_key || $has_bsmsn_key ) ? 'dot-green' : 'dot-yellow'; ?>"></span>
                        <span class="ofp-telemetry-label">SMS:</span>
                        <span class="ofp-telemetry-val"><?php echo $has_at_key ? "Africa's Talking" : ( $has_smartsms_key ? 'SmartSMS' : ( $has_bsmsn_key ? 'BulkSMS' : 'None' ) ); ?></span>
                    </button>

                    <!-- Turnstile Status -->
                    <button type="button" class="ofp-telemetry-chip ofp-nav-trigger" data-target="tab-security" title="Click to view Security & Anti-Bot settings">
                        <span class="ofp-status-dot <?php echo ( ! empty( $turnstile_site ) && $has_turnstile_sec ) ? 'dot-green' : 'dot-blue'; ?>"></span>
                        <span class="ofp-telemetry-label">Defense:</span>
                        <span class="ofp-telemetry-val"><?php echo ( ! empty( $turnstile_site ) && $has_turnstile_sec ) ? 'Turnstile' : 'Bypass'; ?></span>
                    </button>

                    <!-- Bank Status -->
                    <button type="button" class="ofp-telemetry-chip ofp-nav-trigger" data-target="tab-bank" title="Click to view Bank Details">
                        <span class="ofp-status-dot <?php echo $has_bank_set ? 'dot-green' : 'dot-gray'; ?>"></span>
                        <span class="ofp-telemetry-label">Bank:</span>
                        <span class="ofp-telemetry-val"><?php echo $has_bank_set ? esc_html( wp_trim_words( $bank_name, 2, '' ) ) : 'Not Set'; ?></span>
                    </button>
                </div>
            </div>

            <div class="ofp-settings-toolbar-actions">
                <!-- Real-time Filter -->
                <div class="ofp-settings-search-box" title="Quickly filter any setting (⌘K)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" id="ofp-settings-filter-input" placeholder="Filter settings, keys, webhooks..." autocomplete="off">
                    <span class="ofp-search-kbd">⌘K</span>
                </div>

                <!-- Top Save Button -->
                <button type="button" class="ofp-btn-saas-save" onclick="document.getElementById('ofp-master-settings-form').submit();">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                        <polyline points="17 21 17 13 7 13 7 21"></polyline>
                        <polyline points="7 3 7 8 15 8"></polyline>
                    </svg>
                    <span>Save Settings</span>
                    <span class="ofp-save-shortcut">⌘S</span>
                </button>
            </div>
        </div>

        <!-- ── Modern Horizontal Segmented Tabs Navigation Bar ─────────────────── -->
        <div class="ofp-settings-tabs-header-bar">
            <div class="ofp-settings-tabs-scroll" id="ofp-settings-tabs-scroll">
                <button type="button" class="ofp-settings-tab-btn is-active" data-tab="tab-general">
                    <span class="ofp-icon-box ofp-icon-xs icon-blue">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" x2="22" y1="12" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10z"/></svg>
                    </span>
                    <span class="ofp-tab-label">General &amp; Routing</span>
                    <span class="ofp-nav-pill">Core</span>
                </button>

                <button type="button" class="ofp-settings-tab-btn" data-tab="tab-pipeline">
                    <span class="ofp-icon-box ofp-icon-xs icon-indigo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </span>
                    <span class="ofp-tab-label">Message Templates</span>
                    <span class="ofp-nav-pill">Auto</span>
                </button>

                <button type="button" class="ofp-settings-tab-btn" data-tab="tab-email">
                    <span class="ofp-icon-box ofp-icon-xs icon-sky">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    </span>
                    <span class="ofp-tab-label">Email (SMTP)</span>
                    <span class="ofp-nav-pill"><?php echo $smtp_mode === 'custom' ? 'SMTP' : 'WP'; ?></span>
                </button>

                <button type="button" class="ofp-settings-tab-btn" data-tab="tab-payments">
                    <span class="ofp-icon-box ofp-icon-xs icon-emerald">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                    </span>
                    <span class="ofp-tab-label">Payment Gateway</span>
                    <span class="ofp-nav-pill">Paystack</span>
                </button>

                <button type="button" class="ofp-settings-tab-btn" data-tab="tab-sms">
                    <span class="ofp-icon-box ofp-icon-xs icon-violet">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4.93 4.93a10 10 0 0 1 14.14 0"/><path d="M7.76 7.76a6 6 0 0 1 8.48 0"/><circle cx="12" cy="12" r="2"/><line x1="12" x2="12" y1="14" y2="22"/></svg>
                    </span>
                    <span class="ofp-tab-label">SMS &amp; Voice</span>
                    <span class="ofp-nav-pill">Relays</span>
                </button>

                <button type="button" class="ofp-settings-tab-btn" data-tab="tab-pricing">
                    <span class="ofp-icon-box ofp-icon-xs icon-amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2H2v10l9.29 9.29c.94.94 2.48.94 3.42 0l6.58-6.58c.94-.94.94-2.48 0-3.42L12 2Z"/><circle cx="7" cy="7" r="1.5"/></svg>
                    </span>
                    <span class="ofp-tab-label">Plans &amp; Pricing</span>
                    <span class="ofp-nav-pill">Tiers</span>
                </button>

                <button type="button" class="ofp-settings-tab-btn" data-tab="tab-bank">
                    <span class="ofp-icon-box ofp-icon-xs icon-teal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/></svg>
                    </span>
                    <span class="ofp-tab-label">Company Bank</span>
                    <span class="ofp-nav-pill">Direct</span>
                </button>

                <button type="button" class="ofp-settings-tab-btn" data-tab="tab-security">
                    <span class="ofp-icon-box ofp-icon-xs icon-rose">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><rect width="8" height="6" x="8" y="10" rx="1"/></svg>
                    </span>
                    <span class="ofp-tab-label">Security &amp; Vault</span>
                    <span class="ofp-nav-pill">AES-256</span>
                </button>
            </div>
        </div>

        <div class="ofp-settings-layout">

            <!-- ── Full-Width Content Panels ──────────────────────────────────── -->
            <main class="ofp-settings-panels">

                <!-- ════════ TAB 1: GENERAL & ROUTING ════════ -->
                <section id="tab-general" class="ofp-tab-pane is-active">
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-blue">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"/>
                                        <line x1="2" x2="22" y1="12" y2="12"/>
                                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Brand Identity & Core Routing</h3>
                                    <p>Configure product branding, domain routing, and multi-tenant subdomains.</p>
                                </div>
                            </div>
                            <span class="ofp-pill ofp-pill-ready">Active</span>
                        </div>
                        <div class="ofp-card-body">
                            <div class="ofp-field-grid">
                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">Platform App Name</label>
                                    </div>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_app_name"
                                               value="<?php echo esc_attr( $app_name ); ?>"
                                               placeholder="<?php echo esc_attr( OFP_Brand::DEFAULT_NAME ); ?>">
                                    </div>
                                    <p class="ofp-field-hint">Replaces <code>OFast Pipeline</code> and <code>Bofast World / Bofast Homes</code> everywhere across the marketplace, client portals, browser titles, emails, and notifications. Default: <code><?php echo esc_html( OFP_Brand::DEFAULT_NAME ); ?></code>.</p>
                                </div>

                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">Platform Base Domain</label>
                                    </div>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_crm_base_domain"
                                               value="<?php echo esc_attr( $crm_domain ); ?>"
                                               placeholder="e.g. ofastpipeline.com">
                                    </div>
                                    <p class="ofp-field-hint">Bare domain (no https://). Used to route <code>app.yourdomain</code> and client custom domains like <code>tenant.yourdomain</code>.</p>
                                </div>

                                <div class="ofp-field-group ofp-field-full">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">Global Meta Pixel ID</label>
                                    </div>
                                    <div class="ofp-input-box" style="max-width: 380px;">
                                        <input type="text" name="ofp_global_pixel_id"
                                               value="<?php echo esc_attr( $pixel_id ); ?>"
                                               placeholder="e.g. 123456789012345">
                                    </div>
                                    <p class="ofp-field-hint">Injected globally across the primary marketing website for retargeting and analytics tracking.</p>
                                </div>
                            </div>

                            <hr style="border:0;border-top:1px solid var(--ofp-slate-100);margin:24px 0;">

                            <h4 style="margin:0 0 16px;font-size:14px;font-weight:700;color:var(--ofp-slate-800);">Routing & Payment Switches</h4>

                            <!-- Switch 1: app.yourdomain -->
                            <div class="ofp-switch-row">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">Dedicated App Subdomain Routing (app.yourdomain)</span>
                                    <p class="ofp-switch-desc">When enabled, dashboard login and navigation links redirect to <code>app.yourdomain</code> instead of standard WordPress paths. Turn this off if DNS wildcard or app subdomain is not yet pointed.</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_use_app_host" value="1" <?php checked( $use_app_host, '1' ); ?>>
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>

                            <!-- Switch 2: Paystack card payments for buyers/tenants -->
                            <div class="ofp-switch-row">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">Direct Card Payments for Buyers & Tenants (Paystack)</span>
                                    <p class="ofp-switch-desc">Allows end-user buyers and tenants to complete payments directly via online card checkout. When disabled, customers must pay via bank transfer to the property owner's account with manual approval.</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_online_buyer_payments" value="1" <?php checked( $online_payments, '1' ); ?>>
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>

                            <!-- Switch 3: Hide WP Admin Bar on Frontend -->
                            <div class="ofp-switch-row">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">Hide WordPress Admin Top Bar on Frontend</span>
                                    <p class="ofp-switch-desc">Hides the black WordPress admin bar for logged-in users and administrators across the public frontend website and client portal. Keeps the layout clean and prevents header overlap.</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_hide_admin_bar" value="1" <?php checked( $hide_admin_bar, '1' ); ?>>
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>

                            <!-- Switch 4: Auto Watermark Property Photos -->
                            <div class="ofp-switch-row">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">Auto Watermark Property Photos with Platform/Agency Brand</span>
                                    <p class="ofp-switch-desc">Automatically brands all uploaded property photos (JPEG, PNG, WebP) with your platform name or the white-labeled agency name to protect listing images from unauthorized scraping.</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_watermark_enabled" value="1" <?php checked( $watermark_enabled, '1' ); ?>>
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>

                            <div class="ofp-field-group ofp-field-full" style="margin-top: 14px; padding-top: 14px; border-top: 1px dashed var(--ofp-slate-200, #e2e8f0);">
                                <div class="ofp-field-label-row">
                                    <label class="ofp-field-label">Custom Watermark Text (Optional)</label>
                                </div>
                                <div class="ofp-input-box" style="max-width: 420px;">
                                    <input type="text" name="ofp_watermark_text"
                                           value="<?php echo esc_attr( $watermark_text ); ?>"
                                           placeholder="e.g. <?php echo esc_attr( OFP_Brand::name() ); ?>">
                                </div>
                                <p class="ofp-field-hint">Leave blank to automatically use your configured Platform App Name (<code><?php echo esc_html( OFP_Brand::name() ); ?></code>) or the client's White-Label Agency name.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Social Media Channels Card -->
                    <div class="ofp-card" style="margin-top: 24px;">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-purple">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="18" cy="5" r="3"/>
                                        <circle cx="6" cy="12" r="3"/>
                                        <circle cx="18" cy="19" r="3"/>
                                        <line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/>
                                        <line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Social Media Channels</h3>
                                    <p>Connect your brand's official social media profiles. Only channels with a configured link will appear across the global navigation menu and footer.</p>
                                </div>
                            </div>
                            <span class="ofp-pill ofp-pill-purple">Public Links</span>
                        </div>
                        <div class="ofp-card-body">
                            <div class="ofp-field-grid">
                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">Facebook URL</label>
                                    </div>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_social_facebook"
                                               value="<?php echo esc_attr( $social_facebook ); ?>"
                                               placeholder="https://facebook.com/yourpage">
                                    </div>
                                    <p class="ofp-field-hint">e.g. https://facebook.com/bofasthomes</p>
                                </div>

                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">Instagram URL</label>
                                    </div>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_social_instagram"
                                               value="<?php echo esc_attr( $social_instagram ); ?>"
                                               placeholder="https://instagram.com/yourprofile">
                                    </div>
                                    <p class="ofp-field-hint">e.g. https://instagram.com/bofasthomes</p>
                                </div>

                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">X / Twitter URL</label>
                                    </div>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_social_twitter"
                                               value="<?php echo esc_attr( $social_twitter ); ?>"
                                               placeholder="https://x.com/yourhandle">
                                    </div>
                                    <p class="ofp-field-hint">e.g. https://x.com/bofasthomes</p>
                                </div>

                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">LinkedIn URL</label>
                                    </div>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_social_linkedin"
                                               value="<?php echo esc_attr( $social_linkedin ); ?>"
                                               placeholder="https://linkedin.com/company/yourbrand">
                                    </div>
                                    <p class="ofp-field-hint">e.g. https://linkedin.com/company/bofasthomes</p>
                                </div>

                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">YouTube URL</label>
                                    </div>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_social_youtube"
                                               value="<?php echo esc_attr( $social_youtube ); ?>"
                                               placeholder="https://youtube.com/@yourchannel">
                                    </div>
                                    <p class="ofp-field-hint">e.g. https://youtube.com/@bofasthomes</p>
                                </div>

                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">TikTok URL</label>
                                    </div>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_social_tiktok"
                                               value="<?php echo esc_attr( $social_tiktok ); ?>"
                                               placeholder="https://tiktok.com/@yourhandle">
                                    </div>
                                    <p class="ofp-field-hint">e.g. https://tiktok.com/@bofasthomes</p>
                                </div>

                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">WhatsApp Direct Link / Number</label>
                                    </div>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_social_whatsapp"
                                               value="<?php echo esc_attr( $social_whatsapp ); ?>"
                                               placeholder="https://wa.me/2348000000000 or phone number">
                                    </div>
                                    <p class="ofp-field-hint">e.g. https://wa.me/2348123456789 or 08012345678</p>
                                </div>

                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">Telegram Channel / Support</label>
                                    </div>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_social_telegram"
                                               value="<?php echo esc_attr( $social_telegram ); ?>"
                                               placeholder="https://t.me/yourchannel">
                                    </div>
                                    <p class="ofp-field-hint">e.g. https://t.me/bofasthomes</p>
                                </div>
                            </div>
                            <p class="ofp-field-hint" style="margin-top: 16px;">
                                <strong>Note:</strong> Leave any unused social channels completely blank. Only channels with a valid link will be visible to public visitors.
                            </p>
                        </div>
                    </div>
                </section>

                <!-- ════════ TAB 2: PIPELINE MESSAGE TEMPLATES ════════ -->
                <section id="tab-pipeline" class="ofp-tab-pane">
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-indigo">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Default Automated Lead Messages</h3>
                                    <p>Pre-configured messages copied to new client accounts upon signup. Clients can further customize these in their dashboard.</p>
                                </div>
                            </div>
                            <span class="ofp-pill ofp-pill-info">Drip Automation</span>
                        </div>
                        <div class="ofp-card-body">

                            <!-- Instant SMS -->
                            <div class="ofp-field-group" style="margin-bottom: 24px;">
                                <div class="ofp-field-label-row">
                                    <label class="ofp-field-label">1. Instant Welcome SMS (Sent immediately upon lead capture)</label>
                                    <div class="ofp-chips-container">
                                        <span class="ofp-chips-label">Insert:</span>
                                        <button type="button" class="ofp-chip" data-insert="{{name}}" data-target="ofp_instant_sms">+ {{name}}</button>
                                        <button type="button" class="ofp-chip" data-insert="{{phone}}" data-target="ofp_instant_sms">+ {{phone}}</button>
                                        <button type="button" class="ofp-chip" data-insert="{{business_name}}" data-target="ofp_instant_sms">+ {{business_name}}</button>
                                    </div>
                                </div>
                                <textarea id="ofp_instant_sms" name="ofp_default_instant_sms" rows="3" class="ofp-sms-counter-input"
                                          placeholder="Hi {{name}}, thank you for your interest! We will be in touch shortly. - {{business_name}}"><?php echo esc_textarea( get_option( 'ofp_default_instant_sms', '' ) ); ?></textarea>
                                <div class="ofp-char-counter-bar">
                                    <span class="ofp-field-hint">Sent immediately when an inquiry form is submitted.</span>
                                    <span class="ofp-char-pill">0 chars (1 SMS)</span>
                                </div>
                            </div>

                            <!-- Instant Email -->
                            <div class="ofp-field-group" style="margin-bottom: 24px;">
                                <div class="ofp-field-label-row">
                                    <label class="ofp-field-label">1b. Instant Welcome Email (Sent immediately with the Instant SMS)</label>
                                </div>
                                <div class="ofp-input-box" style="margin-bottom: 10px;">
                                    <input type="text" name="ofp_default_instant_email_subject"
                                           value="<?php echo esc_attr( get_option( 'ofp_default_instant_email_subject', '' ) ); ?>"
                                           placeholder="Thank you for contacting {{business_name}}">
                                </div>
                                <textarea id="ofp_instant_email" name="ofp_default_instant_email_message" rows="4"
                                          placeholder="Hi {{name}}, thank you for contacting {{business_name}}. We have received your message and one of our team will reach out to you shortly. If you need us urgently, reply to this email or call us."><?php echo esc_textarea( get_option( 'ofp_default_instant_email_message', '' ) ); ?></textarea>
                                <div class="ofp-char-counter-bar">
                                    <span class="ofp-field-hint">Sent to leads who gave an email. Clients can change it on their Automation page. When a client leaves it empty, this text is used. Email costs no credit.</span>
                                </div>
                            </div>

                            <!-- New lead alert default -->
                            <div class="ofp-switch-row" style="margin-bottom: 24px;">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">New lead alert SMS to the client (default for new clients)</span>
                                    <p class="ofp-switch-desc">When a lead comes in, the client always gets a bell alert and an email based on their notification setting. This switch decides whether new clients also get an SMS to their own phone. It uses 1 SMS credit per lead. Clients can change it on their Automation page.</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_default_alert_sms" value="1" <?php checked( get_option( 'ofp_default_alert_sms', '1' ), '1' ); ?>>
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>

                            <!-- Follow-up 1 -->
                            <div class="ofp-field-group" style="margin-bottom: 24px;">
                                <div class="ofp-field-label-row">
                                    <label class="ofp-field-label">2. Follow-up 1 Message (SMS — 1 hour later)</label>
                                    <div class="ofp-chips-container">
                                        <span class="ofp-chips-label">Insert:</span>
                                        <button type="button" class="ofp-chip" data-insert="{{name}}" data-target="ofp_followup_1">+ {{name}}</button>
                                        <button type="button" class="ofp-chip" data-insert="{{phone}}" data-target="ofp_followup_1">+ {{phone}}</button>
                                        <button type="button" class="ofp-chip" data-insert="{{business_name}}" data-target="ofp_followup_1">+ {{business_name}}</button>
                                    </div>
                                </div>
                                <textarea id="ofp_followup_1" name="ofp_default_followup_1" rows="3" class="ofp-sms-counter-input"
                                          placeholder="Hi {{name}}, just checking in — did you get our message? We would love to help. - {{business_name}}"><?php echo esc_textarea( get_option( 'ofp_default_followup_1', '' ) ); ?></textarea>
                                <div class="ofp-char-counter-bar">
                                    <span class="ofp-field-hint">Scheduled 1 hour after initial lead capture.</span>
                                    <span class="ofp-char-pill">0 chars (1 SMS)</span>
                                </div>
                            </div>

                            <!-- Follow-up 2 -->
                            <div class="ofp-field-group" style="margin-bottom: 24px;">
                                <div class="ofp-field-label-row">
                                    <label class="ofp-field-label">3. Follow-up 2 Message (SMS, 24 hours later)</label>
                                    <div class="ofp-chips-container">
                                        <span class="ofp-chips-label">Insert:</span>
                                        <button type="button" class="ofp-chip" data-insert="{{name}}" data-target="ofp_followup_2">+ {{name}}</button>
                                        <button type="button" class="ofp-chip" data-insert="{{business_name}}" data-target="ofp_followup_2">+ {{business_name}}</button>
                                    </div>
                                </div>
                                <textarea id="ofp_followup_2" name="ofp_default_followup_2" rows="4"
                                          placeholder="Hi {{name}}, this is {{business_name}}. We are still happy to help you with {{property}}. Reply to this message or call us anytime."><?php echo esc_textarea( get_option( 'ofp_default_followup_2', '' ) ); ?></textarea>
                                <p class="ofp-field-hint">Sent as an SMS. Placeholders: {{name}} {{business_name}} {{property}} {{phone}}.</p>
                            </div>

                            <!-- Follow-up 3 -->
                            <div class="ofp-field-group">
                                <div class="ofp-field-label-row">
                                    <label class="ofp-field-label">4. Follow-up 3 Message (SMS — 72 hours later)</label>
                                    <div class="ofp-chips-container">
                                        <span class="ofp-chips-label">Insert:</span>
                                        <button type="button" class="ofp-chip" data-insert="{{name}}" data-target="ofp_followup_3">+ {{name}}</button>
                                        <button type="button" class="ofp-chip" data-insert="{{phone}}" data-target="ofp_followup_3">+ {{phone}}</button>
                                        <button type="button" class="ofp-chip" data-insert="{{business_name}}" data-target="ofp_followup_3">+ {{business_name}}</button>
                                    </div>
                                </div>
                                <textarea id="ofp_followup_3" name="ofp_default_followup_3" rows="3" class="ofp-sms-counter-input"
                                          placeholder="Hi {{name}}, we have been trying to reach you. We would love to show you how {{business_name}} can help. Call or message us anytime."><?php echo esc_textarea( get_option( 'ofp_default_followup_3', '' ) ); ?></textarea>
                                <div class="ofp-char-counter-bar">
                                    <span class="ofp-field-hint">Final automated touchpoint for cold leads.</span>
                                    <span class="ofp-char-pill">0 chars (1 SMS)</span>
                                </div>
                            </div>

                            <!-- Automatic one way calls -->
                            <?php
                            $vn_hours = OFP_Voice_Notice::hours();
                            $vn_fields = [
                                'interest'    => [ '5. Lead interest call (right after a form lead is captured)', '{{name}} {{business_name}} {{property}}' ],
                                'installment' => [ '6. Installment due day call', '{{name}} {{business_name}} {{property}} {{amount}} {{due_date}}' ],
                                'rent'        => [ '7. Rent due day call', '{{name}} {{business_name}} {{property}} {{amount}} {{due_date}}' ],
                            ];
                            foreach ( $vn_fields as $vn_type => $vn_info ) : ?>
                            <div class="ofp-field-group">
                                <div class="ofp-field-label-row">
                                    <label class="ofp-field-label"><?php echo esc_html( $vn_info[0] ); ?></label>
                                </div>
                                <textarea id="ofp_default_call_<?php echo esc_attr( $vn_type ); ?>" name="ofp_default_call_<?php echo esc_attr( $vn_type ); ?>" rows="3"><?php echo esc_textarea( OFP_Voice_Notice::default_script( $vn_type ) ); ?></textarea>
                                <p class="ofp-field-hint">One way call, no key presses. Starting text for clients who have not written their own. You can use: <?php echo esc_html( $vn_info[1] ); ?></p>
                            </div>
                            <?php endforeach; ?>

                            <div class="ofp-field-group">
                                <div class="ofp-field-label-row">
                                    <label class="ofp-field-label">8. Calling hours for all automatic calls</label>
                                </div>
                                <label style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                                    <input type="checkbox" name="ofp_voice_hours_enabled" value="1" <?php checked( $vn_hours['enabled'] ); ?>>
                                    Only call inside these hours (a call due outside them waits for the next morning)
                                </label>
                                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                                    <label>From
                                        <select name="ofp_voice_hours_start">
                                            <?php for ( $h = 0; $h <= 23; $h++ ) : ?>
                                                <option value="<?php echo (int) $h; ?>" <?php selected( $vn_hours['start'], $h ); ?>><?php echo esc_html( gmdate( 'g A', mktime( $h, 0, 0, 1, 1, 2000 ) ) ); ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </label>
                                    <label>To
                                        <select name="ofp_voice_hours_end">
                                            <?php for ( $h = 1; $h <= 24; $h++ ) : ?>
                                                <option value="<?php echo (int) $h; ?>" <?php selected( $vn_hours['end'], $h ); ?>><?php echo esc_html( gmdate( 'g A', mktime( $h % 24, 0, 0, 1, 1, 2000 ) ) ); ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </label>
                                </div>
                                <p class="ofp-field-hint">Hours use the time zone set in WordPress Settings, General. Make sure it is set to your city (for example Lagos).</p>
                            </div>

                        </div>
                    </div>
                </section>

                <!-- ════════ TAB 3: EMAIL / SMTP ════════ -->
                <section id="tab-email" class="ofp-tab-pane">
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-sky">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect width="20" height="16" x="2" y="4" rx="2"/>
                                        <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Email Delivery & SMTP Relay</h3>
                                    <p>Select your delivery engine. Custom SMTP is recommended for high deliverability.</p>
                                </div>
                            </div>
                            <?php if ( $smtp_mode === 'custom' && $has_smtp_pass ) : ?>
                                <span class="ofp-pill ofp-pill-ready">SMTP Configured</span>
                            <?php else : ?>
                                <span class="ofp-pill ofp-pill-info">Using WordPress Mail</span>
                            <?php endif; ?>
                        </div>
                        <div class="ofp-card-body">

                            <!-- Visual Delivery Mode Selector -->
                            <div class="ofp-mode-grid">
                                <label class="ofp-mode-card <?php echo $smtp_mode !== 'custom' ? 'is-active' : ''; ?>">
                                    <input type="radio" name="ofp_smtp_mode" value="default" <?php checked( $smtp_mode !== 'custom', true ); ?>>
                                    <div class="ofp-mode-text">
                                        <strong>WordPress Default Mail</strong>
                                        <small>Uses local PHP mail() or an existing SMTP plugin on this site.</small>
                                    </div>
                                </label>

                                <label class="ofp-mode-card <?php echo $smtp_mode === 'custom' ? 'is-active' : ''; ?>">
                                    <input type="radio" name="ofp_smtp_mode" value="custom" <?php checked( $smtp_mode, 'custom' ); ?>>
                                    <div class="ofp-mode-text">
                                        <strong>Dedicated Custom SMTP Relay</strong>
                                        <small>Connect directly to Brevo, Sendgrid, Mailgun, or your custom mail server.</small>
                                    </div>
                                </label>
                            </div>

                            <!-- Custom SMTP Fields -->
                            <div id="ofp-smtp-credentials-panel" style="<?php echo $smtp_mode !== 'custom' ? 'display:none;' : ''; ?>">
                                <div class="ofp-field-grid">
                                    <div class="ofp-field-group">
                                        <div class="ofp-field-label-row">
                                            <label class="ofp-field-label">SMTP Host</label>
                                        </div>
                                        <div class="ofp-input-box">
                                            <input type="text" name="ofp_smtp_host"
                                                   value="<?php echo esc_attr( $smtp_host ); ?>"
                                                   placeholder="smtp-relay.brevo.com">
                                        </div>
                                    </div>

                                    <div class="ofp-field-group">
                                        <div class="ofp-field-label-row">
                                            <label class="ofp-field-label">SMTP Port</label>
                                        </div>
                                        <div class="ofp-input-box">
                                            <input type="number" id="ofp_smtp_port" name="ofp_smtp_port"
                                                   value="<?php echo esc_attr( $smtp_port ); ?>"
                                                   placeholder="587">
                                        </div>
                                        <div class="ofp-port-presets">
                                            <span class="ofp-chips-label">Presets:</span>
                                            <button type="button" class="ofp-port-btn" onclick="document.getElementById('ofp_smtp_port').value=587;document.getElementById('ofp_smtp_enc').value='tls';">587 (TLS)</button>
                                            <button type="button" class="ofp-port-btn" onclick="document.getElementById('ofp_smtp_port').value=465;document.getElementById('ofp_smtp_enc').value='ssl';">465 (SSL)</button>
                                            <button type="button" class="ofp-port-btn" onclick="document.getElementById('ofp_smtp_port').value=25;document.getElementById('ofp_smtp_enc').value='tls';">25 (Standard)</button>
                                        </div>
                                    </div>

                                    <div class="ofp-field-group">
                                        <div class="ofp-field-label-row">
                                            <label class="ofp-field-label">Encryption Protocol</label>
                                        </div>
                                        <div class="ofp-input-box">
                                            <select id="ofp_smtp_enc" name="ofp_smtp_encryption">
                                                <option value="tls" <?php selected( $smtp_enc, 'tls' ); ?>>TLS (Recommended)</option>
                                                <option value="ssl" <?php selected( $smtp_enc, 'ssl' ); ?>>SSL</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="ofp-field-group">
                                        <div class="ofp-field-label-row">
                                            <label class="ofp-field-label">SMTP Username</label>
                                        </div>
                                        <div class="ofp-input-box">
                                            <input type="text" name="ofp_smtp_username"
                                                   value="<?php echo esc_attr( $smtp_user ); ?>"
                                                   placeholder="user@yourdomain.com">
                                        </div>
                                    </div>

                                    <div class="ofp-field-group">
                                        <div class="ofp-field-label-row">
                                            <label class="ofp-field-label">SMTP Password</label>
                                            <?php if ( $has_smtp_pass ) : ?>
                                                <span class="ofp-badge-encrypted">✓ Encrypted in Vault</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ofp-input-box has-action">
                                            <input type="password" name="ofp_smtp_password" class="ofp-secret-input"
                                                   placeholder="<?php echo $has_smtp_pass ? '•••••••••••••••• (Leave blank to keep existing)' : 'Enter SMTP password'; ?>">
                                            <button type="button" class="ofp-input-action-btn ofp-btn-toggle-pwd" title="Show/Hide Password">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </button>
                                        </div>
                                        <p class="ofp-field-hint">Leave blank to retain current encrypted password.</p>
                                    </div>

                                    <div class="ofp-field-group">
                                        <div class="ofp-field-label-row">
                                            <label class="ofp-field-label">From Email Address</label>
                                        </div>
                                        <div class="ofp-input-box">
                                            <input type="email" name="ofp_smtp_from_email"
                                                   value="<?php echo esc_attr( $smtp_from_email ); ?>"
                                                   placeholder="noreply@yourdomain.com">
                                        </div>
                                    </div>

                                    <div class="ofp-field-group">
                                        <div class="ofp-field-label-row">
                                            <label class="ofp-field-label">From Display Name</label>
                                        </div>
                                        <div class="ofp-input-box">
                                            <input type="text" name="ofp_smtp_from_name"
                                                   value="<?php echo esc_attr( $smtp_from_name ); ?>"
                                                   placeholder="<?php echo esc_attr( OFP_Brand::name() ); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Test Email Box -->
                            <div class="ofp-test-email-card">
                                <div class="ofp-test-email-info">
                                    <div class="ofp-icon-box ofp-icon-sm icon-sky" style="margin-right:4px;">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="22" x2="11" y1="2" y2="13"/>
                                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <strong style="font-size:14px;color:var(--ofp-slate-900);">Verify Email Configuration</strong>
                                        <p style="margin:2px 0 0;font-size:12px;color:var(--ofp-slate-500);">Send a live test message to <code><?php echo esc_html( wp_get_current_user()->user_email ); ?></code> to confirm delivery.</p>
                                    </div>
                                </div>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_test_email' ), 'ofp_test_email' ) ); ?>"
                                   class="ofp-btn-test-email"
                                   onclick="return confirm('Send an email test to <?php echo esc_js( wp_get_current_user()->user_email ); ?>?');">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" x2="11" y1="2" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                                    Send Test Email
                                </a>
                            </div>

                        </div>
                    </div>
                </section>

                <!-- ════════ TAB 4: PAYMENT GATEWAY (PAYSTACK) ════════ -->
                <section id="tab-payments" class="ofp-tab-pane">
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-emerald">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect width="20" height="14" x="2" y="5" rx="2"/>
                                        <line x1="2" x2="22" y1="10" y2="10"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Paystack Payment Gateway</h3>
                                    <p>Primary gateway for client subscriptions, credit top-ups, and optional buyer/tenant checkouts.</p>
                                </div>
                            </div>
                            <?php if ( $paystack_is_live ) : ?>
                                <span class="ofp-pill ofp-pill-ready">Live Mode</span>
                            <?php elseif ( $paystack_is_test ) : ?>
                                <span class="ofp-pill ofp-pill-missing">Test / Sandbox Mode</span>
                            <?php elseif ( $has_paystack_key ) : ?>
                                <span class="ofp-pill ofp-pill-ready">Configured</span>
                            <?php else : ?>
                                <span class="ofp-pill ofp-pill-missing">Needs Setup</span>
                            <?php endif; ?>
                        </div>
                        <div class="ofp-card-body">

                            <div class="ofp-field-grid">
                                <div class="ofp-field-group ofp-field-full">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">Paystack Secret Key</label>
                                        <?php if ( $has_paystack_key ) : ?>
                                            <span class="ofp-badge-encrypted">✓ Encrypted in Vault</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ofp-input-box has-action">
                                        <input type="password" name="ofp_paystack_secret_key" class="ofp-secret-input"
                                               placeholder="<?php echo $has_paystack_key ? '•••••••••••••••• (Leave blank to keep existing)' : 'sk_live_... or sk_test_...'; ?>">
                                        <button type="button" class="ofp-input-action-btn ofp-btn-toggle-pwd" title="Show/Hide Key">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    </div>
                                    <p class="ofp-field-hint">Must begin with <code>sk_live_</code> (production) or <code>sk_test_</code> (development). Leave empty to retain existing stored key.</p>
                                </div>
                            </div>

                            <hr style="border:0;border-top:1px solid var(--ofp-slate-100);margin:24px 0;">

                            <!-- Webhook URL Box -->
                            <div class="ofp-field-group">
                                <div class="ofp-field-label-row">
                                    <label class="ofp-field-label">Paystack Webhook Endpoint URL</label>
                                    <span class="ofp-pill ofp-pill-info">Automatic Verification</span>
                                </div>
                                <div class="ofp-copy-input-wrap">
                                    <code id="ofp-webhook-url"><?php echo esc_url( home_url( '/wp-json/ofp/v1/webhook/payment' ) ); ?></code>
                                    <button type="button" class="ofp-copy-btn" data-clipboard-target="#ofp-webhook-url">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                        <span>Copy URL</span>
                                    </button>
                                </div>
                                <p class="ofp-field-hint">Add this URL to your <strong>Paystack Dashboard → Settings → API & Webhooks → Webhook URL</strong> to enable automatic payment confirmation.</p>
                            </div>

                        </div>
                    </div>
                </section>

                <!-- ════════ TAB 5: SMS & VOICE PROVIDERS ════════ -->
                <section id="tab-sms" class="ofp-tab-pane">

                    <!-- Active SMS provider (one for the whole platform) -->
                    <?php
                    $sms_providers      = OFP_SMS::providers();
                    $active_sms         = OFP_SMS::platform_provider();
                    ?>
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-card-title-group">
                                    <h3>Active SMS Provider</h3>
                                    <p>The one company that sends every SMS on the platform. Clients do not choose a provider. Fill in the keys for the provider you pick below. Voice calls always use Africa's Talking.</p>
                                </div>
                            </div>
                            <?php if ( OFP_SMS::ready() ) : ?>
                                <span class="ofp-pill ofp-pill-ready">Ready</span>
                            <?php else : ?>
                                <span class="ofp-pill ofp-pill-missing">No API key saved for this provider</span>
                            <?php endif; ?>
                        </div>
                        <div class="ofp-card-body">
                            <div class="ofp-field-group">
                                <label class="ofp-field-label" for="ofp_sms_provider">Send all SMS through</label>
                                <div class="ofp-input-box">
                                    <select name="ofp_sms_provider" id="ofp_sms_provider">
                                        <?php foreach ( $sms_providers as $slug => $info ) : ?>
                                            <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $active_sms, $slug ); ?>><?php echo esc_html( $info['label'] ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Africa's Talking Card -->
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-amber">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M4.93 4.93a10 10 0 0 1 14.14 0"/>
                                        <path d="M7.76 7.76a6 6 0 0 1 8.48 0"/>
                                        <circle cx="12" cy="12" r="2"/>
                                        <line x1="12" x2="12" y1="14" y2="22"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Africa's Talking (SMS and Voice IVR)</h3>
                                    <p>High-volume pan-African gateway handling primary outbound SMS and automated voice calls.</p>
                                </div>
                            </div>
                            <?php if ( $has_at_key ) : ?>
                                <span class="ofp-pill ofp-pill-ready">Active Primary</span>
                            <?php else : ?>
                                <span class="ofp-pill ofp-pill-missing">Not Configured</span>
                            <?php endif; ?>
                        </div>
                        <div class="ofp-card-body">
                            <div class="ofp-field-grid">
                                <div class="ofp-field-group">
                                    <label class="ofp-field-label">Username</label>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_at_username"
                                               value="<?php echo esc_attr( $at_user ); ?>"
                                               placeholder="e.g. sandbox or your_app_name">
                                    </div>
                                </div>

                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">API Key</label>
                                        <?php if ( $has_at_key ) : ?><span class="ofp-badge-encrypted">✓ Encrypted</span><?php endif; ?>
                                    </div>
                                    <div class="ofp-input-box has-action">
                                        <input type="password" name="ofp_at_api_key" class="ofp-secret-input"
                                               placeholder="<?php echo $has_at_key ? '••••••••••••••••' : 'Enter AT API Key'; ?>">
                                        <button type="button" class="ofp-input-action-btn ofp-btn-toggle-pwd" title="Show/Hide Key">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="ofp-field-group">
                                    <label class="ofp-field-label">Sender ID (SMS)</label>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_at_sender_id"
                                               value="<?php echo esc_attr( $at_sender ); ?>"
                                               placeholder="e.g. OFastPipe">
                                    </div>
                                </div>

                                <div class="ofp-field-group">
                                    <label class="ofp-field-label">Virtual Phone Number (Voice Calls)</label>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_at_phone_number"
                                               value="<?php echo esc_attr( $at_phone ); ?>"
                                               placeholder="e.g. +2348000000000">
                                    </div>
                                    <p class="ofp-field-hint">Caller ID used for call script calls (lead interest and payment reminders).</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SmartSMSSolutions Card -->
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-emerald">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>SmartSMSSolutions (Nigerian Corporate Route)</h3>
                                    <p>Local Nigerian SMS relay featuring corporate DND bypass routing for guaranteed deliverability.</p>
                                </div>
                            </div>
                            <?php if ( $has_smartsms_key ) : ?>
                                <span class="ofp-pill ofp-pill-ready">Configured</span>
                            <?php else : ?>
                                <span class="ofp-pill ofp-pill-missing">Optional</span>
                            <?php endif; ?>
                        </div>
                        <div class="ofp-card-body">
                            <div class="ofp-field-grid">
                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">API Token</label>
                                        <?php if ( $has_smartsms_key ) : ?><span class="ofp-badge-encrypted">✓ Encrypted</span><?php endif; ?>
                                    </div>
                                    <div class="ofp-input-box has-action">
                                        <input type="password" name="ofp_smartsms_api_key" class="ofp-secret-input"
                                               placeholder="<?php echo $has_smartsms_key ? '••••••••••••••••' : 'Enter SmartSMS API token'; ?>">
                                        <button type="button" class="ofp-input-action-btn ofp-btn-toggle-pwd" title="Show/Hide Token">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="ofp-field-group">
                                    <label class="ofp-field-label">Sender ID</label>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_smartsms_sender_id"
                                               value="<?php echo esc_attr( $smartsms_sender ); ?>"
                                               placeholder="e.g. OFastPipe">
                                    </div>
                                </div>

                                <div class="ofp-field-group">
                                    <label class="ofp-field-label">Delivery Routing Channel</label>
                                    <div class="ofp-input-box">
                                        <select name="ofp_smartsms_routing">
                                            <option value="3" <?php selected( $smartsms_routing, '3' ); ?>>Corporate Route (DND Bypass — Recommended)</option>
                                            <option value="2" <?php selected( $smartsms_routing, '2' ); ?>>Standard Route</option>
                                            <option value="6" <?php selected( $smartsms_routing, '6' ); ?>>Refund Route</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- BulkSMS Nigeria Card -->
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-slate">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                                        <path d="M21 3v5h-5"/>
                                        <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
                                        <path d="M3 21v-5h5"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>BulkSMS Nigeria (Secondary Fallback)</h3>
                                    <p>Secondary fallback relay engaged automatically if the primary provider is unreachable.</p>
                                </div>
                            </div>
                            <?php if ( $has_bsmsn_key ) : ?>
                                <span class="ofp-pill ofp-pill-ready">Fallback Ready</span>
                            <?php else : ?>
                                <span class="ofp-pill ofp-pill-missing">Optional</span>
                            <?php endif; ?>
                        </div>
                        <div class="ofp-card-body">
                            <div class="ofp-field-grid">
                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">BulkSMS API Key</label>
                                        <?php if ( $has_bsmsn_key ) : ?><span class="ofp-badge-encrypted">✓ Encrypted</span><?php endif; ?>
                                    </div>
                                    <div class="ofp-input-box has-action">
                                        <input type="password" name="ofp_bsmsn_api_key" class="ofp-secret-input"
                                               placeholder="<?php echo $has_bsmsn_key ? '••••••••••••••••' : 'Enter BulkSMS API Key'; ?>">
                                        <button type="button" class="ofp-input-action-btn ofp-btn-toggle-pwd" title="Show/Hide Key">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="ofp-field-group">
                                    <label class="ofp-field-label">Sender ID</label>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_bsmsn_sender_id"
                                               value="<?php echo esc_attr( $bsmsn_sender ); ?>"
                                               placeholder="e.g. OFastPipe">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </section>

                <!-- ════════ TAB 6: PLANS & PRICING ════════ -->
                <section id="tab-pricing" class="ofp-tab-pane">
                    
                    <!-- Property Marketplace Listing Plans & Pricing Cards Customizer -->
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-amber">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 2H2v10l9.29 9.29c.94.94 2.48.94 3.42 0l6.58-6.58c.94-.94.94-2.48 0-3.42L12 2Z"/>
                                        <circle cx="7" cy="7" r="1.5"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Pricing Cards & Feature Matrix Customizer</h3>
                                    <p>Fully customize the client-facing pricing cards: tier names, prices, property caps, comparison matrix, and checklist bullets.</p>
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <button type="button" id="ofp-admin-toggle-all-cards" class="ofp-btn ofp-btn-sm" style="background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; font-size:12px; padding:4px 10px; border-radius:6px; cursor:pointer;">
                                    Toggle All Features
                                </button>
                                <span class="ofp-pill ofp-pill-ready">Live Across Client Portal</span>
                            </div>
                        </div>
                        <div class="ofp-card-body">
                            <div class="ofp-pricing-tier-grid">
                                
                                <?php
                                $tier_tags = [
                                    'free'   => 'Starter Tier',
                                    'silver' => 'Growth',
                                    'gold'   => 'Enterprise',
                                ];
                                $tier_colors = [
                                    'free'   => 'var(--ofp-slate-800)',
                                    'silver' => 'var(--ofp-primary)',
                                    'gold'   => '#d97706',
                                ];

                                foreach ( OFP_Property_CPT::PLAN_KEYS as $plan_key ) :
                                    $p = $pricing_cards[ $plan_key ] ?? [];
                                    $is_silver = ( $plan_key === 'silver' );
                                ?>
                                <div class="ofp-tier-card <?php echo $is_silver ? 'tier-popular' : ''; ?>">
                                    <?php if ( $is_silver ) : ?>
                                        <span class="ofp-tier-badge-top">Most Popular</span>
                                    <?php endif; ?>
                                    
                                    <div class="ofp-tier-header">
                                        <h4 class="ofp-tier-name" style="color:<?php echo esc_attr( $tier_colors[ $plan_key ] ?? 'inherit' ); ?>;">
                                            <?php echo esc_html( $p['name'] ?? ucfirst( $plan_key ) ); ?>
                                        </h4>
                                        <span class="ofp-tier-tag"><?php echo esc_html( $tier_tags[ $plan_key ] ?? 'Tier' ); ?></span>
                                    </div>

                                    <div class="ofp-tier-input-group">
                                        <label>Card Display Name</label>
                                        <div class="ofp-input-box">
                                            <input type="text" name="pricing_card_name_<?php echo esc_attr( $plan_key ); ?>"
                                                   value="<?php echo esc_attr( $p['name'] ?? ucfirst( $plan_key ) ); ?>"
                                                   placeholder="<?php echo esc_attr( ucfirst( $plan_key ) ); ?>">
                                        </div>
                                    </div>

                                    <div class="ofp-tier-input-group">
                                        <label>Card Tagline / Subtitle</label>
                                        <div class="ofp-input-box">
                                            <input type="text" name="pricing_card_desc_<?php echo esc_attr( $plan_key ); ?>"
                                                   value="<?php echo esc_attr( $p['desc'] ?? '' ); ?>"
                                                   placeholder="Short tier description">
                                        </div>
                                    </div>

                                    <div class="ofp-tier-input-group">
                                        <label>Monthly Price (NGN)</label>
                                        <div class="ofp-currency-input-wrap">
                                            <span class="ofp-currency-symbol">₦</span>
                                            <input type="number" step="0.01" min="0"
                                                   name="listing_price_<?php echo esc_attr( $plan_key ); ?>"
                                                   value="<?php echo esc_attr( $p['price'] ?? 0 ); ?>">
                                        </div>
                                    </div>

                                    <div class="ofp-tier-input-group">
                                        <label>Active Property Cap</label>
                                        <div class="ofp-input-box">
                                            <input type="number" step="1" min="1"
                                                   name="listing_cap_<?php echo esc_attr( $plan_key ); ?>"
                                                   value="<?php echo esc_attr( $p['cap'] ?? 1 ); ?>">
                                        </div>
                                    </div>

                                    <!-- Custom Checklist Features Accordion -->
                                    <div class="ofp-tier-accordion-wrap">
                                        <?php 
                                        $features_arr = isset( $p['features'] ) && is_array( $p['features'] ) ? $p['features'] : [];
                                        $feat_count   = count( $features_arr );
                                        ?>
                                        <button type="button" class="ofp-tier-accordion-toggle" aria-expanded="false" data-extra="<?php echo max( 0, $feat_count - 5 ); ?>">
                                            <span class="ofp-tier-accordion-left">
                                                <svg class="ofp-tier-accordion-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <line x1="8" y1="6" x2="21" y2="6"></line>
                                                    <line x1="8" y1="12" x2="21" y2="12"></line>
                                                    <line x1="8" y1="18" x2="21" y2="18"></line>
                                                    <line x1="3" y1="6" x2="3.01" y2="6"></line>
                                                    <line x1="3" y1="12" x2="3.01" y2="12"></line>
                                                    <line x1="3" y1="18" x2="3.01" y2="18"></line>
                                                </svg>
                                                <span class="ofp-tier-accordion-label">Feature Bullets</span>
                                                <span class="ofp-tier-count-pill"><?php echo (int) $feat_count; ?></span>
                                            </span>
                                            <span class="ofp-faq-circle">
                                                <svg class="ofp-faq-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="6 9 12 15 18 9"></polyline>
                                                </svg>
                                            </span>
                                        </button>

                                        <div class="ofp-tier-accordion-body">
                                            <label style="font-size:12px; font-weight:600; color:var(--ofp-slate-700); margin:8px 0 4px; display:block;">Custom Feature Bullets (1 per line)</label>
                                            <textarea name="pricing_card_features_<?php echo esc_attr( $plan_key ); ?>" rows="8" class="ofp-textarea"
                                                      style="width:100%; font-size:13px; font-family:inherit; padding:10px; border-radius:8px; border:1px solid var(--ofp-slate-200); line-height:1.5;"
                                                      placeholder="1 feature bullet per line"><?php 
                                                echo esc_textarea( implode( "\n", $features_arr ) ); 
                                            ?></textarea>
                                            <p class="ofp-field-hint" style="margin-top:4px; font-size:11px;">Renders as checkmark bullet items on the client portal pricing card. Only the top 5 display by default until the FAQ toggle is clicked.</p>
                                        </div>
                                    </div>

                                </div>
                                <?php endforeach; ?>

                            </div>
                        </div>
                    </div>

                </section>

                <!-- ════════ TAB 7: COMPANY BANK ACCOUNT ════════ -->
                <section id="tab-bank" class="ofp-tab-pane">
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-teal">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="3" x2="21" y1="22" y2="22"/>
                                        <line x1="6" x2="6" y1="18" y2="11"/>
                                        <line x1="10" x2="10" y1="18" y2="11"/>
                                        <line x1="14" x2="14" y1="18" y2="11"/>
                                        <line x1="18" x2="18" y1="18" y2="11"/>
                                        <polygon points="12 2 20 7 4 7"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Company Bank Wire Details</h3>
                                    <p>Presented to clients and tenants as the official bank transfer option for manual account top-ups and offline funding.</p>
                                </div>
                            </div>
                            <?php if ( $has_bank_set ) : ?>
                                <span class="ofp-pill ofp-pill-ready">Active</span>
                            <?php else : ?>
                                <span class="ofp-pill ofp-pill-missing">Not Configured</span>
                            <?php endif; ?>
                        </div>
                        <div class="ofp-card-body">
                            
                            <div class="ofp-bank-layout">
                                <!-- Form Fields -->
                                <div class="ofp-field-grid" style="grid-template-columns: 1fr;">
                                    <div class="ofp-field-group">
                                        <label class="ofp-field-label">Bank Institution Name</label>
                                        <div class="ofp-input-box">
                                            <input type="text" id="ofp_bank_name_input" name="company_bank_name"
                                                   value="<?php echo esc_attr( $bank_name ); ?>"
                                                   placeholder="e.g. Zenith Bank / GTBank / Access Bank">
                                        </div>
                                    </div>

                                    <div class="ofp-field-group">
                                        <label class="ofp-field-label">Account Number (NUBAN)</label>
                                        <div class="ofp-input-box">
                                            <input type="text" id="ofp_bank_acc_input" name="company_account_no"
                                                   value="<?php echo esc_attr( $bank_acc_no ); ?>"
                                                   placeholder="e.g. 1020304050">
                                        </div>
                                    </div>

                                    <div class="ofp-field-group">
                                        <label class="ofp-field-label">Account Beneficiary Name</label>
                                        <div class="ofp-input-box">
                                            <input type="text" id="ofp_bank_holder_input" name="company_account_name"
                                                   value="<?php echo esc_attr( $bank_acc_name ); ?>"
                                                   placeholder="e.g. OFast Technologies Ltd">
                                        </div>
                                    </div>
                                </div>

                                <!-- Live Interactive Debit/Bank Card Preview -->
                                <div class="ofp-bank-preview">
                                    <div class="ofp-bank-preview-top">
                                        <div class="ofp-bank-chip"></div>
                                        <span class="ofp-bank-preview-label" id="ofp-card-bank-name"><?php echo esc_html( $bank_name ?: 'BANK NAME' ); ?></span>
                                    </div>
                                    <div class="ofp-bank-preview-num" id="ofp-card-acc-no">
                                        <?php echo esc_html( $bank_acc_no ?: '0000 0000 00' ); ?>
                                    </div>
                                    <div class="ofp-bank-preview-bottom">
                                        <div class="ofp-bank-preview-field">
                                            <span class="ofp-bank-preview-sub">Beneficiary</span>
                                            <span class="ofp-bank-preview-val" id="ofp-card-holder-name"><?php echo esc_html( $bank_acc_name ?: 'OFFICIAL BENEFICIARY' ); ?></span>
                                        </div>
                                        <span style="display:inline-flex;align-items:center;gap:4px;background:rgba(255,255,255,0.1);padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700;letter-spacing:0.05em;color:#cbd5e1;">
                                            <svg width="14" height="10" viewBox="0 0 18 12" fill="none"><rect width="6" height="12" fill="#008751"/><rect x="6" width="6" height="12" fill="#ffffff"/><rect x="12" width="6" height="12" fill="#008751"/></svg> NGN
                                        </span>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </section>

                <!-- ════════ TAB 8: SECURITY & TURNSTILE ════════ -->
                <section id="tab-security" class="ofp-tab-pane">
                    
                    <!-- Cloudflare Turnstile Card -->
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-rose">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                        <path d="m9 12 2 2 4-4"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Cloudflare Turnstile (Anti-Bot Protection)</h3>
                                    <p>Frictionless CAPTCHA alternative protecting registration (/signup) and login (/login) only. Lead and inspection forms are not affected. Skipped on a white-label client's own custom domain unless that hostname is added to the widget in Cloudflare.</p>
                                </div>
                            </div>
                            <?php if ( ! empty( $turnstile_site ) && $has_turnstile_sec ) : ?>
                                <span class="ofp-pill ofp-pill-ready">Active Protection</span>
                            <?php else : ?>
                                <span class="ofp-pill ofp-pill-missing">Bypassed (Dev Mode)</span>
                            <?php endif; ?>
                        </div>
                        <div class="ofp-card-body">
                            <div class="ofp-field-grid">
                                <div class="ofp-field-group">
                                    <label class="ofp-field-label">Turnstile Site Key (Public)</label>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_turnstile_site_key"
                                               value="<?php echo esc_attr( $turnstile_site ); ?>"
                                               placeholder="0x4AAAAAA...">
                                    </div>
                                </div>

                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">Turnstile Secret Key (Server Verification)</label>
                                        <?php if ( $has_turnstile_sec ) : ?><span class="ofp-badge-encrypted">✓ Encrypted</span><?php endif; ?>
                                    </div>
                                    <div class="ofp-input-box has-action">
                                        <input type="password" name="ofp_turnstile_secret" class="ofp-secret-input"
                                               placeholder="<?php echo $has_turnstile_sec ? '••••••••••••••••' : '0x4AAAAAA...'; ?>">
                                        <button type="button" class="ofp-input-action-btn ofp-btn-toggle-pwd" title="Show/Hide Key">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    </div>
                                    <p class="ofp-field-hint">Add every hostname that shows /login or /signup (your domain, app., and client subdomains) to this widget in Cloudflare. Leave blank to switch Turnstile off.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Prembly (Identitypass) Verification & KYC Card -->
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-emerald">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                        <path d="m9 12 2 2 4-4"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Prembly (Identitypass) Identity & Business Verification (KYC/KYB)</h3>
                                    <p>Verifies Nigerian CAC business registrations and NIN/BVN identities for real estate clients and agents.</p>
                                </div>
                            </div>
                            <?php if ( $has_prembly_sec ) : ?>
                                <span class="ofp-pill ofp-pill-ready"><?php echo $prembly_env === 'live' ? 'Live Production' : 'Sandbox Active'; ?></span>
                            <?php else : ?>
                                <span class="ofp-pill ofp-pill-missing">Not Configured</span>
                            <?php endif; ?>
                        </div>
                        <div class="ofp-card-body">
                            <div class="ofp-field-grid">
                                <div class="ofp-field-group">
                                    <label class="ofp-field-label">Prembly App ID <span style="font-size:11px;font-weight:400;color:var(--ofp-slate-500);">(Optional / Legacy)</span></label>
                                    <div class="ofp-input-box">
                                        <input type="text" name="ofp_prembly_app_id"
                                               value="<?php echo esc_attr( $prembly_app_id ); ?>"
                                               placeholder="Leave blank (only needed for legacy accounts)">
                                    </div>
                                    <p class="ofp-field-hint">Prembly now uses unified <code>x-api-key</code> authentication. If you only have Secret & Public keys, leave this blank.</p>
                                </div>

                                <div class="ofp-field-group">
                                    <div class="ofp-field-label-row">
                                        <label class="ofp-field-label">Prembly Secret Key (x-api-key)</label>
                                        <?php if ( $has_prembly_sec ) : ?><span class="ofp-badge-encrypted">✓ Encrypted</span><?php endif; ?>
                                    </div>
                                    <div class="ofp-input-box has-action">
                                        <input type="password" name="ofp_prembly_secret_key" class="ofp-secret-input"
                                               placeholder="<?php echo $has_prembly_sec ? '••••••••••••••••' : 'live_sk_... or test_sk_...'; ?>">
                                        <button type="button" class="ofp-input-action-btn ofp-btn-toggle-pwd" title="Show/Hide Key">
                                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    </div>
                                    <p class="ofp-field-hint">Your <code>x-api-key</code> from Prembly dashboard. Encrypted with AES-256 in database.</p>
                                </div>

                                <div class="ofp-field-group ofp-field-full">
                                    <label class="ofp-field-label">Environment Mode</label>
                                    <div class="ofp-mode-grid ofp-prembly-mode-grid" style="margin-bottom:0; margin-top:6px;">
                                        <label class="ofp-mode-card <?php echo $prembly_env !== 'live' ? 'is-active' : ''; ?>">
                                            <input type="radio" name="ofp_prembly_env" value="test" <?php checked( $prembly_env !== 'live', true ); ?>>
                                            <div class="ofp-mode-text">
                                                <strong>Test Sandbox Mode</strong>
                                                <small>Simulated verification without charging your Prembly wallet balance. Perfect for testing workflows.</small>
                                            </div>
                                        </label>

                                        <label class="ofp-mode-card <?php echo $prembly_env === 'live' ? 'is-active' : ''; ?>">
                                            <input type="radio" name="ofp_prembly_env" value="live" <?php checked( $prembly_env, 'live' ); ?>>
                                            <div class="ofp-mode-text">
                                                <strong>Live Production Mode</strong>
                                                <small>Direct queries against Nigerian CAC, NIMC (NIN), and NIBSS (BVN) registries. Uses live wallet balance.</small>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Security & Vault Specs Card -->
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-violet">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Platform Security & Encryption Vault</h3>
                                    <p>Architecture specifications for data protection and credentials security at rest.</p>
                                </div>
                            </div>
                            <span class="ofp-pill ofp-pill-purple">AES-256-CBC Active</span>
                        </div>
                        <div class="ofp-card-body">
                            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:16px;">
                                <div style="background:var(--ofp-slate-50);border:1px solid var(--ofp-slate-200);border-radius:8px;padding:16px;">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                        <span class="ofp-icon-box ofp-icon-sm icon-amber">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
                                        </span>
                                        <strong style="font-size:13px;color:var(--ofp-slate-900);">Cipher Suite</strong>
                                    </div>
                                    <p style="margin:0;font-size:12px;color:var(--ofp-slate-600);line-height:1.5;">AES-256-CBC with randomized 16-byte initialization vectors (IV) generated uniquely for each installation.</p>
                                </div>
                                <div style="background:var(--ofp-slate-50);border:1px solid var(--ofp-slate-200);border-radius:8px;padding:16px;">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                        <span class="ofp-icon-box ofp-icon-sm icon-violet">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        </span>
                                        <strong style="font-size:13px;color:var(--ofp-slate-900);">Encrypted Fields</strong>
                                    </div>
                                    <p style="margin:0;font-size:12px;color:var(--ofp-slate-600);line-height:1.5;">SMTP passwords, Paystack secret keys, Africa's Talking API keys, BulkSMS & SmartSMS tokens, and Turnstile secrets.</p>
                                </div>
                                <div style="background:var(--ofp-slate-50);border:1px solid var(--ofp-slate-200);border-radius:8px;padding:16px;">
                                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                        <span class="ofp-icon-box ofp-icon-sm icon-blue">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                        </span>
                                        <strong style="font-size:13px;color:var(--ofp-slate-900);">Rate Limiting Engine</strong>
                                    </div>
                                    <p style="margin:0;font-size:12px;color:var(--ofp-slate-600);line-height:1.5;">Active on all public REST endpoints and lead capture webhooks to prevent brute-force attacks and credit draining.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                </section>

            </main>
        </div>

        <!-- ── Sticky Floating Save Action Bar ──────────────────────────── -->
        <div class="ofp-sticky-footer-bar">
            <div class="ofp-sticky-left">
                <span class="ofp-pulse-dot"></span>
                <span>Settings Console Active</span>
            </div>
            <div class="ofp-sticky-right">
                <button type="submit" class="ofp-btn-save-master">
                    <svg viewBox="0 0 24 24"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg>
                    Save All Settings
                </button>
            </div>
        </div>

    </form>
</div>

<!-- ── Settings Interactive Scripts ───────────────────────────────────────── -->
<script>
(function() {
    'use strict';

    // ── 1. Tab Switching & Hash Persistence ──────────────────────────────────
    function switchTab(tabId) {
        if ( ! tabId ) return;
        tabId = tabId.replace(/^#/, '');

        var targetPane = document.getElementById(tabId);
        if ( ! targetPane ) return;

        // Update nav items
        document.querySelectorAll('.ofp-settings-tab-btn, .ofp-nav-item').forEach(function(btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-tab') === tabId);
        });

        // Update panes
        document.querySelectorAll('.ofp-tab-pane').forEach(function(pane) {
            pane.classList.remove('is-active');
        });
        targetPane.classList.add('is-active');

        // Update hidden field & URL hash
        var activeTabInput = document.getElementById('ofp_active_tab');
        if ( activeTabInput ) activeTabInput.value = tabId;

        if ( history.replaceState ) {
            history.replaceState(null, null, '#' + tabId);
        } else {
            location.hash = '#' + tabId;
        }

        // Scroll into view if requested from health card
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Attach click to sidebar nav items
    document.querySelectorAll('.ofp-settings-tab-btn, .ofp-nav-item').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            switchTab(this.getAttribute('data-tab'));
        });
    });

    // Attach click to top health cards
    document.querySelectorAll('.ofp-nav-trigger').forEach(function(card) {
        card.addEventListener('click', function(e) {
            e.preventDefault();
            switchTab(this.getAttribute('data-target'));
        });
    });

    // Check initial hash on page load
    window.addEventListener('DOMContentLoaded', function() {
        var hash = location.hash ? location.hash.replace(/^#/, '') : '';
        if ( hash && document.getElementById(hash) ) {
            switchTab(hash);
        }
    });

    // ── 2. Password Eyeball Show/Hide Toggle ─────────────────────────────────
    document.querySelectorAll('.ofp-btn-toggle-pwd').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var input = this.closest('.ofp-input-box').querySelector('input');
            if ( ! input ) return;

            var isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';

            this.innerHTML = isPassword
                ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
                : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        });
    });

    // ── 3. One-Click Copy to Clipboard ───────────────────────────────────────
    document.querySelectorAll('.ofp-copy-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var targetSelector = this.getAttribute('data-clipboard-target');
            var targetElem = document.querySelector(targetSelector);
            if ( ! targetElem ) return;

            var textToCopy = targetElem.innerText || targetElem.value;
            navigator.clipboard.writeText(textToCopy).then(function() {
                var originalHtml = btn.innerHTML;
                btn.classList.add('is-copied');
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> <span>Copied!</span>';

                setTimeout(function() {
                    btn.classList.remove('is-copied');
                    btn.innerHTML = originalHtml;
                }, 2000);
            });
        });
    });

    // ── 4. Click-to-Insert Merge Tag Chips ───────────────────────────────────
    document.querySelectorAll('.ofp-chip').forEach(function(chip) {
        chip.addEventListener('click', function(e) {
            e.preventDefault();
            var tag = this.getAttribute('data-insert');
            var targetId = this.getAttribute('data-target');
            var textarea = document.getElementById(targetId);
            if ( ! textarea || ! tag ) return;

            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var text = textarea.value;

            textarea.value = text.substring(0, start) + tag + text.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + tag.length;
            textarea.focus();

            // Trigger character counter update
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    // ── 5. Live SMS Character Counter ────────────────────────────────────────
    function updateSmsCounters() {
        document.querySelectorAll('.ofp-sms-counter-input').forEach(function(ta) {
            var counterBar = ta.nextElementSibling;
            if ( ! counterBar || ! counterBar.classList.contains('ofp-char-counter-bar') ) return;
            var pill = counterBar.querySelector('.ofp-char-pill');
            if ( ! pill ) return;

            var count = ta.value.length;
            var segments = Math.ceil(count / 160) || 1;
            pill.textContent = count + ' chars (' + segments + ' SMS)';

            pill.classList.toggle('is-multisms', count > 160);
        });
    }

    document.querySelectorAll('.ofp-sms-counter-input').forEach(function(ta) {
        ta.addEventListener('input', updateSmsCounters);
    });
    updateSmsCounters();

    // ── 6. Live Bank Account Card Preview ────────────────────────────────────
    function syncBankPreview() {
        var bankNameInput   = document.getElementById('ofp_bank_name_input');
        var bankAccInput    = document.getElementById('ofp_bank_acc_input');
        var bankHolderInput = document.getElementById('ofp_bank_holder_input');

        var cardBankName   = document.getElementById('ofp-card-bank-name');
        var cardAccNo      = document.getElementById('ofp-card-acc-no');
        var cardHolderName = document.getElementById('ofp-card-holder-name');

        if ( bankNameInput && cardBankName ) {
            bankNameInput.addEventListener('input', function() {
                cardBankName.textContent = this.value.trim() ? this.value.toUpperCase() : 'BANK NAME';
            });
        }

        if ( bankAccInput && cardAccNo ) {
            bankAccInput.addEventListener('input', function() {
                cardAccNo.textContent = this.value.trim() ? this.value : '0000 0000 00';
            });
        }

        if ( bankHolderInput && cardHolderName ) {
            bankHolderInput.addEventListener('input', function() {
                cardHolderName.textContent = this.value.trim() ? this.value.toUpperCase() : 'OFFICIAL BENEFICIARY';
            });
        }
    }
    syncBankPreview();

    // ── 7. Mode Visual Cards Toggle (SMTP & Prembly) ─────────────────────────
    function syncRadioCards(name) {
        var radios = document.querySelectorAll('input[name="' + name + '"]');
        radios.forEach(function(radio) {
            var card = radio.closest('.ofp-mode-card');
            if ( card ) {
                if ( radio.checked ) {
                    card.classList.add('is-active');
                } else {
                    card.classList.remove('is-active');
                }
            }
        });
    }

    function syncSmtpCards() {
        syncRadioCards('ofp_smtp_mode');
        var selected = document.querySelector('input[name="ofp_smtp_mode"]:checked');
        var panel = document.getElementById('ofp-smtp-credentials-panel');
        if ( panel && selected ) {
            panel.style.display = selected.value === 'custom' ? '' : 'none';
        }
    }

    document.querySelectorAll('input[name="ofp_smtp_mode"]').forEach(function(radio) {
        radio.addEventListener('change', syncSmtpCards);
    });

    document.querySelectorAll('input[name="ofp_prembly_env"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            syncRadioCards('ofp_prembly_env');
        });
    });

    // ── 8. Pricing Tier Cards Features Accordion & FAQ Arrow Toggle ──────────
    var tierGrid = document.querySelector('.ofp-pricing-tier-grid');
    var tierToggles = document.querySelectorAll('.ofp-tier-accordion-toggle');
    var globalTierToggle = document.getElementById('ofp-admin-toggle-all-cards');

    function setTierExpansion(expand) {
        if ( ! tierGrid ) return;
        tierGrid.classList.toggle('is-expanded', expand);
        tierToggles.forEach(function(btn) {
            btn.setAttribute('aria-expanded', expand ? 'true' : 'false');
            var arrow = btn.querySelector('.ofp-faq-arrow');
            if ( arrow ) {
                arrow.classList.toggle('is-rotated', expand);
            }
        });
        if ( globalTierToggle ) {
            globalTierToggle.textContent = expand ? 'Collapse All Features' : 'Toggle All Features';
        }
    }

    if ( tierGrid && tierToggles.length ) {
        tierToggles.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var willExpand = ! tierGrid.classList.contains('is-expanded');
                setTierExpansion(willExpand);
            });
        });

        if ( globalTierToggle ) {
            globalTierToggle.addEventListener('click', function(e) {
                e.preventDefault();
                var willExpand = ! tierGrid.classList.contains('is-expanded');
                setTierExpansion(willExpand);
            });
        }

        // Live update bullet count pill as user types in textarea
        document.querySelectorAll('.ofp-tier-accordion-body textarea').forEach(function(ta) {
            ta.addEventListener('input', function() {
                var lines = this.value.split('\n').filter(function(l) { return l.trim().length > 0; });
                var wrap = this.closest('.ofp-tier-accordion-wrap');
                if ( wrap ) {
                    var pill = wrap.querySelector('.ofp-tier-count-pill');
                    if ( pill ) {
                        pill.textContent = lines.length;
                    }
                }
            });
        });
    }

    // ── 9. Real-Time Settings Search & Filter across Tabs ────────────────────
    var searchInput = document.getElementById('ofp-settings-filter-input');
    var navButtons = document.querySelectorAll('.ofp-settings-tab-btn, .ofp-nav-item');
    var originalPills = {};

    navButtons.forEach(function(btn) {
        var tabKey = btn.getAttribute('data-tab');
        var pill = btn.querySelector('.ofp-nav-pill');
        if ( pill ) {
            originalPills[tabKey] = pill.textContent;
        }
    });

    if ( searchInput ) {
        searchInput.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();
            var firstTabWithMatch = null;

            navButtons.forEach(function(btn) {
                var tabId = btn.getAttribute('data-tab');
                var pane = document.getElementById(tabId);
                var pill = btn.querySelector('.ofp-nav-pill');
                if ( ! pane ) return;

                var cards = pane.querySelectorAll('.ofp-card');
                var matchCount = 0;

                cards.forEach(function(card) {
                    if ( ! q ) {
                        card.style.display = '';
                        return;
                    }
                    var text = (card.textContent || '').toLowerCase();
                    var isMatch = text.indexOf(q) !== -1;
                    card.style.display = isMatch ? '' : 'none';
                    if ( isMatch ) matchCount++;
                });

                if ( ! q ) {
                    if ( pill && originalPills[tabId] ) {
                        pill.textContent = originalPills[tabId];
                        pill.style.background = '';
                        pill.style.color = '';
                    }
                } else {
                    if ( pill ) {
                        pill.textContent = matchCount + ' found';
                        pill.style.background = matchCount > 0 ? '#dcfce7' : '#f1f5f9';
                        pill.style.color = matchCount > 0 ? '#15803d' : '#94a3b8';
                    }
                    if ( matchCount > 0 && ! firstTabWithMatch ) {
                        firstTabWithMatch = tabId;
                    }
                }
            });

            if ( q && firstTabWithMatch ) {
                var activeBtn = document.querySelector('.ofp-settings-tab-btn.is-active, .ofp-nav-item.is-active');
                var currentActiveTab = activeBtn ? activeBtn.getAttribute('data-tab') : '';
                var currentPane = document.getElementById(currentActiveTab);
                var currentHasMatch = currentPane ? currentPane.querySelectorAll('.ofp-card:not([style*="display: none"])').length > 0 : false;
                
                if ( ! currentHasMatch ) {
                    switchTab(firstTabWithMatch);
                }
            }
        });
    }

    // ── 10. Global Keyboard Shortcuts (⌘S to Save, ⌘K to Search) ─────────────
    document.addEventListener('keydown', function(e) {
        // ⌘S or Ctrl+S to save
        if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 's' ) {
            e.preventDefault();
            var form = document.getElementById('ofp-master-settings-form');
            if ( form ) form.submit();
        }

        // ⌘K or Ctrl+K to focus search filter
        if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
            if ( searchInput ) {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        }
    });

})();
</script>

<?php
if ( ! $is_portal && file_exists( OFP_PATH . 'admin/views/partials/footer.php' ) ) {
    include OFP_PATH . 'admin/views/partials/footer.php';
}
?>
