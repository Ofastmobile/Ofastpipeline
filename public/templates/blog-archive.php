<?php
/**
 * Template: Blog & Post Archive (Blog loop grid)
 * NaijaHub / OFP Public Blog Archive
 *
 * Uses the exact homepage global navigation header (with desktop MegaMenu and mobile menu),
 * the exact homepage global footer, modern 3-column blog loop grids, live keyword search,
 * dynamic category pills, and 2 rounded navigation buttons (left & right arrows).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Current logged-in client state
$client = class_exists( 'OFP_Auth' ) ? OFP_Auth::current_client() : null;
$ofp_header_social = class_exists( 'OFP_Brand' ) ? OFP_Brand::social_links() : [];

// Determine page title & context
$archive_title = 'Journal & Market Intelligence';
$archive_desc  = 'Expert perspectives, Nigerian real estate trends, legal insights, and investment hotspot analyses.';

if ( is_category() ) {
    $archive_title = single_cat_title( '', false );
    $archive_desc  = category_description() ?: ( 'Browse all articles, market guides, and reports filed under ' . $archive_title . '.' );
} elseif ( is_tag() ) {
    $archive_title = '#' . single_tag_title( '', false );
    $archive_desc  = tag_description() ?: ( 'All articles and guides tagged with ' . $archive_title . '.' );
} elseif ( is_author() ) {
    $author = get_queried_object();
    $archive_title = 'Articles by ' . ( $author->display_name ?? 'Author' );
    $archive_desc  = get_the_author_meta( 'description', $author->ID ) ?: 'Curated insights and articles published by this contributor.';
} elseif ( is_search() ) {
    $archive_title = 'Search: "' . esc_html( get_search_query() ) . '"';
    $archive_desc  = 'Search results matching your keyword across our market publications.';
} elseif ( is_date() ) {
    $archive_title = 'Archive: ' . get_the_date( 'F Y' );
    $archive_desc  = 'Articles and property updates published during this period.';
}

$page_title = class_exists( 'OFP_Brand' ) ? OFP_Brand::title( $archive_title . ' · Real Estate Insights' ) : ( $archive_title . ' - ' . get_bloginfo( 'name' ) );

// Fallback images for posts without thumbnails
$fallback_images = [
    'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=800&q=80',
    'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=800&q=80',
    'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=800&q=80',
    'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=800&q=80',
    'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?auto=format&fit=crop&w=800&q=80',
    'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=800&q=80',
];

// Query state
global $wp_query;
$current_page = max( 1, (int) get_query_var( 'paged' ) );
$total_pages  = max( 1, (int) $wp_query->max_num_pages );
$is_first_page_index = ( $current_page === 1 && ! is_search() && ! is_category() && ! is_tag() && ! is_author() );

$featured_post = null;
$grid_posts    = [];

if ( have_posts() ) {
    $all_found = $wp_query->posts;
    if ( $is_first_page_index && count( $all_found ) > 0 ) {
        $featured_post = $all_found[0];
        $grid_posts    = array_slice( $all_found, 1 );
    } else {
        $grid_posts    = $all_found;
    }
}
?>
<!DOCTYPE html>
<html lang="en" x-data="{ megaMenuOpen: false }">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $page_title ); ?></title>

    <!-- SEO Meta Tags -->
    <meta name="description" content="<?php echo esc_attr( $archive_desc ); ?>">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <link rel="canonical" href="<?php echo esc_url( home_url( '/blog' ) ); ?>">

    <!-- Open Graph / Facebook / WhatsApp Preview -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo esc_attr( class_exists('OFP_Brand') ? OFP_Brand::name() : get_bloginfo('name') ); ?>">
    <meta property="og:url" content="<?php echo esc_url( home_url( '/blog' ) ); ?>">
    <meta property="og:title" content="<?php echo esc_attr( $page_title ); ?>">
    <meta property="og:description" content="<?php echo esc_attr( $archive_desc ); ?>">
    <meta property="og:image" content="<?php echo esc_url( 'https://images.unsplash.com/photo-1499750310107-5fef28a66643?auto=format&fit=crop&w=1200&q=80' ); ?>">

    <!-- Twitter Card Meta -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?php echo esc_url( home_url( '/blog' ) ); ?>">
    <meta name="twitter:title" content="<?php echo esc_attr( $page_title ); ?>">
    <meta name="twitter:description" content="<?php echo esc_attr( $archive_desc ); ?>">
    <meta name="twitter:image" content="<?php echo esc_url( 'https://images.unsplash.com/photo-1499750310107-5fef28a66643?auto=format&fit=crop&w=1200&q=80' ); ?>">

    <!-- Dark Mode Init Script (Defaults to LIGHT MODE unless user explicitly toggled to dark) -->
    <script>
        (function() {
            var theme = localStorage.getItem('ofp_theme');
            var isDark = (theme === 'dark');
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
            window.dispatchEvent(new CustomEvent('ofp-theme-changed', { detail: { dark: isDark, theme: isDark ? 'dark' : 'light' } }));
        }
        window.ofpToggleTheme = ofpToggleTheme;
    </script>

    <!-- Tailwind Config & Libraries -->
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Arial', 'sans-serif'],
                    }
                }
            }
        };
    </script>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/alpine-tailwind-overrides.css' ); ?>">

    <?php wp_head(); ?>

    <style>
        body, html {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .theme-toggle-sun { display: none; }
        .dark .theme-toggle-sun { display: block; }
        .dark .theme-toggle-moon { display: none; }
        .ofp-line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .ofp-line-clamp-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
    </style>
</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 transition-colors duration-300 min-h-screen flex flex-col antialiased">
    <?php wp_body_open(); ?>

    <!-- ── HOMEPAGE GLOBAL HEADER ── -->
    <!-- Mega Menu Backdrop Overlay (Desktop) -->
    <div x-show="megaMenuOpen" class="hidden lg:block fixed inset-0 bg-slate-900/20 backdrop-blur-md z-40"
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;"
        @click="megaMenuOpen = false"></div>

    <!-- Top Navigation Bar -->
    <header x-data="{ scrolledDown: false, lastScroll: 0 }" @scroll.window="
                if (!megaMenuOpen) {
                    const current = window.pageYOffset;
                    if (current > lastScroll && current > 50) scrolledDown = true;
                    else if (current < lastScroll) scrolledDown = false;
                    lastScroll = current;
                }
            " class="fixed top-0 left-0 w-full z-50 bg-white/90 dark:bg-slate-950/90 backdrop-blur-md border-b border-slate-200/80 dark:border-slate-800/80 transition-transform duration-300"
        :class="scrolledDown ? '-translate-y-full' : 'translate-y-0'">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="flex justify-between items-center h-16 transition-all duration-300">
                <!-- Left: Logo & Hamburger -->
                <div class="flex items-center gap-3 sm:gap-5">
                    <a href="<?php echo esc_url( home_url('/') ); ?>" class="flex items-center">
                        <span class="text-[13px] sm:text-sm font-black tracking-[0.25em] uppercase transition-colors text-slate-900 dark:text-white drop-shadow-sm">
                            <?php echo OFP_Brand::public_logo_html( 'BOFAST HOMES' ); ?>
                        </span>
                    </a>

                    <!-- Mega Menu Hamburger Button -->
                    <button @click="megaMenuOpen = !megaMenuOpen"
                        class="rounded-lg px-2.5 py-1.5 transition-colors flex items-center justify-center cursor-pointer border bg-slate-900/5 hover:bg-slate-900/10 dark:bg-white/10 dark:hover:bg-white/20 backdrop-blur-md text-slate-900 dark:text-white border-slate-300/80 dark:border-white/20">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path x-show="!megaMenuOpen" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"></path>
                            <path x-show="megaMenuOpen" style="display: none;" stroke-linecap="round"
                                stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Right: Action Buttons -->
                <div class="flex items-center gap-2 sm:gap-4">
                    <button type="button" onclick="ofpToggleTheme()" aria-label="Toggle Dark Mode"
                        class="p-2 rounded-xl border transition-all cursor-pointer border-slate-300/80 dark:border-white/20 bg-slate-900/5 hover:bg-slate-900/10 dark:bg-white/10 dark:hover:bg-white/20 backdrop-blur-md text-slate-900 dark:text-white">
                        <svg class="w-4 h-4 theme-toggle-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                        </svg>
                        <svg class="w-4 h-4 text-amber-500 dark:text-amber-400 theme-toggle-sun" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                    </button>

                    <!-- List Property Button -->
                    <a href="<?php echo esc_url( home_url( '/login' ) ); ?>"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-5 sm:px-7 py-2 sm:py-2.5 rounded-[7px] text-[11px] sm:text-xs tracking-wider shadow-lg shadow-blue-500/20 transition-all cursor-pointer whitespace-nowrap uppercase inline-flex items-center justify-center">
                        List Property
                    </a>
                </div>
            </div>

            <!-- Desktop Mega Menu Dropdown -->
            <div x-show="megaMenuOpen" style="display: none;" @click.away="megaMenuOpen = false"
                class="hidden lg:block absolute top-full left-4 right-4 sm:left-6 sm:right-6 lg:left-8 lg:right-8 mt-2 z-[100] bg-white/95 dark:bg-slate-900/95 backdrop-blur-2xl border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl overflow-hidden font-sans"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 -translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-4">

                <!-- Main Content Area -->
                <div class="p-8 grid grid-cols-4 gap-8">
                    <!-- Image Cards Area (Takes 3 columns) -->
                    <div class="col-span-3 grid grid-cols-4 gap-4">
                        <!-- Card 1: Properties -->
                        <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=600&q=80"
                                alt="Properties" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide group-hover:text-blue-400 transition-colors">
                                PROPERTIES</div>
                        </a>

                        <!-- Card 2: Plans & Pricing -->
                        <a href="<?php echo esc_url( home_url('/plans-pricing') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=600&q=80"
                                alt="Plans &amp; Pricing" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide group-hover:text-blue-400 transition-colors z-30">
                                PLANS &amp; PRICING</div>
                        </a>

                        <!-- Card 3: Map Search -->
                        <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-blue-50 dark:bg-blue-900/20">
                            <div class="absolute inset-0 bg-blue-500/10 group-hover:bg-blue-500/20 transition-colors z-10"
                                style="background-image: radial-gradient(circle, #3B82F6 1px, transparent 1px); background-size: 20px 20px;"></div>
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent z-20"></div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide group-hover:text-blue-400 transition-colors z-30">
                                MAP SEARCH</div>
                        </a>

                        <!-- Card 4: Our Agents -->
                        <a href="<?php echo esc_url( home_url('/agents') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=600&q=80"
                                alt="Agents" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide group-hover:text-blue-400 transition-colors">
                                OUR AGENTS</div>
                        </a>
                    </div>

                    <!-- Side Links -->
                    <div class="flex flex-col justify-between h-full pl-8 border-l border-slate-200 dark:border-slate-800">
                        <div class="space-y-6">
                            <ul class="space-y-4">
                                <li><a href="<?php echo esc_url( home_url('/login') ); ?>"
                                        class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-xs tracking-widest transition-colors uppercase flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-blue-500"></span> BECOME A PARTNER</a></li>
                                <li><a href="<?php echo esc_url( home_url('/blog') ); ?>"
                                        class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-xs tracking-widest transition-colors uppercase flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-blue-500"></span> BLOG</a></li>
                                <li><a href="<?php echo esc_url( home_url('/careers') ); ?>"
                                        class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-xs tracking-widest transition-colors uppercase flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-blue-500"></span> CAREERS</a></li>
                            </ul>

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

                        <div class="pt-4 border-t border-slate-200 dark:border-slate-800">
                            <a href="<?php echo esc_url( home_url('/privacy-policy/') ); ?>"
                                class="text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 text-xs font-bold tracking-widest transition-colors uppercase whitespace-nowrap">PRIVACY POLICY</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Mobile Full-Page Navigation Screen -->
    <div x-show="megaMenuOpen" style="display: none;"
        class="lg:hidden fixed inset-0 z-[100] bg-white/98 dark:bg-slate-950/98 backdrop-blur-2xl flex flex-col justify-between p-4 sm:p-6 font-sans overflow-y-auto"
        x-transition:enter="transform transition-transform ease-in-out duration-500"
        x-transition:enter-start="-translate-y-full" x-transition:enter-end="translate-y-0"
        x-transition:leave="transform transition-transform ease-in-out duration-500"
        x-transition:leave-start="translate-y-0" x-transition:leave-end="-translate-y-full">

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

        <div class="my-auto py-2 space-y-3">
            <div class="grid grid-cols-2 gap-2.5">
                <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                    class="group relative h-20 sm:h-24 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-900">
                    <img src="https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=400&q=80"
                        alt="Properties" class="w-full h-full object-cover opacity-60 group-hover:scale-105 transition-transform duration-300">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-transparent"></div>
                    <div class="absolute bottom-2.5 left-2.5 text-white font-black text-xs sm:text-sm uppercase tracking-wider group-hover:text-blue-400 transition-colors">Properties</div>
                </a>
                <a href="<?php echo esc_url( home_url('/plans-pricing') ); ?>"
                    class="group relative h-20 sm:h-24 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-900">
                    <img src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=400&q=80"
                        alt="Plans &amp; Pricing" class="w-full h-full object-cover opacity-60 group-hover:scale-105 transition-transform duration-300">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-transparent"></div>
                    <div class="absolute bottom-2.5 left-2.5 text-white font-black text-xs sm:text-sm uppercase tracking-wider group-hover:text-blue-400 transition-colors">Plans &amp; Pricing</div>
                </a>
                <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                    class="group relative h-20 sm:h-24 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-blue-950">
                    <div class="absolute inset-0 bg-blue-500/10" style="background-image: radial-gradient(circle, #3B82F6 1px, transparent 1px); background-size: 16px 16px;"></div>
                    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-transparent"></div>
                    <div class="absolute bottom-2.5 left-2.5 text-white font-black text-xs sm:text-sm uppercase tracking-wider group-hover:text-blue-400 transition-colors">Map Search</div>
                </a>
                <a href="<?php echo esc_url( home_url('/agents') ); ?>"
                    class="group relative h-20 sm:h-24 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-900">
                    <img src="https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=400&q=80"
                        alt="Agents" class="w-full h-full object-cover opacity-60 group-hover:scale-105 transition-transform duration-300">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-transparent"></div>
                    <div class="absolute bottom-2.5 left-2.5 text-white font-black text-xs sm:text-sm uppercase tracking-wider group-hover:text-blue-400 transition-colors">Our Agents</div>
                </a>
            </div>

            <div class="py-1 divide-y divide-slate-100 dark:divide-slate-800/80">
                <a href="<?php echo esc_url( home_url('/login') ); ?>"
                    class="py-3 sm:py-3.5 flex items-center text-slate-800 dark:text-slate-200 font-bold text-xs uppercase tracking-wider hover:text-blue-600 transition-colors">
                    <span class="flex items-center gap-2.5"><span class="w-2 h-2 rounded-full bg-blue-500"></span>Become a Partner</span>
                </a>
                <a href="<?php echo esc_url( home_url('/blog') ); ?>"
                    class="py-3 sm:py-3.5 flex items-center text-slate-800 dark:text-slate-200 font-bold text-xs uppercase tracking-wider hover:text-blue-600 transition-colors">
                    <span class="flex items-center gap-2.5"><span class="w-2 h-2 rounded-full bg-blue-500"></span>Blog</span>
                </a>
                <a href="<?php echo esc_url( home_url('/careers') ); ?>"
                    class="py-3 sm:py-3.5 flex items-center text-slate-800 dark:text-slate-200 font-bold text-xs uppercase tracking-wider hover:text-blue-600 transition-colors">
                    <span class="flex items-center gap-2.5"><span class="w-2 h-2 rounded-full bg-blue-500"></span>Careers</span>
                </a>
            </div>
        </div>

        <div class="pt-4 border-t border-slate-100 dark:border-slate-800/80 text-center text-xs text-slate-400">
            <span><?php echo OFP_Brand::public_copyright(); ?></span>
        </div>
    </div>

    <!-- ── HERO / HEADER SECTION (FULL PAGE HEIGHT WITH EDITORIAL BLOG BG IMAGE) ── -->
    <section class="relative min-h-[85vh] sm:min-h-screen flex flex-col justify-center items-center pt-24 pb-16 px-4 sm:px-6 lg:px-8 overflow-hidden border-b border-slate-200/80 dark:border-slate-800/80 text-center">
        <!-- Editorial Background Image & Adaptive Light / Dark Overlay -->
        <div class="absolute inset-0 bg-cover bg-center bg-no-repeat transition-transform duration-1000 scale-105"
             style="background-image: url('https://images.unsplash.com/photo-1499750310107-5fef28a66643?auto=format&fit=crop&w=2000&q=80');"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-white/65 via-slate-100/55 to-white/75 dark:from-slate-950/85 dark:via-slate-950/75 dark:to-slate-950/90 backdrop-blur-[1.5px] transition-colors duration-300"></div>
        <div class="absolute top-1/3 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[400px] bg-blue-500/10 dark:bg-blue-500/20 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 max-w-4xl mx-auto w-full my-auto py-12">
            <!-- Badge -->
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-xs font-bold tracking-wider uppercase bg-blue-50/90 text-blue-700 border border-blue-200/80 dark:bg-blue-500/20 dark:text-blue-300 dark:border-blue-400/30 backdrop-blur-md mb-6 shadow-sm">
                <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>
                </svg>
                <span>Market Intelligence &amp; Journal</span>
            </div>

            <!-- Headline -->
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black text-slate-900 dark:text-white tracking-tight leading-[1.1] mb-6">
                <?php echo esc_html( $archive_title ); ?>
            </h1>

            <!-- Description -->
            <p class="text-base sm:text-lg lg:text-xl text-slate-600 dark:text-slate-200 font-normal leading-relaxed mb-10 max-w-2xl mx-auto">
                <?php echo esc_html( $archive_desc ); ?>
            </p>

            <!-- Search Form -->
            <form method="get" action="<?php echo esc_url( home_url('/blog') ); ?>" class="relative flex flex-col sm:flex-row gap-2.5 max-w-2xl mx-auto p-2 rounded-2xl bg-white/95 dark:bg-slate-900/95 backdrop-blur-2xl border border-slate-200/80 dark:border-slate-800 shadow-xl dark:shadow-2xl">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    <input type="text" name="s" value="<?php echo esc_attr( get_search_query() ); ?>"
                           placeholder="Search articles, guides, legal reports, market trends..."
                           class="w-full pl-12 pr-4 py-3.5 rounded-xl border border-transparent bg-transparent text-sm sm:text-base text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none transition-all">
                </div>
                <button type="submit"
                        class="px-8 py-3.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs uppercase tracking-wider shadow-lg shadow-blue-500/30 transition-all shrink-0">
                    Search
                </button>
                <?php if ( is_search() || is_category() || is_tag() ) : ?>
                    <a href="<?php echo esc_url( home_url('/blog') ); ?>"
                       class="px-5 py-3.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-bold tracking-wider uppercase text-center transition-colors shrink-0 flex items-center justify-center">
                        Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Scroll Indicator -->
        <a href="#articles-content" class="relative z-10 text-slate-500 hover:text-slate-900 dark:text-white/70 dark:hover:text-white transition-colors flex flex-col items-center gap-1.5 pt-4 pb-2 text-[11px] font-bold uppercase tracking-widest mt-auto">
            <span>Explore Articles</span>
            <svg class="w-4 h-4 animate-bounce text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
            </svg>
        </a>
    </section>

    <!-- ── MAIN CONTENT CONTAINER ── -->
    <main id="articles-content" class="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 lg:py-20 w-full scroll-mt-16">

        <!-- ── FEATURED HERO POST (PAGE 1 ONLY) ── -->
        <?php if ( $featured_post ) :
            $f_id        = $featured_post->ID;
            $f_permalink = get_permalink( $f_id );
            $f_title     = get_the_title( $f_id );
            $f_excerpt   = get_the_excerpt( $f_id ) ?: wp_trim_words( strip_shortcodes( $featured_post->post_content ), 32, '...' );
            $f_author_id = $featured_post->post_author;
            $f_author    = get_the_author_meta( 'display_name', $f_author_id );
            $f_avatar    = get_avatar_url( $f_author_id, [ 'size' => 80 ] );
            $f_date      = get_the_date( 'M j, Y', $f_id );
            $f_read_time = max( 1, (int) ceil( str_word_count( strip_tags( $featured_post->post_content ) ) / 200 ) );

            $f_thumb = has_post_thumbnail( $f_id )
                ? get_the_post_thumbnail_url( $f_id, 'large' )
                : $fallback_images[0];

            $f_cats = get_the_category( $f_id );
            $f_cat_name = ! empty( $f_cats ) ? $f_cats[0]->name : 'Real Estate';
        ?>
            <section class="mb-14">
                <div class="relative rounded-3xl overflow-hidden border border-slate-200/90 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-lg hover:shadow-2xl transition-all duration-300 group">
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-0">
                        <div class="lg:col-span-7 relative h-72 sm:h-96 lg:h-auto min-h-[300px] overflow-hidden bg-slate-900">
                            <img src="<?php echo esc_url( $f_thumb ); ?>" alt="<?php echo esc_attr( $f_title ); ?>"
                                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent lg:hidden"></div>
                            <div class="absolute top-4 left-4 z-10">
                                <span class="px-3.5 py-1.5 rounded-full text-[11px] font-black uppercase tracking-wider bg-blue-600 text-white shadow-lg shadow-blue-500/30">
                                    Featured Insight
                                </span>
                            </div>
                        </div>

                        <div class="lg:col-span-5 p-6 sm:p-8 lg:p-10 flex flex-col justify-between">
                            <div>
                                <div class="flex items-center gap-3 text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-3">
                                    <span class="text-blue-600 dark:text-blue-400"><?php echo esc_html( $f_cat_name ); ?></span>
                                    <span>&bull;</span>
                                    <span><?php echo esc_html( $f_date ); ?></span>
                                    <span>&bull;</span>
                                    <span><?php echo esc_html( $f_read_time ); ?> min read</span>
                                </div>

                                <h2 class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white leading-tight mb-4 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                                    <a href="<?php echo esc_url( $f_permalink ); ?>">
                                        <?php echo esc_html( $f_title ); ?>
                                    </a>
                                </h2>

                                <p class="text-slate-600 dark:text-slate-400 text-sm sm:text-base leading-relaxed mb-6 ofp-line-clamp-3">
                                    <?php echo esc_html( $f_excerpt ); ?>
                                </p>
                            </div>

                            <div class="pt-6 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <img src="<?php echo esc_url( $f_avatar ); ?>" alt="<?php echo esc_attr( $f_author ); ?>"
                                         class="w-10 h-10 rounded-full object-cover border-2 border-slate-200 dark:border-slate-700">
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-wider text-slate-900 dark:text-white"><?php echo esc_html( $f_author ); ?></p>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400">Senior Contributor</p>
                                    </div>
                                </div>

                                <a href="<?php echo esc_url( $f_permalink ); ?>"
                                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs tracking-wider uppercase shadow-md shadow-blue-500/20 transition-all group-hover:translate-x-1">
                                    <span>Read Story</span>
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- ── BLOG LOOP GRID ── -->
        <div class="mb-8 flex items-center justify-between">
            <h3 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight">
                <?php
                if ( is_search() ) {
                    echo 'Search Results (' . (int) $wp_query->found_posts . ')';
                } elseif ( $is_first_page_index ) {
                    echo 'Latest Stories &amp; Reports';
                } else {
                    echo 'Articles — Page ' . (int) $current_page;
                }
                ?>
            </h3>
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">
                <?php echo (int) $wp_query->found_posts; ?> Total Articles
            </span>
        </div>

        <?php if ( ! empty( $grid_posts ) ) : ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php
                $card_idx = 0;
                foreach ( $grid_posts as $post_item ) :
                    $post_id   = $post_item->ID;
                    $permalink = get_permalink( $post_id );
                    $title     = get_the_title( $post_id );
                    $excerpt   = get_the_excerpt( $post_id ) ?: wp_trim_words( strip_shortcodes( $post_item->post_content ), 22, '...' );
                    $author_id = $post_item->post_author;
                    $author    = get_the_author_meta( 'display_name', $author_id );
                    $avatar    = get_avatar_url( $author_id, [ 'size' => 64 ] );
                    $date      = get_the_date( 'M j, Y', $post_id );
                    $read_time = max( 1, (int) ceil( str_word_count( strip_tags( $post_item->post_content ) ) / 200 ) );

                    $thumb = has_post_thumbnail( $post_id )
                        ? get_the_post_thumbnail_url( $post_id, 'medium_large' )
                        : $fallback_images[ $card_idx % count( $fallback_images ) ];

                    $cats = get_the_category( $post_id );
                    $cat_name = ! empty( $cats ) ? $cats[0]->name : 'Real Estate';
                    $card_idx++;
                ?>
                    <article class="group relative rounded-2xl overflow-hidden border border-slate-200/90 dark:border-slate-800 bg-white dark:bg-slate-900/80 shadow-sm hover:shadow-xl hover:border-blue-500/50 hover:-translate-y-1.5 transition-all duration-300 flex flex-col">
                        <a href="<?php echo esc_url( $permalink ); ?>" class="relative block aspect-[16/10] overflow-hidden bg-slate-900">
                            <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $title ); ?>"
                                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>

                            <span class="absolute top-3 left-3 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wider bg-white/95 dark:bg-slate-900/95 text-blue-600 dark:text-blue-400 shadow-sm">
                                <?php echo esc_html( $cat_name ); ?>
                            </span>

                            <span class="absolute bottom-3 right-3 px-2.5 py-0.5 rounded-md text-[10px] font-bold text-white bg-black/60 backdrop-blur-md">
                                <?php echo esc_html( $read_time ); ?> min read
                            </span>
                        </a>

                        <div class="p-5 sm:p-6 flex-1 flex flex-col justify-between">
                            <div>
                                <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-2">
                                    <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    <span><?php echo esc_html( $date ); ?></span>
                                </div>

                                <h4 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white leading-snug mb-3 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors ofp-line-clamp-2">
                                    <a href="<?php echo esc_url( $permalink ); ?>">
                                        <?php echo esc_html( $title ); ?>
                                    </a>
                                </h4>

                                <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-400 leading-relaxed mb-6 ofp-line-clamp-3">
                                    <?php echo esc_html( $excerpt ); ?>
                                </p>
                            </div>

                            <div class="pt-4 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between mt-auto">
                                <div class="flex items-center gap-2.5">
                                    <img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $author ); ?>"
                                         class="w-7 h-7 rounded-full object-cover border border-slate-200 dark:border-slate-700">
                                    <span class="text-xs font-bold text-slate-700 dark:text-slate-300"><?php echo esc_html( $author ); ?></span>
                                </div>

                                <a href="<?php echo esc_url( $permalink ); ?>"
                                   class="text-xs font-black uppercase tracking-wider text-blue-600 dark:text-blue-400 flex items-center gap-1 group-hover:translate-x-1 transition-transform">
                                    <span>Read</span>
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <!-- ── 2 ROUNDED BUTTONS PAGINATION (ARROW LEFT & RIGHT) ── -->
            <?php if ( $total_pages > 1 ) :
                $prev_url = ( $current_page > 1 ) ? get_pagenum_link( $current_page - 1 ) : null;
                $next_url = ( $current_page < $total_pages ) ? get_pagenum_link( $current_page + 1 ) : null;
            ?>
                <div class="mt-14 pt-8 border-t border-slate-200/80 dark:border-slate-800/80 flex items-center justify-center gap-4">
                    <?php if ( $prev_url ) : ?>
                        <a href="<?php echo esc_url( $prev_url ); ?>"
                           class="w-12 h-12 rounded-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 hover:bg-blue-600 hover:text-white hover:border-blue-600 flex items-center justify-center shadow-md transition-all group"
                           aria-label="Previous Page" title="Previous Page">
                            <svg class="w-5 h-5 transition-transform group-hover:-translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </a>
                    <?php else : ?>
                        <span class="w-12 h-12 rounded-full border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-900/50 text-slate-400 dark:text-slate-600 flex items-center justify-center cursor-not-allowed">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </span>
                    <?php endif; ?>

                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 px-3">
                        Page <?php echo (int) $current_page; ?> of <?php echo (int) $total_pages; ?>
                    </span>

                    <?php if ( $next_url ) : ?>
                        <a href="<?php echo esc_url( $next_url ); ?>"
                           class="w-12 h-12 rounded-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 hover:bg-blue-600 hover:text-white hover:border-blue-600 flex items-center justify-center shadow-md transition-all group"
                           aria-label="Next Page" title="Next Page">
                            <svg class="w-5 h-5 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>
                    <?php else : ?>
                        <span class="w-12 h-12 rounded-full border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-900/50 text-slate-400 dark:text-slate-600 flex items-center justify-center cursor-not-allowed">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                            </svg>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php elseif ( ! $featured_post ) : ?>
            <div class="text-center py-20 px-4 rounded-3xl border border-dashed border-slate-300 dark:border-slate-800 bg-white/50 dark:bg-slate-900/50">
                <h4 class="text-xl font-bold text-slate-900 dark:text-white mb-2">No articles found</h4>
                <p class="text-sm text-slate-500 dark:text-slate-400 max-w-md mx-auto mb-6">
                    Articles and market updates will appear here once published from the WordPress admin panel.
                </p>
                <a href="<?php echo esc_url( home_url('/blog') ); ?>"
                   class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs tracking-wider uppercase transition-colors">
                    <span>View All Articles</span>
                </a>
            </div>
        <?php endif; ?>

    </main>

    <!-- 13. Footer (Exact Homepage Global Footer) -->
    <footer class="bg-slate-50 dark:bg-slate-950 text-slate-600 dark:text-slate-400 mt-auto"
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
                        <a href="<?php echo esc_url( home_url('/') ); ?>" class="inline-flex items-center gap-3 mb-6">
                            <img src="<?php echo esc_url( OFP_Brand::logo_url() ); ?>" alt="<?php echo esc_attr( OFP_Brand::name() ); ?>" class="h-9 sm:h-10 w-auto object-contain">
                            <span style="font-size:18px;font-weight:800" class="tracking-tight text-slate-900 dark:text-white">
                                <?php echo esc_html( OFP_Brand::public_name_text( 'MyPropertyHQ' ) ); ?>
                            </span>
                        </a>
                        <p class="text-slate-500 dark:text-slate-400 leading-relaxed mb-6">
                            Nigeria's leading verified real estate marketplace and integrated broker CRM portal. Fast,
                            transparent property acquisitions.
                        </p>
                        <div class="space-y-3 text-slate-500 dark:text-slate-400 text-[12px]">
                            <div class="flex items-start gap-3">
                                <svg class="w-3.5 h-3.5 text-blue-500 shrink-0 mt-0.5" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z">
                                    </path>
                                </svg>
                                <span><?php echo esc_html( OFP_Brand::office_address() ); ?></span>
                            </div>
                            <div class="flex items-center gap-3">
                                <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z">
                                    </path>
                                </svg>
                                <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', OFP_Brand::support_phone() ) ); ?>" class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors"><?php echo esc_html( OFP_Brand::support_phone() ); ?></a>
                            </div>
                            <div class="flex items-center gap-3">
                                <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                    </path>
                                </svg>
                                <a href="mailto:<?php echo esc_attr( OFP_Brand::support_email() ); ?>" class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors"><?php echo esc_html( OFP_Brand::support_email() ); ?></a>
                            </div>
                            <?php if ( OFP_Brand::platform_email() && OFP_Brand::platform_email() !== OFP_Brand::support_email() ) : ?>
                            <div class="flex items-center gap-3">
                                <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207">
                                    </path>
                                </svg>
                                <a href="mailto:<?php echo esc_attr( OFP_Brand::platform_email() ); ?>" class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors"><?php echo esc_html( OFP_Brand::platform_email() ); ?></a>
                            </div>
                            <?php endif; ?>
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
                            <li><a href="<?php echo esc_url( home_url('/login') ); ?>" class="hover:text-slate-900 dark:hover:text-white transition-colors">Agent /
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
                <p><?php echo OFP_Brand::public_copyright(); ?></p>
            </div>
        </div>
    </footer>

    <?php wp_footer(); ?>
</body>
</html>
