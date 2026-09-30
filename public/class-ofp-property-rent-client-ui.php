<?php
/** Adds the Gold-gated Tenants workspace to the client portal navigation. */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Rent_Client_UI {
    public static function init(): void {
        add_action( 'wp_footer', [ __CLASS__, 'inject_nav_item' ], 999 );
    }

    public static function inject_nav_item(): void {
        if ( is_admin() || ! OFP_Auth::current_client() ) return;
        $client = OFP_Auth::current_client();
        $locked = ! OFP_Property_Rent::can_manage( (int) $client->id );
        $url = $locked ? home_url( '/pricing' ) : home_url( '/tenants' );
        ?>
        <script>
        (function(){
            function add(){
                var list=document.querySelector('.ofp-sidebar-nav ul');
                if(!list||list.querySelector('[data-ofp-nav-marker="tenants"]'))return;
                var source=Array.prototype.find.call(list.querySelectorAll(':scope > li'),function(li){var a=li.querySelector('a');return a&&/\/properties\/?$/.test(a.href);});
                if(!source)return;
                var item=source.cloneNode(true),link=item.querySelector('a'),label=link.querySelector('.ofp-nav-label'),icon=link.querySelector('.ofp-nav-icon');
                link.href=<?php echo wp_json_encode( $url ); ?>;link.setAttribute('data-ofp-nav-marker','tenants');
                if(<?php echo $locked ? 'true' : 'false'; ?>){link.classList.add('locked');link.setAttribute('aria-disabled','true');}else if(location.pathname.replace(/\/$/,'')==='/tenants'){link.classList.add('active');}
                if(label)label.textContent='Tenants';
                if(icon)icon.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.75c0-1.243-1.343-2.25-3-2.25s-3 1.007-3 2.25m6 0a2.25 2.25 0 01-2.25 2.25h-1.5A2.25 2.25 0 0112 18.75m6 0v-1.5a3 3 0 00-3-3h-1.5a3 3 0 00-3 3v1.5M12 9a3 3 0 100-6 3 3 0 000 6z" /></svg>';
                list.insertBefore(item,source.nextSibling);
            }
            document.readyState==='loading'?document.addEventListener('DOMContentLoaded',add):add();
        })();
        </script>
        <?php
    }
}
