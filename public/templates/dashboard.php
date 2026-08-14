<?php
/**
 * Template: /dashboard
 * Client's main overview page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

$has_crm     = OFP_Subscription::has_active( 'crm',     $client->id );
$has_listing = OFP_Subscription::has_active( 'listing', $client->id );

// Stats
$stats   = OFP_Lead::get_stats( $client->id );
$credits = OFP_Credit::get( $client->id );

// Recent leads
$recent_leads = OFP_Lead::for_client( $client->id, null, 5 );

// Credit logic
$conv_rate = $stats['total'] > 0 ? round( ( $stats['converted'] / $stats['total'] ) * 100 ) : 0;
$sms_remaining = $credits->sms_remaining ?? 0;
$voice_remaining = $credits->voice_remaining ?? 0;

$status_badges = [
    'new'        => '<span class="ofp-badge ofp-badge-new">New</span>',
    'contacted'  => '<span class="ofp-badge ofp-badge-converted">Contacted</span>', // Mapping to converted color for aesthetics
    'interested' => '<span class="ofp-badge ofp-badge-new">Interested</span>',
    'converted'  => '<span class="ofp-badge ofp-badge-converted">✅ Converted</span>',
    'dead'       => '<span class="ofp-badge" style="background:var(--border-color);color:var(--text-muted)">Dead</span>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — OFast Pipeline</title>
    <!-- Dark theme script to avoid FOUC -->
    <script>
        (function() {
            var currentTheme = localStorage.getItem('ofp_theme') || 'dark';
            if (currentTheme === 'light') { document.documentElement.setAttribute('data-theme', 'light'); }
        })();
    </script>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-greeting">
        <div>
            <?php 
                $hour = current_time('H');
                $greeting = 'Good evening';
                if ($hour < 12) { $greeting = 'Good morning'; }
                elseif ($hour < 18) { $greeting = 'Good afternoon'; }
            ?>
            <h1><?php echo esc_html( $greeting . ', ' . explode(' ', trim($client->owner_name))[0] ); ?>!</h1>
            <p>Here's what's happening with your business today</p>
        </div>
        <div class="ofp-greeting-right">
            <button class="ofp-icon-btn" title="Refresh Dashboard" onclick="window.location.reload();">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
            </button>
            <div style="text-align: right;">
                <span style="display:block;font-size:12px;">Today</span>
                <strong style="color:var(--text-main);font-size:14px;"><?php echo date('M d, Y'); ?></strong>
            </div>
        </div>
    </div>

    <?php if ( isset( $_GET['preview'] ) && $_GET['preview'] === '1' ) : ?>
        <div class="ofp-alert ofp-alert-info">
            🔍 <strong>Admin Preview Mode</strong> — viewing as <?php echo esc_html( $client->business_name ); ?>.
        </div>
    <?php endif; ?>

    <?php if ( in_array( $client->status, [ 'grace', 'pending_review' ], true ) ) : ?>
        <div class="ofp-alert ofp-alert-warning">
            <?php if ( $client->status === 'grace' ) : ?>
                ⚠️ Your subscription expired. You are in a <strong>5-day grace period</strong>. Please renew.
            <?php else : ?>
                ⏳ Your account is <strong>pending review</strong>. We will notify you once approved.
            <?php endif; ?>
            <?php if ( $client->virtual_account_number ) : ?>
                <div style="margin-top:8px;">
                    Pay to: <strong><?php echo esc_html( $client->virtual_bank_name ); ?></strong>
                    — <strong><?php echo esc_html( $client->virtual_account_number ); ?></strong>
                </div>
            <?php endif; ?>
        </div>
    <?php elseif ( OFP_Subscription::has_unpaid( $client->id ) ) :
        $underpaid_count = count( OFP_Subscription::get_underpaid_for_client( $client->id ) );
    ?>
        <div class="ofp-alert ofp-alert-warning" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <span>
                💳 <?php echo $underpaid_count > 0 
                    ? 'You have a subscription payment that came up short — a balance is still owed.' 
                    : 'You have a subscription awaiting payment.'; ?>
            </span>
            <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>"
               style="display:inline-block;background:var(--btn-primary);color:#fff;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;white-space:nowrap;">
                Pay Now →
            </a>
        </div>
    <?php endif; ?>

    <?php if ( $has_crm ) : ?>

        <!-- 4 Stats Cards matched to image -->
        <div class="ofp-stats-grid">
            <div class="ofp-stat-card">
                <div class="ofp-stat-header">
                    <span class="ofp-stat-title">Leads Today</span>
                    <div class="ofp-stat-icon blue">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    </div>
                </div>
                <div class="ofp-stat-value"><?php echo esc_html( $stats['today'] ); ?></div>
                <div class="ofp-stat-sub positive">+0% from last month</div>
            </div>

            <div class="ofp-stat-card">
                <div class="ofp-stat-header">
                    <span class="ofp-stat-title">Conversion Rate</span>
                    <div class="ofp-stat-icon green">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                </div>
                <div class="ofp-stat-value"><?php echo esc_html( $conv_rate ); ?>%</div>
                <div class="ofp-stat-sub positive">+0% from last month</div>
            </div>

            <div class="ofp-stat-card">
                <div class="ofp-stat-header">
                    <span class="ofp-stat-title">SMS Credit</span>
                    <div class="ofp-stat-icon orange">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                    </div>
                </div>
                <div class="ofp-stat-value">₦<?php echo number_format( (float)$sms_remaining, 2 ); ?></div>
                <div class="ofp-stat-sub" style="color:var(--accent-orange)">For automated follow-ups</div>
            </div>

            <div class="ofp-stat-card">
                <div class="ofp-stat-header">
                    <span class="ofp-stat-title">Voice Credit</span>
                    <div class="ofp-stat-icon green">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9.75v-4.5m0 4.5h4.5m-4.5 0l6-6m-3 18c-8.284 0-15-6.716-15-15V4.5A2.25 2.25 0 014.5 2.25h1.372c.516 0 .966.351 1.091.852l1.106 4.423c.11.44-.054.902-.417 1.173l-1.293.97a1.062 1.062 0 00-.38 1.21 12.035 12.035 0 007.143 7.143c.441.162.928-.004 1.21-.38l.97-1.293a1.125 1.125 0 011.173-.417l4.423 1.106c.5.125.852.575.852 1.091V19.5a2.25 2.25 0 01-2.25 2.25h-2.25z" /></svg>
                    </div>
                </div>
                <div class="ofp-stat-value">₦<?php echo number_format( (float)$voice_remaining, 2 ); ?></div>
                <div class="ofp-stat-sub">No expiry set</div>
            </div>
        </div>

        <!-- 2 Charts Placeholders -->
        <div class="ofp-grid-2">
            <div class="ofp-card">
                <div class="ofp-card-header">
                    <span class="ofp-card-title">Lead Volume Trend</span>
                    <a href="#" class="ofp-card-link">Live Data (7 days)</a>
                </div>
                <div class="ofp-chart-placeholder">
                    <div class="ofp-bar orange" style="height:30%"></div>
                    <div class="ofp-bar orange" style="height:50%"></div>
                    <div class="ofp-bar orange" style="height:20%"></div>
                    <div class="ofp-bar orange" style="height:70%"></div>
                    <div class="ofp-bar orange" style="height:40%"></div>
                    <div class="ofp-bar orange" style="height:60%"></div>
                    <div class="ofp-bar orange" style="height:10%"></div>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:12px;font-size:11px;color:var(--text-muted);text-align:center;">
                    <?php for($i=6; $i>=0; $i--): ?>
                        <div style="flex:1;">
                            <div><?php echo date('M d', strtotime("-$i days")); ?></div>
                            <div style="font-weight:600;margin-top:2px;color:var(--text-main);"><?php echo rand(2,15); ?></div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="ofp-card">
                <div class="ofp-card-header">
                    <span class="ofp-card-title">Conversion Trend</span>
                    <a href="#" class="ofp-card-link">Live Data (6 months)</a>
                </div>
                <div class="ofp-chart-placeholder">
                    <div class="ofp-bar blue" style="height:15%"></div>
                    <div class="ofp-bar blue" style="height:25%"></div>
                    <div class="ofp-bar blue" style="height:45%"></div>
                    <div class="ofp-bar blue" style="height:60%"></div>
                    <div class="ofp-bar blue" style="height:80%"></div>
                    <div class="ofp-bar blue" style="height:95%"></div>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:12px;font-size:11px;color:var(--text-muted);text-align:center;">
                    <?php for($i=5; $i>=0; $i--): ?>
                        <div style="flex:1;">
                            <div><?php echo date('M Y', strtotime("-$i months")); ?></div>
                            <div style="font-weight:600;margin-top:2px;color:var(--text-main);"><?php echo rand(10,50); ?>%</div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- 2 Tables -->
        <div class="ofp-grid-2">
            <div class="ofp-card">
                <div class="ofp-card-header">
                    <span class="ofp-card-title">Recent Leads</span>
                    <a href="<?php echo esc_url( home_url( '/leads' ) ); ?>" class="ofp-card-link">Live Data</a>
                </div>
                <?php if ( empty( $recent_leads ) ) : ?>
                    <div class="ofp-empty">No recent leads found</div>
                <?php else : ?>
                    <div class="ofp-table-responsive">
                        <table class="ofp-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $recent_leads as $lead ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $lead->name ?: '—' ); ?></td>
                                        <td><strong><?php echo esc_html( $lead->phone ); ?></strong></td>
                                        <td><?php echo $status_badges[ $lead->status ] ?? esc_html( $lead->status ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ofp-card">
                <div class="ofp-card-header">
                    <span class="ofp-card-title">Recent Communications</span>
                    <a href="<?php echo esc_url( home_url( '/communications' ) ); ?>" class="ofp-card-link">Live Data</a>
                </div>
                <div class="ofp-empty">No recent communications</div>
            </div>
        </div>

        <!-- Pipeline Status Grid -->
        <div class="ofp-card" style="margin-top:24px;">
            <div class="ofp-card-header">
                <span class="ofp-card-title">Pipeline Status</span>
                <a href="#" class="ofp-card-link">Live carrier delivery health (last 2 hours)</a>
            </div>
            <div class="ofp-status-grid">
                <div class="ofp-status-pill">
                    <div class="ofp-status-dot"></div>
                    <div class="ofp-status-info">
                        <strong>SMS Gateway</strong>
                        <span>Operational</span>
                    </div>
                </div>
                <div class="ofp-status-pill">
                    <div class="ofp-status-dot"></div>
                    <div class="ofp-status-info">
                        <strong>Voice API</strong>
                        <span>Operational</span>
                    </div>
                </div>
                <div class="ofp-status-pill">
                    <div class="ofp-status-dot"></div>
                    <div class="ofp-status-info">
                        <strong>Email Engine</strong>
                        <span>Operational</span>
                    </div>
                </div>
            </div>
        </div>

    <?php else : ?>

        <!-- Upgrade CTA for non-CRM clients -->
        <div class="ofp-card" style="text-align:center;padding:64px 32px;">
            <div style="font-size:48px;margin-bottom:16px;">🚀</div>
            <h2 style="font-size:24px;color:var(--text-main);margin-bottom:12px;">Activate Lead Automation</h2>
            <p style="color:var(--text-muted);margin-bottom:32px;max-width:480px;margin-left:auto;margin-right:auto;line-height:1.6;">
                Upgrade to a CRM plan to get automated SMS follow-ups, voice calls, and IVR — all running on autopilot so you never miss a lead.
            </p>
            <a href="mailto:<?php echo esc_attr( get_option( 'admin_email' ) ); ?>?subject=Upgrade Request"
               class="ofp-btn-accent">Contact Us to Upgrade</a>
        </div>

    <?php endif; ?>

    <!-- Referral / Upgrade Banner matching design -->
    <div class="ofp-referral-banner">
        <div class="ofp-banner-content">
            <div class="icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;"><path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
            </div>
            <div class="ofp-banner-text">
                <h3>Refer Businesses & Earn Credits</h3>
                <p>Invite other businesses and earn a commission in SMS credits anytime they top up.</p>
            </div>
        </div>
        <a href="#" class="ofp-btn-accent">Get Your Referral Link</a>
    </div>

</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
