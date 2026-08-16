# OFast Pipeline — Supplemental Additions to Phases 1–8

> This document records decisions, business rules, implementation details, and integration requirements added during the Phase 1–8 build that are not fully captured in the existing master blueprint or `docs/property-installments-implementation-plan.md`.
>
> This is a **supplement**, not a replacement for either document.

## Phase 1 — Property / Listing Foundation

### 1.1 Property ownership model
Properties support two ownership modes:

- **Client-owned** — linked to a client with an active listing subscription.
- **Platform-owned** — owned/listed by OFast Pipeline/Admin itself.

A blank/unassigned owner is not a valid saved/published property.

### 1.2 Admin as a valid owner
Admin/OFast Pipeline must be available as a first-class property owner. This is required for platform-owned listings and prevents the property form from forcing every listing to belong to a client.

### 1.3 Expired client listing subscription rule
A client whose listing subscription is expired/inactive must not be available as a new property owner.

Existing records may retain their historical ownership, but new assignments should only allow eligible clients.

### 1.4 Property status synchronization
The property engine has a business status (`live`, `pending_upload`, `taken`, `expired`) and a WordPress CPT publication state. These two states must remain synchronized.

Minimum mapping:

- `live` → CPT `publish`
- `taken` → CPT `publish`
- `pending_upload` → CPT `draft`
- `expired` → CPT `draft`

The plugin should reconcile legacy records that have one state saying `live` while the CPT remains `draft`.

### 1.5 Property archive / marketplace fallback
The property marketplace is intended to have a dedicated archive surface and ultimately live on the property subdomain in production.

Production target:

- `property.<base-domain>/` → public property archive/home
- `app.<base-domain>/` → CRM/application

Local development must continue to support normal WordPress/local routes as a fallback.

---

## Phase 2 — Listing / Billing Separation

### 2.1 Property listing billing is separate from CRM billing
Listing subscriptions are a property-domain business and must not be mixed with CRM subscription entitlement logic.

Property listing billing must remain visible and accountable under the property/listing area.

### 2.2 Front-end auto-funding must be recorded in the same billing source
Any automatic front-end listing funding/payment must produce a corresponding property billing/payment record so the admin and client views agree.

### 2.3 Funding requests remain a separate concept
Funding requests should not silently become property purchase payments. Property listing billing and property purchase/payment records have different purposes.

---

## Phase 3 — Clients / Ownership / Access

### 3.1 Clients are not inherently property-only
A client may be:

- CRM-only
- Listing-only
- Both CRM + Listing

The listing subscription is therefore the gate for property/listing functionality, not a condition for being a CRM client.

### 3.2 Client-side property menus are standalone
For listing subscribers, property sales tools appear as standalone client dashboard menus rather than being hidden under one "Property" parent.

Current conceptual client property navigation includes:

- My Listing / My Property
- Sales & Installments
- Purchases
- Payment Records
- Listing Billing

These items must only appear for clients with an active listing subscription.

### 3.3 Duplicate menu protection
The client property navigation must not create duplicate "My Property" entries when another component already provides the item.

---

## Phase 4 — Leads / Contacts / Offline Buyers

### 4.1 Buyer account is not required
A property buyer does **not** need an OFast Pipeline account to accept an offer or make a property payment.

### 4.2 Buyer identity is independent from client CRM identity
Buyer information can be stored directly against the property purchase/offer and can optionally be connected to an existing lead/contact.

### 4.3 Lead/contact remains useful for property sales
Property sales can use an existing lead/contact where one exists, but an offline buyer can still proceed without a lead record.

### 4.4 Staff-assisted/offline buyer support
The architecture must leave room for buyers who:

- use button phones
- are not comfortable with web forms
- submit receipts at the office
- pay through POS
- pay through bank deposit/transfer
- pay cash through an authorized office process

These methods must feed the same property payment record system rather than create parallel payment databases.

---

## Phase 5 — Offer / Acceptance / Agreement

