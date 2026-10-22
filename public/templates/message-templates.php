<?php
/**
 * Template: /message-templates
 * Client Communications Hub — mirrors the admin communications page.
 * Tabs: Communications Log | Send Broadcast | Email Template
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

if ( ! OFP_Auth::has_permission( 'send_messages' ) ) {
    wp_die( 'You do not have permission to access communications.', 'Access Denied', [ 'response' => 403 ] );
}

global $wpdb;
$p = $wpdb->prefix;

$has_ebc = $wpdb->get_results( "SHOW COLUMNS FROM {$p}ofp_clients LIKE 'email_brand_color'" );
if ( empty( $has_ebc ) ) {
    $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_brand_color VARCHAR(20) DEFAULT '#0f172a' AFTER logo_url" );
    $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_header_text VARCHAR(150) DEFAULT NULL AFTER email_brand_color" );
    $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_header_tagline VARCHAR(255) DEFAULT NULL AFTER email_header_text" );
    $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_footer_text TEXT DEFAULT NULL AFTER email_header_tagline" );
    $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_template_mode VARCHAR(20) DEFAULT 'visual' AFTER email_footer_text" );
    $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_custom_html MEDIUMTEXT DEFAULT NULL AFTER email_template_mode" );
}

// Refresh client row from DB so all columns are present
$client = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_clients WHERE id = %d LIMIT 1", $client->id ) );

// ── Call Scripts tab: save ────────────────────────────────────────────────
$call_error = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_voice_notice_nonce'] ) ) {
    if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ofp_voice_notice_nonce'] ) ), 'ofp_voice_notice_' . (int) $client->id ) ) {
        OFP_Voice_Notice::save_settings( (int) $client->id, $_POST );
        wp_safe_redirect( home_url( '/message-templates?tab=calls&saved=1' ) );
        exit;
    }
    $call_error = 'Security check failed. Please refresh the page and try again.';
}

$tab = sanitize_text_field( $_GET['tab'] ?? 'log' );
$allowed_tabs = [ 'log', 'calls', 'send', 'templates', 'branding' ];
if ( ! in_array( $tab, $allowed_tabs, true ) ) {
    $tab = 'log';
}
if ( in_array( $tab, [ 'send', 'templates', 'branding' ], true ) && ! OFP_Subscription::allows_email_templates( (int) $client->id ) ) {
    $tab = 'log';
}

// ── Data for Log Tab ────────────────────────────────────────────────────
if ( class_exists( 'OFP_Communications_Log' ) ) {
    OFP_Communications_Log::ensure_schema();
    OFP_Communications_Log::sync_historical_activity( (int) $client->id );
}

$filter_type   = sanitize_text_field( $_GET['type'] ?? '' );
$filter_status = sanitize_text_field( $_GET['status'] ?? '' );
$search        = sanitize_text_field( $_GET['s'] ?? '' );
$per_page      = 25;
$current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

// Fetch initial data via centralized helper
$comm_data = OFP_Communications_Log::fetch( (int) $client->id, [
    'type'   => $filter_type,
    'status' => $filter_status,
    's'      => $search,
], $current_page, $per_page );

$comms       = $comm_data['items'];
$total       = $comm_data['total'];
$total_pages = $comm_data['total_pages'];
$has_filters = ! empty( $filter_type ) || ! empty( $filter_status ) || ! empty( $search );

// Summary stats (scoped to client across all communications)
$sms_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE client_id = %d AND type = 'sms'", (int) $client->id ) );
$voice_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE client_id = %d AND type = 'voice'", (int) $client->id ) );
$email_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE client_id = %d AND type = 'email'", (int) $client->id ) );
$total_cost  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(cost) FROM {$p}ofp_communications_log WHERE client_id = %d AND status NOT IN ('failed', 'error', 'pending')", (int) $client->id ) );

// ── Data for Templates Tab ──────────────────────────────────────────────
$templates = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$p}ofp_client_templates WHERE client_id = %d ORDER BY updated_at DESC",
        $client->id
    )
);

$type_badges = [
    'sms'   => '<span class="ofp-badge ofp-badge-blue">SMS</span>',
    'voice' => '<span class="ofp-badge ofp-badge-green">Voice</span>',
    'email' => '<span class="ofp-badge ofp-badge-yellow">Email</span>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Communications', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <style>
    /* ── STAT CARDS GRID: 4 columns on desktop, 2 columns on mobile ─── */
    .ofp-comm-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 32px;
    }

    .ofp-comm-stat-card {
        border-radius: 16px;
        padding: 24px;
        color: #fff;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
    }

    .ofp-comm-stat-card .stat-bg-icon {
        position: absolute;
        top: -10px;
        right: -10px;
        opacity: 0.15;
        width: 90px;
        height: 90px;
    }

    .ofp-comm-stat-card .stat-title {
        font-size: 13px;
        font-weight: 500;
        opacity: 0.9;
        margin-bottom: 8px;
    }

    .ofp-comm-stat-card .stat-val {
        font-size: 28px;
        font-weight: 700;
    }

    @media (max-width: 768px) {
        .ofp-comm-stat-grid {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 12px !important;
            margin-bottom: 20px !important;
        }
        .ofp-comm-stat-card {
            padding: 16px 14px !important;
            border-radius: 12px !important;
        }
        .ofp-comm-stat-card .stat-bg-icon {
            width: 55px !important;
            height: 55px !important;
            top: -5px !important;
            right: -5px !important;
        }
        .ofp-comm-stat-card .stat-title {
            font-size: 11.5px !important;
            margin-bottom: 4px !important;
        }
        .ofp-comm-stat-card .stat-val {
            font-size: 20px !important;
        }
    }

    /* ── SINGLE STRAIGHT-LINE HORIZONTAL FILTER BAR ─────────────────── */
    .ofp-comm-filter-wrapper {
        background: var(--bg-card, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        position: relative;
        z-index: 1000;
        overflow: visible;
    }

    .ofp-comm-filter-bar {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: nowrap;
        position: relative;
        z-index: 1000;
        overflow: visible;
    }

    @media (max-width: 768px) {
        .ofp-comm-filter-wrapper {
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .ofp-comm-filter-wrapper::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
    }

    .ofp-comm-filter-item {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
        white-space: nowrap;
        position: relative;
        z-index: 1001;
    }

    .ofp-comm-filter-label {
        font-size: 11.5px;
        font-weight: 700;
        color: var(--text-muted, #64748b);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        user-select: none;
        margin: 0;
    }

    .ofp-comm-search-box {
        position: relative;
        flex-shrink: 0;
        width: 250px;
        min-width: 190px;
    }

    .ofp-comm-search-box svg {
        position: absolute;
        left: 11px;
        top: 50%;
        transform: translateY(-50%);
        pointer-events: none;
        color: var(--text-muted, #94a3b8);
    }

    .ofp-comm-search-input {
        height: 38px !important;
        padding: 0 12px 0 34px !important;
        font-size: 13px !important;
        border-radius: 8px !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }

    /* ── Remove ALL arrows on dropdowns & enforce visible options with high z-index ── */
    .ofp-comm-filter-bar .ofp-custom-select-trigger::after,
    .ofp-custom-select-trigger::after {
        display: none !important;
        content: none !important;
    }

    .ofp-comm-select,
    select.ofp-comm-select {
        height: 38px !important;
        padding: 0 14px !important;
        font-size: 13px !important;
        border-radius: 8px !important;
        width: auto !important;
        min-width: 130px !important;
        cursor: pointer !important;
        box-sizing: border-box !important;
        background-image: none !important;
        -webkit-appearance: none !important;
        -moz-appearance: none !important;
        appearance: none !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-wrapper {
        position: relative !important;
        width: auto !important;
        min-width: 130px !important;
        z-index: 1002 !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-wrapper.open {
        z-index: 99999 !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-trigger {
        height: 38px !important;
        padding: 0 14px !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        border-radius: 8px !important;
        background: var(--bg-body, #f8fafc) !important;
        border: 1px solid var(--border-color, #e2e8f0) !important;
        color: var(--text-main, #0f172a) !important;
        min-width: 130px !important;
        white-space: nowrap !important;
        box-sizing: border-box !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-start !important;
        cursor: pointer !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-options {
        position: absolute !important;
        top: calc(100% + 4px) !important;
        left: 0 !important;
        min-width: 160px !important;
        width: max-content !important;
        max-width: 280px !important;
        z-index: 999999 !important;
        background: var(--bg-card, #ffffff) !important;
        border: 1px solid var(--border-color, #e2e8f0) !important;
        border-radius: 8px !important;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
        max-height: 260px !important;
        overflow-y: auto !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-wrapper.open .ofp-custom-select-options {
        display: block !important;
        opacity: 1 !important;
        visibility: visible !important;
        transform: translateY(0) !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-option {
        padding: 9px 14px !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        color: var(--text-main, #0f172a) !important;
        cursor: pointer !important;
        white-space: nowrap !important;
        transition: background 0.15s ease, color 0.15s ease !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-option:hover,
    .ofp-comm-filter-bar .ofp-custom-select-option.selected {
        background: var(--bg-lighter, #f1f5f9) !important;
        color: var(--primary, #3b82f6) !important;
    }

    .ofp-comm-clear-btn {
        height: 38px;
        padding: 0 14px;
        border-radius: 8px;
        border: 1px solid var(--border-color, #e2e8f0);
        background: transparent;
        color: var(--text-muted, #64748b);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        flex-shrink: 0;
        white-space: nowrap;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .ofp-comm-clear-btn:hover {
        color: var(--text-main, #0f172a);
        border-color: var(--text-muted, #94a3b8);
        background: var(--bg-lighter, #f8fafc);
    }

    .ofp-comm-count-badge {
        margin-left: auto;
        flex-shrink: 0;
        white-space: nowrap;
        font-size: 13px;
        color: var(--text-muted, #64748b);
        font-weight: 500;
    }

    #comm-feed-container {
        transition: opacity 0.2s ease;
    }
    </style>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <!-- Tabs -->
    <div class="ofp-tabs" style="margin-bottom: 24px; border-bottom: 1px solid var(--border-color);">
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=log' ) ); ?>" class="ofp-tab <?php echo $tab === 'log' ? 'active' : ''; ?>">Communications Log</a>
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=calls' ) ); ?>" class="ofp-tab <?php echo $tab === 'calls' ? 'active' : ''; ?>">Call Scripts</a>
        <?php if ( OFP_Subscription::allows_email_templates( (int) $client->id ) ) : ?>
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=send' ) ); ?>" class="ofp-tab <?php echo $tab === 'send' ? 'active' : ''; ?>">Send Broadcast</a>
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=templates' ) ); ?>" class="ofp-tab <?php echo $tab === 'templates' ? 'active' : ''; ?>">Message Templates</a>
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=branding' ) ); ?>" class="ofp-tab <?php echo $tab === 'branding' ? 'active' : ''; ?>">Email Brand & Layout</a>
        <?php endif; ?>
    </div>

    <?php
    // =====================================================================
    // TAB 1: COMMUNICATIONS LOG
    // =====================================================================
    ?>
    <?php if ( $tab === 'log' ) : ?>

        <!-- Summary Stat Cards: 4 on desktop, 2 on mobile -->
        <div class="ofp-comm-stat-grid">
            <!-- SMS Sent -->
            <div class="ofp-comm-stat-card" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); box-shadow: 0 10px 15px -3px rgba(124, 58, 237, 0.2);">
                <div class="stat-bg-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:100%;height:100%;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                </div>
                <div class="stat-title">Total SMS Sent</div>
                <div class="stat-val"><?php echo esc_html( number_format( $sms_count ) ); ?></div>
            </div>
            <!-- Calls Made -->
            <div class="ofp-comm-stat-card" style="background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.2);">
                <div class="stat-bg-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:100%;height:100%;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                </div>
                <div class="stat-title">Total Calls Made</div>
                <div class="stat-val"><?php echo esc_html( number_format( $voice_count ) ); ?></div>
            </div>
            <!-- Emails Sent -->
            <div class="ofp-comm-stat-card" style="background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 10px 15px -3px rgba(245, 158, 11, 0.2);">
                <div class="stat-bg-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:100%;height:100%;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                </div>
                <div class="stat-title">Total Emails Sent</div>
                <div class="stat-val"><?php echo esc_html( number_format( $email_count ) ); ?></div>
            </div>
            <!-- Total Credit Used -->
            <div class="ofp-comm-stat-card" style="background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.2);">
                <div class="stat-bg-icon" style="font-size: 80px; font-weight: 700; font-family: sans-serif; line-height: 1; display:flex; justify-content:center; align-items:center;">₦</div>
                <div class="stat-title">Total Spent (Est.)</div>
                <div class="stat-val">₦<?php echo esc_html( number_format( $total_cost, 0 ) ); ?></div>
            </div>
        </div>

        <!-- Straight-Line Horizontal Filter Bar -->
        <div class="ofp-comm-filter-wrapper">
            <form id="comm-filter-form" method="GET" action="<?php echo esc_url( home_url( '/message-templates' ) ); ?>" style="margin:0;">
                <input type="hidden" name="tab" value="log">
                <div class="ofp-comm-filter-bar">
                    
                    <!-- Search Box -->
                    <div class="ofp-comm-search-box">
                        <input type="text" id="comm-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" 
                               placeholder="Search recipient, phone or text..." 
                               class="ofp-input ofp-comm-search-input">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </div>

                    <!-- Channel Dropdown (inherits platform .ofp-select) -->
                    <div class="ofp-comm-filter-item">
                        <label class="ofp-comm-filter-label" for="comm-type-select">Channel:</label>
                        <select id="comm-type-select" name="type" class="ofp-select ofp-comm-select">
                            <option value="" <?php selected( $filter_type, '' ); ?>>All Channels</option>
                            <option value="sms" <?php selected( $filter_type, 'sms' ); ?>>SMS</option>
                            <option value="voice" <?php selected( $filter_type, 'voice' ); ?>>Voice</option>
                            <option value="email" <?php selected( $filter_type, 'email' ); ?>>Email</option>
                        </select>
                    </div>

                    <!-- Status Dropdown (inherits platform .ofp-select) -->
                    <div class="ofp-comm-filter-item">
                        <label class="ofp-comm-filter-label" for="comm-status-select">Status:</label>
                        <select id="comm-status-select" name="status" class="ofp-select ofp-comm-select">
                            <option value="" <?php selected( $filter_status, '' ); ?>>All Statuses</option>
                            <option value="sent" <?php selected( $filter_status, 'sent' ); ?>>Sent / Delivered</option>
                            <option value="pending" <?php selected( $filter_status, 'pending' ); ?>>Pending / Queued</option>
                            <option value="failed" <?php selected( $filter_status, 'failed' ); ?>>Failed</option>
                        </select>
                    </div>

                    <!-- Clear Button -->
                    <button type="button" id="comm-clear-btn" class="ofp-comm-clear-btn" style="<?php echo $has_filters ? 'display:inline-flex;' : 'display:none;'; ?>">
                        Clear
                    </button>

                    <!-- Records Counter -->
                    <div id="comm-count-badge" class="ofp-comm-count-badge">
                        Showing <strong><?php echo esc_html( number_format( count( $comms ) ) ); ?></strong> of <strong><?php echo esc_html( number_format( $total ) ); ?></strong> records
                    </div>

                </div>
            </form>
        </div>

        <!-- Feed Card -->
        <div class="ofp-card" style="padding:0; overflow:hidden;">
            <div id="comm-feed-container">
                <?php echo OFP_Communications_Log::render_feed_html( $comms, (int) $client->id ); ?>
            </div>

            <div id="comm-pagination-container">
                <?php echo OFP_Communications_Log::render_pagination_html( $total_pages, $current_page, home_url( '/message-templates' ), [
                    'tab'    => 'log',
                    'type'   => $filter_type,
                    'status' => $filter_status,
                    's'      => $search,
                ] ); ?>
            </div>
        </div>

    <?php endif; ?>

    <?php
    // =====================================================================
    // TAB 2: SEND BROADCAST
    // =====================================================================
    ?>
    <?php if ( $tab === 'calls' ) :
        $call_settings = OFP_Voice_Notice::settings( (int) $client->id );
        $call_hours    = OFP_Voice_Notice::hours();
        $call_credits  = class_exists( 'OFP_Credit' ) ? OFP_Credit::get( (int) $client->id ) : null;
        $call_balance  = (float) ( $call_credits->voice_remaining ?? 0 );
        $call_cost     = (float) OFP_Queue::VOICE_COST;
        $call_sections = [
            'interest' => [
                'title' => 'When a new lead fills your contact or inspection form',
                'hint'  => 'Calls the lead right after they submit the form. Leads you upload yourself are never called.',
                'tags'  => [ '{{name}}', '{{business_name}}', '{{property}}' ],
            ],
            'installment' => [
                'title' => 'When a buyer\'s installment is due today',
                'hint'  => 'One call on the due day. The buyer still gets the email and SMS reminders.',
                'tags'  => [ '{{name}}', '{{business_name}}', '{{property}}', '{{amount}}', '{{due_date}}' ],
            ],
            'rent' => [
                'title' => 'When a tenant\'s rent is due today',
                'hint'  => 'One call on the due day (the lease end date), only if the tenant still owes money.',
                'tags'  => [ '{{name}}', '{{business_name}}', '{{property}}', '{{amount}}', '{{due_date}}' ],
            ],
        ];
    ?>
        <?php if ( ! empty( $_GET['saved'] ) ) : ?>
            <div class="ofp-card" style="padding:14px 20px; margin-bottom:16px; border-left:4px solid #10b981;">Your call scripts were saved.</div>
        <?php endif; ?>
        <?php if ( $call_error ) : ?>
            <div class="ofp-card" style="padding:14px 20px; margin-bottom:16px; border-left:4px solid #ef4444;"><?php echo esc_html( $call_error ); ?></div>
        <?php endif; ?>

        <div class="ofp-card" style="padding: 24px; margin-bottom: 16px;">
            <h3 style="margin-top:0;">Automatic Calls</h3>
            <p class="ofp-subtitle" style="margin-bottom: 12px;">A short phone call that reads your message and hangs up. There are no key presses. Each call uses &#8358;<?php echo esc_html( number_format( $call_cost, 0 ) ); ?> of voice credit, and you have &#8358;<?php echo esc_html( number_format( $call_balance, 0 ) ); ?> left.</p>
            <?php if ( $call_hours['enabled'] ) : ?>
                <p class="ofp-subtitle" style="margin:0;">Calls only go out between <?php echo esc_html( gmdate( 'g A', mktime( (int) $call_hours['start'], 0, 0, 1, 1, 2000 ) ) ); ?> and <?php echo esc_html( gmdate( 'g A', mktime( (int) $call_hours['end'] % 24, 0, 0, 1, 1, 2000 ) ) ); ?>. A call due outside those hours waits until the next morning.</p>
            <?php endif; ?>
            <?php if ( $call_balance < $call_cost ) : ?>
                <p style="margin:12px 0 0; color:#b45309;">Your voice credit is too low to place a call. Top up on the Credits page.</p>
            <?php endif; ?>
        </div>

        <form method="post" action="<?php echo esc_url( home_url( '/message-templates?tab=calls' ) ); ?>">
            <?php wp_nonce_field( 'ofp_voice_notice_' . (int) $client->id, 'ofp_voice_notice_nonce' ); ?>

            <?php foreach ( $call_sections as $type => $section ) : ?>
                <div class="ofp-card" style="padding: 24px; margin-bottom: 16px;">
                    <label style="display:flex; align-items:center; gap:10px; font-weight:700; font-size:15px; cursor:pointer;">
                        <input type="checkbox" name="call_<?php echo esc_attr( $type ); ?>_enabled" value="1" <?php checked( ! empty( $call_settings[ $type . '_enabled' ] ) ); ?>>
                        <?php echo esc_html( $section['title'] ); ?>
                    </label>
                    <p class="ofp-subtitle" style="margin:6px 0 14px;"><?php echo esc_html( $section['hint'] ); ?></p>

                    <div class="ofp-field">
                        <label for="call_<?php echo esc_attr( $type ); ?>_message">What the call says</label>
                        <textarea id="call_<?php echo esc_attr( $type ); ?>_message" name="call_<?php echo esc_attr( $type ); ?>_message" rows="4" maxlength="<?php echo (int) OFP_Voice_Notice::MAX_CHARS; ?>"><?php echo esc_textarea( $call_settings[ $type . '_message' ] ); ?></textarea>
                        <p class="ofp-subtitle" style="margin:6px 0 0; font-size:12px;">You can use: <?php echo esc_html( implode( '  ', $section['tags'] ) ); ?>. Keep it short and clear. Maximum <?php echo (int) OFP_Voice_Notice::MAX_CHARS; ?> characters.</p>
                    </div>
                </div>
            <?php endforeach; ?>

            <button type="submit" class="ofp-btn ofp-btn-primary">Save Call Scripts</button>
        </form>
    <?php endif; ?>

    <?php if ( $tab === 'send' ) : ?>

        <div class="ofp-card" style="padding: 32px;">
            <h3 style="margin-top: 0;">Send Broadcast Message</h3>
            <p class="ofp-subtitle" style="margin-bottom: 24px;">Send a manual SMS or Email broadcast to specific recipients.</p>

            <form id="send-broadcast-form">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <div class="ofp-field">
                        <label>Channel <span class="required">*</span></label>
                        <select name="channel" id="send-channel" required>
                            <option value="email">Email</option>
                            <option value="sms">SMS</option>
                        </select>
                    </div>

                    <div class="ofp-field">
                        <label>Template (Optional)</label>
                        <select name="template_id" id="send-template">
                            <option value="">-- No Template --</option>
                            <?php foreach ( $templates as $t ) : ?>
                                <option value="<?php echo esc_attr( $t->id ); ?>"
                                        data-type="<?php echo esc_attr( $t->type ); ?>"
                                        data-subject="<?php echo esc_attr( $t->subject ); ?>"
                                        data-body="<?php echo esc_attr( $t->body ); ?>">
                                    <?php echo esc_html( $t->name ); ?> (<?php echo strtoupper( $t->type ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="ofp-field" style="margin-top: 16px;">
                    <label id="recipients-label">Recipients <span class="required">*</span></label>
                    <p class="ofp-hint" id="recipients-hint" style="margin-top: 4px; margin-bottom: 8px;">Enter email addresses separated by commas.</p>
                    <textarea name="recipients" id="send-recipients" rows="3" required placeholder="example@email.com, another@email.com"></textarea>
                </div>

                <div class="ofp-field" id="send-subject-wrap" style="margin-top: 16px;">
                    <label>Subject <span class="required">*</span></label>
                    <input type="text" name="subject" id="send-subject" placeholder="Email subject line">
                </div>

                <div class="ofp-field" style="margin-top: 16px;">
                    <label>Message Body <span class="required">*</span></label>
                    <textarea name="body" id="send-body" rows="8" required placeholder="Write your message..."></textarea>
                </div>

                <div style="margin-top: 24px;">
                    <button type="submit" class="ofp-btn ofp-btn-primary" id="send-btn">Send Message</button>
                </div>
            </form>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const channelSelect = document.getElementById('send-channel');
            const templateSelect = document.getElementById('send-template');
            const subjectWrap = document.getElementById('send-subject-wrap');
            const subjectInput = document.getElementById('send-subject');
            const recipientsLabel = document.getElementById('recipients-label');
            const recipientsHint = document.getElementById('recipients-hint');
            const recipientsInput = document.getElementById('send-recipients');
            const bodyInput = document.getElementById('send-body');

            function toggleChannel() {
                const channel = channelSelect.value;
                if (channel === 'email') {
                    subjectWrap.style.display = 'block';
                    subjectInput.setAttribute('required', 'required');
                    recipientsLabel.innerHTML = 'Recipients <span class="required">*</span>';
                    recipientsHint.textContent = 'Enter email addresses separated by commas.';
                    recipientsInput.placeholder = 'example@email.com, another@email.com';
                } else {
                    subjectWrap.style.display = 'none';
                    subjectInput.removeAttribute('required');
                    recipientsLabel.innerHTML = 'Phone Numbers <span class="required">*</span>';
                    recipientsHint.textContent = 'Enter phone numbers separated by commas.';
                    recipientsInput.placeholder = '08012345678, 09087654321';
                }

                // Filter templates by channel
                Array.from(templateSelect.options).forEach(opt => {
                    if (opt.value === "") return;
                    opt.style.display = (opt.dataset.type === channel) ? 'block' : 'none';
                });
                templateSelect.value = "";
            }

            channelSelect.addEventListener('change', toggleChannel);
            toggleChannel();

            templateSelect.addEventListener('change', function() {
                const selected = this.options[this.selectedIndex];
                if (!selected.value) {
                    subjectInput.value = '';
                    bodyInput.value = '';
                    return;
                }

                if (channelSelect.value === 'email') {
                    subjectInput.value = selected.dataset.subject || '';
                }
                bodyInput.value = selected.dataset.body || '';
            });

            document.getElementById('send-broadcast-form').addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = document.getElementById('send-btn');
                btn.disabled = true;
                btn.textContent = 'Sending...';

                const formData = new FormData(this);
                formData.append('action', 'ofp_send_client_broadcast');
                formData.append('nonce', ofpClientData.nonce);

                fetch(ofpClientData.ajaxurl, { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            alert(res.data || 'Messages sent successfully!');
                            this.reset();
                            toggleChannel();
                        } else {
                            alert(res.data || 'Failed to send messages.');
                        }
                    })
                    .catch(() => alert('Network error. Please try again.'))
                    .finally(() => {
                        btn.disabled = false;
                        btn.textContent = 'Send Message';
                    });
            });
        });
        </script>

    <?php endif; ?>

    <?php
    // =====================================================================
    // TAB 3: MESSAGE TEMPLATES (SMS & Email)
    // =====================================================================
    ?>
    <?php if ( $tab === 'templates' ) : ?>


        <!-- Manage Message Templates (SMS/Email) -->
        <div style="display: flex; gap: 24px; flex-wrap: wrap;">
            <!-- Left: Template Form -->
            <div class="ofp-card" style="flex: 1; min-width: 400px; padding: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 id="form-title" style="margin:0;">Create Message Template</h3>
                    <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-ghost" onclick="resetForm()" id="btn-new-tpl" style="display:none;">+ New</button>
                </div>

                <form id="template-form">
                    <input type="hidden" id="tpl-id" name="id" value="">

                    <div class="ofp-field" style="margin-bottom: 16px;">
                        <label>Channel <span class="required">*</span></label>
                        <select id="tpl-type" name="type" required onchange="toggleTemplateChannel()">
                            <option value="sms">SMS</option>
                            <option value="email">Email</option>
                        </select>
                    </div>

                    <div class="ofp-field" style="margin-bottom: 16px;">
                        <label>Template Name <span class="required">*</span></label>
                        <input type="text" id="tpl-name" name="name" required placeholder="e.g. Welcome Message">
                    </div>

                    <div class="ofp-field" id="tpl-subject-wrap" style="margin-bottom: 16px; display:none;">
                        <label>Subject <span class="required">*</span></label>
                        <input type="text" id="tpl-subject" name="subject" placeholder="Email subject">
                    </div>

                    <div class="ofp-field" style="margin-bottom: 16px;">
                        <label>Message Body <span class="required">*</span></label>
                        <p class="ofp-hint" style="margin-top:0;">You can use placeholders: <code>{{name}}</code>, <code>{{phone}}</code>, <code>{{email}}</code></p>
                        <textarea id="tpl-body" name="body" rows="8" required placeholder="Your message here..." style="font-family: monospace; font-size:13px;"></textarea>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 24px;">
                        <button type="submit" class="ofp-btn ofp-btn-primary" id="tpl-submit">Save Template</button>
                        <button type="button" class="ofp-btn ofp-btn-ghost ofp-btn-danger" id="tpl-delete" style="display:none;" onclick="deleteActiveTemplate()">Delete</button>
                    </div>
                </form>

                <hr style="border:0; border-top:1px solid var(--border-color); margin: 32px 0;">

                <h3 style="margin-bottom: 16px;">Existing Templates</h3>
                <div style="display: flex; flex-direction: column; gap: 8px;" id="templates-list-container">
                    <?php if ( empty( $templates ) ) : ?>
                        <p class="ofp-hint">No templates saved yet.</p>
                    <?php else : ?>
                        <?php foreach ( $templates as $t ) : ?>
                            <div class="ofp-template-list-item" id="template-item-<?php echo (int) $t->id; ?>"
                                 style="padding: 12px 16px; border: 1px solid var(--border-color); border-radius: 8px; transition: all 0.2s; background: var(--bg-lighter); display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                                <div onclick='editTemplate(<?php echo json_encode( $t, JSON_HEX_APOS | JSON_HEX_QUOT ); ?>)' style="cursor: pointer; flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <strong><?php echo esc_html( $t->name ); ?></strong>
                                        <span class="ofp-badge <?php echo $t->type === 'sms' ? 'ofp-badge-blue' : 'ofp-badge-purple'; ?>">
                                            <?php echo esc_html( strtoupper( $t->type ) ); ?>
                                        </span>
                                    </div>
                                    <?php if ( $t->type === 'email' && ! empty( $t->subject ) ) : ?>
                                        <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">Subject: <?php echo esc_html( $t->subject ); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-ghost" onclick='editTemplate(<?php echo json_encode( $t, JSON_HEX_APOS | JSON_HEX_QUOT ); ?>)'>Edit</button>
                                    <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-ghost ofp-btn-danger" style="color:#ef4444;" onclick="deleteTemplateById(event, <?php echo (int) $t->id; ?>, '<?php echo esc_js( $t->name ); ?>')">Delete</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: Template Preview (Smartphone Mockup) -->
            <div class="ofp-template-preview-sidebar" style="width: 370px; flex-shrink: 0; position: sticky; top: 96px; align-self: flex-start;">
                <span id="preview-mode-badge" style="display:none;"></span>

                <div class="ofp-phone-mockup">
                    <img class="ofp-phone-frame-overlay" src="<?php echo esc_url( OFP_URL . 'assets/images/Smartphone-Mockup.png' ); ?>" alt="Smartphone Mockup" loading="lazy">
                    <div class="ofp-phone-screen">
                        <div class="ofp-phone-header">
                            <div class="ofp-phone-contact" id="ofp-tpl-contact-name">John Doe</div>
                            <div id="ofp-tpl-contact-sub" style="font-size: 11px; color: var(--text-muted); margin-top: 1px;">08012345678</div>
                        </div>

                        <!-- SMS View Inside Phone -->
                        <div class="ofp-phone-body" id="ofp-tpl-sms-view" style="display: flex;">
                            <div class="ofp-chat-bubble" id="ofp-tpl-chat-bubble">
                                <span style="color:#94a3b8; font-style:italic;">Type in the message body to see a live preview.</span>
                            </div>
                            <div id="ofp-tpl-sms-time" style="font-size: 10px; color: #94a3b8; margin-top: -6px; margin-left: 4px;">Just now</div>
                        </div>

                        <!-- Email View Inside Phone -->
                        <div id="ofp-tpl-email-view" style="display: none; flex: 1; flex-direction: column; background: #ffffff; overflow-y: auto;">
                            <div style="padding: 12px 14px; border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
                                <div style="font-size: 10px; text-transform: uppercase; font-weight: 600; color: #64748b; letter-spacing: 0.05em;">Subject</div>
                                <div id="ofp-tpl-email-subject" style="font-size: 13px; font-weight: 700; color: #0f172a; margin-top: 2px; word-break: break-word;">(No Subject)</div>
                                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">To: <span style="color: #0f172a; font-weight: 500;">John Doe &lt;john@example.com&gt;</span></div>
                            </div>
                            <div id="ofp-tpl-email-body" style="padding: 14px; font-size: 13px; line-height: 1.6; color: #334155; flex: 1; word-break: break-word;">
                                <span style="color:#94a3b8; font-style:italic;">Type in the message body to see a live preview.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var ajaxUrl = (window.ofpClientData && ofpClientData.ajaxurl) ? ofpClientData.ajaxurl : '<?php echo admin_url( "admin-ajax.php" ); ?>';
            var ajaxNonce = (window.ofpClientData && ofpClientData.nonce) ? ofpClientData.nonce : '<?php echo wp_create_nonce( "ofp_client_ajax" ); ?>';

            // ── Message Templates CRUD ──────────────────────────────────────
            const form = document.getElementById('template-form');
            const tplId = document.getElementById('tpl-id');
            const tplType = document.getElementById('tpl-type');
            const tplName = document.getElementById('tpl-name');
            const tplSubject = document.getElementById('tpl-subject');
            const tplSubjectWrap = document.getElementById('tpl-subject-wrap');
            const tplBody = document.getElementById('tpl-body');
            const btnDelete = document.getElementById('tpl-delete');
            const btnNew = document.getElementById('btn-new-tpl');
            const formTitle = document.getElementById('form-title');

            window.toggleTemplateChannel = function() {
                if (tplType.value === 'email') {
                    tplSubjectWrap.style.display = 'block';
                    tplSubject.setAttribute('required', 'required');
                } else {
                    tplSubjectWrap.style.display = 'none';
                    tplSubject.removeAttribute('required');
                }
                updateMsgPreview();
            };

            window.resetForm = function() {
                tplId.value = '';
                tplType.value = 'sms';
                tplName.value = '';
                tplSubject.value = '';
                tplBody.value = '';
                formTitle.textContent = 'Create Message Template';
                btnDelete.style.display = 'none';
                btnNew.style.display = 'none';
                toggleTemplateChannel();
            };

            window.editTemplate = function(tpl) {
                tplId.value = tpl.id;
                tplType.value = tpl.type;
                tplName.value = tpl.name;
                tplSubject.value = tpl.subject || '';
                tplBody.value = tpl.body || '';
                formTitle.textContent = 'Edit Template';
                btnDelete.style.display = 'block';
                btnNew.style.display = 'inline-block';
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                toggleTemplateChannel();
            };

            window.deleteTemplateById = function(e, id, name) {
                if (e) {
                    e.stopPropagation();
                    e.preventDefault();
                }
                if (!id) return;
                if (!confirm('Are you sure you want to delete template "' + (name || '#' + id) + '"?')) return;

                var formData = new FormData();
                formData.append('action', 'ofp_delete_template');
                formData.append('nonce', ajaxNonce);
                formData.append('template_id', id);

                fetch(ajaxUrl, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res && res.success) {
                            var el = document.getElementById('template-item-' + id);
                            if (el) el.remove();
                            if (tplId.value == id) {
                                resetForm();
                            }
                            alert(res.data || 'Template deleted successfully.');
                        } else {
                            alert((res && res.data) ? res.data : 'Failed to delete template.');
                        }
                    })
                    .catch(function(err) {
                        alert('Network or server error while deleting template.');
                    });
            };

            window.deleteActiveTemplate = function() {
                var id = tplId.value;
                if (!id) return;
                var name = tplName.value || 'this template';
                deleteTemplateById(null, id, name);
            };

            function updateMsgPreview() {
                var type = tplType.value;
                var body = tplBody.value || '';
                var subject = tplSubject.value || '';

                var smsView = document.getElementById('ofp-tpl-sms-view');
                var emailView = document.getElementById('ofp-tpl-email-view');
                var contactName = document.getElementById('ofp-tpl-contact-name');
                var contactSub = document.getElementById('ofp-tpl-contact-sub');
                var badge = document.getElementById('preview-mode-badge');
                var chatBubble = document.getElementById('ofp-tpl-chat-bubble');
                var emailSubject = document.getElementById('ofp-tpl-email-subject');
                var emailBody = document.getElementById('ofp-tpl-email-body');

                var displayBody = body.trim() ? body.replace(/\{\{name\}\}/gi, '<strong>John Doe</strong>')
                                                    .replace(/\{\{phone\}\}/gi, '<strong>08012345678</strong>')
                                                    .replace(/\{\{email\}\}/gi, '<strong>john@example.com</strong>')
                                                    .replace(/\n/g, '<br>')
                                              : '<span style="color:#94a3b8; font-style:italic;">Type in the message body to see a live preview.</span>';

                if (type === 'email') {
                    if (badge) {
                        badge.textContent = 'Email Preview';
                        badge.className = 'ofp-badge ofp-badge-purple';
                    }
                    if (contactName) contactName.textContent = 'Mail Preview';
                    if (contactSub) contactSub.textContent = 'john@example.com';
                    if (smsView) smsView.style.display = 'none';
                    if (emailView) emailView.style.display = 'flex';
                    if (emailSubject) emailSubject.textContent = subject.trim() ? subject : '(No Subject)';
                    if (emailBody) emailBody.innerHTML = displayBody;
                } else {
                    if (badge) {
                        badge.textContent = 'SMS Preview';
                        badge.className = 'ofp-badge ofp-badge-blue';
                    }
                    if (contactName) contactName.textContent = 'John Doe';
                    if (contactSub) contactSub.textContent = '08012345678';
                    if (emailView) emailView.style.display = 'none';
                    if (smsView) smsView.style.display = 'flex';
                    if (chatBubble) chatBubble.innerHTML = displayBody;
                }
            }

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = document.getElementById('tpl-submit');
                btn.disabled = true;
                btn.textContent = 'Saving...';

                var formData = new FormData(this);
                formData.append('action', 'ofp_save_template');
                formData.append('nonce', ajaxNonce);

                fetch(ajaxUrl, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            window.location.reload();
                        } else {
                            alert(res.data || 'Failed to save template.');
                            btn.disabled = false;
                            btn.textContent = 'Save Template';
                        }
                    })
                    .catch(function() {
                        alert('Network error. Please try again.');
                        btn.disabled = false;
                        btn.textContent = 'Save Template';
                    });
            });

            tplType.addEventListener('change', updateMsgPreview);
            tplSubject.addEventListener('input', updateMsgPreview);
            tplBody.addEventListener('input', updateMsgPreview);

            toggleTemplateChannel();
        });
        </script>

    <?php endif; ?>

    <?php
    // =====================================================================
    // TAB 4: EMAIL BRAND & LAYOUT (SaaS White-Label Customizer)
    // =====================================================================
    ?>
    <?php if ( $tab === 'branding' ) : ?>
        <?php
        $brand_color   = ! empty( $client->email_brand_color ) ? $client->email_brand_color : '#0f172a';
        $header_text   = $client->business_name ?: $client->owner_name;
        $header_tagline= ! empty( $client->email_header_tagline ) ? $client->email_header_tagline : '';
        $footer_text   = ! empty( $client->email_footer_text ) ? $client->email_footer_text : (
            'Sent by ' . ( $client->business_name ?: $client->owner_name ) . ( ! empty( $client->phone ) ? ' • Tel: ' . $client->phone : '' ) . "\n" .
            ( ! empty( $client->email ) ? 'Contact: ' . $client->email . "\n" : '' ) .
            'All rights reserved.'
        );
        $tpl_mode      = ! empty( $client->email_template_mode ) ? $client->email_template_mode : 'visual';
        $custom_html   = ! empty( $client->email_custom_html ) ? $client->email_custom_html : OFP_Mailer::get_default_custom_html_boilerplate( $client );
        $logo_url      = ! empty( $client->logo_url ) ? $client->logo_url : '';
        $boilerplate   = OFP_Mailer::get_default_custom_html_boilerplate( $client );
        ?>

        <div style="margin-bottom: 20px; display: flex; justify-content: flex-end;">
            <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-send-test" id="btn-send-test-top" onclick="sendTestEmail()">Send Test to <?php echo esc_html( $client->email ); ?></button>
        </div>

        <div style="display: flex; gap: 24px; flex-wrap: wrap; align-items: flex-start;">
            
            <!-- Left: Settings Form Card -->
            <div class="ofp-card" style="flex: 1; min-width: 420px; padding: 24px;">
                <form id="email-branding-form">
                    
                    <!-- Mode Switcher -->
                    <div style="margin-bottom: 24px; background: var(--bg-lighter); padding: 6px; border-radius: 12px; display: inline-flex; border: 1px solid var(--border-color);">
                        <label style="margin:0; cursor:pointer; padding: 8px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; transition: all 0.2s;" id="label-mode-visual">
                            <input type="radio" name="email_template_mode" value="visual" <?php checked( $tpl_mode, 'visual' ); ?> onchange="toggleBrandingMode()" style="display:none;">
                            Visual Customizer
                        </label>
                        <label style="margin:0; cursor:pointer; padding: 8px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; transition: all 0.2s;" id="label-mode-html">
                            <input type="radio" name="email_template_mode" value="custom_html" <?php checked( $tpl_mode, 'custom_html' ); ?> onchange="toggleBrandingMode()" style="display:none;">
                            Custom HTML Wrapper
                        </label>
                    </div>

                    <!-- VISUAL CUSTOMIZER SECTION -->
                    <div id="section-visual-branding">
                        
                        <!-- Brand Accent Color -->
                        <div class="ofp-field" style="margin-bottom: 20px;">
                            <label style="display:block; margin-bottom: 6px; font-weight: 600; font-size: 13px;">Primary Brand Color <span class="required">*</span></label>
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                                <input type="color" id="brand-color-picker" value="<?php echo esc_attr( $brand_color ); ?>" style="width: 44px; height: 40px; border: 1px solid var(--border-color); border-radius: 8px; padding: 2px; cursor: pointer; background: transparent;">
                                <input type="text" id="brand-color-text" name="email_brand_color" value="<?php echo esc_attr( $brand_color ); ?>" maxlength="7" style="width: 120px; font-family: monospace; font-weight: 600; text-transform: uppercase;">
                                <span style="font-size: 12px; color: var(--text-muted);">Used for email banner header & accent borders</span>
                            </div>
                            <!-- Swatches -->
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <?php
                                $swatches = [
                                    '#0f172a' => 'Slate Dark',
                                    '#2563eb' => 'Royal Blue',
                                    '#059669' => 'Emerald Green',
                                    '#d97706' => 'Amber Gold',
                                    '#4f46e5' => 'Modern Indigo',
                                    '#dc2626' => 'Crimson Red',
                                    '#7c3aed' => 'Deep Violet'
                                ];
                                foreach ( $swatches as $c => $lbl ) : ?>
                                    <button type="button" onclick="setBrandColor('<?php echo $c; ?>')" title="<?php echo esc_attr( $lbl ); ?>" style="width: 24px; height: 24px; border-radius: 50%; background: <?php echo $c; ?>; border: 2px solid #fff; box-shadow: 0 0 0 1px #cbd5e1; cursor: pointer; transition: transform 0.15s;" onmouseover="this.style.transform='scale(1.2)';" onmouseout="this.style.transform='scale(1)';"></button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Company Logo -->
                        <div class="ofp-field" style="margin-bottom: 20px;">
                            <label style="display:block; margin-bottom: 6px; font-weight: 600; font-size: 13px;">Company Logo (locked)</label>
                            <input type="hidden" id="eb-logo-url" value="<?php echo esc_attr( $logo_url ); ?>">
                            <?php if ( $logo_url ) : ?>
                                <img src="<?php echo esc_url( $logo_url ); ?>" alt="Logo" style="max-height:48px;max-width:220px;display:block;margin-bottom:6px;">
                            <?php endif; ?>
                            <p class="ofp-hint" style="margin-top: 4px; font-size: 12px;">
                                Your emails use the logo and business name from your account. To change the logo,
                                <a href="<?php echo esc_url( home_url( '/account' ) ); ?>" target="_blank" style="color: var(--accent-blue); text-decoration: underline;">Upload logo in Account Settings &rarr;</a>
                            </p>
                        </div>

                        <!-- Header Title -->
                        <div class="ofp-field" style="margin-bottom: 20px;">
                            <label style="display:block; margin-bottom: 6px; font-weight: 600; font-size: 13px;">Business Name (locked)</label>
                            <input type="text" id="eb-header-text" value="<?php echo esc_attr( $header_text ); ?>" readonly placeholder="e.g. Apex Homes & Realty" style="background:#f1f5f9;cursor:not-allowed;width: 100%;">
                        </div>

                        <!-- Header Tagline -->
                        <div class="ofp-field" style="margin-bottom: 20px;">
                            <label style="display:block; margin-bottom: 6px; font-weight: 600; font-size: 13px;">Header Tagline / Subtitle (Optional)</label>
                            <input type="text" id="eb-header-tagline" name="email_header_tagline" value="<?php echo esc_attr( $header_tagline ); ?>" placeholder="e.g. Verified Properties & Real Estate Consultancy" style="width: 100%;">
                        </div>

                        <!-- Footer Text -->
                        <div class="ofp-field" style="margin-bottom: 20px;">
                            <label style="display:block; margin-bottom: 6px; font-weight: 600; font-size: 13px;">Footer Signature & Disclaimer</label>
                            <textarea id="eb-footer-text" name="email_footer_text" rows="4" style="width: 100%; font-size: 13px; line-height: 1.5;"><?php echo esc_textarea( $footer_text ); ?></textarea>
                            <p class="ofp-hint" style="margin-top: 4px; font-size: 12px;">Displays at the bottom of all outbound client emails (Office Address, contact phone, compliance notes).</p>
                        </div>

                    </div>

                    <!-- CUSTOM HTML WRAPPER SECTION -->
                    <div id="section-custom-html-branding" style="display: none;">
                        <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.2); border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                            <div style="font-size: 13px; font-weight: 600; color: var(--accent-blue); margin-bottom: 4px;">Developer Mode Enabled</div>
                            <div style="font-size: 12px; color: var(--text-muted); line-height: 1.5;">
                                Write your own full responsive HTML email wrapper. You <strong>must</strong> include the tag <code>{email_content}</code> where the actual message text will be placed.
                            </div>
                        </div>

                        <!-- Tag Pills Helper -->
                        <div style="margin-bottom: 12px;">
                            <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px;">Click to copy variable:</div>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <?php foreach ( [ '{email_content}', '{subject}', '{business_name}', '{owner_name}', '{client_phone}', '{client_email}', '{year}' ] as $tag ) : ?>
                                    <button type="button" onclick="insertTagAtCursor('<?php echo $tag; ?>')" class="ofp-badge ofp-badge-blue" style="cursor: pointer; border: none; font-size: 11px; padding: 4px 8px;">
                                        <?php echo esc_html( $tag ); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="ofp-field" style="margin-bottom: 16px;">
                            <textarea id="eb-custom-html" name="email_custom_html" rows="18" style="width: 100%; font-family: 'Fira Code', 'Courier New', monospace; font-size: 12px; line-height: 1.5; background: #0b1120; color: #38bdf8; border: 1px solid #1e293b; border-radius: 8px; padding: 12px; tab-size: 2;"><?php echo esc_textarea( $custom_html ); ?></textarea>
                        </div>

                        <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-ghost" onclick="resetToBoilerplate()" style="margin-bottom: 16px;">Reset to Standard Boilerplate</button>
                    </div>

                    <!-- Action Buttons -->
                    <div style="display: flex; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                        <button type="submit" class="ofp-btn ofp-btn-primary" id="btn-save-branding" style="padding: 10px 24px;">Save Email Branding</button>
                        <button type="button" class="ofp-btn ofp-btn-send-test" id="btn-send-test-bottom" onclick="sendTestEmail()">Send Test Email</button>
                    </div>

                    <div id="branding-status-message" style="margin-top: 16px; font-size: 13px; font-weight: 500; display: none;"></div>
                </form>
            </div>

            <!-- Right: Interactive Live Preview Card -->
            <div style="flex: 1.1; min-width: 420px; display: flex; flex-direction: column;">
                <div class="ofp-card" style="padding: 16px 20px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; border-radius: 12px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 6px #10b981;"></span>
                        <strong style="font-size: 14px; color: var(--text-main);">Live Preview</strong>
                        <span style="font-size: 12px; color: var(--text-muted);">(Real-Time)</span>
                    </div>

                    <!-- Device Switcher -->
                    <div style="background: var(--bg-lighter); padding: 4px; border-radius: 8px; display: flex; gap: 4px; border: 1px solid var(--border-color);">
                        <button type="button" id="btn-preview-desktop" onclick="setPreviewDevice('desktop')" style="border:none; padding: 4px 12px; font-size: 12px; font-weight: 600; border-radius: 6px; cursor: pointer; background: var(--accent-blue); color: #fff;">Desktop</button>
                        <button type="button" id="btn-preview-mobile" onclick="setPreviewDevice('mobile')" style="border:none; padding: 4px 12px; font-size: 12px; font-weight: 600; border-radius: 6px; cursor: pointer; background: transparent; color: var(--text-muted);">Mobile</button>
                    </div>
                </div>

                <!-- Preview Frame Container -->
                <div id="preview-wrapper" style="width: 100%; border: 1px solid var(--border-color); border-radius: 12px; background: #e2e8f0; min-height: 600px; display: flex; justify-content: center; align-items: flex-start; padding: 20px 0; overflow: hidden; transition: all 0.25s ease;">
                    <iframe id="live-email-preview-frame" style="width: 100%; max-width: 600px; min-height: 560px; border: none; border-radius: 8px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); background: #ffffff; transition: max-width 0.25s ease;"></iframe>
                </div>
            </div>

        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var ajaxUrl   = (window.ofpClientData && ofpClientData.ajaxurl) ? ofpClientData.ajaxurl : '<?php echo admin_url( "admin-ajax.php" ); ?>';
            var ajaxNonce = (window.ofpClientData && ofpClientData.nonce) ? ofpClientData.nonce : '<?php echo wp_create_nonce( "ofp_client_ajax" ); ?>';

            const defaultBoilerplate = <?php echo json_encode( $boilerplate ); ?>;
            const clientOwner = <?php echo json_encode( $client->owner_name ); ?>;
            const clientBiz   = <?php echo json_encode( $client->business_name ?: $client->owner_name ); ?>;
            const clientPhone = <?php echo json_encode( $client->phone ?: '' ); ?>;
            const clientEmail = <?php echo json_encode( $client->email ?: '' ); ?>;

            const form = document.getElementById('email-branding-form');
            const picker = document.getElementById('brand-color-picker');
            const colorText = document.getElementById('brand-color-text');
            const logoUrlInput = document.getElementById('eb-logo-url');
            const headerTextInput = document.getElementById('eb-header-text');
            const headerTaglineInput = document.getElementById('eb-header-tagline');
            const footerTextInput = document.getElementById('eb-footer-text');
            const customHtmlInput = document.getElementById('eb-custom-html');
            const iframe = document.getElementById('live-email-preview-frame');
            const statusMsg = document.getElementById('branding-status-message');

            // Sync Color Picker & Hex Text
            picker.addEventListener('input', function() {
                colorText.value = picker.value.toUpperCase();
                updateLivePreview();
            });
            colorText.addEventListener('input', function() {
                if (/^#[0-9A-Fa-f]{6}$/.test(colorText.value)) {
                    picker.value = colorText.value;
                    updateLivePreview();
                }
            });

            window.setBrandColor = function(hex) {
                picker.value = hex;
                colorText.value = hex.toUpperCase();
                updateLivePreview();
            };

            window.toggleBrandingMode = function() {
                const mode = form.querySelector('input[name="email_template_mode"]:checked').value;
                const secVisual = document.getElementById('section-visual-branding');
                const secHtml = document.getElementById('section-custom-html-branding');
                const lblVisual = document.getElementById('label-mode-visual');
                const lblHtml = document.getElementById('label-mode-html');

                if (mode === 'custom_html') {
                    secVisual.style.display = 'none';
                    secHtml.style.display = 'block';
                    lblHtml.style.background = 'var(--accent-blue)';
                    lblHtml.style.color = '#fff';
                    lblVisual.style.background = 'transparent';
                    lblVisual.style.color = 'var(--text-muted)';
                } else {
                    secVisual.style.display = 'block';
                    secHtml.style.display = 'none';
                    lblVisual.style.background = 'var(--accent-blue)';
                    lblVisual.style.color = '#fff';
                    lblHtml.style.background = 'transparent';
                    lblHtml.style.color = 'var(--text-muted)';
                }
                updateLivePreview();
            };

            window.setPreviewDevice = function(device) {
                const btnDesk = document.getElementById('btn-preview-desktop');
                const btnMob = document.getElementById('btn-preview-mobile');

                if (device === 'mobile') {
                    iframe.style.maxWidth = '375px';
                    btnMob.style.background = 'var(--accent-blue)';
                    btnMob.style.color = '#fff';
                    btnDesk.style.background = 'transparent';
                    btnDesk.style.color = 'var(--text-muted)';
                } else {
                    iframe.style.maxWidth = '600px';
                    btnDesk.style.background = 'var(--accent-blue)';
                    btnDesk.style.color = '#fff';
                    btnMob.style.background = 'transparent';
                    btnMob.style.color = 'var(--text-muted)';
                }
            };

            window.insertTagAtCursor = function(tag) {
                const ta = customHtmlInput;
                const start = ta.selectionStart;
                const end = ta.selectionEnd;
                const text = ta.value;
                ta.value = text.substring(0, start) + tag + text.substring(end);
                ta.selectionStart = ta.selectionEnd = start + tag.length;
                ta.focus();
                updateLivePreview();
            };

            window.resetToBoilerplate = function() {
                if (confirm('Replace current HTML code with the standard clean email boilerplate?')) {
                    customHtmlInput.value = defaultBoilerplate;
                    updateLivePreview();
                }
            };

            // Render live preview
            function updateLivePreview() {
                const mode = form.querySelector('input[name="email_template_mode"]:checked').value;
                let html = '';

                const sampleBody = `
                    <p style="margin: 0 0 16px;">Hello <strong>Valued Client</strong>,</p>
                    <p style="margin: 0 0 16px;">We are pleased to introduce our newly available luxury 3-Bedroom apartment in Lekki Phase 1, featuring 24/7 security, fully-fitted kitchen, and swimming pool.</p>
                    <div style="background:#f8fafc; border-left: 4px solid ` + (picker.value || '#0f172a') + `; padding: 14px 18px; margin: 20px 0; border-radius: 4px;">
                        <p style="margin:0; font-size:14px; font-weight:600; color:#1e293b;">Inspection slots are open this Saturday from 10:00 AM to 2:00 PM.</p>
                    </div>
                    <p style="margin: 0 0 20px;">Reply to this email or click below to schedule your private tour with our property manager.</p>
                    <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px 0;">
                        <tr>
                            <td style="border-radius: 6px; background: ` + (picker.value || '#0f172a') + `;">
                                <a href="#" style="font-size: 14px; font-weight: 600; color: #ffffff; text-decoration: none; padding: 12px 24px; display: inline-block;">Schedule Inspection &rarr;</a>
                            </td>
                        </tr>
                    </table>
                    <p style="margin: 24px 0 0; color: #64748b; font-size: 14px;">Warm regards,<br><strong style="color:#1e293b;">` + (headerTextInput.value || clientBiz) + `</strong></p>
                `;

                if (mode === 'custom_html') {
                    let raw = customHtmlInput.value || '';
                    if (!raw.trim()) {
                        raw = defaultBoilerplate;
                    }
                    html = raw
                        .replace(/\{email_content\}|\{\{content\}\}|\{\{body\}\}/g, sampleBody)
                        .replace(/\{subject\}|\{\{subject\}\}/g, 'Exclusive Listing: Luxury 3-Bedroom Duplex')
                        .replace(/\{business_name\}|\{\{business_name\}\}/g, headerTextInput.value || clientBiz)
                        .replace(/\{owner_name\}|\{\{owner_name\}\}/g, clientOwner)
                        .replace(/\{client_phone\}|\{\{phone\}\}/g, clientPhone)
                        .replace(/\{client_email\}|\{\{email\}\}/g, clientEmail)
                        .replace(/\{year\}|\{\{year\}\}/g, new Date().getFullYear());
                    
                    if (!raw.includes('{email_content}') && !raw.includes('{{content}}') && !raw.includes('{{body}}')) {
                        html += '<div style="padding:20px;">' + sampleBody + '</div>';
                    }
                } else {
                    const color = picker.value || '#0f172a';
                    const title = headerTextInput.value || clientBiz;
                    const tagline = headerTaglineInput.value;
                    const logo = logoUrlInput.value;
                    const footer = footerTextInput.value.replace(/\n/g, '<br>');

                    let headerLogoHtml = '';
                    if (logo) {
                        headerLogoHtml = `<img src="${logo}" alt="${title}" style="max-height:48px; max-width:220px; height:auto; margin-bottom:12px; display:block;">`;
                    }
                    let taglineHtml = '';
                    if (tagline) {
                        taglineHtml = `<p style="margin:6px 0 0;font-size:13px;color:rgba(255,255,255,0.85);line-height:1.4;">${tagline}</p>`;
                    }

                    html = `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>Email Preview</title>
                    </head>
                    <body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:24px 12px;">
                            <tr>
                                <td align="center">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;margin:0 auto;">
                                        <!-- Header -->
                                        <tr>
                                            <td style="background-color:${color};border-radius:12px 12px 0 0;padding:28px 32px;">
                                                ${headerLogoHtml}
                                                <h1 style="margin:0;font-size:20px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;line-height:1.3;">${title}</h1>
                                                ${taglineHtml}
                                            </td>
                                        </tr>
                                        <!-- Body -->
                                        <tr>
                                            <td style="background-color:#ffffff;padding:32px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0;color:#1e293b;font-size:15px;line-height:1.65;">
                                                ${sampleBody}
                                            </td>
                                        </tr>
                                        <!-- Footer -->
                                        <tr>
                                            <td style="background-color:#f8fafc;border-radius:0 0 12px 12px;border:1px solid #e2e8f0;border-top:none;padding:22px 32px;text-align:center;">
                                                <div style="color:#64748b;font-size:12px;line-height:1.7;">
                                                    ${footer}
                                                </div>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </body>
                    </html>
                    `;
                }

                iframe.srcdoc = html;
            }

            // Real-time input listeners
            [logoUrlInput, headerTextInput, headerTaglineInput, footerTextInput, customHtmlInput].forEach(function(el) {
                if (el) {
                    el.addEventListener('input', updateLivePreview);
                }
            });

            // Handle Form Save
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const btn = document.getElementById('btn-save-branding');
                btn.disabled = true;
                btn.textContent = 'Saving...';
                statusMsg.style.display = 'none';

                const formData = new FormData(form);
                formData.append('action', 'ofp_save_email_branding');
                formData.append('nonce', ajaxNonce);

                fetch(ajaxUrl, { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(res => {
                        btn.disabled = false;
                        btn.textContent = 'Save Email Branding';
                        statusMsg.style.display = 'block';
                        if (res.success) {
                            statusMsg.style.color = '#10b981';
                            statusMsg.textContent = res.data || 'Branding saved successfully!';
                            setTimeout(() => { statusMsg.style.display = 'none'; }, 4000);
                        } else {
                            statusMsg.style.color = '#ef4444';
                            statusMsg.textContent = res.data || 'Failed to save settings.';
                        }
                    })
                    .catch(() => {
                        btn.disabled = false;
                        btn.textContent = 'Save Email Branding';
                        statusMsg.style.display = 'block';
                        statusMsg.style.color = '#ef4444';
                        statusMsg.textContent = 'Network error. Please try again.';
                    });
            });

            // Handle Test Email
            window.sendTestEmail = function() {
                const btn1 = document.getElementById('btn-send-test-top');
                const btn2 = document.getElementById('btn-send-test-bottom');
                if (btn1) { btn1.disabled = true; btn1.textContent = 'Sending...'; }
                if (btn2) { btn2.disabled = true; btn2.textContent = 'Sending...'; }
                statusMsg.style.display = 'none';

                const formData = new FormData();
                formData.append('action', 'ofp_send_test_email');
                formData.append('nonce', ajaxNonce);

                fetch(ajaxUrl, { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(res => {
                        if (btn1) { btn1.disabled = false; btn1.textContent = 'Send Test to <?php echo esc_js( $client->email ); ?>'; }
                        if (btn2) { btn2.disabled = false; btn2.textContent = 'Send Test Email'; }
                        statusMsg.style.display = 'block';
                        if (res.success) {
                            statusMsg.style.color = '#10b981';
                            statusMsg.textContent = res.data || 'Test email sent! Check your inbox.';
                            alert(res.data || 'Test email sent successfully!');
                        } else {
                            statusMsg.style.color = '#ef4444';
                            statusMsg.textContent = res.data || 'Failed to send test email.';
                            alert(res.data || 'Failed to send test email.');
                        }
                    })
                    .catch(() => {
                        if (btn1) { btn1.disabled = false; btn1.textContent = 'Send Test to <?php echo esc_js( $client->email ); ?>'; }
                        if (btn2) { btn2.disabled = false; btn2.textContent = 'Send Test Email'; }
                        statusMsg.style.display = 'block';
                        statusMsg.style.color = '#ef4444';
                        statusMsg.textContent = 'Network error. Please try again.';
                    });
            };

            // Initialize mode and live preview
            toggleBrandingMode();
        });
        </script>

    <?php endif; ?>

</div>
</main>
</div><!-- .ofp-shell -->

<?php if ( $tab === 'log' ) : ?>
<script>
(function() {
    var form                = document.getElementById('comm-filter-form');
    var feedContainer       = document.getElementById('comm-feed-container');
    var paginationContainer = document.getElementById('comm-pagination-container');
    var countBadge          = document.getElementById('comm-count-badge');
    var clearBtn            = document.getElementById('comm-clear-btn');
    var searchInput         = document.getElementById('comm-search-input');
    var typeSelect          = document.getElementById('comm-type-select');
    var statusSelect        = document.getElementById('comm-status-select');

    if (!feedContainer) return;

    var searchTimer = null;
    var currentPage = <?php echo (int) $current_page; ?>;
    var ajaxUrl     = (window.ofpClientData && window.ofpClientData.ajaxurl) ? window.ofpClientData.ajaxurl : '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
    var nonce       = (window.ofpClientData && window.ofpClientData.nonce) ? window.ofpClientData.nonce : '<?php echo wp_create_nonce( 'ofp_client_ajax' ); ?>';

    function fetchCommunications(page) {
        if (page !== undefined && page !== null) {
            currentPage = page;
        }

        var type   = typeSelect ? typeSelect.value : '';
        var status = statusSelect ? statusSelect.value : '';
        var s      = searchInput ? searchInput.value.trim() : '';

        // Visual loading state
        feedContainer.style.opacity = '0.4';
        feedContainer.style.pointerEvents = 'none';

        var formData = new FormData();
        formData.append('action', 'ofp_fetch_communications');
        formData.append('nonce', nonce);
        formData.append('type', type);
        formData.append('status', status);
        formData.append('s', s);
        formData.append('paged', currentPage);

        fetch(ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            feedContainer.style.opacity = '1';
            feedContainer.style.pointerEvents = 'auto';

            if (res && res.success && res.data) {
                feedContainer.innerHTML = res.data.html;
                if (paginationContainer) {
                    paginationContainer.innerHTML = res.data.pagination;
                }
                if (countBadge) {
                    countBadge.innerHTML = res.data.count_text;
                }
                if (clearBtn) {
                    clearBtn.style.display = res.data.has_filter ? 'inline-flex' : 'none';
                }

                // Smooth URL update without browser reload
                var url = new URL(window.location.href);
                url.searchParams.set('tab', 'log');
                if (type) url.searchParams.set('type', type); else url.searchParams.delete('type');
                if (status) url.searchParams.set('status', status); else url.searchParams.delete('status');
                if (s) url.searchParams.set('s', s); else url.searchParams.delete('s');
                if (currentPage > 1) url.searchParams.set('paged', currentPage); else url.searchParams.delete('paged');
                window.history.replaceState({}, '', url.toString());
            }
        })
        .catch(function(err) {
            feedContainer.style.opacity = '1';
            feedContainer.style.pointerEvents = 'auto';
            console.error('AJAX communications fetch error:', err);
        });
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', function() {
            fetchCommunications(1);
        });
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            fetchCommunications(1);
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() {
                fetchCommunications(1);
            }, 350);
        });
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchTimer);
                fetchCommunications(1);
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            clearTimeout(searchTimer);
            fetchCommunications(1);
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (searchInput) searchInput.value = '';
            if (typeSelect) typeSelect.value = '';
            if (statusSelect) statusSelect.value = '';
            fetchCommunications(1);
        });
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.ofp-comm-page-btn');
        if (btn) {
            e.preventDefault();
            var page = parseInt(btn.getAttribute('data-page'), 10);
            if (page && page !== currentPage) {
                fetchCommunications(page);
                feedContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    });

    // Dynamically position custom dropdown options with fixed coordinates and max z-index so options never clip
    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.ofp-comm-filter-bar .ofp-custom-select-trigger');
        if (trigger) {
            var wrapper = trigger.closest('.ofp-custom-select-wrapper');
            if (wrapper) {
                setTimeout(function() {
                    var options = wrapper.querySelector('.ofp-custom-select-options');
                    if (options && wrapper.classList.contains('open')) {
                        var rect = trigger.getBoundingClientRect();
                        options.style.position = 'fixed';
                        options.style.top = (rect.bottom + 4) + 'px';
                        options.style.left = rect.left + 'px';
                        options.style.minWidth = Math.max(rect.width, 160) + 'px';
                        options.style.zIndex = '999999';
                        options.style.display = 'block';
                        options.style.opacity = '1';
                        options.style.visibility = 'visible';
                    }
                }, 10);
            }
        }
    });

    window.addEventListener('scroll', function() {
        document.querySelectorAll('.ofp-comm-filter-bar .ofp-custom-select-wrapper.open').forEach(function(el) {
            el.classList.remove('open');
        });
    }, { passive: true });
})();
</script>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
