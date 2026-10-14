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

$inquiries = OFP_Property_Inquiry::get_client_inquiries( $client->id, $query_filters );

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
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
    <style>
        .ofp-inspection-card {
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .ofp-inspection-card:hover {
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
        }
        .ofp-tour-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .ofp-tour-physical {
            background: rgba(37, 99, 235, 0.1);
            color: #2563eb;
            border: 1px solid rgba(37, 99, 235, 0.2);
        }
        .ofp-tour-video {
            background: rgba(16, 185, 129, 0.1);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        .ofp-notes-box {
            background: var(--bg-subtle, #f8fafc);
            border: 1px solid var(--border-color, #e2e8f0);
            border-left: 3px solid #3b82f6;
            border-radius: 10px;
            padding: 12px 14px;
            margin-top: 12px;
            font-size: 13px;
            line-height: 1.5;
            color: var(--text-main, #1e293b);
        }
        .ofp-status-pill {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
        }
        .ofp-status-pending { background: #fef3c7; color: #b45309; }
        .ofp-status-confirmed { background: #dbeafe; color: #1d4ed8; }
        .ofp-status-completed { background: #d1fae5; color: #065f46; }
        .ofp-status-cancelled { background: #fee2e2; color: #b91c1c; }
        [data-theme="dark"] .ofp-status-pending { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        [data-theme="dark"] .ofp-status-confirmed { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        [data-theme="dark"] .ofp-status-completed { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        [data-theme="dark"] .ofp-status-cancelled { background: rgba(239, 68, 68, 0.2); color: #f87171; }
    </style>
</head>
<body class="ofp-portal-body">
    <?php 
    $ofp_banner_btn = [ 'label' => 'View Properties', 'url' => home_url( '/properties' ) ];
    include OFP_PATH . 'public/templates/partials/nav.php'; 
    ?>

    <div class="ofp-container">
        <div style="padding-bottom: 60px;">
            
            <!-- Page Header -->
            <div style="margin: 0 0 24px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1 style="font-size:24px; font-weight:700; color:var(--text-main); margin:0 0 6px; letter-spacing:-0.01em;">
                        Property Inspections &amp; Bookings
                    </h1>
                    <p style="font-size:14px; color:var(--text-muted); margin:0;">
                        Manage physical showings and WhatsApp video tour bookings requested by prospective buyers and tenants.
                    </p>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="<?php echo esc_url( home_url( '/properties' ) ); ?>" class="ofp-btn ofp-btn-secondary" style="font-size: 13px; font-weight: 600;">
                        Browse My Listings
                    </a>
                </div>
            </div>

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

            <!-- Metrics Overview Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
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

            <!-- Filter & Search Toolbar -->
            <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 14px; padding: 16px 20px; margin-bottom: 24px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 14px;">
                <!-- Status Tabs -->
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="<?php echo esc_url( remove_query_arg( 'status' ) ); ?>" 
                       class="ofp-btn ofp-btn-sm <?php echo empty( $filter_status ) ? 'ofp-btn-primary' : 'ofp-btn-ghost'; ?>">
                        All (<?php echo $total_count; ?>)
                    </a>
                    <?php foreach ( OFP_Property_Inquiry::STATUSES as $k => $label ) : 
                        $cnt = isset( $counts_raw[ $k ] ) ? (int) $counts_raw[ $k ]->cnt : 0;
                    ?>
                        <a href="<?php echo esc_url( add_query_arg( 'status', $k ) ); ?>" 
                           class="ofp-btn ofp-btn-sm <?php echo $filter_status === $k ? 'ofp-btn-primary' : 'ofp-btn-ghost'; ?>">
                            <?php echo esc_html( $label ); ?> (<?php echo $cnt; ?>)
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Secondary Filters: Tour Type & Property -->
                <form method="GET" action="" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin: 0;">
                    <?php if ( $filter_status ) : ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr( $filter_status ); ?>">
                    <?php endif; ?>

                    <select name="tour_type" onchange="this.form.submit()" class="ofp-select" style="font-size: 12px; padding: 6px 12px;">
                        <option value="">All Tour Types</option>
                        <option value="physical" <?php selected( $filter_tour_type, 'physical' ); ?>>Physical Tour</option>
                        <option value="whatsapp_video" <?php selected( $filter_tour_type, 'whatsapp_video' ); ?>>WhatsApp Video</option>
                    </select>

                    <?php if ( ! empty( $client_properties ) ) : ?>
                        <select name="property_id" onchange="this.form.submit()" class="ofp-select" style="font-size: 12px; padding: 6px 12px; max-width: 220px;">
                            <option value="">All Listings</option>
                            <?php foreach ( $client_properties as $cp ) : ?>
                                <option value="<?php echo (int) $cp->wp_post_id; ?>" <?php selected( $filter_prop_id, (int) $cp->wp_post_id ); ?>>
                                    <?php echo esc_html( wp_trim_words( $cp->title, 5 ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <?php if ( $filter_status || $filter_tour_type || $filter_prop_id ) : ?>
                        <a href="<?php echo esc_url( home_url( '/inspections' ) ); ?>" class="ofp-btn ofp-btn-sm ofp-btn-ghost" style="color: var(--text-muted); font-size: 12px;">
                            Clear
                        </a>
                    <?php endif; ?>
                </form>
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
                        <div class="ofp-inspection-card" id="inspection-card-<?php echo esc_attr( $inq->id ); ?>">
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

    <script>
    function handleStatusSubmit(e, inquiryId) {
        // Modern async update with fallback to standard form submit
        if (!window.fetch) return true;
        e.preventDefault();

        const form = e.target;
        const select = document.getElementById('status-select-' + inquiryId);
        const newStatus = select.value;
        const pill = document.getElementById('status-pill-' + inquiryId);
        const nonce = form.querySelector('[name="ofp_inspection_nonce"]').value;
        const button = form.querySelector('button[type="submit"]');

        const originalBtnText = button.textContent;
        button.textContent = 'Saving...';
        button.disabled = true;

        const bodyData = new URLSearchParams();
        bodyData.append('action', 'ofp_update_inspection_status');
        bodyData.append('nonce', nonce);
        bodyData.append('inquiry_id', inquiryId);
        bodyData.append('status', newStatus);

        fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: bodyData.toString()
        })
        .then(res => res.json())
        .then(data => {
            button.textContent = originalBtnText;
            button.disabled = false;
            if (data.success) {
                // Update pill style and text instantly
                const statusLabels = {
                    'pending': 'Pending Confirmation',
                    'confirmed': 'Confirmed',
                    'completed': 'Completed',
                    'cancelled': 'Cancelled'
                };
                pill.className = 'ofp-status-pill ofp-status-' + newStatus;
                pill.textContent = statusLabels[newStatus] || newStatus;
                
                // Show subtle feedback
                button.textContent = '✓ Saved';
                setTimeout(() => { button.textContent = originalBtnText; }, 2000);
            } else {
                alert(data.data ? data.data.message : 'Failed to update status.');
            }
        })
        .catch(err => {
            button.textContent = originalBtnText;
            button.disabled = false;
            // Fallback to normal form submit on error
            form.submit();
        });

        return false;
    }
    </script>
</body>
</html>
