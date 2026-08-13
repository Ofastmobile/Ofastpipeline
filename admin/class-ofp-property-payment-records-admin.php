<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Payment_Records_Admin {
    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
    }

    public static function register_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        add_submenu_page(
            'edit.php?post_type=ofp_property',
            'Property Payments',
            'Payments',
            'manage_options',
            'ofp-property-payments',
            [ __CLASS__, 'render' ]
        );
    }

    public static function render(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results(
            "SELECT py.*, pu.property_id, pu.client_id, pu.buyer_name, pu.buyer_phone,
                    pr.title AS property_title, c.business_name
             FROM {$p}ofp_property_payments py
             INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
             ORDER BY py.created_at DESC, py.id DESC
             LIMIT 250"
        );
        ?>
        <div class="wrap">
            <h1>Property Payments</h1>
            <p>Every property payment record, including pending manual payments and verified gateway payments.</p>
            <div style="overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;border:1px solid #dcdcde;">
                <table class="widefat striped" style="min-width:1400px;margin:0;">
                    <thead><tr><th>ID</th><th>Buyer</th><th>Property</th><th>Owner</th><th>Purchase</th><th>Amount</th><th>Method</th><th>Gateway</th><th>Gateway Ref</th><th>Status</th><th>Verified By</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="12">No property payment records yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr>
                                <td>#<?php echo esc_html( $row->id ); ?></td>
                                <td><strong><?php echo esc_html( $row->buyer_name ); ?></strong><br><small><?php echo esc_html( $row->buyer_phone ); ?></small></td>
                                <td><?php echo esc_html( $row->property_title ?: '—' ); ?></td>
                                <td><?php echo esc_html( $row->business_name ?: 'Platform' ); ?></td>
                                <td>#<?php echo esc_html( $row->purchase_id ); ?></td>
                                <td><strong>NGN <?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></strong></td>
                                <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->payment_method ) ) ); ?></td>
                                <td><?php echo esc_html( $row->gateway ?: '—' ); ?></td>
                                <td><code><?php echo esc_html( $row->gateway_reference ?: '—' ); ?></code></td>
                                <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?></td>
                                <td><?php echo $row->verified_by ? esc_html( (string) $row->verified_by ) : '—'; ?></td>
                                <td><?php echo esc_html( $row->created_at ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
