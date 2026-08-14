# OFastpipeline Property Commerce & Payment Architecture Blueprint

**Project:** OFastpipeline  
**Repository:** https://github.com/Ofastmobile/Ofastpipeline  
**Purpose:** Blueprint for extending OFastpipeline from business lead-generation/pipeline automation into property listings, property purchases, installment payments, and payment-provider integrations.

---

## 1. Core Product Philosophy

OFastpipeline is primarily a **business lead-generation and sales pipeline platform**.

Original flow:

```text
AD
 ↓
LANDING PAGE
 ↓
FORM
 ↓
LEAD
 ↓
PIPELINE
 ↓
CLIENT DASHBOARD
 ↓
SMS / EMAIL / WHATSAPP / IVR ACTIONS
```

There is intentionally **no buyer/user account requirement**.

Property functionality was added later:

```text
PROPERTY LISTING
 ↓
PROPERTY SINGLE PAGE
 ↓
BUYER / INQUIRY FORM
 ↓
LEAD / CONTACT
 ↓
PIPELINE
```

The property page acts as the landing page.

### Important principle

> **Property buyers are not OFastpipeline users.**

A buyer may be a lead/contact/purchaser in the database without having:

- an OFastpipeline account
- username
- password
- buyer dashboard
- platform login

This philosophy must remain intact.

---

# 2. Two Different Payment Businesses

OFastpipeline now has two distinct payment contexts.

## A. SaaS subscription payment

This is the existing business:

```text
CLIENT
 ↓
SELECT SUBSCRIPTION
 ↓
PAY
 ↓
PAYSTACK / FLUTTERWAVE / MONNIFY
 ↓
WEBHOOK
 ↓
IDENTIFY CLIENT
 ↓
ACTIVATE SUBSCRIPTION / FEATURES
```

The client subscription determines the features the client can use.

This existing architecture should remain.

If a gateway such as Monnify is removed because of a checkout problem, that is a **gateway-specific decision**, not a reason to redesign the subscription entitlement system.

---

## B. Property commerce payment

This is the new domain:

```text
PROPERTY
 ↓
BUYER / CONTACT
 ↓
PURCHASE
 ↓
PAYMENT PLAN
 ↓
INSTALLMENTS
 ↓
PAYMENTS
```

The property payment system should NOT be implemented as another subscription system.

It should be a separate business context that reuses the existing gateway infrastructure.

---

# 3. One Property Listing Engine

Do NOT build a separate property system for admin listings and client listings.

Both should use the same property listing engine.

Conceptually:

```text
                    PROPERTY LISTING
                         │
             ┌───────────┴───────────┐
             │                       │
          PLATFORM                 CLIENT
             │                       │
             └───────────┬───────────┘
                         │
                    SAME SYSTEM
```

The important difference is ownership.

Conceptually:

```text
owner_type = platform
owner_id   = ...

owner_type = client
owner_id   = ...
```

Use the actual existing database/schema conventions when implementing this.

The following should remain shared:

- property fields
- property images
- property single page
- listing status
- inquiry form
- lead/contact capture
- pipeline
- automation
- purchase records
- installment records
- payment records

Only ownership/payment configuration differs.

---

# 4. Buyer Does Not Need an Account

A buyer can originate in several ways.

## Online buyer

```text
PROPERTY PAGE
 ↓
FORM
 ↓
CONTACT / LEAD
 ↓
PURCHASE
```

## Offline buyer

An admin/client can manually create the contact:

```text
ADD CONTACT / BUYER

Name: John Doe
Phone: 080...
Email: john@email.com
```

Then create:

```text
PURCHASE #5001

Property: Plot A
Buyer: John Doe
Price: ₦10,000,000

Plan:
Initial: ₦2,000,000
Monthly: ₦1,000,000
Duration: 8 months
```

The buyer still does NOT become an OFastpipeline user.

### Important distinction

A person can be:

1. OFastpipeline user/client
2. Contact/lead
3. Property buyer
4. Payment-provider customer

These are separate concepts.

---

# 5. The Purchase Is the Missing Business Abstraction

Property payment should revolve around a **Purchase**.

Recommended conceptual structure:

