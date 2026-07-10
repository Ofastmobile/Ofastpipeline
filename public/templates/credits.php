<?php
/**
 * Template: /credits
 * Credit balance display, subscription details, transaction history.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

global $wpdb;
$p = $wpdb->prefix;

$credits = OFP_Credit::get( $client->id );

$sms_pct   = $credits && $credits->sms_loaded   > 0 ? round( ( $credits->sms_remaining   / $credits->sms_loaded   ) * 100 ) : 0;
$voice_pct = $credits && $credits->voice_loaded > 0 ? round( ( $credits->voice_remaining / $credits->voice_loaded ) * 100 ) : 0;

// Subscription rows
$subscriptions = OFP_Subscription::get_all_for_client( $client->id );

// Transaction history
$transactions = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$p}ofp_credit_transactions
         WHERE client_id = %d
         ORDER BY created_at DESC
         LIMIT 30",
        $client->id
    )
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credits & Billing — OFast Pipeline</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <div class="ofp-page-header">
        <h1>Credits & Billing</h1>
        <p>Your credit balances, subscription status, and payment history.</p>
    </div>

    <?php if ( $credits && $credits->paused ) : ?>
        <div class="ofp-alert ofp-alert-error">
            <strong>Pipeline paused.</strong> Your credit balance is exhausted.
            Please contact us to top up and resume your automation.
        </div>
    <?php endif; ?>

    <!-- Subscription Status -->
    <div class="ofp-card">
        <h3>Subscription Status</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
            <div>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em;">Account Status</div>
                <?php
                $status_colors = [
                    'active'         => 'var(--accent-green)',
                    'grace'          => 'var(--accent-orange)',
                    'pending_review' => 'var(--accent-orange)',
                    'suspended'      => 'var(--accent-red)',
                ];
                $color = $status_colors[ $client->status ] ?? 'var(--text-muted)';
                ?>
                <div style="font-size:18px;font-weight:700;color:<?php echo esc_attr( $color ); ?>;">
                    <?php echo esc_html( ucfirst( str_replace( '_', ' ', $client->status ) ) ); ?>
                </div>
            </div>
            <div>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em;">Expires</div>
                <div style="font-size:18px;font-weight:700;color:var(--text-main);">
                    <?php echo esc_html( $client->subscription_expires ?: '—' ); ?>
                </div>
            </div>
            <div>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em;">Plan</div>
                <div style="font-size:18px;font-weight:700;color:var(--text-main);">
                    <?php echo esc_html( strtoupper( $client->plan ?: '—' ) ); ?>
                </div>
            </div>
        </div>

        <?php if ( $client->virtual_account_number ) : ?>
            <div class="ofp-alert ofp-alert-success" style="margin-top:20px; align-items:flex-start; flex-direction:column; gap:4px; margin-bottom:0;">
                <div style="font-size:13px;font-weight:600;">Renewal Account (pay here to renew)</div>
                <div style="font-size:16px;font-weight:700;color:var(--text-main);">
                    <?php echo esc_html( $client->virtual_bank_name ); ?> — <?php echo esc_html( $client->virtual_account_number ); ?>
                </div>
                <div style="font-size:12px;">
                    This dedicated account is yours only. Payments are automatically applied to your subscription.
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ( OFP_Subscription::has_active( 'crm', $client->id ) ) : ?>

        <!-- Credit Balances -->
        <div class="ofp-card">
            <h3>Credit Balances</h3>

            <div class="ofp-credit-bar-wrap">
                <div class="ofp-credit-bar-label">
                    <strong>SMS Credit</strong>
                    <span>NGN <?php echo number_format( (float) ( $credits->sms_remaining ?? 0 ), 2 ); ?> of NGN <?php echo number_format( (float) ( $credits->sms_loaded ?? 0 ), 2 ); ?> (<?php echo esc_html( $sms_pct ); ?>%)</span>
                </div>
                <div class="ofp-credit-bar-track">
                    <div class="ofp-credit-bar-fill <?php echo $sms_pct > 40 ? 'high' : ( $sms_pct > 15 ? 'medium' : 'low' ); ?>"
                         style="width:<?php echo esc_attr( $sms_pct ); ?>%"></div>
                </div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                    ~<?php echo esc_html( $credits ? floor( $credits->sms_remaining / 6.99 ) : 0 ); ?> SMS messages remaining at NGN 6.99 each.
                </div>
            </div>

            <div class="ofp-credit-bar-wrap" style="margin-top:20px;">
                <div class="ofp-credit-bar-label">
                    <strong>Voice Credit</strong>
                    <span>NGN <?php echo number_format( (float) ( $credits->voice_remaining ?? 0 ), 2 ); ?> of NGN <?php echo number_format( (float) ( $credits->voice_loaded ?? 0 ), 2 ); ?> (<?php echo esc_html( $voice_pct ); ?>%)</span>
                </div>
                <div class="ofp-credit-bar-track">
                    <div class="ofp-credit-bar-fill <?php echo $voice_pct > 40 ? 'high' : ( $voice_pct > 15 ? 'medium' : 'low' ); ?>"
                         style="width:<?php echo esc_attr( $voice_pct ); ?>%"></div>
                </div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                    ~<?php echo esc_html( $credits ? floor( $credits->voice_remaining / 15 ) : 0 ); ?> voice calls remaining at NGN 15.00 per minute.
                </div>
            </div>

            <div class="ofp-alert ofp-alert-info" style="margin-top:20px; margin-bottom:0;">
                To top up your credit, make a transfer and contact us with the reference. We will load it to your account within the hour. Self-serve top-up is coming soon.
            </div>
        </div>

    <?php endif; ?>

    <!-- Payment History -->
    <div class="ofp-card">
        <h3>Payment History</h3>
        <?php if ( empty( $subscriptions ) ) : ?>
            <div class="ofp-empty" style="padding:32px;">
                <h3>No payments yet</h3>
                <p>Your payment history will appear here.</p>
            </div>
        <?php else : ?>
            <div class="ofp-table-wrap">
                <table class="ofp-table">
                    <thead>
                        <tr><th>Type</th><th>Plan</th><th>Amount</th><th>Status</th><th>Period</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $subscriptions as $sub ) : ?>
                            <tr>
                                <td><?php echo esc_html( strtoupper( $sub->type ) ); ?></td>
                                <td><?php echo esc_html( strtoupper( $sub->plan ?: '—' ) ); ?></td>
                                <td><strong>NGN <?php echo esc_html( number_format( (float) $sub->amount, 0 ) ); ?></strong></td>
                                <td>
                                    <?php
                                    $sc = $sub->status === 'paid' ? 'ofp-badge-green' : 'ofp-badge-yellow';
                                    $s_label = $sub->status === 'pending'
                                        ? 'Awaiting Payment'
                                        : ucfirst( $sub->status );
                                    echo '<span class="ofp-badge ' . esc_attr( $sc ) . '">'
                                        . esc_html( $s_label ) . '</span>';
                                    ?>
                                </td>
                                <td style="font-size:12px;color:#9ca3af;">
                                    <?php echo $sub->period_start ? esc_html( $sub->period_start . ' → ' . $sub->period_end ) : '—'; ?>
                                </td>
                                <td style="font-size:12px;color:#9ca3af;">
                                    <?php echo $sub->paid_at ? esc_html( gmdate( 'M j, Y', strtotime( $sub->paid_at ) ) ) : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Credit Transactions -->
    <?php if ( ! empty( $transactions ) ) : ?>
        <div class="ofp-card">
            <h3>Credit Transaction Log</h3>
            <div class="ofp-table-wrap">
                <table class="ofp-table">
                    <thead>
                        <tr><th>Channel</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $transactions as $tx ) : ?>
                            <tr>
                                <td><?php echo esc_html( strtoupper( $tx->channel ) ); ?></td>
                                <td>
                                    <?php
                                    $tc = $tx->type === 'topup' ? 'ofp-badge-green' : 'ofp-badge-grey';
                                    echo '<span class="ofp-badge ' . esc_attr( $tc ) . '">' . esc_html( ucfirst( $tx->type ) ) . '</span>';
                                    ?>
                                </td>
                                <td style="color:<?php echo $tx->type === 'topup' ? '#166534' : '#991b1b'; ?>">
                                    <?php echo $tx->type === 'topup' ? '+' : '-'; ?>NGN <?php echo esc_html( number_format( (float) $tx->amount, 2 ) ); ?>
                                </td>
                                <td>NGN <?php echo esc_html( number_format( (float) $tx->balance_after, 2 ) ); ?></td>
                                <td style="font-size:12px;color:#9ca3af;"><?php echo esc_html( gmdate( 'M j, Y H:i', strtotime( $tx->created_at ) ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
