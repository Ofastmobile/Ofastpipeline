<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$paged  = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$limit  = 50;
$offset = ( $paged - 1 ) * $limit;

$actor_filter = isset( $_GET['actor_type'] ) ? sanitize_key( $_GET['actor_type'] ) : '';
$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

$where_clauses = [];
$args          = [];

if ( $actor_filter === 'email' ) {
    $where_clauses[] = "(l.action LIKE %s OR l.details LIKE %s OR l.details LIKE %s)";
    $args[] = '%email%';
    $args[] = '%"to":%';
    $args[] = '%"subject":%';
} elseif ( in_array( $actor_filter, [ 'client', 'team_member', 'admin', 'system' ], true ) ) {
    // actor_type is stored inside the JSON details blob
    $where_clauses[] = "l.details LIKE %s";
    $args[] = '%"actor_type":"' . $wpdb->esc_like( $actor_filter ) . '"%';
}

if ( $search !== '' ) {
    $search_like = '%' . $wpdb->esc_like( $search ) . '%';
    $where_clauses[] = "(l.action LIKE %s OR l.details LIKE %s OR c.business_name LIKE %s OR a.name LIKE %s)";
    $args[] = $search_like;
    $args[] = $search_like;
    $args[] = $search_like;
    $args[] = $search_like;
}

$where = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

$total_logs = $where
    ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(l.id) FROM {$wpdb->prefix}ofp_activity_logs l LEFT JOIN {$wpdb->prefix}ofp_clients c ON l.client_id = c.id LEFT JOIN {$wpdb->prefix}ofp_admins a ON l.admin_id = a.id {$where}", $args ) )
    : $wpdb->get_var( "SELECT COUNT(id) FROM {$wpdb->prefix}ofp_activity_logs" );

$total_pages = ceil( ( $total_logs ?: 0 ) / $limit );

$query_args = array_merge( $args, [ $limit, $offset ] );
$logs = $wpdb->get_results( $wpdb->prepare(
    "SELECT l.*, c.business_name, c.email AS client_email, a.name AS admin_name, a.email AS admin_email
     FROM {$wpdb->prefix}ofp_activity_logs l
     LEFT JOIN {$wpdb->prefix}ofp_clients c ON l.client_id = c.id
     LEFT JOIN {$wpdb->prefix}ofp_admins a ON l.admin_id = a.id
     {$where}
     ORDER BY l.created_at DESC
     LIMIT %d OFFSET %d",
    $query_args
) );

$is_portal = ( isset( $_GET['tab'] ) || strpos( ( $_SERVER['REQUEST_URI'] ?? '' ), 'admin-portal' ) !== false );
$base_client_url = $is_portal ? home_url( '/admin-portal?tab=clients&client_id=' ) : admin_url( 'admin.php?page=ofp-clients&client_id=' );
?>

