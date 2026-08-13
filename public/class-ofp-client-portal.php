<?php
/**
 * OFP_Client_Portal
 *
 * Registers and handles all front-end client-facing routes using WordPress's
 * native rewrite rule system. No routing plugin, no page builder dependency.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Client_Portal {

    private array $routes = [
        'login'             => 'login.php',
        'signup'            => 'signup.php',
        'forgot-password'   => 'forgot-password.php',
        'reset-password'    => 'reset-password.php',

        'dashboard'         => 'dashboard.php',
        'leads'             => 'leads.php',
        'pipeline-settings' => 'pipeline-settings.php',
        'api-settings'      => 'api-settings.php',
        'communications'    => 'communications.php',
        'credits'           => 'credits.php',
        'reports'           => 'reports.php',
        'account'           => 'account.php',
        'my-listing'        => 'my-listing.php',
        'properties'        => 'properties.php',
        'listing-billing'   => 'listing-billing.php',
        'notifications'     => 'notifications.php',
        'notification-settings' => 'notification-settings.php',
        'funding'           => 'funding.php',
        'pricing'           => 'pricing.php',
    ];

    private array $public_routes = [
        'login',
        'signup',
        'forgot-password',
        'reset-password',
    ];

    public function __construct() {
        add_action( 'init',               [ $this, 'register_rewrite_rules' ] );
        add_filter( 'query_vars',         [ $this, 'register_query_vars' ] );
        add_action( 'template_redirect',  [ $this, 'handle_routes' ] );
        add_action( 'init',               [ $this, 'handle_logout' ] );
        add_action( 'template_redirect',  [ $this, 'redirect_authenticated_away_from_auth_pages' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_ofp_fetch_leads',        [ $this, 'ajax_fetch_leads' ] );
        add_action( 'wp_ajax_nopriv_ofp_fetch_leads', [ $this, 'ajax_fetch_leads' ] );
    }

    public function enqueue_assets(): void {
        $route = get_query_var( 'ofp_route', '' );
        if ( empty( $route ) || ! array_key_exists( $route, $this->routes ) ) return;

        wp_enqueue_script(
            'ofp-client-portal',
            OFP_URL . 'assets/js/client-portal.js',
            [],
            OFP_VERSION,
            true
        );

        wp_localize_script( 'ofp-client-portal', 'ofpClientData', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ofp_client_ajax' ),
        ] );
    }

    public function ajax_fetch_leads(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client || ! OFP_Subscription::has_active( 'crm', $client->id ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $filter_status = sanitize_text_field( $_POST['status'] ?? '' );
        global $wpdb;
        $p = $wpdb->prefix;
        $where = 'l.client_id = %d';
        $args = [ $client->id ];
        if ( $filter_status ) {
            $where .= ' AND l.status = %s';
            $args[] = $filter_status;
        }

        $leads = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.* FROM {$p}ofp_leads l WHERE {$where} ORDER BY l.created_at DESC LIMIT 20",
            ...$args
        ) );

        ob_start();
        if ( empty( $leads ) ) {
            ?>
            <tr><td colspan="7" style="text-align:center;padding:48px;">No leads found.</td></tr>
            <?php
        } else {
            foreach ( $leads as $lead ) {
                ?>
                <tr>
                    <td><?php echo esc_html( $lead->name ?: '—' ); ?></td>
                    <td><strong><?php echo esc_html( $lead->phone ); ?></strong></td>
                    <td><?php echo esc_html( $lead->email ?: '—' ); ?></td>
                    <td><?php echo esc_html( ucfirst( $lead->status ) ); ?></td>
                    <td><?php echo esc_html( $lead->ivr_response ?: '—' ); ?></td>
                    <td><?php echo esc_html( gmdate( 'M j, Y', strtotime( $lead->created_at ) ) ); ?></td>
                    <td>—</td>
                </tr>
                <?php
            }
        }
        $html = ob_get_clean();
        wp_send_json_success( [ 'html' => $html ] );
    }

    public function register_rewrite_rules(): void {
        add_rewrite_rule(
            '^agent/([^/]+)/?$',
            'index.php?ofp_agent_slug=$matches[1]',
            'top'
        );

        foreach ( array_keys( $this->routes ) as $slug ) {
            add_rewrite_rule(
                '^' . preg_quote( $slug, '/' ) . '/?$',
                'index.php?ofp_route=' . $slug,
                'top'
            );
        }
    }

    public function register_query_vars( array $vars ): array {
        $vars[] = 'ofp_route';
        $vars[] = 'ofp_agent_slug';
        return $vars;
    }

    public function handle_routes(): void {
        $route = get_query_var( 'ofp_route', '' );
        if ( empty( $route ) || ! array_key_exists( $route, $this->routes ) ) return;

        if ( ! in_array( $route, $this->public_routes, true ) ) {
            OFP_Auth::require_client_login();
            $client = OFP_Auth::current_client();
            if ( ! $client ) return;

            if ( in_array( $route, [ 'properties', 'listing-billing' ], true ) && ! OFP_Subscription::has_active( 'listing', $client->id ) ) {
                wp_safe_redirect( home_url( '/dashboard' ) );
                exit;
            }
        }

        $template = OFP_PATH . 'public/templates/' . $this->routes[ $route ];
        if ( file_exists( $template ) ) {
            require $template;
            exit;
        }

        wp_die( esc_html__( 'The requested client portal page is unavailable.', 'ofast-pipeline' ) );
    }

    public function handle_logout(): void {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['ofp_logout'] ) ) return;
        $token = OFP_Auth::get_session_token();
        if ( $token ) OFP_Auth::logout( $token );
        wp_safe_redirect( home_url( '/login' ) );
        exit;
    }

    public function redirect_authenticated_away_from_auth_pages(): void {
        $route = get_query_var( 'ofp_route', '' );
        if ( ! in_array( $route, [ 'login', 'signup', 'forgot-password', 'reset-password' ], true ) ) return;
        if ( OFP_Auth::current_client() ) {
            wp_safe_redirect( home_url( '/dashboard' ) );
            exit;
        }
    }
}
