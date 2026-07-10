<?php
/**
 * Template: /property/{slug}/ — public single property detail page.
 *
 * Loaded via OFP_Property::load_templates(). Shows the property
 * regardless of who owns it (client-published or owner-published) —
 * ownership only matters for edit/delete permission in the client
 * dashboard (properties.php), never for public visibility.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

while ( have_posts() ) : the_post();
	$post_id       = get_the_ID();
	$price         = (float) get_post_meta( $post_id, 'ofp_price', true );
	$property_type = get_post_meta( $post_id, 'ofp_property_type', true );
	$bedrooms      = (int) get_post_meta( $post_id, 'ofp_bedrooms', true );
	$bathrooms     = (int) get_post_meta( $post_id, 'ofp_bathrooms', true );
	$size_sqm      = (float) get_post_meta( $post_id, 'ofp_size_sqm', true );
	$address       = get_post_meta( $post_id, 'ofp_address', true );
	$city          = get_post_meta( $post_id, 'ofp_city', true );
	$status        = get_post_meta( $post_id, 'ofp_listing_status', true );
	$gallery_ids   = json_decode( get_post_meta( $post_id, 'ofp_gallery_ids', true ) ?: '[]', true );
	?>

	<div class="ofp-property-single">
		<div class="ofp-property-single-header">
			<h1><?php the_title(); ?></h1>
			<span class="ofp-property-badge ofp-property-badge-<?php echo esc_attr( $status ?: 'available' ); ?>">
				<?php echo esc_html( ucfirst( $status ?: 'Available' ) ); ?>
			</span>
		</div>

		<div class="ofp-property-single-gallery">
			<?php if ( has_post_thumbnail() ) : ?>
				<?php the_post_thumbnail( 'large' ); ?>
			<?php endif; ?>
			<?php if ( ! empty( $gallery_ids ) ) : ?>
				<div class="ofp-property-thumbs">
					<?php foreach ( $gallery_ids as $attachment_id ) : ?>
						<?php echo wp_get_attachment_image( (int) $attachment_id, 'thumbnail' ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="ofp-property-single-details">
			<p class="ofp-property-price">NGN <?php echo esc_html( number_format( $price, 2 ) ); ?>
				<?php echo $property_type === 'rent' ? '<span class="ofp-muted">/ year</span>' : ''; ?>
			</p>

			<ul class="ofp-property-facts">
				<?php if ( $bedrooms ) : ?><li><?php echo esc_html( $bedrooms ); ?> Bedrooms</li><?php endif; ?>
				<?php if ( $bathrooms ) : ?><li><?php echo esc_html( $bathrooms ); ?> Bathrooms</li><?php endif; ?>
				<?php if ( $size_sqm ) : ?><li><?php echo esc_html( $size_sqm ); ?> sqm</li><?php endif; ?>
				<?php if ( $address || $city ) : ?>
					<li><?php echo esc_html( trim( $address . ( $city ? ', ' . $city : '' ), ', ' ) ); ?></li>
				<?php endif; ?>
			</ul>

			<div class="ofp-property-description">
				<?php the_content(); ?>
			</div>
		</div>

		<div class="ofp-property-single-contact">
			<h3>Interested in this property?</h3>
			<a href="<?php echo esc_url( home_url( '/contact?property=' . $post_id ) ); ?>" class="ofp-btn ofp-btn-primary">
				Contact Us
			</a>
		</div>
	</div>

<?php endwhile; ?>

<?php get_footer(); ?>
