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
| `property.yourdomain.com` | Public marketplace (browse, search, single listing) |
| `client.yourdomain.com` | That client's own landing page (separate from the three above) |

Reserved subdomain words (never give these to a client): `app`, `property`, `www`, `mail`, `ftp`, `admin`.

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
