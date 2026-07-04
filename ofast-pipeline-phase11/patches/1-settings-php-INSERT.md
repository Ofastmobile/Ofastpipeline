# PATCH 1 — admin/views/settings.php

**Type:** INSERT (do not replace the whole file)
**Where:** Anywhere inside the existing Settings page markup, as its own
card/section — after the API credentials sections is the natural spot,
before the closing `</div>` of the page wrapper. This is a self-contained
`<form>` with its own submit button and its own nonce, so it does not
need to sit inside any other `<form>` tag — keep it as a sibling section.

**Visibility:** This section should only render for `super_admin`, matching
the existing pattern already used to gate the whole Settings page
(`OFP_Admin_Menu::render_settings()` already calls
`wp_die('Access denied.')` for non-super-admins before this view even
loads, so no additional guard is strictly required inside this file —
but if `settings.php` has any internal role branching for sub-sections,
apply the same `current_admin_role() === 'super_admin'` check here too).

---

## Insert this block:

```php
<h2>Plans &amp; Pricing</h2>
<p class="description">
    Monthly CRM plan fees, one-time setup fees, and the property listing
    fee. These values are read live everywhere a price is needed —
    self-serve signup, manual client creation in wp-admin, and payment
    webhook amount-matching for Monnify, Paystack, and Flutterwave.
    Changing a number here takes effect immediately, no deploy needed.
</p>

<?php
$ofp_plan_prices = OFP_Subscription::get_plan_prices();
$ofp_setup_fees  = OFP_Subscription::get_setup_fees();
$ofp_listing_fee = OFP_Subscription::get_listing_fee();
$ofp_plan_labels = [
    'starter' => 'Starter',
    'growth'  => 'Growth',
    'pro'     => 'Pro',
];
?>

<form method="post" action="">
    <?php wp_nonce_field( 'ofp_save_plan_pricing_action', 'ofp_plan_pricing_nonce' ); ?>

    <table class="form-table" role="presentation">
        <?php foreach ( OFP_Subscription::PLAN_KEYS as $ofp_plan ) : ?>
            <tr>
                <th scope="row"><?php echo esc_html( $ofp_plan_labels[ $ofp_plan ] ); ?> Plan</th>
                <td>
                    <label style="margin-right:24px;">
                        Monthly fee (NGN)
                        <input type="number" step="0.01" min="0"
                               name="price_<?php echo esc_attr( $ofp_plan ); ?>"
                               value="<?php echo esc_attr( $ofp_plan_prices[ $ofp_plan ] ); ?>"
                               style="width:140px;">
                    </label>
                    <label>
                        Setup fee (NGN, one-time)
                        <input type="number" step="0.01" min="0"
                               name="setup_<?php echo esc_attr( $ofp_plan ); ?>"
                               value="<?php echo esc_attr( $ofp_setup_fees[ $ofp_plan ] ); ?>"
                               style="width:140px;">
                    </label>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <th scope="row">Property Listing Fee</th>
            <td>
                <label>
                    Monthly fee per property (NGN)
                    <input type="number" step="0.01" min="0"
                           name="listing_fee"
                           value="<?php echo esc_attr( $ofp_listing_fee ); ?>"
                           style="width:140px;">
                </label>
            </td>
        </tr>
    </table>

    <p class="submit">
        <button type="submit" name="ofp_save_plan_pricing" value="1" class="button button-primary">
            Save Pricing
        </button>
    </p>
</form>
```

---

## Why this is safe to insert as-is

- All variable names are prefixed `$ofp_` to avoid colliding with any
  variables already in scope elsewhere in `settings.php` (e.g. a generic
  `$plan` or `$prices` variable used by another section).
- It posts to itself (`action=""`), same pattern WordPress settings pages
  normally use — no new route or REST endpoint involved.
- The actual save logic lives entirely in Patch 2 (admin-menu.php), so
  this block is pure display + a form. Nothing here touches the database.
