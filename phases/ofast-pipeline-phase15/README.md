# Phase 15 — Client Domain/Subdomain Landing Page Routing

## Manifest

| File | Type | What it does |
|---|---|---|
| `includes/class-ofp-landing-page.php` | New | Detects the visitor's hostname, swaps the homepage to that client's assigned landing page, leaves every other URL (`/login`, `/properties`, etc.) untouched |
| Patch E | Schema + form | 3 new columns on `wp_ofp_clients` (`subdomain`, `custom_domain`, `landing_page_id`), plus the admin form fields to set them per client |

**Bootstrap addition** (same as Phase 14's `OFP_Property::init()`):

```php
require_once OFP_PLUGIN_DIR . 'includes/class-ofp-landing-page.php';
OFP_Landing_Page::init();
```

---

## How this actually works, end to end

1. You build a client's landing page the normal way — WordPress
   Pages → Add New, design it however you like (Elementor, block
   editor, whatever you already use for these).
2. In the client's Add/Edit form in wp-admin, you pick that page from
   the new **Landing Page** dropdown, and set either their
   **Subdomain** (e.g. `abcrealty`) or, if they have their own domain,
   their **Custom Domain** (e.g. `abcrealty.com`) — or both, if you
   want the subdomain as a fallback.
3. When a visitor hits `abcrealty.crmdomain.com` (or `abcrealty.com`,
   once its DNS points here — see below), `OFP_Landing_Page` recognizes
   the hostname, looks up which client it belongs to, and serves that
   client's assigned Page as the homepage for that specific hostname —
   automatically, no manual per-request routing needed once it's set up.
4. Every other URL on that same hostname (`/login`, `/credits`,
   `/properties`) works completely normally — only the homepage
   changes per-client, everything else stays as your one shared
   WordPress install.

---

## The part that ISN'T code — DNS and SSL, and a decision I need from you

This class only decides what to render once a request reaches your
server. Getting the request to arrive here at all, for two very
different hostname shapes, is an infrastructure question with two
different answers:

**For your own subdomains** (`{business}.crmdomain.com`) — straightforward.
Since Cloudflare already fronts your domains, add one wildcard DNS
record: `*.crmdomain.com` → your server, proxied through Cloudflare.
Cloudflare's Universal SSL covers wildcard subdomains automatically,
so every new client subdomain works instantly with HTTPS, no
per-client certificate work. This is a one-time setup, not a
per-client task.

**For a client's own domain** (`abcrealty.com`) — genuinely two
options, worth picking deliberately rather than me assuming:

- **Cloudflare for SaaS** (Cloudflare's paid custom-hostnames product):
  the client adds one CNAME record at their registrar pointing to
  your domain, and Cloudflare automatically issues and manages an SSL
  certificate for their domain on your behalf. This is the smoothest
  experience and scales cleanly as you add more clients with their own
  domains, but it's a paid add-on on top of your existing Cloudflare plan.
- **Manual per-domain setup**: client points their domain's DNS
  directly at your server (A record or CNAME), and you provision an
  SSL certificate for that specific domain yourself (e.g. via
  Certbot/Let's Encrypt). Free, but it's a manual step you'd repeat
  for every client who brings their own domain — exactly the kind of
  repetitive task that eats into your 24–72 hour onboarding window.

Given you're already running a Cloudflare-centric stack across your
other properties, and this is specifically the piece that could slow
down your onboarding promise as you add more custom-domain clients,
I'd lean toward Cloudflare for SaaS being worth the cost once you have
more than a couple of custom-domain clients — but that's a real cost
tradeoff only you can weigh, not something I should decide for you.

**If you want, I can also build the actual automation piece** — a
small admin tool that calls Cloudflare's API to create the DNS record
(and, if you go the Cloudflare for SaaS route, register the custom
hostname) the moment you save a client's subdomain/custom domain in
wp-admin, rather than you doing it by hand in the Cloudflare dashboard
every time. That would directly attack the slowest part of your
24–72 hour window. Worth doing now, or do you want to sit with the
manual version for a while first?

---

## Your test steps

1. Apply Patch E (schema + form fields), add the bootstrap lines,
   drop in `class-ofp-landing-page.php`.
2. Set `ofp_crm_base_domain` to your real base domain.
3. Build a simple test Page (e.g. "Test Landing — ABC Realty"),
   publish it.
4. Edit a test client, set their subdomain to `abctest`, assign that
   Page as their Landing Page.
5. Point your local hosts file (or a real DNS record, if you'd rather
   test against the live wildcard) at `abctest.crmdomain.com` →
   your server's IP.
6. Visit `abctest.crmdomain.com` — confirm you see the assigned Page,
   NOT your normal site homepage.
7. Visit `abctest.crmdomain.com/login` — confirm the normal login page
   loads correctly, proving only the homepage is being overridden.
8. Visit your normal `crmdomain.com` homepage — confirm it's
   completely unaffected.
9. Unpublish the assigned test Page, revisit `abctest.crmdomain.com` —
   confirm it fails safe back to your normal homepage rather than
   showing an error, and check your error log for the expected warning.
10. Repeat steps 4–9 with `custom_domain` set instead of `subdomain`,
    once you've decided on the DNS/SSL approach above and have a real
    or hosts-file-simulated domain to test against.

---

## Status check — your original priority list

This closes out every item you originally flagged: plan pricing,
self-serve credit top-up, password reset, property listing public
pages, and now landing page routing. The one open thread is the
Cloudflare automation question above — let me know which way you want
to go and I'll pick it up from there.
