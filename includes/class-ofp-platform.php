<?php
/**
 * OFP_Platform
 *
 * The platform's OWN company account (for example "MyPropertyHQ"), used to list
 * rentals and properties directly.
 *
 * It is NOT a white label. A white label hides its listings from the main
 * marketplace and gets its own website. The platform account is the opposite:
 * its listings appear everywhere, like any other client's.
 *
 * What a platform account gets
 *  - no listing limit, no team-seat limit, every feature (reuses the existing
 *    "unlimited" switch, OFP_Subscription::is_unlimited)
 *  - no credits, funding, plans or billing: pages hidden and redirected, SMS and
 *    voice are never charged, and no upgrade or low-credit emails are sent
 *  - the owner (main login) can do everything
 *  - TEAM MEMBERS of this account can use the whole client side but cannot:
 *      invite, edit or remove team members (they may view the team list)
 *      open Integrations & API
 *      edit the profile (they may view it)
 *      open any billing page
 *  - emails the owner receives are also copied to the team (never security mail)
 *
 * Everything is enforced on the server. Hiding a menu item is only cosmetic.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Platform {

    const SCHEMA_OPTION = 'ofp_platform_schema_v1';

    /** Pages that deal with money. Nobody on a platform account needs them. */
    const BILLING_ROUTES = [ 'credits', 'funding', 'pricing', 'listing-billing' ];

    /** Pages team members of a platform account may not open. */
    const OWNER_ONLY_ROUTES = [ 'api-settings', 'api' ];

    /** AJAX actions team members of a platform account may not run. */
    const OWNER_ONLY_AJAX = [
        'ofp_invite_team_member',
        'ofp_update_team_member',
        'ofp_delete_team_member',
        'ofp_save_email_branding',
        'ofp_submit_client_verification',
    ];

    /** Emails that are never copied to the team (codes, passwords, invitations, owner-only alerts). */
    const PRIVATE_SUBJECT_PATTERN = '/password|otp|verification|verify|one-time|reset|welcome|invit|login|log in|sign.?in|security|team member|activity/i';

    /** Words in an email body that mark it as private to the owner. */
    const PRIVATE_BODY_PATTERN = '/action=rp|action=resetpass|token=|otp|one-time|verification code|verify your|temporary password|temporary code/i';

    public static function init(): void {
        self::ensure_schema();

        // Run before the portal's own router (priority 10) so blocked pages never render.
        add_action( 'template_redirect', [ __CLASS__, 'guard_routes' ], 5 );
        add_action( 'admin_init', [ __CLASS__, 'guard_ajax' ], 1 );
        add_action( 'wp_footer', [ __CLASS__, 'render_view_only_notice' ], 50 );
    }

    // ── Database ───────────────────────────────────────────────────────

    /** Adds the is_platform column once. */
    public static function ensure_schema(): void {
        if ( '1' === (string) get_option( self::SCHEMA_OPTION, '' ) ) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'ofp_clients';
        if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'is_platform'" ) ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN is_platform TINYINT(1) NOT NULL DEFAULT 0" );
        }
        if ( ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'is_platform'" ) ) ) {
            update_option( self::SCHEMA_OPTION, '1', false );
        }
    }

    // ── Who is the platform account? ───────────────────────────────────

    /** @param int|object|null $client Client ID or ofp_clients row. */
    public static function is_platform( $client ): bool {
        static $memo = [];

        if ( is_object( $client ) ) {
            if ( isset( $client->is_platform ) ) {
                return (int) $client->is_platform === 1;
            }
            $client = (int) ( $client->id ?? 0 );
        }
        $id = (int) $client;
        if ( $id <= 0 ) {
            return false;
        }
        if ( ! isset( $memo[ $id ] ) ) {
            global $wpdb;
            $memo[ $id ] = 1 === (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT is_platform FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $id
            ) );
        }
        return $memo[ $id ];
    }

    /** ID of the platform account, or 0 when none exists yet. */
    public static function platform_client_id(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}ofp_clients WHERE is_platform = 1 ORDER BY id ASC LIMIT 1" );
    }

    /** SQL fragment: leave the platform account out (for upgrade nudges and similar). */
    public static function not_platform_sql( string $alias = '' ): string {
        return ' AND ' . ( '' !== $alias ? $alias . '.' : '' ) . 'is_platform = 0';
    }

    /** True when the logged-in person is a TEAM MEMBER of the platform account. */
    public static function is_team_member_session(): bool {
        static $memo = null;
        if ( null !== $memo ) {
            return $memo;
        }
        $memo = false;
        if ( class_exists( 'OFP_Auth' ) && OFP_Auth::is_logged_in() ) {
            $user = OFP_Auth::current_user();
            if ( $user && ! empty( $user->is_team_member ) ) {
                $memo = self::is_platform( (int) ( $user->parent_client_id ?? 0 ) );
            }
        }
        return $memo;
    }

    /** Alias used by templates: team pages and the profile are read-only for these people. */
    public static function team_view_only(): bool {
        return self::is_team_member_session();
    }

    /** Is the current session (owner or team) working inside the platform account? */
    private static function session_is_platform(): bool {
        if ( ! class_exists( 'OFP_Auth' ) || ! OFP_Auth::is_logged_in() ) {
            return false;
        }
        $client = OFP_Auth::current_client();
        return $client && self::is_platform( (int) $client->id );
    }

    // ── Server-side guards ─────────────────────────────────────────────

    public static function guard_routes(): void {
        $route = (string) get_query_var( 'ofp_route', '' );
        if ( '' === $route || ! self::session_is_platform() ) {
            return;
        }

        // 1. Nobody on the platform account uses billing pages.
        if ( in_array( $route, self::BILLING_ROUTES, true ) ) {
            wp_safe_redirect( home_url( '/dashboard' ) );
            exit;
        }

        if ( ! self::is_team_member_session() ) {
            return; // The owner may open everything else.
        }

        // 2. Integrations and API are for the owner only.
        if ( in_array( $route, self::OWNER_ONLY_ROUTES, true ) ) {
            self::log_blocked( $route );
            wp_safe_redirect( home_url( '/dashboard?owner_only=1' ) );
            exit;
        }

        // 3. The profile is view-only. Any form submission is dropped.
        if ( in_array( $route, [ 'account', 'profile' ], true ) && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            self::log_blocked( $route . ' (save attempt)' );
            wp_safe_redirect( home_url( '/' . $route . '?view_only=1' ) );
            exit;
        }
    }

    public static function guard_ajax(): void {
        if ( ! wp_doing_ajax() ) {
            return;
        }
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( '' === $action || ! in_array( $action, self::OWNER_ONLY_AJAX, true ) ) {
            return;
        }
        if ( self::is_team_member_session() ) {
            self::log_blocked( 'ajax:' . $action );
            wp_send_json_error( 'Only the account owner can do this.' );
        }
    }

    private static function log_blocked( string $what ): void {
        if ( class_exists( 'OFP_Logger' ) && class_exists( 'OFP_Auth' ) ) {
            $user = OFP_Auth::current_user();
            OFP_Logger::log( 'Team member blocked from owner-only area', $user->parent_client_id ?? null, [
                'team_member_id' => $user->id ?? null,
                'name'           => $user->name ?? null,
                'target'         => $what,
            ] );
        }
    }

    // ── View-only profile (visual layer; the real block is guard_routes) ──

    public static function render_view_only_notice(): void {
        if ( ! self::is_team_member_session() ) {
            return;
        }
        $route = (string) get_query_var( 'ofp_route', '' );
        if ( ! in_array( $route, [ 'account', 'profile' ], true ) ) {
            return;
        }
        ?>
        <script>
        (function () {
            var bar = document.createElement('div');
            bar.setAttribute('role', 'status');
            bar.style.cssText = 'position:sticky;top:0;z-index:9999;background:#fef9c3;color:#713f12;border-bottom:1px solid #fde68a;padding:10px 16px;font:600 13px/1.4 system-ui,sans-serif;text-align:center;';
            bar.textContent = 'View only: the account owner manages the profile. Contact them to change anything here.';
            document.body.insertBefore(bar, document.body.firstChild);

            document.querySelectorAll('main form, .ofp-main form, form').forEach(function (form) {
                if (form.closest('#ofp-chat-root')) { return; }
                form.querySelectorAll('input, select, textarea, button').forEach(function (el) {
                    if (el.type === 'hidden') { return; }
                    if (el.type === 'submit' || el.tagName === 'BUTTON') { el.style.display = 'none'; return; }
                    el.disabled = true;
                });
            });
        })();
        </script>
        <?php
    }

    // ── Emails: copy the owner's operational mail to the team ──────────

    /**
     * Add the team as hidden recipients (Bcc) on mail addressed to the platform owner.
     * Called from OFP_Mailer::send(). Security mail is never copied.
     *
     * @param array       $headers          Mail headers.
     * @param object|null $recipient_client Client the recipient belongs to.
     * @param string      $to               Recipient address.
     * @param string      $subject          Subject (used to skip private mail).
     * @return array Headers, with a Bcc line when it applies.
     */
    public static function add_team_bcc( array $headers, $recipient_client, string $to, string $subject, string $body_html = '' ): array {
        if ( ! $recipient_client || ! self::is_platform( $recipient_client ) ) {
            return $headers;
        }
        // Only mail addressed to the OWNER's own address is copied (not mail to a team member or tenant).
        if ( 0 !== strcasecmp( trim( (string) ( $recipient_client->email ?? '' ) ), trim( $to ) ) ) {
            return $headers;
        }
        if ( preg_match( self::PRIVATE_SUBJECT_PATTERN, $subject ) ) {
            return $headers;
        }
        if ( '' !== $body_html && preg_match( self::PRIVATE_BODY_PATTERN, $body_html ) ) {
            return $headers;
        }
        if ( ! apply_filters( 'ofp_platform_team_copy', true, $recipient_client, $to, $subject, $body_html ) ) {
            return $headers;
        }

        global $wpdb;
        $emails = $wpdb->get_col( $wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}ofp_team_members WHERE client_id = %d AND status = 'active'",
            (int) $recipient_client->id
        ) );

        $emails = array_values( array_unique( array_filter( (array) $emails, 'is_email' ) ) );
        $emails = array_values( array_diff( array_map( 'strtolower', $emails ), [ strtolower( $to ) ] ) );
        if ( empty( $emails ) ) {
            return $headers;
        }

        $headers[] = 'Bcc: ' . implode( ', ', $emails );
        return $headers;
    }
}
