<?php
/**
 * Template: /agent/[slug]
 * Public Agent Profile Page (Phase 23)
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

// Ensure business logic is applied (active subscription, not suspended, etc).
// If they are not active, they don't get a public profile.
if ( $client->status !== 'active' ) {
    status_header( 404 );
    include get_query_template( '404' );
    exit;
}

$agent_name = $client->business_name ?: $client->owner_name;
$agent_logo = ! empty( $client->logo_url ) ? $client->logo_url : OFP_URL . 'assets/images/default-avatar.png';
$agent_bio  = ! empty( $client->bio ) ? $client->bio : 'We are dedicated to helping you find the perfect property.';

// Fetch properties
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $agent_name ); ?> — Properties</title>
    
    <?php wp_head(); ?>

    <style>
        :root {
            --ofp-primary: #111827;
            --ofp-primary-light: #374151;
            --ofp-bg: #f9fafb;
            --ofp-surface: #ffffff;
            --ofp-text: #1f2937;
            --ofp-text-light: #6b7280;
            --ofp-accent: #2563eb;
            --ofp-accent-hover: #1d4ed8;
            --ofp-border: #e5e7eb;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background-color: var(--ofp-bg);
            color: var(--ofp-text);
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }

        .agent-header {
            background: #ffffff;
            color: var(--ofp-primary);
            padding: 60px 20px;
            text-align: center;
            border-bottom: 1px solid var(--ofp-border);
        }

        .agent-logo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid var(--ofp-border);
            margin-bottom: 20px;
            background: #fff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .agent-name {
            font-size: 32px;
            font-weight: 700;
            margin: 0 0 12px;
        }

        .agent-bio {
            max-width: 600px;
            margin: 0 auto 30px;
            font-size: 16px;
            line-height: 1.6;
            color: var(--ofp-text-light);
        }

        .agent-contact-actions {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .agent-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 100px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: transform 0.2s, opacity 0.2s;
        }
        .agent-btn:hover { transform: translateY(-2px); opacity: 0.9; }

        .btn-call { background: var(--ofp-primary); color: white; }
        .btn-whatsapp { background: #25D366; color: white; }

        .properties-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .section-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 30px;
            color: var(--ofp-primary);
        }

        .property-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 30px;
        }

        .property-card {
            background: var(--ofp-surface);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid var(--ofp-border);
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .property-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .property-img {
            width: 100%;
            height: 220px;
            object-fit: cover;
            background: #e5e7eb;
        }

        .property-content {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .property-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--ofp-accent);
            margin: 0 0 8px;
        }

        .property-title {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 12px;
            line-height: 1.4;
        }

        .property-meta {
            display: flex;
            gap: 16px;
            color: var(--ofp-text-light);
            font-size: 13px;
            margin-top: auto;
            padding-top: 16px;
            border-top: 1px solid var(--ofp-border);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .meta-item svg { width: 16px; height: 16px; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--ofp-surface);
            border-radius: 16px;
            border: 1px solid var(--ofp-border);
        }
    </style>

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
<body>

    <header class="agent-header">
        <img src="<?php echo esc_url( $agent_logo ); ?>" alt="<?php echo esc_attr( $agent_name ); ?> Logo" class="agent-logo">
        <h1 class="agent-name"><?php echo esc_html( $agent_name ); ?></h1>
        <div class="agent-bio">
            <?php echo wp_kses_post( wpautop( $agent_bio ) ); ?>
        </div>
        <div class="agent-contact-actions">
            <?php if ( ! empty( $client->business_phone ) || ! empty( $client->phone ) ) : 
                $call_num = ! empty( $client->business_phone ) ? $client->business_phone : $client->phone;
            ?>
                <a href="tel:<?php echo esc_attr( preg_replace('/[^0-9+]/', '', $call_num) ); ?>" class="agent-btn btn-call">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
                    Call Us
                </a>
            <?php endif; ?>
            
            <?php if ( ! empty( $client->whatsapp_number ) || ! empty( $client->phone ) ) : 
                $wa_num = ! empty( $client->whatsapp_number ) ? $client->whatsapp_number : $client->phone;
                $wa_link = 'https://wa.me/' . preg_replace('/[^0-9]/', '', $wa_num);
            ?>
                <a href="<?php echo esc_url( $wa_link ); ?>" target="_blank" class="agent-btn btn-whatsapp">
                    <svg fill="currentColor" viewBox="0 0 24 24" width="20" height="20"><path d="M12.031 21c-1.6 0-3.166-.412-4.545-1.196l-.326-.185-3.376.885.903-3.29-.204-.324C3.65 15.485 3 13.784 3 12.015 3 7.042 7.043 3 12.029 3 16.992 3 21 7.043 21 12.022c0 4.97-4.008 8.978-8.969 8.978zm.026-16.518A7.472 7.472 0 0 0 4.541 12.02c0 1.496.39 2.955 1.127 4.24l.115.202-.533 1.943 1.988-.521.212.126A7.447 7.447 0 0 0 12.057 19.5c4.135 0 7.502-3.364 7.502-7.49 0-4.127-3.367-7.491-7.502-7.491zm4.125 10.231c-.226-.113-1.341-.663-1.549-.74-.207-.076-.358-.113-.509.114-.15.226-.583.74-.715.89-.131.151-.264.17-.49.057-.226-.113-.956-.353-1.821-1.126-.673-.603-1.127-1.348-1.258-1.575-.132-.226-.014-.349.099-.462.102-.102.226-.264.34-.396.113-.132.15-.226.226-.377.075-.151.037-.283-.02-.396-.056-.113-.509-1.226-.697-1.68-.184-.442-.371-.382-.509-.389-.132-.007-.283-.007-.434-.007-.15 0-.396.057-.604.283-.207.227-.791.774-.791 1.887 0 1.114.81 2.19 924 2.34.113.15 1.583 2.417 3.834 3.388.536.232.955.371 1.282.475.538.172 1.028.147 1.413.089.431-.065 1.341-.548 1.53-1.077.188-.528.188-.981.131-1.076-.056-.095-.207-.151-.433-.264z"/></svg>
                    WhatsApp Us
                </a>
            <?php endif; ?>
        </div>
    </header>

    <main class="properties-container">
        <h2 class="section-title">Available Properties</h2>

        <?php if ( $properties->have_posts() ) : ?>
            <div class="property-grid">
                <?php while ( $properties->have_posts() ) : $properties->the_post(); 
                    $price     = get_post_meta( get_the_ID(), 'ofp_price', true );
                    $price_fmt = is_numeric( $price ) ? '₦' . number_format( $price ) : $price;
                    $beds      = get_post_meta( get_the_ID(), 'ofp_bedrooms', true );
                    $baths     = get_post_meta( get_the_ID(), 'ofp_bathrooms', true );
                    $thumb_id  = get_post_meta( get_the_ID(), 'ofp_main_image', true );
                    $thumb_url = wp_get_attachment_image_url( $thumb_id, 'medium_large' );
                    if ( ! $thumb_url ) {
                        // Fallback placeholder
                        $thumb_url = OFP_URL . 'assets/images/placeholder.jpg';
                    }
                ?>
                    <a href="<?php the_permalink(); ?>" class="property-card">
                        <img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php the_title_attribute(); ?>" class="property-img">
                        <div class="property-content">
                            <h3 class="property-price"><?php echo esc_html( $price_fmt ); ?></h3>
                            <h4 class="property-title"><?php the_title(); ?></h4>
                            <div class="property-meta">
                                <?php if ( $beds ) : ?>
                                    <div class="meta-item">
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
                                        <?php echo esc_html( $beds ); ?> Beds
                                    </div>
                                <?php endif; ?>
                                <?php if ( $baths ) : ?>
                                    <div class="meta-item">
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                                        <?php echo esc_html( $baths ); ?> Baths
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endwhile; wp_reset_postdata(); ?>
            </div>
        <?php else : ?>
            <div class="empty-state">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="48" height="48" style="color:var(--ofp-border);margin-bottom:16px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                <h3 style="margin:0 0 8px;font-size:18px;">No Properties Yet</h3>
                <p style="margin:0;color:var(--ofp-text-light);">This agent has not published any properties.</p>
            </div>
        <?php endif; ?>
    </main>

    <?php wp_footer(); ?>
</body>
</html>
