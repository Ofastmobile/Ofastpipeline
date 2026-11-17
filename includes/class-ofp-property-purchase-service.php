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

        if ( ! $property ) return new WP_Error( 'property_not_found', 'Property not found.' );
        if ( ! OFP_Property_CPT::is_sale_like( (string) $property->listing_type ) ) return new WP_Error( 'property_not_for_sale', 'Only sale or land properties can have installment purchases.' );
        if ( 'taken' === $property->status ) return new WP_Error( 'property_already_sold', 'This property has already been sold.' );

        // Prevent a property from being bought more than once
        $existing_purchase = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ofp_property_purchases WHERE property_id = %d AND status IN ('active', 'completed')",
            $property_id
        ) );
        if ( $existing_purchase > 0 ) {
            return new WP_Error( 'property_already_purchased', 'This property already has an active or completed purchase.' );
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
        $frequency          = sanitize_key( $data['frequency'] ?? ( isset( $_POST['frequency'] ) ? wp_unslash( $_POST['frequency'] ) : 'monthly' ) );
        $allowed_frequencies = [ 'daily', 'weekly', 'monthly', 'quarterly', 'yearly' ];
        if ( ! in_array( $frequency, $allowed_frequencies, true ) ) {
            $frequency = 'monthly';
        }
        $lead_id            = ! empty( $data['lead_id'] ) ? absint( $data['lead_id'] ) : null;

        if ( ! $buyer_name || ! $buyer_phone ) return new WP_Error( 'buyer_required', 'Buyer name and phone are required.' );
        if ( $buyer_email && ! is_email( $buyer_email ) ) return new WP_Error( 'invalid_buyer_email', 'Buyer email is invalid.' );

        // Prevent the same buyer from purchasing the same property twice
        $buyer_where = $buyer_email ? "(buyer_phone = %s OR buyer_email = %s)" : "buyer_phone = %s";
        $buyer_params = $buyer_email ? [ $buyer_phone, $buyer_email ] : [ $buyer_phone ];
        $duplicate_buyer = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ofp_property_purchases WHERE property_id = %d AND {$buyer_where} AND status IN ('active', 'completed')",
            $property_id,
            ...$buyer_params
        ) );
        if ( $duplicate_buyer > 0 ) {
            return new WP_Error( 'buyer_already_purchased', 'This buyer already has an active or completed purchase for this property.' );
        }

        // Default payment dates if not provided.
        if ( ! $payment_start_date ) {
            $payment_start_date = current_time( 'Y-m-d' );
        }
        if ( ! $first_due_date ) {
            $first_due_date = wp_date( 'Y-m-d', strtotime( $payment_start_date . ' +30 days' ) );
        }
        if ( strtotime( $first_due_date ) < strtotime( $payment_start_date ) ) {
            return new WP_Error( 'invalid_payment_dates', 'First due date cannot be before payment start.' );
        }

        $total_price = (float) $property->price;
        if ( $total_price <= 0 ) return new WP_Error( 'invalid_property_price', 'Property price is invalid.' );
        if ( $initial_payment > $total_price ) return new WP_Error( 'invalid_initial_payment', 'Initial payment cannot exceed the property price.' );
        if ( $initial_payment < $total_price && ( $installment_amount <= 0 || $installment_count <= 0 ) ) return new WP_Error( 'invalid_installment_plan', 'Installment amount and number of installments are required for partial payments.' );
        if ( $initial_payment < $total_price && abs( ( $total_price - $initial_payment ) - ( $installment_amount * $installment_count ) ) > 0.01 ) {
            return new WP_Error( 'installment_mismatch', 'The installment schedule must exactly cover the remaining property balance.' );
        }

        $owner_type = ! empty( $property->client_id ) ? 'client' : 'platform';
        $owner_id   = ! empty( $property->client_id ) ? (int) $property->client_id : null;

        if ( $lead_id ) {
            $lead = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, client_id, property_id FROM {$p}ofp_leads WHERE id = %d LIMIT 1",
                $lead_id
            ) );
            if ( ! $lead ) return new WP_Error( 'lead_not_found', 'Selected lead could not be found.' );
            if ( ! empty( $lead->property_id ) && (int) $lead->property_id !== $property_id ) return new WP_Error( 'lead_property_mismatch', 'Selected lead belongs to a different property.' );
            if ( $owner_id && (int) $lead->client_id !== $owner_id ) return new WP_Error( 'lead_owner_mismatch', 'Selected lead belongs to a different client.' );
        }

        $wpdb->query( 'START TRANSACTION' );

        try {
            // Lock the property row, then repeat the checks. Two requests at the same
            // moment now take turns, so only one purchase can be created (P4.02).
            $locked = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, status FROM {$p}ofp_properties WHERE id = %d LIMIT 1 FOR UPDATE",
                $property_id
            ) );
            if ( ! $locked || 'taken' === $locked->status ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'property_already_sold', 'This property has already been sold.' );
            }
            $existing_locked = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}ofp_property_purchases WHERE property_id = %d AND status IN ('active', 'completed') FOR UPDATE",
                $property_id
            ) );
            if ( $existing_locked > 0 ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'property_already_purchased', 'This property already has an active or completed purchase.' );
            }

            $ok = $wpdb->insert(
                "{$p}ofp_property_purchases",
                [
                    'property_id'         => $property_id,
                    'client_id'           => $owner_id,
                    'lead_id'             => $lead_id,
                    'contact_id'          => null,
                    'buyer_name'          => $buyer_name,
                    'buyer_phone'         => $buyer_phone,
                    'phone10'             => OFP_Security::phone_last10( $buyer_phone ),
                    'buyer_email'         => $buyer_email,
                    'total_price'         => $total_price,
                    'amount_paid'         => 0.00,
                    'balance'             => $total_price,
                    'initial_payment'     => $initial_payment,
                    'installment_amount'  => $installment_amount,
                    'frequency'           => $frequency,
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

            if ( ! $ok ) throw new RuntimeException( 'Unable to create purchase record.' );

            $purchase_id = (int) $wpdb->insert_id;
            $number = 1;

            if ( $initial_payment > 0 ) {
                self::insert_installment( $purchase_id, $number++, $first_due_date, $initial_payment, $grace_days, 'due' );
            } elseif ( $installment_count <= 0 ) {
                self::insert_installment( $purchase_id, $number++, $first_due_date, $total_price, $grace_days, 'due' );
            }

            for ( $i = 0; $i < $installment_count; $i++ ) {
                $period_offset = $i + ( $initial_payment > 0 ? 1 : 0 );
                $due_date = self::calculate_due_date( $first_due_date, $period_offset, $frequency );
                self::insert_installment( $purchase_id, $number++, $due_date, $installment_amount, $grace_days, 'scheduled' );
            }

            $wpdb->query( 'COMMIT' );

            // Invalidate/expire any pending offers for this property
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}ofp_property_offers SET status = 'expired', updated_at = %s WHERE property_id = %d AND status = 'pending'",
                current_time( 'mysql' ),
                $property_id
            ) );

            if ( class_exists( 'OFP_Property_CPT' ) ) {
                OFP_Property_CPT::mark_taken( $property_id, 'taken' );
            }

            OFP_Property_Contact::ensure_for_purchase( $purchase_id );

            if ( $lead_id && class_exists( 'OFP_Lead' ) ) {
                OFP_Lead::update_status( $lead_id, 'converted' );
            }

            do_action( 'ofp_property_purchase_created', $purchase_id, $lead_id, $owner_id, $property_id );
            return $purchase_id;
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'property_purchase_create_failed', $e->getMessage() );
        }
    }

    /**
     * Calculate a schedule due date. ONE helper used by the manual purchase path and by
     * offer acceptance (P4.24, P4.25).
     *
     * Months are added to the original date and the day is cut to the last day of
     * the target month (31 January + 1 month = 28 February, + 2 months = 31 March).
     */
    public static function calculate_due_date( string $base_date, int $offset, string $frequency ): string {
        $base_timestamp = strtotime( $base_date );
        if ( false === $base_timestamp ) {
            return $base_date;
        }

        switch ( $frequency ) {
            case 'daily':
                return gmdate( 'Y-m-d', strtotime( "+{$offset} days", $base_timestamp ) );
            case 'weekly':
                return gmdate( 'Y-m-d', strtotime( "+{$offset} weeks", $base_timestamp ) );
            case 'quarterly':
                return self::add_months_clamped( $base_timestamp, $offset * 3 );
            case 'yearly':
                return self::add_months_clamped( $base_timestamp, $offset * 12 );
            case 'monthly':
            default:
                return self::add_months_clamped( $base_timestamp, $offset );
        }
    }

    private static function add_months_clamped( int $base_timestamp, int $months ): string {
        $year  = (int) gmdate( 'Y', $base_timestamp );
        $month = (int) gmdate( 'n', $base_timestamp );
        $day   = (int) gmdate( 'j', $base_timestamp );

        $total      = ( $month - 1 ) + $months;
        $new_year   = $year + intdiv( $total, 12 );
        $new_month  = ( $total % 12 ) + 1;
        $last_day   = (int) gmdate( 't', gmmktime( 0, 0, 0, $new_month, 1, $new_year ) );

        return sprintf( '%04d-%02d-%02d', $new_year, $new_month, min( $day, $last_day ) );
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

        if ( $wpdb->last_error ) throw new RuntimeException( 'Unable to create installment schedule.' );
    }
}

