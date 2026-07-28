<?php
/**
 * Admin View: Billing
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
if ( $filter_client ) { $where[] = 's.client_id = %d'; $args[] = $filter_client; }
if ( $filter_type )   { $where[] = 's.type = %s';      $args[] = $filter_type; }
$where_sql = implode( ' AND ', $where );

$total = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_subscriptions s WHERE {$where_sql}", ...$args )
);

$subscriptions = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT s.*, c.business_name, c.email
         FROM {$p}ofp_subscriptions s
         JOIN {$p}ofp_clients c ON c.id = s.client_id
         WHERE {$where_sql}
         ORDER BY s.created_at DESC
         LIMIT %d OFFSET %d",
        ...array_merge( $args, [ $per_page, $offset ] )
    )
);

// Revenue summary
$total_revenue = (float) $wpdb->get_var(
    "SELECT SUM(amount) FROM {$p}ofp_subscriptions WHERE status = 'paid'"
);
$revenue_month = (float) $wpdb->get_var(
    "SELECT SUM(amount) FROM {$p}ofp_subscriptions
     WHERE status = 'paid'
     AND MONTH(paid_at) = MONTH(NOW())
     AND YEAR(paid_at) = YEAR(NOW())"
);
$pending_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}ofp_subscriptions WHERE status = 'pending'"
);

$clients     = OFP_Client::all();
$total_pages = ceil( $total / $per_page );

include OFP_PATH . 'admin/views/partials/header.php';
?>

<h2>Billing & Payments</h2>

<div class="ofp-stats-grid">
    <div class="ofp-stat-card">
        <span class="ofp-stat-number">₦<?php echo esc_html( number_format( $total_revenue, 0 ) ); ?></span>
        <span class="ofp-stat-label">Total Revenue</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number ofp-accent">₦<?php echo esc_html( number_format( $revenue_month, 0 ) ); ?></span>
        <span class="ofp-stat-label">This Month</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( $pending_count ); ?></span>
        <span class="ofp-stat-label">Pending Payments</span>
    </div>
</div>

<!-- Filters -->
<div class="ofp-filters">
    <form method="GET" action="" class="ofp-filter-form">
        <input type="hidden" name="page" value="ofp-billing">
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
            <option value="crm"     <?php selected( $filter_type, 'crm' ); ?>>CRM</option>
            <option value="listing" <?php selected( $filter_type, 'listing' ); ?>>Listing</option>
        </select>
        <?php if ( $filter_client || $filter_type ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-billing' ) ); ?>"
               class="button">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="ofp-section">
    <?php if ( empty( $subscriptions ) ) : ?>
        <p>No payment records found.</p>
    <?php else : ?>
        <table class="widefat ofp-table">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Type</th>
                    <th>Plan</th>
                    <th>Amount (NGN)</th>
                    <th>Status</th>
                    <th>Period</th>
                    <th>Paid At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $subscriptions as $sub ) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $sub->business_name ); ?></strong><br>
                            <small><?php echo esc_html( $sub->email ); ?></small>
                        </td>
                        <td>
                            <?php
                            $type_class = $sub->type === 'crm' ? 'ofp-badge-blue' : 'ofp-badge-green';
                            echo '<span class="ofp-badge ' . esc_attr( $type_class ) . '">'
                                . esc_html( strtoupper( $sub->type ) ) . '</span>';
                            ?>
                        </td>
                        <td><?php echo esc_html( strtoupper( $sub->plan ?: '—' ) ); ?></td>
                        <td><strong>₦<?php echo esc_html( number_format( (float) $sub->amount, 0 ) ); ?></strong></td>
                        <td>
                            <?php
                            $s_class = match ( $sub->status ) {
                                'paid'      => 'ofp-badge-green',
                                'underpaid' => 'ofp-badge-red',
                                default     => 'ofp-badge-yellow',
                            };
                            // 'pending' is the initial placeholder row created on onboarding
                            // before any payment has been received. Label it clearly so
                            // it is never mistaken for a failed or overdue payment.
                            $s_label = $sub->status === 'pending'
                                ? 'Awaiting First Payment'
                                : ucfirst( $sub->status );
                            echo '<span class="ofp-badge ' . esc_attr( $s_class ) . '">'
                                . esc_html( $s_label ) . '</span>';

                            if ( $sub->status === 'underpaid' && ! empty( $sub->expected_amount ) ) {
                                $shortfall = max( 0, (float) $sub->expected_amount - (float) $sub->amount );
                                echo '<div style="font-size:11px;color:#dc2626;margin-top:4px;">'
                                    . 'Expected ₦' . esc_html( number_format( (float) $sub->expected_amount, 0 ) )
                                    . ' — short ₦' . esc_html( number_format( $shortfall, 0 ) )
                                    . '</div>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            if ( $sub->period_start && $sub->period_end ) {
                                echo esc_html( $sub->period_start . ' → ' . $sub->period_end );
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td><?php echo $sub->paid_at ? esc_html( $sub->paid_at ) : '—'; ?></td>
                        <td>
                            <?php if ( $sub->status === 'pending' ) : ?>
                                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'ofp_mark_subscription_paid' ); ?>
                                    <input type="hidden" name="action" value="ofp_mark_subscription_paid">
                                    <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $sub->id ); ?>">
                                    <button type="submit" class="button button-small button-primary" onclick="return confirm('Manually mark this subscription as PAID? This will grant the client 30 days of access and send them a receipt.');">Mark Paid</button>
                                </form>
                            <?php else : ?>
                                —
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
