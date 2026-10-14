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
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m1-3l1 3m6-3l1 3m-1-3l-1 3M3 10.5h18" /></svg>',
        'badge' => $ofp_deals_count ?? null,
    ],
    'offer' => [
        'label' => 'Create Installment Offer',
        'url'   => add_query_arg( 'tab', 'offer', home_url( '/property-sales' ) ),
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>',
    ],
    'purchase' => [
        'label' => 'Add Purchase (Manual)',
        'url'   => home_url( '/property-purchases' ),
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>',
    ],
    'payments' => [
        'label' => 'Transactions',
        'url'   => home_url( '/property-payments' ),
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 5.25h-15A2.25 2.25 0 002.25 7.5v9A2.25 2.25 0 004.5 18.75h15a2.25 2.25 0 002.25-2.25v-9A2.25 2.25 0 0019.5 5.25zM2.25 9h19.5M6 13.5h3" /></svg>',
    ],
];
?>
<div class="ofp-sales-tabs-nav" style="display:flex; border-bottom:1px solid var(--border-color); margin-bottom:24px; overflow-x:auto; gap:6px;">
    <?php foreach ( $ofp_tabs as $key => $tab ) :
        $is_active = ( $key === $ofp_sales_tab );
    ?>
        <a href="<?php echo esc_url( $tab['url'] ); ?>"
           class="ofp-tab-btn <?php echo $is_active ? 'active' : ''; ?>">
            <?php echo $tab['icon']; ?>
            <span><?php echo esc_html( $tab['label'] ); ?></span>
            <?php if ( ! empty( $tab['badge'] ) ) : ?>
                <span class="ofp-tab-badge"><?php echo esc_html( $tab['badge'] ); ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>
