<?php
/**
 * Property payment records UI.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Payment_Record_UI {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ], 99 );
        add_action( 'template_redirect', [ __CLASS__, 'client_page' ] );
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

    public static function render_admin(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT py.*, pu.buyer_name, pu.buyer_phone, pu.total_price, pu.amount_paid AS purchase_paid, pu.balance AS purchase_balance,
                    pr.title AS property_title, c.business_name
             FROM {$p}ofp_property_payments py
             INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
             ORDER BY py.created_at DESC, py.id DESC LIMIT 250"
        );
        ?>
        <div class="wrap">
            <h1>Property Payments</h1>
            <p>All payment records for property purchases. Verification and manual receipt handling are handled separately.</p>
            <div style="overflow-x:auto;">
                <table class="widefat striped" style="min-width:1250px;">
                    <thead><tr>
                        <th>ID</th><th>Purchase</th><th>Buyer</th><th>Property</th><th>Owner</th>
                        <th>Amount</th><th>Method</th><th>Gateway</th><th>Gateway Ref</th><th>Status</th>
                        <th>Purchase Paid</th><th>Balance</th><th>Created</th>
                    </tr></thead>
                    <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="13">No property payment records yet.</td></tr>
                    <?php else : foreach ( $rows as $row ) : ?>
                        <tr>
                            <td>#<?php echo esc_html( $row->id ); ?></td>
                            <td>#<?php echo esc_html( $row->purchase_id ); ?></td>
                            <td><strong><?php echo esc_html( $row->buyer_name ); ?></strong><br><small><?php echo esc_html( $row->buyer_phone ); ?></small></td>
                            <td><?php echo esc_html( $row->property_title ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row->business_name ?: 'Platform' ); ?></td>
                            <td><strong>NGN <?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></strong></td>
                            <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->payment_method ) ) ); ?></td>
                            <td><?php echo esc_html( $row->gateway ?: '—' ); ?></td>
                            <td><code><?php echo esc_html( $row->gateway_reference ?: '—' ); ?></code></td>
                            <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?></td>
                            <td>NGN <?php echo esc_html( number_format( (float) $row->purchase_paid, 2 ) ); ?></td>
                            <td>NGN <?php echo esc_html( number_format( (float) $row->purchase_balance, 2 ) ); ?></td>
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
        if ( $path !== 'property-payments' ) return;

        $client = OFP_Auth::current_client();
        if ( ! $client ) {
            wp_safe_redirect( home_url( '/login' ) );
            exit;
        }
        if ( ! OFP_Subscription::has_active( 'listing', $client->id ) ) {
            wp_safe_redirect( home_url( '/dashboard' ) );
            exit;
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT py.*, pu.buyer_name, pu.buyer_phone, pu.total_price, pu.amount_paid AS purchase_paid, pu.balance AS purchase_balance,
                    pr.title AS property_title
             FROM {$p}ofp_property_payments py
             INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             WHERE pu.client_id = %d
             ORDER BY py.created_at DESC, py.id DESC LIMIT 250",
            (int) $client->id
        ) );
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
            <div class="ofp-card"><div style="overflow-x:auto;">
                <table class="widefat striped" style="min-width:1100px;">
                    <thead><tr><th>ID</th><th>Purchase</th><th>Buyer</th><th>Property</th><th>Amount</th><th>Method</th><th>Gateway</th><th>Status</th><th>Paid</th><th>Balance</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="11">No payment records yet.</td></tr>
                    <?php else : foreach ( $rows as $row ) : ?>
                        <tr>
                            <td>#<?php echo esc_html( $row->id ); ?></td>
                            <td>#<?php echo esc_html( $row->purchase_id ); ?></td>
                            <td><?php echo esc_html( $row->buyer_name ); ?><br><small><?php echo esc_html( $row->buyer_phone ); ?></small></td>
                            <td><?php echo esc_html( $row->property_title ?: '—' ); ?></td>
                            <td><strong>NGN <?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></strong></td>
                            <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->payment_method ) ) ); ?></td>
                            <td><?php echo esc_html( $row->gateway ?: '—' ); ?></td>
                            <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?></td>
                            <td>NGN <?php echo esc_html( number_format( (float) $row->purchase_paid, 2 ) ); ?></td>
                            <td>NGN <?php echo esc_html( number_format( (float) $row->purchase_balance, 2 ) ); ?></td>
                            <td><?php echo esc_html( $row->created_at ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div></div>
        <?php wp_footer(); ?></body></html>
        <?php
        exit;
    }
}

OFP_Property_Payment_Record_UI::init();
