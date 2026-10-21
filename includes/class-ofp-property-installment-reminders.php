<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Installment_Reminders {

    const SMS_COST = 6.99;

    public static function run_daily(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $today = current_time( 'Y-m-d' );

        $rows = $wpdb->get_results(
            "SELECT i.*, pu.buyer_name, pu.buyer_phone, pu.buyer_email, pu.client_id,
                    pr.title AS property_title, c.sms_provider
             FROM {$p}ofp_property_installments i
             INNER JOIN {$p}ofp_property_purchases pu ON pu.id = i.purchase_id
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
             WHERE pu.status = 'active'
               AND i.status IN ('scheduled','due','partially_paid','overdue')
               AND i.amount_paid < i.amount_due
             ORDER BY i.due_date ASC"
        );

        foreach ( $rows as $row ) {
            $due_ts = strtotime( $row->due_date . ' 00:00:00' );
            if ( false === $due_ts ) continue;
            $today_ts = strtotime( $today . ' 00:00:00' );
            $days = (int) floor( ( $due_ts - $today_ts ) / DAY_IN_SECONDS );
            $type = null;

            if ( 0 === $days ) {
                $type = 'due_today';
            } elseif ( in_array( $days, [ 7, 3, 1 ], true ) ) {
                $type = 'due_' . $days . '_days';
            } elseif ( -1 === $days ) {
                $type = 'overdue';
            }

            if ( ! $type ) continue;

            $last = $row->last_reminder_at ? strtotime( $row->last_reminder_at ) : 0;
            if ( $last && date( 'Y-m-d', $last ) === $today ) continue;

            $amount = (float) $row->amount_due - (float) $row->amount_paid;
            $date_text = wp_date( 'M j, Y', $due_ts );
            if ( 'overdue' === $type ) {
                $message = sprintf( 'Your installment of ₦%s for %s is overdue. Due date was %s. Outstanding on this installment: ₦%s.', number_format( (float) $row->amount_due, 2 ), $row->property_title ?: 'your property', $date_text, number_format( $amount, 2 ) );
                $subject = 'Property installment overdue';
            } elseif ( 'due_today' === $type ) {
                $message = sprintf( 'Reminder: your property installment of ₦%s for %s is due today. Outstanding on this installment: ₦%s.', number_format( (float) $row->amount_due, 2 ), $row->property_title ?: 'your property', number_format( $amount, 2 ) );
                $subject = 'Property installment due today';
            } else {
                $message = sprintf( 'Reminder: your property installment of ₦%s for %s is due on %s. Outstanding on this installment: ₦%s.', number_format( (float) $row->amount_due, 2 ), $row->property_title ?: 'your property', $date_text, number_format( $amount, 2 ) );
                $subject = 'Property installment payment reminder';
            }

            if ( ! empty( $row->buyer_email ) ) {
                $payment_link = class_exists( 'OFP_Property_Checkout' )
                    ? OFP_Property_Checkout::payment_link( (int) $row->purchase_id )
                    : OFP_Property_Manual_Payment::payment_link( (int) $row->purchase_id );
                $email_body = sprintf(
                    '<p>Hello %s,</p><p>%s</p><p><a href="%s" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;">View payment options</a></p><p>You can pay securely online when available, or use the bank-transfer instructions on the payment page. If you have already paid, please disregard this reminder.</p>',
                    esc_html( $row->buyer_name ?: 'there' ),
                    esc_html( $message ),
                    esc_url( $payment_link )
                );
                OFP_Mailer::send( $row->buyer_email, $row->buyer_name ?: 'there', $subject, $email_body );
            }

            if ( ! empty( $row->sms_provider ) && ! empty( $row->buyer_phone ) && ! empty( $row->client_id ) && class_exists( 'OFP_Credit' ) && OFP_Credit::has_balance( (int) $row->client_id, 'sms', self::SMS_COST ) ) {
                $sms = new OFP_SMS( $row->sms_provider, (int) $row->client_id );
                $sent = $sms->send( $row->buyer_phone, $message );
                if ( ! empty( $sent['success'] ) ) OFP_Credit::deduct( (int) $row->client_id, 'sms', self::SMS_COST );
            }

            // Due day only: also place a short voice call, if the client switched it on.
            if ( 'due_today' === $type && class_exists( 'OFP_Voice_Notice' ) && ! empty( $row->buyer_phone ) && (int) $row->client_id ) {
                OFP_Voice_Notice::queue_reminder(
                    'installment',
                    (int) $row->client_id,
                    (string) $row->buyer_phone,
                    [
                        'name'     => $row->buyer_name,
                        'property' => $row->property_title,
                        'amount'   => $amount,
                        'due_date' => wp_date( 'F j', $due_ts ),
                    ],
                    'installment:' . (int) $row->id . ':' . $row->due_date
                );
            }

            if ( (int) $row->client_id && class_exists( 'OFP_Notification' ) ) {
                OFP_Notification::create( (int) $row->client_id, 'property_installment_reminder', $subject, sprintf( 'Buyer %s has an installment reminder for %s: ₦%s due %s.', $row->buyer_name, $row->property_title ?: 'property', number_format( (float) $row->amount_due, 2 ), $date_text ) );
            }

            $wpdb->update(
                "{$p}ofp_property_installments",
                [ 'last_reminder_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $row->id ]
            );

            if ( $days < 0 && 'overdue' !== $row->status ) {
                $wpdb->update( "{$p}ofp_property_installments", [ 'status' => 'overdue', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => (int) $row->id ] );
            }
        }
    }
}