<div class="ofp-activity-logs-container">
    <!-- Header with Title & Stats -->
    <div class="ofp-logs-header">
        <div>
            <h2 class="ofp-logs-title">System Activity Audit Log</h2>
            <p class="ofp-logs-subtitle">Real-time immutable audit trail of administrative, client, team member, and automated actions.</p>
        </div>
        <div class="ofp-logs-stats-pill">
            <span class="ofp-stats-number"><?php echo number_format( (int) $total_logs ); ?></span>
            <span class="ofp-stats-label">Total Events</span>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="ofp-logs-toolbar">
        <!-- Filter Tabs / Pills -->
        <div class="ofp-filter-pills">
            <a href="<?php echo esc_url( remove_query_arg( [ 'actor_type', 'paged' ] ) ); ?>" class="ofp-pill-btn <?php echo $actor_filter === '' ? 'active' : ''; ?>">
                All Logs
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'actor_type', 'email', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-pill-btn <?php echo $actor_filter === 'email' ? 'active' : ''; ?>">
                <span class="ofp-pill-dot" style="background:#06b6d4;"></span>
                Emails / SMTP
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'actor_type', 'client', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-pill-btn <?php echo $actor_filter === 'client' ? 'active' : ''; ?>">
                <span class="ofp-pill-dot" style="background:#2563eb;"></span>
                Clients
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'actor_type', 'team_member', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-pill-btn <?php echo $actor_filter === 'team_member' ? 'active' : ''; ?>">
                <span class="ofp-pill-dot" style="background:#f59e0b;"></span>
                Team Members
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'actor_type', 'admin', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-pill-btn <?php echo $actor_filter === 'admin' ? 'active' : ''; ?>">
                <span class="ofp-pill-dot" style="background:#7c3aed;"></span>
                Admin
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'actor_type', 'system', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-pill-btn <?php echo $actor_filter === 'system' ? 'active' : ''; ?>">
                <span class="ofp-pill-dot" style="background:#64748b;"></span>
                System / Cron
            </a>
        </div>

        <!-- Search Input -->
        <form method="get" class="ofp-logs-search-form">
            <?php if ( $is_portal ) : ?>
                <input type="hidden" name="tab" value="activity-logs">
            <?php else : ?>
                <input type="hidden" name="page" value="ofp-activity-logs">
            <?php endif; ?>
            <?php if ( $actor_filter ) : ?>
                <input type="hidden" name="actor_type" value="<?php echo esc_attr( $actor_filter ); ?>">
            <?php endif; ?>
            <div class="ofp-search-wrapper">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search actions, clients, actors..." class="ofp-search-input">
                <?php if ( $search ) : ?>
                    <a href="<?php echo esc_url( remove_query_arg( [ 's', 'paged' ] ) ); ?>" class="ofp-clear-search" title="Clear search">&times;</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Table Container (Sleek Compact Fit) -->
    <div class="ofp-table-responsive-wrapper">
        <table class="ofp-modern-log-table">
            <thead>
                <tr>
                    <th style="width: 20%;">Date & Time</th>
                    <th style="width: 32%;">Action / Event</th>
                    <th style="width: 22%;">Target Client</th>
                    <th style="width: 16%;">Actor</th>
                    <th style="width: 10%; text-align: right;">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $logs ) ) : ?>
                    <tr>
                        <td colspan="5" class="ofp-empty-state-cell">
                            <div class="ofp-empty-state-content">
                                <svg width="40" height="40" fill="none" stroke="#94a3b8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <h4>No activity logs found</h4>
                                <p><?php echo $search ? 'No events matching your search criteria. Try a different query.' : 'There are no recorded log events for this filter.'; ?></p>
                            </div>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $logs as $log ) : ?>
                        <?php
                            $details    = $log->details ? json_decode( $log->details, true ) : [];
                            if ( ! is_array( $details ) ) {
                                $details = [];
                            }
                            $actor_type = $details['actor_type'] ?? ( $log->admin_id ? 'admin' : 'system' );
                            $actor_name = $details['actor_name'] ?? null;

                            switch ( $actor_type ) {
                                case 'team_member':
                                    $actor_label = 'Team Member' . ( $actor_name ? ': ' . $actor_name : '' );
                                    $actor_color = '#f59e0b';
                                    $actor_bg    = 'rgba(245, 158, 11, 0.12)';
                                    $actor_icon  = 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z';
                                    break;
                                case 'client':
                                    $actor_label = 'Client' . ( $actor_name ? ': ' . $actor_name : '' );
                                    $actor_color = '#2563eb';
                                    $actor_bg    = 'rgba(37, 99, 235, 0.12)';
                                    $actor_icon  = 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4';
                                    break;
                                case 'admin':
                                    $actor_label = 'Admin' . ( $log->admin_name ? ': ' . $log->admin_name : '' );
                                    $actor_color = '#7c3aed';
                                    $actor_bg    = 'rgba(124, 58, 237, 0.12)';
                                    $actor_icon  = 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z';
                                    break;
                                default:
                                    $actor_label = 'System / Automation';
                                    $actor_color = '#64748b';
                                    $actor_bg    = 'rgba(100, 116, 139, 0.12)';
                                    $actor_icon  = 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z';
                            }

                            // Event category badge styling
                            $action_lower = strtolower( $log->action );
                            if ( strpos( $action_lower, 'paid' ) !== false || strpos( $action_lower, 'payment' ) !== false || strpos( $action_lower, 'approved' ) !== false || strpos( $action_lower, 'topped up' ) !== false ) {
                                $action_theme = 'success';
                            } elseif ( strpos( $action_lower, 'fail' ) !== false || strpos( $action_lower, 'reject' ) !== false || strpos( $action_lower, 'expired' ) !== false || strpos( $action_lower, 'blocked' ) !== false || strpos( $action_lower, 'removed' ) !== false ) {
                                $action_theme = 'danger';
                            } elseif ( strpos( $action_lower, 'sent' ) !== false || strpos( $action_lower, 'email' ) !== false || strpos( $action_lower, 'sms' ) !== false ) {
                                $action_theme = 'info';
                            } elseif ( strpos( $action_lower, 'invited' ) !== false || strpos( $action_lower, 'held' ) !== false || strpos( $action_lower, 'underpayment' ) !== false ) {
                                $action_theme = 'warning';
                            } else {
                                $action_theme = 'default';
                            }

                            $log_timestamp  = strtotime( $log->created_at );
                            $date_full      = wp_date( 'l, F j, Y', $log_timestamp );
                            $time_exact     = wp_date( 'g:i:s A', $log_timestamp );
                            $time_rel       = human_time_diff( $log_timestamp, current_time( 'timestamp' ) ) . ' ago';
                            $client_profile = $log->client_id ? $base_client_url . (int) $log->client_id : '';

                            // Payload for modal
                            $modal_payload = [
                                'id'             => (int) $log->id,
                                'action'         => $log->action,
                                'action_theme'   => $action_theme,
                                'created_at'     => $log->created_at,
                                'date_full'      => $date_full,
                                'time_exact'     => $time_exact,
                                'time_rel'       => $time_rel,
                                'client_id'      => $log->client_id ? (int) $log->client_id : null,
                                'client_name'    => $log->business_name ?: ( $log->client_id ? 'Client #' . $log->client_id : 'Global / Platform' ),
                                'client_email'   => $log->client_email ?? '',
                                'client_url'     => $client_profile,
                                'actor_type'     => $actor_type,
                                'actor_label'    => $actor_label,
                                'actor_name'     => $actor_name ?: ( $log->admin_name ?: 'System' ),
                                'actor_color'    => $actor_color,
                                'actor_bg'       => $actor_bg,
                                'admin_name'     => $log->admin_name ?? '',
                                'admin_email'    => $log->admin_email ?? '',
                                'details'        => $details,
                                'is_email'       => ( strpos( $action_lower, 'email' ) !== false || ! empty( $details['to'] ) || ! empty( $details['subject'] ) ),
                            ];
                        ?>
                        <tr class="ofp-log-row">
                            <!-- Timestamp -->
                            <td class="ofp-cell-date">
                                <div class="ofp-date-primary"><?php echo esc_html( wp_date( 'M j, Y', $log_timestamp ) ); ?></div>
                                <div class="ofp-date-secondary">
                                    <span><?php echo esc_html( $time_exact ); ?></span>
                                    <span class="ofp-rel-dot">•</span>
                                    <span class="ofp-rel-time"><?php echo esc_html( $time_rel ); ?></span>
                                </div>
                            </td>

                            <!-- Action -->
                            <td class="ofp-cell-action">
                                <div class="ofp-action-wrapper">
                                    <span class="ofp-action-pill ofp-action-<?php echo esc_attr( $action_theme ); ?>">
                                        <?php echo esc_html( $log->action ); ?>
                                    </span>
                                </div>
                            </td>

                            <!-- Target Client -->
                            <td class="ofp-cell-client">
                                <?php if ( $log->client_id ) : ?>
                                    <a href="<?php echo esc_url( $client_profile ); ?>" class="ofp-client-link" title="View client profile">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                        <span><?php echo esc_html( $log->business_name ?: 'Client #' . $log->client_id ); ?></span>
                                    </a>
                                <?php else : ?>
                                    <span class="ofp-badge-global">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
                                        Global / Platform
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Actor -->
                            <td class="ofp-cell-actor">
                                <span class="ofp-actor-badge" style="color:<?php echo esc_attr( $actor_color ); ?>; background:<?php echo esc_attr( $actor_bg ); ?>; border-color:<?php echo esc_attr( $actor_color ); ?>33;">
                                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?php echo esc_attr( $actor_icon ); ?>"/></svg>
                                    <span><?php echo esc_html( $actor_label ); ?></span>
                                </span>
                            </td>

                            <!-- Action Button to Open Modern Popup / Slide Drawer -->
                            <td class="ofp-cell-details" style="text-align: right;">
                                <button type="button" 
                                        class="ofp-btn-view-details" 
                                        data-log="<?php echo esc_attr( wp_json_encode( $modal_payload ) ); ?>"
                                        data-is-email="<?php echo ! empty( $modal_payload['is_email'] ) ? '1' : '0'; ?>"
                                        onclick="ofpOpenLogModal(this)"
                                        title="View comprehensive event details">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <span>View Details</span>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls -->
    <?php if ( $total_pages > 1 ) : ?>
        <div class="ofp-logs-pagination">
            <div class="ofp-pagination-info">
                Showing page <strong><?php echo (int) $paged; ?></strong> of <strong><?php echo (int) $total_pages; ?></strong> (<?php echo number_format( (int) $total_logs ); ?> total events)
            </div>
            <div class="ofp-pagination-links">
                <?php
                echo paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'prev_text' => '&laquo; Prev',
                    'next_text' => 'Next &raquo;',
                    'total'     => $total_pages,
                    'current'   => $paged,
                ] );
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════════
     MODERN ACTIVITY LOG DETAILS MODAL (POPUP DIALOG)
     Replaces crude browser alerts with executive SaaS modal with complete
     message, full date and time, actor info, client details, and structured JSON.
     ══════════════════════════════════════════════════════════════════════════════ -->
