<?php
/**
 * Template: Single Blog Post
 * NaijaHub / OFP Public Single Blog Post
 *
 * Uses the exact homepage global navigation header (with desktop MegaMenu and mobile menu),
 * the exact homepage global footer, and the layout matching the uploaded reference design:
 * - Category label, large headline, author avatar with "Author | Date | Read time" meta
 * - Full-width featured hero image
 * - 2-Column content grid: Main article body on left, sticky sidebar with Popular Posts & Promo box on right
 * - "Would love to hear your thoughts" discussion note
 * - 2 rounded buttons for pagination (Previous & Next post arrows)
 * - Related Articles & Market Insights [View Journal →]
 * - Comments hidden
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Single post data
$post_id     = get_the_ID();
$post_title  = get_the_title();
$permalink   = get_permalink();
$author_id   = get_the_author_meta( 'ID' );
$author_name = get_the_author_meta( 'display_name' );
$author_avatar = get_avatar_url( $author_id, [ 'size' => 64 ] );
$post_date   = get_the_date( 'F j, Y' );
$read_time   = max( 1, (int) ceil( str_word_count( strip_tags( get_post_field( 'post_content', $post_id ) ) ) / 200 ) );

// Category
$cats = get_the_category( $post_id );
$primary_cat = ! empty( $cats ) ? $cats[0] : null;
$cat_name = $primary_cat ? $primary_cat->name : 'Insights';
$cat_link = $primary_cat ? get_category_link( $primary_cat->term_id ) : home_url('/blog');

// Tags
$tags = get_the_tags( $post_id );

// Featured Image
$has_thumb = has_post_thumbnail( $post_id );
$thumb_url = $has_thumb
    ? get_the_post_thumbnail_url( $post_id, 'full' )
    : 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=1600&q=80';

// Sidebar: 4 Popular / Latest Posts
$sidebar_posts = get_posts( [
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 4,
    'post__not_in'   => [ $post_id ],
    'orderby'        => 'comment_count',
    'order'          => 'DESC',
] );
if ( empty( $sidebar_posts ) || count( $sidebar_posts ) < 2 ) {
    $sidebar_posts = get_posts( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => 4,
        'post__not_in'   => [ $post_id ],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );
}

// Related Posts (4 posts for bottom section to match reference design)
$related_args = [
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 4,
    'post__not_in'   => [ $post_id ],
];
if ( $primary_cat ) {
    $related_args['cat'] = $primary_cat->term_id;
}
$related_query = new WP_Query( $related_args );
if ( ! $related_query->have_posts() ) {
    unset( $related_args['cat'] );
    $related_query = new WP_Query( $related_args );
}

// Previous & Next posts for 2 rounded buttons
$prev_post = get_previous_post();
$next_post = get_next_post();

$page_title = class_exists( 'OFP_Brand' ) ? OFP_Brand::title( $post_title . ' · Journal' ) : ( $post_title . ' - ' . get_bloginfo( 'name' ) );

// Social feedback links
$twitter_url = get_option( 'ofp_social_twitter' ) ?: 'https://twitter.com';
$twitter_handle = basename( parse_url( $twitter_url, PHP_URL_PATH ) ?: 'naijahub' );
?>
<!DOCTYPE html>
<html lang="en" x-data="{ megaMenuOpen: false }">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $page_title ); ?></title>

    <!-- SEO Meta Tags -->
    <?php
    $single_excerpt = wp_strip_all_tags( get_the_excerpt( $post_id ) );
    if ( empty( $single_excerpt ) ) {
        $single_excerpt = wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ), 30, '...' );
    }
    ?>
    <meta name="description" content="<?php echo esc_attr( $single_excerpt ); ?>">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <link rel="canonical" href="<?php echo esc_url( $permalink ); ?>">

    <!-- Open Graph / Facebook / WhatsApp Preview -->
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="<?php echo esc_attr( class_exists('OFP_Brand') ? OFP_Brand::name() : get_bloginfo('name') ); ?>">
    <meta property="og:url" content="<?php echo esc_url( $permalink ); ?>">
    <meta property="og:title" content="<?php echo esc_attr( $post_title ); ?>">
    <meta property="og:description" content="<?php echo esc_attr( $single_excerpt ); ?>">
    <meta property="og:image" content="<?php echo esc_url( $thumb_url ); ?>">
    <meta property="article:published_time" content="<?php echo esc_attr( get_the_date('c', $post_id) ); ?>">
    <meta property="article:author" content="<?php echo esc_attr( $author_name ); ?>">

    <!-- Twitter Card Meta -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?php echo esc_url( $permalink ); ?>">
    <meta name="twitter:title" content="<?php echo esc_attr( $post_title ); ?>">
    <meta name="twitter:description" content="<?php echo esc_attr( $single_excerpt ); ?>">
    <meta name="twitter:image" content="<?php echo esc_url( $thumb_url ); ?>">

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
        .ofp-article-prose {
            font-size: 1.0625rem;
            line-height: 1.85;
            color: #334155;
        }
        .dark .ofp-article-prose {
            color: #cbd5e1;
        }
        .ofp-article-prose p {
            margin-bottom: 1.6em;
        }
        .ofp-article-prose h2 {
            font-size: 1.75rem;
            font-weight: 800;
            color: #0f172a;
            margin-top: 2.2em;
            margin-bottom: 0.8em;
            line-height: 1.3;
        }
        .dark .ofp-article-prose h2 {
            color: #f8fafc;
        }
        .ofp-article-prose h3 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #0f172a;
            margin-top: 1.8em;
            margin-bottom: 0.6em;
            line-height: 1.35;
        }
        .dark .ofp-article-prose h3 {
            color: #f8fafc;
        }
        .ofp-article-prose img {
            border-radius: 1rem;
            margin: 2em 0;
            width: 100%;
            height: auto;
        }
        .ofp-article-prose blockquote {
            border-left: 4px solid #3b82f6;
            padding-left: 1.25rem;
            font-style: italic;
            margin: 2em 0;
            color: #64748b;
        }
        .dark .ofp-article-prose blockquote {
            color: #94a3b8;
        }
        .ofp-article-prose ul, .ofp-article-prose ol {
            margin: 1.5em 0;
            padding-left: 1.5rem;
        }
        .ofp-article-prose li {
            margin-bottom: 0.5em;
        }
        .ofp-article-prose ul {
            list-style-type: disc;
        }
        .ofp-article-prose ol {
            list-style-type: decimal;
        }
    </style>
</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 transition-colors duration-300 min-h-screen flex flex-col antialiased">
    <?php wp_body_open(); ?>

    <!-- ── HOMEPAGE GLOBAL HEADER ── -->
    <?php
        // Shared top bar + mega menu + mobile menu (X5.03). Settings: partials/site-nav.php
        $ofp_nav_mode = 'blog';
        $ofp_nav_active = '';
        include OFP_PATH . 'public/templates/partials/site-nav.php';
    ?>

    <!-- ── MAIN POST CONTAINER (MATCHING UPLOADED REFERENCE DESIGN) ── -->
    <main class="flex-grow max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-16 w-full">

        <!-- Top: Category Label -->
        <div class="mb-2.5">
            <a href="<?php echo esc_url( $cat_link ); ?>"
               class="text-xs font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400 hover:underline">
                <?php echo esc_html( $cat_name ); ?>
            </a>
        </div>

        <!-- Headline -->
        <h1 class="text-2xl sm:text-3xl lg:text-[40px] font-extrabold text-slate-900 dark:text-white tracking-tight leading-[1.25] mb-4">
            <?php echo esc_html( $post_title ); ?>
        </h1>

        <!-- Author & Date Meta Row -->
        <div class="flex items-center gap-3 text-xs sm:text-[13px] text-slate-500 dark:text-slate-400 pb-2 mb-8 flex-wrap">
            <img src="<?php echo esc_url( $author_avatar ); ?>" alt="<?php echo esc_attr( $author_name ); ?>"
                 class="w-7 h-7 rounded-full object-cover border border-slate-200 dark:border-slate-700">
            <span class="font-bold text-slate-800 dark:text-slate-200"><?php echo esc_html( $author_name ); ?></span>
            <span class="text-slate-300 dark:text-slate-700">|</span>
            <span>Published on <?php echo esc_html( $post_date ); ?></span>
            <span class="text-slate-300 dark:text-slate-700">|</span>
            <span><?php echo esc_html( $read_time ); ?> min read</span>
        </div>

        <!-- Large Featured Hero Image -->
        <div class="mb-10 rounded-2xl overflow-hidden bg-slate-100 dark:bg-slate-900 shadow-sm border border-slate-200/60 dark:border-slate-800/60">
            <img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $post_title ); ?>"
                 class="w-full h-auto max-h-[500px] object-cover">
        </div>

        <!-- 2-Column Grid: Content on Left, Sticky Sidebar on Right -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 items-start">

            <!-- Left: Article Body Content (8 cols) -->
            <div class="lg:col-span-8">
                <article class="ofp-article-prose">
                    <?php
                    while ( have_posts() ) :
                        the_post();
                        the_content();
                    endwhile;
                    ?>
                </article>

                <!-- Tags -->
                <?php if ( ! empty( $tags ) ) : ?>
                    <div class="mt-8 pt-6 border-t border-slate-100 dark:border-slate-800 flex items-center gap-2 flex-wrap">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400 mr-1">Tags:</span>
                        <?php foreach ( $tags as $t_item ) : ?>
                            <a href="<?php echo esc_url( get_tag_link( $t_item->term_id ) ); ?>"
                               class="px-3 py-1 rounded-full text-xs font-medium text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-900 hover:bg-slate-200 dark:hover:bg-slate-800 transition-colors">
                                #<?php echo esc_html( $t_item->name ); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Feedback Callout exactly matching reference design -->
                <div class="mt-10 pt-6 border-t border-slate-200/80 dark:border-slate-800/80 text-xs sm:text-sm text-slate-600 dark:text-slate-400">
                    <span>Read to this end? We'd love to hear your thoughts. Find us on </span>
                    <a href="<?php echo esc_url( $twitter_url ); ?>" target="_blank" rel="noopener noreferrer"
                       class="text-blue-600 dark:text-blue-400 hover:underline font-bold">
                        Twitter (@<?php echo esc_html( $twitter_handle ); ?>)
                    </a>
                </div>
            </div>

            <!-- Right: Sidebar (4 cols) -->
            <aside class="lg:col-span-4 space-y-6 lg:sticky lg:top-24">

                <!-- Popular Posts Box -->
                <div>
                    <h4 class="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-4 pb-2 border-b border-slate-200/60 dark:border-slate-800/60">
                        Popular Posts
                    </h4>

                    <div class="space-y-4">
                        <?php if ( ! empty( $sidebar_posts ) ) :
                            $sb_fallbacks = [
                                'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=200&q=80',
                                'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=200&q=80',
                                'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=200&q=80',
                                'https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?auto=format&fit=crop&w=200&q=80',
                            ];
                            $sb_idx = 0;
                            foreach ( $sidebar_posts as $sb_item ) :
                                $sb_id = $sb_item->ID;
                                $sb_cats = get_the_category( $sb_id );
                                $sb_cat_name = ! empty( $sb_cats ) ? $sb_cats[0]->name : 'Market';
                                $sb_thumb = has_post_thumbnail( $sb_id )
                                    ? get_the_post_thumbnail_url( $sb_id, 'thumbnail' )
                                    : $sb_fallbacks[ $sb_idx % count( $sb_fallbacks ) ];
                                $sb_idx++;
                        ?>
                            <article class="flex items-start gap-3 group">
                                <a href="<?php echo esc_url( get_permalink( $sb_id ) ); ?>"
                                   class="w-14 h-14 rounded-lg overflow-hidden bg-slate-100 dark:bg-slate-800 shrink-0 border border-slate-200/80 dark:border-slate-800">
                                    <img src="<?php echo esc_url( $sb_thumb ); ?>" alt="<?php echo esc_attr( get_the_title( $sb_id ) ); ?>"
                                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                </a>
                                <div class="flex-1 min-w-0">
                                    <span class="text-[9px] font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider block mb-0.5">
                                        <?php echo esc_html( $sb_cat_name ); ?>
                                    </span>
                                    <h5 class="text-xs font-bold text-slate-800 dark:text-slate-200 leading-snug line-clamp-2 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                                        <a href="<?php echo esc_url( get_permalink( $sb_id ) ); ?>">
                                            <?php echo esc_html( get_the_title( $sb_id ) ); ?>
                                        </a>
                                    </h5>
                                </div>
                            </article>
                        <?php endforeach;
                        endif; ?>
                    </div>
                </div>

                <!-- Orange Promo Card (Matching Reference Design) -->
                <div class="rounded-2xl p-6 bg-[#f97316] text-white text-center shadow-lg shadow-orange-500/20">
                    <h4 class="text-base sm:text-lg font-black leading-snug mb-2">
                        Get More Done Together With Us
                    </h4>
                    <p class="text-xs text-orange-100/90 leading-relaxed mb-4">
                        Lorem ipsum dolor sit amet, consectetur adipiscing elit.
                    </p>
                    <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                       class="inline-block px-6 py-2 rounded-lg bg-white hover:bg-orange-50 text-orange-600 font-bold text-xs uppercase tracking-wider shadow transition-all">
                        Exclusive
                    </a>
                </div>

            </aside>

        </div>

        <!-- ── 2 ROUNDED BUTTONS PAGINATION (ARROW LEFT & RIGHT) ── -->
        <div class="mt-12 py-6 border-t border-slate-200/80 dark:border-slate-800/80 flex items-center justify-between">
            <?php if ( $prev_post ) : ?>
                <a href="<?php echo esc_url( get_permalink( $prev_post->ID ) ); ?>"
                   class="flex items-center gap-3 text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 transition-colors group"
                   title="<?php echo esc_attr( get_the_title( $prev_post->ID ) ); ?>">
                    <span class="w-11 h-11 rounded-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 group-hover:bg-blue-600 group-hover:text-white group-hover:border-blue-600 flex items-center justify-center shadow-sm transition-all">
                        <svg class="w-5 h-5 transition-transform group-hover:-translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </span>
                    <div class="hidden sm:block text-left">
                        <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 block">Previous</span>
                        <span class="text-xs font-bold line-clamp-1 max-w-[220px]"><?php echo esc_html( get_the_title( $prev_post->ID ) ); ?></span>
                    </div>
                </a>
            <?php else : ?>
                <div class="flex items-center gap-3 opacity-40 cursor-not-allowed">
                    <span class="w-11 h-11 rounded-full border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-900/60 text-slate-400 dark:text-slate-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </span>
                    <div class="hidden sm:block text-left">
                        <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 block">Previous</span>
                        <span class="text-xs font-bold text-slate-400">No older post</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $next_post ) : ?>
                <a href="<?php echo esc_url( get_permalink( $next_post->ID ) ); ?>"
                   class="flex items-center gap-3 text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 transition-colors group text-right"
                   title="<?php echo esc_attr( get_the_title( $next_post->ID ) ); ?>">
                    <div class="hidden sm:block text-right">
                        <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 block">Next</span>
                        <span class="text-xs font-bold line-clamp-1 max-w-[220px]"><?php echo esc_html( get_the_title( $next_post->ID ) ); ?></span>
                    </div>
                    <span class="w-11 h-11 rounded-full border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 group-hover:bg-blue-600 group-hover:text-white group-hover:border-blue-600 flex items-center justify-center shadow-sm transition-all">
                        <svg class="w-5 h-5 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                        </svg>
                    </span>
                </a>
            <?php else : ?>
                <div class="flex items-center gap-3 opacity-40 cursor-not-allowed">
                    <div class="hidden sm:block text-right">
                        <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 block">Next</span>
                        <span class="text-xs font-bold text-slate-400">No newer post</span>
                    </div>
                    <span class="w-11 h-11 rounded-full border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-900/60 text-slate-400 dark:text-slate-600 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                        </svg>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── RELATED ARTICLES & MARKET INSIGHTS [VIEW JOURNAL →] (MATCHING BOTTOM OF REFERENCE DESIGN) ── -->
        <?php if ( $related_query->have_posts() ) : ?>
            <section class="mt-14 pt-10 border-t border-slate-200/80 dark:border-slate-800/80">
                <div class="flex items-center justify-between mb-8 pb-3 border-b border-slate-200/80 dark:border-slate-800/80">
                    <h3 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white tracking-tight">
                        Related Articles &amp; Market Insights
                    </h3>
                    <a href="<?php echo esc_url( home_url('/blog') ); ?>"
                       class="text-xs font-black uppercase tracking-wider text-blue-600 dark:text-blue-400 hover:underline">
                        View Journal &rarr;
                    </a>
                </div>

                <div class="space-y-6">
                    <?php
                    $rel_fallbacks = [
                        'https://images.unsplash.com/photo-1551024709-8f23befc6f87?auto=format&fit=crop&w=400&q=80',
                        'https://images.unsplash.com/photo-1490474418585-ba9bad8fd0ea?auto=format&fit=crop&w=400&q=80',
                        'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?auto=format&fit=crop&w=400&q=80',
                        'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=400&q=80',
                    ];
                    $r_idx = 0;
                    while ( $related_query->have_posts() ) : $related_query->the_post();
                        $r_id = get_the_ID();
                        $r_cats = get_the_category( $r_id );
                        $r_cat_name = ! empty( $r_cats ) ? $r_cats[0]->name : 'Market';
                        $r_thumb = has_post_thumbnail( $r_id )
                            ? get_the_post_thumbnail_url( $r_id, 'medium' )
                            : $rel_fallbacks[ $r_idx % count( $rel_fallbacks ) ];
                        $r_idx++;
                    ?>
                        <article class="flex flex-col sm:flex-row gap-5 items-start sm:items-center group pb-6 border-b border-slate-100 dark:border-slate-800/80 last:border-b-0 last:pb-0">
                            <a href="<?php echo esc_url( get_permalink() ); ?>"
                               class="w-full sm:w-44 md:w-52 aspect-[16/10] shrink-0 rounded-xl overflow-hidden bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-slate-800">
                                <img src="<?php echo esc_url( $r_thumb ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>"
                                     class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                            </a>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-wider mb-1.5">
                                    <span class="text-blue-600 dark:text-blue-400"><?php echo esc_html( $r_cat_name ); ?></span>
                                    <span class="text-slate-300 dark:text-slate-600">•</span>
                                    <span class="text-slate-400"><?php echo esc_html( get_the_date( 'M j, Y' ) ); ?></span>
                                </div>
                                <h4 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white leading-snug mb-2 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors line-clamp-2">
                                    <a href="<?php echo esc_url( get_permalink() ); ?>">
                                        <?php echo esc_html( get_the_title() ); ?>
                                    </a>
                                </h4>
                                <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-400 line-clamp-2 leading-relaxed">
                                    <?php echo esc_html( wp_trim_words( get_the_excerpt(), 22, '...' ) ); ?>
                                </p>
                            </div>
                        </article>
                    <?php endwhile; wp_reset_postdata(); ?>
                </div>
            </section>
        <?php endif; ?>

    </main>

    <!-- 13. Footer (Exact Homepage Global Footer) -->
    <?php
        // Shared footer (X5.03). Content: partials/site-footer.php
        $ofp_footer_margin = 'mt-auto';
        include OFP_PATH . 'public/templates/partials/site-footer.php';
    ?>

    <?php wp_footer(); ?>
</body>
</html>
