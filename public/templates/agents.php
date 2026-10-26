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

// Pre-fetch cover images for agent cards (profile cover_image_url, first property thumbnail, or default banner)
$default_agent_cover = ofp_image_url( 'dashboard-hero-banner.webp' );
$agent_cover_images = [];
foreach ( $agents as $ag ) {
    if ( ! empty( $ag->cover_image_url ) ) {
        $agent_cover_images[ $ag->id ] = $ag->cover_image_url;
    } else {
        // Fallback: first property thumbnail, else default image
        $cover_src = '';
        $first_prop_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'ofp_client_id'
             WHERE p.post_type = 'ofp_property' AND p.post_status = 'publish' AND pm.meta_value = %s
             ORDER BY p.post_date DESC LIMIT 1",
            (string) $ag->id
        ) );
        if ( $first_prop_id ) {
            $thumb_id = get_post_meta( $first_prop_id, 'ofp_main_image', true );
            if ( $thumb_id ) {
                $img_src = wp_get_attachment_image_url( $thumb_id, 'medium_large' );
                if ( $img_src ) {
                    $cover_src = $img_src;
                }
            }
        }
        $agent_cover_images[ $ag->id ] = ! empty( $cover_src ) ? $cover_src : $default_agent_cover;
    }
}

// Hero banner image from plugin assets (partnership & real estate deal theme)
$agents_hero_img = ofp_image_url( 'Real Estate Deal Handshake.webp' );
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

        /* Custom hero background with white overlay on light mode, dark overlay on dark mode */
        .agents-hero-bg {
            background-image: 
                linear-gradient(to bottom, rgba(255, 255, 255, 0.40) 0%, rgba(255, 255, 255, 0.70) 35%, rgba(255, 255, 255, 0.95) 80%, #ffffff 100%),
                url('<?php echo esc_url( $agents_hero_img ); ?>');
            background-size: cover;
            background-position: center;
            background-attachment: scroll;
        }
        html.dark .agents-hero-bg {
            background-image: 
                linear-gradient(to bottom, rgba(11, 17, 32, 0.55) 0%, rgba(11, 17, 32, 0.85) 50%, rgba(2, 6, 23, 0.98) 100%),
                url('<?php echo esc_url( $agents_hero_img ); ?>');
        }
    </style>
</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 transition-colors duration-300 font-sans antialiased selection:bg-blue-600 selection:text-white">

    <!-- Mega Menu Backdrop Overlay (Desktop) -->
    <div x-show="megaMenuOpen" class="hidden lg:block fixed inset-0 bg-slate-900/20 backdrop-blur-md z-40"
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;"
        @click="megaMenuOpen = false"></div>

    <!-- ── Top Navigation Bar ─────────────────────────────────────────────── -->
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
                    <!-- Logo (Hidden on mobile so only hamburger displays, visible from sm up) -->
                    <a href="<?php echo esc_url( home_url('/') ); ?>" class="hidden sm:flex items-center">
                        <span
                            class="text-[13px] sm:text-sm font-black tracking-[0.25em] text-slate-900 dark:text-white uppercase transition-colors">
                            <?php echo OFP_Brand::public_logo_html( 'BOFAST HOMES' ); ?>
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
                    <!-- List Property Button -->
                    <a href="<?php echo esc_url( home_url( '/login' ) ); ?>"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-5 sm:px-7 py-2 sm:py-2.5 rounded-[7px] text-[11px] sm:text-xs tracking-wider shadow-lg shadow-blue-500/20 transition-all cursor-pointer whitespace-nowrap uppercase inline-flex items-center justify-center">
                        List Property
                    </a>
                </div>
            </div>

            <?php $ofp_header_social = OFP_Brand::social_links(); ?>

            <!-- Desktop Mega Menu Dropdown (hidden lg:block) -->
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
                                alt="Properties"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide group-hover:text-blue-400 transition-colors">
                                PROPERTIES</div>
                        </a>

                        <!-- Card 2: Off-Plan -->
                        <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1503387762-592deb58ef4e?auto=format&fit=crop&w=600&q=80"
                                alt="Off-Plan"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide group-hover:text-blue-400 transition-colors z-30">
                                OFF-PLAN</div>
                        </a>

                        <!-- Card 3: Map -->
                        <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-blue-50 dark:bg-blue-900/20">
                            <div class="absolute inset-0 bg-blue-500/10 group-hover:bg-blue-500/20 transition-colors z-10"
                                style="background-image: radial-gradient(circle, #3B82F6 1px, transparent 1px); background-size: 20px 20px;"></div>
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent z-20"></div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide group-hover:text-blue-400 transition-colors z-30">
                                MAP SEARCH</div>
                        </a>

                        <!-- Card 4: Agents (Active) -->
                        <a href="<?php echo esc_url( home_url('/agents') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-square border-2 border-blue-500 bg-slate-100 dark:bg-slate-800 shadow-lg shadow-blue-500/20">
                            <img src="https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=600&q=80"
                                alt="Agents"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/30 to-transparent"></div>
                            <div class="absolute top-3 right-3 px-2 py-0.5 rounded-full bg-blue-600 text-white text-[10px] font-black uppercase tracking-wider">Active</div>
                            <div class="absolute bottom-4 left-4 text-white font-black text-lg uppercase tracking-wide text-blue-400">
                                OUR AGENTS</div>
                        </a>
                    </div>

                    <!-- Side Links (Takes 1 column) -->
                    <div class="flex flex-col justify-between h-full pl-8 border-l border-slate-200 dark:border-slate-800">
                        <div class="space-y-6">
                            <ul class="space-y-4">
                                <li><a href="<?php echo esc_url( home_url('/login') ); ?>"
                                        class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-xs tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                            class="w-2 h-2 rounded-full bg-blue-500"></span> BECOME A PARTNER</a></li>
                                <li><a href="<?php echo esc_url( home_url('/login') ); ?>"
                                        class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-xs tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                            class="w-2 h-2 rounded-full bg-blue-500"></span> DEVELOPERS HUB</a></li>
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
    <div x-show="megaMenuOpen" style="display: none;"
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

        <!-- Mobile Mid: 2x2 Visual Cards Grid -->
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

                <!-- Card 2: Off-Plan -->
                <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                    class="group relative h-20 sm:h-24 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-900">
                    <img src="https://images.unsplash.com/photo-1503387762-592deb58ef4e?auto=format&fit=crop&w=400&q=80"
                        alt="Off-Plan" class="w-full h-full object-cover opacity-60 group-hover:scale-105 transition-transform duration-300">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-transparent"></div>
                    <div class="absolute bottom-2.5 left-2.5 text-white font-black text-xs sm:text-sm uppercase tracking-wider group-hover:text-blue-400 transition-colors">
                        Off-Plan
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

            <!-- Secondary List Links -->
            <div class="py-1 divide-y divide-slate-100 dark:divide-slate-800/80">
                <a href="<?php echo esc_url( home_url('/login') ); ?>"
                    class="py-3 sm:py-3.5 flex items-center text-slate-800 dark:text-slate-200 font-bold text-xs uppercase tracking-wider hover:text-blue-600 transition-colors">
                    <span class="flex items-center gap-2.5">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                        Become a Partner
                    </span>
                </a>
                <a href="<?php echo esc_url( home_url('/login') ); ?>"
                    class="py-3 sm:py-3.5 flex items-center text-slate-800 dark:text-slate-200 font-bold text-xs uppercase tracking-wider hover:text-blue-600 transition-colors">
                    <span class="flex items-center gap-2.5">
                        <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                        Developers Hub
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
            <a href="<?php echo esc_url( home_url( '/login' ) ); ?>"
                class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3.5 px-6 rounded-[7px] text-center text-xs tracking-wider shadow-lg shadow-blue-500/20 transition-all uppercase">
                List Property
            </a>
        </div>
    </div>

    <!-- ── Hero Banner Section ────────────────────────────────────────────── -->
    <section class="agents-hero-bg relative overflow-hidden border-b border-slate-200 dark:border-slate-800 py-16 sm:py-24 transition-colors duration-300">
        <!-- Ambient decorative blurs -->
        <div class="absolute -top-32 -left-32 w-96 h-96 bg-blue-500/10 dark:bg-blue-500/15 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute top-1/2 -right-32 w-96 h-96 bg-indigo-500/10 dark:bg-indigo-500/15 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <!-- Badge -->
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white/90 dark:bg-slate-900/80 border border-slate-200 dark:border-white/20 backdrop-blur-md text-slate-800 dark:text-blue-300 text-xs font-bold uppercase tracking-wider mb-6 shadow-sm">
                <svg class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <span>Vetted Agency Network</span>
            </div>

            <h1 class="text-3xl sm:text-5xl lg:text-6xl font-black tracking-tight max-w-3xl mx-auto leading-tight sm:leading-tight mb-4 text-slate-950 dark:text-white drop-shadow-sm">
                Connect With Our <span class="text-blue-600 dark:text-blue-500">Verified Real Estate</span> Partners
            </h1>
            <p class="text-slate-700 dark:text-slate-300 text-base sm:text-lg max-w-2xl mx-auto mb-10 leading-relaxed font-normal">
                Discover registered brokerages, certified real estate consultants, and premier property developers delivering secure land and home transactions.
            </p>

            <!-- Search and Filter Form Bar -->
            <form method="GET" action="<?php echo esc_url( home_url('/agents') ); ?>" class="max-w-3xl mx-auto">
                <div class="flex flex-col sm:flex-row items-center gap-3 bg-white/95 dark:bg-slate-900/95 backdrop-blur-xl p-2.5 sm:p-3 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-2xl text-left">
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
                    <article class="group relative flex flex-col justify-between bg-white dark:bg-slate-900 rounded-[8px] border border-slate-200 dark:border-slate-800/80 hover:border-blue-500/50 dark:hover:border-blue-500/50 shadow-sm hover:shadow-xl transition-all duration-300 overflow-hidden">
                        
                        <!-- Top Accent Banner -->
                        <?php
                            $card_cover = ! empty( $agent_cover_images[ $agent->id ] ) 
                                ? $agent_cover_images[ $agent->id ] 
                                : ( ! empty( $agent->cover_image_url ) ? $agent->cover_image_url : ofp_image_url( 'dashboard-hero-banner.webp' ) );
                        ?>
                        <div class="h-44 sm:h-48 relative overflow-hidden bg-slate-900">
                            <img src="<?php echo esc_url( $card_cover ); ?>" alt="<?php echo esc_attr( $agent_name ); ?> cover" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                            <div class="absolute inset-0 bg-gradient-to-t from-slate-900/85 via-slate-900/20 to-black/20"></div>
                            
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
                                <a href="<?php echo esc_url( $profile_url ); ?>" class="block relative w-20 h-20 rounded-[8px] overflow-hidden border-4 border-white dark:border-slate-900 bg-white dark:bg-slate-800 shadow-md group-hover:scale-105 transition-transform duration-300">
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
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-[8px] text-xs font-black bg-blue-50 dark:bg-blue-950/50 text-blue-700 dark:text-blue-300 border border-blue-200/60 dark:border-blue-900/60">
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
                                       class="col-span-1 flex items-center justify-center py-2.5 rounded-[8px] bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500 hover:text-white transition-colors border border-emerald-200/80 dark:border-emerald-800/80"
                                       title="Chat on WhatsApp">
                                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                                    </a>
                                <?php endif; ?>

                                <!-- Call button -->
                                <?php if ( ! empty( $phone ) ) : ?>
                                    <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"
                                       class="col-span-1 flex items-center justify-center py-2.5 rounded-[8px] bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-blue-600 hover:text-white transition-colors border border-slate-200 dark:border-slate-700"
                                       title="Call Agency">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                    </a>
                                <?php endif; ?>

                                <!-- View Profile & Listings Button -->
                                <a href="<?php echo esc_url( $profile_url ); ?>"
                                   class="<?php echo ( ! empty( $wa_url ) && ! empty( $phone ) ) ? 'col-span-2' : 'col-span-4'; ?> flex items-center justify-center gap-1.5 py-2.5 px-3 rounded-[8px] bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider transition-colors shadow-md shadow-blue-500/20 whitespace-nowrap">
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
    <footer class="bg-slate-50 dark:bg-slate-950 text-slate-600 dark:text-slate-400"
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
                                class="tracking-tight text-slate-900 dark:text-white"><?php echo esc_html( OFP_Brand::public_name_text( 'Bofast Homes' ) ); ?></span>
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
                            class="text-slate-900 dark:text-white mb-6 uppercase tracking-wider">TOP LOCATIONS
                        </h4>
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
                            class="text-slate-900 dark:text-white mb-6 uppercase tracking-wider">POPULAR CATEGORIES
                        </h4>
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
                <p><?php echo OFP_Brand::public_copyright(); ?></p>
            </div>
        </div>
    </footer>

    <?php wp_footer(); ?>
</body>
</html>
