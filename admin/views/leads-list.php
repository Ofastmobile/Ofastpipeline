<?php
/**
 * Admin View: Leads List
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

global $wpdb;
$p = $wpdb->prefix;

$filter_client = (int) ( $_GET['client_id'] ?? 0 );
$filter_status = sanitize_text_field( $_GET['status'] ?? '' );
$per_page      = 50;
$current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$offset        = ( $current_page - 1 ) * $per_page;

// Build query
$where = [ '1=1' ];
$args  = [];

if ( $filter_client ) {
    $where[] = 'l.client_id = %d';
    $args[]  = $filter_client;
}
if ( $filter_status ) {
    $where[] = 'l.status = %s';
    $args[]  = $filter_status;
}

$where_sql = implode( ' AND ', $where );

$total_leads = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}ofp_leads l WHERE {$where_sql}",
        ...$args
    )
);

$leads = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT l.*, c.business_name
         FROM {$p}ofp_leads l
         JOIN {$p}ofp_clients c ON c.id = l.client_id
         WHERE {$where_sql}
         ORDER BY l.created_at DESC
         LIMIT %d OFFSET %d",
        ...array_merge( $args, [ $per_page, $offset ] )
    )
);

$clients = OFP_Client::all();

$status_badges = [
    'new'        => '<span class="ofp-badge ofp-badge-blue">New</span>',
    'contacted'  => '<span class="ofp-badge ofp-badge-yellow">Contacted</span>',
    'interested' => '<span class="ofp-badge ofp-badge-green">Interested</span>',
    'converted'  => '<span class="ofp-badge ofp-badge-green">✅ Converted</span>',
    'dead'       => '<span class="ofp-badge ofp-badge-grey">Dead</span>',
];

$total_pages = ceil( $total_leads / $per_page );

include OFP_PATH . 'admin/views/partials/header.php';
?>

<h2>Leads <span class="ofp-count">(<?php echo esc_html( number_format( $total_leads ) ); ?> total)</span></h2>

<!-- Filters -->
<div class="ofp-filters">
    <form method="GET" action="" class="ofp-filter-form">
        <input type="hidden" name="page" value="ofp-leads">

        <select name="client_id" onchange="this.form.submit()">
            <option value="">All Clients</option>
            <?php foreach ( $clients as $c ) : ?>
                <option value="<?php echo esc_attr( $c->id ); ?>"
                    <?php selected( $filter_client, $c->id ); ?>>
                    <?php echo esc_html( $c->business_name ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <?php foreach ( array_keys( $status_badges ) as $s ) : ?>
                <option value="<?php echo esc_attr( $s ); ?>"
                    <?php selected( $filter_status, $s ); ?>>
                    <?php echo esc_html( ucfirst( $s ) ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ( $filter_client || $filter_status ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-leads' ) ); ?>"
               class="button">Clear Filters</a>
        <?php endif; ?>
    </form>
</div>

<!-- Leads table -->
<div class="ofp-section">
    <?php if ( empty( $leads ) ) : ?>
        <p>No leads found.</p>
    <?php else : ?>
        <table class="widefat ofp-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Client</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th>IVR</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $leads as $lead ) : ?>
                    <tr>
                        <td><?php echo esc_html( $lead->name ?: '—' ); ?></td>
                        <td><strong><?php echo esc_html( $lead->phone ); ?></strong></td>
                        <td><?php echo esc_html( $lead->business_name ); ?></td>
                        <td>
                            <?php
                            $source = $lead->source ?: 'direct';
                            // Show just the domain if it's a full URL
                            if ( filter_var( $source, FILTER_VALIDATE_URL ) ) {
                                $source = wp_parse_url( $source, PHP_URL_HOST ) ?: $source;
                            }
                            echo esc_html( $source );
                            ?>
                        </td>
                        <td><?php echo $status_badges[ $lead->status ] ?? esc_html( $lead->status ); ?></td>
                        <td><?php echo $lead->ivr_response ? esc_html( 'Pressed ' . $lead->ivr_response ) : '—'; ?></td>
                        <td>
                            <?php
                            $lead_dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $lead->created_at, wp_timezone() );
                            $lead_ts = $lead_dt ? $lead_dt->getTimestamp() : strtotime( $lead->created_at );
                            echo esc_html( human_time_diff( $lead_ts, time() ) . ' ago' );
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
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
