Good instinct to check before diving into new ideas — you've actually got two problems layered on top of each other: (1) the tracking docs themselves are stale, and (2) even accounting for that staleness, there are a few genuinely unresolved threads. Let me lay out what I found.

The tracking docs are behind the actual code
The continuation blueprint says "Phase 9" and the master HTML doc says "Phase 10" — but the actual code you've pasted in shows work well past that, undocumented anywhere:

Password reset (forgot-password.php, reset-password.php, OFP_Auth::request_password_reset() etc.) — fully built. This was flagged as missing in the master doc; it's done.
Property listing public pages (property-archive.php, property-single.php) — built. Also flagged missing in the master doc; also done.
SMS reseller model fix — class-ofp-sms.php now correctly uses only the global AT/BulkSMS key, no per-client key lookup. Flagged as broken in the master doc; fixed.
Editable plan pricing — OFP_Subscription::get_plan_prices()/save_pricing() plus a full Settings UI. Flagged as hardcoded in the master doc; fixed.
Admin manual credit top-up form — exists on the client detail page. Flagged missing; fixed.
Billing "pending" row labelling — now shows "Awaiting First Payment". Fixed.
Client visibility of their own client_id — now on /api-settings. Fixed.
Beyond all that, there's a whole Phase 14–17b layer none of your blueprint docs mention at all: tiered listing plans (Bronze/Silver/Gold with property caps, replacing the flat listing fee from the v2.1 amendment), an app./property. subdomain host router, a notification system (bell + email preference), and a manual "Funding Requests" flow (client submits bank transfer proof → admin approves → credits/subscription activated).
So: good news, more is done than your own docs say.

Genuinely still-open items (confirmed against actual code, not just old docs)
Monnify underpayment handling — class-ofp-gateway-monnify.php::process_payment() still just logs and silently drops any payment below the expected amount. Nothing is recorded, nothing notifies you. This was flagged in the original amendment (A12) and it's still exactly that way.
Trigger queue retry/cancel UI — admin/views/triggers-list.php is still view-only. OFP_Queue::retry() and cancel_for_client() exist and work, just no buttons calling them.
Communications CSV not separately downloadable — /reports client template still only serves $files[0] (the leads CSV) from the pipe-delimited path; the comms CSV is generated but has no download link.
Two competing credit top-up systems — this is the one I'd actually flag as a decision, not just a gap. OFP_Payment::initiate_credit_topup() and the gateway initiate_transaction() methods (automated, pay-via-Paystack/Flutterwave-link) are fully built. But /credits still says "contact us to top up," and instead you built a separate manual system (/funding, bank transfer + admin approval) that does the same job differently. Right now you're maintaining two parallel top-up paths. Worth deciding whether the automated one on /credits ever gets wired up, or whether /funding is the permanent answer and the automated code should be considered dead weight (or repurposed for CRM/listing subscription renewal instead of just credits).
Landing page integration guide — still just a doc, never written. Zero code impact, purely a "give the client a copy-paste snippet" task.
Featured listing (is_featured) — still just a flag with no pricing or frontend effect. Deferred, as originally planned.
Minor observed inconsistency
Your local emoji-removal change made it into the sidebar nav (nav.php uses SVG icons) but dashboard.php still has emoji in a few places (🔍, 🚀, ⚠️). Not urgent, just noting it's partial.

On the WhatsApp ban worry
Noted, but I'm deliberately not opening that can right now since you said you're still getting your bearings — it's a real and known issue (Meta suspending/flagging WhatsApp numbers tied to click-to-chat ads, especially with generic/bulk-feeling opening messages), and it directly touches your IVR digit-2 flow and whatsapp_link field. Happy to dig into it whenever you're ready — it's more of a business/product-risk conversation than a code task, and it might actually reshape parts of the pipeline config.



fix the gaps


Architected unified payment handling and administrative workflow enhancements
Architected unified payment handling and administrative workflow enhancements
Good — let's knock out the confirmed gaps. I'm treating this as its own mini-phase (call it Phase 18), following your usual pattern: targeted files only, full manifest, test before you move on to WhatsApp or anything else.

I found that the underpayment bug is actually worse than I first said — it's not just Monnify. Paystack and Flutterwave have the identical bug (silently drop the payment, no record, no log), so I fixed it once in a shared place instead of patching three files with the same logic three times.

Manifest — Phase 18
File	Action
includes/class-ofp-activator.php	REPLACE (add one ALTER TABLE guard)
includes/class-ofp-subscription.php	REPLACE (add 2 methods)
includes/gateways/class-ofp-gateway-monnify.php	REPLACE (process_payment() simplified)
includes/gateways/class-ofp-gateway-paystack.php	REPLACE (process_payment() simplified)
includes/gateways/class-ofp-gateway-flutterwave.php	REPLACE (process_payment() simplified)
admin/views/billing.php	REPLACE (underpaid badge + shortfall display)
admin/class-ofp-admin-menu.php	REPLACE (add retry/cancel trigger handlers)
admin/views/triggers-list.php	REPLACE (add retry/cancel buttons)
public/templates/reports.php	REPLACE (serve comms CSV separately)
admin/views/reports.php	REPLACE (two download links per archive)
docs/landing-page-integration.md	ADD (new file)
1. Underpayment — no longer silently dropped
includes/class-ofp-activator.php — add to maybe_upgrade_schema(), right after the logo_url block:

