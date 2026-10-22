<?php
/**
 * Class OFP_Watermark
 *
 * Handles automatic watermarking on property images and dynamic frontend watermark badges.
 * Supports per-client custom watermark text (stored in ofp_clients.watermark_text).
 *
 * Priority cascade for watermark text:
 *   1. Client's own watermark_text (if logged in and they set one)
 *   2. Admin-configured custom text  (ofp_watermark_text option)
 *   3. White-label / brand name      (OFP_Brand::name())
 *   4. Site name fallback             (get_bloginfo('name'))
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Watermark {

    public static function init(): void {
        // Automatic watermark on property photo uploads
        add_filter( 'wp_handle_upload', [ __CLASS__, 'maybe_watermark_upload' ] );

        // CSS-based visual watermark overlay on all property images (frontend only)
        if ( ! is_admin() ) {
            add_action( 'wp_head', [ __CLASS__, 'inject_watermark_css' ], 99 );
        }

        // Ensure the watermark_text column exists in ofp_clients
        self::ensure_schema();
    }

    /**
     * Ensure the watermark_text column exists in the clients table.
     */
    public static function ensure_schema(): void {
        global $wpdb;
        static $done = false;
        if ( $done ) return;
        $done = true;

        $table = $wpdb->prefix . 'ofp_clients';
        $col   = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'watermark_text'" );
        if ( empty( $col ) ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `watermark_text` VARCHAR(100) DEFAULT NULL AFTER `bio`" );
        }
    }

    /**
     * Get watermark text for a given client (or the currently logged-in one).
     *
     * Cascade:
     *   1. Client's own watermark_text
     *   2. Admin custom text (ofp_watermark_text option)
     *   3. Brand name (OFP_Brand::name())
     *   4. Site name
     *
     * @param int|null $client_id  Explicit client ID, or null to auto-detect.
     */
    public static function get_watermark_text( ?int $client_id = null ): string {

        // 1. Try client's own watermark
        if ( $client_id ) {
            $client_wm = self::get_client_watermark( $client_id );
            if ( ! empty( $client_wm ) ) {
                return $client_wm;
            }
        } elseif ( class_exists( 'OFP_Auth' ) && method_exists( 'OFP_Auth', 'current_client' ) ) {
            $client = OFP_Auth::current_client();
            if ( $client && ! empty( $client->id ) ) {
                $client_wm = self::get_client_watermark( (int) $client->id );
                if ( ! empty( $client_wm ) ) {
                    return $client_wm;
                }
            }
        }

        // 2. Admin custom text
        $custom = trim( (string) get_option( 'ofp_watermark_text', '' ) );
        if ( ! empty( $custom ) ) {
            return $custom;
        }

        // 3. Brand name
        if ( class_exists( 'OFP_Brand' ) ) {
            $brand_name = OFP_Brand::name();
            if ( ! empty( $brand_name ) ) {
                return $brand_name;
            }
        }

        // 4. Site name
        return get_bloginfo( 'name' ) ?: 'OFast Pipeline';
    }

    /**
     * Get a specific client's custom watermark text from the DB.
     */
    public static function get_client_watermark( int $client_id ): string {
        global $wpdb;
        $val = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT watermark_text FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $client_id
            )
        );
        return trim( (string) $val );
    }

    /**
     * Check if watermarking is enabled (admin toggle).
     */
    public static function is_enabled(): bool {
        return '0' !== (string) get_option( 'ofp_watermark_enabled', '1' );
    }

    /**
     * Apply watermark to uploaded property images.
     * Fires on the wp_handle_upload filter for every file upload.
     *
     * @param array $file Array with 'file', 'url', 'type'.
     * @return array
     */
    public static function maybe_watermark_upload( array $file ): array {
        if ( ! self::is_enabled() ) {
            return $file;
        }

        // Only watermark standard JPEG/PNG/WebP image files
        $mime = $file['type'] ?? '';
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
            return $file;
        }

        $filepath = $file['file'] ?? '';
        if ( empty( $filepath ) || ! file_exists( $filepath ) ) {
            return $file;
        }

        // Determine client context (if any) for per-client watermark text
        $client_id = null;
        if ( class_exists( 'OFP_Auth' ) && method_exists( 'OFP_Auth', 'current_client' ) ) {
            $client = OFP_Auth::current_client();
            if ( $client && ! empty( $client->id ) ) {
                $client_id = (int) $client->id;
            }
        }

        self::stamp_image( $filepath, $client_id );

        return $file;
    }

    /**
     * Stamp watermark text onto image using PHP GD.
     *
     * @param string   $filepath   Full path to the image file.
     * @param int|null $client_id  Optional client ID for per-client watermark.
     * @return bool
     */
    public static function stamp_image( string $filepath, ?int $client_id = null ): bool {
        if ( ! extension_loaded( 'gd' ) ) {
            return false;
        }

        $info = @getimagesize( $filepath );
        if ( ! $info ) {
            return false;
        }

        $mime = $info['mime'] ?? '';
        switch ( $mime ) {
            case 'image/jpeg':
                $img = @imagecreatefromjpeg( $filepath );
                break;
            case 'image/png':
                $img = @imagecreatefrompng( $filepath );
                break;
            case 'image/webp':
                $img = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $filepath ) : false;
                break;
            default:
                return false;
        }

        if ( ! $img ) {
            return false;
        }

        imagealphablending( $img, true );
        imagesavealpha( $img, true );

        $width  = imagesx( $img );
        $height = imagesy( $img );

        // Do not watermark tiny thumbnails/icons
        if ( $width < 320 || $height < 200 ) {
            imagedestroy( $img );
            return false;
        }

        $text      = self::get_watermark_text( $client_id );
        $font_size = max( 14, (int) round( $width * 0.026 ) ); // Responsive font size

        // Colors: semi-transparent white text + subtle dark shadow
        $color_shadow = imagecolorallocatealpha( $img, 0, 0, 0, 65 );      // ~49% opacity black
        $color_text   = imagecolorallocatealpha( $img, 255, 255, 255, 30 ); // ~76% opacity white

        // Resolve font path – try bundled TTF first, then common system locations
        $font_path = self::resolve_font_path();

        if ( $font_path && function_exists( 'imagettftext' ) ) {
            $bbox    = imagettfbbox( $font_size, 0, $font_path, $text );
            $twidth  = abs( $bbox[4] - $bbox[0] );
            $theight = abs( $bbox[5] - $bbox[1] );

            // Position at bottom-right with margin
            $x = $width - $twidth - 28;
            $y = $height - 28;

            // Clamp position
            if ( $x < 10 ) $x = 10;
            if ( $y < $theight + 10 ) $y = $theight + 10;

            // Draw shadow then text
            imagettftext( $img, $font_size, 0, $x + 1, $y + 1, $color_shadow, $font_path, $text );
            imagettftext( $img, $font_size, 0, $x, $y, $color_text, $font_path, $text );
        } else {
            // Built-in GD font fallback (bitmap font 5 is the largest built-in)
            $font    = 5;
            $twidth  = imagefontwidth( $font ) * strlen( $text );
            $theight = imagefontheight( $font );

            $x = $width - $twidth - 20;
            $y = $height - $theight - 16;

            if ( $x < 10 ) $x = 10;
            if ( $y < 10 ) $y = 10;

            imagestring( $img, $font, $x + 1, $y + 1, $text, $color_shadow );
            imagestring( $img, $font, $x, $y, $text, $color_text );
        }

        // Save back
        switch ( $mime ) {
            case 'image/jpeg':
                imagejpeg( $img, $filepath, 92 );
                break;
            case 'image/png':
                imagepng( $img, $filepath, 8 );
                break;
            case 'image/webp':
                if ( function_exists( 'imagewebp' ) ) {
                    imagewebp( $img, $filepath, 92 );
                }
                break;
        }

        imagedestroy( $img );
        return true;
    }

    /**
     * Try to locate a usable TrueType font.
     *
     * Priority:
     *   1. Bundled PlusJakartaSans-Bold.ttf in plugin assets/fonts
     *   2. Common system TTF locations (Windows, Linux, macOS)
     *
     * @return string|null  Absolute path to a .ttf file, or null.
     */
    private static function resolve_font_path(): ?string {
        // 1. Bundled font
        $bundled = OFP_PATH . 'assets/fonts/PlusJakartaSans-Bold.ttf';
        if ( file_exists( $bundled ) ) {
            return $bundled;
        }

        // 2. Common system fonts (fallback)
        $candidates = [];

        // Windows
        if ( defined( 'PHP_WINDOWS_VERSION_BUILD' ) || strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
            $win_fonts = 'C:/Windows/Fonts/';
            $candidates[] = $win_fonts . 'arial.ttf';
            $candidates[] = $win_fonts . 'arialbd.ttf';
            $candidates[] = $win_fonts . 'segoeui.ttf';
            $candidates[] = $win_fonts . 'calibri.ttf';
        }

        // Linux / macOS
        $candidates[] = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        $candidates[] = '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf';
        $candidates[] = '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf';
        $candidates[] = '/Library/Fonts/Arial.ttf';
        $candidates[] = '/System/Library/Fonts/Helvetica.ttc';

        foreach ( $candidates as $path ) {
            if ( file_exists( $path ) ) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Render frontend dynamic visual watermark badge for property card overlays.
     *
     * @param string   $extra_classes
     * @param int|null $client_id
     * @return string
     */
    public static function badge_html( string $extra_classes = '', ?int $client_id = null ): string {
        $text = esc_html( self::get_watermark_text( $client_id ) );
        return '<div class="ofp-property-watermark pointer-events-none absolute bottom-3.5 right-3.5 z-10 flex items-center gap-1 px-2.5 py-1 rounded-md bg-black/45 backdrop-blur-md border border-white/10 text-white/80 text-[10.5px] font-bold tracking-wider uppercase select-none shadow-sm ' . esc_attr( $extra_classes ) . '">
            <svg class="w-3 h-3 text-white/70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            <span>' . $text . '</span>
        </div>';
    }

    /**
     * Inject CSS-based watermark overlay on property image containers.
     * Uses ::after pseudo-element with repeating text pattern.
     * This works on ALL existing and new images without template changes.
     */
    public static function inject_watermark_css(): void {
        if ( ! self::is_enabled() ) {
            return;
        }

        $text = self::get_watermark_text();
        $escaped = esc_attr( $text );

        // Build a repeating pattern: "TEXT  •  TEXT  •  TEXT"
        $pattern = $escaped . '  \00b7  ' . $escaped . '  \00b7  ' . $escaped;
        ?>
        <style id="ofp-watermark-css">
        /* ── OFP Visual Watermark Overlay ────────────────────── */

        /* Property card images (archive, homepage, listing cards) */
        .ofp-property-card-image,
        [class*="ofp-property"] [class*="card-image"] {
            position: relative;
        }
        .ofp-property-card-image::after,
        [class*="ofp-property"] [class*="card-image"]::after {
            content: '<?php echo $escaped; ?>';
            position: absolute;
            bottom: 8px;
            right: 10px;
            z-index: 15;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.85);
            text-shadow: 0 1px 4px rgba(0,0,0,0.7), 0 0 10px rgba(0,0,0,0.4);
            pointer-events: none;
            user-select: none;
            -webkit-user-select: none;
            line-height: 1;
            white-space: nowrap;
        }

        /* Property detail hero image — larger diagonal repeating watermark */
        .ofp-hero-watermark-wrap {
            position: relative;
        }
        .ofp-hero-watermark-wrap::before {
            content: '<?php echo $pattern; ?>';
            position: absolute;
            inset: 0;
            z-index: 18;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(16px, 2.8vw, 32px);
            font-weight: 900;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.15);
            pointer-events: none;
            user-select: none;
            -webkit-user-select: none;
            transform: rotate(-22deg) scale(1.3);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: clip;
        }

        /* Small watermark badge in bottom-right of hero */
        .ofp-hero-watermark-wrap::after {
            content: '\2713  <?php echo $escaped; ?>';
            position: absolute;
            bottom: 16px;
            right: 16px;
            z-index: 22;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.9);
            background: rgba(0,0,0,0.5);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.12);
            padding: 4px 12px;
            border-radius: 6px;
            pointer-events: none;
            user-select: none;
            -webkit-user-select: none;
            line-height: 1.2;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }

        /* Gallery lightbox images — subtle centered watermark */
        .ofp-gallery-watermark {
            position: relative;
        }
        .ofp-gallery-watermark::after {
            content: '<?php echo $escaped; ?>';
            position: absolute;
            bottom: 12px;
            right: 14px;
            z-index: 15;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.8);
            text-shadow: 0 1px 4px rgba(0,0,0,0.6);
            pointer-events: none;
            user-select: none;
            -webkit-user-select: none;
            white-space: nowrap;
        }

        /* Hide watermark on print */
        @media print {
            .ofp-property-card-image::after,
            .ofp-hero-watermark-wrap::before,
            .ofp-hero-watermark-wrap::after,
            .ofp-gallery-watermark::after { display: none !important; }
        }
        </style>
        <?php
    }
}
