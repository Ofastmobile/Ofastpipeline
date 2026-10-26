<?php
/**
 * Admin View: Feedback & Bug Reports List
 * Displays client feature requests and bug reports with filters,
 * video embeds (YouTube / Loom), and status tracking.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$table = $wpdb->prefix . 'ofp_feedback';
$clients_table = $wpdb->prefix . 'ofp_clients';

// Ensure table exists
if ( class_exists( 'OFP_Feedback' ) ) {
    OFP_Feedback::ensure_schema();
}

// ── Filters & Pagination ──────────────────────────────────────────────────
$paged       = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$limit       = 25;
$offset      = ( $paged - 1 ) * $limit;

$type_filter   = sanitize_key( $_GET['type'] ?? '' );
$status_filter = sanitize_key( $_GET['status'] ?? '' );
$search        = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );

$where_clauses = [];
$args          = [];

if ( in_array( $type_filter, [ 'feature_request', 'bug_report' ], true ) ) {
    $where_clauses[] = 'f.type = %s';
    $args[] = $type_filter;
}

if ( in_array( $status_filter, [ 'new', 'under_review', 'planned', 'in_progress', 'resolved', 'declined' ], true ) ) {
    $where_clauses[] = 'f.status = %s';
    $args[] = $status_filter;
}

if ( ! empty( $search ) ) {
    $search_like = '%' . $wpdb->esc_like( $search ) . '%';
    $where_clauses[] = '(f.title LIKE %s OR f.description LIKE %s OR c.business_name LIKE %s OR c.owner_name LIKE %s)';
    $args[] = $search_like;
    $args[] = $search_like;
    $args[] = $search_like;
    $args[] = $search_like;
}

$where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

// Count total
$count_query = "SELECT COUNT(f.id) FROM {$table} f LEFT JOIN {$clients_table} c ON f.client_id = c.id {$where_sql}";
$total_items = ! empty( $args ) ? (int) $wpdb->get_var( $wpdb->prepare( $count_query, $args ) ) : (int) $wpdb->get_var( $count_query );
$total_pages = ceil( $total_items / $limit );

// Overall metric counts for stat cards
$cnt_total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
$cnt_features = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE type = 'feature_request'" );
$cnt_bugs     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE type = 'bug_report'" );
$cnt_new      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" );
$cnt_resolved = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'resolved'" );

// Fetch items
$query_args = array_merge( $args, [ $limit, $offset ] );
$items = $wpdb->get_results( $wpdb->prepare(
    "SELECT f.*, c.business_name, c.owner_name, c.email AS client_email, c.phone AS client_phone, c.logo_url
     FROM {$table} f
     LEFT JOIN {$clients_table} c ON f.client_id = c.id
     {$where_sql}
     ORDER BY (f.status = 'new') DESC, f.created_at DESC
     LIMIT %d OFFSET %d",
    $query_args
) );

$is_portal = ( isset( $_GET['tab'] ) || strpos( ( $_SERVER['REQUEST_URI'] ?? '' ), 'admin-portal' ) !== false );
$current_page_base = $is_portal ? home_url( '/admin-portal?tab=feedback' ) : admin_url( 'admin.php?page=ofp-feedback' );
?>

<div class="ofp-feedback-wrap wrap">
    <!-- Header -->
    <div class="ofp-fb-header">
        <div>
            <h1 class="ofp-fb-title">Client Feedback & Bug Reports</h1>
            <p class="ofp-fb-subtitle">Review suggestions, ideas, and issue reports submitted directly by clients from their portal.</p>
        </div>
        <div class="ofp-fb-header-actions">
            <span class="ofp-fb-counter-pill">
                <strong><?php echo esc_html( $cnt_new ); ?></strong> Unreviewed
            </span>
        </div>
    </div>

    <!-- Proper Stat Cards Grid -->
    <div class="ofp-stats-grid">
        <div class="ofp-stat-card">
            <span class="ofp-stat-number"><?php echo esc_html( $cnt_total ); ?></span>
            <span class="ofp-stat-label">Total Submissions</span>
        </div>

        <div class="ofp-stat-card">
            <span class="ofp-stat-number ofp-accent"><?php echo esc_html( $cnt_features ); ?></span>
            <span class="ofp-stat-label">Feature Requests</span>
        </div>

        <div class="ofp-stat-card">
            <span class="ofp-stat-number"><?php echo esc_html( $cnt_bugs ); ?></span>
            <span class="ofp-stat-label">Bug Reports</span>
        </div>

        <div class="ofp-stat-card">
            <span class="ofp-stat-number" style="color: #d97706;"><?php echo esc_html( $cnt_new ); ?></span>
            <span class="ofp-stat-label">Awaiting Review</span>
        </div>

        <div class="ofp-stat-card">
            <span class="ofp-stat-number" style="color: #059669;"><?php echo esc_html( $cnt_resolved ); ?></span>
            <span class="ofp-stat-label">Completed / Resolved</span>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="ofp-fb-filter-bar">
        <form method="GET" action="<?php echo esc_url( $current_page_base ); ?>" class="ofp-fb-filter-form">
            <?php if ( $is_portal ) : ?>
                <input type="hidden" name="tab" value="feedback">
            <?php else : ?>
                <input type="hidden" name="page" value="ofp-feedback">
            <?php endif; ?>

            <!-- Type Filter Tabs -->
            <div class="ofp-fb-tabs">
                <a href="<?php echo esc_url( remove_query_arg( [ 'type', 'paged' ], $current_page_base ) ); ?>" class="ofp-fb-tab <?php echo empty( $type_filter ) ? 'active' : ''; ?>">
                    All (<?php echo esc_html( $cnt_total ); ?>)
                </a>
                <a href="<?php echo esc_url( add_query_arg( [ 'type' => 'feature_request', 'paged' => 1 ], $current_page_base ) ); ?>" class="ofp-fb-tab <?php echo $type_filter === 'feature_request' ? 'active' : ''; ?>">
                    Feature Requests (<?php echo esc_html( $cnt_features ); ?>)
                </a>
                <a href="<?php echo esc_url( add_query_arg( [ 'type' => 'bug_report', 'paged' => 1 ], $current_page_base ) ); ?>" class="ofp-fb-tab <?php echo $type_filter === 'bug_report' ? 'active' : ''; ?>">
                    Bug Reports (<?php echo esc_html( $cnt_bugs ); ?>)
                </a>
            </div>

            <div class="ofp-fb-filter-controls">
                <!-- Status Dropdown -->
                <select name="status" class="ofp-fb-select" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="new" <?php selected( $status_filter, 'new' ); ?>>New (Unreviewed)</option>
                    <option value="under_review" <?php selected( $status_filter, 'under_review' ); ?>>Under Review</option>
                    <option value="planned" <?php selected( $status_filter, 'planned' ); ?>>Planned for Build</option>
                    <option value="in_progress" <?php selected( $status_filter, 'in_progress' ); ?>>In Progress</option>
                    <option value="resolved" <?php selected( $status_filter, 'resolved' ); ?>>Resolved / Completed</option>
                    <option value="declined" <?php selected( $status_filter, 'declined' ); ?>>Declined / Closed</option>
                </select>

                <!-- Search Input -->
                <div class="ofp-fb-search-wrap">
                    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search title, description, client..." class="ofp-fb-search-input">
                    <button type="submit" class="ofp-fb-search-btn">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    </button>
                </div>

                <?php if ( ! empty( $search ) || ! empty( $status_filter ) || ! empty( $type_filter ) ) : ?>
                    <a href="<?php echo esc_url( $current_page_base ); ?>" class="ofp-fb-reset-btn" title="Reset Filters">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Table Container -->
    <div class="ofp-fb-table-container">
        <?php if ( empty( $items ) ) : ?>
            <div class="ofp-fb-empty">
                <div class="ofp-fb-empty-icon">
                    <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
                </div>
                <h3>No Submissions Found</h3>
                <p>When clients submit a feature request or report an issue from their portal, it will show up here.</p>
            </div>
        <?php else : ?>
            <table class="ofp-fb-table">
                <thead>
                    <tr>
                        <th style="width: 100px;">Type</th>
                        <th>Summary & Details</th>
                        <th style="width: 180px;">Client</th>
                        <th style="width: 130px;">Video / Media</th>
                        <th style="width: 100px;">Priority</th>
                        <th style="width: 130px;">Status</th>
                        <th style="width: 130px;">Date</th>
                        <th style="width: 100px; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $items as $item ) :
                        $is_feature = ( $item->type === 'feature_request' );
                        $embed_url  = OFP_Feedback::get_embed_url( $item->video_url );
                        $provider   = OFP_Feedback::get_video_provider( $item->video_url );

                        $status_classes = [
                            'new'          => 'status-new',
                            'under_review' => 'status-review',
                            'planned'      => 'status-planned',
                            'in_progress'  => 'status-progress',
                            'resolved'     => 'status-resolved',
                            'declined'     => 'status-declined',
                        ];
                        $status_label = [
                            'new'          => 'New',
                            'under_review' => 'Under Review',
                            'planned'      => 'Planned',
                            'in_progress'  => 'In Progress',
                            'resolved'     => 'Resolved',
                            'declined'     => 'Declined',
                        ][ $item->status ] ?? ucfirst( $item->status );

                        $prio_classes = [
                            'low'      => 'prio-low',
                            'medium'   => 'prio-med',
                            'high'     => 'prio-high',
                            'critical' => 'prio-crit',
                        ];
                    ?>
                        <tr class="ofp-fb-row <?php echo $item->status === 'new' ? 'is-unread' : ''; ?>" id="ofp-fb-row-<?php echo esc_attr( $item->id ); ?>">
                            <!-- Type -->
                            <td>
                                <?php if ( $is_feature ) : ?>
                                    <span class="ofp-fb-type-badge badge-feature">
                                        Feature
                                    </span>
                                <?php else : ?>
                                    <span class="ofp-fb-type-badge badge-bug">
                                        Bug
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Title & Description -->
                            <td>
                                <div class="ofp-fb-title-cell">
                                    <span class="ofp-fb-item-title"><?php echo esc_html( $item->title ); ?></span>
                                    <?php if ( ! empty( $item->category ) ) : ?>
                                        <span class="ofp-fb-item-category"><?php echo esc_html( $item->category ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="ofp-fb-item-desc">
                                    <?php echo esc_html( wp_trim_words( $item->description, 20, '…' ) ); ?>
                                </div>
                                <?php if ( ! empty( $item->admin_notes ) ) : ?>
                                    <div class="ofp-fb-internal-note-snippet">
                                        <strong>Internal Note:</strong> <?php echo esc_html( wp_trim_words( $item->admin_notes, 12, '…' ) ); ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Client -->
                            <td>
                                <div class="ofp-fb-client-cell">
                                    <strong><?php echo esc_html( $item->business_name ?: ( $item->owner_name ?: 'System Client #' . $item->client_id ) ); ?></strong>
                                    <?php if ( ! empty( $item->client_email ) ) : ?>
                                        <span><?php echo esc_html( $item->client_email ); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Video / Media -->
                            <td>
                                <?php if ( ! empty( $item->video_url ) ) : ?>
                                    <button type="button" class="ofp-fb-video-btn ofp-open-video-preview"
                                            data-video-url="<?php echo esc_url( $item->video_url ); ?>"
                                            data-embed-url="<?php echo esc_url( $embed_url ?? '' ); ?>"
                                            data-title="<?php echo esc_attr( $item->title ); ?>">
                                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                        <?php echo esc_html( ucfirst( $provider ) ); ?> Video
                                    </button>
                                <?php else : ?>
                                    <span class="ofp-fb-no-video">None</span>
                                <?php endif; ?>
                            </td>

                            <!-- Priority -->
                            <td>
                                <span class="ofp-fb-prio-badge <?php echo esc_attr( $prio_classes[ $item->priority ] ?? 'prio-med' ); ?>">
                                    <?php echo esc_html( ucfirst( $item->priority ) ); ?>
                                </span>
                            </td>

                            <!-- Status -->
                            <td>
                                <span class="ofp-fb-status-badge <?php echo esc_attr( $status_classes[ $item->status ] ?? 'status-review' ); ?>">
                                    <?php echo esc_html( $status_label ); ?>
                                </span>
                            </td>

                            <!-- Date -->
                            <td>
                                <span class="ofp-fb-date"><?php echo esc_html( date( 'M j, Y', strtotime( $item->created_at ) ) ); ?></span>
                                <small class="ofp-fb-time"><?php echo esc_html( date( 'g:i a', strtotime( $item->created_at ) ) ); ?></small>
                            </td>

                            <!-- Action -->
                            <td style="text-align: right;">
                                <button type="button" class="ofp-fb-action-btn ofp-open-detail"
                                        data-id="<?php echo esc_attr( $item->id ); ?>"
                                        data-type="<?php echo esc_attr( $item->type ); ?>"
                                        data-title="<?php echo esc_attr( $item->title ); ?>"
                                        data-category="<?php echo esc_attr( $item->category ); ?>"
                                        data-priority="<?php echo esc_attr( $item->priority ); ?>"
                                        data-status="<?php echo esc_attr( $item->status ); ?>"
                                        data-description="<?php echo esc_attr( $item->description ); ?>"
                                        data-video-url="<?php echo esc_url( $item->video_url ?? '' ); ?>"
                                        data-embed-url="<?php echo esc_url( $embed_url ?? '' ); ?>"
                                        data-client="<?php echo esc_attr( $item->business_name ?: $item->owner_name ); ?>"
                                        data-email="<?php echo esc_attr( $item->client_email ?? '' ); ?>"
                                        data-page-url="<?php echo esc_url( $item->page_url ?? '' ); ?>"
                                        data-device="<?php echo esc_attr( $item->device_info ?? '' ); ?>"
                                        data-notes="<?php echo esc_attr( $item->admin_notes ?? '' ); ?>"
                                        data-created="<?php echo esc_attr( date( 'F j, Y at g:i A', strtotime( $item->created_at ) ) ); ?>">
                                    Review
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
                <div class="ofp-fb-pagination">
                    <span>Showing <?php echo count( $items ); ?> of <?php echo esc_html( $total_items ); ?> items</span>
                    <div class="ofp-fb-page-links">
                        <?php for ( $i = 1; $i <= $total_pages; $i++ ) :
                            $page_url = add_query_arg( [ 'paged' => $i ], $current_page_base );
                            if ( ! empty( $type_filter ) )   $page_url = add_query_arg( [ 'type' => $type_filter ], $page_url );
                            if ( ! empty( $status_filter ) ) $page_url = add_query_arg( [ 'status' => $status_filter ], $page_url );
                            if ( ! empty( $search ) )        $page_url = add_query_arg( [ 's' => $search ], $page_url );
                        ?>
                            <a href="<?php echo esc_url( $page_url ); ?>" class="ofp-fb-page-link <?php echo $i === $paged ? 'active' : ''; ?>">
                                <?php echo esc_html( $i ); ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── Detail Review Modal ────────────────────────────────────────────────── -->
<div class="ofp-fb-modal-backdrop" id="ofp-fb-detail-modal" style="display: none;">
    <div class="ofp-fb-modal-box">
        <div class="ofp-fb-modal-header">
            <div class="ofp-fb-modal-header-meta">
                <span class="ofp-fb-type-badge" id="modal-type-badge">Feature</span>
                <span class="ofp-fb-modal-date" id="modal-created-at"></span>
            </div>
            <button type="button" class="ofp-fb-modal-close" id="modal-close-btn">&times;</button>
        </div>

        <div class="ofp-fb-modal-body">
            <h2 class="ofp-fb-modal-title" id="modal-title"></h2>
            
            <div class="ofp-fb-meta-grid">
                <div>
                    <strong>Category:</strong> <span id="modal-category"></span>
                </div>
                <div>
                    <strong>Priority:</strong> <span id="modal-priority"></span>
                </div>
                <div>
                    <strong>Submitted by:</strong> <span id="modal-client"></span> (<span id="modal-email"></span>)
                </div>
                <div id="modal-page-url-wrap" style="display:none;">
                    <strong>Trigger Page:</strong> <a href="#" target="_blank" id="modal-page-link" style="color:#10b981; word-break:break-all;"></a>
                </div>
            </div>

            <!-- Video Player (if available) -->
            <div class="ofp-fb-video-container" id="modal-video-wrap" style="display: none;">
                <div class="ofp-fb-video-label">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                    <span>Client Video Explanation</span>
                    <a href="#" target="_blank" id="modal-raw-video-link" class="ofp-fb-raw-link">Open in New Tab &nearr;</a>
                </div>
                <div class="ofp-fb-iframe-holder" id="modal-iframe-holder">
                    <!-- iframe will be dynamically placed here -->
                </div>
            </div>

            <!-- Description -->
            <div class="ofp-fb-desc-section">
                <h3>Description</h3>
                <div class="ofp-fb-desc-content" id="modal-description"></div>
            </div>

            <!-- Diagnostics (for bug reports) -->
            <div class="ofp-fb-diagnostics" id="modal-diagnostics-wrap" style="display: none;">
                <details>
                    <summary><strong>Device & Browser Diagnostics</strong></summary>
                    <pre id="modal-device-info"></pre>
                </details>
            </div>

            <!-- Admin Response / Status Workflow -->
            <form id="ofp-fb-status-form" class="ofp-fb-status-form">
                <input type="hidden" id="modal-item-id" name="id" value="">
                
                <div class="ofp-fb-form-row">
                    <div class="ofp-fb-field-group">
                        <label for="modal-status-select"><strong>Update Status:</strong></label>
                        <select id="modal-status-select" name="status" class="ofp-fb-select">
                            <option value="new">New (Unreviewed)</option>
                            <option value="under_review">Under Review</option>
                            <option value="planned">Planned for Build</option>
                            <option value="in_progress">In Progress / Working On It</option>
                            <option value="resolved">Resolved / Implemented</option>
                            <option value="declined">Declined / Closed</option>
                        </select>
                    </div>

                    <div class="ofp-fb-checkbox-group">
                        <label>
                            <input type="checkbox" name="notify_client" id="modal-notify-client" value="1" checked>
                            Notify client of this status update
                        </label>
                    </div>
                </div>

                <div class="ofp-fb-field-group" style="margin-top: 12px;">
                    <label for="modal-admin-notes"><strong>Internal Notes & Client Update Message:</strong></label>
                    <textarea id="modal-admin-notes" name="admin_notes" rows="3" class="ofp-fb-textarea" placeholder="Add private notes or instructions for the engineering team, or a message to the client..."></textarea>
                </div>

                <div class="ofp-fb-modal-footer">
                    <button type="button" class="ofp-fb-delete-btn" id="modal-delete-btn">Delete Submission</button>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="ofp-fb-cancel-btn" id="modal-cancel-btn">Close</button>
                        <button type="submit" class="ofp-fb-save-btn" id="modal-save-btn">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal        = document.getElementById('ofp-fb-detail-modal');
    var closeBtn     = document.getElementById('modal-close-btn');
    var cancelBtn    = document.getElementById('modal-cancel-btn');
    var statusForm   = document.getElementById('ofp-fb-status-form');
    var deleteBtn    = document.getElementById('modal-delete-btn');
    var saveBtn      = document.getElementById('modal-save-btn');
    var iframeHolder = document.getElementById('modal-iframe-holder');

    function closeModal() {
        modal.style.display = 'none';
        iframeHolder.innerHTML = ''; // Stop video playback
    }

    if ( closeBtn )  closeBtn.addEventListener('click', closeModal);
    if ( cancelBtn ) cancelBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', function(e) {
        if ( e.target === modal ) closeModal();
    });

    // Review Button Handler
    document.querySelectorAll('.ofp-open-detail').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;

            document.getElementById('modal-item-id').value = d.id;
            document.getElementById('modal-title').textContent = d.title;
            document.getElementById('modal-category').textContent = d.category || 'General';
            document.getElementById('modal-priority').textContent = d.priority.toUpperCase();
            document.getElementById('modal-client').textContent = d.client || 'Unknown';
            document.getElementById('modal-email').textContent = d.email || 'N/A';
            document.getElementById('modal-created-at').textContent = d.created;
            document.getElementById('modal-description').textContent = d.description;
            document.getElementById('modal-status-select').value = d.status;
            document.getElementById('modal-admin-notes').value = d.notes || '';

            // Badge
            var typeBadge = document.getElementById('modal-type-badge');
            if ( d.type === 'feature_request' ) {
                typeBadge.className = 'ofp-fb-type-badge badge-feature';
                typeBadge.textContent = 'Feature Request';
            } else {
                typeBadge.className = 'ofp-fb-type-badge badge-bug';
                typeBadge.textContent = 'Bug Report';
            }

            // Trigger Page
            var pageWrap = document.getElementById('modal-page-url-wrap');
            var pageLink = document.getElementById('modal-page-link');
            if ( d.pageUrl ) {
                pageWrap.style.display = 'block';
                pageLink.href = d.pageUrl;
                pageLink.textContent = d.pageUrl;
            } else {
                pageWrap.style.display = 'none';
            }

            // Diagnostics
            var diagWrap = document.getElementById('modal-diagnostics-wrap');
            var diagInfo = document.getElementById('modal-device-info');
            if ( d.device ) {
                diagWrap.style.display = 'block';
                try {
                    diagInfo.textContent = JSON.stringify(JSON.parse(d.device), null, 2);
                } catch(e) {
                    diagInfo.textContent = d.device;
                }
            } else {
                diagWrap.style.display = 'none';
            }

            // Video Player
            var videoWrap = document.getElementById('modal-video-wrap');
            var rawLink   = document.getElementById('modal-raw-video-link');
            iframeHolder.innerHTML = '';

            if ( d.embedUrl ) {
                videoWrap.style.display = 'block';
                rawLink.href = d.videoUrl;
                iframeHolder.innerHTML = '<iframe src="' + d.embedUrl + '" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
            } else if ( d.videoUrl ) {
                videoWrap.style.display = 'block';
                rawLink.href = d.videoUrl;
                iframeHolder.innerHTML = '<div style="padding:20px; color:#cbd5e1; text-align:center;">Video link provided: <a href="' + d.videoUrl + '" target="_blank" style="color:#38bdf8;">' + d.videoUrl + '</a></div>';
            } else {
                videoWrap.style.display = 'none';
            }

            modal.style.display = 'flex';
        });
    });

    // Quick Video Preview Handler from Table
    document.querySelectorAll('.ofp-open-video-preview').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var row = this.closest('tr');
            var reviewBtn = row.querySelector('.ofp-open-detail');
            if ( reviewBtn ) reviewBtn.click();
        });
    });

    // Save Status & Notes
    statusForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var itemId   = document.getElementById('modal-item-id').value;
        var status   = document.getElementById('modal-status-select').value;
        var notes    = document.getElementById('modal-admin-notes').value;
        var notify   = document.getElementById('modal-notify-client').checked ? 1 : 0;

        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        var fd = new FormData();
        fd.append('action', 'ofp_update_feedback');
        fd.append('nonce', typeof ofpAdmin !== 'undefined' ? ofpAdmin.nonce : '<?php echo wp_create_nonce( 'ofp_admin_nonce' ); ?>');
        fd.append('id', itemId);
        fd.append('status', status);
        fd.append('admin_notes', notes);
        fd.append('notify_client', notify);

        var ajaxUrl = typeof ofpAdmin !== 'undefined' ? ofpAdmin.ajaxUrl : '<?php echo admin_url( 'admin-ajax.php' ); ?>';

        fetch(ajaxUrl, {
            method: 'POST',
            body: fd
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Changes';
            if ( data.success ) {
                alert('Feedback updated successfully!');
                window.location.reload();
            } else {
                alert(data.data && data.data.message ? data.data.message : 'Error updating feedback.');
            }
        })
        .catch(function() {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Changes';
            alert('Server error occurred.');
        });
    });

    // Delete Item
    deleteBtn.addEventListener('click', function() {
        var itemId = document.getElementById('modal-item-id').value;
        if ( ! confirm('Are you sure you want to permanently delete this submission?') ) return;

        deleteBtn.disabled = true;
        deleteBtn.textContent = 'Deleting...';

        var fd = new FormData();
        fd.append('action', 'ofp_delete_feedback');
        fd.append('nonce', typeof ofpAdmin !== 'undefined' ? ofpAdmin.nonce : '<?php echo wp_create_nonce( 'ofp_admin_nonce' ); ?>');
        fd.append('id', itemId);

        var ajaxUrl = typeof ofpAdmin !== 'undefined' ? ofpAdmin.ajaxUrl : '<?php echo admin_url( 'admin-ajax.php' ); ?>';

        fetch(ajaxUrl, {
            method: 'POST',
            body: fd
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            deleteBtn.disabled = false;
            deleteBtn.textContent = 'Delete Submission';
            if ( data.success ) {
                var row = document.getElementById('ofp-fb-row-' + itemId);
                if ( row ) row.remove();
                closeModal();
            } else {
                alert(data.data && data.data.message ? data.data.message : 'Error deleting item.');
            }
        })
        .catch(function() {
            deleteBtn.disabled = false;
            deleteBtn.textContent = 'Delete Submission';
            alert('Server error.');
        });
    });
});
</script>
