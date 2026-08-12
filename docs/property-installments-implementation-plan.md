# OFastpipeline Property Installments — Implementation Plan

## Purpose
Extend the existing property/listing system with buyer offers, installment purchases, payment tracking, and payment reminders without creating buyer accounts or replacing the existing pipeline/payment architecture.

## Existing code to preserve
- `includes/class-ofp-property-cpt.php` — existing property/listing domain.
- `public/templates/properties.php` — existing property listing UI.
- `public/templates/property-single.php` — existing property landing/single page.
- `public/templates/my-listing.php` — existing client listing management.
- `includes/class-ofp-lead.php` — existing lead/contact flow.
- `includes/class-ofp-payment.php` — existing payment infrastructure.
- `includes/class-ofp-subscription.php` — existing SaaS subscription logic; do not mix property purchases into subscription entitlement logic.
- `includes/class-ofp-mailer.php` — existing email system.
- `includes/class-ofp-sms.php` — existing SMS system.
- `includes/class-ofp-ivr.php` / `class-ofp-voice.php` — existing voice/IVR system.
- `includes/class-ofp-notification.php` — existing client notifications.
- `includes/class-ofp-queue.php` / `cron/class-ofp-cron-handler.php` — existing scheduled/background work.
- `includes/gateways/*` — existing Paystack/Flutterwave/Monnify gateway adapters.

## New domain objects
### 1. Property Purchase
Connects property, owner/client/platform, buyer/contact, agreed price, offer status, purchase status, payment plan, and terms version.

### 2. Payment Plan
Stores total price, initial/deposit amount, installment amount, frequency, number of installments, first due date, grace period, reminder schedule, and seller-defined late/default rules.

### 3. Installments
Each installment stores due date, amount due, amount paid/allocated, status, reminder state, and overdue/default information.

Statuses should support:
- scheduled
- due
- partially_paid
- paid
- overdue
- defaulted
- cancelled

### 4. Payments
A payment is independent from the property/installment logic and stores purchase_id, installment allocation, amount, gateway/method, gateway transaction/reference, status, and timestamps.

Payments must be idempotent so duplicate webhooks cannot double-count money.

## Offer / acceptance flow
1. Client/admin creates an installment offer for a buyer/contact.
2. Buyer receives a secure purchase/offer link.
3. Buyer sees property, price, payment schedule and seller terms.
4. Buyer can **Accept** or **Decline**.
5. Declined offers remain recorded and can be resent/reissued if still commercially valid.
6. Offers should have an expiry date.
7. Acceptance records the agreed terms/version and timestamp.
8. Only after acceptance can the purchase proceed to payment/VA creation.
9. Buyer never needs an OFastpipeline account.

## Terms / agreement
The seller controls the commercial terms.

The system should support payment terms, grace period, late/default rules, cancellation/refund terms, property-specific conditions, and seller-provided agreement/terms content.

The accepted terms must be versioned/snapshotted so later edits do not rewrite an already accepted agreement.

## Payment modes
### Manual bank transfer
Buyer pays directly to seller's configured bank details. Payment remains `pending_verification` until client/admin confirms it.

### Checkout
Use the existing gateway abstraction and add a property-payment context/reference instead of creating a second gateway architecture.

### Virtual account
For providers that support the exact merchant/settlement model:
- buyer gives required consent
- provider customer/VA is created only after an accepted purchase
- VA is linked to the purchase/installment context
- provider webhook updates the purchase/installment
- money should settle through the provider's supported seller/client destination, not an OFastpipeline-held wallet

## Payment allocation
A buyer may pay multiple months at once.

Example: monthly installment = NGN 1,000,000; payment received = NGN 3,000,000. Allocate installments 1–3 as paid and leave installment 4 due.

For partial payments, allocate sequentially and keep the remaining amount against the current installment. Nothing should silently disappear.

## Reminders
Use the existing scheduled/pipeline communication infrastructure.

Default reminder points:
- 7 days before due date
- 3 days before due date
- 1 day before due date
- overdue reminder after due date

Channels should reuse existing configured capabilities: email, SMS, WhatsApp where supported, and IVR/voice where configured.

## Pipeline integration
Property payments must feed the existing pipeline/notification system rather than creating a parallel notification engine.

Important events include:
- offer_created
- offer_accepted
- offer_declined
- offer_expired
- installment_due
- installment_reminder
- payment_received
- payment_partially_allocated
- payment_overdue
- installment_paid
- installment_defaulted
- purchase_completed
- payment_refunded/reversed

Each event may trigger existing communication/action mechanisms.

## Ownership model
The existing property listing engine is reused for both platform-owned and client-owned properties.

Payment ownership is separate:
- platform-owned property → platform settlement configuration
- client-owned property → client/provider-supported settlement configuration

## Database migration principles
- Add new tables/columns with safe migrations.
- Preserve all existing property, lead, subscription and payment data.
- Do not reuse subscription rows as property purchases.
- Do not overload existing subscription status fields with property-commerce states.

## Security
- Secure, non-guessable buyer purchase links.
- Capability/ownership checks for admin vs client access.
- Verify buyer consent before VA creation.
- Verify gateway webhook signatures.
- Use idempotent payment references.
- Prevent cross-client property/purchase access.

## Build sequence
1. Schema/migrations.
2. Purchase + offer model.
3. Payment plan + installment model.
4. Admin/client purchase management UI.
5. Buyer secure offer/payment page.
6. Terms/acceptance/versioning.
7. Manual payments.
8. Installment allocation/balance engine.
9. Reminder scheduler + existing pipeline events.
10. Checkout extension in gateway layer.
11. VA integration only where provider/settlement model is approved.
12. Regression testing of existing property, pipeline, subscription and gateway flows.

## Explicit non-goals
- No buyer user accounts.
- No separate property listing engine.
- No OFastpipeline wallet.
- No silent over/underpayment loss.
- No replacement of existing subscription entitlement logic.
- No parallel SMS/email/IVR engine.
