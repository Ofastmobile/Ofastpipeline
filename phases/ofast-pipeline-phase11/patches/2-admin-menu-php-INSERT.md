# PATCH 2 — admin/class-ofp-admin-menu.php

**Type:** INSERT (2 small additions to the existing class — do not
replace the file)

---

## 2a. Add one line to the constructor

Find the `__construct()` method (it already registers `admin_menu` and,
per Phase 10b, presumably a hook or dispatch for `handle_topup_credit()`).
Add this line alongside whatever hook registration pattern is already
used for that handler. If Phase 10b's topup handler is wired via
`admin_init`, mirror it exactly:

```php
add_action( 'admin_init', [ $this, 'handle_save_plan_pricing' ] );
```

If instead Phase 10b's handlers are dispatched from inside
`render_settings()` / `render_clients()` by checking `$_POST` directly
(rather than a dedicated `admin_init` hook), then instead call
`$this->handle_save_plan_pricing();` as the first line inside
`render_settings()`, before the `include` of `settings.php` — so the
notice (if any) and any redirect happen before output starts.

**Whichever pattern matches your existing topup handler wiring, use the
same one here for consistency.**

---

## 2b. Add this new method to the class

```php
/**
 * Handles the Settings > Plans & Pricing form submission (Phase 11).
 *
 * Restricted to super_admin only, matching the existing restriction
 * on the whole Settings page — co-admins can view pricing (it's
 * displayed elsewhere, e.g. signup.php) but should not be able to
 * change what the business charges.
 *
 * @return void
 */
public function handle_save_plan_pricing(): void {
    if ( empty( $_POST['ofp_save_plan_pricing'] ) ) {
        return;
    }

    if ( ! OFP_Auth::is_admin_user() ) {
        return;
    }

    if ( OFP_Auth::current_admin_role() !== 'super_admin' ) {
        wp_die( 'Access denied. Only the super admin can change pricing.' );
    }

    check_admin_referer( 'ofp_save_plan_pricing_action', 'ofp_plan_pricing_nonce' );

    $plan_prices = [];
    $setup_fees  = [];

    foreach ( OFP_Subscription::PLAN_KEYS as $plan ) {
        $plan_prices[ $plan ] = isset( $_POST[ "price_{$plan}" ] )
            ? (float) $_POST[ "price_{$plan}" ]
            : 0.0;

        $setup_fees[ $plan ] = isset( $_POST[ "setup_{$plan}" ] )
            ? (float) $_POST[ "setup_{$plan}" ]
            : 0.0;
    }

    $listing_fee = isset( $_POST['listing_fee'] ) ? (float) $_POST['listing_fee'] : 0.0;

    OFP_Subscription::save_pricing( $plan_prices, $setup_fees, $listing_fee );

    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html__( 'Plan pricing updated.', 'ofast-pipeline' )
            . '</p></div>';
    } );
}
```

---

## Why this is safe to insert as-is

- Method name `handle_save_plan_pricing` is new and specific — very low
  collision risk with anything already in the class.
- Uses `check_admin_referer()` (WordPress core nonce verification) —
  matches the security posture already used elsewhere in the plugin
  (Turnstile, rate limiting, etc.).
- Fails closed: if the nonce is missing/invalid, `check_admin_referer()`
  calls `wp_die()` on its own — no pricing changes can be replayed or
  CSRF'd in.
- Does not touch `handle_topup_credit()` or any other existing handler —
  purely additive.
