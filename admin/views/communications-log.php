<?php
/**
 * Admin View: Communications Log
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

global $wpdb;
$p = $wpdb->prefix;

$filter_client = (int) ( $_GET['client_id'] ?? 0 );
$filter_type   = sanitize_text_field( $_GET['type'] ?? '' );
$per_page      = 50;
$current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$offset        = ( $current_page - 1 ) * $per_page;

$where = [ '1=1' ];
$args  = [];

if ( $filter_client ) {
    $where[] = 'cl.client_id = %d';
    $args[]  = $filter_client;
}
if ( $filter_type ) {
    $where[] = 'cl.type = %s';
    $args[]  = $filter_type;
}

$where_sql = implode( ' AND ', $where );

$total = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}ofp_communications_log cl WHERE {$where_sql}",
        ...$args
    )
);

$comms = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT cl.*, c.business_name, l.phone as lead_phone, l.name as lead_name
         FROM {$p}ofp_communications_log cl
         JOIN {$p}ofp_clients c ON c.id = cl.client_id
         JOIN {$p}ofp_leads l ON l.id = cl.lead_id
         WHERE {$where_sql}
         ORDER BY cl.sent_at DESC
         LIMIT %d OFFSET %d",
        ...array_merge( $args, [ $per_page, $offset ] )
    )
);

// Summary stats
$total_sms   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE type = 'sms'" );
$total_voice = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE type = 'voice'" );
$total_email = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE type = 'email'" );
$total_cost  = (float) $wpdb->get_var( "SELECT SUM(cost) FROM {$p}ofp_communications_log" );

$clients     = OFP_Client::all();
$total_pages = ceil( $total / $per_page );

$type_badges = [
    'sms'   => '<span class="ofp-badge ofp-badge-blue">SMS</span>',
    'voice' => '<span class="ofp-badge ofp-badge-green">Voice</span>',
    'email' => '<span class="ofp-badge ofp-badge-yellow">Email</span>',
];

include OFP_PATH . 'admin/views/partials/header.php';
?>

<h2>Communications Log</h2>

<div class="ofp-stats-grid">
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( number_format( $total_sms ) ); ?></span>
        <span class="ofp-stat-label">Total SMS</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( number_format( $total_voice ) ); ?></span>
        <span class="ofp-stat-label">Total Calls</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( number_format( $total_email ) ); ?></span>
        <span class="ofp-stat-label">Total Emails</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number">₦<?php echo esc_html( number_format( $total_cost, 2 ) ); ?></span>
        <span class="ofp-stat-label">Total Credit Used</span>
    </div>
</div>

<!-- Filters -->
<div class="ofp-filters">
    <form method="GET" action="" class="ofp-filter-form">
        <input type="hidden" name="page" value="ofp-communications">
        <select name="client_id" onchange="this.form.submit()">
            <option value="">All Clients</option>
            <?php foreach ( $clients as $c ) : ?>
                <option value="<?php echo esc_attr( $c->id ); ?>"
                    <?php selected( $filter_client, $c->id ); ?>>
                    <?php echo esc_html( $c->business_name ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="type" onchange="this.form.submit()">
            <option value="">All Types</option>
            <option value="sms"   <?php selected( $filter_type, 'sms' ); ?>>SMS</option>
            <option value="voice" <?php selected( $filter_type, 'voice' ); ?>>Voice</option>
            <option value="email" <?php selected( $filter_type, 'email' ); ?>>Email</option>
        </select>
        <?php if ( $filter_client || $filter_type ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-communications' ) ); ?>"
               class="button">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="ofp-section">
    <?php if ( empty( $comms ) ) : ?>
        <p>No communications logged yet.</p>
    <?php else : ?>
        <table class="widefat ofp-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Client</th>
                    <th>Lead</th>
                    <th>Status</th>
                    <th>Cost (NGN)</th>
                    <th>Sent</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $comms as $comm ) : ?>
                    <tr>
                        <td><?php echo $type_badges[ $comm->type ] ?? esc_html( strtoupper( $comm->type ) ); ?></td>
                        <td><?php echo esc_html( $comm->business_name ); ?></td>
                        <td>
                            <?php echo esc_html( $comm->lead_name ?: $comm->lead_phone ); ?><br>
                            <small><?php echo esc_html( $comm->lead_phone ); ?></small>
                        </td>
                        <td>
                            <?php
                            $status_class = $comm->status === 'sent' ? 'ofp-badge-green' : 'ofp-badge-red';
                            echo '<span class="ofp-badge ' . esc_attr( $status_class ) . '">'
                                . esc_html( $comm->status ) . '</span>';
                            ?>
                        </td>
                        <td><?php echo esc_html( number_format( (float) $comm->cost, 2 ) ); ?></td>
                        <td><?php echo esc_html(
                            human_time_diff( strtotime( $comm->sent_at ), current_time( 'timestamp' ) ) . ' ago'
                        ); ?></td>
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
