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

if ( ! empty( $client_properties ) ) {
    update_meta_cache( 'post', wp_list_pluck( $client_properties, 'ID' ) );
}

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

// Leases & Occupancy - Aggregated in a single query
$lease_stats = $wpdb->get_row( $wpdb->prepare(
    "SELECT 
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_cnt,
        COALESCE(SUM(amount_paid), 0) AS total_rent
     FROM {$pfx}ofp_property_leases 
     WHERE client_id = %d",
    (int) $client->id
) );
$active_leases_cnt      = (int) ( $lease_stats->active_cnt ?? 0 );
$monthly_rent_collected = (float) ( $lease_stats->total_rent ?? 0.0 );

$sales_revenue_collected = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(amount_paid), 0) FROM {$pfx}ofp_property_purchases WHERE client_id = %d",
    (int) $client->id
) );
$total_income_collected = $sales_revenue_collected + $monthly_rent_collected;

// Operational Expenses from Communications & Gateway Log
$monthly_expenses = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(cost), 0) FROM {$pfx}ofp_communications_log WHERE client_id = %d",
    (int) $client->id
) );
$net_revenue = max( 0.0, $total_income_collected - $monthly_expenses );

// Outstanding / Pending Receivables (Active Lease Balances + Unpaid Installments)
$pending_lease_due = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(balance), 0) FROM {$pfx}ofp_property_leases WHERE client_id = %d AND status = 'active'",
    (int) $client->id
) );
$pending_sales_due = (float) $wpdb->get_var( $wpdb->prepare(
    "SELECT COALESCE(SUM(GREATEST(0, total_price - amount_paid)), 0) FROM {$pfx}ofp_property_purchases WHERE client_id = %d AND status IN ('active', 'pending', 'installment')",
    (int) $client->id
) );
$total_pending_receivables = $pending_lease_due + $pending_sales_due;

// Collection / Recovery Rate
$total_billed    = $total_income_collected + $total_pending_receivables;
$collection_rate = $total_billed > 0 ? round( ( $total_income_collected / $total_billed ) * 100 ) : ( $total_income_collected > 0 ? 100 : 0 );
if ( $collection_rate > 100 ) $collection_rate = 100;

$income_pct  = $total_billed > 0 ? min( 100, max( 0, round( ( $total_income_collected / $total_billed ) * 100 ) ) ) : ( $total_income_collected > 0 ? 100 : 0 );
$pending_pct = $total_billed > 0 ? min( 100, max( 0, round( ( $total_pending_receivables / $total_billed ) * 100 ) ) ) : 0;
$expense_pct = $total_income_collected > 0 ? min( 100, max( 0, round( ( $monthly_expenses / $total_income_collected ) * 100 ) ) ) : ( $monthly_expenses > 0 ? 100 : 0 );
$net_pct     = $total_income_collected > 0 ? min( 100, max( 0, round( ( $net_revenue / $total_income_collected ) * 100 ) ) ) : 0;

$occupancy_percentage = $total_units_count > 0 ? round( ( $active_leases_cnt / $total_units_count ) * 100 ) : 0;
if ( $occupancy_percentage > 100 ) $occupancy_percentage = 100;

// Real Display Values
$display_portfolio      = $portfolio_value > 0 ? '₦' . number_format( $portfolio_value ) : '₦0';
$display_units          = $total_units_count . ' Units';
$display_occupancy      = $occupancy_percentage . '%';
$display_occupied_units = $active_leases_cnt . ' Units currently occupied';
$display_rent           = $monthly_rent_collected > 0 ? '₦' . number_format( $monthly_rent_collected ) : '₦0';

// ── Real Net Cash Flow Trajectory Datasets (Inflow minus Outflow) ─────
$cf_current_year = (int) current_time( 'Y' );

