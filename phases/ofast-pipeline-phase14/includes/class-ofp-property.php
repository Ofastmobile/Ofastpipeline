<?php
/**
 * Class OFP_Property
 *
 * Phase 14 — Property Listing public pages.
 *
 * Registers the 'ofp_property' custom post type, its meta fields, the
 * three listing plan tiers (Bronze/Silver/Gold — cap + price, editable
 * in Settings), and a template loader so the plugin's own archive/
 * single templates render regardless of the active theme.
 *
 * DELIBERATELY NOT built on ACF — this plugin is meant to be
 * self-contained with no dependency on another plugin being active.
 * Clients never see wp-admin at all; they manage their own listings
 * through a plain form on their portal dashboard (properties.php),
 * which creates/edits real 'ofp_property' posts behind the scenes.
 *
 * Ownership model: since this is one central multi-tenant WordPress
 * site (not one plugin install per client), every property post
 * carries an 'ofp_client_id' meta value tying it to the client who
 * created it. Properties you (the admin) create directly in wp-admin
 * simply have no ofp_client_id meta at all — that's what makes them
 * "owner-published" and exempt from any plan cap, with no separate
 * override flag needed.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property {

	const POST_TYPE = 'ofp_property';

	/**
	 * Listing plan tiers. If a 4th tier is ever added, add its key
	 * here and to the two DEFAULT_* arrays below — the Settings UI
	 * and the plan picker in properties.php both iterate this list
	 * dynamically.
	 */
	const PLAN_KEYS = [ 'bronze', 'silver', 'gold' ];

	const DEFAULT_PLAN_PRICES = [
		'bronze' => 7500.00,
		'silver' => 15000.00,
		'gold'   => 30000.00,
	];

	const DEFAULT_PLAN_CAPS = [
		'bronze' => 3,
		'silver' => 10,
		'gold'   => 25,
	];

	/**
	 * Wires up everything this class needs. Call this once during
	 * plugin bootstrap (wherever OFP_Subscription, OFP_Client, etc.
	 * are already instantiated/hooked from your main plugin file).
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_post_type' ] );
		add_action( 'init', [ __CLASS__, 'register_meta_fields' ] );
		add_filter( 'template_include', [ __CLASS__, 'load_templates' ] );
	}

	/**
	 * Registers the ofp_property custom post type. Public, with its
	 * own archive at /properties/ and singles at /property/{slug}/.
	 */
	public static function register_post_type(): void {
		register_post_type( self::POST_TYPE, [
			'labels' => [
				'name'          => 'Properties',
				'singular_name' => 'Property',
				'add_new_item'  => 'Add New Property',
				'edit_item'     => 'Edit Property',
				'all_items'     => 'All Properties',
			],
			'public'       => true,
			'has_archive'  => 'properties',
			'rewrite'      => [ 'slug' => 'property', 'with_front' => false ],
			'supports'     => [ 'title', 'editor', 'thumbnail' ],
			'menu_icon'    => 'dashicons-admin-home',
			'show_in_menu' => true,
			// Deliberately not show_in_rest => true — this CPT isn't
			// meant to be edited via Gutenberg/the block editor; both
			// admin review and client submission go through this
			// plugin's own forms/list table, not the block editor.
		] );
	}

	/**
	 * Registers post meta fields used by property listings. Kept as
	 * plain post meta (not ACF) so this plugin has no dependency on
	 * another plugin being active.
	 */
	public static function register_meta_fields(): void {
		$fields = [
			'ofp_client_id'      => 'integer', // absent/null = owner-published, exempt from any cap
			'ofp_price'          => 'number',
			'ofp_property_type'  => 'string',  // 'sale'|'rent'
			'ofp_bedrooms'       => 'integer',
			'ofp_bathrooms'      => 'integer',
			'ofp_size_sqm'       => 'number',
			'ofp_address'        => 'string',
			'ofp_city'           => 'string',
			'ofp_listing_status' => 'string',  // 'available'|'sold'|'rented'
			'ofp_gallery_ids'    => 'string',  // JSON-encoded array of attachment IDs
		];

		foreach ( $fields as $key => $type ) {
			register_post_meta( self::POST_TYPE, $key, [
				'type'         => $type,
				'single'       => true,
				'show_in_rest' => false,
				// Front-end forms write these via update_post_meta()
				// directly under capability checks we do ourselves
				// (ownership + nonce), not via the REST API, so no
				// auth_callback is needed here.
			] );
		}
	}

	/**
	 * Serves this plugin's own archive/single templates for the
	 * ofp_property post type, regardless of the active theme — unless
	 * the active theme explicitly provides its own
	 * archive-ofp_property.php / single-ofp_property.php, in which
	 * case that takes priority (standard WordPress template hierarchy
	 * courtesy).
	 *
	 * @param string $template
	 * @return string
	 */
	public static function load_templates( string $template ): string {
		if ( is_post_type_archive( self::POST_TYPE ) ) {
			$theme_override = locate_template( 'archive-' . self::POST_TYPE . '.php' );
			if ( $theme_override ) return $theme_override;
			return OFP_PLUGIN_DIR . 'public/templates/property-archive.php';
		}

		if ( is_singular( self::POST_TYPE ) ) {
			$theme_override = locate_template( 'single-' . self::POST_TYPE . '.php' );
			if ( $theme_override ) return $theme_override;
			return OFP_PLUGIN_DIR . 'public/templates/property-single.php';
		}

		return $template;
	}

	/* -----------------------------------------------------------
	 * Listing plan pricing + caps (Bronze/Silver/Gold)
	 * --------------------------------------------------------- */

	/**
	 * @return array ['bronze' => float, 'silver' => float, 'gold' => float]
	 */
	public static function get_plan_prices(): array {
		$prices = [];
		foreach ( self::PLAN_KEYS as $plan ) {
			$prices[ $plan ] = (float) get_option( "ofp_listing_price_{$plan}", self::DEFAULT_PLAN_PRICES[ $plan ] );
		}
		return $prices;
	}

	/**
	 * @return array ['bronze' => int, 'silver' => int, 'gold' => int]
	 */
	public static function get_plan_caps(): array {
		$caps = [];
		foreach ( self::PLAN_KEYS as $plan ) {
			$caps[ $plan ] = (int) get_option( "ofp_listing_cap_{$plan}", self::DEFAULT_PLAN_CAPS[ $plan ] );
		}
		return $caps;
	}

	public static function get_plan_price( ?string $plan ): float {
		if ( ! $plan || ! in_array( $plan, self::PLAN_KEYS, true ) ) return 0.0;
		return (float) get_option( "ofp_listing_price_{$plan}", self::DEFAULT_PLAN_PRICES[ $plan ] );
	}

	public static function get_plan_cap( ?string $plan ): int {
		if ( ! $plan || ! in_array( $plan, self::PLAN_KEYS, true ) ) return 0;
		return (int) get_option( "ofp_listing_cap_{$plan}", self::DEFAULT_PLAN_CAPS[ $plan ] );
	}

	/**
	 * Persists the full listing plan pricing + cap set. Called only
	 * from the Settings form handler (Patch G,
	 * handle_save_listing_plans() in admin-menu.php).
	 *
	 * @param array $prices ['bronze' => float, 'silver' => float, 'gold' => float]
	 * @param array $caps   ['bronze' => int, 'silver' => int, 'gold' => int]
	 * @return true
	 */
	public static function save_plans( array $prices, array $caps ): bool {
		foreach ( self::PLAN_KEYS as $plan ) {
			$price = isset( $prices[ $plan ] ) ? max( 0.0, (float) $prices[ $plan ] ) : self::DEFAULT_PLAN_PRICES[ $plan ];
			$cap   = isset( $caps[ $plan ] )   ? max( 1, (int) $caps[ $plan ] )       : self::DEFAULT_PLAN_CAPS[ $plan ];
			update_option( "ofp_listing_price_{$plan}", $price );
			update_option( "ofp_listing_cap_{$plan}", $cap );
		}
		return true;
	}

	/* -----------------------------------------------------------
	 * Ownership + cap-check helpers
	 * --------------------------------------------------------- */

	/**
	 * How many non-trashed properties a client currently has,
	 * regardless of their moderation status (pending counts toward
	 * the cap too — a client shouldn't be able to bypass their cap
	 * by submitting a pile of pending listings).
	 *
	 * @param int $client_id
	 * @return int
	 */
	public static function count_for_client( int $client_id ): int {
		$query = new WP_Query( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => [ 'publish', 'pending', 'draft' ],
			'meta_key'       => 'ofp_client_id',
			'meta_value'     => $client_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		return count( $query->posts );
	}

	/**
	 * Whether a client can add one more property right now, based on
	 * their currently active listing plan (via
	 * OFP_Subscription::get_active_listing_plan()) and how many
	 * properties they already have.
	 *
	 * @param int $client_id
	 * @return bool
	 */
	public static function can_add_property( int $client_id ): bool {
		$plan = OFP_Subscription::get_active_listing_plan( $client_id );
		if ( ! $plan ) return false; // no active listing plan at all

		$cap = self::get_plan_cap( $plan );
		return self::count_for_client( $client_id ) < $cap;
	}

	/**
	 * All of a client's own properties (any status except trash),
	 * newest first — used by properties.php's "My Properties" list.
	 *
	 * @param int $client_id
	 * @return WP_Post[]
	 */
	public static function get_client_properties( int $client_id ): array {
		$query = new WP_Query( [
			'post_type'      => self::POST_TYPE,
			'post_status'    => [ 'publish', 'pending', 'draft' ],
			'meta_key'       => 'ofp_client_id',
			'meta_value'     => $client_id,
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );
		return $query->posts;
	}

	/**
	 * Whether a given client owns a given property post — used before
	 * any edit/delete action in properties.php to stop one client
	 * editing another client's listing.
	 *
	 * @param int $post_id
	 * @param int $client_id
	 * @return bool
	 */
	public static function is_owned_by( int $post_id, int $client_id ): bool {
		return (int) get_post_meta( $post_id, 'ofp_client_id', true ) === $client_id;
	}
}
