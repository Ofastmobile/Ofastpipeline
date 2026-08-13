<?php
/**
 * OFP_Property_Commerce
 *
 * Property purchase, installment and payment domain layer.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Commerce {
    const SCHEMA_VERSION = '1.0.1';

    public static function init(): void {
        self::install_schema();
    }

    private static function install_schema(): void {
        if ( get_option( 'ofp_property_commerce_schema' ) === self::SCHEMA_VERSION ) return;

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix;
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE {$p}ofp_property_purchases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            property_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NULL,
            lead_id BIGINT UNSIGNED NULL,
            contact_id BIGINT UNSIGNED NULL,
            purchase_type VARCHAR(20) NOT NULL DEFAULT 'installment',
            offer_id BIGINT UNSIGNED NULL,
            buyer_name VARCHAR(150) NOT NULL,
            buyer_phone VARCHAR(30) NOT NULL,
            buyer_email VARCHAR(150) NULL,
            total_price DECIMAL(14,2) NOT NULL,
            amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            initial_payment DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            installment_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
            installment_count INT UNSIGNED NOT NULL DEFAULT 0,
            payment_start_date DATE NULL,
            first_due_date DATE NULL,
            grace_period_days INT UNSIGNED NOT NULL DEFAULT 7,
            payment_owner_type VARCHAR(20) NOT NULL DEFAULT 'platform',
            payment_owner_id BIGINT UNSIGNED NULL,
            payment_method VARCHAR(30) NOT NULL DEFAULT 'manual',
            payment_provider VARCHAR(30) NULL,
            terms_text LONGTEXT NULL,
            terms_version VARCHAR(40) NULL,
            terms_accepted_at DATETIME NULL,
            terms_accepted_ip VARCHAR(45) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY client_id (client_id),
            KEY lead_id (lead_id),
            KEY contact_id (contact_id),
            KEY purchase_type (purchase_type),
            KEY offer_id (offer_id),
            KEY status (status),
            KEY payment_owner (payment_owner_type, payment_owner_id)
        ) {$charset_collate};" );

        update_option( 'ofp_property_commerce_schema', self::SCHEMA_VERSION, false );
    }
}
