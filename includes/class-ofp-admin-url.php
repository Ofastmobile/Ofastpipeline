<?php
/**
 * OFast Pipeline - Admin URL Customizer & Brute-Force Shield Module
 *
 * Obfuscates /wp-admin and /wp-login.php behind a configurable secret URL.
 * Unauthorized visitors receive a 404 response.
 * Features session-backed hardened cookies, single-use emergency bypass URLs,
 * wp-config.php fail-safe bypass, and IP-level brute-force lockout.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Admin_Url {

    /**
     * Singleton instance.
     *
     * @var OFP_Admin_Url|null
     */
    private static ?OFP_Admin_Url $instance = null;

    /**
     * Active custom slug.
     *
     * @var string
     */
    private string $custom_slug = '';

    /**
     * Active emergency bypass key.
     *
     * @var string
     */
    private string $emergency_key = '';

    /**
     * Initialize singleton and hooks.
     *
     * @return OFP_Admin_Url
     */
    public static function init(): OFP_Admin_Url {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        $this->custom_slug   = (string) get_option( 'ofp_admin_custom_slug', '' );
        $this->emergency_key = (string) get_option( 'ofp_admin_emergency_key', '' );

        // Standalone admin post actions for resending details and rotating emergency key.
        add_action( 'admin_post_ofp_admin_url_resend', [ $this, 'handle_resend_admin_action' ] );
        add_action( 'admin_post_ofp_admin_url_rotate_key', [ $this, 'handle_rotate_key_action' ] );

        // Always register security hooks for brute-force tracking if enabled.
        if ( $this->is_enabled() ) {
            $this->register_security_hooks();
        }

        // Only proceed with URL protection if enabled and custom slug is configured.
        if ( ! $this->is_enabled() || empty( $this->custom_slug ) ) {
            return;
        }

        // Check for bypass constant in wp-config.php.
        if ( ( defined( 'OFP_DISABLE_ADMIN_PROTECTION' ) && OFP_DISABLE_ADMIN_PROTECTION === true ) ||
             ( defined( 'OFAST_DISABLE_ADMIN_PROTECTION' ) && OFAST_DISABLE_ADMIN_PROTECTION === true ) ) {
            return;
        }

        // Customize logout redirect - go to home page instead of login page (which 404s).
        add_filter( 'logout_redirect', [ $this, 'custom_logout_redirect' ], 999, 3 );

        // Fallback: catch wp-login.php?loggedout=true and redirect to home.
        add_action( 'login_init', [ $this, 'handle_logout_redirect' ], 1 );

        // Clear login cookies on logout.
        add_action( 'wp_logout', [ $this, 'clear_login_cookies' ] );

        // Check for emergency key in URL (timing-safe comparison).
        $this->check_emergency_access();

        // Check for bypass cookie (session-based).
        if ( $this->has_valid_bypass_cookie() ) {
            return;
        }

        // Register custom URL handler.
        add_action( 'init', [ $this, 'handle_custom_url' ], 1 );

        // Block default login page - uses login_init which fires only on wp-login.php.
        add_action( 'login_init', [ $this, 'block_login_page' ], 0 );

        // Block wp-admin pages - uses template_redirect which fires after plugins load.
        add_action( 'template_redirect', [ $this, 'block_admin_pages' ], 1 );
    }

    /**
     * Check if connection is secure (HTTPS).
     * Handles reverse proxies, load balancers, and CDNs.
     *
     * @return bool
     */
    public static function is_secure_connection(): bool {
        if ( is_ssl() ) {
            return true;
        }

        if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
            return true;
        }

        if ( isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) && 'on' === $_SERVER['HTTP_X_FORWARDED_SSL'] ) {
            return true;
        }

        if ( isset( $_SERVER['HTTP_CF_VISITOR'] ) ) {
            $cf_visitor = json_decode( (string) $_SERVER['HTTP_CF_VISITOR'] );
            if ( isset( $cf_visitor->scheme ) && 'https' === $cf_visitor->scheme ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send a hardened cookie with SameSite support.
     *
     * @param string $name    Cookie name.
     * @param string $value   Cookie value.
     * @param int    $expires Expiry timestamp.
     * @return void
     */
    public function send_cookie( string $name, string $value, int $expires ): void {
        $path      = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
        $domain    = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
        $secure    = self::is_secure_connection();
        $http_only = true;

        if ( defined( 'PHP_VERSION_ID' ) && PHP_VERSION_ID >= 70300 ) {
            setcookie( $name, $value, [
                'expires'  => $expires,
                'path'     => $path,
                'domain'   => $domain,
                'secure'   => $secure,
                'httponly' => $http_only,
                'samesite' => 'Strict',
            ] );
            return;
        }

        setcookie( $name, $value, $expires, $path . '; samesite=Strict', $domain, $secure, $http_only );
    }

    /**
     * Clear a cookie immediately.
     *
     * @param string $name Cookie name.
     * @return void
     */
    public function clear_cookie( string $name ): void {
        unset( $_COOKIE[ $name ] );
        $this->send_cookie( $name, 'expired', time() - HOUR_IN_SECONDS );
    }

    /**
     * Generate a temporary token for admin-access cookies.
     *
     * @return string
     */
    public function generate_session_token(): string {
        return function_exists( 'random_bytes' )
            ? bin2hex( random_bytes( 32 ) )
            : wp_generate_password( 64, false );
    }

    /**
     * Check if a session-backed cookie is still valid.
     *
     * @param string $cookie_name      Cookie name.
     * @param string $transient_prefix Transient prefix.
     * @return bool
     */
    private function has_valid_session_cookie( string $cookie_name, string $transient_prefix ): bool {
        if ( empty( $_COOKIE[ $cookie_name ] ) ) {
            return false;
        }

        $session_token = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
        if ( '' === $session_token ) {
            $this->clear_cookie( $cookie_name );
            return false;
        }

        $transient_key = $transient_prefix . md5( $session_token );
        if ( get_transient( $transient_key ) ) {
            return true;
        }

        $this->clear_cookie( $cookie_name );
        return false;
    }

    /**
     * Clear a session-backed cookie and its transient.
     *
     * @param string $cookie_name      Cookie name.
     * @param string $transient_prefix Transient prefix.
     * @return void
     */
    private function clear_session_cookie( string $cookie_name, string $transient_prefix ): void {
        if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
            $session_token = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
            if ( '' !== $session_token ) {
                delete_transient( $transient_prefix . md5( $session_token ) );
            }
        }

        $this->clear_cookie( $cookie_name );
    }

    /**
     * Create the temporary session that proves a user entered via the custom URL.
     *
     * @return void
     */
    public function set_custom_login_cookie(): void {
        $session_token = $this->generate_session_token();
        set_transient( 'ofp_custom_login_session_' . md5( $session_token ), true, HOUR_IN_SECONDS );
        $this->send_cookie( 'ofp_custom_login', $session_token, time() + HOUR_IN_SECONDS );
        $_COOKIE['ofp_custom_login'] = $session_token;
    }

    /**
     * Check whether the custom-login cookie is valid.
     *
     * @return bool
     */
    public function has_valid_custom_login_cookie(): bool {
        return $this->has_valid_session_cookie( 'ofp_custom_login', 'ofp_custom_login_session_' );
    }

    /**
     * Check whether the emergency bypass cookie is valid.
     *
     * @return bool
     */
    public function has_valid_bypass_cookie(): bool {
        return $this->has_valid_session_cookie( 'ofp_admin_bypass', 'ofp_bypass_session_' );
    }

    /**
     * Whether Admin URL protection is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_option( 'ofp_admin_url_enabled', 0 );
    }

    /**
     * Get the active custom slug.
     *
     * @return string
     */
    public static function get_custom_slug(): string {
        return (string) get_option( 'ofp_admin_custom_slug', '' );
    }

    /**
     * Get or generate emergency key.
     *
     * @return string
     */
    public static function get_emergency_key(): string {
        $key = (string) get_option( 'ofp_admin_emergency_key', '' );
        if ( empty( $key ) ) {
            $key = wp_generate_password( 32, false );
            update_option( 'ofp_admin_emergency_key', $key );
        }
        return $key;
    }

    /**
     * Get the full custom login URL.
     *
     * @return string
     */
    public static function get_custom_login_url(): string {
        $slug = self::get_custom_slug();
        if ( empty( $slug ) ) {
            return wp_login_url();
        }
        return trailingslashit( home_url() ) . $slug;
    }

    /**
     * Get the emergency bypass login URL.
     *
     * @return string
     */
    public static function get_emergency_bypass_url(): string {
        $key = self::get_emergency_key();
        return add_query_arg( 'ofp_emergency', $key, wp_login_url() );
    }

    /**
     * Check emergency access query parameter.
     *
     * @return void
     */
    private function check_emergency_access(): void {
        if ( ! isset( $_GET['ofp_emergency'] ) && ! isset( $_GET['ofast_emergency'] ) ) {
            return;
        }

        $param_key = isset( $_GET['ofp_emergency'] ) ? 'ofp_emergency' : 'ofast_emergency';

        if ( empty( $this->emergency_key ) ) {
            return;
        }

        // Rate limit emergency key attempts (max 5 attempts per 5 minutes per IP).
        $client_ip = $this->get_client_ip();
        $rate_key  = 'ofp_emergency_attempts_' . md5( $client_ip );
        $attempts  = (int) get_transient( $rate_key );

        if ( $attempts >= 5 ) {
            $this->log_security_event( 'emergency_rate_limited', [
                'ip'       => $client_ip,
                'attempts' => $attempts,
            ] );
            wp_die(
                esc_html__( 'Too many emergency access attempts. Please try again in 5 minutes.', 'ofast-pipeline' ),
                esc_html__( 'Rate Limited', 'ofast-pipeline' ),
                [ 'response' => 429 ]
            );
        }

        set_transient( $rate_key, $attempts + 1, 5 * MINUTE_IN_SECONDS );

        $provided_key = sanitize_text_field( wp_unslash( $_GET[ $param_key ] ) );
        if ( hash_equals( $this->emergency_key, $provided_key ) ) {
            delete_transient( $rate_key );

            // ONE-TIME USE: Rotate key immediately.
            $new_key = $this->rotate_emergency_key();

            // Set bypass cookie.
            $session_token = $this->generate_session_token();
            $this->send_cookie( 'ofp_admin_bypass', $session_token, time() + HOUR_IN_SECONDS );
            $_COOKIE['ofp_admin_bypass'] = $session_token;

            set_transient( 'ofp_bypass_session_' . md5( $session_token ), true, HOUR_IN_SECONDS );

            // Send notification email with the new rotated key.
            $this->send_new_emergency_key_email( $new_key );
        }
    }

    /**
     * Handle custom URL access.
     *
     * @return void
     */
    public function handle_custom_url(): void {
        if ( empty( $this->custom_slug ) ) {
            return;
        }

        $request_uri = parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
        $request_uri = rtrim( (string) $request_uri, '/' );
        $target_slug = '/' . trim( $this->custom_slug, '/' );

        if ( $request_uri === $target_slug || str_ends_with( $request_uri, $target_slug ) ) {
            // Set cookie allowing access to login form.
            $this->set_custom_login_cookie();
            $target_url = wp_login_url();
            if ( ! empty( $_GET['redirect_to'] ) ) {
                $target_url = add_query_arg( 'redirect_to', urlencode( wp_unslash( $_GET['redirect_to'] ) ), $target_url );
            }
            wp_safe_redirect( $target_url );
            exit;
        }
    }

    /**
     * Block default wp-login.php access for unauthorized requests.
     *
     * @return void
     */
    public function block_login_page(): void {
        if ( $this->has_valid_custom_login_cookie() || $this->has_valid_bypass_cookie() ) {
            return;
        }

        // Allow logout actions.
        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        if ( 'logout' === $action ) {
            return;
        }

        $logged_out = isset( $_GET['loggedout'] ) ? sanitize_text_field( wp_unslash( $_GET['loggedout'] ) ) : '';
        if ( 'true' === $logged_out ) {
            return;
        }

        // Allow password reset actions.
        if ( in_array( $action, [ 'lostpassword', 'rp', 'resetpass' ], true ) ) {
            return;
        }

        // If user is already authenticated, allow access.
        if ( is_user_logged_in() ) {
            return;
        }

        // Block with 404 header.
        status_header( 404 );
        nocache_headers();
        wp_die(
            esc_html__( 'Page not found', 'ofast-pipeline' ),
            esc_html__( '404 Not Found', 'ofast-pipeline' ),
            [
                'response'  => 404,
                'back_link' => false,
            ]
        );
    }

    /**
     * Block /wp-admin requests for unauthenticated visitors.
     *
     * @return void
     */
    public function block_admin_pages(): void {
        $request_uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );

        if ( false === strpos( $request_uri, '/wp-admin' ) ) {
            return;
        }

        if ( $this->has_valid_custom_login_cookie() || $this->has_valid_bypass_cookie() ) {
            return;
        }

        // Allow AJAX, admin-post, cron.
        if ( str_contains( $request_uri, 'admin-ajax.php' ) ||
             str_contains( $request_uri, 'admin-post.php' ) ||
             str_contains( $request_uri, 'wp-cron.php' ) ) {
            return;
        }

        // Logged in users have access.
        if ( is_user_logged_in() ) {
            return;
        }

        // Don't block static assets.
        if ( preg_match( '/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/i', $request_uri ) ) {
            return;
        }

        $this->show_404();
    }

    /**
     * Render 404 response.
     *
     * @return void
     */
    private function show_404(): void {
        status_header( 404 );
        nocache_headers();

        $template = get_404_template();
        if ( $template && file_exists( $template ) ) {
            include $template;
        } else {
            wp_die( esc_html__( 'Page not found', 'ofast-pipeline' ), esc_html__( '404 Not Found', 'ofast-pipeline' ), [ 'response' => 404 ] );
        }
        exit;
    }

    /**
     * Custom logout redirect to homepage.
     *
     * @param string           $redirect_to           Default redirect.
     * @param string           $requested_redirect_to Requested URL.
     * @param WP_User|WP_Error $user                  User object.
     * @return string
     */
    public function custom_logout_redirect( $redirect_to, $requested_redirect_to, $user ): string {
        return home_url( '/' );
    }

    /**
     * Catch loggedout parameter on wp-login.php.
     *
     * @return void
     */
    public function handle_logout_redirect(): void {
        $logged_out = isset( $_GET['loggedout'] ) ? sanitize_text_field( wp_unslash( $_GET['loggedout'] ) ) : '';
        if ( 'true' === $logged_out ) {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }
    }

    /**
     * Clear session cookies on logout.
     *
     * @return void
     */
    public function clear_login_cookies(): void {
        $this->clear_session_cookie( 'ofp_custom_login', 'ofp_custom_login_session_' );
        $this->clear_session_cookie( 'ofp_admin_bypass', 'ofp_bypass_session_' );
    }

    /**
     * Rotate emergency key (single-use security).
     *
     * @return string
     */
    public function rotate_emergency_key(): string {
        $new_key = wp_generate_password( 32, false );
        update_option( 'ofp_admin_emergency_key', $new_key );
        $this->emergency_key = $new_key;

        $this->log_security_event( 'emergency_key_rotated', [
            'time' => current_time( 'mysql' ),
            'ip'   => $this->get_client_ip(),
        ] );

        return $new_key;
    }

    /**
     * Send email notification with new/updated login and emergency URLs.
     *
     * @param string $custom_slug   Slug.
     * @param string $emergency_key Key.
     * @return bool
     */
    /**
     * Send email notification to admin with the custom URL and emergency bypass details.
     * Uses OFP_Mailer with HTML design and fallback to wp_mail.
     *
     * @param string $custom_slug   Slug.
     * @param string $emergency_key Key.
     * @return bool
     */
    public static function send_admin_notification( string $custom_slug, string $emergency_key ): bool {
        $admin_email        = get_option( 'admin_email' );
        $current_user_email = wp_get_current_user()->user_email ?? '';

        $recipients = array_filter( array_unique( [ $admin_email, $current_user_email ] ) );
        if ( empty( $recipients ) ) {
            error_log( 'OFP Admin URL: Invalid admin email, cannot send notification.' );
            return false;
        }

        $brand_name    = class_exists( 'OFP_Brand' ) ? OFP_Brand::name() : get_bloginfo( 'name' );
        $site_url      = home_url();
        $custom_url    = trailingslashit( $site_url ) . $custom_slug;
        $emergency_url = add_query_arg( 'ofp_emergency', $emergency_key, wp_login_url() );

        $subject = sprintf( '[%s] Admin Login URL Security Update - Please Save!', $brand_name );

        $html = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1e293b; line-height: 1.6;">'
              . '<div style="background: #0f172a; padding: 24px; border-radius: 8px 8px 0 0; text-align: center;">'
              . '<h1 style="color: #ffffff; margin: 0; font-size: 20px; letter-spacing: 0.5px;">' . esc_html( strtoupper( $brand_name ) ) . ' SECURITY ALERT</h1>'
              . '</div>'
              . '<div style="background: #ffffff; border: 1px solid #e2e8f0; border-top: none; padding: 28px; border-radius: 0 0 8px 8px;">'
              . '<p style="font-size: 15px; margin-top: 0;">Your WordPress admin access URL has been secured. Standard <code>/wp-admin</code> and <code>/wp-login.php</code> paths are cloaked and return 404 to unauthorized visitors.</p>'
              . '<div style="background: #f8fafc; border-left: 4px solid #0284c7; padding: 14px 18px; margin: 20px 0; border-radius: 4px;">'
              . '<strong style="display: block; font-size: 12px; text-transform: uppercase; color: #64748b; margin-bottom: 4px;">Your Secret Login URL:</strong>'
              . '<a href="' . esc_url( $custom_url ) . '" style="color: #0284c7; font-size: 16px; font-weight: 700; word-break: break-all; text-decoration: none;">' . esc_html( $custom_url ) . '</a>'
              . '</div>'
              . '<div style="background: #fef2f2; border-left: 4px solid #ef4444; padding: 14px 18px; margin: 20px 0; border-radius: 4px;">'
              . '<strong style="display: block; font-size: 12px; text-transform: uppercase; color: #991b1b; margin-bottom: 4px;">Emergency One-Time Bypass URL:</strong>'
              . '<a href="' . esc_url( $emergency_url ) . '" style="color: #dc2626; font-size: 14px; font-family: monospace; word-break: break-all; text-decoration: none;">' . esc_html( $emergency_url ) . '</a>'
              . '<p style="font-size: 12px; color: #7f1d1d; margin: 8px 0 0 0;">Save this URL in a secure place. If you ever forget your custom slug, visiting this bypass link resets access immediately and automatically generates a new key.</p>'
              . '</div>'
              . '<div style="background: #f1f5f9; padding: 12px 16px; border-radius: 6px; font-size: 12px; color: #475569; margin-top: 24px;">'
              . '<strong>Developer Fail-safe:</strong> If completely locked out, add <code>define(\'OFP_DISABLE_ADMIN_PROTECTION\', true);</code> to <code>wp-config.php</code>.'
              . '</div>'
              . '<p style="font-size: 11.5px; color: #94a3b8; margin: 24px 0 0; text-align: center;">Generated on ' . esc_html( current_time( 'F j, Y \a\t g:i a' ) ) . ' for ' . esc_html( $site_url ) . '</p>'
              . '</div></div>';

        $plain = "=================================================\n"
               . " " . strtoupper( $brand_name ) . " ADMIN URL SECURITY UPDATE\n"
               . "=================================================\n\n"
               . "Your WordPress admin access URL has been secured.\n\n"
               . "IMPORTANT: Save this email. The default /wp-admin and /wp-login.php\n"
               . "paths now return 404 Not Found to unauthorized visitors.\n\n"
               . "--------------------------------------------------\n"
               . " YOUR NEW LOGIN URL:\n"
               . "--------------------------------------------------\n"
               . "{$custom_url}\n\n"
               . "--------------------------------------------------\n"
               . " EMERGENCY BACKUP ACCESS (One-Time Use):\n"
               . "--------------------------------------------------\n"
               . "{$emergency_url}\n\n"
               . "--------------------------------------------------\n"
               . " DEVELOPER FAIL-SAFE (wp-config.php):\n"
               . "--------------------------------------------------\n"
               . "define('OFP_DISABLE_ADMIN_PROTECTION', true);\n\n"
               . "Site: {$site_url}\n"
               . "Date: " . current_time( 'F j, Y \a\t g:i a' ) . "\n"
               . "=================================================\n";

        $sent_any = false;
        foreach ( $recipients as $recipient ) {
            if ( ! is_email( $recipient ) ) {
                continue;
            }

            $sent = false;
            if ( class_exists( 'OFP_Mailer' ) ) {
                $sent = OFP_Mailer::send_system_email( $recipient, $subject, $html );
            }
            if ( ! $sent ) {
                $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
                $sent    = (bool) wp_mail( $recipient, $subject, $html, $headers );
            }
            if ( ! $sent ) {
                $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
                $sent    = (bool) wp_mail( $recipient, $subject, $plain, $headers );
            }
            if ( $sent ) {
                $sent_any = true;
            }
        }

        return $sent_any;
    }

    /**
     * Send email with new rotated emergency key.
     *
     * @param string $new_key New key.
     * @return bool
     */
    private function send_new_emergency_key_email( string $new_key ): bool {
        $admin_email        = get_option( 'admin_email' );
        $current_user_email = wp_get_current_user()->user_email ?? '';

        $recipients = array_filter( array_unique( [ $admin_email, $current_user_email ] ) );
        if ( empty( $recipients ) ) {
            return false;
        }

        $brand_name        = class_exists( 'OFP_Brand' ) ? OFP_Brand::name() : get_bloginfo( 'name' );
        $site_url          = home_url();
        $custom_url        = trailingslashit( $site_url ) . $this->custom_slug;
        $new_emergency_url = add_query_arg( 'ofp_emergency', $new_key, wp_login_url() );

        $subject = sprintf( '[%s] Emergency Key Rotated (One-Time Use)', $brand_name );

        $html = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #1e293b; line-height: 1.6;">'
              . '<div style="background: #991b1b; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;">'
              . '<h2 style="color: #ffffff; margin: 0; font-size: 18px;">EMERGENCY KEY ROTATED</h2>'
              . '</div>'
              . '<div style="background: #ffffff; border: 1px solid #e2e8f0; border-top: none; padding: 24px; border-radius: 0 0 8px 8px;">'
              . '<p>Your emergency bypass link was just used. For security, a <strong>NEW key has been automatically generated</strong> and the previous key has been invalidated.</p>'
              . '<div style="background: #fef2f2; border-left: 4px solid #dc2626; padding: 14px 18px; margin: 18px 0; border-radius: 4px;">'
              . '<strong style="display: block; font-size: 12px; text-transform: uppercase; color: #991b1b; margin-bottom: 4px;">Your New Emergency Bypass URL:</strong>'
              . '<a href="' . esc_url( $new_emergency_url ) . '" style="color: #dc2626; font-size: 14px; font-family: monospace; word-break: break-all; text-decoration: none;">' . esc_html( $new_emergency_url ) . '</a>'
              . '</div>'
              . '<p style="font-size: 13px; color: #64748b;">Your custom login URL remains: <a href="' . esc_url( $custom_url ) . '" style="color:#0284c7;">' . esc_html( $custom_url ) . '</a></p>'
              . '<p style="font-size: 11.5px; color: #94a3b8; margin-top: 20px;">IP: ' . esc_html( $this->get_client_ip() ) . ' · ' . esc_html( current_time( 'mysql' ) ) . '</p>'
              . '</div></div>';

        $plain = "=================================================\n"
               . " EMERGENCY KEY ROTATED - NEW KEY GENERATED\n"
               . "=================================================\n\n"
               . "Your emergency bypass link was just used. For security, a NEW\n"
               . "key has been generated and the old key has been invalidated.\n\n"
               . "YOUR NEW EMERGENCY BYPASS URL:\n{$new_emergency_url}\n\n"
               . "Your custom login URL remains: {$custom_url}\n\n"
               . "Time: " . current_time( 'mysql' ) . "\n"
               . "IP: " . $this->get_client_ip() . "\n";

        $sent_any = false;
        foreach ( $recipients as $recipient ) {
            if ( ! is_email( $recipient ) ) {
                continue;
            }

            $sent = false;
            if ( class_exists( 'OFP_Mailer' ) ) {
                $sent = OFP_Mailer::send_system_email( $recipient, $subject, $html );
            }
            if ( ! $sent ) {
                $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
                $sent    = (bool) wp_mail( $recipient, $subject, $html, $headers );
            }
            if ( ! $sent ) {
                $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
                $sent    = (bool) wp_mail( $recipient, $subject, $plain, $headers );
            }
            if ( $sent ) {
                $sent_any = true;
            }
        }

        return $sent_any;
    }

    // ── Brute Force Protection & IP Tracking ─────────────────────────────────────

    /**
     * Register authentication and failure hooks.
     *
     * @return void
     */
    public function register_security_hooks(): void {
        add_action( 'wp_login_failed', [ $this, 'record_failed_login' ] );
        add_action( 'wp_login', [ $this, 'record_successful_login' ], 10, 2 );
        add_filter( 'authenticate', [ $this, 'check_lockout_before_auth' ], 30, 3 );
    }

    /**
     * Check if an IP address is currently locked out.
     *
     * @param string $ip Client IP.
     * @return bool
     */
    public function is_ip_locked_out( string $ip ): bool {
        return (bool) get_transient( 'ofp_login_lockout_' . md5( $ip ) );
    }

    /**
     * Get failed login attempts for an IP.
     *
     * @param string $ip Client IP.
     * @return int
     */
    public function get_failed_attempts( string $ip ): int {
        $attempts = get_transient( 'ofp_login_attempts_' . md5( $ip ) );
        return $attempts ? (int) $attempts : 0;
    }

    /**
     * Check if an IP is in the whitelist.
     *
     * @param string $ip Client IP.
     * @return bool
     */
    public function is_ip_whitelisted( string $ip ): bool {
        $whitelist = (string) get_option( 'ofp_security_ip_whitelist', '' );
        if ( empty( $whitelist ) ) {
            return false;
        }

        $whitelisted_ips = array_filter( array_map( 'trim', explode( "\n", $whitelist ) ) );
        return in_array( $ip, $whitelisted_ips, true );
    }

    /**
     * Record a failed login attempt and trigger lockout if limit reached.
     *
     * @param string $username Username attempted.
     * @return void
     */
    public function record_failed_login( string $username ): void {
        $ip = $this->get_client_ip();

        if ( $this->is_ip_whitelisted( $ip ) ) {
            return;
        }

        $max_attempts     = (int) get_option( 'ofp_security_max_attempts', 5 );
        $lockout_duration = (int) get_option( 'ofp_security_lockout_duration', 15 );

        $attempts = $this->get_failed_attempts( $ip ) + 1;
        set_transient( 'ofp_login_attempts_' . md5( $ip ), $attempts, $lockout_duration * MINUTE_IN_SECONDS );

        $this->log_security_event( 'failed_login', [
            'username' => $username,
            'ip'       => $ip,
            'attempts' => $attempts,
        ] );

        if ( $attempts >= $max_attempts ) {
            set_transient( 'ofp_login_lockout_' . md5( $ip ), time(), $lockout_duration * MINUTE_IN_SECONDS );
            $this->send_security_alert( $ip, $username, $attempts );
        }
    }

    /**
     * Clear failed attempts on successful login.
     *
     * @param string  $user_login User login.
     * @param WP_User $user       User object.
     * @return void
     */
    public function record_successful_login( string $user_login, $user ): void {
        $ip = $this->get_client_ip();

        delete_transient( 'ofp_login_attempts_' . md5( $ip ) );
        delete_transient( 'ofp_login_lockout_' . md5( $ip ) );

        $this->log_security_event( 'successful_login', [
            'username' => $user_login,
            'ip'       => $ip,
            'user_id'  => is_object( $user ) && isset( $user->ID ) ? $user->ID : 0,
        ] );
    }

    /**
     * Verify whether the requesting IP is locked out before attempting authentication.
     *
     * @param WP_User|WP_Error|null $user     User.
     * @param string                $username Username.
     * @param string                $password Password.
     * @return mixed
     */
    public function check_lockout_before_auth( $user, string $username, string $password ) {
        if ( empty( $username ) ) {
            return $user;
        }

        $ip = $this->get_client_ip();

        if ( $this->is_ip_whitelisted( $ip ) ) {
            return $user;
        }

        if ( $this->is_ip_locked_out( $ip ) ) {
            $this->log_security_event( 'blocked_attempt', [
                'ip'       => $ip,
                'username' => $username,
            ] );

            $lockout_duration = (int) get_option( 'ofp_security_lockout_duration', 15 );

            return new WP_Error(
                'ofp_locked_out',
                sprintf(
                    '<strong>%s</strong> %s',
                    esc_html__( 'Security Lockout:', 'ofast-pipeline' ),
                    sprintf( esc_html__( 'Too many failed login attempts. Please try again in %d minutes.', 'ofast-pipeline' ), $lockout_duration )
                )
            );
        }

        return $user;
    }

    /**
     * Get client IP with trusted proxy resolution.
     *
     * @return string
     */
    public static function get_client_ip(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        $trusted_proxies = apply_filters( 'ofp_trusted_proxies', [ '127.0.0.1', '::1' ] );

        if ( in_array( $ip, $trusted_proxies, true ) ) {
            if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $forwarded_ips = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
                $ip            = trim( $forwarded_ips[0] );
            } elseif ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
                $ip = (string) $_SERVER['HTTP_CLIENT_IP'];
            }
        }

        $validated = filter_var( $ip, FILTER_VALIDATE_IP );
        return $validated ? sanitize_text_field( $validated ) : '0.0.0.0';
    }

    /**
     * Log a security event to a non-blocking transient.
     *
     * @param string $event_type Event name.
     * @param array  $data       Event metadata.
     * @return void
     */
    public function log_security_event( string $event_type, array $data ): void {
        $log_key = 'ofp_security_log_' . gmdate( 'Y-m-d-H' );
        $log     = get_transient( $log_key );

        if ( ! is_array( $log ) ) {
            $log = [];
        }

        if ( count( $log ) >= 50 ) {
            $log = array_slice( $log, -49 );
        }

        $log[] = [
            'type'      => $event_type,
            'data'      => $data,
            'timestamp' => current_time( 'mysql' ),
        ];

        set_transient( $log_key, $log, DAY_IN_SECONDS );
    }

    /**
     * Send email alert when an IP reaches maximum failed attempts.
     *
     * @param string $ip       Target IP.
     * @param string $username Username attempted.
     * @param int    $attempts Attempt count.
     * @return bool
     */
    private function send_security_alert( string $ip, string $username, int $attempts ): bool {
        $admin_email = get_option( 'admin_email' );
        if ( ! is_email( $admin_email ) ) {
            return false;
        }

        $brand_name = class_exists( 'OFP_Brand' ) ? OFP_Brand::name() : get_bloginfo( 'name' );
        $site_url   = home_url();
        $duration   = (int) get_option( 'ofp_security_lockout_duration', 15 );

        $subject = sprintf( '[Security Alert] %s - Multiple Failed Login Attempts', $brand_name );

        $message = "SECURITY ALERT - Multiple Failed Login Attempts\n"
                 . "================================================\n\n"
                 . "Someone has attempted to log in repeatedly without success.\n\n"
                 . "DETAILS:\n"
                 . "--------\n"
                 . "IP Address: {$ip}\n"
                 . "Username: {$username}\n"
                 . "Attempts: {$attempts}\n"
                 . "Time: " . current_time( 'F j, Y \a\t g:i a' ) . "\n"
                 . "Site: {$site_url}\n\n"
                 . "ACTION TAKEN:\n"
                 . "-------------\n"
                 . "This IP address has been temporarily blocked from logging in for {$duration} minutes.\n";

        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        return (bool) wp_mail( $admin_email, $subject, $message, $headers );
    }

    // ── Standalone Post Handlers ──────────────────────────────────────────────────

    /**
     * Handle 'Resend Login Details' button from Settings.
     *
     * @return void
     */
    public function handle_resend_admin_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( 'ofp_admin_url_action' );

        $slug = self::get_custom_slug();
        $key  = self::get_emergency_key();

        if ( ! empty( $slug ) && ! empty( $key ) ) {
            self::send_admin_notification( $slug, $key );
            $msg = 'Login details resent to ' . get_option( 'admin_email' ) . '.';
        } else {
            $msg = 'Custom login URL is not configured yet.';
        }

        wp_safe_redirect( add_query_arg( [ 'ofp_url_notice' => rawurlencode( $msg ) ], admin_url( 'admin.php?page=ofp-settings#tab-security' ) ) );
        exit;
    }

    /**
     * Handle 'Rotate Emergency Key' button from Settings.
     *
     * @return void
     */
    public function handle_rotate_key_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( 'ofp_admin_url_action' );

        $new_key = $this->rotate_emergency_key();
        $slug    = self::get_custom_slug();

        if ( ! empty( $slug ) ) {
            self::send_admin_notification( $slug, $new_key );
        }

        $msg = 'Emergency bypass key rotated and emailed to ' . get_option( 'admin_email' ) . '.';
        wp_safe_redirect( add_query_arg( [ 'ofp_url_notice' => rawurlencode( $msg ) ], admin_url( 'admin.php?page=ofp-settings#tab-security' ) ) );
        exit;
    }
}

