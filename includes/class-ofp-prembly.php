<?php
/**
 * Class OFP_Prembly
 *
 * Handles Identity & Business Verification via Prembly (formerly Identitypass).
 * Supports:
 * - CAC Business Registration Verification (RC / BN number)
 * - NIN Verification (11-digit National Identity Number)
 * - BVN Verification (11-digit Bank Verification Number)
 *
 * Includes Sandbox/Test mode simulation so testing works reliably in development.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Prembly {

    public static function init(): void {
        // Schema: OFP_Activator::run_helper_schemas() (P6.02).
        add_action( 'wp_ajax_ofp_submit_client_verification', [ __CLASS__, 'ajax_verify' ] );
        add_action( 'wp_ajax_nopriv_ofp_submit_client_verification', [ __CLASS__, 'ajax_verify' ] );
    }

    /**
     * Ensure columns exist on wp_ofp_clients for verification audit data.
     */
    public static function ensure_client_schema(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ofp_clients';

        $columns = [
            'is_verified'           => "ALTER TABLE {$table} ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0",
            'cac_number'            => "ALTER TABLE {$table} ADD COLUMN cac_number VARCHAR(50) DEFAULT NULL",
            'verified_company_name' => "ALTER TABLE {$table} ADD COLUMN verified_company_name VARCHAR(150) DEFAULT NULL",
            'id_type_verified'      => "ALTER TABLE {$table} ADD COLUMN id_type_verified VARCHAR(20) DEFAULT NULL",
            'verified_at'           => "ALTER TABLE {$table} ADD COLUMN verified_at DATETIME DEFAULT NULL",
        ];

        foreach ( $columns as $col => $sql ) {
            $exists = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $col ) );
            if ( empty( $exists ) ) {
                $wpdb->query( $sql );
            }
        }
    }

    public static function get_api_key(): string {
        $raw = get_option( 'ofp_prembly_secret_key', '' );
        return class_exists( 'OFP_Security' ) ? OFP_Security::decrypt( $raw ) : '';
    }

    public static function get_app_id(): string {
        return trim( (string) get_option( 'ofp_prembly_app_id', '' ) );
    }

    public static function get_env(): string {
        // Only "live" or "test" are valid. Anything else is treated as test (P3.05).
        return 'live' === get_option( 'ofp_prembly_env', 'test' ) ? 'live' : 'test';
    }

    public static function is_configured(): bool {
        return ! empty( self::get_api_key() );
    }

    /**
     * Simulated (fake) results are allowed only on a dev or staging site:
     * WP_DEBUG is on, or the constant OFP_PREMBLY_SANDBOX is set (P3.01).
     */
    private static function sandbox_allowed(): bool {
        return ( defined( 'OFP_PREMBLY_SANDBOX' ) && OFP_PREMBLY_SANDBOX )
            || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
    }

    /**
     * True when a check can run: live mode with a key, or a sandbox site.
     */
    public static function is_available(): bool {
        if ( 'live' === self::get_env() && self::is_configured() ) {
            return true;
        }
        return self::sandbox_allowed();
    }

    /**
     * True only when real verification is switched on: live mode with a key.
     * The sandbox does not count. Used by the listing rule and the account form.
     */
    public static function kyc_required(): bool {
        return 'live' === self::get_env() && self::is_configured();
    }

    private static function unavailable(): array {
        return [ 'success' => false, 'message' => 'Verification is not available yet. Please try again later.' ];
    }

    /**
     * Fixed user message for a failed provider call (P3.06).
     * Outage or key problems are not shown as "not found".
     */
    private static function provider_failure( int $http, $body, string $not_found ): array {
        $code = is_array( $body ) ? (string) ( $body['response_code'] ?? '' ) : '';
        if ( $http >= 500 || in_array( $http, [ 0, 401, 403, 429 ], true ) || '02' === $code ) {
            return [ 'success' => false, 'message' => 'The verification service is busy right now. Please try again in a few minutes.' ];
        }
        return [ 'success' => false, 'message' => $not_found ];
    }

    private static function normalize_rc( string $rc_number ): string {
        return trim( strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $rc_number ) ) );
    }

    /**
     * Verify CAC Company Registration.
     *
     * @param string $rc_number e.g. "RC123456" or "BN987654" or "123456"
     * @return array
     */
    public static function verify_cac( string $rc_number ): array {
        $rc_clean = self::normalize_rc( $rc_number );
        if ( strlen( $rc_clean ) < 3 ) {
            return [ 'success' => false, 'message' => 'Please enter a valid CAC RC or Business Name (BN) number.' ];
        }

        $env     = self::get_env();
        $api_key = self::get_api_key();
        $app_id  = self::get_app_id();

        // Test mode or no key: simulate only on a dev/staging site, otherwise refuse (P3.01).
        if ( $env === 'test' || empty( $api_key ) ) {
            if ( ! self::sandbox_allowed() ) {
                return self::unavailable();
            }
            return [
                'success'      => true,
                'company_name' => 'VERIFIED ENTERPRISE (TEST RC ' . $rc_clean . ')',
                'rc_number'    => $rc_clean,
                'status'       => 'ACTIVE',
                'simulated'    => true,
            ];
        }

        $base_url = 'https://api.prembly.com';
        $endpoint = $base_url . '/identitypass/verification/cac';

        // Detect type (RC, BN, IT)
        $company_type = 'RC';
        if ( str_starts_with( $rc_clean, 'BN' ) ) {
            $company_type = 'BN';
        } elseif ( str_starts_with( $rc_clean, 'IT' ) ) {
            $company_type = 'IT';
        }

        $headers = [
            'x-api-key'    => $api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
        if ( ! empty( $app_id ) ) {
            $headers['app-id'] = $app_id;
        }

        $res = wp_remote_post( $endpoint, [
            'timeout' => 10,
            'headers' => $headers,
            'body' => wp_json_encode( [
                'rc_number'    => preg_replace( '/^[A-Za-z]+/', '', $rc_clean ) ?: $rc_clean,
                'company_type' => $company_type,
            ] ),
        ] );

        if ( is_wp_error( $res ) ) {
            return self::provider_failure( 0, null, '' );
        }

        $code = wp_remote_retrieve_response_code( $res );
        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code === 200 && ! empty( $body['status'] ) && ! empty( $body['data'] ) ) {
            $data = $body['data'];
            $c_name = $data['company_name'] ?? ( $data['name'] ?? 'Verified Registered Business' );
            return [
                'success'      => true,
                'company_name' => $c_name,
                'rc_number'    => $rc_clean,
                'status'       => $data['status'] ?? 'ACTIVE',
            ];
        }

        return self::provider_failure( (int) $code, $body, 'Could not verify CAC registration. Please check your RC or BN number.' );
    }

    /**
     * Verify NIN (11 digits).
     */
    public static function verify_nin( string $nin ): array {
        $nin_clean = preg_replace( '/\D/', '', $nin );
        if ( strlen( $nin_clean ) !== 11 ) {
            return [ 'success' => false, 'message' => 'National Identity Number (NIN) must be exactly 11 digits.' ];
        }

        $env     = self::get_env();
        $api_key = self::get_api_key();
        $app_id  = self::get_app_id();

        if ( $env === 'test' || empty( $api_key ) ) {
            if ( ! self::sandbox_allowed() ) {
                return self::unavailable();
            }
            return [
                'success'   => true,
                'id_number' => $nin_clean,
                'id_type'   => 'NIN',
                'simulated' => true,
            ];
        }

        $endpoint = 'https://api.prembly.com/identitypass/verification/nin';
        $headers = [
            'x-api-key'    => $api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
        if ( ! empty( $app_id ) ) {
            $headers['app-id'] = $app_id;
        }

        $res = wp_remote_post( $endpoint, [
            'timeout' => 10,
            'headers' => $headers,
            'body' => wp_json_encode( [
                'number' => $nin_clean,
            ] ),
        ] );

        if ( is_wp_error( $res ) ) {
            return self::provider_failure( 0, null, '' );
        }

        $code = wp_remote_retrieve_response_code( $res );
        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code === 200 && ! empty( $body['status'] ) && ! empty( $body['data'] ) ) {
            return [
                'success'   => true,
                'id_number' => $nin_clean,
                'id_type'   => 'NIN',
                'data'      => $body['data'],
            ];
        }

        return self::provider_failure( (int) $code, $body, 'Could not verify NIN. Please check the 11-digit number.' );
    }

    /**
     * Verify BVN (11 digits).
     */
    public static function verify_bvn( string $bvn ): array {
        $bvn_clean = preg_replace( '/\D/', '', $bvn );
        if ( strlen( $bvn_clean ) !== 11 ) {
            return [ 'success' => false, 'message' => 'Bank Verification Number (BVN) must be exactly 11 digits.' ];
        }

        $env     = self::get_env();
        $api_key = self::get_api_key();
        $app_id  = self::get_app_id();

        if ( $env === 'test' || empty( $api_key ) ) {
            if ( ! self::sandbox_allowed() ) {
                return self::unavailable();
            }
            return [
                'success'   => true,
                'id_number' => $bvn_clean,
                'id_type'   => 'BVN',
                'simulated' => true,
            ];
        }

        $endpoint = 'https://api.prembly.com/identitypass/verification/bvn';
        $headers = [
            'x-api-key'    => $api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
        if ( ! empty( $app_id ) ) {
            $headers['app-id'] = $app_id;
        }

        $res = wp_remote_post( $endpoint, [
            'timeout' => 10,
            'headers' => $headers,
            'body' => wp_json_encode( [
                'number' => $bvn_clean,
            ] ),
        ] );

        if ( is_wp_error( $res ) ) {
            return self::provider_failure( 0, null, '' );
        }

        $code = wp_remote_retrieve_response_code( $res );
        $body = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( $code === 200 && ! empty( $body['status'] ) && ! empty( $body['data'] ) ) {
            return [
                'success'   => true,
                'id_number' => $bvn_clean,
                'id_type'   => 'BVN',
                'data'      => $body['data'],
            ];
        }

        return self::provider_failure( (int) $code, $body, 'Could not verify BVN. Please check the 11-digit number.' );
    }

    /**
     * AJAX handler for client verification submission: CAC + (NIN or BVN).
     */
    public static function ajax_verify(): void {
        global $wpdb;

        // Session: the official auth system only (P3.04).
        $client    = class_exists( 'OFP_Auth' ) ? OFP_Auth::current_client() : null;
        $client_id = ( $client && ! empty( $client->id ) ) ? (int) $client->id : 0;

        if ( $client_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Session expired. Please log in again.' ] );
        }

        // Team members may not verify the company; only the owner can (P3.04).
        $user = OFP_Auth::current_user();
        if ( $user && ! empty( $user->is_team_member ) ) {
            wp_send_json_error( [ 'message' => 'Only the account owner can verify the business.' ] );
        }

        // Nonce (P3.03).
        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'ofp_client_action' ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please reload the page and try again.' ] );
        }

        // Already verified: do not spend a paid call (P3.03).
        if ( ! empty( $client->is_verified ) ) {
            wp_send_json_error( [ 'message' => 'Your business is already verified.' ] );
        }

        // Sandbox or no key on a live site: refuse (P3.01).
        if ( ! self::is_available() ) {
            wp_send_json_error( [ 'message' => self::unavailable()['message'] ] );
        }

        $cac_number = sanitize_text_field( wp_unslash( $_POST['cac_number'] ?? '' ) );
        $id_type    = strtolower( sanitize_text_field( wp_unslash( $_POST['id_type'] ?? 'nin' ) ) );
        $id_number  = sanitize_text_field( wp_unslash( $_POST['id_number'] ?? '' ) );

        // Only nin or bvn is allowed (P3.05).
        if ( ! in_array( $id_type, [ 'nin', 'bvn' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Please choose NIN or BVN.' ] );
        }
        if ( empty( $cac_number ) ) {
            wp_send_json_error( [ 'message' => 'CAC Registration Number (RC/BN) is required.' ] );
        }
        if ( empty( $id_number ) ) {
            wp_send_json_error( [ 'message' => 'Please provide your 11-digit ' . strtoupper( $id_type ) . ' number.' ] );
        }

        // One CAC number can be verified on one account only (G3.03). Checked before any paid call.
        $rc_clean = self::normalize_rc( $cac_number );
        if ( strlen( $rc_clean ) >= 3 ) {
            $taken = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_clients WHERE cac_number = %s AND is_verified = 1 AND id <> %d LIMIT 1",
                $rc_clean,
                $client_id
            ) );
            if ( $taken ) {
                wp_send_json_error( [ 'message' => 'This CAC number is already verified on another account. Please contact support.' ] );
            }
        }

        // 3 tries per day per client (P3.03). Counted before the paid calls.
        $rl_table = $wpdb->prefix . 'ofp_rate_limits';
        $rl_key   = 'client-' . $client_id;
        $since    = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS );
        $tries    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$rl_table} WHERE ip = %s AND action = %s AND created_at > %s",
            $rl_key,
            'kyc_verify',
            $since
        ) );
        if ( $tries >= 3 ) {
            wp_send_json_error( [ 'message' => 'You have reached the limit of 3 verification tries today. Please try again tomorrow.' ] );
        }
        $wpdb->insert( $rl_table, [
            'ip'         => $rl_key,
            'action'     => 'kyc_verify',
            'created_at' => current_time( 'mysql' ),
        ] );

        // 1. Verify CAC. A good result is kept 24 hours so a retry does not pay twice (P3.06).
        $cache_key = 'ofp_cac_' . md5( $client_id . '|' . $rc_clean );
        $cac_res   = get_transient( $cache_key );
        if ( ! is_array( $cac_res ) ) {
            $cac_res = self::verify_cac( $cac_number );
            if ( ! empty( $cac_res['success'] ) && empty( $cac_res['simulated'] ) ) {
                set_transient( $cache_key, $cac_res, DAY_IN_SECONDS );
            }
        }
        if ( ! $cac_res['success'] ) {
            wp_send_json_error( [ 'message' => $cac_res['message'] ] );
        }

        // 2. Verify Identity (NIN or BVN)
        if ( $id_type === 'bvn' ) {
            $id_res = self::verify_bvn( $id_number );
        } else {
            $id_res = self::verify_nin( $id_number );
        }

        if ( ! $id_res['success'] ) {
            wp_send_json_error( [ 'message' => $id_res['message'] ] );
        }

        // Simulated results are never saved as verified (G3.02).
        if ( ! empty( $cac_res['simulated'] ) || ! empty( $id_res['simulated'] ) ) {
            wp_send_json_success( [
                'message'   => 'Test check passed. Nothing was saved because this is a test site.',
                'simulated' => true,
            ] );
        }

        // 3. Mark client as verified in database
        self::ensure_client_schema();

        $company_name = $cac_res['company_name'] ?? 'Verified Enterprise';
        $update_data  = [
            'is_verified'           => 1,
            'cac_number'            => $cac_res['rc_number'],
            'verified_company_name' => $company_name,
            'id_type_verified'      => strtoupper( $id_type ),
            'verified_at'           => current_time( 'mysql' ),
        ];

        // Only a client that is still unverified is updated; the result is checked (P3.05).
        $updated = $wpdb->update(
            $wpdb->prefix . 'ofp_clients',
            $update_data,
            [ 'id' => $client_id, 'is_verified' => 0 ]
        );

        if ( false === $updated ) {
            wp_send_json_error( [ 'message' => 'We could not save your verification. Please try again.' ] );
        }
        if ( 0 === $updated ) {
            wp_send_json_error( [ 'message' => 'Your business is already verified.' ] );
        }

        wp_send_json_success( [
            'message'      => 'Business & Identity verification approved successfully!',
            'company_name' => $company_name,
            'cac_number'   => $cac_res['rc_number'],
            'id_type'      => strtoupper( $id_type ),
        ] );
    }
}
