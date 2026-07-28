<?php
/**
 * Admin View: Trigger Queue
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

global $wpdb;
$p = $wpdb->prefix;

$filter_status = sanitize_text_field( $_GET['filter'] ?? '' );
$filter_client = (int) ( $_GET['client_id'] ?? 0 );
$per_page      = 50;
$current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$offset        = ( $current_page - 1 ) * $per_page;

$where = [ '1=1' ];
$args  = [];

if ( $filter_status ) {
    $where[] = 'q.status = %s';
    $args[]  = $filter_status;
}
if ( $filter_client ) {
    $where[] = 'q.client_id = %d';
    $args[]  = $filter_client;
}

$where_sql = implode( ' AND ', $where );

$total = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}ofp_trigger_queue q WHERE {$where_sql}",
        ...$args
    )
);

$triggers = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT q.*, c.business_name, l.phone as lead_phone
         FROM {$p}ofp_trigger_queue q
         JOIN {$p}ofp_clients c ON c.id = q.client_id
         JOIN {$p}ofp_leads l ON l.id = q.lead_id
         WHERE {$where_sql}
         ORDER BY q.scheduled_at DESC
         LIMIT %d OFFSET %d",
        ...array_merge( $args, [ $per_page, $offset ] )
    )
);

$total_pages = ceil( $total / $per_page );

$status_badges = [
    'pending'    => '<span class="ofp-badge ofp-badge-yellow">Pending</span>',
    'processing' => '<span class="ofp-badge ofp-badge-blue">Processing</span>',
    'completed'  => '<span class="ofp-badge ofp-badge-green">Completed</span>',
    'failed'     => '<span class="ofp-badge ofp-badge-red">Failed</span>',
    'cancelled'  => '<span class="ofp-badge ofp-badge-grey">Cancelled</span>',
];

// Summary counts
$counts = [];
foreach ( array_keys( $status_badges ) as $s ) {
    $counts[ $s ] = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_trigger_queue WHERE status = %s", $s )
    );
}

include OFP_PATH . 'admin/views/partials/header.php';
?>

<h2>Trigger Queue</h2>

<!-- Summary -->
<div class="ofp-stats-grid">
    <?php foreach ( $counts as $status => $count ) : ?>
        <div class="ofp-stat-card">
            <span class="ofp-stat-number"><?php echo esc_html( $count ); ?></span>
            <span class="ofp-stat-label"><?php echo esc_html( ucfirst( $status ) ); ?></span>
        </div>
    <?php endforeach; ?>
</div>

<!-- Filter tabs -->
<div class="ofp-filter-tabs">
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-triggers' ) ); ?>"
       class="<?php echo ! $filter_status ? 'active' : ''; ?>">All</a>
    <?php foreach ( array_keys( $status_badges ) as $s ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-triggers&filter=' . $s ) ); ?>"
           class="<?php echo $filter_status === $s ? 'active' : ''; ?>">
            <?php echo esc_html( ucfirst( $s ) ); ?> (<?php echo esc_html( $counts[ $s ] ); ?>)
        </a>
    <?php endforeach; ?>
</div>

<div class="ofp-section">
    <?php if ( empty( $triggers ) ) : ?>
        <p>No triggers found.</p>
    <?php else : ?>
        <table class="widefat ofp-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Client</th>
                    <th>Lead Phone</th>
                    <th>Scheduled</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Message Preview</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $triggers as $t ) : ?>
                    <tr class="<?php echo $t->status === 'failed' ? 'ofp-row-error' : ''; ?>">
                        <td><strong><?php echo esc_html( strtoupper( $t->type ) ); ?></strong></td>
                        <td><?php echo esc_html( $t->business_name ); ?></td>
                        <td><?php echo esc_html( $t->lead_phone ); ?></td>
                        <td><?php echo esc_html( $t->scheduled_at ); ?></td>
                        <td><?php echo $status_badges[ $t->status ] ?? esc_html( $t->status ); ?></td>
                        <td><?php echo esc_html( $t->attempts ); ?></td>
                        <td>
                            <span title="<?php echo esc_attr( $t->message ); ?>">
                                <?php echo esc_html( wp_trim_words( $t->message ?? '', 8, '…' ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ( in_array( $t->status, [ 'failed', 'pending' ], true ) ) : ?>
                                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'ofp_retry_trigger' ); ?>
                                    <input type="hidden" name="action" value="ofp_retry_trigger">
                                    <input type="hidden" name="trigger_id" value="<?php echo esc_attr( $t->id ); ?>">
                                    <input type="hidden" name="return_filter" value="<?php echo esc_attr( $filter_status ); ?>">
                                    <button type="submit" class="button button-small"
                                            onclick="return confirm('Retry this trigger in 30 minutes?');">
                                        Retry
                                    </button>
                                </form>
                                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'ofp_cancel_trigger' ); ?>
                                    <input type="hidden" name="action" value="ofp_cancel_trigger">
                                    <input type="hidden" name="trigger_id" value="<?php echo esc_attr( $t->id ); ?>">
                                    <input type="hidden" name="return_filter" value="<?php echo esc_attr( $filter_status ); ?>">
                                    <button type="submit" class="button button-small ofp-btn-danger"
                                            onclick="return confirm('Cancel this trigger permanently?');">
                                        Cancel
                                    </button>
                                </form>
                            <?php else : ?>
                                <span class="ofp-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="ofp-pagination">
                <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>"
                       class="button button-small <?php echo $i === $current_page ? 'button-primary' : ''; ?>">
                        <?php echo esc_html( $i ); ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include OFP_PATH . 'admin/views/partials/footer.php'; ?>
