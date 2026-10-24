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

if ( false === get_option( 'ofp_schema_client_email_brand_v1' ) ) {
    $has_ebc = $wpdb->get_results( "SHOW COLUMNS FROM {$p}ofp_clients LIKE 'email_brand_color'" );
    if ( empty( $has_ebc ) ) {
        $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_brand_color VARCHAR(20) DEFAULT '#0f172a' AFTER logo_url" );
        $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_header_text VARCHAR(150) DEFAULT NULL AFTER email_brand_color" );
        $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_header_tagline VARCHAR(255) DEFAULT NULL AFTER email_header_text" );
        $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_footer_text TEXT DEFAULT NULL AFTER email_header_tagline" );
        $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_template_mode VARCHAR(20) DEFAULT 'visual' AFTER email_footer_text" );
        $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN email_custom_html MEDIUMTEXT DEFAULT NULL AFTER email_template_mode" );
    }
    update_option( 'ofp_schema_client_email_brand_v1', 1, false );
}

// ── Send Broadcast: leads picked on the Leads page ─────────────────────────
$aud_pre   = sanitize_key( $_GET['aud'] ?? '' );
$sel_token = sanitize_text_field( wp_unslash( $_GET['sel'] ?? '' ) );
$sel_ids   = [];
if ( $sel_token !== '' && preg_match( '/^[A-Za-z0-9]{20}$/', $sel_token ) ) {
    $stored  = get_transient( 'ofp_sel_' . (int) $client->id . '_' . $sel_token );
    $sel_ids = is_array( $stored ) ? $stored : [];
}
if ( empty( $sel_ids ) ) {
    $sel_token = '';
}
$aud_batches = OFP_Contacts::batches( (int) $client->id );

// ── Redirect old 'calls' tab to Automation page (#call-scripts) ────────────
if ( isset( $_GET['tab'] ) && $_GET['tab'] === 'calls' ) {
    wp_safe_redirect( home_url( '/pipeline-settings#call-scripts' ) );
    exit;
}

$tab = sanitize_text_field( $_GET['tab'] ?? 'log' );
$allowed_tabs = [ 'log', 'send', 'templates', 'branding' ];
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

