# Ofastpipeline — Rent Management & Single Company Blueprint (v2, corrected)

This replaces the earlier draft of this blueprint. Everything about
single company deals has been re-verified directly against the actual
v6 code (`OFP_Host_Router`, `OFP_Client_Portal`, `OFP_Property_Marketplace`,
`signup.php`, `account.php`, `api-settings.php`), not assumed from docs
alone. Where this version disagrees with the first draft, this version
is correct, the first draft's "OFP_Landing_Page" idea has been dropped
entirely per the owner's decision below.

---

## 0. Golden Rule (unchanged, proven necessary already)

Before adding any new taxonomy, meta key, post type, column, or slug,
search the codebase for something already doing that job first. This
rule already caught two real near-mistakes in this same planning
session: almost building a second "API subdomain" field when only one
`subdomain` column exists and is meant to be reused, and almost building
a landing-page system on top of a zone (`'client'`) that was actually
meant for something else once properly discussed.

Reference table, confirmed in code:

| Thing | Current name | Type |
|---|---|---|
| Property listing | `ofp_property` | Custom Post Type |
| Property category | `ofp_property_type` | Taxonomy |
| Property location | `ofp_property_location` | Taxonomy |
| Sale / Rent label | `ofp_list_type` | Post meta |
| Which client owns a property | `ofp_client_id` | Post meta (already exists — this is the hook rent and solo-company filtering both use) |
| Client's own subdomain | `subdomain` column | DB column on client record (one field, one purpose — see Section 2) |
| Base domain for app./property. routing | `ofp_crm_base_domain` option | WP option |

Still to verify in the actual property CPT/frontend code before building
rent: whether "Shortlet" is a third `ofp_list_type` value or something
else, and whether `ofp_list_type` can already hold more than one value
per property (needed for "landlord offers multiple rent options").

---

## 1. Rent Management Module (unchanged from prior planning, still valid)

### 1.1 Reused Foundation

- Payment recording, status flow, webhook idempotency — reuse
  `OFP_Property_Payment_Record` and related classes.
- Per-buyer Paystack VA, now fully wired — same pattern applies per-lease.
- The shared receipt PDF function (from the installment blueprint) —
  tenants call the same function, not a second one.
- Existing email + SMS trigger pattern for confirmations and reminders.
- The offer → accept → pay pattern already used for property purchases —
  a lease offer follows the identical shape.

### 1.2 New Data Structures

- **Tenant record** — created once per person, unique access token, no
  WordPress login, same "no-login, unique link" pattern as buyers.
- **Lease record** — one per rent cycle, linked to a tenant AND a
  property, holds rent amount, rent type/duration for this cycle, start
  date, end date, status (`pending_offer` / `active` / `expired` /
  `renewed`).
- **Property rent options** — a property can offer more than one rent
  type (e.g. monthly or yearly), tenant/lease picks one at offer time.
  Check first whether `ofp_list_type` can already hold multiple values
  before adding a new table for this.
- **Lease payments** — reuses the payment record structure, linked to
  `lease_id` instead of `purchase_id`, flexible "pay at will" amounts,
  no fixed schedule.

### 1.3 Property Occupied / Hidden Behaviour (confirmed)

When a lease becomes `active`: hide the property from the public
marketplace, show "Occupied" inside the client's dashboard (don't just
vanish it silently), and automatically make it visible again if the
lease ends and isn't renewed. Reuse whatever hide/show mechanism already
exists for a property "under offer" or "sold" during a purchase, if one
exists, check before building a second one.

### 1.4 Tenant Menu (separate from Leads)

New **Tenants** menu, separate from Leads. Inside a tenant's profile:
full lease history (all cycles), payments, receipts, contact info.
**New Lease / Renew Lease** always creates a new lease record under the
same tenant, never a new tenant record.

### 1.5 Rent Type & Flexible Payment

Rent type chosen per lease from whatever options the landlord defined
on the property. Payment is "at will" against the lease's current-cycle
target, no fixed installment schedule, each payment logged as it comes
in with its own receipt. When the cycle target is met, mark it complete
and create a new lease record for the next cycle (renewal).

### 1.6 Expiry Reminders (confirmed cadence)

- 60–30 days before expiry: 1 reminder/week.
- Last 30 days: 2 reminders/week.
- Last 7 days: daily.
- After expiry (unpaid/not renewed): every 2–3 days, not daily forever.

