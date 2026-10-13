<?php
/**
 * Tab bar shared by the client Sales pages.
 * Set $ofp_sales_tab to 'sales', 'offer' or 'purchase' before including.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
$ofp_sales_tab = $ofp_sales_tab ?? 'sales';
$ofp_tabs = [
    'sales'    => [ 'Sales Table',  home_url( '/property-sales' ) ],
    'offer'    => [ 'Create Offer', home_url( '/property-sales' ) . '#create-offer' ],
    'purchase' => [ 'Add Purchase', home_url( '/property-purchases' ) ],
];
?>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 24px;border-bottom:1px solid var(--border-color);padding-bottom:12px;">
    <?php foreach ( $ofp_tabs as $key => $tab ) :
        $on = ( $key === $ofp_sales_tab );
    ?>
        <a href="<?php echo esc_url( $tab[1] ); ?>"
           class="ofp-btn <?php echo $on ? 'ofp-btn-primary' : 'ofp-btn-secondary'; ?>"
           style="text-decoration:none;"><?php echo esc_html( $tab[0] ); ?></a>
    <?php endforeach; ?>
</div>
