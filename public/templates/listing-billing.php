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
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">
<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>
<div class="ofp-container"><div style="padding-bottom:60px;">
    <h1 style="font-size:22px;font-weight:700;margin:0 0 24px;">Listing Billing</h1>
    <div class="ofp-card" style="overflow-x:auto;">
        <table class="widefat striped" style="min-width:850px;">
            <thead><tr><th>Plan</th><th>Amount</th><th>Status</th><th>Period</th><th>Payment Ref</th><th>Paid At</th></tr></thead>
            <tbody>
            <?php if ( empty( $records ) ) : ?>
                <tr><td colspan="6">No listing billing records yet.</td></tr>
            <?php else : foreach ( $records as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( strtoupper( $row->plan ?: 'Listing' ) ); ?></td>
                    <td>NGN <?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></td>
                    <td><?php echo esc_html( ucfirst( $row->status ) ); ?></td>
                    <td><?php echo $row->period_start && $row->period_end ? esc_html( $row->period_start . ' → ' . $row->period_end ) : '—'; ?></td>
                    <td><code><?php echo esc_html( $row->payment_ref ?: '—' ); ?></code></td>
                    <td><?php echo esc_html( $row->paid_at ?: '—' ); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div></div>
<?php wp_footer(); ?>
</body>
</html>