All by email and SMS, using the existing cron/reminder pattern already
built for installment reminders.

### 1.7 Access & Gating

- Rent management is **Gold plan only**, gated the same way Team,
  Templates, and Sales & Installments already are (server-side and
  sidebar).
- Tenant access via unique token/URL, no login, matching buyers.
- Frontend (client dashboard + tenant tracking page) and backend
  (wp-admin oversight) both need work. wp-admin stays yours only.

### 1.8 Build Order For Rent

1. Confirm the `ofp_list_type` multi-value question and the
   occupied/hide mechanism, before writing new tables.
2. Tenant + Lease tables and CRUD.
3. Property rent options + occupied/hide logic.
4. Reuse payment recording for lease payments.
5. Reuse the shared receipt function.
6. Expiry reminder cron.
7. Frontend (Tenants menu, lease creation/renewal, tenant tracking page)
   + backend oversight views.
8. Gate to Gold, test as Free/Silver to confirm it's actually hidden.

---

## 2. Domain & Routing Architecture (fully corrected this round)

### 2.1 What's confirmed already working, verified directly in code

- `/login` and `/marketplace/` are real, independently registered
  WordPress routes on the main domain (`OFP_Client_Portal`,
  `OFP_Property_Marketplace`), **always active**, regardless of any
  domain setting. These are the permanent fallback, never remove them.
- `app.{base_domain}` and `property.{base_domain}` are an optional
  rewrite layer on top of the above. They only activate once
  `ofp_crm_base_domain` is set in Settings AND the wildcard DNS record
  is actually live. Until then, `rewrite_portal_links()` and
  `current_zone()` both fail safe back to normal behaviour, confirmed
  in `OFP_Host_Router`.
- The plugin already tags every property with `ofp_client_id`, so
  ownership filtering (needed for both rent and solo-company scoping)
  has a ready-made hook, no new column needed.

### 2.2 Dropped: the custom landing page idea

The original plan to build `OFP_Landing_Page` (a client's subdomain
showing a custom marketing/landing page) is **scrapped**, per owner's
decision. `client.mydomain.com` will not host a landing page. Do not
build this class.

### 2.3 New decision: a client's own subdomain is their dashboard address

Currently, dashboard/login only happens through the one shared
`app.{base_domain}`, resolved purely by login session, never by which
subdomain the visitor is on, confirmed by searching the entire codebase
for any subdomain-aware auth logic (there is none yet).

**Corrected target behaviour:** visiting `clientname.mydomain.com`
should render the same shared login/dashboard templates directly,
branded to look like their own address, without forcing a redirect to
`app.{base_domain}`. This is a routing change only, not new business
logic, since `current_user()`/`current_client()` already scope data
correctly by session.

**What needs building:**

1. **Add a `subdomain` field to `signup.php`.** Auto-suggest it from
   the business name using `sanitize_title()` (same function already
   used elsewhere), editable, with a live uniqueness check against
   existing client records.
2. **Add the same field, read-only, to `account.php`,** so clients can
   see their own address after signup. Lock it after signup, changing
   it later breaks anything already shared with clients/leads, offer
   "contact support to change" instead of self-service editing.
3. **Do not create a second/duplicate subdomain field.** The one
   already used by the admin "Add Client" form (`handle_add_client()`
   in `class-ofp-admin-menu.php`) and shown on `api-settings.php` as
   "Active Domain" is the same column, this is intentional and correct,
   one client, one subdomain, used everywhere consistently.
4. **Extend `OFP_Host_Router`:** when `current_zone()` resolves to
   `'client'` AND the subdomain matches a real client record, let
   `/login`, `/dashboard`, etc. render directly on that host instead of
   rewriting to `app.{base_domain}`. Only fall back to
   `app.{base_domain}` when the visitor is on the main domain with no
   client subdomain at all. If the subdomain doesn't match any known
   client (typo, unclaimed), fall back to a normal 404 or the main site.

### 2.4 Single Company Deals — Level 2 (custom domain, corrected scope)

For a client like Supreme Homes who wants their **own real domain**
(`crm.supremehomes.com`), not just a subdomain of yours:

1. Add a `custom_domain` column to the client record, alongside
   `subdomain` (not replacing it — a client could have both, or just
   one).
