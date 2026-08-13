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

        $items = [
            [ 'url' => home_url( '/properties' ),        'label' => 'My Properties',        'marker' => 'properties' ],
            [ 'url' => home_url( '/property-sales' ),   'label' => 'Sales & Installments', 'marker' => 'sales' ],
            [ 'url' => home_url( '/property-purchases' ), 'label' => 'Purchases',          'marker' => 'purchases' ],
            [ 'url' => home_url( '/listing-billing' ), 'label' => 'Listing Billing',      'marker' => 'listing-billing' ],
        ];

        $items_js = wp_json_encode( $items );
        ?>
        <script>
        (function () {
            var items = <?php echo $items_js; ?>;

            function addStandaloneItems() {
                var navList = document.querySelector('.ofp-sidebar-nav .ofp-nav-group ul');
                if (!navList) return;

                var template = navList.querySelector('li');
                if (!template) return;

                items.forEach(function (item) {
                    if (navList.querySelector('[data-ofp-nav-marker="' + item.marker + '"]')) return;

                    var li = template.cloneNode(true);
                    var link = li.querySelector('a');
                    if (!link) return;

                    link.href = item.url;
                    link.setAttribute('data-ofp-nav-marker', item.marker);
                    link.classList.remove('active', 'locked');

                    var label = link.querySelector('.ofp-nav-label');
                    if (label) label.textContent = item.label;

                    var badge = link.querySelector('.ofp-nav-badge');
                    if (badge) badge.remove();

                    var icon = link.querySelector('.ofp-nav-icon');
                    if (icon) icon.textContent = '';

                    navList.appendChild(li);
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', addStandaloneItems);
            } else {
                addStandaloneItems();
            }
        })();
        </script>
        <?php
    }
}