php
        // expected_amount column on ofp_subscriptions (Phase 18 — underpayment tracking).
        // Records what the client SHOULD have paid alongside what they DID pay,
        // so admin billing can show the shortfall instead of just a mystery amount.
        $expected_col_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM {$p}ofp_subscriptions LIKE 'expected_amount'"
        );
        if ( empty( $expected_col_exists ) ) {
            $wpdb->query( "ALTER TABLE {$p}ofp_subscriptions ADD COLUMN expected_amount DECIMAL(10,2) DEFAULT NULL AFTER amount" );
        }
includes/class-ofp-subscription.php — add these two new methods (I'd put them right after record_payment()):

php
    /**
     * Central payment-processing entry point for ALL gateway adapters.
     *
     * Phase 18: previously each gateway (Monnify, Paystack, Flutterwave)
     * duplicated its own amount-checking logic, and all three had the same
     * bug — an underpaid amount was either silently ignored (Paystack,
     * Flutterwave: nothing recorded, nothing logged) or logged and dropped
     * (Monnify: error_log() only). In every case the payment was effectively
     * lost — the client's money moved, but nothing in the system reflected it.
     *
     * Now every gateway calls this ONE method after verifying its webhook
     * signature. It decides paid vs underpaid in one place, so the fix
     * only ever needs to happen here.
     *
     * @param  int    $client_id    Client ID extracted from the payment reference.
     * @param  float  $amount       Amount actually received, in NGN.
     * @param  string $payment_ref  Gateway's transaction reference.
     * @param  string $method       e.g. 'monnify_virtual_account', 'paystack_virtual_account'.
     * @return void
     */
    public static function process_gateway_payment(
        int $client_id,
        float $amount,
        string $payment_ref,
        string $method
    ): void {
        $expected = self::get_expected_monthly_total( $client_id );

        if ( $amount >= $expected && $expected > 0 ) {
            self::apply_full_payment( $client_id, $amount, $payment_ref, $method );
            return;
        }

        if ( $expected <= 0 ) {
            // No CRM/listing subscription currently expects a payment at all
            // (e.g. a stray/duplicate webhook). Record as underpaid so it's
            // visible rather than silently vanishing, but don't guess a type.
            self::record_underpayment( $client_id, $amount, $expected, $payment_ref, $method );
            return;
        }

        self::record_underpayment( $client_id, $amount, $expected, $payment_ref, $method );
    }

    /**
     * Apply a payment that meets or exceeds the expected monthly total.
     * Splits the recording between 'crm' and 'listing' subscription rows
     * exactly as the original per-gateway logic did.
     *
     * @param  int    $client_id
     * @param  float  $amount
     * @param  string $payment_ref
     * @param  string $method
     * @return void
     */
    private static function apply_full_payment(
        int $client_id,
        float $amount,
        string $payment_ref,
        string $method
    ): void {
        global $wpdb;

        $has_crm = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_subscriptions
             WHERE client_id = %d AND type = 'crm' LIMIT 1",
            $client_id
        ) );

        $has_listing = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_subscriptions
             WHERE client_id = %d AND type = 'listing' LIMIT 1",
            $client_id
        ) );

        if ( $has_crm ) {
            self::record_payment( $client_id, 'crm', $amount, $payment_ref, $method );
        } elseif ( $has_listing ) {
            self::record_payment( $client_id, 'listing', $amount, $payment_ref, $method );
        } else {
            // Client has neither type on record yet — still record it under
            // 'crm' as a safe default rather than dropping it, since a payment
            // that made it this far is real money that needs to be accounted for.
            self::record_payment( $client_id, 'crm', $amount, $payment_ref, $method );
        }
    }

    /**
     * Record an underpaid transaction instead of silently dropping it.
     *
     * Inserts a subscription row with status = 'underpaid' so it shows up
     * in the admin Billing view with both the amount received and the
     * amount expected, and notifies both the admin and the client so
     * nobody is left wondering why their pipeline didn't renew.
     *
     * Admin resolves this manually via the existing "Mark Paid" action on
     * the Billing page (ofp_mark_subscription_paid already accepts any
     * non-paid row, underpaid included) — or by contacting the client for
     * the balance first.
     *
     * @param  int    $client_id
     * @param  float  $amount_paid
     * @param  float  $expected
     * @param  string $payment_ref
     * @param  string $method
     * @return void
     */
    public static function record_underpayment(
        int $client_id,
        float $amount_paid,
        float $expected,
        string $payment_ref,
        string $method
    ): void {
        global $wpdb;

        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            error_log( "[OFP_Subscription] record_underpayment: client {$client_id} not found." );
            return;
        }

        $wpdb->insert(
            $wpdb->prefix . 'ofp_subscriptions',
            [
                'client_id'       => $client_id,
                'type'            => $client->plan ? 'crm' : 'listing',
                'plan'            => $client->plan,
                'amount'          => $amount_paid,
                'expected_amount' => $expected,
                'payment_method'  => $method,
                'payment_ref'     => sanitize_text_field( $payment_ref ),
                'status'          => 'underpaid',
                'created_at'      => current_time( 'mysql' ),
            ]
        );

        $shortfall = max( 0, $expected - $amount_paid );

        // Notify admin — this needs a human decision, it can't self-resolve.
        OFP_Mailer::send(
            get_option( 'admin_email' ),
            'Admin',
            'Underpayment Received — ' . $client->business_name,
            '<h2>⚠️ Underpayment Received</h2>'
            . '<p><strong>Client:</strong> ' . esc_html( $client->business_name ) . '</p>'
            . '<p><strong>Amount received:</strong> NGN ' . number_format( $amount_paid, 2 ) . '</p>'
            . '<p><strong>Amount expected:</strong> NGN ' . number_format( $expected, 2 ) . '</p>'
            . '<p><strong>Shortfall:</strong> NGN ' . number_format( $shortfall, 2 ) . '</p>'
            . '<p><strong>Reference:</strong> ' . esc_html( $payment_ref ) . '</p>'
            . '<p>Review this in wp-admin → OFast Pipeline → Billing before it renews automatically.</p>'
        );

        // Let the client know too, via their own notification preference.
        if ( class_exists( 'OFP_Notification' ) ) {
            OFP_Notification::create(
                $client_id,
                'underpayment_received',
                'Payment received — balance still due',
                'We received NGN ' . number_format( $amount_paid, 2 ) . ', but your plan requires '
                . 'NGN ' . number_format( $expected, 2 ) . '. Please pay the remaining '
                . 'NGN ' . number_format( $shortfall, 2 ) . ' to activate your subscription.'
            );
        }
    }
