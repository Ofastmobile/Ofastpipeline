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
<div class="ofp-container">
    <div style="padding-bottom: 60px;">
        <div style="margin: 0 0 24px;">
            <h1 style="font-size:22px; font-weight:700; color:var(--text-main); margin:0 0 8px; letter-spacing:-0.01em;">
                Property Payments
            </h1>
            <p style="color:#64748b; margin:0; font-size:14px;">Payment records for purchases belonging to your properties. This page is read-only; payment submission and verification are handled separately.</p>
        </div>
        
        <div class="ofp-card">
            <div class="ofp-table-responsive">
                <table class="ofp-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Buyer</th>
                            <th>Property</th>
                            <th>Purchase</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Gateway</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Verified</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $payments ) ) : ?>
                        <tr><td colspan="11" style="text-align:center; color:#64748b;">No property payment records yet.</td></tr>
                    <?php else : foreach ( $payments as $payment ) : ?>
                        <tr>
                            <td style="color:#64748b;">#<?php echo esc_html( $payment->id ); ?></td>
                            <td>
                                <div style="font-weight: 500; color: var(--text-main);"><?php echo esc_html( $payment->buyer_name ); ?></div>
                                <div style="font-size: 12px; color: var(--text-muted);"><?php echo esc_html( $payment->buyer_phone ); ?></div>
                            </td>
                            <td style="color:var(--text-dark);"><?php echo esc_html( $payment->property_title ); ?></td>
                            <td style="color:var(--text-dark);">#<?php echo esc_html( $payment->purchase_id ); ?></td>
                            <td><strong style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $payment->amount, 2 ) ); ?></strong></td>
                            <td style="color:var(--text-dark);"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $payment->payment_method ) ) ); ?></td>
                            <td style="color:var(--text-muted); font-size:13px;"><?php echo esc_html( $payment->gateway ?: '—' ); ?></td>
                            <td>
                                <code style="font-size:12px; color:#475569; background:#f1f5f9; padding:2px 6px; border-radius:4px;">
                                    <?php echo esc_html( $payment->gateway_reference ?: ( $payment->payer_reference ?: '—' ) ); ?>
                                </code>
                            </td>
                            <td>
                                <?php 
                                    $status_styles = [
                                        'verified' => 'background:#dcfce7; color:#16a34a;',
                                        'pending'  => 'background:#fef3c7; color:#d97706;',
                                        'failed'   => 'background:#fee2e2; color:#ef4444;',
                                        'refunded' => 'background:#f1f5f9; color:#64748b;'
                                    ];
                                    $style = $status_styles[ strtolower( $payment->status ) ] ?? 'background:#f1f5f9; color:#64748b;';
                                ?>
                                <span style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:100px; <?php echo esc_attr($style); ?>">
                                    <?php echo esc_html( ucfirst( str_replace( '_', ' ', $payment->status ) ) ); ?>
                                </span>
                            </td>
                            <td style="color:var(--text-muted); font-size:13px;"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $payment->created_at ) ) ); ?></td>
                            <td style="color:var(--text-muted); font-size:13px;"><?php echo $payment->verified_at ? esc_html( wp_date( 'M j, Y', strtotime( $payment->verified_at ) ) ) : '—'; ?></td>
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
