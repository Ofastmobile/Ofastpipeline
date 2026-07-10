# PATCH A — includes/class-ofp-subscription.php

**Type:** INSERT (1 new method)

**Context:** back in Phase 11/12, `listing` subscriptions had no tiers
— `OFP_Subscription::create($client_id, 'listing', null)` always
passed a null plan, and `get_listing_fee()` returned one flat number.
Now that listing has Bronze/Silver/Gold tiers (Phase 14), the `plan`
column on `ofp_subscriptions` (which already exists — it's the same
column CRM subscriptions store 'starter'/'growth'/'pro' in) gets used
for listing tiers too. No schema change needed — just a new way of
reading what's already there.

---

## Add this method to the class

```php
/**
 * The client's currently active listing plan tier ('bronze'|'silver'|'gold'),
 * or null if they have no active listing subscription at all.
 *
 * Phase 14: listing subscriptions now carry a plan tier in the same
 * `plan` column CRM subscriptions already use — this just reads it
 * back for a `type = 'listing'` row instead of `type = 'crm'`.
 *
 * @param int $client_id
 * @return string|null
 */
public static function get_active_listing_plan( int $client_id ): ?string {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( "
        SELECT plan FROM {$wpdb->prefix}ofp_subscriptions
        WHERE client_id = %d AND type = 'listing' AND status = 'paid'
        AND (period_end IS NULL OR period_end >= CURDATE())
        ORDER BY period_end DESC LIMIT 1
    ", $client_id ) );

    return $row ? $row->plan : null;
}
```

---

## One knock-on effect worth knowing about

`get_expected_monthly_total()` (added in Phase 11) currently calls
`self::get_listing_fee()` — the old flat fee — when a client has an
active listing subscription:

```php
if ( self::has_active( 'listing', $client_id ) ) {
    $total += self::get_listing_fee();
}
```

Since listing pricing is now tiered, this line should become:

```php
if ( self::has_active( 'listing', $client_id ) ) {
    $total += OFP_Property::get_plan_price( self::get_active_listing_plan( $client_id ) );
}
```

This affects payment webhook amount-matching for all three gateways —
worth testing a listing-plan sandbox payment specifically after this
change, same as any pricing change (Phase 11's test steps apply here
too).

`get_listing_fee()` and the `ofp_listing_fee_monthly` option itself can
stay in the codebase harmlessly (nothing deletes them), they're just
no longer read by `get_expected_monthly_total()` after this change —
you could remove them later in a cleanup pass if you want, not urgent.
