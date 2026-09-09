<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Comms_Bridge {
    const SMS_COST = 6.99;

    public static function init(): void {
        add_action( 'ofp_property_purchase_created', [ __CLASS__, 'purchase_created' ], 10, 4 );
        add_action( 'ofp_property_payment_processed', [ __CLASS__, 'payment_processed' ], 10, 6 );
        add_action( 'ofp_property_manual_payment_submitted', [ __CLASS__, 'manual_payment_submitted' ], 10, 2 );
    }

    public static function purchase_created( int $purchase_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT pu.*, pr.title AS property_title, c.sms_provider FROM {$p}ofp_property_purchases pu LEFT JOIN {$p}ofp_properties pr ON pr.id=pu.property_id LEFT JOIN {$p}ofp_clients c ON c.id=pu.client_id WHERE pu.id=%d LIMIT 1", $purchase_id ) );
        if ( ! $row ) return;

        if ( ! empty( $row->buyer_email ) ) {
            OFP_Mailer::send(
                $row->buyer_email,
                $row->buyer_name ?: 'there',
                'Purchase recorded — ' . ( $row->property_title ?: 'Property' ),
                sprintf( '<p>Hello %s,</p><p>Your purchase of <strong>%s</strong> has been recorded.</p><p>Total: <strong>₦%s</strong><br>Balance: <strong>₦%s</strong></p>', esc_html( $row->buyer_name ?: 'there' ), esc_html( $row->property_title ?: 'the property' ), number_format( (float) $row->total_price, 2 ), number_format( (float) $row->balance, 2 ) )
            );
        }

        self::sms( $row, sprintf( 'Purchase recorded for %s. Total N%s. Balance N%s. Ref #%d.', $row->property_title ?: 'property', number_format( (float) $row->total_price, 2 ), number_format( (float) $row->balance, 2 ), $purchase_id ) );

        if ( class_exists( 'OFP_Notification' ) && $row->client_id ) {
            OFP_Notification::create( (int) $row->client_id, 'property_purchase_created', 'Property purchase created', sprintf( '%s has accepted an installment purchase for %s.', $row->buyer_name ?: 'A buyer', $row->property_title ?: 'a property' ) );
        }
    }

    public static function payment_processed( int $purchase_id, int $installment_id, float $amount, array $allocation, string $gateway, string $reference ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT py.*, pu.buyer_name, pu.buyer_phone, pu.buyer_email, pu.client_id, pu.amount_paid, pu.balance, c.sms_provider, pr.title AS property_title FROM {$p}ofp_property_payments py INNER JOIN {$p}ofp_property_purchases pu ON pu.id=py.purchase_id LEFT JOIN {$p}ofp_clients c ON c.id=pu.client_id LEFT JOIN {$p}ofp_properties pr ON pr.id=pu.property_id WHERE py.purchase_id=%d AND py.status='successful' ORDER BY py.id DESC LIMIT 1", $purchase_id ) );
        if ( ! $row ) return;

        $reference = $reference ?: ( $row->gateway_reference ?: 'PAY-' . $row->id );
        if ( ! empty( $row->buyer_email ) ) {
            OFP_Mailer::send(
                $row->buyer_email,
                $row->buyer_name ?: 'there',
                'Payment received — ' . ( $row->property_title ?: 'Property' ),
                sprintf( '<p>Hello %s,</p><p>We received <strong>₦%s</strong> for <strong>%s</strong>.</p><p>Total paid: <strong>₦%s</strong><br>Remaining balance: <strong>₦%s</strong></p><p>Reference: <strong>%s</strong></p>', esc_html( $row->buyer_name ?: 'there' ), number_format( $amount, 2 ), esc_html( $row->property_title ?: 'the property' ), number_format( (float) $row->amount_paid, 2 ), number_format( (float) $row->balance, 2 ), esc_html( $reference ) )
            );
        }

        self::sms( $row, sprintf( 'Payment received: N%s for %s. Paid N%s. Balance N%s. Ref %s.', number_format( $amount, 2 ), $row->property_title ?: 'property', number_format( (float) $row->amount_paid, 2 ), number_format( (float) $row->balance, 2 ), $reference ) );

        if ( class_exists( 'OFP_Notification' ) && $row->client_id ) {
            OFP_Notification::create( (int) $row->client_id, 'property_payment_received', 'Property payment received', sprintf( '₦%s received for %s. Remaining balance: ₦%s.', number_format( $amount, 2 ), $row->property_title ?: 'property', number_format( (float) $row->balance, 2 ) ) );
        }
    }

    public static function manual_payment_submitted( int $payment_id, int $purchase_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT py.amount, pu.client_id, pu.buyer_name, pr.title AS property_title FROM {$p}ofp_property_payments py INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id WHERE py.id = %d AND py.purchase_id = %d LIMIT 1", $payment_id, $purchase_id ) );
        if ( $row && $row->client_id && class_exists( 'OFP_Notification' ) ) {
            OFP_Notification::create( (int) $row->client_id, 'property_manual_payment_submitted', 'Manual property payment awaiting review', sprintf( '%s submitted a receipt for ₦%s toward %s.', $row->buyer_name ?: 'A buyer', number_format( (float) $row->amount, 2 ), $row->property_title ?: 'a property' ) );
        }
    }

    private static function sms( object $row, string $message ): void {
        if ( empty( $row->buyer_phone ) || empty( $row->client_id ) || empty( $row->sms_provider ) ) return;
        $client_id = (int) $row->client_id;
        if ( ! OFP_Credit::has_balance( $client_id, 'sms', self::SMS_COST ) ) return;
        $sms = new OFP_SMS( $row->sms_provider, $client_id );
        $result = $sms->send( $row->buyer_phone, $message );
        if ( ! empty( $result['success'] ) ) OFP_Credit::deduct( $client_id, 'sms', self::SMS_COST );
    }
}
