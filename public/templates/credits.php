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
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
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

    <div class="ofp-grid-2" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 24px; margin-bottom: 32px;">
        
        <!-- Subscription Status -->
        <div class="ofp-card">
            <div class="ofp-card-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
                <div style="background: rgba(245, 158, 11, 0.1); padding: 10px; border-radius: 12px; display: flex;">
                    <svg width="24" height="24" fill="none" stroke="var(--accent-orange)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 16px; color: var(--text-main);">Subscription Status</h3>
                    <p style="margin: 0; font-size: 13px; color: var(--text-muted);">Current plan and renewal</p>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px;">
                    <div style="font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Account Status</div>
                    <?php
                    $status_colors = [
                        'active'         => 'var(--accent-green)',
                        'grace'          => 'var(--accent-orange)',
                        'pending_review' => 'var(--accent-orange)',
                        'suspended'      => 'var(--accent-red)',
                    ];
                    $bg_colors = [
                        'active'         => 'rgba(16, 185, 129, 0.1)',
                        'grace'          => 'rgba(245, 158, 11, 0.1)',
                        'pending_review' => 'rgba(245, 158, 11, 0.1)',
                        'suspended'      => 'rgba(239, 68, 68, 0.1)',
                    ];
                    $color = $status_colors[ $client->status ] ?? 'var(--text-muted)';
                    $bg_color = $bg_colors[ $client->status ] ?? 'var(--bg-body)';
                    ?>
                    <div style="font-size: 12px; font-weight: 700; color: <?php echo esc_attr( $color ); ?>; background: <?php echo esc_attr( $bg_color ); ?>; padding: 4px 10px; border-radius: 100px;">
                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $client->status ) ) ); ?>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px;">
                    <div style="font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Plan</div>
                    <div style="font-size: 15px; font-weight: 700; color: var(--text-main);">
                        <?php echo esc_html( strtoupper( $client->plan ?: '—' ) ); ?>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Expires</div>
                    <div style="font-size: 15px; font-weight: 600; color: var(--text-main);">
                        <?php echo esc_html( $client->subscription_expires ? gmdate( 'M j, Y', strtotime( $client->subscription_expires ) ) : '—' ); ?>
                    </div>
                </div>
            </div>

            <?php if ( $client->virtual_account_number ) : ?>
                <div style="margin-top: 24px; background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: 12px; padding: 16px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <svg width="16" height="16" fill="none" stroke="var(--accent-green)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 16 16 12 12 8"></polyline><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                        <span style="font-size: 12px; font-weight: 700; color: var(--accent-green); text-transform: uppercase; letter-spacing: 0.05em;">Renewal Account</span>
                    </div>
                    <div style="font-size: 18px; font-weight: 700; color: var(--text-main); margin-bottom: 4px; font-family: monospace;">
                        <?php echo esc_html( $client->virtual_account_number ); ?>
                    </div>
                    <div style="font-size: 13px; color: var(--text-main); margin-bottom: 6px;">
                        <?php echo esc_html( $client->virtual_bank_name ); ?>
                    </div>
                    <div style="font-size: 12px; color: var(--text-muted); line-height: 1.4;">
                        Transfer funds directly here to renew.
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ( OFP_Subscription::has_active( 'crm', $client->id ) ) : ?>
        <!-- Credit Balances -->
        <div class="ofp-card">
            <div class="ofp-card-header" style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
                <div style="background: rgba(139, 92, 246, 0.1); padding: 10px; border-radius: 12px; display: flex;">
                    <svg width="24" height="24" fill="none" stroke="var(--accent-purple)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 16px; color: var(--text-main);">Credit Balances</h3>
                    <p style="margin: 0; font-size: 13px; color: var(--text-muted);">SMS and Voice top-up balances</p>
                </div>
            </div>

            <div class="ofp-credit-bar-wrap">
                <div class="ofp-credit-bar-label">
                    <span style="font-weight: 600; color: var(--text-main); font-size: 14px;">SMS Credit</span>
                    <span style="font-weight: 600; color: var(--text-main); font-size: 14px;">NGN <?php echo number_format( (float) ( $credits->sms_remaining ?? 0 ), 0 ); ?></span>
                </div>
                <div class="ofp-credit-bar-track">
                    <div class="ofp-credit-bar-fill <?php echo $sms_pct > 40 ? 'high' : ( $sms_pct > 15 ? 'medium' : 'low' ); ?>"
                         style="width:<?php echo esc_attr( $sms_pct ); ?>%"></div>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px; color: var(--text-muted); margin-top: 6px;">
                    <span>~<?php echo esc_html( $credits ? floor( $credits->sms_remaining / 6.99 ) : 0 ); ?> messages remaining</span>
                    <span>Loaded: NGN <?php echo number_format( (float) ( $credits->sms_loaded ?? 0 ), 0 ); ?></span>
                </div>
            </div>

            <div class="ofp-credit-bar-wrap" style="margin-top:24px;">
                <div class="ofp-credit-bar-label">
                    <span style="font-weight: 600; color: var(--text-main); font-size: 14px;">Voice Credit</span>
                    <span style="font-weight: 600; color: var(--text-main); font-size: 14px;">NGN <?php echo number_format( (float) ( $credits->voice_remaining ?? 0 ), 0 ); ?></span>
                </div>
                <div class="ofp-credit-bar-track">
                    <div class="ofp-credit-bar-fill <?php echo $voice_pct > 40 ? 'high' : ( $voice_pct > 15 ? 'medium' : 'low' ); ?>"
                         style="width:<?php echo esc_attr( $voice_pct ); ?>%"></div>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 12px; color: var(--text-muted); margin-top: 6px;">
                    <span>~<?php echo esc_html( $credits ? floor( $credits->voice_remaining / 15 ) : 0 ); ?> minutes remaining</span>
                    <span>Loaded: NGN <?php echo number_format( (float) ( $credits->voice_loaded ?? 0 ), 0 ); ?></span>
                </div>
            </div>

            <div style="margin-top: 24px; display: flex; align-items: flex-start; gap: 10px; font-size: 12px; color: var(--text-muted); background: var(--bg-body); padding: 12px; border-radius: 8px;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0; margin-top:1px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                <div style="line-height: 1.4;">
                    Top-up via the <strong>Funding</strong> tab. Balances are updated within the hour. Self-serve coming soon.
                </div>
            </div>
        </div>
        <?php else : ?>
            <div></div> <!-- Empty column to preserve grid if no CRM plan -->
        <?php endif; ?>

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
