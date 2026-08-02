Got it — Phase 22, building on your Phase 21 audit trail / free Bronze / property menu changes. One thing your Bronze change immediately affects: my Phase 20 "Pay Now" listing card assumes every plan has a price > 0. If Bronze is now free, that card would either show "Pay NGN 0 Now" (confusing) or silently fail (initiate_subscription_checkout() returns null when amount <= 0). I'm fixing that as part of this phase since it's a direct collision with what I built, not a separate ask.

Manifest — Phase 22
File	Action
includes/class-ofp-subscription.php	REPLACE (underpaid helper + free-plan auto-activate)
includes/class-ofp-payment.php	REPLACE (custom-amount checkout for shortfall payments)
public/templates/properties.php	REPLACE (free-plan auto-activate on selection)
public/templates/dashboard.php	REPLACE (underpaid-aware banner)
public/templates/funding.php	REPLACE (shortfall Pay Now variant, hide free-plan card)
admin/views/manage-admins.php	REPLACE (orphaned-row detection)
admin/class-ofp-admin-menu.php	REPLACE (rate limit on preview token)
includes/class-ofp-pipeline-audio.php	ADD (voice audio upload handling)
includes/class-ofp-ivr.php	REPLACE (<Play> support)
includes/class-ofp-voice.php	REPLACE (<Play> support)
public/templates/pipeline-settings.php	REPLACE (audio upload field)
1. Free plans (Bronze) — auto-activate, no checkout needed

includes/class-ofp-subscription.php — add these two methods (place near record_underpayment):

php
    /**
     * All subscription rows currently sitting at 'underpaid' for a client.
     * Used by the client dashboard banner (Phase 22) to show exactly what's
     * still owed, rather than a generic "you have something unpaid" message.
     *
     * @param  int $client_id
     * @return array
     */
    public static function get_underpaid_for_client( int $client_id ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_subscriptions
                 WHERE client_id = %d AND status = 'underpaid'
                 ORDER BY created_at DESC",
                $client_id
            )
        );
    }

    /**
     * Whether a client has any subscription row still needing payment
     * (pending, or underpaid). Distinct from has_active(), which only
     * checks for a currently paid+valid subscription.
     *
     * @param  int $client_id
     * @return bool
     */
    public static function has_unpaid( int $client_id ): bool {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_subscriptions
                 WHERE client_id = %d AND status IN ('pending','underpaid')
                 LIMIT 1",
                $client_id
            )
        );
    }

    /**
     * Activate a listing (or CRM) subscription immediately at zero cost —
     * for free tiers like Bronze. Skips checkout entirely since there's
     * nothing to pay. Mirrors activate_from_manual_payment() but tagged
     * as 'free_tier' in payment_method for clear billing-log attribution.
     *
     * @param  int    $client_id
     * @param  string $type  'crm' or 'listing'.
     * @param  string|null $plan
     * @return void
     */
    public static function activate_free_tier( int $client_id, string $type, ?string $plan = null ): void {
        global $wpdb;

        $period_end = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

        $wpdb->insert(
            $wpdb->prefix . 'ofp_subscriptions',
            [
                'client_id'      => $client_id,
                'type'           => $type,
                'plan'           => $plan,
                'amount'         => 0,
                'payment_method' => 'free_tier',
                'status'         => 'paid',
                'period_start'   => gmdate( 'Y-m-d' ),
                'period_end'     => $period_end,
                'paid_at'        => current_time( 'mysql' ),
                'created_at'     => current_time( 'mysql' ),
            ]
        );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}ofp_clients
             SET status = 'active',
                 subscription_expires = GREATEST( subscription_expires, %s ),
                 updated_at = NOW()
             WHERE id = %d",
            $period_end, $client_id
        ) );
    }

public/templates/properties.php — replace the plan-selection handler's success branch. Find:

php
        } else {
            OFP_Subscription::create( $client->id, 'listing', $chosen_plan );
            wp_safe_redirect( add_query_arg( 'success', 'plan', home_url( '/funding' ) ) );
            exit;
        }

with:

