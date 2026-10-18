<?php
/**
 * Template: /agent/[slug]
 * Public Agent Profile & Property Showcase
 *
 * - Hoomeee light card aesthetic for Agent Showcase
 * - WhatsApp & Call buttons directly under the profile image
 * - WhatsApp number fetched from client profile
 * - Featured property specs start after the profile image
 * - Social media from client account replaces the 3 grid badges below description
 * - Available properties section uses the exact site main card used in the marketplace
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$agent_slug = get_query_var( 'ofp_agent_slug', '' );
if ( empty( $agent_slug ) ) {
    status_header( 404 );
    include get_query_template( '404' );
    exit;
}

global $wpdb;
$client = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE profile_slug = %s LIMIT 1",
        $agent_slug
    )
);

if ( ! $client ) {
    status_header( 404 );
    include get_query_template( '404' );
    exit;
}

// Only active clients get a public profile.
if ( $client->status !== 'active' ) {
    status_header( 404 );
    include get_query_template( '404' );
    exit;
}

$agent_name = $client->business_name ?: $client->owner_name;
$custom_app_name = trim( (string) get_option( 'ofp_app_name', '' ) );
if ( ! empty( $custom_app_name ) && ! in_array( strtolower( preg_replace( '/[^a-z0-9]/', '', $custom_app_name ) ), [ 'ofastpipeline' ], true ) ) {
    $app_name = $custom_app_name;
} else {
    $app_name = 'app name';
}
$agent_logo = ! empty( $client->logo_url ) ? $client->logo_url : OFP_URL . 'assets/images/default-avatar.png';
$agent_bio  = ! empty( $client->bio ) ? $client->bio : 'We provide premium real estate services, helping you discover dream homes, luxury apartments, and commercial investments with complete peace of mind and professional guidance.';

// ── Social handles & extra data from client profile ─────────────────────────
$extra = [];
if ( ! empty( $client->profile_extra ) ) {
    $extra = json_decode( $client->profile_extra, true ) ?: [];
}
$youtube_url   = $extra['youtube_url'] ?? ( $extra['youtube'] ?? '' );
$instagram_url = $extra['instagram_url'] ?? ( $extra['instagram'] ?? '' );
$tiktok_url    = $extra['tiktok_url'] ?? ( $extra['tiktok'] ?? '' );

// ── Fetch WhatsApp Number from Client Profile ───────────────────────────────
// Checks client profile_extra, client table whatsapp_number, usermeta, and fallback phone
$whatsapp_url = '';
if ( ! empty( $extra['whatsapp_url'] ) ) {
    $whatsapp_url = $extra['whatsapp_url'];
} elseif ( ! empty( $extra['whatsapp'] ) ) {
    $clean_extra_wa = preg_replace( '/[^0-9]/', '', $extra['whatsapp'] );
    $whatsapp_url = strpos( $extra['whatsapp'], 'http' ) === 0 ? $extra['whatsapp'] : ( $clean_extra_wa ? 'https://wa.me/' . $clean_extra_wa : '' );
} elseif ( ! empty( $client->whatsapp_number ) ) {
    $clean_client_wa = preg_replace( '/[^0-9]/', '', $client->whatsapp_number );
    $whatsapp_url = $clean_client_wa ? 'https://wa.me/' . $clean_client_wa : '';
}

// Check user meta if linked to WordPress user
if ( empty( $whatsapp_url ) && ! empty( $client->email ) ) {
    $wp_u = get_user_by( 'email', $client->email );
    if ( $wp_u ) {
        $meta_wa = get_user_meta( $wp_u->ID, 'whatsapp_number', true )
                ?: get_user_meta( $wp_u->ID, 'ofp_whatsapp', true )
                ?: get_user_meta( $wp_u->ID, 'whatsapp', true );
        if ( ! empty( $meta_wa ) ) {
            $clean_meta_wa = preg_replace( '/[^0-9]/', '', $meta_wa );
            $whatsapp_url = strpos( $meta_wa, 'http' ) === 0 ? $meta_wa : ( $clean_meta_wa ? 'https://wa.me/' . $clean_meta_wa : '' );
        }
    }
}

// Fallback to client phone if no dedicated WhatsApp number found
if ( empty( $whatsapp_url ) && ! empty( $client->phone ) ) {
    $clean_phone = preg_replace( '/[^0-9]/', '', $client->phone );
    if ( ! empty( $clean_phone ) ) {
        $whatsapp_url = 'https://wa.me/' . $clean_phone;
    }
}

// Call number
$call_num = ! empty( $client->phone ) ? $client->phone : ( ! empty( $client->support_phone ) ? $client->support_phone : '' );

// Resolve Client Branded Portal URL
$base_domain = get_option( 'ofp_crm_base_domain', '' );
if ( empty( $base_domain ) && ! empty( $_SERVER['HTTP_HOST'] ) ) {
    $base_domain = preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
}
$portal_url = '';
if ( ! empty( $client->subdomain ) && ! empty( $base_domain ) ) {
    $scheme     = is_ssl() ? 'https://' : 'http://';
    $portal_url = $scheme . $client->subdomain . '.' . $base_domain;
}

// Fetch properties for this agent/client
$properties = new WP_Query( [
    'post_type'      => 'ofp_property',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'meta_query'     => [
        [
            'key'   => 'ofp_client_id',
            'value' => $client->id,
        ]
    ]
] );

// Cover image & featured property metadata for the top banner
$featured_cover = ! empty( $client->cover_image_url ) 
    ? $client->cover_image_url 
    : ( OFP_URL . 'assets/images/dashboard-hero-banner.jpg' );
$featured_title = $agent_name . ' • Featured Real Estate';
$featured_beds  = '4 Bedroom';
$featured_baths = '3 Bathroom';

if ( $properties->have_posts() ) {
    $first_prop = $properties->posts[0];
    // Use first property thumbnail only if agent hasn't set an explicit custom cover
    if ( empty( $client->cover_image_url ) ) {
        $thumb_id = get_post_meta( $first_prop->ID, 'ofp_main_image', true );
        if ( $thumb_id ) {
            $img_src = wp_get_attachment_image_url( $thumb_id, 'full' );
            if ( $img_src ) {
                $featured_cover = $img_src;
            }
        }
    }
    $featured_title = get_the_title( $first_prop->ID );
    $b_count        = get_post_meta( $first_prop->ID, 'ofp_bedrooms', true );
    if ( $b_count ) $featured_beds = $b_count . ' Bedroom';
    $ba_count       = get_post_meta( $first_prop->ID, 'ofp_bathrooms', true );
    if ( $ba_count ) $featured_baths = $ba_count . ' Bathroom';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $agent_name ); ?> — Real Estate Agent</title>
    
    <!-- Load Tailwind and marketplace theme overrides for pixel-perfect card rendering -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
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
    </script>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/alpine-tailwind-overrides.css' ); ?>">

    <?php wp_head(); ?>

    <!-- Phase 23 Meta Pixel -->
    <?php if ( ! empty( $client->meta_pixel_id ) ) : ?>
        <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?php echo esc_js( $client->meta_pixel_id ); ?>');
        fbq('track', 'PageView');
        </script>
        <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo esc_attr( $client->meta_pixel_id ); ?>&ev=PageView&noscript=1"/></noscript>
    <?php endif; ?>
</head>
<body class="ofp-agent-page-body bg-slate-50 text-slate-900">

    <!-- ── Main Application Shell ────────────────────────────────────────── -->
    <div class="ofp-agent-page-container">

        <!-- ── Top Header Navigation Bar (Clean Minimal) ─────────────────── -->
        <header class="ofp-agent-topbar">
            <div class="ofp-topbar-left">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ofp-topbar-back-btn" title="Back to Marketplace">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                </a>
                <h1 class="ofp-topbar-title">Agent Profile</h1>
            </div>

            <div class="ofp-topbar-right">
                <div class="ofp-topbar-user-badge">
                    <img src="<?php echo esc_url( $agent_logo ); ?>" alt="<?php echo esc_attr( $agent_name ); ?>" class="ofp-topbar-avatar" onerror="this.src='<?php echo esc_url( OFP_URL . 'assets/images/default-avatar.png' ); ?>';">
                    <div class="ofp-topbar-user-info">
                        <span class="ofp-topbar-user-name"><?php echo esc_html( $agent_name ); ?></span>
                        <span class="ofp-topbar-user-role">Verified Agent</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- ── Main Showcase Card (Matching Hoomeee Reference) ─────────────── -->
        <article class="ofp-agent-showcase-card">

            <!-- ── Featured Property Cover Banner ──────────────────────────── -->
            <div class="ofp-cover-banner" style="background-image: url('<?php echo esc_url( $featured_cover ); ?>');">
                <!-- Status Badge -->
                <span class="ofp-cover-badge-available">Available</span>

                <!-- Bottom Image Overlay: Starts AFTER the profile image -->
                <div class="ofp-cover-overlay-bottom">
                    <div class="ofp-cover-meta-info">
                        <h2 class="ofp-cover-property-title"><?php echo esc_html( $featured_title ); ?></h2>
                        <div class="ofp-cover-specs">
                            <span class="ofp-spec-item">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
                                <?php echo esc_html( $featured_beds ); ?>
                            </span>
                            <span class="ofp-spec-item">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                                <?php echo esc_html( $featured_baths ); ?>
                            </span>
                            <span class="ofp-spec-item">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" /></svg>
                                Verified Listings
                            </span>
                        </div>
                    </div>

                    <div class="ofp-cover-tools">
                        <span class="ofp-pagination-pill">1 of <?php echo max( 1, (int) $properties->found_posts ); ?></span>
                    </div>
                </div>
            </div>

            <!-- ── Agent Content Split Row ──────────────────────────────────── -->
            <div class="ofp-agent-content-split">

                <!-- ── Left Column: Overlapping Avatar, CTAs, Address ───────── -->
                <div class="ofp-agent-left-col">
                    
                    <!-- Squircle Avatar Overlapping Banner -->
                    <div class="ofp-squircle-avatar-wrap">
                        <img src="<?php echo esc_url( $agent_logo ); ?>" alt="<?php echo esc_attr( $agent_name ); ?>" class="ofp-squircle-avatar-img" onerror="this.src='<?php echo esc_url( OFP_URL . 'assets/images/default-avatar.png' ); ?>';">
                    </div>

                    <!-- Agent Name & Owner Badge -->
                    <h3 class="ofp-agent-person-name"><?php echo esc_html( $agent_name ); ?></h3>
                    <div class="ofp-agent-owner-badge">OWNER</div>

                    <!-- WhatsApp and Call buttons DIRECTLY UNDER the profile image -->
                    <div class="ofp-avatar-contact-buttons">
                        <?php if ( ! empty( $call_num ) ) : ?>
                            <a href="tel:<?php echo esc_attr( preg_replace('/[^0-9+]/', '', $call_num) ); ?>" class="ofp-btn-avatar-cta ofp-btn-call" title="Call <?php echo esc_attr( $agent_name ); ?>">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
                                <span>Call</span>
                            </a>
                        <?php endif; ?>

                        <?php if ( ! empty( $whatsapp_url ) ) : ?>
                            <a href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" rel="noopener" class="ofp-btn-avatar-cta ofp-btn-whatsapp" title="Chat on WhatsApp">
                                <svg width="17" height="17" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2z"/><path d="M17.84 16.03c-.24.68-1.2 1.26-1.98 1.42-.53.11-1.22.2-3.56-.76-2.99-1.24-4.91-4.28-5.06-4.48-.15-.2-1.2-1.6-1.2-3.05s.76-2.16 1.03-2.46c.27-.3.59-.37.78-.37.2 0 .39 0 .56.01.18.01.42-.07.66.5.24.58.83 2.03.9 2.18.07.15.12.32.02.52-.1.2-.15.32-.3.5-.15.18-.32.4-.45.53-.15.15-.31.31-.13.61.18.3.8 1.32 1.72 2.14 1.18 1.05 2.18 1.38 2.48 1.53.3.15.48.13.66-.08.18-.2.76-.89.96-1.19.2-.3.4-.25.66-.15.27.1 1.72.81 2.01.96.3.15.49.22.56.35.07.13.07.73-.17 1.41z" fill="#ffffff"/>
                                </svg>
                                <span>WhatsApp</span>
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- Address & Location Details (Matching reference style) -->
                    <?php if ( ! empty( $client->email ) ) : ?>
                        <div class="ofp-agent-address-info">
                            <div class="ofp-address-row">
                                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                                <span><?php echo esc_html( $client->email ); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ── Right Column: Description & Social Media from Client Account ─ -->
                <div class="ofp-agent-right-col">
                    <h2 class="ofp-desc-heading">Description</h2>
                    <div class="ofp-desc-text">
                        <?php echo wpautop( esc_html( $agent_bio ) ); ?>
                    </div>

                    <!-- Social Media from Client Account (Replaces previous 3 grid cards) -->
                    <div class="ofp-social-media-section">
                        <h4 class="ofp-social-card-title">Social Media</h4>
                        <div class="ofp-social-icons-list">
                            <!-- YouTube -->
                            <a href="<?php echo esc_url( $youtube_url ?: 'https://youtube.com' ); ?>" target="_blank" rel="noopener" class="ofp-social-bubble yt" title="YouTube">
                                <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                            </a>

                            <!-- Instagram -->
                            <a href="<?php echo esc_url( $instagram_url ?: 'https://instagram.com' ); ?>" target="_blank" rel="noopener" class="ofp-social-bubble ig" title="Instagram">
                                <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                            </a>

                            <!-- TikTok -->
                            <a href="<?php echo esc_url( $tiktok_url ?: 'https://tiktok.com' ); ?>" target="_blank" rel="noopener" class="ofp-social-bubble tt" title="TikTok">
                                <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>
                            </a>

                            <!-- WhatsApp -->
                            <a href="<?php echo esc_url( $whatsapp_url ?: '#' ); ?>" target="_blank" rel="noopener" class="ofp-social-bubble wa" title="WhatsApp">
                                <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2z"/><path d="M17.84 16.03c-.24.68-1.2 1.26-1.98 1.42-.53.11-1.22.2-3.56-.76-2.99-1.24-4.91-4.28-5.06-4.48-.15-.2-1.2-1.6-1.2-3.05s.76-2.16 1.03-2.46c.27-.3.59-.37.78-.37.2 0 .39 0 .56.01.18.01.42-.07.66.5.24.58.83 2.03.9 2.18.07.15.12.32.02.52-.1.2-.15.32-.3.5-.15.18-.32.4-.45.53-.15.15-.31.31-.13.61.18.3.8 1.32 1.72 2.14 1.18 1.05 2.18 1.38 2.48 1.53.3.15.48.13.66-.08.18-.2.76-.89.96-1.19.2-.3.4-.25.66-.15.27.1 1.72.81 2.01.96.3.15.49.22.56.35.07.13.07.73-.17 1.41z" fill="#ffffff"/></svg>
                            </a>

                            <!-- Public Profile / Website -->
                            <?php if ( ! empty( $portal_url ) ) : ?>
                                <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" rel="noopener" class="ofp-social-bubble web" title="Website">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" /></svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div> <!-- /.ofp-agent-content-split -->

        </article>

        <!-- ── Available Properties: Using Site Main Card from Marketplace ── -->
        <section class="ofp-properties-section-card bg-white rounded-2xl shadow-[0_4px_20px_rgba(0,0,0,0.04)] p-6 sm:p-8 mb-8 border border-slate-100">
            <div class="flex flex-wrap items-center justify-between gap-4 mb-6 pb-4 border-b border-slate-100">
                <div>
                    <h3 class="text-xl sm:text-2xl font-bold text-slate-900 m-0">Available Properties</h3>
                    <p class="text-sm text-slate-500 mt-1 mb-0">Browse verified properties listed by <?php echo esc_html( $agent_name ); ?></p>
                </div>
                <div class="bg-emerald-50 text-[#00875A] font-bold text-xs sm:text-sm px-4 py-1.5 rounded-full border border-emerald-100">
                    <?php echo (int) $properties->found_posts; ?> Listings
                </div>
            </div>

            <?php if ( $properties->have_posts() ) : ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php while ( $properties->have_posts() ) : $properties->the_post(); 
                        $prop_id      = get_the_ID();
                        $price        = get_post_meta( $prop_id, 'ofp_price', true );
                        $location     = get_post_meta( $prop_id, 'ofp_location_text', true ) ?: 'Lagos, Nigeria';
                        $beds         = get_post_meta( $prop_id, 'ofp_bedrooms', true );
                        $baths        = get_post_meta( $prop_id, 'ofp_bathrooms', true );
                        $parking      = get_post_meta( $prop_id, 'ofp_parking_spaces', true );
                        $sqm          = get_post_meta( $prop_id, 'ofp_sqm', true );
                        $listing_type = get_post_meta( $prop_id, 'ofp_listing_type', true ) ?: 'sale';
                        $prop_type    = get_post_meta( $prop_id, 'ofp_property_type', true ) ?: 'residential';
                        $is_featured  = get_post_meta( $prop_id, 'ofp_is_featured', true );
                        $is_verified  = get_post_meta( $prop_id, 'ofp_is_verified', true );
                        $status_ctx   = class_exists( 'OFP_Property_Status' ) ? OFP_Property_Status::get_context( $prop_id ) : null;

                        // Marketplace listing badge label
                        $listing_label = 'FOR SALE';
                        if ( $listing_type === 'rent' || $listing_type === 'lease' ) $listing_label = 'FOR RENT';
                        if ( $listing_type === 'shortlet' ) $listing_label = 'SHORTLET';
                        if ( $listing_type === 'commercial' ) $listing_label = 'COMMERCIAL';
                        if ( $listing_type === 'land' ) $listing_label = 'LAND';

                        // Price formatting (Millions or standard formatted)
                        $price_num = floatval( preg_replace( '/[^\d.]/', '', (string) $price ) );
                        if ( $price_num >= 1000000 ) {
                            $formatted_price = '₦' . rtrim( rtrim( number_format( $price_num / 1000000, 1 ), '0' ), '.' ) . ' Million';
                        } elseif ( $price_num > 0 ) {
                            $formatted_price = '₦' . number_format( $price_num );
                        } else {
                            $formatted_price = '₦' . ( $price ?: 'Contact Agent' );
                        }
                    ?>
                        <!-- Exact Marketplace Property Card Structure -->
                        <div class="bg-white rounded-2xl overflow-hidden border border-slate-100 shadow-[0_4px_20px_rgba(0,0,0,0.06)] hover:shadow-xl transition-all duration-300 group flex flex-col relative">
                            <!-- Card Image -->
                            <div class="relative h-60 w-full overflow-hidden shrink-0">
                                <a href="<?php the_permalink(); ?>" class="block w-full h-full">
                                    <?php if ( has_post_thumbnail() ) : ?>
                                        <?php the_post_thumbnail( 'large', [ 'class' => 'w-full h-full object-cover group-hover:scale-105 transition-transform duration-500' ] ); ?>
                                    <?php else : ?>
                                        <img src="<?php echo esc_url( OFP_URL . 'assets/images/dashboard-hero-banner.jpg' ); ?>"
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
                                    <div class="text-white font-black text-2xl tracking-tight drop-shadow-md"><?php echo esc_html( $formatted_price ); ?></div>
                                    <div class="bg-slate-950/75 backdrop-blur-md text-white text-[11px] font-medium px-2.5 py-1 rounded-md border border-white/10 capitalize">
                                        <?php echo esc_html( str_replace('-', ' ', $prop_type) ); ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Card Body -->
                            <div class="p-5 flex-1 flex flex-col justify-between gap-3 min-w-0">
                                <div class="flex flex-col gap-1.5">
                                    <div class="text-[12px] sm:text-[13px] text-blue-600 flex items-center gap-1.5 font-medium">
                                        <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                        </svg>
                                        <span class="truncate"><?php echo esc_html( $location ); ?></span>
                                    </div>
                                    <a href="<?php the_permalink(); ?>" class="text-[15px] sm:text-base font-bold text-slate-900 leading-snug hover:text-blue-600 transition-colors line-clamp-2">
                                        <?php the_title(); ?>
                                    </a>
                                </div>

                                <?php if ( $listing_type === 'land' ) : 
                                    $land_b = class_exists( 'OFP_Property_Land' ) ? OFP_Property_Land::get_breakdown( $prop_id ) : [];
                                ?>
                                    <!-- Land Specs -->
                                    <div class="grid grid-cols-3 gap-2 py-3 border-y border-slate-100 my-1">
                                        <div class="leading-none">
                                            <span class="text-[13px] font-bold text-emerald-600 block truncate"><?php echo esc_html( ! empty( $land_b['plot_short'] ) && $land_b['plot_short'] !== '-' ? $land_b['plot_short'] : 'On Request' ); ?></span>
                                            <span class="text-[11px] text-slate-500 mt-1 block">Plot Sizing</span>
                                        </div>
                                        <div class="leading-none">
                                            <span class="text-[13px] font-bold text-slate-900 block truncate"><?php echo esc_html( ! empty( $land_b['sqm_formatted'] ) && $land_b['sqm_formatted'] !== '-' ? $land_b['sqm_formatted'] . ' SQM' : ( $sqm ? number_format( (float) $sqm ) . ' SQM' : 'On Request' ) ); ?></span>
                                            <span class="text-[11px] text-slate-500 mt-1 block">Total Area</span>
                                        </div>
                                        <div class="leading-none">
                                            <span class="text-[13px] font-bold text-slate-900 block truncate" title="<?php echo esc_attr( get_post_meta( $prop_id, 'ofp_title_document', true ) ?: 'On Request' ); ?>"><?php echo esc_html( get_post_meta( $prop_id, 'ofp_title_document', true ) ?: 'On Request' ); ?></span>
                                            <span class="text-[11px] text-slate-500 mt-1 block">Title Doc</span>
                                        </div>
                                    </div>
                                <?php else : ?>
                                    <!-- 4 Specs: Beds, Baths, Parking, SQM -->
                                    <div class="grid grid-cols-4 gap-2 py-3 border-y border-slate-100 my-1">
                                        <div class="flex items-start gap-1.5">
                                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2 2z"></path>
                                            </svg>
                                            <div class="leading-none">
                                                <span class="text-[13px] font-bold text-slate-900 block"><?php echo esc_html( $beds ?: '-' ); ?></span>
                                                <span class="text-[11px] text-slate-500 mt-1 block">Beds</span>
                                            </div>
                                        </div>
                                        <div class="flex items-start gap-1.5">
                                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <div class="leading-none">
                                                <span class="text-[13px] font-bold text-slate-900 block"><?php echo esc_html( $baths ?: '-' ); ?></span>
                                                <span class="text-[11px] text-slate-500 mt-1 block">Baths</span>
                                            </div>
                                        </div>
                                        <div class="flex items-start gap-1.5">
                                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 17H3a2 2 0 01-2-2V7a2 2 0 012-2h11l4 4v8a2 2 0 01-2 2h-3"></path>
                                            </svg>
                                            <div class="leading-none">
                                                <span class="text-[13px] font-bold text-slate-900 block"><?php echo esc_html( $parking ?: '-' ); ?></span>
                                                <span class="text-[11px] text-slate-500 mt-1 block">Parking</span>
                                            </div>
                                        </div>
                                        <div class="flex items-start gap-1.5">
                                            <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path>
                                            </svg>
                                            <div class="leading-none">
                                                <span class="text-[13px] font-bold text-slate-900 block"><?php echo esc_html( $sqm ?: '-' ); ?></span>
                                                <span class="text-[11px] text-slate-500 mt-1 block">SQM</span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Card Footer: Agent Tag & Details Action -->
                                <div class="flex items-center justify-between pt-1">
                                    <div class="flex items-center gap-2.5">
                                        <div class="w-9 h-9 rounded-full bg-emerald-100 flex items-center justify-center text-[#00875A] font-bold text-xs ring-1 ring-slate-100">
                                            <?php echo esc_html( strtoupper( substr( $agent_name, 0, 1 ) ) ); ?>
                                        </div>
                                        <div>
                                            <p class="text-[13px] font-bold text-slate-900 leading-tight mb-0"><?php echo esc_html( $agent_name ); ?></p>
                                            <p class="text-[11px] text-slate-500 leading-tight mb-0">Verified Agent</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <a href="<?php the_permalink(); ?>" class="bg-[#00875A] hover:bg-[#006644] text-white font-bold text-xs sm:text-[13px] px-3.5 py-2 rounded-xl flex items-center gap-1 shadow-sm transition-colors cursor-pointer text-decoration-none">
                                            <span>Details</span>
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                                            </svg>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; wp_reset_postdata(); ?>
                </div>
            <?php else : ?>
                <div class="text-center py-12 bg-slate-50 rounded-2xl border border-dashed border-slate-200">
                    <svg class="w-10 h-10 mx-auto text-slate-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                    <h4 class="text-base font-bold text-slate-800 mb-1">No Listings Available Right Now</h4>
                    <p class="text-xs text-slate-500 m-0">Please contact the agent directly using the Call or WhatsApp buttons above.</p>
                </div>
            <?php endif; ?>
        </section>

        <!-- ── Clean Minimal Footer ──────────────────────────────────────── -->
        <footer class="ofp-clean-footer">
            <p>&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php echo esc_html( $agent_name ); ?>. All rights reserved. Powered by <?php echo esc_html( $app_name ); ?>.</p>
        </footer>

    </div> <!-- /.ofp-agent-page-container -->

    <?php wp_footer(); ?>
</body>
</html>
