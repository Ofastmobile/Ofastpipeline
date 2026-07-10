<?php
/**
 * OFP_Admin_Menu
 *
 * Registers the OFast Pipeline menu inside wp-admin and gates every page
 * behind three independent security checks:
 *
 *  1. WordPress login check     — must be a logged-in WP user
 *  2. OFP admin table check     — WP user email must exist in wp_ofp_admins
 *  3. Role check per page       — Settings and Manage Admins are super_admin only
 *
 * MENU STRUCTURE:
 *  OFast Pipeline
 *  ├── Overview           (all admins)
 *  ├── Clients            (all admins)
 *  ├── Leads              (all admins)
 *  ├── Trigger Queue      (all admins)
 *  ├── Communications     (all admins)
 *  ├── Billing            (all admins)
 *  ├── Reports            (all admins)
 *  ├── Settings           (super_admin only)
 *  └── Manage Admins      (super_admin only)
 *
 * Depends on: OFP_Auth, all admin/views/*.php files.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Admin_Menu {

    public function __construct() {
        add_action( 'admin_menu',    [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Handle all admin form submissions via admin_post hooks.
        // This keeps form processing separate from view rendering.
        add_action( 'admin_post_ofp_add_client',     [ $this, 'handle_add_client' ] );
        add_action( 'admin_post_ofp_update_client',  [ $this, 'handle_update_client' ] );
        add_action( 'admin_post_ofp_delete_client',  [ $this, 'handle_delete_client' ] );
        add_action( 'admin_post_ofp_approve_client', [ $this, 'handle_approve_client' ] );
        add_action( 'admin_post_ofp_toggle_client',  [ $this, 'handle_toggle_client' ] );
        add_action( 'admin_post_ofp_add_admin',      [ $this, 'handle_add_admin' ] );
        add_action( 'admin_post_ofp_delete_admin',   [ $this, 'handle_delete_admin' ] );
        add_action( 'admin_post_ofp_save_settings',  [ $this, 'handle_save_settings' ] );
        add_action( 'admin_post_ofp_reset_password',   [ $this, 'handle_reset_password' ] );
        add_action( 'admin_post_ofp_generate_report', [ $this, 'handle_generate_report' ] );
        add_action( 'admin_post_ofp_edit_client',     [ $this, 'handle_edit_client' ] );
        add_action( 'admin_post_ofp_trash_client',    [ $this, 'handle_trash_client' ] );
        add_action( 'admin_post_ofp_restore_client',  [ $this, 'handle_restore_client' ] );
        add_action( 'admin_post_ofp_preview_client',  [ $this, 'handle_preview_client' ] );
        add_action( 'admin_post_ofp_topup_credit',    [ $this, 'handle_topup_credit' ] );
        add_action( 'admin_post_ofp_mark_subscription_paid', [ $this, 'handle_mark_subscription_paid' ] );
        add_action( 'admin_init', [ $this, 'handle_save_plan_pricing' ] );
        add_action( 'admin_init', [ $this, 'handle_save_listing_plans' ] );
        add_action( 'admin_init', [ $this, 'handle_save_company_bank' ] ); // Phase 17
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MENU REGISTRATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Register the full OFast Pipeline menu in wp-admin.
     * Only renders for users whose email exists in wp_ofp_admins.
     *
     * @return void
     */
    public function register_menu(): void {

        if ( ! OFP_Auth::is_admin_user() ) {
            return;
        }

        $is_super = OFP_Auth::is_super_admin();

        // ── Main menu item ────────────────────────────────────────────────────
        add_menu_page(
            'OFast Pipeline',
            'OFast Pipeline',
            'read',
            'ofp-overview',
            [ $this, 'render_overview' ],
            'dashicons-chart-line',
            3
        );

        // ── Submenus (all admins) ─────────────────────────────────────────────
        add_submenu_page( 'ofp-overview', 'Overview',        'Overview',        'read', 'ofp-overview',        [ $this, 'render_overview' ] );
        add_submenu_page( 'ofp-overview', 'Clients',         'Clients',         'read', 'ofp-clients',         [ $this, 'render_clients' ] );
        add_submenu_page( 'ofp-overview', 'Leads',           'Leads',           'read', 'ofp-leads',           [ $this, 'render_leads' ] );
        add_submenu_page( 'ofp-overview', 'Trigger Queue',   'Trigger Queue',   'read', 'ofp-triggers',        [ $this, 'render_triggers' ] );
        add_submenu_page( 'ofp-overview', 'Communications',  'Communications',  'read', 'ofp-communications',  [ $this, 'render_communications' ] );
        add_submenu_page( 'ofp-overview', 'Billing',         'Billing',         'read', 'ofp-billing',         [ $this, 'render_billing' ] );
        add_submenu_page( 'ofp-overview', 'Reports',         'Reports',         'read', 'ofp-reports',         [ $this, 'render_reports' ] );
        add_submenu_page( 'ofp-overview', 'Funding Requests', 'Funding Requests', 'read', 'ofp-funding-requests', [ $this, 'render_funding_requests' ] ); // Phase 17
        add_submenu_page( 'ofp-overview', 'Send Notification', 'Send Notification', 'manage_options', 'ofp-send-notification', [ $this, 'render_send_notification' ] ); // Phase 17b

        // ── Super admin only ──────────────────────────────────────────────────
        if ( $is_super ) {
            add_submenu_page( 'ofp-overview', 'Settings',      'Settings',      'read', 'ofp-settings',  [ $this, 'render_settings' ] );
            add_submenu_page( 'ofp-overview', 'Manage Admins', 'Manage Admins', 'read', 'ofp-admins',    [ $this, 'render_admins' ] );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ASSETS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enqueue admin CSS and JS — only on OFP pages.
     *
     * @param string $hook  Current admin page hook suffix.
     * @return void
     */
    public function enqueue_assets( string $hook ): void {

        $ofp_pages = [
            'toplevel_page_ofp-overview',
            'ofast-pipeline_page_ofp-clients',
            'ofast-pipeline_page_ofp-leads',
            'ofast-pipeline_page_ofp-triggers',
            'ofast-pipeline_page_ofp-communications',
            'ofast-pipeline_page_ofp-billing',
            'ofast-pipeline_page_ofp-reports',
            'ofast-pipeline_page_ofp-settings',
            'ofast-pipeline_page_ofp-admins',
            'ofast-pipeline_page_ofp-funding-requests',
            'ofast-pipeline_page_ofp-send-notification',
        ];

        if ( ! in_array( $hook, $ofp_pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'ofp-admin',
            OFP_URL . 'assets/css/admin.css',
            [],
            OFP_VERSION
        );

        wp_enqueue_script(
            'ofp-admin',
            OFP_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            OFP_VERSION,
            true
        );

        wp_localize_script( 'ofp-admin', 'ofpAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ofp_admin_nonce' ),
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VIEW RENDERERS
    // ─────────────────────────────────────────────────────────────────────────

    public function render_overview():       void { $this->load_view( 'overview' ); }
    public function render_clients():        void { $this->load_view( 'clients-list' ); }
    public function render_leads():          void { $this->load_view( 'leads-list' ); }
    public function render_triggers():       void { $this->load_view( 'triggers-list' ); }
    public function render_communications(): void { $this->load_view( 'communications-log' ); }
    public function render_billing():        void { $this->load_view( 'billing' ); }
    public function render_reports():        void { $this->load_view( 'reports' ); }

    public function render_settings(): void {
        if ( ! OFP_Auth::is_super_admin() ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'ofast-pipeline' ) );
        }
        $this->load_view( 'settings' );
    }

    public function render_admins(): void {
        if ( ! OFP_Auth::is_super_admin() ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'ofast-pipeline' ) );
        }
        $this->load_view( 'manage-admins' );
    }

    /**
     * Load a view file from admin/views/.
     * Passes $message (success/error feedback) into the view scope.
     *
     * @param string $view  View filename without .php extension.
     * @return void
     */
    private function load_view( string $view ): void {

        // Pass transient feedback messages into the view.
        $message = get_transient( 'ofp_admin_message_' . get_current_user_id() );
        if ( $message ) {
            delete_transient( 'ofp_admin_message_' . get_current_user_id() );
        }

        $file = OFP_PATH . 'admin/views/' . $view . '.php';

        if ( ! file_exists( $file ) ) {
            echo '<div class="wrap"><h1>OFast Pipeline</h1>';
            echo '<p>View <code>' . esc_html( $view ) . '</code> is being built.</p></div>';
            return;
        }

        include $file;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FORM HANDLERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Handle the "Add Client" form submission from admin/views/client-add.php.
     *
     * @return void
     */
    public function handle_add_client(): void {

        $this->require_admin_post( 'ofp_add_client' );

        $subscriptions = [];
        if ( ! empty( $_POST['want_crm'] ) )     $subscriptions[] = 'crm';
        if ( ! empty( $_POST['want_listing'] ) )  $subscriptions[] = 'listing';
        if ( empty( $subscriptions ) )            $subscriptions   = [ 'crm' ];

        $client_id = OFP_Client::create( [
            'business_name'     => sanitize_text_field( wp_unslash( $_POST['business_name']     ?? '' ) ),
            'owner_name'        => sanitize_text_field( wp_unslash( $_POST['owner_name']        ?? '' ) ),
            'email'             => sanitize_email(      wp_unslash( $_POST['email']             ?? '' ) ),
            'phone'             => sanitize_text_field( wp_unslash( $_POST['phone']             ?? '' ) ),
            'business_phone'    => sanitize_text_field( wp_unslash( $_POST['business_phone']    ?? '' ) ),
            'whatsapp_number'   => sanitize_text_field( wp_unslash( $_POST['whatsapp_number']   ?? '' ) ),
            'subdomain'         => sanitize_title(      wp_unslash( $_POST['subdomain']         ?? '' ) ),
            'business_category' => sanitize_text_field( wp_unslash( $_POST['business_category'] ?? '' ) ),
            'plan'              => sanitize_text_field( wp_unslash( $_POST['plan']              ?? 'starter' ) ),
            'subscriptions'     => $subscriptions,
            'onboarding_source' => 'manual',
        ] );

        if ( $client_id ) {
            $this->set_message( '✅ Client created successfully. Welcome email sent.', 'success' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients&added=' . $client_id ) );
        } else {
            $this->set_message( '❌ Failed to create client. Email may already be registered.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients&action=add' ) );
        }

        exit;
    }

    /**
     * Handle client status toggle (active / suspended / cancelled).
     *
     * @return void
     */
    public function handle_toggle_client(): void {

        $this->require_admin_post( 'ofp_toggle_client' );

        $client_id = (int) ( $_POST['client_id'] ?? 0 );
        $status    = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );

        $allowed = [ 'active', 'suspended', 'cancelled', 'grace' ];
        if ( ! $client_id || ! in_array( $status, $allowed, true ) ) {
            $this->set_message( '❌ Invalid request.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients' ) );
            exit;
        }

        OFP_Subscription::manual_toggle( $client_id, $status );
        $this->set_message( '✅ Client status updated to ' . $status . '.', 'success' );
        wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients&client_id=' . $client_id ) );
        exit;
    }

    /**
     * Handle approval of a pending_review self-serve signup.
     *
     * @return void
     */
    public function handle_approve_client(): void {

        $this->require_admin_post( 'ofp_approve_client' );

        $client_id = (int) ( $_POST['client_id'] ?? 0 );

        if ( OFP_Client::approve( $client_id ) ) {
            $this->set_message( '✅ Client approved. Notification email sent.', 'success' );
        } else {
            $this->set_message( '❌ Could not approve client — check their current status.', 'error' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients' ) );
        exit;
    }

    /**
     * Handle soft-deletion (cancel) of a client.
     *
     * @return void
     */
    public function handle_delete_client(): void {

        $this->require_super_admin_post( 'ofp_delete_client' );

        $client_id = (int) ( $_POST['client_id'] ?? 0 );
        OFP_Client::delete( $client_id );

        $this->set_message( '✅ Client cancelled.', 'success' );
        wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients' ) );
        exit;
    }

    /**
     * Handle client password reset from admin.
     *
     * @return void
     */
    public function handle_reset_password(): void {

        $this->require_admin_post( 'ofp_reset_password' );

        $client_id = (int) ( $_POST['client_id'] ?? 0 );

        if ( OFP_Client::reset_password( $client_id ) ) {
            $this->set_message( '✅ Password reset. New credentials emailed to client.', 'success' );
        } else {
            $this->set_message( '❌ Password reset failed.', 'error' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients&client_id=' . $client_id ) );
        exit;
    }

    /**
     * Handle adding a new co-admin (super_admin only).
     *
     * @return void
     */
    public function handle_add_admin(): void {

        $this->require_super_admin_post( 'ofp_add_admin' );

        global $wpdb;

        $name  = sanitize_text_field( wp_unslash( $_POST['name']  ?? '' ) );
        $email = sanitize_email(      wp_unslash( $_POST['email'] ?? '' ) );
        $pass  = wp_unslash( $_POST['password'] ?? '' );

        if ( ! $name || ! is_email( $email ) || strlen( $pass ) < 8 ) {
            $this->set_message( '❌ All fields required. Password must be at least 8 characters.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-admins' ) );
            exit;
        }

        // Check for duplicate.
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_admins WHERE email = %s LIMIT 1",
                $email
            )
        );

        if ( $exists ) {
            $this->set_message( '❌ That email is already registered as an admin.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-admins' ) );
            exit;
        }

        $wpdb->insert(
            $wpdb->prefix . 'ofp_admins',
            [
                'name'         => $name,
                'email'        => $email,
                'password'     => password_hash( $pass, PASSWORD_BCRYPT ),
                'role'         => 'co_admin',
                'is_protected' => 0,
                'created_at'   => current_time( 'mysql' ),
            ]
        );

        $this->set_message( '✅ Co-admin added. They must also have a WordPress user account with this email to access wp-admin.', 'success' );
        wp_safe_redirect( admin_url( 'admin.php?page=ofp-admins' ) );
        exit;
    }

    /**
     * Handle deletion of a co-admin (super_admin only).
     *
     * PROTECTION LAYERS:
     *  1. Only super_admin can reach this handler (require_super_admin_post check).
     *  2. is_protected = 1 rows are blocked at DB level here.
     *  3. The UI does not render a delete button for protected rows (third layer).
     *
     * @return void
     */
    public function handle_delete_admin(): void {

        $this->require_super_admin_post( 'ofp_delete_admin' );

        global $wpdb;

        $target_id = (int) ( $_POST['admin_id'] ?? 0 );

        if ( ! $target_id ) {
            $this->set_message( '❌ Invalid request.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-admins' ) );
            exit;
        }

        // Layer 2: Check is_protected at PHP level — never trust the UI alone.
        $target = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, is_protected, role FROM {$wpdb->prefix}ofp_admins WHERE id = %d LIMIT 1",
                $target_id
            )
        );

        if ( ! $target ) {
            $this->set_message( '❌ Admin not found.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-admins' ) );
            exit;
        }

        if ( $target->is_protected ) {
            $this->set_message( '❌ This account is protected and cannot be deleted.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-admins' ) );
            exit;
        }

        // Prevent deleting another super_admin (extra guard).
        if ( $target->role === 'super_admin' ) {
            $this->set_message( '❌ Super admin accounts cannot be deleted.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-admins' ) );
            exit;
        }

        $wpdb->delete( $wpdb->prefix . 'ofp_admins', [ 'id' => $target_id ] );
        $this->set_message( '✅ Admin removed.', 'success' );
        wp_safe_redirect( admin_url( 'admin.php?page=ofp-admins' ) );
        exit;
    }

    /**
     * Handles the Plans & Pricing form submission from Settings.
     *
     * @return void
     */
    public function handle_save_plan_pricing(): void {
        if ( empty( $_POST['ofp_save_plan_pricing'] ) ) {
            return;
        }

        if ( ! OFP_Auth::is_admin_user() ) {
            return;
        }

        if ( OFP_Auth::current_admin_role() !== 'super_admin' ) {
            wp_die( 'Access denied. Only the super admin can change pricing.' );
        }

        check_admin_referer( 'ofp_save_plan_pricing_action', 'ofp_plan_pricing_nonce' );

        $plan_prices = [];
        $setup_fees  = [];

        foreach ( OFP_Subscription::PLAN_KEYS as $plan ) {
            $plan_prices[ $plan ] = isset( $_POST[ "price_{$plan}" ] )
                ? (float) $_POST[ "price_{$plan}" ]
                : 0.0;

            $setup_fees[ $plan ] = isset( $_POST[ "setup_{$plan}" ] )
                ? (float) $_POST[ "setup_{$plan}" ]
                : 0.0;
        }

        $listing_fee = isset( $_POST['listing_fee'] ) ? (float) $_POST['listing_fee'] : 0.0;

        OFP_Subscription::save_pricing( $plan_prices, $setup_fees, $listing_fee );

        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Plan pricing updated.', 'ofast-pipeline' )
                . '</p></div>';
        } );
    }

    /**
     * Handles the Settings > Listing Plans form submission (Phase 14).
     *
     * @return void
     */
    public function handle_save_listing_plans(): void {
        if ( empty( $_POST['ofp_save_listing_plans'] ) ) {
            return;
        }

        if ( ! OFP_Auth::is_admin_user() ) {
            return;
        }

        if ( OFP_Auth::current_admin_role() !== 'super_admin' ) {
            wp_die( 'Access denied. Only the super admin can change pricing.' );
        }

        check_admin_referer( 'ofp_save_listing_plans_action', 'ofp_listing_plans_nonce' );

        $prices = [];
        $caps   = [];

        foreach ( OFP_Property_CPT::PLAN_KEYS as $plan ) {
            $prices[ $plan ] = isset( $_POST[ "listing_price_{$plan}" ] ) ? (float) $_POST[ "listing_price_{$plan}" ] : 0.0;
            $caps[ $plan ]   = isset( $_POST[ "listing_cap_{$plan}" ] )   ? (int) $_POST[ "listing_cap_{$plan}" ]   : 1;
        }

        OFP_Property_CPT::save_plans( $prices, $caps );

        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html__( 'Listing plans updated.', 'ofast-pipeline' )
                . '</p></div>';
        } );
    }

    /**
     * Handle saving plugin settings.
     *
     * @return void
     */
    public function handle_save_settings(): void {

        $this->require_super_admin_post( 'ofp_save_settings' );

        // ── SMTP mode toggle ─────────────────────────────────────────────
        $smtp_mode = sanitize_text_field( wp_unslash( $_POST['ofp_smtp_mode'] ?? 'default' ) );
        if ( ! in_array( $smtp_mode, [ 'default', 'custom' ], true ) ) {
            $smtp_mode = 'default';
        }

        $settings = [
            // Domain routing (Phase 16)
            'ofp_crm_base_domain' => sanitize_text_field( wp_unslash( $_POST['ofp_crm_base_domain'] ?? '' ) ),
            // Default pipeline messages
            'ofp_default_instant_sms' => sanitize_textarea_field( wp_unslash( $_POST['ofp_default_instant_sms'] ?? '' ) ),
            'ofp_default_followup_1'  => sanitize_textarea_field( wp_unslash( $_POST['ofp_default_followup_1']  ?? '' ) ),
            'ofp_default_followup_2'  => sanitize_textarea_field( wp_unslash( $_POST['ofp_default_followup_2']  ?? '' ) ),
            'ofp_default_followup_3'  => sanitize_textarea_field( wp_unslash( $_POST['ofp_default_followup_3']  ?? '' ) ),
            // Payment gateway
            'ofp_payment_provider'       => sanitize_text_field( wp_unslash( $_POST['ofp_payment_provider']       ?? 'monnify' ) ),
            'ofp_monnify_base_url'       => esc_url_raw(          wp_unslash( $_POST['ofp_monnify_base_url']       ?? '' ) ),
            'ofp_monnify_contract_code'  => sanitize_text_field( wp_unslash( $_POST['ofp_monnify_contract_code']  ?? '' ) ),
            // SMTP mode + fields
            'ofp_smtp_mode'        => $smtp_mode,
            'ofp_smtp_host'        => sanitize_text_field( wp_unslash( $_POST['ofp_smtp_host']        ?? '' ) ),
            'ofp_smtp_port'        => absint( $_POST['ofp_smtp_port'] ?? 587 ),
            'ofp_smtp_username'    => sanitize_text_field( wp_unslash( $_POST['ofp_smtp_username']    ?? '' ) ),
            'ofp_smtp_encryption'  => sanitize_text_field( wp_unslash( $_POST['ofp_smtp_encryption']  ?? 'tls' ) ),
            'ofp_smtp_from_email'  => sanitize_email(      wp_unslash( $_POST['ofp_smtp_from_email']  ?? '' ) ),
            'ofp_smtp_from_name'   => sanitize_text_field( wp_unslash( $_POST['ofp_smtp_from_name']   ?? '' ) ),
            // Africa's Talking
            'ofp_at_username'      => sanitize_text_field( wp_unslash( $_POST['ofp_at_username']      ?? '' ) ),
            'ofp_at_sender_id'     => sanitize_text_field( wp_unslash( $_POST['ofp_at_sender_id']     ?? '' ) ),
            'ofp_at_phone_number'  => sanitize_text_field( wp_unslash( $_POST['ofp_at_phone_number']  ?? '' ) ),
            // BulkSMS Nigeria
            'ofp_bsmsn_sender_id'  => sanitize_text_field( wp_unslash( $_POST['ofp_bsmsn_sender_id']  ?? '' ) ),
            // Cloudflare Turnstile
            'ofp_turnstile_site_key' => sanitize_text_field( wp_unslash( $_POST['ofp_turnstile_site_key'] ?? '' ) ),
            // Listing
            'ofp_listing_fee_monthly' => absint( $_POST['ofp_listing_fee_monthly'] ?? 7500 ),
        ];

        // Encrypted fields — only update if a new value was submitted.
        // Leaving blank preserves the existing encrypted value.
        // Human-readable labels used in the confirmation message.
        $encrypted_fields = [
            'ofp_smtp_password'           => [ 'raw' => $_POST['ofp_smtp_password']           ?? '', 'label' => 'SMTP Password' ],
            'ofp_at_api_key'              => [ 'raw' => $_POST['ofp_at_api_key']              ?? '', 'label' => 'AT API Key' ],
            'ofp_bsmsn_api_key'           => [ 'raw' => $_POST['ofp_bsmsn_api_key']           ?? '', 'label' => 'BulkSMS API Key' ],
            'ofp_monnify_api_key'         => [ 'raw' => $_POST['ofp_monnify_api_key']         ?? '', 'label' => 'Monnify API Key' ],
            'ofp_monnify_secret_key'      => [ 'raw' => $_POST['ofp_monnify_secret_key']      ?? '', 'label' => 'Monnify Secret Key' ],
            'ofp_paystack_secret_key'     => [ 'raw' => $_POST['ofp_paystack_secret_key']     ?? '', 'label' => 'Paystack Secret Key' ],
            'ofp_flutterwave_secret_key'  => [ 'raw' => $_POST['ofp_flutterwave_secret_key']  ?? '', 'label' => 'Flutterwave Secret Key' ],
            'ofp_flutterwave_secret_hash' => [ 'raw' => $_POST['ofp_flutterwave_secret_hash'] ?? '', 'label' => 'Flutterwave Hash' ],
            'ofp_turnstile_secret'        => [ 'raw' => $_POST['ofp_turnstile_secret']        ?? '', 'label' => 'Turnstile Secret' ],
        ];

        foreach ( $settings as $key => $value ) {
            update_option( $key, $value );
        }

        $updated_keys = [];

        foreach ( $encrypted_fields as $key => $field ) {
            $value = sanitize_text_field( wp_unslash( $field['raw'] ) );
            if ( ! empty( $value ) ) {
                update_option( $key, OFP_Security::encrypt( $value ) );
                $updated_keys[] = $field['label'];
            }
        }

        // Build a descriptive success message.
        $msg = '✅ Settings saved successfully.';
        if ( ! empty( $updated_keys ) ) {
            $msg .= ' Updated keys: ' . implode( ', ', $updated_keys ) . '.';
        }

        $this->set_message( $msg, 'success' );
        wp_safe_redirect( admin_url( 'admin.php?page=ofp-settings' ) );
        exit;
    }

    /**
     * Handle manual report generation from admin Reports page.
     *
     * @return void
     */
    public function handle_generate_report(): void {

        $this->require_admin_post( 'ofp_generate_report' );

        $client_id = (int) ( $_POST['client_id'] ?? 0 );
        $month     = (int) ( $_POST['month']     ?? gmdate( 'n' ) );
        $year      = (int) ( $_POST['year']      ?? gmdate( 'Y' ) );

        if ( ! $client_id ) {
            $this->set_message( '❌ Please select a client.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-reports' ) );
            exit;
        }

        $result = OFP_CSV::generate_monthly_report( $client_id, $month, $year );

        if ( $result ) {
            $this->set_message( '✅ Report generated and emailed to the client.', 'success' );
        } else {
            $this->set_message( '❌ Report generation failed. Check error logs.', 'error' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ofp-reports' ) );
        exit;
    }

    /**
     * Handle editing an existing client's details.
     * Allows correcting details on any client, including approved
     * self-serve signups that may have submitted incorrect information.
     *
     * @return void
     */
    public function handle_edit_client(): void {

        $this->require_admin_post( 'ofp_edit_client' );

        $client_id = (int) ( $_POST['client_id'] ?? 0 );
        if ( ! $client_id ) {
            $this->set_message( '❌ Invalid client.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients' ) );
            exit;
        }

        $new_email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

        // If email changed, check it doesn't collide with another client.
        $current = OFP_Client::get( $client_id );
        if ( $current && $new_email !== $current->email ) {
            global $wpdb;
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ofp_clients WHERE email = %s AND id != %d LIMIT 1",
                    $new_email, $client_id
                )
            );
            if ( $exists ) {
                $this->set_message( '❌ That email is already used by another client.', 'error' );
                wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients&client_id=' . $client_id . '&action=edit' ) );
                exit;
            }
        }

        $updated = OFP_Client::update( $client_id, [
            'business_name'     => sanitize_text_field( wp_unslash( $_POST['business_name']     ?? '' ) ),
            'owner_name'        => sanitize_text_field( wp_unslash( $_POST['owner_name']        ?? '' ) ),
            'email'             => $new_email,
            'phone'             => sanitize_text_field( wp_unslash( $_POST['phone']             ?? '' ) ),
            'business_phone'    => sanitize_text_field( wp_unslash( $_POST['business_phone']    ?? '' ) ),
            'whatsapp_number'   => sanitize_text_field( wp_unslash( $_POST['whatsapp_number']   ?? '' ) ),
            'subdomain'         => sanitize_title(      wp_unslash( $_POST['subdomain']         ?? '' ) ),
            'business_category' => sanitize_text_field( wp_unslash( $_POST['business_category'] ?? '' ) ),
            'plan'              => sanitize_text_field( wp_unslash( $_POST['plan']              ?? '' ) ),
        ] );

        $this->set_message(
            $updated ? '✅ Client details updated.' : '❌ Update failed.',
            $updated ? 'success' : 'error'
        );
        wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients&client_id=' . $client_id ) );
        exit;
    }

    /**
     * Handle moving a client to trash.
     * Cancels their pending automation and hides them from normal lists,
     * while preserving all data for 30 days before permanent purge.
     *
     * @return void
     */
    public function handle_trash_client(): void {

        $this->require_admin_post( 'ofp_trash_client' );

        $client_id = (int) ( $_POST['client_id'] ?? 0 );

        if ( OFP_Client::trash( $client_id ) ) {
            $this->set_message( '✅ Client moved to trash. It will be permanently deleted in 30 days.', 'success' );
        } else {
            $this->set_message( '❌ Could not move client to trash.', 'error' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients' ) );
        exit;
    }

    /**
     * Handle restoring a client from trash back to active.
     *
     * @return void
     */
    public function handle_restore_client(): void {

        $this->require_admin_post( 'ofp_restore_client' );

        $client_id = (int) ( $_POST['client_id'] ?? 0 );

        if ( OFP_Client::restore( $client_id ) ) {
            $this->set_message( '✅ Client restored.', 'success' );
        } else {
            $this->set_message( '❌ Could not restore client.', 'error' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients&filter=trash' ) );
        exit;
    }

    /**
     * Generate an admin preview token and redirect to the client's
     * frontend dashboard for debugging purposes.
     *
     * Available to any OFP admin (super_admin or co_admin) — useful for
     * both day-to-day support and technical debugging.
     *
     * @return void
     */
    public function handle_preview_client(): void {

        $this->require_admin_post( 'ofp_preview_client' );

        $client_id = (int) ( $_POST['client_id'] ?? 0 );
        $client    = OFP_Client::get( $client_id );

        if ( ! $client ) {
            $this->set_message( '❌ Client not found.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients' ) );
            exit;
        }

        $token = OFP_Auth::generate_admin_preview_token( $client_id );
        $url   = add_query_arg( 'admin_preview', $token, home_url( '/login' ) );

        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Handle manual credit top-up from the admin client detail page.
     *
     * Called after Olabode has confirmed a client's manual payment.
     * Immediately loads the specified NGN amount into the client's SMS
     * or voice credit balance via OFP_Credit::topup().
     *
     * Under the reseller model, this is the primary credit-loading mechanism.
     * Self-serve top-up via payment gateway is a future phase.
     *
     * @return void
     */
    public function handle_topup_credit(): void {

        $this->require_admin_post( 'ofp_topup_credit' );

        $client_id = (int) ( $_POST['client_id'] ?? 0 );
        $channel   = sanitize_text_field( wp_unslash( $_POST['channel']   ?? '' ) );
        $amount    = (float) ( $_POST['amount'] ?? 0 );
        $reference = sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) );

        if ( ! $client_id ) {
            $this->set_message( '❌ Invalid client.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients' ) );
            exit;
        }

        if ( ! in_array( $channel, [ 'sms', 'voice' ], true ) ) {
            $this->set_message( '❌ Invalid credit channel. Must be sms or voice.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients&client_id=' . $client_id ) );
            exit;
        }

        if ( $amount < 100 ) {
            $this->set_message( '❌ Minimum top-up amount is NGN 100.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients&client_id=' . $client_id ) );
            exit;
        }

        $client = OFP_Client::get( $client_id );
        if ( ! $client ) {
            $this->set_message( '❌ Client not found.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients' ) );
            exit;
        }

        // OFP_Credit::topup() handles:
        //  - Incrementing loaded + remaining balances
        //  - Resetting paused = 0 and low_warned = 0 automatically
        //  - Inserting a transaction log row with the payment reference
        OFP_Credit::topup( $client_id, $channel, $amount, $reference );

        $label = $channel === 'sms' ? 'SMS' : 'Voice';

        $this->set_message(
            '✅ NGN ' . number_format( $amount, 2 ) . " loaded to {$client->business_name}'s {$label} credit balance.",
            'success'
        );

        wp_safe_redirect( admin_url( 'admin.php?page=ofp-clients&client_id=' . $client_id ) );
        exit;
    }

    /**
     * Handle manually marking a pending subscription as paid.
     * Grants the client 30 days of access, updates the row to paid,
     * and triggers the payment confirmation email.
     *
     * @return void
     */
    public function handle_mark_subscription_paid(): void {

        $this->require_admin_post( 'ofp_mark_subscription_paid' );

        global $wpdb;

        $sub_id = (int) ( $_POST['subscription_id'] ?? 0 );

        if ( ! $sub_id ) {
            $this->set_message( '❌ Invalid subscription ID.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-billing' ) );
            exit;
        }

        $sub = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ofp_subscriptions WHERE id = %d LIMIT 1", $sub_id )
        );

        if ( ! $sub || $sub->status === 'paid' ) {
            $this->set_message( '❌ Subscription not found or already paid.', 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=ofp-billing' ) );
            exit;
        }

        $period_start = gmdate( 'Y-m-d' );
        $period_end   = gmdate( 'Y-m-d', strtotime( '+30 days' ) );

        // 1. Update the pending subscription row to paid
        $wpdb->update(
            $wpdb->prefix . 'ofp_subscriptions',
            [
                'status'         => 'paid',
                'payment_method' => 'manual',
                'payment_ref'    => 'manual_admin_' . current_time( 'timestamp' ),
                'period_start'   => $period_start,
                'period_end'     => $period_end,
                'paid_at'        => current_time( 'mysql' ),
            ],
            [ 'id' => $sub_id ]
        );

        // 2. Extend the client's overall subscription status and expiry
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}ofp_clients
                 SET status               = 'active',
                     subscription_expires = DATE_ADD(
                         GREATEST( IFNULL(subscription_expires, CURDATE()), CURDATE() ),
                         INTERVAL 30 DAY
                     ),
                     updated_at = NOW()
                 WHERE id = %d",
                $sub->client_id
            )
        );

        // 3. Send the payment confirmation email
        $client = OFP_Client::get( (int) $sub->client_id );
        if ( $client ) {
            OFP_Mailer::send_payment_confirmed( $client, (float) $sub->amount, $sub->type );
        }

        $this->set_message( '✅ Subscription manually marked as paid. Client has been granted 30 days access.', 'success' );
        wp_safe_redirect( admin_url( 'admin.php?page=ofp-billing' ) );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECURITY GUARDS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verify nonce and confirm user is any OFP admin.
     * Terminates with wp_die() on failure.
     *
     * @param string $action Nonce action name.
     * @return void
     */
    private function require_admin_post( string $action ): void {
        if (
            ! check_admin_referer( $action ) ||
            ! OFP_Auth::is_admin_user()
        ) {
            wp_die(
                esc_html__( 'Security check failed.', 'ofast-pipeline' ),
                403
            );
        }
    }

    /**
     * Verify nonce and confirm user is specifically super_admin.
     * Co-admins are blocked here — this is the PHP enforcement layer.
     *
     * @param string $action Nonce action name.
     * @return void
     */
    private function require_super_admin_post( string $action ): void {
        if (
            ! check_admin_referer( $action ) ||
            ! OFP_Auth::is_super_admin()
        ) {
            wp_die(
                esc_html__( 'You do not have permission to perform this action.', 'ofast-pipeline' ),
                403
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MESSAGE HELPER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Store an admin feedback message in a short-lived transient.
     * Views retrieve and display this on page load, then delete it.
     *
     * @param string $message Message text.
     * @param string $type    'success' or 'error'.
     * @return void
     */
    private function set_message( string $message, string $type = 'success' ): void {
        set_transient(
            'ofp_admin_message_' . get_current_user_id(),
            [ 'text' => $message, 'type' => $type ],
            60
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PHASE 17 — FUNDING REQUESTS & COMPANY BANK
    // ─────────────────────────────────────────────────────────────────────────

    public function handle_save_company_bank(): void {
        if ( empty( $_POST['ofp_save_company_bank'] ) ) return;
        if ( OFP_Auth::current_admin_role() !== 'super_admin' ) {
            wp_die( 'Access denied.' );
        }
        check_admin_referer( 'ofp_save_company_bank_action', 'ofp_company_bank_nonce' );
        update_option( 'ofp_company_bank_name',    sanitize_text_field( $_POST['company_bank_name'] ?? '' ) );
        update_option( 'ofp_company_account_no',   sanitize_text_field( $_POST['company_account_no'] ?? '' ) );
        update_option( 'ofp_company_account_name', sanitize_text_field( $_POST['company_account_name'] ?? '' ) );
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>Company bank details saved.</p></div>';
        } );
    }

    public function render_funding_requests(): void {
        global $wpdb;

        // Handle approve action.
        if (
            isset( $_GET['action'], $_GET['request_id'] ) &&
            $_GET['action'] === 'approve' &&
            wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ofp_approve_funding' )
        ) {
            $request_id = (int) $_GET['request_id'];
            $request = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_funding_requests WHERE id = %d",
                $request_id
            ) );

            if ( $request && $request->status === 'pending' ) {
                // Route approval based on what the payment was for (Phase 17b fix).
                if ( in_array( $request->channel, [ 'sms', 'voice' ], true ) ) {
                    // SMS or Voice credit top-up.
                    OFP_Credit::topup(
                        $request->client_id,
                        $request->channel,
                        $request->amount,
                        'manual_funding_' . $request_id
                    );
                } elseif ( $request->channel === 'crm_plan' ) {
                    // CRM plan payment — activate or extend the subscription.
                    OFP_Subscription::activate_from_manual_payment(
                        $request->client_id,
                        'crm',
                        $request->amount
                    );
                } elseif ( $request->channel === 'listing_plan' ) {
                    // Listing plan payment — activate or extend the listing subscription.
                    OFP_Subscription::activate_from_manual_payment(
                        $request->client_id,
                        'listing',
                        $request->amount
                    );
                }

                // Mark as approved.
                $wpdb->update(
                    $wpdb->prefix . 'ofp_funding_requests',
                    [
                        'status'      => 'approved',
                        'reviewed_by' => get_current_user_id(),
                        'reviewed_at' => current_time( 'mysql' ),
                    ],
                    [ 'id' => $request_id ]
                );

                // Notify the client.
                OFP_Notification::create(
                    $request->client_id,
                    'manual_funding_approved',
                    'Funding approved',
                    'Your manual funding of NGN ' . number_format( $request->amount, 2 ) .
                    ' has been approved and credited to your ' . ucfirst( $request->channel ) . ' balance.'
                );

                echo '<div class="notice notice-success"><p>Funding request approved and client credited.</p></div>';
            }
        }

        // Handle reject action.
        if (
            isset( $_GET['action'], $_GET['request_id'] ) &&
            $_GET['action'] === 'reject' &&
            wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ofp_reject_funding' )
        ) {
            $request_id = (int) $_GET['request_id'];
            $request = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_funding_requests WHERE id = %d",
                $request_id
            ) );

            if ( $request && $request->status === 'pending' ) {
                $wpdb->update(
                    $wpdb->prefix . 'ofp_funding_requests',
                    [
                        'status'      => 'rejected',
                        'reviewed_by' => get_current_user_id(),
                        'reviewed_at' => current_time( 'mysql' ),
                    ],
                    [ 'id' => $request_id ]
                );

                OFP_Notification::create(
                    $request->client_id,
                    'manual_funding_rejected',
                    'Funding request rejected',
                    'Your manual funding request of NGN ' . number_format( $request->amount, 2 ) .
                    ' could not be verified. Please contact us if you believe this is a mistake.'
                );

                echo '<div class="notice notice-error"><p>Funding request rejected.</p></div>';
            }
        }

        // Fetch all pending requests first, then others.
        $requests = $wpdb->get_results( "
            SELECT r.*, c.business_name, c.owner_name, c.email
            FROM {$wpdb->prefix}ofp_funding_requests r
            JOIN {$wpdb->prefix}ofp_clients c ON c.id = r.client_id
            ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected'), r.created_at DESC
        " );
        ?>
        <div class="wrap">
            <h1>Funding Requests</h1>
            <?php if ( empty( $requests ) ) : ?>
                <p>No funding requests yet.</p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Amount</th>
                        <th>Channel</th>
                        <th>Bank</th>
                        <th>Ref</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $requests as $req ) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html( $req->business_name ); ?><br>
                            <small><?php echo esc_html( $req->owner_name ); ?></small>
                        </td>
                        <td>NGN <?php echo esc_html( number_format( $req->amount, 2 ) ); ?></td>
                        <td><?php echo esc_html( ucfirst( $req->channel ) ); ?></td>
                        <td>
                            <?php echo esc_html( $req->bank_name ); ?><br>
                            <small><?php echo esc_html( $req->account_name ); ?></small>
                        </td>
                        <td><?php echo esc_html( $req->transaction_ref ); ?></td>
                        <td><?php echo esc_html( date( 'd M Y', strtotime( $req->created_at ) ) ); ?></td>
                        <td>
                            <span class="ofp-status-badge ofp-status-<?php echo esc_attr( $req->status ); ?>">
                                <?php echo esc_html( ucfirst( $req->status ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ( $req->status === 'pending' ) : ?>
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    add_query_arg( [ 'action' => 'approve', 'request_id' => $req->id ] ),
                                    'ofp_approve_funding'
                                ) ); ?>" class="button button-primary button-small">Approve</a>
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    add_query_arg( [ 'action' => 'reject', 'request_id' => $req->id ] ),
                                    'ofp_reject_funding'
                                ) ); ?>" class="button button-small"
                                   onclick="return confirm('Reject this request?')">Reject</a>
                            <?php else : ?>
                                <span class="ofp-muted">Done</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    } // end render_funding_requests()

    /**
     * Admin page: Send a manual notification to one client or all clients.
     * Phase 17b — Patch J1.
     */
    public function render_send_notification(): void {
        $sent    = false;
        $error   = '';
        $clients = OFP_Client::all();

        if (
            $_SERVER['REQUEST_METHOD'] === 'POST' &&
            isset( $_POST['ofp_send_notification'] ) &&
            check_admin_referer( 'ofp_send_notification_action', 'ofp_send_notif_nonce' )
        ) {
            $recipient = sanitize_text_field( $_POST['recipient'] ?? '' );
            $title     = sanitize_text_field( $_POST['notif_title'] ?? '' );
            $message   = sanitize_textarea_field( $_POST['notif_message'] ?? '' );

            if ( empty( $title ) || empty( $message ) ) {
                $error = 'Please fill in both a title and a message.';
            } else {
                if ( $recipient === 'all' ) {
                    foreach ( $clients as $client ) {
                        OFP_Notification::create(
                            $client->id,
                            'admin_message',
                            $title,
                            $message
                        );
                    }
                    $sent = true;
                } elseif ( is_numeric( $recipient ) && (int) $recipient > 0 ) {
                    OFP_Notification::create(
                        (int) $recipient,
                        'admin_message',
                        $title,
                        $message
                    );
                    $sent = true;
                } else {
                    $error = 'Please choose a recipient.';
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>Send Notification</h1>

            <?php if ( $sent ) : ?>
                <div class="notice notice-success is-dismissible"><p>Notification sent successfully.</p></div>
            <?php endif; ?>

            <?php if ( $error ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <form method="POST" style="max-width:600px;">
                <?php wp_nonce_field( 'ofp_send_notification_action', 'ofp_send_notif_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="recipient">Send To</label></th>
                        <td>
                            <select name="recipient" id="recipient" required style="width:300px;">
                                <option value="">— Choose recipient —</option>
                                <option value="all">All Clients</option>
                                <optgroup label="One Client">
                                    <?php foreach ( $clients as $client ) : ?>
                                        <option value="<?php echo esc_attr( $client->id ); ?>">
                                            <?php echo esc_html( $client->business_name . ' (' . $client->owner_name . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="notif_title">Title</label></th>
                        <td>
                            <input type="text" name="notif_title" id="notif_title"
                                   style="width:300px;" required
                                   placeholder="e.g. System maintenance tonight">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="notif_message">Message</label></th>
                        <td>
                            <textarea name="notif_message" id="notif_message"
                                      rows="5" style="width:300px;" required
                                      placeholder="Full message the client will see in their bell and/or email"></textarea>
                            <p class="description">
                                Delivered via bell, email, or both — based on each client's own notification preference.
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="ofp_send_notification" value="1" class="button button-primary">
                        Send Notification
                    </button>
                </p>
            </form>
        </div>
        <?php
    }
}