2. `OFP_Host_Router` gets a second lookup: if the host doesn't match the
   `{base_domain}` pattern at all, check it against `custom_domain`
   instead of treating it as unknown.
3. Once resolved to a `client_id`, everything downstream (dashboard,
   properties, branding) works exactly as it already does for subdomain
   clients, same code path, just a different way of arriving at the
   same `client_id`.
4. DNS: you add one A record in Cloudflare (`crm` → your server IP),
   the client's actual domain registrar/DNS is untouched otherwise.
   Server-side, add the domain as an accepted host/SSL alias.

**Per-client white-label flag** (not global): add `is_white_label` on
the client record. When a resolved client has this flag on, hide
signup links, plan/upgrade prompts, and default branding, for that
client's context only, everyone else is unaffected.

**Branding settings:** company name, logo, favicon, primary/secondary
color (as CSS variables your Tailwind setup already reads), support
contact info. Check first for any hardcoded "Ofastpipeline"/brand text
or colors in templates, emails, or the (to-be-built) receipts before
adding this, replace those with a function reading from this client's
settings, falling back to your own defaults when the flag is off.

### 2.5 Marketplace Visibility Rule (the actual "solo company" requirement)

Confirmed final scope: **normal clients' (Free/Silver/Gold) properties
stay aggregated together on your own `property.{base_domain}` /
`/marketplace/`, by design, this was never a bug.** Only white-label
(Level 2/3) clients' properties should:

- **Never appear** on your main marketplace/archive.
- **Only appear** on their own domain's card, single-property page, and
  marketplace/archive view, reusing your existing templates, filtered
  to just their `ofp_client_id`. No separate homepage/marketing template
  needed for them, confirmed, they don't want your homepage design.

**What needs building:** wherever the property archive/marketplace
query currently runs (and wherever the frontend fetches property data,
whether via the default WP REST endpoint or a template query), add a
filter that:
- Excludes any property whose owning client has `is_white_label = true`
  from the main `property.`/`/marketplace/` results.
- On a resolved white-label client's own domain, shows **only** that
  client's own properties, nothing else.

This needs to be checked against whatever the actual property archive
query currently looks like (not reviewed in this session, since the
homepage/marketplace frontend files weren't included in the ZIP), before
writing the filter, per the Golden Rule.

### 2.6 API Key / Lead Capture Snippet — unchanged, confirmed separate

This feature (documented in `docs/landing-page-integration.md`) is
untouched by anything above. It's for a client running their **own
separate website, hosted anywhere**, who wants a lead form/popup posting
to `/wp-json/ofp/v1/capture-lead`. It has nothing to do with subdomains,
dashboards, or white-label domains, and needs no changes.

### 2.7 Build Order For Section 2

1. Add `subdomain` field to `signup.php` (with suggest + uniqueness
   check) and read-only to `account.php`.
2. Extend `OFP_Host_Router` so a client's own subdomain renders
   `/login`/`/dashboard` directly, per 2.3.
3. Add `custom_domain` column + router lookup for Level 2 domains,
   per 2.4.
4. Add `is_white_label` flag + hide signup/plan chrome per-client.
5. Build Branding settings + wire into templates/emails (and receipts,
   once built).
6. Build the marketplace exclusion/inclusion filter per 2.5, after
   first inspecting the actual archive/REST query code.
7. Test end to end: a real client subdomain reaching their dashboard
   directly, and a real custom domain (via Cloudflare A record) reaching
   a white-label client's own private property pages only.

---

## Appendix: Level 3 (documented for later, not being built now)

**What it is:** A fully separate WordPress + plugin install, on the
client's own hosting, own domain, own database, fully disconnected from
your main app. A `SINGLE_COMPANY_MODE` constant/toggle turned on inside
that specific copy permanently hides multi-client screens.

**Why it's heavier:** no shared install to patch, updates must be
manually reapplied per install; their developer has full read access to
your code once it's on their server; no natural recurring hosting income
unless separately charged.

**Why it's still worth keeping as an option:** some companies will
specifically insist on owning their entire environment.

**Pricing implication (already discussed):** roughly double a Level 2
deal, since you're giving up long-term control and recurring hosting
revenue for a bigger one-time payment.

**Not scoped yet:** exact file packaging, license-key enforcement
approach, and training/documentation time for their developer. Scope
this only when a real Level 3 deal is on the table.