includes/gateways/class-ofp-gateway-monnify.php — replace the private process_payment() method entirely with:

php
    /**
     * Delegate a verified payment to the shared handler.
     * See OFP_Subscription::process_gateway_payment() for the full-vs-underpaid logic.
     *
     * @param  int    $client_id
     * @param  float  $amount
     * @param  string $payment_ref
     * @return void
     */
    private function process_payment( int $client_id, float $amount, string $payment_ref ): void {
        OFP_Subscription::process_gateway_payment( $client_id, $amount, $payment_ref, 'monnify_virtual_account' );
    }
(This replaces the whole has_crm/has_listing/underpayment-log block — that logic now lives once in OFP_Subscription::apply_full_payment().)

includes/gateways/class-ofp-gateway-paystack.php — replace its process_payment():

php
    /**
     * Delegate a verified payment to the shared handler.
     * See OFP_Subscription::process_gateway_payment() for the full-vs-underpaid logic.
     *
     * @param  int    $client_id
     * @param  float  $amount
     * @param  string $payment_ref
     * @return void
     */
    private function process_payment( int $client_id, float $amount, string $payment_ref ): void {
        OFP_Subscription::process_gateway_payment( $client_id, $amount, $payment_ref, 'paystack_virtual_account' );
    }
includes/gateways/class-ofp-gateway-flutterwave.php — replace its process_payment():

php
    /**
     * Delegate a verified payment to the shared handler.
     * See OFP_Subscription::process_gateway_payment() for the full-vs-underpaid logic.
     *
     * @param  int    $client_id
     * @param  float  $amount
     * @param  string $payment_ref
     * @return void
     */
    private function process_payment( int $client_id, float $amount, string $payment_ref ): void {
        OFP_Subscription::process_gateway_payment( $client_id, $amount, $payment_ref, 'flutterwave_virtual_account' );
    }
admin/views/billing.php — extend the status badge block to handle underpaid and show the shortfall. Replace:

php
                        <td>
                            <?php
                            $s_class = $sub->status === 'paid' ? 'ofp-badge-green' : 'ofp-badge-yellow';
                            // 'pending' is the initial placeholder row created on onboarding
                            // before any payment has been received. Label it clearly so
                            // it is never mistaken for a failed or overdue payment.
                            $s_label = $sub->status === 'pending'
                                ? 'Awaiting First Payment'
                                : ucfirst( $sub->status );
                            echo '<span class="ofp-badge ' . esc_attr( $s_class ) . '">'
                                . esc_html( $s_label ) . '</span>';
                            ?>
                        </td>
with:

php
                        <td>
                            <?php
                            $s_class = match ( $sub->status ) {
                                'paid'      => 'ofp-badge-green',
                                'underpaid' => 'ofp-badge-red',
                                default     => 'ofp-badge-yellow',
                            };
                            // 'pending' is the initial placeholder row created on onboarding
                            // before any payment has been received. Label it clearly so
                            // it is never mistaken for a failed or overdue payment.
                            $s_label = $sub->status === 'pending'
                                ? 'Awaiting First Payment'
                                : ucfirst( $sub->status );
                            echo '<span class="ofp-badge ' . esc_attr( $s_class ) . '">'
                                . esc_html( $s_label ) . '</span>';

                            if ( $sub->status === 'underpaid' && ! empty( $sub->expected_amount ) ) {
                                $shortfall = max( 0, (float) $sub->expected_amount - (float) $sub->amount );
                                echo '<div style="font-size:11px;color:#dc2626;margin-top:4px;">'
                                    . 'Expected ₦' . esc_html( number_format( (float) $sub->expected_amount, 0 ) )
                                    . ' — short ₦' . esc_html( number_format( $shortfall, 0 ) )
                                    . '</div>';
                            }
                            ?>
                        </td>
