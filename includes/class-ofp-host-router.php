<?php
/**
 * Class OFP_Host_Router
 *
 * Phase 16 & Blueprint v2: app.crmdomain.com, property.crmdomain.com, and client subdomain routing.
 *
 * This is the one place that decides "which links point where" across
 * the whole plugin.
 *
 * 1. WordPress's home_url() always builds links using your MAIN site
 *    address, no matter which address the visitor actually used to
 *    get here. This class fixes that for every portal link in one place,
 *    rewriting to app.{base_domain} or the client's own {subdomain}.{base_domain}.
 *
 * 2. It keeps a list of "reserved" subdomain words (app, property,
 *    www, etc.) so a client can never accidentally be assigned one of
 *    these as their own subdomain slug.
 *
 * 3. Client's own subdomain is their branded dashboard address:
 *    When on clientname.mydomain.com, /login, /dashboard, etc. render
 *    directly on that host instead of forcing a redirect to app.{base_domain}.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Host_Router {

	/**
	 * Words that can never be assigned to a client as their own
	 * subdomain slug, since they're reserved for the system itself.
	 */
	const RESERVED_SUBDOMAINS = [ 'app', 'property', 'www', 'mail', 'ftp', 'admin', 'api', 'crm', 'portal' ];

	/**
	 * Portal routes that resolve to the portal app host (or client's branded host).
	 * Add a new slug here any time a new portal screen is added.
	 */
	const APP_ROUTES = [
		'login', 'signup', 'dashboard', 'credits', 'properties', 'tenants',
		'leads', 'templates', 'team', 'api-settings', 'forgot-password',
		'reset-password', 'account', 'notifications', 'notification-settings', 'funding'
	];

	public static function init(): void {
		add_filter( 'home_url', [ __CLASS__, 'rewrite_portal_links' ], 10, 2 );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_redirect_app_root' ] );
		add_filter( 'redirect_canonical', [ __CLASS__, 'guard_canonical_redirect' ], 10, 2 );
	}

	/**
	 * Whether a given host is one of the system-reserved subdomains.
	 *
	 * @param string $subdomain_slug slug, e.g. "app" — not the full hostname
	 * @return bool
	 */
	public static function is_reserved( string $subdomain_slug ): bool {
		return in_array( strtolower( trim( $subdomain_slug ) ), self::RESERVED_SUBDOMAINS, true );
	}

	/**
	 * Resolves the client record associated with the current hostname
	 * if the visitor is on a client subdomain or custom domain.
	 */
	public static function current_client_record(): ?object {
		if ( self::current_zone() !== 'client' ) {
			return null;
		}

		static $cached = -1;
		if ( $cached !== -1 ) {
			return $cached;
		}

		$host = strtolower( trim( $_SERVER['HTTP_HOST'] ?? '' ) );
		$host = preg_replace( '/:\d+$/', '', $host );
		$base_domain = get_option( 'ofp_crm_base_domain' );

		global $wpdb;
		$client = null;

		// 1. Check if it's a subdomain of base_domain: {subdomain}.{base_domain}
		if ( $base_domain && str_ends_with( $host, '.' . $base_domain ) ) {
			$sub = substr( $host, 0, -strlen( '.' . $base_domain ) );
			if ( ! self::is_reserved( $sub ) ) {
				$client = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}ofp_clients WHERE subdomain = %s AND status != 'trash' LIMIT 1",
					$sub
				) );
			}
		}

		// 2. Check if it's a custom domain (Level 2)
		if ( ! $client ) {
			$has_col = $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'custom_domain'" );
			if ( $has_col ) {
				$client = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}ofp_clients WHERE custom_domain = %s AND status != 'trash' LIMIT 1",
					$host
				) );
			}
		}

		$cached = $client;
		return $cached;
	}

	/**
	 * Returns current client host string if visitor is on a valid client host.
	 */
	public static function current_client_host(): ?string {
		$client = self::current_client_record();
		if ( ! $client ) {
			return null;
		}
		$host = strtolower( trim( $_SERVER['HTTP_HOST'] ?? '' ) );
		return preg_replace( '/:\d+$/', '', $host );
	}

	/**
	 * Rewrites portal URLs to use the active host:
	 * If on a client's branded subdomain, stays on that branded address.
	 * Otherwise rewrites to app.{base_domain}.
	 *
	 * @param string $url  the URL WordPress was about to return
	 * @param string $path the path that was requested, e.g. '/login'
	 * @return string
	 */
	public static function rewrite_portal_links( string $url, string $path ): string {
		$clean_path    = ltrim( (string) parse_url( $path, PHP_URL_PATH ), '/' );
		$first_segment = strtok( $clean_path, '/' );

		if ( ! in_array( $first_segment, self::APP_ROUTES, true ) ) {
			return $url; // not a portal route — leave untouched
		}

		$base_domain = get_option( 'ofp_crm_base_domain' );
		if ( ! $base_domain ) {
			return $url; // option not set yet — fail safe to normal behaviour
		}

		// If visitor is currently on a recognized client subdomain, keep them on that client host!
		if ( self::current_zone() === 'client' && self::current_client_host() ) {
			return preg_replace( '#^https?://[^/]+#', 'https://' . self::current_client_host(), $url );
		}

		return preg_replace( '#^https?://[^/]+#', 'https://app.' . $base_domain, $url );
	}

	/**
	 * If someone visits the bare root of app.{base_domain} or a client's branded address
	 * (no path at all), send them to /login rather than showing a blank/odd homepage.
	 */
	public static function maybe_redirect_app_root(): void {
		$zone = self::current_zone();
		if ( is_front_page() ) {
			if ( $zone === 'app' ) {
				wp_redirect( self::rewrite_portal_links( home_url( '/login' ), '/login' ) );
				exit;
			}
			if ( $zone === 'client' && self::current_client_record() ) {
				wp_redirect( self::rewrite_portal_links( home_url( '/login' ), '/login' ) );
				exit;
			}
		}
	}

	/**
	 * Stops WordPress's automatic canonical URL redirect from fighting
	 * the app, property, or client subdomains.
	 *
	 * @param string|false $redirect_url
	 * @param string       $requested_url
	 * @return string|false
	 */
	public static function guard_canonical_redirect( $redirect_url, string $requested_url ) {
		if ( in_array( self::current_zone(), [ 'app', 'property', 'client' ], true ) ) {
			return false; // never redirect away from portal or client addresses
		}
		return $redirect_url;
	}

	/**
	 * Which "zone" the current request belongs to, based on hostname:
	 * 'app', 'property', 'main', or 'client' (a client's own subdomain/domain).
	 *
	 * @return string
	 */
	public static function current_zone(): string {
		$host = strtolower( trim( $_SERVER['HTTP_HOST'] ?? '' ) );
		$host = preg_replace( '/:\d+$/', '', $host );

		$base_domain = get_option( 'ofp_crm_base_domain' );
		if ( ! $base_domain ) return 'main';

		if ( $host === 'app.' . $base_domain )      return 'app';
		if ( $host === 'property.' . $base_domain ) return 'property';
		if ( $host === $base_domain || $host === 'www.' . $base_domain ) return 'main';

		return 'client'; // anything else is assumed to be a client's own subdomain/domain
	}
}
