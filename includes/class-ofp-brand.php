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
	public static function apply_name( string $text, ?string $name = null ): string {
		$target  = $name ?: self::name();
		$pattern = '/\b(?:OFast\s*Pipeline|OFastPipeline|Bofast\s*World|BofastWorld|Bofast\s*Homes|BofastHomes|Bofast)\b/iu';
		return (string) preg_replace( $pattern, $target, $text );
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
		if ( $client && ( self::can_brand( $client ) || ! empty( $client->brand_color ) ) ) {
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
		return '<span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:6px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;margin-right:8px;"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></span><span>' . esc_html( self::name() ) . '</span>';
	}

	/** Header logo on the public marketplace and single property page. */
	public static function public_logo_html( string $fallback_text = '' ): string {
		if ( self::is_solo_host() ) {
			return self::logo_img( OFP_White_Label::current_client(), 36, 160 );
		}
		$name = self::name();
		if ( $fallback_text !== '' && ctype_upper( str_replace( ' ', '', $fallback_text ) ) ) {
			return esc_html( strtoupper( $name ) );
		}
		return esc_html( $name );
	}

	public static function public_name_text( string $fallback = '' ): string {
		if ( self::is_solo_host() ) {
			return '';
		}
		return self::name();
	}

	public static function public_page_title( string $page, string $fallback_suffix = '' ): string {
		if ( self::is_solo_host() ) {
			return $page;
		}
		return $page . ' | ' . self::name();
	}

	public static function public_copyright(): string {
		if ( self::is_solo_host() ) {
			return '&copy; ' . gmdate( 'Y' ) . '. All rights reserved.';
		}
		return '&copy; ' . gmdate( 'Y' ) . ' ' . esc_html( self::name() ) . '. Nigeria\'s most trusted verified property marketplace. All rights reserved.';
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
			$hex_clean = ltrim( $color, '#' );
			if ( strlen( $hex_clean ) === 3 ) {
				$hex_clean = $hex_clean[0] . $hex_clean[0] . $hex_clean[1] . $hex_clean[1] . $hex_clean[2] . $hex_clean[2];
			}
			$r = hexdec( substr( $hex_clean, 0, 2 ) );
			$g = hexdec( substr( $hex_clean, 2, 2 ) );
			$b = hexdec( substr( $hex_clean, 4, 2 ) );
			$rgb = "{$r}, {$g}, {$b}";
			$hover = self::darken( $color );

			echo '<style id="ofp-brand-colors">
:root, body {
    --brand-color: ' . $color . ' !important;
    --brand-color-rgb: ' . $rgb . ' !important;
    --brand-color-alpha-10: rgba(' . $rgb . ', 0.10) !important;
    --brand-color-alpha-15: rgba(' . $rgb . ', 0.15) !important;
    --brand-color-alpha-25: rgba(' . $rgb . ', 0.25) !important;
    --accent-blue: ' . $color . ' !important;
    --accent-purple: ' . $color . ' !important;
    --btn-primary: ' . $color . ' !important;
    --btn-primary-hover: ' . $hover . ' !important;
}
</style>' . "\n";
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

	// ------------------------------------------------------------------ social links

	/**
	 * Configured social media links.
	 * Only channels with a configured, non-empty URL are returned.
	 *
	 * @return array<string, array{key: string, label: string, url: string, svg: string}>
	 */
	public static function social_links(): array {
		$channels = [
			'facebook'  => [
				'label'   => 'Facebook',
				'opt'     => 'ofp_social_facebook',
				'default' => 'https://facebook.com',
				'svg'     => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
			],
			'instagram' => [
				'label'   => 'Instagram',
				'opt'     => 'ofp_social_instagram',
				'default' => 'https://instagram.com',
				'svg'     => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>',
			],
			'twitter'   => [
				'label'   => 'X (Twitter)',
				'opt'     => 'ofp_social_twitter',
				'default' => 'https://x.com',
				'svg'     => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
			],
			'linkedin'  => [
				'label'   => 'LinkedIn',
				'opt'     => 'ofp_social_linkedin',
				'default' => 'https://linkedin.com',
				'svg'     => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>',
			],
			'whatsapp'  => [
				'label'   => 'WhatsApp',
				'opt'     => 'ofp_social_whatsapp',
				'default' => 'https://wa.me/2348000000000',
				'svg'     => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>',
			],
			'youtube'   => [
				'label'   => 'YouTube',
				'opt'     => 'ofp_social_youtube',
				'default' => 'https://youtube.com',
				'svg'     => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
			],
			'tiktok'    => [
				'label'   => 'TikTok',
				'opt'     => 'ofp_social_tiktok',
				'default' => 'https://tiktok.com',
				'svg'     => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-1.01-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.24 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>',
			],
			'telegram'  => [
				'label'   => 'Telegram',
				'opt'     => 'ofp_social_telegram',
				'default' => 'https://t.me',
				'svg'     => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M12 0c-6.627 0-12 5.373-12 12s5.373 12 12 12 12-5.373 12-12-5.373-12-12-12zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.14.18-.357.295-.6.295-.002 0-.003 0-.005 0l.213-3.054 5.56-5.022c.24-.213-.054-.334-.373-.121l-6.869 4.326-2.96-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.828.942z"/></svg>',
			],
		];

		$out = [];
		foreach ( $channels as $key => $data ) {
			$val = get_option( $data['opt'], null );
			$url = ( $val !== null && trim( (string) $val ) !== '' ) ? trim( (string) $val ) : $data['default'];

			if ( ! empty( $url ) ) {
				$out[ $key ] = [
					'key'   => $key,
					'label' => $data['label'],
					'url'   => $url,
					'svg'   => $data['svg'],
				];
			}
		}
		return $out;
	}
}