The existing "Mark Paid" button already fires for any non-paid row, so no change needed there — an admin can click it on an underpaid row once the client tops up the balance separately (e.g. via /funding), or after confirming they'll accept the partial amount.

2. Trigger queue — retry/cancel buttons
includes/class-ofp-queue.php — add a single-trigger cancel method (you already have retry(); this is its counterpart), right after retry():

php
    /**
     * Cancel a single pending or failed trigger by ID.
     * Counterpart to retry() — for admin use on the Trigger Queue page.
     *
     * @param  int $trigger_id  Trigger ID.
     * @return bool             True if a row was updated.
     */
    public static function cancel_trigger( int $trigger_id ): bool {
        global $wpdb;

        return (bool) $wpdb->update(
            $wpdb->prefix . 'ofp_trigger_queue',
            [ 'status' => 'cancelled' ],
            [ 'id' => $trigger_id ]
        );
    }
admin/class-ofp-admin-menu.php — add two hooks in the constructor, alongside the other admin_post_ofp_* lines:

php
        add_action( 'admin_post_ofp_retry_trigger',  [ $this, 'handle_retry_trigger' ] );
        add_action( 'admin_post_ofp_cancel_trigger',  [ $this, 'handle_cancel_trigger' ] );
And two new handler methods — I'd place them right after handle_generate_report():

php
    /**
     * Manually retry a single failed trigger from the Trigger Queue admin view.
     * OFP_Queue::retry() re-queues it 30 minutes out, up to 3 total attempts.
     *
     * @return void
     */
    public function handle_retry_trigger(): void {

        $this->require_admin_post( 'ofp_retry_trigger' );

        $trigger_id = (int) ( $_POST['trigger_id'] ?? 0 );

        if ( $trigger_id ) {
            OFP_Queue::retry( $trigger_id );
            $this->set_message( '✅ Trigger re-queued for retry in 30 minutes.', 'success' );
        } else {
            $this->set_message( '❌ Invalid trigger.', 'error' );
        }

        $redirect_filter = sanitize_text_field( wp_unslash( $_POST['return_filter'] ?? '' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=ofp-triggers' . ( $redirect_filter ? '&filter=' . $redirect_filter : '' ) ) );
        exit;
    }

    /**
     * Manually cancel a single pending/failed trigger from the Trigger Queue admin view.
     *
     * @return void
     */
    public function handle_cancel_trigger(): void {

        $this->require_admin_post( 'ofp_cancel_trigger' );

        $trigger_id = (int) ( $_POST['trigger_id'] ?? 0 );

        if ( $trigger_id && OFP_Queue::cancel_trigger( $trigger_id ) ) {
            $this->set_message( '✅ Trigger cancelled.', 'success' );
        } else {
            $this->set_message( '❌ Invalid trigger.', 'error' );
        }

        $redirect_filter = sanitize_text_field( wp_unslash( $_POST['return_filter'] ?? '' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=ofp-triggers' . ( $redirect_filter ? '&filter=' . $redirect_filter : '' ) ) );
        exit;
    }
admin/views/triggers-list.php — add an Actions column. Replace the <thead> block:

php
                <tr>
                    <th>Type</th>
                    <th>Client</th>
                    <th>Lead Phone</th>
                    <th>Scheduled</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Message Preview</th>
                </tr>
with:

php
                <tr>
                    <th>Type</th>
                    <th>Client</th>
                    <th>Lead Phone</th>
                    <th>Scheduled</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Message Preview</th>
                    <th>Actions</th>
                </tr>
And add a cell inside the foreach loop, right after the message-preview <td> and before its closing </tr>:

php
                        <td>
                            <?php if ( in_array( $t->status, [ 'failed', 'pending' ], true ) ) : ?>
                                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'ofp_retry_trigger' ); ?>
                                    <input type="hidden" name="action" value="ofp_retry_trigger">
                                    <input type="hidden" name="trigger_id" value="<?php echo esc_attr( $t->id ); ?>">
                                    <input type="hidden" name="return_filter" value="<?php echo esc_attr( $filter_status ); ?>">
                                    <button type="submit" class="button button-small"
                                            onclick="return confirm('Retry this trigger in 30 minutes?');">
                                        Retry
                                    </button>
                                </form>
                                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'ofp_cancel_trigger' ); ?>
                                    <input type="hidden" name="action" value="ofp_cancel_trigger">
                                    <input type="hidden" name="trigger_id" value="<?php echo esc_attr( $t->id ); ?>">
                                    <input type="hidden" name="return_filter" value="<?php echo esc_attr( $filter_status ); ?>">
                                    <button type="submit" class="button button-small ofp-btn-danger"
                                            onclick="return confirm('Cancel this trigger permanently?');">
                                        Cancel
                                    </button>
                                </form>
                            <?php else : ?>
                                <span class="ofp-muted">—</span>
                            <?php endif; ?>
                        </td>
Retry is capped at 3 attempts already (baked into OFP_Queue::retry() — it auto-cancels past that), so there's no risk of an admin retry-looping a dead trigger forever.

3. Communications CSV — separately downloadable
public/templates/reports.php — replace the token-handling block:

php
if ( $token ) {
    $archive = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$p}ofp_archives
             WHERE download_token = %s
               AND client_id     = %d
               AND token_expires > NOW()
             LIMIT 1",
            $token, $client->id
        )
    );

    if ( $archive && $archive->file_path ) {
        $files = explode( '|', $archive->file_path );
        // Serve the first file (leads CSV) directly.
        $file = $files[0] ?? '';
        if ( $file && file_exists( $file ) ) {
            header( 'Content-Type: text/csv' );
            header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
            header( 'Content-Length: ' . filesize( $file ) );
            readfile( $file );
            exit;
        }
    }

    // Invalid or expired token.
    $token_error = true;
}
with:

