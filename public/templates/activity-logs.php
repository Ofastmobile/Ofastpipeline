<?php
/**
 * Template: /activity-logs
 * Dedicated Client Portal Activity & Audit Log.
 * Real-time event log for account owner, team members, automation, and admin actions.
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

if ( $actor_filter === 'team' ) {
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
    <style>
    /* ── DEDICATED ACTIVITY LOG STYLES ───────────────────────────────── */
    .ofp-act-container {
        max-width: 1140px;
        margin: 0 auto;
        padding-bottom: 40px;
    }

    .ofp-act-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .ofp-act-title-box h1 {
        font-size: 24px;
        font-weight: 700;
        margin: 0 0 6px;
        color: var(--text-main, #0f172a);
        letter-spacing: -0.02em;
    }

    .ofp-act-title-box p {
        font-size: 13.5px;
        color: var(--text-muted, #64748b);
        margin: 0;
    }

    .ofp-act-count-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 14px;
        border-radius: 9999px;
        background: var(--bg-card, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        font-size: 13px;
        font-weight: 600;
        color: var(--text-main, #0f172a);
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }

    .ofp-act-count-pill .ofp-act-pulse {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #10b981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
    }

    /* Toolbar: Filters & Search */
    .ofp-act-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }

    .ofp-act-filters {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .ofp-act-filter-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 12.5px;
        font-weight: 500;
        color: var(--text-muted, #64748b);
        background: var(--bg-card, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        text-decoration: none;
        transition: all 0.15s ease;
    }

    .ofp-act-filter-btn:hover {
        color: var(--text-main, #0f172a);
        border-color: #cbd5e1;
        background: var(--bg-subtle, #f8fafc);
    }

    .ofp-act-filter-btn.active {
        background: var(--accent-blue, #2563eb);
        color: #ffffff;
        border-color: var(--accent-blue, #2563eb);
        font-weight: 600;
    }

    .ofp-act-filter-btn .dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
    }

    .ofp-act-search-form {
        margin: 0;
    }

    .ofp-act-search-box {
        position: relative;
        width: 260px;
        max-width: 100%;
    }

    .ofp-act-search-box svg {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted, #94a3b8);
        pointer-events: none;
        width: 14px;
        height: 14px;
    }

    .ofp-act-search-input {
        width: 100%;
        padding: 7px 28px 7px 30px;
        border-radius: 8px;
        font-size: 12.5px;
        border: 1px solid var(--border-color, #e2e8f0);
        background: var(--bg-card, #ffffff);
        color: var(--text-main, #0f172a);
        outline: none;
        box-sizing: border-box;
        transition: border-color 0.15s ease;
    }

    .ofp-act-search-input:focus {
        border-color: var(--accent-blue, #2563eb);
    }

    .ofp-act-search-clear {
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted, #94a3b8);
        text-decoration: none;
        font-size: 16px;
        line-height: 1;
        cursor: pointer;
    }

    /* Clean Compact Table Card */
    .ofp-act-card {
        background: var(--bg-card, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        overflow: hidden;
    }

    .ofp-act-table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .ofp-act-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        table-layout: fixed;
        font-size: 13px;
        line-height: 1.4;
    }

    .ofp-act-table th {
        background: var(--bg-subtle, #f8fafc);
        color: var(--text-muted, #64748b);
        font-weight: 600;
        font-size: 11.5px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 10px 14px;
        border-bottom: 1px solid var(--border-color, #e2e8f0);
        text-align: left;
        white-space: nowrap;
    }

    .ofp-act-table td {
        padding: 9px 14px;
        border-bottom: 1px solid var(--border-color, #f1f5f9);
        vertical-align: middle;
        color: var(--text-main, #0f172a);
    }

    .ofp-act-table tbody tr:last-child td {
        border-bottom: none;
    }

    .ofp-act-table tbody tr:hover {
        background: var(--bg-subtle, #f8fafc);
    }

    /* Column Widths (Tight & Proportional - No Excessive Space) */
    .ofp-act-table th.col-date, .ofp-act-table td.col-date {
        width: 22%;
    }
    .ofp-act-table th.col-action, .ofp-act-table td.col-action {
        width: 40%;
    }
    .ofp-act-table th.col-actor, .ofp-act-table td.col-actor {
        width: 24%;
    }
    .ofp-act-table th.col-details, .ofp-act-table td.col-details {
        width: 14%;
        text-align: right;
    }

    /* Timestamp cell */
    .ofp-act-date-main {
        font-weight: 600;
        color: var(--text-main, #0f172a);
        font-size: 12.5px;
        line-height: 1.2;
    }

    .ofp-act-date-sub {
        font-size: 11.5px;
        color: var(--text-muted, #64748b);
        display: flex;
        align-items: center;
        gap: 5px;
        margin-top: 2px;
    }

    .ofp-act-date-sub .dot-sep {
        font-size: 8px;
        opacity: 0.5;
    }

    /* Action pills */
    .ofp-act-pill {
        display: inline-block;
        max-width: 100%;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        line-height: 1.3;
        white-space: normal;
        word-break: break-word;
    }

    .ofp-act-pill-success {
        background: rgba(16, 185, 129, 0.1);
        color: #059669;
        border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .ofp-act-pill-info {
        background: rgba(37, 99, 235, 0.1);
        color: #2563eb;
        border: 1px solid rgba(37, 99, 235, 0.2);
    }

    .ofp-act-pill-warning {
        background: rgba(245, 158, 11, 0.1);
        color: #d97706;
        border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .ofp-act-pill-danger {
        background: rgba(239, 68, 68, 0.1);
        color: #dc2626;
        border: 1px solid rgba(239, 68, 68, 0.2);
    }

    .ofp-act-pill-default {
        background: rgba(100, 116, 139, 0.08);
        color: #475569;
        border: 1px solid rgba(100, 116, 139, 0.18);
    }

    /* Actor badge */
    .ofp-act-actor-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 500;
        line-height: 1.2;
        border: 1px solid transparent;
        max-width: 100%;
    }

    .ofp-act-actor-badge svg {
        flex-shrink: 0;
        width: 12px;
        height: 12px;
    }

    .ofp-act-actor-badge span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* View button */
    .ofp-act-btn-view {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 9px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        color: var(--accent-blue, #2563eb);
        background: rgba(37, 99, 235, 0.06);
        border: 1px solid rgba(37, 99, 235, 0.18);
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .ofp-act-btn-view:hover {
        background: var(--accent-blue, #2563eb);
        color: #ffffff;
        border-color: var(--accent-blue, #2563eb);
    }

    /* Empty state */
    .ofp-act-empty {
        padding: 48px 16px;
        text-align: center;
    }

    .ofp-act-empty svg {
        width: 36px;
        height: 36px;
        stroke: var(--text-muted, #94a3b8);
        margin-bottom: 12px;
    }

    .ofp-act-empty h4 {
        margin: 0 0 6px;
        font-size: 15px;
        font-weight: 600;
        color: var(--text-main, #0f172a);
    }

    .ofp-act-empty p {
        margin: 0;
        font-size: 13px;
        color: var(--text-muted, #64748b);
    }

    /* Pagination */
    .ofp-act-pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        border-top: 1px solid var(--border-color, #e2e8f0);
        background: var(--bg-subtle, #f8fafc);
        font-size: 12.5px;
        color: var(--text-muted, #64748b);
        flex-wrap: wrap;
        gap: 8px;
    }

    .ofp-act-page-links {
        display: flex;
        gap: 4px;
    }

    .ofp-act-page-btn {
        padding: 4px 10px;
        border-radius: 6px;
        border: 1px solid var(--border-color, #e2e8f0);
        background: var(--bg-card, #ffffff);
        color: var(--text-main, #0f172a);
        text-decoration: none;
        font-size: 12px;
        font-weight: 500;
    }

    .ofp-act-page-btn:hover {
        border-color: #cbd5e1;
    }

    .ofp-act-page-btn.active {
        background: var(--accent-blue, #2563eb);
        color: #ffffff;
        border-color: var(--accent-blue, #2563eb);
        font-weight: 600;
    }

    /* ── MODERN POPUP MODAL ─────────────────────────────────────────── */
    .ofp-act-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }

    .ofp-act-modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .ofp-act-modal-container {
        background: var(--bg-card, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 16px;
        width: 100%;
        max-width: 580px;
        max-height: 85vh;
        overflow-y: auto;
        box-shadow: 0 20px 40px -10px rgba(0,0,0,0.25);
        transform: scale(0.96) translateY(10px);
        transition: transform 0.2s ease;
        display: flex;
        flex-direction: column;
    }

    .ofp-act-modal-overlay.active .ofp-act-modal-container {
        transform: scale(1) translateY(0);
    }

    .ofp-act-modal-header {
        padding: 18px 22px;
        border-bottom: 1px solid var(--border-color, #e2e8f0);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .ofp-act-modal-title {
        font-size: 16px;
        font-weight: 700;
        margin: 0;
        color: var(--text-main, #0f172a);
    }

    .ofp-act-modal-close {
        background: none;
        border: none;
        font-size: 22px;
        line-height: 1;
        color: var(--text-muted, #94a3b8);
        cursor: pointer;
        padding: 4px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .ofp-act-modal-close:hover {
        color: var(--text-main, #0f172a);
        background: var(--bg-subtle, #f1f5f9);
    }

    .ofp-act-modal-body {
        padding: 22px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .ofp-act-box {
        background: var(--bg-subtle, #f8fafc);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 10px;
        padding: 14px 16px;
    }

    .ofp-act-box-label {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-muted, #64748b);
        margin-bottom: 6px;
    }

    .ofp-act-box-val {
        font-size: 14px;
        font-weight: 500;
        color: var(--text-main, #0f172a);
        line-height: 1.4;
    }

    .ofp-act-meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
    }

    .ofp-act-raw-wrap {
        margin-top: 8px;
    }

    .ofp-act-raw-toggle {
        font-size: 12px;
        font-weight: 500;
        color: var(--accent-blue, #2563eb);
        background: none;
        border: none;
        padding: 0;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .ofp-act-raw-code {
        margin-top: 8px;
        background: #0f172a;
        color: #e2e8f0;
        padding: 12px;
        border-radius: 8px;
        font-family: monospace;
        font-size: 11.5px;
        line-height: 1.4;
        max-height: 180px;
        overflow: auto;
        white-space: pre-wrap;
        word-break: break-all;
        display: none;
    }

    .ofp-act-raw-code.show {
        display: block;
    }

    .ofp-act-modal-footer {
        padding: 14px 22px;
        border-top: 1px solid var(--border-color, #e2e8f0);
        display: flex;
        justify-content: flex-end;
        background: var(--bg-subtle, #f8fafc);
        border-radius: 0 0 16px 16px;
    }

    .ofp-act-btn-dismiss {
        padding: 7px 16px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        background: var(--bg-card, #ffffff);
        border: 1px solid var(--border-color, #cbd5e1);
        color: var(--text-main, #0f172a);
        cursor: pointer;
    }

    .ofp-act-btn-dismiss:hover {
        background: var(--bg-subtle, #f1f5f9);
    }
    </style>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container ofp-act-container">

    <!-- Page Header -->
    <div class="ofp-act-header">
        <div class="ofp-act-title-box">
            <h1>Activity & Team Audit Log</h1>
            <p>Chronological history of account activity, team actions, automated processes, and security updates.</p>
        </div>
        <div class="ofp-act-count-pill">
            <span class="ofp-act-pulse"></span>
            <span><?php echo number_format( $total_logs ); ?> Recorded Events</span>
        </div>
    </div>

    <!-- Toolbar: Filters & Search -->
    <div class="ofp-act-toolbar">
        <div class="ofp-act-filters">
            <a href="<?php echo esc_url( remove_query_arg( [ 'actor', 'paged' ] ) ); ?>" class="ofp-act-filter-btn <?php echo $actor_filter === '' ? 'active' : ''; ?>">
                All Events
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'actor', 'team', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-act-filter-btn <?php echo $actor_filter === 'team' ? 'active' : ''; ?>">
                <span class="dot" style="background:#f59e0b;"></span>
                Team Members
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'actor', 'client', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-act-filter-btn <?php echo $actor_filter === 'client' ? 'active' : ''; ?>">
                <span class="dot" style="background:#2563eb;"></span>
                My Actions
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'actor', 'system', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-act-filter-btn <?php echo $actor_filter === 'system' ? 'active' : ''; ?>">
                <span class="dot" style="background:#64748b;"></span>
                Automated / System
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'actor', 'admin', remove_query_arg( 'paged' ) ) ); ?>" class="ofp-act-filter-btn <?php echo $actor_filter === 'admin' ? 'active' : ''; ?>">
                <span class="dot" style="background:#7c3aed;"></span>
                Support / Admin
            </a>
        </div>

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

                            <!-- Action / Event -->
                            <td class="col-action">
                                <span class="ofp-act-pill <?php echo esc_attr( $act_class ); ?>">
                                    <?php echo esc_html( $log->action ); ?>
                                </span>
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
                                <button type="button" class="ofp-act-btn-view" data-log="<?php echo esc_attr( wp_json_encode( $payload ) ); ?>" onclick="ofpOpenClientLogModal(this)">
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

<!-- ── MODERN POPUP EVENT MODAL ─────────────────────────────────────── -->
<div class="ofp-act-modal-overlay" id="ofpActModalOverlay" onclick="ofpHandleModalBackdropClick(event)">
    <div class="ofp-act-modal-container" role="dialog" aria-modal="true" aria-labelledby="ofpModalTitle">
        <div class="ofp-act-modal-header">
            <h3 class="ofp-act-modal-title" id="ofpModalTitle">Event Details</h3>
            <button type="button" class="ofp-act-modal-close" onclick="ofpCloseClientLogModal()" aria-label="Close dialog">&times;</button>
        </div>
        <div class="ofp-act-modal-body">
            <!-- Event Action Pill -->
            <div>
                <span id="ofpModalActionPill" class="ofp-act-pill ofp-act-pill-default" style="font-size:13px; font-weight:600; padding:5px 12px;"></span>
            </div>

            <!-- Date & Time Full Card -->
            <div class="ofp-act-box">
                <div class="ofp-act-box-label">Date & Exact Time</div>
                <div class="ofp-act-box-val" id="ofpModalTimestamp" style="display:flex; flex-direction:column; gap:2px;">
                    <strong id="ofpModalDateFull" style="font-size:14px;"></strong>
                    <span style="font-size:12.5px; color:var(--text-muted,#64748b);">
                        <span id="ofpModalTimeExact"></span> • <span id="ofpModalTimeRel" style="font-weight:600;"></span>
                    </span>
                </div>
            </div>

            <!-- Actor & Source Card -->
            <div class="ofp-act-box">
                <div class="ofp-act-box-label">Initiated By</div>
                <div class="ofp-act-box-val" id="ofpModalActor"></div>
            </div>

            <!-- Full Message Description -->
            <div class="ofp-act-box">
                <div class="ofp-act-box-label">Event Narrative / Message</div>
                <div class="ofp-act-box-val" id="ofpModalNarrative" style="white-space:pre-wrap; line-height:1.5;"></div>
            </div>

            <!-- Structured Parameters Container -->
            <div id="ofpModalParamsContainer" style="display:none;">
                <div class="ofp-act-box-label" style="margin-bottom:8px;">Structured Parameters</div>
                <div class="ofp-act-meta-grid" id="ofpModalParamsGrid"></div>
            </div>

            <!-- Raw Data Accordion -->
            <div class="ofp-act-raw-wrap" id="ofpModalRawWrap">
                <button type="button" class="ofp-act-raw-toggle" onclick="ofpToggleRawLogData()">
                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                    <span>View Technical Details (JSON)</span>
                </button>
                <pre class="ofp-act-raw-code" id="ofpModalRawCode"></pre>
            </div>
        </div>
        <div class="ofp-act-modal-footer">
            <button type="button" class="ofp-act-btn-dismiss" onclick="ofpCloseClientLogModal()">Close</button>
        </div>
    </div>
</div>

<script>
function ofpOpenClientLogModal(btn) {
    try {
        var raw = btn.getAttribute('data-log');
        if (!raw) return;
        var data = JSON.parse(raw);

        var overlay = document.getElementById('ofpActModalOverlay');
        var pill = document.getElementById('ofpModalActionPill');
        var dateFull = document.getElementById('ofpModalDateFull');
        var timeExact = document.getElementById('ofpModalTimeExact');
        var timeRel = document.getElementById('ofpModalTimeRel');
        var actorBox = document.getElementById('ofpModalActor');
        var narrative = document.getElementById('ofpModalNarrative');
        var paramsWrap = document.getElementById('ofpModalParamsContainer');
        var paramsGrid = document.getElementById('ofpModalParamsGrid');
        var rawCode = document.getElementById('ofpModalRawCode');

        // Set action pill
        pill.className = 'ofp-act-pill ' + (data.act_class || 'ofp-act-pill-default');
        pill.textContent = data.action || 'Event';

        // Set date & time
        dateFull.textContent = data.date_full || '';
        timeExact.textContent = data.time_exact || '';
        timeRel.textContent = data.time_rel || '';

        // Set actor
        actorBox.innerHTML = '<span class="ofp-act-actor-badge" style="color:' + data.actor_color + '; background:' + data.actor_bg + '; font-size:13px; padding:4px 10px;">' + ofpEscapeHtml(data.actor_label) + '</span>';

        // Set description
        narrative.textContent = data.description || data.action || 'No additional message details.';

        // Structured parameters
        var details = data.details || {};
        var skipKeys = ['actor_type', 'actor_name', 'actor_id', 'message', 'description'];
        var paramHtml = '';

        for (var key in details) {
            if (!details.hasOwnProperty(key)) continue;
            if (skipKeys.indexOf(key) !== -1) continue;

            var val = details[key];
            var displayVal = '';

            if (typeof val === 'object' && val !== null) {
                displayVal = JSON.stringify(val);
            } else if (typeof val === 'number') {
                if (key.indexOf('amount') !== -1 || key.indexOf('price') !== -1 || key.indexOf('cost') !== -1) {
                    displayVal = '₦' + Number(val).toLocaleString();
                } else {
                    displayVal = String(val);
                }
            } else {
                displayVal = String(val);
            }

            var cleanLabel = key.replace(/_/g, ' ').replace(/\b\w/g, function(l){ return l.toUpperCase(); });

            paramHtml += '<div class="ofp-act-box" style="padding:10px 12px;">' +
                         '<div class="ofp-act-box-label" style="font-size:10px;">' + ofpEscapeHtml(cleanLabel) + '</div>' +
                         '<div class="ofp-act-box-val" style="font-size:13px; font-weight:600;">' + ofpEscapeHtml(displayVal) + '</div>' +
                         '</div>';
        }

        if (paramHtml !== '') {
            paramsGrid.innerHTML = paramHtml;
            paramsWrap.style.display = 'block';
        } else {
            paramsGrid.innerHTML = '';
            paramsWrap.style.display = 'none';
        }

        // Raw code
        rawCode.textContent = JSON.stringify(details, null, 2);
        rawCode.classList.remove('show');

        // Show modal
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    } catch (e) {
        console.error('Error opening activity log modal:', e);
    }
}

function ofpCloseClientLogModal() {
    var overlay = document.getElementById('ofpActModalOverlay');
    if (overlay) {
        overlay.classList.remove('active');
    }
    document.body.style.overflow = '';
}

function ofpHandleModalBackdropClick(e) {
    if (e.target && e.target.id === 'ofpActModalOverlay') {
        ofpCloseClientLogModal();
    }
}

function ofpToggleRawLogData() {
    var code = document.getElementById('ofpModalRawCode');
    if (code) {
        code.classList.toggle('show');
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
        ofpCloseClientLogModal();
    }
});

function ofpEscapeHtml(str) {
    if (str === null || str === undefined) return '';
    var d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}
</script>

</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
