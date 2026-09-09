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
 * Depends on: ofp_clients, ofp_admins, ofp_client_sessions, ofp_team_members, ofp_otps tables.
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
    // CLIENT AUTH & OTP
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check credentials. If valid, return user details for OTP generation.
     * Does NOT issue a session yet.
     *
     * @param  string $email    Submitted email address.
     * @param  string $password Submitted plaintext password.
     * @return array|false      Array with user_type, id, client_id, phone, or false.
     */
    public static function check_credentials( string $email, string $password ) {
        global $wpdb;

        $email = sanitize_email( $email );

        // 1. Check main client table
        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE email = %s LIMIT 1",
                $email
            )
        );

        if ( $client ) {
            if ( in_array( $client->status, [ 'suspended', 'cancelled', 'trash' ], true ) ) {
                return false;
            }
            if ( password_verify( $password, $client->password ) ) {
                return [
                    'user_type'      => 'client',
                    'id'             => $client->id,
                    'client_id'      => $client->id,
                    'team_member_id' => null,
                    'phone'          => $client->phone,
                    'email'          => $client->email
                ];
            }
        }

        // 2. Check team members table
        $team_member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_team_members WHERE email = %s LIMIT 1",
                $email
            )
        );

        if ( $team_member ) {
            if ( $team_member->status !== 'active' ) {
                return false;
            }
            if ( password_verify( $password, $team_member->password ) ) {
                // We also need to ensure the parent client isn't suspended
                $parent_client = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT status, plan FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                        $team_member->client_id
                    )
                );
                if ( ! $parent_client || in_array( $parent_client->status, [ 'suspended', 'cancelled', 'trash' ], true ) ) {
                    return false;
                }

                // Free plan doesn't include team members — if the main
                // client's subscription lapsed and they're back on Free,
                // team members lose login access along with everything
                // else that plan doesn't cover, until the client upgrades.
                if ( class_exists( 'OFP_Subscription' ) && OFP_Subscription::team_member_limit( $parent_client->plan ) <= 0 ) {
                    return false;
                }

                return [
                    'user_type'      => 'team_member',
                    'id'             => $team_member->id,
                    'client_id'      => $team_member->client_id,
                    'team_member_id' => $team_member->id,
                    'phone'          => $team_member->phone,
                    'email'          => $team_member->email
                ];
            }
        }

        return false;
    }

    /**
     * Generate a 7-character alphanumeric OTP, save it, and send via Email and SMS.
     *
     * @param string $email
     * @param string $phone
     * @param string $purpose e.g., 'login', 'signup'
     * @return bool
     */
    public static function generate_and_send_otp( string $email, string $phone, string $purpose ): bool {
        global $wpdb;

        // Generate 7 digit alphanumeric OTP
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $otp_code = '';
        for ( $i = 0; $i < 7; $i++ ) {
            $otp_code .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
        }

        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( 10 * MINUTE_IN_SECONDS ) );

        $wpdb->insert(
            $wpdb->prefix . 'ofp_otps',
            [
                'user_email' => $email,
                'user_phone' => $phone,
                'otp_code'   => $otp_code,
                'purpose'    => $purpose,
                'expires_at' => $expires_at,
                'created_at' => current_time( 'mysql' ),
            ]
        );

        // Send via SMS
        if ( class_exists( 'OFP_SMS' ) ) {
            $sms_msg = "Your Ofast Pipeline OTP is: {$otp_code}. It expires in 10 minutes.";
            OFP_SMS::send_system_sms( $phone, $sms_msg );
        }

        // Send via Email
        if ( class_exists( 'OFP_Mailer' ) ) {
            $subject = "Your Ofast Pipeline Verification Code";
            $html = "<p>Your verification code is: <strong>{$otp_code}</strong></p><p>It will expire in 10 minutes.</p>";
            OFP_Mailer::send_system_email( $email, $subject, $html );
        }

        return true;
    }

    /**
     * Verify an OTP code.
     *
     * @param string $contact Email or Phone
     * @param string $otp_code The submitted code
     * @param string $purpose 'login' or 'signup'
     * @return bool
     */
    public static function verify_otp( string $contact, string $otp_code, string $purpose ): bool {
        global $wpdb;

        $otp_code = strtoupper( trim( $otp_code ) );

        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, expires_at FROM {$wpdb->prefix}ofp_otps 
                 WHERE (user_email = %s OR user_phone = %s) 
                 AND otp_code = %s AND purpose = %s 
                 ORDER BY id DESC LIMIT 1",
                $contact, $contact, $otp_code, $purpose
            )
        );

        if ( ! $record ) {
            return false; // Not found or invalid code
        }

        if ( strtotime( $record->expires_at ) < time() ) {
            return false; // Expired
        }

        // Valid OTP. Delete it so it can't be reused.
        $wpdb->delete( $wpdb->prefix . 'ofp_otps', [ 'id' => $record->id ] );

        return true;
    }

    /**
     * Issues a session token after successful OTP verification.
     */
    public static function issue_session( int $client_id, string $user_type, ?int $team_member_id = null ): void {
        global $wpdb;

        $token      = bin2hex( random_bytes( 32 ) );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL );

        $wpdb->insert(
            $wpdb->prefix . 'ofp_client_sessions',
            [
                'client_id'      => $client_id,
                'user_type'      => $user_type,
                'team_member_id' => $team_member_id,
                'token'          => $token,
                'ip_address'     => OFP_Security::get_client_ip(),
                'expires_at'     => $expires_at,
                'created_at'     => current_time( 'mysql' ),
            ]
        );

        $cookie_options = [
            'expires'  => time() + self::SESSION_TTL,
            'path'     => '/',
            'domain'   => '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie( self::COOKIE_NAME, $token, $cookie_options );

        if ( class_exists( 'OFP_Logger' ) ) {
            // We need to temporarily set the session in memory so the logger can pick it up
            $_COOKIE[ self::COOKIE_NAME ] = $token;
            OFP_Logger::log( 'User logged in.', $client_id, [
                'ip_address' => OFP_Security::get_client_ip(),
                'user_type'  => $user_type
            ] );
        }
    }

    /**
     * Return the currently logged-in user details (client or team member).
     *
     * @return object|null
     */
    public static function current_user(): ?object {
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
            self::clear_cookie();
            return null;
        }

        if ( isset( $session->user_type ) && $session->user_type === 'team_member' ) {
            $user = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ofp_team_members WHERE id = %d LIMIT 1",
                    $session->team_member_id
                )
            );

            if ( $user ) {
                // Free plan doesn't include team members. If the client's
                // subscription lapsed and dropped them to Free while this
                // team member still had an active session, kick them out
                // right now rather than waiting for them to log in again —
                // otherwise they'd keep working the dashboard for however
                // long their session cookie still has left.
                if ( class_exists( 'OFP_Subscription' ) ) {
                    $current_plan = OFP_Subscription::client_plan( $session->client_id );
                    if ( OFP_Subscription::team_member_limit( $current_plan ) <= 0 ) {
                        $wpdb->delete( $wpdb->prefix . 'ofp_client_sessions', [ 'token' => $token ] );
                        self::clear_cookie();
                        return null;
                    }
                }

                $user->is_team_member = true;
                $user->parent_client_id = $session->client_id;
                // Decode permissions for easy access
                $user->perms = $user->permissions ? json_decode( $user->permissions, true ) : [];
            }
            return $user;
        }

        // Default: Client
        $user = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $session->client_id
            )
        );
        if ( $user ) {
            $user->is_team_member = false;
        }
        return $user;
    }

    /**
     * Check if the current user has a specific permission.
     * Main clients and Managers have all permissions.
     * Agents are checked against their specific permissions list.
     *
     * @param string $permission
     * @return bool
     */
    public static function has_permission( string $permission ): bool {
        $user = self::current_user();
        if ( ! $user ) return false;

        // Main client has all permissions
        if ( ! $user->is_team_member ) return true;

        // Managers have all permissions
        if ( isset( $user->role_name ) && $user->role_name === 'Manager' ) return true;

        // Check specific permissions for Agents
        if ( isset( $user->perms ) && is_array( $user->perms ) ) {
            return in_array( $permission, $user->perms, true );
        }

        return false;
    }

    /**
     * Get the user type from the current session.
     *
     * @return string|null
     */
    public static function get_user_type(): ?string {
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
        return ( $session && isset( $session->user_type ) ) ? $session->user_type : 'client';
    }

    /**
     * Return the currently logged-in main client object.
     * If a team member is logged in, this returns their PARENT client object,
     * so that queries scoped to the client still work transparently.
     *
     * @return object|null  Full ofp_clients row, or null.
     */
    /**
     * Resolve the CLIENT record for whoever is logged in — whether that's
     * the main client themselves, or a team member acting under that
     * client's account. Always returns the parent client's row, so
     * standard client-scoped queries (leads, listings, plan, etc.) work
     * the same way regardless of who's actually logged in.
     *
     * Rebuilt on top of current_user() instead of duplicating the session
     * lookup — the two used to be two separate, drifting implementations,
     * which is exactly how team members ended up able to keep a session
     * alive (and reach billing pages) after their client's plan no longer
     * allowed team members at all: current_user() didn't get checked by
     * most pages, only current_client() did, and current_client() never
     * had that check in the first place.
     */
    public static function current_client(): ?object {
        $user = self::current_user();
        if ( ! $user ) {
            return null;
        }

        if ( empty( $user->is_team_member ) ) {
            return $user;
        }

        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $user->parent_client_id
            )
        );
    }

    /**
     * Log out the current user.
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

    public static function require_client_login(): void {
        if ( ! self::current_client() ) {
            wp_safe_redirect( home_url( '/login' ) );
            exit;
        }
    }

    /**
     * Block team members from pages that move money (Funding, plan
     * checkout, manual funding requests). This is a hard rule — unlike
     * has_permission(), no role (not even Manager) can bypass it. Only
     * the main client login can pay, upgrade, downgrade, or submit a
     * manual funding request. Call this AFTER require_client_login().
     */
    public static function require_main_client_only(): void {
        $user = self::current_user();
        if ( $user && ! empty( $user->is_team_member ) ) {
            if ( class_exists( 'OFP_Logger' ) ) {
                OFP_Logger::log( 'Team member blocked from billing page', $user->parent_client_id ?? null, [
                    'team_member_id' => $user->id ?? null,
                    'name'           => $user->name ?? null,
                    'page'           => $_SERVER['REQUEST_URI'] ?? '',
                ] );
            }
            wp_safe_redirect( home_url( '/dashboard?billing_restricted=1' ) );
            exit;
        }
    }

    public static function require_active_subscription( object $client ): void {
        $blocked = [ 'suspended', 'cancelled', 'trash' ];
        if ( in_array( $client->status, $blocked, true ) ) {
            wp_safe_redirect( home_url( '/login?suspended=1' ) );
            exit;
        }
    }

    public static function purge_expired_sessions(): void {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}ofp_client_sessions WHERE expires_at < NOW()"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}ofp_otps WHERE expires_at < NOW()"
        );
    }

    public static function change_password(
        int $client_id,
        string $current_pw,
        string $new_pw,
        string $user_type = 'client',
        int $team_member_id = 0
    ): bool {
        global $wpdb;

        if ( $user_type === 'team_member' ) {
            $user = $wpdb->get_row( $wpdb->prepare( "SELECT password FROM {$wpdb->prefix}ofp_team_members WHERE id = %d LIMIT 1", $team_member_id ) );
            if ( ! $user || ! password_verify( $current_pw, $user->password ) ) return false;
            
            $wpdb->update(
                $wpdb->prefix . 'ofp_team_members',
                [ 'password' => password_hash( $new_pw, PASSWORD_BCRYPT ) ],
                [ 'id'       => $team_member_id ]
            );
            $wpdb->delete( $wpdb->prefix . 'ofp_client_sessions', [ 'team_member_id' => $team_member_id ] );
        } else {
            $client = $wpdb->get_row( $wpdb->prepare( "SELECT password FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1", $client_id ) );
            if ( ! $client || ! password_verify( $current_pw, $client->password ) ) return false;

            $wpdb->update(
                $wpdb->prefix . 'ofp_clients',
                [ 'password' => password_hash( $new_pw, PASSWORD_BCRYPT ) ],
                [ 'id'       => $client_id ]
            );
            // This logs out the client but NOT their team members
            $wpdb->delete( $wpdb->prefix . 'ofp_client_sessions', [ 'client_id' => $client_id, 'user_type' => 'client' ] );
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PASSWORD RESET (Phase 13)
    // ─────────────────────────────────────────────────────────────────────────

    const RESET_TOKEN_TTL_MINUTES = 30;

    public static function request_password_reset( string $email ): bool {
        global $wpdb;

        // Check clients
        $client = $wpdb->get_row( $wpdb->prepare( "SELECT id, email, owner_name FROM {$wpdb->prefix}ofp_clients WHERE email = %s LIMIT 1", sanitize_email( $email ) ) );
        if ( $client ) {
            $raw_token = bin2hex( random_bytes( 32 ) );
            $wpdb->update(
                $wpdb->prefix . 'ofp_clients',
                [
                    'reset_token_hash'    => hash('sha256', $raw_token),
                    'reset_token_expires' => gmdate( 'Y-m-d H:i:s', time() + ( self::RESET_TOKEN_TTL_MINUTES * 60 ) ),
                ],
                [ 'id' => $client->id ]
            );
            $reset_url = add_query_arg( [ 'token' => $raw_token ], home_url( '/reset-password' ) );
            OFP_Mailer::send_password_reset( $client, $reset_url );
            return true;
        }

        // Check team members
        $team_member = $wpdb->get_row( $wpdb->prepare( "SELECT id, email, name FROM {$wpdb->prefix}ofp_team_members WHERE email = %s LIMIT 1", sanitize_email( $email ) ) );
        if ( $team_member ) {
            $raw_token = bin2hex( random_bytes( 32 ) );
            // Reusing invite_token column for reset token for team members to save schema space
            $wpdb->update(
                $wpdb->prefix . 'ofp_team_members',
                [
                    'invite_token' => hash('sha256', $raw_token),
                ],
                [ 'id' => $team_member->id ]
            );
            $reset_url = add_query_arg( [ 'token' => $raw_token, 'tm' => 1 ], home_url( '/reset-password' ) );
            
            // Hacky object creation for mailer compatibility
            $obj = new stdClass();
            $obj->business_name = $team_member->name;
            $obj->owner_name = $team_member->name;
            $obj->email = $team_member->email;
            
            OFP_Mailer::send_password_reset( $obj, $reset_url );
            return true;
        }

        return false;
    }

    /**
     * Verify a reset token by its hash alone (no email needed).
     *
     * @return object|false  The matching client/team member row, or false if invalid/expired.
     */
    public static function verify_reset_token( string $raw_token, bool $is_team_member = false ) {
        global $wpdb;
        $expected_hash = hash('sha256', $raw_token);

        if ( $is_team_member ) {
            $tm = $wpdb->get_row( $wpdb->prepare( "SELECT id, email, name, invite_token FROM {$wpdb->prefix}ofp_team_members WHERE invite_token = %s LIMIT 1", $expected_hash ) );
            if ( ! $tm ) return false;
            return $tm;
        } else {
            $client = $wpdb->get_row( $wpdb->prepare( "SELECT id, email, owner_name, reset_token_hash, reset_token_expires FROM {$wpdb->prefix}ofp_clients WHERE reset_token_hash = %s LIMIT 1", $expected_hash ) );
            if ( ! $client ) return false;
            if ( strtotime( $client->reset_token_expires ) < time() ) return false;
            return $client;
        }
    }

    public static function complete_password_reset( string $raw_token, string $new_password, bool $is_team_member = false ): bool {
        global $wpdb;

        $row = self::verify_reset_token( $raw_token, $is_team_member );
        if ( ! $row ) {
            return false;
        }

        $new_hash = password_hash( $new_password, PASSWORD_BCRYPT );

        if ( $is_team_member ) {
            $updated = $wpdb->update(
                $wpdb->prefix . 'ofp_team_members',
                [ 'password' => $new_hash, 'invite_token' => null ],
                [ 'id' => $row->id ]
            );
            if ( $updated ) {
                $wpdb->delete( $wpdb->prefix . 'ofp_client_sessions', [ 'team_member_id' => $row->id ] );
            }
        } else {
            $updated = $wpdb->update(
                $wpdb->prefix . 'ofp_clients',
                [ 'password' => $new_hash, 'reset_token_hash' => null, 'reset_token_expires' => null ],
                [ 'id' => $row->id ]
            );
            if ( $updated ) {
                $wpdb->delete( $wpdb->prefix . 'ofp_client_sessions', [ 'client_id' => $row->id, 'user_type' => 'client' ] );
            }
        }

        return (bool) $updated;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN PREVIEW (debugging — log into a client's frontend dashboard)
    // ─────────────────────────────────────────────────────────────────────────

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

    public static function consume_admin_preview_token( string $token ): bool {
        $data = get_transient( 'ofp_preview_' . $token );

        if ( ! $data || empty( $data['client_id'] ) ) {
            return false;
        }

        delete_transient( 'ofp_preview_' . $token );

        global $wpdb;
        $client = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1", $data['client_id'] )
        );

        if ( ! $client ) return false;

        self::issue_session( $client->id, 'client' );

        error_log( sprintf(
            '[OFP_Auth] Admin preview: %s (admin #%d) previewed client #%d (%s) at %s',
            $data['admin_email'], $data['admin_id'], $client->id, $client->business_name, current_time( 'mysql' )
        ) );

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN AUTH (wp-admin)
    // ─────────────────────────────────────────────────────────────────────────

    public static function is_admin_user(): bool {
        if ( ! is_user_logged_in() ) return false;
        global $wpdb;
        $current_user = wp_get_current_user();
        $admin = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ofp_admins WHERE email = %s LIMIT 1", $current_user->user_email ) );
        return (bool) $admin;
    }

    public static function current_admin_role(): ?string {
        if ( ! is_user_logged_in() ) return null;
        global $wpdb;
        $current_user = wp_get_current_user();
        $admin = $wpdb->get_row( $wpdb->prepare( "SELECT role FROM {$wpdb->prefix}ofp_admins WHERE email = %s LIMIT 1", $current_user->user_email ) );
        return $admin->role ?? null;
    }

    public static function current_admin(): ?object {
        if ( ! is_user_logged_in() ) return null;
        global $wpdb;
        $current_user = wp_get_current_user();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ofp_admins WHERE email = %s LIMIT 1", $current_user->user_email ) );
    }

    public static function is_super_admin(): bool {
        return self::current_admin_role() === 'super_admin';
    }

    private static function clear_cookie(): void {
        setcookie( self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );
    }
}
