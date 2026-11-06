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

$hide_admin_bar     = get_option( 'ofp_hide_admin_bar', '1' );
$hide_admin_bar_roles = (array) get_option( 'ofp_hide_admin_bar_roles', [] );
$remove_wp_logo     = get_option( 'ofp_remove_wp_logo', '0' );
$remove_new_content = get_option( 'ofp_remove_new_content', '0' );
$custom_greeting_enabled = get_option( 'ofp_custom_greeting_enabled', '0' );
$custom_greeting_text = get_option( 'ofp_custom_greeting_text', 'Welcome' );

$disable_xmlrpc         = get_option( 'ofp_disable_xmlrpc', '0' );
$obfuscate_author_slugs = get_option( 'ofp_obfuscate_author_slugs', '0' );
$obfuscate_emails       = get_option( 'ofp_obfuscate_emails', '0' );
$all_wp_roles           = function_exists( 'wp_roles' ) ? wp_roles()->roles : [];

$watermark_enabled  = get_option( 'ofp_watermark_enabled', '1' );

$watermark_text     = get_option( 'ofp_watermark_text', '' );

$pixel_id           = get_option( 'ofp_global_pixel_id', '' );

$portal_default_dashboard = get_option( 'ofp_portal_default_dashboard', '1' );





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



// Custom Admin URL & Brute-Force Shield

$admin_url_enabled     = (string) get_option( 'ofp_admin_url_enabled', '0' );

$admin_custom_slug     = (string) get_option( 'ofp_admin_custom_slug', '' );

$admin_emergency_key   = (string) get_option( 'ofp_admin_emergency_key', '' );

$security_max_attempts  = (int) get_option( 'ofp_security_max_attempts', 5 );

$security_lockout_dur   = (int) get_option( 'ofp_security_lockout_duration', 15 );

$security_ip_whitelist  = (string) get_option( 'ofp_security_ip_whitelist', '' );

if ( empty( $admin_emergency_key ) ) {

    $admin_emergency_key = wp_generate_password( 32, false );

    update_option( 'ofp_admin_emergency_key', $admin_emergency_key );

}



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



// AI & Live Chat Bridge

$chat_config        = class_exists( 'OFP_Chat_Service' ) ? OFP_Chat_Service::get_config() : [];

$chat_enabled       = $chat_config['enabled'] ?? '1';

$chat_guest_enabled = $chat_config['guest_enabled'] ?? '1';

$chat_guest_human   = $chat_config['guest_human'] ?? '0';

$chat_ai_provider   = $chat_config['ai_provider'] ?? 'gemini';

$chat_gemini_model  = $chat_config['gemini_model'] ?? 'gemini-3.5-flash-lite';

$chat_gemini_fallback = $chat_config['gemini_fallback_model'] ?? 'gemini-3.1-flash-lite';

$chat_groq_model    = $chat_config['groq_model'] ?? 'llama-3.3-70b-versatile';

$chat_tg_chat_id    = $chat_config['telegram_chat_id'] ?? '';

$chat_handoff_msg   = $chat_config['handoff_message'] ?? '';

$chat_tg_bot_user   = class_exists( 'OFP_Chat_Telegram' ) ? OFP_Chat_Telegram::bot_username() : '';

$has_gemini_key     = ! empty( $chat_config['has_gemini_key'] );

$has_groq_key       = ! empty( $chat_config['has_groq_key'] );

$has_tg_bot_token   = ! empty( $chat_config['has_telegram_token'] );

$chat_guest_prompt  = $chat_config['guest_prompt'] ?? '';

$chat_user_prompt   = $chat_config['user_prompt'] ?? '';

