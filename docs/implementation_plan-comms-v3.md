# Fix & Rebuild Communication Module — v3 (Final)

## All Clarifications Applied

| # | Clarification | Decision |
|---|---|---|
| 1 | Client templates affect which emails? | ALL outgoing emails to their buyers/leads/investors — both automated (payment confirmation, installment reminder) AND manual broadcasts. System emails TO the client (welcome, subscription) are NOT affected. |
| 2 | Send test email | Add "Send Test Email" button on template tab for both admin & client. Add dummy sample text to live preview. |
| 3 | Gold = multiple templates | Gold can save many templates and SELECT which one when sending. Silver = 1. Free = 0. `ofp_client_templates` table KEPT. |
| 4 | System emails to clients | Untouched. Only admin universal template can override system emails. Client templates ONLY wrap emails going OUT to buyers/leads/investors. |
| 5 | Placeholders in manual email | Yes — `{{buyer_name}}`, `{{buyer_email}}`, `{{property_title}}`, etc. Document them in the Send tab UI. |
| 6 | Preview clicking | Disable all link clicks in iframe previews using `sandbox` attribute. |
| 7 | SMS sender ID | Yes, both SmartSMS & BulkSMS Nigeria support custom sender IDs. Requires admin to pre-register on the provider. Add per-client `sms_sender_id` column. |
| 8 | Downgrade | Option B: Read-only mode. Existing purchases continue, client can't create new ones. |

---

## Template Architecture (Final)

### Two separate email pipelines:

```
─── PIPELINE A: System Emails TO the Client ───────────────────

  (Welcome, payment confirmed, low credit, subscription reminder)
  
  Template resolution:
  1. Admin Universal Template (ofp_universal_email_template option)
     └── Uses {{content}} placeholder
  2. If empty → Built-in default (OFP_Mailer::wrap_in_template())
  
  ⚠️ Client templates have ZERO effect on these emails.

─── PIPELINE B: Outgoing Emails TO Buyers/Leads/Investors ─────

  (Automated notifications + Manual broadcasts from Send tab)
  
  Template resolution:
  1. Client's selected template (ofp_client_templates table)
     └── Gold: picks from saved templates
     └── Silver: 1 auto-applied template
  2. If client has no template → Admin Universal Template
  3. If admin has no template → Built-in default
  
  All templates use {{content}} placeholder.
```

### Template limits by plan:

| Plan | Templates | Behavior |
|------|-----------|----------|
| Free | 0 | Uses platform default for all emails |
| Silver | 1 | Auto-applied to all outgoing emails. No selection dropdown needed. |
| Gold | Unlimited | Can save many. Picks one when sending. Has a "default" template for automated emails. |

---

## Proposed Changes

### 1. Admin Backend — Communications Page

#### [MODIFY] [communications.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/admin/views/communications.php)

**Template Tab (admin universal):**
- Replace `{email_body}` → `{{content}}`
- Add **dummy sample text** in the live preview (like ofast-x): a realistic email body with heading, paragraph, CTA button is rendered inside `{{content}}`
- Add **"Send Test Email"** button — sends a test email to the admin's email using the current template with sample content
- Add `sandbox` attribute to preview iframe (disables link clicks)
- Note: "If left empty, the built-in platform template will be used"

**Send Broadcast Tab:**
- Add split-pane: compose form on LEFT, live preview (iframe) on RIGHT
- When channel = Email: preview renders message inside the universal template wrapper
- When channel = SMS: preview shows phone bubble mockup
- Preview iframe uses `sandbox` attribute (no clicking)

---

### 2. Client Frontend — Message Templates Page

#### [MODIFY] [message-templates.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/public/templates/message-templates.php)

**Complete restructure into 3 tabs:**

| Tab | Who sees it | Content |
|-----|------------|---------|
| **Communications Log** | All plans | Existing log — keep as-is |
| **Send Message** | Silver & Gold | Split-pane compose + live preview |
| **Email Template** | Silver & Gold | Template editor(s) + live preview |

