<?php
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
if ( ! $client || ! OFP_Subscription::has_active( 'listing', $client->id ) ) {
    wp_safe_redirect( home_url( '/dashboard' ) );
    exit;
}

global $wpdb;
$p = $wpdb->prefix;
$payments = $wpdb->get_results( $wpdb->prepare(
    "SELECT py.*, pu.property_id, pu.buyer_name, pu.buyer_phone, pr.title AS property_title
     FROM {$p}ofp_property_payments py
     INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
     INNER JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
     WHERE pu.client_id = %d
     ORDER BY py.created_at DESC, py.id DESC
     LIMIT 250",
    (int) $client->id
) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Property Payments — OFast Pipeline</title>
<?php wp_head(); ?>
<link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">
<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>
<div class="ofp-container"><div style="padding-bottom:60px;">
<h1 style="font-size:22px;font-weight:700;margin:0 0 24px;">Property Payments</h1>
<div class="ofp-card">
<p class="ofp-hint">Payment records for purchases belonging to your properties. This page is read-only; payment submission and verification are handled separately.</p>
<div style="overflow-x:auto;">
<table class="widefat striped" style="min-width:1200px;">
<thead><tr><th>ID</th><th>Buyer</th><th>Property</th><th>Purchase</th><th>Amount</th><th>Method</th><th>Gateway</th><th>Reference</th><th>Status</th><th>Created</th><th>Verified</th></tr></thead>
<tbody>
<?php if ( empty( $payments ) ) : ?>
<tr><td colspan="11">No property payment records yet.</td></tr>
<?php else : foreach ( $payments as $payment ) : ?>
<tr>
<td>#<?php echo esc_html( $payment->id ); ?></td>
<td><strong><?php echo esc_html( $payment->buyer_name ); ?></strong><br><small><?php echo esc_html( $payment->buyer_phone ); ?></small></td>
<td><?php echo esc_html( $payment->property_title ); ?></td>
<td>#<?php echo esc_html( $payment->purchase_id ); ?></td>
<td><strong>NGN <?php echo esc_html( number_format( (float) $payment->amount, 2 ) ); ?></strong></td>
<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $payment->payment_method ) ) ); ?></td>
<td><?php echo esc_html( $payment->gateway ?: '—' ); ?></td>
<td><code><?php echo esc_html( $payment->gateway_reference ?: ( $payment->payer_reference ?: '—' ) ); ?></code></td>
<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $payment->status ) ) ); ?></td>
<td><?php echo esc_html( $payment->created_at ); ?></td>
<td><?php echo esc_html( $payment->verified_at ?: '—' ); ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table>
</div></div>
</div></div>
<?php wp_footer(); ?>
</body></html>
