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

if ( in_array( $actor_filter, [ 'client', 'team_member', 'admin', 'system' ], true ) ) {
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

                            <!-- Action Button to Open Modern Popup -->
                            <td class="ofp-cell-details" style="text-align: right;">
                                <button type="button" 
                                        class="ofp-btn-view-details" 
                                        data-log="<?php echo esc_attr( wp_json_encode( $modal_payload ) ); ?>"
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

<!-- Scoped Styles for Modern Activity Logs & Popup Modal -->
<style>
/* Activity Logs View Styles */
.ofp-activity-logs-container {
    background: var(--ofp-card-bg, #ffffff);
    border: 1px solid var(--ofp-card-border, #e2e8f0);
    border-radius: 14px;
    padding: 16px 18px;
    box-shadow: var(--ofp-shadow, 0 1px 3px rgba(0,0,0,0.06));
    font-family: var(--ofp-admin-font, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif);
    color: var(--ofp-text-main, #0f172a);
    margin-top: 10px;
}

.ofp-logs-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 20px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--ofp-card-border, #f1f5f9);
}

.ofp-logs-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--ofp-text-main, #0f172a);
    margin: 0 0 4px 0;
    letter-spacing: -0.01em;
}

.ofp-logs-subtitle {
    font-size: 13px;
    color: var(--ofp-text-muted, #64748b);
    margin: 0;
}

.ofp-logs-stats-pill {
    display: flex;
    align-items: baseline;
    gap: 8px;
    background: var(--ofp-table-stripe, #f8fafc);
    border: 1px solid var(--ofp-card-border, #e2e8f0);
    padding: 6px 14px;
    border-radius: 9999px;
}

.ofp-stats-number {
    font-size: 16px;
    font-weight: 700;
    color: var(--ofp-primary, #10b981);
}

.ofp-stats-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--ofp-text-muted, #64748b);
}

/* Toolbar */
.ofp-logs-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 20px;
}

.ofp-filter-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}

.ofp-pill-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 500;
    color: var(--ofp-text-muted, #64748b);
    background: transparent;
    border: 1px solid transparent;
    text-decoration: none;
    transition: all 0.15s ease;
}

.ofp-pill-btn:hover {
    background: var(--ofp-table-stripe, #f1f5f9);
    color: var(--ofp-text-main, #0f172a);
}

.ofp-pill-btn.active {
    background: var(--ofp-sb-bg, #09261f);
    color: #ffffff;
    border-color: var(--ofp-sb-bg, #09261f);
    font-weight: 600;
}

.ofp-pill-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    display: inline-block;
}

.ofp-logs-search-form {
    margin: 0;
}

.ofp-search-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.ofp-search-wrapper svg {
    position: absolute;
    left: 10px;
    color: var(--ofp-text-light, #94a3b8);
    pointer-events: none;
}

.ofp-search-input {
    padding: 7px 28px 7px 32px !important;
    border: 1px solid var(--ofp-input-border, #cbd5e1) !important;
    border-radius: 8px !important;
    font-size: 12.5px !important;
    background: var(--ofp-input-bg, #ffffff) !important;
    color: var(--ofp-text-main, #0f172a) !important;
    width: 240px;
    transition: all 0.2s ease;
    box-shadow: none !important;
}

.ofp-search-input:focus {
    width: 290px;
    border-color: var(--ofp-primary, #10b981) !important;
    outline: none;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15) !important;
}

.ofp-clear-search {
    position: absolute;
    right: 8px;
    font-size: 16px;
    color: var(--ofp-text-muted, #94a3b8);
    text-decoration: none;
    padding: 2px;
    line-height: 1;
}

/* Table Wrapper (Compact & Responsive) */
.ofp-table-responsive-wrapper {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    width: 100%;
    border: 1px solid var(--ofp-card-border, #e2e8f0);
    border-radius: 10px;
    margin-bottom: 14px;
    background: var(--ofp-card-bg, #ffffff);
}

.ofp-modern-log-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    text-align: left;
    table-layout: auto;
}

.ofp-modern-log-table th {
    background: var(--ofp-table-stripe, #f8fafc);
    color: var(--ofp-text-muted, #64748b);
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 8px 12px;
    border-bottom: 1px solid var(--ofp-card-border, #e2e8f0);
    white-space: nowrap;
}

.ofp-modern-log-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--ofp-card-border, #f1f5f9);
    vertical-align: middle;
}

.ofp-log-row:hover td {
    background: var(--ofp-table-hover, #f8fafc);
}

.ofp-cell-date {
    white-space: nowrap;
}

.ofp-date-primary {
    font-weight: 600;
    color: var(--ofp-text-main, #0f172a);
    font-size: 12.5px;
}

.ofp-date-secondary {
    font-size: 11px;
    color: var(--ofp-text-muted, #64748b);
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 2px;
}

.ofp-rel-dot {
    color: var(--ofp-text-light, #cbd5e1);
}

.ofp-rel-time {
    color: var(--ofp-text-muted, #64748b);
}

/* Action Badges */
.ofp-action-wrapper {
    display: flex;
    align-items: center;
}

.ofp-action-pill {
    display: inline-block;
    padding: 2.5px 8px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: 0.01em;
}

.ofp-action-default {
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
}

.ofp-action-success {
    background: rgba(16, 185, 129, 0.12);
    color: #059669;
    border: 1px solid rgba(16, 185, 129, 0.25);
}

.ofp-action-danger {
    background: rgba(239, 68, 68, 0.12);
    color: #dc2626;
    border: 1px solid rgba(239, 68, 68, 0.25);
}

.ofp-action-warning {
    background: rgba(245, 158, 11, 0.12);
    color: #d97706;
    border: 1px solid rgba(245, 158, 11, 0.25);
}

.ofp-action-info {
    background: rgba(37, 99, 235, 0.12);
    color: #2563eb;
    border: 1px solid rgba(37, 99, 235, 0.25);
}

/* Client link & badge */
.ofp-client-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
    font-size: 12px;
    color: var(--ofp-primary, #10b981);
    text-decoration: none;
    transition: color 0.15s ease;
}

.ofp-client-link:hover {
    color: var(--ofp-primary-hover, #059669);
    text-decoration: underline;
}

.ofp-badge-global {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: var(--ofp-table-stripe, #f1f5f9);
    color: var(--ofp-text-muted, #64748b);
    border: 1px solid var(--ofp-card-border, #e2e8f0);
    padding: 2px 7px;
    border-radius: 6px;
    font-size: 10.5px;
    font-weight: 600;
}

/* Actor Badge */
.ofp-actor-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    border: 1px solid transparent;
}

/* Modern View Details Button */
.ofp-btn-view-details {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    background: var(--ofp-card-bg, #ffffff);
    color: var(--ofp-text-main, #0f172a);
    border: 1px solid var(--ofp-input-border, #cbd5e1);
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}

.ofp-btn-view-details:hover {
    background: var(--ofp-primary-light, rgba(16, 185, 129, 0.1));
    border-color: var(--ofp-primary, #10b981);
    color: var(--ofp-primary-hover, #059669);
    transform: translateY(-1px);
}

/* Empty State */
.ofp-empty-state-cell {
    text-align: center;
    padding: 48px 24px !important;
}

.ofp-empty-state-content h4 {
    margin: 12px 0 4px 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--ofp-text-main, #0f172a);
}

.ofp-empty-state-content p {
    margin: 0;
    font-size: 13px;
    color: var(--ofp-text-muted, #64748b);
}

/* Pagination */
.ofp-logs-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    padding-top: 12px;
    font-size: 12.5px;
    color: var(--ofp-text-muted, #64748b);
}

.ofp-pagination-links a,
.ofp-pagination-links span {
    display: inline-block;
    padding: 5px 10px;
    margin: 0 2px;
    border-radius: 6px;
    border: 1px solid var(--ofp-card-border, #e2e8f0);
    text-decoration: none;
    color: var(--ofp-text-main, #0f172a);
    font-weight: 500;
    font-size: 12px;
}

.ofp-pagination-links span.current {
    background: var(--ofp-sb-bg, #09261f);
    color: #ffffff;
    border-color: var(--ofp-sb-bg, #09261f);
    font-weight: 600;
}

.ofp-pagination-links a:hover {
    background: var(--ofp-table-hover, #f1f5f9);
}

/* ══════════════════════════════════════════════════════════════════════════════
   MODERN POPUP MODAL STYLES
   ══════════════════════════════════════════════════════════════════════════════ */
.ofp-log-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(9, 38, 31, 0.68);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    z-index: 999999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    transition: opacity 0.22s ease;
}

.ofp-log-modal-overlay.active {
    display: flex;
    opacity: 1;
}

.ofp-log-modal-dialog {
    background: var(--ofp-card-bg, #ffffff);
    border: 1px solid var(--ofp-card-border, #e2e8f0);
    border-radius: 18px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(0,0,0,0.05);
    max-width: 680px;
    width: 100%;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transform: scale(0.96) translateY(8px);
    transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    font-family: var(--ofp-admin-font, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif);
    color: var(--ofp-text-main, #0f172a);
}

.ofp-log-modal-overlay.active .ofp-log-modal-dialog {
    transform: scale(1) translateY(0);
}

/* Modal Header */
.ofp-log-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px 16px 24px;
    border-bottom: 1px solid var(--ofp-card-border, #e2e8f0);
    background: var(--ofp-card-bg, #ffffff);
}

.ofp-log-modal-title-group {
    display: flex;
    align-items: center;
    gap: 12px;
}

.ofp-log-category-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(16, 185, 129, 0.12);
    color: #10b981;
    flex-shrink: 0;
}

.ofp-modal-eyebrow {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--ofp-text-muted, #64748b);
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 2px;
}

.ofp-modal-id-badge {
    background: var(--ofp-table-stripe, #f1f5f9);
    padding: 1px 6px;
    border-radius: 4px;
    color: var(--ofp-text-main, #0f172a);
}

.ofp-log-modal-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--ofp-text-main, #0f172a);
    margin: 0;
    letter-spacing: -0.01em;
}

.ofp-log-modal-close {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: none;
    background: var(--ofp-table-stripe, #f1f5f9);
    color: var(--ofp-text-muted, #64748b);
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}

.ofp-log-modal-close:hover {
    background: #e2e8f0;
    color: #0f172a;
    transform: scale(1.05);
}

/* Date & Time Bar */
.ofp-log-modal-datetime-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 24px;
    background: var(--ofp-table-stripe, #f8fafc);
    border-bottom: 1px solid var(--ofp-card-border, #e2e8f0);
    flex-wrap: wrap;
    gap: 12px;
}

.ofp-dt-item {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ofp-dt-item svg {
    color: var(--ofp-primary, #10b981);
}

.ofp-dt-label {
    display: block;
    font-size: 10.5px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--ofp-text-muted, #64748b);
    font-weight: 600;
    line-height: 1.1;
}

.ofp-dt-value {
    display: block;
    font-size: 13px;
    color: var(--ofp-text-main, #0f172a);
    font-weight: 600;
}

.ofp-rel-badge {
    background: rgba(16, 185, 129, 0.14);
    color: #059669;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.02em;
}

/* Modal Body */
.ofp-log-modal-body {
    padding: 20px 24px;
    overflow-y: auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

/* Meta Grid (Actor & Client) */
.ofp-modal-meta-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.ofp-meta-card {
    background: var(--ofp-table-stripe, #f8fafc);
    border: 1px solid var(--ofp-card-border, #e2e8f0);
    border-radius: 12px;
    padding: 12px 14px;
}

.ofp-meta-card-label {
    display: block;
    font-size: 10.5px;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.05em;
    color: var(--ofp-text-muted, #64748b);
    margin-bottom: 6px;
}

.ofp-meta-card-content {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--ofp-text-main, #0f172a);
}

.ofp-actor-pill {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 600;
}

/* Complete Message Box */
.ofp-modal-message-box {
    background: rgba(16, 185, 129, 0.05);
    border: 1px solid rgba(16, 185, 129, 0.25);
    border-left: 4px solid var(--ofp-primary, #10b981);
    border-radius: 10px;
    padding: 14px 16px;
}

.ofp-msg-box-header {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--ofp-primary-hover, #059669);
    margin-bottom: 6px;
}

.ofp-msg-box-content {
    font-size: 14px;
    line-height: 1.5;
    color: var(--ofp-text-main, #0f172a);
}

/* Structured Parameters Grid */
.ofp-section-heading {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--ofp-text-muted, #64748b);
    margin: 0 0 10px 0;
}

.ofp-structured-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
}

.ofp-param-tile {
    background: var(--ofp-table-stripe, #f8fafc);
    border: 1px solid var(--ofp-card-border, #e2e8f0);
    border-radius: 8px;
    padding: 8px 12px;
}

.ofp-param-key {
    font-size: 10.5px;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--ofp-text-muted, #64748b);
    margin-bottom: 2px;
}

.ofp-param-value {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--ofp-text-main, #0f172a);
    word-break: break-word;
}

/* Raw JSON Section */
.ofp-modal-raw-section {
    border: 1px solid var(--ofp-card-border, #e2e8f0);
    border-radius: 10px;
    overflow: hidden;
}

.ofp-raw-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    background: var(--ofp-table-stripe, #f8fafc);
    cursor: pointer;
    user-select: none;
    transition: background 0.15s ease;
}

.ofp-raw-header:hover {
    background: #f1f5f9;
}

.ofp-raw-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 600;
    color: var(--ofp-text-muted, #64748b);
}

.ofp-raw-chevron {
    transition: transform 0.2s ease;
}

.ofp-raw-chevron.expanded {
    transform: rotate(90deg);
}

.ofp-btn-copy-json {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 8px;
    border-radius: 5px;
    border: 1px solid var(--ofp-card-border, #cbd5e1);
    background: var(--ofp-card-bg, #ffffff);
    color: var(--ofp-text-main, #0f172a);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
}

.ofp-btn-copy-json:hover {
    border-color: var(--ofp-primary, #10b981);
    color: var(--ofp-primary, #10b981);
}

.ofp-raw-body {
    background: #0f172a;
    padding: 14px;
    max-height: 220px;
    overflow-y: auto;
}

.ofp-json-code {
    margin: 0;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 12px;
    line-height: 1.4;
    color: #38bdf8;
    white-space: pre-wrap;
    word-break: break-word;
}

/* Modal Footer */
.ofp-log-modal-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    border-top: 1px solid var(--ofp-card-border, #e2e8f0);
    background: var(--ofp-card-bg, #ffffff);
}

.ofp-modal-footer-hint {
    font-size: 11.5px;
    color: var(--ofp-text-muted, #94a3b8);
}

.ofp-modal-footer-hint kbd {
    background: var(--ofp-table-stripe, #f1f5f9);
    border: 1px solid var(--ofp-card-border, #cbd5e1);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
}

.ofp-modal-footer-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ofp-btn-modal-client {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 8px;
    background: var(--ofp-primary-light, rgba(16, 185, 129, 0.12));
    border: 1px solid var(--ofp-primary-border, rgba(16, 185, 129, 0.25));
    color: var(--ofp-primary-hover, #059669);
    font-size: 12.5px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s ease;
}

.ofp-btn-modal-client:hover {
    background: var(--ofp-primary, #10b981);
    color: #ffffff;
}

.ofp-btn-modal-close {
    padding: 8px 16px;
    border-radius: 8px;
    background: var(--ofp-sb-bg, #09261f);
    color: #ffffff;
    border: none;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease;
}

.ofp-btn-modal-close:hover {
    background: #061c16;
}

@media (max-width: 640px) {
    .ofp-modal-meta-grid {
        grid-template-columns: 1fr;
    }
    .ofp-log-modal-datetime-bar {
        flex-direction: column;
        align-items: flex-start;
    }
    .ofp-log-modal-dialog {
        max-height: 95vh;
        border-radius: 12px;
    }
}
</style>

<!-- Modern JavaScript Handler for Activity Logs Popup Modal -->
<script>
var ofpCurrentLogData = null;

function ofpOpenLogModal(buttonElement) {
    var raw = buttonElement.getAttribute('data-log');
    if (!raw) return;

    try {
        var log = JSON.parse(raw);
        ofpCurrentLogData = log;
        ofpPopulateAndShowModal(log);
    } catch (e) {
        console.error('Error parsing activity log data:', e);
    }
}

function ofpPopulateAndShowModal(log) {
    // 1. Title & ID
    document.getElementById('ofp-modal-log-id').textContent = '#' + (log.id || '0');
    document.getElementById('ofp-modal-title').textContent = log.action || 'Activity Event';

    // 2. Category Icon Theme
    var iconBox = document.getElementById('ofp-modal-cat-icon');
    var theme = log.action_theme || 'default';
    var iconSvg = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    
    if (theme === 'success') {
        iconBox.style.background = 'rgba(16, 185, 129, 0.15)';
        iconBox.style.color = '#10b981';
        iconSvg = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    } else if (theme === 'danger') {
        iconBox.style.background = 'rgba(239, 68, 68, 0.15)';
        iconBox.style.color = '#ef4444';
        iconSvg = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
    } else if (theme === 'warning') {
        iconBox.style.background = 'rgba(245, 158, 11, 0.15)';
        iconBox.style.color = '#f59e0b';
        iconSvg = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>';
    } else if (theme === 'info') {
        iconBox.style.background = 'rgba(37, 99, 235, 0.15)';
        iconBox.style.color = '#2563eb';
        iconSvg = '<svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>';
    } else {
        iconBox.style.background = '#f1f5f9';
        iconBox.style.color = '#475569';
    }
    iconBox.innerHTML = iconSvg;

    // 3. Full Date & Time
    document.getElementById('ofp-modal-full-date').textContent = log.date_full || '—';
    document.getElementById('ofp-modal-exact-time').textContent = log.time_exact || '—';
    document.getElementById('ofp-modal-rel-time').textContent = log.time_rel || 'Recently';

    // 4. Actor Info
    var actorWrap = document.getElementById('ofp-modal-actor-wrap');
    actorWrap.innerHTML = '<span class="ofp-actor-pill" style="color:' + (log.actor_color || '#64748b') + '; background:' + (log.actor_bg || '#f1f5f9') + ';">' +
                          escapeHtml(log.actor_label || 'System') + '</span>';

    // 5. Client Info
    var clientWrap = document.getElementById('ofp-modal-client-wrap');
    var clientBtnWrap = document.getElementById('ofp-modal-client-btn-wrap');
    if (log.client_id && log.client_url) {
        clientWrap.innerHTML = '<a href="' + escapeHtml(log.client_url) + '" style="color:#10b981; font-weight:700; text-decoration:none;">' +
                               escapeHtml(log.client_name) + ' (ID #' + log.client_id + ')</a>';
        clientBtnWrap.innerHTML = '<a href="' + escapeHtml(log.client_url) + '" class="ofp-btn-modal-client">' +
                                  '<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>' +
                                  'Open Client Profile</a>';
    } else {
        clientWrap.innerHTML = '<span style="color:#64748b;">Global / Platform Action</span>';
        clientBtnWrap.innerHTML = '';
    }

    // 6. Complete Narrative / Message Formulation
    var msgContainer = document.getElementById('ofp-modal-complete-message');
    var details = log.details || {};
    var narrative = '';

    // If explicit message/reason/description exists
    if (details.message) {
        narrative = '<p style="margin:0 0 6px 0; font-weight:600;">' + escapeHtml(details.message) + '</p>';
    } else if (details.note) {
        narrative = '<p style="margin:0 0 6px 0; font-weight:600;">' + escapeHtml(details.note) + '</p>';
    } else if (details.reason) {
        narrative = '<p style="margin:0 0 6px 0;"><strong>Reason:</strong> ' + escapeHtml(details.reason) + '</p>';
    } else if (details.subject) {
        narrative = '<p style="margin:0 0 6px 0;"><strong>Subject:</strong> ' + escapeHtml(details.subject) + '</p>';
    }

    // Dynamic descriptive text based on action
    var extraNarrative = '';
    if (log.action.indexOf('Plan changed') !== -1) {
        var prevPlan = details.previous_plan || 'free';
        var newPlan = details.new_plan || details.plan || 'active';
        extraNarrative = '<div style="margin-top:6px; display:flex; align-items:center; gap:8px;">' +
                         'Plan updated: <span class="ofp-badge-global" style="text-transform:uppercase;">' + escapeHtml(prevPlan) + '</span> &rarr; ' +
                         '<span class="ofp-badge-global" style="background:rgba(16,185,129,0.12); color:#059669; text-transform:uppercase;">' + escapeHtml(newPlan) + '</span>' +
                         '</div>';
    } else if (log.action.indexOf('Payment') !== -1 || log.action.indexOf('paid') !== -1) {
        var amt = details.amount ? (typeof details.amount === 'number' ? '₦' + Number(details.amount).toLocaleString() : details.amount) : '';
        var ref = details.reference || details.transaction_id || '';
        extraNarrative = '<p style="margin:4px 0 0 0;">' +
                         (amt ? '<strong>Amount:</strong> ' + escapeHtml(amt) + ' &bull; ' : '') +
                         (ref ? '<strong>Ref:</strong> <code>' + escapeHtml(ref) + '</code>' : '') +
                         '</p>';
    } else if (log.action.indexOf('Email') !== -1 || log.action.indexOf('SMS') !== -1) {
        var recipient = details.to || details.recipient || details.email || '';
        if (recipient) {
            extraNarrative = '<p style="margin:4px 0 0 0;"><strong>Recipient:</strong> ' + escapeHtml(recipient) + '</p>';
        }
    } else if (log.action.indexOf('status changed') !== -1 || details.previous_status) {
        var prevSt = details.previous_status || 'unknown';
        var newSt = details.new_status || 'updated';
        extraNarrative = '<div style="margin-top:6px; display:flex; align-items:center; gap:8px;">' +
                         'Status Transition: <span class="ofp-badge-global">' + escapeHtml(prevSt) + '</span> &rarr; ' +
                         '<span class="ofp-badge-global" style="background:rgba(16,185,129,0.12); color:#059669;">' + escapeHtml(newSt) + '</span>' +
                         '</div>';
    }

    if (!narrative && !extraNarrative) {
        narrative = '<p style="margin:0;">The event <strong>"' + escapeHtml(log.action) + '"</strong> was logged successfully on ' +
                    escapeHtml(log.date_full) + ' at ' + escapeHtml(log.time_exact) + ' by ' + escapeHtml(log.actor_label) + '.</p>';
    }

    msgContainer.innerHTML = narrative + extraNarrative;

    // 7. Structured Key-Value Grid
    var structuredSection = document.getElementById('ofp-modal-structured-section');
    var structuredGrid = document.getElementById('ofp-modal-structured-grid');
    structuredGrid.innerHTML = '';
    
    // Keys already handled or internal
    var skipKeys = ['actor_type', 'actor_name', 'actor_id'];
    var paramCount = 0;

    for (var key in details) {
        if (!details.hasOwnProperty(key)) continue;
        if (skipKeys.indexOf(key) !== -1) continue;

        var val = details[key];
        var formattedVal = '';

        if (val === null || val === undefined) {
            formattedVal = '<em>null</em>';
        } else if (typeof val === 'boolean') {
            formattedVal = val ? '<span style="color:#10b981;">Yes</span>' : '<span style="color:#ef4444;">No</span>';
        } else if (typeof val === 'object') {
            formattedVal = '<code style="font-size:11px;">' + escapeHtml(JSON.stringify(val)) + '</code>';
        } else {
            // Currency formatting for amount
            if ((key === 'amount' || key === 'expected' || key === 'paid' || key === 'balance') && !isNaN(val)) {
                formattedVal = '₦' + Number(val).toLocaleString();
            } else {
                formattedVal = escapeHtml(String(val));
            }
        }

        // Friendly key label
        var friendlyKey = key.replace(/_/g, ' ');

        var tile = document.createElement('div');
        tile.className = 'ofp-param-tile';
        tile.innerHTML = '<div class="ofp-param-key">' + escapeHtml(friendlyKey) + '</div>' +
                         '<div class="ofp-param-value">' + formattedVal + '</div>';
        structuredGrid.appendChild(tile);
        paramCount++;
    }

    structuredSection.style.display = paramCount > 0 ? 'block' : 'none';

    // 8. Raw JSON Display
    var jsonCode = document.getElementById('ofp-modal-raw-json');
    var jsonPretty = JSON.stringify(details, null, 2);
    jsonCode.textContent = jsonPretty;

    // Reset raw toggle
    document.getElementById('ofp-raw-body').style.display = 'none';
    document.getElementById('ofp-raw-chevron').classList.remove('expanded');
    document.getElementById('ofp-copy-json-label').textContent = 'Copy JSON';

    // 9. Show Modal with animation & lock body scroll
    var overlay = document.getElementById('ofp-log-modal-overlay');
    overlay.classList.add('active');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function ofpCloseLogModal() {
    var overlay = document.getElementById('ofp-log-modal-overlay');
    overlay.classList.remove('active');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

function ofpCloseLogModalOnBackdrop(event) {
    if (event.target === document.getElementById('ofp-log-modal-overlay')) {
        ofpCloseLogModal();
    }
}

function ofpToggleRawJson() {
    var rawBody = document.getElementById('ofp-raw-body');
    var chevron = document.getElementById('ofp-raw-chevron');
    if (rawBody.style.display === 'none') {
        rawBody.style.display = 'block';
        chevron.classList.add('expanded');
    } else {
        rawBody.style.display = 'none';
        chevron.classList.remove('expanded');
    }
}

function ofpCopyRawJson(event) {
    event.stopPropagation();
    if (!ofpCurrentLogData) return;
    
    var jsonString = JSON.stringify(ofpCurrentLogData.details || {}, null, 2);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(jsonString).then(function() {
            var label = document.getElementById('ofp-copy-json-label');
            label.textContent = '✓ Copied!';
            setTimeout(function() {
                label.textContent = 'Copy JSON';
            }, 2000);
        });
    } else {
        // Fallback
        var ta = document.createElement('textarea');
        ta.value = jsonString;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        var label = document.getElementById('ofp-copy-json-label');
        label.textContent = '✓ Copied!';
        setTimeout(function() {
            label.textContent = 'Copy JSON';
        }, 2000);
    }
}

// Keyboard ESC to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
        var overlay = document.getElementById('ofp-log-modal-overlay');
        if (overlay && overlay.classList.contains('active')) {
            ofpCloseLogModal();
        }
    }
});

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    var div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}
</script>
