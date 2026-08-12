<?php
/**
 * Client-side property sales navigation helper.
 *
 * Keeps the established client sidebar template untouched while exposing the
 * property-sales flow to clients who have an active listing subscription.
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
        $sales_url_js   = wp_json_encode( $sales_url );
        $properties_js  = wp_json_encode( $properties_url );
        ?>
        <script>
        (function () {
            var salesUrl = <?php echo $sales_url_js; ?>;
            var propertiesUrl = <?php echo $properties_js; ?>;

            function addSalesLink() {
                var links = document.querySelectorAll('a[href="' + propertiesUrl.replace(/(["\\])/g, '\\$1') + '"]');
                if (!links.length) return;

                links.forEach(function (link) {
                    var host = link.closest('li') || link.parentElement;
                    if (!host || host.parentElement.querySelector('[data-ofp-property-sales="1"]')) return;

                    var item = host.cloneNode(true);
                    var target = item.querySelector('a');
                    if (!target) return;

                    target.href = salesUrl;
                    target.setAttribute('data-ofp-property-sales', '1');
                    target.innerHTML = target.innerHTML.replace(/My Properties/gi, 'Sales & Installments');
                    if (target.textContent.trim().toLowerCase() === 'my properties') {
                        target.textContent = 'Sales & Installments';
                    }

                    host.parentElement.insertBefore(item, host.nextSibling);
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', addSalesLink);
            } else {
                addSalesLink();
            }
        })();
        </script>
        <?php
    }
}