### 5.1 Secure offer link
Installment offers use a non-guessable secure token link. Buyers can open the offer without logging in.

Conceptual route:

`/property-offer/?offer=<secure-token>`

Only the token hash is stored in the database; the raw token is only available at creation time for delivery to the buyer.

### 5.2 Buyer acceptance is a lifecycle boundary
An installment offer is not a purchase merely because an offer exists.

Lifecycle:

`Offer created → Buyer accepts → Purchase created → Payment enabled`

### 5.3 Terms acceptance/versioning
Acceptance records the agreed terms/version and acceptance timestamp/IP so later editing of seller terms does not rewrite an already accepted agreement.

### 5.4 Offer expiry / decline / reissue
Offers support:

- pending
- accepted
- declined
- expired

Declined/expired offers remain historically recorded and may be replaced/reissued where commercially appropriate.

### 5.5 Buyer notification on offer creation
The offer-creation event must produce an actionable buyer notification containing the secure acceptance link through existing communication channels.

Primary channels currently considered:

- Email
- SMS
- WhatsApp later, through the same event system

### 5.6 Acceptance must hand off to payment
After acceptance the buyer must not stop on a generic "purchase created" notice. The system must expose the secure payment entry point immediately.

Target flow:

`Accept Offer → Purchase Created → Secure Payment Link → Manual / Checkout`

---

## Phase 6 — Purchase / Payment Plan / Installments

### 6.1 Purchases are not all the same
The system distinguishes:

1. **Active installment purchase** — buyer still owes a balance.
2. **Completed purchase** — buyer is fully paid and balance is zero.
3. **Outright completed purchase** — buyer paid in full without an installment agreement.

### 6.2 Purchases admin page is completed purchases only
The main **Properties → Purchases** table is intended to represent completed property purchases.

Installment buyers with an outstanding balance must remain in the installment/payment-plan area and must not appear as completed purchases.

### 6.3 Add Purchase is an outright purchase workflow
Admin "Add Purchase" is not a shortcut for creating an installment buyer.

It represents an already-paid/full-payment transaction and records the payment method used.

### 6.4 Payment frequency
Installment plans support:

- Daily
- Weekly
- Monthly
- Quarterly
- Yearly

The selected frequency must drive due-date generation and reminder scheduling.

### 6.5 Initial payment is a payable installment
The initial/deposit amount is not automatically treated as paid merely because the agreement contains it. It becomes paid only when a verified payment is allocated to it.

### 6.6 Allocation rules
One payment may cover multiple installments. Partial payments are supported. Allocation is sequential against the oldest unpaid/partial installment and must never silently discard an amount.

### 6.7 Completion rule
A purchase becomes `completed` only when calculated successful allocated payments reduce the balance to zero.

---

## Phase 7 — Payment Records / Manual Payments / Verification

### 7.1 One canonical payment record system
Manual payments, online checkout payments, and virtual-account payments must all write to the same property payment record table.

No parallel payment databases.

### 7.2 One canonical client payment page
The client experience uses one **Payment Records** page for payment history/status.

Pending manual payment verification is an action/status inside the payment system, not a second independent payment database.

### 7.3 Secure manual payment link
The buyer can use a secure purchase-specific link without an account.

Conceptual route:

`/property-pay/?token=<secure-purchase-token>`

The buyer can submit:

- amount paid
- payer name
- payment/bank reference
- receipt
- note

Receipts are stored in private application storage rather than exposed as ordinary public media URLs.

### 7.4 Verification workflow
Manual payment lifecycle:

`pending_verification → successful`

or

`pending_verification → rejected`

Only successful payments enter the allocation engine.

### 7.5 Admin/client verification permissions
Admin can verify/reject property payments. A property client may manage manual payments belonging to that client's properties when the client has the required active listing entitlement.

### 7.6 Payment record idempotency
Gateway callbacks/webhooks must be idempotent. A repeated callback must update/confirm the same payment record rather than create duplicate money records.

