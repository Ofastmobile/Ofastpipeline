# Communications rebuild

Stacked on `fix/unify-client-plan`. Does **not** SQL-rename `starter/growth/pro`.
Uses `OFP_Subscription::client_plan()` and `allows_email_templates()`.

## Two email pipelines

**Pipeline A — system emails TO the client**
Welcome, billing, OTP, low credit. Uses admin universal template (`{{content}}`) if set, else the built-in shell. Client templates never wrap these.

**Pipeline B — emails TO buyers / leads / investors**
Purchase notices, installment reminders, offer links, broadcasts, lead-queue emails. Resolution order:

1. Client template (`ofp_client_templates`, Gold can pick; Silver auto-applies the one)
2. Admin universal template
3. Built-in shell

## Plan limits

| Plan | Templates | Send Message |
|------|-----------|--------------|
| Free | Hidden | Hidden (log only) |
| Silver | 1 wrapper, auto-applied | Yes |
| Gold | Unlimited, default for automated | Yes, template dropdown |

## New / changed

- `ofp_clients.sms_sender_id` — admin sets this on the client edit screen (must already be registered on SmartSMS / BulkSMS / AT)
- `ofp_client_templates.is_default` — Gold default wrapper for automated Pipeline B
- Placeholder `{{content}}` (legacy `{email_body}` still accepted)
- SMS cost **₦6.99** per send, deducted from SMS credit
- Schema upgrade runs on `plugins_loaded` so Local does not need a re-activate

## How to test on Local

```bash
git fetch origin
git checkout feature/comms-rebuild
```

Reload WordPress once (adds columns). Then:

1. Admin → Communications → Universal template: save with `{{content}}`, Send test email
2. Admin → Communications → Send: email + SMS live preview
3. Free client: Messaging shows log only; Templates nav locked
4. Silver: one wrapper, Send Message, test email
5. Gold: multiple templates, Set as default, pick when sending
6. Buyer purchase / installment reminder email should use the client wrapper, not the system shell
