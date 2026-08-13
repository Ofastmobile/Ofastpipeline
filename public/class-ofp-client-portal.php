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
        'communications' => 'communications.php',
        'credits' => 'credits.php',
        'reports' => 'reports.php',
        'account' => 'account.php',
        'my-listing' => 'my-listing.php',
        'properties' => 'properties.php',
        'listing-billing' => 'listing-billing.php',
        'notifications' => 'notifications.php',
        'notification-settings' => 'notification-settings.php',
        'funding' => 'funding.php',
        'pricing' => 'pricing.php',
    ];

    private array $public_routes = [ 'login', 'signup', 'forgot-password', 'reset-password' ];

    public function __construct() {
        add_action( 'init', [ $this, 'register_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_routes' ] );
        add_action( 'init', [ $this, 'handle_logout' ] );
        add_action( 'template_redirect', [ $this, 'redirect_authenticated_away_from_auth_pages' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_ofp_fetch_leads', [ $this, 'ajax_fetch_leads' ] );
        add_action( 'wp_ajax_nopriv_ofp_fetch_leads', [ $this, 'ajax_fetch_leads' ] );
    }

    public function enqueue_assets(): void {
        $route = get_query_var( 'ofp_route', '' );
        if ( empty( $route ) || ! array_key_exists( $route, $this->routes ) ) return;
        wp_enqueue_script( 'ofp-client-portal', OFP_URL . 'assets/js/client-portal.js', [], OFP_VERSION, true );
        wp_localize_script( 'ofp-client-portal', 'ofpClientData', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'ofp_client_ajax' ),
        ] );
    }

    public function ajax_fetch_leads(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client || ! OFP_Subscription::has_active( 'crm', $client->id ) ) wp_send_json_error( 'Unauthorized' );

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
            ?><tr><td colspan="7" style="text-align:center; padding:48px;"><div class="ofp-empty" style="padding:0;"><div class="ofp-empty-icon" style="font-size:24px;margin-bottom:12px;">📭</div><h3 style="font-size:16px;font-weight:600;margin:0 0 4px;color:var(--text-main);">No leads found</h3><p style="margin:0;color:var(--text-muted);font-size:13px;">Leads matching this filter will appear here.</p></div></td></tr><?php
        } else {
            foreach ( $leads as $lead ) {
                ?>
                <tr>
                    <td><?php echo esc_html( $lead->name ?: '—' ); ?></td>
                    <td><strong><?php echo esc_html( $lead->phone ); ?></strong></td>
                    <td><?php echo esc_html( $lead->email ?: '—' ); ?></td>
                    <td><?php echo $status_badges[ $lead->status ] ?? esc_html( $lead->status ); ?></td>
                    <td style="color:var(--text-muted);"> <?php echo $lead->ivr_response ? esc_html( 'Pressed ' . $lead->ivr_response ) : '—'; ?></td>
                    <td style="white-space:nowrap;font-size:12px;color:var(--text-muted);"><?php echo esc_html( gmdate( 'M j, Y', strtotime( $lead->created_at ) ) ); ?></td>
                    <td><?php if ( $lead->status !== 'converted' ) : ?><form method="POST" action="" style="display:inline;"><?php wp_nonce_field( 'ofp_leads_' . $client->id, 'ofp_leads_nonce' ); ?><input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead->id ); ?>"><select name="new_status" onchange="this.form.submit()" class="ofp-select"><?php foreach ( array_keys( $status_badges ) as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>" <?php selected( $lead->status, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option><?php endforeach; ?></select></form><?php else : ?><span style="font-size:12px;color:#9ca3af;">Closed</span><?php endif; ?></td>
                </tr>
                <?php
            }
        }
        wp_send_json_success( [ 'html' => ob_get_clean() ] );
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

        if ( ! in_array( $route, $this->public_routes, true ) ) {
            OFP_Auth::require_client_login();
            $client = OFP_Auth::current_client();
            if ( $client ) OFP_Auth::require_active_subscription( $client );
        }

        if ( in_array( $route, [ 'properties', 'listing-billing' ], true ) ) {
            $client = OFP_Auth::current_client();
            if ( ! $client || ! OFP_Subscription::has_active( 'listing', $client->id ) ) {
                wp_safe_redirect( home_url( '/dashboard' ) );
                exit;
            }
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
        ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $title ); ?> — OFast Pipeline</title><style>*{box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f4f8;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#333}.card{background:#fff;border-radius:12px;padding:48px 40px;max-width:480px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08)}.badge{display:inline-block;background:#e8f4fd;color:#1a73e8;font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;padding:4px 12px;border-radius:100px;margin-bottom:20px}h1{font-size:24px;font-weight:700;margin-bottom:12px;color:#111}p{font-size:15px;color:#666;line-height:1.6}.route{margin-top:20px;font-size:13px;background:#f8f9fa;border-radius:6px;padding:8px 16px;color:#888;font-family:monospace}.back{margin-top:28px;display:inline-block;color:#1a73e8;text-decoration:none;font-size:14px}</style></head><body><div class="card"><span class="badge">Coming Soon</span><h1><?php echo esc_html( $title ); ?></h1><p>This section of the OFast Pipeline client portal is being built as part of the phased rollout.</p><div class="route">/<?php echo esc_html( $route ); ?></div><a class="back" href="<?php echo esc_url( home_url( '/dashboard' ) ); ?>">← Back to Dashboard</a></div></body></html><?php
    }
}
