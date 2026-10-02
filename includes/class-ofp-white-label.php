<?php
/**
 * Class OFP_White_Label
 *
 * Blueprint v2 Section 2.4 and 2.5.
 *
 * A "white label" (solo company) client has their own domain and their own
 * private property pages. This class does three jobs in one place:
 *
 *  1. Makes sure the clients table has the custom_domain and is_white_label
 *     columns, even on sites that were activated before they existed.
 *  2. Scopes every public property query (marketplace, homepage, similar
 *     properties, agent page, REST API):
 *       - on a white label client's own domain: ONLY that client's properties.
 *       - everywhere else: white label clients' properties are hidden.
 *  3. Keeps every link on a white label client's domain on that same domain,
 *     so visitors are never sent back to the main site.
 *
 * Client dashboard queries are untouched (they pass ofp_include_occupied).
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_White_Label {

	const SCHEMA_OPTION  = 'ofp_white_label_schema';
	const SCHEMA_VERSION = '1';

	public static function init(): void {
		self::ensure_schema();
		add_filter( 'posts_clauses', [ __CLASS__, 'scope_property_queries' ], 11, 2 );
		add_filter( 'home_url', [ __CLASS__, 'keep_links_on_white_label_host' ], 20, 2 );
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	/**
	 * Adds custom_domain and is_white_label to ofp_clients when missing.
	 * Runs once, then remembers it in an option, so no reactivation is needed.
	 */
	public static function ensure_schema(): void {
		if ( self::schema_ready() ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ofp_clients';

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return; // Plugin tables not created yet.
		}

		if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'custom_domain'" ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN custom_domain VARCHAR(150) DEFAULT NULL AFTER subdomain" );
		}
		if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'is_white_label'" ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN is_white_label TINYINT(1) NOT NULL DEFAULT 0 AFTER custom_domain" );
		}

		$both = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'custom_domain'" ) )
			&& ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'is_white_label'" ) );

		if ( $both ) {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
		}
	}

	public static function schema_ready(): bool {
		return get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION;
	}

	// -------------------------------------------------------------------------
	// Who is the visitor? (white label client for this host, if any)
	// -------------------------------------------------------------------------

	/**
	 * The white label client that owns the current host, or null.
	 * Null on the main site, on app/property hosts, on normal client
	 * subdomains, in wp-admin and in cron.
	 */
	public static function current_client(): ?object {
		static $memo = false;
		if ( $memo !== false ) {
			return $memo;
		}
		$memo = null;

		if ( is_admin() || ! self::schema_ready() || ! class_exists( 'OFP_Host_Router' ) ) {
			return $memo;
		}

		$client = OFP_Host_Router::current_client_record();
		if ( $client && ! empty( $client->is_white_label ) ) {
			$memo = $client;
		}
		return $memo;
	}

	// -------------------------------------------------------------------------
	// Central property filter (Blueprint 2.5)
	// -------------------------------------------------------------------------

	public static function scope_property_queries( array $clauses, WP_Query $query ): array {
		if ( is_admin() || wp_doing_cron() || $query->get( 'ofp_include_occupied' ) || $query->get( 'ofp_all_clients' ) ) {
			return $clauses;
		}
		if ( ! self::schema_ready() ) {
			return $clauses;
		}

		$post_type = $query->get( 'post_type' );
		$is_property_query = $post_type === 'ofp_property' || ( is_array( $post_type ) && in_array( 'ofp_property', $post_type, true ) );
		if ( ! $is_property_query ) {
			return $clauses;
		}

		global $wpdb;
		$properties = $wpdb->prefix . 'ofp_properties';
		$clients    = $wpdb->prefix . 'ofp_clients';
		$posts      = $wpdb->posts;

		$white_label = self::current_client();

		if ( $white_label ) {
			// On their own domain: only their own properties.
			$clauses['where'] .= $wpdb->prepare(
				" AND EXISTS (
					SELECT 1 FROM {$properties} ofp_wl_p
					WHERE ofp_wl_p.wp_post_id = {$posts}.ID
					  AND ofp_wl_p.client_id = %d
				)",
				(int) $white_label->id
			);
		} else {
			// Everywhere else: white label properties never appear.
			$clauses['where'] .= " AND NOT EXISTS (
				SELECT 1 FROM {$properties} ofp_wl_p
				INNER JOIN {$clients} ofp_wl_c ON ofp_wl_c.id = ofp_wl_p.client_id
				WHERE ofp_wl_p.wp_post_id = {$posts}.ID
				  AND ofp_wl_c.is_white_label = 1
			)";
		}

		return $clauses;
	}

	// -------------------------------------------------------------------------
	// Keep every link on the white label domain
	// -------------------------------------------------------------------------

	public static function keep_links_on_white_label_host( string $url, string $path = '' ): string {
		if ( is_admin() ) {
			return $url;
		}
		if ( ! self::current_client() ) {
			return $url;
		}
		$host = OFP_Host_Router::current_client_host();
		if ( ! $host ) {
			return $url;
		}
		return preg_replace( '#^https?://[^/]+#i', 'https://' . $host, $url, 1 );
	}

	// -------------------------------------------------------------------------
	// Domain helpers (used by the admin client screen)
	// -------------------------------------------------------------------------

	/**
	 * "https://www.CRM.SupremeHomes.com/path" becomes "crm.supremehomes.com".
	 */
	public static function normalize_domain( string $raw ): string {
		$d = strtolower( trim( $raw ) );
		$d = preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $d );
		$d = preg_replace( '#[/?\#].*$#', '', $d );
		$d = preg_replace( '/:\d+$/', '', $d );
		$d = rtrim( $d, '.' );
		if ( strpos( $d, 'www.' ) === 0 ) {
			$d = substr( $d, 4 );
		}
		return $d;
	}

	public static function is_valid_domain( string $domain ): bool {
		return (bool) preg_match( '/^(?=.{4,150}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain );
	}

	public static function custom_domain_exists( string $domain, int $exclude_id = 0 ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}ofp_clients WHERE custom_domain IN (%s, %s) AND id != %d LIMIT 1",
			$domain,
			'www.' . $domain,
			$exclude_id
		) );
	}

	/**
	 * Returns an error message, or an empty string when the domain is fine.
	 * An empty domain is allowed (it clears the field).
	 */
	public static function validate_custom_domain( string $domain, int $client_id ): string {
		if ( $domain === '' ) {
			return '';
		}
		if ( ! self::is_valid_domain( $domain ) ) {
			return 'Enter a valid domain such as crm.supremehomes.com (no spaces, no path).';
		}

		$base = strtolower( (string) get_option( 'ofp_crm_base_domain', '' ) );
		if ( $base && ( $domain === $base || str_ends_with( $domain, '.' . $base ) ) ) {
			return 'That address belongs to your own base domain. Use the Subdomain field for it instead.';
		}

		$site_host = strtolower( (string) parse_url( get_option( 'siteurl' ), PHP_URL_HOST ) );
		$site_host = preg_replace( '/^www\./', '', $site_host );
		if ( $site_host && $domain === $site_host ) {
			return 'That is the address of this main site. A client cannot use it.';
		}

		if ( self::custom_domain_exists( $domain, $client_id ) ) {
			return 'That domain is already used by another client.';
		}

		return '';
	}
}
