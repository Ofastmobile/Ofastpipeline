<?php
/**
 * Template: /dashboard
 * Client's main overview page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );


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
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
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
            <p>Here's what's happening with your agency today</p>
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

    <?php $ofp_current_user = OFP_Auth::current_user(); ?>
    <?php if ( empty( $ofp_current_user->is_team_member ) && OFP_Subscription::client_plan( $client->id ) === 'free' ) : ?>
        <div class="ofp-alert ofp-alert-info" id="ofp-free-plan-banner" style="display:none;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
            <span>
                ✨ You're on the <strong>Free</strong> plan. Upgrade to unlock more listings with priority
                placement, team member seats, full email templates, and (on Gold) editable installment plans
                for buyers.
            </span>
            <span style="display:flex;gap:10px;align-items:center;white-space:nowrap;">
                <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>"
                   style="display:inline-block;background:var(--btn-primary);color:#fff;padding:8px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;">
                    See Plans →
                </a>
                <button type="button" id="ofp-free-plan-banner-dismiss"
                        style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:18px;line-height:1;padding:0 4px;"
                        title="Dismiss for today" aria-label="Dismiss">&times;</button>
            </span>
        </div>
        <script>
            (function() {
                var key = 'ofp_free_plan_banner_dismissed_until';
                var until = parseInt(localStorage.getItem(key) || '0', 10);
                var banner = document.getElementById('ofp-free-plan-banner');
                if (Date.now() > until) {
                    banner.style.display = 'flex';
                }
                document.getElementById('ofp-free-plan-banner-dismiss').addEventListener('click', function() {
                    banner.style.display = 'none';
                    localStorage.setItem(key, String(Date.now() + 24 * 60 * 60 * 1000));
                });
            })();
        </script>
    <?php endif; ?>


        <div class="ofp-stats-grid">
            <div class="ofp-stat-card">
                <div class="ofp-stat-header">
                    <span class="ofp-stat-title">Prospects Today</span>
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

        <!-- 2 Charts (real data via AJAX) -->
        <div class="ofp-grid-2">
            <div class="ofp-card">
                <div class="ofp-card-header">
                    <span class="ofp-card-title">Prospect Volume Trend</span>
                    <span class="ofp-card-link">Live Data (7 days)</span>
                </div>
                <div style="position:relative;height:220px;">
                    <canvas id="ofp-chart-leads"></canvas>
                </div>
            </div>

            <div class="ofp-card">
                <div class="ofp-card-header">
                    <span class="ofp-card-title">Conversion Trend</span>
                    <span class="ofp-card-link">Live Data (6 months)</span>
                </div>
                <div style="position:relative;height:220px;">
                    <canvas id="ofp-chart-conversion"></canvas>
                </div>
            </div>
        </div>

        <script>
        (function() {
            if ( typeof ofpClientData === 'undefined' || typeof Chart === 'undefined' ) return;

            var body = new URLSearchParams();
            body.append('action', 'ofp_dashboard_chart_data');
            body.append('nonce', ofpClientData.nonce);

            fetch(ofpClientData.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) return;

                    var daily = res.data.daily;
                    var monthly = res.data.monthly;

                    new Chart(document.getElementById('ofp-chart-leads'), {
                        type: 'bar',
                        data: {
                            labels: daily.map(function(d) { return d.label; }),
                            datasets: [{
                                label: 'Leads',
                                data: daily.map(function(d) { return d.count; }),
                                backgroundColor: '#f97316',
                                borderRadius: 6,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                        }
                    });

                    new Chart(document.getElementById('ofp-chart-conversion'), {
                        type: 'line',
                        data: {
                            labels: monthly.map(function(m) { return m.label; }),
                            datasets: [{
                                label: 'Conversion %',
                                data: monthly.map(function(m) { return m.rate; }),
                                borderColor: '#3b82f6',
                                backgroundColor: 'rgba(59,130,246,0.15)',
                                fill: true,
                                tension: 0.3,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, max: 100, ticks: { callback: function(v) { return v + '%'; } } } }
                        }
                    });
                })
                .catch(function(err) { console.error('OFP chart data error:', err); });
        })();
        </script>

        <!-- 2 Tables -->
        <div class="ofp-grid-2">
            <div class="ofp-card">
                <div class="ofp-card-header">
                    <span class="ofp-card-title">Recent Prospects</span>
                    <a href="<?php echo esc_url( home_url( '/leads' ) ); ?>" class="ofp-card-link">Live Data</a>
                </div>
                <?php if ( empty( $recent_leads ) ) : ?>
                    <div class="ofp-empty">No recent prospects found</div>
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


    <!-- Referral / Upgrade Banner matching design -->
    <div class="ofp-referral-banner">
        <div class="ofp-banner-content">
            <div class="icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;"><path stroke-linecap="round" stroke-linejoin="round" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
            </div>
            <div class="ofp-banner-text">
                <h3>Refer Agents & Earn Credits</h3>
                <p>Invite other agents and earn a commission in SMS credits anytime they top up.</p>
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
