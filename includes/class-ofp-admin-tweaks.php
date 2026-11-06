<?php
/**
 * OFP_Admin_Tweaks
 *
 * Handles Admin Bar white-labeling and Security Hardening options.
 * Settings are managed from OFast Pipeline Settings (Tab 1: General and Tab 8: Security).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Admin_Tweaks {

    /**
     * Bootstraps all active admin tweaks and security filters.
     */
    public static function init(): void {
        // ── Admin Bar Customizations ──────────────────────────────────────
        self::init_admin_bar_tweaks();

        // ── Security Hardening ────────────────────────────────────────────
        self::init_security_tweaks();
    }

    /**
     * Initializes Admin Bar visibility and white-labeling.
     */
    private static function init_admin_bar_tweaks(): void {
        // Global & Role-based frontend admin bar visibility
        add_filter( 'show_admin_bar', [ self::class, 'filter_admin_bar_visibility' ], 99 );

        // Admin bar elements removal & greeting customization
        add_action( 'wp_before_admin_bar_render', [ self::class, 'modify_admin_bar_nodes' ] );
        add_filter( 'admin_bar_menu', [ self::class, 'customize_admin_greeting' ], PHP_INT_MAX );

        // Inject inline CSS if admin bar is hidden to prevent margin gap on themes
        add_action( 'wp_head', [ self::class, 'inject_admin_bar_css' ], 1 );
    }

    /**
     * Filters whether the admin bar should be shown on the frontend.
     */
    public static function filter_admin_bar_visibility( bool $show ): bool {
        if ( is_admin() ) {
            return $show;
        }

        // 1. Global toggle
        if ( '1' === (string) get_option( 'ofp_hide_admin_bar', '1' ) ) {
            return false;
        }

        // 2. Role-based hiding
        if ( is_user_logged_in() ) {
            $hidden_roles = get_option( 'ofp_hide_admin_bar_roles', [] );
            if ( is_array( $hidden_roles ) && ! empty( $hidden_roles ) ) {
                $user = wp_get_current_user();
                foreach ( (array) $user->roles as $role ) {
                    if ( in_array( $role, $hidden_roles, true ) ) {
                        return false;
                    }
                }
            }
        }

        return $show;
    }

    /**
     * Injects CSS on frontend when admin bar is hidden to ensure zero gap.
     */
    public static function inject_admin_bar_css(): void {
        if ( ! is_admin() && ! is_admin_bar_showing() ) {
            echo '<style id="ofp-hide-admin-bar">#wpadminbar{display:none!important;}html{margin-top:0!important;}* html body{margin-top:0!important;}body.admin-bar header{top:0!important;}</style>' . "\n";
        }
    }

    /**
     * Removes WP Logo and +New shortcut menu from the admin bar.
     */
    public static function modify_admin_bar_nodes(): void {
        global $wp_admin_bar;

        if ( ! is_object( $wp_admin_bar ) ) {
            return;
        }

        // Remove WordPress Logo dropdown
        if ( '1' === (string) get_option( 'ofp_remove_wp_logo', '0' ) ) {
            $wp_admin_bar->remove_menu( 'wp-logo' );
        }

        // Remove "+ New" creation dropdown
        if ( '1' === (string) get_option( 'ofp_remove_new_content', '0' ) ) {
            $wp_admin_bar->remove_menu( 'new-content' );
        }
    }

    /**
     * Replaces the default "Howdy" greeting in the top-right user menu.
     */
    public static function customize_admin_greeting( $wp_admin_bar ): void {
        if ( '1' !== (string) get_option( 'ofp_custom_greeting_enabled', '0' ) ) {
            return;
        }

        if ( ! is_object( $wp_admin_bar ) ) {
            return;
        }

        $my_account = $wp_admin_bar->get_node( 'my-account' );
        if ( ! $my_account ) {
            return;
        }

        $custom_greeting = trim( (string) get_option( 'ofp_custom_greeting_text', 'Welcome' ) );
        if ( $custom_greeting === '' ) {
            $custom_greeting = 'Welcome';
        }
        $custom_greeting = rtrim( $custom_greeting, " ," );

        // Preserve username/avatar display and update greeting prefix only
        if ( preg_match( '/(<span[^>]*class=["\'][^"\']*display-name[^"\']*["\'][^>]*>.*)$/i', $my_account->title, $matches ) ) {
            $new_title = esc_html( $custom_greeting . ', ' ) . $matches[1];
        } else {
            $new_title = preg_replace( '/^\s*[^<]*?,\s*/u', esc_html( $custom_greeting . ', ' ), $my_account->title, 1 );
        }

        $wp_admin_bar->add_node( [
            'id'    => 'my-account',
            'title' => $new_title,
        ] );
    }

    /**
     * Initializes Security Hardening features.
     */
    private static function init_security_tweaks(): void {
        // ── 1. Disable XML-RPC ────────────────────────────────────────────
        if ( '1' === (string) get_option( 'ofp_disable_xmlrpc', '0' ) ) {
            add_filter( 'xmlrpc_enabled', '__return_false' );
            add_filter( 'wp_headers', [ self::class, 'remove_xmlrpc_headers' ] );
            add_filter( 'xmlrpc_methods', '__return_empty_array' );

            // Block direct requests to xmlrpc.php
            if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) ) === 'xmlrpc.php' ) {
                status_header( 403 );
                nocache_headers();
                exit( 'XML-RPC service is disabled on this server.' );
            }
        }

        // ── 2. Obfuscate Author Slugs & Block Enumeration ─────────────────
        if ( '1' === (string) get_option( 'ofp_obfuscate_author_slugs', '0' ) ) {
            add_filter( 'author_link', [ self::class, 'obfuscate_author_link' ], 10, 3 );
            add_filter( 'request', [ self::class, 'handle_obfuscated_author_request' ] );
            add_action( 'template_redirect', [ self::class, 'block_author_enumeration' ] );

            // Keep hash in sync on user registration/update
            add_action( 'profile_update', [ self::class, 'update_user_author_hash' ] );
            add_action( 'user_register', [ self::class, 'update_user_author_hash' ] );
        }

        // ── 3. Obfuscate Plaintext Emails in Content ──────────────────────
        if ( '1' === (string) get_option( 'ofp_obfuscate_emails', '0' ) ) {
            add_filter( 'the_content', [ self::class, 'obfuscate_emails_in_content' ], 20 );
            add_filter( 'the_excerpt', [ self::class, 'obfuscate_emails_in_content' ], 20 );
            add_filter( 'widget_text', [ self::class, 'obfuscate_emails_in_content' ], 20 );
            add_filter( 'comment_text', [ self::class, 'obfuscate_emails_in_content' ], 20 );
        }
    }

    /**
     * Strips X-Pingback header from outgoing HTTP headers.
     */
    public static function remove_xmlrpc_headers( array $headers ): array {
        unset( $headers['X-Pingback'] );
        return $headers;
    }

    /**
     * Prevents author username harvesting via ?author=N.
     */
    public static function block_author_enumeration(): void {
        if ( isset( $_GET['author'] ) && is_numeric( $_GET['author'] ) ) {
            wp_safe_redirect( home_url(), 301 );
            exit;
        }
    }

    /**
     * Replaces author username in public URL with a 12-char cryptographic hash.
     */
    public static function obfuscate_author_link( string $link, int $author_id, string $author_nicename ): string {
        $hash = self::get_user_author_hash( $author_id );
        return home_url( '/author/' . $hash . '/' );
    }

    /**
     * Gets or generates the obfuscated author hash for a user.
     */
    public static function get_user_author_hash( int $user_id ): string {
        $hash = get_user_meta( $user_id, 'ofp_author_hash', true );
        if ( empty( $hash ) ) {
            $hash = self::update_user_author_hash( $user_id );
        }
        return $hash;
    }

    /**
     * Generates and stores a unique, deterministic hash for a user author URL.
     */
    public static function update_user_author_hash( int $user_id ): string {
        $hash = substr( md5( 'ofp_author_' . $user_id . wp_salt() ), 0, 12 );
        update_user_meta( $user_id, 'ofp_author_hash', $hash );
        return $hash;
    }

    /**
     * Translates incoming obfuscated 12-char author hash back to author user ID.
     */
    public static function handle_obfuscated_author_request( array $query_vars ): array {
        if ( isset( $query_vars['author_name'] ) ) {
            $author_name = $query_vars['author_name'];
            if ( preg_match( '/^[a-f0-9]{12}$/', $author_name ) ) {
                $users = get_users( [
                    'meta_key'   => 'ofp_author_hash',
                    'meta_value' => $author_name,
                    'fields'     => 'ID',
                    'number'     => 1,
                ] );

                if ( ! empty( $users ) ) {
                    $query_vars['author'] = (int) $users[0];
                    unset( $query_vars['author_name'] );
                }
            }
        }
        return $query_vars;
    }

    /**
     * Converts plaintext email addresses in content to HTML character entities.
     */
    public static function obfuscate_emails_in_content( $content ) {
        if ( empty( $content ) || ! is_string( $content ) ) {
            return $content;
        }

        $pattern = '/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/';
        return preg_replace_callback( $pattern, [ self::class, 'encode_email_entity' ], $content );
    }

    /**
     * Converts a matched email string into numeric HTML entities.
     */
    private static function encode_email_entity( array $matches ): string {
        $email   = $matches[1];
        $encoded = '';
        $length  = strlen( $email );
        for ( $i = 0; $i < $length; $i++ ) {
            $encoded .= '&#' . ord( $email[ $i ] ) . ';';
        }
        return $encoded;
    }
}

