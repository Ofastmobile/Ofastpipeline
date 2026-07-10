# PATCH H — Schema migration (Phase 17)

**Type:** CREATE TABLE (2 new tables) + ALTER TABLE (2 new columns)

Add all of these inside your existing `maybe_upgrade_schema()` or
equivalent, with the same column-existence / table-existence checks
you already use for previous migrations.

---

## H1. New table: wp_ofp_notifications

```sql
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ofp_notifications (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id   BIGINT UNSIGNED NOT NULL,
    type        VARCHAR(80)     NOT NULL,
    title       VARCHAR(255)    NOT NULL,
    message     TEXT            NOT NULL,
    is_read     TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_client_unread (client_id, is_read),
    KEY idx_client_created (client_id, created_at)
) {$wpdb->get_charset_collate()};
```

---

## H2. New table: wp_ofp_funding_requests

Stores a client's manual funding submission (they paste their
transaction reference, say how much they sent, and you review it
in wp-admin before crediting anything).

```sql
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ofp_funding_requests (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id        BIGINT UNSIGNED NOT NULL,
    amount           DECIMAL(12,2)   NOT NULL,
    channel          VARCHAR(20)     NOT NULL,
    bank_name        VARCHAR(100)    NOT NULL,
    account_name     VARCHAR(150)    NOT NULL,
    transaction_ref  VARCHAR(255)    NOT NULL,
    note             TEXT,
    status           VARCHAR(20)     NOT NULL DEFAULT 'pending',
    reviewed_by      BIGINT UNSIGNED NULL,
    reviewed_at      DATETIME        NULL,
    created_at       DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_client_id (client_id),
    KEY idx_status (status)
) {$wpdb->get_charset_collate()};
```

---

## H3. Two new columns on wp_ofp_clients

```sql
ALTER TABLE {$wpdb->prefix}ofp_clients
    ADD COLUMN ofp_notification_pref VARCHAR(10) NOT NULL DEFAULT 'both',
    ADD COLUMN ofp_notification_pref_added TINYINT(1) DEFAULT 0;
```

(The second column is just a migration guard — same pattern as before.
You can use your existing column-existence check via
`information_schema.COLUMNS` instead if you prefer consistency with
prior migrations.)

---

## Why these columns and tables are shaped this way

- `ofp_notifications` uses a compound index on `(client_id, is_read)`
  so the unread count query (runs on every page load to show the bell
  badge) is fast even with thousands of rows.
- `ofp_funding_requests` tracks `reviewed_by` and `reviewed_at` so
  you have a full audit trail of who approved what and when — useful
  later if a client disputes a top-up.
- `ofp_notification_pref` defaults to `'both'` so existing clients
  automatically get bell + email without you having to set it
  manually for each one.
