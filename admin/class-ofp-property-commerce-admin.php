<?php
/**
 * OFP_Property_Commerce_Admin
 *
 * Adds property-commerce management to the existing standalone WordPress
 * Properties menu. It intentionally does not modify the OFast Pipeline menu.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Commerce_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
    }

    public function register_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        add_submenu_page(
            'edit.php?post_type=ofp_property',
            'Listing Billing',
            'Billing',
            'manage_options',
            'ofp-property-billing',
            [ $this, 'render_billing' ]
        );

        add_submenu_page(
            'edit.php?post_type=ofp_property',
            'Installment Offers',
            'Installment Offers',
            'manage_options',
            'ofp-property-offers',
            [ $this, 'render_offers' ]
        );

        add_submenu_page(
            'edit.php?post_type=ofp_property',
            'Property Purchases',
            'Purchases',
            'manage_options',
            'ofp-property-purchases',
            [ $this, 'render_purchases' ]
        );
    }

    public function render_billing(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $filter_client = absint( $_GET['client_id'] ?? 0 );
        $status_filter = sanitize_key( $_GET['status'] ?? '' );

        $where = [ "s.type = 'listing'" ];
        $args  = [];

        if ( $filter_client ) {
            $where[] = 's.client_id = %d';
            $args[]  = $filter_client;
        }

        if ( $status_filter && in_array( $status_filter, [ 'pending', 'paid', 'underpaid', 'expired', 'cancelled' ], true ) ) {
            $where[] = 's.status = %s';
            $args[]  = $status_filter;
        }

        $where_sql = implode( ' AND ', $where );

        $subscriptions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.*, c.business_name, c.email, c.status AS client_status
                 FROM {$p}ofp_subscriptions s
                 INNER JOIN {$p}ofp_clients c ON c.id = s.client_id
                 WHERE {$where_sql}
                 ORDER BY s.created_at DESC
                 LIMIT 100",
                ...$args
            )
        );

        $clients = OFP_Client::all();

        $total_revenue = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(amount),0) FROM {$p}ofp_subscriptions WHERE type = 'listing' AND status = 'paid'"
        );
        $month_revenue = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(amount),0) FROM {$p}ofp_subscriptions
             WHERE type = 'listing' AND status = 'paid'
             AND MONTH(paid_at) = MONTH(NOW()) AND YEAR(paid_at) = YEAR(NOW())"
        );
        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}ofp_subscriptions WHERE type = 'listing' AND status = 'pending'"
        );
        $active_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}ofp_subscriptions
             WHERE type = 'listing' AND status = 'paid'
             AND (period_end IS NULL OR period_end >= CURDATE())"
        );
        ?>
        <div class="wrap">
            <h1>Listing Billing</h1>
            <p>Property listing subscriptions only. CRM billing remains under <strong>OFast Pipeline → Billing</strong>.</p>

            <div style="display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:16px;margin:20px 0;">
                <div class="postbox" style="padding:16px;"><strong style="font-size:22px;">₦<?php echo esc_html( number_format( $total_revenue, 0 ) ); ?></strong><br><span>Total Listing Revenue</span></div>
                <div class="postbox" style="padding:16px;"><strong style="font-size:22px;">₦<?php echo esc_html( number_format( $month_revenue, 0 ) ); ?></strong><br><span>This Month</span></div>
                <div class="postbox" style="padding:16px;"><strong style="font-size:22px;"><?php echo esc_html( $active_count ); ?></strong><br><span>Active Listing Subscriptions</span></div>
                <div class="postbox" style="padding:16px;"><strong style="font-size:22px;"><?php echo esc_html( $pending_count ); ?></strong><br><span>Awaiting Payment</span></div>
            </div>

            <div style="margin:20px 0;">
                <form method="get">
                    <input type="hidden" name="post_type" value="ofp_property">
                    <input type="hidden" name="page" value="ofp-property-billing">
                    <select name="client_id" onchange="this.form.submit()">
                        <option value="">All Listing Clients</option>
                        <?php foreach ( $clients as $client ) : ?>
                            <option value="<?php echo esc_attr( $client->id ); ?>" <?php selected( $filter_client, $client->id ); ?>><?php echo esc_html( $client->business_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">All Payment Statuses</option>
                        <option value="pending" <?php selected( $status_filter, 'pending' ); ?>>Pending</option>
                        <option value="paid" <?php selected( $status_filter, 'paid' ); ?>>Paid</option>
                        <option value="underpaid" <?php selected( $status_filter, 'underpaid' ); ?>>Underpaid</option>
                        <option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>>Cancelled</option>
                    </select>
                    <?php if ( $filter_client || $status_filter ) : ?>
                        <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-billing' ) ); ?>">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div style="overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;border:1px solid #dcdcde;">
                <table class="widefat striped" style="min-width:1100px;margin:0;">
                    <thead><tr><th>Client</th><th>Plan</th><th>Amount</th><th>Status</th><th>Period</th><th>Payment Ref</th><th>Paid At</th><th>Client Status</th></tr></thead>
                    <tbody>
                    <?php if ( empty( $subscriptions ) ) : ?>
                        <tr><td colspan="8">No listing billing records found.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $subscriptions as $sub ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $sub->business_name ); ?></strong><br><small><?php echo esc_html( $sub->email ); ?></small></td>
                                <td><?php echo esc_html( strtoupper( $sub->plan ?: '—' ) ); ?></td>
                                <td><strong>₦<?php echo esc_html( number_format( (float) $sub->amount, 0 ) ); ?></strong></td>
                                <td><?php echo esc_html( ucfirst( $sub->status ) ); ?></td>
                                <td><?php echo $sub->period_start && $sub->period_end ? esc_html( $sub->period_start . ' → ' . $sub->period_end ) : '—'; ?></td>
                                <td><code><?php echo esc_html( $sub->payment_ref ?: '—' ); ?></code></td>
                                <td><?php echo esc_html( $sub->paid_at ?: '—' ); ?></td>
                                <td><?php echo esc_html( ucfirst( $sub->client_status ?: 'unknown' ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function render_offers(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $created = isset( $_GET['created'] ) && '1' === $_GET['created'];
        $offer_url = isset( $_GET['offer_url'] ) ? rawurldecode( wp_unslash( $_GET['offer_url'] ) ) : '';

        $offers = $wpdb->get_results(
            "SELECT o.*, p.title AS property_title, c.business_name
             FROM {$p}ofp_property_offers o
             LEFT JOIN {$p}ofp_properties p ON p.id = o.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = o.client_id
             ORDER BY o.created_at DESC
             LIMIT 100"
        );
        ?>
        <div class="wrap">
            <h1>Installment Offers</h1>
            <p>Offers created for property buyers. Acceptance and payment setup happen from the secure buyer flow.</p>

            <?php if ( $created && $offer_url ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Offer created.</strong> Send the secure buyer link below.</p>
                    <p>
                        <input type="text" readonly value="<?php echo esc_attr( $offer_url ); ?>" style="width:70%;max-width:720px;">
                        <button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">Copy Link</button>
                    </p>
                </div>
            <?php elseif ( $created ) : ?>
                <div class="notice notice-success is-dismissible"><p>Installment offer created successfully.</p></div>
            <?php endif; ?>

            <p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-create-offer' ) ); ?>">Create Installment Offer</a></p>

            <?php if ( empty( $offers ) ) : ?>
                <div class="notice notice-info"><p>No installment offers yet. Use <strong>Create Installment Offer</strong> above to start one.</p></div>
            <?php else : ?>
                <div style="overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;border:1px solid #dcdcde;">
                    <table class="widefat striped" style="min-width:1250px;margin:0;">
                        <thead><tr><th>ID</th><th>Buyer</th><th>Property</th><th>Owner</th><th>Total</th><th>Plan</th><th>Payment Starts</th><th>First Due Date</th><th>Grace Period</th><th>Offer Expires</th><th>Status</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php foreach ( $offers as $offer ) : ?>
                            <tr>
                                <td>#<?php echo esc_html( $offer->id ); ?></td>
                                <td><strong><?php echo esc_html( $offer->buyer_name ); ?></strong><br><small><?php echo esc_html( $offer->buyer_phone ); ?></small></td>
                                <td><?php echo esc_html( $offer->property_title ?: '—' ); ?></td>
                                <td><?php echo esc_html( $offer->business_name ?: 'Platform' ); ?></td>
                                <td>NGN <?php echo esc_html( number_format( (float) $offer->total_price, 2 ) ); ?></td>
                                <td>Initial: NGN <?php echo esc_html( number_format( (float) $offer->initial_payment, 2 ) ); ?><br><?php echo esc_html( $offer->installment_count ); ?> × NGN <?php echo esc_html( number_format( (float) $offer->installment_amount, 2 ) ); ?></td>
                                <td><?php echo $offer->payment_start_date ? esc_html( wp_date( 'M j, Y', strtotime( $offer->payment_start_date ) ) ) : '—'; ?></td>
                                <td><?php echo $offer->first_due_date ? esc_html( wp_date( 'M j, Y', strtotime( $offer->first_due_date ) ) ) : '—'; ?></td>
                                <td><?php echo esc_html( (int) $offer->grace_period_days ); ?> days</td>
                                <td><?php echo $offer->expires_at ? esc_html( wp_date( 'M j, Y', strtotime( $offer->expires_at ) ) ) : '—'; ?></td>
                                <td><?php echo esc_html( ucfirst( $offer->status ) ); ?></td>
                                <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $offer->created_at ) ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_purchases(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $purchases = $wpdb->get_results(
            "SELECT pu.*, p.title AS property_title, c.business_name
             FROM {$p}ofp_property_purchases pu
             LEFT JOIN {$p}ofp_properties p ON p.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
             ORDER BY pu.created_at DESC
             LIMIT 100"
        );
        ?>
        <div class="wrap">
            <h1>Property Purchases</h1>
            <p>Accepted installment purchases with their current paid amount and balance.</p>
            <?php if ( empty( $purchases ) ) : ?>
                <div class="notice notice-info"><p>No property purchases yet.</p></div>
            <?php else : ?>
                <div style="overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;border:1px solid #dcdcde;">
                    <table class="widefat striped" style="min-width:1200px;margin:0;">
                        <thead><tr><th>ID</th><th>Buyer</th><th>Property</th><th>Owner</th><th>Total</th><th>Paid</th><th>Balance</th><th>Payment Starts</th><th>First Due Date</th><th>Grace Period</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ( $purchases as $purchase ) : ?>
                            <tr>
                                <td>#<?php echo esc_html( $purchase->id ); ?></td>
                                <td><strong><?php echo esc_html( $purchase->buyer_name ); ?></strong><br><small><?php echo esc_html( $purchase->buyer_phone ); ?></small></td>
                                <td><?php echo esc_html( $purchase->property_title ?: '—' ); ?></td>
                                <td><?php echo esc_html( $purchase->business_name ?: 'Platform' ); ?></td>
                                <td>NGN <?php echo esc_html( number_format( (float) $purchase->total_price, 2 ) ); ?></td>
                                <td>NGN <?php echo esc_html( number_format( (float) $purchase->amount_paid, 2 ) ); ?></td>
                                <td><strong>NGN <?php echo esc_html( number_format( (float) $purchase->balance, 2 ) ); ?></strong></td>
                                <td><?php echo $purchase->payment_start_date ? esc_html( wp_date( 'M j, Y', strtotime( $purchase->payment_start_date ) ) ) : '—'; ?></td>
                                <td><?php echo $purchase->first_due_date ? esc_html( wp_date( 'M j, Y', strtotime( $purchase->first_due_date ) ) ) : '—'; ?></td>
                                <td><?php echo esc_html( (int) $purchase->grace_period_days ); ?> days</td>
                                <td><?php echo esc_html( ucfirst( $purchase->status ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
