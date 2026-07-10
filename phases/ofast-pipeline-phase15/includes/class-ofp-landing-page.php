<?php
/**
 * Class OFP_Landing_Page
 *
 * Phase 15 — subdomain/custom-domain landing page routing.
 *
 * Since OFast Pipeline is one central WordPress install serving many
 * clients (not one install per client), every client's landing page
 * is really just a normal WordPress Page you build yourself in
 * wp-admin — this class's only job is figuring out, from the
 * hostname a visitor arrived on, WHICH page to show as the homepage
 * for that hostname.
 *
 * Two hostname shapes are supported:
 *   - {business}.crmdomain.com  — a subdomain you control (wildcard DNS)
 *   - their-own-domain.com      — a domain the client already owns,
 *                                  pointed at your server (see the
 *                                  DNS/SSL note in this phase's README
 *                                  — that part is infrastructure, not
 *                                  code, and needs a decision from you)
 *
 * This class does NOT touch DNS or SSL — it only decides what content
 * to render once a request for either kind of hostname reaches this
 * WordPress install. Getting the hostname to reach this install at
 * all is a server/DNS-level setup, covered in the README, not here.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Landing_Page {

	/**
	 * Wires up the hostname-based homepage override. Call once during
	 * plugin bootstrap, same place OFP_Property::init() etc. are called.
	 */
	public static function init(): void {
		add_filter( 'template_include', [ __CLASS__, 'maybe_override_homepage' ], 5 );
	}

	/**
	 * If the current request's hostname matches a client's subdomain
	 * or custom domain, AND this is a request for the site's homepage,
	 * swaps the main query to render that client's assigned landing
	 * page instead of your own site's normal homepage — while leaving
	 * every other URL (/login, /signup, /properties, etc.) working
	 * completely normally on that same hostname.
	 *
	 * @param string $template
	 * @return string
	 */
	public static function maybe_override_homepage( string $template ): string {
		// Only the homepage gets swapped — a client's subdomain still
		// needs /login, /credits, /properties etc. to work exactly as
		// they do on your main domain, so this deliberately does NOT
		// touch routing for anything except is_front_page()/is_home().
		if ( ! is_front_page() && ! is_home() ) {
			return $template;
		}

		$host = self::normalize_host( $_SERVER['HTTP_HOST'] ?? '' );
		if ( ! $host ) {
			return $template;
		}

		$client = self::get_client_by_host( $host );
		if ( ! $client || empty( $client->landing_page_id ) ) {
			return $template;
		}

		$landing_page = get_post( $client->landing_page_id );
		if ( ! $landing_page || $landing_page->post_status !== 'publish' ) {
			// Assigned page was unpublished/deleted — fail safe to
			// your normal homepage rather than a broken/blank page.
			error_log( "OFP_Landing_Page — client {$client->id}'s landing_page_id ({$client->landing_page_id}) is missing or unpublished" );
			return $template;
		}

		global $wp_query, $post;
		$wp_query = new WP_Query( [ 'page_id' => $landing_page->ID ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$post     = $landing_page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );

		$page_template = get_page_template_slug( $landing_page->ID );
		if ( $page_template && locate_template( $page_template ) ) {
			return locate_template( $page_template );
		}

		return locate_template( 'page.php' ) ?: locate_template( 'index.php' ) ?: $template;
	}

	/**
	 * Strips port and leading "www." from a Host header for
	 * comparison purposes.
	 *
	 * @param string $raw_host
	 * @return string
	 */
	private static function normalize_host( string $raw_host ): string {
		$host = strtolower( trim( $raw_host ) );
		$host = preg_replace( '/:\d+$/', '', $host ); // strip :port
		$host = preg_replace( '/^www\./', '', $host );
		return $host;
	}

	/**
	 * Looks up a client by either their assigned subdomain (matched
	 * against the configured base crmdomain) or their custom domain
	 * (exact match).
	 *
	 * @param string $host already normalized via normalize_host()
	 * @return object|null client row, or null if no match
	 */
	private static function get_client_by_host( string $host ): ?object {
		global $wpdb;

		$base_domain = self::normalize_host( get_option( 'ofp_crm_base_domain', '' ) );

		// Subdomain match: {slug}.{base_domain}
		if ( $base_domain && str_ends_with( $host, '.' . $base_domain ) ) {
			$slug = substr( $host, 0, -( strlen( $base_domain ) + 1 ) );
			$row = $wpdb->get_row( $wpdb->prepare( "
				SELECT * FROM {$wpdb->prefix}ofp_clients WHERE subdomain = %s LIMIT 1
			", $slug ) );
			if ( $row ) return $row;
		}

		// Custom domain match: exact hostname
		$row = $wpdb->get_row( $wpdb->prepare( "
			SELECT * FROM {$wpdb->prefix}ofp_clients WHERE custom_domain = %s LIMIT 1
		", $host ) );

		return $row ?: null;
	}
}