<div id="ofp-log-modal-overlay" class="ofp-log-modal-overlay" aria-hidden="true" onclick="ofpCloseLogModalOnBackdrop(event)">
    <div class="ofp-log-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="ofp-modal-title">
        
        <!-- Modal Top Banner / Header -->
        <div class="ofp-log-modal-header">
            <div class="ofp-log-modal-title-group">
                <div class="ofp-log-category-icon" id="ofp-modal-cat-icon">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <div class="ofp-modal-eyebrow">
                        <span>AUDIT EVENT</span>
                        <span class="ofp-modal-id-badge" id="ofp-modal-log-id">#0</span>
                    </div>
                    <h3 class="ofp-log-modal-title" id="ofp-modal-title">Activity Details</h3>
                </div>
            </div>
            <button type="button" class="ofp-log-modal-close" onclick="ofpCloseLogModal()" aria-label="Close dialog">&times;</button>
        </div>

        <!-- Date & Time Bar (Full Date, Exact Time, Relative Time) -->
        <div class="ofp-log-modal-datetime-bar">
            <div class="ofp-dt-item">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <div>
                    <span class="ofp-dt-label">Date</span>
                    <strong class="ofp-dt-value" id="ofp-modal-full-date">—</strong>
                </div>
            </div>
            <div class="ofp-dt-item">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <div>
                    <span class="ofp-dt-label">Exact Time</span>
                    <strong class="ofp-dt-value" id="ofp-modal-exact-time">—</strong>
                </div>
            </div>
            <div class="ofp-dt-item ofp-dt-rel-item">
                <span class="ofp-rel-badge" id="ofp-modal-rel-time">Just now</span>
            </div>
        </div>

        <!-- Scrollable Modal Body -->
        <div class="ofp-log-modal-body">
            
            <!-- Actor & Client Summary Strip -->
            <div class="ofp-modal-meta-grid">
                <!-- Actor Card -->
                <div class="ofp-meta-card">
                    <span class="ofp-meta-card-label">Initiated By (Actor)</span>
                    <div class="ofp-meta-card-content" id="ofp-modal-actor-wrap">
                        <span class="ofp-actor-pill" id="ofp-modal-actor-badge">System</span>
                    </div>
                </div>

                <!-- Client Card -->
                <div class="ofp-meta-card">
                    <span class="ofp-meta-card-label">Target Scope / Client</span>
                    <div class="ofp-meta-card-content" id="ofp-modal-client-wrap">
                        <span id="ofp-modal-client-name">Global Platform</span>
                    </div>
                </div>
            </div>

            <!-- Complete Message / Descriptive Summary Box -->
            <div class="ofp-modal-message-box">
                <div class="ofp-msg-box-header">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>Complete Message & Event Summary</span>
                </div>
                <div class="ofp-msg-box-content" id="ofp-modal-complete-message">
                    <!-- Injected by JS -->
                </div>
            </div>

            <!-- Structured Metadata Attributes (Key-Values Grid) -->
            <div class="ofp-modal-section" id="ofp-modal-structured-section" style="display:none;">
                <h4 class="ofp-section-heading">Event Details & Parameters</h4>
                <div class="ofp-structured-grid" id="ofp-modal-structured-grid">
                    <!-- Populated dynamically by JS -->
                </div>
            </div>

            <!-- Developer / Audit Raw JSON Inspector -->
            <div class="ofp-modal-raw-section">
                <div class="ofp-raw-header" onclick="ofpToggleRawJson()">
                    <div class="ofp-raw-title">
                        <svg class="ofp-raw-chevron" id="ofp-raw-chevron" width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <span>Raw Audit Payload (JSON)</span>
                    </div>
                    <button type="button" class="ofp-btn-copy-json" onclick="ofpCopyRawJson(event)">
                        <svg width="13" height="13" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        <span id="ofp-copy-json-label">Copy JSON</span>
                    </button>
                </div>
                <div class="ofp-raw-body" id="ofp-raw-body" style="display:none;">
                    <pre class="ofp-json-code" id="ofp-modal-raw-json"></pre>
                </div>
            </div>

        </div>

        <!-- Modal Footer -->
        <div class="ofp-log-modal-footer">
            <span class="ofp-modal-footer-hint">Press <kbd>ESC</kbd> to close</span>
            <div class="ofp-modal-footer-actions">
                <span id="ofp-modal-client-btn-wrap"></span>
                <button type="button" class="ofp-btn-modal-close" onclick="ofpCloseLogModal()">Close</button>
            </div>
        </div>

    </div>