```text
PROPERTY
   ↓
PURCHASE
   ↓
PAYMENT PLAN
   ↓
INSTALLMENTS
   ↓
PAYMENTS
```

Example:

```text
Purchase #5001

Property: Plot A
Buyer: Contact #874
Owner: Client #32
Total price: ₦10,000,000

Payment plan:
Initial payment: ₦2,000,000
Monthly payment: ₦1,000,000
Duration: 8 months
```

Installments:

```text
Installment #1
₦2,000,000
PAID

Installment #2
₦1,000,000
PAID

Installment #3
₦1,000,000
DUE

Balance:
₦7,000,000
```

The purchase system should know:

- property
- owner
- buyer/contact
- total price
- payment plan
- installments
- amount paid
- outstanding balance
- purchase status
- payment history

---

# 6. Payment Methods Should Be Pluggable

The purchase system should not care how the money arrived.

Conceptually:

```text
                    PURCHASE
                       │
                  PAYMENT PLAN
                       │
                    PAYMENT
                       │
          ┌────────────┼────────────┐
          │            │            │
       Manual       Checkout        VA
          │            │            │
       Bank        Paystack/FLW   Paystack/FLW
```

A payment record should contain information such as:

```text
payment_id
purchase_id
installment_id
amount
gateway
gateway_reference
payment_method
status
created_at
confirmed_at
```

---

# 7. Manual Bank Transfer

Manual payment requires no payment gateway.

For a client property:

```text
PROPERTY
 ↓
PURCHASE
 ↓
INSTALLMENT
 ↓
CLIENT'S BANK ACCOUNT
```

Buyer sees:

```text
Bank: GTBank
Account Name: ABC Properties
Account Number: XXXXX
Amount: ₦1,000,000
```

Buyer transfers money.

Then:

```text
BUYER
 ↓
BANK TRANSFER
 ↓
CLIENT RECEIVES MONEY
 ↓
BUYER SUBMITS REFERENCE / RECEIPT
 ↓
CLIENT / ADMIN VERIFIES
 ↓
OFastpipeline marks payment confirmed
```

Do NOT automatically mark a manual transfer as paid simply because a buyer uploads a receipt.

Use:

```text
PENDING VERIFICATION
       ↓
   CONFIRMED
       OR
    REJECTED
```

---

# 8. Your Own Properties

You are both:

- OFastpipeline platform owner
- property seller/listing owner

Your own property can use the platform's existing payment-provider configuration.

Conceptually:

```text
YOUR PROPERTY
 ↓
PURCHASE
 ↓
BUYER / CONTACT
 ↓
YOUR PAYSTACK / FLUTTERWAVE
 ↓
YOUR SETTLEMENT
```

For a VA:

```text
BUYER / CONTACT
 ↓
PAYMENT-PROVIDER CUSTOMER
 ↓
DEDICATED VA
 ↓
PAYMENT
 ↓
YOUR SETTLEMENT
```

The buyer still does not need an OFastpipeline account.

The payment-provider customer is a separate entity.

---

# 9. Offline Buyer + Virtual Account

An offline buyer can be added manually.

Example:

```text
Contact #874
John Doe
080...
```

Then:

```text
Purchase #5001
 ↓
Property A
 ↓
Buyer = Contact #874
```

When a VA is required, the backend can create a customer at the payment provider using the buyer's details.

Conceptually:

```text
OFastpipeline
John = Contact #874
        ↓
Payment provider
John = Customer CUS_xxxxx
        ↓
DVA
1234567890
```

The buyer does not need:

- OFastpipeline account
- password
- buyer dashboard

They are simply represented as a payment-provider customer and a contact/purchaser inside OFastpipeline.

---

# 10. Existing Gateway Architecture

The repository already has gateway implementations under:

```text
/includes/gateways/
```

The existing gateway implementations are primarily designed around the SaaS subscription/payment model.

The current architecture uses platform-level gateway credentials.

For example, the Paystack gateway reads the platform Paystack secret from a WordPress option:

```php
$this->secret_key =
    OFP_Security::decrypt(
        get_option( 'ofp_paystack_secret_key', '' )
    );
```

Therefore the current architecture is essentially:

