<?php
/**
 * Template: /reports
 * Client's interactive performance analytics & monthly comprehensive report archive.
 *
 * @package OFast_Pipeline
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

if ( ! OFP_Auth::has_permission( 'view_reports' ) ) {
    wp_die( 'You do not have permission to view reports.', 'Access Denied', [ 'response' => 403 ] );
}

global $wpdb;
$p = $wpdb->prefix;

// ── Handle token download ────────────────────────────────────────────────────
$token = sanitize_text_field( $_GET['token'] ?? '' );
if ( $token ) {
    $archive = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$p}ofp_archives
             WHERE download_token = %s
               AND client_id     = %d
               AND token_expires > NOW()
             LIMIT 1",
            $token, $client->id
        )
    );

    if ( $archive ) {
        $which = sanitize_key( $_GET['file'] ?? 'leads' );
        $file  = '';
        $upload_dir  = wp_upload_dir();
        $archive_dir = trailingslashit( $upload_dir['basedir'] ) . 'ofp-archives/' . $client->id . '/';

        $filename_map = [
            'leads'        => "leads_{$archive->period}.csv",
            'sales'        => "sales_installments_{$archive->period}.csv",
            'rent'         => "rent_roll_{$archive->period}.csv",
            'inspections'  => "inspections_{$archive->period}.csv",
            'transactions' => "transactions_{$archive->period}.csv",
            'comms'        => "communications_{$archive->period}.csv",
        ];

        // 1. Direct matched file in archive directory
        if ( isset( $filename_map[ $which ] ) && file_exists( $archive_dir . $filename_map[ $which ] ) ) {
            $file = $archive_dir . $filename_map[ $which ];
        }

        // 2. Search entries in file_path
        if ( ! $file && ! empty( $archive->file_path ) ) {
            $entries = explode( '|', $archive->file_path );
            foreach ( $entries as $entry ) {
                if ( strpos( $entry, $which ) !== false ) {
                    if ( file_exists( $entry ) ) {
                        $file = $entry;
                        break;
                    } elseif ( file_exists( $archive_dir . $entry ) ) {
                        $file = $archive_dir . $entry;
                        break;
                    }
                }
            }
            // 3. Backward compatibility index fallback (leads = 0, comms = 1)
            if ( ! $file ) {
                $idx = $which === 'comms' ? 1 : 0;
                $candidate = $entries[ $idx ] ?? '';
                if ( $candidate && file_exists( $candidate ) ) {
                    $file = $candidate;
                } elseif ( $candidate && file_exists( $archive_dir . $candidate ) ) {
                    $file = $archive_dir . $candidate;
                }
            }
        }

        if ( $file && file_exists( $file ) ) {
            header( 'Content-Type: text/csv; charset=UTF-8' );
            header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
            header( 'Content-Length: ' . filesize( $file ) );
            readfile( $file );
            exit;
        }
    }

    // Invalid or expired token.
    $token_error = true;
}

// Fetch archive list for this client
$archives = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$p}ofp_archives
         WHERE client_id = %d
         ORDER BY created_at DESC
         LIMIT 24",
        $client->id
    )
);

// Initial summary for default view (Leads for current year)
$current_year = (int) gmdate( 'Y' );
$initial_data = OFP_CSV::get_yearly_chart_data( (int) $client->id, 'leads', $current_year );
$initial_kpis = $initial_data['kpis'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Reports & Analytics', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <style>
        .ofp-archive-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-radius: 12px;
            background: var(--bg-lighter);
            border: 1px solid var(--border-color);
            transition: all 0.2s ease;
            flex-wrap: wrap;
            gap: 14px;
        }
        .ofp-archive-row:hover {
            border-color: var(--accent-blue);
        }
        .ofp-csv-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 11px;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            transition: all 0.15s ease;
        }
        .ofp-csv-badge:hover {
            background: var(--accent-blue);
            border-color: var(--accent-blue);
            color: #ffffff !important;
            transform: translateY(-1px);
        }
        .ofp-csv-badge-primary {
            background: rgba(59, 130, 246, 0.12);
            border-color: rgba(59, 130, 246, 0.35);
            color: var(--accent-blue);
        }
        .ofp-csv-badge-primary:hover {
            background: var(--accent-blue);
            color: #ffffff !important;
        }

        /* ── Strict Inline Filter Controls for Chart Header ── */
        .ofp-chart-card-header {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            margin-bottom: 20px !important;
            gap: 16px !important;
            flex-wrap: nowrap !important;
        }
        .ofp-chart-title-wrap {
            display: inline-flex !important;
            align-items: center !important;
            gap: 12px !important;
            flex-shrink: 0 !important;
            white-space: nowrap !important;
        }
        /* ── Platform Frosted Glass Custom Dropdown System ── */
        .ofp-chart-inline-filters {
            display: inline-flex !important;
            flex-direction: row !important;
            align-items: center !important;
            gap: 10px !important;
            flex-wrap: nowrap !important;
            flex-shrink: 0 !important;
            white-space: nowrap !important;
            margin: 0 !important;
        }

        .ofp-chart-inline-filters .ofp-custom-select-wrapper {
            position: relative !important;
            display: inline-block !important;
            width: auto !important;
            user-select: none !important;
            flex: 0 0 auto !important;
        }

        /* HIDE native select completely to prevent duplicate row */
        .ofp-chart-inline-filters select,
        .ofp-chart-inline-filters select.ofp-select,
        .ofp-chart-inline-filters .ofp-custom-select-wrapper select {
            display: none !important;
        }

        .ofp-chart-inline-filters .ofp-custom-select-wrapper:first-child,
        .ofp-chart-inline-filters .ofp-custom-select-wrapper:has(#ofp-report-metric) {
            min-width: 215px !important;
        }

        .ofp-chart-inline-filters .ofp-custom-select-wrapper:last-child,
        .ofp-chart-inline-filters .ofp-custom-select-wrapper:has(#ofp-report-year) {
            min-width: 95px !important;
        }

        /* Frosted Glass Dropdown Trigger (Button) */
        .ofp-chart-inline-filters .ofp-custom-select-trigger {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            height: 40px !important;
            padding: 0 16px !important;
            font-size: 13.5px !important;
            font-weight: 500 !important;
            color: var(--text-main, #f8fafc) !important;
            background: rgba(255, 255, 255, 0.05) !important;
            backdrop-filter: blur(20px) saturate(190%) !important;
            -webkit-backdrop-filter: blur(20px) saturate(190%) !important;
            border: 1px solid rgba(255, 255, 255, 0.14) !important;
            border-radius: 10px !important;
            box-shadow: 0 4px 16px 0 rgba(0, 0, 0, 0.15), inset 0 1px 0 0 rgba(255, 255, 255, 0.12) !important;
            cursor: pointer !important;
            white-space: nowrap !important;
            gap: 12px !important;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
            box-sizing: border-box !important;
        }

        .ofp-chart-inline-filters .ofp-custom-select-trigger:hover,
        .ofp-chart-inline-filters .ofp-custom-select-wrapper.open .ofp-custom-select-trigger {
            background: rgba(255, 255, 255, 0.1) !important;
            border-color: rgba(255, 255, 255, 0.28) !important;
            box-shadow: 0 8px 24px 0 rgba(0, 0, 0, 0.25), inset 0 1px 0 0 rgba(255, 255, 255, 0.2) !important;
        }

        /* Chevron indicator */
        .ofp-chart-inline-filters .ofp-custom-select-trigger::after {
            content: "" !important;
            width: 7px !important;
            height: 7px !important;
            border-right: 2px solid var(--text-muted, #94a3b8) !important;
            border-bottom: 2px solid var(--text-muted, #94a3b8) !important;
            transform: rotate(45deg) translateY(-2px) !important;
            transition: transform 0.2s ease !important;
            display: inline-block !important;
            flex-shrink: 0 !important;
            margin-left: 8px !important;
        }

        .ofp-chart-inline-filters .ofp-custom-select-wrapper.open .ofp-custom-select-trigger::after {
            transform: rotate(-135deg) translateY(-2px) !important;
        }

        /* Frosted Glass Floating Options Menu */
        .ofp-chart-inline-filters .ofp-custom-select-options {
            position: absolute !important;
            top: calc(100% + 6px) !important;
            left: 0 !important;
            right: auto !important;
            min-width: 100% !important;
            width: max-content !important;
            background: rgba(19, 23, 34, 0.88) !important;
            backdrop-filter: blur(24px) saturate(190%) !important;
            -webkit-backdrop-filter: blur(24px) saturate(190%) !important;
            border: 1px solid rgba(255, 255, 255, 0.14) !important;
            box-shadow: 0 20px 48px 0 rgba(0, 0, 0, 0.5), inset 0 1px 0 0 rgba(255, 255, 255, 0.18) !important;
            border-radius: 14px !important;
            z-index: 1050 !important;
            max-height: 280px !important;
            overflow-y: auto !important;
            padding: 6px !important;
            opacity: 0 !important;
            visibility: hidden !important;
            transform: translateY(-6px) scale(0.98) !important;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
            box-sizing: border-box !important;
        }

        .ofp-chart-inline-filters .ofp-custom-select-wrapper:last-child .ofp-custom-select-options {
            left: auto !important;
            right: 0 !important;
        }

        .ofp-chart-inline-filters .ofp-custom-select-wrapper.open {
            z-index: 100 !important;
        }

        .ofp-chart-inline-filters .ofp-custom-select-wrapper.open .ofp-custom-select-options {
            opacity: 1 !important;
            visibility: visible !important;
            transform: translateY(0) scale(1) !important;
        }

        .ofp-chart-inline-filters .ofp-custom-select-option {
            padding: 10px 14px !important;
            font-size: 13.5px !important;
            color: var(--text-main, #e2e8f0) !important;
            cursor: pointer !important;
            border-radius: 8px !important;
            transition: all 0.15s ease !important;
            white-space: nowrap !important;
        }

        .ofp-chart-inline-filters .ofp-custom-select-option:hover {
            background: rgba(59, 130, 246, 0.18) !important;
            color: #ffffff !important;
        }

        .ofp-chart-inline-filters .ofp-custom-select-option.selected {
            background: var(--accent-blue, #3b82f6) !important;
            color: #ffffff !important;
            font-weight: 600 !important;
        }

        /* Light Theme Frosted Glass System */
        [data-theme="light"] .ofp-chart-inline-filters .ofp-custom-select-trigger,
        html.light .ofp-chart-inline-filters .ofp-custom-select-trigger {
            background: rgba(255, 255, 255, 0.65) !important;
            border-color: rgba(255, 255, 255, 0.8) !important;
            color: #0f172a !important;
            box-shadow: 0 4px 16px 0 rgba(15, 23, 42, 0.05), inset 0 1px 1px 0 rgba(255, 255, 255, 0.95) !important;
        }

        [data-theme="light"] .ofp-chart-inline-filters .ofp-custom-select-trigger:hover,
        [data-theme="light"] .ofp-chart-inline-filters .ofp-custom-select-wrapper.open .ofp-custom-select-trigger,
        html.light .ofp-chart-inline-filters .ofp-custom-select-trigger:hover,
        html.light .ofp-chart-inline-filters .ofp-custom-select-wrapper.open .ofp-custom-select-trigger {
            background: rgba(255, 255, 255, 0.9) !important;
            border-color: #ffffff !important;
            box-shadow: 0 8px 24px 0 rgba(15, 23, 42, 0.08), inset 0 1px 1px 0 #ffffff !important;
        }

        [data-theme="light"] .ofp-chart-inline-filters .ofp-custom-select-options,
        html.light .ofp-chart-inline-filters .ofp-custom-select-options {
            background: rgba(255, 255, 255, 0.92) !important;
            border: 1px solid rgba(255, 255, 255, 0.9) !important;
            box-shadow: 0 20px 48px 0 rgba(15, 23, 42, 0.12), inset 0 1px 1px 0 #ffffff !important;
        }

        [data-theme="light"] .ofp-chart-inline-filters .ofp-custom-select-option,
        html.light .ofp-chart-inline-filters .ofp-custom-select-option {
            color: #334155 !important;
        }

        [data-theme="light"] .ofp-chart-inline-filters .ofp-custom-select-option:hover,
        html.light .ofp-chart-inline-filters .ofp-custom-select-option:hover {
            background: rgba(59, 130, 246, 0.1) !important;
            color: var(--accent-blue, #2563eb) !important;
        }

        @media (max-width: 768px) {
            .ofp-chart-card-header {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 12px !important;
            }
            .ofp-chart-inline-filters {
                display: flex !important;
                flex-direction: row !important;
                flex-wrap: nowrap !important;
                width: 100% !important;
            }
            .ofp-chart-inline-filters .ofp-custom-select-wrapper:first-child {
                flex: 1 1 auto !important;
                min-width: 0 !important;
            }
            .ofp-chart-inline-filters .ofp-custom-select-wrapper:last-child {
                flex: 0 0 auto !important;
                min-width: 85px !important;
            }
        }
    </style>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <?php if ( ! empty( $token_error ) ) : ?>
        <div class="ofp-alert ofp-alert-error" style="margin-bottom: 24px;">
            This download link has expired or is invalid. Please contact support or regenerate the report.
        </div>
    <?php endif; ?>

    <!-- ── Dynamic Reports KPI Grid ── -->
    <div class="ofp-zentra-kpi-grid" style="margin-bottom:24px;" id="ofp-report-kpis">
        <?php foreach ( $initial_kpis as $idx => $kpi ) : ?>
            <div class="ofp-kpi-card <?php echo $idx === 0 ? 'ofp-kpi-card-hero' : ''; ?>" id="ofp-kpi-card-<?php echo esc_attr( $idx ); ?>">
                <div class="ofp-kpi-title" id="ofp-kpi-title-<?php echo esc_attr( $idx ); ?>"><?php echo esc_html( $kpi['title'] ); ?></div>
                <div class="ofp-kpi-value" id="ofp-kpi-val-<?php echo esc_attr( $idx ); ?>"><?php echo esc_html( $kpi['value'] ); ?></div>
                <div class="ofp-kpi-badge badge-<?php echo esc_attr( $kpi['color'] ); ?>" id="ofp-kpi-badge-<?php echo esc_attr( $idx ); ?>">
                    <span><?php echo esc_html( $kpi['badge'] ); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Monthly Performance Bar Chart ── -->
    <div class="ofp-card" style="margin-bottom: 32px; display: flex; flex-direction: column;">
        <div class="ofp-chart-card-header" style="display: flex !important; justify-content: space-between !important; align-items: center !important; margin-bottom: 20px !important; gap: 16px !important; flex-wrap: nowrap !important;">
            <div class="ofp-chart-title-wrap" style="display: inline-flex !important; align-items: center !important; gap: 12px !important; flex-shrink: 0 !important; white-space: nowrap !important;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 600;" id="ofp-chart-heading">
                    Monthly Performance
                </h3>
                <div id="ofp-chart-loading" style="display: none; align-items: center; gap: 6px; font-size: 12px; color: var(--accent-blue); font-weight: 500;">
                    <svg style="animation: spin 1s linear infinite; width:15px; height:15px;" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" stroke-dasharray="31.415, 31.415" stroke-linecap="round"/>
                    </svg>
                    <span>Loading...</span>
                </div>
            </div>

            <!-- Filter Dropdowns: Metric Filter FIRST, Year Filter SECOND (Strictly Inline with Platform Frosted Glass System) -->
            <div class="ofp-chart-inline-filters">
                <select id="ofp-report-metric" class="ofp-select">
                    <option value="leads" selected>Leads &amp; Conversions</option>
                    <option value="sales">Property Sales &amp; Revenue</option>
                    <option value="rent">Rent &amp; Tenancies</option>
                    <option value="inspections">Inspections &amp; Tours</option>
                    <option value="comms_spend">SMS &amp; Voice Spend</option>
                    <option value="transactions">All Financial Transactions</option>
                </select>

                <select id="ofp-report-year" class="ofp-select">
                    <?php for ( $y = $current_year; $y >= $current_year - 3; $y-- ) : ?>
                        <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $y, $current_year ); ?>><?php echo esc_html( $y ); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>

        <div style="position: relative; height: 320px; width: 100%; margin-top: auto;">
            <canvas id="monthlyPerformanceChart"></canvas>
        </div>
    </div>

    <!-- ── Comprehensive Report Archive ── -->
    <div class="ofp-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
            <div>
                <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Report Archive</h3>
                <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--text-muted);">
                    Comprehensive monthly reports automatically generated and emailed on the 1st of each month.
                </p>
            </div>
        </div>

        <?php if ( empty( $archives ) ) : ?>
            <div class="ofp-empty" style="padding: 40px 20px; text-align: center;">
                <svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:var(--text-muted); margin-bottom:12px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <h3 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 600;">No reports yet</h3>
                <p style="margin: 0; color: var(--text-muted); font-size: 13px;">
                    Your first report will be generated automatically on the 1st of next month.
                </p>
            </div>
        <?php else : ?>
            <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 16px;">
                <?php foreach ( $archives as $archive ) : ?>
                    <?php
                    $is_valid   = $archive->token_expires && strtotime( $archive->token_expires ) > time();
                    $period_fmt = '';
                    if ( $archive->period ) {
                        $parts      = explode( '_', $archive->period );
                        $period_fmt = isset( $parts[0], $parts[1] )
                            ? gmdate( 'F', mktime( 0, 0, 0, (int) $parts[0], 1 ) ) . ' ' . $parts[1]
                            : $archive->period;
                    }
                    ?>
                    <div class="ofp-archive-row">
                        <!-- Left: Info -->
                        <div style="display: flex; gap: 14px; align-items: center; min-width: 220px;">
                            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(59, 130, 246, 0.1); color: var(--accent-blue); display: flex; align-items: center; justify-content: center;">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </div>
                            <div>
                                <div style="font-size: 15px; font-weight: 600; color: var(--text-main);">
                                    <?php echo esc_html( $period_fmt ?: $archive->period ); ?> Report
                                </div>
                                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                    Generated: <?php echo esc_html( gmdate( 'M j, Y', strtotime( $archive->created_at ) ) ); ?>
                                    <?php if ( $is_valid ) : ?>
                                        &bull; <span style="color: #10b981;">Expires <?php echo esc_html( gmdate( 'M j', strtotime( $archive->token_expires ) ) ); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right: CSV Download Badges -->
                        <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                            <?php if ( $is_valid ) : ?>
                                <a href="<?php echo esc_url( add_query_arg( [ 'token' => $archive->download_token, 'file' => 'leads' ], home_url( '/reports' ) ) ); ?>" class="ofp-csv-badge ofp-csv-badge-primary" title="Download Leads CSV">
                                    Leads CSV
                                </a>
                                <a href="<?php echo esc_url( add_query_arg( [ 'token' => $archive->download_token, 'file' => 'sales' ], home_url( '/reports' ) ) ); ?>" class="ofp-csv-badge" title="Download Property Sales & Installments CSV">
                                    Sales CSV
                                </a>
                                <a href="<?php echo esc_url( add_query_arg( [ 'token' => $archive->download_token, 'file' => 'rent' ], home_url( '/reports' ) ) ); ?>" class="ofp-csv-badge" title="Download Rent Roll & Tenant Leases CSV">
                                    Rent Roll CSV
                                </a>
                                <a href="<?php echo esc_url( add_query_arg( [ 'token' => $archive->download_token, 'file' => 'inspections' ], home_url( '/reports' ) ) ); ?>" class="ofp-csv-badge" title="Download Inspection Tour Bookings CSV">
                                    Inspections CSV
                                </a>
                                <a href="<?php echo esc_url( add_query_arg( [ 'token' => $archive->download_token, 'file' => 'transactions' ], home_url( '/reports' ) ) ); ?>" class="ofp-csv-badge" title="Download Unified Financial Transactions CSV">
                                    Transactions CSV
                                </a>
                                <a href="<?php echo esc_url( add_query_arg( [ 'token' => $archive->download_token, 'file' => 'comms' ], home_url( '/reports' ) ) ); ?>" class="ofp-csv-badge" title="Download Communications Log CSV">
                                    Comms CSV
                                </a>
                            <?php else : ?>
                                <span style="padding: 6px 12px; background: rgba(255,255,255,0.06); color: var(--text-muted); border-radius: 6px; font-size: 12px; font-weight: 500;">
                                    Link Expired
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <p style="font-size: 12px; color: var(--text-muted); font-style: italic; text-align: center; margin-top: 24px;">
        Reports are also emailed to <strong><?php echo esc_html( $client->email ); ?></strong> on the 1st of each month. Download links expire after 72 hours. Contact us if you need a report regenerated.
    </p>

</div>
</main>
</div><!-- .ofp-shell -->

<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.initOfpCustomSelects === 'function') {
        window.initOfpCustomSelects();
    }

    const canvas = document.getElementById('monthlyPerformanceChart');
    if (!canvas || typeof Chart === 'undefined' || typeof ofpClientData === 'undefined') {
        return;
    }

    let reportChart = null;
    let currentMetric = document.getElementById('ofp-report-metric').value || 'leads';
    let currentYear   = document.getElementById('ofp-report-year').value || '<?php echo esc_js( $current_year ); ?>';

    const palette = {
        leads:        { c1: 'rgba(59, 130, 246, 1)',  c1_bg: 'rgba(59, 130, 246, 0.45)', c2: 'rgba(16, 185, 129, 1)',  c2_bg: 'rgba(16, 185, 129, 0.45)' },
        sales:        { c1: 'rgba(139, 92, 246, 1)',  c1_bg: 'rgba(139, 92, 246, 0.45)', c2: 'rgba(16, 185, 129, 1)',  c2_bg: 'rgba(16, 185, 129, 0.45)' },
        rent:         { c1: 'rgba(14, 165, 233, 1)',  c1_bg: 'rgba(14, 165, 233, 0.45)', c2: 'rgba(245, 158, 11, 1)',  c2_bg: 'rgba(245, 158, 11, 0.45)' },
        inspections:  { c1: 'rgba(99, 102, 241, 1)',  c1_bg: 'rgba(99, 102, 241, 0.45)', c2: 'rgba(20, 184, 166, 1)',  c2_bg: 'rgba(20, 184, 166, 0.45)' },
        comms_spend:  { c1: 'rgba(236, 72, 153, 1)',  c1_bg: 'rgba(236, 72, 153, 0.45)', c2: 'rgba(244, 63, 94, 1)',   c2_bg: 'rgba(244, 63, 94, 0.45)' },
        transactions: { c1: 'rgba(16, 185, 129, 1)',  c1_bg: 'rgba(16, 185, 129, 0.45)', c2: 'rgba(59, 130, 246, 1)',  c2_bg: 'rgba(59, 130, 246, 0.45)' }
    };

    function formatNumber(val, isCurrency) {
        if (isCurrency) {
            return '₦' + Number(val || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        }
        return Number(val || 0).toLocaleString();
    }

    function updateKPIs(kpis) {
        if (!kpis || !kpis.length) return;
        kpis.forEach(function(kpi, idx) {
            const card  = document.getElementById('ofp-kpi-card-' + idx);
            const title = document.getElementById('ofp-kpi-title-' + idx);
            const val   = document.getElementById('ofp-kpi-val-' + idx);
            const badge = document.getElementById('ofp-kpi-badge-' + idx);
            if (card && title && val && badge) {
                title.textContent = kpi.title;
                val.textContent = kpi.value;
                badge.className = 'ofp-kpi-badge badge-' + (kpi.color || 'blue');
                badge.innerHTML = '<span>' + kpi.badge + '</span>';
            }
        });
    }

    function loadChart(metric, year) {
        const loading = document.getElementById('ofp-chart-loading');
        if (loading) loading.style.display = 'inline-flex';

        const body = new URLSearchParams();
        body.append('action', 'ofp_reports_chart_data');
        body.append('nonce', ofpClientData.nonce);
        body.append('metric', metric);
        body.append('year', year);

        fetch(ofpClientData.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (loading) loading.style.display = 'none';
                if (!res.success || !res.data) return;

                const data = res.data;
                const colors = palette[metric] || palette.leads;
                const isCurrency = data.format === 'currency';

                updateKPIs(data.kpis);

                const ctx2d = canvas.getContext('2d');
                const g1 = ctx2d.createLinearGradient(0, 0, 0, 300);
                g1.addColorStop(0, colors.c1);
                g1.addColorStop(1, colors.c1_bg);

                const g2 = ctx2d.createLinearGradient(0, 0, 0, 300);
                g2.addColorStop(0, colors.c2);
                g2.addColorStop(1, colors.c2_bg);

                const chartConfig = {
                    type: 'bar',
                    data: {
                        labels: data.labels || [],
                        datasets: [
                            {
                                label: data.series1.label,
                                data: data.series1.data,
                                backgroundColor: g1,
                                borderColor: colors.c1,
                                borderWidth: 1,
                                borderRadius: 6,
                                barPercentage: 0.6,
                                categoryPercentage: 0.75
                            },
                            {
                                label: data.series2.label,
                                data: data.series2.data,
                                backgroundColor: g2,
                                borderColor: colors.c2,
                                borderWidth: 1,
                                borderRadius: 6,
                                barPercentage: 0.6,
                                categoryPercentage: 0.75
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: {
                            duration: 350
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                align: 'end',
                                labels: {
                                    color: '#9ca3af',
                                    usePointStyle: true,
                                    boxWidth: 8,
                                    font: { size: 12, weight: '500' }
                                }
                            },
                            tooltip: {
                                backgroundColor: '#111827',
                                titleColor: '#f9fafb',
                                bodyColor: '#e5e7eb',
                                borderColor: 'rgba(255, 255, 255, 0.1)',
                                borderWidth: 1,
                                padding: 12,
                                cornerRadius: 8,
                                displayColors: true,
                                callbacks: {
                                    label: function(item) {
                                        const label = item.dataset.label || '';
                                        const val = item.raw || 0;
                                        const shouldCurrency = isCurrency || (metric === 'comms_spend' && item.datasetIndex === 1);
                                        return label + ': ' + formatNumber(val, shouldCurrency);
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false, drawBorder: false },
                                ticks: { color: '#9ca3af', font: { size: 12 } }
                            },
                            y: {
                                grid: { color: 'rgba(255, 255, 255, 0.05)', drawBorder: false },
                                ticks: {
                                    color: '#9ca3af',
                                    font: { size: 11 },
                                    padding: 8,
                                    callback: function(value) {
                                        if (isCurrency) {
                                            if (value >= 1000000) return '₦' + (value / 1000000).toFixed(1) + 'M';
                                            if (value >= 1000) return '₦' + (value / 1000).toFixed(0) + 'k';
                                            return '₦' + value;
                                        }
                                        return value;
                                    }
                                }
                            }
                        },
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        }
                    }
                };

                if (reportChart) {
                    reportChart.destroy();
                }
                reportChart = new Chart(canvas, chartConfig);
            })
            .catch(function(err) {
                if (loading) loading.style.display = 'none';
                console.error('OFP report chart fetch error:', err);
            });
    }

    // Metric dropdown change handler
    const metricSelect = document.getElementById('ofp-report-metric');
    if (metricSelect) {
        metricSelect.addEventListener('change', function() {
            currentMetric = this.value || 'leads';
            loadChart(currentMetric, currentYear);
        });
    }

    // Year select handler
    const yearSelect = document.getElementById('ofp-report-year');
    if (yearSelect) {
        yearSelect.addEventListener('change', function() {
            currentYear = this.value || '<?php echo esc_js( $current_year ); ?>';
            loadChart(currentMetric, currentYear);
        });
    }

    // Initial chart load
    loadChart(currentMetric, currentYear);
});
</script>

<?php wp_footer(); ?>
</body>
</html>
