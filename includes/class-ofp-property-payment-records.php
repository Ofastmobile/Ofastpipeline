<?php
/**
 * Canonical property payment records UI.
 * One admin page and one client page; pending manual payments are verified
 * from the same records table rather than a separate verification screen.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Payment_Records {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ], 99 );
        add_action( 'template_redirect', [ __CLASS__, 'client_page' ], 1 );
        add_action( 'template_redirect', [ __CLASS__, 'redirect_legacy_verification' ], 1 );
        add_action( 'admin_post_ofp_property_payment_verify', [ __CLASS__, 'admin_verify' ] );
        add_action( 'admin_post_ofp_property_payment_reject', [ __CLASS__, 'admin_reject' ] );
        add_action( 'admin_post_ofp_client_payment_verify', [ __CLASS__, 'client_verify' ] );
        add_action( 'admin_post_ofp_client_payment_reject', [ __CLASS__, 'client_reject' ] );

        // The manual-payment engine still owns submission, but its old
        // verification UI/menu is no longer exposed.
        remove_action( 'admin_menu', [ 'OFP_Property_Manual_Payment', 'register_admin_menu' ] );
        remove_action( 'wp_footer', [ 'OFP_Property_Manual_Payment', 'inject_client_verification_nav' ], 998 );
    }

    public static function admin_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        add_submenu_page(
            'edit.php?post_type=ofp_property',
            'Property Payments',
            'Payments',
            'manage_options',
            'ofp-property-payments',
            [ __CLASS__, 'render_admin' ]
        );
    }

    private static function get_rows( int $client_id = 0 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $sql = "SELECT py.*, pu.property_id, pu.client_id, pu.buyer_name, pu.buyer_phone,
                       pu.total_price, pu.amount_paid AS purchase_paid, pu.balance AS purchase_balance,
                       pr.title AS property_title, c.business_name
                FROM {$p}ofp_property_payments py
                INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
                LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
                LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id";

        if ( $client_id > 0 ) {
            $sql .= $wpdb->prepare( ' WHERE pu.client_id = %d', $client_id );
        }

        $sql .= ' ORDER BY py.created_at DESC, py.id DESC LIMIT 250';
        return $wpdb->get_results( $sql ) ?: [];
    }

    public static function render_admin(): void {
        $rows = self::get_rows();
        ?>
        <div class="wrap">
            <h1>Property Payments</h1>
            <p>Every property payment record. Pending manual payments can be verified or rejected here.</p>
            <div style="overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;">
                <table class="widefat striped" style="min-width:1500px;">
                    <thead><tr>
                        <th>ID</th><th>Buyer</th><th>Property</th><th>Owner</th><th>Purchase</th>
                        <th>Amount</th><th>Method</th><th>Gateway</th><th>Reference</th><th>Status</th>
                        <th>Paid</th><th>Balance</th><th>Receipt</th><th>Action</th><th>Created</th>
                    </tr></thead>
                    <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="15">No property payment records yet.</td></tr>
                    <?php else : foreach ( $rows as $row ) : ?>
                        <tr>
                            <td>#<?php echo esc_html( $row->id ); ?></td>
                            <td><strong><?php echo esc_html( $row->buyer_name ); ?></strong><br><small><?php echo esc_html( $row->buyer_phone ); ?></small></td>
                            <td><?php echo esc_html( $row->property_title ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row->business_name ?: 'Platform' ); ?></td>
                            <td>#<?php echo esc_html( $row->purchase_id ); ?></td>
                            <td><strong>NGN <?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></strong></td>
                            <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->payment_method ) ) ); ?></td>
                            <td><?php echo esc_html( $row->gateway ?: '—' ); ?></td>
                            <td><code><?php echo esc_html( $row->gateway_reference ?: ( $row->payer_reference ?: '—' ) ); ?></code></td>
                            <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?></td>
                            <td>NGN <?php echo esc_html( number_format( (float) $row->purchase_paid, 2 ) ); ?></td>
                            <td>NGN <?php echo esc_html( number_format( (float) $row->purchase_balance, 2 ) ); ?></td>
                            <td><?php echo $row->receipt_path ? 'Available' : '—'; ?></td>
                            <td>
                                <?php if ( 'pending_verification' === $row->status ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                        <?php wp_nonce_field( 'ofp_property_payment_verify_' . $row->id ); ?>
                                        <input type="hidden" name="action" value="ofp_property_payment_verify">
                                        <input type="hidden" name="payment_id" value="<?php echo esc_attr( $row->id ); ?>">
                                        <button class="button button-primary" type="submit">Verify</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                        <?php wp_nonce_field( 'ofp_property_payment_reject_' . $row->id ); ?>
                                        <input type="hidden" name="action" value="ofp_property_payment_reject">
                                        <input type="hidden" name="payment_id" value="<?php echo esc_attr( $row->id ); ?>">
                                        <button class="button" type="submit">Reject</button>
                                    </form>
                                <?php else : ?>—<?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $row->created_at ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public static function client_page(): void {
        $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        if ( 'property-payments' !== $path ) return;

        $client = OFP_Auth::current_client();
        if ( ! $client ) {
            wp_safe_redirect( home_url( '/login' ) );
            exit;
        }
        if ( ! OFP_Subscription::has_active( 'listing', $client->id ) ) {
            wp_safe_redirect( home_url( '/dashboard' ) );
            exit;
        }

        $rows = self::get_rows( (int) $client->id );
        ?>
        <!doctype html><html lang="en"><head>
            <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Property Payments — OFast Pipeline</title>
            <?php wp_head(); ?>
            <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
        </head><body class="ofp-portal-body">
        <?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>
        <div class="ofp-container"><div style="padding-bottom:60px;">
            <h1 style="font-size:22px;font-weight:700;margin:0 0 24px;">Property Payments</h1>
            <div class="ofp-card">
                <p class="ofp-hint">All payments for purchases belonging to your properties. Pending manual payments can be verified or rejected here.</p>
                <div style="overflow-x:auto;">
                    <table class="widefat striped" style="min-width:1250px;">
                        <thead><tr><th>ID</th><th>Buyer</th><th>Property</th><th>Purchase</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th><th>Receipt</th><th>Paid</th><th>Balance</th><th>Action</th><th>Created</th></tr></thead>
                        <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="13">No property payment records yet.</td></tr>
                        <?php else : foreach ( $rows as $row ) : ?>
                            <tr>
                                <td>#<?php echo esc_html( $row->id ); ?></td>
                                <td><?php echo esc_html( $row->buyer_name ); ?><br><small><?php echo esc_html( $row->buyer_phone ); ?></small></td>
                                <td><?php echo esc_html( $row->property_title ?: '—' ); ?></td>
                                <td>#<?php echo esc_html( $row->purchase_id ); ?></td>
                                <td><strong>NGN <?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></strong></td>
                                <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->payment_method ) ) ); ?></td>
                                <td><code><?php echo esc_html( $row->payer_reference ?: ( $row->gateway_reference ?: '—' ) ); ?></code></td>
                                <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?></td>
                                <td><?php echo $row->receipt_path ? 'Available' : '—'; ?></td>
                                <td>NGN <?php echo esc_html( number_format( (float) $row->purchase_paid, 2 ) ); ?></td>
                                <td>NGN <?php echo esc_html( number_format( (float) $row->purchase_balance, 2 ) ); ?></td>
                                <td>
                                    <?php if ( 'pending_verification' === $row->status ) : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                            <?php wp_nonce_field( 'ofp_client_payment_verify_' . $row->id ); ?>
                                            <input type="hidden" name="action" value="ofp_client_payment_verify">
                                            <input type="hidden" name="payment_id" value="<?php echo esc_attr( $row->id ); ?>">
                                            <button class="button button-primary" type="submit">Verify</button>
                                        </form>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                            <?php wp_nonce_field( 'ofp_client_payment_reject_' . $row->id ); ?>
                                            <input type="hidden" name="action" value="ofp_client_payment_reject">
                                            <input type="hidden" name="payment_id" value="<?php echo esc_attr( $row->id ); ?>">
                                            <button class="button" type="submit">Reject</button>
                                        </form>
                                    <?php else : ?>—<?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $row->created_at ); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div></div>
        <?php wp_footer(); ?></body></html>
        <?php
        exit;
    }

    public static function admin_verify(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        $payment_id = absint( $_POST['payment_id'] ?? 0 );
        check_admin_referer( 'ofp_property_payment_verify_' . $payment_id );
        OFP_Property_Payment_Record::success( $payment_id, get_current_user_id() );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-payments' ) );
        exit;
    }

    public static function admin_reject(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        $payment_id = absint( $_POST['payment_id'] ?? 0 );
        check_admin_referer( 'ofp_property_payment_reject_' . $payment_id );
        OFP_Property_Payment_Record::reject( $payment_id, get_current_user_id(), 'Rejected from property payment review.' );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-payments' ) );
        exit;
    }

    public static function client_verify(): void {
        self::client_action( true );
    }

    public static function client_reject(): void {
        self::client_action( false );
    }

    private static function client_action( bool $approve ): void {
        $payment_id = absint( $_POST['payment_id'] ?? 0 );
        check_admin_referer( 'ofp_client_payment_' . ( $approve ? 'verify_' : 'reject_' ) . $payment_id );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client || ! OFP_Subscription::has_active( 'listing', $client->id ) ) wp_die( 'Access denied.' );

        global $wpdb;
        $owned = $wpdb->get_var( $wpdb->prepare(
            "SELECT py.id FROM {$wpdb->prefix}ofp_property_payments py INNER JOIN {$wpdb->prefix}ofp_property_purchases pu ON pu.id = py.purchase_id WHERE py.id = %d AND pu.client_id = %d LIMIT 1",
            $payment_id,
            (int) $client->id
        ) );
        if ( ! $owned ) wp_die( 'Access denied.' );

        if ( $approve ) {
            OFP_Property_Payment_Record::success( $payment_id, 0 );
        } else {
            OFP_Property_Payment_Record::reject( $payment_id, 0, 'Rejected by property client.' );
        }

        wp_safe_redirect( wp_get_referer() ?: home_url( '/property-payments/' ) );
        exit;
    }

    public static function redirect_legacy_verification(): void {
        $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        if ( 'property-payment-verification' !== $path ) return;
        wp_safe_redirect( home_url( '/property-payments/' ) );
        exit;
    }
}

OFP_Property_Payment_Records::init();
