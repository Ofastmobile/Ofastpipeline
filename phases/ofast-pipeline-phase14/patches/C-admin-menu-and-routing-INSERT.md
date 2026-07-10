# PATCH C — admin/class-ofp-admin-menu.php

**Type:** INSERT (mirrors Patch 2 from Phase 11 exactly — same wiring
pattern, same restriction to super_admin)

---

## Add this line wherever Phase 11's `handle_save_plan_pricing` hook was added

```php
add_action( 'admin_init', [ $this, 'handle_save_listing_plans' ] );
```

## Add this new method to the class

```php
/**
 * Handles the Settings > Listing Plans form submission (Phase 14).
 * Mirrors handle_save_plan_pricing() exactly — same restriction to
 * super_admin, same nonce pattern.
 *
 * @return void
 */
public function handle_save_listing_plans(): void {
    if ( empty( $_POST['ofp_save_listing_plans'] ) ) {
        return;
    }

    if ( ! OFP_Auth::is_admin_user() ) {
        return;
    }

    if ( OFP_Auth::current_admin_role() !== 'super_admin' ) {
        wp_die( 'Access denied. Only the super admin can change pricing.' );
    }

    check_admin_referer( 'ofp_save_listing_plans_action', 'ofp_listing_plans_nonce' );

    $prices = [];
    $caps   = [];

    foreach ( OFP_Property::PLAN_KEYS as $plan ) {
        $prices[ $plan ] = isset( $_POST[ "listing_price_{$plan}" ] ) ? (float) $_POST[ "listing_price_{$plan}" ] : 0.0;
        $caps[ $plan ]   = isset( $_POST[ "listing_cap_{$plan}" ] )   ? (int) $_POST[ "listing_cap_{$plan}" ]   : 1;
    }

    OFP_Property::save_plans( $prices, $caps );

    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html__( 'Listing plans updated.', 'ofast-pipeline' )
            . '</p></div>';
    } );
}
```

---

# PATCH D — route registration for the client-portal properties page

**Type:** INSERT — same file/mechanism as Phase 13's Patch 11a
(`OFP_Client_Portal::handle_routes()`), except this route DOES require
login, so it goes in the normal (non-public) route list, not the
public-exception list.

If routes are registered as an array of logged-in-required templates:

```php
$client_routes = [ 'dashboard', 'credits', 'properties' ];
```

Or if via a `switch`, add:

```php
case 'properties':
    require OFP_PLUGIN_DIR . 'public/templates/properties.php';
    break;
```

Following exactly the same pattern `credits` already uses, since both
require `OFP_Auth::require_client_login()`.

---

## Why these are safe to insert as-is

- `handle_save_listing_plans()` is a new, specific method name — same
  super_admin restriction as pricing, so co-admins can't quietly
  change what listing plans cost.
- The `properties` route follows the exact same shape as `credits`,
  which already works — no new routing mechanism introduced.
