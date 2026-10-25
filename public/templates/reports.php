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
        <div class="ofp-chart-card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 16px; flex-wrap: wrap;">
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

<script>
    window.ofpReportsInitialData = <?php echo wp_json_encode( $initial_data ); ?>;
</script>

<?php wp_footer(); ?>
</body>
</html>
