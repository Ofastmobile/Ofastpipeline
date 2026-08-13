<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Payment_Record {
    public static function create( array $data ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $purchase_id = absint( $data['purchase_id'] ?? 0 );
        $amount = max( 0, (float) ( $data['amount'] ?? 0 ) );
        $method = sanitize_key( $data['payment_method'] ?? 'manual' );
        $status = sanitize_key( $data['status'] ?? 'pending_verification' );
        if ( $purchase_id < 1 || $amount <= 0 ) return new WP_Error( 'invalid_payment', 'Purchase and positive payment amount are required.' );
        if ( ! in_array( $method, [ 'manual', 'checkout', 'virtual_account' ], true ) ) return new WP_Error( 'invalid_payment_method', 'Invalid payment method.' );
        if ( ! in_array( $status, [ 'pending_verification', 'successful', 'failed', 'rejected', 'cancelled' ], true ) ) return new WP_Error( 'invalid_payment_status', 'Invalid payment status.' );
        $purchase = $wpdb->get_row( $wpdb->prepare( "SELECT id, buyer_name, status FROM {$p}ofp_property_purchases WHERE id = %d LIMIT 1", $purchase_id ) );
        if ( ! $purchase ) return new WP_Error( 'purchase_not_found', 'Purchase not found.' );
        if ( 'completed' === $purchase->status ) return new WP_Error( 'purchase_completed', 'This purchase is already fully paid.' );
        $gateway = ! empty( $data['gateway'] ) ? sanitize_key( $data['gateway'] ) : null;
        $gateway_reference = ! empty( $data['gateway_reference'] ) ? sanitize_text_field( $data['gateway_reference'] ) : null;
        if ( $gateway && $gateway_reference ) {
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}ofp_property_payments WHERE gateway = %s AND gateway_reference = %s LIMIT 1", $gateway, $gateway_reference ) );
            if ( $existing ) return (int) $existing;
        }
        $ok = $wpdb->insert( "{$p}ofp_property_payments", [
            'purchase_id' => $purchase_id,
            'payment_method' => $method,
            'gateway' => $gateway,
            'gateway_reference' => $gateway_reference,
            'amount' => $amount,
            'status' => $status,
            'payer_name' => ! empty( $data['payer_name'] ) ? sanitize_text_field( $data['payer_name'] ) : $purchase->buyer_name,
            'payer_reference' => ! empty( $data['payer_reference'] ) ? sanitize_text_field( $data['payer_reference'] ) : null,
            'note' => ! empty( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : null,
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ] );
        if ( ! $ok ) return new WP_Error( 'payment_record_failed', 'Unable to create payment record.' );
        $id = (int) $wpdb->insert_id;
        if ( 'successful' === $status ) self::success( $id );
        return $id;
    }

    public static function success( int $payment_id, int $verified_by = 0 ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_property_payments WHERE id = %d LIMIT 1", $payment_id ) );
        if ( ! $payment ) return [ 'success' => false, 'error' => 'Payment not found.' ];
        if ( 'successful' !== $payment->status ) {
            $wpdb->update( "{$p}ofp_property_payments", [ 'status' => 'successful', 'verified_by' => $verified_by ?: null, 'verified_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $payment_id ] );
        }
        $allocation = OFP_Property_Commerce::allocate_payment( $payment_id );
        return [ 'success' => true, 'allocation' => $allocation ];
    }

    public static function reject( int $payment_id, int $verified_by = 0, string $note = '' ): bool {
        global $wpdb;
        return false !== $wpdb->update( $wpdb->prefix . 'ofp_property_payments', [ 'status' => 'rejected', 'verified_by' => $verified_by ?: null, 'verified_at' => current_time( 'mysql' ), 'note' => sanitize_textarea_field( $note ), 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $payment_id, 'status' => 'pending_verification' ] );
    }
}