php
        } else {
            $plan_price = OFP_Property_CPT::get_plan_price( $chosen_plan );

            if ( $plan_price <= 0 ) {
                // Free tier (e.g. Bronze) — activate immediately, no checkout needed.
                OFP_Subscription::activate_free_tier( $client->id, 'listing', $chosen_plan );

                OFP_Notification::create(
                    $client->id,
                    'listing_plan_activated_free',
                    'Listing plan activated',
                    'Your ' . ucfirst( $chosen_plan ) . ' listing plan is now active — no payment required.'
                );

                wp_safe_redirect( add_query_arg( 'success', 'plan_free', home_url( '/properties' ) ) );
                exit;
            }

            OFP_Subscription::create( $client->id, 'listing', $chosen_plan );

            OFP_Notification::create(
                $client->id,
                'listing_plan_selected',
                'Listing plan selected — payment needed',
                'You selected the ' . ucfirst( $chosen_plan ) . ' plan. Head to Funding to complete payment and activate it.'
            );

            wp_safe_redirect( add_query_arg( 'success', 'plan', home_url( '/funding' ) ) );
            exit;
        }

Add the new success message near your existing if ( isset($_GET['success']) ) block:

php
    if ( $_GET['success'] === 'plan_free' ) $success = 'Your free listing plan is active — you can add properties now.';

public/templates/funding.php — the listing Pay Now card should simply not render for a free plan (nothing to pay). Wrap its existing condition:

php
    <?php
    $pending_listing_plan = OFP_Subscription::get_latest_listing_plan_for_client( $client->id );
    $listing_price        = $pending_listing_plan ? OFP_Property_CPT::get_plan_price( $pending_listing_plan ) : 0;
    if ( $pending_listing_plan && $listing_price > 0 ) :
    ?>

(replaces the old if ( $pending_listing_plan ) : line — same closing <?php endif; ?> stays as-is.)

2. Underpaid-aware dashboard banner + "pay remaining balance"

includes/class-ofp-payment.php — add an optional override-amount parameter to the subscription checkout initiator, so a shortfall can be paid exactly rather than re-charging the full plan price. Replace initiate_subscription_checkout():

php
    /**
     * Initiate a hosted checkout for a CRM or Listing subscription payment.
     *
     * @param  int         $client_id
     * @param  string      $type            'crm' or 'listing'.
     * @param  string|null $plan            Required for 'listing'.
     * @param  float|null  $override_amount Phase 22: pay this exact amount instead
     *                                      of the full plan price — used to let a
     *                                      client pay off an underpayment shortfall
     *                                      without re-charging the whole plan.
     * @return string|null                  Checkout URL, or null on failure.
     */
    public static function initiate_subscription_checkout( int $client_id, string $type, ?string $plan = null, ?float $override_amount = null ): ?string {
        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            return null;
        }

        if ( $type === 'crm' ) {
            $amount      = $override_amount ?? OFP_Subscription::get_plan_price( $client->plan );
            $description = 'CRM Plan Payment — ' . ucfirst( (string) $client->plan );
        } elseif ( $type === 'listing' ) {
            if ( ! $plan || ! in_array( $plan, OFP_Property_CPT::PLAN_KEYS, true ) ) {
                return null;
            }
            $amount      = $override_amount ?? OFP_Property_CPT::get_plan_price( $plan );
            $description = 'Listing Plan Payment — ' . ucfirst( $plan );
        } else {
            return null;
        }

        if ( $amount <= 0 ) {
            return null;
        }

        $reference = self::generate_subscription_checkout_reference( $client_id, $type, $plan );
        $gateway   = self::get_gateway();

        if ( ! $gateway || ! method_exists( $gateway, 'initiate_transaction' ) ) {
            error_log( 'OFP_Payment::initiate_subscription_checkout — active gateway missing initiate_transaction().' );
            return null;
        }

        return $gateway->initiate_transaction( [
            'client_id'    => $client_id,
            'amount'       => $amount,
            'reference'    => $reference,
            'email'        => $client->email,
            'name'         => $client->owner_name,
            'phone'        => $client->phone,
            'description'  => $description,
            'redirect_url' => home_url( '/funding?sub_status=pending' ),
        ] );
    }

public/templates/funding.php — extend the checkout handler to accept a shortfall amount. Replace:

php
            $checkout_url = OFP_Payment::initiate_subscription_checkout(
                $client->id,
                $sub_type,
                $sub_type === 'listing' ? $sub_plan : null
            );

with:

php
            $override_amount = isset( $_POST['sub_override_amount'] ) && (float) $_POST['sub_override_amount'] > 0
                ? (float) $_POST['sub_override_amount']
                : null;

            $checkout_url = OFP_Payment::initiate_subscription_checkout(
                $client->id,
                $sub_type,
                $sub_type === 'listing' ? $sub_plan : null,
                $override_amount
            );

