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

Both regular subscribers and white-label clients can embed their active property listings onto their personal or company websites (WordPress, Elementor, Divi, Wix, Squarespace, or raw HTML).

### The Embed Snippet
Found on their dashboard at **/api-settings**:

```html
<!-- MyPropertyHQ Featured Properties Widget -->
<div id="ofp-property-widget" data-client-id="CLIENT_ID_HERE" data-limit="6"></div>
<script src="https://mypropertyhq.ng/wp-content/plugins/ofast-pipeline/assets/js/ofp-embed.js" async></script>
```

### How the Embed Script Operates
1. **Zero Library Dependencies:** Built in pure, vanilla JavaScript with scoped CSS styles that will not conflict with the client's website theme or page builder styles.
2. **REST API Endpoint:** Fetches live data from `GET /wp-json/ofp/v1/properties?client_id={id}&limit=6`.
3. **Card Design:** Automatically generates responsive property cards featuring cover photos, price tags, title, location, bed/bath counters, and a "View Details" call-to-action.
4. **Smart Permalinks:**
   * For **subscribers**, clicking a card opens `https://marketplace.mypropertyhq.ng/property/{slug}/`.
   * For **white-label clients**, clicking a card opens `https://portal.theircompany.com/property/{slug}/`.

### Optional Widget Customizations
Clients can customize the HTML attributes on the `<div>`:

| Attribute | Default | Options | Description |
|---|---|---|---|
| `data-client-id` | *(Required)* | Numeric ID | Identifies which agency's properties to display. |
| `data-limit` | `6` | `3`, `6`, `9`, `12` | Total number of properties to display. |
| `data-type` | *(All)* | `sale`, `rent`, `land` | Filter to show only sales, rentals, or land deals. |

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
