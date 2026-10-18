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
    <style>
    /* ── DEDICATED ACTIVITY LOG STYLES ───────────────────────────────── */
    .ofp-act-container {
        max-width: 1060px;
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
        border-color: var(--border-highlight, #cbd5e1);
        background: var(--bg-card-hover, #f1f5f9) !important;
    }

    .ofp-act-dropdown-wrap {
        position: relative;
        display: inline-block;
        z-index: 1000;
    }

    .ofp-act-dropdown-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 14px;
        border-radius: 8px;
        font-size: 12.5px;
        font-weight: 500;
        border: 1px solid var(--border-color, #e2e8f0);
        background: var(--bg-card, #ffffff);
        color: var(--text-main, #0f172a);
        cursor: pointer;
        transition: all 0.15s ease;
        font-family: inherit;
    }

    .ofp-act-dropdown-btn:hover {
        background: var(--bg-card-hover, #f1f5f9);
        border-color: var(--border-highlight, #cbd5e1);
    }

    .ofp-act-dropdown-menu {
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        z-index: 10000;
        min-width: 200px;
        background: var(--bg-card, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 10px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        padding: 6px;
        display: none;
    }

    .ofp-act-dropdown-menu.active {
        display: block;
    }

    .ofp-act-dropdown-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        font-size: 12.5px;
        font-weight: 500;
        color: var(--text-main, #0f172a);
        text-decoration: none;
        border-radius: 6px;
        transition: background 0.15s ease;
    }

    .ofp-act-dropdown-item:hover {
        background: var(--bg-card-hover, #f1f5f9);
    }

    .ofp-act-dropdown-item.active {
        background: var(--accent-blue, #2563eb);
        color: #ffffff !important;
        font-weight: 600;
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
        table-layout: auto;
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
        padding: 10px 14px;
        border-bottom: 1px solid var(--border-color, #f1f5f9);
        vertical-align: middle;
        color: var(--text-main, #0f172a);
    }

    .ofp-act-table tbody tr:last-child td {
        border-bottom: none;
    }

    .ofp-act-table tbody tr:hover td,
    .ofp-act-table tbody tr:hover {
        background: var(--bg-card-hover, #f1f5f9) !important;
    }

    [data-theme="dark"] .ofp-act-table tbody tr:hover td,
    [data-theme="dark"] .ofp-act-table tbody tr:hover,
    html.dark .ofp-act-table tbody tr:hover td,
    html.dark .ofp-act-table tbody tr:hover {
        background: var(--bg-card-hover, #252e42) !important;
    }

    [data-theme="dark"] .ofp-act-table th,
    html.dark .ofp-act-table th {
        background: var(--bg-card, #1e293b) !important;
        border-bottom-color: var(--border-color, #334155) !important;
    }

    [data-theme="dark"] .ofp-act-card,
    html.dark .ofp-act-card {
        background: var(--bg-card, #1e293b) !important;
        border-color: var(--border-color, #334155) !important;
    }

    /* Column Widths (Snug & balanced to eliminate wide empty void) */
    .ofp-act-table th.col-date, .ofp-act-table td.col-date {
        width: 22%;
        white-space: nowrap;
    }
    .ofp-act-table th.col-action, .ofp-act-table td.col-action {
        width: 36%;
    }
    .ofp-act-table th.col-actor, .ofp-act-table td.col-actor {
        width: 28%;
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

    /* Action cell wrapper & pills */
    .ofp-act-action-cell {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: nowrap;
    }

    .ofp-act-pill {
        display: inline-block;
        flex-shrink: 0;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        line-height: 1.3;
        white-space: nowrap;
    }

    .ofp-act-snippet {
        font-size: 12px;
        color: var(--text-muted, #64748b);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 220px;
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

    /* ── GENERAL EVENT MODAL (NON-EMAIL EVENTS) ──────────────────────── */
    .ofp-act-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.65);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
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
        background: var(--bg-card, #ffffff);
        border-radius: 16px 16px 0 0;
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
        transition: all 0.15s ease;
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
        background: var(--bg-card, #ffffff);
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
        transition: all 0.15s ease;
    }

    .ofp-act-btn-dismiss:hover {
        background: var(--bg-subtle, #f1f5f9);
    }

    /* Dark mode overrides for General Event Modal Popup */
    [data-theme="dark"] .ofp-act-modal-container,
    html.dark .ofp-act-modal-container {
        background: #181f2f !important;
        border-color: rgba(255, 255, 255, 0.12) !important;
        box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.75), 0 0 0 1px rgba(255, 255, 255, 0.08) !important;
    }

    [data-theme="dark"] .ofp-act-modal-header,
    html.dark .ofp-act-modal-header {
        background: #131722 !important;
        border-bottom-color: rgba(255, 255, 255, 0.08) !important;
    }

    [data-theme="dark"] .ofp-act-modal-title,
    html.dark .ofp-act-modal-title {
        color: #f8fafc !important;
    }

    [data-theme="dark"] .ofp-act-modal-close,
    html.dark .ofp-act-modal-close {
        color: #94a3b8 !important;
    }

    [data-theme="dark"] .ofp-act-modal-close:hover,
    html.dark .ofp-act-modal-close:hover {
        color: #ffffff !important;
        background: rgba(255, 255, 255, 0.08) !important;
    }

    [data-theme="dark"] .ofp-act-modal-body,
    html.dark .ofp-act-modal-body {
        background: #181f2f !important;
    }

    [data-theme="dark"] .ofp-act-box,
    html.dark .ofp-act-box {
        background: #131826 !important;
        border-color: rgba(255, 255, 255, 0.08) !important;
    }

    [data-theme="dark"] .ofp-act-box-label,
    html.dark .ofp-act-box-label {
        color: #94a3b8 !important;
    }

    [data-theme="dark"] .ofp-act-box-val,
    html.dark .ofp-act-box-val {
        color: #f8fafc !important;
    }

    [data-theme="dark"] .ofp-act-modal-footer,
    html.dark .ofp-act-modal-footer {
        background: #131722 !important;
        border-top-color: rgba(255, 255, 255, 0.08) !important;
    }

    [data-theme="dark"] .ofp-act-btn-dismiss,
    html.dark .ofp-act-btn-dismiss {
        background: rgba(255, 255, 255, 0.08) !important;
        border-color: rgba(255, 255, 255, 0.16) !important;
        color: #f8fafc !important;
    }

    [data-theme="dark"] .ofp-act-btn-dismiss:hover,
    html.dark .ofp-act-btn-dismiss:hover {
        background: rgba(255, 255, 255, 0.16) !important;
        border-color: rgba(255, 255, 255, 0.26) !important;
        color: #ffffff !important;
    }

    [data-theme="dark"] .ofp-act-count-pill,
    html.dark .ofp-act-count-pill {
        background: var(--bg-card, #1e2638) !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
        color: var(--text-main, #f8fafc) !important;
    }

    /* ── SLIDE-OVER EMAIL DRAWER WITH TEMPLATE PREVIEW & RESEND ───────── */
    .ofp-email-drawer-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        z-index: 999998;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.25s ease, visibility 0.25s ease;
    }

    .ofp-email-drawer-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .ofp-email-drawer {
        position: fixed;
        top: 0;
        right: -720px;
        width: 680px;
        max-width: 95vw;
        height: 100vh;
        background: var(--bg-card, #ffffff);
        box-shadow: -12px 0 35px rgba(0, 0, 0, 0.2);
        z-index: 999999;
        display: flex;
        flex-direction: column;
        transition: right 0.32s cubic-bezier(0.16, 1, 0.3, 1);
        border-left: 1px solid var(--border-color, #e2e8f0);
    }

    .ofp-email-drawer.active {
        right: 0;
    }

    .ofp-email-drawer-header {
        padding: 16px 22px;
        border-bottom: 1px solid var(--border-color, #e2e8f0);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: var(--bg-subtle, #f8fafc);
    }

    .ofp-email-drawer-header-left {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .ofp-email-drawer-title {
        font-size: 16px;
        font-weight: 700;
        margin: 0;
        color: var(--text-main, #0f172a);
    }

    .ofp-email-drawer-close {
        background: none;
        border: none;
        font-size: 24px;
        line-height: 1;
        color: var(--text-muted, #94a3b8);
        cursor: pointer;
        padding: 4px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .ofp-email-drawer-close:hover {
        color: var(--text-main, #0f172a);
        background: var(--bg-card, #ffffff);
    }

    .ofp-email-drawer-meta {
        padding: 14px 22px;
        background: var(--bg-subtle, #f8fafc);
        border-bottom: 1px solid var(--border-color, #e2e8f0);
        display: flex;
        flex-direction: column;
        gap: 6px;
        font-size: 13px;
    }

    .ofp-email-drawer-meta-row {
        display: flex;
        align-items: baseline;
        gap: 8px;
    }

    .ofp-email-drawer-meta-label {
        font-weight: 600;
        color: var(--text-muted, #64748b);
        min-width: 65px;
        font-size: 11.5px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .ofp-email-drawer-meta-val {
        color: var(--text-main, #0f172a);
        font-weight: 500;
        word-break: break-all;
    }

    .ofp-email-drawer-body {
        flex: 1;
        padding: 16px 22px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 12px;
        background: #f1f5f9;
    }

    .ofp-email-iframe-wrap {
        width: 100%;
        flex: 1;
        min-height: 480px;
        background: #ffffff;
        border-radius: 10px;
        border: 1px solid var(--border-color, #cbd5e1);
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }

    .ofp-email-iframe {
        width: 100%;
        height: 100%;
        min-height: 480px;
        border: none;
        display: block;
    }

    .ofp-email-drawer-footer {
        padding: 14px 22px;
        border-top: 1px solid var(--border-color, #e2e8f0);
        background: var(--bg-card, #ffffff);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .ofp-email-resend-group {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .ofp-act-btn-resend {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        background: var(--accent-blue, #2563eb);
        color: #ffffff;
        border: 1px solid var(--accent-blue, #2563eb);
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .ofp-act-btn-resend:hover {
        background: #1d4ed8;
    }

    .ofp-act-btn-resend:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .ofp-resend-status {
        font-size: 12.5px;
        font-weight: 500;
    }
    </style>
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

<script>
var ofpAjaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
var ofpAjaxNonce = '<?php echo esc_js( wp_create_nonce( 'ofp_client_ajax' ) ); ?>';
var currentDrawerLogId = null;
var currentDrawerRecipient = '';

function ofpHandleViewLog(btn) {
    var isEmail = btn.getAttribute('data-is-email') === '1';
    if (isEmail) {
        ofpOpenEmailSlideDrawer(btn);
    } else {
        ofpOpenClientLogModal(btn);
    }
}

function ofpOpenEmailSlideDrawer(btn) {
    try {
        var raw = btn.getAttribute('data-log');
        if (!raw) return;
        var data = JSON.parse(raw);
        var details = data.details || {};

        currentDrawerLogId = data.id;
        currentDrawerRecipient = details.to || details.recipient || '';

        var drawer = document.getElementById('ofpEmailDrawer');
        var overlay = document.getElementById('ofpEmailDrawerOverlay');
        var toEl = document.getElementById('ofpDrawerTo');
        var subjEl = document.getElementById('ofpDrawerSubject');
        var dateEl = document.getElementById('ofpDrawerDate');
        var actorEl = document.getElementById('ofpDrawerActor');
        var statusBadge = document.getElementById('ofpDrawerStatusBadge');
        var iframe = document.getElementById('ofpEmailIframe');
        var resendStatus = document.getElementById('ofpResendStatus');
        var resendBtn = document.getElementById('ofpBtnResend');

        resendBtn.disabled = false;
        document.getElementById('ofpBtnResendText').textContent = 'Resend Email';
        resendStatus.textContent = '';
        resendStatus.style.color = '';

        toEl.textContent = currentDrawerRecipient || '—';
        subjEl.textContent = details.subject || data.action || 'No Subject';
        dateEl.textContent = (data.date_full || '') + ' at ' + (data.time_exact || '') + ' (' + (data.time_rel || '') + ')';
        actorEl.textContent = data.actor_label || 'Account Owner';

        statusBadge.className = 'ofp-act-pill ' + (data.act_class || 'ofp-act-pill-default');
        statusBadge.textContent = data.action || 'Email sent';

        // Placeholder while loading
        iframe.srcdoc = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;color:#64748b;">Loading email template preview...</div>';

        // Fetch rendered email with template via AJAX
        var fd = new FormData();
        fd.append('action', 'ofp_get_email_preview');
        fd.append('nonce', ofpAjaxNonce);
        fd.append('log_id', data.id);

        fetch(ofpAjaxUrl, {
            method: 'POST',
            body: fd
        })
        .then(function(res) { return res.json(); })
        .then(function(resp) {
            if (resp.success && resp.data && resp.data.html) {
                iframe.srcdoc = resp.data.html;
            } else {
                var fallbackMsg = details.message || details.body || data.description || 'Email was dispatched via SMTP.';
                iframe.srcdoc = '<div style="padding:28px;font-family:sans-serif;color:#334155;line-height:1.6;">' +
                                '<h3 style="margin-top:0;color:#0f172a;">' + ofpEscapeHtml(details.subject || data.action) + '</h3>' +
                                '<p style="white-space:pre-wrap;">' + ofpEscapeHtml(fallbackMsg) + '</p>' +
                                '</div>';
            }
        })
        .catch(function(err) {
            console.error('Failed to load email preview:', err);
            iframe.srcdoc = '<div style="padding:28px;font-family:sans-serif;color:#334155;"><p>' + ofpEscapeHtml(details.message || data.description || 'Email delivered via SMTP.') + '</p></div>';
        });

        // Open slide-over drawer
        overlay.classList.add('active');
        drawer.classList.add('active');
        document.body.style.overflow = 'hidden';
    } catch (e) {
        console.error('Error opening email drawer:', e);
    }
}

function ofpCloseEmailDrawer() {
    var drawer = document.getElementById('ofpEmailDrawer');
    var overlay = document.getElementById('ofpEmailDrawerOverlay');
    if (drawer) drawer.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
}

function ofpTriggerResendEmail() {
    if (!currentDrawerLogId) return;

    var btn = document.getElementById('ofpBtnResend');
    var btnText = document.getElementById('ofpBtnResendText');
    var status = document.getElementById('ofpResendStatus');

    btn.disabled = true;
    btnText.textContent = 'Resending...';
    status.style.color = 'var(--text-muted, #64748b)';
    status.textContent = 'Connecting to SMTP server...';

    var fd = new FormData();
    fd.append('action', 'ofp_resend_activity_email');
    fd.append('nonce', ofpAjaxNonce);
    fd.append('log_id', currentDrawerLogId);

    fetch(ofpAjaxUrl, {
        method: 'POST',
        body: fd
    })
    .then(function(res) { return res.json(); })
    .then(function(resp) {
        if (resp.success) {
            btnText.textContent = 'Resent Successfully!';
            status.style.color = '#059669';
            status.textContent = '✅ ' + (resp.data.message || 'Email successfully resent via SMTP.');
            setTimeout(function() {
                btn.disabled = false;
                btnText.textContent = 'Resend Again';
            }, 3500);
        } else {
            btn.disabled = false;
            btnText.textContent = 'Resend Email';
            status.style.color = '#dc2626';
            status.textContent = '❌ ' + (resp.data || 'Failed to resend email.');
        }
    })
    .catch(function(err) {
        btn.disabled = false;
        btnText.textContent = 'Resend Email';
        status.style.color = '#dc2626';
        status.textContent = '❌ Network error during resend.';
    });
}

function ofpOpenClientLogModal(btn) {
    try {
        var raw = btn.getAttribute('data-log');
        if (!raw) return;
        var data = JSON.parse(raw);

        var overlay = document.getElementById('ofpActModalOverlay');
        var pill = document.getElementById('ofpModalActionPill');
        var relBadge = document.getElementById('ofpModalTimeRelBadge');
        var dateFull = document.getElementById('ofpModalDateFull');
        var timeExact = document.getElementById('ofpModalTimeExact');
        var actorBox = document.getElementById('ofpModalActor');
        var narrative = document.getElementById('ofpModalNarrative');
        var paramsWrap = document.getElementById('ofpModalParamsContainer');
        var paramsGrid = document.getElementById('ofpModalParamsGrid');

        // Set action pill & time
        pill.className = 'ofp-act-pill ' + (data.act_class || 'ofp-act-pill-default');
        pill.textContent = data.action || 'Event';
        relBadge.textContent = data.time_rel || '';

        // Set date & time
        dateFull.textContent = data.date_full || '';
        timeExact.textContent = data.time_exact || '';

        // Set actor
        actorBox.innerHTML = '<span class="ofp-act-actor-badge" style="color:' + data.actor_color + '; background:' + data.actor_bg + '; font-size:13px; padding:4px 10px;">' + ofpEscapeHtml(data.actor_label) + '</span>';

        narrative.textContent = data.description || data.action || 'No additional details.';

        // Structured parameters
        var details = data.details || {};
        var skipKeys = ['actor_type', 'actor_name', 'actor_id', 'message', 'description', 'body', 'content'];
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
                    displayVal = '₦' + Number(val).toLocaleString(undefined, { minimumFractionDigits: 2 });
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

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
        ofpCloseClientLogModal();
        ofpCloseEmailDrawer();
    }
});

function ofpEscapeHtml(str) {
    if (str === null || str === undefined) return '';
    var d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}

function ofpToggleActFilterDropdown() {
    var menu = document.getElementById('ofpActFilterMenu');
    if (menu) {
        menu.classList.toggle('active');
    }
}

document.addEventListener('click', function(e) {
    var wrap = document.getElementById('ofpActDropdownWrap');
    var menu = document.getElementById('ofpActFilterMenu');
    if (wrap && menu && !wrap.contains(e.target)) {
        menu.classList.remove('active');
    }
});
</script>

</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
