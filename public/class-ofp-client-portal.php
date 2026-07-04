<?php
/**
 * OFP_Client_Portal
 *
 * Registers and handles all front-end client-facing routes using WordPress's
 * native rewrite rule system. No routing plugin, no page builder dependency.
 *
 * HOW IT WORKS:
 *  1. register_rewrite_rules() — tells WordPress "if the URL is /login, /dashboard
 *     etc., treat it as index.php?ofp_route=<slug>"
 *  2. register_query_vars()   — whitelists 'ofp_route' so WordPress passes it through
 *  3. handle_routes()         — fires on template_redirect, checks ofp_route, loads
 *     the matching PHP template from public/templates/, then exits (skips WP theme).
 *
 * ROUTES (v2.0 + v2.1):
 *  Public  (no login required): /login, /signup
 *  Private (login required):    /dashboard, /leads, /pipeline-settings,
 *                                /communications, /credits, /reports,
 *                                /account, /my-listing
 *
 * IMPORTANT:
 *  After any change to $routes, WordPress permalinks MUST be flushed once.
 *  This happens automatically on plugin activation (OFP_Activator calls
 *  flush_rewrite_rules()). If you add a new route manually, visit
 *  wp-admin → Settings → Permalinks → Save to flush again.
 *
 * Depends on: OFP_Auth, all public/templates/*.php files.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Client_Portal {

    /**
     * Map of URL slug → template file (relative to public/templates/).
     *
     * Keys are the URL slugs that will be registered as rewrite rules.
     * Values are the PHP template files that render each page.
     *
     * v2.0 routes: login, dashboard, leads, pipeline-settings,
     *              communications, credits, reports, account
     * v2.1 routes: signup, my-listing (NEW)
     *
     * @var array<string, string>
     */
    private array $routes = [
        // ── Public routes (no login required) ────────────────────────────────
        'login'             => 'login.php',
        'signup'            => 'signup.php',            // v2.1 — self-serve onboarding
        'forgot-password'   => 'forgot-password.php',   // v2.2 — Phase 13: password reset request
        'reset-password'    => 'reset-password.php',    // v2.2 — Phase 13: set new password

        // ── Private routes (login required) ──────────────────────────────────
        'dashboard'         => 'dashboard.php',
        'leads'             => 'leads.php',
        'pipeline-settings' => 'pipeline-settings.php',
        'communications'    => 'communications.php',
        'credits'           => 'credits.php',
        'reports'           => 'reports.php',
        'account'           => 'account.php',
        'my-listing'        => 'my-listing.php',        // v2.1 — property listing management
    ];

    /**
     * Routes accessible without being logged in.
     * Everything NOT in this list redirects to /login if the client is unauthenticated.
     *
     * @var array<string>
     */
    private array $public_routes = [
        'login',
        'signup',
        'forgot-password',
        'reset-password',
    ];

    /**
     * Constructor — hook in early enough to register rewrites and intercept routing.
     */
    public function __construct() {
        add_action( 'init',               [ $this, 'register_rewrite_rules' ] );
        add_filter( 'query_vars',         [ $this, 'register_query_vars' ] );
        add_action( 'template_redirect',  [ $this, 'handle_routes' ] );
        add_action( 'init',               [ $this, 'handle_logout' ] );
        add_action( 'template_redirect',  [ $this, 'redirect_authenticated_away_from_auth_pages' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Enqueue client portal JS on OFP portal routes only.
     * CSS is enqueued inline in each template via <link> tag for simplicity.
     *
     * @return void
     */
    public function enqueue_assets(): void {
        $route = get_query_var( 'ofp_route', '' );
        if ( empty( $route ) || ! array_key_exists( $route, $this->routes ) ) {
            return;
        }

        wp_enqueue_script(
            'ofp-client-portal',
            OFP_URL . 'assets/js/client-portal.js',
            [],
            OFP_VERSION,
            true
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REWRITE RULES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Register one rewrite rule per route slug.
     *
     * Example: ^login/?$ → index.php?ofp_route=login
     *          ^my-listing/?$ → index.php?ofp_route=my-listing
     *
     * The trailing /? makes the trailing slash optional so both
     * /login and /login/ work correctly.
     *
     * Rules are added at 'top' priority so they fire before WordPress's
     * own post/page/archive rules and don't accidentally match a real page.
     *
     * @return void
     */
    public function register_rewrite_rules(): void {
        foreach ( array_keys( $this->routes ) as $slug ) {
            add_rewrite_rule(
                '^' . preg_quote( $slug, '/' ) . '/?$',
                'index.php?ofp_route=' . $slug,
                'top'
            );
        }
    }

    /**
     * Whitelist the 'ofp_route' query variable so WordPress
     * doesn't strip it before we can read it in handle_routes().
     *
     * @param  array<string> $vars  Existing query vars.
     * @return array<string>        Vars with our addition.
     */
    public function register_query_vars( array $vars ): array {
        $vars[] = 'ofp_route';
        return $vars;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ROUTE HANDLING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Central route dispatcher — fires on template_redirect.
     *
     * Flow:
     *  1. Read ofp_route from query vars.
     *  2. If no match, return and let WordPress handle the request normally.
     *  3. If the route is private and the client is not logged in, redirect to /login.
     *  4. Build the full path to the template file.
     *  5. If the template file doesn't exist, show a 404.
     *  6. Include the template and exit (prevents WP theme from loading).
     *
     * @return void
     */
    public function handle_routes(): void {
        $route = get_query_var( 'ofp_route', '' );

        // Not one of our routes — let WordPress handle it normally.
        if ( empty( $route ) || ! array_key_exists( $route, $this->routes ) ) {
            return;
        }

        // Private route: require login.
        if ( ! in_array( $route, $this->public_routes, true ) ) {
            OFP_Auth::require_client_login();

            // After confirming login, also check that account is not blocked.
            $client = OFP_Auth::current_client();
            if ( $client ) {
                OFP_Auth::require_active_subscription( $client );
            }
        }

        // Resolve template path.
        $template_file = OFP_PATH . 'public/templates/' . $this->routes[ $route ];

        if ( ! file_exists( $template_file ) ) {
            // Template not built yet (stub phase) — show a friendly placeholder.
            $this->render_placeholder( $route );
            exit;
        }

        // Load the template. The template has access to $route for any
        // conditional logic it needs without re-reading query vars.
        include $template_file;
        exit;
    }

    /**
     * Handle logout by detecting ?ofp_logout=1 on any page.
     * Redirects to /login after clearing the session.
     *
     * @return void
     */
    public function handle_logout(): void {
        if (
            isset( $_GET['ofp_logout'] ) &&
            $_GET['ofp_logout'] === '1' &&
            isset( $_GET['_wpnonce'] ) &&
            wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
                'ofp_logout'
            )
        ) {
            OFP_Auth::logout();
            wp_safe_redirect( home_url( '/login?logged_out=1' ) );
            exit;
        }
    }

    /**
     * If a client is already logged in and tries to visit /login or /signup,
     * redirect them straight to /dashboard — avoids confusion.
     *
     * @return void
     */
    public function redirect_authenticated_away_from_auth_pages(): void {
        $route = get_query_var( 'ofp_route', '' );

        if (
            in_array( $route, [ 'login', 'signup' ], true ) &&
            OFP_Auth::current_client()
        ) {
            wp_safe_redirect( home_url( '/dashboard' ) );
            exit;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER: LOGOUT URL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a nonce-protected logout URL.
     * Use this in nav templates: <a href="<?php echo OFP_Client_Portal::logout_url(); ?>">Logout</a>
     *
     * @return string  Full logout URL with nonce.
     */
    public static function logout_url(): string {
        return add_query_arg(
            [
                'ofp_logout' => '1',
                '_wpnonce'   => wp_create_nonce( 'ofp_logout' ),
            ],
            home_url( '/dashboard' )
        );
    }

    /**
     * Generate a clean URL for any registered OFP route.
     * Example: OFP_Client_Portal::route_url('my-listing') → https://site.com/my-listing
     *
     * @param  string $slug  Route slug (key in $routes).
     * @return string        Full home URL for the route.
     */
    public static function route_url( string $slug ): string {
        return home_url( '/' . ltrim( $slug, '/' ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PLACEHOLDER (shown while templates are being built phase by phase)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Render a minimal placeholder page for routes whose templates haven't
     * been built yet. This means the plugin stays functional during phased
     * development — no white screens or fatal errors on any route.
     *
     * @param  string $route  The requested route slug.
     * @return void
     */
    private function render_placeholder( string $route ): void {
        $title = ucwords( str_replace( '-', ' ', $route ) );
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html( $title ); ?> — OFast Pipeline</title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                    background: #f0f4f8;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    color: #333;
                }
                .card {
                    background: #fff;
                    border-radius: 12px;
                    padding: 48px 40px;
                    max-width: 480px;
                    width: 100%;
                    text-align: center;
                    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
                }
                .badge {
                    display: inline-block;
                    background: #e8f4fd;
                    color: #1a73e8;
                    font-size: 12px;
                    font-weight: 600;
                    letter-spacing: 0.05em;
                    text-transform: uppercase;
                    padding: 4px 12px;
                    border-radius: 100px;
                    margin-bottom: 20px;
                }
                h1 {
                    font-size: 24px;
                    font-weight: 700;
                    margin-bottom: 12px;
                    color: #111;
                }
                p { font-size: 15px; color: #666; line-height: 1.6; }
                .route {
                    margin-top: 20px;
                    font-size: 13px;
                    background: #f8f9fa;
                    border-radius: 6px;
                    padding: 8px 16px;
                    color: #888;
                    font-family: monospace;
                }
                .back {
                    margin-top: 28px;
                    display: inline-block;
                    color: #1a73e8;
                    text-decoration: none;
                    font-size: 14px;
                }
                .back:hover { text-decoration: underline; }
            </style>
        </head>
        <body>
            <div class="card">
                <span class="badge">Coming Soon</span>
                <h1><?php echo esc_html( $title ); ?></h1>
                <p>This section of the OFast Pipeline client portal is being built as part of the phased rollout.</p>
                <div class="route">/<?php echo esc_html( $route ); ?></div>
                <a class="back" href="<?php echo esc_url( home_url( '/dashboard' ) ); ?>">
                    ← Back to Dashboard
                </a>
            </div>
        </body>
        </html>
        <?php
    }
}
