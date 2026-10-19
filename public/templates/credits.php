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

// Back from Paystack: ask Paystack right now instead of waiting for its notification.
$ofp_return_ref = sanitize_text_field( wp_unslash( $_GET['reference'] ?? ( $_GET['trxref'] ?? '' ) ) );
$ofp_return_msg = '';
if ( $ofp_return_ref && OFP_Payment::reference_client_id( $ofp_return_ref ) === (int) $client->id ) {
    $ofp_state = OFP_Payment::settle_reference( $ofp_return_ref );
    if ( $ofp_state === 'applied' ) {
        $ofp_return_msg = 'Payment confirmed. Your credits have been added.';
    } elseif ( $ofp_state === 'failed' ) {
        $ofp_return_msg = 'This payment was not completed, so nothing was charged.';
    } else {
        $ofp_return_msg = 'Your payment is still being confirmed. It will update automatically.';
    }
}

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
    <title><?php echo esc_html( OFP_Brand::title( 'Credits & Billing', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <?php if ( ! empty( $ofp_return_msg ) ) : ?>
        <div style="background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:12px 16px;border-radius:8px;margin:0 0 16px;"><?php echo esc_html( $ofp_return_msg ); ?></div>
    <?php endif; ?>

    <?php if ( $credits && $credits->paused ) : ?>
        <div class="ofp-alert ofp-alert-error">
            <strong>Pipeline paused.</strong> Your credit balance is exhausted.
            Please contact us to top up and resume your automation.
        </div>
    <?php endif; ?>

    <?php 
    $is_solo = ! empty( $client->is_white_label ) || ( class_exists( 'OFP_Subscription' ) && OFP_Subscription::is_unlimited( (int) $client->id ) );
    ?>
    <!-- Credits & Billing 4-KPI Grid -->
    <div class="ofp-zentra-kpi-grid" style="margin-bottom: 32px;">
        <!-- 1. Plan -->
        <div class="ofp-kpi-card ofp-kpi-card-hero">
            <div class="ofp-kpi-title" style="display:flex; justify-content:space-between; align-items:center;">
                <span>Subscription Plan</span>
                <?php if ( $client->status === 'active' || $is_solo ) : ?>
                    <span style="display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:600; color:#34d399;">
                        <span class="ofp-status-dot-pulse" style="width:7px; height:7px;"></span> Active
                    </span>
                <?php endif; ?>
            </div>
            <div class="ofp-kpi-value"><?php echo $is_solo ? 'PREMIUM' : esc_html( strtoupper( OFP_Subscription::client_plan( $client->id ) ) ); ?></div>
            <div class="ofp-kpi-badge badge-hero">
                <span class="ofp-status-dot-pulse" style="width:6px; height:6px;"></span>
                <span><?php echo $is_solo ? 'Lifetime Access' : esc_html( ucfirst( str_replace( '_', ' ', $client->status ) ) ) . ' Status'; ?></span>
            </div>
        </div>

        <!-- 2. Expires / Validity -->
        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title"><?php echo $is_solo ? 'Subscription Validity' : 'Subscription Expires'; ?></div>
            <div class="ofp-kpi-value" style="font-size: 20px;">
                <?php 
                if ( $is_solo ) {
                    echo 'Unlimited';
                } else {
                    echo esc_html( $client->subscription_expires ? gmdate( 'M j, Y', strtotime( $client->subscription_expires ) ) : '—' );
                }
                ?>
            </div>
            <div class="ofp-kpi-badge badge-purple">
                <span>
                    <?php 
                    if ( $is_solo ) {
                        echo 'Never Expires';
                    } elseif ( $client->subscription_expires ) {
                        $diff_days = round( ( strtotime( $client->subscription_expires ) - time() ) / DAY_IN_SECONDS );
                        echo $diff_days > 0 ? $diff_days . ' days remaining' : 'Renewal due';
                    } else {
                        echo 'Active Account';
                    }
                    ?>
                </span>
            </div>
        </div>

        <!-- 3. SMS Credit -->
        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">SMS Credit Balance</div>
            <div class="ofp-kpi-value">₦<?php echo number_format( (float) ( $credits->sms_remaining ?? 0 ), 0 ); ?></div>
            <div class="ofp-kpi-badge badge-blue">
                <span>~<?php echo esc_html( $credits ? floor( $credits->sms_remaining / 6.99 ) : 0 ); ?> msgs (~₦<?php echo number_format((float)($credits->sms_loaded ?? 0), 0); ?> loaded)</span>
            </div>
        </div>

        <!-- 4. Voice Credit -->
        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Voice Credit Balance</div>
            <div class="ofp-kpi-value">₦<?php echo number_format( (float) ( $credits->voice_remaining ?? 0 ), 0 ); ?></div>
            <div class="ofp-kpi-badge badge-green">
                <span>~<?php echo esc_html( $credits ? floor( $credits->voice_remaining / 15 ) : 0 ); ?> mins (~₦<?php echo number_format((float)($credits->voice_loaded ?? 0), 0); ?> loaded)</span>
            </div>
        </div>
    </div>

    <!-- Payment History -->
    <div class="ofp-card" style="margin-bottom: 32px;">
        <h3>Payment History</h3>
        <?php if ( empty( $subscriptions ) ) : ?>
            <div class="ofp-empty" style="padding:32px;">
                <h3>No payments yet</h3>
                <p>Your payment history will appear here.</p>
            </div>
        <?php else : ?>
            <div class="ofp-table-wrap ofp-table-responsive">
                <table class="ofp-table">
                    <thead>
                        <tr><th>Type</th><th>Plan</th><th>Amount</th><th>Status</th><th>Period</th><th>Payment Ref</th><th>Date</th></tr>
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
                                <td>
                                    <code style="font-size:12px; color:var(--text-main); background:rgba(128,128,128,0.1); padding:2px 6px; border-radius:4px;">
                                        <?php echo esc_html( $sub->payment_ref ?? '—' ); ?>
                                    </code>
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
            <div class="ofp-table-wrap ofp-table-responsive">
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
                                <td style="color:<?php echo $tx->type === 'topup' ? 'var(--accent-green)' : 'var(--accent-red)'; ?>">
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
