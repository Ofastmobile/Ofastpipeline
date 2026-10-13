<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Comms_Bridge {
    const SMS_COST = 6.99;

    public static function init(): void {
        add_action( 'ofp_property_payment_rejected', [ __CLASS__, 'payment_rejected' ], 10, 2 );
        add_action( 'ofp_property_purchase_created', [ __CLASS__, 'purchase_created' ], 10, 4 );
        add_action( 'ofp_property_payment_processed', [ __CLASS__, 'payment_processed' ], 10, 6 );
        add_action( 'ofp_property_manual_payment_submitted', [ __CLASS__, 'manual_payment_submitted' ], 10, 2 );
    }

    public static function purchase_created( int $purchase_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT pu.*, pr.title AS property_title, c.sms_provider FROM {$p}ofp_property_purchases pu LEFT JOIN {$p}ofp_properties pr ON pr.id=pu.property_id LEFT JOIN {$p}ofp_clients c ON c.id=pu.client_id WHERE pu.id=%d LIMIT 1", $purchase_id ) );
        if ( ! $row ) return;

        $payment_link = class_exists( 'OFP_Property_Checkout' ) ? OFP_Property_Checkout::payment_link( $purchase_id ) : '';

        // Give the buyer a dedicated account number for this purchase, so
        // any installment (or the full balance) can be paid by bank
        // transfer, matched back automatically via webhook.
        $va = ( class_exists( 'OFP_Property_Commerce' ) && OFP_Bank_Account::online_payments_enabled() ) ? OFP_Property_Commerce::ensure_virtual_account( $purchase_id ) : null;

        $va_html = $va ? sprintf( '<p>Or pay by bank transfer, anytime, any installment:<br>Account Number: <strong>%s</strong><br>Bank: <strong>%s</strong></p>', esc_html( $va->account_number ), esc_html( $va->bank_name ) ) : '';
        $va_sms  = $va ? sprintf( ' Or transfer to %s (%s), any installment.', $va->account_number, $va->bank_name ) : '';

        // Online payments are off: show the owner's own bank account instead.
        if ( ! OFP_Bank_Account::online_payments_enabled() ) {
            $acct = OFP_Bank_Account::for_purchase( $row );
            $ref  = OFP_Bank_Account::purchase_reference( $purchase_id );
            $va_html = $acct ? OFP_Bank_Account::email_block( $acct, $ref ) : '';
            $va_sms  = $acct ? sprintf( ' Transfer to %s %s (%s). Note: %s.', $acct->bank_name, $acct->account_number, $acct->account_name, $ref ) : '';
        }

        if ( ! empty( $row->buyer_email ) ) {
            OFP_Mailer::send(
                $row->buyer_email,
                $row->buyer_name ?: 'there',
                'Purchase recorded — ' . ( $row->property_title ?: 'Property' ),
                sprintf( '<p>Hello %s,</p><p>Your purchase of <strong>%s</strong> has been recorded.</p><p>Total: <strong>₦%s</strong><br>Balance: <strong>₦%s</strong></p>%s%s', esc_html( $row->buyer_name ?: 'there' ), esc_html( $row->property_title ?: 'the property' ), number_format( (float) $row->total_price, 2 ), number_format( (float) $row->balance, 2 ), $payment_link ? sprintf( '<p><a href="%s" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;">Pay Now</a></p>', esc_url( $payment_link ) ) : '', $va_html )
            );
        }

        self::sms( $row, sprintf( 'Purchase recorded for %s. Total N%s. Balance N%s. Pay: %s%s', $row->property_title ?: 'property', number_format( (float) $row->total_price, 2 ), number_format( (float) $row->balance, 2 ), $payment_link ?: ( 'Ref #' . $purchase_id ), $va_sms ) );


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
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT py.amount, py.payer_name, py.payer_reference, pu.client_id, pu.buyer_name, pu.buyer_phone, pu.buyer_email,
                    pr.title AS property_title, c.email AS owner_email, c.owner_name, c.phone AS owner_phone, c.sms_provider
             FROM {$p}ofp_property_payments py
             INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
             WHERE py.id = %d LIMIT 1",
            $payment_id
        ) );
        if ( ! $row ) return;

        $title  = $row->property_title ?: 'the property';
        $amount = number_format( (float) $row->amount, 2 );
        $who    = $row->payer_name ?: ( $row->buyer_name ?: 'A buyer' );

        if ( $row->client_id && class_exists( 'OFP_Notification' ) ) {
            OFP_Notification::create( (int) $row->client_id, 'property_manual_payment_submitted', 'Payment waiting for your approval', sprintf( '%s says they paid ₦%s for %s. Check your account, then approve or reject.', $who, $amount, $title ) );
        }

        // Tell the owner (email and SMS) so a payment never waits unseen.
        if ( ! empty( $row->owner_email ) ) {
            OFP_Mailer::send(
                $row->owner_email,
                $row->owner_name ?: 'there',
                'Payment waiting for your approval — ' . $title,
                sprintf(
                    '<p>Hello %s,</p><p><strong>%s</strong> says they paid <strong>₦%s</strong> for <strong>%s</strong>.</p><p>Reference: <strong>%s</strong></p><p>Check your bank account. If the money is there, approve the payment. If not, reject it with a reason.</p><p><a href="%s">Open Payment Records</a></p>',
                    esc_html( $row->owner_name ?: 'there' ), esc_html( $who ), esc_html( $amount ), esc_html( $title ), esc_html( $row->payer_reference ?: 'none given' ), esc_url( home_url( '/property-payments/' ) )
                )
            );
        }
        self::sms_to( $row, (string) $row->owner_phone, sprintf( 'Payment to approve: %s says N%s paid for %s. Check your bank, then approve in Payment Records.', $who, $amount, $title ) );

        // Tell the buyer it was received and is waiting.
        if ( ! empty( $row->buyer_email ) ) {
            OFP_Mailer::send(
                $row->buyer_email,
                $row->buyer_name ?: 'there',
                'We received your payment notice — ' . $title,
                sprintf( '<p>Hello %s,</p><p>We received your notice of <strong>₦%s</strong> for <strong>%s</strong>.</p><p>It is <strong>pending approval</strong>. The owner will confirm it after checking their bank account. You will get a message when it is approved.</p>', esc_html( $row->buyer_name ?: 'there' ), esc_html( $amount ), esc_html( $title ) ) . '<p><a href="' . esc_url( OFP_Property_My_Payments::url() ) . '">See all your payments and receipts</a></p>'
            );
        }
        self::sms_to( $row, (string) $row->buyer_phone, sprintf( 'We got your payment notice of N%s for %s. It is pending approval. You will be told when it is confirmed.', $amount, $title ) );
    }

    public static function payment_rejected( int $payment_id, string $note = '' ): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT py.amount, py.purchase_id, pu.buyer_name, pu.buyer_phone, pu.buyer_email, pu.client_id, pr.title AS property_title, c.sms_provider
             FROM {$p}ofp_property_payments py
             INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
             WHERE py.id = %d LIMIT 1",
            $payment_id
        ) );
        if ( ! $row ) return;

        $title  = $row->property_title ?: 'the property';
        $amount = number_format( (float) $row->amount, 2 );
        $reason = $note !== '' ? $note : 'The owner could not confirm it.';
        $link   = class_exists( 'OFP_Property_Manual_Payment' ) ? OFP_Property_Manual_Payment::payment_link( (int) $row->purchase_id ) : '';

        if ( ! empty( $row->buyer_email ) ) {
            OFP_Mailer::send(
                $row->buyer_email,
                $row->buyer_name ?: 'there',
                'Payment not confirmed — ' . $title,
                sprintf( '<p>Hello %s,</p><p>Your payment notice of <strong>₦%s</strong> for <strong>%s</strong> was <strong>not confirmed</strong>.</p><p>Reason: %s</p><p>If you did pay, send the notice again with the right details and proof: <a href="%s">Submit again</a></p>', esc_html( $row->buyer_name ?: 'there' ), esc_html( $amount ), esc_html( $title ), esc_html( $reason ), esc_url( $link ) )
            );
        }
        self::sms_to( $row, (string) $row->buyer_phone, sprintf( 'Your payment notice of N%s for %s was not confirmed. Reason: %s. You can submit again: %s', $amount, $title, $reason, $link ) );
    }

    private static function sms_to( object $row, string $phone, string $message ): void {
        if ( $phone === '' || empty( $row->client_id ) || empty( $row->sms_provider ) ) return;
        $client_id = (int) $row->client_id;
        if ( ! OFP_Credit::has_balance( $client_id, 'sms', self::SMS_COST ) ) return;
        $sms    = new OFP_SMS( $row->sms_provider, $client_id );
        $result = $sms->send( $phone, $message );
        if ( ! empty( $result['success'] ) ) OFP_Credit::deduct( $client_id, 'sms', self::SMS_COST );
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
