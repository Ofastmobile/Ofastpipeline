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
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Client_Portal {

    private array $routes = [
        'login' => 'login.php',
        'signup' => 'signup.php',
        'forgot-password' => 'forgot-password.php',
        'reset-password' => 'reset-password.php',
        'dashboard' => 'dashboard.php',
        'leads' => 'leads.php',
        'pipeline-settings' => 'pipeline-settings.php',
        'api-settings' => 'api-settings.php',
        'communications' => 'message-templates.php',
        'credits' => 'credits.php',
        'reports' => 'reports.php',
        'account' => 'account.php',
        'my-listing' => 'my-listing.php',
        'properties' => 'properties.php',
        'inspections' => 'inspections.php',
        'listing-billing' => 'credits.php',
        'notifications' => 'notifications.php',
        'notification-settings' => 'notification-settings.php',
        'funding' => 'funding.php',
        'pricing' => 'pricing.php',
        'team' => 'team.php',
        'team-invite' => 'team-invite.php',
        'message-templates' => 'message-templates.php',
        'tenants' => 'tenants.php',
        'rent' => 'tenants.php',
        'property-sales' => 'property-sales.php',
        'property-purchases' => 'property-purchases.php',
        'property-payments' => 'property-payments.php',
    ];

    private array $public_routes = [ 'login', 'signup', 'forgot-password', 'reset-password', 'team-invite' ];

    public function __construct() {
        add_action( 'init', [ $this, 'register_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_routes' ] );
        add_action( 'init', [ $this, 'handle_logout' ] );
        add_action( 'template_redirect', [ $this, 'redirect_authenticated_away_from_auth_pages' ] );
        add_action( 'wp_head', [ $this, 'render_theme_head_script' ], 0 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_ofp_fetch_leads', [ $this, 'ajax_fetch_leads' ] );
        add_action( 'wp_ajax_nopriv_ofp_fetch_leads', [ $this, 'ajax_fetch_leads' ] );

        // Dashboard chart data
        add_action( 'wp_ajax_ofp_dashboard_chart_data', [ $this, 'ajax_dashboard_chart_data' ] );
        add_action( 'wp_ajax_ofp_reports_chart_data', [ $this, 'ajax_reports_chart_data' ] );
        
        // Team Member AJAX
        add_action( 'wp_ajax_ofp_invite_team_member', [ $this, 'ajax_invite_team_member' ] );
        add_action( 'wp_ajax_ofp_delete_team_member', [ $this, 'ajax_delete_team_member' ] );
        add_action( 'wp_ajax_ofp_update_team_member', [ $this, 'ajax_update_team_member' ] );

        // Template CRUD AJAX
        add_action( 'wp_ajax_ofp_save_template',   [ $this, 'ajax_save_template' ] );
        add_action( 'wp_ajax_ofp_delete_template', [ $this, 'ajax_delete_template' ] );
        add_action( 'wp_ajax_ofp_fetch_templates', [ $this, 'ajax_fetch_templates' ] );

        // Manual Messaging
        add_action( 'wp_ajax_ofp_send_manual_message', [ $this, 'ajax_send_manual_message' ] );
        add_action( 'wp_ajax_ofp_send_client_broadcast', [ $this, 'ajax_send_client_broadcast' ] );

        // Email Branding & Layout Customizer
        add_action( 'wp_ajax_ofp_save_email_branding', [ $this, 'ajax_save_email_branding' ] );
        add_action( 'wp_ajax_ofp_send_test_email',     [ $this, 'ajax_send_test_email' ] );
    }

    public function enqueue_assets(): void {
        $route      = get_query_var( 'ofp_route', '' );
        $agent_slug = get_query_var( 'ofp_agent_slug', '' );

        // ── Agent profile (public /agent/[slug]) ─────────────────────────
        if ( ! empty( $agent_slug ) ) {
            wp_enqueue_style(
                'ofp-agent-profile',
                OFP_URL . 'assets/css/agent-profile.css',
                [],
                OFP_VERSION
            );
            return;
        }

        // Check if this is a portal page via query vars, explicit route, or path
        $is_portal = $this->is_portal_page();
        if ( ! $is_portal && ( empty( $route ) || ! array_key_exists( $route, $this->routes ) ) ) {
            return;
        }

        if ( empty( $route ) ) {
            $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
            $route = explode( '/', $path )[0] ?? '';
        }

        // ── Auth pages (login / signup / forgot-password / reset-password / team-invite) ──
        $auth_routes = [ 'login', 'signup', 'forgot-password', 'reset-password', 'team-invite' ];
        if ( in_array( $route, $auth_routes, true ) ) {
            wp_enqueue_style(
                'ofp-auth',
                OFP_URL . 'assets/css/auth.css',
                [],
                OFP_VERSION
            );

            // Signup needs its own JS with the base-domain data localised in
            if ( $route === 'signup' ) {
                $base_domain = get_option( 'ofp_crm_base_domain' )
                    ?: ( isset( $_SERVER['HTTP_HOST'] )
                        ? preg_replace( '/:\d+$/', '', $_SERVER['HTTP_HOST'] )
                        : 'ofastpipeline.com' );

                wp_enqueue_script(
                    'ofp-signup',
                    OFP_URL . 'assets/js/signup.js',
                    [],
                    OFP_VERSION,
                    true
                );
                wp_localize_script( 'ofp-signup', 'ofpSignupData', [
                    'baseDomain' => $base_domain,
                ] );
            }
            return;
        }

        // ── Authenticated portal pages ────────────────────────────────────
        wp_enqueue_style(
            'ofp-client-portal',
            OFP_URL . 'assets/css/client-portal.css',
            [],
            OFP_VERSION
        );

        $js_deps = [];
        if ( in_array( $route, [ 'dashboard', 'leads', 'reports' ], true ) ) {
            wp_enqueue_script(
                'ofp-chartjs',
                OFP_URL . 'assets/js/chart.min.js',
                [],
                OFP_VERSION,
                true
            );
            $js_deps[] = 'ofp-chartjs';
        }

        wp_enqueue_script(
            'ofp-client-portal',
            OFP_URL . 'assets/js/client-portal.js',
            $js_deps,
            OFP_VERSION,
            true
        );
        wp_localize_script( 'ofp-client-portal', 'ofpClientData', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ofp_client_ajax' ),
        ] );
    }


    /**
     * Determines whether the current request is for a client portal page.
     */
    public function is_portal_page(): bool {
        $route = get_query_var( 'ofp_route', '' );
        if ( ! empty( $route ) && array_key_exists( $route, $this->routes ) ) {
            return true;
        }
        if ( '1' === (string) get_query_var( 'ofp_property_sales', '' ) || '1' === (string) get_query_var( 'ofp_property_purchases', '' ) ) {
            return true;
        }
        $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        $first_segment = explode( '/', $path )[0] ?? '';
        if ( array_key_exists( $first_segment, $this->routes ) || in_array( $first_segment, [ 'property-sales', 'property-purchases', 'property-payments', 'tenants' ], true ) ) {
            return true;
        }
        return false;
    }

    /**
     * Prevents FOUC (Flash of Unstyled Content) and ensures instant theme
     * rendering on client portal pages.
     *
     * NOTE: The dashboard uses its OWN localStorage key ('ofp_portal_theme')
     * so it is completely isolated from the public-site theme ('ofp_theme').
     * Changing theme on the homepage never affects the portal, and vice-versa.
     */
    public function render_theme_head_script(): void {
        if ( ! $this->is_portal_page() ) {
            return;
        }

        ?>
        <script id="ofp-theme-init">
        (function() {
            try {
                // Portal uses its own key — independent of the public-site key ('ofp_theme').
                var PORTAL_KEY = 'ofp_portal_theme';
                var theme = localStorage.getItem(PORTAL_KEY);
                // Default: dark. Portal defaults to dark unless explicitly toggled to light.
                var isDark = theme ? (theme === 'dark') : true;
                var html = document.documentElement;
                if (isDark) {
                    html.classList.add('dark');
                    html.classList.remove('light');
                    html.setAttribute('data-theme', 'dark');
                } else {
                    html.classList.remove('dark');
                    html.classList.add('light');
                    html.setAttribute('data-theme', 'light');
                }
            } catch(e) {}
        })();

        (function() {
            var isToggling = false;
            window.ofpToggleTheme = function() {
                if (isToggling) return;
                isToggling = true;
                setTimeout(function() { isToggling = false; }, 200);

                var PORTAL_KEY = 'ofp_portal_theme';
                var html = document.documentElement;
                var currentlyDark = html.classList.contains('dark') || html.getAttribute('data-theme') === 'dark';
                var makeDark = !currentlyDark;

                if (makeDark) {
                    html.classList.add('dark');
                    html.classList.remove('light');
                    html.setAttribute('data-theme', 'dark');
                    try { localStorage.setItem(PORTAL_KEY, 'dark'); } catch(e) {}
                } else {
                    html.classList.remove('dark');
                    html.classList.add('light');
                    html.setAttribute('data-theme', 'light');
                    try { localStorage.setItem(PORTAL_KEY, 'light'); } catch(e) {}
                }

                window.dispatchEvent(new CustomEvent('ofp-theme-changed', { detail: { dark: makeDark, theme: makeDark ? 'dark' : 'light' } }));
            };
            window.toggleDark = window.ofpToggleTheme;
        })();
        </script>
        <?php
    }


    public function ajax_fetch_leads(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client || ! OFP_Subscription::has_platform_access( $client->id ) ) wp_send_json_error( 'Unauthorized' );

        $filter_status = sanitize_text_field( $_POST['status'] ?? '' );
        global $wpdb;
        $p = $wpdb->prefix;
        $where = 'l.client_id = %d';
        $args = [ $client->id ];
        if ( $filter_status ) { $where .= ' AND l.status = %s'; $args[] = $filter_status; }
        $leads = $wpdb->get_results( $wpdb->prepare( "SELECT l.* FROM {$p}ofp_leads l WHERE {$where} ORDER BY l.created_at DESC LIMIT 20", ...$args ) );

        $status_badges = [
            'new' => '<span class="ofp-badge ofp-badge-blue">New</span>',
            'contacted' => '<span class="ofp-badge ofp-badge-yellow">Contacted</span>',
            'interested' => '<span class="ofp-badge ofp-badge-orange">Interested</span>',
            'converted' => '<span class="ofp-badge ofp-badge-green">✅ Converted</span>',
            'dead' => '<span class="ofp-badge ofp-badge-grey">Dead</span>',
        ];

        ob_start();
        if ( empty( $leads ) ) {
            ?><tr><td colspan="8" style="text-align:center; padding:48px;"><div class="ofp-empty" style="padding:0;"><div class="ofp-empty-icon" style="font-size:24px;margin-bottom:12px;">📭</div><h3 style="font-size:16px;font-weight:600;margin:0 0 4px;color:var(--text-main);">No leads found</h3><p style="margin:0;color:var(--text-muted);font-size:13px;">Leads matching this filter will appear here.</p></div></td></tr><?php
        } else {
            foreach ( $leads as $lead ) {
                ?>
                <tr>
                    <td><?php echo esc_html( $lead->name ?: '—' ); ?></td>
                    <td><strong><?php echo esc_html( $lead->phone ); ?></strong></td>
                    <td><?php echo esc_html( $lead->email ?: '—' ); ?></td>
                    <td><?php echo $status_badges[ $lead->status ] ?? esc_html( $lead->status ); ?></td>
                    <td style="color: var(--text-muted);"><?php echo $lead->ivr_response ? esc_html( 'Pressed ' . $lead->ivr_response ) : '—'; ?></td>
                    <td style="white-space:nowrap;font-size:12px;color:var(--text-muted);"><?php echo esc_html( gmdate( 'M j, Y', strtotime( $lead->created_at ) ) ); ?></td>
                    <td><?php if ( $lead->status !== 'converted' ) : ?><form method="POST" action="" style="display:inline;"><?php wp_nonce_field( 'ofp_leads_' . $client->id, 'ofp_leads_nonce' ); ?><input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead->id ); ?>"><select name="new_status" onchange="this.form.submit()" class="ofp-select"><?php foreach ( array_keys( $status_badges ) as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>" <?php selected( $lead->status, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option><?php endforeach; ?></select></form><?php else : ?><span style="font-size:12px;color:#9ca3af;">Closed</span><?php endif; ?></td>
                    <td style="text-align: center;"><button type="button" class="ofp-btn ofp-btn-sm ofp-btn-ghost" onclick="openMessageModal(<?php echo esc_attr( $lead->id ); ?>, '<?php echo esc_js( $lead->name ); ?>', '<?php echo esc_js( $lead->phone ); ?>', '<?php echo esc_js( $lead->email ); ?>')" title="Send Message"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 16px; height: 16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg></button></td>
                </tr>
                <?php
            }
        }
        wp_send_json_success( [ 'html' => ob_get_clean() ] );
    }

    /**
     * Returns real lead volume + conversion trend data for the dashboard charts.
     */
    public function ajax_dashboard_chart_data(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client || ! OFP_Subscription::has_platform_access( $client->id ) ) wp_send_json_error( 'Unauthorized' );

        wp_send_json_success( [
            'daily'   => OFP_Lead::get_daily_counts( $client->id, 7 ),
            'monthly' => OFP_Lead::get_monthly_conversion( $client->id, 6 ),
        ] );
    }

    /**
     * Returns real monthly lead volume + conversion counts for the
     * Reports page "Monthly Performance" chart (last 12 months).
     */
    public function ajax_reports_chart_data(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client || ! OFP_Subscription::has_platform_access( $client->id ) ) wp_send_json_error( 'Unauthorized' );

        wp_send_json_success( [
            'monthly' => OFP_Lead::get_monthly_stats( $client->id, 12 ),
        ] );
    }

    public function register_rewrite_rules(): void {
        add_rewrite_rule( '^agent/([^/]+)/?$', 'index.php?ofp_agent_slug=$matches[1]', 'top' );
        foreach ( array_keys( $this->routes ) as $slug ) {
            add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?ofp_route=' . $slug, 'top' );
        }
    }

    public function register_query_vars( array $vars ): array {
        $vars[] = 'ofp_route';
        $vars[] = 'ofp_agent_slug';
        return $vars;
    }

    public function handle_routes(): void {
        $agent_slug = get_query_var( 'ofp_agent_slug', '' );
        if ( ! empty( $agent_slug ) ) {
            $template_file = OFP_PATH . 'public/templates/agent-profile.php';
            if ( file_exists( $template_file ) ) { include $template_file; exit; }
        }

        $route = get_query_var( 'ofp_route', '' );
        if ( empty( $route ) || ! array_key_exists( $route, $this->routes ) ) return;

        // Redirect old /communications URL to the unified /message-templates page.
        if ( $route === 'communications' ) {
            wp_safe_redirect( home_url( '/message-templates' ), 301 );
            exit;
        }

        // Redirect old /listing-billing URL to the unified /credits page.
        if ( $route === 'listing-billing' ) {
            wp_safe_redirect( home_url( '/credits' ), 301 );
            exit;
        }

        if ( ! in_array( $route, $this->public_routes, true ) ) {
            OFP_Auth::require_client_login();
            $client = OFP_Auth::current_client();
            if ( $client ) OFP_Auth::require_active_subscription( $client );
        }


        $template_file = OFP_PATH . 'public/templates/' . $this->routes[ $route ];
        if ( ! file_exists( $template_file ) ) { $this->render_placeholder( $route ); exit; }
        include $template_file;
        exit;
    }

    public function handle_logout(): void {
        if ( isset( $_GET['ofp_logout'] ) && '1' === $_GET['ofp_logout'] && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ofp_logout' ) ) {
            OFP_Auth::logout();
            wp_safe_redirect( home_url( '/login?logged_out=1' ) );
            exit;
        }
    }

    public function redirect_authenticated_away_from_auth_pages(): void {
        $route = get_query_var( 'ofp_route', '' );
        if ( in_array( $route, [ 'login', 'signup' ], true ) && OFP_Auth::current_client() ) {
            wp_safe_redirect( home_url( '/dashboard' ) );
            exit;
        }
    }

    public static function logout_url(): string {
        return add_query_arg( [ 'ofp_logout' => '1', '_wpnonce' => wp_create_nonce( 'ofp_logout' ) ], home_url( '/dashboard' ) );
    }

    public static function route_url( string $slug ): string {
        return home_url( '/' . ltrim( $slug, '/' ) );
    }

    private function render_placeholder( string $route ): void {
        $title = ucwords( str_replace( '-', ' ', $route ) );
        ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( OFP_Brand::title( $title ) ); ?></title><style>*{box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f4f8;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#333}.card{background:#fff;border-radius:12px;padding:48px 40px;max-width:480px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08)}.badge{display:inline-block;background:#e8f4fd;color:#1a73e8;font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;padding:4px 12px;border-radius:100px;margin-bottom:20px}h1{font-size:24px;font-weight:700;margin-bottom:12px;color:#111}p{font-size:15px;color:#666;line-height:1.6}.route{margin-top:20px;font-size:13px;background:#f8f9fa;border-radius:6px;padding:8px 16px;color:#888;font-family:monospace}.back{margin-top:28px;display:inline-block;color:#1a73e8;text-decoration:none;font-size:14px}</style></head><body><div class="card"><span class="badge">Coming Soon</span><h1><?php echo esc_html( $title ); ?></h1><p>This section of the <?php echo esc_html( OFP_Brand::name() ); ?> client portal is being built as part of the phased rollout.</p><div class="route">/<?php echo esc_html( $route ); ?></div><a class="back" href="<?php echo esc_url( home_url( '/dashboard' ) ); ?>">← Back to Dashboard</a></div></body></html><?php
    }

    public function ajax_invite_team_member() {
        check_ajax_referer( 'ofp_client_action', 'nonce' );
        if ( ! OFP_Auth::is_logged_in() || OFP_Auth::get_user_type() !== 'client' ) {
            wp_send_json_error( 'Only the main client account can invite team members.' );
        }
        $client_id = OFP_Auth::current_client()->id;

        $name = sanitize_text_field( $_POST['name'] ?? '' );
        $email = sanitize_email( $_POST['email'] ?? '' );
        $phone = OFP_Security::sanitize_phone( $_POST['phone'] ?? '' );
        $role_name = sanitize_text_field( $_POST['role_name'] ?? 'Manager' );
        $permissions = isset( $_POST['permissions'] ) && is_array( $_POST['permissions'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['permissions'] ) ) : [];

        $result = OFP_Team_Member::invite( $client_id, [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'role_name' => $role_name,
            'permissions' => $permissions
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( 'Team member invited successfully.' );
    }

    public function ajax_delete_team_member() {
        check_ajax_referer( 'ofp_client_action', 'nonce' );
        if ( ! OFP_Auth::is_logged_in() || OFP_Auth::get_user_type() !== 'client' ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $client_id = OFP_Auth::current_client()->id;
        $team_member_id = (int) ( $_POST['team_member_id'] ?? 0 );

        OFP_Team_Member::delete( $team_member_id, $client_id );
        wp_send_json_success( 'Team member deleted.' );
    }

    public function ajax_update_team_member() {
        check_ajax_referer( 'ofp_client_action', 'nonce' );
        if ( ! OFP_Auth::is_logged_in() || OFP_Auth::get_user_type() !== 'client' ) {
            wp_send_json_error( 'Unauthorized' );
        }
        $client_id = OFP_Auth::current_client()->id;
        $team_member_id = (int) ( $_POST['team_member_id'] ?? 0 );
        $role_name = sanitize_text_field( $_POST['role_name'] ?? 'Manager' );
        $permissions = isset( $_POST['permissions'] ) && is_array( $_POST['permissions'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['permissions'] ) ) : [];

        OFP_Team_Member::update_permissions( $team_member_id, $client_id, $role_name, $permissions );
        wp_send_json_success( 'Team member updated.' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CLIENT TEMPLATE CRUD
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_save_template(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client ) wp_send_json_error( 'Unauthorized' );

        global $wpdb;
        $p = $wpdb->prefix;

        $id      = (int) ( $_POST['template_id'] ?? 0 );
        $type    = sanitize_text_field( $_POST['type'] ?? '' );
        $name    = sanitize_text_field( $_POST['name'] ?? '' );
        $subject = sanitize_text_field( $_POST['subject'] ?? '' );
        $body    = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );

        if ( ! in_array( $type, [ 'sms', 'email' ], true ) || empty( $name ) || empty( $body ) ) {
            wp_send_json_error( 'Missing required fields.' );
        }

        $now = current_time( 'mysql' );

        if ( $id > 0 ) {
            // Update existing — verify ownership
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}ofp_client_templates WHERE id = %d AND client_id = %d",
                $id, $client->id
            ) );
            if ( ! $existing ) wp_send_json_error( 'Template not found.' );

            $wpdb->update(
                "{$p}ofp_client_templates",
                [
                    'type'       => $type,
                    'name'       => $name,
                    'subject'    => $type === 'email' ? $subject : null,
                    'body'       => $body,
                    'updated_at' => $now,
                ],
                [ 'id' => $id ],
                [ '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            wp_send_json_success( [ 'message' => 'Template updated.', 'id' => $id ] );
        } else {
            // Create new
            $wpdb->insert(
                "{$p}ofp_client_templates",
                [
                    'client_id'  => $client->id,
                    'type'       => $type,
                    'name'       => $name,
                    'subject'    => $type === 'email' ? $subject : null,
                    'body'       => $body,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );

            wp_send_json_success( [ 'message' => 'Template created.', 'id' => $wpdb->insert_id ] );
        }
    }

    public function ajax_delete_template(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client ) {
            wp_send_json_error( 'Unauthorized. Please refresh and log in.' );
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $id = (int) ( $_POST['template_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid template ID.' );
        }

        // Verify template belongs to this client
        $template = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$p}ofp_client_templates WHERE id = %d AND client_id = %d LIMIT 1",
            $id,
            $client->id
        ) );

        if ( ! $template ) {
            wp_send_json_error( 'Template not found or does not belong to your account.' );
        }

        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}ofp_client_templates WHERE id = %d AND client_id = %d",
            $id,
            $client->id
        ) );

        if ( false !== $deleted && $deleted > 0 ) {
            wp_send_json_success( 'Template deleted successfully.' );
        } else {
            wp_send_json_error( 'Unable to delete template from database.' );
        }
    }

    public function ajax_fetch_templates(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client ) wp_send_json_error( 'Unauthorized' );

        global $wpdb;
        $templates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, type, name, subject, body FROM {$wpdb->prefix}ofp_client_templates WHERE client_id = %d ORDER BY name ASC",
                $client->id
            )
        );

        wp_send_json_success( $templates );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MANUAL MESSAGING
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_send_manual_message(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        
        $client = OFP_Auth::current_client();
        if ( ! $client ) wp_send_json_error( 'Unauthorized' );

        if ( ! OFP_Auth::has_permission( 'send_messages' ) ) {
            wp_send_json_error( 'You do not have permission to send messages.' );
        }

        $lead_id = (int) ( $_POST['lead_id'] ?? 0 );
        $channel = sanitize_text_field( $_POST['channel'] ?? '' );
        $subject = sanitize_text_field( $_POST['subject'] ?? '' );
        $body    = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );

        if ( ! $lead_id || ! in_array( $channel, [ 'sms', 'email' ], true ) || empty( $body ) ) {
            wp_send_json_error( 'Missing required fields.' );
        }

        if ( $channel === 'email' && empty( $subject ) ) {
            wp_send_json_error( 'Email requires a subject.' );
        }

        global $wpdb;
        $p = $wpdb->prefix;

        // Verify lead ownership
        $lead = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$p}ofp_leads WHERE id = %d AND client_id = %d LIMIT 1", $lead_id, $client->id )
        );

        if ( ! $lead ) {
            wp_send_json_error( 'Lead not found or does not belong to you.' );
        }

        // Replace placeholders
        $body = str_replace(
            [ '{{name}}', '{{phone}}', '{{email}}' ],
            [ $lead->name, $lead->phone, $lead->email ],
            $body
        );

        $success = false;
        $error   = '';
        $cost    = 0.00;

        if ( $channel === 'sms' ) {
            $cost = 1.00; // Deduct 1 credit for SMS
            if ( ! OFP_Credit::has_balance( $client->id, 'sms', $cost ) ) {
                wp_send_json_error( 'Insufficient credit balance to send SMS.' );
            }
            
            $sms = new OFP_SMS( 'smartsms', $client->id );
            $result = $sms->send( $lead->phone, $body );
            
            $success = $result['success'] ?? false;
            $error   = $result['error'] ?? '';

            if ( $success ) {
                OFP_Credit::deduct( $client->id, 'sms', $cost );
            }

        } elseif ( $channel === 'email' ) {
            if ( empty( $lead->email ) ) {
                wp_send_json_error( 'Lead does not have an email address.' );
            }
            // Send email (using Universal Admin Template wrapper)
            $success = OFP_Mailer::send_client_email( $lead->email, $subject, $body, $client->id );
            if ( ! $success ) {
                $error = 'Email failed to send. Check server configuration.';
            }
        }

        // Log Communication
        if ( $success ) {
            OFP_Communications_Log::log(
                $client->id,
                $lead_id,
                $channel,
                $channel === 'sms' ? $lead->phone : $lead->email,
                'outbound',
                $body,
                $cost
            );

            // Log Accountability Activity
            $current_user = OFP_Auth::current_user();
            $team_member_id = null;
            if ( $current_user && $current_user->is_team_member ) {
                // Determine team member ID by matching email
                $team_member_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}ofp_team_members WHERE client_id = %d AND email = %s",
                    $client->id, $current_user->email
                ) );
            }

            OFP_Activity_Log::log(
                $client->id,
                "Sent manual {$channel} to " . ( $lead->name ?: $lead->phone ),
                "To: " . ( $channel === 'sms' ? $lead->phone : $lead->email ) . "\n\n" . $body,
                $team_member_id
            );

            wp_send_json_success( 'Message sent successfully.' );
        } else {
            wp_send_json_error( 'Failed to send message: ' . $error );
        }
    }

    public function ajax_send_client_broadcast(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client ) wp_send_json_error( 'Unauthorized' );

        if ( ! OFP_Auth::has_permission( 'send_messages' ) ) {
            wp_send_json_error( 'You do not have permission to send messages.' );
        }

        $channel    = sanitize_text_field( $_POST['channel'] ?? '' );
        $recipients = sanitize_textarea_field( $_POST['recipients'] ?? '' );
        $subject    = sanitize_text_field( $_POST['subject'] ?? '' );
        $body       = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );

        if ( ! in_array( $channel, [ 'sms', 'email' ], true ) || empty( $recipients ) || empty( $body ) ) {
            wp_send_json_error( 'Missing required fields.' );
        }

        if ( $channel === 'email' && empty( $subject ) ) {
            wp_send_json_error( 'Email requires a subject.' );
        }

        $raw_list = array_filter( array_map( 'trim', explode( ',', $recipients ) ) );
        if ( empty( $raw_list ) ) {
            wp_send_json_error( 'Please enter at least one recipient.' );
        }

        $sent   = 0;
        $failed = 0;

        if ( $channel === 'sms' ) {
            $cost_per_sms = 1.00;
            $needed_cost  = count( $raw_list ) * $cost_per_sms;
            if ( ! OFP_Credit::has_balance( $client->id, 'sms', $needed_cost ) ) {
                wp_send_json_error( 'Insufficient SMS credit balance. Required: ₦' . number_format( $needed_cost, 2 ) );
            }

            $sms = new OFP_SMS( 'smartsms', $client->id );
            foreach ( $raw_list as $phone ) {
                $clean_phone = preg_replace( '/[^0-9+]/', '', $phone );
                if ( empty( $clean_phone ) ) {
                    $failed++;
                    continue;
                }
                $res = $sms->send( $clean_phone, $body );
                if ( ! empty( $res['success'] ) ) {
                    $sent++;
                    OFP_Credit::deduct( $client->id, 'sms', $cost_per_sms );
                    OFP_Communications_Log::log( $client->id, null, 'sms', $clean_phone, 'outbound', $body, $cost_per_sms );
                } else {
                    $failed++;
                }
            }
        } elseif ( $channel === 'email' ) {
            foreach ( $raw_list as $email ) {
                $clean_email = sanitize_email( $email );
                if ( ! is_email( $clean_email ) ) {
                    $failed++;
                    continue;
                }
                $ok = OFP_Mailer::send_client_email( $clean_email, $subject, $body, $client->id );
                if ( $ok ) {
                    $sent++;
                    OFP_Communications_Log::log( $client->id, null, 'email', $clean_email, 'outbound', $body, 0.00 );
                } else {
                    $failed++;
                }
            }
        }

        if ( $sent > 0 ) {
            $msg = sprintf( '%d broadcast message(s) sent successfully.', $sent );
            if ( $failed > 0 ) {
                $msg .= sprintf( ' (%d failed)', $failed );
            }
            wp_send_json_success( $msg );
        } else {
            wp_send_json_error( 'Failed to send messages. Please check recipient addresses/numbers.' );
        }
    }

    public function ajax_save_email_branding(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client ) {
            wp_send_json_error( 'Unauthorized.' );
        }
        if ( ! OFP_Subscription::allows_email_templates( (int) $client->id ) ) {
            wp_send_json_error( 'Email branding is available on the Silver and Gold plans.' );
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $brand_color   = sanitize_hex_color( $_POST['email_brand_color'] ?? '#0f172a' ) ?: '#0f172a';
        // The header name is always the registered business name and the logo
        // always comes from the account page, so neither is accepted here.
        $header_text   = '';
        $tagline       = sanitize_text_field( $_POST['email_header_tagline'] ?? '' );
        $footer_text   = wp_kses_post( wp_unslash( $_POST['email_footer_text'] ?? '' ) );
        $template_mode = sanitize_text_field( $_POST['email_template_mode'] ?? 'visual' );
        if ( ! in_array( $template_mode, [ 'visual', 'custom_html' ], true ) ) {
            $template_mode = 'visual';
        }
        $custom_html   = wp_unslash( $_POST['email_custom_html'] ?? '' );

        $data = [
            'email_brand_color'    => $brand_color,
            'email_header_text'    => $header_text,
            'email_header_tagline' => $tagline,
            'email_footer_text'    => $footer_text,
            'email_template_mode'  => $template_mode,
            'email_custom_html'    => $custom_html,
        ];

        $updated = $wpdb->update(
            "{$p}ofp_clients",
            $data,
            [ 'id' => $client->id ]
        );

        if ( false !== $updated ) {
            wp_send_json_success( 'Email branding & layout settings saved successfully!' );
        } else {
            wp_send_json_error( 'Failed to save settings. Please try again.' );
        }
    }

    public function ajax_send_test_email(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client || empty( $client->email ) ) {
            wp_send_json_error( 'Invalid or missing client email address.' );
        }

        $test_subject = 'Test: ' . ( $client->business_name ?: 'Your Company' ) . ' Branded Email Layout';
        $test_body = '
            <p>Hello <strong>' . esc_html( $client->owner_name ) . '</strong>,</p>
            <p>This is a live preview test of your branded email layout from <strong>' . esc_html( $client->business_name ?: OFP_Brand::name() ) . '</strong>.</p>
            <p>Whenever you send broadcasts, automated lead follow-ups, or lease notices, your recipients will see this exact design and your business branding.</p>
            <div style="background:#f8fafc;border-left:4px solid ' . esc_attr( $client->email_brand_color ?: '#0f172a' ) . ';padding:14px 18px;margin:20px 0;border-radius:4px;">
                <p style="margin:0;font-size:14px;color:#334155;">
                    &ldquo;Success in real estate is about speed, consistency, and professional presentation.&rdquo;
                </p>
            </div>
            <p>If you are happy with how this looks in your inbox, your email layout is fully configured and ready!</p>
            <p style="margin-top:28px;">Best regards,<br><strong>' . esc_html( $client->owner_name ) . '</strong><br>' . esc_html( $client->business_name ?: '' ) . '</p>
        ';

        $sent = OFP_Mailer::send_client_email( $client->email, $test_subject, $test_body, $client->id );

        if ( $sent ) {
            wp_send_json_success( 'Test email sent to ' . $client->email . '. Please check your inbox or spam folder!' );
        } else {
            wp_send_json_error( 'Failed to send test email. Please check your SMTP configuration.' );
        }
    }
}

