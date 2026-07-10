<?php
/**
 * Template: /leads
 * Client's leads list with filtering and status management.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

if ( ! OFP_Subscription::has_active( 'crm', $client->id ) ) {
    wp_safe_redirect( home_url( '/dashboard' ) );
    exit;
}

// Handle status update
$message = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_leads_nonce'] ) ) {
    if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ofp_leads_nonce'] ) ), 'ofp_leads_' . $client->id ) ) {
        $lead_id    = (int) ( $_POST['lead_id'] ?? 0 );
        $new_status = sanitize_text_field( wp_unslash( $_POST['new_status'] ?? '' ) );
        $allowed    = [ 'new', 'contacted', 'interested', 'converted', 'dead' ];

        if ( $lead_id && in_array( $new_status, $allowed, true ) ) {
            // Verify this lead belongs to this client.
            global $wpdb;
            $owns = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ofp_leads WHERE id = %d AND client_id = %d LIMIT 1",
                    $lead_id, $client->id
                )
            );
            if ( $owns ) {
                OFP_Lead::update_status( $lead_id, $new_status );
                if ( $new_status === 'converted' ) {
                    OFP_Queue::cancel_for_lead( $lead_id );
                }
                $message = 'success';
            }
        }
    }
}

// Pagination and filter
$filter_status = sanitize_text_field( $_GET['status'] ?? '' );
$per_page      = 20;
$current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

global $wpdb;
$p     = $wpdb->prefix;
$where = 'l.client_id = %d';
$args  = [ $client->id ];

if ( $filter_status ) {
    $where .= ' AND l.status = %s';
    $args[] = $filter_status;
}

$total = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_leads l WHERE {$where}", ...$args )
);

$leads = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT l.* FROM {$p}ofp_leads l
         WHERE {$where}
         ORDER BY l.created_at DESC
         LIMIT %d OFFSET %d",
        ...array_merge( $args, [ $per_page, ( $current_page - 1 ) * $per_page ] )
    )
);

$total_pages = ceil( $total / $per_page );

$stats = OFP_Lead::get_stats( $client->id );

$status_badges = [
    'new'        => '<span class="ofp-badge ofp-badge-blue">New</span>',
    'contacted'  => '<span class="ofp-badge ofp-badge-yellow">Contacted</span>',
    'interested' => '<span class="ofp-badge ofp-badge-orange">Interested</span>',
    'converted'  => '<span class="ofp-badge ofp-badge-green">✅ Converted</span>',
    'dead'       => '<span class="ofp-badge ofp-badge-grey">Dead</span>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Leads — OFast Pipeline</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <div class="ofp-page-header">
        <h1>My Leads</h1>
        <p>All leads captured through your pipeline.</p>
    </div>

    <?php if ( $message === 'success' ) : ?>
        <div class="ofp-alert ofp-alert-success">✅ Lead status updated.</div>
    <?php endif; ?>

    <!-- Stats row -->
    <div class="ofp-stats-grid" style="margin-bottom:24px;">
        <div class="ofp-stat-card">
            <div class="ofp-stat-header">
                <span class="ofp-stat-title">Today</span>
            </div>
            <div class="ofp-stat-value"><?php echo esc_html( $stats['today'] ); ?></div>
        </div>
        <div class="ofp-stat-card">
            <div class="ofp-stat-header">
                <span class="ofp-stat-title">This Month</span>
            </div>
            <div class="ofp-stat-value"><?php echo esc_html( $stats['this_month'] ); ?></div>
        </div>
        <div class="ofp-stat-card">
            <div class="ofp-stat-header">
                <span class="ofp-stat-title">Converted</span>
            </div>
            <div class="ofp-stat-value"><?php echo esc_html( $stats['converted'] ); ?></div>
        </div>
        <div class="ofp-stat-card">
            <div class="ofp-stat-header">
                <span class="ofp-stat-title">Interested</span>
            </div>
            <div class="ofp-stat-value"><?php echo esc_html( $stats['interested'] ); ?></div>
        </div>
    </div>

    <!-- Filter tabs -->
    <div style="display:flex;gap:4px;margin-bottom:24px;border-bottom:2px solid var(--border-color);padding-bottom:0;">
        <?php
        $filters = [ '' => 'All', 'new' => 'New', 'contacted' => 'Contacted', 'interested' => 'Interested', 'converted' => 'Converted', 'dead' => 'Dead' ];
        foreach ( $filters as $val => $label ) :
            $active = $filter_status === $val;
        ?>
            <a href="<?php echo esc_url( add_query_arg( 'status', $val, home_url( '/leads' ) ) ); ?>"
               style="padding:8px 14px;font-size:13px;font-weight:500;text-decoration:none;border-bottom:2px solid <?php echo $active ? 'var(--accent-blue)' : 'transparent'; ?>;margin-bottom:-2px;color:<?php echo $active ? 'var(--accent-blue)' : 'var(--text-muted)'; ?>;">
                <?php echo esc_html( $label ); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="ofp-card" style="padding:0;overflow:hidden;">
        <?php if ( empty( $leads ) ) : ?>
            <div class="ofp-empty" style="padding:48px;">
                <div class="ofp-empty-icon">📭</div>
                <h3>No leads found</h3>
                <p>Leads matching this filter will appear here.</p>
            </div>
        <?php else : ?>
            <div class="ofp-table-wrap">
                <table class="ofp-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>IVR</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $leads as $lead ) : ?>
                            <tr>
                                <td><?php echo esc_html( $lead->name ?: '—' ); ?></td>
                                <td><strong><?php echo esc_html( $lead->phone ); ?></strong></td>
                                <td><?php echo esc_html( $lead->email ?: '—' ); ?></td>
                                <td><?php echo $status_badges[ $lead->status ] ?? esc_html( $lead->status ); ?></td>
                                <td><?php echo $lead->ivr_response ? esc_html( 'Pressed ' . $lead->ivr_response ) : '—'; ?></td>
                                <td style="white-space:nowrap;font-size:12px;color:#9ca3af;">
                                    <?php echo esc_html( gmdate( 'M j, Y', strtotime( $lead->created_at ) ) ); ?>
                                </td>
                                <td>
                                    <?php if ( $lead->status !== 'converted' ) : ?>
                                        <form method="POST" action="" style="display:inline;">
                                            <?php wp_nonce_field( 'ofp_leads_' . $client->id, 'ofp_leads_nonce' ); ?>
                                            <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead->id ); ?>">
                                            <select name="new_status" onchange="this.form.submit()" style="font-size:12px;padding:4px 8px;border:1px solid #e5e7eb;border-radius:4px;background:#fff;">
                                                <?php foreach ( array_keys( $status_badges ) as $s ) : ?>
                                                    <option value="<?php echo esc_attr( $s ); ?>"
                                                        <?php selected( $lead->status, $s ); ?>>
                                                        <?php echo esc_html( ucfirst( $s ) ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    <?php else : ?>
                                        <span style="font-size:12px;color:#9ca3af;">Closed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="ofp-pagination" style="padding:16px;">
                    <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( [ 'paged' => $i, 'status' => $filter_status ], home_url( '/leads' ) ) ); ?>"
                           class="ofp-page-btn <?php echo $i === $current_page ? 'active' : ''; ?>">
                            <?php echo esc_html( $i ); ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
