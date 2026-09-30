<?php
/**
 * Keeps actively leased properties out of every public property query.
 *
 * This is deliberately SQL-level rather than template-specific: the existing
 * marketplace, homepage and similar-property components each create their own
 * WP_Query instances. Client dashboard queries are explicitly left untouched.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Rent_Visibility {

    public static function init(): void {
        add_filter( 'posts_clauses', [ __CLASS__, 'exclude_occupied_from_public_queries' ], 10, 2 );
    }

    public static function exclude_occupied_from_public_queries( array $clauses, WP_Query $query ): array {
        if ( is_admin() || $query->get( 'ofp_include_occupied' ) ) return $clauses;

        $post_type = $query->get( 'post_type' );
        $is_property_query = $post_type === 'ofp_property' || ( is_array( $post_type ) && in_array( 'ofp_property', $post_type, true ) );
        if ( ! $is_property_query ) return $clauses;

        global $wpdb;
        $properties = $wpdb->prefix . 'ofp_properties';
        $leases = $wpdb->prefix . 'ofp_property_leases';
        $posts = $wpdb->posts;

        $clauses['where'] .= $wpdb->prepare(
            " AND NOT EXISTS (
                SELECT 1 FROM {$properties} ofp_rent_property
                INNER JOIN {$leases} ofp_rent_lease ON ofp_rent_lease.property_id = ofp_rent_property.id
                WHERE ofp_rent_property.wp_post_id = {$posts}.ID
                  AND ofp_rent_lease.status = %s
            )",
            'active'
        );

        return $clauses;
    }
}
