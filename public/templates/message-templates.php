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

$tab = sanitize_text_field( $_GET['tab'] ?? 'log' );
$allowed_tabs = [ 'log', 'send', 'templates', 'branding' ];
if ( ! in_array( $tab, $allowed_tabs, true ) ) {
    $tab = 'log';
}
if ( in_array( $tab, [ 'send', 'templates', 'branding' ], true ) && ! OFP_Subscription::allows_email_templates( (int) $client->id ) ) {
    $tab = 'log';
}

// ── Data for Log Tab ────────────────────────────────────────────────────
$filter_type  = sanitize_text_field( $_GET['type'] ?? '' );
$per_page     = 25;
$current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$offset       = ( $current_page - 1 ) * $per_page;

$where = 'cl.client_id = %d';
$args  = [ $client->id ];

if ( $filter_type ) {
    $where .= ' AND cl.type = %s';
    $args[] = $filter_type;
}

$total = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log cl WHERE {$where}", ...$args )
);

$comms = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT cl.*, l.name as lead_name, l.phone as lead_phone
         FROM {$p}ofp_communications_log cl
         LEFT JOIN {$p}ofp_leads l ON l.id = cl.lead_id
         WHERE {$where}
         ORDER BY cl.sent_at DESC
         LIMIT %d OFFSET %d",
        ...array_merge( $args, [ $per_page, $offset ] )
    )
);

$total_pages = ceil( $total / $per_page );