// Summary stats (scoped to client across all communications) - aggregated in 1 query
$comm_stats = $wpdb->get_row( $wpdb->prepare(
    "SELECT 
        SUM(CASE WHEN type = 'sms' THEN 1 ELSE 0 END) AS sms_count,
        SUM(CASE WHEN type = 'voice' THEN 1 ELSE 0 END) AS voice_count,
        SUM(CASE WHEN type = 'email' THEN 1 ELSE 0 END) AS email_count,
        SUM(CASE WHEN status NOT IN ('failed', 'error', 'pending') THEN cost ELSE 0 END) AS total_cost
     FROM {$p}ofp_communications_log 
     WHERE client_id = %d",
    (int) $client->id
) );
$sms_count   = (int) ( $comm_stats->sms_count ?? 0 );
$voice_count = (int) ( $comm_stats->voice_count ?? 0 );
$email_count = (int) ( $comm_stats->email_count ?? 0 );
$total_cost  = (float) ( $comm_stats->total_cost ?? 0.0 );

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
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <!-- Tabs -->
    <div class="ofp-tabs" style="margin-bottom: 24px; border-bottom: 1px solid var(--border-color);">
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=log' ) ); ?>" data-tab="log" class="ofp-tab <?php echo $tab === 'log' ? 'active' : ''; ?>">Communications Log</a>
        <?php if ( OFP_Subscription::allows_email_templates( (int) $client->id ) ) : ?>
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=send' ) ); ?>" data-tab="send" class="ofp-tab <?php echo $tab === 'send' ? 'active' : ''; ?>">Send Broadcast</a>
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=templates' ) ); ?>" data-tab="templates" class="ofp-tab <?php echo $tab === 'templates' ? 'active' : ''; ?>">Message Templates</a>
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=branding' ) ); ?>" data-tab="branding" class="ofp-tab <?php echo $tab === 'branding' ? 'active' : ''; ?>">Email Brand & Layout</a>
        <?php endif; ?>
    </div>

    <?php
    // =====================================================================
    // TAB 1: COMMUNICATIONS LOG
    // =====================================================================
    ?>
    <div id="ofp-tab-pane-log" class="ofp-tab-pane" style="<?php echo $tab === 'log' ? '' : 'display:none;'; ?>">

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
            <div id="comm-feed-container" data-current-page="<?php echo (int) $current_page; ?>">
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
    </div><!-- #ofp-tab-pane-log -->

    <?php
    // =====================================================================
    // TAB 2: SEND BROADCAST
    // =====================================================================
    if ( OFP_Subscription::allows_email_templates( (int) $client->id ) ) :
    ?>
    <div id="ofp-tab-pane-send" class="ofp-tab-pane" style="<?php echo $tab === 'send' ? '' : 'display:none;'; ?>">

    <div class="ofp-broadcast-layout">
        <!-- Left: Broadcast Composer Form -->
        <div class="ofp-broadcast-form-col">
            <div class="ofp-card" style="padding: 24px;">
                <h3 style="margin: 0 0 4px; font-size: 16px; font-weight: 700; line-height: 1.3;">Send Broadcast Message</h3>
                <p class="ofp-subtitle" style="margin: 0 0 16px; font-size: 12.5px; color: var(--text-muted); line-height: 1.4;">Send a manual SMS or Email broadcast to specific recipients.</p>

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
                        <label for="send-audience">Who do you want to message?</label>
                        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                            <select id="send-audience" style="min-width:240px;">
                                <option value="">I will type or paste them myself</option>
                                <?php if ( ! empty( $sel_ids ) ) : ?>
                                    <option value="leads_selected" <?php selected( $aud_pre, 'leads_selected' ); ?>>Leads I selected (<?php echo (int) count( $sel_ids ); ?>)</option>
                                <?php endif; ?>
                                <?php foreach ( [ 'leads_all', 'leads_status', 'leads_batch', 'buyers', 'tenants', 'customers' ] as $aud_key ) : ?>
                                    <option value="<?php echo esc_attr( $aud_key ); ?>" <?php selected( $aud_pre, $aud_key ); ?>><?php echo esc_html( OFP_Contacts::audience_types()[ $aud_key ] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="send-aud-status" style="display:none;">
                                <?php foreach ( [ 'new' => 'New', 'contacted' => 'Contacted', 'interested' => 'Interested', 'converted' => 'Converted', 'dead' => 'Dead' ] as $v => $l ) : ?>
                                    <option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $l ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="send-aud-batch" style="display:none;">
                                <?php if ( empty( $aud_batches ) ) : ?><option value="">No imports yet</option><?php endif; ?>
                                <?php foreach ( $aud_batches as $b ) : ?>
                                    <option value="<?php echo esc_attr( $b ); ?>"><?php echo esc_html( preg_replace( '/^import: /', '', $b ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="ofp-hint" id="send-aud-info" style="margin-top:6px;"></p>
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
                        <p class="ofp-hint" style="margin-top:6px;">Merge tags available: <code>{{name}}</code>, <code>{{business_name}}</code>, <code>{{property}}</code>, <code>{{phone}}</code>, <code>{{amount}}</code>, <code>{{due_date}}</code>.</p>
                    </div>

                    <div style="margin-top: 24px;">
                        <button type="submit" class="ofp-btn ofp-btn-primary" id="send-btn">Send Message</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right: Dispatch Inspector Deck -->
        <div class="ofp-broadcast-inspector-col">
            <div class="ofp-card ofp-inspector-card" style="padding: 24px;">
                <!-- Header with Pulse & Persona Switcher -->
                <div class="ofp-inspector-header">
                    <div class="ofp-inspector-title-row">
                        <div class="ofp-inspector-title">
                            <span class="ofp-pulse-indicator"></span>
                            <span>Dispatch Simulator</span>
                        </div>
                        <span class="ofp-badge ofp-inspector-channel-badge" id="insp-channel-badge">Email</span>
                    </div>

                    <!-- Persona Switcher -->
                    <div class="ofp-persona-bar">
                        <span class="ofp-persona-label">Simulate Recipient:</span>
                        <div class="ofp-persona-pills">
                            <button type="button" class="ofp-persona-pill active" data-persona="lead">Lead</button>
                            <button type="button" class="ofp-persona-pill" data-persona="buyer">Buyer</button>
                            <button type="button" class="ofp-persona-pill" data-persona="tenant">Tenant</button>
                        </div>
                    </div>
                </div>

                <!-- Notification Glass Banner -->
                <div class="ofp-notification-preview" id="insp-notification-preview">
                    <div class="ofp-notification-glass">
                        <div class="ofp-notification-top">
                            <div class="ofp-notification-sender">
                                <div class="ofp-notification-avatar">
                                    <?php if ( ! empty( $client->logo_url ) ) : ?>
                                        <img src="<?php echo esc_url( $client->logo_url ); ?>" alt="Logo">
                                    <?php else : ?>
                                        <span><?php echo esc_html( strtoupper( mb_substr( $client->business_name ?? 'OFP', 0, 2 ) ) ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="ofp-notification-app-name"><?php echo esc_html( $client->business_name ?? 'My Business' ); ?></div>
                            </div>
                            <div class="ofp-notification-time">just now</div>
                        </div>
                        <div class="ofp-notification-title" id="insp-notif-title">Subject Line</div>
                        <div class="ofp-notification-body" id="insp-notif-body">Your message preview will appear here...</div>
                    </div>
                </div>

                <!-- Rendered Payload Card -->
                <div class="ofp-rendered-msg-card">
                    <div class="ofp-rendered-header">
                        <span>Personalized Payload</span>
                        <span class="ofp-var-counter" id="insp-var-count">0 variables</span>
                    </div>
                    <div class="ofp-rendered-content" id="insp-rendered-content">Type your message to see how personalized variables are resolved for each recipient.</div>
                </div>

                <!-- Telemetry HUD -->
                <div class="ofp-inspector-telemetry">
                    <div class="ofp-telemetry-item">
                        <span class="ofp-telemetry-lbl">Audience Reach</span>
                        <span class="ofp-telemetry-val" id="insp-rec-count">0</span>
                    </div>
                    <div class="ofp-telemetry-item">
                        <span class="ofp-telemetry-lbl">Size / Payload</span>
                        <span class="ofp-telemetry-val" id="insp-char-count">0 chars</span>
                    </div>
                    <div class="ofp-telemetry-item">
                        <span class="ofp-telemetry-lbl">Estimated Cost</span>
                        <span class="ofp-telemetry-val" id="insp-cost-val" style="color:#10b981;">Free</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Audience auto-resolution
        var aud = document.getElementById('send-audience'), audStatus = document.getElementById('send-aud-status'),
            audBatch = document.getElementById('send-aud-batch'), audInfo = document.getElementById('send-aud-info'),
            recBox = document.getElementById('send-recipients'), chan = document.getElementById('send-channel'),
            sel = <?php echo wp_json_encode( $sel_token ); ?>,
            url = (window.ofpClientData && ofpClientData.ajaxurl) ? ofpClientData.ajaxurl : <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
            nonce = (window.ofpClientData && ofpClientData.nonce) ? ofpClientData.nonce : <?php echo wp_json_encode( wp_create_nonce( 'ofp_client_ajax' ) ); ?>,
            seq = 0;

        function resolveAudience() {
            audStatus.style.display = aud.value === 'leads_status' ? '' : 'none';
            audBatch.style.display  = aud.value === 'leads_batch' ? '' : 'none';
            if (!aud.value) { audInfo.textContent = ''; return; }
            var mine = ++seq, fd = new FormData();
            fd.append('action', 'ofp_resolve_audience'); fd.append('nonce', nonce);
            fd.append('channel', chan.value); fd.append('audience', aud.value);
            fd.append('status', audStatus.value); fd.append('batch', audBatch.value); fd.append('sel', sel);
            audInfo.textContent = 'Finding people...';
            fetch(url, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (res) {
                if (mine !== seq) return;
                if (!res.success) { audInfo.textContent = res.data || 'Could not load that list.'; return; }
                var d = res.data, what = chan.value === 'sms' ? 'phone numbers' : 'email addresses';
                recBox.value = d.recipients;
                var msg = d.count + ' ' + what + ' added. You can still edit the box below.';
                if (d.left_out > 0) msg += ' ' + d.left_out + ' left out (no ' + (chan.value === 'sms' ? 'phone number' : 'email') + ' or repeated).';
                if (d.capped) msg += ' Only the first ' + d.cap + ' were added. Send the rest in a second broadcast.';
                if (d.sample && d.sample.length) msg += ' For example: ' + d.sample.join(', ') + '.';
                audInfo.textContent = msg;
                updateInspector();
            }).catch(function () { if (mine === seq) audInfo.textContent = 'Could not load that list. Please try again.'; });
        }
        [aud, audStatus, audBatch].forEach(function (el) { el.addEventListener('change', resolveAudience); });
        chan.addEventListener('change', function () { if (aud.value) resolveAudience(); });
        if (aud.value) resolveAudience();

        // ── Dispatch Inspector Simulation Engine ────────────────────────
        const channelSelect = document.getElementById('send-channel');
        const templateSelect = document.getElementById('send-template');
        const subjectWrap = document.getElementById('send-subject-wrap');
        const subjectInput = document.getElementById('send-subject');
        const recipientsLabel = document.getElementById('recipients-label');
        const recipientsHint = document.getElementById('recipients-hint');
        const recipientsInput = document.getElementById('send-recipients');
        const bodyInput = document.getElementById('send-body');

        const inspBadge = document.getElementById('insp-channel-badge');
        const notifTitle = document.getElementById('insp-notif-title');
        const notifBody = document.getElementById('insp-notif-body');
        const renderedContent = document.getElementById('insp-rendered-content');
        const varCounter = document.getElementById('insp-var-count');
        const recCount = document.getElementById('insp-rec-count');
        const charCount = document.getElementById('insp-char-count');
        const costVal = document.getElementById('insp-cost-val');

        const clientBusiness = <?php echo wp_json_encode( $client->business_name ?? 'My Business' ); ?>;

        const personas = {
            lead: {
                name: 'John Okafor',
                business_name: clientBusiness,
                property: 'Ocean Crest Penthouse',
                phone: '08023456789',
                email: 'john.okafor@example.com',
                amount: '₦45,000,000',
                due_date: '31st Oct'
            },
            buyer: {
                name: 'Sarah Adebayo',
                business_name: clientBusiness,
                property: 'Greenlake Residence',
                phone: '08134567890',
                email: 'sarah.adebayo@example.com',
                amount: '₦12,500,000',
                due_date: '15th Nov'
            },
            tenant: {
                name: 'Ahmed Bello',
                business_name: clientBusiness,
                property: 'Admiralty Flat 4B',
                phone: '09087654321',
                email: 'ahmed.bello@example.com',
                amount: '₦3,200,000',
                due_date: '1st Dec'
            }
        };

        let currentPersona = 'lead';

        // Persona button handlers
        document.querySelectorAll('.ofp-persona-pill').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.ofp-persona-pill').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentPersona = this.getAttribute('data-persona') || 'lead';
                updateInspector();
            });
        });

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function renderWithChips(rawText, pData) {
            let varCount = 0;
            let replaced = escapeHtml(rawText);

            const tags = ['name', 'business_name', 'property', 'phone', 'amount', 'due_date'];
            tags.forEach(t => {
                const re = new RegExp('\\{\\{' + t + '\\}\\}|\\{' + t + '\\}', 'gi');
                const val = pData[t] || '—';
                replaced = replaced.replace(re, function(match) {
                    varCount++;
                    return '<span class="ofp-tag-chip" title="' + match + '">' + escapeHtml(val) + '</span>';
                });
            });

            return { html: replaced, count: varCount };
        }

        function resolvePlain(rawText, pData) {
            let replaced = rawText;
            const tags = ['name', 'business_name', 'property', 'phone', 'amount', 'due_date'];
            tags.forEach(t => {
                const re = new RegExp('\\{\\{' + t + '\\}\\}|\\{' + t + '\\}', 'gi');
                replaced = replaced.replace(re, pData[t] || '—');
            });
            return replaced;
        }

        function updateInspector() {
            const isEmail = channelSelect.value === 'email';
            const pData = personas[currentPersona] || personas.lead;

            inspBadge.textContent = isEmail ? 'Email' : 'SMS';
            inspBadge.style.background = isEmail ? 'rgba(59, 130, 246, 0.12)' : 'rgba(16, 185, 129, 0.12)';
            inspBadge.style.color = isEmail ? 'var(--btn-primary, #3b82f6)' : '#10b981';
            inspBadge.style.borderColor = isEmail ? 'rgba(59, 130, 246, 0.25)' : 'rgba(16, 185, 129, 0.25)';

            // 1. Notification Card
            const rawSub = subjectInput.value.trim();
            const rawBody = bodyInput.value.trim();

            if (isEmail) {
                notifTitle.textContent = rawSub ? resolvePlain(rawSub, pData) : 'Exclusive Update from ' + clientBusiness;
                notifBody.textContent = rawBody ? resolvePlain(rawBody, pData) : 'Type your email body to see live preview...';
            } else {
                notifTitle.textContent = clientBusiness;
                notifBody.textContent = rawBody ? resolvePlain(rawBody, pData) : 'Type your SMS message to see live preview...';
            }

            // 2. Rendered Payload Card (with chips)
            if (!rawBody) {
                renderedContent.innerHTML = '<span style="color:var(--text-muted); font-style:italic;">Type your message in the composer to view personalized payload with live variable resolution.</span>';
                varCounter.textContent = '0 variables';
            } else {
                const res = renderWithChips(rawBody, pData);
                renderedContent.innerHTML = res.html;
                varCounter.textContent = res.count + ' variable' + (res.count === 1 ? '' : 's');
            }

            // 3. Telemetry HUD
            // Recipients count
            const recRaw = recipientsInput.value.trim();
            const totalRecipients = recRaw ? recRaw.split(',').filter(x => x.trim().length > 0).length : 0;
            recCount.textContent = totalRecipients;

            // Character & Segment sizing
            const bodyLen = rawBody.length;
            if (isEmail) {
                charCount.innerHTML = bodyLen + ' chars <small style="color:var(--text-muted);font-weight:400;">(HTML)</small>';
                costVal.textContent = 'Free (Plan)';
                costVal.style.color = '#10b981';
            } else {
                let segments = 1;
                if (bodyLen > 160) {
                    segments = Math.ceil(bodyLen / 153);
                }
                charCount.innerHTML = bodyLen + ' chars <small style="color:var(--text-muted);font-weight:400;">(' + segments + ' seg)</small>';
                
                const neededCredits = totalRecipients * segments;
                const estCostNaira = (neededCredits * 6.99).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                costVal.textContent = neededCredits + ' Credits (₦' + estCostNaira + ')';
                costVal.style.color = neededCredits > 0 ? '#10b981' : 'var(--text-muted)';
            }
        }

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
                if (opt.value === '') return;
                opt.style.display = (opt.dataset.type === channel) ? 'block' : 'none';
            });
            templateSelect.value = '';
            updateInspector();
        }

        channelSelect.addEventListener('change', toggleChannel);
        toggleChannel();

        templateSelect.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            if (!selected.value) {
                subjectInput.value = '';
                bodyInput.value = '';
                updateInspector();
                return;
            }

            if (channelSelect.value === 'email') {
                subjectInput.value = selected.dataset.subject || '';
            }
            bodyInput.value = selected.dataset.body || '';
            updateInspector();
        });

        // Real-time typing listeners
        [subjectInput, bodyInput, recipientsInput].forEach(el => {
            if (el) el.addEventListener('input', updateInspector);
        });

        // Broadcast submission
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
                        updateInspector();
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

        window.updateInspector = updateInspector;
        updateInspector();
    });
    </script>
    </div><!-- #ofp-tab-pane-send -->

    <?php
    // =====================================================================
    // TAB 3: MESSAGE TEMPLATES (SMS & Email)
    // =====================================================================
    ?>
    <div id="ofp-tab-pane-templates" class="ofp-tab-pane" style="<?php echo $tab === 'templates' ? '' : 'display:none;'; ?>">


        <!-- Manage Message Templates (SMS/Email) -->
        <div class="ofp-pipeline-layout">
            <!-- Left: Template Form -->
            <div class="ofp-pipeline-main">
                <div class="ofp-card" style="padding: 24px;">
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
                            <p class="ofp-hint" style="margin-top:0;">You can use placeholders: <code>{{name}}</code>, <code>{{phone}}</code>, <code>{{email}}</code>, <code>{{business_name}}</code>, <code>{{property}}</code></p>
                            <textarea id="tpl-body" name="body" rows="8" required placeholder="Your message here..." style="font-family: inherit; font-size:13px;"></textarea>
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
            </div>

            <!-- Right: Smartphone Mockup Preview Sidebar -->
            <div class="ofp-pipeline-sidebar">
                <div class="ofp-phone-mockup">
                    <img class="ofp-phone-frame-overlay" src="<?php echo esc_url( OFP_URL . 'assets/images/Smartphone-Mockup.png' ); ?>" alt="Smartphone Mockup" loading="lazy">
                    <div class="ofp-phone-screen">
                        <div class="ofp-phone-header">
                            <div class="ofp-phone-contact" id="ofp-tpl-phone-header">SMS Preview</div>
                        </div>
                        <div class="ofp-phone-body" id="ofpPhoneBody">
                            <!-- SMS View -->
                            <div id="ofp-tpl-sms-view" style="display: flex; flex-direction: column; gap: 6px; width: 100%;">
                                <div class="ofp-chat-bubble active" id="ofp-tpl-sms-bubble" style="transform: none !important; word-break: break-word; white-space: pre-wrap;">Type in the message body to see a preview.</div>
                                <div style="font-size: 10px; color: var(--text-muted, #94a3b8); margin-left: 6px;" id="ofp-tpl-sms-timestamp">Just now</div>
                            </div>

                            <!-- Email View -->
                            <div id="ofp-tpl-email-view" style="display: none; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); width: 100%;">
                                <div style="padding: 12px 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                                    <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; font-weight: 700; margin-bottom: 2px;">Subject</div>
                                    <div id="ofp-tpl-email-subject" style="font-size: 13px; font-weight: 600; color: #0f172a; word-break: break-word;">(No Subject)</div>
                                </div>
                                <div id="ofp-tpl-email-body" style="padding: 14px; font-size: 12.5px; line-height: 1.6; color: #334155; word-break: break-word; white-space: pre-wrap;">Type in the message body to see a preview.</div>
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
            var clientBizName = <?php echo json_encode( $client->business_name ?: ( $client->owner_name ?: 'My Business' ) ); ?>;

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

            const phoneHeader = document.getElementById('ofp-tpl-phone-header');
            const smsView = document.getElementById('ofp-tpl-sms-view');
            const smsBubble = document.getElementById('ofp-tpl-sms-bubble');
            const emailView = document.getElementById('ofp-tpl-email-view');
            const emailSubj = document.getElementById('ofp-tpl-email-subject');
            const emailBody = document.getElementById('ofp-tpl-email-body');

            function escapeHtml(str) {
                var div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            function formatPreviewText(text) {
                if (!text || !text.trim()) {
                    return '<span style="color:var(--text-muted,#94a3b8);font-style:italic;">Type in the message body to see a preview.</span>';
                }
                var escaped = escapeHtml(text);
                return escaped
                    .replace(/\{\{name\}\}/gi, '<strong style="color:var(--accent-blue,#2563eb);">John Doe</strong>')
                    .replace(/\{\{phone\}\}/gi, '<strong style="color:var(--accent-blue,#2563eb);">08012345678</strong>')
                    .replace(/\{\{email\}\}/gi, '<strong style="color:var(--accent-blue,#2563eb);">john@example.com</strong>')
                    .replace(/\{\{business_name\}\}/gi, '<strong style="color:var(--accent-blue,#2563eb);">' + escapeHtml(clientBizName) + '</strong>')
                    .replace(/\{\{property\}\}/gi, '<strong style="color:var(--accent-blue,#2563eb);">3 Bedroom Apartment</strong>')
                    .replace(/\{\{amount\}\}/gi, '<strong style="color:var(--accent-blue,#2563eb);">₦2,500,000</strong>')
                    .replace(/\{\{due_date\}\}/gi, '<strong style="color:var(--accent-blue,#2563eb);">Oct 31, 2026</strong>')
                    .replace(/\n/g, '<br>');
            }

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
                var body = tplBody.value;
                var subject = tplSubject.value;

                if (type === 'email') {
                    if (phoneHeader) phoneHeader.textContent = 'Email Preview';
                    if (smsView) smsView.style.display = 'none';
                    if (emailView) emailView.style.display = 'block';
                    if (emailSubj) {
                        emailSubj.textContent = subject.trim() ? subject : '(No Subject)';
                    }
                    if (emailBody) {
                        emailBody.innerHTML = formatPreviewText(body);
                    }
                } else {
                    if (phoneHeader) phoneHeader.textContent = (type === 'voice') ? 'Voice Preview' : 'SMS Preview';
                    if (emailView) emailView.style.display = 'none';
                    if (smsView) smsView.style.display = 'flex';
                    if (smsBubble) {
                        smsBubble.innerHTML = formatPreviewText(body);
                        if (type === 'voice') {
                            smsBubble.classList.add('call-bubble');
                        } else {
                            smsBubble.classList.remove('call-bubble');
                        }
                    }
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

            window.updateMsgPreview = updateMsgPreview;
            window.toggleTemplateChannel = toggleTemplateChannel;
            toggleTemplateChannel();
        });
        </script>
    </div><!-- #ofp-tab-pane-templates -->

    <?php
    // =====================================================================
    // TAB 4: EMAIL BRAND & LAYOUT (SaaS White-Label Customizer)
    // =====================================================================
    ?>
    <div id="ofp-tab-pane-branding" class="ofp-tab-pane" style="<?php echo $tab === 'branding' ? '' : 'display:none;'; ?>">
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

                        <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-secondary" onclick="resetToBoilerplate()" style="margin-bottom: 16px; background: var(--bg-card, #ffffff); border: 1px solid var(--border-color, #cbd5e1); color: var(--text-main, #0f172a); font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: inline-flex; align-items: center; gap: 6px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path><path d="M21 3v5h-5"></path><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path><path d="M8 16H3v5"></path></svg>
                            Reset to Standard Boilerplate
                        </button>
                    </div>

                    <!-- Action Buttons -->
                    <div style="display: flex; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border-color); align-items: center;">
                        <button type="submit" class="ofp-btn ofp-btn-primary" id="btn-save-branding" style="padding: 10px 24px;">Save Email Branding</button>
                        <button type="button" class="ofp-btn ofp-btn-send-test" id="btn-send-test-bottom" onclick="sendTestEmail()">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                            Send Test Email
                        </button>
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
            window.toggleBrandingMode = toggleBrandingMode;
            window.updateLivePreview = updateLivePreview;
            toggleBrandingMode();
        });
        </script>
    </div><!-- #ofp-tab-pane-branding -->
    <?php endif; // OFP_Subscription::allows_email_templates ?>

    <!-- AJAX Tab Switching Script -->
    <script>
    (function() {
        function switchCommTab(targetTab, updateHistory) {
            if (!targetTab) return;
            var targetPane = document.getElementById('ofp-tab-pane-' + targetTab);
            if (!targetPane) return;

            // Update tab button active states
            var tabs = document.querySelectorAll('.ofp-tabs .ofp-tab');
            tabs.forEach(function(t) {
                if (t.getAttribute('data-tab') === targetTab) {
                    t.classList.add('active');
                } else {
                    t.classList.remove('active');
                }
            });

            // Toggle tab pane visibility
            var panes = document.querySelectorAll('.ofp-tab-pane');
            panes.forEach(function(p) {
                if (p.id === 'ofp-tab-pane-' + targetTab) {
                    p.style.display = '';
                    p.classList.add('active');
                } else {
                    p.style.display = 'none';
                    p.classList.remove('active');
                }
            });

            // Sync URL via history.pushState without page reload
            if (updateHistory !== false && window.history && window.history.pushState) {
                var url = new URL(window.location.href);
                url.searchParams.set('tab', targetTab);
                window.history.pushState({ tab: targetTab }, '', url.toString());
            }

            // Trigger refreshes for active pane
            if (targetTab === 'send' && typeof window.updateInspector === 'function') {
                window.updateInspector();
            } else if (targetTab === 'templates' && typeof window.updateMsgPreview === 'function') {
                window.updateMsgPreview();
            } else if (targetTab === 'branding' && typeof window.toggleBrandingMode === 'function') {
                window.toggleBrandingMode();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var tabLinks = document.querySelectorAll('.ofp-tabs .ofp-tab');
            tabLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    var targetTab = this.getAttribute('data-tab');
                    if (targetTab && document.getElementById('ofp-tab-pane-' + targetTab)) {
                        e.preventDefault();
                        switchCommTab(targetTab, true);
                    }
                });
            });

            window.addEventListener('popstate', function() {
                var params = new URLSearchParams(window.location.search);
                var currentTab = params.get('tab') || 'log';
                if (document.getElementById('ofp-tab-pane-' + currentTab)) {
                    switchCommTab(currentTab, false);
                }
            });
        });
    })();
    </script>

</div>
</main>
</div><!-- .ofp-shell -->


<?php wp_footer(); ?>
</body>
</html>
