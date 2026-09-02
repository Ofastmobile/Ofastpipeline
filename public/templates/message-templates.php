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
        "SELECT * FROM {$p}ofp_client_templates WHERE client_id = %d AND type = 'email' ORDER BY is_default DESC, updated_at DESC",
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
    <title>Messaging — OFast Pipeline</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <div class="ofp-page-header">
        <h1>Messaging</h1>
        <p>Communications log, manual broadcasts, and email template management.</p>
    </div>

    <!-- Tabs -->
    <div class="ofp-tabs" style="margin-bottom: 24px; border-bottom: 1px solid var(--border-color);">
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=log' ) ); ?>" class="ofp-tab <?php echo $tab === 'log' ? 'active' : ''; ?>">Communications Log</a>
        <?php if ( OFP_Subscription::allows_email_templates( (int) $client->id ) ) : ?>
        <a href="<?php echo esc_url( home_url( '/message-templates?tab=send' ) ); ?>" class="ofp-tab <?php echo $tab === 'send' ? 'active' : ''; ?>">Send Message</a>
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

    <?php if ( $tab === 'send' ) : ?>
        <?php include OFP_PATH . 'public/templates/partials/comms-send.php'; ?>
    <?php endif; ?>

    <?php if ( $tab === 'templates' ) : ?>
        <?php include OFP_PATH . 'public/templates/partials/comms-templates.php'; ?>
    <?php endif; ?>

</div>
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
