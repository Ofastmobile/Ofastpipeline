# Ofast Pipeline — Product Blueprint
## Revised Direction

### 1. Product Structure
- Ofast Pipeline becomes a unified real-estate platform.
- CRM and Property Listings are part of the same client dashboard.
- Client onboarding provides access to both immediately, subject to plan limits.
- The platform is focused on real-estate sales, installment payments, and investment/ROI.

### 2. User Types

#### Clients
Property owners, agents, or real-estate companies.
- Have Ofast Pipeline accounts.
- Manage CRM, listings, buyers, transactions, installment plans, and ROI plans according to their subscription.

#### Leads
People who submit enquiries or otherwise show interest.
- Stored in CRM.
- Can later become Buyers through a purchase.

#### Buyers
- Created through a property purchase.
- Do not require an Ofast Pipeline account.
- Their purchase and payment information is stored against their record.

#### Investors
- Separate from Leads and Buyers.
- Enter through an investment form and select an available investment/ROI plan.
- Investor payment/onboarding system is deferred until the final development phase.

### 3. Subscription Plans

| Feature | Free | Silver | Gold |
|---|---:|---:|---:|
| CRM | Yes | Yes | Yes |
| Leads | Yes | Yes | Yes |
| Buyers | Yes | Yes | Yes |
| Listings | 1 | 3 | 10 |
| Payment records | Yes | Yes | Yes |
| Customer transaction portal | Yes | Yes | Yes |
| Basic automation | Yes | Yes | Yes |
| Editable email templates | No | Yes | Yes |
| Team members | 0 | 2 | 3 |
| Installment payments | No | No | Yes |
| ROI / Investment | No | No | Yes |
| Reports | Basic | Standard | Advanced |

**Plan pricing**
- Free: ₦0
- Silver: ₦50,000
- Gold: ₦100,000

### 4. Automation

#### Free
Predefined platform automations.

#### Silver
Predefined automations with editable email templates/messages.

#### Gold
Advanced automation rules and editable messages.

Initial automated events include:
- Payment successful
- Installment reminders
- Payment overdue
- Offer acceptance
- Investment events when ROI is implemented

Email sender identity/SMTP configuration remains controlled by Ofast. Clients customize templates only.

### 5. Property Purchase Flow

```text
Ads / Owner Website
        ↓
Single Property Page
        ↓
Enquiry / Purchase Form
        ↓
CRM Lead
        ↓
Client Follow-up
        ↓
Purchase
        ↓
Buyer
        ↓
Offer / Acceptance
        ↓
Payment
        ↓
Completed Purchase
```

### 6. Installment Flow

Available to Gold clients.

```text
Property
  ↓
Offer
  ↓
Acceptance
  ↓
Installment Plan
  ↓
Payment
  ↓
Payment Record
  ↓
Next Due Date
  ↓
Fully Paid
```

A property can support both an installment option and an ROI/investment option.

### 7. ROI / Investment

Gold-only feature.

Clients can create up to **10 ROI/investment plans**.

Each plan can have:
- Custom name
- Investment amount
- Term/duration
- Return percentage
- Terms/details

Initial flow:

```text
Investment Page
      ↓
Select Investment Plan
      ↓
Investment Form
      ↓
Investor Prospect
      ↓
Client Processing
      ↓
Investment
      ↓
Payment Tracking
      ↓
Maturity
```

Investor payment/onboarding implementation is deferred until the final phase.

### 8. Buyer / Investor Transaction Access

- Buyers and investors do not need normal user accounts.
- Transactions are stored in Pipeline.
- They receive access to a single dynamic, secure transaction page.
- Access uses a unique link/code.
- The page displays only the relevant person's transaction information.

Possible information:
- Payment history
- Amount paid
- Balance
- Due dates
- Payment plan
- Investment details
- Documents/transaction information when implemented

### 9. Payment Architecture

#### Client Subscription
- Paystack is the primary payment gateway.
- Client subscription does not use a Virtual Account.
- Normal Paystack checkout/manual payment can be used.

#### Buyer Property Payments
- Buyers are the primary users of Virtual Accounts.
- Paystack DVA/Virtual Account is intended for buyer property payments and recurring installment payments.
- Incoming payments must be mapped to the correct client, buyer, purchase, and payment/installment record.

#### Removed / Deferred
- Monnify: remove completely.
- Flutterwave: not required as the primary gateway; may be removed after final verification.
- Client subscription Virtual Accounts: remove.

### 10. Communication

#### SMS
- Automatic.
- Ofast controls the provider account.
- Clients do not bring their own SMS API/account.
- SmartSMSSolutions can serve as an SMS fallback provider.
- Client SMS balances remain an internal Ofast usage/credit concern rather than separate provider accounts.

#### Email
- Automatic.
- Ofast controls SMTP and sender/domain configuration.
- Clients can customize their email templates.
- Clients cannot change the platform SMTP/sender identity.

#### WhatsApp
- No WhatsApp API initially.
- Use normal WhatsApp click-to-chat links for direct conversations.
- Infobip WhatsApp API is deferred because of cost.
- Automated WhatsApp transaction notifications can be considered later.

#### Voice
- Africa's Talking remains the voice provider.
- IVR is removed completely.
- No IVR menus or digit selection.

### 11. Direct Buyer → Owner Calling

Primary voice feature:

```text
Buyer
  ↓
Call Owner
  ↓
Ofast
  ↓
Africa's Talking
  ↓
Buyer ↔ Property Owner
```

- Buyer does not need an Ofast account.
- Client funds an internal Ofast voice-credit balance.
- Ofast uses its single Africa's Talking account to place/bridge calls.
- Ofast deducts the client's voice credit based on actual voice usage.
- Calls stop working for that client when available voice credit is exhausted.
- Call duration, status, owner, buyer/lead, property, and cost should be recorded.

### 12. Optional Automated Voice Notifications

Voice is optional and reserved for important payment confirmations.

#### Installment Buyer
After successful payment:
- Automatic SMS
- Automatic email
- Optional short voice confirmation

Voice content can state:
- Payment received
- Amount paid
- Next due date

Due-date reminders remain SMS/email by default.

#### Investor
When ROI is implemented:
- Automatic SMS
- Automatic email
- Optional short voice confirmation that payment was received

Voice should not read the entire transaction.

### 13. Communication Credit Architecture

Use one provider account per service at Ofast level.

```text
Ofast Provider Account
        ↓
Multiple Ofast Clients
        ↓
Individual internal client credits/usage
```

Clients do not create Africa's Talking, SMS-provider, or WhatsApp-provider accounts.

### 14. Removed Features / Decisions

- Remove IVR completely.
- Remove client Virtual Accounts.
- Remove Monnify.
- Do not require buyer/investor accounts.
- Do not require clients to provide their own SMS API.
- Do not implement WhatsApp API initially.
- Do not build investor payment/onboarding deeply until the final phase.
- Do not add a separate document-management feature unless required later.
- Voice/SMS plan restrictions are not finalized as subscription-plan features yet.

### 15. Development Priority

1. Unified CRM + Listings experience.
2. Finalize Free/Silver/Gold restrictions.
3. Client subscription through Paystack without client VA.
4. Buyer property payment through Paystack Virtual Account/DVA.
5. Installment system for Gold.
6. Automatic SMS/email transaction notifications.
7. Direct buyer → owner calling through Africa's Talking; remove IVR.
8. Optional successful-payment voice notification.
9. ROI/investment system last.
10. Revisit WhatsApp API only when automated WhatsApp notifications justify the cost.
