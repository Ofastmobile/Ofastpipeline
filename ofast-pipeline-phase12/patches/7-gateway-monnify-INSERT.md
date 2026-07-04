# PATCH 7 — includes/gateways/class-ofp-gateway-monnify.php

**Type:** INSERT (2 additions — new method + routing branch)

**Read this one carefully before applying** — Monnify is the odd one
out here. Your existing Monnify code (subscription billing) uses the
**Reserved Accounts** product: one dedicated virtual account per
client, reused indefinitely, matched by amount comparison on every
inbound transfer. Credit top-up needs Monnify's **Checkout API /
Initialize Transaction** product instead — a genuinely different
Monnify feature, generating a one-off `checkoutUrl` per transaction
with its own `paymentReference`. Same Monnify account and contract
code, different API surface. This is why the webhook routing branch
below checks a different field (`eventData->paymentReference`) than
your existing reserved-account logic (`eventData->product->reference`).

---

## 7a. Add this new method to the class

```php
/**
 * Initiates a one-off Monnify Checkout transaction for a self-serve
 * credit top-up (see OFP_Payment::initiate_credit_topup()).
 *
 * Uses Monnify's Checkout API (Initialize Transaction), NOT the
 * Reserved Accounts product used elsewhere in this class for
 * subscription billing. Reuses $this->get_access_token() — whatever
 * that private/protected method is already named in this class for
 * the Bearer token login step used by create_virtual_account().
 *
 * @param array $args ['client_id','amount','reference','email','name','phone','description','redirect_url']
 * @return string|null Monnify's checkoutUrl, or null on failure
 */
public function initiate_transaction( array $args ): ?string {
    // Adjust this call if your existing token-fetching method has a
    // different name — same method create_virtual_account() already
    // calls for its Bearer token.
    $token = $this->get_access_token();

    if ( ! $token ) {
        error_log( 'OFP Monnify initiate_transaction — failed to obtain access token' );
        return null;
    }

    $base_url = get_option( 'ofp_monnify_base_url', 'https://api.monnify.com' );

    $response = wp_remote_post( $base_url . '/api/v1/merchant/transactions/init-transaction', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode( [
            'amount'             => $args['amount'],
            'customerName'       => $args['name'],
            'customerEmail'      => $args['email'],
            'paymentReference'   => $args['reference'],
            'paymentDescription' => $args['description'],
            'currencyCode'       => 'NGN',
            'contractCode'       => get_option( 'ofp_monnify_contract_code' ),
            'redirectUrl'        => $args['redirect_url'],
        ] ),
        'timeout' => 20,
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( 'OFP Monnify initiate_transaction request error: ' . $response->get_error_message() );
        return null;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ) );

    if ( empty( $body->requestSuccessful ) || empty( $body->responseBody->checkoutUrl ) ) {
        error_log( 'OFP Monnify initiate_transaction unexpected response: ' . wp_remote_retrieve_body( $response ) );
        return null;
    }

    return $body->responseBody->checkoutUrl;
}
```

---

## 7b. Add this routing branch inside the existing `handle_webhook()` method

Insert this **after** signature verification and the `eventType ===
'SUCCESSFUL_TRANSACTION'` check, but **before** the existing
`preg_match('/ofp_client_(\d+)/', $account_ref, ...)` subscription
logic (the v2.0 blueprint's version of this method, Section 18.1, is
the reference point — your current file may have evolved since, but
the anchor point is "right after we know it's a successful
transaction, right before we try to match it to a subscription"):

```php
// Phase 12 — self-serve credit top-up routing. One-off Checkout API
// transactions report their reference at eventData->paymentReference
// — NOT eventData->product->reference, which only exists for the
// Reserved Accounts product used by subscription billing below. If
// this is a credit top-up, apply it and stop here.
$topup_reference = $data->eventData->paymentReference ?? '';

if ( $topup_reference && OFP_Payment::is_credit_topup_reference( $topup_reference ) ) {
    $amount_paid = (float) ( $data->eventData->amountPaid ?? 0 );
    OFP_Payment::confirm_credit_topup(
        $topup_reference,
        $amount_paid,
        (string) ( $data->eventData->transactionReference ?? '' )
    );
    return new WP_REST_Response( [ 'status' => 'credit_topup_processed' ], 200 );
}
```

---

## Why this needs a closer look than Patches 5–6

- Confirm in your Monnify dashboard/sandbox that your existing
  `contractCode` supports Checkout API transactions, not just Reserved
  Accounts — in most Monnify setups the same contract code covers
  both products, but this is worth a one-time sandbox check before
  going live, since it's the one gateway where top-up genuinely uses a
  different product than subscriptions do.
- The exact webhook payload shape for Checkout API transactions vs.
  Reserved Account transactions should be confirmed against a real
  sandbox test — the `eventData->paymentReference` field name above is
  Monnify's documented convention for one-off transactions, but
  differs enough from your existing reserved-account webhook handling
  that I'd treat this specific branch as the first thing to test with
  a real sandbox top-up before trusting it in production.