php
if ( $token ) {
    $archive = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$p}ofp_archives
             WHERE download_token = %s
               AND client_id     = %d
               AND token_expires > NOW()
             LIMIT 1",
            $token, $client->id
        )
    );

    if ( $archive && $archive->file_path ) {
        $files = explode( '|', $archive->file_path );

        // 'file' param picks which CSV: 'leads' (default, index 0) or 'comms' (index 1).
        // Both live behind the same 72-hour token — no need for a second token per file.
        $which = sanitize_text_field( $_GET['file'] ?? 'leads' );
        $index = $which === 'comms' ? 1 : 0;
        $file  = $files[ $index ] ?? '';

        if ( $file && file_exists( $file ) ) {
            header( 'Content-Type: text/csv' );
            header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
            header( 'Content-Length: ' . filesize( $file ) );
            readfile( $file );
            exit;
        }
    }

    // Invalid or expired token.
    $token_error = true;
}
Then in the archive list further down, replace the single download link:

php
                        <div style="text-align: right;">
                            <?php if ( $is_valid ) : ?>
                                <a href="<?php echo esc_url( add_query_arg( 'token', $archive->download_token, home_url( '/reports' ) ) ); ?>"
                                   style="display: inline-block; padding: 8px 16px; background: var(--accent-blue); color: #fff; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; transition: background 0.2s;" onmouseover="this.style.background='#2563eb';" onmouseout="this.style.background='var(--accent-blue)';">
                                    Download CSV
                                </a>
                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 6px;">
                                    Expires <?php echo esc_html( gmdate( 'M j', strtotime( $archive->token_expires ) ) ); ?>
                                </div>
                            <?php else : ?>
                                <div style="padding: 8px 16px; background: rgba(255,255,255,0.05); color: var(--text-muted); border-radius: 8px; font-size: 13px; font-weight: 600;">
                                    Link Expired
                                </div>
                            <?php endif; ?>
                        </div>
with:

php
                        <div style="text-align: right;">
                            <?php if ( $is_valid ) : ?>
                                <a href="<?php echo esc_url( add_query_arg( [ 'token' => $archive->download_token, 'file' => 'leads' ], home_url( '/reports' ) ) ); ?>"
                                   style="display: inline-block; padding: 8px 14px; background: var(--accent-blue); color: #fff; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none;">
                                    Leads CSV
                                </a>
                                <a href="<?php echo esc_url( add_query_arg( [ 'token' => $archive->download_token, 'file' => 'comms' ], home_url( '/reports' ) ) ); ?>"
                                   style="display: inline-block; padding: 8px 14px; background: var(--bg-lighter); border: 1px solid var(--border-color); color: var(--text-main); border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; margin-top:6px;">
                                    Comms CSV
                                </a>
                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 6px;">
                                    Expires <?php echo esc_html( gmdate( 'M j', strtotime( $archive->token_expires ) ) ); ?>
                                </div>
                            <?php else : ?>
                                <div style="padding: 8px 16px; background: rgba(255,255,255,0.05); color: var(--text-muted); border-radius: 8px; font-size: 13px; font-weight: 600;">
                                    Link Expired
                                </div>
                            <?php endif; ?>
                        </div>
admin/views/reports.php — same idea for the admin-side archive table. Replace:

php
                        <td>
                            <?php if ( $archive->download_token && strtotime( $archive->token_expires ) > time() ) : ?>
                                <a href="<?php echo esc_url(
                                    add_query_arg( 'token', $archive->download_token, home_url( '/reports' ) )
                                ); ?>" class="button button-small" target="_blank">
                                    Download
                                </a>
                            <?php else : ?>
                                <span class="ofp-muted">Expired</span>
                            <?php endif; ?>
                        </td>
with:

