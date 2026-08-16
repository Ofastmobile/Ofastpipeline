<?php
/**
 * Template: /communications
 * Client's full communication log - every SMS, voice call, and email sent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

if ( ! OFP_Subscription::has_active( 'crm', $client->id ) ) {
    wp_safe_redirect( home_url( '/dashboard' ) );
    exit;
}

global $wpdb;
$p = $wpdb->prefix;

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
         JOIN {$p}ofp_leads l ON l.id = cl.lead_id
         WHERE {$where}
         ORDER BY cl.sent_at DESC
         LIMIT %d OFFSET %d",
        ...array_merge( $args, [ $per_page, $offset ] )
    )
);

$total_pages = ceil( $total / $per_page );

// Summary counts
$sms_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE client_id = %d AND type = 'sms'",   $client->id ) );
$voice_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE client_id = %d AND type = 'voice'", $client->id ) );
$total_cost  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(cost) FROM {$p}ofp_communications_log WHERE client_id = %d", $client->id ) );

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
        <p>Every message sent to your leads through the pipeline.</p>
    </div>

    <!-- Hero Summary Cards -->
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-bottom: 32px;">
        <!-- SMS Sent (Bold Purple) -->
        <div style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); border-radius: 16px; padding: 24px; color: #fff; display: flex; flex-direction: column; justify-content: space-between; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(124, 58, 237, 0.2);">
            <div style="position: absolute; top: -10px; right: -10px; opacity: 0.1; font-size: 100px;">💬</div>
            <div style="font-size: 14px; font-weight: 500; opacity: 0.9; margin-bottom: 8px;">Total SMS Sent</div>
            <div style="font-size: 32px; font-weight: 700;"><?php echo esc_html( number_format( $sms_count ) ); ?></div>
        </div>

        <!-- Calls Made (Bold Green) -->
        <div style="background: linear-gradient(135deg, #10b981, #059669); border-radius: 16px; padding: 24px; color: #fff; display: flex; flex-direction: column; justify-content: space-between; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.2);">
            <div style="position: absolute; top: -10px; right: -10px; opacity: 0.1; font-size: 100px;">📞</div>
            <div style="font-size: 14px; font-weight: 500; opacity: 0.9; margin-bottom: 8px;">Total Calls Made</div>
            <div style="font-size: 32px; font-weight: 700;"><?php echo esc_html( number_format( $voice_count ) ); ?></div>
        </div>

        <!-- Total Credit Used (Bold Blue) -->
        <div style="background: linear-gradient(135deg, #3b82f6, #2563eb); border-radius: 16px; padding: 24px; color: #fff; display: flex; flex-direction: column; justify-content: space-between; position: relative; overflow: hidden; box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.2);">
            <div style="position: absolute; top: -10px; right: -10px; opacity: 0.1; font-size: 100px;">₦</div>
            <div style="font-size: 14px; font-weight: 500; opacity: 0.9; margin-bottom: 8px;">Total Credit Used</div>
            <div style="font-size: 32px; font-weight: 700;">₦<?php echo esc_html( number_format( $total_cost, 0 ) ); ?></div>
        </div>
    </div>

    <!-- Filter Pills -->
    <div style="display: flex; gap: 12px; margin-bottom: 24px;">
        <?php foreach ( [ '' => 'All', 'sms' => 'SMS', 'voice' => 'Voice', 'email' => 'Email' ] as $val => $label ) : 
            $active = $filter_type === $val;
            $bg = $active ? 'var(--accent-blue)' : 'var(--bg-lighter)';
            $color = $active ? '#fff' : 'var(--text-muted)';
        ?>
            <a href="<?php echo esc_url( add_query_arg( 'type', $val, home_url( '/communications' ) ) ); ?>"
               style="padding: 8px 16px; font-size: 13px; font-weight: 600; text-decoration: none; border-radius: 20px; background: <?php echo $bg; ?>; color: <?php echo $color; ?>; transition: all 0.2s;">
                <?php echo esc_html( $label ); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="ofp-card" style="padding:0;overflow:hidden;">
        <?php if ( empty( $comms ) ) : ?>
            <div class="ofp-empty" style="padding:48px;">
                <div class="ofp-empty-icon">💬</div>
                <h3>No communications yet</h3>
                <p>Messages sent to your leads will appear here.</p>
            </div>
        <?php else : ?>
            <!-- Activity Feed List -->
            <div style="display: flex; flex-direction: column; gap: 16px; padding: 24px;">
                <?php foreach ( $comms as $comm ) : 
                    $is_success = $comm->status === 'sent';
                    
                    // Determine Icon & Color based on type
                    $icon = '💬'; $bg = 'rgba(139, 92, 246, 0.1)'; $color = '#8b5cf6';
                    if ($comm->type === 'voice') {
                        $icon = '📞'; $bg = 'rgba(16, 185, 129, 0.1)'; $color = '#10b981';
                    } elseif ($comm->type === 'email') {
                        $icon = '✉️'; $bg = 'rgba(245, 158, 11, 0.1)'; $color = '#f59e0b';
                    }
                ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px; border-radius: 12px; background: var(--bg-lighter); border: 1px solid var(--border-color); transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='none';">
                        
                        <!-- Left: Icon & Message Preview -->
                        <div style="display: flex; gap: 16px; align-items: center; flex: 1; min-width: 0;">
                            <!-- Icon Box -->
                            <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; background: <?php echo $bg; ?>; color: <?php echo $color; ?>; flex-shrink: 0;">
                                <?php echo $icon; ?>
                            </div>
                            
                            <!-- Text Stack -->
                            <div style="min-width: 0;">
                                <div style="font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
                                    <?php echo esc_html( $comm->lead_name ?: $comm->lead_phone ); ?>
                                    <?php if (!$is_success): ?>
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
                        <a href="<?php echo esc_url( add_query_arg( [ 'paged' => $i, 'type' => $filter_type ], home_url( '/communications' ) ) ); ?>"
                           class="ofp-page-btn <?php echo $i === $current_page ? 'active' : ''; ?>">
                            <?php echo esc_html( $i ); ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
