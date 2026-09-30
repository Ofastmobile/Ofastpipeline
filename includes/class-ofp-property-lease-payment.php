<?php
/** Payment recording and Paystack hand-off for rent cycles. */
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
        if ( ! in_array( $lease->status, [ 'pending_offer', 'active' ], true ) || empty( $lease->accepted_at ) ) return new WP_Error( 'lease_not_payable', 'The tenant must accept an active lease offer before payment can be recorded.' );
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

        $newly_successful = $payment->status !== 'successful';
        if ( $newly_successful ) {
            $wpdb->update( "{$p}ofp_property_lease_payments", [
                'status' => 'successful', 'verified_by' => $verified_by ?: null, 'verified_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
            ], [ 'id' => $payment_id ] );
        }
        $lease = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_property_leases WHERE id = %d LIMIT 1", $payment->lease_id ) );
        if ( ! $lease ) return [ 'success' => false, 'error' => 'Lease not found.' ];
        if ( $lease->status === 'pending_offer' ) {
            $activation = OFP_Property_Rent::activate_lease( (int) $lease->id );
            if ( is_wp_error( $activation ) ) return [ 'success' => false, 'error' => $activation->get_error_message() ];
        }

        // A second call for the same already-successful record is idempotent.
        if ( $newly_successful ) {
            $paid = min( (float) $lease->rent_amount, round( (float) $lease->amount_paid + (float) $payment->amount, 2 ) );
            $wpdb->update( "{$p}ofp_property_leases", [
                'amount_paid' => $paid,
                'balance' => max( 0, round( (float) $lease->rent_amount - $paid, 2 ) ),
                'updated_at' => current_time( 'mysql' ),
            ], [ 'id' => $lease->id ] );
            do_action( 'ofp_property_lease_payment_processed', (int) $lease->id, $payment_id, (float) $payment->amount, (string) $payment->payment_method );
        }
        return [ 'success' => true ];
    }

    /** Landlord-side cash logging is immediately verified and auditable. */
    public static function record_cash_payment( int $client_id, int $lease_id, float $amount, string $reference = '', string $note = '' ) {
        global $wpdb;
        if ( ! OFP_Property_Rent::can_manage( $client_id ) ) return new WP_Error( 'rent_plan_required', 'Rent management is available on the Gold plan.' );
        $lease = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ofp_property_leases WHERE id = %d AND client_id = %d LIMIT 1", $lease_id, $client_id ) );
        if ( ! $lease ) return new WP_Error( 'lease_not_found', 'Lease not found for this client.' );
        return self::create( [
            'lease_id' => $lease_id, 'payment_method' => 'cash', 'amount' => $amount, 'status' => 'successful',
            'payer_reference' => $reference, 'note' => $note,
        ] );
    }

    public static function reference( int $lease_id ): string {
        return 'ofpl-' . $lease_id . '-' . wp_generate_password( 12, false, false );
    }

    public static function is_reference( string $reference ): bool {
        return (bool) preg_match( '/^ofpl-\d+-[A-Za-z0-9]+$/', $reference );
    }

    public static function initiate_checkout( int $lease_id, float $amount ) {
        global $wpdb;
        $p = $wpdb->prefix;
        $lease = $wpdb->get_row( $wpdb->prepare( "SELECT l.*, t.full_name, t.email FROM {$p}ofp_property_leases l INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id WHERE l.id = %d LIMIT 1", $lease_id ) );
        if ( ! $lease || ! $lease->email ) return new WP_Error( 'lease_checkout_email_required', 'The tenant needs an email address for online checkout.' );
        $reference = self::reference( $lease_id );
        $payment_id = self::create( [ 'lease_id' => $lease_id, 'payment_method' => 'checkout', 'gateway' => 'paystack', 'gateway_reference' => $reference, 'amount' => $amount, 'payer_name' => $lease->full_name ] );
        if ( is_wp_error( $payment_id ) ) return $payment_id;
        $gateway = new OFP_Gateway_Paystack();
        if ( ! $gateway->is_configured() ) return new WP_Error( 'paystack_not_configured', 'Online payments are not configured.' );
        $url = $gateway->initiate_transaction( [
            'email' => $lease->email, 'amount' => $amount, 'reference' => $reference, 'client_id' => (int) $lease->client_id,
            'description' => 'Rent payment for lease #' . $lease_id, 'redirect_url' => home_url( '/tenant-pay/' ),
        ] );
        return $url ?: new WP_Error( 'lease_checkout_start_failed', 'Unable to start online checkout.' );
    }

    public static function process_verified_checkout( string $reference, float $amount, string $gateway, string $provider_reference ): bool {
        global $wpdb;
        $payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ofp_property_lease_payments WHERE gateway_reference = %s AND gateway = %s LIMIT 1", $reference, $gateway ) );
        if ( ! $payment || abs( (float) $payment->amount - $amount ) > 0.01 ) return false;
        if ( $payment->status === 'successful' ) return true;
        $wpdb->update( "{$wpdb->prefix}ofp_property_lease_payments", [ 'payer_reference' => $provider_reference ?: $reference, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $payment->id ] );
        return ! empty( self::success( (int) $payment->id )['success'] );
    }

    /** Creates one reusable Paystack dedicated account for a lease cycle. */
    public static function ensure_virtual_account( int $lease_id ): ?object {
        global $wpdb;
        $p = $wpdb->prefix;
        $lease = $wpdb->get_row( $wpdb->prepare( "SELECT l.*, t.full_name, t.email, t.phone FROM {$p}ofp_property_leases l INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id WHERE l.id = %d LIMIT 1", $lease_id ) );
        if ( ! $lease || ! $lease->email ) return null;
        if ( $lease->va_customer_code && $lease->va_account_number ) {
            return (object) [ 'account_number' => $lease->va_account_number, 'bank_name' => $lease->va_bank_name, 'bank_code' => $lease->va_bank_code, 'customer_code' => $lease->va_customer_code ];
        }
        $gateway = new OFP_Gateway_Paystack();
        if ( ! $gateway->is_configured() ) return null;
        $parts = preg_split( '/\s+/', trim( $lease->full_name ), 2 );
        $account = $gateway->create_virtual_account( [
            'email' => $lease->email, 'name' => $lease->full_name, 'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'phone' => $lease->phone,
        ], [ 'ofp_lease_id' => $lease_id ] );
        if ( ! $account ) return null;
        $wpdb->update( "{$p}ofp_property_leases", [
            'va_account_number' => $account->account_number, 'va_bank_name' => $account->bank_name, 'va_bank_code' => $account->bank_code,
            'va_customer_code' => $account->customer_code, 'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $lease_id ] );
        return $account;
    }

    public static function process_verified_virtual_account_payment( string $customer_code, float $amount, string $gateway, string $provider_reference ): bool {
        global $wpdb;
        $lease = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_property_leases WHERE va_customer_code = %s AND status IN ('pending_offer','active') AND accepted_at IS NOT NULL LIMIT 1",
            $customer_code
        ) );
        if ( ! $lease ) return false;
        $payment_id = self::create( [
            'lease_id' => (int) $lease->id, 'payment_method' => 'virtual_account', 'gateway' => $gateway,
            'gateway_reference' => $provider_reference, 'amount' => $amount, 'status' => 'successful', 'payer_reference' => $provider_reference,
        ] );
        return ! is_wp_error( $payment_id );
    }
}
