<?php
/**
 * OFP_Property_Commerce
 *
 * Property purchase, installment and payment domain layer.
 *
 * This class intentionally does NOT replace the existing property CPT,
 * lead/pipeline, subscription or gateway systems. It adds the transaction
 * layer that sits after a property inquiry/offer.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_Commerce {

    const SCHEMA_VERSION = '1.0.0';

    /**
     * Bootstrap the property-commerce layer.
     */
    public static function init(): void {
        self::install_schema();
    }

    /**
     * Create/upgrade commerce tables safely on plugin load.
     *
     * We deliberately keep this separate from existing subscription tables so
     * property payments can evolve without changing subscription entitlements.
     */
    private static function install_schema(): void {
        if ( get_option( 'ofp_property_commerce_schema' ) === self::SCHEMA_VERSION ) {
            return;
        }

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $p = $wpdb->prefix;
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE {$p}ofp_property_offers (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            property_id           BIGINT UNSIGNED NOT NULL,
            client_id             BIGINT UNSIGNED NULL,
            lead_id               BIGINT UNSIGNED NULL,
            buyer_name            VARCHAR(150) NOT NULL,
            buyer_phone           VARCHAR(30)  NOT NULL,
            buyer_email           VARCHAR(150) NULL,
            total_price           DECIMAL(14,2) NOT NULL,
            initial_payment       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            installment_amount    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            frequency             VARCHAR(20) NOT NULL DEFAULT 'monthly',
            installment_count    INT UNSIGNED NOT NULL DEFAULT 0,
            first_due_date        DATE NULL,
            grace_period_days     INT UNSIGNED NOT NULL DEFAULT 7,
            reminder_days         VARCHAR(100) NOT NULL DEFAULT '7,3,1',
            terms_text            LONGTEXT NULL,
            terms_version         VARCHAR(40) NOT NULL DEFAULT '1',
            offer_token_hash      CHAR(64) NOT NULL,
            status                VARCHAR(20) NOT NULL DEFAULT 'pending',
            expires_at            DATETIME NULL,
            accepted_at           DATETIME NULL,
            declined_at           DATETIME NULL,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY offer_token_hash (offer_token_hash),
            KEY property_id (property_id),
            KEY client_id (client_id),
            KEY lead_id (lead_id),
            KEY status (status),
            KEY expires_at (expires_at)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$p}ofp_property_purchases (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            property_id           BIGINT UNSIGNED NOT NULL,
            client_id             BIGINT UNSIGNED NULL,
            lead_id               BIGINT UNSIGNED NULL,
            offer_id              BIGINT UNSIGNED NULL,
            buyer_name            VARCHAR(150) NOT NULL,
            buyer_phone           VARCHAR(30)  NOT NULL,
            buyer_email           VARCHAR(150) NULL,
            total_price           DECIMAL(14,2) NOT NULL,
            amount_paid           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            balance               DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            initial_payment       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            installment_amount    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            frequency             VARCHAR(20) NOT NULL DEFAULT 'monthly',
            installment_count    INT UNSIGNED NOT NULL DEFAULT 0,
            first_due_date        DATE NULL,
            grace_period_days     INT UNSIGNED NOT NULL DEFAULT 7,
            payment_owner_type    VARCHAR(20) NOT NULL DEFAULT 'platform',
            payment_owner_id      BIGINT UNSIGNED NULL,
            payment_method        VARCHAR(30) NOT NULL DEFAULT 'manual',
            payment_provider      VARCHAR(30) NULL,
            terms_text            LONGTEXT NULL,
            terms_version         VARCHAR(40) NULL,
            terms_accepted_at     DATETIME NULL,
            terms_accepted_ip     VARCHAR(45) NULL,
            status                VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME NULL,
            PRIMARY KEY (id),
            KEY property_id (property_id),
            KEY client_id (client_id),
            KEY lead_id (lead_id),
            KEY offer_id (offer_id),
            KEY status (status),
            KEY payment_owner (payment_owner_type, payment_owner_id)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$p}ofp_property_installments (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            purchase_id           BIGINT UNSIGNED NOT NULL,
            installment_no        INT UNSIGNED NOT NULL,
            due_date              DATE NOT NULL,
            amount_due            DECIMAL(14,2) NOT NULL,
            amount_paid           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            status                VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            last_reminder_at      DATETIME NULL,
            grace_ends_at         DATE NULL,
            paid_at               DATETIME NULL,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY purchase_installment (purchase_id, installment_no),
            KEY purchase_id (purchase_id),
            KEY due_status (due_date, status)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$p}ofp_property_payments (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            purchase_id           BIGINT UNSIGNED NOT NULL,
            payment_method        VARCHAR(30) NOT NULL DEFAULT 'manual',
            gateway               VARCHAR(30) NULL,
            gateway_reference     VARCHAR(150) NULL,
            amount                DECIMAL(14,2) NOT NULL,
            status                VARCHAR(20) NOT NULL DEFAULT 'pending_verification',
            payer_name            VARCHAR(150) NULL,
            payer_reference       VARCHAR(150) NULL,
            receipt_path          VARCHAR(500) NULL,
            receipt_mime          VARCHAR(100) NULL,
            receipt_size          INT UNSIGNED NULL,
            note                  TEXT NULL,
            verified_by           BIGINT UNSIGNED NULL,
            verified_at           DATETIME NULL,
            provider_payload_hash CHAR(64) NULL,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at            DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY gateway_reference (gateway, gateway_reference),
            KEY purchase_id (purchase_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$p}ofp_property_payment_allocations (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id            BIGINT UNSIGNED NOT NULL,
            installment_id        BIGINT UNSIGNED NOT NULL,
            amount                DECIMAL(14,2) NOT NULL,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY payment_installment (payment_id, installment_id),
            KEY payment_id (payment_id),
            KEY installment_id (installment_id)
        ) {$charset_collate};" );

        update_option( 'ofp_property_commerce_schema', self::SCHEMA_VERSION, false );
    }

    /**
     * Create an offer token. Only the hash is stored in the database.
     */
    public static function create_offer_token(): array {
        $token = wp_generate_password( 48, false, false );
        return [ $token, hash( 'sha256', $token ) ];
    }

    /**
     * Create a purchase from an accepted offer.
     *
     * @return int|WP_Error
     */
    public static function create_purchase_from_offer( int $offer_id, string $accepted_ip = '' ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $offer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_offers WHERE id = %d LIMIT 1",
            $offer_id
        ) );

        if ( ! $offer ) {
            return new WP_Error( 'offer_not_found', 'Installment offer was not found.' );
        }

        if ( $offer->status !== 'pending' ) {
            return new WP_Error( 'offer_not_pending', 'This offer is no longer available for acceptance.' );
        }

        if ( $offer->expires_at && strtotime( $offer->expires_at ) < current_time( 'timestamp' ) ) {
            $wpdb->update(
                "{$p}ofp_property_offers",
                [ 'status' => 'expired', 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $offer_id ]
            );
            return new WP_Error( 'offer_expired', 'This installment offer has expired.' );
        }

        $wpdb->query( 'START TRANSACTION' );

        try {
            $inserted = $wpdb->insert(
                "{$p}ofp_property_purchases",
                [
                    'property_id'         => (int) $offer->property_id,
                    'client_id'           => $offer->client_id ? (int) $offer->client_id : null,
                    'lead_id'             => $offer->lead_id ? (int) $offer->lead_id : null,
                    'offer_id'            => $offer_id,
                    'buyer_name'          => sanitize_text_field( $offer->buyer_name ),
                    'buyer_phone'         => sanitize_text_field( $offer->buyer_phone ),
                    'buyer_email'         => $offer->buyer_email ? sanitize_email( $offer->buyer_email ) : null,
                    'total_price'         => (float) $offer->total_price,
                    'amount_paid'         => 0,
                    'balance'             => (float) $offer->total_price,
                    'initial_payment'     => (float) $offer->initial_payment,
                    'installment_amount'  => (float) $offer->installment_amount,
                    'frequency'           => sanitize_key( $offer->frequency ),
                    'installment_count'  => (int) $offer->installment_count,
                    'first_due_date'      => $offer->first_due_date,
                    'grace_period_days'   => (int) $offer->grace_period_days,
                    'payment_owner_type'  => $offer->client_id ? 'client' : 'platform',
                    'payment_owner_id'    => $offer->client_id ? (int) $offer->client_id : null,
                    'terms_text'          => $offer->terms_text,
                    'terms_version'       => sanitize_text_field( $offer->terms_version ),
                    'terms_accepted_at'   => current_time( 'mysql' ),
                    'terms_accepted_ip'   => sanitize_text_field( $accepted_ip ),
                    'status'              => 'active',
                    'created_at'          => current_time( 'mysql' ),
                    'updated_at'          => current_time( 'mysql' ),
                ]
            );

            if ( ! $inserted ) {
                throw new Exception( 'Unable to create purchase.' );
            }

            $purchase_id = (int) $wpdb->insert_id;

            $initial = max( 0.0, (float) $offer->initial_payment );
            $count   = (int) $offer->installment_count;
            $monthly = (float) $offer->installment_amount;
            $first   = $offer->first_due_date;

            // The initial payment is not automatically marked paid. It is an
            // amount due under the accepted agreement and becomes paid only
            // after a verified payment is allocated to it.
            if ( $initial > 0 ) {
                $wpdb->insert(
                    "{$p}ofp_property_installments",
                    [
                        'purchase_id' => $purchase_id,
                        'installment_no' => 1,
                        'due_date' => $first ?: current_time( 'Y-m-d' ),
                        'amount_due' => $initial,
                        'status' => 'due',
                        'grace_ends_at' => $first ? gmdate( 'Y-m-d', strtotime( $first . ' +' . (int) $offer->grace_period_days . ' days' ) ) : null,
                        'created_at' => current_time( 'mysql' ),
                        'updated_at' => current_time( 'mysql' ),
                    ]
                );
            }

            if ( $count > 0 && $monthly > 0 && $first ) {
                $start_no = $initial > 0 ? 2 : 1;
                for ( $i = 0; $i < $count; $i++ ) {
                    $no = $start_no + $i;
                    $due = gmdate( 'Y-m-d', strtotime( $first . ' +' . ( $i + ( $initial > 0 ? 1 : 0 ) ) . ' month' ) );
                    $wpdb->insert(
                        "{$p}ofp_property_installments",
                        [
                            'purchase_id' => $purchase_id,
                            'installment_no' => $no,
                            'due_date' => $due,
                            'amount_due' => $monthly,
                            'status' => 'scheduled',
                            'grace_ends_at' => gmdate( 'Y-m-d', strtotime( $due . ' +' . (int) $offer->grace_period_days . ' days' ) ),
                            'created_at' => current_time( 'mysql' ),
                            'updated_at' => current_time( 'mysql' ),
                        ]
                    );
                }
            }

            $wpdb->update(
                "{$p}ofp_property_offers",
                [
                    'status' => 'accepted',
                    'accepted_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' ),
                ],
                [ 'id' => $offer_id ]
            );

            $wpdb->query( 'COMMIT' );
            return $purchase_id;
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'purchase_create_failed', $e->getMessage() );
        }
    }

    /**
     * Allocate a verified payment across the oldest unpaid/partial installments.
     * Supports one payment covering multiple months and partial payments.
     * Returns an array summary; never silently discards money.
     */
    public static function allocate_payment( int $payment_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $payment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_payments WHERE id = %d LIMIT 1",
            $payment_id
        ) );

        if ( ! $payment || $payment->status !== 'successful' ) {
            return [ 'allocated' => 0.0, 'unallocated' => $payment ? (float) $payment->amount : 0.0, 'installments_paid' => [] ];
        }

        $already = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$p}ofp_property_payment_allocations WHERE payment_id = %d",
            $payment_id
        ) );

        $remaining = max( 0.0, (float) $payment->amount - $already );
        $paid_ids  = [];

        if ( $remaining <= 0 ) {
            return [ 'allocated' => $already, 'unallocated' => 0.0, 'installments_paid' => [] ];
        }

        $installments = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_installments
             WHERE purchase_id = %d
               AND status IN ('scheduled','due','partially_paid','overdue')
               AND amount_paid < amount_due
             ORDER BY installment_no ASC",
            (int) $payment->purchase_id
        ) );

        foreach ( $installments as $installment ) {
            if ( $remaining <= 0 ) {
                break;
            }

            $outstanding = max( 0.0, (float) $installment->amount_due - (float) $installment->amount_paid );
            if ( $outstanding <= 0 ) {
                continue;
            }

            $allocation = min( $remaining, $outstanding );

            $wpdb->insert(
                "{$p}ofp_property_payment_allocations",
                [
                    'payment_id' => $payment_id,
                    'installment_id' => (int) $installment->id,
                    'amount' => $allocation,
                    'created_at' => current_time( 'mysql' ),
                ]
            );

            $new_paid = (float) $installment->amount_paid + $allocation;
            $new_status = $new_paid + 0.00001 >= (float) $installment->amount_due ? 'paid' : 'partially_paid';

            $wpdb->update(
                "{$p}ofp_property_installments",
                [
                    'amount_paid' => $new_paid,
                    'status' => $new_status,
                    'paid_at' => $new_status === 'paid' ? current_time( 'mysql' ) : null,
                    'updated_at' => current_time( 'mysql' ),
                ],
                [ 'id' => (int) $installment->id ]
            );

            if ( $new_status === 'paid' ) {
                $paid_ids[] = (int) $installment->id;
            }

            $remaining -= $allocation;
        }

        $allocated = (float) $payment->amount - $remaining;

        // Recalculate purchase totals from allocations, never by trusting a
        // client-supplied balance value.
        $purchase_paid = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$p}ofp_property_payment_allocations a
             INNER JOIN {$p}ofp_property_payments pmt ON pmt.id = a.payment_id
             WHERE pmt.purchase_id = %d AND pmt.status = 'successful'",
            (int) $payment->purchase_id
        ) );

        $total_price = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT total_price FROM {$p}ofp_property_purchases WHERE id = %d",
            (int) $payment->purchase_id
        ) );

        $balance = max( 0.0, $total_price - $purchase_paid );

        $wpdb->update(
            "{$p}ofp_property_purchases",
            [
                'amount_paid' => $purchase_paid,
                'balance' => $balance,
                'status' => $balance <= 0.00001 ? 'completed' : 'active',
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => (int) $payment->purchase_id ]
        );

        return [
            'allocated' => $allocated,
            'unallocated' => max( 0.0, $remaining ),
            'installments_paid' => $paid_ids,
        ];
    }
}
