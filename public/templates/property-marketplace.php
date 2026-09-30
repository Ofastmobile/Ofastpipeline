<?php
$current_listing_type = sanitize_text_field( $_GET['listing_type'] ?? 'all' );
$current_sort         = sanitize_text_field( $_GET['sort'] ?? 'featured' );
$current_verified     = ! empty( $_GET['verified'] );
$current_location     = sanitize_text_field( $_GET['location'] ?? '' );
$current_prop_type    = sanitize_text_field( $_GET['property_type'] ?? '' );
$current_beds         = sanitize_text_field( $_GET['beds'] ?? '' );
$current_price_max    = sanitize_text_field( $_GET['price_max'] ?? '' );
$current_search       = sanitize_text_field( $_GET['s'] ?? $_GET['q'] ?? '' );

// Setup WP_Query for marketplace
$paged = max( 1, (int) get_query_var( 'paged' ) );
$args = [
    'post_type'      => 'ofp_property',
    'post_status'    => 'publish',
    'posts_per_page' => 12,
    'paged'          => $paged,
    'meta_query'     => [],
];

// 1. Keyword search (title / location)
if ( ! empty( $current_search ) ) {
    $args['s'] = $current_search;
}

// 2. Listing type filter
if ( ! empty( $current_listing_type ) && $current_listing_type !== 'all' ) {
    $args['meta_query'][] = [
        'key'     => 'ofp_listing_type',
        'value'   => $current_listing_type,
        'compare' => '=',
    ];
}

// 3. Property type filter
if ( ! empty( $current_prop_type ) ) {
    $args['meta_query'][] = [
        'key'     => 'ofp_property_type',
        'value'   => $current_prop_type,
        'compare' => '=',
    ];
}

// 4. Location filter
if ( ! empty( $current_location ) ) {
    $args['meta_query'][] = [
        'key'     => 'ofp_location_text',
        'value'   => $current_location,
        'compare' => 'LIKE',
    ];
}

// 5. Bedrooms filter
if ( ! empty( $current_beds ) ) {
    $args['meta_query'][] = [
        'key'     => 'ofp_bedrooms',
        'value'   => (int) $current_beds,
        'compare' => '>=',
        'type'    => 'NUMERIC',
    ];
}

// 6. Price max filter
if ( ! empty( $current_price_max ) ) {
    $args['meta_query'][] = [
        'key'     => 'ofp_price',
        'value'   => (float) $current_price_max,
        'compare' => '<=',
        'type'    => 'NUMERIC',
    ];
}

// 7. Verified only filter
if ( $current_verified ) {
    $args['meta_query'][] = [
        'key'     => 'ofp_status',
        'value'   => 'live',
        'compare' => '=',
    ];
}

// 8. Sorting
switch ( $current_sort ) {
    case 'price_asc':
        $args['meta_key'] = 'ofp_price';
        $args['orderby']  = 'meta_value_num';
        $args['order']    = 'ASC';
        break;
    case 'price_desc':
        $args['meta_key'] = 'ofp_price';
        $args['orderby']  = 'meta_value_num';
        $args['order']    = 'DESC';
        break;
    case 'newest':
        $args['orderby'] = 'date';
        $args['order']   = 'DESC';
        break;
    case 'featured':
    default:
        $args['meta_key'] = 'ofp_is_featured';
        $args['orderby']  = [ 'meta_value_num' => 'DESC', 'date' => 'DESC' ];
        break;
}

if ( empty( $args['meta_query'] ) ) {
    unset( $args['meta_query'] );
}

