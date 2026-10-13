<?php
/**
 * Class OFP_Brand
 *
 * One place that decides what name, logo, colors and support details a page
 * shows. Three modes:
 *
 *  - app:  normal look. Shows the app name from Settings (default "OFast Pipeline").
 *  - gold: a Gold client's own dashboard. Their logo, business name and colors.
 *  - solo: a white label (solo company). Only their logo and colors. No company
 *          name anywhere, no signup links, no upgrade prompts.
 *
 * The app name is stored in the option ofp_app_name, so it can be changed in
 * admin Settings without touching any code.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Brand {

	const DEFAULT_NAME = 'OFast Pipeline';

	private static bool $head_printed = false;

	public static function init(): void {
		add_action( 'wp_head', [ __CLASS__, 'print_host_head' ], 99 );
	}

	// ------------------------------------------------------------------ name

	/** The product name shown to normal clients. Editable in admin Settings. */
	public static function name(): string {
		$n = trim( (string) get_option( 'ofp_app_name', '' ) );
		return $n !== '' ? $n : self::DEFAULT_NAME;
	}

	/**
	 * The name to use in an email or message sent to this client's people.
	 * A solo company gets their own business name. Everyone else gets the app name.
	 */
	public static function email_name( $client = null ): string {
		if ( $client && ! empty( $client->is_white_label ) ) {
			$n = trim( (string) ( $client->business_name ?: $client->owner_name ) );
			if ( $n !== '' ) {
				return $n;
			}
		}
		return self::name();
	}

	/** Same idea for pages and texts that depend on which domain was opened. */
	public static function context_name(): string {
		$c = self::is_solo_host() ? OFP_White_Label::current_client() : null;
		return self::email_name( $c );
	}

	/** Replaces the built in product name in a text with the right name. */
	public static function apply_name( string $text, string $name ): string {
		return str_ireplace( [ 'OFast Pipeline', 'Ofast Pipeline' ], $name, $text );
	}

	// ------------------------------------------------------------------ mode

	/** True when the visitor is on a white label client's own domain. */
	public static function is_solo_host(): bool {
		return class_exists( 'OFP_White_Label' ) && (bool) OFP_White_Label::current_client();
	}

	/** 'solo', 'gold' or 'app' for this request and (optional) logged in client. */
	public static function mode( $client = null ): string {
		if ( self::is_solo_host() ) {
			return 'solo';
		}
		if ( $client && ! empty( $client->is_white_label ) ) {
			return 'solo';
		}
		if ( $client && ! empty( $client->id ) && class_exists( 'OFP_Subscription' ) ) {
			static $plans = [];
			$id = (int) $client->id;
			if ( ! isset( $plans[ $id ] ) ) {
				$plans[ $id ] = OFP_Subscription::client_plan( $id );
			}
			if ( $plans[ $id ] === 'gold' ) {
				return 'gold';
			}
		}
		return 'app';
	}

	/** Gold and solo clients can edit their branding. */
	public static function can_brand( $client ): bool {
		return in_array( self::mode( $client ), [ 'gold', 'solo' ], true );
	}

	/** Solo companies see no signup links and no upgrade prompts. */
	public static function hide_plan_prompts( $client = null ): bool {
		return self::mode( $client ) === 'solo';
	}

	/** Whose logo, colors and support details apply. Null means the app's own look. */
	public static function brand_client( $client = null ): ?object {
		$host = class_exists( 'OFP_White_Label' ) ? OFP_White_Label::current_client() : null;
		if ( $host ) {
			return $host;
		}
		if ( $client && self::can_brand( $client ) ) {
			return $client;
		}
		return null;
	}

	// ------------------------------------------------------------------ pieces

	private static function logo_img( ?object $c, int $max_h, int $max_w ): string {
		if ( ! $c || empty( $c->logo_url ) ) {
			return '';
		}
		return '<img src="' . esc_url( $c->logo_url ) . '" alt="Logo" style="max-height:' . $max_h . 'px;max-width:' . $max_w . 'px;width:auto;height:auto;display:block;margin:0 auto;object-fit:contain;">';
	}

	/** Browser tab title. Solo shows the page title only, no company name. */
	public static function title( string $page, $client = null ): string {
		switch ( self::mode( $client ) ) {
			case 'solo':
				return $page;
			case 'gold':
				$c = self::brand_client( $client );
				return $page . ' — ' . ( $c && $c->business_name ? $c->business_name : self::name() );
			default:
				return $page . ' — ' . self::name();
		}
	}

	/**
	 * Heading block for login, forgot password and reset password pages.
	 * Solo: only the logo and a plain line. App: the app name and the line.
	 */
	public static function auth_heading( string $tagline ): string {
		if ( self::is_solo_host() ) {
			$c    = OFP_White_Label::current_client();
			$line = preg_replace( '/^Client Portal\s+—\s+/u', '', $tagline );
			$html = self::logo_img( $c, 72, 240 );
			return ( $html ? '<div style="margin-bottom:14px;">' . $html . '</div>' : '' ) . '<p>' . esc_html( $line ) . '</p>';
		}
		return '<h1>' . esc_html( self::name() ) . '</h1><p>' . esc_html( $tagline ) . '</p>';
	}

	public static function welcome_title(): string {
		return self::is_solo_host() ? 'Welcome' : 'Welcome to ' . self::name();
	}

	/** Brand block at the top of the dashboard sidebar. */
	public static function sidebar_brand_html( $client = null ): string {
		$mode = self::mode( $client );
		$c    = self::brand_client( $client );
		if ( $mode === 'solo' ) {
			return self::logo_img( $c, 40, 170 );
		}
		if ( $mode === 'gold' && $c ) {
			$logo = self::logo_img( $c, 32, 120 );
			return '<span style="display:flex;align-items:center;gap:10px;">' . $logo . '<span>' . esc_html( $c->business_name ) . '</span></span>';
		}
		return '⚡ <span>' . esc_html( self::name() ) . '</span>';
	}

	/** Header logo on the public marketplace and single property page. */
	public static function public_logo_html( string $fallback_text ): string {
		if ( self::is_solo_host() ) {
			return self::logo_img( OFP_White_Label::current_client(), 36, 160 );
		}
		return esc_html( $fallback_text );
	}

	public static function public_name_text( string $fallback ): string {
		return self::is_solo_host() ? '' : $fallback;
	}

	public static function public_page_title( string $page, string $fallback_suffix ): string {
		return self::is_solo_host() ? $page : $page . ' | ' . $fallback_suffix;
	}

	public static function public_copyright(): string {
		if ( self::is_solo_host() ) {
			return '&copy; ' . gmdate( 'Y' ) . '. All rights reserved.';
		}
		return '&copy; 2026 Bofast Homes. Nigeria\'s most trusted verified property marketplace. All rights reserved.';
	}

	/** Support line under the login box on a solo domain. */
	public static function support_line_html(): string {
		$c = class_exists( 'OFP_White_Label' ) ? OFP_White_Label::current_client() : null;
		if ( ! $c ) {
			return '';
		}
		$parts = [];
		if ( ! empty( $c->support_email ) ) {
			$parts[] = '<a href="mailto:' . esc_attr( $c->support_email ) . '">' . esc_html( $c->support_email ) . '</a>';
		}
		if ( ! empty( $c->support_phone ) ) {
			$parts[] = '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $c->support_phone ) ) . '">' . esc_html( $c->support_phone ) . '</a>';
		}
		if ( ! $parts ) {
			return '';
		}
		return '<div class="ofp-footer">Need help? ' . implode( ' &middot; ', $parts ) . '</div>';
	}

	// ------------------------------------------------------------------ head

	public static function darken( string $hex, float $by = 0.15 ): string {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$out = '#';
		foreach ( [ 0, 2, 4 ] as $i ) {
			$out .= str_pad( dechex( (int) max( 0, round( hexdec( substr( $hex, $i, 2 ) ) * ( 1 - $by ) ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/** Mixes a color toward another color. $amount 0 to 1. */
	private static function mix( string $hex, string $toward, float $amount ): string {
		$a = ltrim( $hex, '#' );
		$b = ltrim( $toward, '#' );
		if ( strlen( $a ) === 3 ) { $a = $a[0].$a[0].$a[1].$a[1].$a[2].$a[2]; }
		if ( strlen( $b ) === 3 ) { $b = $b[0].$b[0].$b[1].$b[1].$b[2].$b[2]; }
		$out = '#';
		foreach ( [ 0, 2, 4 ] as $i ) {
			$v    = hexdec( substr( $a, $i, 2 ) ) * ( 1 - $amount ) + hexdec( substr( $b, $i, 2 ) ) * $amount;
			$out .= str_pad( dechex( (int) round( max( 0, min( 255, $v ) ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	private static function luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
		$ch = [];
		foreach ( [ 0, 2, 4 ] as $i ) {
			$c    = hexdec( substr( $hex, $i, 2 ) ) / 255;
			$ch[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $ch[0] + 0.7152 * $ch[1] + 0.0722 * $ch[2];
	}

	private static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Sidebar CSS. Text colors are chosen automatically so the menu is always
	 * readable. A menu hover color that would be hard to read is ignored.
	 */
	private static function sidebar_css( string $sidebar, string $hover_pref ): string {
		$is_light = self::luminance( $sidebar ) > 0.5;
		$text     = $is_light ? '#0f172a' : '#f8fafc';
		$muted    = $is_light ? '#475569' : '#cbd5e1';
		$border   = $is_light ? 'rgba(15,23,42,0.10)' : 'rgba(255,255,255,0.12)';
		$hover    = $is_light ? self::mix( $sidebar, '#000000', 0.08 ) : self::mix( $sidebar, '#ffffff', 0.12 );

		if ( $hover_pref ) {
			if ( self::contrast( $hover_pref, $text ) >= 4.5 && self::contrast( $hover_pref, $sidebar ) >= 1.1 ) {
				$hover = $hover_pref;
			}
		}

		return '.ofp-sidebar{--bg-sidebar:' . $sidebar . ';--bg-card-hover:' . $hover . ';--text-main:' . $text . ';--text-dark:' . $text . ';--text-muted:' . $muted . ';--border-color:' . $border . ';}';
	}

	/** Prints favicon and color overrides for the branded client. Safe to call twice. */
	public static function print_head( $client = null ): void {
		if ( self::$head_printed ) {
			return;
		}
		$c = self::brand_client( $client );
		if ( ! $c ) {
			return;
		}
		self::$head_printed = true;

		if ( ! empty( $c->favicon_url ) ) {
			echo '<link rel="icon" href="' . esc_url( $c->favicon_url ) . '">' . "\n";
		}
		$color = ! empty( $c->brand_color ) ? sanitize_hex_color( $c->brand_color ) : '';
		if ( $color ) {
			$hover = self::darken( $color );
			echo '<style id="ofp-brand-colors">body{--accent-blue:' . $color . ';--btn-primary:' . $color . ';--btn-primary-hover:' . $hover . ';}</style>' . "\n";
		}

		$sidebar = ! empty( $c->sidebar_color ) ? sanitize_hex_color( $c->sidebar_color ) : '';
		if ( $sidebar ) {
			$hover_pref = ! empty( $c->menu_hover_color ) ? sanitize_hex_color( $c->menu_hover_color ) : '';
			echo '<style id="ofp-brand-sidebar">' . self::sidebar_css( $sidebar, (string) $hover_pref ) . '</style>' . "\n";
		}
	}

	public static function print_host_head(): void {
		if ( self::is_solo_host() ) {
			self::print_head( null );
		}
	}
}