Add a shortfall card right after the two existing Pay Now cards in the Auto Funding tab:

php
    <?php
    $underpaid_rows = OFP_Subscription::get_underpaid_for_client( $client->id );
    foreach ( $underpaid_rows as $underpaid ) :
        $shortfall = max( 0, (float) $underpaid->expected_amount - (float) $underpaid->amount );
        if ( $shortfall <= 0 ) continue;
    ?>
    <div class="ofp-funding-card" style="border-color: rgba(239,68,68,0.3);">
        <div class="ofp-funding-card-label" style="color:var(--accent-red);">Balance Owed</div>
        <div class="ofp-funding-card-title"><?php echo esc_html( ucfirst( $underpaid->type ) ); ?> Plan — Remaining Balance</div>
        <div class="ofp-funding-card-desc">
            You paid NGN <?php echo esc_html( number_format( (float) $underpaid->amount, 2 ) ); ?> toward this,
            but NGN <?php echo esc_html( number_format( (float) $underpaid->expected_amount, 2 ) ); ?> was expected.
            Pay the remaining balance below to activate.
        </div>
        <form method="POST" action="">
            <?php wp_nonce_field( 'ofp_sub_checkout_action', 'ofp_sub_checkout_nonce' ); ?>
            <input type="hidden" name="sub_type" value="<?php echo esc_attr( $underpaid->type ); ?>">
            <input type="hidden" name="sub_plan" value="<?php echo esc_attr( $underpaid->plan ); ?>">
            <input type="hidden" name="sub_override_amount" value="<?php echo esc_attr( $shortfall ); ?>">
            <button type="submit" name="ofp_initiate_subscription_checkout" value="1" class="ofp-submit-btn">
                Pay NGN <?php echo esc_html( number_format( $shortfall, 0 ) ); ?> Remaining
            </button>
        </form>
    </div>
    <?php endforeach; ?>

public/templates/dashboard.php — replace the existing pending/grace alert block to also cover underpaid + generic unpaid, with a Pay Now link:

php
    <?php if ( in_array( $client->status, [ 'grace', 'pending_review' ], true ) ) : ?>
        <div class="ofp-alert ofp-alert-warning">
            <?php if ( $client->status === 'grace' ) : ?>
                ⚠️ Your subscription expired. You are in a <strong>5-day grace period</strong>. Please renew.
            <?php else : ?>
                ⏳ Your account is <strong>pending review</strong>. We will notify you once approved.
            <?php endif; ?>
            <?php if ( $client->virtual_account_number ) : ?>
                <div style="margin-top:8px;">
                    Pay to: <strong><?php echo esc_html( $client->virtual_bank_name ); ?></strong>
                    — <strong><?php echo esc_html( $client->virtual_account_number ); ?></strong>
                </div>
            <?php endif; ?>
        </div>
    <?php elseif ( OFP_Subscription::has_unpaid( $client->id ) ) :
        $underpaid_count = count( OFP_Subscription::get_underpaid_for_client( $client->id ) );
    ?>
        <div class="ofp-alert ofp-alert-warning" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <span>
                💳 <?php echo $underpaid_count > 0
                    ? 'You have a subscription payment that came up short — a balance is still owed.'
                    : 'You have a subscription awaiting payment.'; ?>
            </span>
            <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>" class="ofp-btn-accent">Pay Now →</a>
        </div>
    <?php endif; ?>
3. Orphaned co-admin rows — surfaced for cleanup

admin/views/manage-admins.php — add an orphan check right after $current_admin = OFP_Auth::current_admin();:

php
// Phase 22: flag ofp_admins rows whose email doesn't match any current
// WordPress user — these are orphaned (e.g. the WP user account was
// deleted from Users, but nobody removed the corresponding OFP admin row).
// They can't log in (no WP account to authenticate against) but they sit
// here indefinitely unless a super admin notices and removes them.
$orphaned_admin_ids = [];
foreach ( $admins as $admin ) {
    if ( $admin->is_protected ) continue; // super admin's own row — never orphaned in practice
    $wp_user = get_user_by( 'email', $admin->email );
    if ( ! $wp_user ) {
        $orphaned_admin_ids[] = $admin->id;
    }
}

Add a warning box right after the <div class="ofp-info-box">...</div> block:

