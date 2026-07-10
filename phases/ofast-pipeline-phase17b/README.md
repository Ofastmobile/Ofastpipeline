# Phase 17b — Fixes for Phase 17

Three real gaps fixed here.

---

## What was wrong and what's fixed

**Gap 1 — Funding form only covered SMS and Voice**
Fixed. `account.php` is a full replacement. The "This Payment Is For"
dropdown now shows four options: SMS Credit, Voice Credit, CRM Plan,
and Listing Plan. The plan options show the client's current plan name
and monthly price pulled live from Settings, so they know exactly what
amount to transfer. If a client has no active plan yet, it still shows
a generic "CRM Plan Payment" and "Listing Plan Payment" option so they
can submit a request even before their plan is confirmed.

**Gap 2 — No way for admin to send a notification to clients**
Fixed. New wp-admin sub-menu page: OFast Pipeline → Send Notification.
Pick one client or "All Clients," type a title and message, send. Each
client receives it via bell, email, or both based on their own saved
preference — you don't need to worry about that, the system handles it.

**Gap 3 — Notification Settings had no visible link**
Fixed. Patch J2 gives you the exact link to add to your nav alongside
the other dashboard links.

**Bonus fix — Funding Requests Approve button broke for plan payments**
The original Patch I4 approve handler called `OFP_Credit::topup()`
for everything — which only works for SMS/Voice. Now it routes to the
right handler per payment type. CRM plan and Listing plan approvals
call the new `activate_from_manual_payment()` (Patch K), which flips
the subscription to 'paid' and sets a 30-day period rather than
incorrectly trying to top up a credit balance.

---

## Manifest

| File | Type | What it does |
|---|---|---|
| `public/templates/account.php` | Full replacement | Expanded funding form covering all 4 payment types |
| Patch J | Insert | Admin Send Notification page + nav link fix + approve handler fix |
| Patch K | Insert | `activate_from_manual_payment()` on `OFP_Subscription` |

**Apply order:**
1. Patch K first (new method on subscription class)
2. Patch J (references the new method in the approve handler fix)
3. Replace `public/templates/account.php`

---

## Test steps

1. Log in as a test client, go to `/account` — confirm the funding
   form now shows all four options in the dropdown, with correct plan
   names and prices showing for CRM and Listing options.
2. Submit a funding request for "CRM Plan" — confirm admin gets email
   and it shows in wp-admin → Funding Requests.
3. Approve that request in wp-admin — confirm the client's
   subscription row is now 'paid' with a period_end 30 days out, and
   their status is 'active'.
4. Go to wp-admin → Send Notification, send a message to one client —
   confirm it appears in that client's bell and/or email.
5. Send to "All Clients" — confirm every client gets it.
6. Check that the notification settings link is now visible in the
   client nav, and that the page works correctly.
