# Phase 12 — Self-Serve Credit Top-Up

## Manifest

**No full-file replacements this phase** — every change is a targeted
patch into an existing file, since all five touched files
(`class-ofp-payment.php`, the three gateway adapters, `credits.php`)
are large, already-built files I don't have the current source of.
See `patches/` for exact insert points.

| Patch | File | What it adds |
|---|---|---|
| 4 | `includes/class-ofp-payment.php` | `initiate_credit_topup()`, reference generation/parsing, `confirm_credit_topup()` — the central logic everything else calls into |
| 5 | `includes/gateways/class-ofp-gateway-paystack.php` | `initiate_transaction()` + webhook routing branch |
| 6 | `includes/gateways/class-ofp-gateway-flutterwave.php` | `initiate_transaction()` + webhook routing branch |
| 7 | `includes/gateways/class-ofp-gateway-monnify.php` | `initiate_transaction()` (via Monnify's **Checkout API**, distinct from Reserved Accounts) + webhook routing branch |
| 8 | `public/templates/credits.php` | POST handler + top-up form + pending-redirect notice |

**No schema change.** No deactivate/reactivate needed.

---

## Why this design, and what it costs you later

**The reference-encoding approach** (`ofp_credit_{channel}_{client_id}_{random}`)
means client_id and channel travel with the payment itself, so no new
table is needed to correlate "who initiated this" with "which webhook
just fired." This is deliberately the same pattern your existing
Monnify subscription webhook already uses (`ofp_client_{id}` regex on
the account reference) — one mental model for "how do webhooks know
whose money this is" across the whole plugin, rather than two.

**The tradeoff:** if you ever want a top-up *request* to exist as a
first-class record before payment completes — e.g. to show a client
"your NGN 5,000 top-up is awaiting payment" the way `/credits` already
shows "Awaiting Payment" for subscriptions (Phase 10b) — that would
need a small `ofp_credit_topup_requests` table after all, since right
now a top-up that's initiated but never completed leaves no trace
anywhere until (and unless) the webhook fires. Given your priority
list didn't ask for that visibility and it adds schema + UI surface
for a fairly edge-case need (abandoned checkouts), I left it out of
this phase. Worth a deliberate "yes/no, later" decision from you
rather than me silently deciding either way — flagging it here so it's
a choice, not a gap that surprises you in three months.

**Monnify is architecturally the odd one out** (see Patch 7's longer
note) — it's the only gateway where top-up genuinely uses a different
underlying product (Checkout API) than subscription billing does
(Reserved Accounts), even though both ultimately settle into the same
Monnify wallet. Paystack and Flutterwave don't have this asymmetry —
their subscription and top-up flows already use the same underlying
transaction primitive in your existing code, just with different
reference formats. If Monnify is your primary active gateway, I'd
sandbox-test Patch 7 first before the other two.

---

## Your test steps

**1. Apply patches 4–8**, in order, to the five existing files. Given
these are reconstructions from documentation rather than diffs against
your actual current source, diff each patch against your real file
before saving — the insertion anchors are described precisely, but
your file's exact current wording around those anchors may differ.

**2. Confirm your active gateway** — check `wp-admin → OFast Pipeline
→ Settings` for whichever of `ofp_payment_provider` is currently set
(monnify/paystack/flutterwave), and test that one first.

**3. Sandbox test the full loop, in this order:**
   - Log in as a test client, go to `/credits`.
   - Enter an amount below the minimum (e.g. NGN 50) — confirm you get
     the "Minimum top-up amount..." error, no redirect happens.
   - Enter a valid amount (e.g. NGN 5,000) for SMS credit, submit.
   - Confirm you're redirected to your gateway's real hosted checkout
     page (Paystack/Flutterwave/Monnify sandbox), not an error page.
   - Complete the sandbox payment.
   - Confirm you land back on `/credits?topup_status=pending` and see
     the "we're confirming your payment" notice.
   - Check your webhook logs (or `error_log()` output) — confirm the
     webhook fired, hit the new routing branch, and called
     `confirm_credit_topup()`.
   - Refresh `/credits` — confirm the SMS balance increased by exactly
     the amount paid.
   - Check `wp_ofp_credit_transactions` in Adminer — confirm a new
     `type = 'topup'` row exists with the correct `reference`.

**4. Test the idempotency guard** — manually re-trigger the same
webhook payload a second time (most sandbox dashboards let you resend
a webhook event). Confirm the balance does NOT increase a second time,
and `confirm_credit_topup()` returns `true` without inserting a
duplicate transaction row.

**5. Test the security rails:**
   - Submit the top-up form 6 times rapidly — confirm the 6th attempt
     is rate-limited.
   - Try submitting the form with a tampered/missing nonce (e.g. via
     curl without the nonce field) — confirm it's rejected with the
     "Security check failed" message.

**6. Repeat steps 3–5 for Voice credit**, and — time permitting — for
whichever of the other two gateways you also plan to use, since each
has its own `initiate_transaction()` implementation to verify
independently.

---

## What's still open after this phase

Per your priority list, **password reset handler** is next — same
"infrastructure exists, the actual flow doesn't" situation as this
phase started in, since `OFP_Auth::generate_reset_token()` is already
built but unused.

Also still flagged from earlier phases, unaffected by this one: the
underpayment-handling decision for subscription webhooks (continuation
blueprint Section 4, item 1), and the property listing public pages
(blocked on the PHP-vs-Elementor-vs-hybrid decision).
