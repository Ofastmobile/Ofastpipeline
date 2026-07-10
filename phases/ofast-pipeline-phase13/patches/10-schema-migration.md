# PATCH 10 — Schema migration: reset token columns

**Type:** ALTER TABLE — 2 new nullable columns on `wp_ofp_clients`

This is the one piece of this phase that IS a schema change, so it
needs a proper migration rather than a raw one-time query, in case you
ever reinstall on a fresh site or another dev pulls this later.

---

## Add these 2 columns

```sql
ALTER TABLE {$wpdb->prefix}ofp_clients
    ADD COLUMN reset_token_hash VARCHAR(64) NULL DEFAULT NULL AFTER password_hash,
    ADD COLUMN reset_token_expires DATETIME NULL DEFAULT NULL AFTER reset_token_hash;
```

(`VARCHAR(64)` because sha256 hex output is always exactly 64
characters — no need for more.)

---

## Where to run this

Wherever your plugin already handles schema upgrades on activation/
version bump (per your notes this pattern already exists — described
elsewhere as `maybe_upgrade_schema()` or similar). Add a
column-existence check so this is safe to run repeatedly and safe on
a fresh install where the columns won't exist yet but also won't
double-run on every page load:

```php
public static function maybe_add_reset_token_columns(): void {
    global $wpdb;
    $table = $wpdb->prefix . 'ofp_clients';

    $column_exists = $wpdb->get_var( $wpdb->prepare( "
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'reset_token_hash'
    ", DB_NAME, $table ) );

    if ( ! $column_exists ) {
        $wpdb->query( "
            ALTER TABLE {$table}
                ADD COLUMN reset_token_hash VARCHAR(64) NULL DEFAULT NULL,
                ADD COLUMN reset_token_expires DATETIME NULL DEFAULT NULL
        " );
    }
}
```

Hook this into whatever your existing schema-upgrade trigger is (a
`register_activation_hook()` callback, or a version-number check on
`admin_init` if that's your existing pattern for adding columns to
already-live sites without requiring deactivate/reactivate). If you
already have a version-check upgrade routine from earlier phases,
adding a call to this method there is the cleanest fit — I don't have
that routine's exact code in front of me to patch it directly, but the
column-existence check above makes this method itself idempotent
regardless of how/when it gets called.

---

## Why the "AFTER password_hash" placement matters (a little)

Purely cosmetic — column order in a `SELECT *` result, nothing
functional depends on it. Feel free to drop the `AFTER` clauses
entirely if your MySQL/MariaDB version or hosting setup makes that
simpler; the columns will just land at the end of the table instead.
