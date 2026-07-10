<?php
/**
 * Template: /properties/ — public archive, grid of cards.
 *
 * Loaded via OFP_Property::load_templates() (hooked to
 * 'template_include'), so this renders regardless of the active
 * theme, unless the theme provides its own archive-ofp_property.php.
 *
 * Shows every PUBLISHED property across all clients plus any
 * owner-published ones — this is a single, central marketplace-style
 * listing site, not a per-client scoped view. Pending/draft listings
 * never appear here regardless of whose they are.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$paged = max( 1, get_query_var( 'paged' ) ?: 1 );

$query = new WP_Query( [
	'post_type'      => OFP_Property::POST_TYPE,
	'post_status'    => 'publish',
	'posts_per_page' => 12,
	'paged'          => $paged,
	'meta_query'     => [
		[
			'key'     => 'ofp_listing_status',
			'value'   => 'available',
			'compare' => '=',
		],
	],
] );

$type_filter = sanitize_text_field( $_GET['type'] ?? '' );
$city_filter = sanitize_text_field( $_GET['city'] ?? '' );
?>

<div class="ofp-properties-wrapper">
	<h1>Properties</h1>

	<form method="GET" class="ofp-properties-filter">
		<select name="type">
			<option value="">All Types</option>
			<option value="sale" <?php selected( $type_filter, 'sale' ); ?>>For Sale</option>
			<option value="rent" <?php selected( $type_filter, 'rent' ); ?>>For Rent</option>
		</select>
		<input type="text" name="city" placeholder="City" value="<?php echo esc_attr( $city_filter ); ?>">
		<button type="submit" class="ofp-btn">Filter</button>
	</form>

	<?php if ( $query->have_posts() ) : ?>
		<div class="ofp-properties-grid">
			<?php while ( $query->have_posts() ) : $query->the_post();
				$post_id       = get_the_ID();
				$price         = (float) get_post_meta( $post_id, 'ofp_price', true );
				$property_type = get_post_meta( $post_id, 'ofp_property_type', true );
				$bedrooms      = (int) get_post_meta( $post_id, 'ofp_bedrooms', true );
				$bathrooms     = (int) get_post_meta( $post_id, 'ofp_bathrooms', true );
				$city          = get_post_meta( $post_id, 'ofp_city', true );

				// Client-side filtering by GET params (kept simple —
				// a proper meta_query filter can replace this once
				// you have enough listings for it to matter for
				// performance).
				if ( $type_filter && $property_type !== $type_filter ) continue;
				if ( $city_filter && stripos( $city, $city_filter ) === false ) continue;
				?>
				<a href="<?php the_permalink(); ?>" class="ofp-property-card">
					<div class="ofp-property-card-image">
						<?php if ( has_post_thumbnail() ) : ?>
							<?php the_post_thumbnail( 'medium' ); ?>
						<?php else : ?>
							<div class="ofp-property-card-placeholder"></div>
						<?php endif; ?>
						<span class="ofp-property-badge"><?php echo esc_html( ucfirst( $property_type ?: 'Listing' ) ); ?></span>
					</div>
					<div class="ofp-property-card-body">
						<h3><?php the_title(); ?></h3>
						<p class="ofp-property-price">NGN <?php echo esc_html( number_format( $price, 2 ) ); ?></p>
						<p class="ofp-property-meta">
							<?php if ( $bedrooms ) : ?><?php echo esc_html( $bedrooms ); ?> bed<?php endif; ?>
							<?php if ( $bathrooms ) : ?> &middot; <?php echo esc_html( $bathrooms ); ?> bath<?php endif; ?>
							<?php if ( $city ) : ?> &middot; <?php echo esc_html( $city ); ?><?php endif; ?>
						</p>
					</div>
				</a>
			<?php endwhile; ?>
		</div>

		<div class="ofp-properties-pagination">
			<?php
			echo paginate_links( [
				'total'   => $query->max_num_pages,
				'current' => $paged,
			] );
			?>
		</div>

	<?php else : ?>
		<p class="ofp-muted">No properties match your search right now — check back soon.</p>
	<?php endif; ?>

	<?php wp_reset_postdata(); ?>
</div>

<?php get_footer(); ?>
