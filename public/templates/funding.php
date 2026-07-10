<?php
/**
 * Template: /funding
 *
 * Client Funding page — Phase 17 / 17b.
 *
 * Sections (in order of preference):
 *  1. Virtual Account (from payment gateway — auto-matched, no form needed)
 *  2. Company Bank Account (manual transfer — admin fills in Settings)
 *  3. "I Have Already Transferred" form (lets client notify us of a manual payment)
 *
 * The funding form covers all four payment types:
 *  - SMS Credit top-up
 *  - Voice Credit top-up
 *  - CRM Plan payment / renewal
 *  - Listing Plan payment / renewal
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();

$success = '';
$error   = '';

/* -----------------------------------------------------------
 * Handle virtual account generation request
 * --------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_generate_virtual_account'] ) ) {

    if ( ! wp_verify_nonce( $_POST['ofp_va_nonce'] ?? '', 'ofp_generate_va_action' ) ) {
        $error = 'Security check failed — please try again.';
    } elseif ( ! empty( $client->virtual_account_number ) ) {
        $error = 'You already have a virtual account.';
    } elseif ( ! class_exists( 'OFP_Payment' ) ) {
        $error = 'Payment gateway is not configured yet. Please contact support.';
    } else {
        $account = OFP_Payment::create_virtual_account(
            [
                'business_name' => $client->business_name,
                'owner_name'    => $client->owner_name,
                'email'         => $client->email,
            ],
            $client->id
        );

        if ( $account && ! empty( $account->account_number ) ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'ofp_clients',
                [
                    'virtual_account_number' => sanitize_text_field( $account->account_number ),
                    'virtual_bank_name'      => sanitize_text_field( $account->bank_name ?? '' ),
                ],
                [ 'id' => $client->id ]
            );

            // Re-fetch client to show the new account details immediately.
            $client = OFP_Auth::current_client();
            $success = 'Your dedicated payment account has been generated!';
        } else {
            $error = 'Could not generate your virtual account. The payment gateway may be temporarily unavailable — please try again later or contact support.';
        }
    }
}

/* -----------------------------------------------------------
 * Handle manual funding form submission
 * --------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_submit_funding'] ) ) {

    if ( ! wp_verify_nonce( $_POST['ofp_funding_nonce'] ?? '', 'ofp_manual_funding_action' ) ) {
        $error = 'Security check failed — please try again.';
    } else {

        // Basic rate-limit: 5 submissions per IP per 10 minutes.
        if ( class_exists( 'OFP_Security' ) ) {
            OFP_Security::check_rate_limit( $_SERVER['REMOTE_ADDR'] ?? '', 'manual_funding', 5, 600 );
        }

        $amount          = (float) ( $_POST['amount'] ?? 0 );
        $payment_for     = sanitize_text_field( $_POST['payment_for'] ?? '' );
        $bank_name       = sanitize_text_field( $_POST['bank_name'] ?? '' );
        $account_name    = sanitize_text_field( $_POST['account_name'] ?? '' );
        $transaction_ref = sanitize_text_field( $_POST['transaction_ref'] ?? '' );
        $note            = sanitize_textarea_field( $_POST['note'] ?? '' );

        $valid_payment_types = [ 'sms', 'voice', 'crm_plan', 'listing_plan' ];

        if ( $amount <= 0 ) {
            $error = 'Please enter the amount you sent.';
        } elseif ( ! in_array( $payment_for, $valid_payment_types, true ) ) {
            $error = 'Please choose what this payment is for.';
        } elseif ( empty( $bank_name ) || empty( $account_name ) || empty( $transaction_ref ) ) {
            $error = 'Please fill in all required fields.';
        } else {

            // Human-readable labels for email and notification.
            $crm_plan_label     = $client->plan ? ucfirst( $client->plan ) : 'N/A';
            $listing_plan_label = OFP_Subscription::get_active_listing_plan( $client->id );
            $listing_plan_label = $listing_plan_label ? ucfirst( $listing_plan_label ) : 'N/A';

            $payment_labels = [
                'sms'          => 'SMS Credit',
                'voice'        => 'Voice Credit',
                'crm_plan'     => "CRM Plan ({$crm_plan_label})",
                'listing_plan' => "Listing Plan ({$listing_plan_label})",
            ];
            $payment_label = $payment_labels[ $payment_for ];

            global $wpdb;
            $wpdb->insert( $wpdb->prefix . 'ofp_funding_requests', [
                'client_id'       => $client->id,
                'amount'          => $amount,
                'channel'         => $payment_for,
                'bank_name'       => $bank_name,
                'account_name'    => $account_name,
                'transaction_ref' => $transaction_ref,
                'note'            => $note,
                'status'          => 'pending',
                'created_at'      => current_time( 'mysql' ),
            ] );

            // Notify admin by email.
            $email_body = '<p>A new manual funding request has been submitted.</p>
<table cellpadding="6" cellspacing="0" style="border-collapse:collapse; font-family:sans-serif; font-size:14px;">
<tr><td><strong>Client</strong></td><td>' . esc_html( $client->business_name ) . ' (' . esc_html( $client->owner_name ) . ')</td></tr>
<tr><td><strong>Payment For</strong></td><td>' . esc_html( $payment_label ) . '</td></tr>
<tr><td><strong>Amount</strong></td><td>NGN ' . esc_html( number_format( $amount, 2 ) ) . '</td></tr>
<tr><td><strong>Bank</strong></td><td>' . esc_html( $bank_name ) . '</td></tr>
<tr><td><strong>Account Name</strong></td><td>' . esc_html( $account_name ) . '</td></tr>
<tr><td><strong>Transaction Ref</strong></td><td>' . esc_html( $transaction_ref ) . '</td></tr>
' . ( $note ? '<tr><td><strong>Note</strong></td><td>' . esc_html( $note ) . '</td></tr>' : '' ) . '
</table>';

            OFP_Mailer::send(
                get_option( 'admin_email' ),
                'Admin',
                'New Manual Funding Request — ' . $client->business_name,
                $email_body
            );

            // Bell/email notification to client based on their preference.
            OFP_Notification::create(
                $client->id,
                'manual_funding_received',
                'Funding request received',
                'We received your payment of NGN ' . number_format( $amount, 2 ) .
                ' for ' . $payment_label . '. We will review and update your account within 24 hours.'
            );

            $success = 'Your funding request has been submitted. We will review and update your account within 24 hours.';
        }
    }
}

// Company bank account details — set by admin in Settings.
$company_bank_name    = get_option( 'ofp_company_bank_name', '' );
$company_account_no   = get_option( 'ofp_company_account_no', '' );
$company_account_name = get_option( 'ofp_company_account_name', '' );

// Client's current plan context for funding form dropdown.
$crm_plan     = $client->plan ?? null;
$listing_plan = OFP_Subscription::get_active_listing_plan( $client->id );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Funding — OFast Pipeline</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
    <style>
        /* ── Funding-page specific styles ── */
        .ofp-funding-page { max-width: 560px; margin: 0 auto; padding: 0 0 60px; }

        .ofp-funding-card {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 20px;
            padding: 28px 28px 32px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        }

        .ofp-funding-card-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .ofp-funding-card-title {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 6px;
        }

        .ofp-funding-card-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 20px;
            line-height: 1.55;
        }

        .ofp-funding-detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            font-size: 14px;
        }
        .ofp-funding-detail-row:last-child { border-bottom: none; }
        .ofp-funding-detail-label { color: var(--text-muted); font-weight: 500; }
        .ofp-funding-detail-value { color: var(--text-main); font-weight: 600; display: flex; align-items: center; gap: 10px; }

        .ofp-copy-btn {
            padding: 3px 12px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 100px;
            color: var(--text-main);
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }
        .ofp-copy-btn:hover { background: rgba(255,255,255,0.15); }

        .ofp-auto-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(16,185,129,0.12);
            color: var(--accent-green);
            border-radius: 100px;
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            margin-top: 14px;
        }

        .ofp-section-divider {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-muted);
            text-align: center;
            margin: 28px 0 20px;
            opacity: 0.6;
        }

        .ofp-form-row {
            margin-bottom: 18px;
        }
        .ofp-form-row label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 7px;
            letter-spacing: 0.04em;
        }
        .ofp-form-input {
            width: 100%;
            height: 48px;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 0 16px;
            font-size: 14px;
            color: var(--text-main);
            background: rgba(0,0,0,0.2);
            transition: border-color 0.2s, background 0.2s;
            box-sizing: border-box;
        }
        .ofp-form-input:focus {
            outline: none;
            border-color: rgba(255,255,255,0.3);
            background: rgba(0,0,0,0.3);
        }
        select.ofp-form-input { cursor: pointer; }
        textarea.ofp-form-input {
            height: auto;
            padding: 12px 16px;
            resize: vertical;
            line-height: 1.5;
        }

        .ofp-submit-btn {
            width: 100%;
            height: 52px;
            background: linear-gradient(135deg, rgba(99,102,241,0.3), rgba(139,92,246,0.3));
            border: 1px solid rgba(139,92,246,0.4);
            border-radius: 14px;
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.04em;
            cursor: pointer;
            margin-top: 8px;
            transition: all 0.2s;
        }
        .ofp-submit-btn:hover {
            background: linear-gradient(135deg, rgba(99,102,241,0.5), rgba(139,92,246,0.5));
            transform: translateY(-1px);
            box-shadow: 0 4px 20px rgba(139,92,246,0.2);
        }

        .ofp-alert {
            border-radius: 12px;
            font-size: 13px;
            padding: 12px 18px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .ofp-alert-success {
            background: rgba(16,185,129,0.1);
            color: var(--accent-green);
            border: 1px solid rgba(16,185,129,0.2);
        }
        .ofp-alert-error {
            background: rgba(239,68,68,0.1);
            color: var(--accent-red);
            border: 1px solid rgba(239,68,68,0.2);
        }
    </style>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">
<div class="ofp-funding-page">

    <h1 style="font-size:22px; font-weight:700; color:var(--text-main); margin:0 0 24px; letter-spacing:-0.01em;">
        Account Funding
    </h1>

    <?php if ( $success ) : ?>
        <div class="ofp-alert ofp-alert-success"><?php echo esc_html( $success ); ?></div>
    <?php endif; ?>
    <?php if ( $error ) : ?>
        <div class="ofp-alert ofp-alert-error"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>

    <!-- ══ Section 1: Virtual Account (gateway — auto-matched) ══════════════ -->
    <?php if ( ! empty( $client->virtual_account_number ) ) : ?>
    <div class="ofp-funding-card">
        <div class="ofp-funding-card-label">Automatic</div>
        <div class="ofp-funding-card-title">Your Dedicated Virtual Account</div>
        <div class="ofp-funding-card-desc">
            Transfer any amount directly to this account. Payments are automatically
            matched to your profile — no form or waiting needed.
        </div>

        <div class="ofp-funding-detail-row">
            <span class="ofp-funding-detail-label">Bank</span>
            <span class="ofp-funding-detail-value"><?php echo esc_html( $client->virtual_bank_name ?? '' ); ?></span>
        </div>
        <div class="ofp-funding-detail-row">
            <span class="ofp-funding-detail-label">Account Number</span>
            <span class="ofp-funding-detail-value">
                <?php echo esc_html( $client->virtual_account_number ); ?>
                <button type="button" class="ofp-copy-btn" data-copy="<?php echo esc_attr( $client->virtual_account_number ); ?>">Copy</button>
            </span>
        </div>
        <div class="ofp-funding-detail-row">
            <span class="ofp-funding-detail-label">Account Name</span>
            <span class="ofp-funding-detail-value"><?php echo esc_html( $client->virtual_account_name ?? $client->business_name ); ?></span>
        </div>

        <div class="ofp-auto-badge">
            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            Auto-matched — no form required
        </div>
    </div>
    <?php elseif ( class_exists( 'OFP_Payment' ) ) : ?>
    <!-- Client has no virtual account yet but gateway is configured — offer to generate -->
    <div class="ofp-funding-card">
        <div class="ofp-funding-card-label">Automatic</div>
        <div class="ofp-funding-card-title">Get Your Dedicated Payment Account</div>
        <div class="ofp-funding-card-desc">
            Generate a personal bank account number tied to your profile.
            Any transfer you make to it will be automatically detected and
            credited — no forms, no waiting.
        </div>

        <form method="POST" action="">
            <?php wp_nonce_field( 'ofp_generate_va_action', 'ofp_va_nonce' ); ?>
            <button type="submit" name="ofp_generate_virtual_account" value="1" class="ofp-submit-btn" style="margin-top:0;">
                Generate My Account
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ══ Section 2: Company Bank Account (admin-configured manual transfer) -->
    <?php if ( $company_bank_name && $company_account_no ) : ?>
    <div class="ofp-funding-card">
        <div class="ofp-funding-card-label">Bank Transfer</div>
        <div class="ofp-funding-card-title">Pay to Our Company Account</div>
        <div class="ofp-funding-card-desc">
            Transfer to the account below, then fill in the form underneath so
            we can verify and credit your account promptly.
        </div>

        <div class="ofp-funding-detail-row">
            <span class="ofp-funding-detail-label">Bank</span>
            <span class="ofp-funding-detail-value"><?php echo esc_html( $company_bank_name ); ?></span>
        </div>
        <div class="ofp-funding-detail-row">
            <span class="ofp-funding-detail-label">Account Number</span>
            <span class="ofp-funding-detail-value">
                <?php echo esc_html( $company_account_no ); ?>
                <button type="button" class="ofp-copy-btn" data-copy="<?php echo esc_attr( $company_account_no ); ?>">Copy</button>
            </span>
        </div>
        <div class="ofp-funding-detail-row">
            <span class="ofp-funding-detail-label">Account Name</span>
            <span class="ofp-funding-detail-value"><?php echo esc_html( $company_account_name ); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ Section 3: Manual Transfer Form ═════════════════════════════════ -->
    <?php if ( $company_bank_name && $company_account_no ) : ?>
    <div class="ofp-section-divider">I have already transferred</div>
    <?php else : ?>
    <div class="ofp-section-divider">Submit a payment notification</div>
    <?php endif; ?>

    <div class="ofp-funding-card">
        <div class="ofp-funding-card-title">Notify Us of Your Transfer</div>
        <div class="ofp-funding-card-desc">
            Transferred to our company account? Fill this in so we can verify
            and update your account within 24 hours.
        </div>

        <form method="POST" action="">
            <?php wp_nonce_field( 'ofp_manual_funding_action', 'ofp_funding_nonce' ); ?>
            <input type="hidden" name="ofp_submit_funding" value="1">

            <div class="ofp-form-row">
                <label for="ofp-payment-for">This Payment Is For</label>
                <select id="ofp-payment-for" name="payment_for" class="ofp-form-input" required>
                    <option value="">— Choose one —</option>

                    <optgroup label="Credit Top-Up">
                        <option value="sms">SMS Credit</option>
                        <option value="voice">Voice Credit</option>
                    </optgroup>

                    <?php if ( $crm_plan ) : ?>
                    <optgroup label="CRM Subscription">
                        <option value="crm_plan">
                            CRM Plan — <?php echo esc_html( ucfirst( $crm_plan ) ); ?>
                            (NGN <?php echo esc_html( number_format( OFP_Subscription::get_plan_price( $crm_plan ), 0 ) ); ?>/mo)
                        </option>
                    </optgroup>
                    <?php elseif ( ! $crm_plan ) : ?>
                    <optgroup label="New Subscription">
                        <option value="crm_plan">CRM Plan Payment</option>
                    </optgroup>
                    <?php endif; ?>

                    <?php if ( $listing_plan ) : ?>
                    <optgroup label="Listing Subscription">
                        <option value="listing_plan">
                            Listing Plan — <?php echo esc_html( ucfirst( $listing_plan ) ); ?>
                            (NGN <?php echo esc_html( number_format( OFP_Property_CPT::get_plan_price( $listing_plan ), 0 ) ); ?>/mo)
                        </option>
                    </optgroup>
                    <?php else : ?>
                    <optgroup label="Listing Subscription">
                        <option value="listing_plan">Listing Plan Payment</option>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </div>

            <div class="ofp-form-row">
                <label for="ofp-amount">Amount You Sent (NGN)</label>
                <input type="number" id="ofp-amount" name="amount" class="ofp-form-input"
                       step="0.01" min="1" placeholder="e.g. 25000" required>
            </div>

            <div class="ofp-form-row">
                <label for="ofp-bank-name">Your Bank Name</label>
                <input type="text" id="ofp-bank-name" name="bank_name" class="ofp-form-input"
                       placeholder="e.g. GTBank" required>
            </div>

            <div class="ofp-form-row">
                <label for="ofp-account-name">Your Account Name</label>
                <input type="text" id="ofp-account-name" name="account_name" class="ofp-form-input"
                       placeholder="Name on your bank account" required>
            </div>

            <div class="ofp-form-row">
                <label for="ofp-transaction-ref">Transaction Reference</label>
                <input type="text" id="ofp-transaction-ref" name="transaction_ref" class="ofp-form-input"
                       placeholder="Reference from your bank app or receipt" required>
            </div>

            <div class="ofp-form-row">
                <label for="ofp-note">Additional Note <span style="font-weight:400; opacity:0.6;">(optional)</span></label>
                <textarea id="ofp-note" name="note" class="ofp-form-input" rows="3"
                          placeholder="Anything else we should know?"></textarea>
            </div>

            <button type="submit" class="ofp-submit-btn">Submit Funding Request</button>
        </form>
    </div>

</div>
</div>

<script>
document.querySelectorAll('.ofp-copy-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        navigator.clipboard.writeText(btn.dataset.copy).then(function() {
            btn.textContent = 'Copied!';
            setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
        }).catch(function() {
            btn.textContent = 'N/A';
        });
    });
});
</script>

<?php wp_footer(); ?>
</body>
</html>
