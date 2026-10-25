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

$inquiries = OFP_Property_Inquiry::get_client_inquiries( $client->id, [] );

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
                    ✓ <?php echo esc_html( $success ); ?>
                </div>
            <?php endif; ?>
            <?php if ( $error ) : ?>
                <div class="ofp-alert ofp-alert-error" style="margin-bottom: 20px; border-radius: 12px; padding: 14px 18px; font-size: 13px; font-weight: 600;">
                    ⚠ <?php echo esc_html( $error ); ?>
                </div>
            <?php endif; ?>

            <!-- Metrics Overview Cards (Responsive: 4 on desktop, 2 on tablet & mobile) -->
            <div class="ofp-inspection-kpi-grid" style="margin-bottom: 24px;">
                <div class="ofp-stat-card" style="padding: 18px 20px; border-radius: 14px; background: var(--card-bg); border: 1px solid var(--border-color);">
                    <div style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Total Requests</div>
                    <div style="font-size: 26px; font-weight: 800; color: var(--text-main);"><?php echo number_format( $total_count ); ?></div>
                </div>
                <div class="ofp-stat-card" style="padding: 18px 20px; border-radius: 14px; background: var(--card-bg); border: 1px solid var(--border-color);">
                    <div style="font-size: 12px; font-weight: 700; color: #b45309; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Pending Confirmation</div>
                    <div style="font-size: 26px; font-weight: 800; color: #b45309;"><?php echo number_format( $pending_count ); ?></div>
                </div>
                <div class="ofp-stat-card" style="padding: 18px 20px; border-radius: 14px; background: var(--card-bg); border: 1px solid var(--border-color);">
                    <div style="font-size: 12px; font-weight: 700; color: #2563eb; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Confirmed Tours</div>
                    <div style="font-size: 26px; font-weight: 800; color: #2563eb;"><?php echo number_format( $confirmed_count ); ?></div>
                </div>
                <div class="ofp-stat-card" style="padding: 18px 20px; border-radius: 14px; background: var(--card-bg); border: 1px solid var(--border-color);">
                    <div style="font-size: 12px; font-weight: 700; color: #059669; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Completed Showings</div>
                    <div style="font-size: 26px; font-weight: 800; color: #059669;"><?php echo number_format( $completed_count ); ?></div>
                </div>
            </div>

            <!-- Filter & Search Toolbar (Redesigned with AJAX Tab Switching) -->
            <div class="ofp-card ofp-inspection-toolbar-card" style="padding: 18px 20px; margin-bottom: 24px; border-radius: 14px;">
                <!-- Top Row: Starts with search, then the 2 filters in one line on desktop; collapsible on mobile -->
                <div class="ofp-inspection-toolbar-row">
                    <div class="ofp-inspection-controls-group">
                        
                        <!-- 1. Real-time Search Box (Starts with search on desktop) -->
                        <div class="ofp-comm-search-box ofp-inspection-search-wrap">
                            <input type="text" id="ofp-inspection-search-input" 
                                   placeholder="Search prospect, phone, property..." 
                                   class="ofp-input ofp-comm-search-input"
                                   style="height: 38px; font-size: 13px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                        </div>

                        <!-- Mobile Filter Toggle Button (Shown on mobile to hide & unhide the filter box) -->
                        <button type="button" id="ofp-inspection-filter-toggle" class="ofp-btn ofp-btn-sm ofp-inspection-filter-toggle" aria-expanded="false" title="Toggle Filters">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                            </svg>
                            <span>Filters</span>
                            <span class="ofp-filter-count-badge" id="ofp-filter-count-badge" style="display:none;"></span>
                        </button>

                        <!-- 2 & 3. The 2 Filters (Tour Type & Property) + Clear button in one line on desktop -->
                        <div class="ofp-inspection-filters-box" id="ofp-inspection-filters-box">
                            <!-- Tour Type Dropdown -->
                            <select id="ofp-inspection-tour-select" class="ofp-select ofp-inspection-select" style="font-size: 12.5px; height: 38px; padding: 0 12px; min-width: 140px;">
                                <option value="">All Tour Types</option>
                                <option value="physical" <?php selected( $filter_tour_type, 'physical' ); ?>>Physical Showing</option>
                                <option value="whatsapp_video" <?php selected( $filter_tour_type, 'whatsapp_video' ); ?>>WhatsApp Video</option>
                            </select>

                            <!-- Property Dropdown -->
                            <?php if ( ! empty( $client_properties ) ) : ?>
                                <select id="ofp-inspection-prop-select" class="ofp-select ofp-inspection-select" style="font-size: 12.5px; height: 38px; padding: 0 12px; max-width: 200px;">
                                    <option value="">All Listings</option>
                                    <?php foreach ( $client_properties as $cp ) : ?>
                                        <option value="<?php echo (int) $cp->wp_post_id; ?>" <?php selected( $filter_prop_id, (int) $cp->wp_post_id ); ?>>
                                            <?php echo esc_html( wp_trim_words( $cp->title, 5 ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>

                            <button type="button" id="ofp-inspection-clear-btn" class="ofp-btn ofp-btn-sm ofp-btn-ghost" style="height: 38px; display: none;">Clear</button>
                        </div>
                    </div>

                    <!-- Records Counter Badge -->
                    <div id="ofp-inspection-counter" style="font-size: 12.5px; color: var(--text-muted); font-weight: 500; white-space: nowrap; flex-shrink: 0;">
                        Showing <strong style="color: var(--text-main);" id="ofp-inspection-visible-count"><?php echo count( $inquiries ); ?></strong> of <strong style="color: var(--text-main);"><?php echo $total_count; ?></strong> requests
                    </div>
                </div>

                <!-- Bottom Row: Sleek Segmented Pill Tabs -->
                <div class="ofp-segmented-nav ofp-inspection-tabs" style="display: flex; gap: 6px; margin: 0; padding-top: 14px; border-top: 1px solid var(--border-color);">
                    <button type="button" data-status="" class="ofp-inspection-tab ofp-segmented-tab <?php echo empty( $filter_status ) ? 'active' : ''; ?>">
                        <span>All Requests</span>
                        <span class="ofp-tab-badge" id="tab-cnt-all"><?php echo $total_count; ?></span>
                    </button>
                    <?php foreach ( OFP_Property_Inquiry::STATUSES as $k => $label ) : 
                        $cnt = isset( $counts_raw[ $k ] ) ? (int) $counts_raw[ $k ]->cnt : 0;
                    ?>
                        <button type="button" data-status="<?php echo esc_attr( $k ); ?>" class="ofp-inspection-tab ofp-segmented-tab <?php echo $filter_status === $k ? 'active' : ''; ?>">
                            <span><?php echo esc_html( $label ); ?></span>
                            <span class="ofp-tab-badge" id="tab-cnt-<?php echo esc_attr( $k ); ?>"><?php echo $cnt; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Inquiries List -->
            <?php if ( empty( $inquiries ) ) : ?>
                <div style="text-align: center; padding: 64px 20px; background: var(--card-bg); border: 1px dashed var(--border-color); border-radius: 16px;">
                    <div style="font-size: 40px; margin-bottom: 12px;">🗓️</div>
                    <h3 style="font-size: 18px; font-weight: 700; color: var(--text-main); margin: 0 0 6px;">No inspection requests found</h3>
                    <p style="font-size: 14px; color: var(--text-muted); max-width: 480px; margin: 0 auto 20px;">
                        When prospects submit inspection dates or connect with you from your property detail pages, their viewing requests and specific requirements will appear right here.
                    </p>
                    <a href="<?php echo esc_url( home_url( '/properties' ) ); ?>" class="ofp-btn ofp-btn-primary">
                        View Active Listings
                    </a>
                </div>
            <?php else : ?>
                <div id="ofp-inspection-empty-notice" style="display:none; text-align:center; padding:48px 20px; background:rgba(255,255,255,0.02); border:1px dashed var(--border-color); border-radius:14px; margin-bottom:20px;">
                    <p style="color:var(--text-muted); font-size:14px; margin:0;">No inspection requests match your selected filters or search query.</p>
                </div>
                <div class="ofp-inspections-list">
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
                    ?>
                        <div class="ofp-inspection-card" id="inspection-card-<?php echo esc_attr( $inq->id ); ?>"
                             data-id="<?php echo esc_attr( $inq->id ); ?>"
                             data-status="<?php echo esc_attr( $inq->status ); ?>"
                             data-tour="<?php echo esc_attr( $inq->tour_type ); ?>"
                             data-property="<?php echo (int) $inq->property_id; ?>"
                             data-search="<?php echo esc_attr( strtolower( $lead_name . ' ' . $lead_phone . ' ' . $inq->lead_email . ' ' . $prop_title . ' ' . $inq->message ) ); ?>"
                             style="<?php echo ( $filter_status && $filter_status !== $inq->status ) ? 'display:none;' : ''; ?>">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
                                
                                <!-- Left Column: Prospect & Property Details -->
                                <div style="flex: 1; min-width: 280px;">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                        <span class="ofp-status-pill ofp-status-<?php echo esc_attr( $inq->status ); ?>" id="status-pill-<?php echo esc_attr( $inq->id ); ?>">
                                            <?php echo esc_html( OFP_Property_Inquiry::STATUSES[ $inq->status ] ?? ucfirst( $inq->status ) ); ?>
                                        </span>
                                        <span class="ofp-tour-badge <?php echo $is_video ? 'ofp-tour-video' : 'ofp-tour-physical'; ?>">
                                            <?php if ( $is_video ) : ?>
                                                <svg style="width:14px;height:14px;" fill="currentColor" viewBox="0 0 24 24"><path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                WhatsApp Video Call
                                            <?php else : ?>
                                                <svg style="width:14px;height:14px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6m6 0l-3-3m3 3l-3 3"/></svg>
                                                Physical Showing
                                            <?php endif; ?>
                                        </span>
                                        <span style="font-size: 11px; color: var(--text-muted);">
                                            Booked <?php echo esc_html( gmdate( 'M j, Y g:i A', strtotime( $inq->created_at ) ) ); ?>
                                        </span>
                                    </div>

                                    <!-- Prospect Name & Property Info -->
                                    <div style="display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap;">
                                        <h3 style="font-size: 17px; font-weight: 700; color: var(--text-main); margin: 0;">
                                            <?php echo esc_html( $lead_name ); ?>
                                        </h3>
                                        <span style="color: var(--text-muted); font-size: 13px;">for</span>
                                        <a href="<?php echo esc_url( $prop_url ); ?>" target="_blank" style="font-size: 15px; font-weight: 600; color: #2563eb; text-decoration: none;">
                                            <?php echo esc_html( $prop_title ); ?> ↗
                                        </a>
                                    </div>

                                    <!-- Contact details + schedule badge -->
                                    <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: center; margin-top: 8px; font-size: 13px; color: var(--text-muted);">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <span style="font-weight: 600; color: var(--text-main);"><?php echo esc_html( $lead_phone ); ?></span>
                                        </div>
                                        <?php if ( ! empty( $inq->lead_email ) ) : ?>
                                            <div>&bull;</div>
                                            <div><?php echo esc_html( $inq->lead_email ); ?></div>
                                        <?php endif; ?>
                                        <div>&bull;</div>
                                        <div style="color: #2563eb; font-weight: 600;">
                                            📅 <?php echo esc_html( $inq->preferred_viewing_date ?: 'Date not specified' ); ?>
                                            <?php if ( ! empty( $inq->preferred_viewing_time ) ) : ?>
                                                at <?php echo esc_html( $inq->preferred_viewing_time ); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Prospect Detailed Request / Requirements -->
                                    <?php if ( ! empty( $inq->message ) ) : ?>
                                        <div class="ofp-notes-box">
                                            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 4px;">
                                                Prospect Request &amp; Specifications:
                                            </div>
                                            <div style="white-space: pre-line;">
                                                <?php echo esc_html( $inq->message ); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Right Column: Direct Prospect Communication & Status Actions -->
                                <div style="display: flex; flex-direction: column; gap: 10px; align-items: flex-end; min-width: 220px;">
                                    
                                    <!-- Direct Actions -->
                                    <div style="display: flex; gap: 8px; width: 100%; justify-content: flex-end;">
                                        <?php if ( $clean_lead_phone ) : ?>
                                            <a href="https://wa.me/<?php echo esc_attr( $clean_lead_phone ); ?>?text=<?php echo $wa_reply_text; ?>" target="_blank"
                                               class="ofp-btn ofp-btn-sm" style="background: #00a859; color: #fff; border: none; font-weight: 700; flex: 1; text-align: center; justify-content: center;">
                                                WhatsApp Prospect
                                            </a>
                                            <a href="tel:<?php echo esc_attr( $lead_phone ); ?>"
                                               class="ofp-btn ofp-btn-sm ofp-btn-secondary" style="font-weight: 700;">
                                                Call
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Change Status Selector Form -->
                                    <div style="width: 100%; background: var(--bg-subtle, #f8fafc); border: 1px solid var(--border-color); border-radius: 12px; padding: 10px 12px;">
                                        <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); margin-bottom: 6px;">
                                            Update Inspection Status:
                                        </div>
                                        <form method="POST" action="" onsubmit="return handleStatusSubmit(event, <?php echo (int) $inq->id; ?>)">
                                            <?php wp_nonce_field( 'ofp_inspection_action', 'ofp_inspection_nonce' ); ?>
                                            <input type="hidden" name="inquiry_id" value="<?php echo (int) $inq->id; ?>">
                                            <input type="hidden" name="ofp_update_status" value="1">
                                            
                                            <div style="display: flex; gap: 6px;">
                                                <select name="status" id="status-select-<?php echo (int) $inq->id; ?>" class="ofp-select" style="font-size: 12px; flex: 1; padding: 6px 10px;">
                                                    <?php foreach ( OFP_Property_Inquiry::STATUSES as $sk => $slabel ) : ?>
                                                        <option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $inq->status, $sk ); ?>>
                                                            <?php echo esc_html( $slabel ); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="ofp-btn ofp-btn-sm ofp-btn-secondary" style="font-size: 12px; font-weight: 600; padding: 6px 12px;">
                                                    Save
                                                </button>
                                            </div>
                                        </form>
                                    </div>

                                </div>

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <?php wp_footer(); ?>
</body>
</html>
