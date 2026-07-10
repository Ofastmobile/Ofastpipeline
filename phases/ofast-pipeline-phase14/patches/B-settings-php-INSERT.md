# PATCH B — admin/views/settings.php

**Type:** INSERT — add this as its own card, right after the Plans &
Pricing section from Phase 11 (same page, separate form/section).

---

## Insert this block

```php
<h2>Listing Plans</h2>
<p class="description">
    Bronze/Silver/Gold property listing tiers — monthly price and
    property cap per tier. Read live by the client dashboard's plan
    picker and by payment webhook amount-matching, same as CRM
    pricing above.
</p>

<?php
$ofp_listing_prices = OFP_Property::get_plan_prices();
$ofp_listing_caps   = OFP_Property::get_plan_caps();
$ofp_listing_labels = [ 'bronze' => 'Bronze', 'silver' => 'Silver', 'gold' => 'Gold' ];
?>

<form method="post" action="">
    <?php wp_nonce_field( 'ofp_save_listing_plans_action', 'ofp_listing_plans_nonce' ); ?>

    <table class="form-table" role="presentation">
        <?php foreach ( OFP_Property::PLAN_KEYS as $ofp_lp ) : ?>
            <tr>
                <th scope="row"><?php echo esc_html( $ofp_listing_labels[ $ofp_lp ] ); ?></th>
                <td>
                    <label style="margin-right:24px;">
                        Monthly price (NGN)
                        <input type="number" step="0.01" min="0"
                               name="listing_price_<?php echo esc_attr( $ofp_lp ); ?>"
                               value="<?php echo esc_attr( $ofp_listing_prices[ $ofp_lp ] ); ?>"
                               style="width:140px;">
                    </label>
                    <label>
                        Property cap
                        <input type="number" step="1" min="1"
                               name="listing_cap_<?php echo esc_attr( $ofp_lp ); ?>"
                               value="<?php echo esc_attr( $ofp_listing_caps[ $ofp_lp ] ); ?>"
                               style="width:80px;">
                    </label>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <p class="submit">
        <button type="submit" name="ofp_save_listing_plans" value="1" class="button button-primary">
            Save Listing Plans
        </button>
    </p>
</form>
```

Variables prefixed `$ofp_lp`/`$ofp_listing_` to avoid colliding with
the `$ofp_plan_*` variables Phase 11's Plans & Pricing block already
uses on this same page.
