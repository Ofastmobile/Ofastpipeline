<?php
/**
 * OFP_Mailer
 *
 * Centralised transactional email layer for OFast Pipeline.
 *
 * ARCHITECTURE DECISION (final):
 *  OFast Pipeline owns all email LOGIC and TEMPLATES.
 *  SMTP DELIVERY is handled at site level — either by Ofast Toolkit's SMTP
 *  module (hooks at phpmailer_init priority 999 when enabled) or by OFP's
 *  own generic SMTP fallback (hooks at priority 10, always yields to OFT).
 *
 * WHY OFP OWNS ITS OWN TEMPLATES:
 *  These are transactional system emails (welcome, payment confirmed, credit
 *  warning, subscription reminder). They are not marketing campaigns. They
 *  must fire instantly, one recipient at a time, triggered by pipeline events.
 *  Ofast Toolkit's emailer is built for bulk/campaign sending — wrong tool
 *  for this purpose. Same pattern used by WooCommerce, Tutor LMS, LearnDash.
 *
 * HOW OFAST TOOLKIT SMTP INTERACTS:
 *  OFP_Mailer::send() calls wp_mail() with fully-wrapped HTML.
 *  If Ofast Toolkit SMTP is enabled, it intercepts phpmailer_init at priority
 *  999 and handles delivery. It detects OFP's HTML is already wrapped and
 *  passes it through untouched — no double-wrapping, no conflict.
 *  If Ofast Toolkit SMTP is not enabled, OFP's own fallback (priority 10)
 *  activates only when ofp_smtp_host is configured in OFP Settings.
 *
 * EMAIL METHODS:
 *  - send()                      Core send — all other methods route through this.
 *  - send_welcome_email()        New client onboarded (manual or self-serve).
 *  - send_subscription_reminder() 7-day and 3-day expiry warnings.
 *  - send_payment_confirmed()    Payment received, subscription renewed.
 *  - send_low_credit_warning()   SMS or voice balance below 20%.
 *  - send_approval_notification() Self-serve signup approved by admin.
 *  - send_monthly_report()       CSV download link, monthly.
 *  - send_password_reset()       Password reset link for client.
 *
 * Depends on: wp_mail(), wp_options SMTP settings (fallback only).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Mailer {

    // ─────────────────────────────────────────────────────────────────────────
    // SMTP CONFIGURATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Register OFP's SMTP configuration via phpmailer_init at priority 10.
     *
     * Called once in plugins_loaded (ofast-pipeline.php).
     *
     * SMTP MODE (ofp_smtp_mode option):
     *  - 'default'  → Do NOT touch PHPMailer. Let WordPress / PHP mail() /
     *                  any other plugin handle delivery. This is the safe
     *                  fallback for local dev or when another SMTP plugin is
     *                  managing delivery (e.g. WP Mail SMTP, FluentSMTP).
     *  - 'custom'   → Apply the SMTP credentials configured in OFP Settings.
     *                  Requires ofp_smtp_host to be set.
     *
     * PRIORITY LOGIC:
     *  - OFP hooks at priority 10  (runs first)
     *  - Ofast Toolkit hooks at priority 999 (runs last, always wins when enabled)
     *  This means when Ofast Toolkit SMTP is enabled, it overwrites whatever
     *  OFP set, so OFT is always the authoritative SMTP when present.
     *
     * @return void
     */
    public static function configure_smtp(): void {

        add_action(
            'phpmailer_init',
            function ( \PHPMailer\PHPMailer\PHPMailer $phpmailer ): void {

                // ── Mode check ───────────────────────────────────────────
                $mode = get_option( 'ofp_smtp_mode', 'default' );
                if ( $mode !== 'custom' ) {
                    // 'default' mode — hands off. Let WP / PHP / another
                    // plugin handle delivery. Nothing to configure here.
                    return;
                }

                $host = get_option( 'ofp_smtp_host', '' );

                // Custom mode selected but no host configured — skip.
                if ( empty( $host ) ) {
                    return;
                }

                $phpmailer->isSMTP();
                $phpmailer->Host       = $host;
                $phpmailer->Port       = (int) get_option( 'ofp_smtp_port', 587 );
                $phpmailer->SMTPAuth   = true;
                $phpmailer->Username   = get_option( 'ofp_smtp_username', '' );
                $phpmailer->Password   = OFP_Security::decrypt( get_option( 'ofp_smtp_password', '' ) );
                $phpmailer->SMTPSecure = get_option( 'ofp_smtp_encryption', 'tls' );
                $phpmailer->From       = get_option(
                    'ofp_smtp_from_email',
                    get_option( 'admin_email' )
                );
                $phpmailer->FromName   = get_option( 'ofp_smtp_from_name', 'OFast Pipeline' );

            },
            10  // Lower than OFT's 999 — OFT always wins when both are configured.
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CORE SEND METHOD
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send a transactional HTML email via wp_mail().
     *
     * Every other method in this class routes through here.
     * Wraps the body in wrap_in_template() before sending so all
     * OFast Pipeline emails share a consistent branded shell.
     *
     * @param  string $to        Recipient email address.
     * @param  string $to_name   Recipient display name.
     * @param  string $subject   Email subject line.
     * @param  string $body_html Inner HTML body content — does not need a full
     *                           <html> wrapper, wrap_in_template() provides that.
     * @return bool              True if wp_mail() accepted the message.
     */
    public static function send(
        string $to,
        string $to_name,
        string $subject,
        string $body_html
    ): bool {

        if ( empty( $to ) || ! is_email( $to ) ) {
            error_log( "[OFP_Mailer] Invalid recipient email: {$to}" );
            return false;
        }

        $from_email = get_option( 'ofp_smtp_from_email', get_option( 'admin_email' ) );
        $from_name  = get_option( 'ofp_smtp_from_name', 'OFast Pipeline' );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
        ];

        $full_html = self::wrap_in_template( $to_name, $subject, $body_html );

        $sent = wp_mail( $to, $subject, $full_html, $headers );

        if ( ! $sent ) {
            error_log( "[OFP_Mailer] wp_mail() failed for: {$to} | Subject: {$subject}" );
        }

        return $sent;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SYSTEM EMAILS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send a welcome email to a newly onboarded client.
     *
     * Sent immediately after OFP_Client::create() completes.
     * Contains login credentials and Monnify virtual account details.
     *
     * @param  int    $client_id     Client ID in wp_ofp_clients.
     * @param  string $temp_password Plaintext temporary password — emailed once,
     *                               never stored in plain form anywhere.
     * @return void
     */
    public static function send_welcome_email( int $client_id, string $temp_password ): void {
        global $wpdb;

        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $client_id
            )
        );

        if ( ! $client ) {
            error_log( "[OFP_Mailer] send_welcome_email: client {$client_id} not found." );
            return;
        }

        $login_url = home_url( '/login' );

        $body = '
            <h2>Welcome to OFast Pipeline, ' . esc_html( $client->owner_name ) . '! 🎉</h2>
            <p>Your client account has been created and is ready to use.
               Here are your login details — please keep them safe.</p>

            <div style="background:#f8fafc;border-radius:8px;padding:20px 24px;
                        margin:24px 0;border-left:4px solid #1a73e8;">
                <p style="margin:0 0 10px;">
                    <strong>Login URL:</strong>
                    <a href="' . esc_url( $login_url ) . '">' . esc_url( $login_url ) . '</a>
                </p>
                <p style="margin:0 0 10px;">
                    <strong>Email:</strong> ' . esc_html( $client->email ) . '
                </p>
                <p style="margin:0;">
                    <strong>Temporary Password:</strong>
                    <code style="background:#e8f0fe;padding:3px 10px;border-radius:4px;
                                 font-size:14px;">' . esc_html( $temp_password ) . '</code>
                </p>
            </div>

            <p>⚠️ <strong>Please change your password</strong> after your first login
               via My Account → Change Password.</p>

            <h3 style="margin-top:28px;">Activate Your Subscription</h3>
            <p>Transfer your subscription fee to your dedicated virtual account below.
               Your pipeline activates automatically once payment is confirmed —
               no manual intervention needed.</p>

            <div style="background:#f0fdf4;border-radius:8px;padding:20px 24px;
                        margin:16px 0;border-left:4px solid #16a34a;">
                <p style="margin:0 0 10px;">
                    <strong>Bank:</strong>
                    ' . esc_html( $client->virtual_bank_name ?: 'Being set up — check back shortly' ) . '
                </p>
                <p style="margin:0;">
                    <strong>Account Number:</strong>
                    ' . esc_html( $client->virtual_account_number ?: '—' ) . '
                </p>
            </div>

            <p style="color:#6b7280;font-size:14px;">
                This is a dedicated virtual account for your business only.
                Every payment is automatically tracked and applied to your account.
            </p>

            <h3 style="margin-top:28px;">What Happens Next?</h3>
            <ol style="padding-left:20px;line-height:2.2;">
                <li>Log in to your dashboard at the URL above</li>
                <li>Make your first subscription payment to the account above</li>
                <li>Your lead pipeline activates automatically</li>
                <li>Leads start flowing in as your ads run</li>
            </ol>

            <p style="margin-top:24px;">
                Questions? Simply reply to this email and we will get back to you promptly.
            </p>
        ';

        self::send(
            $client->email,
            $client->owner_name,
            'Welcome to OFast Pipeline — Your Account Is Ready',
            $body
        );
    }

    /**
     * Send a subscription expiry reminder email.
     *
     * Called by OFP_Subscription::run_daily_check() at 7 days and 3 days
     * before subscription_expires date.
     *
     * @param  object $client    Full wp_ofp_clients row.
     * @param  int    $days_left Days remaining until expiry.
     * @return void
     */
    public static function send_subscription_reminder( object $client, int $days_left ): void {

        $urgent  = $days_left <= 3;
        $prefix  = $urgent ? '⚠️ Urgent: ' : '';
        $day_str = $days_left === 1 ? '1 day' : "{$days_left} days";

        $body = '
            <h2>' . $prefix . 'Your subscription expires in ' . esc_html( $day_str ) . '</h2>

            <p>Hi ' . esc_html( $client->owner_name ) . ',</p>

            <p>Your OFast Pipeline subscription for
               <strong>' . esc_html( $client->business_name ) . '</strong>
               expires on <strong>' . esc_html( $client->subscription_expires ) . '</strong>.</p>

            <p>To keep your lead pipeline running without interruption, please renew
               before the expiry date by transferring your subscription fee to your
               dedicated virtual account:</p>

            <div style="background:#fef3c7;border-radius:8px;padding:20px 24px;
                        margin:20px 0;border-left:4px solid #f59e0b;">
                <p style="margin:0 0 10px;">
                    <strong>Bank:</strong> ' . esc_html( $client->virtual_bank_name ) . '
                </p>
                <p style="margin:0;">
                    <strong>Account Number:</strong>
                    ' . esc_html( $client->virtual_account_number ) . '
                </p>
            </div>

            ' . ( $urgent ? '
            <p style="color:#dc2626;font-weight:600;">
                ⚠️ Your pipeline will enter a 5-day grace period after expiry,
                then be suspended if payment is not received.
            </p>' : '
            <p>Your pipeline will continue running during a 5-day grace period
               after expiry, giving you time to renew without interruption.</p>' ) . '

            <p>
                <a href="' . esc_url( home_url( '/credits' ) ) . '"
                   style="display:inline-block;background:#1a73e8;color:#fff;
                          padding:12px 28px;border-radius:8px;text-decoration:none;
                          font-weight:600;margin-top:8px;">
                    View My Account
                </a>
            </p>
        ';

        self::send(
            $client->email,
            $client->owner_name,
            $prefix . "Your OFast Pipeline subscription expires in {$day_str}",
            $body
        );
    }

    /**
     * Send a payment confirmed / subscription renewed email.
     *
     * Called by OFP_Subscription::record_payment() after Monnify webhook
     * verifies a successful transaction.
     *
     * @param  object $client  Full wp_ofp_clients row.
     * @param  float  $amount  Amount paid in NGN.
     * @param  string $type    Subscription type: 'crm' or 'listing'.
     * @return void
     */
    public static function send_payment_confirmed(
        object $client,
        float $amount,
        string $type = 'crm'
    ): void {

        $label  = $type === 'listing' ? 'Property Listing' : 'CRM Pipeline';
        $expiry = gmdate( 'F j, Y', strtotime( '+30 days' ) );

        $body = '
            <h2>✅ Payment Received — ' . esc_html( $label ) . ' Subscription Renewed</h2>

            <p>Hi ' . esc_html( $client->owner_name ) . ',</p>

            <p>We have received your payment and your subscription has been renewed.</p>

            <div style="background:#f0fdf4;border-radius:8px;padding:20px 24px;
                        margin:20px 0;border-left:4px solid #16a34a;">
                <p style="margin:0 0 10px;">
                    <strong>Amount Received:</strong>
                    NGN ' . number_format( $amount, 2 ) . '
                </p>
                <p style="margin:0 0 10px;">
                    <strong>Subscription:</strong> ' . esc_html( $label ) . '
                </p>
                <p style="margin:0;">
                    <strong>Active Until:</strong> ' . esc_html( $expiry ) . '
                </p>
            </div>

            <p>Your lead pipeline is fully active. Check your dashboard for
               incoming leads and activity.</p>

            <p>
                <a href="' . esc_url( home_url( '/dashboard' ) ) . '"
                   style="display:inline-block;background:#1a73e8;color:#fff;
                          padding:12px 28px;border-radius:8px;text-decoration:none;
                          font-weight:600;margin-top:8px;">
                    Go to My Dashboard
                </a>
            </p>
        ';

        self::send(
            $client->email,
            $client->owner_name,
            'Payment Confirmed — OFast Pipeline ' . $label . ' Renewed',
            $body
        );
    }

    /**
     * Send a low credit warning email.
     *
     * Called by OFP_Credit when a client's SMS or voice balance drops below 20%.
     * Only sent once per low-balance cycle (low_warned flag prevents repeats).
     *
     * @param  object $client    Full wp_ofp_clients row.
     * @param  string $channel   'sms' or 'voice'.
     * @param  float  $remaining Remaining balance in NGN.
     * @return void
     */
    public static function send_low_credit_warning(
        object $client,
        string $channel,
        float $remaining
    ): void {

        $label = strtoupper( $channel );

        $body = '
            <h2>⚠️ Low ' . esc_html( $label ) . ' Credit Alert</h2>

            <p>Hi ' . esc_html( $client->owner_name ) . ',</p>

            <p>Your <strong>' . esc_html( $label ) . ' credit</strong> balance for
               <strong>' . esc_html( $client->business_name ) . '</strong>
               has dropped below 20%.</p>

            <div style="background:#fef3c7;border-radius:8px;padding:20px 24px;
                        margin:20px 0;border-left:4px solid #f59e0b;">
                <p style="margin:0;">
                    <strong>Remaining Balance:</strong>
                    NGN ' . number_format( $remaining, 2 ) . '
                </p>
            </div>

            <p>Your pipeline will automatically <strong>pause</strong> when the
               balance reaches zero. Top up now to avoid any interruption to your
               lead follow-up sequence.</p>

            <p>
                <a href="' . esc_url( home_url( '/credits' ) ) . '"
                   style="display:inline-block;background:#f59e0b;color:#fff;
                          padding:12px 28px;border-radius:8px;text-decoration:none;
                          font-weight:600;margin-top:8px;">
                    Top Up ' . esc_html( $label ) . ' Credit
                </a>
            </p>
        ';

        self::send(
            $client->email,
            $client->owner_name,
            'Action Required: Low ' . $label . ' Credit — OFast Pipeline',
            $body
        );
    }

    /**
     * Notify a self-serve signup client that their account has been approved.
     *
     * Called by OFP_Client::approve() after an admin reviews and approves
     * a pending_review account.
     *
     * @param  object $client Full wp_ofp_clients row.
     * @return void
     */
    public static function send_approval_notification( object $client ): void {

        $body = '
            <h2>🎉 Your OFast Pipeline Account Is Approved!</h2>

            <p>Hi ' . esc_html( $client->owner_name ) . ',</p>

            <p>Great news — your account for
               <strong>' . esc_html( $client->business_name ) . '</strong>
               has been reviewed and approved. You can now log in and
               activate your pipeline.</p>

            <p>
                <a href="' . esc_url( home_url( '/login' ) ) . '"
                   style="display:inline-block;background:#1a73e8;color:#fff;
                          padding:12px 28px;border-radius:8px;text-decoration:none;
                          font-weight:600;margin-top:8px;">
                    Log In Now
                </a>
            </p>

            <p style="margin-top:20px;">
                Questions? Simply reply to this email and we will assist you promptly.
            </p>
        ';

        self::send(
            $client->email,
            $client->owner_name,
            'Your OFast Pipeline Account Is Approved — Welcome Aboard!',
            $body
        );
    }

    /**
     * Send a monthly report download link to a client.
     *
     * Called by OFP_CSV::generate_monthly_report() after CSVs are generated.
     * The download link uses a 72-hour expiring token for security.
     *
     * @param  object $client        Full wp_ofp_clients row.
     * @param  string $period        Human-readable period e.g. "June 2026".
     * @param  string $download_url  Tokenised download URL.
     * @return void
     */
    public static function send_monthly_report(
        object $client,
        string $period,
        string $download_url
    ): void {

        $body = '
            <h2>📊 Your Monthly Pipeline Report Is Ready</h2>

            <p>Hi ' . esc_html( $client->owner_name ) . ',</p>

            <p>Your lead pipeline report for
               <strong>' . esc_html( $period ) . '</strong> is ready.
               It includes:</p>

            <ul style="padding-left:20px;line-height:2.2;color:#374151;">
                <li>All leads captured this month</li>
                <li>Full communications log (SMS, voice, email)</li>
                <li>Lead status breakdown (new, contacted, converted)</li>
            </ul>

            <p>
                <a href="' . esc_url( $download_url ) . '"
                   style="display:inline-block;background:#1a73e8;color:#fff;
                          padding:12px 28px;border-radius:8px;text-decoration:none;
                          font-weight:600;margin-top:8px;">
                    Download Report
                </a>
            </p>

            <p style="margin-top:16px;color:#9ca3af;font-size:13px;">
                ⏳ This download link expires in 72 hours.
            </p>
        ';

        self::send(
            $client->email,
            $client->owner_name,
            'Your OFast Pipeline Report for ' . $period . ' Is Ready',
            $body
        );
    }

    /**
     * Send a password reset link to a client.
     *
     * Called by OFP_Client::reset_password() after generating a reset token.
     * The reset link expires in 1 hour.
     *
     * @param  object $client     Full wp_ofp_clients row.
     * @param  string $reset_url  Tokenised password reset URL.
     * @return void
     */
    public static function send_password_reset( object $client, string $reset_url ): void {

        $body = '
            <h2>Reset Your OFast Pipeline Password</h2>

            <p>Hi ' . esc_html( $client->owner_name ) . ',</p>

            <p>We received a request to reset the password for your OFast Pipeline account.
               Click the button below to set a new password.</p>

            <p>
                <a href="' . esc_url( $reset_url ) . '"
                   style="display:inline-block;background:#1a73e8;color:#fff;
                          padding:12px 28px;border-radius:8px;text-decoration:none;
                          font-weight:600;margin-top:8px;">
                    Reset My Password
                </a>
            </p>

            <p style="margin-top:20px;color:#9ca3af;font-size:13px;">
                This link expires in <strong>1 hour</strong>.<br>
                If you did not request a password reset, you can safely ignore this email.
                Your password will not change.
            </p>
        ';

        self::send(
            $client->email,
            $client->owner_name,
            'Reset Your OFast Pipeline Password',
            $body
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EMAIL HTML TEMPLATE SHELL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Wrap an email body in OFast Pipeline's branded HTML template shell.
     *
     * DESIGN DECISIONS:
     *  - Inline CSS throughout — email clients strip <style> blocks.
     *  - Max-width 600px — standard for email clients.
     *  - No images in the shell — images break in many email clients without
     *    a proper CDN and alt text setup. Typography only for reliability.
     *  - No unsubscribe link — these are transactional emails, not marketing.
     *    Adding an unsubscribe link to a payment confirmation would be wrong.
     *
     * @param  string $recipient_name  Recipient name — not used in shell but
     *                                 available for future personalisation.
     * @param  string $subject         Email subject — used as preheader text.
     * @param  string $body_html       Inner email content to inject.
     * @return string                  Complete HTML email document.
     */
    private static function wrap_in_template(
        string $recipient_name,
        string $subject,
        string $body_html
    ): string {

        $site_name = get_bloginfo( 'name' ) ?: 'OFast Pipeline';
        $site_url  = home_url();
        $year      = gmdate( 'Y' );

        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>' . esc_html( $subject ) . '</title>
    <!--[if mso]>
    <noscript>
        <xml><o:OfficeDocumentSettings>
            <o:PixelsPerInch>96</o:PixelsPerInch>
        </o:OfficeDocumentSettings></xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f0f4f8;
             font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,
             \'Helvetica Neue\',Arial,sans-serif;">

    <!-- Preheader (hidden preview text in email clients) -->
    <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">
        ' . esc_html( wp_strip_all_tags( $subject ) ) . '
        &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>

    <!-- Outer wrapper -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
           style="background-color:#f0f4f8;padding:32px 16px;">
        <tr>
            <td align="center">

                <!-- Email container -->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width:600px;width:100%;">

                    <!-- Header -->
                    <tr>
                        <td style="background-color:#0f172a;border-radius:12px 12px 0 0;
                                   padding:28px 36px;">
                            <p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;
                                      letter-spacing:-0.3px;">
                                ' . esc_html( $site_name ) . '
                            </p>
                            <p style="margin:4px 0 0;font-size:13px;color:#94a3b8;">
                                Done-for-you lead pipeline for Nigerian businesses
                            </p>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="background-color:#ffffff;padding:36px;
                                   border-left:1px solid #e5e7eb;
                                   border-right:1px solid #e5e7eb;">
                            ' . $body_html . '
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f8fafc;border-radius:0 0 12px 12px;
                                   border:1px solid #e5e7eb;border-top:none;
                                   padding:20px 36px;text-align:center;">
                            <p style="margin:0;color:#9ca3af;font-size:12px;line-height:1.7;">
                                You are receiving this email because you have an account with
                                <a href="' . esc_url( $site_url ) . '"
                                   style="color:#1a73e8;text-decoration:none;">
                                    ' . esc_html( $site_name ) . '
                                </a>.<br>
                                &copy; ' . $year . ' ' . esc_html( $site_name ) . '.
                                All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
                <!-- End email container -->

            </td>
        </tr>
    </table>
    <!-- End outer wrapper -->

</body>
</html>';
    }
}