---

**Tab: Send Message (Silver & Gold only)**

Left side (compose form):
- **Channel toggle**: Email / SMS
- **Recipient source** (radio buttons):
  - "From Leads" → searchable checkbox list from `ofp_leads`
  - "From Buyers" → searchable checkbox list from `ofp_property_purchases` (buyer_name, buyer_phone, buyer_email)
  - "Manual Entry" → comma-separated emails or phone numbers
- **Template selector** (Email channel, Gold only):
  - Dropdown of saved templates. Selecting one wraps the preview in that template.
  - Silver: hidden (their 1 template is auto-applied)
- **Subject** (Email only)
- **Message body** (textarea/rich text)
- **Placeholder reference** displayed above body:
  ```
  Available placeholders: {{name}}, {{email}}, {{phone}}, {{property_title}}
  ```

Right side (live preview):
- Email: renders body inside selected template (or default) with `sandbox` iframe
- SMS: phone bubble mockup with `sandbox` iframe
- Placeholders replaced with sample data in preview (e.g. `{{name}}` → "John Doe")

---

**Tab: Email Template (Silver & Gold only)**

**Silver** (1 template max):
- Single HTML editor with `{{content}}` placeholder
- Live preview on the right with **dummy sample content** rendered inside `{{content}}`:
  ```html
  <h2>Your Payment Was Successful</h2>
  <p>Hi John, your payment of ₦500,000 for Luxury Villa has been confirmed.</p>
  <p>Your next installment of ₦250,000 is due on March 15, 2026.</p>
  <a href="#">View Transaction</a>
  ```
- "Send Test Email" button — sends test to client's own email
- Preview iframe uses `sandbox` (no clicking)
- "If left empty, the platform default will be used"

**Gold** (unlimited templates):
- Same editor as Silver, BUT with template management:
  - Template name field
  - "Save Template" / "Save as New" buttons
  - Template list on the left showing all saved templates (click to edit)
  - "Set as Default" button (marks one template as the auto-applied one for automated emails)
  - Delete button
- Live preview + "Send Test Email" + `sandbox` iframe

---

### 3. Backend PHP Changes

#### [MODIFY] [class-ofp-mailer.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/includes/class-ofp-mailer.php)

Add new method — **Pipeline B** (emails to buyers/leads/investors):
```php
public static function send_client_email(
    string $to,
    string $subject, 
    string $body_html,
    int $client_id,
    ?int $template_id = null  // Gold clients can specify which template
): bool
```

Logic:
1. If `$template_id` provided → load that template from `ofp_client_templates`
2. Else → load client's default template (where `is_default = 1`)
3. If client has no template → load admin universal template (`ofp_universal_email_template` option)
4. If admin has no template → use built-in `wrap_in_template()`
5. Replace `{{content}}` with `$body_html`
6. Send via `wp_mail()`

Existing methods (`send_welcome_email`, `send_subscription_reminder`, etc.) remain unchanged — they use `wrap_in_template()` directly (Pipeline A). Client templates never touch these.

#### [MODIFY] [class-ofp-client-portal.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/public/class-ofp-client-portal.php)