php
<?php if ( ! empty( $orphaned_admin_ids ) ) : ?>
<div class="ofp-info-box" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
    <p>
        ⚠️ <strong><?php echo esc_html( count( $orphaned_admin_ids ) ); ?> admin(s) below have no matching WordPress user account</strong> —
        their WP account was likely deleted from Users. They can no longer log in, but their OFP admin row
        (marked <em>Orphaned</em> below) is still here. Consider removing them.
    </p>
</div>
<?php endif; ?>

Add the badge in the table row — inside the existing <td><strong>...</strong> name cell, right after the "You" badge:

php
                        <?php if ( in_array( (int) $admin->id, $orphaned_admin_ids, true ) ) : ?>
                            <span class="ofp-badge ofp-badge-red">Orphaned</span>
                        <?php endif; ?>

This is detection/visibility only, deliberately not auto-delete — removing an admin is destructive and should stay a deliberate super-admin click via the existing Remove button, which already works fine on non-protected rows.

4. Rate limit on admin preview token generation

admin/class-ofp-admin-menu.php — in handle_preview_client(), add a rate-limit check right after require_admin_post:

php
    public function handle_preview_client(): void {

        $this->require_admin_post( 'ofp_preview_client' );

        // Phase 22: cap preview-token generation per admin per window —
        // low risk since it already requires authenticated admin access,
        // but cheap to close off entirely.
        OFP_Security::check_rate_limit(
            OFP_Security::get_client_ip(),
            'admin_preview_generate',
            10,
            300
        );

        $client_id = (int) ( $_POST['client_id'] ?? 0 );
        $client    = OFP_Client::get( $client_id );

        if ( ! $client ) {
            $this->set_message( '❌ Client not found.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients' ) );
            exit;
        }

        $token = OFP_Auth::generate_admin_preview_token( $client_id );
        $url   = add_query_arg( 'admin_preview', $token, home_url( '/login' ) );

        wp_safe_redirect( $url );
        exit;
    }

10 per 5 minutes is generous for legitimate support use, tight enough to stop abuse.

5. Voice audio upload (alternative to TTS)

Scoped as: one optional MP3/WAV per client, uploaded on Pipeline Settings, used as the IVR greeting instead of TTS when present. Falls back to <Say> automatically if no file is uploaded — nothing breaks for existing clients.

includes/class-ofp-pipeline-audio.php (new file):

php
<?php
/**
 * OFP_Pipeline_Audio
 *
 * Handles upload, storage, and validation of a client's custom IVR
 * greeting audio file — the alternative to TTS flagged as missing since
 * v2.0 Section 23.4.
 *
 * DESIGN:
 *  - One optional audio file per client, stored on pipeline_configs
 *    as `voice_audio_url`.
 *  - MP3 or WAV only, max 5MB (matches the original v2.0 spec).
 *  - Africa's Talking's <Play> XML tag is used instead of <Say> when
 *    a URL is present — the URL must be a real publicly-reachable
 *    file, so we store it in wp-content/uploads (already public).
 *  - If no audio is uploaded, IVR falls back to TTS exactly as before.
 *    Nothing changes for clients who never touch this feature.
 *
 * Depends on: wp_handle_upload(), ofp_pipeline_configs.voice_audio_url column.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Pipeline_Audio {

    const ALLOWED_MIMES = [ 'audio/mpeg', 'audio/wav', 'audio/x-wav' ];
    const MAX_BYTES      = 5 * 1024 * 1024; // 5MB

    /**
     * Handle an uploaded audio file for a client's IVR greeting.
     * Stores the file and updates pipeline_configs.voice_audio_url.
     *
     * @param  int   $client_id
     * @param  array $file  A single entry from $_FILES.
     * @return string|false Public URL on success, false on failure/validation error.
     */
    public static function handle_upload( int $client_id, array $file ): string|false {

        if ( empty( $file['tmp_name'] ) || $file['error'] !== UPLOAD_ERR_OK ) {
            return false;
        }

        if ( $file['size'] > self::MAX_BYTES ) {
            return false;
        }

        $finfo = new finfo( FILEINFO_MIME_TYPE );
        $mime  = $finfo->file( $file['tmp_name'] );

        if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
            return false;
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $movefile = wp_handle_upload( $file, [ 'test_form' => false ] );

        if ( ! $movefile || isset( $movefile['error'] ) ) {
            return false;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ofp_pipeline_configs',
            [ 'voice_audio_url' => esc_url_raw( $movefile['url'] ) ],
            [ 'client_id' => $client_id ]
        );

        return $movefile['url'];
    }

    /**
     * Remove a client's custom IVR audio, reverting them to TTS.
     *
     * @param  int $client_id
     * @return void
     */
    public static function remove( int $client_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ofp_pipeline_configs',
            [ 'voice_audio_url' => null ],
            [ 'client_id' => $client_id ]
        );
    }

    /**
     * Get a client's current custom IVR audio URL, if any.
     *
     * @param  int $client_id
     * @return string|null
     */
    public static function get_url( int $client_id ): ?string {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT voice_audio_url FROM {$wpdb->prefix}ofp_pipeline_configs WHERE client_id = %d",
            $client_id
        ) );
    }
}

