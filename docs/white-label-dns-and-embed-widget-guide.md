# White-Label, DNS Routing & Property Embed Widget Guide

## 1. Architecture Overview

The **Ofast Pipeline / MyPropertyHQ** platform operates on a multi-tiered domain and host routing engine ([`includes/class-ofp-host-router.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/includes/class-ofp-host-router.php) and [`includes/class-ofp-property-cpt.php`](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/includes/class-ofp-property-cpt.php)).

### The Two Client Tiers & How Links Resolve

```
                           Visitor Clicks Property Link
                                        │
                                        ▼
                           Is Client White-Label?
                               /              \
                             YES              NO (Subscriber)
                             /                  \
                Stay 100% on Custom Domain    Rewrite to Public Marketplace
           (portal.agency.com/property/{slug}/) (marketplace.mypropertyhq.ng/property/{slug}/)
```

| Feature | Standard Subscriber | White-Label / Solo Client |
|---|---|---|
| **Subdomain** | `agent.mypropertyhq.ng` | `portal.theircompany.com` (or `agent.mypropertyhq.ng`) |
| **Subdomain Job** | **Agent Auth & CRM Portal Only** (`/login`, `/dashboard`) | **Full Branded Agency Suite** (Marketplace, Portal, Inquiries) |
| **Property Card Click** | Routes to **`marketplace.mypropertyhq.ng/property/{slug}/`** | Routes to **`portal.theircompany.com/property/{slug}/`** |
| **Main Site Homepage** | Main `mypropertyhq.ng` marketing home | **Completely bypassed** (Redirects to their private listings archive or login) |
| **Billing & Upgrades** | Sees plan limits and credit top-up UI | **All subscription chrome & upgrade prompts hidden** |
| **Listing Isolation** | Listed on the shared public marketplace | Private listings hidden from shared marketplace |

---

## 2. DNS Configuration Guide

### A. Server Setup for `mypropertyhq.ng` (Do Once)
To enable unlimited subscriber subdomains (`*.mypropertyhq.ng`) automatically without manually creating records for each new user:

1. **In DNS Manager (Cloudflare, cPanel Zone Editor, or Namecheap):**
   * **Record Type:** `A`
   * **Name / Host:** `*`
   * **Points to (Value):** Your Server IP Address (e.g. `123.45.67.89`)
   * **TTL:** Auto / 1 Hour
   * *(Also ensure `app` and `property` A-records or CNAMEs point to the same server IP)*

2. **In cPanel Domains:**
   * Go to **Domains** → **Create A New Domain**.
   * Enter: `*.mypropertyhq.ng`.
   * Set Document Root to the **exact same directory** as your main WordPress install (`public_html`).
   * This allows any incoming `{subdomain}.mypropertyhq.ng` request to hit the WordPress router.

3. **In WordPress Admin:**
   * Go to **OFP Pipeline** → **Settings** → **CRM Base Domain**.
   * Enter: `mypropertyhq.ng`.

---

### B. Setup for a White-Label Client (e.g. `portal.apexrealty.com`)
When an independent real estate agency wants their own dedicated branded portal:

1. **What the Client Configures in their Domain Registrar (e.g., GoDaddy, Namecheap, Cloudflare):**
   * They add a single DNS record:
   
   | Type | Host / Name | Value / Points To | TTL |
   |---|---|---|---|
   | **CNAME** | `portal` *(or `properties`)* | `mypropertyhq.ng` | Auto |

2. **What YOU Configure in your WordPress Admin (Takes 30 seconds):**
   * Go to **OFP Pipeline** → **Clients** → **Edit** client.
   * Under **Custom Domain**, enter: `portal.apexrealty.com`.
   * Check **"White-Label / Solo Company"** (`is_white_label = 1`).
   * Upload their **Logo**, set their **Brand Color** (hex), and **Support Phone / Email**.
   * Click **Save Changes**.

3. **In your Server (cPanel):**
   * In **Domains** → **Create A New Domain**, add `portal.apexrealty.com` pointing to the same `public_html` directory so your server issues a free AutoSSL certificate.

---

## 3. Website Featured Properties Embed Widget

Both regular subscribers and white-label clients can show their live listings on any website
(WordPress, Elementor, Divi, Wix, Squarespace, raw HTML). Each client copies their own snippet
from **/api-settings**; the dashboard fills in the right domain and client id.

### The snippet (what /api-settings generates)

Subscriber, or white-label on a platform subdomain:

```html
<div id="ofp-property-widget" data-client-id="CLIENT_ID" data-limit="6"></div>
<script src="https://YOUR-PLATFORM-DOMAIN/widget.js" async></script>
```

White-label client with a custom domain (no client id needed, the domain identifies them):

```html
<div id="ofp-property-widget" data-limit="6"></div>
<script src="https://portal.theircompany.com/widget.js" async></script>
```

### Public routes

| Route | Purpose |
|---|---|
| `GET /widget.js` | The embed script (served without the plugin folder in the URL) |
| `GET /api/v1/properties?client_id=ID&limit=6&type=sale` | Property feed used by the widget |
| `POST /api/v1/inquire` | Lead capture (same engine as `/wp-json/ofp/v1/capture-lead`) |

`/wp-json/ofp/v1/properties` and `/capture-lead` still work and return the same data.

### What the feed returns (and does not)

- Fixed fields only: id (WordPress post id), title, price, formatted price (rent shows `/yr`, `/mo`, `/night`),
  location, listing type, beds, baths, image URL, link. No table names, file paths, owner phone/email or owner name.
- Only listings that are **live**, **published** and **not currently let**.
- Unknown, suspended, cancelled and trashed clients all get the same empty answer.
- Cached for 60 seconds, so new listings show within a minute.
- `type` accepts `sale`, `rent`, `land`, `shortlet`. `limit` is 1 to 24.

### Where a card click goes

| Client | Link opens |
|---|---|
| Subscriber | `https://marketplace.{base}/property/{slug}/` on the shared marketplace |
| White-label, custom domain | `https://{custom_domain}/property/{slug}/` |
| White-label, no custom domain | `https://{subdomain}.{base}/property/{slug}/` |

This is decided in one place, `OFP_Property_CPT::rewrite_permalink_to_property_subdomain()` using
`OFP_White_Label::host_for_post()`. Emails, SMS, wp-admin "View" and the widget all get the same link.
White-label listings are never served on the main site or the marketplace (single page, archive, search).
If a main-site visitor opens an old link to one, WordPress redirects them to the owner's own domain.

### Widget attributes

| Attribute | Default | Options | Description |
|---|---|---|---|
| `data-client-id` | *(required, except on a custom domain)* | Numeric ID | Whose properties to show |
| `data-limit` | `6` | 1 to 24 | Number of properties |
| `data-type` | *(all)* | `sale`, `rent`, `land`, `shortlet` | Filter by listing type |

### Lead form on the client's own site

The dashboard sample uses `fetch()` to `/api/v1/inquire`, includes the hidden honeypot field (`website`) and,
when Turnstile is switched on in Settings, the Turnstile widget. If Turnstile is on, the client's website
domain must be added to the hostnames of the Turnstile widget in Cloudflare.

---

## 4. Integration Examples

### Elementor (WordPress)
1. Edit the target page with Elementor.
2. Search for the **HTML** widget and drag it to the desired section.
3. Paste the embed code from **/api-settings**.
4. Click **Update** — live properties render immediately.

### Custom HTML / Wix / Squarespace
1. Insert an **Embed / Custom Code** block on your homepage or listings page.
2. Paste the 2-line snippet and save.
