<?php
/**
 * OFP_Property_Purchase_Service
 *
 * Purchase-domain operations for property commerce. Buyers do not need an
 * OFastpipeline account and an OFP lead record is optional for offline buyers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_Purchase_Service {

    /**
     * Create a direct property purchase and its installment schedule.
     *
     * @return int|WP_Error
     */
    public static function create( array $data ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $property_id = absint( $data['property_id'] ?? 0 );
        $property = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_properties WHERE id = %d LIMIT 1",
            $property_id
        ) );

        if ( ! $property ) {
            return new WP_Error( 'property_not_found', 'Property not found.' );
        }
        if ( 'sale' !== $property->listing_type ) {
            return new WP_Error( 'property_not_for_sale', 'Only sale properties can have installment purchases.' );
        }

        $buyer_name         = sanitize_text_field( $data['buyer_name'] ?? '' );
        $buyer_phone        = OFP_Security::sanitize_phone( $data['buyer_phone'] ?? '' );
        $buyer_email        = ! empty( $data['buyer_email'] ) ? sanitize_email( $data['buyer_email'] ) : null;
        $initial_payment    = max( 0.0, (float) ( $data['initial_payment'] ?? 0 ) );
        $installment_amount = max( 0.0, (float) ( $data['installment_amount'] ?? 0 ) );
        $installment_count  = max( 0, absint( $data['installment_count'] ?? 0 ) );
        $payment_start_date = sanitize_text_field( $data['payment_start_date'] ?? '' );
        $first_due_date     = sanitize_text_field( $data['first_due_date'] ?? '' );
        $grace_days         = min( 365, max( 0, absint( $data['grace_period_days'] ?? 7 ) ) );
        $payment_method     = sanitize_key( $data['payment_method'] ?? 'manual' );
        $lead_id            = ! empty( $data['lead_id'] ) ? absint( $data['lead_id'] ) : null;

        if ( ! $buyer_name || ! $buyer_phone ) {
            return new WP_Error( 'buyer_required', 'Buyer name and phone are required.' );
        }
        if ( $buyer_email && ! is_email( $buyer_email ) ) {
            return new WP_Error( 'invalid_buyer_email', 'Buyer email is invalid.' );
        }
        if ( ! $payment_start_date || ! $first_due_date || strtotime( $first_due_date ) < strtotime( $payment_start_date ) ) {
            return new WP_Error( 'invalid_payment_dates', 'Payment start and first due date are required, and first due date cannot be before payment start.' );
        }

        $total_price = (float) $property->price;
        if ( $total_price <= 0 ) {
            return new WP_Error( 'invalid_property_price', 'Property price is invalid.' );
        }
        if ( $initial_payment >= $total_price ) {
            return new WP_Error( 'invalid_initial_payment', 'Initial payment must be less than the property price.' );
        }
        if ( $installment_amount <= 0 || $installment_count <= 0 ) {
            return new WP_Error( 'invalid_installment_plan', 'Installment amount and number of installments are required.' );
        }
        if ( abs( ( $total_price - $initial_payment ) - ( $installment_amount * $installment_count ) ) > 0.01 ) {
            return new WP_Error( 'installment_mismatch', 'The installment schedule must exactly cover the remaining property balance.' );
        }

        $owner_type = ! empty( $property->client_id ) ? 'client' : 'platform';
        $owner_id   = ! empty( $property->client_id ) ? (int) $property->client_id : null;

        $wpdb->query( 'START TRANSACTION' );

        try {
            $ok = $wpdb->insert(
                "{$p}ofp_property_purchases",
                [
                    'property_id'         => $property_id,
                    'client_id'           => $owner_id,
                    'lead_id'             => $lead_id,
                    'buyer_name'          => $buyer_name,
                    'buyer_phone'         => $buyer_phone,
                    'buyer_email'         => $buyer_email,
                    'total_price'         => $total_price,
                    'amount_paid'         => 0.00,
                    'balance'             => $total_price,
                    'initial_payment'     => $initial_payment,
                    'installment_amount'  => $installment_amount,
                    'frequency'           => 'monthly',
                    'installment_count'   => $installment_count,
                    'payment_start_date'  => $payment_start_date,
                    'first_due_date'      => $first_due_date,
                    'grace_period_days'   => $grace_days,
                    'payment_owner_type'  => $owner_type,
                    'payment_owner_id'    => $owner_id,
                    'payment_method'      => $payment_method,
                    'status'              => 'active',
                    'created_at'          => current_time( 'mysql' ),
                    'updated_at'          => current_time( 'mysql' ),
                ]
            );

            if ( ! $ok ) {
                throw new RuntimeException( 'Unable to create purchase record.' );
            }

            $purchase_id = (int) $wpdb->insert_id;
            $number = 1;

            if ( $initial_payment > 0 ) {
                self::insert_installment( $purchase_id, $number++, $first_due_date, $initial_payment, $grace_days, 'due' );
            }

            for ( $i = 0; $i < $installment_count; $i++ ) {
                $month_offset = $i + ( $initial_payment > 0 ? 1 : 0 );
                $due_date = gmdate( 'Y-m-d', strtotime( $first_due_date . ' +' . $month_offset . ' month' ) );
                self::insert_installment( $purchase_id, $number++, $due_date, $installment_amount, $grace_days, 'scheduled' );
            }

            $wpdb->query( 'COMMIT' );
            return $purchase_id;
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'property_purchase_create_failed', $e->getMessage() );
        }
    }

    private static function insert_installment( int $purchase_id, int $number, string $due_date, float $amount, int $grace_days, string $status ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert(
            "{$p}ofp_property_installments",
            [
                'purchase_id'    => $purchase_id,
                'installment_no' => $number,
                'due_date'       => $due_date,
                'amount_due'     => $amount,
                'amount_paid'    => 0.00,
                'status'         => $status,
                'grace_ends_at'  => gmdate( 'Y-m-d', strtotime( $due_date . ' +' . $grace_days . ' days' ) ),
                'created_at'     => current_time( 'mysql' ),
                'updated_at'     => current_time( 'mysql' ),
            ]
        );

        if ( $wpdb->last_error ) {
            throw new RuntimeException( 'Unable to create installment schedule.' );
        }
    }
}
