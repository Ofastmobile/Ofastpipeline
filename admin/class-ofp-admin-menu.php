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
        add_action( 'admin_init', [ $this, 'handle_save_plan_pricing' ] );
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
     * Handle saving the Settings page (super_admin only).
     *
     * @return void
     */
    public function handle_save_settings(): void {

        $this->require_super_admin_post( 'ofp_save_settings' );

        $settings = [
            // Default pipeline messages
            'ofp_default_instant_sms' => sanitize_textarea_field( wp_unslash( $_POST['ofp_default_instant_sms'] ?? '' ) ),
            'ofp_default_followup_1'  => sanitize_textarea_field( wp_unslash( $_POST['ofp_default_followup_1']  ?? '' ) ),
            'ofp_default_followup_2'  => sanitize_textarea_field( wp_unslash( $_POST['ofp_default_followup_2']  ?? '' ) ),
            'ofp_default_followup_3'  => sanitize_textarea_field( wp_unslash( $_POST['ofp_default_followup_3']  ?? '' ) ),
            // Payment gateway
            'ofp_payment_provider'       => sanitize_text_field( wp_unslash( $_POST['ofp_payment_provider']       ?? 'monnify' ) ),
            'ofp_monnify_base_url'       => esc_url_raw(          wp_unslash( $_POST['ofp_monnify_base_url']       ?? '' ) ),
            'ofp_monnify_contract_code'  => sanitize_text_field( wp_unslash( $_POST['ofp_monnify_contract_code']  ?? '' ) ),
            // SMTP
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
        $encrypted_fields = [
            'ofp_smtp_password'              => $_POST['ofp_smtp_password']              ?? '',
            'ofp_at_api_key'                 => $_POST['ofp_at_api_key']                 ?? '',
            'ofp_bsmsn_api_key'              => $_POST['ofp_bsmsn_api_key']              ?? '',
            'ofp_monnify_api_key'            => $_POST['ofp_monnify_api_key']            ?? '',
            'ofp_monnify_secret_key'         => $_POST['ofp_monnify_secret_key']         ?? '',
            'ofp_paystack_secret_key'        => $_POST['ofp_paystack_secret_key']        ?? '',
            'ofp_flutterwave_secret_key'     => $_POST['ofp_flutterwave_secret_key']     ?? '',
            'ofp_flutterwave_secret_hash'    => $_POST['ofp_flutterwave_secret_hash']    ?? '',
            'ofp_turnstile_secret'           => $_POST['ofp_turnstile_secret']           ?? '',
        ];

        foreach ( $settings as $key => $value ) {
            update_option( $key, $value );
        }

        foreach ( $encrypted_fields as $key => $value ) {
            $value = sanitize_text_field( wp_unslash( $value ) );
            if ( ! empty( $value ) ) {
                update_option( $key, OFP_Security::encrypt( $value ) );
            }
        }

        $this->set_message( '✅ Settings saved successfully.', 'success' );
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
}
