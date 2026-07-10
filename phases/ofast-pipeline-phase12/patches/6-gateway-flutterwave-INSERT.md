# PATCH 6 — includes/gateways/class-ofp-gateway-flutterwave.php

**Type:** INSERT (2 additions — new method + routing branch)

---

## 6a. Add this new method to the class

Verified against Flutterwave's v3 Standard payment endpoint
(`POST https://api.flutterwave.com/v3/payments`) — amounts are in the
main currency unit (NGN), not subunits, unlike Paystack.

```php
/**
 * Initiates a one-off Flutterwave Standard transaction for a
 * self-serve credit top-up (see OFP_Payment::initiate_credit_topup()).
 *
 * @param array $args ['client_id','amount','reference','email','name','phone','description','redirect_url']
 * @return string|null Flutterwave's hosted checkout link, or null on failure
 */
public function initiate_transaction( array $args ): ?string {
    $secret_key = get_option( 'ofp_flutterwave_secret_key' );

    if ( ! $secret_key ) {
        error_log( 'OFP Flutterwave initiate_transaction — ofp_flutterwave_secret_key is not configured in Settings' );
        return null;
    }

    $response = wp_remote_post( 'https://api.flutterwave.com/v3/payments', [
        'headers' => [
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode( [
            'tx_ref'       => $args['reference'],
            // Flutterwave takes the amount in NGN directly, not kobo.
            'amount'       => $args['amount'],
            'currency'     => 'NGN',
            'redirect_url' => $args['redirect_url'],
            'customer'     => [
                'email'       => $args['email'],
                'name'        => $args['name'],
                'phonenumber' => $args['phone'],
            ],
            'customizations' => [
                'title' => $args['description'],
            ],
            'meta' => [
                'client_id' => $args['client_id'],
            ],
        ] ),
        'timeout' => 20,
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( 'OFP Flutterwave initiate_transaction request error: ' . $response->get_error_message() );
        return null;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ) );

    if ( empty( $body->status ) || $body->status !== 'success' || empty( $body->data->link ) ) {
        error_log( 'OFP Flutterwave initiate_transaction unexpected response: ' . wp_remote_retrieve_body( $response ) );
        return null;
    }

    return $body->data->link;
}
```

---

## 6b. Add this routing branch near the top of the existing webhook handler

Flutterwave's webhook payload shape is
`{ "event": "charge.completed", "data": { "tx_ref": ..., "amount": ..., "id": ..., "status": ... } }`.
Insert this **after** your existing `verif-hash` header check and
**before** any subscription-renewal logic:

```php
// Phase 12 — self-serve credit top-up routing.
$tx_ref = $payload->data->tx_ref ?? '';

if ( $tx_ref && OFP_Payment::is_credit_topup_reference( $tx_ref ) ) {
    $amount_paid = (float) ( $payload->data->amount ?? 0 ); // already NGN, no conversion
    OFP_Payment::confirm_credit_topup( $tx_ref, $amount_paid, (string) ( $payload->data->id ?? '' ) );
    return new WP_REST_Response( [ 'status' => 'credit_topup_processed' ], 200 );
}
```

Adjust `$payload` to whatever variable name your existing handler
already decoded the webhook JSON into.

---

## Why this is safe to insert as-is

- Same reasoning as Patch 5 — new method, additive webhook branch
  placed before existing logic, no changes to signature/hash
  verification you already have in place.
- Note the unit difference from Paystack: Flutterwave amounts are
  plain NGN, Paystack amounts are kobo. Getting this backwards in
  either direction would silently under- or over-credit clients by
  100x, so it's called out explicitly in both patches' comments.