// 1. Monthly Sales Inflow
$cf_monthly_sales = array_fill( 1, 12, 0.0 );
$cf_sales_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT MONTH(created_at) AS m, COALESCE(SUM(amount_paid), 0) AS val
     FROM {$pfx}ofp_property_purchases
     WHERE client_id = %d AND YEAR(created_at) = %d
     GROUP BY m",
    (int) $client->id, $cf_current_year
) );
foreach ( (array) $cf_sales_rows as $sr ) {
    $m = (int) $sr->m;
    if ( isset( $cf_monthly_sales[ $m ] ) ) {
        $cf_monthly_sales[ $m ] = (float) $sr->val;
    }
}
$cf_pay_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT MONTH(py.created_at) AS m, COALESCE(SUM(py.amount), 0) AS val
     FROM {$pfx}ofp_property_payments py
     INNER JOIN {$pfx}ofp_property_purchases pu ON pu.id = py.purchase_id
     WHERE pu.client_id = %d AND py.status IN ('successful', 'verified', 'paid')
       AND YEAR(py.created_at) = %d
     GROUP BY m",
    (int) $client->id, $cf_current_year
) );
if ( ! empty( $cf_pay_rows ) ) {
    foreach ( $cf_pay_rows as $pr ) {
        $m = (int) $pr->m;
        if ( isset( $cf_monthly_sales[ $m ] ) ) {
            $cf_monthly_sales[ $m ] = (float) $pr->val;
        }
    }
}

// 2. Monthly Rent Inflow
$cf_monthly_rent = array_fill( 1, 12, 0.0 );
$cf_rent_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT MONTH(py.created_at) AS m, COALESCE(SUM(py.amount), 0) AS val
     FROM {$pfx}ofp_property_lease_payments py
     INNER JOIN {$pfx}ofp_property_leases l ON l.id = py.lease_id
     WHERE l.client_id = %d AND py.status IN ('successful', 'verified', 'paid', 'approved')
       AND YEAR(py.created_at) = %d
     GROUP BY m",
    (int) $client->id, $cf_current_year
) );
foreach ( (array) $cf_rent_rows as $rr ) {
    $m = (int) $rr->m;
    if ( isset( $cf_monthly_rent[ $m ] ) ) {
        $cf_monthly_rent[ $m ] = (float) $rr->val;
    }
}

// 3. Monthly Operational / Credit Outflow
$cf_monthly_outflow = array_fill( 1, 12, 0.0 );
$cf_outflow_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT MONTH(sent_at) AS m, COALESCE(SUM(cost), 0) AS val
     FROM {$pfx}ofp_communications_log
     WHERE client_id = %d AND YEAR(sent_at) = %d
     GROUP BY m",
    (int) $client->id, $cf_current_year
) );
foreach ( (array) $cf_outflow_rows as $or ) {
    $m = (int) $or->m;
    if ( isset( $cf_monthly_outflow[ $m ] ) ) {
        $cf_monthly_outflow[ $m ] = (float) $or->val;
    }
}

// 4. Full Year Net Cash Flow (Jan - Dec)
$cf_year_labels = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
$cf_year_data   = [];
for ( $m = 1; $m <= 12; $m++ ) {
    $inflow  = $cf_monthly_sales[ $m ] + $cf_monthly_rent[ $m ];
    $outflow = $cf_monthly_outflow[ $m ];
    $cf_year_data[] = round( $inflow - $outflow, 2 );
}

// 5. Last 6 Months Net Cash Flow
$cf_six_labels = [];
$cf_six_data   = [];
for ( $i = 5; $i >= 0; $i-- ) {
    $time_i = strtotime( "-{$i} months", current_time( 'timestamp' ) );
    $m_num  = (int) date( 'n', $time_i );
    $cf_six_labels[] = date( 'M', $time_i );
    $cf_six_data[]   = isset( $cf_year_data[ $m_num - 1 ] ) ? $cf_year_data[ $m_num - 1 ] : 0.0;
}

// 6. Last 30 Days Net Cash Flow
$cf_thirty_labels = [];
$cf_thirty_data   = [];
$thirty_days_ago  = date( 'Y-m-d H:i:s', strtotime( '-30 days', current_time( 'timestamp' ) ) );

$d_sales_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT DATE(created_at) AS dt, COALESCE(SUM(amount_paid), 0) AS val
     FROM {$pfx}ofp_property_purchases
     WHERE client_id = %d AND created_at >= %s
     GROUP BY dt",
    (int) $client->id, $thirty_days_ago
) );
$d_sales_map = [];
foreach ( (array) $d_sales_rows as $ds ) {
    $d_sales_map[ $ds->dt ] = (float) $ds->val;
}

