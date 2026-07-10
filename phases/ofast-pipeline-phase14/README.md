# Phase 14 — Property Listing Public Pages

## Manifest

**Full new files:**
- `includes/class-ofp-property.php` — CPT, meta fields, Bronze/Silver/Gold pricing+caps, template loader, ownership/cap helpers
- `public/templates/property-archive.php` — public grid at `/properties/`
- `public/templates/property-single.php` — public single page at `/property/{slug}/`
- `public/templates/properties.php` — client portal "My Properties" (plan picker + add/edit/delete)

**Patches:**
- Patch A → `includes/class-ofp-subscription.php` — `get_active_listing_plan()`, plus a one-line change to `get_expected_monthly_total()`
- Patch B → `admin/views/settings.php` — Listing Plans card (Bronze/Silver/Gold price + cap)
- Patch C → `admin/class-ofp-admin-menu.php` — `handle_save_listing_plans()`
- Patch D → routing — registers `/properties` (client portal, login required)

**One thing you must do that isn't a patch to an existing file:** add
this to your main plugin bootstrap file, wherever the other `OFP_*`
classes get `require_once`'d and initialized:

```php
require_once OFP_PLUGIN_DIR . 'includes/class-ofp-property.php';
OFP_Property::init();
```

**No new database table.** Properties are regular WordPress posts
(`ofp_property` post type); listing plan tiers reuse the existing
`plan` column on `ofp_subscriptions` (Patch A explains this).

---

## What you'll need to do that isn't code

1. **Styling.** Every template above is plain, unstyled HTML with
   `ofp-*` class names — consistent with your other client-facing
   templates (signup.php, credits.php) which I understand you style
   centrally (likely a shared `ofp-frontend.css` given the consistent
   class naming across phases). Nothing here will look like a real
   property site until that CSS is applied.
2. **Add a "My Properties" link** to whatever nav/menu already links
   to Dashboard and Credits in the client portal — I didn't touch that
   nav since I don't have its file.
3. **SEO plugin setup** — once this is live, go into Yoast/RankMath's
   settings and enable SEO for the `ofp_property` post type (both
   plugins support custom post types out of the box, just needs
   switching on) so titles/meta descriptions/sitemaps pick up
   properties automatically.

---

## Your test steps

1. Add the bootstrap lines above, apply Patches A–D.
2. Visit `wp-admin → Properties` — confirm the post type shows up
   with its own menu item (dashicon: home icon).
3. Visit `/properties/` on the front end — confirm it loads your
   plugin's template (empty grid, since nothing's published yet) and
   NOT a 404 or the theme's generic archive.
4. Log in as a test client, go to `/properties` (portal version) —
   confirm you see "Choose a Listing Plan" since they have no active
   listing plan yet.
5. Pick Bronze, submit — confirm you're redirected into the payment
   flow (same as any other subscription payment). Complete it in
   sandbox.
6. Back on `/properties` — confirm it now shows "Bronze Plan, using 0
   of 3 properties" and the Add Property form is visible.
7. Add a property with a title, price, and at least one photo —
   confirm it saves, shows "awaiting review" in Your Listings, and
   does NOT appear on the public `/properties/` archive yet.
8. In `wp-admin → Properties`, find that pending post, change its
   status to Published — confirm it now appears on the public
   `/properties/` archive and its single page loads correctly with
   the photo, price, and details.
9. Add 3 total properties (hitting the Bronze cap of 3) — confirm the
   4th attempt is blocked with the "reached your plan's property
   limit" message, and that switching to Silver removes the block.
10. Log in as a DIFFERENT test client, try to guess-edit the first
    client's property by manually changing `?edit={id}` in the URL —
    confirm the ownership check blocks it (form should just show "Add
    New Property" again, not their listing).
11. Publish one property directly in wp-admin with no `ofp_client_id`
    meta set at all (i.e., don't go through the client form) — confirm
    it appears on the public archive with no cap/ownership
    restriction, proving the owner-published path works.
12. Test the amount-matching change from Patch A — do a sandbox
    listing-plan payment and confirm the webhook matches the correct
    tiered price (e.g. Bronze's 7,500), not the old flat listing fee.

---

## What's still open

The **landing page integration guide** is the one remaining item from
your original priority list — worth discussing what that actually
needs to cover (embed codes? webhook docs? a walkthrough for clients
using page builders other than WordPress?) before I start writing it,
since "integration guide" could mean fairly different things depending
on who's meant to read it.
