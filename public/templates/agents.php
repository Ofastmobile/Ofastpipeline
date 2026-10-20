<?php
/**
 * Template: /agents (Our Agents Directory)
 *
 * Public directory showcasing verified real estate agencies, licensed brokers,
 * and property developers with active listings.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

// ── Query Parameters ────────────────────────────────────────────────────────
$search         = sanitize_text_field( $_GET['s'] ?? $_GET['q'] ?? '' );
$verified_only  = ! empty( $_GET['verified'] );
$sort           = sanitize_text_field( $_GET['sort'] ?? 'properties' );

// ── Build Database Query ────────────────────────────────────────────────────
$where_clauses = [ "c.status = 'active'", "(c.trashed_at IS NULL OR c.trashed_at = '0000-00-00 00:00:00')" ];
$where_params  = [];

if ( $verified_only ) {
    $where_clauses[] = "c.is_verified = 1";
}

if ( ! empty( $search ) ) {
    $where_clauses[] = "(c.business_name LIKE %s OR c.owner_name LIKE %s OR c.bio LIKE %s)";
    $like = '%' . $wpdb->esc_like( $search ) . '%';
    $where_params[] = $like;
    $where_params[] = $like;
    $where_params[] = $like;
}

$where_sql = implode( ' AND ', $where_clauses );

// Sorting SQL
switch ( $sort ) {
    case 'verified':
        $order_sql = "c.is_verified DESC, total_listings DESC, c.id DESC";
        break;
    case 'name':
        $order_sql = "COALESCE(NULLIF(c.business_name, ''), c.owner_name) ASC";
        break;
    case 'newest':
        $order_sql = "c.created_at DESC";
        break;
    case 'properties':
    default:
        $order_sql = "total_listings DESC, c.is_verified DESC, c.id DESC";
        break;
}

$p = $wpdb->prefix;
$sql = "
    SELECT c.*,
           COALESCE(p_count.total_listings, 0) AS total_listings,
           COALESCE(p_count.verified_listings, 0) AS verified_listings
    FROM {$p}ofp_clients c
    LEFT JOIN (
        SELECT pm.meta_value AS client_id,
               COUNT(DISTINCT p.ID) AS total_listings,
               COUNT(DISTINCT CASE WHEN pm_v.meta_value = '1' THEN p.ID END) AS verified_listings
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'ofp_client_id'
        LEFT JOIN {$wpdb->postmeta} pm_v ON pm_v.post_id = p.ID AND pm_v.meta_key = 'ofp_is_verified'
        WHERE p.post_type = 'ofp_property' AND p.post_status = 'publish'
        GROUP BY pm.meta_value
    ) p_count ON p_count.client_id = CAST(c.id AS CHAR)
    WHERE {$where_sql}
    ORDER BY {$order_sql}
";

if ( ! empty( $where_params ) ) {
    $agents = $wpdb->get_results( $wpdb->prepare( $sql, ...$where_params ) );
} else {
    $agents = $wpdb->get_results( $sql );
}

// Global stats for hero banner
$total_agents   = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$p}ofp_clients WHERE status = 'active' AND (trashed_at IS NULL OR trashed_at = '0000-00-00 00:00:00')" );
$verified_count = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$p}ofp_clients WHERE status = 'active' AND is_verified = 1 AND (trashed_at IS NULL OR trashed_at = '0000-00-00 00:00:00')" );
$total_props    = (int) wp_count_posts( 'ofp_property' )->publish;
?>
<!DOCTYPE html>
<html lang="en"
    x-data="{
        mobileMenu: false,
        megaMenuOpen: false,
        searchQuery: '<?php echo esc_js( $search ); ?>',
        verifiedOnly: <?php echo $verified_only ? 'true' : 'false'; ?>,
        sortBy: '<?php echo esc_js( $sort ); ?>',
        darkMode: document.documentElement.classList.contains('dark'),
        toggleDark() { ofpToggleTheme(); }
    }">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Our Agents & Real Estate Agencies' ) ); ?></title>
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
                        sans: ['"Plus Jakarta Sans"', '"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'sans-serif'],
                        system: ['"Plus Jakarta Sans"', '"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'sans-serif']
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
                            sans: ['"Plus Jakarta Sans"', '"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'sans-serif'],
                            system: ['"Plus Jakarta Sans"', '"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'sans-serif']
                        }
                    }
                }
            };
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap');
        [x-cloak] { display: none !important; }
        .theme-toggle-sun { display: none; }
        .dark .theme-toggle-sun { display: inline-block; }
        .dark .theme-toggle-moon { display: none; }
        .theme-toggle-moon { display: inline-block; }
    </style>
</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 transition-colors duration-300 font-sans antialiased selection:bg-blue-600 selection:text-white">

    <!-- Mega Menu Backdrop Overlay (Desktop) -->
    <div x-show="megaMenuOpen" class="hidden lg:block fixed inset-0 bg-slate-900/30 backdrop-blur-md z-40"
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;"
        @click="megaMenuOpen = false"></div>

    <!-- ── Top Navigation Bar ─────────────────────────────────────────────── -->
    <header class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 transition-all duration-300">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="flex justify-between items-center h-16">
                <!-- Left: Logo & Hamburger -->
                <div class="flex items-center gap-3 sm:gap-5">
                    <a href="<?php echo esc_url( home_url('/') ); ?>" class="flex items-center">
                        <span class="text-[13px] sm:text-sm font-black tracking-[0.25em] text-slate-900 dark:text-white uppercase transition-colors">
                            <?php echo OFP_Brand::public_logo_html( 'BOFAST HOMES' ); ?>
                        </span>
                    </a>

                    <!-- Mega Menu Hamburger Button -->
                    <button @click="megaMenuOpen = !megaMenuOpen"
                        class="bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg px-2.5 py-1.5 text-slate-700 dark:text-slate-300 transition-colors flex items-center justify-center cursor-pointer border border-slate-200 dark:border-slate-700">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path x-show="!megaMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"></path>
                            <path x-show="megaMenuOpen" style="display: none;" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Center Nav: Quick Links -->
                <nav class="hidden md:flex items-center gap-6 text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                    <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>" class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors">Marketplace</a>
                    <a href="<?php echo esc_url( home_url('/agents') ); ?>" class="text-blue-600 dark:text-blue-400 font-extrabold flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span>
                        Our Agents
                    </a>
                    <a href="<?php echo esc_url( home_url('/marketplace/?listing_type=sale') ); ?>" class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors">For Sale</a>
                    <a href="<?php echo esc_url( home_url('/marketplace/?listing_type=rent') ); ?>" class="hover:text-blue-600 dark:hover:text-blue-400 transition-colors">For Rent</a>
                </nav>

                <!-- Right: Action Button -->
                <div class="flex items-center gap-2 sm:gap-4">
                    <button type="button" onclick="ofpToggleTheme()" aria-label="Toggle Dark Mode"
                        class="p-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-all cursor-pointer">
                        <svg class="w-4 h-4 theme-toggle-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                        </svg>
                        <svg class="w-4 h-4 text-amber-400 theme-toggle-sun" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                    </button>
                    <!-- List Property / Join Agent Button -->
                    <a href="<?php echo esc_url( home_url( '/login' ) ); ?>"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-5 sm:px-7 py-2 sm:py-2.5 rounded-[7px] text-[11px] sm:text-xs tracking-wider shadow-lg shadow-blue-500/20 transition-all cursor-pointer whitespace-nowrap uppercase inline-flex items-center justify-center">
                        Agent Login
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

                <div class="p-8 grid grid-cols-4 gap-8">
                    <!-- Image Cards Area (Takes 3 columns) -->
                    <div class="col-span-3 grid grid-cols-4 gap-4">
                        <!-- Card 1: Properties -->
                        <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=600&q=80"
                                alt="Properties" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide group-hover:text-blue-400 transition-colors">PROPERTIES</div>
                        </a>

                        <!-- Card 2: Off-Plan -->
                        <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1503387762-592deb58ef4e?auto=format&fit=crop&w=600&q=80"
                                alt="Off-Plan" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide group-hover:text-blue-400 transition-colors z-30">OFF-PLAN</div>
                        </a>

                        <!-- Card 3: Map -->
                        <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-blue-50 dark:bg-blue-900/20">
                            <div class="absolute inset-0 bg-blue-500/10 group-hover:bg-blue-500/20 transition-colors z-10"
                                style="background-image: radial-gradient(circle, #3B82F6 1px, transparent 1px); background-size: 20px 20px;"></div>
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent z-20"></div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide group-hover:text-blue-400 transition-colors z-30">MAP SEARCH</div>
                        </a>

                        <!-- Card 4: Agents (Active) -->
                        <a href="<?php echo esc_url( home_url('/agents') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square border-2 border-blue-500 bg-slate-100 dark:bg-slate-800 shadow-lg shadow-blue-500/20">
                            <img src="https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=600&q=80"
                                alt="Agents" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/30 to-transparent"></div>
                            <div class="absolute top-3 right-3 px-2 py-0.5 rounded-full bg-blue-600 text-white text-[10px] font-black uppercase tracking-wider">Active</div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide text-blue-400">OUR AGENTS</div>
                        </a>
                    </div>

                    <!-- Side Links -->
                    <div class="flex flex-col justify-between h-full pl-8 border-l border-slate-200 dark:border-slate-800">
                        <div class="space-y-6">
                            <ul class="space-y-4">
                                <li><a href="<?php echo esc_url( home_url('/signup') ); ?>"
                                        class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-xs tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                            class="w-2 h-2 rounded-full bg-blue-500"></span> BECOME AN AGENT</a></li>
                                <li><a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                                        class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-xs tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                            class="w-2 h-2 rounded-full bg-blue-500"></span> EXPLORE PROPERTIES</a></li>
                                <li><a href="<?php echo esc_url( home_url('/login') ); ?>"
                                        class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-xs tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                            class="w-2 h-2 rounded-full bg-blue-500"></span> CLIENT PORTAL</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- ── Hero Banner Section ────────────────────────────────────────────── -->
    <section class="relative overflow-hidden bg-gradient-to-b from-blue-900 via-slate-900 to-slate-950 text-white py-16 sm:py-24 border-b border-slate-800">
        <!-- Ambient decorative blurs -->
        <div class="absolute -top-32 -left-32 w-96 h-96 bg-blue-500/15 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute top-1/2 -right-32 w-96 h-96 bg-indigo-500/15 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute inset-0 opacity-5" style="background-image: radial-gradient(#fff 1px, transparent 1px); background-size: 28px 28px;"></div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <!-- Badge -->
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-blue-500/15 border border-blue-400/30 text-blue-300 text-xs font-bold uppercase tracking-wider mb-6">
                <svg class="w-3.5 h-3.5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <span>Vetted Agency Network</span>
            </div>

            <h1 class="text-3xl sm:text-5xl lg:text-6xl font-black tracking-tight max-w-3xl mx-auto leading-tight sm:leading-tight mb-4">
                Connect With Our <span class="bg-gradient-to-r from-blue-400 via-sky-300 to-indigo-300 bg-clip-text text-transparent">Verified Real Estate</span> Partners
            </h1>
            <p class="text-slate-300 text-base sm:text-lg max-w-2xl mx-auto mb-10 leading-relaxed font-normal">
                Discover registered brokerages, certified real estate consultants, and premier property developers delivering secure land and home transactions.
            </p>

            <!-- KPI Summary Bar -->
            <div class="grid grid-cols-3 max-w-xl mx-auto divide-x divide-slate-800 bg-slate-900/70 backdrop-blur-md rounded-2xl border border-slate-800 py-3.5 px-2 shadow-2xl mb-8">
                <div>
                    <div class="text-2xl sm:text-3xl font-black text-white"><?php echo number_format( $total_agents ); ?></div>
                    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">Agencies</div>
                </div>
                <div>
                    <div class="text-2xl sm:text-3xl font-black text-emerald-400 flex items-center justify-center gap-1">
                        <span><?php echo number_format( $verified_count ); ?></span>
                        <svg class="w-4 h-4 text-emerald-400 inline" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    </div>
                    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">Verified KYB</div>
                </div>
                <div>
                    <div class="text-2xl sm:text-3xl font-black text-blue-400"><?php echo number_format( $total_props ); ?></div>
                    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">Listings</div>
                </div>
            </div>

            <!-- Search and Filter Form Bar -->
            <form method="GET" action="<?php echo esc_url( home_url('/agents') ); ?>" class="max-w-3xl mx-auto">
                <div class="flex flex-col sm:flex-row items-center gap-3 bg-white dark:bg-slate-900 p-2.5 sm:p-3 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-2xl text-left">
                    <!-- Search Input -->
                    <div class="relative flex-1 w-full">
                        <svg class="w-5 h-5 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
                            placeholder="Search agency name, agent, or location..."
                            class="w-full pl-10 pr-4 py-2.5 bg-transparent text-slate-900 dark:text-white placeholder-slate-400 text-sm focus:outline-none font-medium">
                    </div>

                    <!-- Verified Only Toggle -->
                    <label class="flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 hover:bg-slate-200 dark:hover:bg-slate-800 text-xs font-bold text-slate-700 dark:text-slate-300 cursor-pointer select-none transition-colors whitespace-nowrap">
                        <input type="checkbox" name="verified" value="1" <?php checked( $verified_only, true ); ?> onchange="this.form.submit()" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                        <span class="flex items-center gap-1">
                            <span class="text-emerald-500">✓</span> Verified Only
                        </span>
                    </label>

                    <!-- Sort Dropdown -->
                    <select name="sort" onchange="this.form.submit()" class="px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800/80 text-xs font-bold text-slate-700 dark:text-slate-300 border-none focus:ring-2 focus:ring-blue-500 cursor-pointer">
                        <option value="properties" <?php selected( $sort, 'properties' ); ?>>Most Listings</option>
                        <option value="verified" <?php selected( $sort, 'verified' ); ?>>Verified First</option>
                        <option value="name" <?php selected( $sort, 'name' ); ?>>Alphabetical (A-Z)</option>
                        <option value="newest" <?php selected( $sort, 'newest' ); ?>>Newest Joined</option>
                    </select>

                    <!-- Submit Button -->
                    <button type="submit" class="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider transition-colors shadow-md shadow-blue-500/25">
                        Filter
                    </button>
                </div>
            </form>
        </div>
    </section>

    <!-- ── Main Agent Directory Section ───────────────────────────────────── -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 sm:py-16">
        <!-- Results header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div>
                <h2 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white">
                    <?php if ( ! empty( $search ) ) : ?>
                        Search Results for "<?php echo esc_html( $search ); ?>"
                    <?php elseif ( $verified_only ) : ?>
                        Verified Real Estate Agencies
                    <?php else : ?>
                        All Real Estate Agencies & Partners
                    <?php endif; ?>
                </h2>
                <p class="text-slate-500 dark:text-slate-400 text-xs sm:text-sm mt-0.5">
                    Showing <strong><?php echo count( $agents ); ?></strong> registered real estate partner<?php echo count( $agents ) === 1 ? '' : 's'; ?>
                </p>
            </div>

            <!-- Active filter chips -->
            <?php if ( ! empty( $search ) || $verified_only || $sort !== 'properties' ) : ?>
                <div class="flex items-center gap-2">
                    <a href="<?php echo esc_url( home_url('/agents') ); ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 hover:bg-rose-100 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        <span>Reset Filters</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ( empty( $agents ) ) : ?>
            <!-- Empty State -->
            <div class="text-center py-20 px-4 bg-white dark:bg-slate-900/60 rounded-3xl border border-slate-200 dark:border-slate-800 shadow-sm max-w-xl mx-auto my-8">
                <div class="w-16 h-16 rounded-2xl bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                </div>
                <h3 class="text-lg font-black text-slate-900 dark:text-white mb-2">No Agencies Found</h3>
                <p class="text-slate-500 dark:text-slate-400 text-sm max-w-md mx-auto mb-6">
                    We couldn't find any real estate agencies matching your search criteria. Try relaxing your filters or search keywords.
                </p>
                <a href="<?php echo esc_url( home_url('/agents') ); ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider transition-colors shadow-md shadow-blue-500/20">
                    View All Agencies
                </a>
            </div>
        <?php else : ?>
            <!-- Agent Cards Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
                <?php foreach ( $agents as $agent ) : 
                    $agent_name     = ! empty( $agent->business_name ) ? $agent->business_name : $agent->owner_name;
                    $owner_name     = $agent->owner_name;
                    $is_verified    = ! empty( $agent->is_verified );
                    $total_listings = (int) $agent->total_listings;
                    $verified_props = (int) $agent->verified_listings;
                    $slug           = ! empty( $agent->profile_slug ) ? $agent->profile_slug : $agent->id;
                    $profile_url    = home_url( '/agent/' . $slug );
                    $agent_logo     = ! empty( $agent->logo_url ) ? $agent->logo_url : '';
                    $bio            = ! empty( $agent->bio ) ? $agent->bio : 'Professional real estate agency delivering prime residential homes, land investments, and commercial spaces with guaranteed trust and verified documentation.';

                    // Extra contacts
                    $extra = ! empty( $agent->profile_extra ) ? json_decode( $agent->profile_extra, true ) : [];
                    $phone = ! empty( $agent->phone ) ? $agent->phone : ( $agent->support_phone ?? '' );

                    // WhatsApp link resolution
                    $clean_wa = '';
                    if ( ! empty( $extra['whatsapp_url'] ) && str_starts_with( $extra['whatsapp_url'], 'http' ) ) {
                        $wa_url = $extra['whatsapp_url'];
                    } else {
                        $raw_wa = $extra['whatsapp'] ?? ( $agent->whatsapp_number ?? $phone );
                        $clean_wa = preg_replace( '/[^0-9]/', '', $raw_wa );
                        if ( ! empty( $clean_wa ) ) {
                            if ( str_starts_with( $clean_wa, '0' ) ) {
                                $clean_wa = '234' . substr( $clean_wa, 1 );
                            }
                            $wa_url = 'https://wa.me/' . $clean_wa . '?text=' . rawurlencode( "Hello {$agent_name}, I saw your agency profile on " . OFP_Brand::name() . " and would like to inquire about your property listings." );
                        } else {
                            $wa_url = '';
                        }
                    }

                    // Initials for avatar fallback
                    $initials = '';
                    $words = explode( ' ', trim( $agent_name ) );
                    foreach ( array_slice( $words, 0, 2 ) as $w ) {
                        $initials .= strtoupper( substr( $w, 0, 1 ) );
                    }
                ?>
                    <article class="group relative flex flex-col justify-between bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800/80 hover:border-blue-500/50 dark:hover:border-blue-500/50 shadow-sm hover:shadow-xl transition-all duration-300 overflow-hidden">
                        
                        <!-- Top Accent Banner -->
                        <div class="h-24 bg-gradient-to-r from-blue-600 via-indigo-600 to-sky-500 relative">
                            <div class="absolute inset-0 bg-black/10"></div>
                            
                            <!-- Badges Top Right -->
                            <div class="absolute top-3.5 right-3.5 flex items-center gap-1.5">
                                <?php if ( $is_verified ) : ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10.5px] font-black uppercase tracking-wider bg-emerald-500 text-white shadow-sm">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                        Verified
                                    </span>
                                <?php else : ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-black/35 backdrop-blur-md text-white/90 border border-white/10">
                                        Partner
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Body -->
                        <div class="px-6 pb-6 pt-0 relative flex-1 flex flex-col">
                            
                            <!-- Avatar / Logo -->
                            <div class="-mt-12 mb-4 flex items-end justify-between">
                                <a href="<?php echo esc_url( $profile_url ); ?>" class="block relative w-20 h-20 rounded-2xl overflow-hidden border-4 border-white dark:border-slate-900 bg-white dark:bg-slate-800 shadow-md group-hover:scale-105 transition-transform duration-300">
                                    <?php if ( ! empty( $agent_logo ) ) : ?>
                                        <img src="<?php echo esc_url( $agent_logo ); ?>" alt="<?php echo esc_attr( $agent_name ); ?>" class="w-full h-full object-cover">
                                    <?php else : ?>
                                        <div class="w-full h-full bg-gradient-to-tr from-blue-600 to-indigo-700 text-white font-black text-xl flex items-center justify-center select-none">
                                            <?php echo esc_html( $initials ?: 'AG' ); ?>
                                        </div>
                                    <?php endif; ?>
                                </a>

                                <!-- Listings Pill -->
                                <div class="text-right">
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-black bg-blue-50 dark:bg-blue-950/50 text-blue-700 dark:text-blue-300 border border-blue-200/60 dark:border-blue-900/60">
                                        <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                                        <span><?php echo number_format( $total_listings ); ?> <?php echo $total_listings === 1 ? 'Listing' : 'Listings'; ?></span>
                                    </span>
                                </div>
                            </div>

                            <!-- Agency Info -->
                            <div class="mb-3">
                                <h3 class="text-lg font-black text-slate-900 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors line-clamp-1">
                                    <a href="<?php echo esc_url( $profile_url ); ?>"><?php echo esc_html( $agent_name ); ?></a>
                                </h3>
                                <?php if ( ! empty( $owner_name ) && $owner_name !== $agent_name ) : ?>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 font-semibold mt-0.5">
                                        Principal: <span class="text-slate-700 dark:text-slate-300"><?php echo esc_html( $owner_name ); ?></span>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <!-- Bio Snippet -->
                            <p class="text-xs text-slate-600 dark:text-slate-400 line-clamp-3 leading-relaxed mb-5 flex-1">
                                <?php echo esc_html( wp_strip_all_tags( $bio ) ); ?>
                            </p>

                            <!-- Meta stats bar -->
                            <div class="pt-3.5 border-t border-slate-100 dark:border-slate-800/80 mb-5 flex items-center justify-between text-[11px] font-bold text-slate-500 dark:text-slate-400">
                                <span class="flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span><?php echo number_format( $verified_props ); ?> Verified Titles</span>
                                </span>
                                <?php if ( ! empty( $agent->subdomain ) ) : ?>
                                    <span class="text-blue-600 dark:text-blue-400">
                                        <?php echo esc_html( $agent->subdomain ); ?>.crm
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Action Buttons -->
                            <div class="grid grid-cols-4 gap-2 pt-1">
                                <!-- WhatsApp button -->
                                <?php if ( ! empty( $wa_url ) ) : ?>
                                    <a href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer"
                                       class="col-span-1 flex items-center justify-center py-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500 hover:text-white transition-colors border border-emerald-200/80 dark:border-emerald-800/80"
                                       title="Chat on WhatsApp">
                                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                                    </a>
                                <?php endif; ?>

                                <!-- Call button -->
                                <?php if ( ! empty( $phone ) ) : ?>
                                    <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"
                                       class="col-span-1 flex items-center justify-center py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-blue-600 hover:text-white transition-colors border border-slate-200 dark:border-slate-700"
                                       title="Call Agency">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                    </a>
                                <?php endif; ?>

                                <!-- View Profile & Listings Button -->
                                <a href="<?php echo esc_url( $profile_url ); ?>"
                                   class="<?php echo ( ! empty( $wa_url ) && ! empty( $phone ) ) ? 'col-span-2' : 'col-span-4'; ?> flex items-center justify-center gap-1.5 py-2.5 px-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider transition-colors shadow-md shadow-blue-500/20 whitespace-nowrap">
                                    <span>View Profile</span>
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                </a>
                            </div>

                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- ── Join as Agent CTA Section ──────────────────────────────────────── -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-20">
        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-blue-700 via-indigo-700 to-sky-600 p-8 sm:p-14 text-white text-center sm:text-left flex flex-col sm:flex-row items-center justify-between gap-8 shadow-2xl">
            <div class="absolute -right-20 -bottom-20 w-80 h-80 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
            
            <div class="max-w-xl">
                <span class="inline-block px-3 py-1 rounded-full bg-white/15 backdrop-blur-md text-white text-xs font-bold uppercase tracking-wider mb-3">
                    Partner With <?php echo esc_html( OFP_Brand::name() ); ?>
                </span>
                <h3 class="text-2xl sm:text-4xl font-black tracking-tight leading-tight mb-3">
                    Are You a Licensed Agent or Property Developer?
                </h3>
                <p class="text-blue-100 text-sm sm:text-base leading-relaxed">
                    List your properties on Nigeria's fastest real estate marketplace, get instant SMS buyer inquiry notifications, and manage inspections on your dedicated CRM portal.
                </p>
            </div>

            <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
                <a href="<?php echo esc_url( home_url('/signup') ); ?>"
                   class="w-full sm:w-auto text-center px-8 py-3.5 rounded-xl bg-white text-blue-900 hover:bg-blue-50 font-black text-xs uppercase tracking-wider shadow-xl transition-all">
                    Register Your Agency
                </a>
                <a href="<?php echo esc_url( home_url('/login') ); ?>"
                   class="w-full sm:w-auto text-center px-6 py-3.5 rounded-xl bg-black/25 hover:bg-black/40 text-white font-bold text-xs uppercase tracking-wider border border-white/20 transition-all">
                    Sign In to Portal
                </a>
            </div>
        </div>
    </section>

    <!-- ── Footer ─────────────────────────────────────────────────────────── -->
    <footer class="bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 text-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-10">
                <!-- Col 1: Brand -->
                <div class="lg:col-span-2 space-y-4">
                    <span class="text-base font-black tracking-[0.25em] text-slate-900 dark:text-white uppercase">
                        <?php echo OFP_Brand::public_logo_html( 'BOFAST HOMES' ); ?>
                    </span>
                    <p class="text-xs text-slate-500 dark:text-slate-400 max-w-sm leading-relaxed">
                        Nigeria's premier verified property marketplace and real estate CRM pipeline. Connecting buyers, tenants, and institutional investors with verified titles.
                    </p>
                    <div class="pt-2 text-xs text-slate-400">
                        <span>All listings strictly verified against state land registry archives.</span>
                    </div>
                </div>

                <!-- Col 2: Navigation -->
                <div>
                    <h4 class="text-xs font-black uppercase tracking-wider text-slate-900 dark:text-white mb-4">Marketplace</h4>
                    <ul class="space-y-2.5 text-xs text-slate-500 dark:text-slate-400">
                        <li><a href="<?php echo esc_url( home_url('/marketplace/') ); ?>" class="hover:text-blue-600 dark:hover:text-white transition-colors">All Properties</a></li>
                        <li><a href="<?php echo esc_url( home_url('/marketplace/?listing_type=sale') ); ?>" class="hover:text-blue-600 dark:hover:text-white transition-colors">Properties for Sale</a></li>
                        <li><a href="<?php echo esc_url( home_url('/marketplace/?listing_type=rent') ); ?>" class="hover:text-blue-600 dark:hover:text-white transition-colors">Properties for Rent</a></li>
                        <li><a href="<?php echo esc_url( home_url('/marketplace/?verified=1') ); ?>" class="hover:text-blue-600 dark:hover:text-white transition-colors">Verified Only</a></li>
                    </ul>
                </div>

                <!-- Col 3: Agents -->
                <div>
                    <h4 class="text-xs font-black uppercase tracking-wider text-slate-900 dark:text-white mb-4">Agents & Partners</h4>
                    <ul class="space-y-2.5 text-xs text-slate-500 dark:text-slate-400">
                        <li><a href="<?php echo esc_url( home_url('/agents') ); ?>" class="hover:text-blue-600 dark:hover:text-white transition-colors">Agency Directory</a></li>
                        <li><a href="<?php echo esc_url( home_url('/agents?verified=1') ); ?>" class="hover:text-blue-600 dark:hover:text-white transition-colors">Verified Agencies</a></li>
                        <li><a href="<?php echo esc_url( home_url('/signup') ); ?>" class="hover:text-blue-600 dark:hover:text-white transition-colors">Join as Agent</a></li>
                        <li><a href="<?php echo esc_url( home_url('/login') ); ?>" class="hover:text-blue-600 dark:hover:text-white transition-colors">Client CRM Portal</a></li>
                    </ul>
                </div>

                <!-- Col 4: Platform -->
                <div>
                    <h4 class="text-xs font-black uppercase tracking-wider text-slate-900 dark:text-white mb-4">Platform</h4>
                    <ul class="space-y-2.5 text-xs text-slate-500 dark:text-slate-400">
                        <li><a href="<?php echo esc_url( home_url('/pricing') ); ?>" class="hover:text-blue-600 dark:hover:text-white transition-colors">Pricing & Plans</a></li>
                        <li><a href="<?php echo esc_url( home_url('/terms') ); ?>" class="hover:text-blue-600 dark:hover:text-white transition-colors">Terms of Service</a></li>
                        <li><a href="<?php echo esc_url( home_url('/privacy') ); ?>" class="hover:text-blue-600 dark:hover:text-white transition-colors">Privacy Policy</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="border-t border-slate-200 dark:border-slate-800 py-6">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-4 text-xs text-slate-500">
                <div class="flex items-center gap-2 text-emerald-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>All listings verified against state land registry archives.</span>
                </div>
                <p><?php echo OFP_Brand::public_copyright(); ?></p>
            </div>
        </div>
    </footer>

    <?php wp_footer(); ?>
</body>
</html>