$d_rent_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT DATE(py.created_at) AS dt, COALESCE(SUM(py.amount), 0) AS val
     FROM {$pfx}ofp_property_lease_payments py
     INNER JOIN {$pfx}ofp_property_leases l ON l.id = py.lease_id
     WHERE l.client_id = %d AND py.created_at >= %s
     GROUP BY dt",
    (int) $client->id, $thirty_days_ago
) );
$d_rent_map = [];
foreach ( (array) $d_rent_rows as $dr ) {
    $d_rent_map[ $dr->dt ] = (float) $dr->val;
}

$d_cost_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT DATE(sent_at) AS dt, COALESCE(SUM(cost), 0) AS val
     FROM {$pfx}ofp_communications_log
     WHERE client_id = %d AND sent_at >= %s
     GROUP BY dt",
    (int) $client->id, $thirty_days_ago
) );
$d_cost_map = [];
foreach ( (array) $d_cost_rows as $dc ) {
    $d_cost_map[ $dc->dt ] = (float) $dc->val;
}

for ( $d = 27; $d >= 0; $d -= 3 ) {
    $dt_ts  = strtotime( "-{$d} days", current_time( 'timestamp' ) );
    $dt_str = date( 'Y-m-d', $dt_ts );
    $lbl    = date( 'M j', $dt_ts );
    $inflow = ( $d_sales_map[ $dt_str ] ?? 0.0 ) + ( $d_rent_map[ $dt_str ] ?? 0.0 );
    $outflow = $d_cost_map[ $dt_str ] ?? 0.0;
    $cf_thirty_labels[] = $lbl;
    $cf_thirty_data[]   = round( $inflow - $outflow, 2 );
}

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

