# Unified client plan (Free / Silver / Gold)

The product is one real-estate app. CRM and listings are features of **one**
client plan, not two subscriptions.

This pass does **not** SQL-rename old rows. Old keys stay in the database.
Feature gates and new payments use the unified names.

## Canonical helper

```php
OFP_Subscription::client_plan( $client_id ); // 'free' | 'silver' | 'gold'
```

Mapping (highest of `ofp_clients.plan`, `listing_plan`, and an active listing row):

| Stored key | Unified |
|---|---|
| starter, bronze, free | free |
| growth, silver | silver |
| pro, gold | gold |

Other helpers:

- `client_plan_at_least( $id, 'silver' )`
- `has_paid_plan( $id )` — silver or gold
- `allows_email_templates( $id )` — silver+
- `allows_installments( $id )` — gold only
- `team_member_limit( $plan )` — 0 / 2 / 3
- `has_platform_access( $id )` — not suspended (use this instead of `has_active('crm')` / `has_active('listing')` for feature gates)
- `unified_plan_price( $plan )` — listing-plan option prices (what Funding already charges)

`has_active( 'crm' | 'listing' )` still means “a paid row of that **legacy type** exists”. Do not use it to hide CRM, leads, listings, or credits.

## What changed in billing

- `get_expected_monthly_total()` returns **one** plan price. It no longer adds CRM + listing.
- Unmatched VA / webhook full payments write **one** `type = listing` row at the unified tier.
- New checkouts that still pass `type = crm` are remapped to a listing-plan checkout.
- After any recorded payment, `ofp_clients.plan` and `listing_plan` are synced to the unified key.

Historical `ofp_subscriptions` rows are left as-is.

## Feature gates

| Feature | Free | Silver | Gold |
|---|---|---|---|
| CRM, leads, buyers | yes | yes | yes |
| Listings | 1 | plan cap | plan cap |
| Featured listing | no | yes | yes |
| Email templates | no | yes | yes |
| Team seats | 0 | 2 | 3 |
| Installment offers | view existing | view existing | create + view |
| SMS / voice credit top-up | yes | yes | yes |

## How to test on Local

1. Stash or commit any unpushed Local work.
2. `git fetch && git checkout fix/unify-client-plan`
3. Confirm a client with only a CRM `growth` row sees **Silver** (listings + templates).
4. Confirm a client with CRM + listing is **not** asked to pay both prices.
5. Confirm Free can open Leads and add 1 property.
6. Confirm installment **create** is Gold-only; existing offers still list.

Do not merge to `main` until that Local pass is green.

## Later (not this branch)

- Migrate old `type=crm` rows and drop dual-price summing for good.
- Optionally rename stored keys starter/growth/pro → free/silver/gold in SQL.
- Align displayed prices with the blueprint (₦50k / ₦100k) if listing option prices are still the old ₦15k / ₦30k defaults.
