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
        add_action( 'admin_post_ofp_admin_log_manual_payment', [ $this, 'handle_log_manual_payment' ] );
        add_action( 'admin_post_ofp_backfill_virtual_accounts', [ $this, 'handle_backfill_virtual_accounts' ] );
    }

    /**
     * One-time (repeatable/safe) tool: creates a dedicated virtual account
     * for every existing, non-completed purchase that doesn't have one yet.
     * Needed because VA creation only auto-fires for purchases created after
     * this feature shipped — older purchases wouldn't otherwise get one.
     */
    public function handle_backfill_virtual_accounts(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Access denied.', 'ofast-pipeline' ) );
        check_admin_referer( 'ofp_backfill_virtual_accounts' );

        global $wpdb;
        $p = $wpdb->prefix;
        $ids = $wpdb->get_col( "SELECT id FROM {$p}ofp_property_purchases WHERE (va_customer_code IS NULL OR va_customer_code = '') AND status != 'completed'" );

        $created = 0;
        $skipped = 0;
        foreach ( $ids as $id ) {
            $va = OFP_Property_Commerce::ensure_virtual_account( (int) $id );
            if ( $va ) $created++; else $skipped++;
        }

        wp_safe_redirect( add_query_arg( [ 'va_backfill_created' => $created, 'va_backfill_skipped' => $skipped ], admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-purchases' ) ) );
        exit;
    }

    /**
     * Admin-side "Log Manual Payment": records money the admin already knows
     * was received (cash, direct bank transfer, etc), receipt required,
     * applied to the schedule/balance immediately, buyer notified.
     */
    public function handle_log_manual_payment(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Access denied.', 'ofast-pipeline' ) );
        $purchase_id = absint( $_POST['log_purchase_id'] ?? 0 );
        check_admin_referer( 'ofp_admin_log_manual_payment_' . $purchase_id );

        global $wpdb;
        $p = $wpdb->prefix;
        $redirect_back = admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-purchases&id=' . $purchase_id );

        $purchase = $wpdb->get_row( $wpdb->prepare( "SELECT id, buyer_name FROM {$p}ofp_property_purchases WHERE id = %d LIMIT 1", $purchase_id ) );
        $amount = max( 0.0, (float) ( $_POST['log_amount'] ?? 0 ) );
        $reference = sanitize_text_field( wp_unslash( $_POST['log_reference'] ?? '' ) );
        $note = sanitize_textarea_field( wp_unslash( $_POST['log_note'] ?? '' ) );

        if ( ! $purchase ) {
            wp_safe_redirect( add_query_arg( 'log_error', rawurlencode( 'Purchase not found.' ), $redirect_back ) );
            exit;
        }
        if ( $amount <= 0 ) {
            wp_safe_redirect( add_query_arg( 'log_error', rawurlencode( 'Enter a valid payment amount.' ), $redirect_back ) );
            exit;
        }
        if ( empty( $_FILES['log_receipt']['name'] ) || ! empty( $_FILES['log_receipt']['error'] ) ) {
            wp_safe_redirect( add_query_arg( 'log_error', rawurlencode( 'A payment receipt is required.' ), $redirect_back ) );
            exit;
        }

        $receipt = OFP_Property_Manual_Payment::store_receipt( $_FILES['log_receipt'] );
        if ( is_wp_error( $receipt ) ) {
            wp_safe_redirect( add_query_arg( 'log_error', rawurlencode( $receipt->get_error_message() ), $redirect_back ) );
            exit;
        }

        $payment_id = OFP_Property_Payment_Record::create([
            'purchase_id'    => $purchase_id,
            'payment_method' => 'manual',
            'amount'         => $amount,
            'status'         => 'successful',
            'payer_name'     => $purchase->buyer_name,
            'payer_reference' => $reference,
            'note'           => $note,
        ]);

        if ( is_wp_error( $payment_id ) ) {
            OFP_Property_Manual_Payment::delete_receipt( $receipt['path'] );
            wp_safe_redirect( add_query_arg( 'log_error', rawurlencode( $payment_id->get_error_message() ), $redirect_back ) );
            exit;
        }

        $wpdb->update(
            "{$p}ofp_property_payments",
            [ 'receipt_path' => $receipt['path'], 'receipt_mime' => $receipt['mime'], 'receipt_size' => $receipt['size'], 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => (int) $payment_id ]
        );
        OFP_Property_Payment_Record::success( (int) $payment_id, get_current_user_id() );

        wp_safe_redirect( add_query_arg( 'payment_logged', 1, $redirect_back ) );
        exit;
    }

    public function register_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        add_submenu_page(
            'edit.php?post_type=ofp_property',
            'Property Sales',
            'Sales',
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

    public function render_purchases(): void {
        if ( ! empty( $_GET['id'] ) ) {
            $this->render_purchase_details();
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $created = isset( $_GET['created'] ) && '1' === $_GET['created'];
        $resent  = isset( $_GET['resent'] ) && '1' === $_GET['resent'];
        $offer_url = isset( $_GET['offer_url'] ) ? rawurldecode( wp_unslash( $_GET['offer_url'] ) ) : '';

        $purchases = $wpdb->get_results(
            "SELECT pu.*, p.title AS property_title, c.business_name, o.expires_at AS offer_expires,
             (SELECT MIN(due_date) FROM {$p}ofp_property_installments WHERE purchase_id = pu.id AND status = 'scheduled') AS next_due_date
             FROM {$p}ofp_property_purchases pu
             LEFT JOIN {$p}ofp_properties p ON p.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
             LEFT JOIN {$p}ofp_property_offers o ON o.id = pu.offer_id
             ORDER BY pu.created_at DESC
             LIMIT 100"
        );

        // Pending/declined/expired offers only — an accepted offer already
        // has its own row above as a purchase, so it's excluded here to
        // avoid showing the same deal twice.
        $offers = $wpdb->get_results(
            "SELECT o.*, p.title AS property_title, c.business_name
             FROM {$p}ofp_property_offers o
             LEFT JOIN {$p}ofp_properties p ON p.id = o.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = o.client_id
             WHERE o.status != 'accepted'
             ORDER BY o.created_at DESC
             LIMIT 100"
        );
        ?>
        <h2 class="nav-tab-wrapper">
            <a href="?post_type=ofp_property&page=ofp-property-purchases" class="nav-tab nav-tab-active">Sales Table</a>
            <a href="?post_type=ofp_property&page=ofp-property-add-purchase" class="nav-tab">Add Purchase</a>
            <a href="?post_type=ofp_property&page=ofp-property-create-offer" class="nav-tab">Create Offer</a>
        </h2>
        <div class="wrap">
            <h1>Property Sales</h1>
            <p>Every buyer deal in one place — pending offers awaiting a decision, and active or completed purchases with their paid amount and balance.</p>

            <?php if ( isset( $_GET['va_backfill_created'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Virtual accounts created: <?php echo (int) $_GET['va_backfill_created']; ?>. Skipped (no email or gateway not configured): <?php echo (int) $_GET['va_backfill_skipped']; ?>.</p></div>
            <?php endif; ?>
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
            <?php elseif ( $resent ) : ?>
                <div class="notice notice-success is-dismissible"><p>Installment offer email resent successfully.</p></div>
            <?php endif; ?>

            <p>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-add-purchase' ) ); ?>">Add Purchase</a>
                <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-create-offer' ) ); ?>">Create Offer</a>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                    <?php wp_nonce_field( 'ofp_backfill_virtual_accounts' ); ?>
                    <input type="hidden" name="action" value="ofp_backfill_virtual_accounts">
                    <button type="submit" class="button" onclick="return confirm('Create a bank transfer account for every existing purchase that doesn\'t have one yet?');">Backfill Virtual Accounts</button>
                </form>
            </p>

            <?php if ( empty( $purchases ) && empty( $offers ) ) : ?>
                <div class="notice notice-info"><p>No sales yet. Use <strong>Add Purchase</strong> or <strong>Create Offer</strong> above to start one.</p></div>
            <?php else : ?>
                <div style="overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;border:1px solid #dcdcde;">
                    <table class="widefat striped" style="min-width:1300px;margin:0;">
                        <thead><tr><th>ID</th><th>Buyer</th><th>Property</th><th>Owner</th><th>Total</th><th>Paid / Plan</th><th>Balance</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ( $purchases as $purchase ) :
                            $status_styles = [
                                'active'    => 'background:#dbeafe;color:#2563eb;',
                                'completed' => 'background:#dcfce7;color:#16a34a;',
                                'defaulted' => 'background:#fee2e2;color:#ef4444;',
                            ];
                            $status_style = $status_styles[ $purchase->status ] ?? 'background:rgba(128,128,128,0.1);color:#666;';
                        ?>
                            <tr>
                                <td>#<?php echo esc_html( $purchase->id ); ?></td>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-purchases&id=' . $purchase->id ) ); ?>">
                                            <?php echo esc_html( $purchase->buyer_name ); ?>
                                        </a>
                                    </strong>
                                    <br><small><?php echo esc_html( $purchase->buyer_phone ); ?></small>
                                </td>
                                <td><?php echo esc_html( $purchase->property_title ?: '—' ); ?></td>
                                <td><?php echo esc_html( $purchase->business_name ?: 'Platform' ); ?></td>
                                <td>NGN <?php echo esc_html( number_format( (float) $purchase->total_price, 2 ) ); ?></td>
                                <td>NGN <?php echo esc_html( number_format( (float) $purchase->amount_paid, 2 ) ); ?></td>
                                <td><strong>NGN <?php echo esc_html( number_format( (float) $purchase->balance, 2 ) ); ?></strong></td>
                                <td><span style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:100px;<?php echo esc_attr( $status_style ); ?>"><?php echo esc_html( ucfirst( $purchase->status ) ); ?></span></td>
                                <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $purchase->created_at ) ) ); ?></td>
                                <td><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-purchases&id=' . $purchase->id ) ); ?>" class="button button-small">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ( $offers as $offer ) :
                            $offer_status_styles = [
                                'pending'  => 'background:#fef3c7;color:#d97706;',
                                'declined' => 'background:#fee2e2;color:#ef4444;',
                                'expired'  => 'background:rgba(128,128,128,0.15);color:#666;',
                            ];
                            $offer_style = $offer_status_styles[ $offer->status ] ?? 'background:rgba(128,128,128,0.1);color:#666;';
                        ?>
                            <tr>
                                <td>#<?php echo esc_html( $offer->id ); ?></td>
                                <td><strong><?php echo esc_html( $offer->buyer_name ); ?></strong><br><small><?php echo esc_html( $offer->buyer_phone ); ?></small></td>
                                <td><?php echo esc_html( $offer->property_title ?: '—' ); ?></td>
                                <td><?php echo esc_html( $offer->business_name ?: 'Platform' ); ?></td>
                                <td>NGN <?php echo esc_html( number_format( (float) $offer->total_price, 2 ) ); ?></td>
                                <td>Initial: NGN <?php echo esc_html( number_format( (float) $offer->initial_payment, 2 ) ); ?><br><?php echo esc_html( $offer->installment_count ); ?> × NGN <?php echo esc_html( number_format( (float) $offer->installment_amount, 2 ) ); ?></td>
                                <td>—</td>
                                <td><span style="font-size:12px;font-weight:600;padding:4px 10px;border-radius:100px;<?php echo esc_attr( $offer_style ); ?>"><?php echo $offer->status === 'pending' ? 'Offer sent, waiting' : esc_html( ucfirst( $offer->status ) ); ?></span></td>
                                <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $offer->created_at ) ) ); ?></td>
                                <td>
                                    <?php if ( ! empty( $offer->offer_token ) && $offer->status === 'pending' ) : ?>
                                        <input type="hidden" value="<?php echo esc_attr( add_query_arg( 'offer', rawurlencode( $offer->offer_token ), home_url( '/property-offer' ) ) ); ?>">
                                        <button type="button" class="button button-small" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);alert('Link copied!')" style="margin-bottom:4px;">Copy Link</button>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_resend_offer&offer_id=' . $offer->id ), 'ofp_resend_offer' ) ); ?>" class="button button-small">Resend</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_purchase_details(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $purchase_id = absint( $_GET['id'] ?? 0 );

        if ( ! $purchase_id ) {
            wp_die( 'Invalid purchase ID.' );
        }

        $purchase = $wpdb->get_row( $wpdb->prepare(
            "SELECT pu.*, p.title AS property_title, c.business_name
             FROM {$p}ofp_property_purchases pu
             LEFT JOIN {$p}ofp_properties p ON p.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
             WHERE pu.id = %d LIMIT 1",
            $purchase_id
        ) );

        if ( ! $purchase ) {
            wp_die( 'Purchase not found.' );
        }
        
        $installments = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_installments WHERE purchase_id = %d ORDER BY installment_no ASC",
            $purchase_id
        ) );

        $log_error = isset( $_GET['log_error'] ) ? sanitize_text_field( wp_unslash( $_GET['log_error'] ) ) : '';
        $payment_logged = ! empty( $_GET['payment_logged'] );

        ?>
        <div class="wrap">
            <h1>Purchase Details: #<?php echo esc_html( $purchase->id ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-purchases' ) ); ?>">← Back to Purchases</a></p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
                <div class="postbox" style="padding:16px;">
                    <h2 style="margin-top:0;">Buyer Details</h2>
                    <p><strong>Name:</strong> <?php echo esc_html( $purchase->buyer_name ); ?></p>
                    <p><strong>Phone:</strong> <?php echo esc_html( $purchase->buyer_phone ); ?></p>
                    <p><strong>Email:</strong> <?php echo esc_html( $purchase->buyer_email ?: '—' ); ?></p>
                    <p><strong>Status:</strong> <?php echo esc_html( ucfirst( $purchase->status ) ); ?></p>
                    <p><strong>Date:</strong> <?php echo esc_html( wp_date( 'M j, Y', strtotime( $purchase->created_at ) ) ); ?></p>
                </div>
                
                <div class="postbox" style="padding:16px;">
                    <h2 style="margin-top:0;">Property & Plan</h2>
                    <p><strong>Property:</strong> <?php echo esc_html( $purchase->property_title ?: '—' ); ?> (<?php echo esc_html( $purchase->business_name ?: 'Platform' ); ?>)</p>
                    <p><strong>Total Price:</strong> NGN <?php echo esc_html( number_format( (float) $purchase->total_price, 2 ) ); ?></p>
                    <p><strong>Amount Paid:</strong> NGN <?php echo esc_html( number_format( (float) $purchase->amount_paid, 2 ) ); ?></p>
                    <p><strong>Balance:</strong> NGN <?php echo esc_html( number_format( (float) $purchase->balance, 2 ) ); ?></p>
                    <p><strong>Initial Payment:</strong> NGN <?php echo esc_html( number_format( (float) $purchase->initial_payment, 2 ) ); ?></p>
                    <p><strong>Plan:</strong> <?php echo esc_html( $purchase->installment_count ); ?> × NGN <?php echo esc_html( number_format( (float) $purchase->installment_amount, 2 ) ); ?> (<?php echo esc_html( ucfirst( $purchase->frequency ) ); ?>)</p>
                </div>
            </div>

            <h2>Installment Schedule</h2>
            <?php if ( empty( $installments ) ) : ?>
                <div class="notice notice-info"><p>No installments found.</p></div>
            <?php else : ?>
                <div style="overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;border:1px solid #dcdcde;">
                    <table class="widefat striped" style="margin:0;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Paid At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $installments as $ins ) : ?>
                                <tr>
                                    <td><?php echo $ins->installment_no == 1 && (float) $purchase->initial_payment > 0 ? '—' : esc_html( $ins->installment_no ); ?></td>
                                    <td><?php echo $ins->installment_no == 1 && (float) $purchase->initial_payment > 0 ? 'Initial Payment' : 'Installment'; ?></td>
                                    <td><strong>NGN <?php echo esc_html( number_format( (float) $ins->amount_due, 2 ) ); ?></strong></td>
                                    <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $ins->due_date ) ) ); ?></td>
                                    <td>
                                        <?php if ( $ins->status === 'paid' ) : ?>
                                            <span style="color:green;font-weight:bold;">Paid</span>
                                        <?php elseif ( $ins->status === 'overdue' ) : ?>
                                            <span style="color:red;font-weight:bold;">Overdue</span>
                                        <?php else : ?>
                                            <?php echo esc_html( ucfirst( str_replace( '_', ' ', $ins->status ) ) ); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $ins->paid_at ? esc_html( wp_date( 'M j, Y', strtotime( $ins->paid_at ) ) ) : '—'; ?></td>
                                    <td>
                                        <?php if ( $ins->status !== 'paid' && class_exists( 'OFP_Property_Checkout' ) ) : ?>
                                            <input type="hidden" value="<?php echo esc_attr( OFP_Property_Checkout::payment_link( (int) $purchase->id ) ); ?>">
                                            <button type="button" class="button button-small" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);alert('Payment Link copied!')">Copy Payment Link</button>
                                        <?php else : ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ( 'completed' !== $purchase->status ) : ?>
            <div class="postbox" style="padding:16px;margin-top:20px;">
                <h2 style="margin-top:0;">Log Manual Payment</h2>
                <p class="description">Record money already received from this buyer directly (cash, bank transfer to your own account, etc). A receipt is required, and this marks the payment as received immediately.</p>
                <?php if ( $payment_logged ) : ?>
                    <div class="notice notice-success inline"><p>Payment logged and applied successfully.</p></div>
                <?php endif; ?>
                <?php if ( $log_error ) : ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html( $log_error ); ?></p></div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top:12px;">
                    <?php wp_nonce_field( 'ofp_admin_log_manual_payment_' . $purchase->id ); ?>
                    <input type="hidden" name="action" value="ofp_admin_log_manual_payment">
                    <input type="hidden" name="log_purchase_id" value="<?php echo esc_attr( $purchase->id ); ?>">
                    <table class="form-table" role="presentation">
                        <tr><th><label for="log_amount">Amount received</label></th><td><input type="number" step="0.01" min="0.01" max="<?php echo esc_attr( $purchase->balance ); ?>" id="log_amount" name="log_amount" required></td></tr>
                        <tr><th><label for="log_reference">Reference</label></th><td><input class="regular-text" id="log_reference" name="log_reference"><p class="description">Optional. Bank transfer reference, etc.</p></td></tr>
                        <tr><th><label for="log_receipt">Receipt</label></th><td><input type="file" id="log_receipt" name="log_receipt" accept="image/jpeg,image/png,application/pdf" required><p class="description">Required. JPG, PNG or PDF, max 5 MB.</p></td></tr>
                        <tr><th><label for="log_note">Note</label></th><td><input class="regular-text" id="log_note" name="log_note" placeholder="e.g. Installment 3, paid in cash"></td></tr>
                    </table>
                    <?php submit_button( 'Log Payment' ); ?>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
