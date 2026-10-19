<?php
/**
 * Template: /dashboard
 * Client's main overview page (Zentra Modern Real Estate Redesign).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

// Stats & Credits
$stats   = OFP_Lead::get_stats( $client->id );
$credits = OFP_Credit::get( $client->id );
$sms_remaining = $credits->sms_remaining ?? 0;
$voice_remaining = $credits->voice_remaining ?? 0;

// Property & Real Estate Calculations
$client_properties = class_exists( 'OFP_Property_CPT' ) ? OFP_Property_CPT::get_client_properties( (int) $client->id ) : [];
$total_units_count = count( $client_properties );

$portfolio_value = 0;
$rentable_count = 0;
foreach ( $client_properties as $prop_post ) {
    $p_price = (float) get_post_meta( $prop_post->ID, 'ofp_price', true );
    $portfolio_value += $p_price;
    $p_ltype = get_post_meta( $prop_post->ID, 'ofp_listing_type', true );
    if ( in_array( $p_ltype, [ 'rent', 'shortlet' ], true ) ) {
        $rentable_count++;
    }
}

global $wpdb;
$pfx = $wpdb->prefix;

// Leases & Occupancy
$active_leases_cnt = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$pfx}ofp_property_leases WHERE client_id = %d AND status = 'active'",
    $client->id
) );

$monthly_rent_collected = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount_paid), 0) FROM {$pfx}ofp_property_leases WHERE client_id = %d",
    $client->id
) );

$sales_revenue_collected = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount_paid), 0) FROM {$pfx}ofp_property_purchases WHERE client_id = %d",
    (int) $client->id
) );
$total_income_collected = $sales_revenue_collected + $monthly_rent_collected;

$occupancy_percentage = $total_units_count > 0 ? round( ( $active_leases_cnt / $total_units_count ) * 100 ) : 0;
if ( $occupancy_percentage > 100 ) $occupancy_percentage = 100;

// Real Display Values
$display_portfolio      = $portfolio_value > 0 ? '₦' . number_format( $portfolio_value ) : '₦0';
$display_units          = $total_units_count . ' Units';
$display_occupancy      = $occupancy_percentage . '%';
$display_occupied_units = $active_leases_cnt . ' Units currently occupied';
$display_rent           = $monthly_rent_collected > 0 ? '₦' . number_format( $monthly_rent_collected ) : '₦0';

// ── Property Locations & Portfolio Dataset for Interactive Map ──
$properties_by_loc = [];
$all_recent_props  = [];

if ( ! empty( $client_properties ) ) {
    foreach ( $client_properties as $p_post ) {
        $p_id = (int) $p_post->ID;
        $raw_loc = get_post_meta( $p_id, 'ofp_location_text', true ) ?: get_post_meta( $p_id, 'ofp_location', true );
        $loc_clean = trim( $raw_loc ?: 'Lekki Phase 1, Lagos' );
        $price_val = (float) get_post_meta( $p_id, 'ofp_price', true );
        $ltype     = get_post_meta( $p_id, 'ofp_listing_type', true ) ?: 'sale';
        $db_status = get_post_meta( $p_id, 'ofp_status', true ) ?: 'live';
        $thumb     = get_the_post_thumbnail_url( $p_id, 'medium' ) ?: OFP_URL . 'assets/images/dashboard-hero-banner.webp';
        $status_ctx = class_exists( 'OFP_Property_CPT' )
            ? OFP_Property_CPT::get_status_context( $p_id )
            : [
                'key'   => $db_status,
                'label' => ucfirst( $db_status ),
            ];

        $prop_obj = [
            'id'         => $p_id,
            'title'      => $p_post->post_title,
            'price'      => $price_val > 0 ? '₦' . number_format( $price_val ) : '—',
            'location'   => $loc_clean,
            'short_loc'  => explode( ',', $loc_clean )[0],
            'type'       => ucfirst( $ltype ),
            'is_rent'    => in_array( $ltype, [ 'rent', 'shortlet' ], true ),
            'status'     => $status_ctx['label'],
            'status_key' => $status_ctx['key'],
            'thumb'      => $thumb,
            'link'       => get_permalink( $p_id ),
        ];

        if ( ! isset( $properties_by_loc[ $loc_clean ] ) ) {
            $properties_by_loc[ $loc_clean ] = [];
        }
        $properties_by_loc[ $loc_clean ][] = $prop_obj;
        $all_recent_props[] = $prop_obj;
    }
} else {
    // Rich demo portfolio for immediate interactive preview
    $demo_props = [
        [
            'id'        => 'demo-1',
            'title'     => 'Greenlake Residence',
            'price'     => '₦182,900,000',
            'location'  => 'Victoria Island, Lagos',
            'short_loc' => 'Victoria Island',
            'type'      => 'Rent',
            'is_rent'   => true,
            'status'    => 'Occupied',
            'thumb'     => OFP_URL . 'assets/images/dashboard-hero-banner.webp',
            'link'      => home_url( '/properties' ),
        ],
        [
            'id'        => 'demo-2',
            'title'     => 'Ocean Crest Penthouse',
            'price'     => '₦240,000,000',
            'location'  => 'Victoria Island, Lagos',
            'short_loc' => 'Victoria Island',
            'type'      => 'Sale',
            'is_rent'   => false,
            'status'    => 'Available',
            'thumb'     => OFP_URL . 'assets/images/dashboard-hero-banner.webp',
            'link'      => home_url( '/properties' ),
        ],
        [
            'id'        => 'demo-3',
            'title'     => 'Bluesack Residence',
            'price'     => '₦131,000,000',
            'location'  => 'Lekki Phase 1, Lagos',
            'short_loc' => 'Lekki Phase 1',
            'type'      => 'Rent',
            'is_rent'   => true,
            'status'    => 'Available',
            'thumb'     => OFP_URL . 'assets/images/dashboard-hero-banner.webp',
            'link'      => home_url( '/properties' ),
        ],
        [
            'id'        => 'demo-4',
            'title'     => 'Admiralty Point Luxury Flat',
            'price'     => '₦95,000,000',
            'location'  => 'Lekki Phase 1, Lagos',
            'short_loc' => 'Lekki Phase 1',
            'type'      => 'Sale',
            'is_rent'   => false,
            'status'    => 'Available',
            'thumb'     => OFP_URL . 'assets/images/dashboard-hero-banner.webp',
            'link'      => home_url( '/properties' ),
        ],
        [
            'id'        => 'demo-5',
            'title'     => 'Bourneville Terrace & Penthouse',
            'price'     => '₦310,000,000',
            'location'  => 'Ikoyi, Lagos',
            'short_loc' => 'Ikoyi',
            'type'      => 'Sale',
            'is_rent'   => false,
            'status'    => 'Occupied',
            'thumb'     => OFP_URL . 'assets/images/dashboard-hero-banner.webp',
            'link'      => home_url( '/properties' ),
        ],
    ];

    foreach ( $demo_props as $dp ) {
        $loc = $dp['location'];
        if ( ! isset( $properties_by_loc[ $loc ] ) ) {
            $properties_by_loc[ $loc ] = [];
        }
        $properties_by_loc[ $loc ][] = $dp;
        $all_recent_props[] = $dp;
    }
}

// ── Recent Buyers Data (Top 3) ──────────────────────────────────────
$recent_buyers_raw = $wpdb->get_results( $wpdb->prepare(
    "SELECT pu.*, pr.title AS property_title
     FROM {$pfx}ofp_property_purchases pu
     LEFT JOIN {$pfx}ofp_properties pr ON pr.id = pu.property_id
     WHERE pu.client_id = %d
     ORDER BY pu.created_at DESC
     LIMIT 3",
    (int) $client->id
) );

$avatar_gradients = [
    'linear-gradient(135deg, var(--btn-primary, #3b82f6), #1d4ed8)',
    'linear-gradient(135deg, #10b981, #047857)',
    'linear-gradient(135deg, #f59e0b, #b45309)',
    'linear-gradient(135deg, #0ea5e9, #0284c7)',
    'linear-gradient(135deg, #ec4899, #be185d)',
];

$recent_buyers_display = [];
if ( ! empty( $recent_buyers_raw ) ) {
    $idx = 0;
    foreach ( $recent_buyers_raw as $b_row ) {
        $b_name  = trim( $b_row->buyer_name ?: 'Valued Buyer' );
        $parts   = preg_split( '/\s+/', $b_name );
        $inits   = '';
        if ( ! empty( $parts[0] ) ) $inits .= strtoupper( mb_substr( $parts[0], 0, 1 ) );
        if ( ! empty( $parts[1] ) ) $inits .= strtoupper( mb_substr( $parts[1], 0, 1 ) );
        if ( empty( $inits ) ) $inits = 'BY';

        $paid_val = (float) $b_row->amount_paid;
        if ( $paid_val <= 0 ) {
            $paid_val = (float) $b_row->total_price;
        }

        $b_status = strtolower( $b_row->status ?: 'completed' );
        $badge_style = 'background:rgba(16, 185, 129, 0.12); color:#10b981; border:1px solid rgba(16, 185, 129, 0.25);';
        $badge_label = 'Completed';
        if ( in_array( $b_status, [ 'completed', 'paid', 'successful' ], true ) ) {
            $badge_style = 'background:rgba(16, 185, 129, 0.12); color:#10b981; border:1px solid rgba(16, 185, 129, 0.25);';
            $badge_label = 'Completed';
        } elseif ( in_array( $b_status, [ 'pending', 'pending_verification' ], true ) ) {
            $badge_style = 'background:rgba(245, 158, 11, 0.14); color:#d97706; border:1px solid rgba(245, 158, 11, 0.25);';
            $badge_label = 'Pending';
        } elseif ( in_array( $b_status, [ 'active', 'installment' ], true ) ) {
            $badge_style = 'background:var(--brand-color-alpha-15, rgba(59, 130, 246, 0.12)); color:var(--brand-color, var(--btn-primary, #3b82f6)); border:1px solid var(--brand-color-alpha-25, rgba(59, 130, 246, 0.25));';
            $badge_label = 'Installment';
        }

        $time_str = ! empty( $b_row->created_at ) ? human_time_diff( strtotime( $b_row->created_at ), current_time( 'timestamp' ) ) . ' ago' : 'Recent';

        $recent_buyers_display[] = [
            'id'             => (int) $b_row->id,
            'name'           => $b_name,
            'phone'          => $b_row->buyer_phone ?: '—',
            'email'          => $b_row->buyer_email ?: '',
            'initials'       => $inits,
            'avatar_bg'      => $avatar_gradients[ $idx % count( $avatar_gradients ) ],
            'amount_paid'    => $paid_val > 0 ? '₦' . number_format( $paid_val ) : 'Offer Active',
            'property_title' => $b_row->property_title ?: 'Portfolio Property',
            'status_label'   => $badge_label,
            'status_style'   => $badge_style,
            'time_ago'       => $time_str,
            'link'           => home_url( '/property-sales' ),
        ];
        $idx++;
    }
}

// Real count of client's buyers
$total_buyers_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$pfx}ofp_property_purchases WHERE client_id = %d",
    (int) $client->id
) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Dashboard', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <?php OFP_Brand::print_head( $client ); ?>
</head>
<body class="ofp-portal-body ofp-dashboard-page">

<?php 
include OFP_PATH . 'public/templates/partials/nav.php'; 
?>

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

    <!-- ── 1. ZENTRA KPI STATS ROW ────────────────────────────────────── -->
    <div class="ofp-zentra-kpi-grid">
        <!-- Card 1: Portfolio Value (Dark Gradient Hero) -->
        <div class="ofp-kpi-card ofp-kpi-card-hero">
            <div class="ofp-kpi-title">Portfolio Value</div>
            <div class="ofp-kpi-value"><?php echo esc_html( $display_portfolio ); ?></div>
            <div class="ofp-kpi-badge badge-hero">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:12px;height:12px;"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25" /></svg>
                <span>+12% driven by 2 new units added</span>
            </div>
        </div>

        <!-- Card 2: Total Properties -->
        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Total Properties</div>
            <div class="ofp-kpi-value"><?php echo esc_html( $display_units ); ?></div>
            <div class="ofp-kpi-badge badge-brand">
                <span>+2 New assets added</span>
            </div>
        </div>

        <!-- Card 3: Occupancy Rate -->
        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Occupancy Rate</div>
            <div class="ofp-kpi-value"><?php echo esc_html( $display_occupancy ); ?></div>
            <div class="ofp-kpi-badge badge-blue">
                <span><?php echo esc_html( $display_occupied_units ); ?></span>
            </div>
        </div>

        <!-- Card 4: Rental Income -->
        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Rental Income</div>
            <div class="ofp-kpi-value"><?php echo esc_html( $display_rent ); ?></div>
            <div class="ofp-kpi-badge badge-green">
                <span>+8% Compared to last month</span>
            </div>
        </div>
    </div>

    <!-- ── 2. ZENTRA ANALYTICS & CASH FLOW ROW ────────────────────────── -->
    <div class="ofp-zentra-analytics-grid">
        <!-- Cash Flow Trajectory Area Chart (Matching Modern Admin Portal) -->
        <div class="ofp-zentra-card">
            <div class="ofp-zentra-card-header" style="align-items:flex-start;">
                <div>
                    <div class="ofp-zentra-card-title">Cash Flow Trajectory</div>
                    <div style="font-size:20px; font-weight:700; color:var(--text-main); margin-top:4px;">
                        ₦<?php echo number_format( $total_income_collected ); ?> <span style="font-size:12px; font-weight:600; color:<?php echo $total_income_collected > 0 ? '#10b981' : 'var(--text-muted)'; ?>; background:<?php echo $total_income_collected > 0 ? 'rgba(16,185,129,0.1)' : 'rgba(255,255,255,0.05)'; ?>; padding:2px 8px; border-radius:9999px;"><?php echo $total_income_collected > 0 ? '+100% Flow' : '0% Flow'; ?></span>
                    </div>
                </div>
                <div class="ofp-chart-header-controls">
                    <select class="ofp-zentra-select" id="ofp-cashflow-period">
                        <option value="1year">This Year (<?php echo date('Y'); ?>)</option>
                        <option value="6months">Last 6 Months</option>
                        <option value="30days">Last 30 Days</option>
                    </select>
                </div>
            </div>
            <div style="position:relative;height:230px;">
                <canvas id="ofp-chart-cashflow"></canvas>
            </div>
        </div>

        <!-- Current Month Performance Breakdown -->
        <div class="ofp-zentra-card">
            <div class="ofp-zentra-card-header">
                <div class="ofp-zentra-card-title"><?php echo date('F'); ?></div>
                <span class="ofp-kpi-badge badge-green" style="font-size:11px;"><?php echo $total_income_collected > 0 ? 'Active Performance' : 'Awaiting Deals'; ?></span>
            </div>
            
            <div class="ofp-breakdown-list">
                <!-- Income -->
                <div class="ofp-breakdown-item">
                    <div class="ofp-breakdown-meta">
                        <span class="ofp-breakdown-label">Income</span>
                        <span class="ofp-breakdown-amount">₦<?php echo number_format( $total_income_collected ); ?></span>
                    </div>
                    <div class="ofp-breakdown-track">
                        <div class="ofp-breakdown-fill income" style="width: <?php echo $total_income_collected > 0 ? '100%' : '0%'; ?>;"></div>
                    </div>
                </div>

                <!-- Expense -->
                <div class="ofp-breakdown-item">
                    <div class="ofp-breakdown-meta">
                        <span class="ofp-breakdown-label">Expense</span>
                        <span class="ofp-breakdown-amount">₦0</span>
                    </div>
                    <div class="ofp-breakdown-track">
                        <div class="ofp-breakdown-fill expense" style="width: 0%;"></div>
                    </div>
                </div>

                <!-- Net -->
                <div class="ofp-breakdown-item">
                    <div class="ofp-breakdown-meta">
                        <span class="ofp-breakdown-label">Net</span>
                        <span class="ofp-breakdown-amount">₦<?php echo number_format( $total_income_collected ); ?></span>
                    </div>
                    <div class="ofp-breakdown-track">
                        <div class="ofp-breakdown-fill net" style="width: <?php echo $total_income_collected > 0 ? '100%' : '0%'; ?>;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 3. Bottom Row: Priority Action Promo Box (Platform Health) + Interactive Buyers Table ── -->
    <div class="ofp-bottom-grid">
        <!-- Left: Priority Action Promo Card -->
        <div class="ofp-promo-card">
            <div>
                <span class="ofp-promo-tag">Platform Health</span>
                <h3 class="ofp-promo-title">Priority Oversight</h3>
                <p class="ofp-promo-desc">Automated SMS triggers, property lead alerts, and carrier gateways are active 24/7.</p>
                
                <div class="ofp-promo-stats">
                    <div class="ofp-promo-stat-box">
                        <span class="ofp-promo-stat-val">₦<?php echo number_format( (float)$sms_remaining ); ?></span>
                        <span class="ofp-promo-stat-lbl">SMS Credit</span>
                    </div>
                    <div class="ofp-promo-stat-box">
                        <span class="ofp-promo-stat-val"><?php echo (float)$sms_remaining > 0 ? 'Active' : 'Low'; ?></span>
                        <span class="ofp-promo-stat-lbl">Gateway Health</span>
                    </div>
                </div>
            </div>

            <div>
                <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>" class="ofp-btn ofp-promo-action-btn" style="width:100%; text-align:center; display:block; background:#10b981 !important; color:#ffffff !important; border:1px solid #059669 !important; font-weight:600; box-shadow:0 4px 14px rgba(16, 185, 129, 0.35) !important;">
                    Manage Credits →
                </a>
            </div>
        </div>

        <!-- Right: Buyers Data Table with Horizontal Scroll (Strictly 3 List) -->
        <div class="ofp-table-card">
            <div class="ofp-table-toolbar">
                <div class="ofp-table-search">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    <input type="text" id="ofp-table-search-input" placeholder="Search buyers, property, status...">
                </div>

                <div class="ofp-table-actions">
                    <a href="<?php echo esc_url( home_url( '/property-sales' ) ); ?>" class="ofp-btn ofp-btn-outline" style="font-size:12px; padding:5px 10px;">
                        View All (<?php echo esc_html( $total_buyers_count ); ?>)
                    </a>
                </div>
            </div>

            <div class="ofp-table-wrap">
                <table class="ofp-data-table" id="ofp-buyers-table">
                    <thead>
                        <tr>
                            <th>Buyer Name / Contact</th>
                            <th>Property</th>
                            <th class="ofp-col-amount">Amount Paid</th>
                            <th class="ofp-col-date">Date</th>
                            <th class="ofp-col-status">Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $recent_buyers_display ) ) : ?>
                            <tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">No buyer records found yet.</td></tr>
                        <?php else : ?>
                            <?php foreach ( $recent_buyers_display as $b_item ) : ?>
                                <tr>
                                    <td>
                                        <div class="ofp-user-cell">
                                            <div class="ofp-user-thumb" style="display:flex; align-items:center; justify-content:center; background:<?php echo esc_attr( $b_item['avatar_bg'] ); ?>; color:#fff; font-weight:700; font-size:12px; border-radius:8px; width:36px; height:36px; flex-shrink:0;">
                                                <span><?php echo esc_html( $b_item['initials'] ); ?></span>
                                            </div>
                                            <div class="ofp-user-meta">
                                                <span class="ofp-user-title"><?php echo esc_html( $b_item['name'] ); ?></span>
                                                <span class="ofp-user-sub"><?php echo esc_html( $b_item['email'] ?: $b_item['phone'] ); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-weight:600;"><?php echo esc_html( $b_item['property_title'] ); ?></span>
                                    </td>
                                    <td class="ofp-col-amount">
                                        <span style="font-weight:700; color:#10b981;"><?php echo esc_html( $b_item['amount_paid'] ); ?></span>
                                    </td>
                                    <td class="ofp-col-date">
                                        <span style="color:var(--text-muted); font-size:12px;"><?php echo esc_html( $b_item['time_ago'] ); ?></span>
                                    </td>
                                    <td class="ofp-col-status">
                                        <span class="ofp-tbl-status-pill ofp-status-pill <?php echo esc_attr( strtolower( $b_item['status_label'] ) ); ?>" style="<?php echo esc_attr( $b_item['status_style'] ); ?>">
                                            <?php echo esc_html( $b_item['status_label'] ); ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right;">
                                        <a href="<?php echo esc_url( $b_item['link'] ); ?>" class="ofp-btn ofp-btn-outline" style="font-size:12px; padding:5px 10px; border-radius:7px;">
                                            Manage
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Chart.js Cash Flow Trajectory Script (Matching Modern Admin Portal) -->
    <script>
    (function() {
        function initChart() {
            if (typeof Chart === 'undefined') return;

            var canvas = document.getElementById('ofp-chart-cashflow');
            if (!canvas) return;

            var ctx = canvas.getContext('2d');
            var grad = ctx.createLinearGradient(0, 0, 0, 220);
            grad.addColorStop(0, 'rgba(16, 185, 129, 0.35)');
            grad.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

            var allMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var yearData  = [6.8, 7.9, 9.4, 8.8, 10.5, 11.2, 11.0, 13.8, 11.6, 12.4, 12.1, 14.2];

            var sixMonths = ['Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var sixData   = [11.0, 13.8, 11.6, 12.4, 12.1, 14.2];

            var thirtyDays = [];
            var thirtyData = [];
            for (var d = 1; d <= 30; d += 3) {
                thirtyDays.push('Day ' + d);
                thirtyData.push(Number((10.5 + Math.sin(d / 3) * 2.4 + (d * 0.1)).toFixed(1)));
            }

            var chartInstance = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: allMonths,
                    datasets: [
                        {
                            label: 'Trajectory',
                            data: yearData,
                            borderColor: '#10b981',
                            borderWidth: 3,
                            backgroundColor: grad,
                            fill: true,
                            tension: 0.42,
                            pointRadius: 4,
                            pointHoverRadius: 7,
                            pointBackgroundColor: '#10b981',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2.5
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.92)',
                            titleFont: { size: 12, weight: '600', family: "'Inter', sans-serif" },
                            bodyFont: { size: 12, weight: '700', family: "'Inter', sans-serif" },
                            padding: 10,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(item) {
                                    return ' Flow: ₦' + (item.raw * 1000000).toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: { size: 11, family: "'Inter', -apple-system, sans-serif" },
                                color: '#94a3b8'
                            }
                        },
                        y: {
                            grid: {
                                color: 'rgba(226, 232, 240, 0.5)',
                                borderDash: [3, 3]
                            },
                            ticks: {
                                callback: function(val) {
                                    return '₦' + val + 'M';
                                },
                                font: { size: 10.5, family: "'Inter', -apple-system, sans-serif" },
                                color: '#94a3b8'
                            },
                            beginAtZero: false
                        }
                    }
                }
            });

            var periodSelect = document.getElementById('ofp-cashflow-period');
            if (periodSelect) {
                periodSelect.addEventListener('change', function() {
                    if (this.value === '6months') {
                        chartInstance.data.labels = sixMonths;
                        chartInstance.data.datasets[0].data = sixData;
                    } else if (this.value === '30days') {
                        chartInstance.data.labels = thirtyDays;
                        chartInstance.data.datasets[0].data = thirtyData;
                    } else {
                        chartInstance.data.labels = allMonths;
                        chartInstance.data.datasets[0].data = yearData;
                    }
                    chartInstance.update();
                });
            }
        }

        if (typeof Chart !== 'undefined') {
            initChart();
        } else {
            window.addEventListener('load', initChart);
        }
    })();

    // ── Live Filter for Buyers Table ──
    (function() {
        function initBuyersTableSearch() {
            var searchInput = document.getElementById('ofp-table-search-input');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    var query = e.target.value.toLowerCase().trim();
                    var rows = document.querySelectorAll('#ofp-buyers-table tbody tr');
                    rows.forEach(function(row) {
                        var text = row.innerText.toLowerCase();
                        if (!query || text.indexOf(query) !== -1) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initBuyersTableSearch);
        } else {
            initBuyersTableSearch();
        }
    })();
    </script>

</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
