<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Communications {
    const SMS_COST = 6.99;

    public static function init(): void {
        add_action( 'ofp_property_purchase_created', [ __CLASS__, 'purchase_created' ], 10, 4 );
        add_action( 'ofp_property_payment_allocated', [ __CLASS__, 'payment_allocated' ], 10, 2 );
    }

    public static function purchase_created( int $purchase_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT pu.*, pr.title AS property_title, c.sms_provider FROM {$p}ofp_property_purchases pu LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id WHERE pu.id = %d LIMIT 1", $purchase_id ) );
        if ( ! $row ) return;

        $subject = 'Purchase recorded — ' . ( $row->property_title ?: 'Property' );
        $body = sprintf( '<p>Hello %s,</p><p>Your purchase of <strong>%s</strong> has been recorded.</p><p>Total: <strong>₦%s</strong><br>Paid: <strong>₦%s</strong><br>Balance: <strong>₦%s</strong></p>', esc_html( $row->buyer_name ?: 'there' ), esc_html( $row->property_title ?: 'the property' ), number_format( (float) $row->total_price, 2 ), number_format( (float) $row->amount_paid, 2 ), number_format( (float) $row->balance, 2 ) );
        if ( ! empty( $row->buyer_email ) ) OFP_Mailer::send( $row->buyer_email, $row->buyer_name ?: 'there', $subject, $body );

        if ( ! empty( $row->buyer_phone ) && ! empty( $row->client_id ) && ! empty( $row->sms_provider ) && OFP_Credit::has_balance( (int) $row->client_id, 'sms', self::SMS_COST ) ) {
            $sms = new OFP_SMS( $row->sms_provider, (int) $row->client_id );
            $result = $sms->send( $row->buyer_phone, sprintf( 'Purchase recorded for %s. Total N%s. Balance N%s. Ref #%d.', $row->property_title ?: 'property', number_format( (float) $row->total_price, 2 ), number_format( (float) $row->balance, 2 ), $purchase_id ) );
            if ( $result['success'] ) OFP_Credit::deduct( (int) $row->client_id, 'sms', self::SMS_COST );
        }
    }

    public static function payment_allocated( int $payment_id, array $summary = [] ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT py.*, pu.buyer_name, pu.buyer_phone, pu.buyer_email, pu.client_id, pu.amount_paid, pu.balance, c.sms_provider, pr.title AS property_title FROM {$p}ofp_property_payments py INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id WHERE py.id = %d LIMIT 1", $payment_id ) );
        if ( ! $row || 'successful' !== $row->status ) return;

        $subject = 'Payment received — ' . ( $row->property_title ?: 'Property' );
        $body = sprintf( '<p>Hello %s,</p><p>We received your payment of <strong>₦%s</strong> for <strong>%s</strong>.</p><p>Total paid: <strong>₦%s</strong><br>Remaining balance: <strong>₦%s</strong></p>', esc_html( $row->buyer_name ?: 'there' ), number_format( (float) $row->amount, 2 ), esc_html( $row->property_title ?: 'the property' ), number_format( (float) $row->amount_paid, 2 ), number_format( (float) $row->balance, 2 ) );
        if ( ! empty( $row->buyer_email ) ) OFP_Mailer::send( $row->buyer_email, $row->buyer_name ?: 'there', $subject, $body );

        if ( ! empty( $row->buyer_phone ) && ! empty( $row->client_id ) && ! empty( $row->sms_provider ) && OFP_Credit::has_balance( (int) $row->client_id, 'sms', self::SMS_COST ) ) {
            $sms = new OFP_SMS( $row->sms_provider, (int) $row->client_id );
            $result = $sms->send( $row->buyer_phone, sprintf( 'Payment received: N%s for %s. Paid N%s. Balance N%s.', number_format( (float) $row->amount, 2 ), $row->property_title ?: 'property', number_format( (float) $row->amount_paid, 2 ), number_format( (float) $row->balance, 2 ) ) );
            if ( $result['success'] ) OFP_Credit::deduct( (int) $row->client_id, 'sms', self::SMS_COST );
        }

        if ( class_exists( 'OFP_Notification' ) && $row->client_id ) OFP_Notification::create( (int) $row->client_id, 'property_payment_received', 'Property payment received', sprintf( '₦%s received for %s. Remaining balance: ₦%s.', number_format( (float) $row->amount, 2 ), $row->property_title ?: 'property', number_format( (float) $row->balance, 2 ) ) );
    }
}
