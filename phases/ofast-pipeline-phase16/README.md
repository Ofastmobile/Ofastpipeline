# Phase 16 — app.crmdomain.com and property.crmdomain.com

## What this phase does, in plain words

Three addresses now do three different jobs:

- **crmdomain.com** — your normal homepage. Untouched.
- **app.crmdomain.com** — the whole tool. Login, signup, dashboard,
  credits, and the client's own "My Properties" page.
- **property.crmdomain.com** — the public marketplace anyone can
  browse, all approved listings.

Client subdomains like `abcrealty.crmdomain.com` still work exactly
like before — nothing about Phase 15 changes.

---

## Manifest

| File | Type | What it does |
|---|---|---|
| `includes/class-ofp-host-router.php` | New | Fixes every "Login"/"Dashboard"/etc. link so it always points to app.crmdomain.com, no matter which address someone is on |
| Patch F → `class-ofp-property.php` | Insert | Property links point to property.crmdomain.com automatically; property.crmdomain.com's homepage shows the marketplace directly |
| Patch G → `class-ofp-landing-page.php` | Insert | Makes sure "app" and "property" can never accidentally be treated as a client's own subdomain |

**Bootstrap addition** (same spot as the others):

```php
require_once OFP_PLUGIN_DIR . 'includes/class-ofp-host-router.php';
OFP_Host_Router::init();
```

---

## A real bug this phase found and fixed

While building this, I found that `/properties` was actually being
used for two different things at once since Phase 14 — the public
marketplace grid AND the client's own private listings page. On one
shared address that was already a quiet conflict waiting to cause
confusion. Patch F fixes it directly: `app.crmdomain.com/properties`
now always means the client's own page, `property.crmdomain.com`
always means the public marketplace, no matter what.

---

## One thing to verify, not guess

Login, signup, dashboard, credits, and properties are handled by
whatever routing code already sits in your `OFP_Client_Portal` class
— I don't have that file's exact content. The design here assumes that
routing simply looks at the URL path (like `/login`) and doesn't care
which address it came in on. That should already be true for most
WordPress setups, but it's worth actually testing rather than just
trusting it, since it's the one piece I can't verify directly.

---

## Your test steps

1. Set up the DNS: `app.crmdomain.com` and `property.crmdomain.com`
   should already work automatically, since your wildcard
   (`*.crmdomain.com`) already covers them — no new DNS record needed.
2. Add the bootstrap lines, apply Patches F and G.
3. Visit `app.crmdomain.com` (just the bare address, no path) —
   confirm it sends you straight to the login page.
4. Log in, confirm `app.crmdomain.com/dashboard`,
   `app.crmdomain.com/credits`, and `app.crmdomain.com/properties`
   all load correctly.
5. On the dashboard, click a few internal links (like "Top Up
   Credit" or "My Properties") — confirm they stay on
   app.crmdomain.com and never bounce you back to crmdomain.com.
6. Visit `property.crmdomain.com` — confirm you see the public
   marketplace grid directly, no need to add `/properties` to the address.
7. Click into a single property — confirm the address bar shows
   `property.crmdomain.com/property/{slug}/`, not your main domain.
8. Visit `crmdomain.com` on its own — confirm your normal homepage is
   completely unaffected by any of this.
9. Try to set a client's subdomain to literally "app" or "property" in
   wp-admin — confirm it's rejected (Patch G).

---

## Status

This closes out the whole domain/subdomain structure discussion. If
DNS/SSL for a client's own external domain ever comes back up in the
future, that's still a separate, later conversation — for now, every
client only ever gets a subdomain of yours, which this phase and
Phase 15 already fully support.
