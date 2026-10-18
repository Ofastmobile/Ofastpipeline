<?php
/**
 * Tab bar shared across the Sales & Installments suite.
 * Tabs:
 * - 'sales': Active Deals & Sales Portfolio Table
 * - 'offer': Create Installment Offer Form
 * - 'purchase': Add Purchase (Manual)
 * - 'payments': Transactions Ledger
 *
 * Set $ofp_sales_tab before including.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$ofp_sales_tab = $ofp_sales_tab ?? 'sales';

$ofp_tabs = [
    'sales' => [
        'label' => 'Sales & Deals',
        'url'   => home_url( '/property-sales' ),
    ],
    'offer' => [
        'label' => 'Create Installment Offer',
        'url'   => add_query_arg( 'tab', 'offer', home_url( '/property-sales' ) ),
    ],
    'purchase' => [
        'label' => 'Add Purchase (Manual)',
        'url'   => home_url( '/property-purchases' ),
    ],
    'payments' => [
        'label' => 'Transactions',
        'url'   => home_url( '/property-payments' ),
    ],
];
?>
<div class="ofp-sales-tabs-nav" style="display:flex; border-bottom:1px solid var(--border-color); margin-bottom:24px; overflow-x:auto; gap:4px;">
    <?php foreach ( $ofp_tabs as $key => $tab ) :
        $is_active = ( $key === $ofp_sales_tab );
    ?>
        <a href="<?php echo esc_url( $tab['url'] ); ?>"
           class="ofp-tab-btn <?php echo $is_active ? 'active' : ''; ?>">
            <span><?php echo esc_html( $tab['label'] ); ?></span>
        </a>
    <?php endforeach; ?>
</div>

