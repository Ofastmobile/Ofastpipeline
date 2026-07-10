# Phase 17/17b — Complete Implementation Walkthrough

## Bug Fixes from `error.md`

### 1. `OFP_Client::get_all()` → `OFP_Client::all()`
**File:** `admin/class-ofp-admin-menu.php` (Send Notification page)
- The method `get_all()` doesn't exist on `OFP_Client`. The correct method is `all()`.

### 2. `OFP_Mailer::send_transactional()` → `OFP_Mailer::send()`
**File:** `public/templates/funding.php` (manual funding form handler)
- The method `send_transactional()` doesn't exist on `OFP_Mailer`. Replaced with `send()` using the correct signature `($to, $to_name, $subject, $body_html)` and converted the admin notification body to HTML table format.

---

## Virtual Account Generation on Live

**Problem:** Virtual accounts are only created during onboarding (`OFP_Client::create()` step 6). If the payment gateway wasn't configured at the time, the client never gets one.

**Solution:** Added a **"Generate My Account"** button on the `/funding` page. When:
- The client has no `virtual_account_number` in the DB, AND
- `OFP_Payment` class exists (meaning a gateway is configured)

...they see a card with a button to generate their account on-demand. The handler calls `OFP_Payment::create_virtual_account()` and stores the result in `ofp_clients`.

**Flow on live:**
1. Admin configures Monnify/Paystack/Flutterwave keys in Settings
2. Client navigates to `/funding`
3. Client clicks "Generate My Account"
4. Gateway creates a dedicated virtual account → shown immediately
5. All future transfers to that account are auto-matched

---

## Subscription Plan Change Controls

### Listing Plans (Properties page)
**Problem:** Clients could submit the plan picker form multiple times, creating duplicate `ofp_subscriptions` rows even while an active paid plan existed.

**Solution:** Three-state UI in `properties.php`:

| State | What client sees |
|-------|-----------------|
| **Active & Paid** | Plan name + green "Active" badge, expiry date, property usage count. No form — "You can choose a different plan after your current plan expires." |
| **Selected but Pending Payment** | Plan name + amber "Pending Payment" badge, amount due + link to Funding page. Form shown so they can change selection before paying. |
| **No plan yet** | Full plan picker form to choose Bronze/Silver/Gold |

The server-side handler also blocks submission with: `OFP_Subscription::has_active('listing', $client_id)` check.

---

## Files Changed

| File | Change |
|------|--------|
| `admin/class-ofp-admin-menu.php` | Fixed `get_all()` → `all()`, added Send Notification page, fixed approve handler routing for 4 payment types, added enqueue hooks |
| `public/templates/funding.php` | Fixed `send_transactional()` → `send()`, added virtual account generation handler + UI card |
| `public/templates/properties.php` | Added 3-state plan picker UI, server-side guard against duplicate active plan submissions |
| `includes/class-ofp-subscription.php` | Added `activate_from_manual_payment()` method (Patch K) |
| `public/templates/partials/nav.php` | Added "Funding" sidebar nav item, reverted dropdown to "Profile Settings" |
| `public/templates/account.php` | Removed merged funding card (lives in `/funding` now) |
| `public/class-ofp-client-portal.php` | Registered `funding` route |
| `includes/class-ofp-host-router.php` | Added `funding` to APP_ROUTES |
| `admin/views/settings.php` | Fixed SMTP toggle: removed double `checked()`, added DOMContentLoaded JS sync |

All files pass `php -l` syntax check.
