<?php
/**
 * Client-side property sales navigation helper.
 *
 * Exposes property-management and sales tools as standalone sidebar items
 * for clients with an active listing subscription.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Sales_Client_UI {

    public static function init(): void {
        add_action( 'wp_footer', [ __CLASS__, 'inject_sales_nav_item' ], 999 );
    }

    public static function inject_sales_nav_item(): void {
        if ( is_admin() || ! OFP_Auth::current_client() ) return;

        $client = OFP_Auth::current_client();
        if ( ! OFP_Subscription::has_active( 'listing', $client->id ) ) return;

        $properties_url = home_url( '/properties' );
        $sales_url      = home_url( '/property-sales' );
        $purchases_url  = home_url( '/property-purchases' );
        $billing_url    = home_url( '/listing-billing' );

        $properties_url_js = wp_json_encode( $properties_url );
        $sales_url_js      = wp_json_encode( $sales_url );
        $purchases_url_js  = wp_json_encode( $purchases_url );
        $billing_url_js    = wp_json_encode( $billing_url );
        ?>
        <script>
        (function () {
            var propertiesUrl = <?php echo $properties_url_js; ?>;
            var salesUrl = <?php echo $sales_url_js; ?>;
            var purchasesUrl = <?php echo $purchases_url_js; ?>;
            var billingUrl = <?php echo $billing_url_js; ?>;

            function escapeAttr(value) {
                return value.replace(/(["\\])/g, '\\$1');
            }

            function makeItem(sourceHost, targetUrl, label, marker) {
                var item = sourceHost.cloneNode(true);
                var target = item.querySelector('a');
                if (!target) return null;
                target.href = targetUrl;
                target.setAttribute('data-ofp-nav-marker', marker);
                target.textContent = label;
                return item;
            }

            function addNav() {
                var links = document.querySelectorAll('a[href="' + escapeAttr(propertiesUrl) + '"]');
                if (!links.length) return;

                links.forEach(function (link) {
                    var propertiesHost = link.closest('li') || link.parentElement;
                    if (!propertiesHost || !propertiesHost.parentElement) return;

                    var parent = propertiesHost.parentElement;

                    if (!parent.querySelector('[data-ofp-nav-marker="sales"]')) {
                        var salesItem = makeItem(propertiesHost, salesUrl, 'Sales & Installments', 'sales');
                        if (salesItem) parent.insertBefore(salesItem, propertiesHost.nextSibling);
                    }

                    if (!parent.querySelector('[data-ofp-nav-marker="purchases"]')) {
                        var purchasesItem = makeItem(propertiesHost, purchasesUrl, 'Purchases', 'purchases');
                        if (purchasesItem) {
                            var salesItem = parent.querySelector('[data-ofp-nav-marker="sales"]');
                            if (salesItem && salesItem.nextSibling) parent.insertBefore(purchasesItem, salesItem.nextSibling);
                            else parent.appendChild(purchasesItem);
                        }
                    }

                    if (!parent.querySelector('[data-ofp-nav-marker="listing-billing"]')) {
                        var billingItem = makeItem(propertiesHost, billingUrl, 'Listing Billing', 'listing-billing');
                        if (billingItem) {
                            var purchasesItem = parent.querySelector('[data-ofp-nav-marker="purchases"]');
                            if (purchasesItem && purchasesItem.nextSibling) parent.insertBefore(billingItem, purchasesItem.nextSibling);
                            else parent.appendChild(billingItem);
                        }
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', addNav);
            } else {
                addNav();
            }
        })();
        </script>
        <?php
    }
}
