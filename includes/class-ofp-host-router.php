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
	const RESERVED_SUBDOMAINS = [ 'app', 'marketplace', 'property', 'www', 'mail', 'ftp', 'admin', 'api', 'crm', 'portal' ];

	/**
	 * Portal routes that resolve to the portal app host (or client's branded host).
	 * Add a new slug here any time a new portal screen is added.
	 */
	const APP_ROUTES = [
		'login', 'signup', 'dashboard', 'credits', 'properties', 'tenants',
		'leads', 'templates', 'team', 'api-settings', 'api', 'forgot-password',
		'reset-password', 'account', 'profile', 'notifications', 'notification-settings',
		'funding', 'pricing', 'inspections', 'pipeline-settings', 'message-templates',
		'property-sales', 'property-purchases', 'property-payments', 'reports', 'activity-logs',
		'my-listing'
	];

	public static function init(): void {
		add_filter( 'home_url', [ __CLASS__, 'rewrite_portal_links' ], 10, 2 );
		add_action( 'template_redirect', [ __CLASS__, 'handle_unknown_client_host' ], 0 );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_redirect_app_root' ] );
		add_filter( 'redirect_canonical', [ __CLASS__, 'guard_canonical_redirect' ], 10, 2 );
		add_filter( 'allowed_redirect_hosts', [ __CLASS__, 'allow_portal_redirect_hosts' ] );
	}

	/**
	 * wp_safe_redirect() only trusts the main site host by default. The portal
	 * redirects people to app.{base}, property.{base} and client hosts, so those
	 * hosts must be trusted too, otherwise a redirect can fall back to wp-admin.
	 */
	public static function allow_portal_redirect_hosts( array $hosts ): array {
		$base = get_option( 'ofp_crm_base_domain' );
		if ( $base ) {
			$hosts[] = 'app.' . $base;
			$hosts[] = 'marketplace.' . $base;
			$hosts[] = 'property.' . $base;
		}
		$current = self::current_client_host();
		if ( $current ) {
			$hosts[] = $current;
		}
		return $hosts;
	}

	/**
	 * Whether a given host is one of the system-reserved subdomains.
	 *
	 * @param string $subdomain_slug slug, e.g. "app" — not the full hostname
	 * @return bool
	 */
	public static function is_reserved( string $subdomain_slug ): bool {
		$slug = strtolower( trim( $subdomain_slug ) );
		if ( in_array( $slug, self::RESERVED_SUBDOMAINS, true ) ) {
			return true;
		}
		// Also reserve the site's own host subdomain if it has one (e.g. 'supreme')
		$own_host = preg_replace( '/^www\./', '', strtolower( (string) parse_url( get_option( 'siteurl' ), PHP_URL_HOST ) ) );
		$base_domain = get_option( 'ofp_crm_base_domain' );
		if ( $base_domain && str_ends_with( $own_host, '.' . $base_domain ) ) {
			$own_sub = substr( $own_host, 0, -strlen( '.' . $base_domain ) );
			if ( $slug === $own_sub ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The hostname of this request, lowercase, without a port.
	 */
	public static function normalized_host(): string {
		$host = strtolower( trim( $_SERVER['HTTP_HOST'] ?? '' ) );
		return preg_replace( '/:\d+$/', '', $host );
	}

	/**
	 * Whether the host is this WordPress site's own address.
	 */
	public static function is_own_site_host( string $host ): bool {
		static $own = null;
		if ( $own === null ) {
			$own = preg_replace( '/^www\./', '', strtolower( (string) parse_url( get_option( 'siteurl' ), PHP_URL_HOST ) ) );
		}
		return $own !== '' && preg_replace( '/^www\./', '', $host ) === $own;
	}

	private static function has_custom_domain_column(): bool {
		static $has = null;
		if ( $has === null ) {
			global $wpdb;
			$has = (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'custom_domain'" );
		}
		return $has;
	}

	/**
	 * Finds the client that owns the current host: first by
	 * {subdomain}.{base_domain}, then by custom domain (Level 2).
	 * Works with or without a base domain. Cached per host.
	 */
	private static function resolve_host_client(): ?object {
		static $memo = [];

		$host = self::normalized_host();
		if ( $host === '' || self::is_own_site_host( $host ) ) {
			return null;
		}
		if ( array_key_exists( $host, $memo ) ) {
			return $memo[ $host ];
		}

		global $wpdb;
		$client      = null;
		$base_domain = get_option( 'ofp_crm_base_domain' );

		// 1. A subdomain of the base domain: {subdomain}.{base_domain}
		if ( $base_domain && str_ends_with( $host, '.' . $base_domain ) ) {
			$sub = substr( $host, 0, -strlen( '.' . $base_domain ) );
			if ( ! self::is_reserved( $sub ) ) {
				$client = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}ofp_clients WHERE subdomain = %s AND status != 'trash' LIMIT 1",
					$sub
				) );
			}
		}

		// 2. A custom domain (Level 2). Matches with or without "www.".
		if ( ! $client && self::has_custom_domain_column() ) {
			$bare   = preg_replace( '/^www\./', '', $host );
			$client = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}ofp_clients WHERE custom_domain IN (%s, %s) AND status != 'trash' LIMIT 1",
				$bare,
				'www.' . $bare
			) );
		}

		$memo[ $host ] = $client ?: null;
		return $memo[ $host ];
	}

	/**
	 * A host that looks like a client address but matches no client (a typo or an
	 * unclaimed name) is sent to the main site instead of showing the portal.
	 * Only front end page requests are affected.
	 */
	public static function handle_unknown_client_host(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$base_domain = get_option( 'ofp_crm_base_domain' );
		if ( ! $base_domain || self::current_zone() !== 'client' || self::resolve_host_client() ) {
			return;
		}
		if ( self::is_own_site_host( self::normalized_host() ) ) {
			return;
		}
		wp_redirect( 'https://' . $base_domain . '/', 302 );
		exit;
	}

	/**
	 * Resolves the client record associated with the current hostname
	 * if the visitor is on a client subdomain or custom domain.
	 */
	public static function current_client_record(): ?object {
		if ( self::current_zone() !== 'client' ) {
			return null;
		}
		return self::resolve_host_client();
	}

	/**
	 * Returns current client host string if visitor is on a valid client host.
	 */
	public static function current_client_host(): ?string {
		if ( ! self::current_client_record() ) {
			return null;
		}
		return self::normalized_host();
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
		$segments      = array_values( array_filter( explode( '/', $clean_path ) ) );
		$first_segment = $segments[0] ?? '';

		// A path like 'property/my-luxury-apartment' is a single property listing, not the client portal.
		if ( $first_segment === 'property' || ( $first_segment === 'properties' && count( $segments ) > 1 ) ) {
			return $url;
		}

		if ( ! in_array( $first_segment, self::APP_ROUTES, true ) ) {
			return $url; // not a portal route — leave untouched
		}

		// If visitor is currently on a recognized client subdomain or custom
		// domain, keep them on that host (works even with no base domain set).
		if ( self::current_zone() === 'client' && self::current_client_host() ) {
			return preg_replace( '#^https?://[^/]+#', 'https://' . self::current_client_host(), $url );
		}

		// If visitor is currently on the WordPress site's own host (e.g. supreme.ofastshop.com),
		// keep them on this site host! Do NOT rewrite away to app.{base_domain}.
		$current_host = self::normalized_host();
		if ( self::is_own_site_host( $current_host ) ) {
			return $url;
		}

		$base_domain = get_option( 'ofp_crm_base_domain' );
		if ( ! $base_domain ) {
			return $url; // option not set yet — fail safe to normal behaviour
		}

		// The app. address can be switched off in Settings (when its DNS is not set up).
		if ( get_option( 'ofp_use_app_host', '1' ) !== '1' ) {
			return $url;
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
			if ( $zone === 'client' ) {
				$client = self::current_client_record();
				if ( $client ) {
					// A white label (solo company) domain opens on their own
					// marketplace, never on the main site's homepage.
					if ( ! empty( $client->is_white_label ) ) {
						wp_redirect( 'https://' . self::current_client_host() . '/marketplace/' );
						exit;
					}
					wp_redirect( self::rewrite_portal_links( home_url( '/login' ), '/login' ) );
					exit;
				}
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
		$host = self::normalized_host();

		// The WordPress site's own address is ALWAYS 'main'.
		if ( $host === '' || self::is_own_site_host( $host ) ) {
			return 'main';
		}

		$base_domain = get_option( 'ofp_crm_base_domain' );

		if ( $base_domain ) {
			if ( $host === 'app.' . $base_domain )                                                  return 'app';
			if ( $host === 'marketplace.' . $base_domain || $host === 'property.' . $base_domain ) return 'property';
			if ( $host === $base_domain || $host === 'www.' . $base_domain )                        return 'main';

			// A subdomain or custom domain: check if an actual registered client exists
			return self::resolve_host_client() ? 'client' : 'main';
		}

		// No base domain set: the main site behaves exactly as before.
		// Only a registered custom domain counts as a client host.
		return self::resolve_host_client() ? 'client' : 'main';
	}
}
