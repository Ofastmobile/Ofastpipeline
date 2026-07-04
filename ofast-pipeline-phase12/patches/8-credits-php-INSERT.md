# PATCH 8 — public/templates/credits.php

**Type:** INSERT (2 additions — a POST handler block at the very top
of the file, and a form block wherever the SMS/Voice balance display
already lives)

---

## 8a. Insert at the VERY TOP of the file (before any HTML output)

This must run before any `echo`/HTML so `wp_redirect()` can still set
headers. If the file currently starts with something like
`OFP_Auth::require_client_login();` followed by a `$client = ...`
line, insert this block immediately after that — it needs `$client`
to already be resolved.

```php
$ofp_topup_error   = '';
$ofp_topup_channel = '';
$ofp_topup_amount  = '';

// Phase 12 — self-serve credit top-up bounds. Hardcoded here for now;
// if these ever need to be admin-editable, they'd follow the exact
// same wp_options + Settings pattern used for plan pricing (Phase 11)
// — new options ofp_credit_topup_min / ofp_credit_topup_max, a small
// section in Settings, done.
$ofp_topup_min = 100;
$ofp_topup_max = 500000;

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_initiate_topup'] ) ) {

    if ( ! wp_verify_nonce( $_POST['ofp_credit_topup_nonce'] ?? '', 'ofp_credit_topup_action' ) ) {
        $ofp_topup_error = 'Security check failed — please try again.';
    } else {

        OFP_Security::check_rate_limit( $_SERVER['REMOTE_ADDR'] ?? '', 'credit_topup_initiate', 5, 300 );

        $ofp_topup_channel = sanitize_text_field( $_POST['channel'] ?? '' );
        $ofp_topup_amount  = (float) ( $_POST['amount'] ?? 0 );

        if ( ! in_array( $ofp_topup_channel, [ 'sms', 'voice' ], true ) ) {
            $ofp_topup_error = 'Please choose SMS or Voice credit.';
        } elseif ( $ofp_topup_amount < $ofp_topup_min ) {
            $ofp_topup_error = 'Minimum top-up amount is NGN ' . number_format( $ofp_topup_min, 2 ) . '.';
        } elseif ( $ofp_topup_amount > $ofp_topup_max ) {
            $ofp_topup_error = 'Maximum top-up amount is NGN ' . number_format( $ofp_topup_max, 2 ) . ' per transaction.';
        } else {

            $checkout_url = OFP_Payment::initiate_credit_topup( $client->id, $ofp_topup_channel, $ofp_topup_amount );

            if ( $checkout_url ) {
                wp_redirect( $checkout_url );
                exit;
            }

            $ofp_topup_error = 'We could not start your payment right now. Please try again shortly, or contact support if this continues.';
        }
    }
}

// After a completed (or abandoned) checkout, the gateway redirects
// the browser back here with ?topup_status=pending. We show a
// friendly "processing" notice ONLY — we never credit the balance
// from this redirect. The webhook (OFP_Payment::confirm_credit_topup())
// is the sole source of truth for whether the payment actually
// succeeded, exactly like subscription activation already works.
$ofp_topup_just_redirected = ( ( $_GET['topup_status'] ?? '' ) === 'pending' );
```

---

## 8b. Insert this HTML block near the existing SMS/Voice balance display

```php
<?php if ( $ofp_topup_just_redirected ) : ?>
    <div class="ofp-notice ofp-notice-info">
        Thanks — we're confirming your payment now. Your credit balance
        will update automatically within a few minutes once it's
        confirmed. No need to refresh repeatedly.
    </div>
<?php endif; ?>

<?php if ( $ofp_topup_error ) : ?>
    <div class="ofp-notice ofp-notice-error">
        <?php echo esc_html( $ofp_topup_error ); ?>
    </div>
<?php endif; ?>

<div class="ofp-card ofp-credit-topup-card">
    <h2>Top Up Credit</h2>
    <p class="ofp-muted">
        Add SMS or Voice credit instantly via card, bank transfer, or
        USSD. Your balance updates automatically once payment is
        confirmed.
    </p>

    <form method="POST" class="ofp-topup-form">
        <?php wp_nonce_field( 'ofp_credit_topup_action', 'ofp_credit_topup_nonce' ); ?>

        <label>
            Credit Type
            <select name="channel" required>
                <option value="sms"   <?php selected( $ofp_topup_channel, 'sms' ); ?>>SMS Credit</option>
                <option value="voice" <?php selected( $ofp_topup_channel, 'voice' ); ?>>Voice Credit</option>
            </select>
        </label>

        <label>
            Amount (NGN)
            <input type="number" name="amount" step="0.01"
                   min="<?php echo esc_attr( $ofp_topup_min ); ?>"
                   max="<?php echo esc_attr( $ofp_topup_max ); ?>"
                   value="<?php echo esc_attr( $ofp_topup_amount ); ?>"
                   placeholder="e.g. 5000" required>
        </label>

        <p class="ofp-muted" style="font-size:12px;">
            Minimum NGN <?php echo esc_html( number_format( $ofp_topup_min, 2 ) ); ?>,
            maximum NGN <?php echo esc_html( number_format( $ofp_topup_max, 2 ) ); ?> per transaction.
        </p>

        <button type="submit" name="ofp_initiate_topup" value="1" class="ofp-btn ofp-btn-primary">
            Proceed to Payment
        </button>
    </form>
</div>
```

---

## Why this is safe to insert as-is

- The POST handler is guarded by a distinct `name="ofp_initiate_topup"`
  submit button, so it won't accidentally fire on any other form this
  page might already have (e.g. if there's an existing "request a
  manual top-up" contact form from before this phase).
- Rate-limited via the same `OFP_Security::check_rate_limit()` used
  everywhere else in the plugin (signup, login, lead capture) —
  consistent security posture, 5 attempts per 5 minutes here.
- Nonce-protected via a plain `wp_verify_nonce()` call rather than
  `check_admin_referer()` (which assumes a wp-admin context) — this is
  a client-facing, non-wp-admin page, so this is the correct WP nonce
  API to use here.
- Never credits anything itself — purely initiates a payment and
  redirects. All actual crediting happens in `OFP_Payment::confirm_credit_topup()`
  via the webhook, matching your existing "webhook is the only source
  of truth" rule for subscription payments.
