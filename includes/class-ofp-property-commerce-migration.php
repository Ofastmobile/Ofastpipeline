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
            "ALTER TABLE {$p}ofp_property_purchases ADD COLUMN contact_id BIGINT UNSIGNED NULL AFTER lead_id",
        ];

        foreach ( $columns as $sql ) {
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

        // Standalone property buyer/contact records. No user account is created.
        $charset = $wpdb->get_charset_collate();
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}ofp_property_contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NULL,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(30) NOT NULL,
            email VARCHAR(150) NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'offline',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY client_phone (client_id, phone),
            KEY phone (phone),
            KEY client_id (client_id)
        ) {$charset};" );
    }
}