```text
OFastpipeline
 ↓
PLATFORM GATEWAY CREDENTIALS
 ↓
CLIENT SUBSCRIPTION PAYMENT
```

It is NOT currently:

```text
Client A → own Paystack secret
Client B → own Paystack secret
Client C → own Paystack secret
```

This distinction is important.

---

# 11. Do Not Immediately Make Clients Paste Secret API Keys

A possible implementation would be:

```text
Client Dashboard
 ↓
Paystack
Secret Key: ********
Public Key: ********
```

and store encrypted credentials per client.

Technically possible, but NOT the preferred long-term model.

Reasons:

- OFastpipeline would store third-party payment credentials
- increased security responsibility
- more complicated credential rotation
- harder support
- more liability around secret handling
- poor scalability as a marketplace/payment platform

Only use this model if a provider's supported integration genuinely requires it and secure credential storage is deliberately designed.

---

# 12. Preferred Client Payment Model

For client-owned property, the preferred direction is:

```text
CLIENT
 ↓
PAYMENT-PROVIDER ACCOUNT / SETTLEMENT DESTINATION
 ↓
PROPERTY
 ↓
BUYER
 ↓
PAYMENT
```

Rather than:

```text
BUYER
 ↓
OFastpipeline account
 ↓
OFastpipeline holds money
 ↓
CLIENT
```

The preferred architecture is:

> **The payment provider handles movement and settlement of money; OFastpipeline handles the commercial transaction, purchase, installment schedule, webhook processing and automation.**

This keeps OFastpipeline primarily as software/orchestration rather than a wallet/escrow system.

---

# 13. Paystack Subaccounts

Paystack currently supports subaccounts.

Subaccounts can be used to represent settlement destinations for different businesses and can be used with transaction splitting.

Official documentation:

https://paystack.com/docs/api/subaccount/

Paystack also documents multi-split payments:

https://paystack.com/docs/payments/multi-split-payments/

Conceptually:

```text
OFastpipeline
      ↓
Paystack integration
      ↓
Client A subaccount
      ↓
Client A settlement
```

For a property purchase:

```text
BUYER
 ↓
PAYSTACK
 ↓
CLIENT'S SETTLEMENT DESTINATION
 ↓
WEBHOOK
 ↓
OFastpipeline
 ↓
PURCHASE #5001
 ↓
INSTALLMENT MARKED PAID
```

---

# 14. Paystack Dedicated Virtual Accounts

Paystack currently supports Dedicated Virtual Accounts.

Official documentation:

https://paystack.com/docs/payments/dedicated-virtual-accounts/

The important part for this architecture is that a DVA is associated with a payment-provider customer.

Conceptually:

```text
Buyer/contact
 ↓
Paystack customer
 ↓
Dedicated Virtual Account
 ↓
Payment
 ↓
Webhook
 ↓
Purchase
```

Paystack also documents attaching a subaccount to a DVA.

That makes the following architecture technically interesting:

```text
CLIENT
 ↓
SUBACCOUNT / SETTLEMENT DESTINATION
 ↓
BUYER'S DVA
 ↓
INSTALLMENT PAYMENTS
 ↓
PAYSTACK
 ↓
CLIENT SETTLEMENT
```

This is one of the strongest reasons to investigate Paystack for the property VA implementation.

However, provider eligibility, KYC, business category, onboarding and commercial requirements must be confirmed before production use.

Do not assume technical API availability automatically means the business model is approved.

---

# 15. Flutterwave

Flutterwave also supports virtual-account functionality and subaccounts/split payments.

Relevant official documentation:

https://developer.flutterwave.com/v3.0/docs/ngn-virtual-accounts

https://developer.flutterwave.com/v3.0/docs/split-payments

The same general architecture can be investigated:

```text
CLIENT
 ↓
PAYMENT PROVIDER SETTLEMENT DESTINATION
 ↓
PROPERTY
 ↓
BUYER
 ↓
CHECKOUT / VA
 ↓
WEBHOOK
 ↓
OFastpipeline
```

Flutterwave's marketplace/subaccount model also places merchant-vetting responsibilities on the platform, so this must be considered when onboarding property businesses.

---

# 16. Payment Ownership

Introduce the concept of **payment owner**.

