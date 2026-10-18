<?php
/**
 * Template: /reports
 * Client's monthly report download page.
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

// Handle token download
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

    if ( $archive && $archive->file_path ) {
        $files = explode( '|', $archive->file_path );

        // 'file' param picks which CSV: 'leads' (default, index 0) or 'comms' (index 1).
        // Both live behind the same 72-hour token — no need for a second token per file.
        $which = sanitize_text_field( $_GET['file'] ?? 'leads' );
        $index = $which === 'comms' ? 1 : 0;
        $file  = $files[ $index ] ?? '';

        if ( $file && file_exists( $file ) ) {
            header( 'Content-Type: text/csv' );
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

// Quick stats for the current month
$stats = OFP_Lead::get_stats( $client->id );
$comms_this_month = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}ofp_communications_log
         WHERE client_id = %d
           AND MONTH(sent_at) = MONTH(NOW())
           AND YEAR(sent_at)  = YEAR(NOW())",
        $client->id
    )
);

$comms_last_month = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}ofp_communications_log
         WHERE client_id = %d
           AND MONTH(sent_at) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH))
           AND YEAR(sent_at)  = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH))",
        $client->id
    )
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Reports', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <?php if ( ! empty( $token_error ) ) : ?>
        <div class="ofp-alert ofp-alert-error">
            ❌ This download link has expired or is invalid. Please contact us to generate a new report.
        </div>
    <?php endif; ?>

    <!-- Reports KPI Summary Grid -->
    <div class="ofp-zentra-kpi-grid" style="margin-bottom:24px;">
        <div class="ofp-kpi-card ofp-kpi-card-hero">
            <div class="ofp-kpi-title">Leads This Month</div>
            <div class="ofp-kpi-value"><?php echo esc_html( $stats['this_month'] ?: 0 ); ?></div>
            <div class="ofp-kpi-badge badge-hero">
                <span><?php echo strip_tags( OFP_Lead::get_growth_html( $stats['this_month'], $stats['last_month'] ) ); ?> vs last month</span>
            </div>
        </div>

        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Converted</div>
            <div class="ofp-kpi-value"><?php echo esc_html( $stats['converted_month'] ?: 0 ); ?></div>
            <div class="ofp-kpi-badge badge-green">
                <span><?php echo strip_tags( OFP_Lead::get_growth_html( $stats['converted_month'], $stats['converted_last'] ) ); ?> deals converted</span>
            </div>
        </div>

        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Messages Sent</div>
            <div class="ofp-kpi-value"><?php echo esc_html( $comms_this_month ?: 0 ); ?></div>
            <div class="ofp-kpi-badge badge-blue">
                <span><?php echo strip_tags( OFP_Lead::get_growth_html( $comms_this_month, $comms_last_month ) ); ?> communications</span>
            </div>
        </div>

        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Conv. Rate</div>
            <div class="ofp-kpi-value">
                <?php 
                    $rate = $stats['this_month'] > 0 ? round( ( $stats['converted_month'] / $stats['this_month'] ) * 100 ) : 0;
                    $last_rate = $stats['last_month'] > 0 ? round( ( $stats['converted_last'] / $stats['last_month'] ) * 100 ) : 0;
                    echo esc_html( $rate ) . '%';
                ?>
            </div>
            <div class="ofp-kpi-badge badge-purple">
                <span><?php echo strip_tags( OFP_Lead::get_growth_html( $rate, $last_rate ) ); ?> conversion rate</span>
            </div>
        </div>
    </div>

    <!-- Monthly Performance Bar Chart -->
    <div class="ofp-card" style="margin-bottom: 32px; display: flex; flex-direction: column;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <div>
                <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Monthly Performance</h3>
                <p style="margin: 0; font-size: 13px; color: var(--text-muted); margin-top: 4px;">Lead generation vs conversions</p>
            </div>
            <div style="padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); font-size: 12px; font-weight: 600; color: var(--text-muted);">
                2026 ⌵
            </div>
        </div>
        <div style="position: relative; height: 300px; width: 100%; margin-top: auto;">
            <canvas id="monthlyPerformanceChart"></canvas>
        </div>
    </div>

    <p class="ofp-hint" style="margin-bottom:24px;">
        Your full monthly report (CSV) is automatically generated and emailed to you on the 1st of each month.
    </p>

    <!-- Report Archive -->
    <div class="ofp-card">
        <h3>Report Archive</h3>
        <?php if ( empty( $archives ) ) : ?>
            <div class="ofp-empty" style="padding:32px;">
                
                <h3>No reports yet</h3>
                <p>Your first report will be generated automatically on the 1st of next month.</p>
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
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-radius: 12px; background: var(--bg-lighter); border: 1px solid var(--border-color); transition: all 0.2s;" onmouseover="this.style.borderColor='var(--accent-blue)';" onmouseout="this.style.borderColor='var(--border-color)';">
                        
                        <!-- Left: Icon & Details -->
                        <div style="display: flex; gap: 16px; align-items: center;">
                            <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(59, 130, 246, 0.1); color: var(--accent-blue); display: flex; align-items: center; justify-content: center; font-size: 20px;">
                                📄
                            </div>
                            <div>
                                <div style="font-size: 15px; font-weight: 600; color: var(--text-main); margin-bottom: 4px;">
                                    <?php echo esc_html( $period_fmt ?: $archive->period ); ?> Report
                                </div>
                                <div style="font-size: 13px; color: var(--text-muted);">
                                    Generated: <?php echo esc_html( gmdate( 'M j, Y', strtotime( $archive->created_at ) ) ); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Actions -->
                        <div style="text-align: right;">
                            <?php if ( $is_valid ) : ?>
                                <a href="<?php echo esc_url( add_query_arg( [ 'token' => $archive->download_token, 'file' => 'leads' ], home_url( '/reports' ) ) ); ?>"
                                   style="display: inline-block; padding: 8px 14px; background: var(--accent-blue); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none;">
                                    Leads CSV
                                </a>
                                <a href="<?php echo esc_url( add_query_arg( [ 'token' => $archive->download_token, 'file' => 'comms' ], home_url( '/reports' ) ) ); ?>"
                                   style="display: inline-block; padding: 8px 14px; background: var(--bg-lighter); border: 1px solid var(--border-color); color: var(--text-main); border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; margin-top:6px;">
                                    Comms CSV
                                </a>
                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 6px;">
                                    Expires <?php echo esc_html( gmdate( 'M j', strtotime( $archive->token_expires ) ) ); ?>
                                </div>
                            <?php else : ?>
                                <div style="padding: 8px 16px; background: rgba(255,255,255,0.05); color: var(--text-muted); border-radius: 8px; font-size: 13px; font-weight: 600;">
                                    Link Expired
                                </div>
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
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('monthlyPerformanceChart');
    if (ctx && typeof ofpClientData !== 'undefined') {
        // Gradient for Leads
        const ctx2d = ctx.getContext('2d');
        const gradientLeads = ctx2d.createLinearGradient(0, 0, 0, 300);
        gradientLeads.addColorStop(0, 'rgba(59, 130, 246, 1)');
        gradientLeads.addColorStop(1, 'rgba(59, 130, 246, 0.4)');

        // Gradient for Conversions
        const gradientConv = ctx2d.createLinearGradient(0, 0, 0, 300);
        gradientConv.addColorStop(0, 'rgba(16, 185, 129, 1)');
        gradientConv.addColorStop(1, 'rgba(16, 185, 129, 0.4)');

        const body = new URLSearchParams();
        body.append('action', 'ofp_reports_chart_data');
        body.append('nonce', ofpClientData.nonce);

        fetch(ofpClientData.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success) return;
                const monthly = res.data.monthly || [];

                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: monthly.map(function(m) { return m.label; }),
                        datasets: [
                            {
                                label: 'Leads Generated',
                                data: monthly.map(function(m) { return m.total; }),
                                backgroundColor: gradientLeads,
                                borderRadius: 6,
                                barPercentage: 0.6,
                                categoryPercentage: 0.8
                            },
                            {
                                label: 'Conversions',
                                data: monthly.map(function(m) { return m.converted; }),
                                backgroundColor: gradientConv,
                                borderRadius: 6,
                                barPercentage: 0.6,
                                categoryPercentage: 0.8
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                align: 'end',
                                labels: {
                                    color: '#9ca3af',
                                    usePointStyle: true,
                                    boxWidth: 8
                                }
                            },
                            tooltip: {
                                backgroundColor: '#1f2937',
                                titleColor: '#f9fafb',
                                bodyColor: '#d1d5db',
                                padding: 12,
                                cornerRadius: 8,
                                displayColors: true
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false, drawBorder: false },
                                ticks: { color: '#9ca3af', font: { size: 12 } }
                            },
                            y: {
                                grid: { color: 'rgba(255,255,255,0.05)', drawBorder: false },
                                ticks: { color: '#9ca3af', font: { size: 12 }, padding: 10, precision: 0 }
                            }
                        },
                        interaction: {
                            intersect: false,
                            mode: 'index',
                        },
                    }
                });
            })
            .catch(function(err) { console.error('OFP reports chart error:', err); });
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
