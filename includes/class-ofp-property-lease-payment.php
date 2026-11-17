<?php
/** Payment recording for rent cycles. Tenants pay the owner's own bank account. */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Lease_Payment {

    public static function create( array $data ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $lease_id = absint( $data['lease_id'] ?? 0 );
        $amount = round( max( 0, (float) ( $data['amount'] ?? 0 ) ), 2 );
        $method = sanitize_key( $data['payment_method'] ?? 'manual' );
        $status = sanitize_key( $data['status'] ?? 'pending_verification' );
        if ( ! $lease_id || $amount <= 0 ) return new WP_Error( 'invalid_lease_payment', 'Lease and a positive payment amount are required.' );
        if ( ! in_array( $method, [ 'cash', 'manual', 'checkout', 'virtual_account' ], true ) ) return new WP_Error( 'invalid_lease_payment_method', 'Invalid lease payment method.' );
        if ( ! in_array( $status, [ 'pending_verification', 'successful', 'failed', 'rejected', 'cancelled' ], true ) ) return new WP_Error( 'invalid_lease_payment_status', 'Invalid lease payment status.' );

        $lease = $wpdb->get_row( $wpdb->prepare( "SELECT l.*, t.full_name, t.phone FROM {$p}ofp_property_leases l INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id WHERE l.id = %d LIMIT 1", $lease_id ) );
        if ( ! $lease ) return new WP_Error( 'lease_not_found', 'Lease not found.' );
        // P4.23: an expired lease can still be paid while a balance is owed.
        $payable_status = in_array( $lease->status, [ 'pending_offer', 'active' ], true )
            || ( 'expired' === $lease->status && (float) $lease->balance > 0 );
        if ( ! $payable_status || empty( $lease->accepted_at ) ) return new WP_Error( 'lease_not_payable', 'The tenant must accept an active lease offer before payment can be recorded.' );
        if ( $amount > (float) $lease->balance + 0.00001 ) return new WP_Error( 'lease_payment_exceeds_balance', 'Payment cannot exceed the current cycle balance.' );

        $gateway = ! empty( $data['gateway'] ) ? sanitize_key( $data['gateway'] ) : null;
        $gateway_reference = ! empty( $data['gateway_reference'] ) ? sanitize_text_field( $data['gateway_reference'] ) : null;
        if ( $gateway && $gateway_reference ) {
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}ofp_property_lease_payments WHERE gateway = %s AND gateway_reference = %s LIMIT 1", $gateway, $gateway_reference ) );
            if ( $existing ) return (int) $existing;
        }

        $ok = $wpdb->insert( "{$p}ofp_property_lease_payments", [
            'lease_id'          => $lease_id,
            'payment_method'    => $method,
            'gateway'           => $gateway,
            'gateway_reference' => $gateway_reference,
            'amount'            => $amount,
            'status'            => $status,
            'payer_name'        => ! empty( $data['payer_name'] ) ? sanitize_text_field( $data['payer_name'] ) : $lease->full_name,
            'payer_reference'   => ! empty( $data['payer_reference'] ) ? sanitize_text_field( $data['payer_reference'] ) : null,
            'note'              => ! empty( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : null,
            'created_at'        => current_time( 'mysql' ),
            'updated_at'        => current_time( 'mysql' ),
        ] );
        if ( ! $ok ) return new WP_Error( 'lease_payment_create_failed', 'Unable to create lease payment record.' );
        $payment_id = (int) $wpdb->insert_id;
        if ( $status === 'successful' ) {
            $result = self::success( $payment_id );
            if ( empty( $result['success'] ) ) return new WP_Error( 'lease_payment_apply_failed', $result['error'] ?? 'Unable to apply lease payment.' );
        }
        return $payment_id;
    }

    public static function success( int $payment_id, int $verified_by = 0 ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_property_lease_payments WHERE id = %d LIMIT 1", $payment_id ) );
        if ( ! $payment ) return [ 'success' => false, 'error' => 'Lease payment not found.' ];
        if ( in_array( $payment->status, [ 'rejected', 'cancelled', 'failed' ], true ) ) return [ 'success' => false, 'error' => 'This payment cannot be verified.' ];

        $lease = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_property_leases WHERE id = %d LIMIT 1", $payment->lease_id ) );
        if ( ! $lease ) return [ 'success' => false, 'error' => 'Lease not found.' ];

        // P4.22: refuse a payment that is more than the balance left on the lease.
        // Nothing is lost: the payment stays pending so the owner can reject it.
        $over_message = 'This payment of NGN ' . number_format( (float) $payment->amount, 2 ) . ' is more than the balance left on the lease (NGN ' . number_format( (float) $lease->balance, 2 ) . '). Reject it, or ask the tenant to send the correct amount.';
        if ( 'successful' !== $payment->status && (float) $payment->amount > (float) $lease->balance + 0.01 ) {
            return [ 'success' => false, 'error' => $over_message ];
        }

        // P4.21: claim the payment with one conditional UPDATE. Only the request that
        // really changes the row counts as newly successful, so receipt, SMS and
        // email events fire once and the amount is added once.
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}ofp_property_lease_payments
             SET status = 'successful', verified_by = NULLIF(%d, 0), verified_at = %s, updated_at = %s
             WHERE id = %d AND status NOT IN ('successful', 'rejected', 'cancelled', 'failed')
               AND amount <= ( SELECT balance + 0.01 FROM {$p}ofp_property_leases WHERE id = %d )",
            $verified_by,
            current_time( 'mysql' ),
            current_time( 'mysql' ),
            $payment_id,
            (int) $payment->lease_id
        ) );
        $newly_successful = 1 === (int) $claimed;
        if ( ! $newly_successful ) {
            // Not claimed: either another request already approved it, or the balance changed just now.
            $now_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}ofp_property_lease_payments WHERE id = %d", $payment_id ) );
            if ( 'successful' !== $now_status ) {
                return [ 'success' => false, 'error' => $over_message ];
            }
        }
        if ( $lease->status === 'pending_offer' ) {
            $activation = OFP_Property_Rent::activate_lease( (int) $lease->id );
            if ( is_wp_error( $activation ) ) return [ 'success' => false, 'error' => $activation->get_error_message() ];
        }

        // A second call for the same already-successful record is idempotent.
        if ( $newly_successful ) {
            // One query adds to the amount already in the database, so two payments
            // approved together are both counted (P4.21).
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}ofp_property_leases
                 SET balance     = GREATEST( 0, ROUND( rent_amount - LEAST( rent_amount, ROUND( amount_paid + %f, 2 ) ), 2 ) ),
                     amount_paid = LEAST( rent_amount, ROUND( amount_paid + %f, 2 ) ),
                     updated_at  = %s
                 WHERE id = %d",
                (float) $payment->amount,
                (float) $payment->amount,
                current_time( 'mysql' ),
                (int) $lease->id
            ) );
            do_action( 'ofp_property_lease_payment_processed', (int) $lease->id, $payment_id, (float) $payment->amount, (string) $payment->payment_method );
        }
        return [ 'success' => true ];
    }

    /** Landlord-side cash logging is immediately verified and auditable. */
    public static function record_cash_payment( int $client_id, int $lease_id, float $amount, string $reference = '', string $note = '', string $payer_name = '' ) {
        global $wpdb;
        if ( ! OFP_Property_Rent::can_manage( $client_id ) ) return new WP_Error( 'rent_plan_required', 'Rent management is available on the Gold plan.' );
        $lease = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ofp_property_leases WHERE id = %d AND client_id = %d LIMIT 1", $lease_id, $client_id ) );
        if ( ! $lease ) return new WP_Error( 'lease_not_found', 'Lease not found for this client.' );
        return self::create( [
            'lease_id' => $lease_id, 'payment_method' => 'cash', 'amount' => $amount, 'status' => 'successful',
            'payer_name' => $payer_name ?: null,
            'payer_reference' => $reference, 'note' => $note,
        ] );
    }

}
