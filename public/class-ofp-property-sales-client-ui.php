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
        ?>
        <script>
        (function () {
            var items = [
                {
                    url: <?php echo wp_json_encode( home_url( '/property-sales' ) ); ?>,
                    label: 'Sales & Installments',
                    marker: 'sales',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7.5 16.5l3-3 2.25 2.25L19.5 9" /></svg>'
                },
                {
                    url: <?php echo wp_json_encode( home_url( '/property-purchases' ) ); ?>,
                    label: 'Purchases',
                    marker: 'purchases',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75h19.5v12H2.25zM6 6.75V4.5h12v2.25M6.75 12h.008v.008H6.75V12zm3 0h.008v.008H9.75V12zm3 0h.008v.008h-.008V12z" /></svg>'
                },
                {
                    url: <?php echo wp_json_encode( home_url( '/property-payments' ) ); ?>,
                    label: 'Payment Records',
                    marker: 'payment-records',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 5.25h-15A2.25 2.25 0 002.25 7.5v9A2.25 2.25 0 004.5 18.75h15a2.25 2.25 0 002.25-2.25v-9A2.25 2.25 0 0019.5 5.25zM2.25 9h19.5M6 13.5h3" /></svg>'
                },
                {
                    url: <?php echo wp_json_encode( home_url( '/listing-billing' ) ); ?>,
                    label: 'Listing Billing',
                    marker: 'listing-billing',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zM7.5 12h.008v.008H7.5V12zm3 0h.008v.008h-.008V12z" /></svg>'
                }
            ];

            function navList() {
                return document.querySelector('.ofp-sidebar-nav ul');
            }

            function sidebarItems() {
                var list = navList();
                return list ? list.querySelectorAll(':scope > li') : [];
            }

            function findPropertiesItems() {
                var list = navList();
                if (!list) return [];
                return Array.prototype.filter.call(sidebarItems(), function (li) {
                    var a = li.querySelector('a');
                    return a && a.href.replace(/\/$/, '') === <?php echo wp_json_encode( rtrim( home_url( '/properties' ), '/' ) ); ?>;
                });
            }

            function removeDuplicateProperties() {
                var matches = findPropertiesItems();
                for (var i = 1; i < matches.length; i++) {
                    matches[i].remove();
                }
                return matches[0] || null;
            }

            function makeItem(source, item) {
                var clone = source.cloneNode(true);
                var link = clone.querySelector('a');
                if (!link) return null;

                link.href = item.url;
                link.removeAttribute('aria-disabled');
                link.classList.remove('locked');
                link.classList.remove('active');
                
                if (window.location.href.indexOf(item.url) !== -1) {
                    link.classList.add('active');
                }

                link.setAttribute('data-ofp-nav-marker', item.marker);

                var icon = link.querySelector('.ofp-nav-icon');
                if (icon) icon.innerHTML = item.icon;

                var label = link.querySelector('.ofp-nav-label');
                if (label) label.textContent = item.label;

                var badge = link.querySelector('.ofp-nav-badge');
                if (badge) badge.remove();

                return clone;
            }

            function addListingItems() {
                var propertiesItem = removeDuplicateProperties();
                var list = navList();
                if (!propertiesItem || !list) return;

                var cursor = propertiesItem.nextSibling;

                items.forEach(function (item) {
                    if (list.querySelector('[data-ofp-nav-marker="' + item.marker + '"]')) return;
                    var newItem = makeItem(propertiesItem, item);
                    if (!newItem) return;
                    list.insertBefore(newItem, cursor);
                });
            }

            function run() {
                addListingItems();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', run);
            } else {
                run();
            }
        })();
        </script>
        <?php
    }
}
