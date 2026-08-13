<?php
/**
 * Property payment context resolver/handler.
 *
 * Keeps property purchase/installment payments separate from SaaS
 * subscriptions while reusing the existing gateway adapters and webhooks.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once OFP_PATH . 'includes/class-ofp-property-comms-bridge.php';

class OFP_Property_Payment_Context {

    public static function generate_reference( int $installment_id ): string {
        return sprintf( 'ofp_property_installment_%d_%s', $installment_id, wp_generate_password( 10, false, false ) );
    }

    public static function is_reference( string $reference ): bool {
        return (bool) preg_match( '/^ofp_property_installment_(\d+)_/', $reference );
    }

    public static function parse_reference( string $reference ): ?array {
        if ( ! preg_match( '/^ofp_property_installment_(\d+)_/', $reference, $matches ) ) {
            return null;
        }
        return [ 'installment_id' => (int) $matches[1] ];
    }

    public static function process_verified_payment( string $reference, float $amount, string $gateway, string $provider_reference = '' ): bool {
        if ( $amount <= 0 || ! self::is_reference( $reference ) ) {
            return false;
        }

        $parsed = self::parse_reference( $reference );
        if ( ! $parsed ) {
            return false;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $installment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_installments WHERE id = %d LIMIT 1",
            $parsed['installment_id']
        ) );
        if ( ! $installment ) {
            error_log( '[OFP_Property_Payment_Context] Installment not found for reference ' . $reference );
            return false;
        }

        $purchase = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_purchases WHERE id = %d LIMIT 1",
            (int) $installment->purchase_id
        ) );
        if ( ! $purchase ) {
            error_log( '[OFP_Property_Payment_Context] Purchase not found for reference ' . $reference );
            return false;
        }

        if ( $provider_reference !== '' ) {
            $existing_provider = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}ofp_property_payments WHERE gateway = %s AND gateway_reference = %s LIMIT 1",
                sanitize_key( $gateway ),
                sanitize_text_field( $provider_reference )
            ) );
            if ( $existing_provider ) {
                return true;
            }
        }

        $existing_reference = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}ofp_property_payments WHERE gateway = %s AND payer_reference = %s LIMIT 1",
            sanitize_key( $gateway ),
            sanitize_text_field( $reference )
        ) );
        if ( $existing_reference ) {
            return true;
        }

        $inserted = $wpdb->insert(
            "{$p}ofp_property_payments",
            [
                'purchase_id'       => (int) $purchase->id,
                'payment_method'    => 'checkout',
                'gateway'           => sanitize_key( $gateway ),
                'gateway_reference' => sanitize_text_field( $provider_reference ?: $reference ),
                'amount'            => $amount,
                'status'            => 'successful',
                'payer_name'        => sanitize_text_field( $purchase->buyer_name ),
                'payer_reference'   => sanitize_text_field( $reference ),
                'created_at'        => current_time( 'mysql' ),
                'updated_at'        => current_time( 'mysql' ),
            ]
        );

        if ( ! $inserted ) {
            error_log( '[OFP_Property_Payment_Context] Could not record property payment for ' . $reference );
            return false;
        }

        $payment_id = (int) $wpdb->insert_id;
        $allocation = OFP_Property_Commerce::allocate_payment( $payment_id );

        do_action(
            'ofp_property_payment_processed',
            (int) $purchase->id,
            (int) $installment->id,
            $amount,
            $allocation,
            $gateway,
            $reference
        );

        return true;
    }
}

OFP_Property_Comms_Bridge::init();