- **Rewrite `ajax_save_template()`** — save email wrapper template (HTML with `{{content}}`). Enforce plan limits (Silver=1, Gold=unlimited). `type` column forced to `'email'`.
- **Keep `ajax_delete_template()`** — Gold only (can't delete if Silver, they only have 1).
- **Keep `ajax_fetch_templates()`** — used by Send tab's template dropdown.
- **Add `ajax_save_client_email_template_universal()`** — saves single wrapper for Silver clients (alternative simpler path).
- **Add `ajax_send_client_broadcast()`** — handles multi-recipient send:
  - Accepts: channel, recipients (array), subject, body, template_id (optional)
  - Email: free, wraps in template via `send_client_email()`
  - SMS: deducts ₦6.99/SMS from credit balance, uses client's `sms_sender_id` if set
  - Replaces placeholders (`{{name}}`, `{{email}}`, `{{phone}}`, `{{property_title}}`) with actual values per recipient
  - Logs each message to `ofp_communications_log`
- **Add `ajax_send_test_email()`** — sends a test email to the client's own email using their selected template + dummy content
- **Plan-gate**: Silver/Gold check on Send Message and Email Template tabs

#### [MODIFY] [class-ofp-admin-menu.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/admin/class-ofp-admin-menu.php)

- Fix broadcast handler: `{email_body}` → `{{content}}`
- Fix template save handler: `{email_body}` → `{{content}}`
- Add `handle_send_test_email()` — sends test to admin's email with sample content
- Add admin broadcast live preview (same split-pane pattern)

#### [MODIFY] [class-ofp-sms.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/includes/class-ofp-sms.php)

- Accept optional `$sender_id` parameter in `send()` method
- When client has `sms_sender_id` set, pass it to the provider API instead of the global default
- SmartSMS: use `sender` parameter
- BulkSMS Nigeria: use `from` parameter
- Africa's Talking: use `from` parameter

#### [MODIFY] [class-ofp-subscription.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/includes/class-ofp-subscription.php)

- Rename plan keys: `starter`→`free`, `growth`→`silver`, `pro`→`gold`
- Update all constants: `CRM_PRICES`, `DEFAULT_PLAN_PRICES`, `DEFAULT_SETUP_FEES`, `PLAN_KEYS`
- Prices remain fetched from options (not hardcoded): `get_option("ofp_plan_price_silver", ...)`
- Add helper: `OFP_Subscription::client_plan( int $client_id ): string`

#### [MODIFY] [class-ofp-activator.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/includes/class-ofp-activator.php)

- Migration in `maybe_upgrade_schema()`:
  ```sql
  UPDATE ofp_clients SET plan = 'free'   WHERE plan = 'starter';
  UPDATE ofp_clients SET plan = 'silver' WHERE plan = 'growth';
  UPDATE ofp_clients SET plan = 'gold'   WHERE plan = 'pro';
  UPDATE ofp_subscriptions SET plan = 'free'   WHERE plan = 'starter';
  UPDATE ofp_subscriptions SET plan = 'silver' WHERE plan = 'growth';
  UPDATE ofp_subscriptions SET plan = 'gold'   WHERE plan = 'pro';
  ```
- Change `ofp_clients.plan` default: `'starter'` → `'free'`
- Add `is_default` and `sms_sender_id` columns:
  ```sql
  ALTER TABLE ofp_client_templates ADD COLUMN is_default TINYINT(1) DEFAULT 0;
  ALTER TABLE ofp_clients ADD COLUMN sms_sender_id VARCHAR(20) DEFAULT NULL;
  ```
- Force existing `ofp_client_templates.type` to `'email'` (drop any SMS rows if they exist)

---

### 4. Navigation

#### [MODIFY] [nav.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/public/templates/partials/nav.php)

- "Templates" nav → only visible for Silver & Gold
- For Free: either hidden or shown as locked with upgrade prompt

---

### 5. SMS Sender ID (Per-Client)

Both SmartSMSSolutions and BulkSMS Nigeria support custom sender IDs via their API. Sender IDs must be **pre-registered on the provider account by the admin** (max 11 characters, alphanumeric).

**How it works:**
1. Admin registers client's desired sender ID on SmartSMS/BulkSMS dashboard
2. Admin sets it in the client's profile: `ofp_clients.sms_sender_id` = "ClientBrand"
3. When sending SMS for that client, `OFP_SMS` passes the client's sender ID to the API
4. If client has no sender ID set → falls back to the platform default ("OFastPipe")

> [!NOTE]
> Clients do NOT register sender IDs themselves. The admin handles this on the provider platform. Client can REQUEST a sender ID (future feature), but activation is admin-side.

---

### 6. Placeholders

Available in the **Send Message** tab body field:

| Placeholder | Replaced With | Source |
|---|---|---|
| `{{name}}` | Recipient name | Lead name or Buyer name |
| `{{email}}` | Recipient email | Lead email or Buyer email |
| `{{phone}}` | Recipient phone | Lead phone or Buyer phone |
| `{{property_title}}` | Property title | From `ofp_properties` (if linked) |
| `{{business_name}}` | Client's business | From `ofp_clients.business_name` |

Shown as a helper reference above the message body textarea in the client Send tab. In the live preview, replaced with sample values (e.g., `{{name}}` → "John Doe").

---

### 7. Database Changes Summary

| Action | Target | Details |
|---|---|---|
| **Rename plans** | `ofp_clients.plan` | starter→free, growth→silver, pro→gold |
| **Rename plans** | `ofp_subscriptions.plan` | Same rename |
| **Change default** | `ofp_clients.plan` | Default 'starter' → 'free' |
| **Add column** | `ofp_client_templates.is_default` | TINYINT(1) DEFAULT 0 — marks Gold client's default template for automated emails |
| **Add column** | `ofp_clients.sms_sender_id` | VARCHAR(20) DEFAULT NULL — custom SMS sender ID |
| **Clean up** | `ofp_client_templates` | Force type='email', remove any SMS template rows |
| **No new tables** | — | Everything fits existing schema |

---

### 8. Downgrade Logic (Option B — Read-Only Mode)

When a client's subscription expires and they're downgraded to Free:

| Feature | Behavior |
|---|---|
| Existing property purchases | Continue as normal. Buyers keep paying via secure links. |
| Automated installment reminders | Keep firing (system-level, not gated by plan). |
| View purchases/payments | ✅ Client can view all existing data |
| Create NEW offers | ❌ Blocked |
| Add NEW purchases | ❌ Blocked |
| Edit existing offers | ❌ Blocked |
| Properties on marketplace | Remain visible but marked "Not accepting new offers" |
| Send broadcasts | ❌ Blocked (Silver/Gold only) |
| Templates | ❌ Hidden (Silver/Gold only) |
| View communications log | ✅ Still visible |

---

## Files Changed Summary

| File | Type of Change |
|---|---|
| [communications.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/admin/views/communications.php) | Template tab + Send tab UI |
| [message-templates.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/public/templates/message-templates.php) | Full rebuild of all 3 tabs |
| [class-ofp-mailer.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/includes/class-ofp-mailer.php) | Add `send_client_email()` |
| [class-ofp-client-portal.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/public/class-ofp-client-portal.php) | AJAX handlers for templates, broadcast, test email |
| [class-ofp-admin-menu.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/admin/class-ofp-admin-menu.php) | Fix placeholder, add test email, add preview |
| [class-ofp-sms.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/includes/class-ofp-sms.php) | Per-client sender ID support |
| [class-ofp-subscription.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/includes/class-ofp-subscription.php) | Plan rename free/silver/gold |
| [class-ofp-activator.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/includes/class-ofp-activator.php) | Migration + new columns |
| [nav.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/public/templates/partials/nav.php) | Plan-gate Templates nav |

---

## Verification Plan

1. **Admin Template tab**: `{{content}}` works, dummy text in preview, Send Test Email works, clicks disabled in preview
2. **Admin Send tab**: Live preview on right, both email/SMS channels
3. **Client (Free)**: Templates + Send Message tabs hidden. Only Comms Log visible.
4. **Client (Silver)**: 1 template only. Auto-applied. Can send broadcasts. Test email works.
5. **Client (Gold)**: Multiple templates. Select when sending. Set default. CRUD works. Test email works.
6. **SMS sending**: Credits deducted (₦6.99/SMS). Client sender ID passed to provider.
7. **Email sending**: Free, no credit deduction. Wrapped in client's template.
8. **Placeholders**: `{{name}}` etc. replaced correctly per recipient.
9. **Plan migration**: starter→free, growth→silver, pro→gold runs clean.
10. **Downgrade**: Free client can view but not create new offers/purchases.
