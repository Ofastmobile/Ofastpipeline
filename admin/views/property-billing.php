<?php
/**
 * Admin View: Property Listing Billing
 * 
 * Displays subscription billing for property listings only (type = 'listing').
 * Separate from CRM billing to allow independent management of property and CRM revenue.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

include OFP_PATH . 'admin/views/partials/header.php';
?>

<h2>Property Listing Billing & Subscriptions</h2>
<p>Subscription billing for property listing memberships. CRM billing is managed separately under <strong>Billing → CRM Billing</strong>.</p>

<div class="ofp-stats-grid">
    <div class="ofp-stat-card"><span class="ofp-stat-number">₦<?php echo esc_html( number_format( $total_revenue, 0 ) ); ?></span><span class="ofp-stat-label">Listing Revenue</span></div>
    <div class="ofp-stat-card"><span class="ofp-stat-number ofp-accent">₦<?php echo esc_html( number_format( $month_revenue, 0 ) ); ?></span><span class="ofp-stat-label">This Month</span></div>
    <div class="ofp-stat-card"><span class="ofp-stat-number"><?php echo esc_html( $pending_count ); ?></span><span class="ofp-stat-label">Pending Payments</span></div>
    <div class="ofp-stat-card"><span class="ofp-stat-number"><?php echo esc_html( $active_count ); ?></span><span class="ofp-stat-label">Active Subscriptions</span></div>
</div>

<div class="ofp-filters">
    <form method="GET" action="" class="ofp-filter-form">
        <input type="hidden" name="post_type" value="ofp_property">
        <input type="hidden" name="page" value="ofp-property-billing">
        <select name="client_id" onchange="this.form.submit()">
            <option value="">All Listing Clients</option>
            <?php foreach ( $clients as $c ) : ?>
                <option value="<?php echo esc_attr( $c->id ); ?>" <?php selected( $filter_client, $c->id ); ?>><?php echo esc_html( $c->business_name ); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <option value="pending" <?php selected( $status_filter, 'pending' ); ?>>Pending</option>
            <option value="paid" <?php selected( $status_filter, 'paid' ); ?>>Paid</option>
            <option value="underpaid" <?php selected( $status_filter, 'underpaid' ); ?>>Underpaid</option>
            <option value="expired" <?php selected( $status_filter, 'expired' ); ?>>Expired</option>
            <option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>>Cancelled</option>
        </select>
        <?php if ( $filter_client || $status_filter ) : ?>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-billing' ) ); ?>" class="button">Clear Filters</a>
        <?php endif; ?>
    </form>
</div>

<div class="ofp-section">
    <?php if ( empty( $subscriptions ) ) : ?>
        <p>No property listing payment records found.</p>
    <?php else : ?>
        <div style="overflow-x:auto;">
            <table class="widefat ofp-table" style="min-width:1200px;">
                <thead><tr>
                    <th>Client</th>
                    <th>Plan</th>
                    <th>Amount (NGN)</th>
                    <th>Status</th>
                    <th>Period</th>
                    <th>Paid At</th>
                    <th>Client Status</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $subscriptions as $sub ) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $sub->business_name ); ?></strong>
                            <br>
                            <small><?php echo esc_html( $sub->email ); ?></small>
                        </td>
                        <td><?php echo esc_html( strtoupper( $sub->plan ?: 'STANDARD' ) ); ?></td>
                        <td><strong>₦<?php echo esc_html( number_format( (float) $sub->amount, 0 ) ); ?></strong></td>
                        <td>
                            <?php
                            $status_class = match ( $sub->status ) {
                                'paid'      => 'ofp-badge-green',
                                'underpaid' => 'ofp-badge-red',
                                'expired'   => 'ofp-badge-gray',
                                'cancelled' => 'ofp-badge-gray',
                                default     => 'ofp-badge-yellow',
                            };
                            $label = match ( $sub->status ) {
                                'pending'   => 'Awaiting First Payment',
                                'underpaid' => 'Underpaid',
                                'expired'   => 'Expired',
                                'cancelled' => 'Cancelled',
                                'paid'      => 'Paid',
                                default     => ucfirst( $sub->status ),
                            };
                            echo '<span class="ofp-badge ' . esc_attr( $status_class ) . '">' . esc_html( $label ) . '</span>';
                            if ( $sub->status === 'underpaid' && ! empty( $sub->expected_amount ) ) {
                                $shortfall = max( 0, (float) $sub->expected_amount - (float) $sub->amount );
                                echo '<div style="font-size:11px;color:#dc2626;margin-top:4px;">Expected ₦' . esc_html( number_format( (float) $sub->expected_amount, 0 ) ) . '<br>Short ₦' . esc_html( number_format( $shortfall, 0 ) ) . '</div>';
                            }
                            ?>
                        </td>
                        <td><?php echo $sub->period_start && $sub->period_end ? esc_html( $sub->period_start . ' → ' . $sub->period_end ) : '—'; ?></td>
                        <td><?php echo esc_html( $sub->paid_at ?: '—' ); ?></td>
                        <td>
                            <?php
                            $client_status_class = $sub->client_status === 'active' ? 'ofp-badge-green' : 'ofp-badge-red';
                            echo '<span class="ofp-badge ' . esc_attr( $client_status_class ) . '">' . esc_html( ucfirst( $sub->client_status ?: 'unknown' ) ) . '</span>';
                            ?>
                        </td>
                        <td>
                            <?php if ( $sub->status === 'pending' ) : ?>
                                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'ofp_mark_subscription_paid' ); ?>
                                    <input type="hidden" name="action" value="ofp_mark_subscription_paid">
                                    <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $sub->id ); ?>">
                                    <button type="submit" class="button button-small button-primary" onclick="return confirm('Manually mark this listing subscription as PAID?');">Mark Paid</button>
                                </form>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="ofp-pagination">
                <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>" class="button button-small <?php echo $i === $current_page ? 'button-primary' : ''; ?>"><?php echo esc_html( $i ); ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include OFP_PATH . 'admin/views/partials/footer.php'; ?>
