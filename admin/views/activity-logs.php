<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$limit = 50;
$offset = ( $paged - 1 ) * $limit;

$total_logs = $wpdb->get_var( "SELECT COUNT(id) FROM {$wpdb->prefix}ofp_activity_logs" );
$total_pages = ceil( $total_logs / $limit );

$logs = $wpdb->get_results( $wpdb->prepare(
    "SELECT l.*, c.business_name, a.name AS admin_name
     FROM {$wpdb->prefix}ofp_activity_logs l
     LEFT JOIN {$wpdb->prefix}ofp_clients c ON l.client_id = c.id
     LEFT JOIN {$wpdb->prefix}ofp_admins a ON l.admin_id = a.id
     ORDER BY l.created_at DESC
     LIMIT %d OFFSET %d",
    $limit, $offset
) );

?>
<div class="wrap ofp-admin-wrap">
    <h1 class="wp-heading-inline">Global Activity Logs</h1>
    <p>Audit trail of all administrative and client actions.</p>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 180px;">Date</th>
                <th>Action</th>
                <th>Client</th>
                <th>User / Admin</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $logs ) ) : ?>
                <tr>
                    <td colspan="5">No activity logs found.</td>
                </tr>
            <?php else : ?>
                <?php foreach ( $logs as $log ) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html( wp_date( 'M j, Y \a\t g:i a', strtotime( $log->created_at ) ) ); ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html( $log->action ); ?></strong>
                        </td>
                        <td>
                            <?php if ( $log->client_id ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&client_id=' . $log->client_id ) ); ?>">
                                    <?php echo esc_html( $log->business_name ?: 'Client #' . $log->client_id ); ?>
                                </a>
                            <?php else : ?>
                                <span class="ofp-badge" style="background:#e5e7eb;color:#374151;padding:2px 8px;border-radius:12px;font-size:11px;">Global</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html( $log->admin_name ?: 'System / Unknown' ); ?>
                        </td>
                        <td>
                            <?php if ( $log->details ) : ?>
                                <button type="button" class="button button-small" onclick="alert(<?php echo esc_attr( wp_json_encode( $log->details ) ); ?>)">View Details</button>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                echo paginate_links( [
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'   => $total_pages,
                    'current' => $paged,
                ] );
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>
