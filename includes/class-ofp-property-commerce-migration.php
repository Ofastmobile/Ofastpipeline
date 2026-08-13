<?php
/**
 * Additive migrations for property commerce.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Commerce_Migration {
    public static function init(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $columns = [
            "ALTER TABLE {$p}ofp_property_offers ADD COLUMN payment_start_date DATE NULL AFTER installment_count",
            "ALTER TABLE {$p}ofp_property_purchases ADD COLUMN payment_start_date DATE NULL AFTER installment_count",
        ];

        foreach ( $columns as $sql ) {
            // MySQL has no portable IF NOT EXISTS for ADD COLUMN across the
            // versions supported by WordPress, so inspect the schema first.
            if ( preg_match( '/ALTER TABLE (\S+) ADD COLUMN (\S+)/', $sql, $m ) ) {
                $table  = $m[1];
                $column = $m[2];
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                    $table,
                    $column
                ) );
                if ( ! $exists ) {
                    $wpdb->query( $sql );
                }
            }
        }
    }
}
