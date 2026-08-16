<?php
/** Client listing subscription billing. */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
if ( ! $client || ! OFP_Subscription::has_active( 'listing', $client->id ) ) {
    wp_safe_redirect( home_url( '/dashboard' ) );
    exit;
}

global $wpdb;
$p = $wpdb->prefix;
$records = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$p}ofp_subscriptions WHERE client_id = %d AND type = 'listing' ORDER BY created_at DESC LIMIT 100",
    (int) $client->id
) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listing Billing — OFast Pipeline</title>
    <!-- Dark theme script to avoid FOUC -->
    <script>
        (function() {
            var currentTheme = localStorage.getItem('ofp_theme') || 'dark';
            if (currentTheme === 'light') { document.documentElement.setAttribute('data-theme', 'light'); }
        })();
    </script>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
</head>
<body class="ofp-portal-body">
<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>
<div class="ofp-container">
    <div style="padding-bottom: 60px;">
        <div style="margin: 0 0 24px;">
            <h1 style="font-size:22px; font-weight:700; color:var(--text-main); margin:0 0 8px; letter-spacing:-0.01em;">
                Listing Billing
            </h1>
            <p style="color:#64748b; margin:0; font-size:14px;">Review your property listing subscription and payment history.</p>
        </div>
        
        <div class="ofp-card">
            <div class="ofp-table-responsive">
                <table class="ofp-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Period</th>
                            <th>Payment Ref</th>
                            <th>Paid At</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $records ) ) : ?>
                        <tr><td colspan="6" style="text-align:center; color:#64748b;">No listing billing records yet.</td></tr>
                    <?php else : foreach ( $records as $row ) : ?>
                        <tr>
                            <td style="font-weight: 500; color: var(--text-main);"><?php echo esc_html( strtoupper( $row->plan ?: 'Listing' ) ); ?></td>
                            <td><strong style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></strong></td>
                            <td>
                                <?php 
                                    $status_styles = [
                                        'active'  => 'background:#dcfce7; color:#16a34a;',
                                        'expired' => 'background:rgba(128,128,128,0.1); color:var(--text-muted);',
                                        'pending' => 'background:#fef3c7; color:#d97706;',
                                        'failed'  => 'background:#fee2e2; color:#ef4444;'
                                    ];
                                    $style = $status_styles[ strtolower( $row->status ) ] ?? 'background:rgba(128,128,128,0.1); color:var(--text-muted);';
                                ?>
                                <span style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:100px; <?php echo esc_attr($style); ?>">
                                    <?php echo esc_html( ucfirst( $row->status ) ); ?>
                                </span>
                            </td>
                            <td style="color:var(--text-muted); font-size:13px;"><?php echo $row->period_start && $row->period_end ? esc_html( $row->period_start . ' → ' . $row->period_end ) : '—'; ?></td>
                            <td>
                                <code style="font-size:12px; color:var(--text-main); background:rgba(128,128,128,0.1); padding:2px 6px; border-radius:4px;">
                                    <?php echo esc_html( $row->payment_ref ?: '—' ); ?>
                                </code>
                            </td>
                            <td style="color:var(--text-muted); font-size:13px;"><?php echo $row->paid_at ? esc_html( wp_date( 'M j, Y', strtotime( $row->paid_at ) ) ) : '—'; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