php
                        <td>
                            <?php if ( $archive->download_token && strtotime( $archive->token_expires ) > time() ) : ?>
                                <a href="<?php echo esc_url(
                                    add_query_arg( [ 'token' => $archive->download_token, 'file' => 'leads' ], home_url( '/reports' ) )
                                ); ?>" class="button button-small" target="_blank">
                                    Leads CSV
                                </a>
                                <a href="<?php echo esc_url(
                                    add_query_arg( [ 'token' => $archive->download_token, 'file' => 'comms' ], home_url( '/reports' ) )
                                ); ?>" class="button button-small" target="_blank">
                                    Comms CSV
                                </a>
                            <?php else : ?>
                                <span class="ofp-muted">Expired</span>
                            <?php endif; ?>
                        </td>
4. Landing page integration guide (documentation only)
docs/landing-page-integration.md (new file):

markdown
# OFast Pipeline — Landing Page Integration Guide

For any client landing page (WordPress + Elementor, on its own subdomain
or domain, no plugin installed there). This form posts straight to the
OFast Pipeline REST API on your main site.

## 1. Find the client's own values

The client can see their own values at **/api-settings** once logged in:
- **Client ID** — required, identifies which client a lead belongs to.
- **Lead Capture Endpoint** — the full URL to post to.

Or as the admin, pull the Client ID from wp-admin → OFast Pipeline → Clients → (client) → Client ID shown in the detail view.

## 2. Copy-paste snippet (Elementor "Custom HTML" widget)

Replace `CLIENT_ID_HERE` and `YOUR_TURNSTILE_SITE_KEY` below. The site key
is in wp-admin → OFast Pipeline → Settings → Cloudflare Turnstile.

```html
<form id="ofp-lead-form">
    <input type="text" name="name" placeholder="Your Name" required>
    <input type="tel" name="phone" placeholder="Phone Number" required>
    <input type="email" name="email" placeholder="Email (optional)">
    <input type="hidden" name="client_id" value="CLIENT_ID_HERE">

    <!-- Honeypot — must stay hidden and empty. Do not remove. -->
    <input type="text" name="website" style="display:none !important" tabindex="-1" autocomplete="off">

    <div class="cf-turnstile" data-sitekey="YOUR_TURNSTILE_SITE_KEY"></div>

    <button type="submit">Get Started</button>
</form>

<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<script>
document.getElementById('ofp-lead-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;

    try {
        const res = await fetch('https://YOUR-MAIN-DOMAIN.com/wp-json/ofp/v1/capture-lead', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            this.innerHTML = '<p>' + data.message + '</p>';
        } else {
            submitBtn.disabled = false;
            alert(data.message || 'Something went wrong. Please try again.');
        }
    } catch (err) {
        submitBtn.disabled = false;
        alert('Network error. Please try again.');
    }
});
</script>
```

Replace `https://YOUR-MAIN-DOMAIN.com` with your actual OFast Pipeline install
URL (the one running the plugin, not the client's own domain).

## 3. Property listing pages (only if the client is a listing subscriber)

The single-property template already includes the inquiry form with
`property_id` pre-filled — no manual setup needed. This snippet is only
for **standalone landing pages**, not property listing pages.

## 4. Testing checklist before going live

- [ ] Submit the form with a real phone number — confirm a lead appears in wp-admin → Leads.
- [ ] Confirm the instant SMS arrives within a few minutes (cron runs every 5 min).
- [ ] Try submitting the honeypot field via browser dev tools — confirm it's silently rejected.
- [ ] Submit 4 times quickly from the same IP — confirm the 4th is rate-limited.
- [ ] Confirm Turnstile actually blocks a scripted/automated submission once the secret key is set in Settings.
That's the four confirmed gaps closed. Two things I deliberately did not touch, since they're decisions rather than bugs:

The two competing credit top-up systems (/credits automated vs /funding manual) — still open, still worth a real decision before you build anything else on top of either.
Featured listing pricing — still correctly deferred per your own plan.
Test these against your LocalWP setup — activate/reactivate the plugin first so the expected_amount column gets added 






phase 19


Architected credit top-up integration across payment gateways
Locking in Option C. Here's Phase 19 — wiring the already-built hosted checkout system to an actual button, with the webhook confirmation notifying the client automatically.

What this adds
A "Quick Top-Up" card on /funding (Auto Funding tab) — client picks SMS or Voice, enters an amount, gets redirected to a real Paystack/Flutterwave/Monnify checkout page.
When the webhook confirms payment, the client now gets a bell/email notification automatically (previously confirm_credit_topup() credited the balance silently with no confirmation to the client at all).
A status banner on /funding when they return from checkout ("payment initiated, awaiting confirmation").
No gateway files need touching — initiate_transaction() and the ofp_credit_{channel}_{id}_... reference-matching in all three webhook handlers were already correctly built in Phase 6/17b. This phase only had to connect a button to code that already existed.

Manifest — Phase 19
File	Action
public/templates/funding.php	REPLACE (add Quick Top-Up card + handler)
includes/class-ofp-payment.php	REPLACE (add client notification on confirmed top-up)
1. Notify the client when their top-up actually confirms
includes/class-ofp-payment.php — replace confirm_credit_topup():

php
    /**
     * Confirm a top-up payment and credit the client balance.
     *
     * @param string $reference
     * @param float  $amount_paid
     * @param string $provider_ref
     * @return bool
     */
    public static function confirm_credit_topup( string $reference, float $amount_paid, string $provider_ref = '' ): bool {
        $parsed = self::parse_credit_topup_reference( $reference );
        if ( ! $parsed ) {
            return false;
        }

        $client = OFP_Client::get( $parsed['client_id'] );
        if ( ! $client ) {
            error_log( "OFP_Payment::confirm_credit_topup — reference {$reference} points to a missing client" );
            return false;
        }

        global $wpdb;

        $already_processed = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_credit_transactions WHERE reference = %s AND type = 'topup' LIMIT 1",
            $reference
        ) );

        if ( $already_processed ) {
            return true;
        }

        if ( $amount_paid <= 0 ) {
            error_log( "OFP_Payment::confirm_credit_topup — reference {$reference} had non-positive amount {$amount_paid}" );
            return false;
        }

        OFP_Credit::topup( $parsed['client_id'], $parsed['channel'], $amount_paid, $reference );

        // Phase 19: let the client know their top-up landed. Previously this
        // was silent — the balance updated but nothing told the client it
        // had happened, which was confusing given the checkout redirect
        // just sends them back to a "pending" page with no follow-up.
        if ( class_exists( 'OFP_Notification' ) ) {
            OFP_Notification::create(
                $parsed['client_id'],
                'credit_topup_confirmed',
                ucfirst( $parsed['channel'] ) . ' credit top-up confirmed',
                'Your top-up of NGN ' . number_format( $amount_paid, 2 ) . ' has been received and added to your '
                . strtoupper( $parsed['channel'] ) . ' credit balance.'
            );
        }

        return true;
    }