### 7.7 Payment table UX
Long payment tables should support horizontal scrolling so all reconciliation fields remain available without destroying the layout.

Important fields include buyer, property, owner, amount, method, gateway, reference, status, receipt, verified-by, and created/verified timestamps.

### 7.8 Buyer-side accessibility requirement
The payment architecture must not assume every Nigerian buyer has a smartphone, email, or literacy to use a web form. Staff-assisted and office workflows must be able to record payments into the same payment record engine later.

---

## Phase 8 — Online Checkout / Gateways

### 8.1 Multiple gateways can coexist
The platform should support Paystack and Flutterwave simultaneously even if only one is configured as the primary/default gateway.

### 8.2 Primary + secondary gateway model
Recommended configuration:

- **Paystack** — primary/default
- **Flutterwave** — secondary/alternative

The actual gateway used is stored on each payment attempt.

### 8.3 No silent automatic gateway switching
Do not silently move a failed Paystack transaction to Flutterwave and create a second unknown transaction.

Prefer buyer-visible fallback:

`Paystack unavailable → offer Flutterwave as an alternative`

### 8.4 One gateway per payment attempt
Each payment attempt belongs to one provider and one gateway reference, while all providers feed the same property payment record system.

### 8.5 Buyer-facing checkout route
The checkout is reached from the secure buyer payment flow rather than by asking the buyer to construct a URL manually.

Conceptual route:

`/property-checkout/?token=<secure-token>&installment=<installment-id>`

### 8.6 Checkout lifecycle

`Secure Payment Link → Pay Online → choose configured gateway → create pending payment record → gateway checkout → verified callback/webhook → successful payment record → allocation → balance update`

### 8.7 Gateway visibility
A gateway should only be displayed when its configuration is available/usable. The primary gateway is preferred when both are configured.

### 8.8 Virtual accounts remain a separate later payment method
Virtual accounts should use the same payment record and allocation engine once implemented. They do not create a separate wallet or payment database.

---

## Cross-phase Nigeria-market requirements

These were added during implementation discussions and should remain part of the eventual complete system:

### A. Button-phone buyers
SMS/voice should be sufficient to participate in the process without requiring a web account.

### B. Office receipt submission
A buyer can bring a physical bank/POS receipt to the office. Staff records or uploads the receipt against the same payment record.

### C. Staff-assisted payment
Authorized staff can record a payment on behalf of a buyer who cannot use the online interface.

### D. Payment methods to support through the same engine

- Bank transfer
- Bank deposit
- POS
- Cash (authorized staff workflow only)
- Online checkout
- Virtual account
- Office/staff-assisted submission

### E. Third-party payer
A buyer may have another person make the payment. Payment records should preserve the payer identity/reference separately from the buyer where needed.

### F. WhatsApp is a channel, not a new payment engine
WhatsApp should later plug into existing lifecycle events (offer created, payment due, payment received, etc.) rather than creating a separate communication architecture.

---

## Canonical end-to-end flow after Phases 1–8

```text
PROPERTY
   ↓
OFFER (installment only)
   ↓
BUYER ACCEPTS
   ↓
PURCHASE + PAYMENT PLAN
   ↓
SECURE BUYER PAYMENT LINK
   ↓
┌────────────────────────────────────────┐
│ Manual Payment                         │
│ Online Checkout → Paystack/Flutterwave │
│ Virtual Account (later)                │
│ Office / Staff Assisted                │
└────────────────────────────────────────┘
   ↓
ONE PAYMENT RECORD
   ↓
VERIFY / GATEWAY CONFIRMATION
   ↓
PAYMENT ALLOCATION
   ↓
INSTALLMENT / BALANCE UPDATE
   ↓
BALANCE = 0
   ↓
COMPLETED PURCHASE
```

## Relationship to the existing implementation plan

This document supplements, but does not replace:

- the 35-phase/master project blueprint
- `docs/property-installments-implementation-plan.md`

Future phases should treat the rules above as accepted architectural decisions unless the master blueprint is deliberately revised.