$chat_last_ai_error = class_exists( 'OFP_Chat_AI' ) ? OFP_Chat_AI::last_error() : [];



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



                <button type="button" class="ofp-settings-tab-btn" data-tab="tab-ai-chat">

                    <span class="ofp-icon-box ofp-icon-xs icon-indigo">

                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2 2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"></path><rect x="4" y="8" width="16" height="12" rx="4"></rect><circle cx="9" cy="13" r="1.5" fill="currentColor"></circle><circle cx="15" cy="13" r="1.5" fill="currentColor"></circle><path d="M9 17h6"></path></svg>

                    </span>

                    <span class="ofp-tab-label">AI &amp; Live Chat</span>

                    <span class="ofp-nav-pill">Telegram</span>

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



                            <!-- Switch 0: Modern Admin Portal as Default Dashboard -->

                            <div class="ofp-switch-row">

                                <div class="ofp-switch-info">

                                    <span class="ofp-switch-title">Modern Admin Portal as Default Dashboard</span>

                                    <p class="ofp-switch-desc">When enabled, administrators logging into WordPress are automatically redirected to the Modern Executive Admin Dashboard (<code>/admin-portal</code>) instead of classic <code>/wp-admin/</code>. You can still access classic WordPress anytime using the Classic WP Admin toggle in the portal header or by appending <code>?classic=1</code> to any wp-admin URL.</p>

                                </div>

                                <label class="ofp-switch">

                                    <input type="checkbox" name="ofp_portal_default_dashboard" value="1" <?php checked( $portal_default_dashboard, '1' ); ?>>

                                    <span class="ofp-slider"></span>

                                </label>

                            </div>



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



                    <!-- Admin Bar & Dashboard White-Labeling Card -->
                    <div class="ofp-card" style="margin-top: 24px;">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-blue">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>Admin Bar &amp; White-Labeling</h3>
                                    <p>Control admin bar visibility on the frontend, remove standard WordPress brand marks, and customize system greetings.</p>
                                </div>
                            </div>
                            <span class="ofp-pill ofp-pill-ready">Active Controls</span>
                        </div>
                        <div class="ofp-card-body">
                            <!-- Switch: Global Frontend Admin Bar Hide -->
                            <div class="ofp-switch-row">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">Hide Admin Bar Globally on Frontend</span>
                                    <p class="ofp-switch-desc">Completely hides the top admin bar for all visitors and logged-in users on the public frontend website and client portal.</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_hide_admin_bar" value="1" <?php checked( $hide_admin_bar, '1' ); ?>>
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>

                            <!-- Role-based Admin Bar Hiding -->
                            <div style="margin: 18px 0; padding: 14px 0; border-top: 1px dashed var(--ofp-slate-200, #e2e8f0); border-bottom: 1px dashed var(--ofp-slate-200, #e2e8f0);">
                                <label class="ofp-field-label" style="margin-bottom: 4px; display: block; font-weight: 600;">Hide Frontend Admin Bar by Specific Roles</label>
                                <p class="ofp-field-hint" style="margin-bottom: 10px;">Select user roles that should NOT see the admin bar on the frontend (applies when global hide is disabled).</p>
                                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                    <?php foreach ( $all_wp_roles as $role_slug => $role_data ) : ?>
                                        <label style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: var(--ofp-slate-100, #f1f5f9); border: 1px solid var(--ofp-slate-200, #e2e8f0); border-radius: 6px; font-size: 12px; font-weight: 500; color: var(--ofp-slate-700, #334155); cursor: pointer;">
                                            <input type="checkbox" name="ofp_hide_admin_bar_roles[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $hide_admin_bar_roles, true ) ); ?>>
                                            <span><?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Switch: Remove WP Logo -->
                            <div class="ofp-switch-row">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">Remove WordPress Logo from Admin Bar</span>
                                    <p class="ofp-switch-desc">Removes the standard "W" logo dropdown from the top-left corner of the admin bar for a cleaner white-label interface.</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_remove_wp_logo" value="1" <?php checked( $remove_wp_logo, '1' ); ?>>
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>

                            <!-- Switch: Remove "+ New" Creation Menu -->
                            <div class="ofp-switch-row">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">Remove "+ New" Quick Creation Menu</span>
                                    <p class="ofp-switch-desc">Removes the generic "+ New" dropdown (post, page, media) from the top admin bar to streamline navigation.</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_remove_new_content" value="1" <?php checked( $remove_new_content, '1' ); ?>>
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>

                            <!-- Switch & Input: Custom Admin Bar Greeting -->
                            <div class="ofp-switch-row" style="margin-top: 10px;">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">Custom Admin User Greeting</span>
                                    <p class="ofp-switch-desc">Replace the default "Howdy, [Name]" in the top-right account menu with your preferred greeting (e.g. "Welcome", "Hello", "Hi").</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_custom_greeting_enabled" id="ofp_custom_greeting_enabled" value="1" <?php checked( $custom_greeting_enabled, '1' ); ?> onchange="document.getElementById('ofp-greeting-text-wrap').style.display = this.checked ? 'block' : 'none';">
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>

                            <div id="ofp-greeting-text-wrap" style="<?php echo $custom_greeting_enabled === '1' ? '' : 'display:none;'; ?> margin-top: 12px; padding: 14px; background: var(--ofp-slate-50, #f8fafc); border-radius: 6px; border: 1px solid var(--ofp-slate-200, #e2e8f0);">
                                <label class="ofp-field-label" for="ofp_custom_greeting_text" style="font-weight: 600;">Greeting Text Prefix</label>
                                <div class="ofp-input-box" style="max-width: 280px; margin-top: 6px;">
                                    <input type="text" name="ofp_custom_greeting_text" id="ofp_custom_greeting_text" value="<?php echo esc_attr( $custom_greeting_text ); ?>" placeholder="Welcome">
                                </div>
                                <p class="ofp-field-hint" style="margin-top: 6px;">Example preview: <code><?php echo esc_html( ! empty( $custom_greeting_text ) ? $custom_greeting_text : 'Welcome' ); ?>, <?php echo esc_html( wp_get_current_user()->display_name ); ?></code></p>
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

                                    <h3>Africa's Talking (SMS and Voice)</h3>

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

                                                   id="listing_price_<?php echo esc_attr( $plan_key ); ?>"

                                                   class="ofp-plan-monthly-price-input"

                                                   data-plan="<?php echo esc_attr( $plan_key ); ?>"

                                                   value="<?php echo esc_attr( $p['price'] ?? 0 ); ?>">

                                        </div>

                                    </div>



                                    <?php if ( $plan_key !== 'free' ) : ?>

                                    <div style="margin: 12px 0 6px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                        <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--ofp-slate-500, #64748b);">
                                            Period Discounts
                                        </span>
                                        <button type="button" class="ofp-btn-recalc-plan-discounts" data-plan="<?php echo esc_attr( $plan_key ); ?>"
                                                style="background: rgba(37, 99, 235, 0.08); border: 1px solid rgba(37, 99, 235, 0.25); border-radius: 6px; padding: 3px 8px; font-size: 11px; font-weight: 600; color: #2563eb; cursor: pointer; transition: all 0.15s ease;"
                                                title="Recalculate 3m (5%), 6m (10%) and 1yr (15%) discounts from monthly price">
                                            ⚡ Auto-calc (5%, 10%, 15%)
                                        </button>
                                    </div>

                                    <?php 
                                    $discounts = [
                                        3  => [ 'rate' => 0.05, 'label' => '-5% off' ],
                                        6  => [ 'rate' => 0.10, 'label' => '-10% off' ],
                                        12 => [ 'rate' => 0.15, 'label' => '-15% off' ],
                                    ];
                                    foreach ( [ 3, 6, 12 ] as $pp_m ) :
                                        $pp_saved = (float) get_option( "ofp_period_price_{$plan_key}_{$pp_m}", 0 );
                                        $disc_info = $discounts[ $pp_m ];
                                        $monthly_base = (float) ( $p['price'] ?? 0 );
                                        $calc_val = round( $monthly_base * $pp_m * ( 1 - $disc_info['rate'] ), 2 );
                                        $display_val = $pp_saved > 0 ? $pp_saved : ( $monthly_base > 0 ? $calc_val : 0 );
                                    ?>

                                    <div class="ofp-tier-input-group">

                                        <label style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                            <span>
                                                <?php echo (int) $pp_m === 12 ? '1-Year (12m)' : (int) $pp_m . '-Month'; ?> Price (NGN)
                                                <span class="ofp-badge" style="background: rgba(16, 185, 129, 0.12); color: #059669; font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">
                                                    <?php echo esc_html( $disc_info['label'] ); ?>
                                                </span>
                                            </span>
                                            <span class="ofp-period-mo-preview" id="period_mo_preview_<?php echo esc_attr( $plan_key ); ?>_<?php echo (int) $pp_m; ?>" style="font-size: 11px; font-weight: 500; color: var(--ofp-slate-400, #94a3b8);">
                                                <?php if ( $display_val > 0 ) : ?>
                                                    ₦<?php echo number_format( round( $display_val / $pp_m ) ); ?>/mo
                                                <?php endif; ?>
                                            </span>
                                        </label>

                                        <div class="ofp-currency-input-wrap">

                                            <span class="ofp-currency-symbol">₦</span>

                                            <input type="number" step="0.01" min="0"
                                                   name="period_price_<?php echo esc_attr( $plan_key ); ?>_<?php echo (int) $pp_m; ?>"
                                                   id="period_price_<?php echo esc_attr( $plan_key ); ?>_<?php echo (int) $pp_m; ?>"
                                                   class="ofp-period-price-input"
                                                   data-plan="<?php echo esc_attr( $plan_key ); ?>"
                                                   data-months="<?php echo (int) $pp_m; ?>"
                                                   data-discount="<?php echo esc_attr( $disc_info['rate'] ); ?>"
                                                   value="<?php echo esc_attr( $display_val > 0 ? $display_val : '' ); ?>"
                                                   placeholder="<?php echo esc_attr( $calc_val > 0 ? $calc_val : '0.00' ); ?>">

                                        </div>

                                    </div>

                                    <?php endforeach; ?>

                                    <?php endif; ?>



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

                    <!-- ── DETAILED COMPARISON MATRIX ────────────────────────── -->
                    <div class="ofp-card" style="margin-top: 24px;" id="ofp-matrix-card">

                        <div class="ofp-card-header" style="flex-wrap: wrap; gap: 12px;">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-blue">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                        <line x1="3" y1="9" x2="21" y2="9"/>
                                        <line x1="3" y1="15" x2="21" y2="15"/>
                                        <line x1="9" y1="3" x2="9" y2="21"/>
                                        <line x1="15" y1="3" x2="15" y2="21"/>
                                    </svg>
                                </div>
                                <div>
                                    <span class="ofp-section-eyebrow" style="text-transform:uppercase; font-size:11px; font-weight:800; color:var(--ofp-primary, #2563eb); letter-spacing:0.06em; display:block; margin-bottom:2px;">Feature by Feature</span>
                                    <h3 style="margin:0 0 4px 0; font-size:18px; font-weight:800; color:#0f172a;">Full Plan Matrix Comparison</h3>
                                    <p style="margin:0; font-size:13px; color:#64748b;">Click any cell to edit custom text or select ✓ / — &middot; Live synced with client pricing page.</p>
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <button type="button" id="ofp-matrix-add-group-btn" class="ofp-btn ofp-btn-sm" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                    Add Section
                                </button>
                                <button type="button" id="ofp-matrix-save-quick-btn" class="ofp-btn ofp-btn-sm" style="background:var(--ofp-primary, #2563eb); color:#fff; border:none; font-size:12px; font-weight:700; padding:6px 14px; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 1px 2px rgba(37,99,235,0.2);">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                                    <span id="ofp-matrix-save-txt">Save Matrix</span>
                                </button>
                                <span class="ofp-pill ofp-pill-ready">Active on Pricing Page</span>
                            </div>
                        </div>

                        <!-- Hidden serialized JSON input inside the settings form -->
                        <input type="hidden" name="ofp_plan_matrix_json" id="ofp_plan_matrix_json" value="">

                        <div class="ofp-card-body" style="padding: 0; overflow-x: auto;">
                            <?php
                            $matrix_groups = class_exists( 'OFP_Property_CPT' ) ? OFP_Property_CPT::get_plan_matrix() : [];

                            $render_admin_val = function( $val, $col = 'free' ) {
                                $val = trim( (string) $val );
                                if ( $val === '✓' ) {
                                    return '<span class="ofp-badge-check" style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:#dcfce7; color:#16a34a; font-weight:800; font-size:13px;">✓</span>';
                                }
                                if ( $val === '—' || $val === '' ) {
                                    return '<span class="ofp-badge-dash" style="color:#94a3b8; font-size:16px;">—</span>';
                                }
                                $weight = ( $col === 'wl' || $col === 'gold' ) ? '800' : '600';
                                $color = '#1e293b';
                                if ( $col === 'gold' ) {
                                    $color = '#2563eb';
                                } elseif ( $col === 'wl' ) {
                                    $color = '#b45309';
                                }
                                return '<span class="ofp-badge-text" style="font-weight:' . $weight . '; font-size:12px; color:' . $color . ';">' . esc_html( $val ) . '</span>';
                            };
                            ?>

                            <table id="ofp-admin-matrix-table" class="ofp-table" style="width:100%; border-collapse:collapse; min-width:750px; text-align:left;">
                                <thead>
                                    <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                                        <th style="padding:14px 20px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#334155; width:36%;">Platform Features</th>
                                        <th style="padding:14px 14px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#475569; text-align:center; width:16%;">Free (Starter)</th>
                                        <th style="padding:14px 14px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#2563eb; text-align:center; width:16%;">Silver (Growth)</th>
                                        <th style="padding:14px 14px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#d97706; text-align:center; width:16%;">Gold (Enterprise)</th>
                                        <th style="padding:14px 14px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#b45309; text-align:center; width:16%;">White-Label (Solo)</th>
                                    </tr>
                                </thead>
                                <tbody id="ofp-matrix-tbody">
                                    <?php foreach ( $matrix_groups as $group_title => $items ) : ?>
                                        <tr class="ofp-matrix-group-row" data-group-name="<?php echo esc_attr( $group_title ); ?>" style="background:#f1f5f9; border-top:1px solid #e2e8f0; border-bottom:1px solid #e2e8f0;">
                                            <td colspan="5" style="padding:9px 20px;">
                                                 <div style="display:flex; align-items:center; justify-content:space-between;">
                                                    <span class="ofp-matrix-group-title" title="Click to rename section" style="font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:0.06em; color:#475569; cursor:pointer;">
                                                        <?php echo esc_html( $group_title ); ?>
                                                    </span>
                                                    <button type="button" class="ofp-matrix-del-group-btn" title="Delete section" style="background:none; border:none; color:#ef4444; font-size:12px; font-weight:700; cursor:pointer; opacity:0; transition:opacity 0.15s; padding:2px 8px; border-radius:4px;">
                                                        Remove Section
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php foreach ( $items as $row ) : ?>
                                            <tr class="ofp-matrix-row" data-group="<?php echo esc_attr( $group_title ); ?>" style="border-bottom:1px solid #f1f5f9; transition:background 0.15s ease;">
                                                <td class="ofp-matrix-cell" data-col="feature" data-val="<?php echo esc_attr( $row['feature'] ?? '' ); ?>" title="Click to edit feature name" style="padding:12px 20px; font-size:13px; font-weight:600; color:#1e293b;">
                                                    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                                                        <span class="ofp-matrix-display-val"><?php echo esc_html( $row['feature'] ?? '' ); ?></span>
                                                        <button type="button" class="ofp-matrix-del-row-btn" title="Delete feature" style="background:none; border:none; color:#ef4444; cursor:pointer; padding:2px 6px; font-size:16px; line-height:1; opacity:0; transition:opacity 0.15s; border-radius:4px;">&times;</button>
                                                    </div>
                                                </td>
                                                <td class="ofp-matrix-cell" data-col="free" data-val="<?php echo esc_attr( $row['free'] ?? '—' ); ?>" title="Click to edit text or set checkmark" style="padding:12px 14px; text-align:center;">
                                                    <span class="ofp-matrix-display-val"><?php echo $render_admin_val( $row['free'] ?? '—', 'free' ); ?></span>
                                                </td>
                                                <td class="ofp-matrix-cell" data-col="silver" data-val="<?php echo esc_attr( $row['silver'] ?? '—' ); ?>" title="Click to edit text or set checkmark" style="padding:12px 14px; text-align:center;">
                                                    <span class="ofp-matrix-display-val"><?php echo $render_admin_val( $row['silver'] ?? '—', 'silver' ); ?></span>
                                                </td>
                                                <td class="ofp-matrix-cell" data-col="gold" data-val="<?php echo esc_attr( $row['gold'] ?? '—' ); ?>" title="Click to edit text or set checkmark" style="padding:12px 14px; text-align:center;">
                                                    <span class="ofp-matrix-display-val"><?php echo $render_admin_val( $row['gold'] ?? '—', 'gold' ); ?></span>
                                                </td>
                                                <td class="ofp-matrix-cell" data-col="wl" data-val="<?php echo esc_attr( $row['wl'] ?? '—' ); ?>" title="Click to edit text or set checkmark" style="padding:12px 14px; text-align:center;">
                                                    <span class="ofp-matrix-display-val"><?php echo $render_admin_val( $row['wl'] ?? '—', 'wl' ); ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="ofp-matrix-add-row" data-group="<?php echo esc_attr( $group_title ); ?>">
                                            <td colspan="5" style="padding:6px 20px; background:#fafbfc; border-bottom:1px solid #e2e8f0; text-align:left;">
                                                <button type="button" class="ofp-matrix-add-feature-btn" data-group="<?php echo esc_attr( $group_title ); ?>" style="display:inline-flex; align-items:center; gap:6px; background:none; border:none; color:var(--ofp-primary, #2563eb); font-size:12px; font-weight:700; cursor:pointer; padding:4px 8px; border-radius:4px; transition:all 0.15s ease;">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                                    + Add Feature to this section
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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



                

                    <!-- Custom Admin URL & Brute-Force Shield Card -->

                    <div class="ofp-card" style="margin-top: 24px;">

                        <input type="hidden" name="ofp_admin_url_settings_present" value="1">

                        <div class="ofp-card-header">

                            <div class="ofp-card-header-main">

                                <div class="ofp-icon-box ofp-icon-lg icon-amber">

                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">

                                        <rect width="18" height="11" x="3" y="11" rx="2" ry="2"/>

                                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>

                                    </svg>

                                </div>

                                <div class="ofp-card-title-group">

                                    <h3>Custom Admin URL &amp; Brute-Force Shield</h3>

                                    <p>Cloak standard WordPress admin and login routes (<code>/wp-admin</code> and <code>/wp-login.php</code>) behind a custom secret URL. Protects against automated bots and credential stuffing attacks.</p>

                                </div>

                            </div>

                            <?php if ( '1' === $admin_url_enabled && ! empty( $admin_custom_slug ) ) : ?>

                                <span class="ofp-pill ofp-pill-ready">Shield Active (Cloaked)</span>

                            <?php else : ?>

                                <span class="ofp-pill ofp-pill-missing">Standard WP URLs</span>

                            <?php endif; ?>

                        </div>

                        <div class="ofp-card-body">



                            <div class="ofp-switch-row" style="margin-bottom: 20px;">

                                <div class="ofp-switch-info">

                                    <span class="ofp-switch-title">Enable Custom Admin URL Cloaking</span>

                                    <p class="ofp-switch-desc">When active, direct visits to <code>/wp-login.php</code> and <code>/wp-admin/</code> from unauthenticated visitors will be blocked and redirected to the home page or a 404.</p>

                                </div>

                                <label class="ofp-switch">

                                    <input type="checkbox" name="ofp_admin_url_enabled" value="1" <?php checked( $admin_url_enabled, '1' ); ?>>

                                    <span class="ofp-slider"></span>

                                </label>

                            </div>



                            <div class="ofp-field-grid">

                                <div class="ofp-field-group ofp-field-full">

                                    <label class="ofp-field-label">Custom Secret Login Slug</label>

                                    <div class="ofp-input-box" style="display:flex;align-items:center;">

                                        <span style="background:var(--ofp-slate-100, #f1f5f9);padding:10px 14px;border:1px solid var(--ofp-slate-300, #cbd5e1);border-right:none;border-radius:8px 0 0 8px;font-family:monospace;color:var(--ofp-slate-600);font-size:13px;white-space:nowrap;">

                                            <?php echo esc_html( trailingslashit( home_url() ) ); ?>

                                        </span>

                                        <input type="text" name="ofp_admin_custom_slug" id="ofp_admin_custom_slug"

                                               value="<?php echo esc_attr( $admin_custom_slug ); ?>"

                                               placeholder="e.g. secret-login"

                                               pattern="[a-zA-Z0-9\-]+"

                                               style="border-radius:0 8px 8px 0;max-width:280px;height:42px;">

                                    </div>

                                    <p class="ofp-field-hint">Use lowercase letters, numbers, and hyphens only. Leave empty to disable URL cloaking.</p>

                                </div>

                            </div>



                            <?php if ( ! empty( $admin_custom_slug ) ) : ?>

                                <div style="background:var(--ofp-slate-50, #f8fafc);border:1px solid var(--ofp-slate-200, #e2e8f0);border-radius:8px;padding:16px;margin:20px 0;">

                                    <?php if ( ! empty( $_GET['ofp_url_notice'] ) ) : ?>

                                        <div style="background:#dcfce7;border-left:4px solid #16a34a;padding:10px 14px;border-radius:6px;font-size:12.5px;color:#166534;margin-bottom:14px;display:flex;align-items:center;gap:8px;">

                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>

                                            <span><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ofp_url_notice'] ) ) ); ?></span>

                                        </div>

                                    <?php endif; ?>



                                    <div style="display:flex;align-items:center;justify-content:space-between;background:#fff;padding:10px 14px;border:1px solid var(--ofp-slate-200);border-radius:6px;margin-bottom:10px;">

                                        <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">

                                            <strong style="color:var(--ofp-slate-900);font-size:12.5px;">Custom Login URL:</strong>

                                            <code id="ofp_custom_login_url_display" style="margin-left:8px;font-size:12.5px;color:var(--ofp-brand, #0284c7);"><?php echo esc_html( trailingslashit( home_url() ) . $admin_custom_slug ); ?></code>

                                        </div>

                                        <button type="button" class="ofp-btn-copy-chip" onclick="navigator.clipboard.writeText(document.getElementById('ofp_custom_login_url_display').innerText);alert('Copied custom login URL to clipboard!');" title="Copy Custom URL" style="background:var(--ofp-slate-100);border:1px solid var(--ofp-slate-300);padding:4px 10px;border-radius:5px;cursor:pointer;font-size:12px;color:var(--ofp-slate-700);">Copy</button>

                                    </div>



                                    <div style="display:flex;align-items:center;justify-content:space-between;background:#fff;padding:10px 14px;border:1px solid var(--ofp-slate-200);border-radius:6px;margin-bottom:12px;">

                                        <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">

                                            <strong style="color:var(--ofp-slate-900);font-size:12.5px;">Emergency Bypass URL:</strong>

                                            <code id="ofp_emergency_url_display" style="margin-left:8px;font-size:12px;color:#dc2626;"><?php echo esc_html( wp_login_url() . '?ofast_emergency=' . $admin_emergency_key ); ?></code>

                                        </div>

                                        <button type="button" class="ofp-btn-copy-chip" onclick="navigator.clipboard.writeText(document.getElementById('ofp_emergency_url_display').innerText);alert('Copied emergency bypass URL to clipboard! Bookmark this in a secure place.');" title="Copy Emergency URL" style="background:var(--ofp-slate-100);border:1px solid var(--ofp-slate-300);padding:4px 10px;border-radius:5px;cursor:pointer;font-size:12px;color:var(--ofp-slate-700);">Copy</button>

                                    </div>



                                    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid var(--ofp-slate-200);">

                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_admin_url_resend' ), 'ofp_admin_url_action' ) ); ?>"

                                           class="button"

                                           style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:5px 12px;border-radius:6px;background:var(--ofp-slate-100, #f1f5f9);border:1px solid var(--ofp-slate-300, #cbd5e1);color:var(--ofp-slate-700);text-decoration:none;">

                                            <span class="dashicons dashicons-email-alt" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span>

                                            Resend Login Details Email

                                        </a>



                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_admin_url_rotate_key' ), 'ofp_admin_url_action' ) ); ?>"

                                           class="button"

                                           onclick="return confirm('Rotate the emergency bypass key now? The current emergency link will be invalidated immediately and a fresh key will be generated and emailed.');"

                                           style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:5px 12px;border-radius:6px;background:#fef2f2;border:1px solid #fecaca;color:#dc2626;text-decoration:none;">

                                            <span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;line-height:16px;"></span>

                                            Rotate Emergency Key

                                        </a>

                                    </div>



                                    <p class="ofp-field-hint" style="margin-top:10px;color:var(--ofp-slate-500);">⚠️ <strong>Safety Hatch &amp; Key Rotation:</strong> The emergency bypass key is <strong>one-time use</strong>. When accessed, it automatically generates and rotates to a new secret key, invalidating the old one and emailing the new URL to you. You can also click <strong>Rotate Emergency Key</strong> at any time to generate a fresh key manually.</p>

                                </div>

                            <?php endif; ?>



                            <div style="border-top:1px dashed var(--ofp-slate-200);margin:20px 0;padding-top:16px;">

                                <h4 style="margin:0 0 14px;font-size:13.5px;font-weight:700;color:var(--ofp-slate-800);">Brute-Force Attack Shield &amp; Lockout Rules</h4>

                                <div class="ofp-field-grid">

                                    <div class="ofp-field-group">

                                        <label class="ofp-field-label">Max Failed Attempts</label>

                                        <div class="ofp-input-box">

                                            <input type="number" name="ofp_security_max_attempts" value="<?php echo esc_attr( $security_max_attempts ); ?>" min="1" max="20" style="max-width:140px;">

                                        </div>

                                        <p class="ofp-field-hint">Number of consecutive bad logins before temporary IP lockout (recommended: 5).</p>

                                    </div>



                                    <div class="ofp-field-group">

                                        <label class="ofp-field-label">Lockout Duration (Minutes)</label>

                                        <div class="ofp-input-box">

                                            <input type="number" name="ofp_security_lockout_duration" value="<?php echo esc_attr( $security_lockout_dur ); ?>" min="1" max="1440" style="max-width:140px;">

                                        </div>

                                        <p class="ofp-field-hint">How long the offending IP remains blocked from login attempts (default: 15 min).</p>

                                    </div>



                                    <div class="ofp-field-group ofp-field-full">

                                        <label class="ofp-field-label">IP Whitelist (Never Blocked)</label>

                                        <textarea name="ofp_security_ip_whitelist" rows="3" class="ofp-textarea" placeholder="e.g. 192.168.1.1&#10;105.112.45.12" style="width:100%;max-width:440px;font-family:monospace;font-size:12px;padding:8px 10px;"><?php echo esc_textarea( $security_ip_whitelist ); ?></textarea>

                                        <p class="ofp-field-hint">One IPv4 or IPv6 address per line. These IPs will never be locked out by brute-force protection.</p>

                                    </div>

                                </div>

                            </div>



                        </div>

                    </div>

                    <!-- System Hardening & Information Disclosure Shield Card -->
                    <div class="ofp-card" style="margin-top: 24px;">
                        <div class="ofp-card-header">
                            <div class="ofp-card-header-main">
                                <div class="ofp-icon-box ofp-icon-lg icon-rose">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect width="18" height="18" x="3" y="3" rx="2"/><path d="m9 12 2 2 4-4"/>
                                    </svg>
                                </div>
                                <div class="ofp-card-title-group">
                                    <h3>System Hardening &amp; Information Disclosure Shield</h3>
                                    <p>Prevent automated scanner probes, disable obsolete remote endpoints, and stop bot harvesting of author profiles and email addresses.</p>
                                </div>
                            </div>
                            <span class="ofp-pill ofp-pill-ready">Active Defense</span>
                        </div>
                        <div class="ofp-card-body">
                            <!-- Switch 1: Disable XML-RPC -->
                            <div class="ofp-switch-row">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">Disable XML-RPC Endpoint &amp; Pingbacks</span>
                                    <p class="ofp-switch-desc">Fully disables <code>xmlrpc.php</code>, strips <code>X-Pingback</code> headers, and blocks XML-RPC methods. Prevents brute-force amplification attacks and XML-RPC DDoS exploits.</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_disable_xmlrpc" value="1" <?php checked( $disable_xmlrpc, '1' ); ?>>
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>

                            <!-- Switch 2: Obfuscate Author Slugs & Block Enumeration -->
                            <div class="ofp-switch-row">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">Obfuscate Author Slugs &amp; Block Username Enumeration</span>
                                    <p class="ofp-switch-desc">Blocks scanning bots querying <code>/?author=1</code> by redirecting to the homepage. Replaces public author archive URLs (<code>/author/username/</code>) with unique cryptographic 12-char hashes to prevent admin usernames from being discovered.</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_obfuscate_author_slugs" value="1" <?php checked( $obfuscate_author_slugs, '1' ); ?>>
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>

                            <!-- Switch 3: Email Address Obfuscator -->
                            <div class="ofp-switch-row">
                                <div class="ofp-switch-info">
                                    <span class="ofp-switch-title">Email Address Anti-Scraping Obfuscator</span>
                                    <p class="ofp-switch-desc">Encodes plaintext email addresses across posts, pages, widgets, and comments into HTML decimal entities (<code>&amp;#101;&amp;#120;...</code>). Keeps emails completely readable to visitors while invisible to spam bots and automated email harvesters.</p>
                                </div>
                                <label class="ofp-switch">
                                    <input type="checkbox" name="ofp_obfuscate_emails" value="1" <?php checked( $obfuscate_emails, '1' ); ?>>
                                    <span class="ofp-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>






                </section>



                            <!-- ════════ TAB 9: AI ASSISTANT & LIVE SUPPORT ════════ -->

                <section id="tab-ai-chat" class="ofp-tab-pane">



                    <div class="ofp-card">

                        <div class="ofp-card-header">

                            <div class="ofp-card-header-main">

                                <div class="ofp-icon-box ofp-icon-lg icon-indigo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2 2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"></path><rect x="4" y="8" width="16" height="12" rx="4"></rect><circle cx="9" cy="13" r="1.5" fill="currentColor"></circle><circle cx="15" cy="13" r="1.5" fill="currentColor"></circle><path d="M9 17h6"></path></svg></div>

                                <div class="ofp-card-title-group">

                                    <h3>AI Assistant &amp; Live Support</h3>

                                    <p>A floating chat that answers questions with AI and hands over to your team when a person is needed.</p>

                                </div>

                            </div>

                            <?php if ( '1' === $chat_enabled && ( $has_gemini_key || $has_groq_key ) ) : ?>

                                <span class="ofp-pill ofp-pill-ready">AI ready<?php echo $has_tg_bot_token ? ' · Live support ready' : ''; ?></span>

                            <?php else : ?>

                                <span class="ofp-pill ofp-pill-missing">Setup incomplete</span>

                            <?php endif; ?>

                        </div>

                        <div class="ofp-card-body">

                            <div class="ofp-field-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">

                                <div class="ofp-field-group">

                                    <label class="ofp-field-label">Enable chat widget</label>

                                    <label class="ofp-toggle-switch">

                                        <input type="checkbox" name="ofp_chat_enabled" value="1" <?php checked( '1', $chat_enabled ); ?>>

                                        <span class="ofp-toggle-slider"></span>

                                        <span class="ofp-toggle-text">Show the chat on the dashboard, portal and public pages</span>

                                    </label>

                                </div>

                                <div class="ofp-field-group">

                                    <label class="ofp-field-label">Show to public visitors</label>

                                    <label class="ofp-toggle-switch">

                                        <input type="checkbox" name="ofp_chat_guest_enabled" value="1" <?php checked( '1', $chat_guest_enabled ); ?>>

                                        <span class="ofp-toggle-slider"></span>

                                        <span class="ofp-toggle-text">Lets guests ask general questions (AI only)</span>

                                    </label>

                                </div>

                                <div class="ofp-field-group">

                                    <label class="ofp-field-label">Let guests talk to a human agent</label>

                                    <label class="ofp-toggle-switch">

                                        <input type="checkbox" name="ofp_chat_guest_human" value="1" <?php checked( '1', $chat_guest_human ); ?>>

                                        <span class="ofp-toggle-slider"></span>

                                        <span class="ofp-toggle-text">Guests give a name first, then can message your team. Logged-in users can always do this.</span>

                                    </label>

                                </div>

                            </div>

                        </div>

                    </div>





                    <div class="ofp-card">

                        <div class="ofp-card-header">

                            <div class="ofp-card-header-main">

                                <div class="ofp-icon-box ofp-icon-lg icon-purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg></div>

                                <div class="ofp-card-title-group">

                                    <h3>AI engine</h3>

                                    <p>Add a Gemini key (and optionally Groq). The chat falls back between models automatically so it keeps answering when one hits a free-tier limit.</p>

                                </div>

                            </div><div style="display:flex;gap:8px;"><button type="button" class="ofp-btn-test-action" onclick="window.location.href='<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_test_chat_ai' ), 'ofp_test_chat_ai' ) ); ?>'">⚡ Test AI</button></div>

                        </div>

                        <div class="ofp-card-body">

                            <div class="ofp-field-grid">

                                <div class="ofp-field-group">

                                    <label class="ofp-field-label">Preferred AI provider</label>

                                    <div class="ofp-input-box">

                                        <select name="ofp_chat_ai_provider" class="ofp-select" style="width:100%;height:42px;border:none;background:transparent;padding:0 12px;font-size:14px;outline:none;">

                                            <option value="gemini" <?php selected( 'gemini', $chat_ai_provider ); ?>>Google Gemini</option>

                                            <option value="groq" <?php selected( 'groq', $chat_ai_provider ); ?>>Groq Cloud</option>

                                        </select>

                                    </div>

                                    <p class="ofp-field-hint">If this provider is busy or out of quota, the chat automatically tries the other models below, then the other provider if you saved a key for it.</p>

                                </div>

                                <div class="ofp-field-group">

                                    <label class="ofp-field-label">Gemini model (first choice)</label>

                                    <div class="ofp-input-box">

                                        <input type="text" name="ofp_chat_gemini_model" value="<?php echo esc_attr( $chat_gemini_model ); ?>" placeholder="gemini-3.5-flash-lite" list="ofp-gemini-models" autocomplete="off">

                                    </div>

                                    <p class="ofp-field-hint">Free-tier quotas are counted <strong>per model</strong>. Flash-Lite models have far higher daily limits than the full Flash models. Check yours at <a href="https://aistudio.google.com/rate-limit" target="_blank" rel="noopener">AI Studio</a>.</p>

                                </div>

                                <div class="ofp-field-group">

                                    <label class="ofp-field-label">Gemini fallback model</label>

                                    <div class="ofp-input-box">

                                        <input type="text" name="ofp_chat_gemini_fallback_model" value="<?php echo esc_attr( $chat_gemini_fallback ); ?>" placeholder="gemini-3.1-flash-lite" list="ofp-gemini-models" autocomplete="off">

                                    </div>

                                    <p class="ofp-field-hint">Used when the first model hits its limit. A different model has its own separate quota.</p>

                                </div>

                                <div class="ofp-field-group">

                                    <label class="ofp-field-label">Groq model</label>

                                    <div class="ofp-input-box">

                                        <input type="text" name="ofp_chat_groq_model" value="<?php echo esc_attr( $chat_groq_model ); ?>" placeholder="llama-3.3-70b-versatile" autocomplete="off">

                                    </div>

                                    <p class="ofp-field-hint">Only used if a Groq key is saved. If Test AI reports the model is not found, pick a current one from the Groq console.</p>

                                </div>

                                <div class="ofp-field-group">

                                    <div class="ofp-field-label-row">

                                        <label class="ofp-field-label">Google Gemini API key</label>

                                        <?php if ( $has_gemini_key ) : ?><span class="ofp-badge-encrypted">✓ Encrypted</span><?php endif; ?>

                                    </div>

                                    <div class="ofp-input-box has-action">

                                        <input type="password" name="ofp_chat_gemini_api_key" class="ofp-secret-input" autocomplete="new-password"

                                               placeholder="<?php echo $has_gemini_key ? '••••••••••••••••••••••••' : 'Enter Gemini API key...'; ?>">

                                        <button type="button" class="ofp-input-action-btn ofp-btn-toggle-pwd" title="Show/Hide"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>

                                    </div>

                                    <p class="ofp-field-hint">Free key from <a href="https://aistudio.google.com/" target="_blank" rel="noopener">Google AI Studio</a>. Leave blank to keep the saved key.</p>

                                </div>

                                <div class="ofp-field-group">

                                    <div class="ofp-field-label-row">

                                        <label class="ofp-field-label">Groq API key (optional backup)</label>

                                        <?php if ( $has_groq_key ) : ?><span class="ofp-badge-encrypted">✓ Encrypted</span><?php endif; ?>

                                    </div>

                                    <div class="ofp-input-box has-action">

                                        <input type="password" name="ofp_chat_groq_api_key" class="ofp-secret-input" autocomplete="new-password"

                                               placeholder="<?php echo $has_groq_key ? '••••••••••••••••••••••••' : 'gsk_...'; ?>">

                                        <button type="button" class="ofp-input-action-btn ofp-btn-toggle-pwd" title="Show/Hide"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>

                                    </div>

                                    <p class="ofp-field-hint">Free key from <a href="https://console.groq.com/" target="_blank" rel="noopener">Groq Console</a>. Adds a second provider as a safety net.</p>

                                </div>

                            </div>

                            <datalist id="ofp-gemini-models">

                                <option value="gemini-3.5-flash-lite"></option>

                                <option value="gemini-3.1-flash-lite"></option>

                                <option value="gemini-3.6-flash"></option>

                                <option value="gemini-3.8-flash"></option>

                            </datalist>

                            <?php if ( ! empty( $chat_last_ai_error ) ) : ?>

                            <div style="margin-top:14px;padding:10px 12px;border:1px solid #fecdd3;background:#fff1f2;border-radius:8px;font-size:12.5px;color:#9f1239;">

                                <strong>Last AI problem</strong> (<?php echo esc_html( wp_date( 'M j, g:i a', (int) ( $chat_last_ai_error['time'] ?? 0 ) ) ); ?>):

                                <code><?php echo esc_html( ( $chat_last_ai_error['provider'] ?? '' ) . '/' . ( $chat_last_ai_error['model'] ?? '' ) ); ?></code>

                                <?php echo esc_html( $chat_last_ai_error['code'] ?? '' ); ?> &mdash; <?php echo esc_html( $chat_last_ai_error['message'] ?? '' ); ?>

                            </div>

                            <?php endif; ?>

                        </div>

                    </div>





                    <div class="ofp-card">

                        <div class="ofp-card-header">

                            <div class="ofp-card-header-main">

                                <div class="ofp-icon-box ofp-icon-lg icon-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg></div>

                                <div class="ofp-card-title-group">

                                    <h3>Human agent (via Telegram, private)</h3>

                                    <p>Visitor messages reach your Telegram and your replies appear in their chat. This is only visible here, never on the website.</p>

                                </div>

                            </div><div style="display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="ofp-btn-test-action" onclick="window.location.href='<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_test_chat_telegram' ), 'ofp_test_chat_telegram' ) ); ?>'">📱 Send test</button><button type="button" class="ofp-btn-test-action" onclick="window.location.href='<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_set_telegram_webhook' ), 'ofp_set_telegram_webhook' ) ); ?>'">🔗 Connect Webhook</button><button type="button" class="ofp-btn-test-action" onclick="window.location.href='<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_set_telegram_pull' ), 'ofp_set_telegram_pull' ) ); ?>'">☁️ Use pull mode (Cloudflare-safe)</button><button type="button" class="ofp-btn-test-action" onclick="window.location.href='<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_check_telegram_webhook' ), 'ofp_check_telegram_webhook' ) ); ?>'">🩺 Check Webhook</button></div>

                        </div>

                        <div class="ofp-card-body">

                            <div class="ofp-field-grid">

                                <div class="ofp-field-group">

                                    <div class="ofp-field-label-row">

                                        <label class="ofp-field-label">Bot token</label>

                                        <?php if ( $has_tg_bot_token ) : ?><span class="ofp-badge-encrypted">✓ Encrypted</span><?php endif; ?>

                                    </div>

                                    <div class="ofp-input-box has-action">

                                        <input type="password" name="ofp_chat_telegram_bot_token" class="ofp-secret-input" autocomplete="new-password"

                                               placeholder="<?php echo $has_tg_bot_token ? '••••••••••••••••••••••••' : 'Enter token from @BotFather'; ?>">

                                        <button type="button" class="ofp-input-action-btn ofp-btn-toggle-pwd" title="Show/Hide"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>

                                    </div>

                                    <p class="ofp-field-hint">Create a bot with Telegram&#39;s <code>@BotFather</code> and paste its token. Leave blank to keep the saved token.</p>

                                </div>

                                <div class="ofp-field-group">

                                    <label class="ofp-field-label">Your chat ID (or staff group ID)</label>

                                    <div class="ofp-input-box">

                                        <input type="text" name="ofp_chat_telegram_chat_id" value="<?php echo esc_attr( $chat_tg_chat_id ); ?>" placeholder="e.g. 123456789" autocomplete="off">

                                    </div>

                                    <p class="ofp-field-hint">Only messages from this chat can answer visitors. Message <code>@userinfobot</code> on Telegram to find your ID. Start your bot once (press Start) so it can message you.</p>

                                </div>

                                <div class="ofp-field-group">

                                    <label class="ofp-field-label">Message shown when a chat is handed to your team</label>

                                    <div class="ofp-input-box">

                                        <input type="text" name="ofp_chat_handoff_message" value="<?php echo esc_attr( $chat_handoff_msg ); ?>" placeholder="" autocomplete="off">

                                    </div>

                                    <p class="ofp-field-hint">Visitors see this once, when they first message a human agent.</p>

                                </div>

                                <div class="ofp-field-group">

                                    <label class="ofp-field-label">Webhook address</label>

                                    <div class="ofp-input-box">

                                        <input type="text" readonly value="<?php echo esc_url( rest_url( 'ofast/v1/chat/telegram-webhook' ) ); ?>" style="background:#f1f5f9;color:#64748b;">

                                    </div>

                                    <p class="ofp-field-hint">Behind Cloudflare, or unsure? Click <strong>Use pull mode</strong>: your site fetches replies from Telegram, so nothing has to pass Cloudflare. Otherwise click <strong>Connect Webhook</strong>. Use <strong>Check</strong> any time replies stop arriving. Current mode: <strong><?php echo esc_html( class_exists( 'OFP_Chat_Telegram' ) ? OFP_Chat_Telegram::mode() : 'webhook' ); ?></strong>.<?php if ( $chat_tg_bot_user ) : ?> Connected bot: <code>@<?php echo esc_html( $chat_tg_bot_user ); ?></code>.<?php endif; ?></p>

                                </div>

                            </div>

                            <p class="ofp-field-hint" style="margin-top:12px;">How to answer: every visitor message arrives in Telegram tagged like <code>#C12</code>. <strong>Swipe-reply</strong> to it, or type <code>/r 12 your reply</code>. <code>/list</code> shows open chats and <code>/close 12</code> ends one. Visitors never see Telegram.</p>

                        </div>

                    </div>





                    <div class="ofp-card">

                        <div class="ofp-card-header">

                            <div class="ofp-card-header-main">

                                <div class="ofp-icon-box ofp-icon-lg icon-amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h10"/></svg></div>

                                <div class="ofp-card-title-group">

                                    <h3>Instructions for the assistant</h3>

                                    <p>Tell the AI how to talk to public visitors and to logged-in clients.</p>

                                </div>

                            </div>

                        </div>

                        <div class="ofp-card-body">

                            <div class="ofp-field-grid" style="grid-template-columns: 1fr;">

                                <div class="ofp-field-group">

                                    <label class="ofp-field-label">Public visitor instructions</label>

                                    <textarea name="ofp_chat_guest_prompt" rows="5" class="ofp-textarea" style="width:100%;font-size:13px;line-height:1.5;padding:10px;border:1px solid #cbd5e1;border-radius:8px;font-family:monospace;"><?php echo esc_textarea( $chat_guest_prompt ); ?></textarea>

                                    <p class="ofp-field-hint">Instructions for anonymous visitors on your homepage or marketing pages.</p>

                                </div>

                                <div class="ofp-field-group" style="margin-top:14px;">

                                    <label class="ofp-field-label">Logged-in dashboard copilot instructions</label>

                                    <textarea name="ofp_chat_user_prompt" rows="6" class="ofp-textarea" style="width:100%;font-size:13px;line-height:1.5;padding:10px;border:1px solid #cbd5e1;border-radius:8px;font-family:monospace;"><?php echo esc_textarea( $chat_user_prompt ); ?></textarea>

                                    <p class="ofp-field-hint">Instructions for logged-in clients. Tags: <code>{user_name}</code>, <code>{user_role}</code>. The assistant is always told never to mention Telegram.</p>

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


<?php

if ( ! $is_portal && file_exists( OFP_PATH . 'admin/views/partials/footer.php' ) ) {

    include OFP_PATH . 'admin/views/partials/footer.php';

}

?>