Add the schema column — includes/class-ofp-activator.php, inside maybe_upgrade_schema(), append:

php
        // voice_audio_url column on ofp_pipeline_configs (Phase 22 — custom IVR audio).
        $audio_col_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM {$p}ofp_pipeline_configs LIKE 'voice_audio_url'"
        );
        if ( empty( $audio_col_exists ) ) {
            $wpdb->query( "ALTER TABLE {$p}ofp_pipeline_configs ADD COLUMN voice_audio_url VARCHAR(255) DEFAULT NULL AFTER followup_2_message" );
        }

includes/class-ofp-ivr.php — replace build_menu() to support <Play>:

php
    /**
     * Build the IVR menu XML.
     * Africa's Talking reads the <Say> text (or plays the audio file, if the
     * client has uploaded one) to the lead and waits for a digit.
     *
     * @param  string      $message    The script read to the lead (TTS fallback).
     * @param  string|null $audio_url  Optional custom audio URL — used instead
     *                                 of TTS when present (Phase 22).
     * @return string                  Valid AT Voice XML.
     */
    public static function build_menu( string $message, ?string $audio_url = null ): string {
        $callback = home_url( '/wp-json/ofp/v1/webhook/voice-ivr' );

        $prompt = $audio_url
            ? '<Play url="' . esc_url( $audio_url ) . '"/>'
            : '<Say>' . esc_html( $message ) . '</Say>';

        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<Response>' .
                '<GetDigits timeout="30" finishOnKey="#" callbackUrl="' . esc_url( $callback ) . '">' .
                    $prompt .
                '</GetDigits>' .
                '<Say>We did not receive your response. We will try again soon. Goodbye.</Say>' .
            '</Response>';
    }

In handle_callback(), update the "no digit yet" block to pass the audio URL through. Replace:

php
        // ── No digit yet: serve the IVR menu ─────────────────────────────────
        if ( empty( $digit ) ) {
            $menu_message = $config->followup_2_message
                ?: 'Hello, thank you for your interest. Press 1 to speak with us now. Press 2 to receive our WhatsApp contact via SMS. Press 3 to request a callback later. Press hash when done.';

            // Personalise the message.
            $menu_message = str_replace(
                [ '{{name}}', '{{business_name}}' ],
                [ $lead->name ?: 'there', $client->business_name ],
                $menu_message
            );

            echo self::build_menu( $menu_message );
            exit;
        }

with:

php
        // ── No digit yet: serve the IVR menu ─────────────────────────────────
        if ( empty( $digit ) ) {
            $menu_message = $config->followup_2_message
                ?: 'Hello, thank you for your interest. Press 1 to speak with us now. Press 2 to receive our WhatsApp contact via SMS. Press 3 to request a callback later. Press hash when done.';

            // Personalise the message.
            $menu_message = str_replace(
                [ '{{name}}', '{{business_name}}' ],
                [ $lead->name ?: 'there', $client->business_name ],
                $menu_message
            );

            // Phase 22: use the client's uploaded audio instead of TTS if present.
            $audio_url = isset( $config->voice_audio_url ) ? $config->voice_audio_url : null;

            echo self::build_menu( $menu_message, $audio_url );
            exit;
        }

includes/class-ofp-voice.php — the outbound call itself doesn't need changes; AT fetches the callback URL fresh when the lead answers, and build_menu() (called from handle_callback()) already decides <Play> vs <Say>. No edit needed here — noting it explicitly so it's not assumed missing.

