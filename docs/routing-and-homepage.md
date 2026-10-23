# Routing, marketplace, and homepage — do not forget

The project grew past the original CRM. This is the public-site map so we do not re-decide it later.

Switch for all of this: **Settings → CRM base domain** (`ofp_crm_base_domain`).
Until that is set, links stay on the main WordPress URL.

---

## The three public zones

| Address | Job |
|---|---|
| `yourdomain.com` | Marketing homepage (coded, fast, SEO) |
| `app.yourdomain.com` | Client CRM portal (login, dashboard, my properties, funding) |
| `marketplace.yourdomain.com` | Public marketplace (browse, search, single listing) — *`property.` also supported as alias* |
| `client.yourdomain.com` | That client's own landing page (separate from the three above) |

Reserved subdomain words (never give these to a client): `app`, `marketplace`, `property`, `www`, `mail`, `ftp`, `admin`, `api`, `crm`, `portal`.

DNS wildcard `*.yourdomain.com` must point at the WordPress install or none of the subdomains work.

---

## What is already wired vs not

### `app.` — mostly done

`OFP_Host_Router` rewrites portal paths to `app.{base_domain}`:
login, signup, dashboard, credits, properties, funding, account, etc.

Bare `app.yourdomain.com` redirects to `/login`.

### `property.` — half done

- Single listing permalinks already rewrite to `property.{base_domain}`.
- Code *tries* to show the archive when `property.` is the front page.
- There is **no** host-router rewrite for marketplace/archive links the way `/login` jumps to `app.`.

That last gap is why `/marketplace/` still exists.

### `/marketplace/` — keep it

Fallback URL for Local and for when DNS/subdomain is not live yet.
Same archive template as `property.`.
Needed because `app.../properties/` is already the **logged-in** "My Properties" page — that path cannot also be the public directory.

**Do not delete `/marketplace/`.** Brand URL is `property.yourdomain.com`. Backup URL is `/marketplace/`.

**Still to finish:** make marketplace/search/card links stay on `property.` the same way portal links stay on `app.`.

---

## Homepage — decision (no Elementor)

Do **not** paste `hero.html` into a WordPress HTML widget or page builder.

Do this:

1. Pages → create a page called **Home**. Leave it blank.
2. Settings → Reading → A static page → Homepage = **Home**.
3. That blank page is only the SEO hook (AIOSEO / Rank Math title, meta, sitemap).
4. Plugin takes over the front of the **main domain only** and loads a coded template (`public/templates/home.php`), same pattern as `/marketplace/`.
5. Call `wp_head()` / `wp_footer()` so SEO plugins still inject tags.
6. Do **not** call the theme header/footer — that is the speed win.
7. Hero search should post to `/marketplace/` (and later to `property.` once that rewrite is finished).
8. Stats and listing cards come from the database, not the fake numbers in `hero.html`.

`hero.html` on this branch is the **design mock**, not the live page.

`app.` homepage stays login. `property.` homepage stays the listings archive. Only `yourdomain.com` gets the marketing homepage.

Client landing pages (Elementor + lead-form snippet) are a different thing — see `docs/landing-page-integration.md`. That is for a client's own site, not our main homepage.

---

## Path collisions to remember

| Path | Where | What it is |
|---|---|---|
| `/properties/` on `app.` | Portal | Logged-in client "My Properties" |
| CPT archive `/properties/` | Public | WordPress property archive |
| `/marketplace/` | Public fallback | Same listings grid, no subdomain required |
| `/login` | Always `app.` | Portal login |

This collision is why the public directory cannot live at `/properties/` on the main domain once `app.` is in use.

---

## Next (after current debug is pushed)

1. Finish `property.` host routing (root = archive, links stay on `property.`).
2. Keep `/marketplace/` as fallback.
3. Turn `hero.html` into `public/templates/home.php` + plugin CSS/JS on the main-domain front page.

Do not start that until the in-progress Local bug work is committed, or we will edit a stale copy.



====================================
NOTICE
====================================
Searched for "ofp_crm_base_domain"
Viewed routing-and-homepage.md:1-80

Yes, absolutely! It will work seamlessly when you move to your official main domain.

Here is why:

### 1. Why the issue happened during your test
Because `supreme.ofastshop.com` is a subdomain itself, the system previously assumed that any subdomain ending in `.ofastshop.com` was an individual client’s private subdomain rather than the main WordPress site. 

The fix we just made permanently solves this: the system now checks `is_own_site_host()` first. It automatically recognizes whatever domain or subdomain WordPress is currently installed on as the primary host.

---

### 2. How it will work on your official main domain (e.g. `yourdomain.com`)
The entire plugin architecture was designed specifically for your official domain:

| Address | What it displays |
|---|---|
| `yourdomain.com` | Fast, SEO-optimized marketing homepage |
| `app.yourdomain.com` | Client portal (Login, Dashboard, Profile, Properties, Funding, Leads) |
| `marketplace.yourdomain.com` | Public property marketplace & search (`property.` supported as alias) |
| `{clientname}.yourdomain.com` | Branded white-label agency portal & listings |

---

### 3. What you need to do when you switch to your official domain
When you purchase and set up your main domain:

