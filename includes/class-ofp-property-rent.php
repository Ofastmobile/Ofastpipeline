<?php
/**
 * Rent-management foundation.
 *
 * Tenant and lease data deliberately live outside the purchase tables: a rent
 * cycle has different lifecycle, payment and renewal rules from a sale.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Rent {

    const SCHEMA_VERSION = '1.0.3';

    /** Rent periods selectable by a landlord when setting up a property. */
    const STANDARD_PERIODS = [ 'monthly', 'quarterly', 'biannual', 'yearly', 'shortlet', 'custom' ];

    public static function init(): void {
        self::ensure_schema();
    }

    /**
     * Uses the same dbDelta migration pattern as property commerce, but keeps
     * rent schema independently versioned for safe upgrades on existing sites.
     */
    public static function ensure_schema(): void {
        if ( get_option( 'ofp_property_rent_schema_version' ) === self::SCHEMA_VERSION ) return;

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix;
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE {$p}ofp_property_tenants (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id         BIGINT UNSIGNED NOT NULL,
            full_name         VARCHAR(150) NOT NULL,
            email             VARCHAR(150) NULL,
            phone             VARCHAR(30) NOT NULL,
            access_token_hash CHAR(64) NOT NULL,
            status            VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY access_token_hash (access_token_hash),
            KEY client_phone (client_id, phone),
            KEY client_email (client_id, email),
            KEY client_id (client_id),
            KEY status (status)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$p}ofp_property_rent_options (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            property_id       BIGINT UNSIGNED NOT NULL,
            client_id         BIGINT UNSIGNED NOT NULL,
            period             VARCHAR(20) NOT NULL,
            custom_days        INT UNSIGNED NULL,
            amount             DECIMAL(14,2) NOT NULL,
            is_active          TINYINT(1) NOT NULL DEFAULT 1,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME NULL,
            PRIMARY KEY (id),
            KEY property_active (property_id, is_active),
            KEY client_id (client_id),
            KEY period (period)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$p}ofp_property_leases (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id             BIGINT UNSIGNED NOT NULL,
            property_id           BIGINT UNSIGNED NOT NULL,
            tenant_id             BIGINT UNSIGNED NOT NULL,
            rent_option_id        BIGINT UNSIGNED NULL,
            rent_period           VARCHAR(20) NOT NULL,
            custom_days           INT UNSIGNED NULL,
            rent_amount           DECIMAL(14,2) NOT NULL,
            amount_paid           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            balance               DECIMAL(14,2) NOT NULL,
            start_date            DATE NULL,
            end_date              DATE NULL,
            terms_text            LONGTEXT NULL,
            terms_accepted_at     DATETIME NULL,
            terms_accepted_ip     VARCHAR(45) NULL,
            offer_token           VARCHAR(64) NULL,
            offer_token_hash      CHAR(64) NOT NULL,
            offer_sent_at         DATETIME NULL,
            offer_expires_at      DATETIME NULL,
            accepted_at           DATETIME NULL,
            status                VARCHAR(20) NOT NULL DEFAULT 'pending_offer',
            last_reminder_at      DATETIME NULL,
            va_account_number     VARCHAR(30) NULL,
            va_bank_name          VARCHAR(100) NULL,
            va_bank_code          VARCHAR(30) NULL,
            va_customer_code      VARCHAR(100) NULL,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY offer_token_hash (offer_token_hash),
            KEY property_status (property_id, status),
            KEY tenant_created (tenant_id, created_at),
            KEY client_status (client_id, status),
            KEY end_date_status (end_date, status)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$p}ofp_property_lease_payments (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lease_id              BIGINT UNSIGNED NOT NULL,
            payment_method        VARCHAR(30) NOT NULL DEFAULT 'manual',
            gateway               VARCHAR(30) NULL,
            gateway_reference     VARCHAR(150) NULL,
            amount                DECIMAL(14,2) NOT NULL,
            status                VARCHAR(30) NOT NULL DEFAULT 'pending_verification',
            payer_name            VARCHAR(150) NULL,
            payer_reference       VARCHAR(150) NULL,
            note                  TEXT NULL,
            receipt_path          VARCHAR(500) NULL,
            receipt_mime          VARCHAR(100) NULL,
            receipt_size          INT UNSIGNED NULL,
            verified_by           BIGINT UNSIGNED NULL,
            verified_at           DATETIME NULL,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY gateway_reference (gateway, gateway_reference),
            KEY lease_id (lease_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};" );

        // Ensure offer_token column exists if table was created in 1.0.2
        $cols = (array) $wpdb->get_col( "DESCRIBE {$p}ofp_property_leases" );
        if ( ! in_array( 'offer_token', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$p}ofp_property_leases ADD COLUMN `offer_token` VARCHAR(64) NULL AFTER `terms_accepted_ip`" );
        }

        update_option( 'ofp_property_rent_schema_version', self::SCHEMA_VERSION, false );
    }

    public static function can_manage( int $client_id ): bool {
        return class_exists( 'OFP_Subscription' ) && OFP_Subscription::allows_rent_management( $client_id );
    }

    /** A tenant is private to one landlord, even if they rent elsewhere. */
    public static function find_tenant( int $client_id, string $phone, string $email = '' ): ?object {
        global $wpdb;
        $phone = self::normalize_phone( $phone );
        $email = sanitize_email( $email );
        if ( ! $client_id || ( ! $phone && ! $email ) ) return null;

        $where = $phone ? 'phone = %s' : 'email = %s';
        $value = $phone ?: $email;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ofp_property_tenants WHERE client_id = %d AND {$where} LIMIT 1",
            $client_id,
            $value
        ) );
    }

    /**
     * Creates or updates a client-scoped tenant and returns both the record ID
     * and a one-time raw tracking token when a new record was created.
     */
    public static function find_or_create_tenant( int $client_id, array $data ) {
        global $wpdb;
        $name  = sanitize_text_field( $data['full_name'] ?? '' );
        $phone = self::normalize_phone( $data['phone'] ?? '' );
        $email = sanitize_email( $data['email'] ?? '' );
        if ( ! $client_id || ! $name || ! $phone ) return new WP_Error( 'invalid_tenant', 'Tenant name and phone are required.' );

        $existing = self::find_tenant( $client_id, $phone, $email );
        if ( $existing ) {
            $wpdb->update( "{$wpdb->prefix}ofp_property_tenants", [
                'full_name'  => $name,
                'email'      => $email ?: $existing->email,
                'updated_at' => current_time( 'mysql' ),
            ], [ 'id' => (int) $existing->id ] );
            return [ 'tenant_id' => (int) $existing->id, 'access_token' => null ];
        }

        $token = bin2hex( random_bytes( 32 ) );
        $ok = $wpdb->insert( "{$wpdb->prefix}ofp_property_tenants", [
            'client_id'         => $client_id,
            'full_name'         => $name,
            'email'             => $email ?: null,
            'phone'             => $phone,
            'access_token_hash' => hash( 'sha256', $token ),
            'created_at'        => current_time( 'mysql' ),
        ] );
        if ( ! $ok ) return new WP_Error( 'tenant_create_failed', 'Unable to create the tenant record.' );
        return [ 'tenant_id' => (int) $wpdb->insert_id, 'access_token' => $token ];
    }

    public static function normalize_phone( string $phone ): string {
        return preg_replace( '/[^0-9+]/', '', trim( $phone ) );
    }

    public static function period_label( string $period, ?int $custom_days = null ): string {
        if ( $period === 'custom' && $custom_days ) return sprintf( _n( '%d day', '%d days', $custom_days, 'ofast-pipeline' ), $custom_days );
        return ucfirst( $period );
    }

    /** @return object|WP_Error Property commerce row owned by this client. */
    private static function owned_property( int $property_id, int $client_id ) {
        global $wpdb;
        $property = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ofp_properties WHERE id = %d AND client_id = %d LIMIT 1",
            $property_id,
            $client_id
        ) );
        if ( ! $property ) return new WP_Error( 'property_not_found', 'Property not found for this client.' );
        if ( ! in_array( $property->listing_type, [ 'rent', 'shortlet' ], true ) ) {
            return new WP_Error( 'property_not_rentable', 'Only rent or shortlet properties can have rent options.' );
        }
        return $property;
    }

    public static function get_rent_options( int $property_id, int $client_id = 0, bool $active_only = false ): array {
        global $wpdb;
        $where = 'property_id = %d';
        $args = [ $property_id ];
        if ( $client_id ) {
            $where .= ' AND client_id = %d';
            $args[] = $client_id;
        }
        if ( $active_only ) $where .= ' AND is_active = 1';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ofp_property_rent_options WHERE {$where} ORDER BY amount ASC, id ASC",
            ...$args
        ) );
    }

    public static function save_rent_option( int $client_id, int $property_id, array $data, int $option_id = 0 ) {
        global $wpdb;
        $property = self::owned_property( $property_id, $client_id );
        if ( is_wp_error( $property ) ) return $property;

        $period = sanitize_key( $data['period'] ?? '' );
        $custom_days = absint( $data['custom_days'] ?? 0 );
        $amount = round( max( 0, (float) ( $data['amount'] ?? 0 ) ), 2 );
        if ( ! in_array( $period, self::STANDARD_PERIODS, true ) ) return new WP_Error( 'invalid_rent_period', 'Invalid rent period.' );
        if ( $period === 'custom' && $custom_days < 1 ) return new WP_Error( 'custom_days_required', 'A custom rent period needs a positive number of days.' );
        if ( $period !== 'custom' ) $custom_days = 0;
        if ( $amount <= 0 ) return new WP_Error( 'invalid_rent_amount', 'Rent amount must be greater than zero.' );

        $values = [
            'period'      => $period,
            'custom_days' => $custom_days ?: null,
            'amount'      => $amount,
            'is_active'   => empty( $data['is_active'] ) ? 0 : 1,
            'updated_at'  => current_time( 'mysql' ),
        ];
        if ( $option_id ) {
            $updated = $wpdb->update( "{$wpdb->prefix}ofp_property_rent_options", $values, [
                'id' => $option_id, 'property_id' => $property_id, 'client_id' => $client_id,
            ] );
            if ( false === $updated ) return new WP_Error( 'rent_option_update_failed', 'Unable to update rent option.' );
            return $option_id;
        }

        $values['property_id'] = $property_id;
        $values['client_id'] = $client_id;
        $values['created_at'] = current_time( 'mysql' );
        if ( ! $wpdb->insert( "{$wpdb->prefix}ofp_property_rent_options", $values ) ) return new WP_Error( 'rent_option_create_failed', 'Unable to create rent option.' );
        return (int) $wpdb->insert_id;
    }

    public static function create_lease_offer( int $client_id, int $property_id, int $tenant_id, int $option_id, array $data ) {
        global $wpdb;
        if ( ! self::can_manage( $client_id ) ) return new WP_Error( 'rent_plan_required', 'Rent management is available on the Gold plan.' );
        $property = self::owned_property( $property_id, $client_id );
        if ( is_wp_error( $property ) ) return $property;
        if ( self::property_is_occupied( $property_id ) ) return new WP_Error( 'property_occupied', 'This property already has an active lease.' );

        $tenant = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_property_tenants WHERE id = %d AND client_id = %d AND status = 'active' LIMIT 1",
            $tenant_id,
            $client_id
        ) );
        if ( ! $tenant ) return new WP_Error( 'tenant_not_found', 'Tenant not found for this client.' );
        $option = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ofp_property_rent_options WHERE id = %d AND property_id = %d AND client_id = %d AND is_active = 1 LIMIT 1",
            $option_id,
            $property_id,
            $client_id
        ) );
        if ( ! $option ) return new WP_Error( 'rent_option_not_found', 'Select an active rent option for this property.' );

        $expires_at = ! empty( $data['offer_expires_at'] ) ? sanitize_text_field( $data['offer_expires_at'] ) : gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) );
        $token = bin2hex( random_bytes( 32 ) );
        $amount = (float) $option->amount;
        $ok = $wpdb->insert( "{$wpdb->prefix}ofp_property_leases", [
            'client_id'        => $client_id,
            'property_id'      => $property_id,
            'tenant_id'        => $tenant_id,
            'rent_option_id'   => $option_id,
            'rent_period'      => $option->period,
            'custom_days'      => $option->custom_days,
            'rent_amount'      => $amount,
            'balance'          => $amount,
            'terms_text'       => sanitize_textarea_field( $data['terms_text'] ?? '' ) ?: null,
            'offer_token'      => $token,
            'offer_token_hash' => hash( 'sha256', $token ),
            'offer_sent_at'    => current_time( 'mysql' ),
            'offer_expires_at' => $expires_at,
            'created_at'       => current_time( 'mysql' ),
        ] );
        if ( ! $ok ) return new WP_Error( 'lease_offer_create_failed', 'Unable to create lease offer.' );
        return [ 'lease_id' => (int) $wpdb->insert_id, 'offer_token' => $token ];
    }

    /** Acceptance records consent; the first verified payment activates the lease. */
    public static function accept_lease_offer( string $token, string $ip = '' ) {
        global $wpdb;
        $lease = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ofp_property_leases WHERE offer_token_hash = %s LIMIT 1",
            hash( 'sha256', $token )
        ) );
        if ( ! $lease || $lease->status !== 'pending_offer' ) return new WP_Error( 'lease_offer_invalid', 'This lease offer is unavailable.' );
        if ( $lease->offer_expires_at && strtotime( $lease->offer_expires_at ) < time() ) {
            $wpdb->update( "{$wpdb->prefix}ofp_property_leases", [ 'status' => 'expired', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $lease->id ] );
            return new WP_Error( 'lease_offer_expired', 'This lease offer has expired.' );
        }
        $wpdb->update( "{$wpdb->prefix}ofp_property_leases", [
            'accepted_at'       => current_time( 'mysql' ),
            'terms_accepted_at' => current_time( 'mysql' ),
            'terms_accepted_ip' => sanitize_text_field( $ip ) ?: null,
            'updated_at'        => current_time( 'mysql' ),
        ], [ 'id' => $lease->id ] );
        return (int) $lease->id;
    }

    public static function property_is_occupied( int $property_id ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_property_leases WHERE property_id = %d AND status = 'active' LIMIT 1",
            $property_id
        ) );
    }

    public static function wp_property_is_occupied( int $wp_post_id ): bool {
        global $wpdb;
        $property_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_properties WHERE wp_post_id = %d LIMIT 1",
            $wp_post_id
        ) );
        return $property_id > 0 && self::property_is_occupied( $property_id );
    }

    /** Used by the payment layer after the first successful payment. */
    public static function activate_lease( int $lease_id, string $start_date = '' ) {
        global $wpdb;
        $lease = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ofp_property_leases WHERE id = %d LIMIT 1", $lease_id ) );
        if ( ! $lease ) return new WP_Error( 'lease_not_found', 'Lease not found.' );
        if ( $lease->status === 'active' ) return true;
        if ( $lease->status !== 'pending_offer' || ! $lease->accepted_at ) return new WP_Error( 'lease_not_accepted', 'The tenant must accept the lease before it can be activated.' );
        if ( self::property_is_occupied( (int) $lease->property_id ) ) return new WP_Error( 'property_occupied', 'This property already has an active lease.' );

        $start = $start_date ?: current_time( 'Y-m-d' );
        $end = self::calculate_end_date( $start, (string) $lease->rent_period, (int) $lease->custom_days );
        $updated = $wpdb->update( "{$wpdb->prefix}ofp_property_leases", [
            'status' => 'active', 'start_date' => $start, 'end_date' => $end, 'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $lease_id ] );
        return false === $updated ? new WP_Error( 'lease_activation_failed', 'Unable to activate lease.' ) : true;
    }

    public static function calculate_end_date( string $start, string $period, int $custom_days = 0 ): string {
        $date = new DateTimeImmutable( $start, wp_timezone() );
        $spec = match ( $period ) {
            'monthly'   => '+1 month',
            'quarterly' => '+3 months',
            'biannual'  => '+6 months',
            'yearly'    => '+1 year',
            'shortlet'  => '+1 month',
            'custom'    => '+' . max( 1, $custom_days ) . ' days',
            default     => '+1 month',
        };
        return $date->modify( $spec )->modify( '-1 day' )->format( 'Y-m-d' );
    }
}