// Uniform avatar style for consistent fintech presentation
$avatar_bg    = 'rgba(59, 130, 246, 0.12)';
$avatar_color = 'var(--btn-primary, #3b82f6)';
$avatar_bdr   = 'rgba(59, 130, 246, 0.25)';

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
            $badge_style = 'background:rgba(0, 188, 181, 0.12); color:#00bcb5; border:1px solid rgba(0, 188, 181, 0.25);';
            $badge_label = 'Installment';
        }

        $time_str = ! empty( $b_row->created_at ) ? human_time_diff( strtotime( $b_row->created_at ), current_time( 'timestamp' ) ) . ' ago' : 'Recent';

        $recent_buyers_display[] = [
            'id'             => (int) $b_row->id,
            'name'           => $b_name,
            'phone'          => $b_row->buyer_phone ?: '—',
            'email'          => $b_row->buyer_email ?: '',
            'initials'       => $inits,
            'avatar_bg'      => $avatar_bg,
            'avatar_color'   => $avatar_color,
            'avatar_border'  => $avatar_bdr,
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
                <span>+12% Portfolio growth</span>
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
                        ₦<?php echo number_format( $net_revenue ); ?> <span style="font-size:12px; font-weight:600; color:<?php echo $net_revenue > 0 ? '#10b981' : 'var(--text-muted)'; ?>; background:<?php echo $net_revenue > 0 ? 'rgba(16,185,129,0.1)' : 'rgba(255,255,255,0.05)'; ?>; padding:2px 8px; border-radius:9999px;"><?php echo $net_revenue > 0 ? 'Net Flow' : '0.00 Flow'; ?></span>
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
                <div class="ofp-zentra-card-title"><?php echo date('F'); ?> Performance</div>
            </div>
            
            <div class="ofp-breakdown-list">
                <!-- 1. Income (Total Collected) with Sub-Split (Sales vs Rent) -->
                <div class="ofp-breakdown-item">
                    <div class="ofp-breakdown-meta">
                        <span class="ofp-breakdown-label">Gross Income Collected</span>
                        <span class="ofp-breakdown-amount">₦<?php echo number_format( $total_income_collected ); ?></span>
                    </div>
                    <div class="ofp-breakdown-track">
                        <div class="ofp-breakdown-fill income" style="width: <?php echo esc_attr( $income_pct ); ?>%;"></div>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted); margin-top:2px;">
                        <span>Sales: <strong style="color:var(--text-main);">₦<?php echo number_format( $sales_revenue_collected ); ?></strong></span>
                        <span>Rent: <strong style="color:var(--text-main);">₦<?php echo number_format( $monthly_rent_collected ); ?></strong></span>
                    </div>
                </div>

                <!-- 2. Credit Expenses (Comms & Gateway Spend) -->
                <div class="ofp-breakdown-item">
                    <div class="ofp-breakdown-meta">
                        <span class="ofp-breakdown-label">Credit Expenses</span>
                        <span class="ofp-breakdown-amount">₦<?php echo number_format( $monthly_expenses, 2 ); ?></span>
                    </div>
                    <div class="ofp-breakdown-track">
                        <div class="ofp-breakdown-fill expense" style="width: <?php echo esc_attr( $expense_pct ); ?>%;"></div>
                    </div>
                </div>

                <!-- 3. Net Inflow -->
                <div class="ofp-breakdown-item">
                    <div class="ofp-breakdown-meta">
                        <span class="ofp-breakdown-label">Net Inflow</span>
                        <span class="ofp-breakdown-amount">₦<?php echo number_format( $net_revenue ); ?></span>
                    </div>
                    <div class="ofp-breakdown-track">
                        <div class="ofp-breakdown-fill net" style="width: <?php echo esc_attr( $net_pct ); ?>%;"></div>
                    </div>
                </div>

                <!-- 4. Pending Dues -->
                <div class="ofp-breakdown-item">
                    <div class="ofp-breakdown-meta">
                        <span class="ofp-breakdown-label">Pending Dues</span>
                        <span class="ofp-breakdown-amount">₦<?php echo number_format( $total_pending_receivables ); ?></span>
                    </div>
                    <div class="ofp-breakdown-track">
                        <div class="ofp-breakdown-fill pending" style="width: <?php echo esc_attr( $pending_pct ); ?>%;"></div>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--text-muted); margin-top:2px;">
                        <span>Installments: <strong style="color:var(--text-main);">₦<?php echo number_format( $pending_sales_due ); ?></strong></span>
                        <span>Lease Balances: <strong style="color:var(--text-main);">₦<?php echo number_format( $pending_lease_due ); ?></strong></span>
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
                                            <div class="ofp-user-thumb" style="display:flex; align-items:center; justify-content:center; background:<?php echo esc_attr( $b_item['avatar_bg'] ); ?>; color:<?php echo esc_attr( $b_item['avatar_color'] ); ?>; border:1px solid <?php echo esc_attr( $b_item['avatar_border'] ); ?>; font-weight:700; font-size:12px; border-radius:8px; width:36px; height:36px; flex-shrink:0;">
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

            var allMonths  = <?php echo json_encode( $cf_year_labels ); ?>;
            var yearData   = <?php echo json_encode( $cf_year_data ); ?>;

            var sixMonths  = <?php echo json_encode( $cf_six_labels ); ?>;
            var sixData    = <?php echo json_encode( $cf_six_data ); ?>;

            var thirtyDays = <?php echo json_encode( $cf_thirty_labels ); ?>;
            var thirtyData = <?php echo json_encode( $cf_thirty_data ); ?>;

            var chartInstance = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: allMonths,
                    datasets: [
                        {
                            label: 'Net Cash Flow',
                            data: yearData,
                            borderColor: '#10b981',
                            borderWidth: 2.5,
                            backgroundColor: grad,
                            fill: true,
                            tension: 0.35,
                            pointRadius: 3.5,
                            pointHoverRadius: 6,
                            pointBackgroundColor: '#10b981',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2
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
                                    return ' Net Flow: ₦' + Number(item.raw).toLocaleString();
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
                                color: 'rgba(226, 232, 240, 0.15)',
                                borderDash: [3, 3]
                            },
                            ticks: {
                                callback: function(val) {
                                    if (Math.abs(val) >= 1000000) {
                                        return '₦' + (val / 1000000).toFixed(1) + 'M';
                                    } else if (Math.abs(val) >= 1000) {
                                        return '₦' + (val / 1000).toFixed(0) + 'k';
                                    }
                                    return '₦' + val;
                                },
                                font: { size: 10.5, family: "'Inter', -apple-system, sans-serif" },
                                color: '#94a3b8'
                            },
                            beginAtZero: true
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
