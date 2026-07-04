# Phase 11 — Editable Plan Pricing

## Manifest

**REPLACED (full files — safe to drop in as-is):**
- `includes/class-ofp-subscription.php`
- `public/templates/signup.php`

**PATCHES (targeted inserts/replacements into existing files I don't have
the current source of — see `patches/` folder for exact blocks + anchors):**
- `patches/1-settings-php-INSERT.md` → `admin/views/settings.php`
- `patches/2-admin-menu-php-INSERT.md` → `admin/class-ofp-admin-menu.php`
- `patches/3-clients-list-php-REPLACE-block.md` → `admin/views/clients-list.php`

**No schema change.** Pricing lives in `wp_options`, not a DB table —
no `ALTER TABLE`, no deactivate/reactivate needed.

**No gateway file changes needed.** Per the continuation blueprint,
`class-ofp-gateway-monnify.php`, `-paystack.php`, and `-flutterwave.php`
already call `OFP_Subscription::get_expected_monthly_total()` for
webhook amount-matching — since that method now reads live pricing
internally, all three gateways pick up editable pricing automatically
with zero changes on their end.

---

## What changed, conceptually

Before: `CRM_PRICES` was a hardcoded array duplicated (in spirit) across
`class-ofp-subscription.php`'s `resolve_amount()`, the signup template,
and referenced conceptually in the original Monnify webhook code.

After: three new option groups, all managed from one place
(Settings > Plans & Pricing):

| Option key | Meaning | Default |
|---|---|---|
| `ofp_plan_price_starter` | Starter monthly fee | 25000 |
| `ofp_plan_price_growth` | Growth monthly fee | 45000 |
| `ofp_plan_price_pro` | Pro monthly fee | 75000 |
| `ofp_plan_setup_fee_starter` | Starter one-time setup | 15000 |
| `ofp_plan_setup_fee_growth` | Growth one-time setup | 25000 |
| `ofp_plan_setup_fee_pro` | Pro one-time setup | 40000 |
| `ofp_listing_fee_monthly` | Per-property listing fee | 7500 *(this option already existed)* |

Every price shown anywhere in the plugin should now be read through
one of `OFP_Subscription::get_plan_prices()`, `get_setup_fees()`,
`get_plan_price( $plan )`, `get_setup_fee( $plan )`, or
`get_listing_fee()` — never hardcoded again.

---

## Your test steps

1. Drop `class-ofp-subscription.php` and `signup.php` into place
   (straight file replace, no zip needed, no deactivate/reactivate).
2. Apply the three patches manually — each patch file tells you exactly
   what to find and what to insert/replace. Since I reconstructed these
   from the blueprint docs rather than your actual current file
   contents, please diff carefully before saving, in case your real
   files have since diverged from what's documented.
3. Visit `wp-admin → OFast Pipeline → Settings` — you should see a new
   **Plans & Pricing** section with all 3 plans (monthly fee + setup
   fee) and the listing fee, pre-filled with the current defaults
   (25000/45000/75000, 15000/25000/40000, 7500).
4. Change the Starter monthly fee to, say, `27500` and save. Confirm
   the success notice appears.
5. Visit `/signup` — the Starter option should now show
   **NGN 27,500.00/month**, live, no cache clear needed.
6. Go to `wp-admin → OFast Pipeline → Clients → Add New` — the plan
   dropdown should also show **NGN 27,500.00/month** for Starter.
7. Confirm a co-admin (not super_admin) can still view Settings but
   the Save Pricing button either isn't shown or `wp_die()`s if they
   somehow POST it directly — this should already be covered since
   `render_settings()` gates the whole page to super_admin, but worth
   a quick check given pricing is more sensitive than most settings.
8. Optional deeper test: manually trigger a Monnify/Paystack/Flutterwave
   sandbox webhook for a client on the Starter plan and confirm the
   amount it expects now reflects your changed 27500 figure, not the
   old hardcoded 25000.

---

## What's still open after this phase

Per your own priority list, next up would be either:
- **Self-serve credit top-up flow** (Option 2 — Paystack/Flutterwave
  initiation from `/credits`, webhook routes to `OFP_Credit::topup()`)
- **Password reset handler** (`OFP_Auth::generate_reset_token()`
  already exists as infrastructure; the request/verify/update flow
  around it does not)

Also still flagged, not part of this phase: the underpayment-handling
decision (continuation blueprint Section 4, item 1) — `get_expected_monthly_total()`
now correctly computes *what's owed*, but what happens when a client
pays *less* than that is a separate, still-unresolved decision.