// P6.09: the injected "Payment frequency" scripts were removed. Every form already has its own frequency select.

/**
 * Reconcile accepted-offer schedules with the stored purchase frequency.
 */
add_action( 'wp_footer', function (): void {
    if ( empty( $_POST['ofp_offer_action'] ) || 'accept' !== sanitize_key( $_POST['ofp_offer_action'] ) ) return;

    $token = sanitize_text_field( wp_unslash( $_GET['offer'] ?? '' ) );
    if ( ! $token ) return;

    global $wpdb;
    $p = $wpdb->prefix;
    $hash = hash( 'sha256', $token );

    $offer = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$p}ofp_property_offers WHERE offer_token_hash = %s LIMIT 1",
        $hash
    ) );
    if ( ! $offer || 'accepted' !== $offer->status ) return;

    $purchase = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$p}ofp_property_purchases WHERE offer_id = %d ORDER BY id DESC LIMIT 1",
        (int) $offer->id
    ) );
    if ( ! $purchase ) return;

    $installments = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$p}ofp_property_installments WHERE purchase_id = %d ORDER BY installment_no ASC",
        (int) $purchase->id
    ) );

    foreach ( $installments as $inst ) {
        if ( (float) $inst->amount_paid > 0 ) return;
    }

    $initial = (float) $purchase->initial_payment;
    $frequency = sanitize_key( $purchase->frequency ?: 'monthly' );
    $base_date = $purchase->first_due_date ?: current_time( 'Y-m-d' );

    foreach ( $installments as $inst ) {
        $number = (int) $inst->installment_no;
        $offset = $initial > 0 ? max( 0, $number - 1 ) : max( 0, $number - 1 );
        $ts = strtotime( $base_date );
        if ( false === $ts ) continue;

        switch ( $frequency ) {
            case 'daily':
                $due = gmdate( 'Y-m-d', strtotime( "+{$offset} days", $ts ) );
                break;
            case 'weekly':
                $due = gmdate( 'Y-m-d', strtotime( "+{$offset} weeks", $ts ) );
                break;
            case 'quarterly':
                $due = gmdate( 'Y-m-d', strtotime( '+' . ( $offset * 3 ) . ' months', $ts ) );
                break;
            case 'yearly':
                $due = gmdate( 'Y-m-d', strtotime( "+{$offset} years", $ts ) );
                break;
            case 'monthly':
            default:
                $due = gmdate( 'Y-m-d', strtotime( "+{$offset} months", $ts ) );
                break;
        }

        $grace = gmdate( 'Y-m-d', strtotime( $due . ' +' . (int) $purchase->grace_period_days . ' days' ) );
        $wpdb->update(
            "{$p}ofp_property_installments",
            [ 'due_date' => $due, 'grace_ends_at' => $grace, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => (int) $inst->id ]
        );
    }
}, 1001 );
