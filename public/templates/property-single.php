<?php
/**
 * Template: /property/{slug}/ — public single property detail page.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Phase 23: Facebook Meta Pixel Injection
add_action( 'wp_head', function() {
    $post_id   = get_the_ID();
    if ( ! $post_id ) return;
    
    $client_id = get_post_meta( $post_id, 'ofp_client_id', true );
    if ( ! $client_id ) return;
    
    global $wpdb;
    $pixel = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_pixel_id FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
            $client_id
        )
    );
    
    if ( ! empty( $pixel ) ) {
        ?>
        <!-- Phase 23 Meta Pixel -->
        <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?php echo esc_js( $pixel ); ?>');
        fbq('track', 'PageView');
        </script>
        <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo esc_attr( $pixel ); ?>&ev=PageView&noscript=1"/></noscript>
        <?php
    }
} );

get_header();

while ( have_posts() ) : the_post();
	$post_id       = get_the_ID();
	$price         = (float) get_post_meta( $post_id, 'ofp_price', true );
	$price_period  = get_post_meta( $post_id, 'ofp_price_period', true ) ?: 'year';
	$listing_type  = get_post_meta( $post_id, 'ofp_listing_type', true ) ?: 'sale';
	$property_type = get_post_meta( $post_id, 'ofp_property_type', true );
	$bedrooms      = (int) get_post_meta( $post_id, 'ofp_bedrooms', true );
	$bathrooms     = (int) get_post_meta( $post_id, 'ofp_bathrooms', true );
	$location      = get_post_meta( $post_id, 'ofp_location_text', true );
	$status        = get_post_meta( $post_id, 'ofp_status', true ) ?: 'live';
	$gallery_ids   = json_decode( get_post_meta( $post_id, 'ofp_gallery_ids', true ) ?: '[]', true );
	?>

	<div class="ofp-property-single" style="max-width:1000px; margin:0 auto; padding:40px 20px;">
		
        <div style="margin-bottom:24px;">
            <a href="<?php echo esc_url( home_url( '/properties/' ) ); ?>" style="color:#64748b; text-decoration:none; font-size:14px;">&larr; Back to all properties</a>
        </div>

		<div class="ofp-property-single-header" style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
			<h1 style="margin:0; font-size:32px; font-weight:700; color:#0f172a;"><?php the_title(); ?></h1>
			<span class="ofp-property-badge" style="background:<?php echo $status === 'live' ? '#10b981' : '#64748b'; ?>; color:white; padding:6px 12px; border-radius:100px; font-size:14px; font-weight:600;">
				<?php echo esc_html( ucfirst( $status === 'live' ? 'Available' : $status ) ); ?>
			</span>
		</div>

		<div class="ofp-property-single-gallery" style="margin-bottom:40px;">
			<?php if ( has_post_thumbnail() ) : ?>
				<div style="width:100%; aspect-ratio:16/9; background:#f1f5f9; border-radius:16px; overflow:hidden; margin-bottom:16px;">
                    <?php the_post_thumbnail( 'full', ['style' => 'width:100%; height:100%; object-fit:cover;'] ); ?>
                </div>
			<?php endif; ?>
			<?php if ( ! empty( $gallery_ids ) ) : ?>
				<div class="ofp-property-thumbs" style="display:flex; gap:12px; overflow-x:auto; padding-bottom:8px;">
					<?php foreach ( $gallery_ids as $attachment_id ) : ?>
						<div style="width:120px; height:80px; flex-shrink:0; border-radius:8px; overflow:hidden; background:#f1f5f9; border:2px solid transparent; cursor:pointer;">
                            <?php echo wp_get_attachment_image( (int) $attachment_id, 'thumbnail', false, ['style' => 'width:100%; height:100%; object-fit:cover;'] ); ?>
                        </div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="ofp-property-single-details" style="display:grid; grid-template-columns: 2fr 1fr; gap:40px;">
			
            <div class="ofp-property-main">
                <h2 style="font-size:24px; font-weight:600; margin:0 0 16px 0; color:#0f172a;">Overview</h2>
                <div class="ofp-property-description" style="font-size:16px; line-height:1.6; color:#475569;">
                    <?php the_content(); ?>
                </div>
            </div>

            <div class="ofp-property-sidebar" style="background:white; border:1px solid #e2e8f0; border-radius:12px; padding:24px; height:fit-content; position:sticky; top:40px;">
                <p class="ofp-property-price" style="margin:0 0 24px 0; font-size:28px; font-weight:700; color:#2563eb;">
                    NGN <?php echo esc_html( number_format( $price, 2 ) ); ?>
                    <?php if ( $listing_type === 'rent' ) : ?>
                        <span style="font-size:16px; color:#64748b; font-weight:500;">/ <?php echo esc_html( $price_period ); ?></span>
                    <?php endif; ?>
                </p>

                <ul class="ofp-property-facts" style="list-style:none; padding:0; margin:0 0 24px 0; display:flex; flex-direction:column; gap:12px; font-size:15px; color:#475569;">
                    <li style="display:flex; justify-content:space-between;">
                        <span style="color:#64748b;">Type</span>
                        <strong><?php echo esc_html( ucfirst( $property_type ) ); ?> for <?php echo esc_html( ucfirst( $listing_type ) ); ?></strong>
                    </li>
                    <?php if ( $bedrooms ) : ?>
                        <li style="display:flex; justify-content:space-between;">
                            <span style="color:#64748b;">Bedrooms</span>
                            <strong><?php echo esc_html( $bedrooms ); ?></strong>
                        </li>
                    <?php endif; ?>
                    <?php if ( $bathrooms ) : ?>
                        <li style="display:flex; justify-content:space-between;">
                            <span style="color:#64748b;">Bathrooms</span>
                            <strong><?php echo esc_html( $bathrooms ); ?></strong>
                        </li>
                    <?php endif; ?>
                    <?php if ( $location ) : ?>
                        <li style="display:flex; flex-direction:column; gap:4px; margin-top:8px; padding-top:12px; border-top:1px solid #f1f5f9;">
                            <span style="color:#64748b;">Location</span>
                            <strong><?php echo esc_html( $location ); ?></strong>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="ofp-property-single-contact">
                    <a href="<?php echo esc_url( home_url( '/contact?property=' . $post_id ) ); ?>" style="display:block; width:100%; text-align:center; background:#2563eb; color:white; padding:12px; border-radius:8px; font-weight:600; text-decoration:none; transition:background 0.2s;">
                        Contact Us
                    </a>
                </div>
            </div>

		</div>
	</div>

<?php endwhile; ?>

<?php get_footer(); ?>
