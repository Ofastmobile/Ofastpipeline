<?php
/**
 * Template: /communications
 * Client's full communication log - every SMS, voice call, and email sent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

global $wpdb;
$p = $wpdb->prefix;

if ( class_exists( 'OFP_Communications_Log' ) ) {
    OFP_Communications_Log::ensure_schema();
    OFP_Communications_Log::sync_historical_activity( (int) $client->id );
}

$filter_type   = sanitize_text_field( $_GET['type'] ?? '' );
$filter_status = sanitize_text_field( $_GET['status'] ?? '' );
$search        = sanitize_text_field( $_GET['s'] ?? '' );
$per_page      = 25;
$current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

// Fetch initial data via centralized helper
$data = OFP_Communications_Log::fetch( (int) $client->id, [
    'type'   => $filter_type,
    'status' => $filter_status,
    's'      => $search,
], $current_page, $per_page );

$comms       = $data['items'];
$total       = $data['total'];
$total_pages = $data['total_pages'];

// Summary counts (scoped to client across all records)
$sms_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE client_id = %d AND type = 'sms'", (int) $client->id ) );
$voice_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE client_id = %d AND type = 'voice'", (int) $client->id ) );
$email_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE client_id = %d AND type = 'email'", (int) $client->id ) );
$total_cost  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(cost) FROM {$p}ofp_communications_log WHERE client_id = %d AND status NOT IN ('failed', 'error', 'pending')", (int) $client->id ) );

$has_filters = ! empty( $filter_type ) || ! empty( $filter_status ) || ! empty( $search );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Communications', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <!-- Hero Summary Cards: 4 on desktop, 2 on mobile -->
    <div class="ofp-comm-stat-grid">
        <!-- SMS Sent (Bold Purple) -->
        <div class="ofp-comm-stat-card" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); box-shadow: 0 10px 15px -3px rgba(124, 58, 237, 0.2);">
            <div class="stat-bg-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:100%;height:100%;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
            </div>
            <div class="stat-title">Total SMS Sent</div>
            <div class="stat-val"><?php echo esc_html( number_format( $sms_count ) ); ?></div>
        </div>

        <!-- Calls Made (Bold Green) -->
        <div class="ofp-comm-stat-card" style="background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.2);">
            <div class="stat-bg-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:100%;height:100%;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
            </div>
            <div class="stat-title">Total Calls Made</div>
            <div class="stat-val"><?php echo esc_html( number_format( $voice_count ) ); ?></div>
        </div>

        <!-- Emails Sent (Bold Amber) -->
        <div class="ofp-comm-stat-card" style="background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 10px 15px -3px rgba(245, 158, 11, 0.2);">
            <div class="stat-bg-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:100%;height:100%;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
            </div>
            <div class="stat-title">Total Emails Sent</div>
            <div class="stat-val"><?php echo esc_html( number_format( $email_count ) ); ?></div>
        </div>

        <!-- Total Credit Used (Bold Blue) -->
        <div class="ofp-comm-stat-card" style="background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.2);">
            <div class="stat-bg-icon" style="font-size: 80px; font-weight: 700; font-family: sans-serif; line-height: 1; display:flex; justify-content:center; align-items:center;">₦</div>
            <div class="stat-title">Total Credit Used</div>
            <div class="stat-val">₦<?php echo esc_html( number_format( $total_cost, 0 ) ); ?></div>
        </div>
    </div>

    <!-- Straight-Line Horizontal Filter Bar -->
    <div class="ofp-comm-filter-wrapper">
        <form id="comm-filter-form" method="GET" action="<?php echo esc_url( home_url( '/communications' ) ); ?>" style="margin:0;">
            <div class="ofp-comm-filter-bar">
                
                <!-- Search Box -->
                <div class="ofp-comm-search-box">
                    <input type="text" id="comm-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" 
                           placeholder="Search recipient, phone or text..." 
                           class="ofp-input ofp-comm-search-input">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </div>

                <!-- Channel Dropdown (inherits platform .ofp-select) -->
                <div class="ofp-comm-filter-item">
                    <label class="ofp-comm-filter-label" for="comm-type-select">Channel:</label>
                    <select id="comm-type-select" name="type" class="ofp-select ofp-comm-select">
                        <option value="" <?php selected( $filter_type, '' ); ?>>All Channels</option>
                        <option value="sms" <?php selected( $filter_type, 'sms' ); ?>>SMS</option>
                        <option value="voice" <?php selected( $filter_type, 'voice' ); ?>>Voice</option>
                        <option value="email" <?php selected( $filter_type, 'email' ); ?>>Email</option>
                    </select>
                </div>

                <!-- Status Dropdown (inherits platform .ofp-select) -->
                <div class="ofp-comm-filter-item">
                    <label class="ofp-comm-filter-label" for="comm-status-select">Status:</label>
                    <select id="comm-status-select" name="status" class="ofp-select ofp-comm-select">
                        <option value="" <?php selected( $filter_status, '' ); ?>>All Statuses</option>
                        <option value="sent" <?php selected( $filter_status, 'sent' ); ?>>Sent / Delivered</option>
                        <option value="pending" <?php selected( $filter_status, 'pending' ); ?>>Pending / Queued</option>
                        <option value="failed" <?php selected( $filter_status, 'failed' ); ?>>Failed</option>
                    </select>
                </div>

                <!-- Clear Button -->
                <button type="button" id="comm-clear-btn" class="ofp-comm-clear-btn" style="<?php echo $has_filters ? 'display:inline-flex;' : 'display:none;'; ?>">
                    Clear
                </button>

                <!-- Records Counter -->
                <div id="comm-count-badge" class="ofp-comm-count-badge">
                    Showing <strong><?php echo esc_html( number_format( count( $comms ) ) ); ?></strong> of <strong><?php echo esc_html( number_format( $total ) ); ?></strong> records
                </div>

            </div>
        </form>
    </div>

    <!-- Feed Card -->
    <div class="ofp-card" style="padding:0; overflow:hidden;">
        <div id="comm-feed-container" data-current-page="<?php echo (int) $current_page; ?>">
            <?php echo OFP_Communications_Log::render_feed_html( $comms, (int) $client->id ); ?>
        </div>

        <div id="comm-pagination-container">
            <?php echo OFP_Communications_Log::render_pagination_html( $total_pages, $current_page, home_url( '/communications' ), [
                'type'   => $filter_type,
                'status' => $filter_status,
                's'      => $search,
            ] ); ?>
        </div>
    </div>

</div>
</main>
</div><!-- .ofp-shell -->


<?php wp_footer(); ?>
</body>
</html>
