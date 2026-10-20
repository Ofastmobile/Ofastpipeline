<?php
/**
 * Class OFP_Watermark
 *
 * Handles automatic watermarking on property images and dynamic frontend watermark badges.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Watermark {

    public static function init(): void {
        // Automatic watermark on property photo uploads
        add_filter( 'wp_handle_upload', [ __CLASS__, 'maybe_watermark_upload' ] );
    }

    /**
     * Get watermark text (White-label brand name, platform name, or custom setting).
     */
    public static function get_watermark_text(): string {
        $custom = trim( (string) get_option( 'ofp_watermark_text', '' ) );
        if ( ! empty( $custom ) ) {
            return $custom;
        }

        if ( class_exists( 'OFP_Brand' ) ) {
            $brand_name = OFP_Brand::name();
            if ( ! empty( $brand_name ) ) {
                return $brand_name;
            }
        }

        return get_bloginfo( 'name' ) ?: 'OFast Pipeline';
    }

    /**
     * Check if watermarking is enabled.
     */
    public static function is_enabled(): bool {
        return '0' !== (string) get_option( 'ofp_watermark_enabled', '1' );
    }

    /**
     * Apply watermark to uploaded property images.
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

        self::stamp_image( $filepath );

        return $file;
    }

    /**
     * Stamp watermark text onto image using PHP GD.
     *
     * @param string $filepath
     * @return bool
     */
    public static function stamp_image( string $filepath ): bool {
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

        $text = self::get_watermark_text();
        $font_size = max( 12, (int) round( $width * 0.024 ) ); // Responsive font size
        
        // Colors: semi-transparent white text + subtle dark shadow
        $color_shadow = imagecolorallocatealpha( $img, 0, 0, 0, 75 );      // 40% opacity black
        $color_text   = imagecolorallocatealpha( $img, 255, 255, 255, 35 ); // 72% opacity white

        // Check if a TrueType font is available in plugin
        $font_path = OFP_PATH . 'assets/fonts/PlusJakartaSans-Bold.ttf';
        if ( file_exists( $font_path ) && function_exists( 'imagettftext' ) ) {
            $bbox   = imagettfbbox( $font_size, 0, $font_path, $text );
            $twidth = abs( $bbox[4] - $bbox[0] );
            $theight = abs( $bbox[5] - $bbox[1] );

            // Position at bottom-right with margin
            $x = $width - $twidth - 24;
            $y = $height - 24;

            // Draw shadow then text
            imagettftext( $img, $font_size, 0, $x + 1, $y + 1, $color_shadow, $font_path, $text );
            imagettftext( $img, $font_size, 0, $x, $y, $color_text, $font_path, $text );
        } else {
            // Built-in GD font fallback
            $font = 5;
            $twidth  = imagefontwidth( $font ) * strlen( $text );
            $theight = imagefontheight( $font );

            $x = $width - $twidth - 20;
            $y = $height - $theight - 16;

            imagestring( $img, $font, $x + 1, $y + 1, $text, $color_shadow );
            imagestring( $img, $font, $x, $y, $text, $color_text );
        }

        // Save back
        switch ( $mime ) {
            case 'image/jpeg':
                imagejpeg( $img, $filepath, 90 );
                break;
            case 'image/png':
                imagepng( $img, $filepath, 8 );
                break;
            case 'image/webp':
                if ( function_exists( 'imagewebp' ) ) {
                    imagewebp( $img, $filepath, 90 );
                }
                break;
        }

        imagedestroy( $img );
        return true;
    }

    /**
     * Render frontend dynamic visual watermark badge for property card overlays.
     *
     * @param string $extra_classes
     * @return string
     */
    public static function badge_html( string $extra_classes = '' ): string {
        $text = esc_html( self::get_watermark_text() );
        return '<div class="ofp-property-watermark pointer-events-none absolute bottom-3.5 right-3.5 z-10 flex items-center gap-1 px-2.5 py-1 rounded-md bg-black/45 backdrop-blur-md border border-white/10 text-white/80 text-[10.5px] font-bold tracking-wider uppercase select-none shadow-sm ' . esc_attr( $extra_classes ) . '">
            <svg class="w-3 h-3 text-white/70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            <span>' . $text . '</span>
        </div>';
    }
}
