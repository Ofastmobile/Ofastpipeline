# PATCH 3 — admin/views/clients-list.php

**Type:** REPLACE (a specific block only — find-and-replace, not a
full-file rewrite)

---

## Find

The existing `<select name="plan">` block in the **Add Client** form
and, separately, in the **Edit Client** form. In the original v2.0
blueprint these plans were referenced as plain hardcoded labels
(`Starter`, `Growth`, `Pro`) with no price shown, something like:

```php
<select name="plan">
    <option value="starter" <?php selected( $client->plan ?? '', 'starter' ); ?>>Starter</option>
    <option value="growth"  <?php selected( $client->plan ?? '', 'growth' ); ?>>Growth</option>
    <option value="pro"     <?php selected( $client->plan ?? '', 'pro' ); ?>>Pro</option>
</select>
```

(Your actual current markup may differ slightly in attributes/classes —
match on the `<select name="plan">` element itself and swap its inner
`<option>` list for the block below. There are likely two occurrences —
one in the Add Client form, one in the Edit Client form — apply to both.)

---

## Replace with

```php
<?php
$ofp_cl_plan_prices = OFP_Subscription::get_plan_prices();
$ofp_cl_plan_labels = [
    'starter' => 'Starter',
    'growth'  => 'Growth',
    'pro'     => 'Pro',
];
$ofp_cl_current_plan = $client->plan ?? 'starter';
?>
<select name="plan">
    <?php foreach ( OFP_Subscription::PLAN_KEYS as $ofp_cl_plan ) : ?>
        <option value="<?php echo esc_attr( $ofp_cl_plan ); ?>"
            <?php selected( $ofp_cl_current_plan, $ofp_cl_plan ); ?>>
            <?php echo esc_html( $ofp_cl_plan_labels[ $ofp_cl_plan ] ); ?>
            — NGN <?php echo esc_html( number_format( $ofp_cl_plan_prices[ $ofp_cl_plan ], 2 ) ); ?>/month
        </option>
    <?php endforeach; ?>
</select>
```

---

## Why this is safe to insert as-is

- Variables are prefixed `$ofp_cl_` to avoid colliding with `$client`,
  `$plan`, or anything else already in scope in this (larger, busier)
  file.
- Falls back to `'starter'` if `$client` is null/new (Add Client form
  case) or has no `plan` set yet.
- No JS, no AJAX — this is a plain server-rendered `<select>`, same as
  before, just with live pricing in the label text.
- Does not touch the Load Credit form, trash view, or any other section
  of this file added in Phase 10b.
