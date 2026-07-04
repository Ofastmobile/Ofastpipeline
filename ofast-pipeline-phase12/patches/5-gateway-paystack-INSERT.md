# PATCH 5 — includes/gateways/class-ofp-gateway-paystack.php

**Type:** INSERT (2 additions — a new method, and one routing branch
inserted near the top of the existing webhook handler)

---

## 5a. Add this new method to the class

Verified against Paystack's current Initialize Transaction endpoint
(`POST https://api.paystack.co/transaction/initialize`) — amounts are
in kobo (NGN subunit), so the NGN amount is multiplied by 100 before
sending.

```php
/**
 * Initiates a one-off Paystack transaction for a self-serve credit
 * top-up (see OFP_Payment::initiate_credit_topup()). Distinct from
 * any dedicated-account logic this adapter may have for subscription
 * billing — this always creates a fresh, single-use checkout link.
 *
 * @param array $args ['client_id','amount','reference','email','name','phone','description','redirect_url']
 * @return string|null Paystack's authorization_url, or null on failure
 */
public function initiate_transaction( array $args ): ?string {
    $secret_key = get_option( 'ofp_paystack_secret_key' );

    if ( ! $secret_key ) {
        error_log( 'OFP Paystack initiate_transaction — ofp_paystack_secret_key is not configured in Settings' );
        return null;
    }

    // Paystack requires amounts in the currency's subunit (kobo for NGN).
    $amount_kobo = (int) round( $args['amount'] * 100 );

    $response = wp_remote_post( 'https://api.paystack.co/transaction/initialize', [
        'headers' => [
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode( [
            'email'        => $args['email'],
            'amount'       => $amount_kobo,
            'currency'     => 'NGN',
            'reference'    => $args['reference'],
            'callback_url' => $args['redirect_url'],
            'metadata'     => [
                'client_id'   => $args['client_id'],
                'description' => $args['description'],
            ],
        ] ),
        'timeout' => 20,
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( 'OFP Paystack initiate_transaction request error: ' . $response->get_error_message() );
        return null;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ) );

    if ( empty( $body->status ) || empty( $body->data->authorization_url ) ) {
        error_log( 'OFP Paystack initiate_transaction unexpected response: ' . wp_remote_retrieve_body( $response ) );
        return null;
    }

    return $body->data->authorization_url;
}
```

---

## 5b. Add this routing branch near the top of the existing webhook handler

Find the method that currently handles Paystack's incoming webhook
(after signature verification, likely named something like
`handle_webhook()` or `process_payment()`). Insert this branch
**before** any existing subscription-renewal / amount-matching logic
runs — a credit top-up should never fall through into that code path.

Adjust `$event` / property names below to match whatever variable your
existing code already decoded the webhook JSON body into (Paystack
sends `{ "event": "charge.success", "data": { "reference": ..., "amount": ..., "id": ... } }`).

```php
// Phase 12 — self-serve credit top-up routing. If the reference
// matches the credit top-up format, apply it to the client's SMS/
// voice balance and stop here. Do NOT let this fall through into the
// subscription amount-matching logic below — a top-up was never a
// subscription payment and won't match any expected plan/listing total.
$reference = $event->data->reference ?? '';

if ( $reference && OFP_Payment::is_credit_topup_reference( $reference ) ) {
    $amount_paid = ( (float) ( $event->data->amount ?? 0 ) ) / 100; // kobo -> NGN
    OFP_Payment::confirm_credit_topup( $reference, $amount_paid, (string) ( $event->data->id ?? '' ) );
    return new WP_REST_Response( [ 'status' => 'credit_topup_processed' ], 200 );
}
```

---

## Why this is safe to insert as-is

- The new method name `initiate_transaction` is the one method every
  gateway adapter needs to implement for Phase 12 to work — if your
  adapters implement a formal `OFP_Gateway_Interface`, add this
  method's signature there too so all three stay contractually in sync.
- The webhook branch only reads `$reference` and `$amount` off the
  already-verified payload — it doesn't touch signature verification,
  so nothing about your existing security posture changes.
- Placed before subscription logic, so a credit top-up webhook can
  never be misread as (or silently ignored by) subscription
  amount-matching code.