Conceptually:

```text
Payment Owner
     │
     ├── Platform
     │
     └── Client
```

### Your property

```text
owner = platform
payment_owner = platform
```

### Client property

```text
owner = client
payment_owner = client
```

This distinction allows the same property system to serve both cases.

---

# 17. Payment Context

The gateway layer should not assume every payment is a subscription.

Introduce a payment context/purpose.

Examples:

```text
subscription
property_purchase
property_installment
```

Or use reference prefixes:

```text
ofp_sub_123
ofp_purchase_5001
ofp_installment_9003
```

Then gateway webhooks can route appropriately:

```text
WEBHOOK
   │
   ├── subscription reference
   │       ↓
   │   Subscription handler
   │
   └── property reference
           ↓
       Property payment handler
```

This is an extension of the current gateway architecture rather than a separate gateway system.

---

# 18. Important Gateway Refactor Direction

Current gateway logic is heavily connected to:

```text
client_id
subscription
```

That is appropriate for existing SaaS payments.

For property commerce, payment identity should eventually be based around:

```text
purchase_id
installment_id
payment_reference
```

rather than using `client_id` as the primary identity of the transaction.

Example:

```text
Payment:
purchase_id = 5001
installment_id = 3
amount = 1000000
gateway = paystack
reference = ofp_installment_9003
status = successful
```

Then the webhook can find:

```text
Purchase #5001
 ↓
Property
 ↓
Buyer
 ↓
Owner
 ↓
Installment #3
```

---

# 19. Pipeline Integration

The property payment should continue to fit the original OFastpipeline philosophy.

Final conceptual flow:

```text
AD
 ↓
PROPERTY PAGE
 ↓
FORM
 ↓
CONTACT / LEAD
 ↓
PIPELINE
 ↓
PURCHASE
 ↓
INSTALLMENT
 ↓
PAYMENT
 ↓
WEBHOOK / MANUAL CONFIRMATION
 ↓
PURCHASE UPDATED
 ↓
PIPELINE ACTIONS
```

Potential automated actions:

```text
Payment successful
 ↓
SMS
Email
WhatsApp
Internal notification
Update pipeline stage
Update purchase balance
Generate receipt
```

This is where OFastpipeline's existing strength becomes useful.

---

# 20. No Buyer Dashboard Required

The system can remain completely transactional.

A buyer can receive a secure payment page containing:

```text
Property
Purchase
Payment plan
Amount due
Amount paid
Outstanding balance
Payment instructions
Pay button
```

This does NOT require a traditional account.

A secure tokenized purchase URL can be used if needed.

Example concept:

```text
/purchase/secure/{token}
```

The exact URL/security implementation should use non-guessable tokens and appropriate authorization/expiration controls.

---

# 21. Three Client Payment Modes

A property owner could eventually choose:

## Mode 1 — Manual

```text
Client bank account
 ↓
Buyer transfer
 ↓
Verification
 ↓
OFastpipeline records payment
```

## Mode 2 — Checkout

```text
Buyer
 ↓
Paystack / Flutterwave checkout
 ↓
Client settlement
 ↓
Webhook
 ↓
OFastpipeline
```

## Mode 3 — Virtual Account

```text
Buyer
 ↓
Dedicated Virtual Account
 ↓
Payment provider
 ↓
Client settlement
 ↓
Webhook
 ↓
OFastpipeline
```

The property/purchase system remains identical.

Only the payment method changes.

---

# 22. Recommended Data Relationships

Conceptually:

```text
CLIENT
   │
   └── PROPERTY
          │
          └── PURCHASE
                 │
                 ├── BUYER / CONTACT
                 │
                 ├── PAYMENT PLAN
                 │       │
                 │       └── INSTALLMENTS
                 │
                 └── PAYMENTS
```

Payment configuration:

```text
PAYMENT OWNER
   │
   ├── PLATFORM
   │
   └── CLIENT
```

Payment method:

```text
PAYMENT
   │
   ├── MANUAL
   ├── CHECKOUT
   └── VIRTUAL_ACCOUNT
```

---

# 23. What NOT To Build

Avoid:

