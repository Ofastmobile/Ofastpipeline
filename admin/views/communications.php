<?php
/**
 * Admin View: Communications
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

global $wpdb;
$p = $wpdb->prefix;

$is_portal = ( isset( $_GET['tab'] ) && $_GET['tab'] === 'communications' )
    || isset( $_GET['subtab'] )
    || ( strpos( $_SERVER['REQUEST_URI'] ?? '', 'admin-portal' ) !== false );

$subtab_input = sanitize_text_field( $_GET['subtab'] ?? $_GET['comm_tab'] ?? '' );
if ( empty( $subtab_input ) && isset( $_GET['tab'] ) && in_array( $_GET['tab'], [ 'log', 'send', 'templates' ], true ) ) {
    $subtab_input = sanitize_text_field( $_GET['tab'] );
}
$tab = in_array( $subtab_input, [ 'log', 'send', 'templates' ], true ) ? $subtab_input : 'log';

$log_url = $is_portal 
    ? home_url( '/admin-portal?tab=communications&subtab=log' ) 
    : admin_url( 'admin.php?page=ofp-communications&tab=log' );

$send_url = $is_portal 
    ? home_url( '/admin-portal?tab=communications&subtab=send' ) 
    : admin_url( 'admin.php?page=ofp-communications&tab=send' );

$templates_url = $is_portal 
    ? home_url( '/admin-portal?tab=communications&subtab=templates' ) 
    : admin_url( 'admin.php?page=ofp-communications&tab=templates' );

include OFP_PATH . 'admin/views/partials/header.php';
?>

<div class="wrap">
    <h2 class="nav-tab-wrapper">
        <a href="<?php echo esc_url( $log_url ); ?>" class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>">Communications Log</a>
        <?php if ( OFP_Auth::is_super_admin() ) : ?>
        <a href="<?php echo esc_url( $send_url ); ?>" class="nav-tab <?php echo $tab === 'send' ? 'nav-tab-active' : ''; ?>">Send Broadcast</a>
        <a href="<?php echo esc_url( $templates_url ); ?>" class="nav-tab <?php echo $tab === 'templates' ? 'nav-tab-active' : ''; ?>">Universal Email Template</a>
        <?php endif; ?>
    </h2>

    <?php if ( $tab === 'log' ) : ?>
        <?php
        $filter_client = (int) ( $_GET['client_id'] ?? 0 );
        $filter_type   = sanitize_text_field( $_GET['type'] ?? '' );
        $per_page      = 50;
        $current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset        = ( $current_page - 1 ) * $per_page;

        $where = [ '1=1' ];
        $args  = [];

        if ( $filter_client ) {
            $where[] = 'cl.client_id = %d';
            $args[]  = $filter_client;
        }
        if ( $filter_type ) {
            $where[] = 'cl.type = %s';
            $args[]  = $filter_type;
        }

        $where_sql = implode( ' AND ', $where );

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}ofp_communications_log cl WHERE {$where_sql}",
                ...$args
            )
        );

        $comms = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cl.*, c.business_name, l.phone as lead_phone, l.name as lead_name
                 FROM {$p}ofp_communications_log cl
                 JOIN {$p}ofp_clients c ON c.id = cl.client_id
                 LEFT JOIN {$p}ofp_leads l ON l.id = cl.lead_id
                 WHERE {$where_sql}
                 ORDER BY cl.sent_at DESC
                 LIMIT %d OFFSET %d",
                ...array_merge( $args, [ $per_page, $offset ] )
            )
        );

        // Summary stats
        $total_sms   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE type = 'sms'" );
        $total_voice = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE type = 'voice'" );
        $total_email = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE type = 'email'" );
        $total_cost  = (float) $wpdb->get_var( "SELECT SUM(cost) FROM {$p}ofp_communications_log" );

        $clients     = OFP_Client::all();
        $total_pages = ceil( $total / $per_page );

        $type_badges = [
            'sms'   => '<span class="ofp-badge ofp-badge-blue">SMS</span>',
            'voice' => '<span class="ofp-badge ofp-badge-green">Voice</span>',
            'email' => '<span class="ofp-badge ofp-badge-yellow">Email</span>',
        ];
        ?>

        <div class="ofp-stats-grid" style="margin-top:20px;">
            <div class="ofp-stat-card">
                <span class="ofp-stat-number"><?php echo esc_html( number_format( $total_sms ) ); ?></span>
                <span class="ofp-stat-label">Total SMS</span>
            </div>
            <div class="ofp-stat-card">
                <span class="ofp-stat-number"><?php echo esc_html( number_format( $total_voice ) ); ?></span>
                <span class="ofp-stat-label">Total Calls</span>
            </div>
            <div class="ofp-stat-card">
                <span class="ofp-stat-number"><?php echo esc_html( number_format( $total_email ) ); ?></span>
                <span class="ofp-stat-label">Total Emails</span>
            </div>
            <div class="ofp-stat-card">
                <span class="ofp-stat-number">₦<?php echo esc_html( number_format( $total_cost, 2 ) ); ?></span>
                <span class="ofp-stat-label">Total Credit Used</span>
            </div>
        </div>

        <!-- Filters -->
        <div class="ofp-filters">
            <form method="GET" action="" class="ofp-filter-form">
                <?php if ( $is_portal ) : ?>
                    <input type="hidden" name="tab" value="communications">
                    <input type="hidden" name="subtab" value="log">
                <?php else : ?>
                    <input type="hidden" name="page" value="ofp-communications">
                    <input type="hidden" name="tab" value="log">
                <?php endif; ?>
                <select name="client_id" onchange="this.form.submit()">
                    <option value="">All Clients</option>
                    <?php foreach ( $clients as $c ) : ?>
                        <option value="<?php echo esc_attr( $c->id ); ?>"
                            <?php selected( $filter_client, $c->id ); ?>>
                            <?php echo esc_html( $c->business_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="type" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <option value="sms"   <?php selected( $filter_type, 'sms' ); ?>>SMS</option>
                    <option value="voice" <?php selected( $filter_type, 'voice' ); ?>>Voice</option>
                    <option value="email" <?php selected( $filter_type, 'email' ); ?>>Email</option>
                </select>
                <?php if ( $filter_client || $filter_type ) : ?>
                    <?php
                    $clear_url = $is_portal
                        ? home_url( '/admin-portal?tab=communications&subtab=log' )
                        : admin_url( 'admin.php?page=ofp-communications&tab=log' );
                    ?>
                    <a href="<?php echo esc_url( $clear_url ); ?>"
                       class="button">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="ofp-section">
            <?php if ( empty( $comms ) ) : ?>
                <p>No communications logged yet.</p>
            <?php else : ?>
                <table class="widefat ofp-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Client</th>
                            <th>Lead/Recipient</th>
                            <th>Status</th>
                            <th>Cost (NGN)</th>
                            <th>Sent</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $comms as $comm ) : ?>
                            <?php
                            $comm_recipient = $comm->lead_name ?: ( $comm->lead_phone ?: 'Recipient' );
                            $comm_dt  = DateTime::createFromFormat( 'Y-m-d H:i:s', $comm->sent_at, wp_timezone() );
                            $comm_ts  = $comm_dt ? $comm_dt->getTimestamp() : strtotime( $comm->sent_at );
                            $sent_str = wp_date( 'M j, Y g:i A', $comm_ts );
                            $rel_str  = human_time_diff( $comm_ts, time() ) . ' ago';

                            $comm_payload = [
                                'id'         => (int) $comm->id,
                                'type'       => $comm->type,
                                'status'     => $comm->status,
                                'recipient'  => $comm_recipient,
                                'client'     => $comm->business_name ?? 'Super Admin',
                                'sent_at'    => $sent_str,
                                'time_rel'   => $rel_str,
                                'message'    => $comm->message ?? '',
                            ];
                            ?>
                            <tr>
                                <td><?php echo $type_badges[ $comm->type ] ?? esc_html( strtoupper( $comm->type ) ); ?></td>
                                <td><?php echo esc_html( $comm->business_name ?? 'Super Admin' ); ?></td>
                                <td>
                                    <?php echo esc_html( $comm->lead_name ?: $comm->lead_phone ); ?><br>
                                    <small><?php echo esc_html( $comm->lead_phone ); ?></small>
                                </td>
                                <td>
                                    <?php
                                    $status_class = $comm->status === 'sent' ? 'ofp-badge-green' : 'ofp-badge-red';
                                    echo '<span class="ofp-badge ' . esc_attr( $status_class ) . '">'
                                        . esc_html( $comm->status ) . '</span>';
                                    ?>
                                </td>
                                <td><?php echo esc_html( number_format( (float) $comm->cost, 2 ) ); ?></td>
                                <td><?php echo esc_html( $rel_str ); ?></td>
                                <td>
                                    <?php if ( $comm->type === 'email' ) : ?>
                                        <button type="button" class="button button-small" onclick="ofpOpenCommEmailDrawer(<?php echo (int) $comm->id; ?>, <?php echo esc_attr( wp_json_encode( $comm_payload ) ); ?>)">
                                            View
                                        </button>
                                    <?php else : ?>
                                        <span style="color:#94a3b8; font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- ── SLIDE-OVER EMAIL DRAWER WITH TEMPLATE PREVIEW & RESEND ───────── -->
                <div class="ofp-email-drawer-overlay" id="ofpCommDrawerOverlay" onclick="ofpCloseCommDrawer()"></div>
                <div class="ofp-email-drawer" id="ofpCommDrawer" role="dialog" aria-modal="true" aria-labelledby="ofpCommDrawerTitle">
                    <div class="ofp-email-drawer-header">
                        <div class="ofp-email-drawer-header-left">
                            <svg width="20" height="20" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <h3 class="ofp-email-drawer-title" id="ofpCommDrawerTitle">Email Delivery & Template</h3>
                            <span id="ofpCommStatusBadge" class="ofp-badge ofp-badge-green" style="font-size:11px;">Sent via SMTP</span>
                        </div>
                        <button type="button" class="ofp-email-drawer-close" onclick="ofpCloseCommDrawer()" aria-label="Close drawer">&times;</button>
                    </div>

                    <!-- Meta Details Bar -->
                    <div class="ofp-email-drawer-meta">
                        <div class="ofp-email-drawer-meta-row">
                            <span class="ofp-email-drawer-meta-label">To:</span>
                            <span class="ofp-email-drawer-meta-val" id="ofpCommDrawerTo"></span>
                        </div>
                        <div class="ofp-email-drawer-meta-row">
                            <span class="ofp-email-drawer-meta-label">Subject:</span>
                            <span class="ofp-email-drawer-meta-val" id="ofpCommDrawerSubject" style="font-weight:600;"></span>
                        </div>
                        <div class="ofp-email-drawer-meta-row">
                            <span class="ofp-email-drawer-meta-label">Date:</span>
                            <span class="ofp-email-drawer-meta-val" id="ofpCommDrawerDate"></span>
                        </div>
                        <div class="ofp-email-drawer-meta-row">
                            <span class="ofp-email-drawer-meta-label">Sender:</span>
                            <span class="ofp-email-drawer-meta-val" id="ofpCommDrawerActor"></span>
                        </div>
                    </div>

                    <!-- Email Template Preview Body -->
                    <div class="ofp-email-drawer-body">
                        <div class="ofp-email-iframe-wrap">
                            <iframe id="ofpCommEmailIframe" class="ofp-email-iframe" sandbox="allow-same-origin" title="Email Template Preview"></iframe>
                        </div>
                    </div>

                    <!-- Footer with Resend Action -->
                    <div class="ofp-email-drawer-footer">
                        <div class="ofp-email-resend-group">
                            <button type="button" class="ofp-act-btn-resend" id="ofpCommBtnResend" onclick="ofpTriggerCommResend()">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                <span id="ofpCommBtnResendText">Resend Email</span>
                            </button>
                            <span id="ofpCommResendStatus" class="ofp-resend-status"></span>
                        </div>
                        <button type="button" class="ofp-act-btn-dismiss" onclick="ofpCloseCommDrawer()">Close</button>
                    </div>
                </div>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="ofp-pagination">
                        <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                            <?php
                            $page_url = $is_portal
                                ? home_url( '/admin-portal?tab=communications&subtab=log&paged=' . $i . ( $filter_client ? '&client_id=' . $filter_client : '' ) . ( $filter_type ? '&type=' . urlencode( $filter_type ) : '' ) )
                                : add_query_arg( [ 'paged' => $i, 'tab' => 'log' ] );
                            ?>
                            <a href="<?php echo esc_url( $page_url ); ?>"
                               class="button button-small <?php echo $i === $current_page ? 'button-primary' : ''; ?>">
                                <?php echo esc_html( $i ); ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( $tab === 'send' && OFP_Auth::is_super_admin() ) : ?>
        <?php if ( isset( $_GET['sent'] ) ) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top:20px;">
                <p><?php echo esc_html( (int) $_GET['sent'] ); ?> message(s) sent successfully.
                <?php if ( isset( $_GET['failed'] ) && (int) $_GET['failed'] > 0 ) : ?>
                    <?php echo esc_html( (int) $_GET['failed'] ); ?> failed.
                <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
        <div class="ofp-section" style="margin-top:20px;">
            <h3>Send Broadcast Message</h3>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ofp-form">
                <?php wp_nonce_field( 'ofp_send_broadcast' ); ?>
                <input type="hidden" name="action" value="ofp_send_broadcast">
                
                <div class="ofp-form-grid">
                    <div class="ofp-field ofp-field-full">
                        <label>Channel <span class="required">*</span></label>
                        <select name="channel" id="broadcast_channel" required>
                            <option value="email">Email</option>
                            <option value="sms">SMS</option>
                        </select>
                    </div>

                    <div class="ofp-field ofp-field-full">
                        <label>Recipients <span class="required">*</span></label>
                        <textarea name="recipients" rows="3" required placeholder="Comma-separated list of emails or phone numbers"></textarea>
                        <p class="ofp-hint">Enter email addresses (for Email) or phone numbers in international format (e.g. 234... for SMS), separated by commas.</p>
                    </div>

                    <div class="ofp-field ofp-field-full" id="broadcast_subject_field">
                        <label>Subject <span class="required">*</span></label>
                        <input type="text" name="subject" id="broadcast_subject" placeholder="Email subject line">
                    </div>

                    <div class="ofp-field ofp-field-full">
                        <label>Message Body <span class="required">*</span></label>
                        <?php 
                        wp_editor( '', 'broadcast_message', [
                            'textarea_name' => 'message',
                            'media_buttons' => false,
                            'textarea_rows' => 10,
                            'tinymce'       => true,
                            'quicktags'     => true,
                        ] ); 
                        ?>
                    </div>
                </div>

                <div class="ofp-form-actions">
                    <button type="submit" class="button button-primary ofp-btn-primary">Send Message</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ( $tab === 'templates' && OFP_Auth::is_super_admin() ) : ?>
        <?php if ( isset( $_GET['updated'] ) ) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top:20px;">
                <p>Universal email template saved successfully.</p>
            </div>
        <?php endif; ?>
        <div class="ofp-section" style="margin-top:20px;">
            <h3>Universal Admin Email Template</h3>
            <p class="ofp-hint" style="margin-bottom:20px;">
                Define the HTML wrapper for all system and broadcast emails. Use the variable <code>{email_body}</code> to indicate where the main message content should be injected. If left empty, the built-in default template will be used.
            </p>
            
            <div style="display: flex; gap: 24px; margin-top: 20px;">
                <!-- Left: Editor -->
                <div style="flex: 1; min-width: 400px;">
                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ofp-form">
                        <?php wp_nonce_field( 'ofp_save_universal_template' ); ?>
                        <input type="hidden" name="action" value="ofp_save_universal_template">
                        
                        <div class="ofp-field ofp-field-full">
                            <textarea id="universal_template_input" name="ofp_universal_email_template" rows="25" style="width: 100%; font-family: monospace; font-size: 13px; line-height: 1.5; padding: 12px;"><?php echo esc_textarea( get_option( 'ofp_universal_email_template', '' ) ); ?></textarea>
                        </div>

                        <div class="ofp-form-actions" style="margin-top:15px;">
                            <button type="submit" class="button button-primary ofp-btn-primary">Save Template</button>
                        </div>
                    </form>
                </div>

                <!-- Right: Live Preview -->
                <div style="flex: 1; min-width: 400px; display: flex; flex-direction: column;">
                    <h4 style="margin-top:0; margin-bottom: 12px;">Live Preview</h4>
                    <div style="flex-grow: 1; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff; min-height: 500px; overflow: hidden; display: flex;">
                        <iframe id="template-preview-frame" style="width:100%; height:100%; border:none; flex-grow: 1;"></iframe>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include OFP_PATH . 'admin/views/partials/footer.php'; ?>
