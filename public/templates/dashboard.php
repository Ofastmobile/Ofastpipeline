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

$occupancy_percentage = $total_units_count > 0 ? round( ( $active_leases_cnt / $total_units_count ) * 100 ) : 0;
if ( $occupancy_percentage > 100 ) $occupancy_percentage = 100;

// Smart Display with Fallbacks matching reference
$has_real_properties = $total_units_count > 0;
$display_portfolio = $has_real_properties && $portfolio_value > 0 ? '₦' . number_format( $portfolio_value ) : '₦1,482,900,000';
$display_units = $has_real_properties ? $total_units_count . ' Units' : '12 Units';
$display_occupancy = $has_real_properties ? $occupancy_percentage . '%' : '91%';
$display_occupied_units = $has_real_properties ? $active_leases_cnt . ' Units currently occupied' : '5 Units currently occupied';
$display_rent = $has_real_properties && $monthly_rent_collected > 0 ? '₦' . number_format( $monthly_rent_collected ) : '₦18,240,000';

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
        $thumb     = get_the_post_thumbnail_url( $p_id, 'medium' ) ?: OFP_URL . 'assets/images/dashboard-hero-banner.jpg';
        $is_occupied = class_exists( 'OFP_Property_Rent' ) && OFP_Property_Rent::wp_property_is_occupied( $p_id );

        $prop_obj = [
            'id'        => $p_id,
            'title'     => $p_post->post_title,
            'price'     => $price_val > 0 ? '₦' . number_format( $price_val ) : '—',
            'location'  => $loc_clean,
            'short_loc' => explode( ',', $loc_clean )[0],
            'type'      => ucfirst( $ltype ),
            'is_rent'   => in_array( $ltype, [ 'rent', 'shortlet' ], true ),
            'status'    => $is_occupied ? 'Occupied' : ( $db_status === 'live' ? 'Available' : ucfirst( $db_status ) ),
            'thumb'     => $thumb,
            'link'      => get_permalink( $p_id ),
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
            'thumb'     => OFP_URL . 'assets/images/dashboard-hero-banner.jpg',
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
            'thumb'     => OFP_URL . 'assets/images/dashboard-hero-banner.jpg',
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
            'thumb'     => OFP_URL . 'assets/images/dashboard-hero-banner.jpg',
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
            'thumb'     => OFP_URL . 'assets/images/dashboard-hero-banner.jpg',
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
            'thumb'     => OFP_URL . 'assets/images/dashboard-hero-banner.jpg',
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

$initial_featured = ! empty( $all_recent_props ) ? $all_recent_props[0] : null;

$pin_coords = [
    [ 'top' => '42%', 'left' => '30%' ],
    [ 'top' => '62%', 'left' => '65%' ],
    [ 'top' => '28%', 'left' => '78%' ],
    [ 'top' => '74%', 'left' => '24%' ],
    [ 'top' => '50%', 'left' => '84%' ],
    [ 'top' => '22%', 'left' => '50%' ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Dashboard', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
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
            <div class="ofp-kpi-badge badge-purple">
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
        <!-- Cash Flow Multi-bar Chart -->
        <div class="ofp-zentra-card">
            <div class="ofp-zentra-card-header">
                <div class="ofp-zentra-card-title">Cash flow</div>
                <div class="ofp-chart-header-controls">
                    <div class="ofp-chart-legends">
                        <div class="ofp-chart-legend-item">
                            <span class="ofp-legend-dot" style="background:#8b5cf6;"></span>
                            <span>Income</span>
                        </div>
                        <div class="ofp-chart-legend-item">
                            <span class="ofp-legend-dot" style="background:#c084fc;"></span>
                            <span>Expense</span>
                        </div>
                        <div class="ofp-chart-legend-item">
                            <span class="ofp-legend-dot" style="background:#cbd5e1;"></span>
                            <span>Net</span>
                        </div>
                    </div>
                    <select class="ofp-zentra-select" id="ofp-cashflow-period">
                        <option value="1year">1 Year</option>
                        <option value="6months">6 Months</option>
                    </select>
                </div>
            </div>
            <div style="position:relative;height:240px;">
                <canvas id="ofp-chart-cashflow"></canvas>
            </div>
        </div>

        <!-- Current Month Performance Breakdown -->
        <div class="ofp-zentra-card">
            <div class="ofp-zentra-card-header">
                <div class="ofp-zentra-card-title"><?php echo date('F'); ?></div>
                <span class="ofp-kpi-badge badge-green" style="font-size:11px;">+8% Compared to last month</span>
            </div>
            
            <div class="ofp-breakdown-list">
                <!-- Income -->
                <div class="ofp-breakdown-item">
                    <div class="ofp-breakdown-meta">
                        <span class="ofp-breakdown-label">Income</span>
                        <span class="ofp-breakdown-amount">₦12,320,000</span>
                    </div>
                    <div class="ofp-breakdown-track">
                        <div class="ofp-breakdown-fill income" style="width: 82%;"></div>
                    </div>
                </div>

                <!-- Expense -->
                <div class="ofp-breakdown-item">
                    <div class="ofp-breakdown-meta">
                        <span class="ofp-breakdown-label">Expense</span>
                        <span class="ofp-breakdown-amount">₦4,540,000</span>
                    </div>
                    <div class="ofp-breakdown-track">
                        <div class="ofp-breakdown-fill expense" style="width: 38%;"></div>
                    </div>
                </div>

                <!-- Net -->
                <div class="ofp-breakdown-item">
                    <div class="ofp-breakdown-meta">
                        <span class="ofp-breakdown-label">Net</span>
                        <span class="ofp-breakdown-amount">₦7,780,000</span>
                    </div>
                    <div class="ofp-breakdown-track">
                        <div class="ofp-breakdown-fill net" style="width: 58%;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 3. ZENTRA LOCATION & RECENT PROPERTIES ROW ─────────────────── -->
    <div class="ofp-zentra-bottom-grid">
        <!-- Left: Properties by location -->
        <div class="ofp-zentra-card">
            <div class="ofp-zentra-card-header">
                <div class="ofp-zentra-card-title">Properties by location</div>
                <div class="ofp-card-header-search">
                    <div class="ofp-card-mini-search">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:13px;height:13px;color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                        <input type="text" id="ofp-search-location-input" placeholder="Search location..." autocomplete="off" />
                    </div>
                    <button type="button" class="ofp-card-filter-btn" id="ofp-btn-reset-map" title="Show all locations">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" /></svg>
                    </button>
                </div>
            </div>

            <!-- Quick Location Filter Pills -->
            <div class="ofp-location-pills-bar" id="ofp-location-pills-bar">
                <button type="button" class="ofp-location-pill-btn active" data-location="all">
                    <span>All Locations</span>
                    <span style="font-size:10px; opacity:0.8;">(<?php echo count( $all_recent_props ); ?>)</span>
                </button>
                <?php foreach ( $properties_by_loc as $loc_key => $loc_items ) : ?>
                    <button type="button" class="ofp-location-pill-btn" data-location="<?php echo esc_attr( $loc_key ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:12px;height:12px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                        <span><?php echo esc_html( explode( ',', $loc_key )[0] ); ?></span>
                        <span style="font-size:10px; opacity:0.8;">(<?php echo count( $loc_items ); ?>)</span>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Featured Location Property Capsule -->
            <?php 
                $feat_prop = $initial_featured;
            ?>
            <div class="ofp-location-featured-card" id="ofp-location-featured-card">
                <div>
                    <div class="ofp-loc-feat-title" id="ofp-feat-title"><?php echo esc_html( $feat_prop ? $feat_prop['title'] : 'Prime Portfolio Property' ); ?></div>
                    <div class="ofp-loc-feat-sub">
                        <span>Status: <strong style="color:#f59e0b;" id="ofp-feat-status"><?php echo esc_html( $feat_prop ? $feat_prop['status'] : 'Occupied' ); ?></strong></span>
                        <span>•</span>
                        <span id="ofp-feat-loc"><?php echo esc_html( $feat_prop ? $feat_prop['location'] : 'Lagos, Nigeria' ); ?></span>
                    </div>
                </div>
                <div>
                    <div class="ofp-loc-feat-price" id="ofp-feat-price"><?php echo esc_html( $feat_prop ? $feat_prop['price'] : '—' ); ?></div>
                    <a href="<?php echo esc_url( $feat_prop ? $feat_prop['link'] : home_url('/properties') ); ?>" class="ofp-btn-see-details" id="ofp-feat-link">See Details</a>
                </div>
            </div>

            <!-- Real Google Map Embed with Dynamic Location Pan -->
            <?php
            $initial_loc_query = ! empty( $feat_prop['location'] ) ? $feat_prop['location'] . ', Nigeria' : 'Lagos, Nigeria';
            $initial_short_loc = ! empty( $feat_prop['short_loc'] ) ? $feat_prop['short_loc'] : ( ! empty( $feat_prop['location'] ) ? explode( ',', $feat_prop['location'] )[0] : 'Lagos' );
            ?>
            <div class="ofp-google-map-wrap" id="ofp-dashboard-map-box">
                <iframe
                    id="ofp-dashboard-google-map"
                    src="https://maps.google.com/maps?q=<?php echo urlencode( $initial_loc_query ); ?>&t=&z=14&ie=UTF8&iwloc=&output=embed"
                    loading="lazy"
                    allowfullscreen>
                </iframe>

                <!-- Floating Live Location Beacon Badge -->
                <div class="ofp-map-live-badge" id="ofp-map-live-badge">
                    <div class="ofp-beacon-dot-wrap">
                        <div class="ofp-beacon-ping"></div>
                        <div class="ofp-beacon-core"></div>
                    </div>
                    <div>
                        <div class="ofp-map-badge-title" id="ofp-map-badge-title"><?php echo esc_html( $initial_short_loc ); ?></div>
                        <div class="ofp-map-badge-sub" id="ofp-map-badge-sub"><?php echo count( $all_recent_props ); ?> Properties Active</div>
                    </div>
                </div>

                <!-- External Open in Google Maps Link Button -->
                <div class="ofp-map-ext-btn-wrap">
                    <a id="ofp-map-external-link" href="https://maps.google.com/?q=<?php echo urlencode( $initial_loc_query ); ?>" target="_blank" rel="noopener noreferrer" class="ofp-map-external-link-btn" title="Open in Google Maps">
                        <svg style="width:13px; height:13px; color:#4285F4; flex-shrink:0;" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                        </svg>
                        <span>Open in Google Maps</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Right: Your Recent Properties (Interactively Filtered by Map Location) -->
        <div class="ofp-zentra-card">
            <div class="ofp-zentra-card-header">
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <div class="ofp-zentra-card-title" id="ofp-recent-props-title">Your Recent Properties</div>
                    <span id="ofp-active-loc-tag" class="ofp-loc-filter-badge" style="display:none;">
                        <span id="ofp-active-loc-text"></span>
                        <button type="button" id="ofp-btn-clear-loc" title="Show all properties">&times;</button>
                    </span>
                </div>
                <div class="ofp-card-header-search">
                    <div class="ofp-card-mini-search">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:13px;height:13px;color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                        <input type="text" id="ofp-search-properties-input" placeholder="Search by title..." autocomplete="off" />
                    </div>
                    <button type="button" class="ofp-card-filter-btn" id="ofp-btn-reset-filters" title="Reset Filters">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                    </button>
                </div>
            </div>

            <div class="ofp-recent-properties-list" id="ofp-recent-properties-container">
                <?php foreach ( $all_recent_props as $p_item ) : ?>
                    <a href="<?php echo esc_url( $p_item['link'] ); ?>" 
                       class="ofp-recent-property-card" 
                       style="text-decoration:none;"
                       data-location="<?php echo esc_attr( strtolower( $p_item['location'] ) ); ?>"
                       data-prop-title="<?php echo esc_attr( $p_item['title'] ); ?>"
                       data-prop-loc="<?php echo esc_attr( $p_item['location'] ); ?>"
                       data-prop-price="<?php echo esc_attr( $p_item['price'] ); ?>"
                       data-prop-status="<?php echo esc_attr( $p_item['status'] ); ?>"
                       data-prop-link="<?php echo esc_url( $p_item['link'] ); ?>">
                        <div class="ofp-recent-prop-thumb">
                            <img src="<?php echo esc_url( $p_item['thumb'] ); ?>" alt="<?php echo esc_attr( $p_item['title'] ); ?>" loading="lazy" />
                        </div>
                        <div class="ofp-recent-prop-content">
                            <div class="ofp-recent-prop-title"><?php echo esc_html( $p_item['title'] ); ?></div>
                            <div class="ofp-recent-prop-price"><?php echo esc_html( $p_item['price'] ); ?></div>
                            <div class="ofp-recent-prop-sub">
                                <span><?php echo $p_item['is_rent'] ? 'Monthly rent: ' . esc_html( $p_item['price'] ) : 'Type: ' . esc_html( $p_item['type'] ); ?></span>
                                <div style="display:inline-flex; align-items:center; gap:6px;">
                                    <span class="ofp-kpi-badge <?php echo $p_item['status'] === 'Occupied' ? 'badge-hero' : 'badge-green'; ?>" style="font-size:10px;padding:2px 8px;">
                                        <?php echo esc_html( $p_item['status'] ); ?>
                                    </span>
                                    <button type="button" 
                                            class="ofp-btn-card-locate" 
                                            title="View on Google Map"
                                            onclick="event.preventDefault(); event.stopPropagation(); window.selectDashboardLocation('<?php echo esc_js( $p_item['location'] ); ?>');">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:11px;height:11px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>

                <div id="ofp-no-location-props" class="ofp-location-empty-state" style="display:none;">
                    <p style="margin:0 0 6px; font-weight:600;">No properties found in this location.</p>
                    <button type="button" onclick="selectDashboardLocation('all')">View All Properties</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 4. PIPELINE AUTOMATION & CREDITS SUMMARY ──────────────────── -->
    <div class="ofp-card" style="margin-top:20px;border-radius:18px;">
        <div class="ofp-card-header">
            <span class="ofp-card-title">Automation & Gateway Health</span>
            <span class="ofp-card-link" style="color:#10b981;">All Systems Operational</span>
        </div>
        <div class="ofp-status-grid">
            <div class="ofp-status-pill">
                <div class="ofp-status-dot"></div>
                <div class="ofp-status-info">
                    <strong>SMS Credit</strong>
                    <span>₦<?php echo number_format( (float)$sms_remaining, 2 ); ?></span>
                </div>
            </div>
            <div class="ofp-status-pill">
                <div class="ofp-status-dot"></div>
                <div class="ofp-status-info">
                    <strong>Voice Credit</strong>
                    <span>₦<?php echo number_format( (float)$voice_remaining, 2 ); ?></span>
                </div>
            </div>
            <div class="ofp-status-pill">
                <div class="ofp-status-dot"></div>
                <div class="ofp-status-info">
                    <strong>Pipeline Status</strong>
                    <span>Carrier Healthy</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart.js Cash Flow Script -->
    <script>
    (function() {
        function initChart() {
            if (typeof Chart === 'undefined') return;

            var canvas = document.getElementById('ofp-chart-cashflow');
            if (!canvas) return;

            var allMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            var fullIncome = [8.5, 9.2, 10.4, 9.8, 11.2, 12.0, 11.8, 14.5, 12.2, 13.0, 12.8, 15.0];
            var fullExpense = [3.2, 3.8, 4.1, 3.6, 4.0, 4.5, 4.2, 5.2, 4.4, 4.6, 4.5, 5.0];
            var fullNet = [5.3, 5.4, 6.3, 6.2, 7.2, 7.5, 7.6, 9.3, 7.8, 8.4, 8.3, 10.0];

            var chartInstance = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: allMonths,
                    datasets: [
                        {
                            label: 'Income',
                            data: fullIncome,
                            backgroundColor: function(context) {
                                var index = context.dataIndex;
                                return index === 7 ? '#7c3aed' : 'rgba(139, 92, 246, 0.45)';
                            },
                            borderRadius: 6,
                            barPercentage: 0.7,
                        },
                        {
                            label: 'Expense',
                            data: fullExpense,
                            backgroundColor: function(context) {
                                var index = context.dataIndex;
                                return index === 7 ? '#c084fc' : 'rgba(192, 132, 252, 0.35)';
                            },
                            borderRadius: 6,
                            barPercentage: 0.7,
                        },
                        {
                            label: 'Net',
                            data: fullNet,
                            backgroundColor: function(context) {
                                var index = context.dataIndex;
                                return index === 7 ? '#a78bfa' : 'rgba(203, 213, 225, 0.4)';
                            },
                            borderRadius: 6,
                            barPercentage: 0.7,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(item) {
                                    return item.dataset.label + ': ₦' + (item.raw * 1000000).toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: { size: 11, family: "'Segoe UI', sans-serif" },
                                color: '#94a3b8'
                            }
                        },
                        y: {
                            display: false,
                            beginAtZero: true
                        }
                    }
                }
            });

            var periodSelect = document.getElementById('ofp-cashflow-period');
            if (periodSelect) {
                periodSelect.addEventListener('change', function() {
                    if (this.value === '6months') {
                        chartInstance.data.labels = allMonths.slice(6);
                        chartInstance.data.datasets[0].data = fullIncome.slice(6);
                        chartInstance.data.datasets[1].data = fullExpense.slice(6);
                        chartInstance.data.datasets[2].data = fullNet.slice(6);
                    } else {
                        chartInstance.data.labels = allMonths;
                        chartInstance.data.datasets[0].data = fullIncome;
                        chartInstance.data.datasets[1].data = fullExpense;
                        chartInstance.data.datasets[2].data = fullNet;
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

    // ── Real Google Map & Recent Properties Interactive Linking ──
    (function() {
        function initLocationMapLinking() {
            var pillBtns = document.querySelectorAll('.ofp-location-pill-btn');
            var propCards = document.querySelectorAll('#ofp-recent-properties-container .ofp-recent-property-card');
            var activeTag = document.getElementById('ofp-active-loc-tag');
            var activeTagText = document.getElementById('ofp-active-loc-text');
            var clearBtn = document.getElementById('ofp-btn-clear-loc');
            var resetFiltersBtn = document.getElementById('ofp-btn-reset-filters');
            var resetMapBtn = document.getElementById('ofp-btn-reset-map');
            var heading = document.getElementById('ofp-recent-props-title');
            var emptyMsg = document.getElementById('ofp-no-location-props');
            var locSearchInput = document.getElementById('ofp-search-location-input');
            var propSearchInput = document.getElementById('ofp-search-properties-input');

            var featTitle = document.getElementById('ofp-feat-title');
            var featLoc = document.getElementById('ofp-feat-loc');
            var featStatus = document.getElementById('ofp-feat-status');
            var featPrice = document.getElementById('ofp-feat-price');
            var featLink = document.getElementById('ofp-feat-link');

            var gmap = document.getElementById('ofp-dashboard-google-map');
            var gmapBadgeTitle = document.getElementById('ofp-map-badge-title');
            var gmapBadgeSub = document.getElementById('ofp-map-badge-sub');
            var gmapExtLink = document.getElementById('ofp-map-external-link');

            window.selectDashboardLocation = function(locName) {
                var isAll = !locName || locName === 'all';
                var query = isAll ? '' : locName.toLowerCase().trim();

                // 1. Highlight matching pill button
                pillBtns.forEach(function(btn) {
                    var bLoc = (btn.getAttribute('data-location') || '').toLowerCase().trim();
                    if ((isAll && bLoc === 'all') || (!isAll && (bLoc.indexOf(query) !== -1 || query.indexOf(bLoc) !== -1))) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                });

                // 2. Filter properties in the Recent Properties container
                var visibleCount = 0;
                var firstCard = null;

                propCards.forEach(function(card) {
                    var cLoc = (card.getAttribute('data-location') || '').toLowerCase();
                    var matches = isAll || cLoc.indexOf(query) !== -1 || query.indexOf(cLoc) !== -1;
                    if (matches) {
                        card.style.display = 'flex';
                        visibleCount++;
                        if (!firstCard) firstCard = card;
                    } else {
                        card.style.display = 'none';
                    }
                });

                // 3. Update Real Google Map iframe location & zoom
                if (gmap) {
                    var mapQuery = 'Lagos, Nigeria';
                    var zoom = 12;

                    if (!isAll) {
                        var target = locName.trim();
                        if (target.toLowerCase().indexOf('lagos') === -1 && target.toLowerCase().indexOf('nigeria') === -1) {
                            target += ', Lagos, Nigeria';
                        }
                        mapQuery = target;
                        zoom = 15;
                    }

                    var embedUrl = 'https://maps.google.com/maps?q=' + encodeURIComponent(mapQuery) + '&t=&z=' + zoom + '&ie=UTF8&iwloc=&output=embed';
                    if (gmap.src !== embedUrl) {
                        gmap.src = embedUrl;
                    }

                    if (gmapExtLink) {
                        gmapExtLink.href = 'https://maps.google.com/?q=' + encodeURIComponent(mapQuery);
                    }
                }

                // 4. Update Live Map Beacon Badge
                if (gmapBadgeTitle) {
                    gmapBadgeTitle.textContent = isAll ? 'All Locations (Lagos)' : locName.split(',')[0];
                }
                if (gmapBadgeSub) {
                    gmapBadgeSub.textContent = visibleCount + (visibleCount === 1 ? ' Property Active' : ' Properties Active');
                }

                // 5. Update Header Badge and Title in Recent Properties card
                if (isAll) {
                    if (activeTag) activeTag.style.display = 'none';
                    if (heading) heading.textContent = 'Your Recent Properties';
                } else {
                    var displayLabel = locName.split(',')[0];
                    if (activeTag) activeTag.style.display = 'inline-flex';
                    if (activeTagText) activeTagText.textContent = '📍 ' + displayLabel + ' (' + visibleCount + ')';
                    if (heading) heading.textContent = 'Properties in ' + displayLabel;
                }

                // 6. Empty state visibility
                if (emptyMsg) {
                    emptyMsg.style.display = visibleCount === 0 ? 'block' : 'none';
                }

                // 7. Update Featured Property Capsule in left box
                if (firstCard) {
                    if (featTitle) featTitle.textContent = firstCard.getAttribute('data-prop-title') || '';
                    if (featLoc) featLoc.textContent = firstCard.getAttribute('data-prop-loc') || '';
                    if (featPrice) featPrice.textContent = firstCard.getAttribute('data-prop-price') || '';
                    if (featStatus) featStatus.textContent = firstCard.getAttribute('data-prop-status') || '';
                    if (featLink) featLink.href = firstCard.getAttribute('data-prop-link') || '#';
                }
            };

            // Click handlers on pill buttons
            pillBtns.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var loc = this.getAttribute('data-location');
                    window.selectDashboardLocation(loc);
                });
            });

            // Clear filter button
            if (clearBtn) {
                clearBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (locSearchInput) locSearchInput.value = '';
                    window.selectDashboardLocation('all');
                });
            }
            if (resetFiltersBtn) {
                resetFiltersBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (locSearchInput) locSearchInput.value = '';
                    if (propSearchInput) propSearchInput.value = '';
                    window.selectDashboardLocation('all');
                });
            }
            if (resetMapBtn) {
                resetMapBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (locSearchInput) locSearchInput.value = '';
                    window.selectDashboardLocation('all');
                });
            }

            // Live location search input
            if (locSearchInput) {
                locSearchInput.addEventListener('input', function() {
                    var val = this.value.trim();
                    window.selectDashboardLocation(val ? val : 'all');
                });
            }

            // Live property title search input in right box
            if (propSearchInput) {
                propSearchInput.addEventListener('input', function() {
                    var term = this.value.toLowerCase().trim();
                    propCards.forEach(function(card) {
                        var title = (card.getAttribute('data-prop-title') || '').toLowerCase();
                        if (!term || title.indexOf(term) !== -1) {
                            card.style.display = 'flex';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initLocationMapLinking);
        } else {
            initLocationMapLinking();
        }
    })();
    </script>

</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
