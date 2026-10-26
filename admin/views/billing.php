<?php
/**
 * Admin View: Unified Billing, Subscriptions & Credits Hub
 * Merges CRM Subscription Billing, Property Listing Subscriptions,
 * and SMS/Voice Credits management into a single, comprehensive backend interface.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! OFP_Auth::is_admin_user() ) {
    wp_die( esc_html__( 'Access denied.', 'ofast-pipeline' ) );
}

global $wpdb;
$p = $wpdb->prefix;

$is_portal = ( isset( $_GET['tab'] ) || strpos( ( $_SERVER['REQUEST_URI'] ?? '' ), 'admin-portal' ) !== false );
$base_url  = $is_portal ? home_url( '/admin-portal?tab=billing' ) : admin_url( 'admin.php?page=ofp-billing' );

// ── Active View / Subtab ──────────────────────────────────────────────────
$current_view = sanitize_key( $_GET['view'] ?? 'subscriptions' ); // 'subscriptions' or 'credits'
$type_filter  = sanitize_key( $_GET['type'] ?? '' );               // '', 'crm', 'listing'
$status_filter= sanitize_key( $_GET['status'] ?? '' );             // 'pending', 'paid', 'underpaid', 'expired', 'cancelled'
$client_filter= absint( $_GET['client_id'] ?? 0 );
$search_query = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );

$per_page     = 30;
$current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
$offset       = ( $current_page - 1 ) * $per_page;

// ── High-Level Financial Metrics ──────────────────────────────────────────
$total_rev = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount), 0) FROM {$p}ofp_subscriptions WHERE status = 'paid'" );
$crm_rev   = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount), 0) FROM {$p}ofp_subscriptions WHERE status = 'paid' AND type = 'crm'" );
$list_rev  = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount), 0) FROM {$p}ofp_subscriptions WHERE status = 'paid' AND type = 'listing'" );

$month_rev = (float) $wpdb->get_var(
    "SELECT COALESCE(SUM(amount), 0) FROM {$p}ofp_subscriptions
     WHERE status = 'paid' AND MONTH(paid_at) = MONTH(NOW()) AND YEAR(paid_at) = YEAR(NOW())"
);

$pending_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_subscriptions WHERE status = 'pending'" );
$active_subs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_subscriptions WHERE status = 'paid' AND (period_end IS NULL OR period_end >= CURDATE())" );

// SMS and Voice circulation
$total_sms_circ   = (float) $wpdb->get_var( "SELECT COALESCE(SUM(sms_remaining), 0) FROM {$p}ofp_credits" );
$total_voice_circ = (float) $wpdb->get_var( "SELECT COALESCE(SUM(voice_remaining), 0) FROM {$p}ofp_credits" );

$clients = OFP_Client::all();

// ── Subscriptions Query ───────────────────────────────────────────────────
if ( $current_view !== 'credits' ) {
    $where = [ '1=1' ];
    $args  = [];

    if ( in_array( $type_filter, [ 'crm', 'listing', 'whitelabel' ], true ) ) {
        $where[] = 's.type = %s';
        $args[]  = $type_filter;
    }

    if ( in_array( $status_filter, [ 'pending', 'paid', 'underpaid', 'expired', 'cancelled' ], true ) ) {
        $where[] = 's.status = %s';
        $args[]  = $status_filter;
    }

    if ( $client_filter ) {
        $where[] = 's.client_id = %d';
        $args[]  = $client_filter;
    }

    if ( ! empty( $search_query ) ) {
        $like = '%' . $wpdb->esc_like( $search_query ) . '%';
        $where[] = '(c.business_name LIKE %s OR c.owner_name LIKE %s OR c.email LIKE %s OR s.payment_ref LIKE %s OR s.plan LIKE %s)';
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
        $args[] = $like;
    }

    $where_sql = implode( ' AND ', $where );

    $total_items = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(s.id) FROM {$p}ofp_subscriptions s
             JOIN {$p}ofp_clients c ON c.id = s.client_id
             WHERE {$where_sql}",
            ...$args
        )
    );

    $total_pages = max( 1, (int) ceil( $total_items / $per_page ) );

    $subscriptions = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT s.*, c.business_name, c.owner_name, c.email, c.phone, c.status AS client_status, c.is_white_label
             FROM {$p}ofp_subscriptions s
             JOIN {$p}ofp_clients c ON c.id = s.client_id
             WHERE {$where_sql}
             ORDER BY s.created_at DESC
             LIMIT %d OFFSET %d",
            ...array_merge( $args, [ $per_page, $offset ] )
        )
    );
} else {
    // Credits View Query
    $credits_list = $wpdb->get_results(
        "SELECT cr.*, cl.business_name, cl.owner_name, cl.email, cl.plan, cl.status AS client_status, cl.is_white_label
         FROM {$p}ofp_credits cr
         JOIN {$p}ofp_clients cl ON cl.id = cr.client_id
         ORDER BY cr.updated_at DESC"
    );

    $credit_txs = $wpdb->get_results(
        "SELECT tx.*, cl.business_name, cl.owner_name
         FROM {$p}ofp_credit_transactions tx
         JOIN {$p}ofp_clients cl ON cl.id = tx.client_id
         ORDER BY tx.created_at DESC
         LIMIT 40"
    );
}

if ( ! $is_portal && file_exists( OFP_PATH . 'admin/views/partials/header.php' ) ) {
    include OFP_PATH . 'admin/views/partials/header.php';
}
?>

<div class="ofp-unified-billing-wrap wrap">


    <!-- Proper Stat Cards Grid -->
    <div class="ofp-stats-grid">
        <!-- Active Subscribers -->
        <div class="ofp-stat-card">
            <span class="ofp-stat-number" style="color: #059669;"><?php echo esc_html( $active_subs ); ?></span>
            <span class="ofp-stat-label">Active Subscribers</span>
            <span style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Paid &amp; Current Coverage</span>
        </div>

        <!-- Total Revenue -->
        <div class="ofp-stat-card">
            <span class="ofp-stat-number ofp-accent">₦<?php echo esc_html( number_format( $total_rev, 2 ) ); ?></span>
            <span class="ofp-stat-label">Total Revenue</span>
            <span style="font-size: 11.5px; color: #64748b; margin-top: 4px;">This Month: ₦<?php echo esc_html( number_format( $month_rev, 2 ) ); ?></span>
        </div>

        <!-- CRM Revenue -->
        <div class="ofp-stat-card">
            <span class="ofp-stat-number">₦<?php echo esc_html( number_format( $crm_rev, 2 ) ); ?></span>
            <span class="ofp-stat-label">CRM Plans</span>
            <span style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Starter, Silver &amp; Gold</span>
        </div>

        <!-- Listing Memberships Revenue -->
        <div class="ofp-stat-card">
            <span class="ofp-stat-number">₦<?php echo esc_html( number_format( $list_rev, 2 ) ); ?></span>
            <span class="ofp-stat-label">Listing Plans</span>
            <span style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Marketplace Listings</span>
        </div>

        <!-- Pending Payments -->
        <div class="ofp-stat-card">
            <span class="ofp-stat-number" style="color: #d97706;"><?php echo esc_html( $pending_cnt ); ?></span>
            <span class="ofp-stat-label">Awaiting Payment</span>
            <span style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Pending Invoices</span>
        </div>

        <!-- Credit Circulation -->
        <div class="ofp-stat-card">
            <span class="ofp-stat-number" style="font-size: 20px;">₦<?php echo esc_html( number_format( $total_sms_circ, 0 ) ); ?></span>
            <span class="ofp-stat-label">SMS Credits Pool</span>
            <span style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Voice: ₦<?php echo esc_html( number_format( $total_voice_circ, 0 ) ); ?></span>
        </div>
    </div>

    <!-- Unified Segmented Tabs & Active Subscribers Counter -->
    <div class="ofp-ub-tabs-header-bar">
        <!-- In front of the tabs: Active Subscribers pill -->
        <div class="ofp-ub-subscribers-pill">
            <span class="ofp-status-dot-pulse"></span>
            <span><strong><?php echo esc_html( $active_subs ); ?></strong> Active Subscribers</span>
        </div>

        <!-- The Tabs with reduced gaps -->
        <div class="ofp-ub-nav-tabs" id="ofp-billing-tabs">
            <a href="<?php echo esc_url( remove_query_arg( [ 'view', 'type', 'paged' ], $base_url ) ); ?>"
               class="ofp-ub-nav-tab <?php echo ( $current_view === 'subscriptions' && empty( $type_filter ) ) ? 'active' : ''; ?>">
                <span>All Subscriptions</span>
                <span class="count-badge"><?php echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_subscriptions" ); ?></span>
            </a>

            <a href="<?php echo esc_url( add_query_arg( [ 'view' => 'subscriptions', 'type' => 'crm', 'paged' => 1 ], $base_url ) ); ?>"
               class="ofp-ub-nav-tab <?php echo ( $current_view === 'subscriptions' && $type_filter === 'crm' ) ? 'active' : ''; ?>">
                <span>CRM Plans</span>
                <span class="count-badge"><?php echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_subscriptions WHERE type = 'crm'" ); ?></span>
            </a>

            <a href="<?php echo esc_url( add_query_arg( [ 'view' => 'subscriptions', 'type' => 'listing', 'paged' => 1 ], $base_url ) ); ?>"
               class="ofp-ub-nav-tab <?php echo ( $current_view === 'subscriptions' && $type_filter === 'listing' ) ? 'active' : ''; ?>">
                <span>Property Listing Memberships</span>
                <span class="count-badge"><?php echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_subscriptions WHERE type = 'listing'" ); ?></span>
            </a>

            <a href="<?php echo esc_url( add_query_arg( [ 'view' => 'credits' ], $base_url ) ); ?>"
               class="ofp-ub-nav-tab <?php echo $current_view === 'credits' ? 'active' : ''; ?>">
                <span>SMS & Voice Credits</span>
                <span class="count-badge"><?php echo count( $clients ); ?> clients</span>
            </a>
        </div>
    </div>

    <!-- Dynamic AJAX Content Container -->
    <div id="ofp-billing-tab-content" class="ofp-billing-tab-content">
        <?php if ( $current_view === 'credits' ) : ?>
        <!-- ══════════════════════════════════════════════════════════════════════
             CREDITS & BALANCES VIEW
             ══════════════════════════════════════════════════════════════════════ -->
        <div class="ofp-ub-card" style="margin-bottom: 24px;">
            <div class="ofp-ub-card-header">
                <h3>Client Credit Balances & Automation Status</h3>
                <p>Monitor real-time SMS and Voice credit allowances. When remaining balance reaches zero, automated campaigns pause.</p>
            </div>

            <div style="overflow-x: auto;">
                <table class="ofp-ub-table">
                    <thead>
                        <tr>
                            <th>Client / Agency</th>
                            <th>Plan</th>
                            <th style="width: 240px;">SMS Credits Balance</th>
                            <th style="width: 240px;">Voice Credits Balance</th>
                            <th>Automation Status</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $credits_list ) ) : ?>
                            <tr><td colspan="6" style="text-align:center; padding:30px;">No credit accounts initialized yet.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $credits_list as $cr ) :
                                $sms_rem   = (float) $cr->sms_remaining;
                                $sms_load  = (float) $cr->sms_loaded;
                                $sms_pct   = $sms_load > 0 ? min( 100, round( ( $sms_rem / $sms_load ) * 100 ) ) : 0;

                                $voice_rem  = (float) $cr->voice_remaining;
                                $voice_load = (float) $cr->voice_loaded;
                                $voice_pct  = $voice_load > 0 ? min( 100, round( ( $voice_rem / $voice_load ) * 100 ) ) : 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $cr->business_name ); ?></strong>
                                        <div style="font-size:12px; color:#64748b;"><?php echo esc_html( $cr->owner_name ); ?> &bull; <?php echo esc_html( $cr->email ); ?></div>
                                    </td>
                                    <td>
                                        <span class="ofp-badge ofp-badge-crm">
                                            <?php echo ! empty( $cr->is_white_label ) ? 'SOLO / UNLIMITED' : esc_html( strtoupper( $cr->plan ?: 'STARTER' ) ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                                            <span><strong>₦<?php echo esc_html( number_format( $sms_rem, 2 ) ); ?></strong></span>
                                            <span style="color:#64748b;"><?php echo esc_html( $sms_pct ); ?>% left</span>
                                        </div>
                                        <div class="ofp-progress-track">
                                            <div class="ofp-progress-bar <?php echo $sms_pct < 20 ? 'bar-low' : 'bar-good'; ?>" style="width: <?php echo esc_attr( $sms_pct ); ?>%;"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                                            <span><strong>₦<?php echo esc_html( number_format( $voice_rem, 2 ) ); ?></strong></span>
                                            <span style="color:#64748b;"><?php echo esc_html( $voice_pct ); ?>% left</span>
                                        </div>
                                        <div class="ofp-progress-track">
                                            <div class="ofp-progress-bar <?php echo $voice_pct < 20 ? 'bar-low' : 'bar-good'; ?>" style="width: <?php echo esc_attr( $voice_pct ); ?>%;"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ( ! empty( $cr->paused ) ) : ?>
                                            <span class="ofp-ub-status-badge status-declined">Paused (Exhausted)</span>
                                        <?php else : ?>
                                            <span class="ofp-ub-status-badge status-resolved">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <button type="button" class="ofp-ub-btn-topup ofp-open-topup-modal"
                                                data-client-id="<?php echo esc_attr( $cr->client_id ); ?>"
                                                data-client-name="<?php echo esc_attr( $cr->business_name ); ?>">
                                            + Top Up
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Credit Transactions Log -->
        <div class="ofp-ub-card">
            <div class="ofp-ub-card-header">
                <h3>Recent Credit Transaction Log</h3>
                <p>Audit trail of all SMS and Voice credit top-ups and deductions.</p>
            </div>
            <div style="overflow-x: auto;">
                <table class="ofp-ub-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Channel</th>
                            <th>Action Type</th>
                            <th>Amount (NGN)</th>
                            <th>Balance After</th>
                            <th>Reference</th>
                            <th>Date / Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $credit_txs ) ) : ?>
                            <tr><td colspan="7" style="text-align:center; padding:30px;">No credit transactions logged yet.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $credit_txs as $tx ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $tx->business_name ); ?></strong></td>
                                    <td>
                                        <span class="ofp-badge <?php echo $tx->channel === 'sms' ? 'ofp-badge-crm' : 'ofp-badge-listing'; ?>">
                                            <?php echo esc_html( strtoupper( $tx->channel ) ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="ofp-ub-status-badge <?php echo $tx->type === 'topup' ? 'status-resolved' : 'status-review'; ?>">
                                            <?php echo esc_html( ucfirst( $tx->type ) ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong style="color: <?php echo $tx->type === 'topup' ? '#059669' : '#dc2626'; ?>;">
                                            <?php echo $tx->type === 'topup' ? '+' : '-'; ?>₦<?php echo esc_html( number_format( (float) $tx->amount, 2 ) ); ?>
                                        </strong>
                                    </td>
                                    <td>₦<?php echo esc_html( number_format( (float) $tx->balance_after, 2 ) ); ?></td>
                                    <td><code><?php echo esc_html( $tx->reference ?: 'System' ); ?></code></td>
                                    <td style="color:#64748b; font-size:12px;"><?php echo esc_html( date( 'M j, Y g:i a', strtotime( $tx->created_at ) ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else : ?>
        <!-- ══════════════════════════════════════════════════════════════════════
             SUBSCRIPTIONS TABLE VIEW (CRM + LISTING UNIFIED)
             ══════════════════════════════════════════════════════════════════════ -->

        <!-- Filter Bar -->
        <div class="ofp-ub-filter-bar">
            <form method="GET" action="<?php echo esc_url( $base_url ); ?>" class="ofp-ub-filter-form">
                <?php if ( $is_portal ) : ?>
                    <input type="hidden" name="tab" value="billing">
                <?php else : ?>
                    <input type="hidden" name="page" value="ofp-billing">
                <?php endif; ?>

                <?php if ( ! empty( $type_filter ) ) : ?>
                    <input type="hidden" name="type" value="<?php echo esc_attr( $type_filter ); ?>">
                <?php endif; ?>

                <!-- Client Selector -->
                <select name="client_id" class="ofp-ub-select" onchange="this.form.submit()">
                    <option value="">All Clients (<?php echo count( $clients ); ?>)</option>
                    <?php foreach ( $clients as $c ) : ?>
                        <option value="<?php echo esc_attr( $c->id ); ?>" <?php selected( $client_filter, $c->id ); ?>>
                            <?php echo esc_html( $c->business_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Status Selector -->
                <select name="status" class="ofp-ub-select" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="paid" <?php selected( $status_filter, 'paid' ); ?>>Paid</option>
                    <option value="pending" <?php selected( $status_filter, 'pending' ); ?>>Awaiting First Payment (Pending)</option>
                    <option value="underpaid" <?php selected( $status_filter, 'underpaid' ); ?>>Underpaid</option>
                    <option value="expired" <?php selected( $status_filter, 'expired' ); ?>>Expired</option>
                    <option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>>Cancelled</option>
                </select>

                <!-- Search Input -->
                <div class="ofp-ub-search-wrap">
                    <input type="text" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="Search client, plan, ref..." class="ofp-ub-search-input">
                    <button type="submit" class="ofp-ub-search-btn">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    </button>
                </div>

                <?php if ( $client_filter || $status_filter || $search_query || $type_filter ) : ?>
                    <a href="<?php echo esc_url( remove_query_arg( [ 'client_id', 'status', 's', 'type', 'paged' ], $base_url ) ); ?>" class="ofp-ub-reset-btn">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Subscriptions Table Card -->
        <div class="ofp-ub-card">
            <?php if ( empty( $subscriptions ) ) : ?>
                <div class="ofp-ub-empty">
                    <div style="color:#cbd5e1; margin-bottom:12px;">
                        <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                    </div>
                    <h3>No Subscription Records Found</h3>
                    <p>There are no matching billing records found with the applied filters.</p>
                </div>
            <?php else : ?>
                <div style="overflow-x: auto;">
                    <table class="ofp-ub-table">
                        <thead>
                            <tr>
                                <th>Client / Business</th>
                                <th style="width: 130px;">Membership Type</th>
                                <th style="width: 120px;">Plan / Tier</th>
                                <th>Amount (NGN)</th>
                                <th style="width: 140px;">Status</th>
                                <th>Coverage Period</th>
                                <th>Payment Method / Ref</th>
                                <th>Paid At</th>
                                <th style="text-align: right; width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $subscriptions as $sub ) :
                                $is_listing = ( $sub->type === 'listing' );
                                $status_badge_class = match ( $sub->status ) {
                                    'paid'      => 'status-resolved',
                                    'underpaid' => 'status-underpaid',
                                    'expired'   => 'status-declined',
                                    'cancelled' => 'status-declined',
                                    default     => 'status-new',
                                };
                                $status_label = match ( $sub->status ) {
                                    'pending'   => 'Awaiting Payment',
                                    'underpaid' => 'Underpaid',
                                    'expired'   => 'Expired',
                                    'cancelled' => 'Cancelled',
                                    'paid'      => 'Paid',
                                    default     => ucfirst( $sub->status ),
                                };
                            ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $sub->business_name ); ?></strong>
                                        <div style="font-size: 12px; color: #64748b;">
                                            <?php echo esc_html( $sub->owner_name ); ?> &bull; <?php echo esc_html( $sub->email ); ?>
                                        </div>
                                    </td>
                                    <?php 
                                    $is_listing = ( $sub->type === 'listing' );
                                    $rl = OFP_Subscription::row_labels( $sub );
                                    ?>
                                    <td>
                                        <?php if ( $rl['type'] === 'WHITE LABEL' ) : ?>
                                            <span class="ofp-badge" style="background:#e0e7ff;color:#3730a3;font-weight:600;">
                                                White Label
                                            </span>
                                        <?php elseif ( $is_listing ) : ?>
                                            <span class="ofp-badge ofp-badge-listing">
                                                Listing
                                            </span>
                                        <?php else : ?>
                                            <span class="ofp-badge ofp-badge-crm">
                                                CRM Plan
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html( $rl['plan'] ); ?></strong>
                                    </td>
                                    <td>
                                        <strong style="font-size: 14px; color: #0f172a;">₦<?php echo esc_html( number_format( (float) $sub->amount, 2 ) ); ?></strong>
                                    </td>
                                    <td>
                                        <span class="ofp-ub-status-badge <?php echo esc_attr( $status_badge_class ); ?>">
                                            <?php echo esc_html( $status_label ); ?>
                                        </span>
                                        <?php if ( $sub->status === 'underpaid' && ! empty( $sub->expected_amount ) ) :
                                            $shortfall = max( 0, (float) $sub->expected_amount - (float) $sub->amount );
                                        ?>
                                            <div style="font-size: 11px; color: #dc2626; margin-top: 3px;">
                                                Expected ₦<?php echo esc_html( number_format( (float) $sub->expected_amount, 0 ) ); ?><br>
                                                Short ₦<?php echo esc_html( number_format( $shortfall, 0 ) ); ?>
                                            </div>
                                        <?php elseif ( $sub->status === 'paid' && ! empty( $sub->expected_amount ) && (float) $sub->amount > (float) $sub->expected_amount + 1 ) :
                                            $excess = (float) $sub->amount - (float) $sub->expected_amount;
                                        ?>
                                            <div style="font-size: 11px; color: #d97706; margin-top: 3px;">
                                                Overpaid by ₦<?php echo esc_html( number_format( $excess, 0 ) ); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size: 12.5px; color: #334155;">
                                        <?php echo $sub->period_start && $sub->period_end ? esc_html( $sub->period_start . ' → ' . $sub->period_end ) : '—'; ?>
                                    </td>
                                    <td>
                                        <div><span style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b;"><?php echo esc_html( $sub->payment_method ?: 'Online' ); ?></span></div>
                                        <code style="font-size: 11px;"><?php echo esc_html( $sub->payment_ref ?: '—' ); ?></code>
                                    </td>
                                    <td style="font-size: 12.5px; color: #64748b;">
                                        <?php echo $sub->paid_at ? esc_html( date( 'M j, Y g:i a', strtotime( $sub->paid_at ) ) ) : '—'; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <?php if ( $sub->status === 'pending' || $sub->status === 'underpaid' ) : ?>
                                            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                                <?php wp_nonce_field( 'ofp_mark_subscription_paid' ); ?>
                                                <input type="hidden" name="action" value="ofp_mark_subscription_paid">
                                                <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $sub->id ); ?>">
                                                <button type="submit" class="ofp-ub-action-btn" onclick="return confirm('Manually mark this <?php echo esc_js( $is_listing ? 'listing' : 'CRM' ); ?> subscription as PAID?');">
                                                    Mark Paid
                                                </button>
                                            </form>
                                        <?php else : ?>
                                            <span style="color:#94a3b8; font-size:12px;">Settled</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ( $total_pages > 1 ) : ?>
                    <div class="ofp-ub-pagination">
                        <span>Showing <?php echo count( $subscriptions ); ?> of <?php echo esc_html( $total_items ); ?> subscriptions</span>
                        <div class="ofp-ub-page-links">
                            <?php for ( $i = 1; $i <= $total_pages; $i++ ) :
                                $p_url = add_query_arg( [ 'paged' => $i ], $base_url );
                                if ( $type_filter )   $p_url = add_query_arg( [ 'type' => $type_filter ], $p_url );
                                if ( $status_filter ) $p_url = add_query_arg( [ 'status' => $status_filter ], $p_url );
                                if ( $client_filter ) $p_url = add_query_arg( [ 'client_id' => $client_filter ], $p_url );
                                if ( $search_query )  $p_url = add_query_arg( [ 's' => $search_query ], $p_url );
                            ?>
                                <a href="<?php echo esc_url( $p_url ); ?>" class="ofp-ub-page-link <?php echo $i === $current_page ? 'active' : ''; ?>">
                                    <?php echo esc_html( $i ); ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </div> <!-- /#ofp-billing-tab-content -->

</div>

<!-- ── Quick Credit Top-Up Modal ─────────────────────────────────────────── -->
<div class="ofp-ub-modal-backdrop" id="ofp-topup-modal" style="display: none;">
    <div class="ofp-ub-modal-box">
        <div class="ofp-ub-modal-header">
            <h3>Quick Credit Top-Up</h3>
            <button type="button" class="ofp-ub-modal-close" id="ofp-topup-close">&times;</button>
        </div>
        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ofp-ub-modal-form">
            <?php wp_nonce_field( 'ofp_topup_credit' ); ?>
            <input type="hidden" name="action" value="ofp_topup_credit">
            <input type="hidden" name="client_id" id="topup-client-id" value="">

            <div class="ofp-ub-form-group">
                <label>Client</label>
                <input type="text" id="topup-client-name" class="ofp-ub-input" readonly style="background:#f1f5f9; cursor:not-allowed;">
            </div>

            <div class="ofp-ub-form-group">
                <label for="topup-channel">Channel</label>
                <select name="channel" id="topup-channel" class="ofp-ub-select" style="width:100%;" required>
                    <option value="sms">SMS Credit Balance</option>
                    <option value="voice">Voice Call Credit Balance</option>
                </select>
            </div>

            <div class="ofp-ub-form-group">
                <label for="topup-amount">Amount to Add (NGN)</label>
                <input type="number" name="amount" id="topup-amount" min="100" step="100" value="5000" class="ofp-ub-input" required>
                <small style="color:#64748b; font-size:12px;">Minimum NGN 100.</small>
            </div>

            <div class="ofp-ub-form-group">
                <label for="topup-reference">Payment Reference / Note</label>
                <input type="text" name="reference" id="topup-reference" placeholder="e.g. Bank Transfer / Manual Ref" class="ofp-ub-input">
            </div>

            <div class="ofp-ub-modal-footer">
                <button type="button" class="ofp-ub-btn-cancel" id="ofp-topup-cancel">Cancel</button>
                <button type="submit" class="ofp-ub-btn-submit">Load Credits</button>
            </div>
        </form>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal     = document.getElementById('ofp-topup-modal');
    var closeBtn  = document.getElementById('ofp-topup-close');
    var cancelBtn = document.getElementById('ofp-topup-cancel');

    function closeModal() {
        if ( modal ) modal.style.display = 'none';
    }

    if ( closeBtn )  closeBtn.addEventListener('click', closeModal);
    if ( cancelBtn ) cancelBtn.addEventListener('click', closeModal);

    if ( modal ) {
        modal.addEventListener('click', function (e) {
            if ( e.target === modal ) closeModal();
        });
    }

    function bindTopupButtons() {
        document.querySelectorAll('.ofp-open-topup-modal').forEach(function(btn) {
            btn.onclick = function() {
                var cid   = this.getAttribute('data-client-id');
                var cname = this.getAttribute('data-client-name');
                document.getElementById('topup-client-id').value = cid;
                document.getElementById('topup-client-name').value = cname;
                if ( modal ) modal.style.display = 'flex';
            };
        });
    }

    // ── AJAX Tab Switching & History State Management ─────────────────────────
    var tabLinks  = document.querySelectorAll('.ofp-ub-nav-tab');
    var container = document.getElementById('ofp-billing-tab-content');

    function updateActiveTabState(targetUrl) {
        try {
            var parsedTarget = new URL(targetUrl, window.location.origin);
            var targetView = parsedTarget.searchParams.get('view') || 'subscriptions';
            var targetType = parsedTarget.searchParams.get('type') || '';

            tabLinks.forEach(function(tab) {
                var tabUrl = tab.getAttribute('href');
                var parsedTab = new URL(tabUrl, window.location.origin);
                var tabView = parsedTab.searchParams.get('view') || 'subscriptions';
                var tabType = parsedTab.searchParams.get('type') || '';

                if ( tabView === targetView && tabType === targetType ) {
                    tab.classList.add('active');
                } else {
                    tab.classList.remove('active');
                }
            });
        } catch(e) {}
    }

    function bindFilterFormAjax() {
        if ( ! container ) return;
        var filterForm = container.querySelector('.ofp-ub-filter-form');
        if ( filterForm ) {
            filterForm.onsubmit = function(e) {
                e.preventDefault();
                var formData = new FormData(filterForm);
                var params = new URLSearchParams(formData).toString();
                var actionUrl = filterForm.getAttribute('action') || window.location.href.split('?')[0];
                var fullUrl = actionUrl + (actionUrl.indexOf('?') !== -1 ? '&' : '?') + params;
                loadTabUrl(fullUrl, true);
            };

            // Dropdowns onchange AJAX trigger
            container.querySelectorAll('.ofp-ub-select').forEach(function(sel) {
                sel.onchange = function() {
                    filterForm.dispatchEvent(new Event('submit', { cancelable: true }));
                };
            });
        }

        // Pagination and Reset links inside container
        container.querySelectorAll('.ofp-ub-page-link, .ofp-ub-reset-btn').forEach(function(link) {
            link.onclick = function(e) {
                var href = this.getAttribute('href');
                if ( href && href.indexOf('javascript') === -1 ) {
                    e.preventDefault();
                    loadTabUrl(href, true);
                }
            };
        });
    }

    function loadTabUrl(url, updateHistory) {
        if ( ! container ) return;
        container.classList.add('is-loading');

        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) {
            if ( ! res.ok ) throw new Error('HTTP ' + res.status);
            return res.text();
        })
        .then(function(html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var newContent = doc.getElementById('ofp-billing-tab-content');

            if ( newContent ) {
                container.innerHTML = newContent.innerHTML;
            } else {
                var newWrap = doc.querySelector('.ofp-unified-billing-wrap');
                if ( newWrap ) {
                    var inner = newWrap.querySelector('#ofp-billing-tab-content');
                    if ( inner ) container.innerHTML = inner.innerHTML;
                }
            }

            container.classList.remove('is-loading');

            if ( updateHistory && window.history && window.history.pushState ) {
                window.history.pushState({ ofpBillingUrl: url }, '', url);
            }

            updateActiveTabState(url);
            bindTopupButtons();
            bindFilterFormAjax();
        })
        .catch(function(err) {
            container.classList.remove('is-loading');
            console.warn('AJAX Tab fallback navigation:', err);
            window.location.href = url;
        });
    }

    // Attach click listener to tab links
    tabLinks.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            var url = this.getAttribute('href');
            if ( url ) {
                loadTabUrl(url, true);
            }
        });
    });

    // Browser back/forward navigation support
    window.addEventListener('popstate', function(e) {
        if ( e.state && e.state.ofpBillingUrl ) {
            loadTabUrl(e.state.ofpBillingUrl, false);
        } else {
            loadTabUrl(window.location.href, false);
        }
    });

    bindTopupButtons();
    bindFilterFormAjax();
});
</script>

<?php
if ( ! $is_portal && file_exists( OFP_PATH . 'admin/views/partials/footer.php' ) ) {
    include OFP_PATH . 'admin/views/partials/footer.php';
}
?>