- Buyer user accounts
- Buyer passwords
- Buyer dashboard as a requirement
- OFastpipeline wallet
- OFastpipeline holding client installment funds
- Separate admin property engine
- Separate client property engine
- Property-specific gateway classes when existing gateway adapters can be reused
- Unencrypted client secret keys
- Automatic confirmation of manual bank-transfer receipts
- Generating VAs merely because someone submitted a property inquiry

---

# 24. What SHOULD Be Built

Core domain:

```text
Contact / Lead
 ↓
Property Purchase
 ↓
Payment Plan
 ↓
Installments
 ↓
Payments
```

Payment layer:

```text
Manual
Paystack
Flutterwave
Virtual Account
```

Ownership layer:

```text
Platform
Client
```

Payment context:

```text
Subscription
Property Purchase
Property Installment
```

Webhook routing:

```text
Gateway
 ↓
Reference
 ↓
Payment context
 ↓
Correct handler
```

---

# 25. Your Own Property vs Client Property

## Your listing

```text
Platform
 ↓
Property
 ↓
Buyer
 ↓
Purchase
 ↓
Your existing gateway configuration
 ↓
Settlement
```

## Client listing

```text
Client
 ↓
Property
 ↓
Buyer
 ↓
Purchase
 ↓
Client payment destination
 ↓
Settlement
```

Same:

- listing
- property page
- contact
- purchase
- installment
- payment
- webhook
- pipeline

Different:

- owner
- payment destination

---

# 26. Subscription and Property Payments Must Remain Separate

Do NOT make:

```text
property payment
```

automatically behave like:

```text
subscription payment
```

Existing subscription:

```text
Payment
 ↓
Client
 ↓
Subscription
 ↓
Entitlements
```

Property:

```text
Payment
 ↓
Purchase
 ↓
Installment
 ↓
Balance
 ↓
Pipeline
```

Both can share:

- gateway adapters
- webhook infrastructure
- transaction/reference handling
- payment status
- security utilities

But they should have separate business handlers.

---

# 27. Regulatory / Commercial Boundary

The safest architectural objective is:

> **OFastpipeline is software that orchestrates and records transactions, while the licensed payment provider processes and settles the money.**

Avoid deliberately designing:

```text
Buyer
 ↓
OFastpipeline wallet/account
 ↓
OFastpipeline holds money
 ↓
Client
```

That moves the product toward payment aggregation, escrow, stored value or other regulated activity.

For client property payments, use payment-provider-supported settlement/subaccount/marketplace mechanisms where appropriate.

For manual payments, the buyer pays the client's own bank account and OFastpipeline records/automates the transaction.

For your own properties, your platform's payment account can be used.

Final legal/regulatory structure should be confirmed with the payment provider and, where necessary, qualified Nigerian legal/compliance professionals before launch.

---

# 28. Recommended Implementation Order

The implementation should follow the architecture rather than building random gateway features first.

## Step 1 — Preserve existing subscriptions

Do not break:

```text
subscription
 ↓
gateway
 ↓
webhook
 ↓
entitlement
```

## Step 2 — Formalize property ownership

Use one property engine for:

```text
platform
client
```

## Step 3 — Add Purchase

A property inquiry/lead can become a purchase.

Offline contacts can also become purchases.

## Step 4 — Add Payment Plan

Represent:

- total price
- initial payment
- frequency
- duration
- installments
- balance

## Step 5 — Add Payment records

Make payment independent from the gateway.

## Step 6 — Add manual payment

This allows property installment functionality without any gateway integration.

## Step 7 — Extend existing gateway abstraction

Add property payment context/reference handling.

## Step 8 — Add checkout

Use provider-supported client settlement mechanisms.

## Step 9 — Add VA

Only after provider onboarding/KYC/settlement requirements are confirmed.

---

# 29. Final Architecture

The target system should look like this:

