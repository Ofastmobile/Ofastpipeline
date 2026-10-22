<?php
/**
 * Template: /activity-logs
 * Dedicated Client Portal Activity & Audit Log.
 * Real-time event log with SMTP Email Slide-Over Viewer & Resend capability.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

global $wpdb;

$paged  = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$limit  = 25;
$offset = ( $paged - 1 ) * $limit;

$actor_filter = isset( $_GET['actor'] ) ? sanitize_key( $_GET['actor'] ) : '';
$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

$where_clauses = [ "l.client_id = %d" ];
$args          = [ (int) $client->id ];

if ( $actor_filter === 'email' ) {
    $where_clauses[] = "(l.action LIKE %s OR l.action LIKE %s OR l.details LIKE %s)";
    $args[] = '%email%';
    $args[] = '%broadcast%';
    $args[] = '%"to":%';
} elseif ( $actor_filter === 'team' ) {
    $where_clauses[] = "(l.team_member_id IS NOT NULL OR l.details LIKE %s)";
    $args[] = '%"actor_type":"team_member"%';
} elseif ( $actor_filter === 'client' ) {
    $where_clauses[] = "(l.admin_id IS NULL AND (l.team_member_id IS NULL OR l.team_member_id = 0) AND (l.details LIKE %s OR (l.details NOT LIKE %s AND l.details NOT LIKE %s AND l.details NOT LIKE %s)))";
    $args[] = '%"actor_type":"client"%';
    $args[] = '%"actor_type":"team_member"%';
    $args[] = '%"actor_type":"system"%';
    $args[] = '%"actor_type":"admin"%';
} elseif ( $actor_filter === 'system' ) {
    $where_clauses[] = "(l.details LIKE %s OR l.details LIKE %s)";
    $args[] = '%"actor_type":"system"%';
    $args[] = '%"actor_type":"cron"%';
} elseif ( $actor_filter === 'admin' ) {
    $where_clauses[] = "(l.admin_id IS NOT NULL OR l.details LIKE %s)";
    $args[] = '%"actor_type":"admin"%';
}

if ( $search !== '' ) {
    $search_like = '%' . $wpdb->esc_like( $search ) . '%';
    $where_clauses[] = "(l.action LIKE %s OR l.details LIKE %s)";
    $args[] = $search_like;
    $args[] = $search_like;
}

$where = 'WHERE ' . implode( ' AND ', $where_clauses );

// Total count
$total_logs = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(l.id) FROM {$wpdb->prefix}ofp_activity_logs l {$where}",
    $args
) );

$total_pages = ceil( max( 1, $total_logs ) / $limit );

$query_args = array_merge( $args, [ $limit, $offset ] );
$logs = $wpdb->get_results( $wpdb->prepare(
    "SELECT l.*, a.name AS admin_name
     FROM {$wpdb->prefix}ofp_activity_logs l
     LEFT JOIN {$wpdb->prefix}ofp_admins a ON l.admin_id = a.id
     {$where}
     ORDER BY l.created_at DESC
     LIMIT %d OFFSET %d",
    $query_args
) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Activity Log', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    </head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container ofp-act-container">

    <!-- Top Toolbar: Search, Filter Dropdown, and Event Count (H1 and subtitle removed as requested) -->
    <div class="ofp-act-toolbar" style="margin-bottom: 20px; align-items: center; justify-content: space-between; gap: 14px;">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; position: relative; z-index: 1000;">
            <!-- 1. Search Box First -->
            <form method="get" action="<?php echo esc_url( home_url( '/activity-logs' ) ); ?>" class="ofp-act-search-form">
                <?php if ( $actor_filter ) : ?>
                    <input type="hidden" name="actor" value="<?php echo esc_attr( $actor_filter ); ?>">
                <?php endif; ?>
                <div class="ofp-act-search-box">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search events..." class="ofp-act-search-input">
                    <?php if ( $search ) : ?>
                        <a href="<?php echo esc_url( remove_query_arg( [ 's', 'paged' ] ) ); ?>" class="ofp-act-search-clear" title="Clear">&times;</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- 2. Filter Dropdown (Placed AFTER Search, with high z-index and inheriting CSS style) -->
            <?php
            $filter_labels = [
                ''       => 'All Events',
                'email'  => 'Emails / SMTP',
                'team'   => 'Team Members',
                'client' => 'My Actions',
                'system' => 'Automated / System',
                'admin'  => 'Support / Admin',
            ];
            $active_label = $filter_labels[ $actor_filter ] ?? 'All Events';
            ?>
            <div class="ofp-act-dropdown-wrap" id="ofpActDropdownWrap">
                <button type="button" class="ofp-act-dropdown-btn" id="ofpActFilterTrigger" onclick="ofpToggleActFilterDropdown()">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    <span>Filter: <strong><?php echo esc_html( $active_label ); ?></strong></span>
                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div class="ofp-act-dropdown-menu" id="ofpActFilterMenu">
                    <a href="<?php echo esc_url( remove_query_arg( [ 'actor', 'paged' ] ) ); ?>" class="ofp-act-dropdown-item <?php echo $actor_filter === '' ? 'active' : ''; ?>">
                        All Events
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( 'actor', 'email', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-act-dropdown-item <?php echo $actor_filter === 'email' ? 'active' : ''; ?>">
                        <span class="dot" style="background:#06b6d4; width:7px; height:7px; border-radius:50%;"></span>
                        Emails / SMTP
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( 'actor', 'team', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-act-dropdown-item <?php echo $actor_filter === 'team' ? 'active' : ''; ?>">
                        <span class="dot" style="background:#f59e0b; width:7px; height:7px; border-radius:50%;"></span>
                        Team Members
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( 'actor', 'client', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-act-dropdown-item <?php echo $actor_filter === 'client' ? 'active' : ''; ?>">
                        <span class="dot" style="background:#2563eb; width:7px; height:7px; border-radius:50%;"></span>
                        My Actions
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( 'actor', 'system', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-act-dropdown-item <?php echo $actor_filter === 'system' ? 'active' : ''; ?>">
                        <span class="dot" style="background:#64748b; width:7px; height:7px; border-radius:50%;"></span>
                        Automated / System
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( 'actor', 'admin', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-act-dropdown-item <?php echo $actor_filter === 'admin' ? 'active' : ''; ?>">
                        <span class="dot" style="background:#7c3aed; width:7px; height:7px; border-radius:50%;"></span>
                        Support / Admin
                    </a>
                </div>
            </div>
        </div>

        <!-- Event Count Pill -->
        <div class="ofp-act-count-pill">
            <span class="ofp-act-pulse"></span>
            <span><?php echo number_format( $total_logs ); ?> Recorded Events</span>
        </div>
    </div>

    <!-- Table Card Container -->
    <div class="ofp-act-card">
        <div class="ofp-act-table-wrap">
            <table class="ofp-act-table">
                <thead>
                    <tr>
                        <th class="col-date">Date & Time</th>
                        <th class="col-action">Action / Event</th>
                        <th class="col-actor">Initiated By</th>
                        <th class="col-details">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr>
                            <td colspan="4">
                                <div class="ofp-act-empty">
                                    <svg fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    <h4>No activity recorded</h4>
                                    <p><?php echo $search ? 'No events matching your search criteria.' : 'Events will appear here as your team and pipeline perform actions.'; ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $log ) :
                            $details = $log->details ? json_decode( $log->details, true ) : [];
                            if ( ! is_array( $details ) ) {
                                $details = [];
                            }

                            // Determine Actor
                            $raw_actor_type = $details['actor_type'] ?? null;
                            if ( ! $raw_actor_type ) {
                                if ( ! empty( $log->team_member_id ) ) {
                                    $raw_actor_type = 'team_member';
                                } elseif ( ! empty( $log->admin_id ) ) {
                                    $raw_actor_type = 'admin';
                                } else {
                                    $raw_actor_type = 'client';
                                }
                            }

                            $actor_name = $details['actor_name'] ?? null;

                            switch ( $raw_actor_type ) {
                                case 'team_member':
                                    $actor_label = $actor_name ? $actor_name . ' (Team)' : 'Team Member';
                                    $actor_color = '#f59e0b';
                                    $actor_bg    = 'rgba(245, 158, 11, 0.1)';
                                    $actor_svg   = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>';
                                    break;
                                case 'client':
                                    $actor_label = 'You (' . ( $client->owner_name ?: 'Account Owner' ) . ')';
                                    $actor_color = '#2563eb';
                                    $actor_bg    = 'rgba(37, 99, 235, 0.1)';
                                    $actor_svg   = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>';
                                    break;
                                case 'admin':
                                    $actor_label = 'Platform Support' . ( $log->admin_name ? ' (' . $log->admin_name . ')' : '' );
                                    $actor_color = '#7c3aed';
                                    $actor_bg    = 'rgba(124, 58, 237, 0.1)';
                                    $actor_svg   = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>';
                                    break;
                                default:
                                    $actor_label = 'System / Automated';
                                    $actor_color = '#64748b';
                                    $actor_bg    = 'rgba(100, 116, 139, 0.1)';
                                    $actor_svg   = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/>';
                            }

                            // Action pill color
                            $act_lower = strtolower( $log->action );
                            if ( strpos( $act_lower, 'paid' ) !== false || strpos( $act_lower, 'payment' ) !== false || strpos( $act_lower, 'approved' ) !== false || strpos( $act_lower, 'topped up' ) !== false || strpos( $act_lower, 'added' ) !== false ) {
                                $act_class = 'ofp-act-pill-success';
                            } elseif ( strpos( $act_lower, 'fail' ) !== false || strpos( $act_lower, 'reject' ) !== false || strpos( $act_lower, 'expired' ) !== false || strpos( $act_lower, 'delete' ) !== false || strpos( $act_lower, 'removed' ) !== false ) {
                                $act_class = 'ofp-act-pill-danger';
                            } elseif ( strpos( $act_lower, 'sent' ) !== false || strpos( $act_lower, 'email' ) !== false || strpos( $act_lower, 'sms' ) !== false || strpos( $act_lower, 'lead' ) !== false ) {
                                $act_class = 'ofp-act-pill-info';
                            } elseif ( strpos( $act_lower, 'invited' ) !== false || strpos( $act_lower, 'pending' ) !== false || strpos( $act_lower, 'held' ) !== false ) {
                                $act_class = 'ofp-act-pill-warning';
                            } else {
                                $act_class = 'ofp-act-pill-default';
                            }

                            $log_ts     = strtotime( $log->created_at );
                            $date_short = wp_date( 'M j, Y', $log_ts );
                            $time_clock = wp_date( 'g:i A', $log_ts );
                            $time_exact = wp_date( 'g:i:s A', $log_ts );
                            $date_full  = wp_date( 'l, F j, Y', $log_ts );
                            $time_rel   = human_time_diff( $log_ts, current_time( 'timestamp' ) ) . ' ago';

                            // Clean message description
                            $desc = $details['message'] ?? ( $details['description'] ?? '' );
                            if ( empty( $desc ) ) {
                                $desc = $log->action;
                            }

                            // Extra snippet for table row to bridge gaps naturally
                            $extra_snippet = '';
                            if ( ! empty( $details['subject'] ) ) {
                                $extra_snippet = $details['subject'] . ( ! empty( $details['to'] ) ? ' (' . $details['to'] . ')' : '' );
                            } elseif ( ! empty( $details['message'] ) ) {
                                $extra_snippet = $details['message'];
                            } elseif ( ! empty( $details['to'] ) ) {
                                $extra_snippet = $details['to'];
                            } elseif ( ! empty( $details['amount'] ) ) {
                                $extra_snippet = '₦' . number_format( (float) $details['amount'], 2 );
                            } elseif ( ! empty( $details['plan'] ) ) {
                                $extra_snippet = ucfirst( $details['plan'] ) . ' plan';
                            }

                            // Precise email log detection (must NOT mistake SMS with phone recipient for email)
                            $is_sms_log   = ( strpos( $act_lower, 'sms' ) !== false );
                            $is_voice_log = ( strpos( $act_lower, 'voice' ) !== false || strpos( $act_lower, 'call' ) !== false );
                            $is_email_log = ( ! $is_sms_log && ! $is_voice_log ) && (
                                strpos( $act_lower, 'email' ) !== false ||
                                ! empty( $details['subject'] ) ||
                                ( ! empty( $details['to'] ) && is_email( $details['to'] ) )
                            );

                            $payload = [
                                'id'          => (int) $log->id,
                                'action'      => $log->action,
                                'act_class'   => $act_class,
                                'date_full'   => $date_full,
                                'time_exact'  => $time_exact,
                                'time_rel'    => $time_rel,
                                'actor_label' => $actor_label,
                                'actor_color' => $actor_color,
                                'actor_bg'    => $actor_bg,
                                'description' => $desc,
                                'details'     => $details,
                                'is_email'    => $is_email_log,
                            ];
                        ?>
                        <tr>
                            <!-- Date & Time -->
                            <td class="col-date">
                                <div class="ofp-act-date-main"><?php echo esc_html( $date_short ); ?></div>
                                <div class="ofp-act-date-sub">
                                    <span><?php echo esc_html( $time_clock ); ?></span>
                                    <span class="dot-sep">•</span>
                                    <span><?php echo esc_html( $time_rel ); ?></span>
                                </div>
                            </td>

                            <!-- Action / Event (With informative snippet to bridge gap to Actor) -->
                            <td class="col-action">
                                <div class="ofp-act-action-cell">
                                    <span class="ofp-act-pill <?php echo esc_attr( $act_class ); ?>">
                                        <?php echo esc_html( $log->action ); ?>
                                    </span>
                                    <?php if ( $extra_snippet ) : ?>
                                        <span class="ofp-act-snippet" title="<?php echo esc_attr( $extra_snippet ); ?>">
                                            <?php echo esc_html( wp_trim_words( $extra_snippet, 5, '…' ) ); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Initiated By -->
                            <td class="col-actor">
                                <span class="ofp-act-actor-badge" style="color:<?php echo esc_attr( $actor_color ); ?>; background:<?php echo esc_attr( $actor_bg ); ?>; border-color:<?php echo esc_attr( $actor_color ); ?>33;">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><?php echo $actor_svg; ?></svg>
                                    <span title="<?php echo esc_attr( $actor_label ); ?>"><?php echo esc_html( $actor_label ); ?></span>
                                </span>
                            </td>

                            <!-- Details button -->
                            <td class="col-details">
                                <button type="button" class="ofp-act-btn-view" data-log="<?php echo esc_attr( wp_json_encode( $payload ) ); ?>" data-is-email="<?php echo $is_email_log ? '1' : '0'; ?>" onclick="ofpHandleViewLog(this)">
                                    <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <span>View</span>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ) : ?>
            <div class="ofp-act-pagination">
                <div>
                    Showing <strong><?php echo number_format( $offset + 1 ); ?></strong> - <strong><?php echo number_format( min( $total_logs, $offset + $limit ) ); ?></strong> of <strong><?php echo number_format( $total_logs ); ?></strong> events
                </div>
                <div class="ofp-act-page-links">
                    <?php if ( $paged > 1 ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>" class="ofp-act-page-btn">&larr; Prev</a>
                    <?php endif; ?>

                    <?php
                    $start_p = max( 1, $paged - 2 );
                    $end_p   = min( $total_pages, $paged + 2 );
                    for ( $i = $start_p; $i <= $end_p; $i++ ) :
                    ?>
                        <a href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>" class="ofp-act-page-btn <?php echo $i === $paged ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ( $paged < $total_pages ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>" class="ofp-act-page-btn">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- ── SLIDE-OVER EMAIL DRAWER WITH TEMPLATE PREVIEW & RESEND ───────── -->
<div class="ofp-email-drawer-overlay" id="ofpEmailDrawerOverlay" onclick="ofpCloseEmailDrawer()"></div>
<div class="ofp-email-drawer" id="ofpEmailDrawer" role="dialog" aria-modal="true" aria-labelledby="ofpEmailDrawerTitle">
    <div class="ofp-email-drawer-header">
        <div class="ofp-email-drawer-header-left">
            <svg width="20" height="20" fill="none" stroke="var(--accent-blue,#2563eb)" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <h3 class="ofp-email-drawer-title" id="ofpEmailDrawerTitle">Email Delivery & Template</h3>
            <span id="ofpDrawerStatusBadge" class="ofp-act-pill ofp-act-pill-success" style="font-size:11px;">Sent via SMTP</span>
        </div>
        <button type="button" class="ofp-email-drawer-close" onclick="ofpCloseEmailDrawer()" aria-label="Close drawer">&times;</button>
    </div>

    <!-- Meta Details Bar -->
    <div class="ofp-email-drawer-meta">
        <div class="ofp-email-drawer-meta-row">
            <span class="ofp-email-drawer-meta-label">To:</span>
            <span class="ofp-email-drawer-meta-val" id="ofpDrawerTo"></span>
        </div>
        <div class="ofp-email-drawer-meta-row">
            <span class="ofp-email-drawer-meta-label">Subject:</span>
            <span class="ofp-email-drawer-meta-val" id="ofpDrawerSubject" style="font-weight:600;"></span>
        </div>
        <div class="ofp-email-drawer-meta-row">
            <span class="ofp-email-drawer-meta-label">Date:</span>
            <span class="ofp-email-drawer-meta-val" id="ofpDrawerDate"></span>
        </div>
        <div class="ofp-email-drawer-meta-row">
            <span class="ofp-email-drawer-meta-label">Sender:</span>
            <span class="ofp-email-drawer-meta-val" id="ofpDrawerActor"></span>
        </div>
    </div>

    <!-- Email Template Preview Body -->
    <div class="ofp-email-drawer-body">
        <div class="ofp-email-iframe-wrap">
            <iframe id="ofpEmailIframe" class="ofp-email-iframe" sandbox="allow-same-origin" title="Email Template Preview"></iframe>
        </div>
    </div>

    <!-- Footer with Resend Action -->
    <div class="ofp-email-drawer-footer">
        <div class="ofp-email-resend-group">
            <button type="button" class="ofp-act-btn-resend" id="ofpBtnResend" onclick="ofpTriggerResendEmail()">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span id="ofpBtnResendText">Resend Email</span>
            </button>
            <span id="ofpResendStatus" class="ofp-resend-status"></span>
        </div>
        <button type="button" class="ofp-act-btn-dismiss" onclick="ofpCloseEmailDrawer()">Close</button>
    </div>
</div>

<!-- ── GENERAL EVENT MODAL (FOR NON-EMAIL EVENTS) ───────────────────── -->
<div class="ofp-act-modal-overlay" id="ofpActModalOverlay" onclick="ofpHandleModalBackdropClick(event)">
    <div class="ofp-act-modal-container" role="dialog" aria-modal="true" aria-labelledby="ofpModalTitle">
        <div class="ofp-act-modal-header">
            <h3 class="ofp-act-modal-title" id="ofpModalTitle">Event Details</h3>
            <button type="button" class="ofp-act-modal-close" onclick="ofpCloseClientLogModal()" aria-label="Close dialog">&times;</button>
        </div>
        <div class="ofp-act-modal-body">
            <!-- Event Action Pill & Relative Time -->
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <span id="ofpModalActionPill" class="ofp-act-pill ofp-act-pill-default" style="font-size:13px; font-weight:600; padding:5px 12px;"></span>
                <span id="ofpModalTimeRelBadge" style="font-size:12px; color:var(--text-muted,#64748b); font-weight:500;"></span>
            </div>

            <!-- Date & Time Full Card -->
            <div class="ofp-act-box">
                <div class="ofp-act-box-label">Date & Exact Time</div>
                <div class="ofp-act-box-val" id="ofpModalTimestamp" style="display:flex; flex-direction:column; gap:2px;">
                    <strong id="ofpModalDateFull" style="font-size:14px;"></strong>
                    <span style="font-size:12.5px; color:var(--text-muted,#64748b);">
                        Exact time: <span id="ofpModalTimeExact" style="font-weight:600;"></span>
                    </span>
                </div>
            </div>

            <!-- Initiated By Card -->
            <div class="ofp-act-box">
                <div class="ofp-act-box-label">Initiated By</div>
                <div class="ofp-act-box-val" id="ofpModalActor"></div>
            </div>

            <!-- General Event Narrative -->
            <div id="ofpModalGeneralCard" class="ofp-act-box">
                <div class="ofp-act-box-label">Event Description</div>
                <div class="ofp-act-box-val" id="ofpModalNarrative" style="white-space:pre-wrap; line-height:1.5;"></div>
            </div>

            <!-- Structured Parameters Container (User-Friendly Cards) -->
            <div id="ofpModalParamsContainer" style="display:none;">
                <div class="ofp-act-box-label" style="margin-bottom:8px;">Event Information</div>
                <div class="ofp-act-meta-grid" id="ofpModalParamsGrid"></div>
            </div>
        </div>
        <div class="ofp-act-modal-footer">
            <button type="button" class="ofp-act-btn-dismiss" onclick="ofpCloseClientLogModal()">Close</button>
        </div>
    </div>
</div>

</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
