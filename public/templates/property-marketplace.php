<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
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
        'key'     => 'ofp_price_effective', // real move-in price (rent + fees)
        'value'   => (float) $current_price_max,
        'compare' => '<=',
        'type'    => 'NUMERIC',
    ];
}

// 7. Verified only filter (Properties owned by verified clients or flagged verified)
if ( $current_verified ) {
    global $wpdb;
    $verified_cids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}ofp_clients WHERE is_verified = 1" );
    $verified_meta = [
        'relation' => 'OR',
        [
            'key'     => 'ofp_is_verified',
            'value'   => '1',
            'compare' => '=',
        ],
    ];
    if ( ! empty( $verified_cids ) ) {
        $verified_meta[] = [
            'key'     => 'ofp_client_id',
            'value'   => array_map( 'intval', $verified_cids ),
            'compare' => 'IN',
        ];
    }
    $args['meta_query'][] = $verified_meta;
}

// 8. Sorting
switch ( $current_sort ) {
    case 'price_asc':
        $args['meta_key'] = 'ofp_price_effective';
        $args['orderby']  = 'meta_value_num';
        $args['order']    = 'ASC';
        break;
    case 'price_desc':
        $args['meta_key'] = 'ofp_price_effective';
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
            var isDark = theme ? (theme === 'dark') : prefersDark;
            var html = document.documentElement;
            if (isDark) {
                html.classList.add('dark');
                html.classList.remove('light');
                html.setAttribute('data-theme', 'dark');
            } else {
                html.classList.remove('dark');
                html.classList.add('light');
                html.setAttribute('data-theme', 'light');
            }
        })();

        function ofpToggleTheme() {
            var html = document.documentElement;
            var isDark = html.classList.toggle('dark');
            if (isDark) {
                html.classList.remove('light');
                html.setAttribute('data-theme', 'dark');
            } else {
                html.classList.add('light');
                html.setAttribute('data-theme', 'light');
            }
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
            window.dispatchEvent(new CustomEvent('ofp-theme-changed', { detail: { dark: isDark, theme: isDark ? 'dark' : 'light' } }));
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

    <!-- Mega Menu Backdrop Overlay (Desktop) -->
    <?php
        // Shared top bar + mega menu + mobile menu (X5.03). Settings: partials/site-nav.php
        $ofp_nav_mode = 'solid';
        $ofp_nav_active = '';
        include OFP_PATH . 'public/templates/partials/site-nav.php';
    ?>
    

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
                        <button type="button" @click="filterTab = 'land'; $nextTick(() => $refs.filterForm.submit())"
                            :class="filterTab === 'land' ? 'bg-lime-700 text-white' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700'"
                            class="px-4 py-2 rounded-xl text-sm font-bold whitespace-nowrap transition-colors cursor-pointer">
                            Land
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
                    $ofp_pkg      = OFP_Property_Package::get( get_the_ID() ); // rental with a move-in package?
                    if ( $ofp_pkg ) { $price = $ofp_pkg['total']; }
                    $listing_type = get_post_meta( get_the_ID(), 'ofp_listing_type', true );
                    $prop_type    = get_post_meta( get_the_ID(), 'ofp_property_type', true );
                    $location     = get_post_meta( get_the_ID(), 'ofp_location_text', true );
                    $beds         = get_post_meta( get_the_ID(), 'ofp_bedrooms', true );
                    $baths        = get_post_meta( get_the_ID(), 'ofp_bathrooms', true );
                    $parking      = get_post_meta( get_the_ID(), 'ofp_parking', true );
                    $sqm          = get_post_meta( get_the_ID(), 'ofp_area_sqm', true );
                    $client_id    = get_post_meta( get_the_ID(), 'ofp_client_id', true );
                    $is_featured  = get_post_meta( get_the_ID(), 'ofp_is_featured', true );
                    $status_ctx   = class_exists( 'OFP_Property_CPT' ) ? OFP_Property_CPT::get_status_context( get_the_ID() ) : null;
                    
                    $agent_name        = 'Admin';
                    $agent_company     = '';
                    $agent_profile_url = '';
                    $client_is_verified = false;
                    if ( $client_id ) {
                        global $wpdb;
                        $client = $wpdb->get_row( $wpdb->prepare( "SELECT owner_name, business_name, profile_slug, is_verified FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1", $client_id ) );
                        if ( $client ) {
                            $agent_name         = $client->owner_name ?: $client->business_name;
                            $agent_company      = $client->business_name;
                            $client_is_verified = ! empty( $client->is_verified );
                            if ( ! empty( $client->profile_slug ) ) {
                                $agent_profile_url = home_url( '/agent/' . $client->profile_slug );
                            } else {
                                $agent_profile_url = home_url( '/agent/' . sanitize_title( $agent_name ) );
                            }
                        }
                    }
                    $is_verified = $client_is_verified || ( '1' === (string) get_post_meta( get_the_ID(), 'ofp_is_verified', true ) );
                    
                    $listing_label = 'FOR SALE';
                    if ( $listing_type === 'rent' ) $listing_label = 'FOR RENT';
                    if ( $listing_type === 'shortlet' ) $listing_label = 'SHORT LET';
                    if ( $listing_type === 'commercial' ) $listing_label = 'COMMERCIAL';
                    if ( $listing_type === 'land' ) $listing_label = 'LAND';
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
                    <div class="absolute top-3.5 left-3.5 right-3.5 flex items-center justify-between pointer-events-none flex-wrap gap-1.5">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="bg-[#00875A] text-white text-[11px] font-bold uppercase tracking-wider px-2.5 py-0.5 rounded-full shadow-sm">
                                <?php echo esc_html( $listing_label ); ?>
                            </span>
                            <?php if ( $status_ctx && ! empty( $status_ctx['is_occupied'] ) ) : ?>
                                <span class="bg-purple-700 text-white text-[11px] font-bold uppercase tracking-wider px-2.5 py-0.5 rounded-full shadow-sm flex items-center gap-1">
                                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    Occupied
                                </span>
                            <?php elseif ( $status_ctx && ! empty( $status_ctx['is_taken'] ) ) : ?>
                                <span class="bg-rose-600 text-white text-[11px] font-bold uppercase tracking-wider px-2.5 py-0.5 rounded-full shadow-sm">
                                    <?php echo esc_html( $status_ctx['label'] ); ?>
                                </span>
                            <?php elseif ( $status_ctx && ! empty( $status_ctx['key'] ) && $status_ctx['key'] === 'under_offer' ) : ?>
                                <span class="bg-amber-600 text-white text-[11px] font-bold uppercase tracking-wider px-2.5 py-0.5 rounded-full shadow-sm">
                                    Under Offer
                                </span>
                            <?php endif; ?>
                            <?php if ( $is_featured ) : ?>
                                <span class="bg-amber-400 text-slate-950 text-[10px] font-extrabold uppercase tracking-wider px-2.5 py-0.5 rounded-full shadow-sm flex items-center gap-1">
                                    <svg class="w-2.5 h-2.5 text-slate-950" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                    Featured
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ( $is_verified && ( ! $status_ctx || $status_ctx['is_available'] ) ) : ?>
                        <span class="bg-emerald-950/70 text-emerald-400 border border-emerald-500/50 backdrop-blur-md text-[11px] font-semibold px-2.5 py-0.5 rounded-full flex items-center gap-1">
                            <svg class="w-3 h-3 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z" /></svg> Verified
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="absolute bottom-3.5 left-3.5 right-3.5 flex justify-between items-end pointer-events-none">
                        <div class="text-white font-black text-2xl sm:text-[26px] tracking-tight drop-shadow-md"><?php echo esc_html( $formatted_price ); ?></div>
                        <?php if ( $ofp_pkg ) : ?><div class="text-white/90 text-[11px] font-semibold drop-shadow"><?php echo esc_html( OFP_Property_Package::note( $ofp_pkg ) ); ?></div><?php endif; ?>
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

<?php if ( $listing_type === 'land' ) : 
                    $land_b = class_exists( 'OFP_Property_Land' ) ? OFP_Property_Land::get_breakdown( get_the_ID() ) : [];
?>
                    <!-- Land specs: Plot benchmark, Total Area, and Title document -->
                    <div class="grid grid-cols-3 gap-2 py-3 border-y border-slate-100 dark:border-white/5 my-1">
                        <div class="leading-none">
                            <span class="text-[13px] font-bold text-emerald-600 dark:text-emerald-400 block truncate"><?php echo esc_html( ! empty( $land_b['plot_short'] ) && $land_b['plot_short'] !== '-' ? $land_b['plot_short'] : 'On Request' ); ?></span>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 block">Plot Sizing</span>
                        </div>
                        <div class="leading-none">
                            <span class="text-[13px] font-bold text-slate-900 dark:text-white block truncate"><?php echo esc_html( ! empty( $land_b['sqm_formatted'] ) && $land_b['sqm_formatted'] !== '-' ? $land_b['sqm_formatted'] . ' SQM' : ( $sqm ? number_format( (float) $sqm ) . ' SQM' : 'On Request' ) ); ?></span>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 block">Total Area</span>
                        </div>
                        <div class="leading-none">
                            <?php
                            $raw_title_doc = get_post_meta( get_the_ID(), 'ofp_title_document', true ) ?: '';
                            $docs_array = array_filter( array_map( 'trim', explode( ',', $raw_title_doc ) ) );
                            if ( ! empty( $docs_array ) ) :
                                $first_doc = $docs_array[0];
                                $extra_count = count( $docs_array ) - 1;
                                $all_docs_tooltip = esc_attr( implode( ', ', $docs_array ) );
                            ?>
                                <div class="flex items-center gap-1 min-w-0" title="<?php echo $all_docs_tooltip; ?>">
                                    <span class="text-[13px] font-bold text-slate-900 dark:text-white truncate"><?php echo esc_html( $first_doc ); ?></span>
                                    <?php if ( $extra_count > 0 ) : ?>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-400 border border-emerald-300 dark:border-emerald-700/50 shrink-0 cursor-default" title="<?php echo $all_docs_tooltip; ?>">+<?php echo (int) $extra_count; ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php else : ?>
                                <span class="text-[13px] font-bold text-slate-900 dark:text-white block truncate">On Request</span>
                            <?php endif; ?>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 block">Title Doc</span>
                        </div>
                    </div>
<?php else : ?>
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
<?php endif; ?>

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
    <?php
        // Shared footer (X5.03). Content: partials/site-footer.php
        $ofp_footer_margin = 'mt-16';
        include OFP_PATH . 'public/templates/partials/site-footer.php';
    ?>

    <?php wp_footer(); ?>
</body>

</html>