```text
                         OFastpipeline
                              │
             ┌────────────────┴────────────────┐
             │                                 │
        SaaS Platform                     Property Commerce
             │                                 │
        SUBSCRIPTIONS                         │
             │                         ┌───────┴────────┐
             │                         │                │
          CLIENT                    PLATFORM          CLIENT
             │                      LISTING           LISTING
             │                         │                │
             │                         └───────┬────────┘
             │                                 │
             │                            PROPERTY
             │                                 │
             │                            CONTACT/BUYER
             │                                 │
             │                              PURCHASE
             │                                 │
             │                           PAYMENT PLAN
             │                                 │
             │                            INSTALLMENTS
             │                                 │
             │                              PAYMENTS
             │                                 │
             │                 ┌───────────────┼───────────────┐
             │                 │               │               │
             │              MANUAL          CHECKOUT            VA
             │                 │               │               │
             │               BANK        PAYSTACK/FLW      PAYSTACK/FLW
             │                 │               │               │
             │                 └───────────────┼───────────────┘
             │                                 │
             │                              WEBHOOK
             │                                 │
             │                            OFastpipeline
             │                                 │
             │                         Purchase updated
             │                                 │
             └───────────────────────────── PIPELINE
                                               │
                                  SMS / EMAIL / WHATSAPP / IVR
```

---

# 30. The Core Rule

Keep this rule visible while building:

> **OFastpipeline owns the listing, lead/contact, purchase, installment, payment record, automation and business logic. The payment provider owns the actual movement and settlement of money.**

And:

> **A buyer is a contact/purchaser, not an OFastpipeline user.**

And:

> **Your properties and client properties use the same property/purchase system; payment ownership is what changes.**

And:

> **Existing SaaS subscription payments remain separate from property purchase payments, while both can reuse the same gateway infrastructure.**

---

# 31. Current Decision Summary

### KEEP

- Existing subscription payment system
- Client subscription entitlement logic
- Existing gateway classes
- Existing webhook infrastructure
- No buyer accounts
- One property listing engine
- Pipeline automation

### ADD

- Purchase entity/domain
- Payment plan
- Installment schedule
- Payment records
- Manual payment mode
- Property payment context
- Property payment references
- Client payment ownership/configuration
- Provider-supported settlement integration
- VA support later

### AVOID

- OFastpipeline holding client money
- Buyer accounts
- Duplicated property systems
- Client secret-key dumping
- Treating property purchases as subscriptions
- Automatic manual-payment confirmation

---

# 32. Provider Research References

## Paystack

Dedicated Virtual Accounts:
https://paystack.com/docs/payments/dedicated-virtual-accounts/

Subaccounts:
https://paystack.com/docs/api/subaccount/

Multi-split payments:
https://paystack.com/docs/payments/multi-split-payments/

## Flutterwave

NGN Virtual Accounts:
https://developer.flutterwave.com/v3.0/docs/ngn-virtual-accounts

Split Payments:
https://developer.flutterwave.com/v3.0/docs/split-payments

---

# 33. Blueprint Status

**Architecture decision:** APPROVED FOR IMPLEMENTATION

**Buyer accounts:** NO

**Separate admin/client property engines:** NO

**Existing subscription gateway system:** KEEP

**Property purchase domain:** ADD

**Manual installments:** YES

**Checkout installments:** YES, subject to provider settlement/onboarding model

**Virtual-account installments:** YES, subject to provider capabilities, KYC, settlement and commercial approval

**OFastpipeline holding client funds:** NO

**Client payment destination:** Prefer provider-supported client/subaccount/connected settlement architecture

**Your own property payments:** Existing platform gateway configuration

**Client property payments:** Client-specific payment destination/settlement configuration

**Primary architectural principle:** Reuse the existing gateway infrastructure; add property commerce as a new payment context rather than creating a second payment platform.

---

# 34. Final Payment Provider Connection & Safety Additions

These rules were added after further discussion of the payment-provider architecture.

## 34.1 Client Payment Connection

For client-owned property payments, the preferred model is:

```text
CLIENT
 ↓
Payment-provider setup
 ↓
Subaccount / settlement destination
 ↓
Provider account ID stored against client
 ↓
Property Purchase
 ↓
Buyer Payment
 ↓
Client settlement
```

The client should not automatically be required to paste raw secret API keys into OFastpipeline.

Use a provider-supported onboarding/connection mechanism where available. If a provider requires a different setup, design that explicitly and securely.

## 34.2 Buyer VA Consent

A buyer-specific virtual account must NOT be created merely because someone submitted an inquiry.

