<?php
/**
 * Shared public-page navigation (X5.03, D-42).
 *
 * ONE place for the top bar, the desktop mega menu, the mobile full-screen menu
 * and the dark backdrop. Pages: marketplace, single property, homepage, agents,
 * agent profile, blog (2), plans and pricing. Login and signup have NO header.
 *
 * Before including, a page may set (all optional):
 *   $ofp_nav_mode      'solid' (default) | 'glass' | 'blog' | 'overlay' | 'ios'
 *   $ofp_nav_active    '' | 'properties' | 'pricing' | 'map' | 'agents'  (which card shows "Active")
 *   $ofp_nav_dashboard true = the button says Dashboard (client is logged in), else List Property
 *
 * The page itself must still have <html x-data="{ megaMenuOpen: false }"> or a
 * parent with megaMenuOpen, plus Alpine and ofpToggleTheme() (same as before).
 * The enterprise copy simply leaves this include out.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$ofp_nav_mode      = isset( $ofp_nav_mode ) ? (string) $ofp_nav_mode : 'solid';
$ofp_nav_active    = isset( $ofp_nav_active ) ? (string) $ofp_nav_active : '';
$ofp_nav_dashboard = ! empty( $ofp_nav_dashboard );

// Style parts that differ between the page looks.
$ofp_nav_btn_solid_menu  = 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg px-2.5 py-1.5 text-slate-700 dark:text-slate-300 transition-colors flex items-center justify-center cursor-pointer border border-slate-200 dark:border-slate-700';
$ofp_nav_btn_solid_theme = 'p-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-all cursor-pointer';
$ofp_nav_btn_glass_menu  = 'rounded-lg px-2.5 py-1.5 transition-colors flex items-center justify-center cursor-pointer border bg-slate-900/5 hover:bg-slate-900/10 dark:bg-white/10 dark:hover:bg-white/20 backdrop-blur-md text-slate-900 dark:text-white border-slate-300/80 dark:border-white/20';
$ofp_nav_btn_glass_theme = 'p-2 rounded-xl border transition-all cursor-pointer border-slate-300/80 dark:border-white/20 bg-slate-900/5 hover:bg-slate-900/10 dark:bg-white/10 dark:hover:bg-white/20 backdrop-blur-md text-slate-900 dark:text-white';
$ofp_nav_hide_attrs      = 'x-data="{ scrolledDown: false, lastScroll: 0 }" @scroll.window="
                if (!megaMenuOpen) {
                    const current = window.pageYOffset;
                    if (current > lastScroll && current > 50) scrolledDown = true;
                    else if (current < lastScroll) scrolledDown = false;
                    lastScroll = current;
                }
            "';
$ofp_nav_hide_class      = ':class="scrolledDown ? \'-translate-y-full\' : \'translate-y-0\'"';

$ofp_nav_base = [
    'row'       => 'flex justify-between items-center h-16 transition-all duration-300',
    'logo_a'    => 'hidden sm:flex items-center',
    'logo_span' => 'text-[13px] sm:text-sm font-black tracking-[0.25em] text-slate-900 dark:text-white uppercase transition-colors',
    'btn_menu'  => $ofp_nav_btn_solid_menu,
    'btn_theme' => $ofp_nav_btn_solid_theme,
    'sun'       => 'w-4 h-4 text-amber-400 theme-toggle-sun',
];
$ofp_nav_glass_logo = 'text-[13px] sm:text-sm font-black tracking-[0.25em] uppercase transition-colors text-slate-900 dark:text-white drop-shadow-sm';
$ofp_nav_glass_sun  = 'w-4 h-4 text-amber-500 dark:text-amber-400 theme-toggle-sun';

$ofp_nav_modes = [
    // marketplace, single property: sticky bar that hides when scrolling down.
    'solid'   => [ 'wrapper' => '<header ' . $ofp_nav_hide_attrs . ' class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 transition-transform duration-300" ' . $ofp_nav_hide_class . '>' ],
    // agents, agent profile: sticky frosted bar, always visible.
    'glass'   => [ 'wrapper' => '<header class="sticky top-0 z-50 bg-white/70 dark:bg-slate-900/70 backdrop-blur-2xl backdrop-saturate-150 border-b border-slate-200/50 dark:border-white/10 shadow-[0_4px_30px_rgba(0,0,0,0.03)] transition-all duration-300">' ],
    // blog list and blog post: fixed bar over the page top.
    'blog'    => [ 'wrapper' => '<header ' . $ofp_nav_hide_attrs . ' class="fixed top-0 left-0 w-full z-50 bg-white/90 dark:bg-slate-950/90 backdrop-blur-md border-b border-slate-200/80 dark:border-slate-800/80 transition-transform duration-300" ' . $ofp_nav_hide_class . '>',
                   'logo_a' => 'flex items-center', 'logo_span' => $ofp_nav_glass_logo, 'btn_menu' => $ofp_nav_btn_glass_menu, 'btn_theme' => $ofp_nav_btn_glass_theme, 'sun' => $ofp_nav_glass_sun ],
    // homepage: transparent bar over the hero picture.
    'overlay' => [ 'wrapper' => '<header ' . $ofp_nav_hide_attrs . ' class="fixed top-0 left-0 w-full z-50 bg-transparent transition-transform duration-300" ' . $ofp_nav_hide_class . '>',
                   'row' => 'flex justify-between items-center h-16 transition-all duration-300 mt-2', 'logo_span' => $ofp_nav_glass_logo, 'btn_menu' => $ofp_nav_btn_glass_menu, 'btn_theme' => $ofp_nav_btn_glass_theme, 'sun' => $ofp_nav_glass_sun ],
    // plans and pricing: very faint at the top, frosted after scrolling.
    'ios'     => [ 'wrapper' => '<header x-data="{ isScrolled: window.pageYOffset > 20 }" @scroll.window="isScrolled = (window.pageYOffset > 20)" class="fixed top-0 left-0 w-full z-50 transition-all duration-300 ofp-ios-glass-nav ofp-nav-top" :class="isScrolled ? \'ofp-nav-scrolled\' : \'ofp-nav-top\'">',
                   'row' => 'flex justify-between items-center h-16 sm:h-20 transition-all duration-300', 'btn_menu' => $ofp_nav_btn_glass_menu, 'btn_theme' => $ofp_nav_btn_glass_theme ],
];
$ofp_nav_cfg = array_merge( $ofp_nav_base, $ofp_nav_modes[ $ofp_nav_mode ] ?? $ofp_nav_modes['solid'] );

// The four cards of the mega menu (links and pictures live ONLY here).
$ofp_nav_cards = [
    [ 'key' => 'properties', 'path' => '/marketplace/',   'label' => 'PROPERTIES',        'alt' => 'Properties',        'img' => 'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=600&q=80' ],
    [ 'key' => 'pricing',    'path' => '/plans-pricing',  'label' => 'PLANS & PRICING',   'alt' => 'Plans & Pricing',   'img' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=600&q=80' ],
    [ 'key' => 'map',        'path' => '/marketplace/',   'label' => 'MAP SEARCH',        'alt' => 'Map Search',        'img' => '' ],
    [ 'key' => 'agents',     'path' => '/agents',         'label' => 'OUR AGENTS',        'alt' => 'Agents',            'img' => 'https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=600&q=80' ],
];
?>
    <div id="ofp-megamenu-backdrop" x-show="megaMenuOpen" class="hidden lg:block fixed inset-0 bg-slate-900/20 backdrop-blur-md z-40"
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;"
        @click="megaMenuOpen = false"></div>

    <!-- Top Navigation -->
    <?php echo $ofp_nav_cfg['wrapper']; // fixed markup from this file, see $ofp_nav_modes ?>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="<?php echo esc_attr( $ofp_nav_cfg['row'] ); ?>">
                <!-- Left: Logo & Hamburger -->
                <div class="flex items-center gap-3 sm:gap-5">
                    <!-- Logo (Hidden on mobile so only hamburger displays, visible from sm up) -->
                    <a href="<?php echo esc_url( home_url('/') ); ?>" class="<?php echo esc_attr( $ofp_nav_cfg['logo_a'] ); ?>">
                        <span
                            class="<?php echo esc_attr( $ofp_nav_cfg['logo_span'] ); ?>">
                            <?php echo OFP_Brand::public_logo_html( 'BOFAST HOMES' ); ?>
                        </span>
                    </a>

                    
                    <!-- Mega Menu Hamburger Button (Reduced size) -->
                    <button type="button" @click.stop="megaMenuOpen = !megaMenuOpen" aria-label="Toggle Navigation Menu"
                        class="<?php echo esc_attr( $ofp_nav_cfg['btn_menu'] ); ?>">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path x-show="!megaMenuOpen" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"></path>
                            <path x-show="megaMenuOpen" style="display: none;" stroke-linecap="round"
                                stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                    
                </div>

                <!-- Right: Action Button -->
                <div class="flex items-center gap-2 sm:gap-4">
                    <button type="button" onclick="ofpToggleTheme()" aria-label="Toggle Dark Mode"
                        class="<?php echo esc_attr( $ofp_nav_cfg['btn_theme'] ); ?>">
                        <svg class="w-4 h-4 theme-toggle-moon" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z">
                            </path>
                        </svg>
                        <svg class="<?php echo esc_attr( $ofp_nav_cfg['sun'] ); ?>" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z">
                            </path>
                        </svg>
                    </button>
                    
                    <!-- List Property / Dashboard Button -->
                    <a href="<?php echo esc_url( home_url( $ofp_nav_dashboard ? '/dashboard' : '/login' ) ); ?>"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-5 sm:px-7 py-2 sm:py-2.5 rounded-[7px] text-[11px] sm:text-xs tracking-wider shadow-lg shadow-blue-500/20 transition-all cursor-pointer whitespace-nowrap uppercase inline-flex items-center justify-center">
                        <?php echo $ofp_nav_dashboard ? 'Dashboard' : 'List Property'; ?>
                    </a>
                    
                </div>
            </div>

            <?php $ofp_header_social = OFP_Brand::social_links(); ?>

            
            <!-- Desktop Mega Menu Dropdown (hidden lg:block) -->
            <div id="ofp-desktop-megamenu" x-show="megaMenuOpen" style="display: none;" @click.away="megaMenuOpen = false"
                class="hidden lg:block absolute top-full left-4 right-4 sm:left-6 sm:right-6 lg:left-8 lg:right-8 mt-2 z-[100] bg-white/95 dark:bg-slate-900/95 backdrop-blur-2xl border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl overflow-hidden font-sans"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 -translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-4">

                <!-- Main Content Area -->
                <div class="p-8 grid grid-cols-4 gap-8">
                    <!-- Image Cards Area (Takes 3 columns) -->
                    <div class="col-span-3 grid grid-cols-4 gap-4">

                        <?php foreach ( $ofp_nav_cards as $ofp_card ) :
                            $ofp_is_active = ( $ofp_nav_active === $ofp_card['key'] );
                            $ofp_card_bg   = ( 'map' === $ofp_card['key'] ) ? 'bg-blue-50 dark:bg-blue-900/20' : 'bg-slate-100 dark:bg-slate-800';
                            $ofp_card_cls  = $ofp_is_active
                                ? 'border-2 border-blue-500 shadow-lg shadow-blue-500/20 ' . $ofp_card_bg
                                : 'border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors ' . $ofp_card_bg;
                        ?>
                        <a href="<?php echo esc_url( home_url( $ofp_card['path'] ) ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square <?php echo esc_attr( $ofp_card_cls ); ?>">
                            <?php if ( ! empty( $ofp_card['img'] ) ) : ?>
                                <img src="<?php echo esc_url( $ofp_card['img'] ); ?>" alt="<?php echo esc_attr( $ofp_card['alt'] ); ?>"
                                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                                <div class="absolute inset-0 bg-gradient-to-t <?php echo $ofp_is_active ? 'from-black/85 via-black/30' : 'from-black/80 via-black/20'; ?> to-transparent"></div>
                            <?php else : ?>
                                <div class="absolute inset-0 bg-blue-500/10 group-hover:bg-blue-500/20 transition-colors z-10"
                                    style="background-image: radial-gradient(circle, #3B82F6 1px, transparent 1px); background-size: 20px 20px;"></div>
                                <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent z-20"></div>
                            <?php endif; ?>
                            <?php if ( $ofp_is_active ) : ?>
                                <div class="absolute top-3 right-3 px-2 py-0.5 rounded-full bg-blue-600 text-white text-[10px] font-black uppercase tracking-wider z-30">Active</div>
                            <?php endif; ?>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide z-30 <?php echo $ofp_is_active ? 'text-blue-400' : 'group-hover:text-blue-400 transition-colors'; ?>">
                                <?php echo esc_html( $ofp_card['label'] ); ?></div>
                        </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Side Links (Takes 1 column) -->
                    <div class="flex flex-col justify-between h-full pl-8 border-l border-slate-200 dark:border-slate-800">
                        <div class="space-y-6">
                            <ul class="space-y-4">
                                <li><a href="<?php echo esc_url( home_url('/login') ); ?>"
                                        class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-xs tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                            class="w-2 h-2 rounded-full bg-blue-500"></span> BECOME A PARTNER</a></li>
                                <li><a href="<?php echo esc_url( home_url('/blog') ); ?>"
                                        class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-xs tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                            class="w-2 h-2 rounded-full bg-blue-500"></span> BLOG</a></li>
                                <li><a href="<?php echo esc_url( home_url('/careers') ); ?>"
                                        class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-xs tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                            class="w-2 h-2 rounded-full bg-blue-500"></span> CAREERS</a></li>
                            </ul>

                            <!-- Under Careers, Above Privacy Policy: Social Media -->
                            <?php if ( ! empty( $ofp_header_social ) ) : ?>
                                <div class="pt-4 border-t border-slate-200 dark:border-slate-800">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <?php foreach ( $ofp_header_social as $sm ) : ?>
                                            <a href="<?php echo esc_url( $sm['url'] ); ?>" target="_blank" rel="noopener noreferrer"
                                               aria-label="<?php echo esc_attr( $sm['label'] ); ?>" title="<?php echo esc_attr( $sm['label'] ); ?>"
                                               class="p-1 rounded text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                                                <?php echo $sm['svg']; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Privacy Policy (Below Social Media) -->
                        <div class="pt-4 border-t border-slate-200 dark:border-slate-800">
                            <a href="<?php echo esc_url( home_url('/privacy-policy/') ); ?>"
                                class="text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 text-xs font-bold tracking-widest transition-colors uppercase whitespace-nowrap">PRIVACY POLICY</a>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </header>

    
    <!-- Mobile Full-Page Navigation Screen (lg:hidden, placed outside <header> so transform doesn't clip/break fixed inset-0) -->
    <div id="ofp-mobile-megamenu" x-show="megaMenuOpen" style="display: none;"
        class="lg:hidden fixed inset-0 z-[100] bg-white/98 dark:bg-slate-950/98 backdrop-blur-2xl flex flex-col justify-between p-4 sm:p-6 font-sans overflow-y-auto"
        x-transition:enter="transform transition-transform ease-in-out duration-500"
        x-transition:enter-start="-translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transform transition-transform ease-in-out duration-500"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="-translate-y-full">

        <!-- Mobile Top Bar: Brand Logo & Close Button -->
        <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800/80 shrink-0">
            <a href="<?php echo esc_url( home_url('/') ); ?>" class="flex items-center">
                <span class="text-xs sm:text-sm font-black tracking-[0.25em] text-slate-900 dark:text-white uppercase">
                    <?php echo OFP_Brand::public_logo_html( 'BOFAST HOMES' ); ?>
                </span>
            </a>
            <button type="button" @click="megaMenuOpen = false"
                class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Mobile Mid: 2x2 Visual Cards Grid (Compact, no tedious scrolling) -->
        <div class="my-auto py-2 space-y-3">
            <div class="grid grid-cols-2 gap-2.5">
                <!-- Card 1: Properties -->
                <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                    class="group relative h-20 sm:h-24 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-900">
                    <img src="https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=400&q=80"
                        alt="Properties" class="w-full h-full object-cover opacity-60 group-hover:scale-105 transition-transform duration-300">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-transparent"></div>
                    <div class="absolute bottom-2.5 left-2.5 text-white font-black text-xs sm:text-sm uppercase tracking-wider group-hover:text-blue-400 transition-colors">
                        Properties
                    </div>
                </a>

                <!-- Card 2: Plans & Pricing -->
                <a href="<?php echo esc_url( home_url('/plans-pricing') ); ?>"
                    class="group relative h-20 sm:h-24 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-900">
                    <img src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=400&q=80"
                        alt="Plans &amp; Pricing" class="w-full h-full object-cover opacity-60 group-hover:scale-105 transition-transform duration-300">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-transparent"></div>
                    <div class="absolute bottom-2.5 left-2.5 text-white font-black text-xs sm:text-sm uppercase tracking-wider group-hover:text-blue-400 transition-colors">
                        Plans &amp; Pricing
                    </div>
                </a>

                <!-- Card 3: Map Search -->
                <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                    class="group relative h-20 sm:h-24 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-blue-950">
                    <div class="absolute inset-0 bg-blue-500/10" style="background-image: radial-gradient(circle, #3B82F6 1px, transparent 1px); background-size: 16px 16px;"></div>
                    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-transparent"></div>
                    <div class="absolute bottom-2.5 left-2.5 text-white font-black text-xs sm:text-sm uppercase tracking-wider group-hover:text-blue-400 transition-colors">
                        Map Search
                    </div>
                </a>

                <!-- Card 4: Our Agents -->
                <a href="<?php echo esc_url( home_url('/agents') ); ?>"
                    class="group relative h-20 sm:h-24 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-900">
                    <img src="https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=400&q=80"
                        alt="Agents" class="w-full h-full object-cover opacity-60 group-hover:scale-105 transition-transform duration-300">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-transparent"></div>
                    <div class="absolute bottom-2.5 left-2.5 text-white font-black text-xs sm:text-sm uppercase tracking-wider group-hover:text-blue-400 transition-colors">
                        Our Agents
                    </div>
                </a>
            </div>

            <!-- Secondary List Links (Arrows removed, line height increased) -->
            <div class="py-1 divide-y divide-slate-100 dark:divide-slate-800/80">
                <a href="<?php echo esc_url( home_url('/login') ); ?>"
                    class="py-3 sm:py-3.5 flex items-center text-slate-800 dark:text-slate-200 font-bold text-xs uppercase tracking-wider hover:text-blue-600 transition-colors">
                    <span class="flex items-center gap-2.5">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                        Become a Partner
                    </span>
                </a>
                <a href="<?php echo esc_url( home_url('/blog') ); ?>"
                    class="py-3 sm:py-3.5 flex items-center text-slate-800 dark:text-slate-200 font-bold text-xs uppercase tracking-wider hover:text-blue-600 transition-colors">
                    <span class="flex items-center gap-2.5">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                        Blog
                    </span>
                </a>
                <a href="<?php echo esc_url( home_url('/careers') ); ?>"
                    class="py-3 sm:py-3.5 flex items-center text-slate-800 dark:text-slate-200 font-bold text-xs uppercase tracking-wider hover:text-blue-600 transition-colors">
                    <span class="flex items-center gap-2.5">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                        Careers
                    </span>
                </a>
            </div>

            <!-- Under Careers: Social Media channels (above Privacy Policy) -->
            <?php if ( ! empty( $ofp_header_social ) ) : ?>
                <div class="pt-3 pb-1 border-t border-slate-100 dark:border-slate-800/80">
                    <div class="flex items-center gap-3 flex-wrap">
                        <?php foreach ( $ofp_header_social as $sm ) : ?>
                            <a href="<?php echo esc_url( $sm['url'] ); ?>" target="_blank" rel="noopener noreferrer"
                               aria-label="<?php echo esc_attr( $sm['label'] ); ?>" title="<?php echo esc_attr( $sm['label'] ); ?>"
                               class="p-1 rounded text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                                <?php echo $sm['svg']; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Privacy Policy (Below Social Media) -->
            <div class="pt-2">
                <a href="<?php echo esc_url( home_url('/privacy-policy/') ); ?>"
                    class="text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 text-[11px] font-bold tracking-widest uppercase transition-colors inline-block">
                    Privacy Policy
                </a>
            </div>
        </div>

        <!-- Mobile Bottom Action Row -->
        <div class="pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center gap-3 shrink-0">
            <button type="button" onclick="ofpToggleTheme()" aria-label="Toggle Dark Mode"
                class="p-3 rounded-[7px] border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-all cursor-pointer">
                <svg class="w-5 h-5 theme-toggle-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                </svg>
                <svg class="w-5 h-5 text-amber-400 theme-toggle-sun" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
            </button>
            <a href="<?php echo esc_url( home_url( $ofp_nav_dashboard ? '/dashboard' : '/login' ) ); ?>"
                class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 px-6 rounded-[7px] text-center text-xs tracking-wider shadow-lg shadow-blue-500/20 transition-all uppercase">
                <?php echo $ofp_nav_dashboard ? 'Dashboard' : 'List Property'; ?>
            </a>
        </div>
    </div>
