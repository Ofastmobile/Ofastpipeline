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
           data-tab="<?php echo esc_attr( $key ); ?>"
           class="ofp-tab-btn <?php echo $is_active ? 'active' : ''; ?>">
            <span><?php echo esc_html( $tab['label'] ); ?></span>
        </a>
    <?php endforeach; ?>
</div>

<script>
(function() {
    if (window._ofpSalesTabsBound) return;
    window._ofpSalesTabsBound = true;

    document.addEventListener('click', function(e) {
        var tabLink = e.target.closest('.ofp-sales-tabs-nav .ofp-tab-btn');
        if (!tabLink) return;
        var tabKey = tabLink.getAttribute('data-tab');

        if (typeof window.switchSalesTab === 'function' && (tabKey === 'sales' || tabKey === 'offer')) {
            // Handled locally by property-sales.php pane switcher
            return;
        }

        var url = tabLink.getAttribute('href');
        if (!url || url.indexOf('#') === 0) return;

        var container = document.querySelector('.ofp-container') || document.querySelector('.ofp-content-area');
        if (!container) return;

        e.preventDefault();
        container.style.opacity = '0.5';

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(res) { return res.text(); })
            .then(function(html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var newContainer = doc.querySelector('.ofp-container') || doc.querySelector('.ofp-content-area');
                if (newContainer) {
                    container.innerHTML = newContainer.innerHTML;
                    container.style.opacity = '1';
                    if (doc.title) document.title = doc.title;
                    window.history.pushState({ url: url }, '', url);

                    // Re-run inline scripts inside container
                    var scripts = container.querySelectorAll('script');
                    scripts.forEach(function(oldScript) {
                        var newScript = document.createElement('script');
                        Array.from(oldScript.attributes).forEach(function(attr) {
                            newScript.setAttribute(attr.name, attr.value);
                        });
                        newScript.textContent = oldScript.textContent;
                        oldScript.parentNode.replaceChild(newScript, oldScript);
                    });
                } else {
                    window.location.href = url;
                }
            })
            .catch(function() {
                container.style.opacity = '1';
                window.location.href = url;
            });
    });
})();
</script>

