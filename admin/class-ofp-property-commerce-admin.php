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
            <p>Property listing subscriptions only. CRM billing remains under <strong><?php echo esc_html( OFP_Brand::name() ); ?> → Billing</strong>.</p>

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
                                <td><?php echo esc_html( OFP_Subscription::row_labels( $sub )['plan'] ); ?></td>
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
        if ( class_exists( 'OFP_Property_Commerce' ) ) {
            OFP_Property_Commerce::sync_purchase_totals();
        }

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

        // Dynamic sync of amount_paid & balance from payments and installments
        if ( class_exists( 'OFP_Property_Commerce' ) ) {
            OFP_Property_Commerce::sync_purchase_totals( $purchase_id );
            $purchase = $wpdb->get_row( $wpdb->prepare(
                "SELECT pu.*, p.title AS property_title, c.business_name
                 FROM {$p}ofp_property_purchases pu
                 LEFT JOIN {$p}ofp_properties p ON p.id = pu.property_id
                 LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
                 WHERE pu.id = %d LIMIT 1",
                $purchase_id
            ) );
        }
        
        $installments = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_installments WHERE purchase_id = %d ORDER BY installment_no ASC",
            $purchase_id
        ) );

        $recorded_payments = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_payments WHERE purchase_id = %d ORDER BY created_at DESC",
            $purchase_id
        ) );

        $log_error = isset( $_GET['log_error'] ) ? sanitize_text_field( wp_unslash( $_GET['log_error'] ) ) : '';
        $payment_logged = ! empty( $_GET['payment_logged'] );

        ?>
        <div class="wrap">
            <h1>Purchase Details: #<?php echo esc_html( $purchase->id ); ?></h1>
            <p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-purchases' ) ); ?>">← Back to Purchases</a></p>

            <?php
            $names = preg_split( '/\s+/', trim( (string) $purchase->buyer_name ) );
            $initials = '';
            foreach ( array_slice( $names, 0, 2 ) as $n ) {
                $initials .= mb_strtoupper( mb_substr( $n, 0, 1 ) );
            }
            if ( ! $initials ) {
                $initials = 'BY';
            }
            $detail_styles = [
                'active'    => 'background:#dbeafe; color:#2563eb;',
                'completed' => 'background:#dcfce7; color:#16a34a;',
                'cancelled' => 'background:#fee2e2; color:#ef4444;',
                'defaulted' => 'background:#fee2e2; color:#ef4444;',
                'pending'   => 'background:#fef3c7; color:#d97706;',
            ];
            $detail_style = $detail_styles[ strtolower( $purchase->status ) ] ?? 'background:#f1f5f9; color:#64748b;';
            $pct_paid = (float) $purchase->total_price > 0 ? min( 100, round( ((float) $purchase->amount_paid / (float) $purchase->total_price) * 100 ) ) : 0;
            ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap:20px; margin-bottom:24px;">
                <!-- Buyer Details Card -->
                <div class="postbox" style="padding:22px; margin:0; border-radius:12px; border:1px solid #c3c4c7; box-shadow:0 1px 3px rgba(0,0,0,0.04); display:flex; flex-direction:column; justify-content:space-between; background:#fff;">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; gap:12px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div style="width:44px; height:44px; border-radius:50%; background:linear-gradient(135deg, #3b82f6, #1d4ed8); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:15px; letter-spacing:0.02em; flex-shrink:0; box-shadow:0 2px 6px rgba(59, 130, 246, 0.3);">
                                    <?php echo esc_html( $initials ); ?>
                                </div>
                                <div>
                                    <h3 style="margin:0 0 2px; font-size:17px; font-weight:700; color:#1e293b; letter-spacing:-0.01em;">
                                        <?php echo esc_html( $purchase->buyer_name ); ?>
                                    </h3>
                                    <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px;">
                                        <span>Buyer Details</span>
                                        <span>&bull;</span>
                                        <span style="font-weight:600; color:#0f172a;">Purchase #<?php echo esc_html( $purchase->id ); ?></span>
                                    </div>
                                </div>
                            </div>
                            <span style="font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; text-transform:uppercase; letter-spacing:0.04em; <?php echo esc_attr( $detail_style ); ?>">
                                <?php echo esc_html( ucfirst( $purchase->status ) ); ?>
                            </span>
                        </div>

                        <div style="font-size:13px; color:#475569; margin:0 0 16px; line-height:1.45; padding:9px 12px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                            Purchase details and installment payment schedule for Purchase #<?php echo esc_html( $purchase->id ); ?>.
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div style="padding:10px 12px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0;">
                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; font-weight:600; margin-bottom:3px;">Phone Number</div>
                                <div style="font-size:13px; font-weight:600; color:#0f172a; word-break:break-all;">
                                    <a href="tel:<?php echo esc_attr( $purchase->buyer_phone ); ?>" style="color:#0f172a; text-decoration:none;">
                                        <?php echo esc_html( $purchase->buyer_phone ?: '—' ); ?>
                                    </a>
                                </div>
                            </div>

                            <div style="padding:10px 12px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0;">
                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; font-weight:600; margin-bottom:3px;">Email Address</div>
                                <div style="font-size:13px; font-weight:600; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo esc_attr( $purchase->buyer_email ); ?>">
                                    <?php if ( $purchase->buyer_email ) : ?>
                                        <a href="mailto:<?php echo esc_attr( $purchase->buyer_email ); ?>" style="color:#2563eb; text-decoration:none;">
                                            <?php echo esc_html( $purchase->buyer_email ); ?>
                                        </a>
                                    <?php else : ?>
                                        <span style="color:#64748b;">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:16px; padding-top:12px; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; font-size:12px; color:#64748b;">
                        <span>Enrollment Date</span>
                        <span style="font-weight:600; color:#0f172a;"><?php echo esc_html( wp_date( 'M j, Y g:ia', strtotime( $purchase->created_at ) ) ); ?></span>
                    </div>
                </div>

                <!-- Property & Plan Overview Card -->
                <div class="postbox" style="padding:22px; margin:0; border-radius:12px; border:1px solid #c3c4c7; box-shadow:0 1px 3px rgba(0,0,0,0.04); display:flex; flex-direction:column; justify-content:space-between; background:#fff;">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; gap:12px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div style="width:42px; height:42px; border-radius:10px; background:linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.22)); color:#10b981; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                </div>
                                <div>
                                    <h3 style="margin:0 0 2px; font-size:17px; font-weight:700; color:#1e293b; letter-spacing:-0.01em;">
                                        <?php echo esc_html( $purchase->property_title ?: 'Selected Property' ); ?>
                                    </h3>
                                    <div style="font-size:12px; color:#64748b;">
                                        <?php echo esc_html( $purchase->business_name ?: 'Platform' ); ?> &bull; Payment Plan
                                    </div>
                                </div>
                            </div>
                            <?php if ( (int) $purchase->installment_count === 0 || (float) $purchase->initial_payment >= (float) $purchase->total_price ) : ?>
                                <span style="font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; background:#dbeafe; color:#2563eb; text-transform:uppercase; letter-spacing:0.04em;">
                                    Outright
                                </span>
                            <?php else : ?>
                                <span style="font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; background:#f1f5f9; color:#0f172a; text-transform:uppercase; letter-spacing:0.04em;">
                                    <?php echo (int) $purchase->installment_count; ?> Installments
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Financial Metric Highlights -->
                        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; margin-bottom:14px;">
                            <div style="padding:10px 12px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0;">
                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:#64748b; font-weight:600; margin-bottom:2px;">Total Price</div>
                                <div style="font-size:14px; font-weight:700; color:#0f172a;">₦<?php echo esc_html( number_format( (float) $purchase->total_price ) ); ?></div>
                            </div>

                            <div style="padding:10px 12px; background:rgba(16, 185, 129, 0.08); border-radius:10px; border:1px solid rgba(16, 185, 129, 0.25);">
                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:#059669; font-weight:600; margin-bottom:2px;">Paid</div>
                                <div style="font-size:14px; font-weight:700; color:#059669;">₦<?php echo esc_html( number_format( (float) $purchase->amount_paid ) ); ?></div>
                            </div>

                            <div style="padding:10px 12px; background:<?php echo (float)$purchase->balance > 0 ? 'rgba(239, 68, 68, 0.06)' : '#f8fafc'; ?>; border-radius:10px; border:1px solid <?php echo (float)$purchase->balance > 0 ? 'rgba(239, 68, 68, 0.25)' : '#e2e8f0'; ?>;">
                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:<?php echo (float)$purchase->balance > 0 ? '#dc2626' : '#64748b'; ?>; font-weight:600; margin-bottom:2px;">Balance</div>
                                <div style="font-size:14px; font-weight:700; color:<?php echo (float)$purchase->balance > 0 ? '#dc2626' : '#0f172a'; ?>;">₦<?php echo esc_html( number_format( (float) $purchase->balance ) ); ?></div>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div style="margin-bottom:8px;">
                            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:6px;">
                                <span style="color:#64748b; font-weight:500;">Payment Progress</span>
                                <span style="font-weight:700; color:#0f172a;"><?php echo (int) $pct_paid; ?>% Paid</span>
                            </div>
                            <div style="height:7px; background:#e2e8f0; border-radius:99px; overflow:hidden;">
                                <div style="height:100%; width:<?php echo (int) $pct_paid; ?>%; background:linear-gradient(90deg, #10b981, #059669); border-radius:99px; transition:width 0.4s ease;"></div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:14px; padding-top:12px; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; font-size:12px; color:#64748b;">
                        <span>Structure:</span>
                        <span style="font-weight:600; color:#0f172a;">
                            <?php if ( (int) $purchase->installment_count === 0 || (float) $purchase->initial_payment >= (float) $purchase->total_price ) : ?>
                                100% Full Payment (Outright)
                            <?php else : ?>
                                Initial ₦<?php echo esc_html( number_format( (float) $purchase->initial_payment ) ); ?> &bull; <?php echo (int) $purchase->installment_count; ?> &times; ₦<?php echo esc_html( number_format( (float) $purchase->installment_amount ) ); ?> (<?php echo esc_html( ucfirst( $purchase->frequency ) ); ?>)
                            <?php endif; ?>
                        </span>
                    </div>
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

            <h2>Payment History &amp; Receipts</h2>
            <?php if ( empty( $recorded_payments ) ) : ?>
                <div class="notice notice-info"><p>No payments recorded yet for this purchase.</p></div>
            <?php else : ?>
                <div style="overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;border:1px solid #dcdcde;margin-bottom:20px;">
                    <table class="widefat striped" style="margin:0;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Transaction ID</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $recorded_payments as $idx => $pmt ) : 
                                $raw_ref     = (string) ( $pmt->gateway_reference ?: ( $pmt->payer_reference ?: '' ) );
                                $display_ref = class_exists( 'OFP_Property_Receipt_Generator' ) 
                                    ? OFP_Property_Receipt_Generator::format_display_reference( $raw_ref, '—' ) 
                                    : ( $raw_ref ?: '—' );
                            ?>
                                <tr>
                                    <td><?php echo (int) ( count( $recorded_payments ) - $idx ); ?></td>
                                    <td><strong>NGN <?php echo esc_html( number_format( (float) $pmt->amount, 2 ) ); ?></strong></td>
                                    <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $pmt->payment_method ) ) ); ?></td>
                                    <td><code title="<?php echo esc_attr( $raw_ref ); ?>"><?php echo esc_html( $display_ref ); ?></code></td>
                                    <td>
                                        <?php if ( in_array( $pmt->status, [ 'successful', 'verified' ], true ) ) : ?>
                                            <span style="color:#16a34a;font-weight:600;background:#dcfce7;padding:3px 8px;border-radius:12px;font-size:11.5px;"><?php echo esc_html( ucfirst( $pmt->status ) ); ?></span>
                                        <?php elseif ( $pmt->status === 'pending_verification' ) : ?>
                                            <span style="color:#d97706;font-weight:600;background:#fef3c7;padding:3px 8px;border-radius:12px;font-size:11.5px;">Pending</span>
                                        <?php else : ?>
                                            <span style="color:#ef4444;font-weight:600;background:#fee2e2;padding:3px 8px;border-radius:12px;font-size:11.5px;"><?php echo esc_html( ucfirst( $pmt->status ) ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( wp_date( 'M j, Y g:ia', strtotime( $pmt->created_at ) ) ); ?></td>
                                    <td>
                                        <?php if ( ! empty( $pmt->receipt_path ) && class_exists( 'OFP_Property_Receipt_Viewer' ) ) : ?>
                                            <a href="<?php echo esc_url( OFP_Property_Receipt_Viewer::get_receipt_url( (int) $pmt->id, 'purchase' ) ); ?>" onclick="event.preventDefault(); ofpOpenProofModal(this.href);" class="button button-small">
                                                View Receipt
                                            </a>
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

            <!-- PROOF LIGHTBOX MODAL -->
            <div id="ofp-modal-proof" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; width:100vw; height:100vh; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); z-index:999999; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;" onclick="if(event.target===this) ofpClosePaymentModal('ofp-modal-proof');">
                <div style="background:#ffffff; color:#0f172a; width:100%; max-width:760px; max-height:90vh; border-radius:14px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); border:1px solid #e2e8f0; overflow:hidden; box-sizing:border-box; display:flex; flex-direction:column;">
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e2e8f0; background:#ffffff;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <div style="width:36px; height:36px; border-radius:50%; background:#e0e7ff; color:#4338ca; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </div>
                            <div>
                                <h3 style="margin:0; font-size:16px; font-weight:700;">Payment Proof</h3>
                                <div style="font-size:12px; color:#64748b;">Uploaded receipt / transfer proof preview</div>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap:14px;">
                            <a id="ofp-proof-external-link" href="#" target="_blank" rel="noopener" style="font-size:12px; font-weight:600; color:#2563eb; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                <span>Open original</span> &nearr;
                            </a>
                            <button type="button" onclick="ofpClosePaymentModal('ofp-modal-proof')" style="background:none; border:none; font-size:24px; cursor:pointer; color:#94a3b8; line-height:1; padding:0;">&times;</button>
                        </div>
                    </div>
                    <div style="padding:0; flex:1; min-height:480px; background:#0f172a; position:relative; overflow:hidden;">
                        <iframe id="ofp-proof-iframe" src="about:blank" style="width:100%; height:100%; min-height:480px; border:none; display:block;"></iframe>
                    </div>
                    <div style="display:flex; justify-content:flex-end; padding:14px 20px; background:#f8fafc; border-top:1px solid #e2e8f0;">
                        <button type="button" onclick="ofpClosePaymentModal('ofp-modal-proof')" class="button">Close</button>
                    </div>
                </div>
            </div>

            <script>
            function ofpOpenProofModal(url) {
                var iframe = document.getElementById('ofp-proof-iframe');
                var ext = document.getElementById('ofp-proof-external-link');
                if (iframe) iframe.src = url;
                if (ext) ext.href = url;
                var m = document.getElementById('ofp-modal-proof');
                if (m) m.style.display = 'flex';
            }
            function ofpClosePaymentModal(id) {
                var m = document.getElementById(id);
                if (m) m.style.display = 'none';
                if (id === 'ofp-modal-proof') {
                    var iframe = document.getElementById('ofp-proof-iframe');
                    if (iframe) iframe.src = 'about:blank';
                }
            }
            </script>

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
