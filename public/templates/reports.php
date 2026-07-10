<?php
/**
 * Template: /reports
 * Client's monthly report download page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

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
        // Serve the first file (leads CSV) directly.
        $file = $files[0] ?? '';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — OFast Pipeline</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <div class="ofp-page-header">
        <h1>Reports</h1>
        <p>Monthly pipeline reports automatically generated on the 1st of each month.</p>
    </div>

    <?php if ( ! empty( $token_error ) ) : ?>
        <div class="ofp-alert ofp-alert-error">
            ❌ This download link has expired or is invalid. Please contact us to generate a new report.
        </div>
    <?php endif; ?>

    <!-- This Month Summary -->
    <div class="ofp-stats-grid">
        <div class="ofp-stat-card">
            <div class="ofp-stat-header">
                <span class="ofp-stat-title">Leads This Month</span>
            </div>
            <div class="ofp-stat-value"><?php echo esc_html( $stats['this_month'] ); ?></div>
        </div>
        <div class="ofp-stat-card">
            <div class="ofp-stat-header">
                <span class="ofp-stat-title">Converted</span>
            </div>
            <div class="ofp-stat-value"><?php echo esc_html( $stats['converted'] ); ?></div>
        </div>
        <div class="ofp-stat-card">
            <div class="ofp-stat-header">
                <span class="ofp-stat-title">Messages Sent</span>
            </div>
            <div class="ofp-stat-value"><?php echo esc_html( $comms_this_month ); ?></div>
        </div>
        <div class="ofp-stat-card">
            <div class="ofp-stat-header">
                <span class="ofp-stat-title">Conv. Rate</span>
            </div>
            <div class="ofp-stat-value">
                <?php echo $stats['this_month'] > 0
                    ? esc_html( round( ( $stats['converted'] / $stats['this_month'] ) * 100 ) ) . '%'
                    : '—'; ?>
            </div>
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
            <div class="ofp-table-wrap">
                <table class="ofp-table">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Generated</th>
                            <th>Download</th>
                        </tr>
                    </thead>
                    <tbody>
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
                            <tr>
                                <td><strong><?php echo esc_html( $period_fmt ?: $archive->period ); ?></strong></td>
                                <td style="font-size:13px;color:#9ca3af;">
                                    <?php echo esc_html( gmdate( 'M j, Y', strtotime( $archive->created_at ) ) ); ?>
                                </td>
                                <td>
                                    <?php if ( $is_valid ) : ?>
                                        <a href="<?php echo esc_url( add_query_arg( 'token', $archive->download_token, home_url( '/reports' ) ) ); ?>"
                                           class="ofp-btn ofp-btn-secondary"
                                           style="font-size:12px;padding:6px 14px;">
                                            ⬇ Download CSV
                                        </a>
                                        <span style="font-size:11px;color:#9ca3af;display:block;margin-top:4px;">
                                            Expires <?php echo esc_html( gmdate( 'M j, g:ia', strtotime( $archive->token_expires ) ) ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span style="font-size:13px;color:#9ca3af;">Link expired</span>
                                        <span style="font-size:11px;color:#9ca3af;display:block;">Contact us to regenerate</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="ofp-alert ofp-alert-info">
        Reports are also emailed to <strong><?php echo esc_html( $client->email ); ?></strong>
        on the 1st of each month. Download links expire after 72 hours.
        Contact us if you need a report regenerated.
    </div>

</div>
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
