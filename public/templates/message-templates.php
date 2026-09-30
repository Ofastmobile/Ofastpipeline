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

$tab = sanitize_text_field( $_GET['tab'] ?? 'log');
$allowed_tabs = [ 'log', 'send', 'templates' ];
if ( ! in_array( $tab, $allowed_tabs, true ) ) {
    $tab = 'log';
}
if ( in_array( $tab, [ 'send', 'templates' ], true ) && ! OFP_Subscription::allows_email_templates( (int) $client->id ) ) {
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
        <p>Communications log, manual broadcasts, and email template management.</p>
    </div>

    <!-- Tabs -->
    <div class="ofp-tabs" style="margin-bottom: 24px; border-bottom: 1px solid var(--border-color);">
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=log' ) ); ?>" class="ofp-tab <?php echo $tab === 'log' ? 'active' : ''; ?>">Communications Log</a>
        <?php if ( OFP_Subscription::allows_email_templates( (int) $client->id ) ) : ?>
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=send' ) ); ?>" class="ofp-tab <?php echo $tab === 'send' ? 'active' : ''; ?>">Send Broadcast</a>
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=templates' ) ); ?>" class="ofp-tab <?php echo $tab === 'templates' ? 'active' : ''; ?>">Email Template</a>
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
                <div style="position: absolute; top: -10px; right: -10px; opacity: 0.1; font-size: 80px;">💬</div>
                <div style="font-size: 13px; font-weight: 500; opacity: 0.9; margin-bottom: 8px;">Total SMS Sent</div>
                <div style="font-size: 28px; font-weight: 700;"><?php echo esc_html( number_format( $sms_count ) ); ?></div>
            </div>
            <!-- Calls Made -->
            <div style="background: linear-gradient(135deg, #10b981, #059669); border-radius: 16px; padding: 24px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.2);">
                <div style="position: absolute; top: -10px; right: -10px; opacity: 0.1; font-size: 80px;">📞</div>
                <div style="font-size: 13px; font-weight: 500; opacity: 0.9; margin-bottom: 8px;">Total Calls Made</div>
                <div style="font-size: 28px; font-weight: 700;"><?php echo esc_html( number_format( $voice_count ) ); ?></div>
            </div>
            <!-- Emails Sent -->
            <div style="background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 16px; padding: 24px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(245, 158, 11, 0.2);">
                <div style="position: absolute; top: -10px; right: -10px; opacity: 0.1; font-size: 80px;">✉️</div>
                <div style="font-size: 13px; font-weight: 500; opacity: 0.9; margin-bottom: 8px;">Total Emails Sent</div>
                <div style="font-size: 28px; font-weight: 700;"><?php echo esc_html( number_format( $email_count ) ); ?></div>
            </div>
            <!-- Total Credit Used -->
            <div style="background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 16px; padding: 24px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.2);">
                <div style="position: absolute; top: -10px; right: -10px; opacity: 0.1; font-size: 80px;">₦</div>
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
                    <div class="ofp-empty-icon">💬</div>
                    <h3>No communications yet</h3>
                    <p>Messages sent to your leads will appear here.</p>
                </div>
            <?php else : ?>
                <div style="display: flex; flex-direction: column; gap: 16px; padding: 24px;">
                    <?php foreach ( $comms as $comm ) :
                        $is_success = $comm->status === 'sent';
                        $icon = '💬'; $bg = 'rgba(139, 92, 246, 0.1)'; $ic = '#8b5cf6';
                        if ( $comm->type === 'voice' ) {
                            $icon = '📞'; $bg = 'rgba(16, 185, 129, 0.1)'; $ic = '#10b981';
                        } elseif ( $comm->type === 'email' ) {
                            $icon = '✉️'; $bg = 'rgba(245, 158, 11, 0.1)'; $ic = '#f59e0b';
                        }
                    ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px; border-radius: 12px; background: var(--bg-lighter); border: 1px solid var(--border-color); transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='none';">

                            <!-- Left: Icon & Message Preview -->
                            <div style="display: flex; gap: 16px; align-items: center; flex: 1; min-width: 0;">
                                <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; background: <?php echo $bg; ?>; color: <?php echo $ic; ?>; flex-shrink: 0;">
                                    <?php echo $icon; ?>
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
    // TAB 3: EMAIL TEMPLATE (Universal Template — mirrors admin)
    // =====================================================================
    ?>
    <?php if ( $tab === 'templates' ) : ?>

        <?php if ( isset( $_GET['updated'] ) ) : ?>
            <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 8px; padding: 12px 16px; margin-bottom: 24px; color: #059669; font-size: 14px; font-weight: 500;">
                ✅ Email template saved successfully.
            </div>
        <?php endif; ?>

        <div class="ofp-card" style="padding: 32px;">
            <h3 style="margin-top: 0;">Universal Email Template</h3>
            <p class="ofp-hint" style="margin-bottom: 24px;">
                Define the HTML wrapper for all your broadcast and system emails. Use the variable <code>{email_body}</code> to indicate where the main message content should be injected. If left empty, the built-in default template will be used.
            </p>

            <div style="display: flex; gap: 24px; margin-top: 20px;">
                <!-- Left: Editor -->
                <div style="flex: 1; min-width: 400px;">
                    <form id="universal-template-form">
                        <div class="ofp-field">
                            <textarea id="universal_template_input" name="template_html" rows="25" style="width: 100%; font-family: monospace; font-size: 13px; line-height: 1.5; padding: 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-lighter); color: var(--text-main); resize: vertical;"><?php echo esc_textarea( get_option( 'ofp_client_email_template_' . $client->id, '' ) ); ?></textarea>
                        </div>

                        <div style="margin-top: 16px;">
                            <button type="submit" class="ofp-btn ofp-btn-primary" id="save-template-btn">Save Template</button>
                        </div>
                    </form>
                </div>

                <!-- Right: Live Preview -->
                <div style="flex: 1; min-width: 400px; display: flex; flex-direction: column;">
                    <h4 style="margin-top: 0; margin-bottom: 12px; color: var(--text-main);">Live Preview</h4>
                    <div style="flex-grow: 1; border: 1px solid var(--border-color); border-radius: 8px; background: #fff; min-height: 500px; overflow: hidden; display: flex; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                        <iframe id="template-preview-frame" style="width:100%; height:100%; border:none; flex-grow: 1; background: #fff;"></iframe>
                    </div>
                </div>
            </div>
        </div>

        <hr style="border:0; border-top:1px solid var(--border-color); margin: 32px 0;">

        <!-- Manage Message Templates (SMS/Email) -->
        <div style="display: flex; gap: 24px;">
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
                            <div class="ofp-template-list-item"
                                 onclick='editTemplate(<?php echo json_encode( $t, JSON_HEX_APOS | JSON_HEX_QUOT ); ?>)'
                                 style="padding: 12px 16px; border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; transition: all 0.2s; background: var(--bg-lighter);">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <strong><?php echo esc_html( $t->name ); ?></strong>
                                    <span class="ofp-badge <?php echo $t->type === 'sms' ? 'ofp-badge-blue' : 'ofp-badge-purple'; ?>">
                                        <?php echo esc_html( strtoupper( $t->type ) ); ?>
                                    </span>
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

            // ── Universal Email Template ────────────────────────────────────
            var uniInput = document.getElementById('universal_template_input');
            var uniIframe = document.getElementById('template-preview-frame');

            function updateUniversalPreview() {
                var template = uniInput.value;
                var previewHtml = '';

                if (!template.trim()) {
                    previewHtml = '<div style="padding:40px; text-align:center; color:#64748b; font-family:sans-serif;">Default built-in template will be used.</div>';
                } else {
                    previewHtml = template.replace(
                        '{email_body}',
                        '<div style="padding: 20px; border: 2px dashed #ccc; background: #fafafa; text-align: center; font-family: sans-serif;"><h3>Message Content Goes Here</h3><p>This is where the actual email body will be injected.</p></div>'
                    );
                }

                var doc = uniIframe.contentDocument || uniIframe.contentWindow.document;
                doc.open();
                doc.writeln(previewHtml);
                doc.close();
            }

            updateUniversalPreview();
            uniInput.addEventListener('input', updateUniversalPreview);

            // Save universal template via AJAX
            document.getElementById('universal-template-form').addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = document.getElementById('save-template-btn');
                btn.disabled = true;
                btn.textContent = 'Saving...';

                var formData = new FormData();
                formData.append('action', 'ofp_save_client_email_template');
                formData.append('nonce', ofpClientData.nonce);
                formData.append('template_html', uniInput.value);

                fetch(ofpClientData.ajaxurl, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            alert(res.data || 'Template saved successfully!');
                        } else {
                            alert(res.data || 'Failed to save template.');
                        }
                    })
                    .catch(function() { alert('Network error. Please try again.'); })
                    .finally(function() {
                        btn.disabled = false;
                        btn.textContent = 'Save Template';
                    });
            });

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

            window.deleteActiveTemplate = function() {
                if (!confirm('Are you sure you want to delete this template?')) return;
                var id = tplId.value;
                if (!id) return;

                var formData = new FormData();
                formData.append('action', 'ofp_delete_template');
                formData.append('nonce', ofpClientData.nonce);
                formData.append('template_id', id);

                fetch(ofpClientData.ajaxurl, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            window.location.reload();
                        } else {
                            alert(res.data || 'Failed to delete template.');
                        }
                    });
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
                formData.append('nonce', ofpClientData.nonce);

                fetch(ofpClientData.ajaxurl, { method: 'POST', body: formData })
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

</div>
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
