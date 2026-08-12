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
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>Buyer</th><th>Property</th><th>Owner</th><th>Total</th><th>Plan</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php foreach ( $offers as $offer ) : ?>
                        <tr>
                            <td>#<?php echo esc_html( $offer->id ); ?></td>
                            <td><strong><?php echo esc_html( $offer->buyer_name ); ?></strong><br><small><?php echo esc_html( $offer->buyer_phone ); ?></small></td>
                            <td><?php echo esc_html( $offer->property_title ?: '—' ); ?></td>
                            <td><?php echo esc_html( $offer->business_name ?: 'Platform' ); ?></td>
                            <td>NGN <?php echo esc_html( number_format( (float) $offer->total_price, 2 ) ); ?></td>
                            <td>Initial: NGN <?php echo esc_html( number_format( (float) $offer->initial_payment, 2 ) ); ?><br><?php echo esc_html( $offer->installment_count ); ?> × NGN <?php echo esc_html( number_format( (float) $offer->installment_amount, 2 ) ); ?></td>
                            <td><?php echo esc_html( ucfirst( $offer->status ) ); ?></td>
                            <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $offer->created_at ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>Buyer</th><th>Property</th><th>Owner</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead>
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
                            <td><?php echo esc_html( ucfirst( $purchase->status ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
