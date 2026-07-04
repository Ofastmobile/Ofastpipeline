# Phase 13 — Password Reset Handler

## Manifest

| Patch/File | Type | What it adds |
|---|---|---|
| Patch 9 — `includes/class-ofp-auth.php` | INSERT | `request_password_reset()`, `verify_reset_token()`, `complete_password_reset()`, `hash_reset_token()` |
| Patch 10 — schema migration | ALTER TABLE | `reset_token_hash` (varchar 64), `reset_token_expires` (datetime) on `wp_ofp_clients` |
| `public/templates/forgot-password.php` | NEW FILE (full) | Request-a-reset-link page |
| `public/templates/reset-password.php` | NEW FILE (full) | Token verification + new password form |
| Patch 11 — routing + login.php | INSERT | Registers the 2 new public routes, adds "Forgot password?" link |

**This phase DOES need a schema change** (2 new columns) — the only
phase so far that does. Everything else is additive code.

---

## The flow, end to end

1. Client clicks "Forgot your password?" on `/login` → lands on
   `/forgot-password`.
2. Enters email, submits. `OFP_Auth::request_password_reset()` looks
   up the client, and — win or lose — the page always shows the same
   "if that email exists, we sent a link" message. If a client *does*
   exist for that email, a 32-character token is generated, its
   **hash** (not the raw token) is stored with a 30-minute expiry, and
   an email goes out via your ZeptoMail transactional route with a
   link like `/reset-password?client=42&token=abc123...`.
3. Client clicks the link → `reset-password.php` calls
   `verify_reset_token()` to check the token matches the stored hash
   and hasn't expired, *before* showing any form. If invalid/expired,
   they see "request a new link" instead.
4. Client enters a new password (min 8 characters, confirmed twice),
   submits. `complete_password_reset()` re-verifies the token
   independently (never trusts step 3's check alone), updates the
   password hash, and clears the reset token fields — so the same
   link can't be reused even if the client accidentally clicks it a
   second time from their email.

---

## Two assumptions I made, worth double-checking against your real code

1. **Password column name.** I assumed `password_hash` on
   `wp_ofp_clients`, hashed via PHP's `password_hash()`. If your login
   flow uses a different column name or hashing approach, the fix is a
   single line inside `complete_password_reset()`.
2. **Mailer method name.** I assumed `OFP_Mailer::send_transactional()`
   routes through ZeptoMail per your notes (Brevo for marketing,
   ZeptoMail for transactional). If the actual method has a different
   name, swap it in the one call site inside `request_password_reset()`.

Both are called out inline in Patch 9's comments too, not just here.

---

## Your test steps

1. **Apply Patch 10 first** (schema) — confirm the 2 columns now
   exist on `wp_ofp_clients` via Adminer before testing anything else.
2. Apply Patch 9, the 2 new template files, and Patch 11.
3. Go to `/login`, click "Forgot your password?", confirm you land on
   `/forgot-password`.
4. Submit a real test client's email — confirm you see the generic
   "if that email exists..." message (not an error, even on success).
5. Submit a made-up email that doesn't belong to any client — confirm
   you see the exact same generic message. This is the important one:
   if the message differs between the two, something's leaking which
   emails exist.
6. Check the test client's inbox — confirm the reset email arrived
   with a working link.
7. Click the link — confirm you land on `/reset-password` with the
   new-password form showing (not an error).
8. Try submitting a password under 8 characters — confirm you get the
   length error, nothing is changed.
9. Try mismatched password/confirm — confirm you get that error.
10. Submit matching, valid passwords — confirm success message, then
    log in with the new password at `/login` to confirm it actually
    took.
11. **Reuse test:** click the same reset link again (e.g. from your
    email client's history) — confirm it now shows "invalid or
    expired," since the token was cleared after step 10.
12. **Expiry test:** if you want to test the 30-minute expiry without
    waiting, temporarily manually set that client's
    `reset_token_expires` to a past timestamp via Adminer, then try
    the link again — confirm it's rejected.
13. **Rate limit test:** submit the forgot-password form 6 times
    rapidly — confirm the 6th is rate-limited.

---

## What's still open

Per your priority list, that clears all three items from the original
"what's next" menu (plan pricing, self-serve top-up, password reset).
Remaining, lower-priority items still on the board: the property
listing public pages (blocked on the PHP-vs-Elementor-vs-hybrid
decision), the landing page integration guide, and the underpayment-
handling decision for subscription webhooks flagged back in Phase 12.
