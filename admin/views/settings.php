<?php
/**
 * Admin View: Settings (Super Admin Only)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_super_admin() ) wp_die( 'Access denied.' );

$active_provider = get_option( 'ofp_payment_provider', 'monnify' );

include OFP_PATH . 'admin/views/partials/header.php';
?>

<h2>Settings</h2>
<p>Sensitive fields (passwords, API keys) are stored encrypted. Leave a key field blank to keep the existing value.</p>

<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ofp-form">
    <?php wp_nonce_field( 'ofp_save_settings' ); ?>
    <input type="hidden" name="action" value="ofp_save_settings">

    <!-- ── DEFAULT PIPELINE MESSAGES ─────────────────────────────────────── -->
    <div class="ofp-settings-section">
        <h3> Default Pipeline Messages</h3>
        <p class="ofp-hint">
            These are the default messages pre-filled when a new client is onboarded.
            Each client can customise their own messages from their dashboard → Pipeline Settings.<br>
            <strong>Placeholders:</strong> <code>{{name}}</code> <code>{{phone}}</code> <code>{{business_name}}</code>
        </p>
        <div class="ofp-form-grid">
            <div class="ofp-field ofp-field-full">
                <label>Instant SMS Message</label>
                <textarea name="ofp_default_instant_sms" rows="3" placeholder="Hi {{name}}, thank you for your interest! We will be in touch shortly. - {{business_name}}"><?php echo esc_textarea( get_option( 'ofp_default_instant_sms', '' ) ); ?></textarea>
                <p class="ofp-hint">Sent immediately when a lead submits the form. Keep under 160 characters for a single SMS.</p>
            </div>
            <div class="ofp-field ofp-field-full">
                <label>Follow-up 1 Message (SMS — default 1 hour later)</label>
                <textarea name="ofp_default_followup_1" rows="3" placeholder="Hi {{name}}, just checking in — did you get our message? We would love to help. - {{business_name}}"><?php echo esc_textarea( get_option( 'ofp_default_followup_1', '' ) ); ?></textarea>
            </div>
            <div class="ofp-field ofp-field-full">
                <label>Follow-up 2 Message (Voice/IVR — default 24 hours later)</label>
                <textarea name="ofp_default_followup_2" rows="3" placeholder="Hello, this is a message from {{business_name}}. You recently showed interest in our services. Press 1 to speak with us now. Press 2 for our WhatsApp contact. Press 3 for a callback later."><?php echo esc_textarea( get_option( 'ofp_default_followup_2', '' ) ); ?></textarea>
                <p class="ofp-hint">This is read aloud during the IVR voice call. Write it as natural speech.</p>
            </div>
            <div class="ofp-field ofp-field-full">
                <label>Follow-up 3 Message (SMS — default 72 hours later)</label>
                <textarea name="ofp_default_followup_3" rows="3" placeholder="Hi {{name}}, we have been trying to reach you. We would love to show you how {{business_name}} can help. Call or message us anytime."><?php echo esc_textarea( get_option( 'ofp_default_followup_3', '' ) ); ?></textarea>
            </div>
        </div>
    </div>

    <!-- ── SMTP ───────────────────────────────────────────────────────────── -->
    <div class="ofp-settings-section">
        <h3> SMTP Configuration</h3>
        <p class="ofp-hint">
            Only fill this in if <strong>Ofast Toolkit SMTP</strong> is not active on this site.
            When Ofast Toolkit SMTP is enabled (priority 999), it handles all delivery automatically
            and these fields are not used.
        </p>
        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label>SMTP Host</label>
                <input type="text" name="ofp_smtp_host"
                       value="<?php echo esc_attr( get_option( 'ofp_smtp_host', '' ) ); ?>"
                       placeholder="e.g. smtp-relay.brevo.com">
            </div>
            <div class="ofp-field">
                <label>SMTP Port</label>
                <input type="number" name="ofp_smtp_port"
                       value="<?php echo esc_attr( get_option( 'ofp_smtp_port', 587 ) ); ?>">
            </div>
            <div class="ofp-field">
                <label>SMTP Username</label>
                <input type="text" name="ofp_smtp_username"
                       value="<?php echo esc_attr( get_option( 'ofp_smtp_username', '' ) ); ?>">
            </div>
            <div class="ofp-field">
                <label>SMTP Password</label>
                <input type="password" name="ofp_smtp_password" placeholder="Leave blank to keep existing">
            </div>
            <div class="ofp-field">
                <label>Encryption</label>
                <select name="ofp_smtp_encryption">
                    <option value="tls" <?php selected( get_option( 'ofp_smtp_encryption', 'tls' ), 'tls' ); ?>>TLS (recommended)</option>
                    <option value="ssl" <?php selected( get_option( 'ofp_smtp_encryption', 'tls' ), 'ssl' ); ?>>SSL</option>
                </select>
            </div>
            <div class="ofp-field">
                <label>From Email</label>
                <input type="email" name="ofp_smtp_from_email"
                       value="<?php echo esc_attr( get_option( 'ofp_smtp_from_email', '' ) ); ?>"
                       placeholder="noreply@ofastpipeline.com">
            </div>
            <div class="ofp-field">
                <label>From Name</label>
                <input type="text" name="ofp_smtp_from_name"
                       value="<?php echo esc_attr( get_option( 'ofp_smtp_from_name', 'OFast Pipeline' ) ); ?>">
            </div>
        </div>
        <div class="ofp-form-actions" style="border:0;padding:0;margin-top:12px;">
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_test_email' ), 'ofp_test_email' ) ); ?>"
               class="button"
               onclick="return confirm('Send a test email to your admin address?');"
            > Send Test Email to Me</a>
        </div>
    </div>

    <!-- ── PAYMENT GATEWAY ────────────────────────────────────────────────── -->
    <div class="ofp-settings-section">
        <h3> Payment Gateway</h3>
        <p class="ofp-hint">
            Select your active payment provider. All providers create dedicated virtual accounts
            per client. Switching provider here requires no code changes — only credentials below.
        </p>

        <div class="ofp-field" style="max-width:300px;margin-bottom:20px;">
            <label>Active Provider</label>
            <select name="ofp_payment_provider" id="ofp-payment-provider">
                <option value="monnify"     <?php selected( $active_provider, 'monnify' ); ?>>Monnify</option>
                <option value="paystack"    <?php selected( $active_provider, 'paystack' ); ?>>Paystack</option>
                <option value="flutterwave" <?php selected( $active_provider, 'flutterwave' ); ?>>Flutterwave</option>
            </select>
        </div>

        <!-- Monnify credentials -->
        <div class="ofp-gateway-fields" id="ofp-fields-monnify"
             style="<?php echo $active_provider !== 'monnify' ? 'display:none;' : ''; ?>">
            <h4>Monnify Credentials</h4>
            <div class="ofp-form-grid">
                <div class="ofp-field">
                    <label>API Key</label>
                    <input type="password" name="ofp_monnify_api_key" placeholder="Leave blank to keep existing">
                </div>
                <div class="ofp-field">
                    <label>Secret Key</label>
                    <input type="password" name="ofp_monnify_secret_key" placeholder="Leave blank to keep existing">
                </div>
                <div class="ofp-field">
                    <label>Contract Code</label>
                    <input type="text" name="ofp_monnify_contract_code"
                           value="<?php echo esc_attr( get_option( 'ofp_monnify_contract_code', '' ) ); ?>">
                </div>
                <div class="ofp-field">
                    <label>Base URL</label>
                    <input type="url" name="ofp_monnify_base_url"
                           value="<?php echo esc_attr( get_option( 'ofp_monnify_base_url', 'https://api.monnify.com' ) ); ?>">
                    <p class="ofp-hint">Use https://sandbox.monnify.com for testing.</p>
                </div>
            </div>
        </div>

        <!-- Paystack credentials -->
        <div class="ofp-gateway-fields" id="ofp-fields-paystack"
             style="<?php echo $active_provider !== 'paystack' ? 'display:none;' : ''; ?>">
            <h4>Paystack Credentials</h4>
            <div class="ofp-form-grid">
                <div class="ofp-field">
                    <label>Secret Key</label>
                    <input type="password" name="ofp_paystack_secret_key" placeholder="Leave blank to keep existing">
                    <p class="ofp-hint">Starts with sk_live_ (production) or sk_test_ (sandbox).</p>
                </div>
            </div>
            <p class="ofp-hint">Webhook URL to configure in Paystack dashboard:
                <code><?php echo esc_url( home_url( '/wp-json/ofp/v1/webhook/payment' ) ); ?></code>
            </p>
        </div>

        <!-- Flutterwave credentials -->
        <div class="ofp-gateway-fields" id="ofp-fields-flutterwave"
             style="<?php echo $active_provider !== 'flutterwave' ? 'display:none;' : ''; ?>">
            <h4>Flutterwave Credentials</h4>
            <div class="ofp-form-grid">
                <div class="ofp-field">
                    <label>Secret Key</label>
                    <input type="password" name="ofp_flutterwave_secret_key" placeholder="Leave blank to keep existing">
                </div>
                <div class="ofp-field">
                    <label>Webhook Secret Hash</label>
                    <input type="password" name="ofp_flutterwave_secret_hash" placeholder="Leave blank to keep existing">
                    <p class="ofp-hint">Set this in your Flutterwave dashboard under Webhooks.</p>
                </div>
            </div>
            <p class="ofp-hint">Webhook URL to configure in Flutterwave dashboard:
                <code><?php echo esc_url( home_url( '/wp-json/ofp/v1/webhook/payment' ) ); ?></code>
            </p>
        </div>
    </div>

    <!-- ── Africa's Talking ───────────────────────────────────────────────── -->
    <div class="ofp-settings-section">
        <h3> Africa's Talking (SMS & Voice)</h3>
        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label>AT Username</label>
                <input type="text" name="ofp_at_username"
                       value="<?php echo esc_attr( get_option( 'ofp_at_username', '' ) ); ?>">
            </div>
            <div class="ofp-field">
                <label>AT API Key</label>
                <input type="password" name="ofp_at_api_key" placeholder="Leave blank to keep existing">
            </div>
            <div class="ofp-field">
                <label>Sender ID (SMS)</label>
                <input type="text" name="ofp_at_sender_id"
                       value="<?php echo esc_attr( get_option( 'ofp_at_sender_id', '' ) ); ?>"
                       placeholder="e.g. OFastPipe">
            </div>
            <div class="ofp-field">
                <label>AT Phone Number (Voice calls)</label>
                <input type="text" name="ofp_at_phone_number"
                       value="<?php echo esc_attr( get_option( 'ofp_at_phone_number', '' ) ); ?>"
                       placeholder="e.g. +2348000000000">
            </div>
        </div>
    </div>

    <!-- ── BulkSMS Nigeria ────────────────────────────────────────────────── -->
    <div class="ofp-settings-section">
        <h3> BulkSMS Nigeria (Fallback SMS)</h3>
        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label>BulkSMS API Key</label>
                <input type="password" name="ofp_bsmsn_api_key" placeholder="Leave blank to keep existing">
            </div>
            <div class="ofp-field">
                <label>Sender ID</label>
                <input type="text" name="ofp_bsmsn_sender_id"
                       value="<?php echo esc_attr( get_option( 'ofp_bsmsn_sender_id', '' ) ); ?>"
                       placeholder="e.g. OFastPipe">
            </div>
        </div>
    </div>

    <!-- ── Cloudflare Turnstile ───────────────────────────────────────────── -->
    <div class="ofp-settings-section">
        <h3> Cloudflare Turnstile</h3>
        <p class="ofp-hint">Bot protection on lead capture forms, /login, and /signup. Leave blank during local development — Turnstile is automatically bypassed when no secret key is set.</p>
        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label>Site Key (public)</label>
                <input type="text" name="ofp_turnstile_site_key"
                       value="<?php echo esc_attr( get_option( 'ofp_turnstile_site_key', '' ) ); ?>">
            </div>
            <div class="ofp-field">
                <label>Secret Key</label>
                <input type="password" name="ofp_turnstile_secret" placeholder="Leave blank to keep existing">
            </div>
        </div>
    </div>

    <!-- ── Property Listing Fee ──────────────────────────────────────────── -->
    <div class="ofp-settings-section">
        <h3> Property Listing Fee</h3>
        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label>Monthly Listing Fee (NGN)</label>
                <input type="number" name="ofp_listing_fee_monthly"
                       value="<?php echo esc_attr( get_option( 'ofp_listing_fee_monthly', 7500 ) ); ?>">
                <p class="ofp-hint">Charged per property listing per month in addition to any CRM plan.</p>
            </div>
        </div>
    </div>

    <div class="ofp-form-actions">
        <button type="submit" class="button button-primary ofp-btn-primary">Save All Settings</button>
    </div>

</form>

<div class="ofp-settings-section">
    <h3>Plans &amp; Pricing</h3>
    <p class="ofp-hint">
        Monthly CRM plan fees, one-time setup fees, and the property listing fee.
        These values are read live across signup, wp-admin client creation, and payment amount matching.
    </p>

    <?php
    $ofp_plan_prices = OFP_Subscription::get_plan_prices();
    $ofp_setup_fees  = OFP_Subscription::get_setup_fees();
    $ofp_listing_fee = OFP_Subscription::get_listing_fee();
    $ofp_plan_labels = [
        'starter' => 'Starter',
        'growth'  => 'Growth',
        'pro'     => 'Pro',
    ];
    ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'ofp_save_plan_pricing_action', 'ofp_plan_pricing_nonce' ); ?>

        <table class="form-table" role="presentation">
            <?php foreach ( OFP_Subscription::PLAN_KEYS as $ofp_plan ) : ?>
                <tr>
                    <th scope="row"><?php echo esc_html( $ofp_plan_labels[ $ofp_plan ] ); ?> Plan</th>
                    <td>
                        <label style="margin-right:24px;">
                            Monthly fee (NGN)
                            <input type="number" step="0.01" min="0"
                                   name="price_<?php echo esc_attr( $ofp_plan ); ?>"
                                   value="<?php echo esc_attr( $ofp_plan_prices[ $ofp_plan ] ); ?>"
                                   style="width:140px;">
                        </label>
                        <label>
                            Setup fee (NGN, one-time)
                            <input type="number" step="0.01" min="0"
                                   name="setup_<?php echo esc_attr( $ofp_plan ); ?>"
                                   value="<?php echo esc_attr( $ofp_setup_fees[ $ofp_plan ] ); ?>"
                                   style="width:140px;">
                        </label>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th scope="row">Property Listing Fee</th>
                <td>
                    <label>
                        Monthly fee per property (NGN)
                        <input type="number" step="0.01" min="0"
                               name="listing_fee"
                               value="<?php echo esc_attr( $ofp_listing_fee ); ?>"
                               style="width:140px;">
                    </label>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" name="ofp_save_plan_pricing" value="1" class="button button-primary">
                Save Pricing
            </button>
        </p>
    </form>
</div>

<script>
// Show/hide gateway credential fields based on selected provider.
document.getElementById('ofp-payment-provider').addEventListener('change', function() {
    document.querySelectorAll('.ofp-gateway-fields').forEach(function(el) {
        el.style.display = 'none';
    });
    var target = document.getElementById('ofp-fields-' + this.value);
    if (target) target.style.display = '';
});
</script>

<?php include OFP_PATH . 'admin/views/partials/footer.php'; ?>
