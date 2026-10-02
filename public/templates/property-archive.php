<?php
/**
 * Template: /properties/ — public archive, grid of cards.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( file_exists( __DIR__ . '/property-marketplace.php' ) ) {
    include __DIR__ . '/property-marketplace.php';
    return;
}

get_header();

$paged = max( 1, get_query_var( 'paged' ) ?: 1 );

// Extract filters from $_GET
$search_filter = sanitize_text_field( $_GET['search'] ?? '' );
$type_filter = isset($_GET['type']) && is_array($_GET['type']) ? array_map('sanitize_text_field', $_GET['type']) : [];
$ptype_filter = isset($_GET['ptype']) && is_array($_GET['ptype']) ? array_map('sanitize_text_field', $_GET['ptype']) : [];
$price_ranges = isset($_GET['price_range']) && is_array($_GET['price_range']) ? array_map('sanitize_text_field', $_GET['price_range']) : [];

$query = new WP_Query( [
	'post_type'      => 'ofp_property',
	'post_status'    => 'publish',
	'posts_per_page' => 12,
	'paged'          => $paged,
	'meta_query'     => [
		[
			'key'     => 'ofp_status',
			'value'   => 'live',
			'compare' => '=',
		],
	],
] );
?>

<div class="mp-container">
    
    <div class="mp-mobile-header">
        <h1 style="margin:0; font-size:24px; font-weight:700;">Properties</h1>
        <button id="mp-filter-toggle" class="mp-filter-toggle">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            Filter
        </button>
    </div>

    <div id="mp-sidebar-overlay" class="mp-sidebar-overlay"></div>

    <aside id="mp-sidebar" class="mp-sidebar">
        
        <form id="mp-filter-form" method="GET" action="">
            <div class="mp-search-box">
                <input type="text" name="search" placeholder="Location or keywords..." value="<?php echo esc_attr( $search_filter ); ?>">
                <button type="submit">Search</button>
            </div>

            <div class="mp-sidebar-header">
                <h2>Custom Filter</h2>
                <button type="button" id="mp-clear-all" class="mp-clear-all">Clear All</button>
            </div>

            <details class="mp-filter-group" open>
                <summary>Property Price</summary>
                <div class="mp-checkbox-list">
                    <label class="mp-checkbox-item">
                        <input type="checkbox" name="price_range[]" value="0-50" <?php checked(in_array('0-50', $price_ranges)); ?>>
                        Under 50M
                    </label>
                    <label class="mp-checkbox-item">
                        <input type="checkbox" name="price_range[]" value="50-100" <?php checked(in_array('50-100', $price_ranges)); ?>>
                        50M - 100M
                    </label>
                    <label class="mp-checkbox-item">
                        <input type="checkbox" name="price_range[]" value="100-250" <?php checked(in_array('100-250', $price_ranges)); ?>>
                        100M - 250M
                    </label>
                    <label class="mp-checkbox-item">
                        <input type="checkbox" name="price_range[]" value="250-750" <?php checked(in_array('250-750', $price_ranges)); ?>>
                        250M - 750M
                    </label>
                    <label class="mp-checkbox-item">
                        <input type="checkbox" name="price_range[]" value="750-plus" <?php checked(in_array('750-plus', $price_ranges)); ?>>
                        Over 750M
                    </label>
                </div>
                <!-- Visual Slider Track -->
                <div class="mp-price-visual">
                    <div class="mp-price-labels">
                        <span>Min</span>
                        <span>Max</span>
                    </div>
                    <div class="mp-price-track">
                        <div class="mp-price-fill"></div>
                        <div class="mp-price-handle left"></div>
                        <div class="mp-price-handle right"></div>
                    </div>
                </div>
            </details>

            <details class="mp-filter-group" open>
                <summary>Property Type</summary>
                <div class="mp-checkbox-list">
                    <?php 
                    $ptypes = ['apartment' => 'Apartment', 'duplex' => 'Duplex', 'bungalow' => 'Bungalow', 'terrace' => 'Terrace', 'land' => 'Land'];
                    foreach ($ptypes as $val => $label): 
                    ?>
                    <label class="mp-checkbox-item">
                        <input type="checkbox" name="ptype[]" value="<?php echo esc_attr($val); ?>" <?php checked(in_array($val, $ptype_filter)); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </details>

            <details class="mp-filter-group" open>
                <summary>Listing Type</summary>
                <div class="mp-checkbox-list">
                    <label class="mp-checkbox-item">
                        <input type="checkbox" name="type[]" value="sale" <?php checked(in_array('sale', $type_filter)); ?>>
                        For Sale
                    </label>
                    <label class="mp-checkbox-item">
                        <input type="checkbox" name="type[]" value="rent" <?php checked(in_array('rent', $type_filter)); ?>>
                        For Rent
                    </label>
                </div>
            </details>

            <!-- Placeholders -->
            <details class="mp-filter-group">
                <summary>Tags</summary>
                <div class="mp-checkbox-list">
                    <label class="mp-checkbox-item"><input type="checkbox" name="tags[]" value="pool"> Swimming Pool</label>
                    <label class="mp-checkbox-item"><input type="checkbox" name="tags[]" value="gym"> Gym</label>
                </div>
            </details>
            <details class="mp-filter-group">
                <summary>Brands</summary>
                <div class="mp-checkbox-list">
                    <label class="mp-checkbox-item"><input type="checkbox" name="brands[]" value="b1"> Brand A</label>
                    <label class="mp-checkbox-item"><input type="checkbox" name="brands[]" value="b2"> Brand B</label>
                </div>
            </details>

        </form>
    </aside>

    <main class="mp-main-content">
        <div class="mp-main-header">
            <h1 style="display: none;">Properties</h1>
            <p><?php echo esc_html($query->found_posts); ?> properties found that match your preferences</p>
        </div>

        <div id="mp-grid-container" class="mp-grid-wrapper">
            <div id="mp-loading-overlay" class="mp-loading-overlay"><div class="mp-spinner"></div></div>
            
            <?php if ( $query->have_posts() ) : ?>
                <div class="mp-grid">
                    <?php while ( $query->have_posts() ) : $query->the_post();
                        $post_id       = get_the_ID();
                        $price         = (float) get_post_meta( $post_id, 'ofp_price', true );
                        $listing_type  = get_post_meta( $post_id, 'ofp_listing_type', true );
                        $property_type = get_post_meta( $post_id, 'ofp_property_type', true );
                        $bedrooms      = (int) get_post_meta( $post_id, 'ofp_bedrooms', true );
                        $bathrooms     = (int) get_post_meta( $post_id, 'ofp_bathrooms', true );
                        $location      = get_post_meta( $post_id, 'ofp_location_text', true );

                        // Filter logic in PHP
                        if ( !empty($type_filter) && !in_array($listing_type, $type_filter) ) continue;
                        if ( !empty($ptype_filter) && !in_array($property_type, $ptype_filter) ) continue;
                        if ( $search_filter && stripos( $location, $search_filter ) === false && stripos( get_the_title(), $search_filter ) === false ) continue;
                        
                        // Handle price filter
                        if ( !empty($price_ranges) ) {
                            $price_m = $price / 1000000;
                            $matches_price = false;
                            foreach ($price_ranges as $range) {
                                if ($range === '0-50' && $price_m <= 50) $matches_price = true;
                                if ($range === '50-100' && $price_m > 50 && $price_m <= 100) $matches_price = true;
                                if ($range === '100-250' && $price_m > 100 && $price_m <= 250) $matches_price = true;
                                if ($range === '250-750' && $price_m > 250 && $price_m <= 750) $matches_price = true;
                                if ($range === '750-plus' && $price_m > 750) $matches_price = true;
                            }
                            if ( !$matches_price ) continue;
                        }
                        ?>
                        <a href="<?php the_permalink(); ?>" class="ofp-property-card" style="display:block; background:white; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; text-decoration:none; color:inherit; transition:transform 0.2s, box-shadow 0.2s;">
                            <div class="ofp-property-card-image" style="position:relative; aspect-ratio:4/3; background:#f1f5f9; overflow:hidden;">
                                <?php if ( has_post_thumbnail() ) : ?>
                                    <?php the_post_thumbnail( 'medium', ['style' => 'width:100%; height:100%; object-fit:cover;'] ); ?>
                                <?php else : ?>
                                    <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#94a3b8;">No Image</div>
                                <?php endif; ?>
                                <span class="ofp-property-badge" style="position:absolute; top:12px; left:12px; background:white; color:#0f172a; padding:4px 10px; border-radius:100px; font-size:12px; font-weight:600; box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                                    <?php echo esc_html( ucfirst( $listing_type ?: 'Listing' ) ); ?>
                                </span>
                            </div>
                            <div class="ofp-property-card-body" style="padding:16px;">
                                <h3 style="margin:0 0 8px 0; font-size:16px; font-weight:600; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php the_title(); ?></h3>
                                <p style="margin:0 0 12px 0; font-size:18px; font-weight:700; color:#2563eb;">NGN <?php echo esc_html( number_format( $price, 2 ) ); ?></p>
                                <p style="margin:0; font-size:13px; color:#64748b; display:flex; gap:12px;">
                                    <?php if ( $bedrooms ) : ?><span><?php echo esc_html( $bedrooms ); ?> bed</span><?php endif; ?>
                                    <?php if ( $bathrooms ) : ?><span><?php echo esc_html( $bathrooms ); ?> bath</span><?php endif; ?>
                                </p>
                                <?php if ( $location ) : ?>
                                    <p style="margin:8px 0 0 0; font-size:13px; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        📍 <?php echo esc_html( $location ); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endwhile; ?>
                </div>
                
                <div class="ofp-properties-pagination" style="margin-top:40px; display:flex; justify-content:center; gap:8px;">
                    <?php
                    echo paginate_links( [
                        'total'   => $query->max_num_pages,
                        'current' => $paged,
                    ] );
                    ?>
                </div>

            <?php else : ?>
                <p class="ofp-muted" style="color:#64748b; font-size:16px; text-align:center; padding:40px 0;">No properties match your search right now — check back soon.</p>
            <?php endif; ?>

            <?php wp_reset_postdata(); ?>
        </div>
    </main>

</div>

<?php get_footer(); ?>
