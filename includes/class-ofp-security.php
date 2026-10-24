<?php
/**
 * OFP_Security
 *
 * Central security utility class. Handles:
 *  - Input sanitization (phone numbers)
 *  - AES-256-CBC encryption / decryption (for client API keys at rest)
 *  - IP-based rate limiting (stored in ofp_rate_limits table)
 *  - Cloudflare Turnstile server-side token verification
 *
 * Used by: OFP_REST_API, OFP_Auth, OFP_Client_Portal, OFP_Client
 * Depends on: ofp_rate_limits table (created by OFP_Activator)
 *             ofp_encryption_key / ofp_encryption_iv wp_options (set by OFP_Activator)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Security {

    // ─────────────────────────────────────────────────────────────────────────
    // SANITIZATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Strip everything except digits and the leading + from a phone number.
     * Works for Nigerian formats: 08012345678, +2348012345678, 2348012345678
     *
     * @param  string $input Raw phone input from a form or API.
     * @return string        Cleaned phone string.
     */
    public static function sanitize_phone( string $input ): string {
        // Keep only digits and a leading +
        $cleaned = preg_replace( '/[^0-9+]/', '', trim( $input ) );

        // Normalise: if it starts with 0 and is 11 digits, it's a local Nigerian number.
        // Leave as-is — Africa's Talking and BulkSMSNigeria both accept 08XXXXXXXXX.
        return $cleaned;
    }

    /**
     * Validate that a sanitised phone number looks plausible.
     * Minimum 10 digits, maximum 15 (E.164 max without +).
     *
     * @param  string $phone Already sanitised phone string.
     * @return bool
     */
    public static function is_valid_phone( string $phone ): bool {
        $digits_only = preg_replace( '/[^0-9]/', '', $phone );
        $len         = strlen( $digits_only );
        return $len >= 10 && $len <= 15;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENCRYPTION / DECRYPTION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Encrypt a plaintext value using AES-256-CBC.
     * Keys are stored in wp_options, generated once on plugin activation.
     *
     * @param  string $value Plaintext to encrypt (e.g. an API key).
     * @return string        Base64-encoded ciphertext, or empty string on failure.
     */
    public static function encrypt( string $value ): string {
        if ( empty( $value ) ) {
            return '';
        }

        $key = get_option( 'ofp_encryption_key', '' );
        $iv  = substr( get_option( 'ofp_encryption_iv', '' ), 0, 16 );

        if ( ! $key || ! $iv ) {
            // Keys not set yet — should never happen after activation.
            return $value;
        }

        $encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv );
        return $encrypted !== false ? base64_encode( $encrypted ) : '';
    }

    /**
     * Decrypt a value previously encrypted with self::encrypt().
     *
     * @param  string $encrypted Base64-encoded ciphertext.
     * @return string            Decrypted plaintext, or empty string on failure.
     */
    public static function decrypt( string $encrypted ): string {
        if ( empty( $encrypted ) ) {
            return '';
        }

        $key = get_option( 'ofp_encryption_key', '' );
        $iv  = substr( get_option( 'ofp_encryption_iv', '' ), 0, 16 );

        if ( ! $key || ! $iv ) {
            return $encrypted;
        }

        $decrypted = openssl_decrypt(
            base64_decode( $encrypted ),
            'AES-256-CBC',
            $key,
            0,
            $iv
        );

        return $decrypted !== false ? $decrypted : '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RATE LIMITING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if an IP has exceeded the allowed action count within the time window.
     * Inserts a new record if not exceeded, terminates with 429 if exceeded.
     *
     * Old records are cleaned up by the monthly cron (OFP_Cron_Handler).
     *
     * @param string $ip     Visitor IP address.
     * @param string $action Identifier for the action (e.g. 'lead_submit', 'client_login').
     * @param int    $max    Maximum allowed attempts within $window seconds.
     * @param int    $window Time window in seconds.
     *
     * @return void  Terminates execution with wp_die() if limit exceeded.
     */
    public static function check_rate_limit(
        string $ip,
        string $action,
        int $max    = 5,
        int $window = 300
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ofp_rate_limits';

        // Count how many attempts this IP has made for this action in the window.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE ip = %s
                   AND action = %s
                   AND created_at > DATE_SUB(NOW(), INTERVAL %d SECOND)",
                $ip,
                $action,
                $window
            )
        );

        if ( $count >= $max ) {
            // Return a polite JSON response for AJAX / REST callers,
            // or a plain page for direct browser hits.
            // OFP_JSON_API is defined by the /api/v1/* stealth routes, which run
            // before WordPress sets REST_REQUEST.
            if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || defined( 'OFP_JSON_API' ) ) {
                wp_send_json(
                    [ 'success' => false, 'message' => 'Too many attempts. Please try again later.' ],
                    429
                );
            }
            wp_die(
                esc_html__( 'Too many attempts. Please try again later.', 'ofast-pipeline' ),
                esc_html__( 'Rate Limited', 'ofast-pipeline' ),
                [ 'response' => 429 ]
            );
        }

        // Log this attempt.
        $wpdb->insert(
            $table,
            [
                'ip'         => $ip,
                'action'     => $action,
                'created_at' => current_time( 'mysql' ),
            ]
        );
    }

    /**
     * Cloudflare's published proxy ranges (https://www.cloudflare.com/ips-v4 and
     * /ips-v6). Only a request that really arrives from one of these may tell us
     * the visitor's address through CF-Connecting-IP / X-Forwarded-For.
     */
    const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * The visitor's real IP address, used for rate limits and the lead's IP.
     *
     * The CF-Connecting-IP and X-Forwarded-For headers are plain text that anyone
     * can send. They are only believed when the connection itself comes from a
     * trusted proxy: Cloudflare, a private/loopback address (your own web server
     * proxy on the same machine), or an address listed in the 'ofp_trusted_proxies'
     * option (one IP or CIDR per line) or filter. Anything else gets REMOTE_ADDR.
     *
     * Before, the headers were believed from anyone, so an attacker could send a
     * new fake IP with every request and never hit the rate limit.
     *
     * @return string
     */
    public static function get_client_ip(): string {
        $remote = self::normalize_ip( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        if ( $remote === '' ) {
            return '0.0.0.0';
        }
        if ( ! self::is_trusted_proxy( $remote ) ) {
            return $remote;
        }

        // Cloudflare sets this to the visitor's address.
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $cf = self::normalize_ip( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
            if ( $cf !== '' ) {
                return $cf;
            }
        }

        // Generic proxy chain: "client, proxy1, proxy2". Walk from the right and
        // take the first address that is not one of our own proxies.
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $chain = array_reverse( array_map( 'trim', explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) );
            foreach ( $chain as $candidate ) {
                $ip = self::normalize_ip( $candidate );
                if ( $ip !== '' && ! self::is_trusted_proxy( $ip ) ) {
                    return $ip;
                }
            }
        }

        return $remote;
    }

    /** A valid IP in canonical form, or '' when the text is not an IP address. */
    private static function normalize_ip( string $ip ): string {
        $ip = trim( $ip );
        if ( stripos( $ip, '::ffff:' ) === 0 && filter_var( substr( $ip, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            $ip = substr( $ip, 7 ); // IPv4 written as IPv6
        }
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? strtolower( $ip ) : '';
    }

    /** Whether an address may be believed when it forwards a visitor's IP. */
    public static function is_trusted_proxy( string $ip ): bool {
        // Private, loopback and reserved addresses cannot be forged from the internet,
        // so a connection from one is our own reverse proxy / load balancer.
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return true;
        }

        $ranges = self::CLOUDFLARE_RANGES;
        $extra  = (string) get_option( 'ofp_trusted_proxies', '' );
        if ( $extra !== '' ) {
            $ranges = array_merge( $ranges, preg_split( '/[\s,]+/', $extra, -1, PREG_SPLIT_NO_EMPTY ) );
        }
        $ranges = (array) apply_filters( 'ofp_trusted_proxies', $ranges );

        foreach ( $ranges as $range ) {
            if ( self::ip_in_range( $ip, (string) $range ) ) {
                return true;
            }
        }
        return false;
    }

    /** IPv4 / IPv6 CIDR match ("1.2.3.0/24", "2606:4700::/32" or a single IP). */
    private static function ip_in_range( string $ip, string $range ): bool {
        if ( strpos( $range, '/' ) === false ) {
            return $ip === strtolower( $range );
        }
        [ $subnet, $bits ] = explode( '/', $range, 2 );
        $ip_bin     = @inet_pton( $ip );
        $subnet_bin = @inet_pton( $subnet );
        if ( $ip_bin === false || $subnet_bin === false || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
            return false;
        }
        $bits  = max( 0, min( (int) $bits, strlen( $ip_bin ) * 8 ) );
        $bytes = intdiv( $bits, 8 );
        $rest  = $bits % 8;
        if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
            return false;
        }
        if ( $rest > 0 ) {
            $mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
            if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $subnet_bin[ $bytes ] ) & $mask ) ) {
                return false;
            }
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CLOUDFLARE TURNSTILE VERIFICATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verify a Cloudflare Turnstile token with Cloudflare's siteverify API.
     *
     * The secret key is stored in wp_options as 'ofp_turnstile_secret'.
     * Configure it via wp-admin → OFast Pipeline → Settings.
     *
     * @param  string|null $token The cf-turnstile-response value from the form submission.
     * @return bool               True if valid, false if invalid or API error.
     */
    public static function verify_turnstile( ?string $token ): bool {
        $stored = get_option( 'ofp_turnstile_secret', '' );

        // Turnstile not configured: nothing to verify. The honeypot and the
        // per-IP rate limit in OFP_REST_API::capture_lead still protect the
        // endpoint. This check MUST come before the empty-token check below,
        // otherwise every form without a Turnstile widget (property page,
        // /api/v1/inquire snippet) is rejected even when Turnstile is off.
        if ( empty( $stored ) ) {
            return true;
        }

        // The secret is saved encrypted (see OFP_Admin_Menu). Cloudflare needs
        // the plain value. If it is configured but cannot be decrypted (for
        // example the encryption keys changed), fail closed.
        $secret = self::decrypt( (string) $stored );
        if ( $secret === '' ) {
            error_log( '[OFP] Turnstile secret is set but could not be decrypted. Re-save it in Settings.' );
            return false;
        }

        if ( empty( $token ) ) {
            return false;
        }

        $response = wp_remote_post(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            [
                'body'    => [
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => self::get_client_ip(),
                ],
                'timeout' => 10,
            ]
        );

        if ( is_wp_error( $response ) ) {
            // Log the error silently; don't block the user on an API failure.
            error_log( '[OFP] Turnstile verification error: ' . $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        return isset( $body->success ) && $body->success === true;
    }

    /**
     * Turnstile is used on the LOGIN and SIGN-UP pages only. Lead and inspection
     * forms rely on the honeypot, phone check and per-IP rate limit instead.
     *
     * On when a site key AND a secret are saved in Settings. It is skipped on a
     * white label client's own custom domain, because Cloudflare only shows the
     * widget on hostnames added to the Turnstile widget, and a missing hostname
     * would lock that client out of logging in. (They still have password + OTP +
     * rate limit.) Use the 'ofp_turnstile_enabled' filter to change this.
     */
    public static function turnstile_enabled(): bool {
        $on = (string) get_option( 'ofp_turnstile_site_key', '' ) !== ''
            && (string) get_option( 'ofp_turnstile_secret', '' ) !== '';

        if ( $on && class_exists( 'OFP_Host_Router' ) && 'client' === OFP_Host_Router::current_zone() ) {
            $host = OFP_Host_Router::normalized_host();
            $base = strtolower( (string) get_option( 'ofp_crm_base_domain', '' ) );
            if ( ! $base || ! str_ends_with( $host, '.' . $base ) ) {
                $on = false; // custom domain
            }
        }

        return (bool) apply_filters( 'ofp_turnstile_enabled', $on );
    }

    /**
     * The widget markup (plus Cloudflare's script, once per page). Empty string
     * when Turnstile is off, so templates can echo it unconditionally.
     */
    public static function turnstile_widget(): string {
        if ( ! self::turnstile_enabled() ) {
            return '';
        }
        static $script_printed = false;
        $html = '';
        if ( ! $script_printed ) {
            $html .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
            $script_printed = true;
        }
        $html .= '<div class="cf-turnstile" style="margin:14px 0;" data-sitekey="'
            . esc_attr( (string) get_option( 'ofp_turnstile_site_key', '' ) ) . '" data-theme="auto"></div>';
        return $html;
    }

    /**
     * Check the Turnstile answer posted with a login or sign-up form.
     * True when Turnstile is off, or when Cloudflare accepts the token.
     */
    public static function verify_turnstile_request(): bool {
        if ( ! self::turnstile_enabled() ) {
            return true;
        }
        $token = isset( $_POST['cf-turnstile-response'] )
            ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) )
            : '';
        return self::verify_turnstile( $token );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NONCE HELPERS (used in admin forms)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Output a hidden nonce field for an OFP admin form.
     *
     * @param string $action Unique nonce action name.
     */
    public static function nonce_field( string $action ): void {
        wp_nonce_field( 'ofp_' . $action, 'ofp_nonce' );
    }

    /**
     * Verify a nonce submitted from an OFP admin form.
     * Terminates with wp_die() on failure.
     *
     * @param string $action Unique nonce action name.
     */
    public static function verify_nonce( string $action ): void {
        $nonce = isset( $_POST['ofp_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['ofp_nonce'] ) )
            : '';

        if ( ! wp_verify_nonce( $nonce, 'ofp_' . $action ) ) {
            wp_die(
                esc_html__( 'Security check failed. Please refresh and try again.', 'ofast-pipeline' ),
                esc_html__( 'Security Error', 'ofast-pipeline' ),
                [ 'response' => 403 ]
            );
        }
    }
}
