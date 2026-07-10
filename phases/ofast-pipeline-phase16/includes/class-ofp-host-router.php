<?php
/**
 * Class OFP_Host_Router
 *
 * Phase 16 — app.crmdomain.com and property.crmdomain.com routing.
 *
 * This is the one place that decides "which links point where" across
 * the whole plugin. Two problems it solves:
 *
 * 1. WordPress's home_url() always builds links using your MAIN site
 *    address, no matter which address the visitor actually used to
 *    get here. Left alone, every "Login" or "Back to dashboard" link
 *    would silently send people back to crmdomain.com instead of
 *    staying on app.crmdomain.com. This class fixes that for every
 *    portal link in one place, instead of needing every template file
 *    to be individually patched (past or future).
 *
 * 2. It keeps a list of "reserved" subdomain words (app, property,
 *    www, etc.) so a client can never accidentally be assigned one of
 *    these as their own subdomain slug.
 *
 * The actual DNS wildcard (*.crmdomain.com) is what makes any of
 * these addresses reach your server at all — this class only decides
 * what happens once a request arrives.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Host_Router {

	/**
	 * Words that can never be assigned to a client as their own
	 * subdomain slug, since they're reserved for the system itself.
	 */
	const RESERVED_SUBDOMAINS = [ 'app', 'property', 'www', 'mail', 'ftp', 'admin' ];

	/**
	 * Portal routes that must always resolve to app.{base_domain},
	 * regardless of which address the visitor is currently on. Add a
	 * new slug here any time a new logged-in-required page is added.
	 */
	const APP_ROUTES = [ 'login', 'signup', 'dashboard', 'credits', 'properties', 'forgot-password', 'reset-password' ];

	public static function init(): void {
		add_filter( 'home_url', [ __CLASS__, 'rewrite_portal_links' ], 10, 2 );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_redirect_app_root' ] );
		add_filter( 'redirect_canonical', [ __CLASS__, 'guard_canonical_redirect' ], 10, 2 );
	}

	/**
	 * Whether a given host is one of the system-reserved subdomains
	 * (as opposed to a client's own assigned subdomain). Used by
	 * OFP_Landing_Page so it never mistakes app.crmdomain.com or
	 * property.crmdomain.com for a client's landing page.
	 *
	 * @param string $subdomain_slug just the slug, e.g. "app" — not the full hostname
	 * @return bool
	 */
	public static function is_reserved( string $subdomain_slug ): bool {
		return in_array( strtolower( $subdomain_slug ), self::RESERVED_SUBDOMAINS, true );
	}

	/**
	 * Rewrites any home_url('/login'), home_url('/credits?...'), etc.
	 * call — anywhere in the codebase, this file or any template — to
	 * use app.{base_domain} instead of your main site address. Only
	 * touches paths matching APP_ROUTES above; every other home_url()
	 * call (e.g. a normal page link, or the "Contact Us" link on a
	 * property page) is left completely untouched.
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

		return preg_replace( '#^https?://[^/]+#', 'https://app.' . $base_domain, $url );
	}

	/**
	 * If someone visits the bare root of app.crmdomain.com (no path
	 * at all), send them to /login rather than showing a blank/odd
	 * homepage on that address.
	 */
	public static function maybe_redirect_app_root(): void {
		if ( is_front_page() && self::current_zone() === 'app' ) {
			wp_redirect( self::rewrite_portal_links( home_url( '/login' ), '/login' ) );
			exit;
		}
	}

	/**
	 * Stops WordPress's automatic "canonical URL" redirect from
	 * fighting the app/property subdomains. WordPress sometimes tries
	 * to bounce a visitor to what it thinks is the "correct" address
	 * for a page — without this guard, that could send people from
	 * app.crmdomain.com back to your main domain unexpectedly.
	 *
	 * @param string|false $redirect_url
	 * @param string       $requested_url
	 * @return string|false
	 */
	public static function guard_canonical_redirect( $redirect_url, string $requested_url ) {
		if ( in_array( self::current_zone(), [ 'app', 'property' ], true ) ) {
			return false; // never redirect away from these two addresses
		}
		return $redirect_url;
	}

	/**
	 * Which "zone" the current request belongs to, based on hostname:
	 * 'app', 'property', 'main', or 'client' (a client's own
	 * subdomain/domain — handled by OFP_Landing_Page, not here).
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