public/templates/pipeline-settings.php — add an upload field. Insert this new card right after the "Follow-up 2 (Voice Call / IVR)" card's closing </div></div></div> (i.e., as a new timeline step, before the IVR Actions step):

php
                <!-- ── Custom IVR Audio (Phase 22) ─────────────────────────── -->
                <div class="ofp-timeline-step">
                    <div class="ofp-timeline-badge"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"></path><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="16" r="3"></circle></svg></div>
                    <div class="ofp-timeline-content">
                        <div class="ofp-card">
                            <div class="ofp-card-header">
                                <h3>Custom Voice Greeting (Optional)</h3>
                            </div>
                            <p class="ofp-hint" style="margin-bottom:16px;">
                                Upload a real recorded voice message instead of text-to-speech for your IVR call.
                                MP3 or WAV, max 5MB. Leave blank to keep using text-to-speech.
                            </p>

                            <?php $current_audio = OFP_Pipeline_Audio::get_url( $client->id ); ?>
                            <?php if ( $current_audio ) : ?>
                                <div style="margin-bottom:16px;">
                                    <audio controls src="<?php echo esc_url( $current_audio ); ?>" style="width:100%;"></audio>
                                    <form method="POST" action="" style="margin-top:8px;">
                                        <?php wp_nonce_field( 'ofp_save_pipeline_' . $client->id, 'ofp_pipeline_nonce' ); ?>
                                        <input type="hidden" name="ofp_remove_audio" value="1">
                                        <button type="submit" class="ofp-btn ofp-btn-secondary" style="font-size:13px;padding:6px 14px;"
                                                onclick="return confirm('Remove custom audio and revert to text-to-speech?');">
                                            Remove Audio — Use TTS Instead
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="" enctype="multipart/form-data">
                                <?php wp_nonce_field( 'ofp_save_pipeline_' . $client->id, 'ofp_pipeline_nonce' ); ?>
                                <div class="ofp-field" style="margin-bottom:12px;">
                                    <input type="file" name="voice_audio" accept="audio/mpeg,audio/wav">
                                </div>
                                <button type="submit" name="ofp_upload_audio" value="1" class="ofp-btn ofp-btn-primary">
                                    Upload Audio
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

Add the handler at the top of the file, alongside the existing settings-save handler. Find the existing if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_pipeline_nonce'] ) ) { block and add these two branches before the existing $wpdb->update(...) save logic runs (i.e., check these first and exit/skip the rest if matched):

php
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_pipeline_nonce'] ) ) {

    if ( ! wp_verify_nonce(
        sanitize_text_field( wp_unslash( $_POST['ofp_pipeline_nonce'] ) ),
        'ofp_save_pipeline_' . $client->id
    ) ) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif ( isset( $_POST['ofp_remove_audio'] ) ) {
        OFP_Pipeline_Audio::remove( $client->id );
        $saved = true;
    } elseif ( isset( $_POST['ofp_upload_audio'] ) && ! empty( $_FILES['voice_audio']['name'] ) ) {
        $uploaded = OFP_Pipeline_Audio::handle_upload( $client->id, $_FILES['voice_audio'] );
        if ( $uploaded ) {
            $saved = true;
        } else {
            $error = 'Upload failed. Please use an MP3 or WAV file under 5MB.';
        }
    } else {
        // ... existing $wpdb->update(...) pipeline save logic stays exactly as-is here ...

(Close that else { block's brace at the same point the existing save logic currently ends — everything between else { and the original closing } is unchanged.)

Finally, add the require to your autoloader — ofast-pipeline.php, right after require_once OFP_PATH . 'includes/class-ofp-ivr.php';:

php
require_once OFP_PATH . 'includes/class-ofp-pipeline-audio.php';
Manifest recap — dependency note

Same rule as before: this phase touches OFP_Subscription, OFP_Payment, and the funding/dashboard/properties templates together — the shortfall-payment feature depends on all of them landing at once (the new $override_amount param won't exist if class-ofp-payment.php isn't updated alongside funding.php). Apply the whole phase together, then deactivate/reactivate the plugin once for the new voice_audio_url column to appear.

Test order I'd suggest: (1) select a free Bronze plan on /properties, confirm instant activation with no /funding redirect; (2) manually force an underpaid row via a test webhook or DB edit, confirm the dashboard banner and shortfall card both show the right numbers; (3) upload a short MP3 on Pipeline Settings, trigger a test voice call, confirm AT plays it instead of TTS.