</div>

<!-- ── SLIDE-OVER EMAIL DRAWER WITH TEMPLATE PREVIEW & RESEND ───────── -->
<div class="ofp-email-drawer-overlay" id="ofpEmailDrawerOverlay" onclick="ofpCloseEmailDrawer()"></div>
<div class="ofp-email-drawer" id="ofpEmailDrawer" role="dialog" aria-modal="true" aria-labelledby="ofpEmailDrawerTitle">
    <div class="ofp-email-drawer-header">
        <div class="ofp-email-drawer-header-left">
            <svg width="20" height="20" fill="none" stroke="#2563eb" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <h3 class="ofp-email-drawer-title" id="ofpEmailDrawerTitle">Email Delivery & Template</h3>
            <span id="ofpDrawerStatusBadge" class="ofp-action-pill ofp-action-success" style="font-size:11px;">Sent via SMTP</span>
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
            <button type="button" class="ofp-act-btn-resend" id="ofpBtnResend" onclick="ofpTriggerAdminResendEmail()">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span id="ofpBtnResendText">Resend Email</span>
            </button>
            <span id="ofpResendStatus" class="ofp-resend-status"></span>
        </div>
        <button type="button" class="ofp-act-btn-dismiss" onclick="ofpCloseEmailDrawer()">Close</button>
    </div>
</div>

<!-- Activity Logs styles and scripts are enqueued via ofp-admin-activity-logs.css and ofp-admin-activity-logs.js -->

