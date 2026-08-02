<?php
/**
 * OFP_Pipeline_Audio
 *
 * Handles custom voice audio uploads for IVR menus (Phase 22).
 * When a client uploads an MP3 recording, Africa's Talking will
 * use <Play> instead of <Say> — giving a human voice instead of TTS.
 *
 * The audio URL is stored in ofp_pipeline_configs.voice_audio_url
 * and read by OFP_IVR::build_menu() at call-time.
 *
 * Depends on: OFP_Auth, ofp_pipeline_configs table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Pipeline_Audio {

    /** @var array Allowed MIME types for IVR audio uploads. */
    private const ALLOWED_MIMES = [
        'audio/mpeg',
        'audio/wav',
        'audio/x-wav',
    ];

    /** @var int Maximum upload size in bytes (5 MB). */
    private const MAX_SIZE = 5 * 1024 * 1024;

    /**
     * Upload and store a custom IVR audio file for a client.
     *
     * Validates MIME type and file size, moves the file into the
     * WordPress uploads directory, and writes the resulting URL
     * into the pipeline_configs row.
     *
     * @param  int    $client_id
     * @param  array  $file      $_FILES['voice_audio'] entry.
     * @return string|WP_Error   Public URL on success, WP_Error on failure.
     */
    public static function upload( int $client_id, array $file ) {
        if ( empty( $file ) || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_failed', 'No file received or upload error.' );
        }

        // Size check.
        if ( $file['size'] > self::MAX_SIZE ) {
            return new WP_Error( 'file_too_large', 'Audio file must be under 5 MB.' );
        }

        // MIME check.
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
            return new WP_Error( 'invalid_mime', 'Only MP3 and WAV files are accepted.' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $upload = wp_handle_upload( $file, [ 'test_form' => false ] );

        if ( isset( $upload['error'] ) ) {
            return new WP_Error( 'wp_upload_error', $upload['error'] );
        }

        // Store the URL in the config row.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ofp_pipeline_configs',
            [ 'voice_audio_url' => $upload['url'] ],
            [ 'client_id'       => $client_id ]
        );

        return $upload['url'];
    }

    /**
     * Remove the stored audio file URL (revert to TTS).
     *
     * Does NOT delete the physical file from wp-content/uploads
     * (WordPress media library handles its own cleanup).
     *
     * @param  int  $client_id
     * @return void
     */
    public static function remove( int $client_id ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'ofp_pipeline_configs',
            [ 'voice_audio_url' => null ],
            [ 'client_id'       => $client_id ]
        );
    }

    /**
     * Get the stored audio URL for a client, or null.
     *
     * @param  int         $client_id
     * @return string|null
     */
    public static function get_url( int $client_id ): ?string {
        global $wpdb;

        $url = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT voice_audio_url FROM {$wpdb->prefix}ofp_pipeline_configs WHERE client_id = %d LIMIT 1",
                $client_id
            )
        );

        return $url ?: null;
    }
}
