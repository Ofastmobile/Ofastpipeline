# PATCH E — Schema migration + admin client form fields

**Type:** ALTER TABLE (3 new nullable columns) + INSERT into
clients-list.php's Add/Edit Client form

---

## 1. Schema — 3 new columns on `wp_ofp_clients`

```sql
ALTER TABLE {$wpdb->prefix}ofp_clients
    ADD COLUMN subdomain VARCHAR(63) NULL DEFAULT NULL,
    ADD COLUMN custom_domain VARCHAR(255) NULL DEFAULT NULL,
    ADD COLUMN landing_page_id BIGINT UNSIGNED NULL DEFAULT NULL;
```

Same idempotent-migration pattern as Phase 13's Patch 10 — add a
column-existence check via `information_schema.COLUMNS` before
running the `ALTER TABLE`, and hook it into whatever your existing
schema-upgrade routine is. `subdomain` should also get a unique index
once you're comfortable it's populated correctly, to guarantee two
clients can never collide on the same slug:

```sql
ALTER TABLE {$wpdb->prefix}ofp_clients ADD UNIQUE INDEX idx_subdomain (subdomain);
```

(Left as a separate, optional statement rather than bundled into the
first migration, since adding a unique index to a table that might
already have duplicate/blank subdomain values would fail outright —
worth checking for duplicates first if you're applying this to a site
with existing client rows.)

---

## 2. One new option: your base CRM domain

```php
update_option( 'ofp_crm_base_domain', 'crmdomain.com' ); // replace with your real domain
```

This is what `OFP_Landing_Page::get_client_by_host()` strips off a
hostname to figure out the subdomain slug — e.g. if this option is
`crmdomain.com` and a request comes in for `abcrealty.crmdomain.com`,
it extracts `abcrealty` and looks that up against the `subdomain`
column. Add a simple text field for this in Settings if you want it
admin-editable, or just set it once via `update_option()` — it's not
expected to change often.

---

## 3. Add these 3 fields to the Add/Edit Client form in clients-list.php

```php
<label>
    Subdomain
    <input type="text" name="subdomain"
           value="<?php echo esc_attr( $client->subdomain ?? '' ); ?>"
           placeholder="e.g. abcrealty">
    <span class="ofp-muted">
        Will be reachable at {value}.<?php echo esc_html( get_option( 'ofp_crm_base_domain', 'crmdomain.com' ) ); ?>
    </span>
</label>

<label>
    Custom Domain <span class="ofp-optional">(optional — if the client has their own domain)</span>
    <input type="text" name="custom_domain"
           value="<?php echo esc_attr( $client->custom_domain ?? '' ); ?>"
           placeholder="e.g. abcrealty.com">
</label>

<?php
$ofp_landing_pages = get_posts( [
    'post_type'      => 'page',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
] );
?>
<label>
    Landing Page
    <select name="landing_page_id">
        <option value="">— None assigned yet —</option>
        <?php foreach ( $ofp_landing_pages as $ofp_page ) : ?>
            <option value="<?php echo esc_attr( $ofp_page->ID ); ?>"
                <?php selected( $client->landing_page_id ?? '', $ofp_page->ID ); ?>>
                <?php echo esc_html( $ofp_page->post_title ); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <span class="ofp-muted">
        Build the actual page first (Pages &rarr; Add New), then come back and assign it here.
    </span>
</label>
```

## 4. Save handler — add to wherever the existing Add/Edit Client
form is processed (likely already in clients-list.php or
admin-menu.php, alongside where `business_name`, `email`, etc. are saved)

```php
$subdomain = sanitize_title( wp_unslash( $_POST['subdomain'] ?? '' ) ); // sanitize_title enforces lowercase/URL-safe
$custom_domain = sanitize_text_field( wp_unslash( $_POST['custom_domain'] ?? '' ) );
$landing_page_id = isset( $_POST['landing_page_id'] ) && $_POST['landing_page_id'] !== ''
    ? (int) $_POST['landing_page_id']
    : null;

// Add these 3 keys to whatever $wpdb->update(...) array already saves
// the rest of this client's fields:
// 'subdomain'       => $subdomain ?: null,
// 'custom_domain'   => $custom_domain ?: null,
// 'landing_page_id' => $landing_page_id,
```

---

## Why this is safe to insert as-is

- `sanitize_title()` on the subdomain field guarantees it's always
  lowercase and URL-safe (matching what a real subdomain needs to be)
  regardless of what an admin types in.
- The landing page dropdown only ever lists already-published Pages —
  an admin can't accidentally point a client at a draft/unpublished
  page and get a broken result (the routing class also fails safe to
  your normal homepage if the assigned page turns out unpublished, as
  a second layer of protection).
