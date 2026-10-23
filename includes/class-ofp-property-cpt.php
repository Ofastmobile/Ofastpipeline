<?php
/**
 * OFP_Property_CPT
 *
 * Registers the 'ofp_property' Custom Post Type for the public-facing
 * property listing directory.
 *
 * ARCHITECTURE (v2.1):
 *  The plugin maintains TWO parallel records for each property:
 *
 *  1. ofp_properties (plugin table) — the billing/ownership source of truth.
 *     Tied to client_id, tracks status, price, bedrooms etc., and links to
 *     the payment system. This is what the admin works with.
 *
 *  2. ofp_property (WordPress CPT) — the public-facing page.
 *     SEO-optimised via RankMath, rendered via WordPress templates,
 *     discoverable via search engines. The wp_post_id column in
 *     ofp_properties links the two records together.
 *
 * WHY A CPT AND NOT JUST PLUGIN TABLES:
 *  WordPress's permalink system, RankMath SEO, and the REST API all
 *  expect real WP posts. Plugin table rows cannot be indexed by search
 *  engines or use WP's native permalink structure without significant
 *  custom routing work. The CPT gives us all of that for free.
 *
 * SEARCH & FILTERING:
 *  The directory search page uses WP_Query with custom meta_query
 *  arguments to filter by property type, location, price range, etc.
 *  All filterable data is stored as post meta on the CPT post.
 *
 * Depends on: WordPress CPT registration, ofp_properties table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_CPT {

    const PLAN_KEYS = [ 'free', 'silver', 'gold' ];

    const DEFAULT_PLAN_PRICES = [
        'free'   => 0.00,
        'silver' => 50000.00,
        'gold'   => 100000.00,
    ];

    const DEFAULT_PLAN_CAPS = [
        'free'   => 1,
        'silver' => 3,
        'gold'   => 10,
    ];

    public function __construct() {
        add_action( 'init',                    [ $this, 'register_post_type' ] );
        add_action( 'init',                    [ $this, 'register_taxonomies' ] );
        add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_admin_assets' ] );
        add_action( 'add_meta_boxes',          [ $this, 'add_meta_boxes' ], 20 );
        add_action( 'save_post_ofp_property',  [ $this, 'save_meta' ] );
        add_filter( 'manage_ofp_property_posts_columns',       [ $this, 'custom_columns' ] );
        add_action( 'manage_ofp_property_posts_custom_column', [ $this, 'render_columns' ], 10, 2 );
        add_action( 'quick_edit_custom_box',                   [ $this, 'quick_edit_listing_type' ], 10, 2 );
        add_action( 'admin_footer-edit.php',                   [ $this, 'quick_edit_js' ] );
        add_filter( 'template_include',                        [ $this, 'load_templates' ] );
        add_filter( 'post_type_link',                          [ $this, 'rewrite_permalink_to_property_subdomain' ], 10, 2 );
        add_filter( 'use_block_editor_for_post_type',          [ $this, 'disable_block_editor' ], 10, 2 );
        add_action( 'template_redirect',                       [ $this, 'maybe_redirect_old_property_url' ], 1 );
    }

    /**
     * Disable the Gutenberg block editor for properties to provide a unified 2026 SaaS Studio experience.
     *
     * @param bool   $use_block_editor Current setting.
     * @param string $post_type        Post type slug.
     * @return bool
     */
    public function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
        if ( $post_type === 'ofp_property' ) {
            return false;
        }
        return $use_block_editor;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CPT REGISTRATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Register the 'ofp_property' CPT.
     *
     * Public-facing, has archive at /properties/, supports SEO via RankMath.
     * @return void
     */
    public function register_post_type(): void {
        register_post_type( 'ofp_property', [
            'labels' => [
                'name'               => 'Property Listings',
                'singular_name'      => 'Property',
                'add_new'            => 'Add New',
                'add_new_item'       => 'Add New Property',
                'edit_item'          => 'Edit Property',
                'view_item'          => 'View Property',
                'all_items'          => 'All Properties',
                'search_items'       => 'Search Properties',
                'not_found'          => 'No properties found.',
                'not_found_in_trash' => 'No properties found in trash.',
            ],
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,           // Top-level menu.
            'show_in_rest'        => true,           // Required for RankMath and Gutenberg.
            'has_archive'         => false,
            'rewrite'             => [ 'slug' => 'property', 'with_front' => false ],
            'supports'            => [ 'title', 'editor', 'thumbnail' ],
            'menu_icon'           => 'dashicons-building',
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ] );

        // Flush rewrite rules when the CPT slug is updated.
        if ( get_option( 'ofp_property_slug_version' ) !== '2' || get_transient( 'ofp_flush_rewrite_rules' ) ) {
            flush_rewrite_rules( false );
            update_option( 'ofp_property_slug_version', '2' );
            delete_transient( 'ofp_flush_rewrite_rules' );
        }
    }

    /**
     * Register property taxonomies.
     *
     * @return void
     */
    public function register_taxonomies(): void {

        // Property Type taxonomy (apartment, duplex, land, office, etc.)
        register_taxonomy( 'ofp_property_type', 'ofp_property', [
            'labels' => [
                'name'          => 'Property Types',
                'singular_name' => 'Property Type',
            ],
            'hierarchical'      => true,  // Like categories — types can have sub-types.
            'public'            => true,
            'show_in_rest'      => true,
            'rewrite'           => [ 'slug' => 'property-type' ],
        ] );

        // Location taxonomy (Lekki, Ikeja, Victoria Island, etc.)
        register_taxonomy( 'ofp_property_location', 'ofp_property', [
            'labels' => [
                'name'          => 'Locations',
                'singular_name' => 'Location',
            ],
            'hierarchical'      => false, // Flat list of location tags.
            'public'            => true,
            'show_in_rest'      => true,
            'rewrite'           => [ 'slug' => 'property-location' ],
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // META BOXES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enqueue CSS, JS, and WP media for the property edit screen.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_admin_assets( string $hook ): void {
        global $post, $typenow;

        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $screen_type = ( $screen && ! empty( $screen->post_type ) )
            ? $screen->post_type
            : ( $typenow ?: ( $post ? $post->post_type : ( $_GET['post_type'] ?? '' ) ) );

        if ( $screen_type !== 'ofp_property' ) {
            return;
        }

        // Enable WordPress native media library modal
        wp_enqueue_media();

        // Admin Property Editor CSS
        wp_enqueue_style(
            'ofp-admin-property-editor',
            OFP_URL . 'assets/css/admin-property-editor.css',
            [],
            OFP_VERSION
        );

        // Admin Property Editor JS
        wp_enqueue_script(
            'ofp-admin-property-editor',
            OFP_URL . 'assets/js/admin-property-editor.js',
            [ 'jquery' ],
            OFP_VERSION,
            true
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // META BOXES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Register meta boxes on the property CPT edit screen.
     * @return void
     */
    public function add_meta_boxes(): void {
        // Remove archaic WP slug box; slug is handled cleanly and automatically by the Studio
        remove_meta_box( 'slugdiv', 'ofp_property', 'normal' );

        add_meta_box(
            'ofp_property_details',
            'Property Details & Commerce Management',
            [ $this, 'render_meta_box' ],
            'ofp_property',
            'normal',
            'high'
        );
    }

    /**
     * Render the revamped property details meta box.
     *
     * @param  WP_Post $post
     * @return void
     */
    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'ofp_property_meta', 'ofp_property_nonce' );

        global $wpdb;
        $p = $wpdb->prefix;

        $meta = [
            'ofp_client_id'       => get_post_meta( $post->ID, 'ofp_client_id',       true ),
            'ofp_listing_type'    => get_post_meta( $post->ID, 'ofp_listing_type',    true ) ?: 'sale',
            'ofp_property_type'   => get_post_meta( $post->ID, 'ofp_property_type',   true ) ?: 'apartment',
            'ofp_price'           => get_post_meta( $post->ID, 'ofp_price',           true ),
            'ofp_price_period'    => get_post_meta( $post->ID, 'ofp_price_period',    true ) ?: 'sales',
            'ofp_bedrooms'        => get_post_meta( $post->ID, 'ofp_bedrooms',        true ),
            'ofp_bathrooms'       => get_post_meta( $post->ID, 'ofp_bathrooms',       true ),
            'ofp_parking'         => get_post_meta( $post->ID, 'ofp_parking',         true ),
            'ofp_area_sqm'        => get_post_meta( $post->ID, 'ofp_area_sqm',        true ),
            'ofp_plots_count'     => get_post_meta( $post->ID, 'ofp_plots_count',     true ),
            'ofp_land_topography' => get_post_meta( $post->ID, 'ofp_land_topography', true ),
            'ofp_land_zoning'     => get_post_meta( $post->ID, 'ofp_land_zoning',     true ),
            'ofp_land_dimensions' => get_post_meta( $post->ID, 'ofp_land_dimensions', true ),
            'ofp_survey_status'   => get_post_meta( $post->ID, 'ofp_survey_status',   true ),
            'ofp_title_document'  => get_post_meta( $post->ID, 'ofp_title_document',  true ),
            'ofp_condition'       => get_post_meta( $post->ID, 'ofp_condition',       true ),
            'ofp_furnishing'      => get_post_meta( $post->ID, 'ofp_furnishing',      true ),
            'ofp_video_url'       => get_post_meta( $post->ID, 'ofp_video_url',       true ),
            'ofp_amenities'       => json_decode( get_post_meta( $post->ID, 'ofp_amenities', true ) ?: '[]', true ) ?: [],
            'ofp_location_text'   => get_post_meta( $post->ID, 'ofp_location_text',   true ),
            'ofp_is_featured'     => get_post_meta( $post->ID, 'ofp_is_featured',     true ),
            'ofp_status'          => get_post_meta( $post->ID, 'ofp_status',          true ) ?: 'pending_upload',
            'ofp_gallery_ids'     => json_decode( get_post_meta( $post->ID, 'ofp_gallery_ids', true ) ?: '[]', true ) ?: [],
            'ofp_bank_account_id' => (int) get_post_meta( $post->ID, 'ofp_bank_account_id', true ),
            'ofp_total_package'   => get_post_meta( $post->ID, 'ofp_total_package',   true ),
            'ofp_allow_installment'=> get_post_meta( $post->ID, 'ofp_allow_installment', true ),
            'ofp_inspection_days' => get_post_meta( $post->ID, 'ofp_inspection_days', true ) ?: 'Mondays - Saturdays',
            'ofp_inspection_hours'=> get_post_meta( $post->ID, 'ofp_inspection_hours',true ) ?: '10:00 AM - 5:00 PM',
            'ofp_inspection_notice'=> get_post_meta( $post->ID, 'ofp_inspection_notice', true ),
        ];

        // Normalize legacy one-time -> sales
        if ( $meta['ofp_price_period'] === 'one-time' ) {
            $meta['ofp_price_period'] = 'sales';
        }

        $post_thumb_id = (int) get_post_thumbnail_id( $post->ID );

        // Active clients for owner selector
        $clients = $wpdb->get_results(
            "SELECT id, business_name, owner_name, email, phone FROM {$p}ofp_clients
             WHERE status = 'active' ORDER BY business_name ASC"
        ) ?: [];

        // Build Client Info and Bank Accounts preloaded maps for JS
        $clients_info_map = [];
        $clients_banks_map = [];

        // Company Bank for Admin (0)
        $company_bank_name = get_option( 'ofp_company_bank_name', '' );
        $company_bank_num  = get_option( 'ofp_company_account_number', '' );
        $company_bank_user = get_option( 'ofp_company_account_name', '' );

        $clients_banks_map['0'] = [
            [
                'id'             => '0',
                'bank_name'      => $company_bank_name ?: 'Platform Company Bank',
                'account_number' => $company_bank_num ?: '—',
                'account_name'   => $company_bank_user ?: ( OFP_Brand::name() . ' Primary' ),
                'is_default'     => 1,
            ]
        ];

        foreach ( $clients as $c ) {
            $cid = (int) $c->id;
            $plan = 'free';
            if ( class_exists( 'OFP_Subscription' ) ) {
                $plan = OFP_Subscription::feature_plan( $cid );
            }
            $clients_info_map[ (string) $cid ] = [
                'id'            => $cid,
                'business_name' => $c->business_name,
                'owner_name'    => $c->owner_name,
                'email'         => $c->email,
                'phone'         => $c->phone,
                'plan'          => $plan,
            ];

            if ( class_exists( 'OFP_Bank_Account' ) ) {
                $accts = OFP_Bank_Account::list( $cid );
                $clients_banks_map[ (string) $cid ] = array_map( function( $a ) {
                    return [
                        'id'             => (int) $a->id,
                        'bank_name'      => $a->bank_name,
                        'account_number' => $a->account_number,
                        'account_name'   => $a->account_name,
                        'is_default'     => (int) $a->is_default,
                    ];
                }, $accts );
            }
        }

        // Check if property has commerce activity
        $has_commerce   = false;
        $purchases      = [];
        $offers         = [];
        $leases         = [];
        $inquiry_count  = 0;
        $existing_prop  = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_id FROM {$p}ofp_properties WHERE wp_post_id = %d LIMIT 1",
            $post->ID
        ) );

        if ( $existing_prop ) {
            $prop_id = (int) $existing_prop->id;

            $purchases = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, buyer_name, buyer_phone, total_price, amount_paid, balance, status, created_at
                 FROM {$p}ofp_property_purchases WHERE property_id = %d ORDER BY created_at DESC LIMIT 20",
                $prop_id
            ) ) ?: [];

            $offers = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, buyer_name, buyer_phone, offered_price, status, expires_at, created_at
                 FROM {$p}ofp_property_offers WHERE property_id = %d ORDER BY created_at DESC LIMIT 20",
                $prop_id
            ) ) ?: [];

            $leases = $wpdb->get_results( $wpdb->prepare(
                "SELECT l.id, l.rent_amount, l.start_date, l.end_date, l.status, t.name AS tenant_name, t.phone AS tenant_phone
                 FROM {$p}ofp_property_leases l
                 LEFT JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
                 WHERE l.property_id = %d ORDER BY l.created_at DESC LIMIT 20",
                $prop_id
            ) ) ?: [];

            $inquiry_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}ofp_leads WHERE property_id = %d",
                $prop_id
            ) );

            if ( ! empty( $purchases ) || ! empty( $offers ) ) {
                $has_commerce = true;
            }
        }

        $total_deals_count = count( $purchases ) + count( $offers ) + count( $leases );

        // Current owner plan checking for featured listing gate
        $current_client_id = (int) $meta['ofp_client_id'];
        $can_feature = true;
        if ( $current_client_id ) {
            $plan = 'free';
            if ( class_exists( 'OFP_Subscription' ) ) {
                $plan = OFP_Subscription::feature_plan( $current_client_id );
            }
            if ( $plan === 'free' ) {
                $can_feature = false;
            }
        }

        $public_url = get_permalink( $post->ID );
        $is_published = ( $post->post_status === 'publish' );

        $featured_img_url = get_the_post_thumbnail_url( $post->ID, 'medium' ) ?: '';
        if ( ! $featured_img_url && ! empty( $meta['ofp_gallery_ids'] ) ) {
            $featured_img_url = wp_get_attachment_image_url( (int) $meta['ofp_gallery_ids'][0], 'medium' ) ?: '';
        }
        ?>

        <script>
            window.ofpClientBanksData = <?php echo json_encode( $clients_banks_map ); ?>;
            window.ofpClientsInfoData = <?php echo json_encode( $clients_info_map ); ?>;
        </script>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- OFAST PIPELINE 2026 REAL ESTATE PROPERTY STUDIO                    -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="ofp-studio-wrap" id="ofp-property-editor-app">

            <!-- ── Top Studio Command Bar ──────────────────────────────────── -->
            <div class="ofp-studio-topbar">
                <div class="ofp-studio-topbar-left">
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property' ) ); ?>" class="ofp-topbar-back-btn" title="Return to property listings table">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                        <span>All Properties</span>
                    </a>
                    <div class="ofp-studio-badge">
                        <span class="ofp-badge-dot"></span>
                        <span><?php echo $post->ID ? 'Property #' . esc_html( $post->ID ) : 'New Listing'; ?></span>
                    </div>
                    <div class="ofp-status-pill-wrap">
                        <select name="ofp_status" id="ofp_status_select" class="ofp-status-select-pill">
                            <option value="pending_upload" <?php selected( $meta['ofp_status'], 'pending_upload' ); ?>>● Pending Upload / Draft</option>
                            <option value="live"           <?php selected( $meta['ofp_status'], 'live' ); ?>>● Live (Publicly Active)</option>
                            <option value="taken"          <?php selected( $meta['ofp_status'], 'taken' ); ?>>● Taken (Sold / Rented)</option>
                            <option value="expired"        <?php selected( $meta['ofp_status'], 'expired' ); ?>>● Expired</option>
                        </select>
                    </div>
                    <?php if ( $is_published && $public_url ) : ?>
                        <a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener" class="ofp-topbar-view-btn" title="Open public marketplace listing in new tab">
                            <span>View Live</span>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="ofp-studio-topbar-right">
                    <span class="ofp-sync-status" id="ofp-sync-status">
                        <span class="ofp-sync-dot"></span>
                        <span id="ofp-sync-text">All edits in sync</span>
                    </span>
                    <span class="ofp-shortcut-tag">Ctrl + S to save</span>
                    <button type="button" class="ofp-btn-studio-save" id="ofp-btn-studio-save">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        <span>Save Property</span>
                    </button>
                </div>
            </div>

            <!-- ── Main 2-Column Responsive Workspace ──────────────────────── -->
            <div class="ofp-studio-workspace">

                <!-- ── Column 1: Main Studio Panel (Left ~68%) ─────────────── -->
                <div class="ofp-studio-main-col">

                    <!-- Hero Title Card (Unified, replaces archaic titlediv) -->
                    <div class="ofp-hero-title-box">
                        <div class="ofp-hero-title-top">
                            <label for="ofp_studio_title" class="ofp-hero-title-label">
                                <span>Property Listing Title &amp; Marketing Headline</span>
                                <span class="ofp-req">*</span>
                            </label>
                            <span class="ofp-title-counter" id="ofp-title-counter">0 / 120 chars</span>
                        </div>
                        <input type="text"
                               name="ofp_studio_title"
                               id="ofp_studio_title"
                               class="ofp-hero-title-input"
                               value="<?php echo esc_attr( $post->post_title ); ?>"
                               placeholder="e.g. Luxurious 4-Bedroom Fully Detached Duplex with Swimming Pool &amp; BQ"
                               autocomplete="off">
                        <div class="ofp-permalink-bar">
                            <span class="ofp-permalink-prefix">Marketplace URL:</span>
                            <span class="ofp-permalink-slug" id="ofp-permalink-slug"><?php echo esc_html( $public_url ?: home_url( '/properties/your-listing-title' ) ); ?></span>
                        </div>
                    </div>

                    <!-- Modern Vertical Studio Navigation Layout -->
                    <div class="ofp-studio-vertical-layout">
                        <nav class="ofp-studio-nav-vertical" role="tablist" aria-label="Property Studio Sections">
                            <div class="ofp-vertical-nav-header">
                                <span class="ofp-vertical-nav-title">Studio Sections</span>
                            </div>
                            <button type="button" class="ofp-studio-tab-btn is-active" data-tab="overview" role="tab" aria-selected="true">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                <span class="ofp-tab-label">Overview &amp; Story</span>
                            </button>
                            <button type="button" class="ofp-studio-tab-btn" data-tab="pricing" role="tab" aria-selected="false">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                <span class="ofp-tab-label">Pricing &amp; Terms</span>
                            </button>
                            <button type="button" class="ofp-studio-tab-btn" data-tab="specs" role="tab" aria-selected="false">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                                <span class="ofp-tab-label">Building &amp; Land Specs</span>
                            </button>
                            <button type="button" class="ofp-studio-tab-btn" data-tab="media" role="tab" aria-selected="false">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                                <span class="ofp-tab-label">Media &amp; Gallery</span>
                                <span class="ofp-nav-count" id="ofp-media-count-badge"><?php echo count( (array) $meta['ofp_gallery_ids'] ); ?></span>
                            </button>
                            <button type="button" class="ofp-studio-tab-btn" data-tab="amenities" role="tab" aria-selected="false">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                                <span class="ofp-tab-label">Amenities</span>
                                <span class="ofp-nav-count" id="ofp-amenities-count-badge"><?php echo count( (array) $meta['ofp_amenities'] ); ?></span>
                            </button>
                            <button type="button" class="ofp-studio-tab-btn" data-tab="ownership" role="tab" aria-selected="false">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                                <span class="ofp-tab-label">Ownership &amp; Bank</span>
                            </button>
                            <button type="button" class="ofp-studio-tab-btn" data-tab="deals" role="tab" aria-selected="false">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6H2.25m0 0v10.5m0-10.5A.75.75 0 013 4.5h.75m0 0h16.5m0 0h.75A.75.75 0 0121 6v10.5m0-10.5a.75.75 0 00-.75-.75h-.75m0 0v10.5m-15 0h15" /></svg>
                                <span class="ofp-tab-label">Deals &amp; Pipeline</span>
                                <?php if ( $total_deals_count > 0 ) : ?>
                                    <span class="ofp-nav-count ofp-count-highlight"><?php echo (int) $total_deals_count; ?></span>
                                <?php endif; ?>
                            </button>
                        </nav>

                        <div class="ofp-studio-panels-container">

                    <!-- ── TAB 1: Overview & Story ────────────────────────────── -->
                    <div class="ofp-studio-panel is-active" data-panel="overview">
                        <div class="ofp-panel-header">
                            <div>
                                <h3>Overview, Classification &amp; Marketing Story</h3>
                                <p>Set property purpose, classification, comprehensive architectural description, and neighborhood address.</p>
                            </div>
                        </div>

                        <div class="ofp-form-grid-2" style="margin-bottom:20px;">
                            <div class="ofp-form-group">
                                <label class="ofp-form-label">Listing Intent / Purpose <span class="ofp-req">*</span></label>
                                <select name="ofp_listing_type" id="ofp_listing_type_select" class="ofp-input-select">
                                    <option value="sale"       <?php selected( $meta['ofp_listing_type'], 'sale' ); ?>>For Sale (Outright Purchase)</option>
                                    <option value="rent"       <?php selected( $meta['ofp_listing_type'], 'rent' ); ?>>For Rent (Long-term Tenancy)</option>
                                    <option value="shortlet"   <?php selected( $meta['ofp_listing_type'], 'shortlet' ); ?>>Short Let (Serviced Daily/Monthly)</option>
                                    <option value="commercial" <?php selected( $meta['ofp_listing_type'], 'commercial' ); ?>>Commercial (Office / Retail / Warehouse)</option>
                                    <option value="land"       <?php selected( $meta['ofp_listing_type'], 'land' ); ?>>Land / Plot (Residential / Commercial)</option>
                                </select>
                            </div>

                            <div class="ofp-form-group">
                                <label class="ofp-form-label">Property Architecture Type <span class="ofp-req">*</span></label>
                                <select name="ofp_property_type" id="ofp_property_type_select" class="ofp-input-select">
                                    <option value="apartment"   <?php selected( $meta['ofp_property_type'], 'apartment' ); ?>>Apartment / Flat</option>
                                    <option value="duplex"      <?php selected( $meta['ofp_property_type'], 'duplex' ); ?>>Detached / Semi-Detached Duplex</option>
                                    <option value="terrace"     <?php selected( $meta['ofp_property_type'], 'terrace' ); ?>>Terraced House / Townhouse</option>
                                    <option value="penthouse"   <?php selected( $meta['ofp_property_type'], 'penthouse' ); ?>>Penthouse Suite</option>
                                    <option value="mansion"     <?php selected( $meta['ofp_property_type'], 'mansion' ); ?>>Luxury Villa / Mansion</option>
                                    <option value="bungalow"    <?php selected( $meta['ofp_property_type'], 'bungalow' ); ?>>Bungalow</option>
                                    <option value="office"      <?php selected( $meta['ofp_property_type'], 'office' ); ?>>Commercial Office Space</option>
                                    <option value="shop"        <?php selected( $meta['ofp_property_type'], 'shop' ); ?>>Retail Shop / Mall Space</option>
                                    <option value="warehouse"   <?php selected( $meta['ofp_property_type'], 'warehouse' ); ?>>Industrial Warehouse</option>
                                    <option value="land"        <?php selected( $meta['ofp_property_type'], 'land' ); ?>>Bare Land / Development Site</option>
                                </select>
                            </div>
                        </div>

                        <!-- Integrated Property Description (Replaces primitive postdivrich) -->
                        <div class="ofp-form-group" style="margin-bottom:24px;">
                            <div class="ofp-desc-header">
                                <label class="ofp-form-label" for="ofp_studio_content">
                                    <span>Detailed Property Description &amp; Walkthrough Story</span>
                                </label>
                                <div class="ofp-desc-tools">
                                    <button type="button" class="ofp-desc-tool-btn" data-tool="bullet">• Add Bullet</button>
                                    <button type="button" class="ofp-desc-tool-btn" data-tool="features">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="vertical-align:middle;margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>Key Features Template
                                    </button>
                                    <span class="ofp-desc-word-count" id="ofp-desc-word-count">0 words</span>
                                </div>
                            </div>
                            <textarea name="ofp_studio_content"
                                       id="ofp_studio_content"
                                       class="ofp-studio-content-editor"
                                       rows="9"
                                       placeholder="Provide an evocative description highlighting architectural finishes, master suite layout, kitchen design, estate security, and prime neighborhood accessibility..."><?php echo esc_textarea( $post->post_content ); ?></textarea>
                            <span class="ofp-field-hint">Engaging descriptions improve buyer inquiry rates by over 40%. Automatically synced with public listing pages and SEO metadata.</span>
                        </div>

                        <!-- Physical Location & Neighborhood Chips -->
                        <div class="ofp-form-group">
                            <label class="ofp-form-label">Physical Address &amp; Neighborhood <span class="ofp-req">*</span></label>
                            <input type="text"
                                   name="ofp_location_text"
                                   id="ofp_location_text_input"
                                   class="ofp-input-text"
                                   value="<?php echo esc_attr( $meta['ofp_location_text'] ); ?>"
                                   placeholder="e.g. Admiralty Way, Lekki Phase 1, Lagos">
                            <span class="ofp-field-hint">Click any preset prime location chip below to append it directly:</span>
                            <div class="ofp-location-presets">
                                <span class="ofp-location-chip">Lekki Phase 1, Lagos</span>
                                <span class="ofp-location-chip">Victoria Island, Lagos</span>
                                <span class="ofp-location-chip">Ikoyi, Lagos</span>
                                <span class="ofp-location-chip">Ikeja GRA, Lagos</span>
                                <span class="ofp-location-chip">Banana Island, Lagos</span>
                                <span class="ofp-location-chip">Chevy View, Lekki</span>
                                <span class="ofp-location-chip">Ajah, Lagos</span>
                                <span class="ofp-location-chip">Maitama, Abuja</span>
                                <span class="ofp-location-chip">Guzape, Abuja</span>
                                <span class="ofp-location-chip">Wuse 2, Abuja</span>
                            </div>
                        </div>

                        <!-- Inspection & Viewing Availability Schedule -->
                        <div class="ofp-panel-header" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.06);">
                            <div>
                                <h3>Inspection &amp; Viewing Availability Schedule</h3>
                                <p>Set available inspection days, hours, and estate access notice requirements for buyers/tenants.</p>
                            </div>
                        </div>

                        <div class="ofp-form-grid-2">
                            <div class="ofp-form-group">
                                <label class="ofp-form-label">Available Inspection Days</label>
                                <input type="text" name="ofp_inspection_days" class="ofp-input-text"
                                       value="<?php echo esc_attr( $meta['ofp_inspection_days'] ); ?>"
                                       placeholder="e.g. Mondays - Saturdays, or Weekends Only">
                                <span class="ofp-field-hint">Days prospects can schedule physical or WhatsApp video showings.</span>
                            </div>

                            <div class="ofp-form-group">
                                <label class="ofp-form-label">Available Viewing Hours</label>
                                <input type="text" name="ofp_inspection_hours" class="ofp-input-text"
                                       value="<?php echo esc_attr( $meta['ofp_inspection_hours'] ); ?>"
                                       placeholder="e.g. 10:00 AM - 5:00 PM">
                                <span class="ofp-field-hint">Time slot range for viewings.</span>
                            </div>

                            <div class="ofp-form-group ofp-field-full">
                                <label class="ofp-form-label">Gate Access Protocol &amp; Advance Notice</label>
                                <input type="text" name="ofp_inspection_notice" class="ofp-input-text"
                                       value="<?php echo esc_attr( $meta['ofp_inspection_notice'] ); ?>"
                                       placeholder="e.g. 24 hours advance notice required for estate security clearance code.">
                                <span class="ofp-field-hint">Displayed on single property booking card so prospects know estate gate rules in advance.</span>
                            </div>
                        </div>
                    </div>

                    <!-- ── TAB 2: Pricing & Terms ─────────────────────────────────── -->
                    <div class="ofp-studio-panel" data-panel="pricing">
                        <div class="ofp-panel-header">
                            <div>
                                <h3>Valuation, Billing &amp; Title Verification</h3>
                                <p>Set property valuation, payment structure, and registered title documentation.</p>
                            </div>
                        </div>

                        <div class="ofp-form-grid-2">
                            <div class="ofp-form-group">
                                <label class="ofp-form-label">Asking Price (NGN ₦) <span class="ofp-req">*</span></label>
                                <div class="ofp-currency-wrap">
                                    <span class="ofp-currency-symbol">₦</span>
                                    <input type="number" step="0.01" name="ofp_price" id="ofp_price_input" class="ofp-input-number ofp-currency-input"
                                           value="<?php echo esc_attr( $meta['ofp_price'] ); ?>" placeholder="0.00">
                                </div>
                                <div class="ofp-currency-words" id="ofp_price_words_preview"></div>
                                <span class="ofp-field-hint">Enter the full listing price in Naira. Live verbalization helps prevent digit errors.</span>
                            </div>

                            <div class="ofp-form-group">
                                <label class="ofp-form-label">Price Billing Period</label>
                                <select name="ofp_price_period" id="ofp_price_period_select" class="ofp-input-select">
                                    <option value="sales"  <?php selected( $meta['ofp_price_period'], 'sales' ); ?>>Sales (One-off Total Amount)</option>
                                    <option value="year"   <?php selected( $meta['ofp_price_period'], 'year' ); ?>>Per Year (Rent)</option>
                                    <option value="2years" <?php selected( $meta['ofp_price_period'], '2years' ); ?>>Per 2 Years (Rent)</option>
                                    <option value="month"  <?php selected( $meta['ofp_price_period'], 'month' ); ?>>Per Month (Shortlet / Rent)</option>
                                </select>
                                <span class="ofp-field-hint">Sales listings automatically lock to one-off total.</span>
                            </div>

                            <div class="ofp-form-group" id="ofp_total_package_group">
                                <label class="ofp-form-label">Total Move-in Package (₦) <span class="ofp-opt">(Rent Only)</span></label>
                                <div class="ofp-currency-wrap">
                                    <span class="ofp-currency-symbol">₦</span>
                                    <input type="number" step="0.01" name="ofp_total_package" id="ofp_total_package_input" class="ofp-input-number ofp-currency-input"
                                           value="<?php echo esc_attr( $meta['ofp_total_package'] ); ?>" placeholder="e.g. Total Package including Legal &amp; Agency">
                                </div>
                                <span class="ofp-field-hint">In Nigeria, total move-in cost includes agency, legal, caution, and service fee. Displayed separately alongside rent.</span>
                            </div>

                            <div class="ofp-form-group ofp-field-full" style="padding:14px 16px; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:10px;">
                                <label class="ofp-featured-switch-label" style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                                    <input type="checkbox" name="ofp_allow_installment" value="1" <?php checked( $meta['ofp_allow_installment'], '1' ); ?>>
                                    <span style="font-weight:600; color:#f8fafc;">Allow Installment / Mortgage Financing Plan</span>
                                </label>
                                <span class="ofp-field-hint" style="margin-left:26px; margin-top:4px;">When checked, the Installment &amp; Mortgage Estimator tab is activated on the public property page. Leave unchecked for regular properties without financing plans.</span>
                            </div>

                            <div class="ofp-form-group ofp-field-full">
                                <label class="ofp-form-label">Official Title Document</label>
                                <select name="ofp_title_document" id="ofp_title_document_select" class="ofp-input-select">
                                    <option value="">-- Select Title Document --</option>
                                    <?php
                                    $docs = [
                                        'C of O'              => 'Certificate of Occupancy (C of O)',
                                        "Governor's Consent"  => "Governor's Consent",
                                        'Gazette'             => 'Government Gazette',
                                        'Deed of Assignment'  => 'Deed of Assignment',
                                        'R of O'              => 'Right of Occupancy (R of O)',
                                        'Excision'            => 'Government Excision',
                                        'Court Judgment'      => 'Court Judgment / Ruling',
                                        'Other'               => 'Other Document',
                                    ];
                                    foreach ( $docs as $val => $lbl ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $meta['ofp_title_document'], $val ); ?>>
                                            <?php echo esc_html( $lbl ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="ofp-field-hint">Verifiable title deed gives buyers high transaction confidence.</span>
                            </div>
                        </div>
                    </div>

                    <!-- ── TAB 3: Spaces & Specs ──────────────────────────────────── -->
                    <div class="ofp-studio-panel" data-panel="specs">
                        <div class="ofp-panel-header">
                            <div>
                                <h3>Building Dimensions &amp; Specifications</h3>
                                <p>Room capacities, land measuring in square meters, and property finish grade.</p>
                            </div>
                        </div>

                        <!-- Land mode notice -->
                        <div class="ofp-land-notice" id="ofp_land_notice">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                            <div>
                                <strong>Land listing active:</strong> Room counts, bathrooms, parking spaces, and furnishing specifications are hidden. Nigerian Land Sizing (Plots, SQM, Topography, Zoning) and Title Document apply.
                            </div>
                        </div>

                        <!-- Building Specs (Hidden when listing is Land) -->
                        <div id="ofp_building_specs_section">
                            <div class="ofp-form-grid-4" style="margin-bottom:20px;">
                                <div class="ofp-form-group">
                                    <label class="ofp-form-label">Bedrooms</label>
                                    <input type="number" min="0" max="25" name="ofp_bedrooms" id="ofp_bedrooms_input" class="ofp-input-number"
                                           value="<?php echo esc_attr( $meta['ofp_bedrooms'] ); ?>" placeholder="e.g. 4">
                                </div>

                                <div class="ofp-form-group">
                                    <label class="ofp-form-label">Bathrooms</label>
                                    <input type="number" min="0" max="25" name="ofp_bathrooms" id="ofp_bathrooms_input" class="ofp-input-number"
                                           value="<?php echo esc_attr( $meta['ofp_bathrooms'] ); ?>" placeholder="e.g. 4">
                                </div>

                                <div class="ofp-form-group">
                                    <label class="ofp-form-label">Parking Spaces</label>
                                    <input type="number" min="0" max="50" name="ofp_parking" id="ofp_parking_input" class="ofp-input-number"
                                           value="<?php echo esc_attr( $meta['ofp_parking'] ); ?>" placeholder="e.g. 3">
                                </div>

                                <div class="ofp-form-group">
                                    <label class="ofp-form-label">Condition</label>
                                    <select name="ofp_condition" class="ofp-input-select">
                                        <option value="">-- Select Condition --</option>
                                        <?php
                                        $conditions = [ 'Newly Built', 'Fairly Used', 'Renovation Needed', 'Under Construction', 'Off-Plan' ];
                                        foreach ( $conditions as $cond ) :
                                        ?>
                                            <option value="<?php echo esc_attr( $cond ); ?>" <?php selected( $meta['ofp_condition'], $cond ); ?>>
                                                <?php echo esc_html( $cond ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="ofp-form-grid-2" style="margin-bottom:20px;">
                                <div class="ofp-form-group">
                                    <label class="ofp-form-label">Furnishing Grade</label>
                                    <select name="ofp_furnishing" class="ofp-input-select">
                                        <option value="">-- Select Furnishing --</option>
                                        <?php
                                        $furnishings = [ 'Furnished', 'Semi-Furnished', 'Unfurnished' ];
                                        foreach ( $furnishings as $f ) :
                                        ?>
                                            <option value="<?php echo esc_attr( $f ); ?>" <?php selected( $meta['ofp_furnishing'], $f ); ?>>
                                                <?php echo esc_html( $f ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Dedicated Nigerian Land Specs Section -->
                        <div id="ofp_land_specs_section" style="<?php echo ( $meta['ofp_listing_type'] === 'land' ) ? 'display:block;' : 'display:none;'; ?> margin-bottom:20px; padding:18px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
                                <strong style="color:#166534; font-size:13.5px; display:flex; align-items:center; gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                                    Nigerian Land Sizing &amp; Plot Breakdown
                                </strong>
                                <span style="font-size:11px; background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:12px; font-weight:600;">Standard Lagos Plot = 600 SQM</span>
                            </div>

                            <div class="ofp-form-grid-2" style="margin-bottom:16px;">
                                <div class="ofp-form-group">
                                    <label class="ofp-form-label">Number of Plots (e.g. 1, 0.5, 2, 6)</label>
                                    <input type="number" step="0.1" min="0" name="ofp_plots_count" id="ofp_plots_count_input" class="ofp-input-number"
                                           value="<?php echo esc_attr( $meta['ofp_plots_count'] ); ?>" placeholder="e.g. 1">
                                    <span class="ofp-field-hint" id="ofp_admin_plot_hint">1 plot ≈ 600 SQM (~60ft × 120ft). Auto-syncs with Area SQM.</span>
                                </div>

                                <div class="ofp-form-group">
                                    <label class="ofp-form-label">Topography</label>
                                    <select name="ofp_land_topography" class="ofp-input-select">
                                        <option value="">-- Select Topography --</option>
                                        <?php
                                        $topos = [ '100% Dry Land', 'Dry Table Land', 'Mixed / Firm Land', 'Sandfilling Required', 'Waterfront Land' ];
                                        foreach ( $topos as $t ) :
                                        ?>
                                            <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $meta['ofp_land_topography'], $t ); ?>><?php echo esc_html( $t ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="ofp-form-grid-3">
                                <div class="ofp-form-group">
                                    <label class="ofp-form-label">Zoning / Land Use</label>
                                    <select name="ofp_land_zoning" class="ofp-input-select">
                                        <option value="">-- Select Zoning --</option>
                                        <?php
                                        $zonings = [ 'Residential', 'Commercial', 'Mixed-Use', 'Industrial', 'Agricultural' ];
                                        foreach ( $zonings as $z ) :
                                        ?>
                                            <option value="<?php echo esc_attr( $z ); ?>" <?php selected( $meta['ofp_land_zoning'], $z ); ?>><?php echo esc_html( $z ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="ofp-form-group">
                                    <label class="ofp-form-label">Land Dimensions</label>
                                    <input type="text" name="ofp_land_dimensions" class="ofp-input-text"
                                           value="<?php echo esc_attr( $meta['ofp_land_dimensions'] ); ?>" placeholder="e.g. 60ft × 120ft">
                                </div>

                                <div class="ofp-form-group">
                                    <label class="ofp-form-label">Survey &amp; Boundaries</label>
                                    <select name="ofp_survey_status" class="ofp-input-select">
                                        <option value="">-- Select Survey Status --</option>
                                        <?php
                                        $surveys = [ 'Registered Survey with Coordinates', 'Provisional Survey / Layout', 'Perimeter Fenced & Gated', 'Beaconed / Pegged', 'Survey in Progress' ];
                                        foreach ( $surveys as $s ) :
                                        ?>
                                            <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $meta['ofp_survey_status'], $s ); ?>><?php echo esc_html( $s ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Total Area (SQM) - Prominently Available for all listing types -->
                        <div class="ofp-form-grid-2" style="margin-bottom:20px;">
                            <div class="ofp-form-group">
                                <label class="ofp-form-label">Total Area (SQM)</label>
                                <input type="number" min="0" name="ofp_area_sqm" id="ofp_area_sqm_input" class="ofp-input-number"
                                       value="<?php echo esc_attr( $meta['ofp_area_sqm'] ); ?>" placeholder="e.g. 600">

                                <?php
                                $admin_sqm   = (float) ( $meta['ofp_area_sqm'] ?? 0 );
                                $admin_plots = (float) ( $meta['ofp_plots_count'] ?? 0 );
                                $admin_label = 'Standard Lagos Plot = 600 SQM (60ft × 120ft)';
                                if ( $admin_sqm > 0 || $admin_plots > 0 ) {
                                    $abk = class_exists( 'OFP_Property_Land' ) ? OFP_Property_Land::calculate( $admin_sqm, 0, $admin_plots ) : [];
                                    if ( ! empty( $abk['plot_label'] ) ) {
                                        $admin_label = $abk['plot_label'];
                                    }
                                }
                                ?>
                                <div id="ofp_admin_sqm_plot_badge" style="margin-top:8px; padding:7px 11px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; font-size:12px; font-weight:600; color:#15803d; display:flex; align-items:center; gap:6px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                                    <span id="ofp_admin_sqm_live_text"><?php echo esc_html( $admin_label ); ?></span>
                                </div>

                                <div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
                                    <span style="font-size:11px; color:#64748b; font-weight:600;">Plot Presets:</span>
                                    <button type="button" class="ofp-admin-sqm-preset-btn" data-sqm="300" data-plots="0.5" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:5px; padding:3px 8px; font-size:11px; font-weight:600; color:#334155; cursor:pointer;">Half Plot (300m²)</button>
                                    <button type="button" class="ofp-admin-sqm-preset-btn" data-sqm="600" data-plots="1" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:5px; padding:3px 8px; font-size:11px; font-weight:600; color:#334155; cursor:pointer;">1 Plot (600m²)</button>
                                    <button type="button" class="ofp-admin-sqm-preset-btn" data-sqm="1200" data-plots="2" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:5px; padding:3px 8px; font-size:11px; font-weight:600; color:#334155; cursor:pointer;">2 Plots (1,200m²)</button>
                                    <button type="button" class="ofp-admin-sqm-preset-btn" data-sqm="1800" data-plots="3" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:5px; padding:3px 8px; font-size:11px; font-weight:600; color:#334155; cursor:pointer;">3 Plots (1,800m²)</button>
                                    <button type="button" class="ofp-admin-sqm-preset-btn" data-sqm="4047" data-plots="6.7" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:5px; padding:3px 8px; font-size:11px; font-weight:600; color:#334155; cursor:pointer;">1 Acre (4,047m²)</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── TAB 4: Media & Tour ─────────────────────────────────── -->
                    <div class="ofp-studio-panel" data-panel="media">
                        <div class="ofp-panel-header">
                            <div>
                                <h3>High-Resolution Gallery &amp; Virtual Walkthrough</h3>
                                <p>Upload property photos with WordPress Media Library, arrange thumbnails, pick the cover image, and link video tours.</p>
                            </div>
                        </div>

                        <div class="ofp-gallery-header">
                            <div>
                                <strong style="font-size:13.5px;color:#0f172a;">Property Gallery Media</strong>
                                <p style="margin:2px 0 0;font-size:12px;color:#64748b;">Add photos from your device or media library. The primary image is your cover photo.</p>
                            </div>
                            <button type="button" class="ofp-btn-media-add" id="ofp-btn-add-gallery">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                <span>+ Add / Select Gallery Photos</span>
                            </button>
                        </div>

                        <!-- Hidden inputs for gallery state -->
                        <input type="hidden" name="ofp_gallery_ids" id="ofp_gallery_ids_input" value="<?php echo esc_attr( json_encode( $meta['ofp_gallery_ids'] ) ); ?>">
                        <input type="hidden" name="ofp_set_cover_photo" id="ofp_set_cover_photo" value="<?php echo esc_attr( $post_thumb_id ); ?>">

                        <!-- Gallery Cards Grid -->
                        <div class="ofp-gallery-grid" id="ofp-gallery-grid">
                            <?php if ( empty( $meta['ofp_gallery_ids'] ) && ! $post_thumb_id ) : ?>
                                <div class="ofp-gallery-empty-state" id="ofp-gallery-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                                    <div style="font-weight:600;font-size:14px;color:#1e293b;margin-bottom:4px;">No gallery photos added yet</div>
                                    <div style="font-size:12.5px;">Click <strong>+ Add / Select Gallery Photos</strong> above to upload or choose from the Media Library.</div>
                                </div>
                            <?php else :
                                $display_ids = $meta['ofp_gallery_ids'];
                                if ( $post_thumb_id && ! in_array( $post_thumb_id, $display_ids, true ) ) {
                                    array_unshift( $display_ids, $post_thumb_id );
                                }
                                foreach ( $display_ids as $gid ) :
                                    $gid = (int) $gid;
                                    $thumb_url = wp_get_attachment_image_url( $gid, 'medium' ) ?: wp_get_attachment_image_url( $gid, 'full' );
                                    if ( ! $thumb_url ) continue;
                                    $is_cover = ( $gid === $post_thumb_id );
                                ?>
                                    <div class="ofp-gallery-card <?php echo $is_cover ? 'is-cover' : ''; ?>" data-id="<?php echo esc_attr( $gid ); ?>">
                                        <div class="ofp-gallery-thumb-wrap">
                                            <img src="<?php echo esc_url( $thumb_url ); ?>" alt="Gallery item">
                                            <?php if ( $is_cover ) : ?>
                                                <span class="ofp-gallery-badge-cover">★ Cover Photo</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ofp-gallery-actions">
                                            <button type="button" class="ofp-btn-move-photo ofp-btn-move-left" data-dir="-1" title="Move left">&larr;</button>
                                            <button type="button" class="ofp-btn-set-cover" title="Make this the cover photo"><?php echo $is_cover ? 'Main Cover' : 'Set as Cover'; ?></button>
                                            <button type="button" class="ofp-btn-move-photo ofp-btn-move-right" data-dir="1" title="Move right">&rarr;</button>
                                            <button type="button" class="ofp-btn-remove-photo" title="Remove photo">&times;</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Video Walkthrough Tour URL -->
                        <div class="ofp-form-group" style="margin-top:28px;padding-top:20px;border-top:1px solid #e2e8f0;">
                            <label class="ofp-form-label">Virtual Video Tour URL (YouTube, Vimeo, or MP4 link)</label>
                            <input type="url" name="ofp_video_url" id="ofp_video_url_input" class="ofp-input-text"
                                   value="<?php echo esc_attr( $meta['ofp_video_url'] ); ?>" placeholder="https://www.youtube.com/watch?v=... or Vimeo link">
                            <span class="ofp-field-hint">Paste a walkthrough video link. A live player preview will render below automatically.</span>

                            <div class="ofp-video-preview-wrap" id="ofp_video_preview_wrap"></div>
                        </div>
                    </div>

                    <!-- ── TAB 5: Amenities & Features ────────────────────────────── -->
                    <div class="ofp-studio-panel" data-panel="amenities">
                        <div class="ofp-panel-header">
                            <div>
                                <h3>Amenities &amp; Facility Provisions</h3>
                                <p>Select on-site residential security, power utilities, smart automation, and leisure features.</p>
                            </div>
                        </div>

                        <div class="ofp-amenities-actions">
                            <button type="button" class="ofp-btn-tiny" id="ofp-btn-amenities-all">+ Select All</button>
                            <button type="button" class="ofp-btn-tiny" id="ofp-btn-amenities-none">&times; Deselect All</button>
                        </div>

                        <?php
                        $amenities_catalog = [
                            'swimming_pool'   => [
                                'label' => 'Swimming Pool',
                                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5c1.125 0 2.25-.45 3.375-1.35 1.125.9 2.25 1.35 3.375 1.35s2.25-.45 3.375-1.35c1.125.9 2.25 1.35 3.375 1.35M3.75 18c1.125 0 2.25-.45 3.375-1.35 1.125.9 2.25 1.35 3.375 1.35s2.25-.45 3.375-1.35c1.125.9 2.25 1.35 3.375 1.35M12 6a3 3 0 100-6 3 3 0 000 6z" /></svg>',
                            ],
                            'smart_home'      => [
                                'label' => 'Smart Home Automation',
                                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V10.53a1.875 1.875 0 00-.67-1.442l-6.75-5.4a1.875 1.875 0 00-2.36 0l-6.75 5.4a1.875 1.875 0 00-.67 1.442V21h4.5z" /></svg>',
                            ],
                            'power_247'       => [
                                'label' => '24/7 Electricity / Gen',
                                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>',
                            ],
                            'cctv_security'   => [
                                'label' => 'CCTV & Uniformed Security',
                                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>',
                            ],
                            'gym'             => [
                                'label' => 'Gym / Fitness Center',
                                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-15-4.5h1.5v9h-1.5zm12 0h1.5v9h-1.5zM2.25 9.75h1.5v4.5h-1.5zm18 0h1.5v4.5h-1.5z" /></svg>',
                            ],
                            'elevator'        => [
                                'label' => 'Elevator / Lift',
                                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h18v15H3zM8.25 9.75l2.25-2.25 2.25 2.25M12.75 14.25l-2.25 2.25-2.25-2.25" /></svg>',
                            ],
                            'playground'      => [
                                'label' => "Children's Play Area",
                                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" /></svg>',
                            ],
                            'bq'              => [
                                'label' => 'Boys Quarters (BQ)',
                                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m-6-13.5h1.5m-1.5 4.5h1.5m-1.5 4.5h1.5m7.5-9h1.5m-1.5 4.5h1.5m-1.5 4.5h1.5" /></svg>',
                            ],
                            'water_treatment' => [
                                'label' => 'Water Treatment Plant',
                                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2.25c-5.385 7.18-7.5 10.686-7.5 13.5a7.5 7.5 0 1015 0c0-2.814-2.115-6.32-7.5-13.5z" /></svg>',
                            ],
                            'fitted_kitchen'  => [
                                'label' => 'Fully Fitted Kitchen',
                                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19 14.5M14.25 3.104c.251.023.501.05.75.082M19 14.5l-2.5 6.5H7.5L5 14.5m14 0H5" /></svg>',
                            ],
                        ];
                        if ( class_exists( 'OFP_Property_Amenities' ) ) {
                            foreach ( OFP_Property_Amenities::get_all() as $ckey => $cdata ) {
                                if ( ! isset( $amenities_catalog[ $ckey ] ) ) {
                                    $amenities_catalog[ $ckey ] = [
                                        'label' => $cdata['label'],
                                        'svg'   => OFP_Property_Amenities::get_icon_svg( $ckey . ' ' . $cdata['label'], 'w-4 h-4' ),
                                    ];
                                }
                            }
                        }
                        $selected_amenities = is_array( $meta['ofp_amenities'] ) ? $meta['ofp_amenities'] : [];
                        ?>

                        <div class="ofp-amenities-grid">
                            <?php foreach ( $amenities_catalog as $akey => $data ) :
                                $is_checked = in_array( $akey, $selected_amenities, true );
                            ?>
                                <label class="ofp-amenity-card <?php echo $is_checked ? 'is-selected' : ''; ?>">
                                    <input type="checkbox" name="ofp_amenities[]" value="<?php echo esc_attr( $akey ); ?>"
                                        <?php checked( $is_checked ); ?>>
                                    <span class="ofp-amenity-card-label">
                                        <span class="ofp-amenity-icon"><?php echo $data['svg']; ?></span>
                                        <span><?php echo esc_html( $data['label'] ); ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ── TAB 6: Owner & Banking ─────────────────────────────────── -->
                    <div class="ofp-studio-panel" data-panel="ownership">
                        <div class="ofp-panel-header">
                            <div>
                                <h3>Property Ownership &amp; Receiving Bank Details</h3>
                                <p>Link this property to an approved client and select the bank account where buyer/tenant transfers will be routed.</p>
                            </div>
                        </div>

                        <div class="ofp-form-grid-2">
                            <div class="ofp-form-group">
                                <label class="ofp-form-label">Client (Property Owner / Agent) <span class="ofp-req">*</span></label>
                                <?php if ( $has_commerce ) : ?>
                                    <input type="hidden" name="ofp_client_id" value="<?php echo esc_attr( $meta['ofp_client_id'] ); ?>">
                                    <select id="ofp_client_id_select" disabled class="ofp-input-select" style="opacity:0.8;">
                                        <?php if ( (string) $meta['ofp_client_id'] === '0' ) : ?>
                                            <option selected>Admin (Internal Platform)</option>
                                        <?php else :
                                            $c_owner = $wpdb->get_var( $wpdb->prepare(
                                                "SELECT CONCAT(business_name, ' (', owner_name, ')') FROM {$p}ofp_clients WHERE id = %d LIMIT 1",
                                                $meta['ofp_client_id']
                                            ) );
                                        ?>
                                            <option selected><?php echo esc_html( $c_owner ?: 'Client #' . $meta['ofp_client_id'] ); ?></option>
                                        <?php endif; ?>
                                    </select>
                                    <p style="color:#ef4444;font-size:12px;margin:5px 0 0;font-weight:600;display:flex;align-items:center;gap:4px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                                        Owner locked — active financial transactions exist for this property.
                                    </p>
                                <?php else : ?>
                                    <select name="ofp_client_id" id="ofp_client_id_select" class="ofp-input-select">
                                        <option value="">— Select Owner / Client —</option>
                                        <option value="0" <?php selected( (string) $meta['ofp_client_id'], '0' ); ?>>Admin (Internal Platform Listing)</option>
                                        <?php foreach ( $clients as $c ) : ?>
                                            <option value="<?php echo esc_attr( $c->id ); ?>" <?php selected( $meta['ofp_client_id'], $c->id ); ?>>
                                                <?php echo esc_html( $c->business_name . ' (' . $c->owner_name . ')' ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>

                            <div class="ofp-form-group">
                                <label class="ofp-form-label">Receiving Bank Account for Transfers</label>
                                <select name="ofp_bank_account_id" id="ofp_bank_account_select" class="ofp-input-select"
                                        data-selected="<?php echo (int) $meta['ofp_bank_account_id']; ?>">
                                    <option value="0">Default Primary Account</option>
                                </select>
                                <span class="ofp-field-hint">Transfers from buyers or tenants will be instructed to pay into this account.</span>
                            </div>

                            <!-- Client Detail Card -->
                            <div class="ofp-field-full">
                                <div class="ofp-owner-card" id="ofp_client_preview_card">
                                    <div class="ofp-owner-info">
                                        <div class="ofp-owner-avatar" id="ofp_client_avatar">OF</div>
                                        <div>
                                            <div class="ofp-owner-name" id="ofp_client_name">Platform Core Asset</div>
                                            <div class="ofp-owner-meta" id="ofp_client_meta">Select a client above to load account details</div>
                                        </div>
                                    </div>
                                    <span class="ofp-pill" id="ofp_client_plan_badge" style="background:#e2e8f0;color:#334155;">PLAN</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── TAB 7: Deals & Pipeline ──────────────────────── -->
                    <div class="ofp-studio-panel" data-panel="deals">
                        <div class="ofp-panel-header">
                            <div>
                                <h3>Deals, Installments &amp; Tenancy Records</h3>
                                <p>Live financial overview of all buyer purchases, open offers, and active tenant leases attached to this property.</p>
                            </div>
                        </div>

                        <?php if ( empty( $purchases ) && empty( $offers ) && empty( $leases ) ) : ?>
                            <div class="ofp-gallery-empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0l-3-3m3 3l3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
                                <div style="font-weight:600;font-size:14px;color:#1e293b;margin-bottom:4px;">No active transactions yet</div>
                                <div style="font-size:12.5px;">When buyers purchase or submit offers, or when tenants are leased this property, transactions will be tracked here.</div>
                                <?php if ( $inquiry_count > 0 ) : ?>
                                    <p style="margin-top:12px;font-weight:600;color:#2563eb;display:flex;align-items:center;gap:6px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                                        <span><?php echo (int) $inquiry_count; ?> inquiry / lead(s) recorded for this listing.</span>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-leads' ) ); ?>">View Leads &rarr;</a>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php else : ?>
                            <div class="ofp-deals-card-grid">
                                <!-- Purchases -->
                                <?php foreach ( $purchases as $pu ) : ?>
                                    <div class="ofp-deal-item-card">
                                        <div class="ofp-deal-header">
                                            <div class="ofp-deal-buyer"><?php echo esc_html( $pu->buyer_name ); ?></div>
                                            <span class="ofp-pill ofp-pill-<?php echo esc_attr( $pu->status === 'active' ? 'pending' : ( $pu->status === 'completed' ? 'live' : 'expired' ) ); ?>">
                                                <?php echo esc_html( ucfirst( $pu->status ) ); ?>
                                            </span>
                                        </div>
                                        <div class="ofp-deal-row">
                                            <span class="ofp-deal-label">Total Price:</span>
                                            <span class="ofp-deal-value">₦<?php echo esc_html( number_format( (float) $pu->total_price, 2 ) ); ?></span>
                                        </div>
                                        <div class="ofp-deal-row">
                                            <span class="ofp-deal-label">Amount Paid:</span>
                                            <span class="ofp-deal-value" style="color:#10b981;">₦<?php echo esc_html( number_format( (float) $pu->amount_paid, 2 ) ); ?></span>
                                        </div>
                                        <div class="ofp-deal-row">
                                            <span class="ofp-deal-label">Balance:</span>
                                            <span class="ofp-deal-value" style="color:#ef4444;">₦<?php echo esc_html( number_format( (float) $pu->balance, 2 ) ); ?></span>
                                        </div>
                                        <div class="ofp-deal-actions">
                                            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-purchases&id=' . $pu->id ) ); ?>" class="button button-small" target="_blank">
                                                Open Deal Management &rarr;
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Leases -->
                                <?php foreach ( $leases as $ls ) : ?>
                                    <div class="ofp-deal-item-card" style="border-left:4px solid #8b5cf6;">
                                        <div class="ofp-deal-header">
                                            <div class="ofp-deal-buyer">Tenant: <?php echo esc_html( $ls->tenant_name ?: 'Unnamed Tenant' ); ?></div>
                                            <span class="ofp-pill" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe;">Lease <?php echo esc_html( ucfirst( $ls->status ) ); ?></span>
                                        </div>
                                        <div class="ofp-deal-row">
                                            <span class="ofp-deal-label">Rent Rate:</span>
                                            <span class="ofp-deal-value">₦<?php echo esc_html( number_format( (float) $ls->rent_amount, 2 ) ); ?></span>
                                        </div>
                                        <div class="ofp-deal-row">
                                            <span class="ofp-deal-label">Duration:</span>
                                            <span class="ofp-deal-value"><?php echo esc_html( $ls->start_date . ' to ' . $ls->end_date ); ?></span>
                                        </div>
                                        <div class="ofp-deal-actions">
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-rent-oversight' ) ); ?>" class="button button-small" target="_blank">
                                                Rent Oversight &rarr;
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Offers -->
                                <?php foreach ( $offers as $off ) : ?>
                                    <div class="ofp-deal-item-card" style="border-left:4px solid #f59e0b;">
                                        <div class="ofp-deal-header">
                                            <div class="ofp-deal-buyer">Offer: <?php echo esc_html( $off->buyer_name ); ?></div>
                                            <span class="ofp-pill ofp-pill-pending"><?php echo esc_html( ucfirst( $off->status ) ); ?></span>
                                        </div>
                                        <div class="ofp-deal-row">
                                            <span class="ofp-deal-label">Offered Price:</span>
                                            <span class="ofp-deal-value">₦<?php echo esc_html( number_format( (float) $off->offered_price, 2 ) ); ?></span>
                                        </div>
                                        <div class="ofp-deal-actions">
                                            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-purchases' ) ); ?>" class="button button-small" target="_blank">
                                                View in Sales &rarr;
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div> <!-- /[data-panel="deals"] -->
                        </div> <!-- /.ofp-studio-panels-container -->
                    </div> <!-- /.ofp-studio-vertical-layout -->
                </div> <!-- /.ofp-studio-main-col -->

                <!-- ── Column 2: Live Command & Preview Sidebar (Right ~32%) ── -->
                <div class="ofp-studio-sidebar-col">

                    <!-- Live Marketplace Card Preview -->
                    <div class="ofp-sidebar-card ofp-preview-widget">
                        <div class="ofp-sidebar-card-header">
                            <div class="ofp-preview-badge-live">
                                <span class="ofp-pulse-dot"></span>
                                <span>Live Card Preview</span>
                            </div>
                            <span class="ofp-preview-sub">Marketplace View</span>
                        </div>

                        <div class="ofp-card-mockup" id="ofp-preview-card">
                            <div class="ofp-card-media-wrap">
                                <img src="<?php echo esc_url( $featured_img_url ?: plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/placeholder-property.jpg' ); ?>" 
                                     id="ofp-preview-img" 
                                     alt="Property Preview"
                                     onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'400\' height=\'260\' viewBox=\'0 0 400 260\' fill=\'%230f172a\'><rect width=\'400\' height=\'260\' fill=\'%231e293b\'/><text x=\'50%25\' y=\'50%25\' dominant-baseline=\'middle\' text-anchor=\'middle\' fill=\'%2394a3b8\' font-family=\'sans-serif\' font-size=\'16\'>No Cover Photo Uploaded</text></svg>';">
                                <div class="ofp-card-badge-row">
                                    <span class="ofp-mock-pill ofp-mock-type" id="ofp-preview-type-pill">
                                        <?php echo esc_html( ucfirst( $meta['ofp_listing_type'] ?: 'sale' ) ); ?>
                                    </span>
                                    <span class="ofp-mock-pill ofp-mock-featured" id="ofp-preview-featured-pill" style="<?php echo $meta['ofp_is_featured'] === '1' ? '' : 'display:none;'; ?>">
                                        ★ Featured
                                    </span>
                                </div>
                            </div>
                            <div class="ofp-card-body">
                                <div class="ofp-card-price-row">
                                    <div class="ofp-card-price" id="ofp-preview-price">
                                        ₦<?php echo $meta['ofp_price'] ? number_format( (float) $meta['ofp_price'] ) : '0.00'; ?>
                                    </div>
                                    <span class="ofp-card-period" id="ofp-preview-period">
                                        <?php echo $meta['ofp_price_period'] === 'year' ? '/ year' : ( $meta['ofp_price_period'] === 'month' ? '/ mo' : '' ); ?>
                                    </span>
                                </div>
                                <h4 class="ofp-card-title" id="ofp-preview-title">
                                    <?php echo esc_html( $post->post_title ?: 'Untitled Luxury Listing' ); ?>
                                </h4>
                                <div class="ofp-card-location" id="ofp-preview-location">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                                    <span id="ofp-preview-location-text"><?php echo esc_html( $meta['ofp_location_text'] ?: 'Location not specified' ); ?></span>
                                </div>
                                <div class="ofp-card-specs-row" id="ofp-preview-specs-row">
                                    <span class="ofp-card-spec-item" id="ofp-preview-bed-spec" title="Bedrooms">
                                        <svg class="ofp-spec-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12V6a2.25 2.25 0 012.25-2.25h15A2.25 2.25 0 0121.75 6v6m-19.5 0v6.75A2.25 2.25 0 004.5 21h15a2.25 2.25 0 002.25-2.25V12m-19.5 0h19.5M3.75 9.75h4.5a1.5 1.5 0 011.5 1.5v.75m4.5-2.25h4.5a1.5 1.5 0 011.5 1.5v.75" /></svg>
                                        <strong id="ofp-preview-beds-val"><?php echo (int) $meta['ofp_bedrooms']; ?></strong> Beds
                                    </span>
                                    <span class="ofp-card-spec-item" id="ofp-preview-bath-spec" title="Bathrooms">
                                        <svg class="ofp-spec-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-15 0a4.5 4.5 0 009 0m-9 0V7.5a3 3 0 013-3h3a3 3 0 013 3V12m3 0v4.5a3 3 0 01-3 3H7.5a3 3 0 01-3-3V12" /></svg>
                                        <strong id="ofp-preview-baths-val"><?php echo (int) $meta['ofp_bathrooms']; ?></strong> Baths
                                    </span>
                                    <span class="ofp-card-spec-item" id="ofp-preview-car-spec" title="Parking Spaces">
                                        <svg class="ofp-spec-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h7.5m-7.5 0H4.5A2.25 2.25 0 012.25 16.5v-3.75a2.25 2.25 0 012.25-2.25h1.294a3.75 3.75 0 013.342 2.053l.314.628h5.6l.314-.628A3.75 3.75 0 0118.456 10.5H19.5A2.25 2.25 0 0121.75 12.75v3.75a2.25 2.25 0 01-2.25 2.25h-1.5m-3 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-6 0h6" /></svg>
                                        <strong id="ofp-preview-parking-val"><?php echo (int) $meta['ofp_parking']; ?></strong> Cars
                                    </span>
                                    <span class="ofp-card-spec-item" id="ofp-preview-plots-spec" style="<?php echo ( $meta['ofp_listing_type'] === 'land' ) ? '' : 'display:none;'; ?>" title="Plot Breakdown">
                                        <svg class="ofp-spec-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                                        <strong id="ofp-preview-plots-val"><?php echo esc_html( $meta['ofp_plots_count'] ? $meta['ofp_plots_count'] . ' Plot(s)' : '1 Plot' ); ?></strong>
                                    </span>
                                    <span class="ofp-card-spec-item" id="ofp-preview-sqm-spec" title="Total Area">
                                        <svg class="ofp-spec-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v16.5h16.5M6 16.5v-3m3 3v-1.5m3 1.5v-3m3 3v-1.5m3 1.5v-3" /></svg>
                                        <strong id="ofp-preview-sqm-val"><?php echo (int) $meta['ofp_area_sqm']; ?></strong> SQM
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Listing Readiness & Quality Gauge -->
                    <div class="ofp-sidebar-card">
                        <div class="ofp-sidebar-card-header">
                            <div>
                                <h4 class="ofp-sidebar-card-title">Listing Readiness</h4>
                                <p class="ofp-sidebar-card-subtitle">Complete all items for optimal conversion</p>
                            </div>
                            <span class="ofp-readiness-pill" id="ofp-readiness-pct">0%</span>
                        </div>
                        <div class="ofp-readiness-progress-track">
                            <div class="ofp-readiness-progress-fill" id="ofp-readiness-bar" style="width:0%;"></div>
                        </div>
                        <div class="ofp-readiness-checklist" id="ofp-readiness-checklist">
                            <div class="ofp-check-item" data-check="title">
                                <span class="ofp-check-icon">✓</span>
                                <span>Compelling property headline</span>
                            </div>
                            <div class="ofp-check-item" data-check="price">
                                <span class="ofp-check-icon">✓</span>
                                <span>Commercial valuation &amp; price</span>
                            </div>
                            <div class="ofp-check-item" data-check="desc">
                                <span class="ofp-check-icon">✓</span>
                                <span>Walkthrough story (> 20 words)</span>
                            </div>
                            <div class="ofp-check-item" data-check="photo">
                                <span class="ofp-check-icon">✓</span>
                                <span>High-res cover photo</span>
                            </div>
                            <div class="ofp-check-item" data-check="location">
                                <span class="ofp-check-icon">✓</span>
                                <span>Address &amp; neighborhood location</span>
                            </div>
                            <div class="ofp-check-item" data-check="specs">
                                <span class="ofp-check-icon">✓</span>
                                <span>Room specs or land area</span>
                            </div>
                            <div class="ofp-check-item" data-check="title_doc">
                                <span class="ofp-check-icon">✓</span>
                                <span>Verified title document</span>
                            </div>
                            <div class="ofp-check-item" data-check="owner">
                                <span class="ofp-check-icon">✓</span>
                                <span>Owner / client linked</span>
                            </div>
                        </div>
                    </div>

                    <!-- Publishing Command & Actions -->
                    <div class="ofp-sidebar-card">
                        <div class="ofp-sidebar-card-header">
                            <h4 class="ofp-sidebar-card-title">Publishing &amp; Status</h4>
                        </div>

                        <div class="ofp-form-group" style="margin-bottom:14px;">
                            <label class="ofp-form-label">Marketplace Status</label>
                            <select name="ofp_status" id="ofp_status_sidebar_select" class="ofp-input-select">
                                <option value="pending_upload" <?php selected( $meta['ofp_status'], 'pending_upload' ); ?>>● Pending Upload / Draft</option>
                                <option value="live"           <?php selected( $meta['ofp_status'], 'live' ); ?>>● Live (Publicly Active)</option>
                                <option value="taken"          <?php selected( $meta['ofp_status'], 'taken' ); ?>>● Taken (Sold / Rented)</option>
                                <option value="expired"        <?php selected( $meta['ofp_status'], 'expired' ); ?>>● Expired</option>
                            </select>
                        </div>

                        <div class="ofp-form-group" style="margin-bottom:16px;">
                            <label class="ofp-featured-switch-label">
                                <input type="checkbox" name="ofp_is_featured" id="ofp_is_featured_checkbox" value="1"
                                    <?php checked( $meta['ofp_is_featured'], '1' ); ?>
                                    <?php disabled( $can_feature, false ); ?>>
                                <span class="ofp-switch-slider"></span>
                                <span class="ofp-switch-text">
                                    <strong>Promote as Featured</strong>
                                    <small>Pin to top of search results</small>
                                </span>
                            </label>
                            <?php if ( ! $can_feature ) : ?>
                                <span style="display:block;margin-top:6px;color:#ef4444;font-size:11.5px;font-weight:600;">Requires Silver or Gold client plan</span>
                            <?php endif; ?>
                        </div>

                        <div class="ofp-sidebar-actions">
                            <button type="button" class="ofp-btn-studio-primary-action" id="ofp-btn-sidebar-save">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                <span>Save Property</span>
                                <kbd>Ctrl+S</kbd>
                            </button>

                            <?php if ( $is_published && $public_url ) : ?>
                                <a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener" class="ofp-btn-studio-secondary-action">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                    <span>View Live Listing</span>
                                </a>
                            <?php endif; ?>

                            <?php if ( current_user_can( 'delete_post', $post->ID ) && $post->ID ) : ?>
                                <a href="<?php echo esc_url( get_delete_post_link( $post->ID ) ); ?>" 
                                   class="ofp-btn-studio-trash-action" 
                                   onclick="return confirm('Are you sure you want to move this property to Trash?');">
                                    Move to Trash
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Assigned Owner Quick Card -->
                    <div class="ofp-sidebar-card">
                        <div class="ofp-sidebar-card-header">
                            <h4 class="ofp-sidebar-card-title">Assigned Owner</h4>
                        </div>
                        <div class="ofp-owner-card-mini" id="ofp_client_sidebar_card">
                            <div class="ofp-owner-avatar-mini" id="ofp_client_sidebar_avatar">OF</div>
                            <div class="ofp-owner-mini-info">
                                <div class="ofp-owner-mini-name" id="ofp_client_sidebar_name">Platform Core Asset</div>
                                <div class="ofp-owner-mini-meta" id="ofp_client_sidebar_meta">Internal Listing</div>
                            </div>
                            <span class="ofp-pill" id="ofp_client_sidebar_plan" style="background:#e2e8f0;color:#334155;font-size:10px;">CORE</span>
                        </div>
                    </div>

                </div> <!-- /.ofp-studio-sidebar-col -->

            </div> <!-- /.ofp-studio-workspace -->
        </div> <!-- /.ofp-studio-wrap -->
        <?php
    }

    /**
     * Save meta box data when a property post is saved.
     *
     * @param  int $post_id
     * @return void
     */
    public function save_meta( int $post_id ): void {

        if (
            ! isset( $_POST['ofp_property_nonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['ofp_property_nonce'] ) ),
                'ofp_property_meta'
            )
        ) {
            // Quick Edit has no custom meta-box nonce. Keep the business
            // status and property table aligned with its WordPress status.
            if ( is_admin() && ( $_POST['action'] ?? '' ) === 'inline-save' && ( $_POST['post_type'] ?? '' ) === 'ofp_property' ) {
                $wp_status = get_post_status( $post_id );
                if ( $wp_status === 'publish' ) {
                    update_post_meta( $post_id, 'ofp_status', 'live' );
                } elseif ( $wp_status === 'pending' ) {
                    update_post_meta( $post_id, 'ofp_status', 'pending_upload' );
                }

                // Save listing type from Quick Edit.
                if ( isset( $_POST['ofp_listing_type'] ) ) {
                    $listing_type = sanitize_text_field( wp_unslash( $_POST['ofp_listing_type'] ) );
                    if ( in_array( $listing_type, self::LISTING_TYPES, true ) ) {
                        update_post_meta( $post_id, 'ofp_listing_type', $listing_type );
                    }
                }

                // Save price period from Quick Edit.
                if ( isset( $_POST['ofp_price_period'] ) ) {
                    $price_period = sanitize_text_field( wp_unslash( $_POST['ofp_price_period'] ) );
                    if ( $price_period === 'one-time' ) $price_period = 'sales';
                    if ( in_array( $price_period, [ 'sales', 'year', '2years', 'month' ], true ) ) {
                        update_post_meta( $post_id, 'ofp_price_period', $price_period );
                    }
                }

                // Enforce: sale listing type must have 'sales' price period.
                $current_lt = get_post_meta( $post_id, 'ofp_listing_type', true );
                if ( self::is_sale_like( (string) $current_lt ) ) {
                    update_post_meta( $post_id, 'ofp_price_period', 'sales' );
                }

                $client_id = absint( get_post_meta( $post_id, 'ofp_client_id', true ) );
                self::sync_to_plugin_table( $post_id, $client_id );
            }
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Synchronize studio listing title & walkthrough content if submitted
        $studio_title = isset( $_POST['ofp_studio_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ofp_studio_title'] ) ) : null;
        $studio_content = isset( $_POST['ofp_studio_content'] ) ? wp_kses_post( wp_unslash( $_POST['ofp_studio_content'] ) ) : null;

        $post_update = [];
        if ( $studio_title !== null && $studio_title !== '' && $studio_title !== get_the_title( $post_id ) ) {
            $post_update['post_title'] = $studio_title;
        }
        if ( $studio_content !== null && $studio_content !== get_post_field( 'post_content', $post_id ) ) {
            $post_update['post_content'] = $studio_content;
        }
        if ( ! empty( $post_update ) ) {
            $post_update['ID'] = $post_id;
            remove_action( 'save_post_ofp_property', [ $this, 'save_meta' ] );
            wp_update_post( $post_update );
            add_action( 'save_post_ofp_property', [ $this, 'save_meta' ] );
        }

        $fields = [
            'ofp_client_id'       => 'absint',
            'ofp_listing_type'    => 'sanitize_text_field',
            'ofp_property_type'   => 'sanitize_text_field',
            'ofp_price'           => 'floatval',
            'ofp_price_period'    => 'sanitize_text_field',
            'ofp_bedrooms'        => 'absint',
            'ofp_bathrooms'       => 'absint',
            'ofp_parking'         => 'absint',
            'ofp_area_sqm'        => 'absint',
            'ofp_plots_count'     => 'floatval',
            'ofp_land_topography' => 'sanitize_text_field',
            'ofp_land_zoning'     => 'sanitize_text_field',
            'ofp_land_dimensions' => 'sanitize_text_field',
            'ofp_survey_status'   => 'sanitize_text_field',
            'ofp_title_document'  => 'sanitize_text_field',
            'ofp_condition'       => 'sanitize_text_field',
            'ofp_furnishing'      => 'sanitize_text_field',
            'ofp_video_url'       => 'esc_url_raw',
            'ofp_location_text'   => 'sanitize_text_field',
            'ofp_status'          => 'sanitize_text_field',
            'ofp_bank_account_id' => 'absint',
            'ofp_total_package'   => 'floatval',
            'ofp_allow_installment'=> 'sanitize_text_field',
            'ofp_inspection_days' => 'sanitize_text_field',
            'ofp_inspection_hours'=> 'sanitize_text_field',
            'ofp_inspection_notice'=> 'sanitize_text_field',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            $value = isset( $_POST[ $key ] )
                ? $sanitizer( wp_unslash( $_POST[ $key ] ) )
                : '';
            // Normalize legacy one-time -> sales.
            if ( $key === 'ofp_price_period' && $value === 'one-time' ) {
                $value = 'sales';
            }
            update_post_meta( $post_id, $key, $value );
        }

        // Save amenities array
        $amenities = isset( $_POST['ofp_amenities'] ) && is_array( $_POST['ofp_amenities'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ofp_amenities'] ) )
            : [];
        if ( class_exists( 'OFP_Property_Amenities' ) && ! empty( $amenities ) ) {
            OFP_Property_Amenities::register_custom_items( $amenities );
        }
        update_post_meta( $post_id, 'ofp_amenities', json_encode( array_values( $amenities ) ) );

        // Save gallery attachment IDs array
        if ( isset( $_POST['ofp_gallery_ids'] ) ) {
            $raw_gallery = wp_unslash( $_POST['ofp_gallery_ids'] );
            $decoded_gallery = json_decode( $raw_gallery, true );
            if ( is_array( $decoded_gallery ) ) {
                $clean_gallery = array_values( array_unique( array_filter( array_map( 'absint', $decoded_gallery ) ) ) );
                update_post_meta( $post_id, 'ofp_gallery_ids', json_encode( $clean_gallery ) );
            }
        }

        // Set Cover Photo (WordPress post thumbnail) if chosen from gallery
        if ( ! empty( $_POST['ofp_set_cover_photo'] ) ) {
            $cover_photo_id = absint( $_POST['ofp_set_cover_photo'] );
            if ( $cover_photo_id > 0 ) {
                set_post_thumbnail( $post_id, $cover_photo_id );
            }
        }

        // Enforce: sale listing type must have 'sales' price period.
        $listing_type = sanitize_text_field( wp_unslash( $_POST['ofp_listing_type'] ?? '' ) );
        if ( self::is_sale_like( $listing_type ) ) {
            update_post_meta( $post_id, 'ofp_price_period', 'sales' );
        }

        // Land listings always use the Land property type and have no rooms or parking.
        if ( $listing_type === 'land' ) {
            update_post_meta( $post_id, 'ofp_property_type', 'land' );
            update_post_meta( $post_id, 'ofp_bedrooms', 0 );
            update_post_meta( $post_id, 'ofp_bathrooms', 0 );
            update_post_meta( $post_id, 'ofp_parking', 0 );
            $plots_val = (float) ( $_POST['ofp_plots_count'] ?? 0 );
            $sqm_val   = (int) ( $_POST['ofp_area_sqm'] ?? 0 );
            if ( $sqm_val <= 0 && $plots_val > 0 ) {
                update_post_meta( $post_id, 'ofp_area_sqm', (int) round( $plots_val * 600 ) );
            }
        }

        // Lock client_id if property has commerce activity.
        $client_id = absint( $_POST['ofp_client_id'] ?? 0 );
        global $wpdb;
        $p = $wpdb->prefix;
        $existing_prop = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_id FROM {$p}ofp_properties WHERE wp_post_id = %d LIMIT 1",
            $post_id
        ) );
        if ( $existing_prop ) {
            $has_commerce = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$p}ofp_property_purchases WHERE property_id = %d LIMIT 1",
                (int) $existing_prop->id
            ) );
            if ( ! $has_commerce ) {
                $has_commerce = (bool) $wpdb->get_var( $wpdb->prepare(
                    "SELECT 1 FROM {$p}ofp_property_offers WHERE property_id = %d LIMIT 1",
                    (int) $existing_prop->id
                ) );
            }
            if ( $has_commerce ) {
                // Keep existing owner — ignore submitted client_id.
                $client_id = (int) $existing_prop->client_id;
                update_post_meta( $post_id, 'ofp_client_id', $client_id );
            }
        }

        // Checkbox — absent means unchecked.
        // If client is on free plan, force to 0.
        $is_featured = isset( $_POST['ofp_is_featured'] ) ? '1' : '0';
        
        if ( $client_id ) {
            $plan = 'free';
            if ( class_exists( 'OFP_Subscription' ) ) {
                $plan = OFP_Subscription::feature_plan( $client_id );
            }
            if ( $plan === 'free' ) {
                $is_featured = '0';
            }
        }
        update_post_meta( $post_id, 'ofp_is_featured', $is_featured );

        // Sync back to ofp_properties table if a client is assigned (or admin).
        self::sync_to_plugin_table( $post_id, $client_id );

        // Sync ofp_status to WP post_status
        $ofp_status = $_POST['ofp_status'] ?? 'pending_upload';
        $post_status = ( $ofp_status === 'live' ) ? 'publish' : 'draft';
        if ( get_post_status( $post_id ) !== $post_status ) {
            remove_action( 'save_post_ofp_property', [ $this, 'save_meta' ] );
            wp_update_post( [ 'ID' => $post_id, 'post_status' => $post_status ] );
            add_action( 'save_post_ofp_property', [ $this, 'save_meta' ] );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SYNC
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sync a CPT post's data back to the ofp_properties plugin table.
     *
     * This keeps the billing source-of-truth (ofp_properties) in sync
     * with the public-facing CPT post whenever an admin saves/edits.
     *
     * @param  int  $post_id      WP post ID.
     * @param  int  $client_id    OFP client ID.
     * @param  bool $notify_owner Whether to notify owner on status change.
     * @return void
     */
    public static function sync_to_plugin_table( int $post_id, int $client_id, bool $notify_owner = true ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $post = get_post( $post_id );
        if ( ! $post ) return;

        // Resolve featured image URL and gallery URLs
        $post_thumb_url = get_the_post_thumbnail_url( $post_id, 'full' ) ?: '';
        $gallery_ids = json_decode( get_post_meta( $post_id, 'ofp_gallery_ids', true ) ?: '[]', true ) ?: [];
        $gallery_urls = [];
        if ( is_array( $gallery_ids ) ) {
            foreach ( $gallery_ids as $gid ) {
                $u = wp_get_attachment_image_url( (int) $gid, 'full' );
                if ( $u ) {
                    $gallery_urls[] = $u;
                }
            }
        }

        $data = [
            'title'          => $post->post_title,
            'description'    => $post->post_content,
            'property_type'  => get_post_meta( $post_id, 'ofp_property_type', true ),
            'listing_type'   => get_post_meta( $post_id, 'ofp_listing_type',  true ) ?: 'sale',
            'price'          => (float) get_post_meta( $post_id, 'ofp_price',   true ),
            'price_period'   => get_post_meta( $post_id, 'ofp_price_period',   true ),
            'bedrooms'       => (int) get_post_meta( $post_id, 'ofp_bedrooms',  true ),
            'bathrooms'      => (int) get_post_meta( $post_id, 'ofp_bathrooms', true ),
            'location_text'  => get_post_meta( $post_id, 'ofp_location_text',  true ),
            'featured_image' => $post_thumb_url ?: null,
            'gallery_images' => ! empty( $gallery_urls ) ? json_encode( $gallery_urls ) : null,
            'status'         => get_post_meta( $post_id, 'ofp_status',          true ) ?: 'pending_upload',
            'is_featured'    => (int) get_post_meta( $post_id, 'ofp_is_featured', true ),
            'wp_post_id'     => $post_id,
            'updated_at'     => current_time( 'mysql' ),
        ];

        // Check if a row already exists for this wp_post_id.
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status FROM {$p}ofp_properties WHERE wp_post_id = %d LIMIT 1",
                $post_id
            )
        );

        $old_status = $existing ? $existing->status : '';

        if ( $existing ) {
            $data['client_id'] = $client_id;
            $wpdb->update( $p . 'ofp_properties', $data, [ 'wp_post_id' => $post_id ] );
        } else {
            $data['client_id']  = $client_id;
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $p . 'ofp_properties', $data );
        }
        
        $new_status = $data['status'];
        
        // Notify owner if status changes to pending_upload or live
        if ( $notify_owner && $client_id && $old_status !== $new_status && in_array( $new_status, ['pending_upload', 'live'] ) ) {
            $client = $wpdb->get_row( $wpdb->prepare( "SELECT email, owner_name FROM {$p}ofp_clients WHERE id = %d LIMIT 1", $client_id ) );
            if ( $client && $client->email ) {
                $subject = ( $new_status === 'live' ) ? 'Your Property is Live!' : 'Property Submitted for Review';
                $message = ( $new_status === 'live' ) 
                    ? "Hello {$client->owner_name},<br><br>Good news! Your property <strong>{$data['title']}</strong> has been approved and is now live on our platform."
                    : "Hello {$client->owner_name},<br><br>Your property <strong>{$data['title']}</strong> has been successfully submitted and is currently awaiting admin approval.";
                
                if ( class_exists( 'OFP_Mailer' ) ) {
                    OFP_Mailer::send( $client->email, $client->owner_name ?: 'there', $subject, $message );
                }
                if ( class_exists( 'OFP_Notification' ) ) {
                    OFP_Notification::create( $client_id, 'property_status_changed', $subject, wp_strip_all_tags( $message ) );
                }
            }
        }
    }

    /**
     * Ensure every published CPT listing has a current commerce-table record
     * with correct status and listing_type.
     *
     * Also fixes NULL listing_type values in the DB table (defaults to 'sale').
     */
    public static function reconcile_live_property_records(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ofp_properties';

        // 1. Fix any NULL/empty listing_type in the ofp_properties table.
        $wpdb->query( "UPDATE {$table} SET listing_type = 'sale' WHERE listing_type IS NULL OR listing_type = ''" );

        // 2. Find all published CPT posts (regardless of ofp_status meta).
        $post_ids = get_posts( [
            'post_type'      => 'ofp_property',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'ofp_include_occupied' => true,
        ] );

        foreach ( $post_ids as $post_id ) {
            $post_id = (int) $post_id;

            // Ensure ofp_status meta is 'live' for published posts.
            if ( get_post_meta( $post_id, 'ofp_status', true ) !== 'live' ) {
                update_post_meta( $post_id, 'ofp_status', 'live' );
            }

            // Ensure ofp_listing_type meta is set.
            if ( ! get_post_meta( $post_id, 'ofp_listing_type', true ) ) {
                update_post_meta( $post_id, 'ofp_listing_type', 'sale' );
            }

            // Resolve client_id and sync to plugin table.
            $stored_client_id = get_post_meta( $post_id, 'ofp_client_id', true );
            if ( $stored_client_id === '' ) {
                $stored_client_id = $wpdb->get_var( $wpdb->prepare( "SELECT client_id FROM {$table} WHERE wp_post_id = %d LIMIT 1", $post_id ) );
            }
            $client_id = absint( $stored_client_id );
            self::sync_to_plugin_table( $post_id, $client_id, false );
        }
    }

    /**
     * Create a CPT post from an ofp_properties plugin table row.
     * Called when a property is created programmatically (e.g. from admin client form).
     *
     * @param  array $property_data  Data matching ofp_properties columns.
     * @param  int   $client_id      OFP client ID.
     * @return int                   New WP post ID, or 0 on failure.
     */
    public static function create_from_plugin_data( array $property_data, int $client_id ): int {
        $post_id = wp_insert_post( [
            'post_title'   => sanitize_text_field( $property_data['title'] ?? 'New Property' ),
            'post_content' => wp_kses_post( $property_data['description'] ?? '' ),
            'post_status'  => ( ( $property_data['status'] ?? 'pending_upload' ) === 'live' ) ? 'publish' : 'draft',
            'post_type'    => 'ofp_property',
        ] );

        if ( is_wp_error( $post_id ) ) {
            error_log( '[OFP_Property_CPT] Failed to create CPT post: ' . $post_id->get_error_message() );
            return 0;
        }

        $meta_fields = [
            'ofp_client_id', 'ofp_listing_type', 'ofp_property_type',
            'ofp_price', 'ofp_price_period', 'ofp_bedrooms',
            'ofp_bathrooms', 'ofp_location_text', 'ofp_status', 'ofp_is_featured',
        ];

        // Map snake_case property data keys to post meta keys.
        $key_map = [
            'ofp_client_id'     => $client_id,
            'ofp_listing_type'  => $property_data['listing_type']  ?? '',
            'ofp_property_type' => $property_data['property_type'] ?? '',
            'ofp_price'         => $property_data['price']         ?? '',
            'ofp_price_period'  => $property_data['price_period']  ?? '',
            'ofp_bedrooms'      => $property_data['bedrooms']      ?? '',
            'ofp_bathrooms'     => $property_data['bathrooms']     ?? '',
            'ofp_location_text' => $property_data['location_text'] ?? '',
            'ofp_status'        => $property_data['status']        ?? 'pending_upload',
            'ofp_is_featured'   => $property_data['is_featured']   ?? '0',
        ];

        foreach ( $key_map as $meta_key => $meta_value ) {
            update_post_meta( $post_id, $meta_key, $meta_value );
        }

        self::sync_to_plugin_table( $post_id, $client_id );

        return $post_id;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN COLUMNS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add custom columns to the property list table in wp-admin.
     *
     * @param  array $columns
     * @return array
     */
    public function custom_columns( array $columns ): array {
        unset( $columns['date'] );
        $columns['ofp_client']    = 'Client';
        $columns['ofp_location']  = 'Location';
        $columns['ofp_list_type'] = 'List Type';
        $columns['ofp_prop_type'] = 'Property Type';
        $columns['ofp_price']     = 'Price';
        $columns['ofp_status']    = 'Status';
        $columns['date']          = 'Date';
        return $columns;
    }

    /**
     * Render custom column content.
     *
     * @param  string $column   Column name.
     * @param  int    $post_id  Post ID.
     * @return void
     */
    public function render_columns( string $column, int $post_id ): void {
        switch ( $column ) {
            case 'ofp_client':
                $client_id = get_post_meta( $post_id, 'ofp_client_id', true );
                $owner_type = get_post_meta( $post_id, 'ofp_owner_type', true );
                if ( 'platform' === $owner_type || (string) $client_id === '0' ) {
                    echo esc_html__( 'Admin (Platform)', 'ofast-pipeline' );
                } elseif ( $client_id ) {
                    global $wpdb;
                    $name = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT business_name FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                            $client_id
                        )
                    );
                    echo esc_html( $name ?: '—' );
                } else {
                    echo '—';
                }
                break;

            case 'ofp_location':
                echo esc_html( get_post_meta( $post_id, 'ofp_location_text', true ) ?: '—' );
                break;

            case 'ofp_list_type':
                $lt = get_post_meta( $post_id, 'ofp_listing_type', true );
                $lt_colors = [ 'sale' => '#2563eb', 'rent' => '#8b5cf6', 'shortlet' => '#d97706', 'commercial' => '#7c3aed', 'land' => '#4d7c0f' ];
                $lt_color  = $lt_colors[ $lt ] ?? '#9ca3af';
                echo '<span style="color:' . esc_attr( $lt_color ) . ';font-weight:600;" data-listing-type="' . esc_attr( $lt ) . '">'
                    . esc_html( ucfirst( $lt ?: '—' ) )
                    . '</span>';
                break;

            case 'ofp_prop_type':
                echo esc_html( ucfirst( get_post_meta( $post_id, 'ofp_property_type', true ) ?: '—' ) );
                break;

            case 'ofp_price':
                $price  = (float) get_post_meta( $post_id, 'ofp_price',        true );
                $period = get_post_meta( $post_id, 'ofp_price_period', true );
                if ( $period === 'one-time' ) $period = 'sales';
                $period_labels = [ 'sales' => '', 'year' => 'year', '2years' => '2 years', 'month' => 'month' ];
                $period_display = $period_labels[ $period ] ?? $period;
                echo $price
                    ? '₦' . esc_html( number_format( $price, 0 ) ) . ( $period_display ? ' / ' . esc_html( $period_display ) : '' )
                    : '—';
                echo ' <span style="display:none;" data-price-period="' . esc_attr( $period ?: 'sales' ) . '"></span>';
                break;

            case 'ofp_status':
                $ctx = self::get_status_context( $post_id );
                echo '<span style="display:inline-flex;align-items:center;gap:5px;font-weight:700;color:' . esc_attr( $ctx['bg_color'] ) . ';">'
                    . '● ' . esc_html( $ctx['label'] )
                    . '</span>';
                if ( get_post_meta( $post_id, 'ofp_is_featured', true ) === '1' ) {
                    echo '<br><span style="display:inline-flex;align-items:center;gap:3px;font-size:10.5px;font-weight:700;padding:2px 6px;border-radius:100px;background:#fef3c7;color:#b45309;margin-top:4px;">★ Featured</span>';
                }
                break;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // QUICK EDIT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add a Listing Type dropdown to the Quick Edit panel.
     */
    public function quick_edit_listing_type( string $column_name, string $post_type ): void {
        if ( $column_name !== 'ofp_list_type' || $post_type !== 'ofp_property' ) return;
        ?>
        <fieldset class="inline-edit-col-right" style="clear:both;">
            <div class="inline-edit-col">
                <label class="inline-edit-group">
                    <span class="title">Listing Type</span>
                    <select name="ofp_listing_type">
                        <option value="sale">For Sale</option>
                        <option value="rent">For Rent</option>
                        <option value="shortlet">Short Let</option>
                        <option value="commercial">Commercial</option>
                        <option value="land">Land</option>
                    </select>
                </label>
                <label class="inline-edit-group">
                    <span class="title">Price Period</span>
                    <select name="ofp_price_period">
                        <option value="sales">Sales</option>
                        <option value="year">Per Year (Rent)</option>
                        <option value="2years">Per 2 Years (Rent)</option>
                        <option value="month">Per Month (Rent)</option>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Inline JS to populate the Quick Edit listing type dropdown
     * with the current row's value when the user clicks "Quick Edit".
     */
    public function quick_edit_js(): void {
        global $typenow;
        if ( $typenow !== 'ofp_property' ) return;
        ?>
        <script>
        (function($){
            var origInlineEdit = inlineEditPost.edit;
            inlineEditPost.edit = function(id){
                origInlineEdit.apply(this, arguments);
                if (typeof id === 'object') id = this.getId(id);
                var row = $('#post-' + id);
                var listingType = row.find('.column-ofp_list_type span[data-listing-type]').data('listing-type') || 'sale';
                var pricePeriod = row.find('.column-ofp_price span[data-price-period]').data('price-period') || 'sales';
                if (pricePeriod === 'one-time') pricePeriod = 'sales';
                var editRow = $('#edit-' + id);
                editRow.find('select[name="ofp_listing_type"]').val(listingType);
                editRow.find('select[name="ofp_price_period"]').val(pricePeriod);

                // Auto-set price period when listing type changes.
                editRow.find('select[name="ofp_listing_type"]').off('change.ofpPeriod').on('change.ofpPeriod', function(){
                    if ($(this).val() === 'sale' || $(this).val() === 'land') {
                        editRow.find('select[name="ofp_price_period"]').val('sales');
                    } else {
                        var curPeriod = editRow.find('select[name="ofp_price_period"]').val();
                        if (curPeriod === 'sales') {
                            editRow.find('select[name="ofp_price_period"]').val('year');
                        }
                    }
                });
            };
        })(jQuery);
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC TEMPLATES & PRICING HELPERS (Phase 14)
    // ─────────────────────────────────────────────────────────────────────────

    public function load_templates( string $template ): string {
        // Phase 16: property.crmdomain.com's homepage IS the property
        // archive — nicer than making visitors go to /properties/ on their
        // own subdomain.
        if ( is_front_page() && OFP_Host_Router::current_zone() === 'property' ) {
            $theme_override = locate_template( 'archive-ofp_property.php' );
            if ( $theme_override ) return $theme_override;
            if ( file_exists( OFP_PATH . 'public/templates/property-marketplace.php' ) ) {
                return OFP_PATH . 'public/templates/property-marketplace.php';
            }
            return OFP_PATH . 'public/templates/property-archive.php';
        }

        // Main site front page: load modern property-homepage.php
        if ( ( is_front_page() || is_home() ) && OFP_Host_Router::current_zone() !== 'app' ) {
            if ( file_exists( OFP_PATH . 'public/templates/property-homepage.php' ) ) {
                return OFP_PATH . 'public/templates/property-homepage.php';
            }
        }

        if ( is_post_type_archive( 'ofp_property' ) && OFP_Host_Router::current_zone() !== 'app' ) {
            $theme_override = locate_template( 'archive-ofp_property.php' );
            if ( $theme_override ) return $theme_override;
            if ( file_exists( OFP_PATH . 'public/templates/property-marketplace.php' ) ) {
                return OFP_PATH . 'public/templates/property-marketplace.php';
            }
            return OFP_PATH . 'public/templates/property-archive.php';
        }

        if ( is_singular( 'ofp_property' ) ) {
            $theme_override = locate_template( 'single-ofp_property.php' );
            if ( $theme_override ) return $theme_override;
            if ( file_exists( OFP_PATH . 'public/templates/property-single-detail.php' ) ) {
                return OFP_PATH . 'public/templates/property-single-detail.php';
            }
            return OFP_PATH . 'public/templates/property-single.php';
        }

        return $template;
    }

    /**
     * Makes every property permalink (the_permalink(), get_permalink(),
     * etc. — anywhere in the codebase) use property.{base_domain}
     * instead of your main site address. This is what makes the property
     * cards on the archive page link correctly to
     * property.crmdomain.com/property/{slug}/ instead of back to your
     * main domain, with no changes needed to property-archive.php itself.
     *
     * As a side effect, this also prevents WordPress's canonical-redirect
     * feature from trying to bounce visitors away from
     * property.crmdomain.com back to your main domain — that check
     * compares against get_permalink(), which this filter has already
     * corrected, so there's nothing left for it to "fix."
     *
     * @param string  $post_link
     * @param WP_Post $post
     * @return string
     */
    public function rewrite_permalink_to_property_subdomain( string $post_link, WP_Post $post ): string {
        if ( $post->post_type !== 'ofp_property' ) {
            return $post_link;
        }

        // On a solo company (white label) domain, keep the property permalink on that custom domain
        if ( class_exists( 'OFP_Brand' ) && OFP_Brand::is_solo_host() ) {
            $host = OFP_Host_Router::current_client_host();
            if ( $host ) {
                return preg_replace( '#^https?://[^/]+#', 'https://' . $host, $post_link );
            }
        }

        $base_domain = get_option( 'ofp_crm_base_domain' );
        if ( ! $base_domain ) {
            return $post_link;
        }

        return preg_replace( '#^https?://[^/]+#', 'https://property.' . $base_domain, $post_link );
    }

    /**
     * Backward-compatibility redirect:
     * If someone visits legacy /properties/{slug}, redirect 301 to /property/{slug}.
     * This protects existing bookmarks, shared links, and search indexing.
     */
    public function maybe_redirect_old_property_url(): void {
        $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        $segments = array_values( array_filter( explode( '/', $path ) ) );
        if ( count( $segments ) === 2 && $segments[0] === 'properties' ) {
            $slug = sanitize_title( $segments[1] );
            $post = get_page_by_path( $slug, OBJECT, 'ofp_property' );
            if ( $post && $post->post_status === 'publish' ) {
                wp_safe_redirect( get_permalink( $post ), 301 );
                exit;
            }
        }
    }

    public static function get_plan_prices(): array {
        $prices = [];
        foreach ( self::PLAN_KEYS as $plan ) {
            $prices[ $plan ] = (float) get_option( "ofp_listing_price_{$plan}", self::DEFAULT_PLAN_PRICES[ $plan ] );
        }
        return $prices;
    }

    public static function get_plan_caps(): array {
        $caps = [];
        foreach ( self::PLAN_KEYS as $plan ) {
            $caps[ $plan ] = (int) get_option( "ofp_listing_cap_{$plan}", self::DEFAULT_PLAN_CAPS[ $plan ] );
        }
        return $caps;
    }

    public static function get_plan_price( ?string $plan ): float {
        if ( ! $plan || ! in_array( $plan, self::PLAN_KEYS, true ) ) return 0.0;
        return (float) get_option( "ofp_listing_price_{$plan}", self::DEFAULT_PLAN_PRICES[ $plan ] );
    }

    public static function get_plan_cap( ?string $plan ): int {
        if ( ! $plan || ! in_array( $plan, self::PLAN_KEYS, true ) ) return 0;
        return (int) get_option( "ofp_listing_cap_{$plan}", self::DEFAULT_PLAN_CAPS[ $plan ] );
    }

    public static function save_plans( array $prices, array $caps ): bool {
        foreach ( self::PLAN_KEYS as $plan ) {
            $price = isset( $prices[ $plan ] ) ? max( 0.0, (float) $prices[ $plan ] ) : self::DEFAULT_PLAN_PRICES[ $plan ];
            $cap   = isset( $caps[ $plan ] )   ? max( 1, (int) $caps[ $plan ] )       : self::DEFAULT_PLAN_CAPS[ $plan ];
            update_option( "ofp_listing_price_{$plan}", $price );
            update_option( "ofp_listing_cap_{$plan}", $cap );
        }
        return true;
    }

    /**
     * Retrieve customizable pricing card data for all plans.
     * Merges admin settings with defaults (Free, Silver, Gold matrix).
     *
     * @return array<string, array>
     */
    public static function get_pricing_cards(): array {
        $saved  = get_option( 'ofp_pricing_cards_data', [] );
        $prices = self::get_plan_prices();
        $caps   = self::get_plan_caps();

        $defaults = [
            'free' => [
                'name'     => 'Free',
                'desc'     => 'Essential tools for getting started.',
                'features' => [
                    'Automation: Yes',
                    'Branding: None',
                    'Verification: Light',
                    'Rent Monitoring: No',
                    '1 Property Listing',
                    'Leads & Buyers Management',
                    'Transactions & Customer portal',
                    '0 Team members',
                ],
            ],
            'silver' => [
                'name'     => 'Silver',
                'desc'     => 'For growing teams and standard reporting.',
                'features' => [
                    'Automation: Yes',
                    'Branding: Partially',
                    'Verification: Full Verification',
                    'Rent Monitoring: No',
                    '3 Property Listings',
                    'Leads & Buyers Management',
                    'Transactions & Customer portal',
                    'Editable email templates',
                    '2 Team members',
                    'Standard Reports',
                ],
            ],
            'gold' => [
                'name'     => 'Gold',
                'desc'     => 'Advanced features, ROI and unlimited potential.',
                'features' => [
                    'Automation: Yes',
                    'Branding: Full',
                    'Verification: Full Verification',
                    'Rent Monitoring: Yes',
                    '10 Property Listings',
                    'Leads & Buyers Management',
                    'Transactions & Customer portal',
                    'Editable email templates',
                    '3 Team members',
                    'Installment payments & ROI/Investment',
                    'Advanced Reports',
                ],
            ],
        ];

        $cards = [];
        foreach ( self::PLAN_KEYS as $plan ) {
            $def  = $defaults[ $plan ] ?? [];
            $data = ( is_array( $saved ) && isset( $saved[ $plan ] ) && is_array( $saved[ $plan ] ) ) ? $saved[ $plan ] : [];

            $cards[ $plan ] = [
                'name'     => ! empty( $data['name'] ) ? $data['name'] : ( $def['name'] ?? ucfirst( $plan ) ),
                'desc'     => isset( $data['desc'] ) ? $data['desc'] : ( $def['desc'] ?? '' ),
                'price'    => isset( $prices[ $plan ] ) ? $prices[ $plan ] : ( $def['price'] ?? 0 ),
                'cap'      => isset( $caps[ $plan ] ) ? $caps[ $plan ] : ( $def['cap'] ?? 1 ),
                'features' => isset( $data['features'] ) && is_array( $data['features'] ) ? $data['features'] : ( $def['features'] ?? [] ),
            ];
        }

        return $cards;
    }

    /**
     * Persist dynamic pricing cards data.
     *
     * @param array $cards_data
     * @return bool
     */
    public static function save_pricing_cards( array $cards_data ): bool {
        return (bool) update_option( 'ofp_pricing_cards_data', $cards_data );
    }

    /** Every allowed listing type value. */
    const LISTING_TYPES = [ 'sale', 'rent', 'shortlet', 'commercial', 'land' ];

    /**
     * Listing types that are bought (one time or by installment) rather
     * than rented. They all use the 'sales' price period and can go
     * through offers and installment purchases.
     */
    public static function is_sale_like( string $listing_type ): bool {
        return in_array( $listing_type, [ 'sale', 'land' ], true );
    }

    public static function count_for_client( int $client_id ): int {
        $query = new WP_Query( [
            'post_type'      => 'ofp_property',
            'post_status'    => [ 'publish', 'pending', 'draft' ],
            'meta_key'       => 'ofp_client_id',
            'meta_value'     => $client_id,
            'ofp_include_occupied' => true,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        return count( $query->posts );
    }

    public static function can_add_property( int $client_id ): bool {
        if ( OFP_Subscription::is_unlimited( $client_id ) ) {
            return true; // Solo company: no listing limit.
        }
        $plan = OFP_Subscription::client_plan( $client_id );
        $cap  = self::get_plan_cap( $plan );
        return self::count_for_client( $client_id ) < $cap;
    }

    public static function get_client_properties( int $client_id ): array {
        $query = new WP_Query( [
            'post_type'      => 'ofp_property',
            'post_status'    => [ 'publish', 'pending', 'draft' ],
            'meta_key'       => 'ofp_client_id',
            'meta_value'     => $client_id,
            'ofp_include_occupied' => true,
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );
        return $query->posts;
    }

    public static function is_owned_by( int $post_id, int $client_id ): bool {
        return (int) get_post_meta( $post_id, 'ofp_client_id', true ) === $client_id;
    }

    /**
     * Resolves the comprehensive occupancy and commercial status of a property.
     * Accurately reflects Occupied vs Taken vs Available instead of generic fallbacks.
     *
     * @param int $post_id
     * @return array
     */
    public static function get_status_context( int $post_id ): array {
        static $status_cache = [];
        if ( isset( $status_cache[ $post_id ] ) ) {
            return $status_cache[ $post_id ];
        }

        $db_status    = get_post_meta( $post_id, 'ofp_status', true ) ?: 'live';
        $listing_type = get_post_meta( $post_id, 'ofp_listing_type', true ) ?: 'sale';
        $post_status  = get_post_status( $post_id );
        $is_rental    = in_array( $listing_type, [ 'rent', 'shortlet', 'commercial' ], true );

        // Short-circuit queries if status is already explicit
        $has_active_lease = false;
        $has_purchase     = false;

        if ( $db_status === 'occupied' || ( $db_status === 'taken' && $is_rental ) ) {
            $has_active_lease = true;
        } elseif ( $db_status === 'taken' ) {
            $has_purchase     = true;
        } elseif ( $db_status !== 'under_offer' && $db_status !== 'pending_upload' && $db_status !== 'expired' && $post_status === 'publish' ) {
            global $wpdb;
            $p = $wpdb->prefix;
            $prop_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}ofp_properties WHERE wp_post_id = %d LIMIT 1", $post_id ) );
            if ( $prop_id > 0 ) {
                if ( $is_rental ) {
                    $has_active_lease = (bool) $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM {$p}ofp_property_leases WHERE property_id = %d AND status = 'active' LIMIT 1",
                        $prop_id
                    ) );
                } else {
                    $has_purchase = self::property_has_active_purchase( $prop_id );
                }
            }
        }

        if ( $has_active_lease || ( $db_status === 'taken' && $is_rental ) || $db_status === 'occupied' ) {
            $status_key  = 'occupied';
            $badge_label = 'Occupied';
            $full_label  = 'Occupied / Leased';
            $bg_color    = '#8b5cf6';
            $tw_badge    = 'bg-purple-600 text-white';
            $tw_text     = 'text-purple-600 dark:text-purple-400';
        } elseif ( $db_status === 'taken' || $has_purchase ) {
            $status_key  = 'taken';
            $badge_label = ( $listing_type === 'sale' || $listing_type === 'land' ) ? 'Sold Out' : 'Taken';
            $full_label  = ( $listing_type === 'sale' || $listing_type === 'land' ) ? 'Sold / Taken' : 'Taken / Leased';
            $bg_color    = '#ef4444';
            $tw_badge    = 'bg-rose-600 text-white';
            $tw_text     = 'text-rose-600 dark:text-rose-400';
        } elseif ( $db_status === 'under_offer' ) {
            $status_key  = 'under_offer';
            $badge_label = 'Under Offer';
            $full_label  = 'Under Offer';
            $bg_color    = '#f59e0b';
            $tw_badge    = 'bg-amber-500 text-white';
            $tw_text     = 'text-amber-600 dark:text-amber-400';
        } elseif ( $db_status === 'pending_upload' || $post_status === 'pending' ) {
            $status_key  = 'pending_upload';
            $badge_label = 'Pending Review';
            $full_label  = 'Pending Review';
            $bg_color    = '#f59e0b';
            $tw_badge    = 'bg-amber-600 text-white';
            $tw_text     = 'text-amber-600 dark:text-amber-400';
        } elseif ( $db_status === 'expired' ) {
            $status_key  = 'expired';
            $badge_label = 'Expired';
            $full_label  = 'Expired';
            $bg_color    = '#ef4444';
            $tw_badge    = 'bg-red-600 text-white';
            $tw_text     = 'text-red-600 dark:text-red-400';
        } else {
            $status_key  = 'live';
            $badge_label = 'Available';
            $full_label  = 'Live / Available';
            $bg_color    = '#10b981';
            $tw_badge    = 'bg-emerald-600 text-white';
            $tw_text     = 'text-emerald-600 dark:text-emerald-400';
        }

        $res = [
            'key'          => $status_key,
            'label'        => $badge_label,
            'full_label'   => $full_label,
            'is_occupied'  => ( $status_key === 'occupied' ),
            'is_taken'     => ( $status_key === 'taken' ),
            'is_available' => ( $status_key === 'live' ),
            'is_rental'    => $is_rental,
            'bg_color'     => $bg_color,
            'tw_badge'     => $tw_badge,
            'tw_text'      => $tw_text,
        ];
        $status_cache[ $post_id ] = $res;
        return $res;
    }

    /**
     * Mark a property as taken / sold across the database and WP post meta.
     */
    public static function mark_taken( int $property_id, string $meta_status = 'taken' ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->update(
            "{$p}ofp_properties",
            [ 'status' => 'taken', 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $property_id ]
        );

        $wp_post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_post_id FROM {$p}ofp_properties WHERE id = %d LIMIT 1",
            $property_id
        ) );
        if ( $wp_post_id ) {
            update_post_meta( (int) $wp_post_id, 'ofp_status', $meta_status );
        }
    }

    /**
     * Mark a property as available / live across the database and WP post meta.
     */
    public static function mark_available( int $property_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->update(
            "{$p}ofp_properties",
            [ 'status' => 'available', 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $property_id ]
        );

        $wp_post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_post_id FROM {$p}ofp_properties WHERE id = %d LIMIT 1",
            $property_id
        ) );
        if ( $wp_post_id ) {
            update_post_meta( (int) $wp_post_id, 'ofp_status', 'live' );
            if ( 'publish' !== get_post_status( (int) $wp_post_id ) ) {
                wp_update_post( [ 'ID' => (int) $wp_post_id, 'post_status' => 'publish' ] );
            }
        }
    }

    /**
     * Check if a property currently has an active or completed purchase.
     */
    public static function property_has_active_purchase( int $property_id ): bool {
        global $wpdb;
        $p = $wpdb->prefix;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}ofp_property_purchases WHERE property_id = %d AND status IN ('active', 'completed') LIMIT 1",
            $property_id
        ) );
    }

    /**
     * Check if a property is currently eligible for sale or installment.
     */
    public static function is_property_available_for_sale( int $property_id ): bool {
        global $wpdb;
        $p = $wpdb->prefix;
        $property = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status, listing_type FROM {$p}ofp_properties WHERE id = %d LIMIT 1",
            $property_id
        ) );
        if ( ! $property ) return false;
        if ( ! self::is_sale_like( (string) $property->listing_type ) ) return false;
        if ( 'taken' === $property->status ) return false;
        return ! self::property_has_active_purchase( $property_id );
    }
}
