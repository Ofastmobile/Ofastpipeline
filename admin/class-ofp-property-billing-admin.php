<?php
/**
 * Property Billing admin module.
 *
 * Listing subscription billing lives under the standalone Properties menu.
 * It reads the same ofp_subscriptions table used by the core billing system,
 * filtered strictly to type = listing.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Billing_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
    }

    public function register_menu(): void {
        if ( ! OFP_Auth::is_admin_user() ) return;

        add_submenu_page(
            'edit.php?post_type=ofp_property',
            'Listing Billing',
            'Billing',
            'read',
            'ofp-property-billing',
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        if ( ! OFP_Auth::is_admin_user() ) {
            wp_die( esc_html__( 'Access denied.', 'ofast-pipeline' ) );
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $filter_client = absint( $_GET['client_id'] ?? 0 );
        $status_filter = sanitize_key( $_GET['status'] ?? '' );
        $per_page      = 50;
        $current_page  = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $offset        = ( $current_page - 1 ) * $per_page;

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

        $count_sql = "SELECT COUNT(*) FROM {$p}ofp_subscriptions s WHERE {$where_sql}";
        $total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, ...$args ) : $count_sql );

        $select_sql = "SELECT s.*, c.business_name, c.email, c.status AS client_status
             FROM {$p}ofp_subscriptions s
             INNER JOIN {$p}ofp_clients c ON c.id = s.client_id
             WHERE {$where_sql}
             ORDER BY s.created_at DESC
             LIMIT %d OFFSET %d";

        $subscriptions = $wpdb->get_results(
            $wpdb->prepare( $select_sql, ...array_merge( $args, [ $per_page, $offset ] ) )
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

        include OFP_PATH . 'admin/views/property-billing.php';
    }
}
