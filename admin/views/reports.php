<?php
/**
 * Admin View: Reports
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

global $wpdb;
$p = $wpdb->prefix;

$clients = OFP_Client::all( 'active' );

// Recent archives
$archives = $wpdb->get_results(
    "SELECT a.*, c.business_name
     FROM {$p}ofp_archives a
     JOIN {$p}ofp_clients c ON c.id = a.client_id
     ORDER BY a.created_at DESC
     LIMIT 30"
);

include OFP_PATH . 'admin/views/partials/header.php';
?>

<h2>Reports</h2>

<!-- Manual report generation -->
<div class="ofp-section">
    <h3>Generate Monthly Report</h3>
    <p>Reports are generated automatically on the 1st of each month and emailed to each client.
       Use this to manually generate a report for a specific client and month.</p>

    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ofp-form">
        <?php wp_nonce_field( 'ofp_generate_report' ); ?>
        <input type="hidden" name="action" value="ofp_generate_report">

        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label>Client</label>
                <select name="client_id" required>
                    <option value="">— Select Client —</option>
                    <?php foreach ( $clients as $c ) : ?>
                        <option value="<?php echo esc_attr( $c->id ); ?>">
                            <?php echo esc_html( $c->business_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ofp-field">
                <label>Month</label>
                <select name="month">
                    <?php for ( $m = 1; $m <= 12; $m++ ) : ?>
                        <option value="<?php echo esc_attr( $m ); ?>"
                            <?php selected( (int) gmdate( 'n' ) - 1, $m ); ?>>
                            <?php echo esc_html( gmdate( 'F', mktime( 0, 0, 0, $m, 1 ) ) ); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="ofp-field">
                <label>Year</label>
                <select name="year">
                    <?php for ( $y = (int) gmdate( 'Y' ); $y >= (int) gmdate( 'Y' ) - 2; $y-- ) : ?>
                        <option value="<?php echo esc_attr( $y ); ?>"><?php echo esc_html( $y ); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>

        <div class="ofp-form-actions">
            <button type="submit" class="button button-primary">Generate & Email Report</button>
        </div>
    </form>
</div>

<!-- Archive list -->
<div class="ofp-section">
    <h3>Recent Report Archives</h3>
    <?php if ( empty( $archives ) ) : ?>
        <p>No reports generated yet. Reports appear here after the first monthly run.</p>
    <?php else : ?>
        <table class="widefat ofp-table">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Period</th>
                    <th>Generated</th>
                    <th>Download</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $archives as $archive ) : ?>
                    <tr>
                        <td><?php echo esc_html( $archive->business_name ); ?></td>
                        <td><?php echo esc_html( $archive->period ); ?></td>
                        <td><?php echo esc_html( $archive->created_at ); ?></td>
                        <td>
                            <?php if ( $archive->download_token && strtotime( $archive->token_expires ) > time() ) : ?>
                                <a href="<?php echo esc_url(
                                    add_query_arg( [ 'token' => $archive->download_token, 'file' => 'leads' ], home_url( '/reports' ) )
                                ); ?>" class="button button-small" target="_blank">
                                    Leads CSV
                                </a>
                                <a href="<?php echo esc_url(
                                    add_query_arg( [ 'token' => $archive->download_token, 'file' => 'comms' ], home_url( '/reports' ) )
                                ); ?>" class="button button-small" target="_blank">
                                    Comms CSV
                                </a>
                            <?php else : ?>
                                <span class="ofp-muted">Expired</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include OFP_PATH . 'admin/views/partials/footer.php'; ?>
