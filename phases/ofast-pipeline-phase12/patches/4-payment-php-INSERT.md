# PATCH 4 — includes/class-ofp-payment.php

**Type:** INSERT (add these methods to the existing `OFP_Payment` class
— do not replace the file). This is the single place all credit top-up
logic lives; the three gateway adapters only need a thin routing line
each (Patches 5–7).

---

## Add these methods to the class

```php
/* -----------------------------------------------------------
 * Self-serve credit top-up (Phase 12)
 *
 * Distinct from create_virtual_account() / the subscription
 * billing flow: a CRM/listing subscription is billed against a
 * client's ONE dedicated, reusable virtual account (matched by
 * amount comparison on every inbound transfer). A credit top-up
 * is a one-off, reference-tagged checkout transaction — closer to
 * a "pay now" link than a standing account. Different Monnify
 * product even (Checkout API / Initialize Transaction, not
 * Reserved Accounts), which is why it needs its own method here
 * rather than reusing create_virtual_account().
 * --------------------------------------------------------- */

/**
 * Initiates a one-off credit top-up transaction with whichever
 * gateway is currently active, and returns a hosted checkout URL to
 * redirect the client to.
 *
 * @param int    $client_id
 * @param string $channel 'sms'|'voice'
 * @param float  $amount  NGN amount — caller must have already
 *                        validated this against your min/max bounds
 *                        (see credits.php Patch 8) before calling.
 * @return string|null checkout URL, or null on failure (bad channel,
 *                      unknown client, gateway call failed)
 */
public static function initiate_credit_topup( int $client_id, string $channel, float $amount ): ?string {
    if ( ! in_array( $channel, [ 'sms', 'voice' ], true ) ) {
        return null;
    }

    $client = OFP_Client::get( $client_id );
    if ( ! $client ) {
        return null;
    }

    $reference = self::generate_credit_topup_reference( $client_id, $channel );

    // IMPORTANT: replace self::get_gateway() below with whatever your
    // existing create_virtual_account() method uses internally to
    // resolve the currently-active adapter from the
    // 'ofp_payment_provider' option (e.g. self::resolve_provider(),
    // self::get_active_gateway() — same resolution logic your
    // existing code already has, just reused here).
    $gateway = self::get_gateway();

    if ( ! $gateway || ! method_exists( $gateway, 'initiate_transaction' ) ) {
        error_log( 'OFP_Payment::initiate_credit_topup — active gateway missing initiate_transaction(). Did you add it per Patches 5-7?' );
        return null;
    }

    $checkout_url = $gateway->initiate_transaction( [
        'client_id'    => $client_id,
        'amount'       => $amount,
        'reference'    => $reference,
        'email'        => $client->email,
        'name'         => $client->owner_name,
        'phone'        => $client->phone,
        'description'  => ucfirst( $channel ) . ' Credit Top-Up',
        'redirect_url' => home_url( '/credits?topup_status=pending' ),
    ] );

    if ( ! $checkout_url ) {
        error_log( "OFP_Payment::initiate_credit_topup — gateway returned no checkout URL for client {$client_id}, channel {$channel}, amount {$amount}" );
    }

    return $checkout_url;
}

/**
 * Builds a unique, parseable payment reference for a credit top-up
 * request. Format: ofp_credit_{channel}_{client_id}_{random8}
 *
 * All three gateway webhook handlers parse this back out via
 * parse_credit_topup_reference() to distinguish a credit top-up from
 * a subscription payment — this is the ONLY signal used for that
 * distinction, so never hand-construct a reference elsewhere without
 * going through this method.
 *
 * @param int    $client_id
 * @param string $channel
 * @return string
 */
public static function generate_credit_topup_reference( int $client_id, string $channel ): string {
    return sprintf(
        'ofp_credit_%s_%d_%s',
        $channel,
        $client_id,
        wp_generate_password( 8, false, false )
    );
}

/**
 * Whether a payment reference matches the credit top-up format.
 * Cheap check gateway webhook handlers use to decide whether to
 * route into confirm_credit_topup() instead of their normal
 * subscription-renewal logic.
 *
 * @param string $reference
 * @return bool
 */
public static function is_credit_topup_reference( string $reference ): bool {
    return (bool) preg_match( '/^ofp_credit_(sms|voice)_(\d+)_/', $reference );
}

/**
 * Parses client_id and channel back out of a credit top-up reference.
 *
 * @param string $reference
 * @return array|null ['channel' => 'sms'|'voice', 'client_id' => int], or null if it doesn't match the format
 */
public static function parse_credit_topup_reference( string $reference ): ?array {
    if ( ! preg_match( '/^ofp_credit_(sms|voice)_(\d+)_/', $reference, $matches ) ) {
        return null;
    }
    return [
        'channel'   => $matches[1],
        'client_id' => (int) $matches[2],
    ];
}

/**
 * Confirms a credit top-up payment and applies it to the client's
 * SMS/voice balance via OFP_Credit::topup(). This is the ONE place
 * all three gateway webhook handlers should call for a reference
 * matching the credit top-up format — do not duplicate the crediting
 * logic per-gateway (Patches 5-7 each add only a short routing branch
 * that calls into this).
 *
 * Idempotent: payment providers retry undelivered webhooks, so before
 * crediting we check whether this exact reference has already been
 * recorded as a 'topup' row in ofp_credit_transactions. If so, this
 * is a replay of an already-processed payment, not a new one — we
 * return true without crediting a second time.
 *
 * @param string $reference    payment reference from the webhook payload
 * @param float  $amount_paid  amount actually confirmed paid by the gateway, in NGN
 * @param string $provider_ref gateway's own transaction id, stored for the audit trail
 * @return bool true if applied (or already applied earlier), false if
 *              the reference doesn't match the expected format or the
 *              client no longer exists
 */
public static function confirm_credit_topup( string $reference, float $amount_paid, string $provider_ref = '' ): bool {
    $parsed = self::parse_credit_topup_reference( $reference );
    if ( ! $parsed ) {
        return false;
    }

    $client = OFP_Client::get( $parsed['client_id'] );
    if ( ! $client ) {
        error_log( "OFP_Payment::confirm_credit_topup — reference {$reference} parsed to a client_id that no longer exists" );
        return false;
    }

    global $wpdb;

    $already_processed = $wpdb->get_var( $wpdb->prepare( "
        SELECT id FROM {$wpdb->prefix}ofp_credit_transactions
        WHERE reference = %s AND type = 'topup'
        LIMIT 1
    ", $reference ) );

    if ( $already_processed ) {
        // Webhook retry / duplicate delivery for a payment we've
        // already credited. Idempotent no-op — NOT an error.
        return true;
    }

    if ( $amount_paid <= 0 ) {
        error_log( "OFP_Payment::confirm_credit_topup — reference {$reference} confirmed with a non-positive amount ({$amount_paid}), refusing to credit" );
        return false;
    }

    OFP_Credit::topup( $parsed['client_id'], $parsed['channel'], $amount_paid, $reference );

    return true;
}
```

---

## Why this is safe to insert as-is

- All new method names (`initiate_credit_topup`, `generate_credit_topup_reference`,
  `is_credit_topup_reference`, `parse_credit_topup_reference`,
  `confirm_credit_topup`) are new and specific — no collision risk
  with anything already in the class.
- `confirm_credit_topup()` is the ONLY place that calls
  `OFP_Credit::topup()` for self-serve top-ups, so the idempotency
  check lives in exactly one place, not duplicated three times across
  gateway files where it'd be easy for one copy to drift from the
  others.
- Nothing here touches `create_virtual_account()`, `handle_webhook()`,
  or any subscription-billing logic already in this file — purely
  additive, and the one place you need to adapt (`self::get_gateway()`)
  is called out explicitly in a comment rather than silently assumed.
