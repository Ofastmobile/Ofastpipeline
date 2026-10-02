<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$limit = 50;
$offset = ( $paged - 1 ) * $limit;

$actor_filter = isset( $_GET['actor_type'] ) ? sanitize_key( $_GET['actor_type'] ) : '';
$where = '';
$args  = [];
if ( in_array( $actor_filter, [ 'client', 'team_member', 'admin', 'system' ], true ) ) {
    // actor_type is stored inside the JSON details blob, not a real column.
    $where = "WHERE l.details LIKE %s";
    $args[] = '%"actor_type":"' . $wpdb->esc_like( $actor_filter ) . '"%';
}

$total_logs = $where
    ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$wpdb->prefix}ofp_activity_logs l {$where}", $args ) )
    : $wpdb->get_var( "SELECT COUNT(id) FROM {$wpdb->prefix}ofp_activity_logs" );
$total_pages = ceil( $total_logs / $limit );

$query_args = array_merge( $args, [ $limit, $offset ] );
$logs = $wpdb->get_results( $wpdb->prepare(
    "SELECT l.*, c.business_name, a.name AS admin_name
     FROM {$wpdb->prefix}ofp_activity_logs l
     LEFT JOIN {$wpdb->prefix}ofp_clients c ON l.client_id = c.id
     LEFT JOIN {$wpdb->prefix}ofp_admins a ON l.admin_id = a.id
     {$where}
     ORDER BY l.created_at DESC
     LIMIT %d OFFSET %d",
    $query_args
) );

?>
<div class="wrap ofp-admin-wrap">
    <h1 class="wp-heading-inline">Global Activity Logs</h1>
    <p>Audit trail of all administrative, client, and team member actions.</p>

    <ul class="subsubsub">
        <li><a href="<?php echo esc_url( remove_query_arg( [ 'actor_type', 'paged' ] ) ); ?>" <?php echo $actor_filter === '' ? 'class="current"' : ''; ?>>All</a> |</li>
        <li><a href="<?php echo esc_url( add_query_arg( 'actor_type', 'client', remove_query_arg( 'paged' ) ) ); ?>" <?php echo $actor_filter === 'client' ? 'class="current"' : ''; ?>>Clients</a> |</li>
        <li><a href="<?php echo esc_url( add_query_arg( 'actor_type', 'team_member', remove_query_arg( 'paged' ) ) ); ?>" <?php echo $actor_filter === 'team_member' ? 'class="current"' : ''; ?>>Team Members</a> |</li>
        <li><a href="<?php echo esc_url( add_query_arg( 'actor_type', 'admin', remove_query_arg( 'paged' ) ) ); ?>" <?php echo $actor_filter === 'admin' ? 'class="current"' : ''; ?>>Admin (you)</a> |</li>
        <li><a href="<?php echo esc_url( add_query_arg( 'actor_type', 'system', remove_query_arg( 'paged' ) ) ); ?>" <?php echo $actor_filter === 'system' ? 'class="current"' : ''; ?>>System / Cron</a></li>
    </ul>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 180px;">Date</th>
                <th>Action</th>
                <th>Client</th>
                <th>Actor</th>
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
                    <?php
                        $details    = $log->details ? json_decode( $log->details, true ) : [];
                        $actor_type = $details['actor_type'] ?? ( $log->admin_id ? 'admin' : 'system' );
                        $actor_name = $details['actor_name'] ?? null;

                        switch ( $actor_type ) {
                            case 'team_member':
                                $actor_label = 'Team Member' . ( $actor_name ? ': ' . $actor_name : '' );
                                $actor_color = '#f59e0b'; // amber — draws the eye, this is the one to watch
                                break;
                            case 'client':
                                $actor_label = 'Client' . ( $actor_name ? ': ' . $actor_name : '' );
                                $actor_color = '#2563eb';
                                break;
                            case 'admin':
                                $actor_label = 'Admin' . ( $log->admin_name ? ': ' . $log->admin_name : '' );
                                $actor_color = '#7c3aed';
                                break;
                            default:
                                $actor_label = 'System / Cron';
                                $actor_color = '#6b7280';
                        }
                    ?>
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
                            <span style="background:<?php echo esc_attr( $actor_color ); ?>1a;color:<?php echo esc_attr( $actor_color ); ?>;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;white-space:nowrap;">
                                <?php echo esc_html( $actor_label ); ?>
                            </span>
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
