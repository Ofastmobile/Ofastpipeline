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
    <style>
    /* ── STAT CARDS GRID: 4 columns on desktop, 2 columns on mobile ─── */
    .ofp-comm-stat-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 32px;
    }

    .ofp-comm-stat-card {
        border-radius: 16px;
        padding: 24px;
        color: #fff;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
    }

    .ofp-comm-stat-card .stat-bg-icon {
        position: absolute;
        top: -10px;
        right: -10px;
        opacity: 0.15;
        width: 90px;
        height: 90px;
    }

    .ofp-comm-stat-card .stat-title {
        font-size: 13px;
        font-weight: 500;
        opacity: 0.9;
        margin-bottom: 8px;
    }

    .ofp-comm-stat-card .stat-val {
        font-size: 28px;
        font-weight: 700;
    }

    @media (max-width: 768px) {
        .ofp-comm-stat-grid {
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 12px !important;
            margin-bottom: 20px !important;
        }
        .ofp-comm-stat-card {
            padding: 16px 14px !important;
            border-radius: 12px !important;
        }
        .ofp-comm-stat-card .stat-bg-icon {
            width: 55px !important;
            height: 55px !important;
            top: -5px !important;
            right: -5px !important;
        }
        .ofp-comm-stat-card .stat-title {
            font-size: 11.5px !important;
            margin-bottom: 4px !important;
        }
        .ofp-comm-stat-card .stat-val {
            font-size: 20px !important;
        }
    }

    /* ── SINGLE STRAIGHT-LINE HORIZONTAL FILTER BAR ─────────────────── */
    .ofp-comm-filter-wrapper {
        background: var(--bg-card, #ffffff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 12px;
        padding: 12px 16px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        position: relative;
        z-index: 1000;
        overflow: visible;
    }

    .ofp-comm-filter-bar {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: nowrap;
        position: relative;
        z-index: 1000;
        overflow: visible;
    }

    @media (max-width: 768px) {
        .ofp-comm-filter-wrapper {
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .ofp-comm-filter-wrapper::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
    }

    .ofp-comm-filter-item {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
        white-space: nowrap;
        position: relative;
        z-index: 1001;
    }

    .ofp-comm-filter-label {
        font-size: 11.5px;
        font-weight: 700;
        color: var(--text-muted, #64748b);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        user-select: none;
        margin: 0;
    }

    .ofp-comm-search-box {
        position: relative;
        flex-shrink: 0;
        width: 250px;
        min-width: 190px;
    }

    .ofp-comm-search-box svg {
        position: absolute;
        left: 11px;
        top: 50%;
        transform: translateY(-50%);
        pointer-events: none;
        color: var(--text-muted, #94a3b8);
    }

    .ofp-comm-search-input {
        height: 38px !important;
        padding: 0 12px 0 34px !important;
        font-size: 13px !important;
        border-radius: 8px !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }

    /* ── Remove ALL arrows on dropdowns & enforce visible options with high z-index ── */
    .ofp-comm-filter-bar .ofp-custom-select-trigger::after,
    .ofp-custom-select-trigger::after {
        display: none !important;
        content: none !important;
    }

    .ofp-comm-select,
    select.ofp-comm-select {
        height: 38px !important;
        padding: 0 14px !important;
        font-size: 13px !important;
        border-radius: 8px !important;
        width: auto !important;
        min-width: 130px !important;
        cursor: pointer !important;
        box-sizing: border-box !important;
        background-image: none !important;
        -webkit-appearance: none !important;
        -moz-appearance: none !important;
        appearance: none !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-wrapper {
        position: relative !important;
        width: auto !important;
        min-width: 130px !important;
        z-index: 1002 !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-wrapper.open {
        z-index: 99999 !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-trigger {
        height: 38px !important;
        padding: 0 14px !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        border-radius: 8px !important;
        background: var(--bg-body, #f8fafc) !important;
        border: 1px solid var(--border-color, #e2e8f0) !important;
        color: var(--text-main, #0f172a) !important;
        min-width: 130px !important;
        white-space: nowrap !important;
        box-sizing: border-box !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-start !important;
        cursor: pointer !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-options {
        position: absolute !important;
        top: calc(100% + 4px) !important;
        left: 0 !important;
        min-width: 160px !important;
        width: max-content !important;
        max-width: 280px !important;
        z-index: 999999 !important;
        background: var(--bg-card, #ffffff) !important;
        border: 1px solid var(--border-color, #e2e8f0) !important;
        border-radius: 8px !important;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
        max-height: 260px !important;
        overflow-y: auto !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-wrapper.open .ofp-custom-select-options {
        display: block !important;
        opacity: 1 !important;
        visibility: visible !important;
        transform: translateY(0) !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-option {
        padding: 9px 14px !important;
        font-size: 13px !important;
        font-weight: 500 !important;
        color: var(--text-main, #0f172a) !important;
        cursor: pointer !important;
        white-space: nowrap !important;
        transition: background 0.15s ease, color 0.15s ease !important;
    }

    .ofp-comm-filter-bar .ofp-custom-select-option:hover,
    .ofp-comm-filter-bar .ofp-custom-select-option.selected {
        background: var(--bg-lighter, #f1f5f9) !important;
        color: var(--primary, #3b82f6) !important;
    }

    .ofp-comm-clear-btn {
        height: 38px;
        padding: 0 14px;
        border-radius: 8px;
        border: 1px solid var(--border-color, #e2e8f0);
        background: transparent;
        color: var(--text-muted, #64748b);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        flex-shrink: 0;
        white-space: nowrap;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .ofp-comm-clear-btn:hover {
        color: var(--text-main, #0f172a);
        border-color: var(--text-muted, #94a3b8);
        background: var(--bg-lighter, #f8fafc);
    }

    .ofp-comm-count-badge {
        margin-left: auto;
        flex-shrink: 0;
        white-space: nowrap;
        font-size: 13px;
        color: var(--text-muted, #64748b);
        font-weight: 500;
    }

    #comm-feed-container {
        transition: opacity 0.2s ease;
    }
    </style>
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
        <div id="comm-feed-container">
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

<script>
(function() {
    var form                = document.getElementById('comm-filter-form');
    var feedContainer       = document.getElementById('comm-feed-container');
    var paginationContainer = document.getElementById('comm-pagination-container');
    var countBadge          = document.getElementById('comm-count-badge');
    var clearBtn            = document.getElementById('comm-clear-btn');
    var searchInput         = document.getElementById('comm-search-input');
    var typeSelect          = document.getElementById('comm-type-select');
    var statusSelect        = document.getElementById('comm-status-select');

    if (!feedContainer) return;

    var searchTimer = null;
    var currentPage = <?php echo (int) $current_page; ?>;
    var ajaxUrl     = (window.ofpClientData && window.ofpClientData.ajaxurl) ? window.ofpClientData.ajaxurl : '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
    var nonce       = (window.ofpClientData && window.ofpClientData.nonce) ? window.ofpClientData.nonce : '<?php echo wp_create_nonce( 'ofp_client_ajax' ); ?>';

    function fetchCommunications(page) {
        if (page !== undefined && page !== null) {
            currentPage = page;
        }

        var type   = typeSelect ? typeSelect.value : '';
        var status = statusSelect ? statusSelect.value : '';
        var s      = searchInput ? searchInput.value.trim() : '';

        // Visual loading state
        feedContainer.style.opacity = '0.4';
        feedContainer.style.pointerEvents = 'none';

        var formData = new FormData();
        formData.append('action', 'ofp_fetch_communications');
        formData.append('nonce', nonce);
        formData.append('type', type);
        formData.append('status', status);
        formData.append('s', s);
        formData.append('paged', currentPage);

        fetch(ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            feedContainer.style.opacity = '1';
            feedContainer.style.pointerEvents = 'auto';

            if (res && res.success && res.data) {
                feedContainer.innerHTML = res.data.html;
                if (paginationContainer) {
                    paginationContainer.innerHTML = res.data.pagination;
                }
                if (countBadge) {
                    countBadge.innerHTML = res.data.count_text;
                }
                if (clearBtn) {
                    clearBtn.style.display = res.data.has_filter ? 'inline-flex' : 'none';
                }

                // Smooth URL update without browser reload
                var url = new URL(window.location.href);
                if (type) url.searchParams.set('type', type); else url.searchParams.delete('type');
                if (status) url.searchParams.set('status', status); else url.searchParams.delete('status');
                if (s) url.searchParams.set('s', s); else url.searchParams.delete('s');
                if (currentPage > 1) url.searchParams.set('paged', currentPage); else url.searchParams.delete('paged');
                window.history.replaceState({}, '', url.toString());
            }
        })
        .catch(function(err) {
            feedContainer.style.opacity = '1';
            feedContainer.style.pointerEvents = 'auto';
            console.error('AJAX communications fetch error:', err);
        });
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', function() {
            fetchCommunications(1);
        });
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            fetchCommunications(1);
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() {
                fetchCommunications(1);
            }, 350);
        });
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchTimer);
                fetchCommunications(1);
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            clearTimeout(searchTimer);
            fetchCommunications(1);
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (searchInput) searchInput.value = '';
            if (typeSelect) typeSelect.value = '';
            if (statusSelect) statusSelect.value = '';
            fetchCommunications(1);
        });
    }

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.ofp-comm-page-btn');
        if (btn) {
            e.preventDefault();
            var page = parseInt(btn.getAttribute('data-page'), 10);
            if (page && page !== currentPage) {
                fetchCommunications(page);
                feedContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    });

    // Dynamically position custom dropdown options with fixed coordinates and max z-index so options never clip
    document.addEventListener('click', function(e) {
        var trigger = e.target.closest('.ofp-comm-filter-bar .ofp-custom-select-trigger');
        if (trigger) {
            var wrapper = trigger.closest('.ofp-custom-select-wrapper');
            if (wrapper) {
                setTimeout(function() {
                    var options = wrapper.querySelector('.ofp-custom-select-options');
                    if (options && wrapper.classList.contains('open')) {
                        var rect = trigger.getBoundingClientRect();
                        options.style.position = 'fixed';
                        options.style.top = (rect.bottom + 4) + 'px';
                        options.style.left = rect.left + 'px';
                        options.style.minWidth = Math.max(rect.width, 160) + 'px';
                        options.style.zIndex = '999999';
                        options.style.display = 'block';
                        options.style.opacity = '1';
                        options.style.visibility = 'visible';
                    }
                }, 10);
            }
        }
    });

    window.addEventListener('scroll', function() {
        document.querySelectorAll('.ofp-comm-filter-bar .ofp-custom-select-wrapper.open').forEach(function(el) {
            el.classList.remove('open');
        });
    }, { passive: true });
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