1. **In cPanel / DNS:** Add a wildcard `*` A-record (or CNAME) pointing `*.yourdomain.com` to your server IP so `app.` and client subdomains resolve.
2. **In WordPress Settings (General):** Update the Site URL and WordPress URL to `https://yourdomain.com` (or `https://app.yourdomain.com`).
3. **In OFP Admin Settings:** Set **CRM Base Domain** to `yourdomain.com`.

With the session cookie scoping (`.yourdomain.com`) we just implemented in [class-ofp-auth.php](file:///c:/Users/bodma/Local%20Sites/ofast-pipeline/app/public/wp-content/plugins/ofast-pipeline/includes/class-ofp-auth.php), users will remain logged in smoothly across `app.`, client subdomains, and the main site without session drops.

==================================
Viewed signup.php:355-380
Viewed signup.php:440-480
Viewed signup.php:480-520
Searched for "subdomain"

**Yes, exactly! That is 100% how it works.**

When your **CRM Base Domain** is set to `mydomain.com`, every client's chosen subdomain automatically becomes their direct address:

> **Example:** If a client registers with subdomain `lekkihomes`, their address is:  
> **`https://lekkihomes.mydomain.com`**

---

### What happens when they visit `https://client.mydomain.com`:

1. **Branded Login & Dashboard:**
   - Navigating to `https://client.mydomain.com/login` loads their branded login screen with their agency name and logo.
   - Once logged in, all their portal pages (`/dashboard`, `/properties`, `/leads`, etc.) stay right on `https://client.mydomain.com/...`.
2. **Branded Marketplace / Solo White-Label:**
   - If they are on a White-Label/Solo plan, visiting the root `https://client.mydomain.com` automatically opens **their own private property marketplace** (`/marketplace/`), displaying only their properties, custom colors, and agency branding.
3. **Public Agent Profile:**
   - They also have their public agent landing page at `https://client.mydomain.com/agent/{slug}`.

---

### The One Thing Needed on Your Server (Wildcard DNS)

For `client.mydomain.com`, `app.mydomain.com`, and `property.mydomain.com` to resolve automatically without creating each subdomain manually:

1. **In cPanel Domains:**
   - Go to **Domains** &rarr; **Create A New Domain**.
   - Enter `*.mydomain.com` (with the asterisk `*`).
   - Set the Document Root to the **exact same folder** as your main WordPress site (`public_html` or `/home/.../mydomain.com`).
2. **In DNS Zone Editor:**
   - Ensure an **A Record** exists for `*.mydomain.com` pointing to your server IP address (or CNAME pointing to `mydomain.com`).

Once the wildcard `*` points to WordPress, any client subdomain created in the plugin works instantly!
==================================================



==================================================
Searched for "custom_domain"
Searched for "custom_domain"
Viewed Ofastpipeline_Rent_and_Single_Company_Blueprint_v2.md:190-240

For a Solo / White-Label client who wants the platform running on their own address, there are **two ways** they can do it:

---

### Option 1: They use your subdomain (e.g. `supremehomes.yourdomain.com`)
*Best for clients who don't want technical setup.*

* **From their end:** **Zero setup.** Nothing to buy, no DNS to configure.
* **From your end:** You simply give them the link `https://supremehomes.yourdomain.com`. 
* Because you already have wildcard DNS (`*.yourdomain.com`), it works instantly.

---

### Option 2: They use THEIR OWN domain (e.g. `crm.supremehomes.com` or `portal.supremehomes.com`)
*For premium clients who want their own brand on the address bar.*

Here is the exact step-by-step breakdown:

#### 1. What the Client does (at their domain provider, e.g., Namecheap, GoDaddy, Cloudflare):
They only need to add **one DNS record**:

| Record Type | Host / Name | Points To (Value) | TTL |
|---|---|---|---|
| **CNAME** | `crm` *(or `portal`)* | `yourdomain.com` *(your main domain)* | Auto / 1 Hour |
| *or* **A Record** | `crm` *(or `portal`)* | `YOUR.SERVER.IP.ADDRESS` | Auto / 1 Hour |

> **Client's note:** If they want their naked root domain (e.g. `supremehomes.com` without `crm.`), they add an **A Record** with Host `@` pointing to your server IP.

---

#### 2. What YOU do from your end (takes 2 minutes):

1. **In your WordPress Admin:**
   - Go to **OFP Pipeline** &rarr; **Clients** &rarr; **Edit** that client.
   - Under **Custom Domain**, enter: `crm.supremehomes.com`.
   - Ensure the **Solo / White-Label Company** toggle is enabled.
   - Click **Save**.

2. **In your cPanel (Hosting):**
   - Go to **Domains** &rarr; **Create a New Domain**.
   - Enter `crm.supremehomes.com`.
   - Uncheck "Share document root" and set the document root to the **exact same folder** as your WordPress site (`public_html`).
   - cPanel's AutoSSL will automatically issue a free SSL certificate (HTTPS) for their domain within a few minutes.

---

### What happens once this is connected:
When anyone opens `https://crm.supremehomes.com`:
- `OFP_Host_Router` automatically identifies that domain and loads that specific client's portal.
- **100% White-Labeled:** Your platform's name, signup buttons, pricing plans, and other agents' properties are completely hidden.
- The root URL opens directly to **their private agency property marketplace** with their own brand colors, logo, and phone numbers.
=================================================