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

            if ( in_array( $days, [ 7, 3, 1 ], true ) ) {
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
            } else {
                $message = sprintf( 'Reminder: your property installment of ₦%s for %s is due on %s. Outstanding on this installment: ₦%s.', number_format( (float) $row->amount_due, 2 ), $row->property_title ?: 'your property', $date_text, number_format( $amount, 2 ) );
                $subject = 'Property installment payment reminder';
            }

            if ( ! empty( $row->buyer_email ) ) {
                OFP_Mailer::send( $row->buyer_email, $row->buyer_name ?: 'there', $subject, sprintf( '<p>Hello %s,</p><p>%s</p><p>Please use your secure payment link to make the payment.</p>', esc_html( $row->buyer_name ?: 'there' ), esc_html( $message ) ) );
            }

            if ( ! empty( $row->sms_provider ) && ! empty( $row->buyer_phone ) && ! empty( $row->client_id ) && class_exists( 'OFP_Credit' ) && OFP_Credit::has_balance( (int) $row->client_id, 'sms', self::SMS_COST ) ) {
                $sms = new OFP_SMS( $row->sms_provider, (int) $row->client_id );
                $sent = $sms->send( $row->buyer_phone, $message );
                if ( ! empty( $sent['success'] ) ) OFP_Credit::deduct( (int) $row->client_id, 'sms', self::SMS_COST );
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
