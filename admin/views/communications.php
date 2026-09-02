<?php
/**
 * Admin View: Communications
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

global $wpdb;
$p = $wpdb->prefix;

$tab = sanitize_text_field( $_GET['tab'] ?? 'log' );
$allowed_tabs = [ 'log', 'send', 'templates' ];
if ( ! in_array( $tab, $allowed_tabs, true ) ) {
    $tab = 'log';
}

include OFP_PATH . 'admin/views/partials/header.php';
?>

<div class="wrap">
    <h2 class="nav-tab-wrapper">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-communications&tab=log' ) ); ?>" class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>">Communications Log</a>
        <?php if ( OFP_Auth::is_super_admin() ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-communications&tab=send' ) ); ?>" class="nav-tab <?php echo $tab === 'send' ? 'nav-tab-active' : ''; ?>">Send Broadcast</a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-communications&tab=templates' ) ); ?>" class="nav-tab <?php echo $tab === 'templates' ? 'nav-tab-active' : ''; ?>">Universal Email Template</a>
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
                <input type="hidden" name="page" value="ofp-communications">
                <input type="hidden" name="tab" value="log">
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
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-communications&tab=log' ) ); ?>"
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $comms as $comm ) : ?>
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
                                <td><?php echo esc_html(
                                    human_time_diff( strtotime( $comm->sent_at ), current_time( 'timestamp' ) ) . ' ago'
                                ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="ofp-pagination">
                        <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( [ 'paged' => $i, 'tab' => 'log' ] ) ); ?>"
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
        <?php $admin_wrapper = get_option( 'ofp_universal_email_template', '' ); ?>
        <div class="ofp-section" style="margin-top:20px;">
            <h3>Send Broadcast</h3>
            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:stretch;">
                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ofp-form" id="ofp-admin-send" style="flex:1;min-width:360px;">
                    <?php wp_nonce_field( 'ofp_send_broadcast' ); ?>
                    <input type="hidden" name="action" value="ofp_send_broadcast">

                    <div class="ofp-field ofp-field-full">
                        <label>Channel <span class="required">*</span></label>
                        <select name="channel" id="broadcast_channel" required>
                            <option value="email">Email</option>
                            <option value="sms">SMS</option>
                        </select>
                    </div>

                    <div class="ofp-field ofp-field-full">
                        <label>Recipients <span class="required">*</span></label>
                        <textarea name="recipients" id="broadcast_recipients" rows="3" required placeholder="Comma-separated list of emails or phone numbers"></textarea>
                    </div>

                    <div class="ofp-field ofp-field-full" id="broadcast_subject_field">
                        <label>Subject <span class="required">*</span></label>
                        <input type="text" name="subject" id="broadcast_subject" placeholder="Email subject line">
                    </div>

                    <div class="ofp-field ofp-field-full">
                        <label>Message Body <span class="required">*</span></label>
                        <textarea name="message" id="broadcast_message" rows="10" required></textarea>
                    </div>

                    <div class="ofp-form-actions">
                        <button type="submit" class="button button-primary ofp-btn-primary">Send Message</button>
                    </div>
                </form>
                <div style="flex:1;min-width:320px;">
                    <h4 style="margin-top:0;">Live preview</h4>
                    <iframe id="broadcast-preview" sandbox srcdoc="" style="width:100%;min-height:520px;border:1px solid #c3c4c7;background:#fff;"></iframe>
                </div>
            </div>
        </div>
        <script>
        (function(){
            var SAMPLE = <?php echo wp_json_encode( OFP_Comms::sample_body_html() ); ?>;
            var WRAP = <?php echo wp_json_encode( $admin_wrapper ); ?>;
            var channel = document.getElementById('broadcast_channel');
            var msg = document.getElementById('broadcast_message');
            var iframe = document.getElementById('broadcast-preview');
            function preview(){
                var inner = (msg.value || SAMPLE).replace(/\n/g,'<br>');
                var html;
                if (channel.value === 'sms') {
                    html = '<div style="min-height:100%;display:flex;align-items:center;justify-content:center;background:#e2e8f0;font-family:sans-serif;"><div style="width:280px;height:480px;background:#fff;border-radius:28px;border:8px solid #94a3b8;padding:48px 16px 16px;box-sizing:border-box;"><div style="background:#e2e8f0;border-radius:16px;padding:12px 14px;">'+inner+'</div></div></div>';
                } else if (WRAP && (WRAP.indexOf('{{content}}') !== -1 || WRAP.indexOf('{email_body}') !== -1)) {
                    html = WRAP.replace('{{content}}', inner).replace('{email_body}', inner);
                } else {
                    html = '<div style="font-family:sans-serif;padding:24px;background:#f1f5f9;"><div style="max-width:600px;margin:0 auto;background:#fff;padding:24px;border-radius:12px;">'+inner+'</div></div>';
                }
                iframe.setAttribute('sandbox','');
                iframe.srcdoc = html;
            }
            channel.addEventListener('change', function(){
                var email = this.value === 'email';
                document.getElementById('broadcast_subject_field').style.display = email ? 'block' : 'none';
                preview();
            });
            msg.addEventListener('input', preview);
            preview();
        })();
        </script>
    <?php endif; ?>

    <?php if ( $tab === 'templates' && OFP_Auth::is_super_admin() ) : ?>
        <?php if ( isset( $_GET['updated'] ) ) : ?>
            <div class="notice notice-success is-dismissible" style="margin-top:20px;"><p>Universal email template saved.</p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['tested'] ) ) : ?>
            <div class="notice <?php echo (int) $_GET['tested'] ? 'notice-success' : 'notice-error'; ?> is-dismissible" style="margin-top:20px;">
                <p><?php echo (int) $_GET['tested'] ? 'Test email sent to your WordPress admin email.' : 'Test email failed. Check SMTP settings.'; ?></p>
            </div>
        <?php endif; ?>
        <?php $saved_tpl = str_replace( '{email_body}', '{{content}}', get_option( 'ofp_universal_email_template', '' ) ); ?>
        <div class="ofp-section" style="margin-top:20px;">
            <h3>Universal Admin Email Template</h3>
            <p class="ofp-hint" style="margin-bottom:20px;">
                Wrapper for system emails to clients (welcome, billing) and the fallback for client outgoing mail.
                Use <code>{{content}}</code> where the message body should go. If left empty, the built-in platform template is used.
            </p>
            <div style="display:flex;gap:24px;flex-wrap:wrap;">
                <div style="flex:1;min-width:360px;">
                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'ofp_save_universal_template' ); ?>
                        <input type="hidden" name="action" value="ofp_save_universal_template">
                        <textarea id="universal_template_input" name="ofp_universal_email_template" rows="22" style="width:100%;font-family:monospace;font-size:13px;"><?php echo esc_textarea( $saved_tpl ); ?></textarea>
                        <p class="ofp-form-actions" style="margin-top:12px;">
                            <button type="submit" class="button button-primary">Save Template</button>
                        </p>
                    </form>
                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <?php wp_nonce_field( 'ofp_send_test_email' ); ?>
                        <input type="hidden" name="action" value="ofp_send_test_email">
                        <button type="submit" class="button">Send test email</button>
                    </form>
                </div>
                <div style="flex:1;min-width:320px;">
                    <h4 style="margin-top:0;">Live preview</h4>
                    <iframe id="template-preview-frame" sandbox srcdoc="" style="width:100%;min-height:520px;border:1px solid #c3c4c7;background:#fff;"></iframe>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var input = document.getElementById('universal_template_input');
            var iframe = document.getElementById('template-preview-frame');
            var SAMPLE = <?php echo wp_json_encode( OFP_Comms::sample_body_html() ); ?>;
            function updatePreview() {
                var template = input.value;
                var previewHtml;
                if (!template.trim()) {
                    previewHtml = '<div style="padding:40px;text-align:center;color:#64748b;font-family:sans-serif;">Default built-in template will be used.</div>';
                } else {
                    previewHtml = template.replaceAll('{{content}}', SAMPLE).replaceAll('{email_body}', SAMPLE);
                }
                iframe.setAttribute('sandbox', '');
                iframe.srcdoc = previewHtml;
            }
            updatePreview();
            input.addEventListener('input', updatePreview);
        });
        </script>
    <?php endif; ?>

</div>

<?php include OFP_PATH . 'admin/views/partials/footer.php'; ?>