// Summary stats (scoped to client)
$sms_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE client_id = %d AND type = 'sms'",   $client->id ) );
$voice_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE client_id = %d AND type = 'voice'", $client->id ) );
$email_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE client_id = %d AND type = 'email'", $client->id ) );
$total_cost  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(cost) FROM {$p}ofp_communications_log WHERE client_id = %d", $client->id ) );

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
    <title>Communications — OFast Pipeline</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <div class="ofp-page-header">
        <h1>Communications</h1>
        <p>Communications log, broadcasts, and reusable message templates.</p>
    </div>

    <!-- Tabs -->
    <div class="ofp-tabs" style="margin-bottom: 24px; border-bottom: 1px solid var(--border-color);">
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=log' ) ); ?>" class="ofp-tab <?php echo $tab === 'log' ? 'active' : ''; ?>">Communications Log</a>
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

        <!-- Summary Stat Cards -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 32px;">
            <!-- SMS Sent -->
            <div style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); border-radius: 16px; padding: 24px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(124, 58, 237, 0.2);">
                <div style="position: absolute; top: -10px; right: -10px; opacity: 0.15; width: 90px; height: 90px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:100%;height:100%;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                </div>
                <div style="font-size: 13px; font-weight: 500; opacity: 0.9; margin-bottom: 8px;">Total SMS Sent</div>
                <div style="font-size: 28px; font-weight: 700;"><?php echo esc_html( number_format( $sms_count ) ); ?></div>
            </div>
            <!-- Calls Made -->
            <div style="background: linear-gradient(135deg, #10b981, #059669); border-radius: 16px; padding: 24px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.2);">
                <div style="position: absolute; top: -10px; right: -10px; opacity: 0.15; width: 90px; height: 90px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:100%;height:100%;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                </div>
                <div style="font-size: 13px; font-weight: 500; opacity: 0.9; margin-bottom: 8px;">Total Calls Made</div>
                <div style="font-size: 28px; font-weight: 700;"><?php echo esc_html( number_format( $voice_count ) ); ?></div>
            </div>
            <!-- Emails Sent -->
            <div style="background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 16px; padding: 24px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(245, 158, 11, 0.2);">
                <div style="position: absolute; top: -10px; right: -10px; opacity: 0.15; width: 90px; height: 90px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:100%;height:100%;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                </div>
                <div style="font-size: 13px; font-weight: 500; opacity: 0.9; margin-bottom: 8px;">Total Emails Sent</div>
                <div style="font-size: 28px; font-weight: 700;"><?php echo esc_html( number_format( $email_count ) ); ?></div>
            </div>
            <!-- Total Credit Used -->
            <div style="background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 16px; padding: 24px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.2);">
                <div style="position: absolute; top: -10px; right: -10px; opacity: 0.15; font-size: 80px; font-weight: 700; font-family: sans-serif; line-height: 1;">₦</div>
                <div style="font-size: 13px; font-weight: 500; opacity: 0.9; margin-bottom: 8px;">Total Credit Used</div>
                <div style="font-size: 28px; font-weight: 700;">₦<?php echo esc_html( number_format( $total_cost, 0 ) ); ?></div>
            </div>
        </div>

        <!-- Filter Pills -->
        <div style="display: flex; gap: 12px; margin-bottom: 24px;">
            <?php foreach ( [ '' => 'All', 'sms' => 'SMS', 'voice' => 'Voice', 'email' => 'Email' ] as $val => $label ) :
                $active = $filter_type === $val;
                $bg    = $active ? 'var(--accent-blue)' : 'var(--bg-lighter)';
                $color = $active ? '#fff' : 'var(--text-muted)';
            ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'type' => $val, 'tab' => 'log' ], home_url( '/message-templates' ) ) ); ?>"
                   style="padding: 8px 16px; font-size: 13px; font-weight: 600; text-decoration: none; border-radius: 20px; background: <?php echo $bg; ?>; color: <?php echo $color; ?>; transition: all 0.2s;">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Activity Feed -->
        <div class="ofp-card" style="padding:0; overflow:hidden;">
            <?php if ( empty( $comms ) ) : ?>
                <div class="ofp-empty" style="padding:48px;">
                    <div class="ofp-empty-icon" style="display:flex; justify-content:center; margin-bottom:12px;">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    </div>
                    <h3>No communications yet</h3>
                    <p>Messages sent to your leads will appear here.</p>
                </div>
            <?php else : ?>
                <div style="display: flex; flex-direction: column; gap: 16px; padding: 24px;">
                    <?php foreach ( $comms as $comm ) :
                        $is_success = $comm->status === 'sent';
                        $bg = 'rgba(139, 92, 246, 0.1)'; $ic = '#8b5cf6';
                        $svg_icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
                        if ( $comm->type === 'voice' ) {
                            $bg = 'rgba(16, 185, 129, 0.1)'; $ic = '#10b981';
                            $svg_icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>';
                        } elseif ( $comm->type === 'email' ) {
                            $bg = 'rgba(245, 158, 11, 0.1)'; $ic = '#f59e0b';
                            $svg_icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>';
                        }
                    ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px; border-radius: 12px; background: var(--bg-lighter); border: 1px solid var(--border-color); transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='none';">

                            <!-- Left: Icon & Message Preview -->
                            <div style="display: flex; gap: 16px; align-items: center; flex: 1; min-width: 0;">
                                <div style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: <?php echo $bg; ?>; color: <?php echo $ic; ?>; flex-shrink: 0;">
                                    <?php echo $svg_icon; ?>
                                </div>
                                <div style="min-width: 0;">
                                    <div style="font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
                                        <?php echo esc_html( $comm->lead_name ?: $comm->lead_phone ?: '—' ); ?>
                                        <?php if ( ! $is_success ) : ?>
                                            <span style="font-size: 10px; padding: 2px 6px; border-radius: 4px; background: rgba(239, 68, 68, 0.1); color: var(--accent-red); font-weight: 700; text-transform: uppercase;">Failed</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 13px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 400px;" title="<?php echo esc_attr( $comm->message ); ?>">
                                        <?php echo esc_html( mb_substr( $comm->message ?? '', 0, 80 ) . ( mb_strlen( $comm->message ?? '' ) > 80 ? '…' : '' ) ); ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Right: Cost & Time -->
                            <div style="text-align: right; flex-shrink: 0;">
                                <div style="font-size: 14px; font-weight: 700; color: <?php echo $is_success ? 'var(--accent-red)' : 'var(--text-muted)'; ?>; margin-bottom: 4px;">
                                    - ₦<?php echo esc_html( number_format( (float) $comm->cost, 2 ) ); ?>
                                </div>
                                <div style="font-size: 12px; color: var(--text-muted);">
                                    <?php echo esc_html( human_time_diff( strtotime( $comm->sent_at ), current_time( 'timestamp' ) ) . ' ago' ); ?>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="ofp-pagination" style="padding:16px;">
                        <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( [ 'paged' => $i, 'type' => $filter_type, 'tab' => 'log' ], home_url( '/message-templates' ) ) ); ?>"
                               class="ofp-page-btn <?php echo $i === $current_page ? 'active' : ''; ?>">
                                <?php echo esc_html( $i ); ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <?php
    // =====================================================================
    // TAB 2: SEND BROADCAST
    // =====================================================================
    ?>
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

        <div style="margin-bottom: 24px;">
            <h2 style="margin: 0 0 6px; font-size: 20px; font-weight: 700; color: var(--text-main);">Reusable Message Templates</h2>
            <p style="margin: 0; color: var(--text-muted); font-size: 14px;">Create and manage reusable SMS and email templates to speed up broadcasts and lead messages.</p>
        </div>

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

            <!-- Right: Template Preview -->
            <div style="flex: 1; min-width: 400px; display: flex; flex-direction: column;">
                <h4 style="margin-top:0; margin-bottom: 12px;">Message Preview</h4>
                <div style="flex-grow: 1; border: 1px solid var(--border-color); border-radius: 8px; background: #fff; min-height: 500px; overflow: hidden; display: flex; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                    <iframe id="client-template-preview" style="width:100%; height:100%; border:none; flex-grow: 1; background: #fff;"></iframe>
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
            const iframe = document.getElementById('client-template-preview');

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
                var previewHtml = '';

                if (!body.trim()) {
                    previewHtml = '<div style="padding:40px; text-align:center; color:#94a3b8; font-family:sans-serif;">Type in the message body to see a preview.</div>';
                } else {
                    var displayBody = body.replace(/\{\{name\}\}/g, '<strong>John Doe</strong>')
                                          .replace(/\{\{phone\}\}/g, '<strong>08012345678</strong>')
                                          .replace(/\{\{email\}\}/g, '<strong>john@example.com</strong>')
                                          .replace(/\n/g, '<br>');

                    if (type === 'email') {
                        previewHtml = '<div style="font-family: sans-serif; background: #f3f4f6; padding: 20px; min-height: 100vh;">'
                            + '<div style="max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">'
                            + '<div style="padding: 16px 24px; border-bottom: 1px solid #e5e7eb; background: #fafafa;">'
                            + '<div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">Subject:</div>'
                            + '<div style="font-size: 16px; font-weight: 600; color: #111827;">' + (subject || '(No Subject)') + '</div>'
                            + '</div>'
                            + '<div style="padding: 24px; color: #374151; line-height: 1.6; font-size: 15px;">' + displayBody + '</div>'
                            + '</div></div>';
                    } else {
                        previewHtml = '<div style="font-family: -apple-system, BlinkMacSystemFont, sans-serif; height: 100vh; display: flex; align-items: center; justify-content: center; background: #f1f5f9;">'
                            + '<div style="width: 300px; height: 500px; background: #fff; border-radius: 30px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1); border: 8px solid #cbd5e1; position: relative; overflow: hidden;">'
                            + '<div style="background: #f8fafc; padding: 40px 16px 16px; text-align: center; border-bottom: 1px solid #e2e8f0; font-weight: 600;">Message</div>'
                            + '<div style="padding: 16px; background: #fff; height: 100%; box-sizing: border-box; overflow-y: auto;">'
                            + '<div style="background: #e2e8f0; color: #0f172a; padding: 12px 16px; border-radius: 16px; border-bottom-left-radius: 4px; display: inline-block; max-width: 85%; font-size: 14px; line-height: 1.5;">'
                            + displayBody + '</div>'
                            + '<div style="font-size: 10px; color: #94a3b8; margin-top: 4px; margin-left: 4px;">Just now</div>'
                            + '</div></div></div>';
                    }
                }

                var doc = iframe.contentDocument || iframe.contentWindow.document;
                doc.open();
                doc.writeln(previewHtml);
                doc.close();
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
        $header_text   = ! empty( $client->email_header_text ) ? $client->email_header_text : ( $client->business_name ?: $client->owner_name );
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

        <div style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h2 style="margin: 0 0 6px; font-size: 20px; font-weight: 700; color: var(--text-main);">Email Brand & Layout Customizer</h2>
                    <p style="margin: 0; color: var(--text-muted); font-size: 14px;">Personalize the outer shell of all broadcast emails, lead notifications, and announcements sent from your business.</p>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-ghost" id="btn-send-test-top" onclick="sendTestEmail()">Send Test to <?php echo esc_html( $client->email ); ?></button>
                </div>
            </div>
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
                            <label style="display:block; margin-bottom: 6px; font-weight: 600; font-size: 13px;">Company Logo URL</label>
                            <input type="url" id="eb-logo-url" name="logo_url" value="<?php echo esc_attr( $logo_url ); ?>" placeholder="https://yourwebsite.com/logo.png" style="width: 100%;">
                            <p class="ofp-hint" style="margin-top: 4px; font-size: 12px;">
                                Enter a public image URL for your logo (PNG with transparent background recommended).
                                <a href="<?php echo esc_url( home_url( '/account' ) ); ?>" target="_blank" style="color: var(--accent-blue); text-decoration: underline;">Upload logo in Account Settings &rarr;</a>
                            </p>
                        </div>

                        <!-- Header Title -->
                        <div class="ofp-field" style="margin-bottom: 20px;">
                            <label style="display:block; margin-bottom: 6px; font-weight: 600; font-size: 13px;">Header Title / Business Name <span class="required">*</span></label>
                            <input type="text" id="eb-header-text" name="email_header_text" value="<?php echo esc_attr( $header_text ); ?>" required placeholder="e.g. Apex Homes & Realty" style="width: 100%;">
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
                        <button type="button" class="ofp-btn ofp-btn-ghost" id="btn-send-test-bottom" onclick="sendTestEmail()">Send Test Email</button>
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

<?php wp_footer(); ?>
</body>
</html>