Recommended flow:

```text
Buyer interested
 ↓
Buyer accepts installment purchase
 ↓
Buyer explicitly consents to payment-provider customer/VA creation
 ↓
Create payment-provider customer
 ↓
Create VA
 ↓
Attach VA to Purchase
```

The buyer remains a contact/purchaser, NOT an OFastpipeline user.

## 34.3 Payment Ownership & Settlement

Every property purchase should conceptually identify:

```text
payment_owner
settlement_destination
```

Examples:

### Your own property

```text
payment_owner = platform
settlement_destination = platform payment account
```

### Client property

```text
payment_owner = client
settlement_destination = client's provider-supported destination
```

This keeps the same Purchase system usable for both platform-owned and client-owned properties.

## 34.4 Payment Idempotency

Gateway webhooks can be delivered more than once.

A payment must never be counted twice.

Every gateway payment should have a unique provider transaction/reference.

Conceptually:

```text
gateway
gateway_transaction_id
payment_reference
```

Before recording a successful payment:

```text
Does this transaction/reference already exist?
    ↓
YES → ignore duplicate
NO  → record payment
```

This is mandatory for installment accounting.

## 34.5 Installment Lifecycle

Installments should have explicit states.

Recommended lifecycle:

```text
scheduled
   ↓
due
   ↓
paid
```

Additional states:

```text
partially_paid
overdue
cancelled
defaulted
completed
```

The actual rules for overdue/defaulted status should be determined by the property seller's agreed payment terms.

## 34.6 Manual Payment Reconciliation

Manual bank-transfer payments should never become confirmed automatically just because a buyer submits a receipt.

Use:

```text
pending_verification
      ↓
 ┌────┴────┐
 ↓         ↓
confirmed  rejected
```

Record:

```text
verified_by
verified_at
verification_note
```

This provides an audit trail.

## 34.7 Purchase Status vs Payment Status

Keep **Purchase Status** separate from **Payment Status**.

### Purchase Status

```text
active
completed
cancelled
defaulted
```

### Payment Status

```text
pending
successful
failed
refunded
reversed
```

Do not make them the same field.

Example:

```text
Purchase = active
Payment = refunded
```

This is possible and must be representable.

## 34.8 Refund / Cancellation / Default Rules

Before production payment functionality is released, define what happens when:

- buyer cancels
- seller cancels
- property is withdrawn
- buyer defaults
- installment is overpaid
- payment is refunded
- payment is reversed
- property becomes unavailable while a payment plan is active

These rules should not be hard-coded casually.

The Purchase, Installment and Payment models should support the states required by the agreed business rules.

## 34.9 Final Build Rule

The property commerce layer must not make OFastpipeline responsible for holding client funds.

Target:

```text
OFastpipeline
=
Listing + Contact + Purchase + Installments + Payment Records + Automation

Payment Provider
=
Payment Processing + Settlement + Provider-side Compliance
```

The buyer remains:

```text
Contact / Purchaser
```

not:

```text
OFastpipeline User
```

---

# 35. Final Blueprint Sign-Off

The architecture is considered ready for implementation with these additions.

### Core

```text
Property
 ↓
Buyer / Contact
 ↓
Purchase
 ↓
Payment Plan
 ↓
Installments
 ↓
Payments
```

### Payment methods

```text
Manual Bank Transfer
Checkout
Virtual Account
```

### Ownership

```text
Platform
Client
```

### Payment context

```text
Subscription
Property Purchase
Property Installment
```

### Required safeguards

```text
Buyer VA consent
Webhook idempotency
Manual-payment verification
Separate purchase/payment status
Refund/reversal support
Explicit settlement ownership
```

### Core architectural rule

> OFastpipeline manages the commercial record and automation. The payment provider handles the actual movement and settlement of money.

### Buyer rule

> A property buyer does not need an OFastpipeline account.

### Property rule

> Platform-owned and client-owned properties use the same listing and purchase architecture.

### Gateway rule

> Existing gateway adapters are extended rather than replaced. Property payments become a new payment context rather than another subscription system.


### Additional architectural considerations
> docs/property-installments-implementation-plan.md (fetch this from my branch in github as an additional things added to the blueprint)
