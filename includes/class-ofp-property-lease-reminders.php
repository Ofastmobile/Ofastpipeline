<?php
/**
 * Tiered lease expiry reminders.
 *
 * Cadence (from blueprint §1.6):
 *  - 60–30 days before expiry: 1 reminder per week
 *  - Last 30 days: 2 reminders per week
 *  - Last 7 days: daily
 *  - After expiry (unpaid / not renewed): every 2–3 days, not daily forever
 *
 * Uses the existing email + SMS + in-app notification pattern from
 * OFP_Property_Installment_Reminders, same credit-gated SMS approach.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Lease_Reminders {

    const SMS_COST = 6.99;

    /**
     * Called once daily from OFP_Cron_Handler::check_subscriptions().
     */
    public static function run_daily(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $today = current_time( 'Y-m-d' );
        $today_ts = strtotime( $today . ' 00:00:00' );

        // Active leases with an end_date, or expired but not renewed.
        $leases = $wpdb->get_results(
            "SELECT l.*, t.full_name AS tenant_name, t.phone AS tenant_phone, t.email AS tenant_email,
                    pr.title AS property_title, c.sms_provider, c.business_name
             FROM {$p}ofp_property_leases l
             INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
             LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
             WHERE l.status IN ('active', 'expired')
               AND l.end_date IS NOT NULL
             ORDER BY l.end_date ASC"
        );

        foreach ( $leases as $lease ) {
            $end_ts = strtotime( $lease->end_date . ' 00:00:00' );
            if ( false === $end_ts ) continue;

            $days_until_expiry = (int) floor( ( $end_ts - $today_ts ) / DAY_IN_SECONDS );

            if ( ! self::should_send_today( $days_until_expiry, $lease->last_reminder_at, $today ) ) {
                continue;
            }

            self::send_reminder( $lease, $days_until_expiry );

            $wpdb->update(
                "{$p}ofp_property_leases",
                [ 'last_reminder_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $lease->id ]
            );

            // Mark as expired if past end_date and still active.
            if ( $days_until_expiry < 0 && $lease->status === 'active' ) {
                $wpdb->update(
                    "{$p}ofp_property_leases",
                    [ 'status' => 'expired', 'updated_at' => current_time( 'mysql' ) ],
                    [ 'id' => (int) $lease->id ]
                );
            }
        }
    }

    /**
     * Determines whether today is a valid send day based on the tiered cadence.
     */
    private static function should_send_today( int $days_until_expiry, ?string $last_reminder_at, string $today ): bool {
        // Calculate days since last reminder.
        $days_since_last = null;
        if ( $last_reminder_at ) {
            $last_date = date( 'Y-m-d', strtotime( $last_reminder_at ) );
            if ( $last_date === $today ) return false; // Already sent today.
            $days_since_last = (int) floor( ( strtotime( $today ) - strtotime( $last_date ) ) / DAY_IN_SECONDS );
        }

        // 60–30 days before: 1 per week (every 7 days).
        if ( $days_until_expiry >= 30 && $days_until_expiry <= 60 ) {
            return $days_since_last === null || $days_since_last >= 7;
        }

        // 30–8 days before: 2 per week (every 3–4 days).
        if ( $days_until_expiry >= 8 && $days_until_expiry < 30 ) {
            return $days_since_last === null || $days_since_last >= 3;
        }

        // Last 7 days: daily.
        if ( $days_until_expiry >= 0 && $days_until_expiry <= 7 ) {
            return $days_since_last === null || $days_since_last >= 1;
        }

        // After expiry (negative): every 2–3 days, cap at 90 days past.
        if ( $days_until_expiry < 0 && $days_until_expiry >= -90 ) {
            return $days_since_last === null || $days_since_last >= 3;
        }

        // More than 90 days past expiry: stop reminding.
        // More than 60 days before expiry: not yet time.
        return false;
    }

    /**
     * Sends a reminder via email, SMS (credit-gated), and in-app notification.
     */
    private static function send_reminder( object $lease, int $days_until_expiry ): void {
        $tenant_name = $lease->tenant_name ?: 'Tenant';
        $property    = $lease->property_title ?: 'your property';
        $expiry_date = wp_date( 'M j, Y', strtotime( $lease->end_date ) );
        $rent_amount = number_format( (float) $lease->rent_amount, 2 );
        $balance     = number_format( (float) $lease->balance, 2 );

        if ( $days_until_expiry < 0 ) {
            $days_past = abs( $days_until_expiry );
            $subject = 'Lease expired — action required';
            $message = sprintf(
                'Your lease for %s expired %d day(s) ago on %s. Outstanding balance: ₦%s of ₦%s. Please contact your property manager to renew or settle your account.',
                $property, $days_past, $expiry_date, $balance, $rent_amount
            );
        } elseif ( $days_until_expiry <= 7 ) {
            $subject = 'Urgent: Lease expiring very soon';
            $message = sprintf(
                'Your lease for %s expires in %d day(s) on %s. Cycle rent: ₦%s. Outstanding: ₦%s. Please arrange your renewal or final payment now.',
                $property, $days_until_expiry, $expiry_date, $rent_amount, $balance
            );
        } else {
            $subject = 'Lease expiry reminder';
            $message = sprintf(
                'Reminder: Your lease for %s expires on %s (%d days remaining). Cycle rent: ₦%s. Outstanding: ₦%s.',
                $property, $expiry_date, $days_until_expiry, $rent_amount, $balance
            );
        }

        // Email to tenant.
        if ( ! empty( $lease->tenant_email ) && class_exists( 'OFP_Mailer' ) ) {
            $email_body = sprintf(
                '<p>Hello %s,</p><p>%s</p><p>If you have already made a payment, please disregard this reminder.</p>',
                esc_html( $tenant_name ),
                esc_html( $message )
            );
            OFP_Mailer::send( $lease->tenant_email, $tenant_name, $subject, $email_body );
        }

        // SMS to tenant (credit-gated).
        $client_id = (int) $lease->client_id;
        if (
            ! empty( $lease->sms_provider ) &&
            ! empty( $lease->tenant_phone ) &&
            $client_id &&
            class_exists( 'OFP_Credit' ) &&
            OFP_Credit::has_balance( $client_id, 'sms', self::SMS_COST )
        ) {
            $sms = new OFP_SMS( $lease->sms_provider, $client_id );
            $sent = $sms->send( $lease->tenant_phone, $message );
            if ( ! empty( $sent['success'] ) ) {
                OFP_Credit::deduct( $client_id, 'sms', self::SMS_COST );
            }
        }

        // In-app notification to the landlord/client.
        if ( $client_id && class_exists( 'OFP_Notification' ) ) {
            OFP_Notification::create(
                $client_id,
                'lease_expiry_reminder',
                $subject,
                sprintf(
                    'Tenant %s — %s: Lease expires %s. Balance: ₦%s.',
                    $tenant_name, $property, $expiry_date, $balance
                )
            );
        }
    }
}