$marketplace_query = new WP_Query( $args );
?>
<!DOCTYPE html>
<html lang="en"
    x-data="{
        mobileMenu: false,
        filterTab: '<?php echo esc_js($current_listing_type); ?>',
        megaMenuOpen: false,
        viewMode: 'grid',
        verifiedOnly: <?php echo $current_verified ? 'true' : 'false'; ?>,
        sortBy: '<?php echo esc_js($current_sort); ?>',
        filterOpen: false,
        darkMode: document.documentElement.classList.contains('dark'),
        toggleDark() { ofpToggleTheme(); }
    }">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_title('|', true, 'right'); ?></title>
    <script>
        (function() {
            var theme = localStorage.getItem('ofp_theme');
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (theme === 'dark' || (!theme && prefersDark)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();

        function ofpToggleTheme() {
            var html = document.documentElement;
            var isDark = html.classList.toggle('dark');
            try {
                localStorage.setItem('ofp_theme', isDark ? 'dark' : 'light');
            } catch (e) {}
            if (typeof tailwind !== 'undefined' && tailwind.config) {
                tailwind.config.darkMode = 'class';
            }
            if (window.Alpine) {
                document.querySelectorAll('[x-data]').forEach(function(el) {
                    try {
                        if (el._x_dataStack) {
                            el._x_dataStack.forEach(function(s) {
                                if (typeof s.darkMode !== 'undefined') s.darkMode = isDark;
                            });
                        }
                    } catch(err) {}
                });
            }
            window.dispatchEvent(new CustomEvent('ofp-theme-changed', { detail: { dark: isDark } }));
        }
        window.ofpToggleTheme = ofpToggleTheme;
        window.toggleDark = ofpToggleTheme;
    </script>
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'Arial', 'sans-serif'],
                        system: ['"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'Arial', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <style>
        body,
        .font-system,
        .font-sans {
            font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol" !important;
        }

        /* Hero background: white overlay on light mode, dark overlay on dark mode */
        .hero-bg {
            background-image: 
                linear-gradient(to bottom, rgba(255, 255, 255, 0.45) 0%, rgba(255, 255, 255, 0.65) 40%, rgba(255, 255, 255, 0.95) 85%, #ffffff 100%),
                url('https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=2000&q=85');
            background-size: cover;
            background-position: center;
            background-attachment: scroll !important;
        }

        html.dark .hero-bg {
            background-image: 
                linear-gradient(to bottom, rgba(11, 17, 32, 0.5) 0%, rgba(11, 17, 32, 0.8) 50%, rgba(2, 6, 23, 0.95) 100%),
                url('https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=2000&q=85');
        }
    </style>
    <?php wp_head(); ?>
    <script>
        if (typeof tailwind !== 'undefined') {
            tailwind.config = {
                darkMode: 'class',
                theme: {
                    extend: {
                        fontFamily: {
                            sans: ['"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'Arial', 'sans-serif'],
                            system: ['"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'Arial', 'sans-serif']
                        }
                    }
                }
            };
        }
    </script>
</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 transition-colors duration-300">

    <!-- Mega Menu Backdrop Overlay -->
    <div x-show="megaMenuOpen" class="fixed inset-0 bg-slate-900/20 backdrop-blur-md z-40"
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;"
        @click="megaMenuOpen = false"></div>

    <!-- Top Navigation -->
    <header x-data="{ scrolledDown: false, lastScroll: 0 }" @scroll.window="
                if (!megaMenuOpen) {
                    const current = window.pageYOffset;
                    if (current > lastScroll && current > 50) scrolledDown = true;
                    else if (current < lastScroll) scrolledDown = false;
                    lastScroll = current;
                }
            "
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 transition-transform duration-300"
        :class="scrolledDown ? '-translate-y-full' : 'translate-y-0'">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="flex justify-between items-center h-16 transition-all duration-300">
                <!-- Left: Logo & Hamburger -->
                <div class="flex items-center gap-3 sm:gap-5">
                    <!-- Logo (No icon, Uppercase, Small font) -->
                    <a href="<?php echo esc_url( home_url('/') ); ?>" class="flex items-center">
                        <span
                            class="text-[13px] sm:text-sm font-black tracking-[0.25em] text-slate-900 dark:text-white uppercase transition-colors">
                            BOFAST HOMES
                        </span>
                    </a>

                    <!-- Mega Menu Hamburger Button (Reduced size) -->
                    <button @click="megaMenuOpen = !megaMenuOpen"
                        class="bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg px-2.5 py-1.5 text-slate-700 dark:text-slate-300 transition-colors flex items-center justify-center cursor-pointer border border-slate-200 dark:border-slate-700">
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
                        class="p-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-all cursor-pointer">
                        <svg class="w-4 h-4 theme-toggle-moon" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z">
                            </path>
                        </svg>
                        <svg class="w-4 h-4 text-amber-400 theme-toggle-sun" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z">
                            </path>
                        </svg>
                    </button>
                    <!-- Post Property Button -->
                    <button
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 sm:px-6 py-1.5 sm:py-2 rounded-xl text-[11px] sm:text-xs tracking-wider shadow-lg shadow-blue-500/20 transition-all cursor-pointer whitespace-nowrap uppercase">
                        Post Property
                    </button>
                </div>
            </div>

            <!-- Mega Menu Dropdown (Boxed, not full width) -->
            <div x-show="megaMenuOpen" style="display: none;" @click.away="megaMenuOpen = false"
                class="absolute top-full left-4 right-4 sm:left-6 sm:right-6 lg:left-8 lg:right-8 mt-2 z-[100] bg-white/90 dark:bg-slate-900/90 backdrop-blur-2xl border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl overflow-hidden font-sans"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 -translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-4">

                <!-- Main Content Area -->
                <div class="p-5 sm:p-8 grid grid-cols-1 lg:grid-cols-4 gap-6 sm:gap-8">
                    <!-- Image Cards Area (Takes 3 columns) -->
                    <div class="lg:col-span-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

                        <!-- Card 1: Properties -->
                        <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-[4/3] sm:aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=600&q=80"
                                alt="Properties"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent">
                            </div>
                            <div
                                class="absolute bottom-4 left-4 text-white font-black text-lg sm:text-xl uppercase tracking-wide group-hover:text-blue-400 transition-colors">
                                PROPERTIES</div>
                        </a>

                        <!-- Card 2: Off-Plan -->
                        <a href="#"
                            class="group relative rounded-2xl overflow-hidden aspect-[4/3] sm:aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1503387762-592deb58ef4e?auto=format&fit=crop&w=600&q=80"
                                alt="Off-Plan"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent">
                            </div>
                            <div
                                class="absolute bottom-4 left-4 text-white font-black text-lg sm:text-xl uppercase tracking-wide group-hover:text-blue-400 transition-colors z-30">
                                OFF-PLAN</div>
                        </a>

                        <!-- Card 3: Map -->
                        <a href="#"
                            class="group relative rounded-2xl overflow-hidden aspect-[4/3] sm:aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-blue-50 dark:bg-blue-900/20">
                            <div class="absolute inset-0 bg-blue-500/10 group-hover:bg-blue-500/20 transition-colors z-10"
                                style="background-image: radial-gradient(circle, #3B82F6 1px, transparent 1px); background-size: 20px 20px;">
                            </div>
                            <div
                                class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent z-20">
                            </div>
                            <div
                                class="absolute bottom-4 left-4 text-white font-black text-lg sm:text-xl uppercase tracking-wide group-hover:text-blue-400 transition-colors z-30">
                                MAP SEARCH</div>
                        </a>

                        <!-- Card 4: Agents -->
                        <a href="#"
                            class="group relative rounded-2xl overflow-hidden aspect-[4/3] sm:aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=600&q=80"
                                alt="Agents"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent">
                            </div>
                            <div
                                class="absolute bottom-4 left-4 text-white font-black text-lg sm:text-xl uppercase tracking-wide group-hover:text-blue-400 transition-colors">
                                OUR AGENTS</div>
                        </a>
                    </div>

                    <!-- Side Links (Takes 1 column) -->
                    <div
                        class="flex flex-col justify-between h-full pt-4 lg:pt-0 lg:pl-8 lg:border-l border-slate-200 dark:border-slate-800">
                        <ul class="space-y-4">
                            <li><a href="#"
                                    class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-[11px] sm:text-sm tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                        class="w-2 h-2 rounded-full bg-blue-500"></span> BECOME A PARTNER</a></li>
                            <li><a href="#"
                                    class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-[11px] sm:text-sm tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                        class="w-2 h-2 rounded-full bg-blue-500"></span> DEVELOPERS HUB</a></li>
                            <li><a href="#"
                                    class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-[11px] sm:text-sm tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                        class="w-2 h-2 rounded-full bg-blue-500"></span> CAREERS</a></li>
                        </ul>
                        <div class="mt-8 lg:mt-0 pt-4 border-t border-slate-200 dark:border-slate-800 lg:border-none">
                            <a href="#"
                                class="text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 text-[10px] sm:text-xs font-bold tracking-widest transition-colors uppercase">PRIVACY
                                POLICY</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Banner Section -->
    <div class="hero-bg relative overflow-hidden border-b border-slate-200 dark:border-slate-800 pt-12 pb-10 sm:pt-16 sm:pb-12 transition-colors duration-300">
        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6">

            <div
                class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-slate-800 dark:text-white mb-5 bg-white/90 dark:bg-slate-900/80 border border-slate-200 dark:border-white/20 backdrop-blur-md px-3.5 py-1.5 rounded-full shadow-sm dark:shadow-lg">
                <svg class="w-3.5 h-3.5 text-emerald-500 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>Verified Direct Listings</span>
                <span class="text-slate-300 dark:text-white/40">|</span>
                <span class="text-slate-500 dark:text-slate-300 text-[11px] font-medium lowercase">0% brokerage markup</span>
            </div>

            <h1
                class="text-3xl sm:text-5xl lg:text-6xl font-black tracking-tight text-slate-950 dark:text-white mb-4 leading-[1.15] drop-shadow-sm dark:drop-shadow-md">
                Nigeria's Premier Real Estate <br class="hidden sm:block">
                <span class="text-blue-600 dark:text-blue-500">
                    Marketplace
                </span>
            </h1>
            <p class="text-slate-700 dark:text-slate-300 mb-8 max-w-2xl text-sm sm:text-base leading-relaxed font-medium">
                Discover luxury duplexes, serviced shortlet apartments, prime commercial buildings, and verified title
                lands across Lagos, Abuja, and Port Harcourt.
            </p>

            <!-- Advanced Filter Bar -->
            <div
                class="bg-white/95 dark:bg-[#0B132B]/95 backdrop-blur-xl rounded-3xl p-5 sm:p-6 border border-slate-200 dark:border-slate-800 shadow-2xl flex flex-col gap-4 text-slate-900 dark:text-white">

                <!-- Row 1: Tabs + Search + Mobile Toggle -->
                <div
                    class="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4">
                    <div class="flex overflow-x-auto hide-scrollbar gap-2 w-full lg:w-auto">
                        <button type="button" @click="filterTab = 'all'; verifiedOnly = false; $nextTick(() => $refs.filterForm.submit())"
                            :class="filterTab === 'all' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700'"
                            class="px-4 py-2 rounded-xl text-sm font-bold whitespace-nowrap transition-colors cursor-pointer">
                            All Listings
                        </button>
                        <button type="button" @click="filterTab = 'sale'; $nextTick(() => $refs.filterForm.submit())"
                            :class="filterTab === 'sale' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700'"
                            class="px-4 py-2 rounded-xl text-sm font-bold whitespace-nowrap transition-colors cursor-pointer">
                            For Sale
                        </button>
                        <button type="button" @click="filterTab = 'rent'; $nextTick(() => $refs.filterForm.submit())"
                            :class="filterTab === 'rent' ? 'bg-emerald-600 text-white' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700'"
                            class="px-4 py-2 rounded-xl text-sm font-bold whitespace-nowrap transition-colors cursor-pointer">
                            For Rent
                        </button>
                        <button type="button" @click="filterTab = 'shortlet'; $nextTick(() => $refs.filterForm.submit())"
                            :class="filterTab === 'shortlet' ? 'bg-amber-600 text-white' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700'"
                            class="px-4 py-2 rounded-xl text-sm font-bold whitespace-nowrap transition-colors cursor-pointer">
                            Short Lets
                        </button>
                        <button type="button" @click="filterTab = 'commercial'; $nextTick(() => $refs.filterForm.submit())"
                            :class="filterTab === 'commercial' ? 'bg-purple-600 text-white' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700'"
                            class="px-4 py-2 rounded-xl text-sm font-bold whitespace-nowrap transition-colors cursor-pointer">
                            Commercial
                        </button>
                    </div>

                    <div class="flex items-center gap-2 w-full lg:w-auto">
                        <div class="w-full lg:w-80 relative flex-1">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </div>
                            <input type="text" name="s" value="<?php echo esc_attr( $current_search ); ?>" placeholder="Search location, estate, title..." class="w-full pl-10 pr-4 py-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all" onkeydown="if(event.key==='Enter'){event.preventDefault();$refs.filterForm.submit();}">
                        </div>
                        <!-- Mobile Filter Toggle -->
                        <button @click="filterOpen = !filterOpen"
                            class="lg:hidden flex items-center gap-1.5 px-3 py-2.5 bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl text-slate-700 dark:text-slate-300 text-sm font-semibold transition-colors shrink-0 cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z">
                                </path>
                            </svg>
                            Filters
                            <svg :class="filterOpen ? 'rotate-180' : ''" class="w-3.5 h-3.5 transition-transform"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Row 2: Detailed Filters (always visible on desktop, collapsible on mobile) -->
                <div :class="filterOpen ? 'flex' : 'hidden lg:flex'" class="flex-col lg:flex-row gap-4 items-end">
                    <div class="w-full lg:w-1/4">
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 ml-1">Location</label>
                        <select name="location" onchange="this.form.submit()"
                            class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none cursor-pointer">
                            <option value="">All Locations</option>
                            <?php
                            $locations = [ 'Lekki', 'Lekki Phase 1', 'Ikoyi', 'Victoria Island', 'Maitama', 'Ikeja', 'Banana Island', 'Abuja' ];
                            foreach ( $locations as $loc ) :
                            ?>
                                <option value="<?php echo esc_attr( $loc ); ?>" <?php selected( $current_location, $loc ); ?>><?php echo esc_html( $loc ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="w-full lg:w-1/4">
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 ml-1">Property Type</label>
                        <select name="property_type" onchange="this.form.submit()"
                            class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none cursor-pointer">
                            <option value="">All Types</option>
                            <?php
                            $ptypes = [
                                'apartment'     => 'Apartment',
                                'duplex'        => 'Duplex',
                                'semi-detached' => 'Semi-Detached',
                                'bungalow'      => 'Bungalow',
                                'terrace'       => 'Terrace',
                                'land'          => 'Land / Plot',
                                'office'        => 'Commercial Office',
                            ];
                            foreach ( $ptypes as $k => $l ) :
                            ?>
                                <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $current_prop_type, $k ); ?>><?php echo esc_html( $l ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="w-full lg:w-1/4">
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 ml-1">Bedrooms</label>
                        <select name="beds" onchange="this.form.submit()"
                            class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none cursor-pointer">
                            <option value="">Any Bedrooms</option>
                            <option value="1" <?php selected( $current_beds, '1' ); ?>>1+ Bed</option>
                            <option value="2" <?php selected( $current_beds, '2' ); ?>>2+ Beds</option>
                            <option value="3" <?php selected( $current_beds, '3' ); ?>>3+ Beds</option>
                            <option value="4" <?php selected( $current_beds, '4' ); ?>>4+ Beds</option>
                        </select>
                    </div>
                    <div class="w-full lg:w-1/4">
                        <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 ml-1">Price Cap</label>
                        <select name="price_max" onchange="this.form.submit()"
                            class="w-full px-4 py-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none cursor-pointer">
                            <option value="">Any Price</option>
                            <option value="50000000" <?php selected( $current_price_max, '50000000' ); ?>>Under ₦50M</option>
                            <option value="150000000" <?php selected( $current_price_max, '150000000' ); ?>>Under ₦150M</option>
                            <option value="300000000" <?php selected( $current_price_max, '300000000' ); ?>>Under ₦300M</option>
                            <option value="500000000" <?php selected( $current_price_max, '500000000' ); ?>>Under ₦500M</option>
                        </select>
                    </div>

                    <div class="flex items-center gap-4 w-full lg:w-auto">
                        <label class="flex items-center gap-2 cursor-pointer whitespace-nowrap">
                            <input type="checkbox" name="verified" value="1" <?php checked( $current_verified ); ?> x-model="verifiedOnly" @change="$nextTick(() => $refs.filterForm.submit())" class="w-4 h-4 text-emerald-500 rounded border-slate-300 focus:ring-emerald-500">
                            <span class="text-sm font-bold text-slate-700 dark:text-slate-300">Verified Only</span>
                        </label>

                        <!-- View Toggle: hidden on mobile -->
                        <div
                            class="hidden sm:flex items-center gap-1 bg-slate-100 dark:bg-slate-800 p-1 rounded-xl shrink-0 border border-slate-200 dark:border-slate-700/60">
                            <button type="button" @click="viewMode = 'grid'"
                                :class="viewMode === 'grid' ? 'bg-white dark:bg-slate-700 shadow-sm text-blue-600 dark:text-blue-400 font-bold' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                                class="p-2 rounded-lg transition-all cursor-pointer flex items-center gap-1.5 text-xs"
                                title="Grid View">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z">
                                    </path>
                                </svg>
                                <span class="hidden sm:inline">Grid</span>
                            </button>
                            <button type="button" @click="viewMode = 'list'"
                                :class="viewMode === 'list' ? 'bg-white dark:bg-slate-700 shadow-sm text-blue-600 dark:text-blue-400 font-bold' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                                class="p-2 rounded-lg transition-all cursor-pointer flex items-center gap-1.5 text-xs"
                                title="List View">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"></path>
                                </svg>
                                <span class="hidden sm:inline">List</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Active Filters Row -->
            <div class="flex items-center gap-3 mt-4 text-sm flex-wrap">
                <span class="text-slate-300 font-semibold text-xs uppercase tracking-wider">Active:</span>
                <span
                    class="inline-flex items-center gap-1.5 bg-blue-950/80 text-blue-300 px-2.5 py-1 rounded-lg text-xs font-semibold border border-blue-500/30 backdrop-blur-sm shadow-sm">
                    <span>Purpose: <span x-text="filterTab" class="uppercase font-bold text-white"></span></span>
                    <button @click="filterTab = 'all'"
                        class="hover:text-white transition-colors cursor-pointer text-sm">&times;</button>
                </span>
                <span x-show="verifiedOnly"
                    class="inline-flex items-center gap-1.5 bg-emerald-950/80 text-emerald-300 px-2.5 py-1 rounded-lg text-xs font-semibold border border-emerald-500/30 backdrop-blur-sm shadow-sm">
                    <span>Verified Only</span>
                    <button @click="verifiedOnly = false"
                        class="hover:text-white transition-colors cursor-pointer text-sm">&times;</button>
                </span>
                <button @click="filterTab = 'all'; verifiedOnly = false; sortBy = 'featured'"
                    class="text-rose-400 hover:text-rose-300 text-xs font-bold transition-colors cursor-pointer">Clear
                    all</button>
            </div>

        </div>
    </div>

    <!-- Listings Grid -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 py-8">
        <div class="flex justify-between items-center mb-6">
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Showing <span class="text-slate-900 dark:text-white font-bold"><?php echo esc_html( (int) $marketplace_query->found_posts ); ?></span> verified listings</p>
            <div class="flex items-center gap-2">
                <span class="text-xs text-slate-500">Sort by:</span>
                <select x-model="sortBy" @change="$refs.sortInput.value = sortBy; $nextTick(() => $refs.filterForm.submit())"
                    class="bg-transparent border-none text-sm font-bold text-slate-900 dark:text-white focus:ring-0 cursor-pointer">
                    <option value="featured">Featured First</option>
                    <option value="price_asc">Price: Low to High</option>
                    <option value="price_desc">Price: High to Low</option>
                    <option value="newest">Newest</option>
                </select>
            </div>
        </div>

        <!-- On mobile always show grid; on desktop respect viewMode -->
        <div
            :class="viewMode === 'grid' ? 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6' : 'grid grid-cols-1 gap-6'">

                        <?php if ( $marketplace_query->have_posts() ) :
                while ( $marketplace_query->have_posts() ) : $marketplace_query->the_post();
                    $price        = get_post_meta( get_the_ID(), 'ofp_price', true );
                    $listing_type = get_post_meta( get_the_ID(), 'ofp_listing_type', true );
                    $prop_type    = get_post_meta( get_the_ID(), 'ofp_property_type', true );
                    $location     = get_post_meta( get_the_ID(), 'ofp_location_text', true );
                    $beds         = get_post_meta( get_the_ID(), 'ofp_bedrooms', true );
                    $baths        = get_post_meta( get_the_ID(), 'ofp_bathrooms', true );
                    $parking      = get_post_meta( get_the_ID(), 'ofp_parking', true );
                    $sqm          = get_post_meta( get_the_ID(), 'ofp_area_sqm', true );
                    $is_verified  = get_post_meta( get_the_ID(), 'ofp_status', true ) === 'live';
                    $client_id    = get_post_meta( get_the_ID(), 'ofp_client_id', true );
                    $is_featured  = get_post_meta( get_the_ID(), 'ofp_is_featured', true );
                    
                    $agent_name = 'Admin';
                    $agent_company = '';
                    $agent_profile_url = '';
                    if ( $client_id ) {
                        global $wpdb;
                        $client = $wpdb->get_row( $wpdb->prepare( "SELECT owner_name, business_name, profile_slug FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1", $client_id ) );
                        if ( $client ) {
                            $agent_name = $client->owner_name ?: $client->business_name;
                            $agent_company = $client->business_name;
                            if ( ! empty( $client->profile_slug ) ) {
                                $agent_profile_url = home_url( '/agent/' . $client->profile_slug );
                            } else {
                                $agent_profile_url = home_url( '/agent/' . sanitize_title( $agent_name ) );
                            }
                        }
                    }
                    
                    $listing_label = 'FOR SALE';
                    if ( $listing_type === 'rent' ) $listing_label = 'FOR RENT';
                    if ( $listing_type === 'shortlet' ) $listing_label = 'SHORT LET';
            ?>
            <?php
            $price_num = floatval( preg_replace( '/[^\d.]/', '', (string) $price ) );
            if ( $price_num >= 1000000 ) {
                $formatted_price = '₦' . rtrim( rtrim( number_format( $price_num / 1000000, 1 ), '0' ), '.' ) . ' Million';
            } elseif ( $price_num > 0 ) {
                $formatted_price = '₦' . number_format( $price_num );
            } else {
                $formatted_price = '₦' . ( $price ?: 'Contact Agent' );
            }
            ?>
            <div
                class="bg-white dark:bg-[#0F1423] rounded-2xl overflow-hidden border border-slate-100 dark:border-[#1C2438] shadow-[0_4px_20px_rgba(0,0,0,0.06)] hover:shadow-xl transition-all duration-300 group flex relative"
                :class="viewMode === 'grid' ? 'flex-col' : 'flex-col sm:flex-row'">
                <div :class="viewMode === 'grid' ? 'relative h-60 w-full overflow-hidden shrink-0' : 'relative h-60 sm:h-auto sm:w-80 md:w-96 shrink-0 overflow-hidden'">
                    <a href="<?php the_permalink(); ?>" class="block w-full h-full">
                        <?php if ( has_post_thumbnail() ) : ?>
                            <?php the_post_thumbnail( 'large', [ 'class' => 'w-full h-full object-cover group-hover:scale-105 transition-transform duration-500' ] ); ?>
                        <?php else : ?>
                            <img src="https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=800&q=80"
                                alt="<?php the_title_attribute(); ?>"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                        <?php endif; ?>
                    </a>
                    <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/20 to-transparent pointer-events-none"></div>
                    <div class="absolute top-3.5 left-3.5 right-3.5 flex items-center justify-between pointer-events-none">
                        <div class="flex items-center gap-1.5">
                            <span class="bg-[#00875A] text-white text-[11px] font-bold uppercase tracking-wider px-2.5 py-0.5 rounded-full shadow-sm">
                                <?php echo esc_html( $listing_label ); ?>
                            </span>
                            <?php if ( $is_featured ) : ?>
                            <span class="bg-slate-900/40 backdrop-blur-md text-white text-[11px] font-medium px-2.5 py-0.5 rounded-full border border-white/20">Featured</span>
                            <?php endif; ?>
                        </div>
                        <?php if ( $is_verified ) : ?>
                        <span class="bg-emerald-950/70 text-emerald-400 border border-emerald-500/50 backdrop-blur-md text-[11px] font-semibold px-2.5 py-0.5 rounded-full flex items-center gap-1">
                            <svg class="w-3 h-3 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z" /></svg> Verified
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="absolute bottom-3.5 left-3.5 right-3.5 flex justify-between items-end pointer-events-none">
                        <div class="text-white font-black text-2xl sm:text-[26px] tracking-tight drop-shadow-md"><?php echo esc_html( $formatted_price ); ?></div>
                        <div class="bg-slate-950/75 backdrop-blur-md text-white text-[11px] font-medium px-2.5 py-1 rounded-md border border-white/10 capitalize">
                            <?php echo esc_html( str_replace('-', ' ', $prop_type) ); ?>
                        </div>
                    </div>
                </div>
                <div class="p-5 flex-1 flex flex-col justify-between gap-3 min-w-0">
                    <div class="flex flex-col gap-1.5">
                        <div class="text-[12px] sm:text-[13px] text-blue-600 dark:text-blue-400 flex items-center gap-1.5 font-medium">
                            <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            <span class="truncate"><?php echo esc_html( $location ); ?></span>
                        </div>
                        <a href="<?php the_permalink(); ?>" class="font-system text-[15px] sm:text-base font-bold text-slate-900 dark:text-white leading-snug hover:text-blue-600 dark:hover:text-blue-400 transition-colors line-clamp-2">
                            <?php the_title(); ?>
                        </a>
                        <p x-show="viewMode === 'list'" class="hidden sm:block text-xs text-slate-500 dark:text-slate-400 leading-relaxed line-clamp-2 mt-1">
                            <?php echo wp_trim_words( get_the_content(), 20, '...' ); ?>
                        </p>
                    </div>

                    <!-- 4 Specs: Beds, Baths, Parking, SQM -->
                    <div class="grid grid-cols-4 gap-2 py-3 border-y border-slate-100 dark:border-white/5 my-1">
                        <div class="flex items-start gap-1.5">
                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2 2z"></path>
                            </svg>
                            <div class="leading-none">
                                <span class="text-[13px] font-bold text-slate-900 dark:text-white block"><?php echo esc_html( $beds ?: '-' ); ?></span>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 block">Beds</span>
                            </div>
                        </div>
                        <div class="flex items-start gap-1.5">
                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <div class="leading-none">
                                <span class="text-[13px] font-bold text-slate-900 dark:text-white block"><?php echo esc_html( $baths ?: '-' ); ?></span>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 block">Baths</span>
                            </div>
                        </div>
                        <div class="flex items-start gap-1.5">
                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 17H3a2 2 0 01-2-2V7a2 2 0 012-2h11l4 4v8a2 2 0 01-2 2h-3"></path>
                            </svg>
                            <div class="leading-none">
                                <span class="text-[13px] font-bold text-slate-900 dark:text-white block"><?php echo esc_html( $parking ?: '-' ); ?></span>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 block">Parking</span>
                            </div>
                        </div>
                        <div class="flex items-start gap-1.5">
                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path>
                            </svg>
                            <div class="leading-none">
                                <span class="text-[13px] font-bold text-slate-900 dark:text-white block"><?php echo esc_html( $sqm ?: '-' ); ?></span>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 block">SQM</span>
                            </div>
                        </div>
                    </div>

                    <!-- Card Footer -->
                    <div class="flex items-center justify-between pt-1">
                        <?php if ( $agent_profile_url ) : ?>
                        <a href="<?php echo esc_url( $agent_profile_url ); ?>" class="flex items-center gap-2.5 group/owner hover:opacity-90 transition-opacity">
                            <div class="w-9 h-9 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center text-blue-600 dark:text-blue-400 font-bold text-xs ring-1 ring-slate-100 dark:ring-slate-800 group-hover/owner:ring-blue-500 transition-all">
                                <?php echo esc_html( strtoupper( substr( $agent_name, 0, 1 ) ) ); ?>
                            </div>
                            <div>
                                <p class="text-[13px] font-bold text-slate-900 dark:text-white leading-tight group-hover/owner:text-blue-600 dark:group-hover/owner:text-blue-400 transition-colors"><?php echo esc_html( $agent_name ); ?></p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-tight"><?php echo esc_html( $agent_company ?: 'Agent' ); ?></p>
                            </div>
                        </a>
                        <?php else : ?>
                        <div class="flex items-center gap-2.5">
                            <div class="w-9 h-9 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center text-blue-600 dark:text-blue-400 font-bold text-xs ring-1 ring-slate-100 dark:ring-slate-800">
                                <?php echo esc_html( strtoupper( substr( $agent_name, 0, 1 ) ) ); ?>
                            </div>
                            <div>
                                <p class="text-[13px] font-bold text-slate-900 dark:text-white leading-tight"><?php echo esc_html( $agent_name ); ?></p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-tight"><?php echo esc_html( $agent_company ?: 'Agent' ); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="flex items-center gap-1.5">
                            <!-- Details Button -->
                            <a href="<?php the_permalink(); ?>" class="bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs sm:text-[13px] px-3.5 py-2 rounded-xl flex items-center gap-1 shadow-sm shadow-blue-500/20 transition-colors cursor-pointer">
                                <span>Details</span>
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php
                endwhile;
            else :
            ?>
            <div class="col-span-full text-center py-12 bg-white dark:bg-[#0F1423] rounded-2xl border border-slate-100 dark:border-[#1C2438] shadow-sm">
                <p class="text-slate-500 dark:text-slate-400 font-medium">No properties found.</p>
            </div>
            <?php
            endif;
            wp_reset_postdata();
            ?>
            </div>

        <!-- Pagination -->
        <div class="mt-12 flex justify-center">
            <div class="inline-flex items-center gap-1 bg-white dark:bg-slate-900 p-1 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm ofp-pagination">
                <?php
                if ( isset( $marketplace_query ) && $marketplace_query->max_num_pages > 1 ) {
                    $pages = paginate_links( [
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'current'   => max( 1, get_query_var( 'paged' ) ),
                        'total'     => $marketplace_query->max_num_pages,
                        'type'      => 'array',
                        'prev_text' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>',
                        'next_text' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>',
                    ] );

                    if ( is_array( $pages ) ) {
                        foreach ( $pages as $page ) {
                            $page = str_replace( 'page-numbers', 'w-8 h-8 flex items-center justify-center rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold text-sm transition-colors', $page );
                            $page = str_replace( 'current', 'bg-blue-600 text-white hover:bg-blue-600 hover:text-white dark:hover:bg-blue-600 dark:hover:text-white', $page );
                            echo $page;
                        }
                    }
                }
                ?>
            </div>
        </div>

    </section>

    <!-- 13. Footer -->
    <footer class="bg-slate-50 dark:bg-slate-950 text-slate-600 dark:text-slate-400 mt-16"
        style="font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,'Helvetica Neue','Noto Sans',Arial,sans-serif,'Apple Color Emoji','Segoe UI Emoji','Segoe UI Symbol','Noto Color Emoji';font-size:12px;font-weight:400">
        <!-- Glass Divider -->
        <div class="border-t border-slate-200 dark:border-slate-800"></div>
        <!-- Top Subscription Area -->
        <div class="border-b border-slate-200 dark:border-slate-800 py-12">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 flex flex-col md:flex-row items-center justify-between gap-6">
                <div>
                    <h3 style="font-size:16px;font-weight:700" class="text-slate-900 dark:text-white mb-2">Get
                        Off-Market Real Estate Alerts & Price Drops</h3>
                    <p class="text-slate-500 dark:text-slate-400">Join over 24,000 verified investors and homebuyers
                        receiving
                        weekly curated deals in Nigeria.</p>
                </div>
                <form class="flex w-full md:w-auto gap-4">
                    <input type="email" placeholder="Enter your email address"
                        class="w-full md:w-72 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg px-4 py-3 text-slate-900 dark:text-white focus:outline-none focus:border-blue-500"
                        style="font-size:12px">
                    <button type="button"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-3 rounded-lg transition-colors cursor-pointer whitespace-nowrap flex items-center gap-2">
                        Subscribe <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </button>
                </form>
            </div>
        </div>

        <!-- Main Footer Links -->
        <div class="py-16">
            <div class="max-w-7xl mx-auto px-4 sm:px-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-[1.6fr_1fr_1fr_1fr] gap-8 lg:gap-10">
                    <!-- Column 1 -->
                    <div>
                        <a href="<?php echo esc_url( home_url('/') ); ?>" class="flex items-center gap-2 mb-6">
                            <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                                    </path>
                                </svg>
                            </div>
                            <span style="font-size:16px;font-weight:700"
                                class="tracking-tight text-slate-900 dark:text-white">Bofast Homes</span>
                        </a>
                        <p class="text-slate-500 dark:text-slate-400 leading-relaxed mb-6">
                            Nigeria's leading verified real estate marketplace and integrated broker CRM portal. Fast,
                            transparent property acquisitions.
                        </p>
                        <div class="space-y-3 text-slate-500 dark:text-slate-400 text-[12px]">
                            <div class="flex items-start gap-3">
                                <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z">
                                    </path>
                                </svg>
                                <span>Plot 14 Admiralty Way, Lekki Phase 1, Lagos</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z">
                                    </path>
                                </svg>
                                <span>+234 (01) 888 4000</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                    </path>
                                </svg>
                                <span>support@propertyportal.ng</span>
                            </div>
                        </div>
                    </div>
                    <!-- Column 2 -->
                    <div>
                        <h4 style="font-weight:700"
                            class="text-slate-900 dark:text-white mb-6 uppercase tracking-wider">TOP LOCATIONS</h4>
                        <ul class="space-y-3 text-slate-500 dark:text-slate-400 text-[12px]">
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Lekki Phase 1</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Ikoyi</a></li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Victoria Island</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Maitama Abuja</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Ikeja GRA</a></li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Banana Island</a>
                            </li>
                        </ul>
                    </div>
                    <!-- Column 3 -->
                    <div>
                        <h4 style="font-weight:700"
                            class="text-slate-900 dark:text-white mb-6 uppercase tracking-wider">POPULAR CATEGORIES</h4>
                        <ul class="space-y-3 text-slate-500 dark:text-slate-400 text-[12px]">
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Houses
                                    for Sale in Lekki</a></li>
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Flats &
                                    Apartments for Rent</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Serviced Luxury
                                    Short Lets</a>
                            </li>
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Prime
                                    Residential Lands (C of
                                    O)</a></li>
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Grade-A
                                    Commercial Offices</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Distressed /
                                    Below-Market
                                    Deals</a></li>
                        </ul>
                    </div>
                    <!-- Column 4 -->
                    <div>
                        <h4 style="font-weight:700"
                            class="text-slate-900 dark:text-white mb-6 uppercase tracking-wider">CRM PORTAL & AGENTS
                        </h4>
                        <ul class="space-y-3 text-slate-500 dark:text-slate-400 text-[12px]">
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Agent /
                                    Broker CRM Login</a></li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Developer
                                    Project Showcases</a>
                            </li>
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Land
                                    Title Verification
                                    Service</a></li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Inspection
                                    Scheduling API</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Mortgage &
                                    Financing
                                    Calculators</a></li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">WordPress
                                    Plugin Integration
                                    Docs</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="border-t border-slate-200 dark:border-slate-800 py-6">
            <div
                class="max-w-7xl mx-auto px-4 sm:px-6 flex flex-col md:flex-row items-center justify-between gap-4 text-slate-500 dark:text-slate-500">
                <div class="flex items-center gap-2 text-emerald-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>All listings strictly verified against state land registry archives.</span>
                </div>
                <p>&copy; 2026 Bofast Homes. Nigeria's most trusted verified property marketplace. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <?php wp_footer(); ?>
</body>

</html>

