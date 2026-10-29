<?php
/**
 * Template: /inspections (client portal) — Property Inspections & Booking Manager.
 *
 * Exclusively for logged-in clients to oversee viewing requests, physical & WhatsApp video tours,
 * prospect contact details, and their detailed requirements.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();

$error   = '';
$success = '';

// Handle direct non-JS fallback POST status update
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_update_status'] ) ) {
    if ( ! wp_verify_nonce( $_POST['ofp_inspection_nonce'] ?? '', 'ofp_inspection_action' ) ) {
        $error = 'Security check failed. Please try again.';
    } else {
        $inquiry_id = (int) ( $_POST['inquiry_id'] ?? 0 );
        $new_status = sanitize_key( $_POST['status'] ?? '' );
        if ( $inquiry_id && array_key_exists( $new_status, OFP_Property_Inquiry::STATUSES ) ) {
            $updated = OFP_Property_Inquiry::update_status( $inquiry_id, $new_status, $client->id );
            if ( $updated ) {
                $success = 'Inspection status updated to ' . OFP_Property_Inquiry::STATUSES[ $new_status ] . '.';
            } else {
                $error = 'Failed to update inspection status.';
            }
        }
    }
}

// Filters
$filter_status    = sanitize_key( $_GET['status'] ?? '' );
$filter_tour_type = sanitize_key( $_GET['tour_type'] ?? '' );
$filter_prop_id   = (int) ( $_GET['property_id'] ?? 0 );
$filter_q         = sanitize_text_field( $_GET['q'] ?? '' );

$query_filters = [];
if ( ! empty( $filter_status ) && array_key_exists( $filter_status, OFP_Property_Inquiry::STATUSES ) ) {
    $query_filters['status'] = $filter_status;
}
if ( ! empty( $filter_tour_type ) ) {
    $query_filters['tour_type'] = $filter_tour_type;
}
if ( ! empty( $filter_prop_id ) ) {
    $query_filters['property_id'] = $filter_prop_id;
}

$inquiries = OFP_Property_Inquiry::get_client_inquiries( $client->id, $query_filters );

if ( ! empty( $filter_q ) ) {
    $inquiries = array_filter( $inquiries, function( $inq ) use ( $filter_q ) {
        $lead_name  = $inq->lead_name ?? '';
        $lead_phone = $inq->lead_phone ?? '';
        $lead_email = $inq->lead_email ?? '';
        $prop_title = $inq->property_title ?? '';
        $msg        = $inq->message ?? '';
        $haystack   = strtolower( $lead_name . ' ' . $lead_phone . ' ' . $lead_email . ' ' . $prop_title . ' ' . $msg );
        return strpos( $haystack, strtolower( $filter_q ) ) !== false;
    } );
}

// Calculate metrics across all inquiries for this client
global $wpdb;
$counts_raw = $wpdb->get_results( $wpdb->prepare(
    "SELECT status, COUNT(*) as cnt 
     FROM {$wpdb->prefix}ofp_property_inquiries 
     WHERE client_id = %d 
     GROUP BY status",
    $client->id
), OBJECT_K );

$total_count     = array_sum( array_column( $counts_raw, 'cnt' ) );
$pending_count   = isset( $counts_raw['pending'] ) ? (int) $counts_raw['pending']->cnt : 0;
$confirmed_count = isset( $counts_raw['confirmed'] ) ? (int) $counts_raw['confirmed']->cnt : 0;
$completed_count = isset( $counts_raw['completed'] ) ? (int) $counts_raw['completed']->cnt : 0;

// Fetch client properties for property filter
$client_properties = $wpdb->get_results( $wpdb->prepare(
    "SELECT id, wp_post_id, title 
     FROM {$wpdb->prefix}ofp_properties 
     WHERE client_id = %d 
     ORDER BY title ASC",
    $client->id
) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Property Inspections', $client ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">
    <?php 
    $ofp_banner_btn = [ 'label' => 'View Properties', 'url' => home_url( '/properties' ) ];
    include OFP_PATH . 'public/templates/partials/nav.php'; 
    ?>

    <div class="ofp-container">
        <div style="padding-bottom: 60px;">
            <!-- Flash Notices -->
            <?php if ( $success ) : ?>
                <div class="ofp-alert ofp-alert-success" style="margin-bottom: 20px; border-radius: 12px; padding: 14px 18px; font-size: 13px; font-weight: 600;">
                    <?php echo esc_html( $success ); ?>
                </div>
            <?php endif; ?>
            <?php if ( $error ) : ?>
                <div class="ofp-alert ofp-alert-error" style="margin-bottom: 20px; border-radius: 12px; padding: 14px 18px; font-size: 13px; font-weight: 600;">
                    <?php echo esc_html( $error ); ?>
                </div>
            <?php endif; ?>

            <!-- Metrics Overview Cards (Responsive: 4 on desktop, 2 on tablet & mobile) -->
            <div class="ofp-inspection-kpi-grid" style="margin-bottom: 24px;">
                <div class="ofp-stat-card" style="padding: 18px 20px; border-radius: 14px; background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Total Requests</div>
                    <div style="font-size: 26px; font-weight: 800; color: var(--text-main);"><?php echo number_format( $total_count ); ?></div>
                </div>
                <div class="ofp-stat-card" style="padding: 18px 20px; border-radius: 14px; background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div style="font-size: 12px; font-weight: 700; color: #b45309; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Pending Confirmation</div>
                    <div style="font-size: 26px; font-weight: 800; color: #b45309;"><?php echo number_format( $pending_count ); ?></div>
                </div>
                <div class="ofp-stat-card" style="padding: 18px 20px; border-radius: 14px; background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div style="font-size: 12px; font-weight: 700; color: #2563eb; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Confirmed Tours</div>
                    <div style="font-size: 26px; font-weight: 800; color: #2563eb;"><?php echo number_format( $confirmed_count ); ?></div>
                </div>
                <div class="ofp-stat-card" style="padding: 18px 20px; border-radius: 14px; background: var(--bg-card); border: 1px solid var(--border-color);">
                    <div style="font-size: 12px; font-weight: 700; color: #059669; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Completed Showings</div>
                    <div style="font-size: 26px; font-weight: 800; color: #059669;"><?php echo number_format( $completed_count ); ?></div>
                </div>
            </div>

            <!-- ── SEARCH BAR & FILTER PILLS: Exact replica of Leads (/leads) ── -->
            <div class="ofp-leads-filter-card">
                <form method="get" action="<?php echo esc_url( home_url( '/inspections' ) ); ?>" class="ofp-leads-search-row">
                    <?php if ( $filter_status !== '' ) : ?>
                        <input type="hidden" name="status" id="ofp-inspection-status-hidden" value="<?php echo esc_attr( $filter_status ); ?>">
                    <?php endif; ?>

                    <div class="ofp-leads-search-box">
                        <svg class="ofp-leads-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                        <input class="ofp-leads-search-input" id="ofp-inspection-search-input" type="text" name="q" value="<?php echo esc_attr( $filter_q ); ?>" placeholder="Search prospects by name, phone or email...">
                    </div>

                    <select name="tour_type" id="ofp-inspection-tour-select" class="ofp-leads-select">
                        <option value="">All Tour Types</option>
                        <option value="physical" <?php selected( $filter_tour_type, 'physical' ); ?>>Physical Showing</option>
                        <option value="whatsapp_video" <?php selected( $filter_tour_type, 'whatsapp_video' ); ?>>WhatsApp Video</option>
                    </select>

                    <?php if ( ! empty( $client_properties ) ) : ?>
                        <select name="property_id" id="ofp-inspection-prop-select" class="ofp-leads-select">
                            <option value="">All Listings</option>
                            <?php foreach ( $client_properties as $cp ) : ?>
                                <option value="<?php echo (int) $cp->wp_post_id; ?>" <?php selected( $filter_prop_id, (int) $cp->wp_post_id ); ?>>
                                    <?php echo esc_html( wp_trim_words( $cp->title, 5 ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <button type="submit" class="ofp-btn ofp-btn-primary" style="display:inline-flex;align-items:center;gap:6px;padding:9px 20px;font-weight:700;">
                        <span>Filter</span>
                    </button>

                    <?php if ( $filter_q !== '' || $filter_tour_type !== '' || $filter_prop_id !== 0 || $filter_status !== '' ) : ?>
                        <a href="<?php echo esc_url( home_url( '/inspections' ) ); ?>" id="ofp-inspection-clear-btn" class="ofp-btn" style="padding:9px 14px;">Clear</a>
                    <?php else : ?>
                        <a href="<?php echo esc_url( home_url( '/inspections' ) ); ?>" id="ofp-inspection-clear-btn" class="ofp-btn" style="padding:9px 14px; display:none;">Clear</a>
                    <?php endif; ?>
                </form>

                <div class="ofp-leads-filter-pills">
                    <?php
                    $pills = [
                        ''           => [ 'label' => 'All Requests', 'count' => $total_count ],
                        'pending'    => [ 'label' => 'Pending Confirmation', 'count' => $pending_count ],
                        'confirmed'  => [ 'label' => 'Confirmed',    'count' => $confirmed_count ],
                        'completed'  => [ 'label' => 'Completed',    'count' => $completed_count ],
                        'cancelled'  => [ 'label' => 'Cancelled',    'count' => isset( $counts_raw['cancelled'] ) ? (int) $counts_raw['cancelled']->cnt : 0 ],
                    ];
                    foreach ( $pills as $val => $p_info ) :
                        $active = ( $filter_status === $val );
                        $pill_url = add_query_arg( array_filter( [
                            'status'      => $val,
                            'tour_type'   => $filter_tour_type,
                            'property_id' => $filter_prop_id ?: null,
                            'q'           => $filter_q ?: null,
                        ] ), home_url( '/inspections' ) );
                    ?>
                        <a class="ofp-leads-pill ofp-inspection-tab <?php echo $active ? 'active' : ''; ?>" href="<?php echo esc_url( $pill_url ); ?>" data-status="<?php echo esc_attr( $val ); ?>">
                            <span><?php echo esc_html( $p_info['label'] ); ?></span>
                            <span class="ofp-leads-pill-count" id="tab-cnt-<?php echo $val ?: 'all'; ?>"><?php echo (int) $p_info['count']; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Inquiries Table Card -->
            <div class="ofp-card ofp-inspection-table-card" style="padding: 0; overflow: hidden;">
                
                <div style="padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color);">
                    <div>
                        <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main);">Scheduled Inspections</h3>
                        <p style="margin: 2px 0 0; font-size: 12px; color: var(--text-muted);">Click any row to open the details slide and manage status.</p>
                    </div>
                    <div>
                        <a href="<?php echo esc_url( home_url( '/properties' ) ); ?>" class="ofp-btn ofp-btn-sm ofp-btn-ghost" style="font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                            <span>View Active Listings</span>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                        </a>
                    </div>
                </div>

                <!-- Empty Notice when filters return 0 -->
                <div id="ofp-inspection-empty-notice" style="display:none; text-align:center; padding:44px 20px; background:rgba(255,255,255,0.02);">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);margin:0 auto 8px;display:block;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <h4 style="font-size: 14px; font-weight: 700; color: var(--text-main); margin: 0 0 4px;">No matching inspections</h4>
                    <p style="color:var(--text-muted); font-size:12.5px; margin:0;">No inspection requests match your selected filters or search query.</p>
                </div>

                <?php if ( empty( $inquiries ) ) : ?>
                    <div style="text-align: center; padding: 56px 20px;">
                        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);margin:0 auto 10px;display:block;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <h3 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0 0 6px;">No inspection requests found</h3>
                        <p style="font-size: 13px; color: var(--text-muted); max-width: 440px; margin: 0 auto 18px;">
                            When prospects submit viewing requests from your property listings, their appointment details and specifications will appear here.
                        </p>
                        <a href="<?php echo esc_url( home_url( '/properties' ) ); ?>" class="ofp-btn ofp-btn-primary">
                            Browse Your Listings
                        </a>
                    </div>
                <?php else : ?>
                    <div class="ofp-table-wrap ofp-table-responsive" style="margin-top: 0;">
                        <table class="ofp-table" id="ofp-inspections-table">
                            <thead>
                                <tr>
                                    <th>Prospect</th>
                                    <th>Property Listing</th>
                                    <th>Tour Type</th>
                                    <th>Viewing Schedule</th>
                                    <th>Status</th>
                                    <th>Booked</th>
                                    <th style="text-align: right; width: 70px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="ofp-inspections-tbody">
                                <?php foreach ( $inquiries as $inq ) : 
                                    $lead_name  = $inq->lead_name ?: 'Prospect';
                                    $lead_phone = $inq->lead_phone ?: '';
                                    $clean_lead_phone = preg_replace( '/[^0-9]/', '', $lead_phone );
                                    if ( strpos( $clean_lead_phone, '0' ) === 0 ) {
                                        $clean_lead_phone = '234' . substr( $clean_lead_phone, 1 );
                                    }
                                    $prop_title = $inq->property_title ?: ( $inq->property_id ? get_the_title( $inq->property_id ) : 'Property Listing' );
                                    $prop_url   = $inq->property_id ? get_permalink( $inq->property_id ) : '#';
                                    $is_video   = ( $inq->tour_type === 'whatsapp_video' || $inq->tour_type === 'video' );

                                    // WhatsApp message template for agent to prospect
                                    $wa_reply_text = rawurlencode(
                                        "Hello {$lead_name}, thank you for your inspection request for '{$prop_title}'. " .
                                        "I am reaching out from {$client->business_name} to confirm your appointment for " .
                                        ( $inq->preferred_viewing_date ?: 'your preferred date' ) .
                                        ( $inq->preferred_viewing_time ? " ({$inq->preferred_viewing_time})" : "" ) . "."
                                    );

                                    $inquiry_payload = [
                                        'id'                   => (int) $inq->id,
                                        'status'               => $inq->status,
                                        'status_label'         => OFP_Property_Inquiry::STATUSES[ $inq->status ] ?? ucfirst( $inq->status ),
                                        'tour_type'            => $inq->tour_type,
                                        'is_video'             => $is_video,
                                        'lead_name'            => $lead_name,
                                        'lead_phone'           => $lead_phone,
                                        'lead_phone_clean'     => $clean_lead_phone,
                                        'lead_email'           => $inq->lead_email ?: '',
                                        'property_id'          => (int) $inq->property_id,
                                        'property_title'       => $prop_title,
                                        'property_url'         => $prop_url,
                                        'property_location'    => $inq->property_location ?: '',
                                        'property_image'       => $inq->property_image ?: '',
                                        'preferred_date'       => $inq->preferred_viewing_date ?: 'Date not specified',
                                        'preferred_time'       => $inq->preferred_viewing_time ?: '',
                                        'message'              => $inq->message ?: '',
                                        'created_at_formatted' => gmdate( 'M j, Y g:i A', strtotime( $inq->created_at ) ),
                                        'wa_link'              => $clean_lead_phone ? "https://wa.me/{$clean_lead_phone}?text={$wa_reply_text}" : '',
                                    ];

                                    $avatar_initial = strtoupper( substr( trim( $lead_name ), 0, 1 ) ?: 'P' );
                                ?>
                                    <tr class="ofp-inspection-row" id="inspection-row-<?php echo esc_attr( $inq->id ); ?>"
                                        data-id="<?php echo esc_attr( $inq->id ); ?>"
                                        data-status="<?php echo esc_attr( $inq->status ); ?>"
                                        data-tour="<?php echo esc_attr( $inq->tour_type ); ?>"
                                        data-property="<?php echo (int) $inq->property_id; ?>"
                                        data-search="<?php echo esc_attr( strtolower( $lead_name . ' ' . $lead_phone . ' ' . $inq->lead_email . ' ' . $prop_title . ' ' . $inq->message ) ); ?>"
                                        data-inquiry="<?php echo esc_attr( wp_json_encode( $inquiry_payload ) ); ?>"
                                        onclick="ofpOpenInspectionDrawer(this, event)">
                                        
                                        <!-- Prospect Name & Phone -->
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 9px;">
                                                <div class="ofp-prospect-avatar"><?php echo esc_html( $avatar_initial ); ?></div>
                                                <div>
                                                    <div style="font-weight: 700; color: var(--text-main); font-size: 13px; line-height: 1.3;">
                                                        <?php echo esc_html( $lead_name ); ?>
                                                    </div>
                                                    <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 1px;">
                                                        <?php echo esc_html( $lead_phone ?: '—' ); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Property Listing -->
                                        <td>
                                            <a href="<?php echo esc_url( $prop_url ); ?>" target="_blank" onclick="event.stopPropagation();" style="font-size: 12.5px; font-weight: 600; color: var(--accent-blue); text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                                <span><?php echo esc_html( wp_trim_words( $prop_title, 6 ) ); ?></span>
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                            </a>
                                            <?php if ( ! empty( $inq->property_location ) ) : ?>
                                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 1px;">
                                                    <?php echo esc_html( $inq->property_location ); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Tour Type Badge -->
                                        <td>
                                            <span class="ofp-tour-badge <?php echo $is_video ? 'ofp-tour-video' : 'ofp-tour-physical'; ?>">
                                                <?php if ( $is_video ) : ?>
                                                    <svg style="width:12px;height:12px;" fill="currentColor" viewBox="0 0 24 24"><path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                    WhatsApp Video
                                                <?php else : ?>
                                                    <svg style="width:12px;height:12px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6m6 0l-3-3m3 3l-3 3"/></svg>
                                                    Physical Tour
                                                <?php endif; ?>
                                            </span>
                                        </td>

                                        <!-- Viewing Schedule -->
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 600; color: var(--text-main);">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                                                <span><?php echo esc_html( $inq->preferred_viewing_date ?: 'Flexible date' ); ?></span>
                                            </div>
                                            <?php if ( ! empty( $inq->preferred_viewing_time ) ) : ?>
                                                <div style="font-size: 11px; color: var(--accent-blue); font-weight: 500; margin-left: 17px;">
                                                    at <?php echo esc_html( $inq->preferred_viewing_time ); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Status Badge -->
                                        <td>
                                            <span class="ofp-status-pill ofp-status-<?php echo esc_attr( $inq->status ); ?>" id="status-pill-<?php echo esc_attr( $inq->id ); ?>">
                                                <?php echo esc_html( OFP_Property_Inquiry::STATUSES[ $inq->status ] ?? ucfirst( $inq->status ) ); ?>
                                            </span>
                                        </td>

                                        <!-- Booked At -->
                                        <td style="font-size: 11.5px; color: var(--text-muted); white-space: nowrap;">
                                            <?php echo esc_html( gmdate( 'M j, Y', strtotime( $inq->created_at ) ) ); ?>
                                        </td>

                                        <!-- Row Action Icons -->
                                        <td style="text-align: right;">
                                            <div style="display: inline-flex; align-items: center; gap: 5px;" onclick="event.stopPropagation();">
                                                <?php if ( $clean_lead_phone ) : ?>
                                                    <a href="https://wa.me/<?php echo esc_attr( $clean_lead_phone ); ?>?text=<?php echo $wa_reply_text; ?>" 
                                                       target="_blank" 
                                                       class="ofp-action-icon-btn ofp-action-wa-btn" 
                                                       title="WhatsApp Prospect">
                                                        <svg style="width: 14px; height: 14px;" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                                                    </a>
                                                    <a href="tel:<?php echo esc_attr( $lead_phone ); ?>" 
                                                       class="ofp-action-icon-btn" 
                                                       title="Call Prospect">
                                                        <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- ── SLIDE-OVER INSPECTION DRAWER ──────────────────────────────────────── -->
    <div class="ofp-inspection-drawer-overlay" id="ofpInspectionDrawerOverlay" onclick="ofpCloseInspectionDrawer()"></div>

    <aside class="ofp-inspection-drawer" id="ofpInspectionDrawer" aria-label="Inspection Details Drawer">
        <!-- Drawer Header with prominent Back & Close Buttons -->
        <div class="ofp-inspection-drawer-header">
            <div class="ofp-inspection-drawer-header-left">
                <button type="button" class="ofp-inspection-drawer-back-btn" onclick="ofpCloseInspectionDrawer()" aria-label="Go Back" title="Go Back">
                    <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                    </svg>
                    <span>Back</span>
                </button>
                <div class="ofp-inspection-drawer-title-group">
                    <h3 class="ofp-inspection-drawer-title">Inspection Details</h3>
                    <span style="font-size: 12px; font-weight: 700; color: var(--text-muted);" id="ofpDrawerInquiryId">#</span>
                    <span class="ofp-status-pill" id="ofpDrawerStatusBadge">Pending</span>
                </div>
            </div>
            <button type="button" class="ofp-inspection-drawer-close" onclick="ofpCloseInspectionDrawer()" aria-label="Close Drawer" title="Close / Hide Details">
                <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Drawer Body (Clean flat canvas without nested background cards) -->
        <div class="ofp-inspection-drawer-body">

            <!-- Section 1: Prospect Contact -->
            <div class="ofp-drawer-section">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
                    <div class="ofp-drawer-avatar-lg" id="ofpDrawerAvatar">P</div>
                    <div style="flex: 1; min-width: 0;">
                        <h4 class="ofp-drawer-prospect-name" id="ofpDrawerLeadName">—</h4>
                        <div class="ofp-drawer-prospect-contact">
                            <a href="#" id="ofpDrawerLeadPhone" style="color: var(--text-main); font-weight: 600; text-decoration: none;">—</a>
                            <span id="ofpDrawerLeadEmailWrap" style="display:none;">
                                <span>&bull;</span>
                                <a href="#" id="ofpDrawerLeadEmail" style="color: var(--text-muted); text-decoration: none;">—</a>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="ofp-drawer-actions-grid">
                    <a href="#" id="ofpDrawerWaBtn" target="_blank" class="ofp-btn ofp-btn-sm" style="background: #00a859; color: #ffffff; border: none; font-weight: 700; justify-content: center; display: inline-flex; align-items: center; gap: 6px;">
                        <svg style="width: 14px; height: 14px;" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                        <span>WhatsApp Prospect</span>
                    </a>
                    <a href="#" id="ofpDrawerCallBtn" class="ofp-btn ofp-btn-sm ofp-btn-secondary" style="font-weight: 700; justify-content: center; display: inline-flex; align-items: center; gap: 6px;">
                        <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        <span>Call</span>
                    </a>
                </div>
            </div>

            <div class="ofp-drawer-divider"></div>

            <!-- Section 2: Property & Schedule -->
            <div class="ofp-drawer-section">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; gap: 10px;">
                    <div>
                        <div class="ofp-drawer-section-label">Property Listing</div>
                        <a href="#" id="ofpDrawerPropTitle" target="_blank" style="font-size: 14.5px; font-weight: 700; color: var(--accent-blue); text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                            <span>Property Listing</span>
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                        </a>
                        <div id="ofpDrawerPropLocation" style="font-size: 12px; color: var(--text-muted); margin-top: 3px;"></div>
                    </div>
                    <div id="ofpDrawerTourType" class="ofp-tour-badge ofp-tour-physical">Physical Showing</div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-top: 1px dashed var(--border-color); font-size: 12.5px;">
                    <span style="color: var(--text-muted); font-weight: 500;">Viewing Appointment</span>
                    <span style="color: var(--text-main); font-weight: 700;" id="ofpDrawerScheduleDate">Date not specified</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 7px 0; font-size: 11.5px; color: var(--text-muted);">
                    <span>Date Requested</span>
                    <span id="ofpDrawerBookedDate">Recently</span>
                </div>
            </div>

            <div class="ofp-drawer-divider"></div>

            <!-- Section 3: Prospect Message Sent (Textarea visual display, but div) -->
            <div class="ofp-drawer-section">
                <div class="ofp-drawer-section-label">Prospect Request &amp; Specifications</div>
                <div id="ofpDrawerMessageText" class="ofp-drawer-textarea-display">No additional specifications provided.</div>
            </div>

            <!-- Section 4: Update Inspection Status (Dropup opens upwards, removing bottom gap) -->
            <div class="ofp-drawer-section" style="margin-top: 16px;">
                <div class="ofp-drawer-section-label">Update Inspection Status</div>
                <form onsubmit="return handleDrawerStatusSubmit(event);" style="margin-top: 6px;">
                    <?php wp_nonce_field( 'ofp_inspection_action', 'ofp_inspection_nonce' ); ?>
                    <input type="hidden" id="ofpDrawerInquiryIdInput" value="">
                    
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <div class="ofp-dropup-container" id="ofpDropupContainer">
                            <div class="ofp-select ofp-dropup-trigger" id="ofpDropupBtn" onclick="ofpToggleStatusDropup(event)" tabindex="0" role="button" aria-haspopup="listbox" aria-expanded="false">
                                <span id="ofpDropupLabel">Pending Confirmation</span>
                            </div>
                            <div class="ofp-dropup-menu" id="ofpDropupMenu" onclick="event.stopPropagation();" role="listbox">
                                <?php foreach ( OFP_Property_Inquiry::STATUSES as $sk => $slabel ) : ?>
                                    <div class="ofp-dropup-option" data-value="<?php echo esc_attr( $sk ); ?>" onclick="ofpSelectDropupStatus('<?php echo esc_js( $sk ); ?>', '<?php echo esc_js( $slabel ); ?>')" role="option">
                                        <?php echo esc_html( $slabel ); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="status" id="ofpDrawerStatusSelect" value="pending">
                        </div>
                        <button type="submit" id="ofpDrawerSaveStatusBtn" class="ofp-btn ofp-btn-primary" style="font-size: 13px; font-weight: 700; padding: 0 20px; height: 38px; white-space: nowrap;">
                            Save
                        </button>
                    </div>

                    <div id="ofpDrawerStatusFeedback" style="display:none; font-size: 12px; font-weight: 600; padding: 6px 0 0;"></div>
                </form>
            </div>

        </div>

        <!-- Drawer Footer with prominent Hide/Close Details button -->
        <div class="ofp-inspection-drawer-footer">
            <button type="button" class="ofp-btn ofp-btn-secondary ofp-inspection-drawer-dismiss-btn" onclick="ofpCloseInspectionDrawer()" aria-label="Hide Details">
                <svg style="width: 15px; height: 15px;" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                <span>Hide Details</span>
            </button>
        </div>
    </aside>

    </main>
</div><!-- .ofp-shell -->

    <?php wp_footer(); ?>
</body>
</html>