(Only change: the if ( class_exists( 'OFP_Notification' ) ) { ... } block added right before return true;.)

2. Quick Top-Up card + handler on /funding
public/templates/funding.php — three small additions.

(a) Add a handler block. Insert it right after the existing "Handle virtual account generation request" block and before "Handle manual funding form submission":

php
/* -----------------------------------------------------------
 * Handle: quick credit top-up via hosted checkout (Option C)
 *
 * Unlike the virtual account (which can't tell what a payment is
 * FOR), this builds a one-time checkout link tagged with a reference
 * like ofp_credit_sms_{client_id}_{random}. The gateway webhook
 * recognises that prefix and credits the SMS/Voice balance directly
 * — no ambiguity, no manual review needed.
 * --------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_initiate_credit_topup'] ) ) {

    if ( ! wp_verify_nonce( $_POST['ofp_topup_nonce'] ?? '', 'ofp_topup_credit_action' ) ) {
        $error = 'Security check failed — please try again.';
    } elseif ( ! class_exists( 'OFP_Payment' ) ) {
        $error = 'Payment gateway is not configured yet. Please contact support.';
    } else {

        if ( class_exists( 'OFP_Security' ) ) {
            OFP_Security::check_rate_limit( $_SERVER['REMOTE_ADDR'] ?? '', 'credit_topup_init', 5, 600 );
        }

        $topup_channel = sanitize_text_field( $_POST['topup_channel'] ?? '' );
        $topup_amount  = (float) ( $_POST['topup_amount'] ?? 0 );

        if ( ! in_array( $topup_channel, [ 'sms', 'voice' ], true ) ) {
            $error = 'Please choose SMS or Voice credit.';
        } elseif ( $topup_amount < 500 ) {
            $error = 'Minimum top-up amount is NGN 500.';
        } else {
            $checkout_url = OFP_Payment::initiate_credit_topup( $client->id, $topup_channel, $topup_amount );

            if ( $checkout_url ) {
                wp_redirect( $checkout_url );
                exit;
            }

            $error = 'Could not start the top-up right now — the payment gateway may be temporarily unavailable. Please try again shortly or use manual transfer below.';
        }
    }
}
(b) Add a status banner for when they land back from checkout. Right after the existing success-message block (if ( isset($_GET['success']) ) { ... }), add:

php
if ( isset( $_GET['topup_status'] ) && $_GET['topup_status'] === 'pending' ) {
    $success = 'Your top-up is being processed. Your credit balance will update automatically within a few minutes once payment is confirmed — you\'ll also get a notification here.';
}
(c) Add the card itself, inside <div id="tab-auto" class="ofp-funding-pane active">, right after the virtual account card/generate-button block and before the closing </div><!-- #tab-auto -->. Only shown to CRM clients since credit balances only exist for CRM subscribers:

php
    <?php if ( OFP_Subscription::has_active( 'crm', $client->id ) ) :
        $topup_credits = OFP_Credit::get( $client->id );
    ?>
    <div class="ofp-funding-card">
        <div class="ofp-funding-card-label">Automatic</div>
        <div class="ofp-funding-card-title">Quick Credit Top-Up</div>
        <div class="ofp-funding-card-desc">
            Top up your SMS or Voice credit instantly via a secure checkout page.
            Your balance updates automatically within minutes — no need to fill in a form.
        </div>

        <?php if ( $topup_credits ) : ?>
        <div style="display:flex; gap:24px; margin-bottom:20px; font-size:13px;">
            <div>
                <span style="color:var(--text-muted);">SMS Balance:</span>
                <strong style="color:var(--text-main); margin-left:6px;">NGN <?php echo esc_html( number_format( (float) $topup_credits->sms_remaining, 0 ) ); ?></strong>
            </div>
            <div>
                <span style="color:var(--text-muted);">Voice Balance:</span>
                <strong style="color:var(--text-main); margin-left:6px;">NGN <?php echo esc_html( number_format( (float) $topup_credits->voice_remaining, 0 ) ); ?></strong>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?php wp_nonce_field( 'ofp_topup_credit_action', 'ofp_topup_nonce' ); ?>

            <div class="ofp-form-row">
                <label for="ofp-topup-channel">Top Up</label>
                <select id="ofp-topup-channel" name="topup_channel" class="ofp-form-input" required>
                    <option value="sms">SMS Credit</option>
                    <option value="voice">Voice Credit</option>
                </select>
            </div>

            <div class="ofp-form-row">
                <label for="ofp-topup-amount">Amount (NGN)</label>
                <input type="number" id="ofp-topup-amount" name="topup_amount" class="ofp-form-input"
                       min="500" step="100" placeholder="e.g. 5000" required>
                <p class="ofp-hint" style="margin-top:6px;">NGN 6.99 per SMS · NGN 15.00 per voice minute</p>
            </div>

            <button type="submit" name="ofp_initiate_credit_topup" value="1" class="ofp-submit-btn">
                Pay & Top Up Now
            </button>
        </form>
    </div>
    <?php endif; ?>
That's the whole wire-up. To walk through what happens end to end now:

Client picks SMS, enters ₦5,000, hits Pay & Top Up Now.
OFP_Payment::initiate_credit_topup() builds reference ofp_credit_sms_{client_id}_{random} and calls the active gateway's initiate_transaction().
Client is redirected to the real Paystack/Flutterwave/Monnify hosted page, pays.
Gateway fires its webhook → each adapter's handle_webhook() already checks OFP_Payment::is_credit_topup_reference() first, before falling into subscription logic → routes to confirm_credit_topup().
OFP_Credit::topup() credits the balance, and now the client gets a bell/email notification.
Client's browser redirect lands back on /funding?topup_status=pending showing the banner while the webhook (usually near-instant) does its thing.
One thing worth flagging while it's fresh: this only works for gateways that have initiate_transaction() implemented. Monnify's version builds a Monnify-hosted checkout page — different from its virtual account flow — so double check in Settings which gateway is actually active before testing, since the three behave slightly differently on the merchant dashboard side (Paystack/Flutterwave redirect to their own branded page; Monnify's checkout init returns a checkoutUrl too, should behave the same from the client's perspective).

Test this end-to-end with sandbox credentials before going live — specifically confirm the webhook actually fires for the checkout-link flow and not just the virtual-account flow, since those can sometimes be configured as separate webhook URLs in a gateway dashboard.

all the corrections you have made, i can copy all and paste it as an md or text file in vscode and claude can fix it right or


Evaluated feasibility of bulk code corrections and structured guidance
Evaluated feasibility of bulk code corrections and structured guidance
Yes, that'll work — but a couple of practical notes so it goes smoothly rather than causing a mess.

It'll work because:

Every change I gave you is already labeled ADD/REPLACE/full-method-swap, matching your usual delivery format.
Claude Code (or Claude in VS Code) can read your actual project files, so it'll apply each snippet against the real current state of the file rather than guessing.
A few things to watch out for:

Be explicit about scope per file. Some of my instructions said "replace this one method" (like process_payment() in the gateway files) rather than "replace the whole file." If you just paste the whole conversation in, make sure the instructions still read as targeted edits — otherwise there's a risk Claude Code tries to regenerate an entire file from scratch and drops something that wasn't shown here (like a method I didn't touch).
Give it the actual files, not just my snippets. Rather than only pasting my replies, either:
Open the real files in VS Code so Claude Code can see current state and diff against it, or
Paste both "here's the current file" and "here's what changes" if you're doing it via chat-paste rather than an agentic tool with file access.
One phase at a time. Since you asked for Phase 18 then Phase 19 as two separate turns, I'd literally split this into two files/two prompts (phase18-fixes.md, phase19-topup.md) rather than one giant dump — keeps it matching your test-between-phases habit, and makes it easier to tell if something breaks which phase caused it.
Double-check the DB migration lands first. Phase 18 added the expected_amount column via maybe_upgrade_schema(). That only runs on plugin activation — so after Claude Code applies the file change, you still need to deactivate/reactivate the plugin yourself (or it won't fire). Worth a note-to-self in the file so you don't forget mid-session.
Flag the one cross-file dependency. Phase 18's three gateway file changes depend on the new OFP_Subscription::process_gateway_payment() method existing — so if Claude Code applies them out of order or only applies some files, you'd get a fatal error calling an undefined method. Worth saying explicitly in your prompt: "apply all files in this phase together, don't skip any."