<?php
/**
 * Property payment context resolver/handler.
 * Keeps property payments separate from SaaS subscriptions while reusing
 * the existing gateway adapters and webhook flow.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once OFP_PATH . 'includes/class-ofp-property-payment-record.php';
require_once OFP_PATH . 'includes/class-ofp-property-manual-payment.php';

class OFP_Property_Payment_Context {

    public static function generate_reference( int $installment_id ): string {
        return sprintf( 'ofp_property_installment_%d_%s', $installment_id, wp_generate_password( 10, false, false ) );
    }

    public static function is_reference( string $reference ): bool {
        return (bool) preg_match( '/^ofp_property_installment_(\d+)_/', $reference );
    }

    public static function parse_reference( string $reference ): ?array {
        if ( ! preg_match( '/^ofp_property_installment_(\d+)_/', $reference, $matches ) ) return null;
        return [ 'installment_id' => (int) $matches[1] ];
    }

    public static function process_verified_payment( string $reference, float $amount, string $gateway, string $provider_reference = '' ): bool {
        if ( $amount <= 0 || ! self::is_reference( $reference ) ) return false;
        $parsed = self::parse_reference( $reference );
        if ( ! $parsed ) return false;

        global $wpdb;
        $p = $wpdb->prefix;
        $installment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_property_installments WHERE id = %d LIMIT 1", $parsed['installment_id'] ) );
        if ( ! $installment ) return false;
        $purchase = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_property_purchases WHERE id = %d LIMIT 1", (int) $installment->purchase_id ) );
        if ( ! $purchase ) return false;

        if ( $provider_reference ) {
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}ofp_property_payments WHERE gateway = %s AND gateway_reference = %s LIMIT 1", sanitize_key( $gateway ), sanitize_text_field( $provider_reference ) ) );
            if ( $existing ) return true;
        }

        $result = OFP_Property_Payment_Record::create([
            'purchase_id' => (int) $purchase->id,
            'payment_method' => 'checkout',
            'gateway' => $gateway,
            'gateway_reference' => $provider_reference ?: $reference,
            'amount' => $amount,
            'status' => 'successful',
            'payer_name' => $purchase->buyer_name,
            'payer_reference' => $reference,
        ]);

        if ( is_wp_error( $result ) ) {
            error_log( '[OFP_Property_Payment_Context] Could not record property payment for ' . $reference );
            return false;
        }

        $payment_id = (int) $result;
        $allocation = OFP_Property_Commerce::allocate_payment( $payment_id );

        do_action( 'ofp_property_payment_processed', (int) $purchase->id, (int) $installment->id, $amount, $allocation, $gateway, $reference );
        return true;
    }
}
