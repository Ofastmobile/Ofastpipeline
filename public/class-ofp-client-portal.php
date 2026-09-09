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
        'team' => 'team.php',
        'team-invite' => 'team-invite.php',
        'message-templates' => 'message-templates.php',
    ];

    private array $public_routes = [ 'login', 'signup', 'forgot-password', 'reset-password', 'team-invite' ];

    public function __construct() {
        add_action( 'init', [ $this, 'register_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_routes' ] );
        add_action( 'init', [ $this, 'handle_logout' ] );
        add_action( 'template_redirect', [ $this, 'redirect_authenticated_away_from_auth_pages' ] );
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
    }

    public function enqueue_assets(): void {
        $route = get_query_var( 'ofp_route', '' );
        if ( empty( $route ) || ! array_key_exists( $route, $this->routes ) ) return;

        $deps = [];
        if ( in_array( $route, [ 'dashboard', 'leads', 'reports' ], true ) ) {
            wp_enqueue_script( 'ofp-chartjs', OFP_URL . 'assets/js/chart.min.js', [], OFP_VERSION, true );
            $deps[] = 'ofp-chartjs';
        }

        wp_enqueue_script( 'ofp-client-portal', OFP_URL . 'assets/js/client-portal.js', $deps, OFP_VERSION, true );
        wp_localize_script( 'ofp-client-portal', 'ofpClientData', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'ofp_client_ajax' ),
        ] );
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
        ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $title ); ?> — OFast Pipeline</title><style>*{box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f0f4f8;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#333}.card{background:#fff;border-radius:12px;padding:48px 40px;max-width:480px;width:100%;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08)}.badge{display:inline-block;background:#e8f4fd;color:#1a73e8;font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;padding:4px 12px;border-radius:100px;margin-bottom:20px}h1{font-size:24px;font-weight:700;margin-bottom:12px;color:#111}p{font-size:15px;color:#666;line-height:1.6}.route{margin-top:20px;font-size:13px;background:#f8f9fa;border-radius:6px;padding:8px 16px;color:#888;font-family:monospace}.back{margin-top:28px;display:inline-block;color:#1a73e8;text-decoration:none;font-size:14px}</style></head><body><div class="card"><span class="badge">Coming Soon</span><h1><?php echo esc_html( $title ); ?></h1><p>This section of the OFast Pipeline client portal is being built as part of the phased rollout.</p><div class="route">/<?php echo esc_html( $route ); ?></div><a class="back" href="<?php echo esc_url( home_url( '/dashboard' ) ); ?>">← Back to Dashboard</a></div></body></html><?php
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
        if ( ! $client ) wp_send_json_error( 'Unauthorized' );

        global $wpdb;
        $p = $wpdb->prefix;

        $id = (int) ( $_POST['template_id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid template.' );

        $deleted = $wpdb->delete(
            "{$p}ofp_client_templates",
            [ 'id' => $id, 'client_id' => $client->id ],
            [ '%d', '%d' ]
        );

        if ( $deleted ) {
            wp_send_json_success( 'Template deleted.' );
        } else {
            wp_send_json_error( 'Template not found or already deleted.' );
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
            if ( ! OFP_Credits::has_sufficient_balance( $client->id, $cost ) ) {
                wp_send_json_error( 'Insufficient credit balance to send SMS.' );
            }
            
            $sms = new OFP_SMS( 'smartsms', $client->id );
            $result = $sms->send( $lead->phone, $body );
            
            $success = $result['success'] ?? false;
            $error   = $result['error'] ?? '';

            if ( $success ) {
                OFP_Credits::deduct( $client->id, $cost, "Manual SMS to {$lead->phone}" );
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
}

