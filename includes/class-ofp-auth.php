<?php
/**
 * OFP_Auth
 *
 * Custom authentication system for both clients and admins.
 * NO membership plugin, NO WooCommerce, NO extra dependency.
 *
 * TWO separate auth contexts:
 *
 * 1. CLIENT AUTH  — completely custom, nothing to do with WordPress login.
 *    Clients log in at /login, get a token stored in ofp_client_sessions,
 *    token is set as an HttpOnly cookie. No WordPress user account needed.
 *
 * 2. ADMIN AUTH   — Olabode and partner log in via normal wp-login.php,
 *    but access to OFast Pipeline admin pages is gated by checking their
 *    email against the ofp_admins table AFTER WordPress login succeeds.
 *    This means a WP user who is NOT in ofp_admins sees nothing.
 *
 * Depends on: ofp_clients, ofp_admins, ofp_client_sessions tables.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Auth {

    /** Cookie name for the client session token. */
    const COOKIE_NAME = 'ofp_client_session';

    /** Session lifetime in seconds (7 days). */
    const SESSION_TTL = 604800;

    // ─────────────────────────────────────────────────────────────────────────
    // CLIENT AUTH
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Attempt to log a client in with email + password.
     * On success, generates a session token, stores it in the DB, and sets a cookie.
     *
     * @param  string $email    Submitted email address.
     * @param  string $password Submitted plaintext password.
     * @return bool             True on success, false on failure.
     */
    public static function attempt_login( string $email, string $password ): bool {
        global $wpdb;

        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE email = %s LIMIT 1",
                sanitize_email( $email )
            )
        );

        if ( ! $client ) {
            return false;
        }

        // Reject suspended / cancelled accounts before checking password.
        // Reject suspended, cancelled, or trashed accounts before checking password.
        if ( in_array( $client->status, [ 'suspended', 'cancelled', 'trash' ], true ) ) {
            return false;
        }

        if ( ! password_verify( $password, $client->password ) ) {
            return false;
        }

        // Generate a cryptographically secure session token.
        $token      = bin2hex( random_bytes( 32 ) );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL );

        $wpdb->insert(
            $wpdb->prefix . 'ofp_client_sessions',
            [
                'client_id'  => $client->id,
                'token'      => $token,
                'ip_address' => OFP_Security::get_client_ip(),
                'expires_at' => $expires_at,
                'created_at' => current_time( 'mysql' ),
            ]
        );

        // Set HttpOnly, SameSite cookie — secure flag only when on HTTPS.
        $cookie_options = [
            'expires'  => time() + self::SESSION_TTL,
            'path'     => '/',
            'domain'   => '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie( self::COOKIE_NAME, $token, $cookie_options );

        return true;
    }

    /**
     * Return the currently logged-in client object, or null if not logged in.
     * Validates the cookie token against ofp_client_sessions.
     *
     * @return object|null  Full ofp_clients row, or null.
     */
    public static function current_client(): ?object {
        global $wpdb;

        $token = isset( $_COOKIE[ self::COOKIE_NAME ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) )
            : '';

        if ( empty( $token ) ) {
            return null;
        }

        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_client_sessions
                 WHERE token = %s AND expires_at > NOW()
                 LIMIT 1",
                $token
            )
        );

        if ( ! $session ) {
            // Token expired or invalid — clear the stale cookie.
            self::clear_cookie();
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $session->client_id
            )
        );
    }

    /**
     * Log out the current client: delete DB session and clear cookie.
     *
     * @return void
     */
    public static function logout(): void {
        global $wpdb;

        $token = isset( $_COOKIE[ self::COOKIE_NAME ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) )
            : '';

        if ( $token ) {
            $wpdb->delete(
                $wpdb->prefix . 'ofp_client_sessions',
                [ 'token' => $token ]
            );
        }

        self::clear_cookie();
    }

    /**
     * Redirect to /login if the client is not authenticated.
     * Call this at the top of every protected client portal template.
     *
     * @return void
     */
    public static function require_client_login(): void {
        if ( ! self::current_client() ) {
            wp_safe_redirect( home_url( '/login' ) );
            exit;
        }
    }

    /**
     * Redirect to /login if the client's subscription is not active.
     * pending_review and grace are allowed through so they can still
     * see their dashboard and billing info.
     *
     * @param  object $client  Client row from ofp_clients.
     * @return void
     */
    public static function require_active_subscription( object $client ): void {
        $blocked = [ 'suspended', 'cancelled', 'trash' ];
        if ( in_array( $client->status, $blocked, true ) ) {
            wp_safe_redirect( home_url( '/login?suspended=1' ) );
            exit;
        }
    }

    /**
     * Expire and clean up sessions older than SESSION_TTL.
     * Called periodically by the daily cron job.
     *
     * @return void
     */
    public static function purge_expired_sessions(): void {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}ofp_client_sessions WHERE expires_at < NOW()"
        );
    }

    /**
     * Change a client's password after verifying the current one.
     *
     * @param  int    $client_id   Client ID.
     * @param  string $current_pw  Current plaintext password.
     * @param  string $new_pw      New plaintext password.
     * @return bool                True on success.
     */
    public static function change_password(
        int $client_id,
        string $current_pw,
        string $new_pw
    ): bool {
        global $wpdb;

        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT password FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $client_id
            )
        );

        if ( ! $client || ! password_verify( $current_pw, $client->password ) ) {
            return false;
        }

        $wpdb->update(
            $wpdb->prefix . 'ofp_clients',
            [ 'password' => password_hash( $new_pw, PASSWORD_BCRYPT ) ],
            [ 'id'       => $client_id ]
        );

        // Invalidate all existing sessions so other devices are logged out.
        $wpdb->delete( $wpdb->prefix . 'ofp_client_sessions', [ 'client_id' => $client_id ] );

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PASSWORD RESET (Phase 13)
    // ─────────────────────────────────────────────────────────────────────────

    const RESET_TOKEN_TTL_MINUTES = 30;

    /**
     * Request a password reset email for a client.
     * Generates a token, hashes it, saves to DB, and sends the email.
     *
     * @param string $email Client email address
     * @return bool True if successful, false if email not found
     */
    public static function request_password_reset( string $email ): bool {
        global $wpdb;

        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE email = %s LIMIT 1",
                sanitize_email( $email )
            )
        );

        if ( ! $client ) {
            return false;
        }

        // Generate 32 bytes of secure randomness
        $raw_token = bin2hex( random_bytes( 32 ) );
        $hashed_token = self::hash_reset_token( $raw_token );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( self::RESET_TOKEN_TTL_MINUTES * 60 ) );

        $updated = $wpdb->update(
            $wpdb->prefix . 'ofp_clients',
            [
                'reset_token_hash'    => $hashed_token,
                'reset_token_expires' => $expires_at,
            ],
            [ 'id' => $client->id ]
        );

        if ( false === $updated ) {
            return false;
        }

        $reset_url = add_query_arg(
            [
                'token' => $raw_token,
                'email' => rawurlencode( $client->email ),
            ],
            home_url( '/reset-password' )
        );

        // Uses the existing mailer method which accepts ($client, $reset_url)
        OFP_Mailer::send_password_reset( $client, $reset_url );

        return true;
    }

    /**
     * Verify if a reset token is valid and not expired.
     *
     * @param string $email
     * @param string $raw_token
     * @return bool True if valid, false if invalid or expired
     */
    public static function verify_reset_token( string $email, string $raw_token ): bool {
        global $wpdb;

        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, reset_token_hash, reset_token_expires FROM {$wpdb->prefix}ofp_clients WHERE email = %s LIMIT 1",
                sanitize_email( $email )
            )
        );

        if ( ! $client || empty( $client->reset_token_hash ) || empty( $client->reset_token_expires ) ) {
            return false;
        }

        // Check expiration
        if ( strtotime( $client->reset_token_expires ) < time() ) {
            return false;
        }

        // Verify token hash
        $expected_hash = self::hash_reset_token( $raw_token );
        if ( ! hash_equals( $expected_hash, $client->reset_token_hash ) ) {
            return false;
        }

        return true;
    }

    /**
     * Complete the password reset. Verifies token and updates password.
     *
     * @param string $email
     * @param string $raw_token
     * @param string $new_password
     * @return bool
     */
    public static function complete_password_reset( string $email, string $raw_token, string $new_password ): bool {
        global $wpdb;

        if ( ! self::verify_reset_token( $email, $raw_token ) ) {
            return false;
        }

        $new_hash = password_hash( $new_password, PASSWORD_BCRYPT );

        $updated = $wpdb->update(
            $wpdb->prefix . 'ofp_clients',
            [
                'password'            => $new_hash,
                'reset_token_hash'    => null, // Consume the token
                'reset_token_expires' => null,
            ],
            [ 'email' => sanitize_email( $email ) ]
        );

        // Delete any active sessions so they have to log in again
        if ( $updated ) {
            $client_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ofp_clients WHERE email = %s", sanitize_email( $email ) ) );
            if ( $client_id ) {
                $wpdb->delete( $wpdb->prefix . 'ofp_client_sessions', [ 'client_id' => $client_id ] );
            }
        }

        return (bool) $updated;
    }

    /**
     * Hash the raw reset token for secure DB storage.
     *
     * @param string $raw_token
     * @return string
     */
    private static function hash_reset_token( string $raw_token ): string {
        return hash( 'sha256', $raw_token );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN PREVIEW (debugging — log into a client's frontend dashboard)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a one-time admin preview token for a specific client.
     *
     * Allows a verified OFP admin to view a client's frontend dashboard
     * exactly as the client sees it, for debugging purposes — without
     * knowing or resetting the client's password.
     *
     * The token is single-use and expires after 15 minutes. Every use is
     * logged via error_log() for auditability (who previewed whose account).
     *
     * @param  int $client_id  Client ID to preview.
     * @return string          The generated token.
     */
    public static function generate_admin_preview_token( int $client_id ): string {
        $token   = bin2hex( random_bytes( 32 ) );
        $admin   = self::current_admin();

        set_transient(
            'ofp_preview_' . $token,
            [
                'client_id'   => $client_id,
                'admin_id'    => $admin->id   ?? 0,
                'admin_email' => $admin->email ?? 'unknown',
                'created_at'  => time(),
            ],
            15 * MINUTE_IN_SECONDS
        );

        return $token;
    }

    /**
     * Verify and consume an admin preview token.
     *
     * On success, logs the admin into the target client's session exactly
     * like a normal client login, then deletes the token (single-use).
     *
     * @param  string $token  Token from the preview URL.
     * @return bool           True if the token was valid and login succeeded.
     */
    public static function consume_admin_preview_token( string $token ): bool {
        $data = get_transient( 'ofp_preview_' . $token );

        if ( ! $data || empty( $data['client_id'] ) ) {
            return false;
        }

        // Token is single-use — delete immediately regardless of outcome below.
        delete_transient( 'ofp_preview_' . $token );

        global $wpdb;
        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $data['client_id']
            )
        );

        if ( ! $client ) {
            return false;
        }

        // Create a session exactly as attempt_login() would, but without
        // password verification — this IS the verification (admin auth + token).
        $session_token = bin2hex( random_bytes( 32 ) );
        $expires_at    = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL );

        $wpdb->insert(
            $wpdb->prefix . 'ofp_client_sessions',
            [
                'client_id'  => $client->id,
                'token'      => $session_token,
                'ip_address' => OFP_Security::get_client_ip(),
                'expires_at' => $expires_at,
                'created_at' => current_time( 'mysql' ),
            ]
        );

        setcookie(
            self::COOKIE_NAME,
            $session_token,
            [
                'expires'  => time() + self::SESSION_TTL,
                'path'     => '/',
                'domain'   => '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        // Audit log — who previewed whose account and when.
        error_log( sprintf(
            '[OFP_Auth] Admin preview: %s (admin #%d) previewed client #%d (%s) at %s',
            $data['admin_email'],
            $data['admin_id'],
            $client->id,
            $client->business_name,
            current_time( 'mysql' )
        ) );

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN AUTH (wp-admin)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if the currently logged-in WordPress user is a registered OFP admin.
     * Cross-references wp_get_current_user()->user_email against ofp_admins.
     *
     * @return bool
     */
    public static function is_admin_user(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        global $wpdb;

        $current_user = wp_get_current_user();

        $admin = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_admins WHERE email = %s LIMIT 1",
                $current_user->user_email
            )
        );

        return (bool) $admin;
    }

    /**
     * Return the role of the currently logged-in OFP admin.
     *
     * @return string|null  'super_admin', 'co_admin', or null if not an admin.
     */
    public static function current_admin_role(): ?string {
        if ( ! is_user_logged_in() ) {
            return null;
        }

        global $wpdb;

        $current_user = wp_get_current_user();

        $admin = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT role FROM {$wpdb->prefix}ofp_admins WHERE email = %s LIMIT 1",
                $current_user->user_email
            )
        );

        return $admin->role ?? null;
    }

    /**
     * Return the full admin row for the currently logged-in WordPress user.
     *
     * @return object|null  Full ofp_admins row, or null.
     */
    public static function current_admin(): ?object {
        if ( ! is_user_logged_in() ) {
            return null;
        }

        global $wpdb;

        $current_user = wp_get_current_user();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_admins WHERE email = %s LIMIT 1",
                $current_user->user_email
            )
        );
    }

    /**
     * Check if current admin is a super_admin. Shorthand for role check.
     *
     * @return bool
     */
    public static function is_super_admin(): bool {
        return self::current_admin_role() === 'super_admin';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Clear the client session cookie from the browser.
     *
     * @return void
     */
    private static function clear_cookie(): void {
        setcookie(
            self::COOKIE_NAME,
            '',
            [
                'expires'  => time() - 3600,
                'path'     => '/',
                'domain'   => '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
}
