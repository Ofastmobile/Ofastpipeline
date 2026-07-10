# Phase 17 — Virtual Account Page, Manual Funding, Notifications

## Manifest

| File | Type | What it does |
|---|---|---|
| `includes/class-ofp-notification.php` | New | Creates notifications, checks bell count, marks as read, saves client preference |
| `public/templates/account.php` | New | Client's Account page — virtual account details, company bank details, manual funding form |
| `public/templates/notifications.php` | New | Client's full notifications list with pagination and mark-as-read |
| `public/templates/notification-settings.php` | New | Client chooses Bell + Email, Bell only, or Email only |
| Patch H | Schema | New `ofp_notifications` table, new `ofp_funding_requests` table, new `ofp_notification_pref` column on `ofp_clients` |
| Patch I | Wiring | Routes, bell icon HTML, company bank settings in wp-admin, admin Funding Requests page with Approve/Reject |

**Bootstrap addition:**
```php
require_once OFP_PLUGIN_DIR . 'includes/class-ofp-notification.php';
```
No `init()` call needed — this class has no hooks, just static methods.

---

## How the manual funding flow works, simply

1. Client goes to `/account`, sees their virtual account AND your company bank details.
2. They transfer money to your company account the normal way (bank app, USSD, etc.).
3. They come back, fill the "I Have Already Transferred" form with the amount, their bank name, and their transaction reference.
4. You get an email immediately.
5. Client gets a bell notification + email (based on their preference) saying "we received your request."
6. You go to wp-admin → Funding Requests, see the request, verify it manually (check your bank statement), then click Approve or Reject.
7. Client gets another notification saying approved or rejected, and if approved, their balance is credited instantly.

---

## How notifications connect to the rest of the plugin

`OFP_Notification::create()` is now the one method to call anywhere in
the plugin when something happens that a client should know about. Right
now it's called in two places: manual funding received, and manual
funding approved/rejected. But it's designed to be dropped into any
future event too — property approved, subscription expiring, etc. Just
call it with a `$type`, `$title`, and `$message`, and it handles bell
+ email automatically based on what the client chose.

---

## Your test steps

1. Apply Patch H (schema) first — confirm the two new tables exist
   and the new column is on `ofp_clients`.
2. Add bootstrap line, apply Patch I wiring.
3. Go to wp-admin → Settings, fill in company bank account details,
   save — confirm they appear on the client's `/account` page.
4. Log in as a test client, go to `/account` — confirm you see:
   - Their virtual account number (if one was already assigned)
   - Your company bank account details
   - The manual funding form
5. Submit a test funding request — confirm:
   - You get an admin email
   - The client sees a bell notification
   - The request shows up in wp-admin → Funding Requests
6. Click Approve in wp-admin — confirm:
   - Client's SMS or Voice balance increases correctly
   - Client gets a second notification saying "approved"
   - The request row shows "Approved" status
7. Test Reject the same way with a second request.
8. Go to `/notification-settings` as the client — switch to "Bell only,"
   save. Submit another funding request — confirm NO email goes out
   this time, only bell.
9. Switch to "Email only" — confirm bell count stays at zero but email
   still arrives.
10. Go to `/notifications` — confirm full list shows with correct
    read/unread styling, pagination works if you have more than 20.
11. Click "Mark all as read" — confirm badge on bell drops to zero.

---

## What's left

Nothing from your original list is outstanding anymore. Everything
you described is now built across Phases 11–17. From here, next steps
would be CSS styling for the client-facing pages, testing on live
server, and then actual client onboarding.
