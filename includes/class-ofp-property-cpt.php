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
        'silver' => 15000.00,
        'gold'   => 30000.00,
    ];

    const DEFAULT_PLAN_CAPS = [
        'free'   => 1,
        'silver' => 5,
        'gold'   => 10,
    ];

    public function __construct() {
        add_action( 'init',                    [ $this, 'register_post_type' ] );
        add_action( 'init',                    [ $this, 'register_taxonomies' ] );
        add_action( 'add_meta_boxes',          [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_ofp_property',  [ $this, 'save_meta' ] );
        add_filter( 'manage_ofp_property_posts_columns',       [ $this, 'custom_columns' ] );
        add_action( 'manage_ofp_property_posts_custom_column', [ $this, 'render_columns' ], 10, 2 );
        add_action( 'quick_edit_custom_box',                   [ $this, 'quick_edit_listing_type' ], 10, 2 );
        add_action( 'admin_footer-edit.php',                   [ $this, 'quick_edit_js' ] );
        add_filter( 'template_include',                        [ $this, 'load_templates' ] );
        add_filter( 'post_type_link',                          [ $this, 'rewrite_permalink_to_property_subdomain' ], 10, 2 );
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
            'has_archive'         => true,
            'rewrite'             => [ 'slug' => 'properties', 'with_front' => false ],
            'supports'            => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
            'menu_icon'           => 'dashicons-building',
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ] );

        // Flush rewrite rules when the CPT is first registered.
        // We check a transient to only flush once, not on every page load.
        if ( get_transient( 'ofp_flush_rewrite_rules' ) ) {
            flush_rewrite_rules();
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
     * Register meta boxes on the property CPT edit screen.
     * @return void
     */
    public function add_meta_boxes(): void {
        add_meta_box(
            'ofp_property_details',
            'Property Details',
            [ $this, 'render_meta_box' ],
            'ofp_property',
            'normal',
            'high'
        );
    }

    /**
     * Render the property details meta box.
     *
     * @param  WP_Post $post
     * @return void
     */
    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'ofp_property_meta', 'ofp_property_nonce' );

        $meta = [
            'ofp_client_id'      => get_post_meta( $post->ID, 'ofp_client_id',     true ),
            'ofp_listing_type'   => get_post_meta( $post->ID, 'ofp_listing_type',  true ),
            'ofp_property_type'  => get_post_meta( $post->ID, 'ofp_property_type', true ),
            'ofp_price'          => get_post_meta( $post->ID, 'ofp_price',         true ),
            'ofp_price_period'   => get_post_meta( $post->ID, 'ofp_price_period',  true ),
            'ofp_bedrooms'       => get_post_meta( $post->ID, 'ofp_bedrooms',      true ),
            'ofp_bathrooms'      => get_post_meta( $post->ID, 'ofp_bathrooms',     true ),
            'ofp_parking'        => get_post_meta( $post->ID, 'ofp_parking',        true ),
            'ofp_area_sqm'       => get_post_meta( $post->ID, 'ofp_area_sqm',       true ),
            'ofp_title_document' => get_post_meta( $post->ID, 'ofp_title_document', true ),
            'ofp_condition'      => get_post_meta( $post->ID, 'ofp_condition',      true ),
            'ofp_furnishing'     => get_post_meta( $post->ID, 'ofp_furnishing',     true ),
            'ofp_video_url'      => get_post_meta( $post->ID, 'ofp_video_url',      true ),
            'ofp_amenities'      => json_decode( get_post_meta( $post->ID, 'ofp_amenities', true ) ?: '[]', true ) ?: [],
            'ofp_location_text'  => get_post_meta( $post->ID, 'ofp_location_text',  true ),
            'ofp_is_featured'    => get_post_meta( $post->ID, 'ofp_is_featured',   true ),
            'ofp_status'         => get_post_meta( $post->ID, 'ofp_status',        true ),
        ];

        // Get all active clients for the dropdown.
        global $wpdb;
        $clients = $wpdb->get_results(
            "SELECT id, business_name, owner_name FROM {$wpdb->prefix}ofp_clients
             WHERE status = 'active' ORDER BY business_name ASC"
        );
        ?>
        <style>
            .ofp-meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; padding:12px 0; }
            .ofp-meta-field { display:flex; flex-direction:column; gap:4px; }
            .ofp-meta-field label { font-size:12px; font-weight:600; color:#374151; }
            .ofp-meta-field input,
            .ofp-meta-field select { padding:6px 10px; border:1px solid #ddd; border-radius:4px; font-size:13px; }
        </style>

        <?php
        // Check if property has commerce activity (purchases, offers, or payment records).
        $has_commerce = false;
        $existing_prop = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_properties WHERE wp_post_id = %d LIMIT 1",
            $post->ID
        ) );
        if ( $existing_prop ) {
            $prop_id = (int) $existing_prop->id;
            $has_commerce = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}ofp_property_purchases WHERE property_id = %d LIMIT 1",
                $prop_id
            ) );
            if ( ! $has_commerce ) {
                $has_commerce = (bool) $wpdb->get_var( $wpdb->prepare(
                    "SELECT 1 FROM {$wpdb->prefix}ofp_property_offers WHERE property_id = %d LIMIT 1",
                    $prop_id
                ) );
            }
        }
        ?>
        <div class="ofp-meta-grid">
            <div class="ofp-meta-field" style="grid-column:1/-1;">
                <label>Client (Property Owner / Agent)</label>
                <?php if ( $has_commerce ) : ?>
                    <input type="hidden" name="ofp_client_id" value="<?php echo esc_attr( $meta['ofp_client_id'] ); ?>">
                    <select disabled style="opacity:0.7;">
                        <?php if ( (string) $meta['ofp_client_id'] === '0' ) : ?>
                            <option selected>Admin (Internal)</option>
                        <?php else :
                            $owner_name = $wpdb->get_var( $wpdb->prepare(
                                "SELECT CONCAT(business_name, ' (', owner_name, ')') FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                                $meta['ofp_client_id']
                            ) );
                        ?>
                            <option selected><?php echo esc_html( $owner_name ?: 'Client #' . $meta['ofp_client_id'] ); ?></option>
                        <?php endif; ?>
                    </select>
                    <p style="color:#ef4444;font-size:11px;margin:4px 0 0;">Owner cannot be changed — this property has active purchases or offers.</p>
                <?php else : ?>
                    <select name="ofp_client_id">
                        <option value="">— Select Client —</option>
                        <option value="0" data-ofp-platform="1" <?php selected( $meta['ofp_client_id'], '0' ); ?>>Admin (Internal)</option>
                        <?php foreach ( $clients as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->id ); ?>"
                                <?php selected( $meta['ofp_client_id'], $c->id ); ?>>
                                <?php echo esc_html( $c->business_name . ' (' . $c->owner_name . ')' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="ofp-meta-field">
                <label>Listing Type</label>
                <select name="ofp_listing_type">
                    <option value="sale" <?php selected( $meta['ofp_listing_type'], 'sale' ); ?>>For Sale</option>
                    <option value="rent" <?php selected( $meta['ofp_listing_type'], 'rent' ); ?>>For Rent</option>
                    <option value="shortlet" <?php selected( $meta['ofp_listing_type'], 'shortlet' ); ?>>Short Let</option>
                    <option value="commercial" <?php selected( $meta['ofp_listing_type'], 'commercial' ); ?>>Commercial</option>
                    <option value="land" <?php selected( $meta['ofp_listing_type'], 'land' ); ?>>Land</option>
                </select>
            </div>

            <div class="ofp-meta-field">
                <label>Property Type</label>
                <select name="ofp_property_type">
                    <?php
                    $types = [ 'apartment' => 'Apartment', 'duplex' => 'Duplex', 'semi-detached' => 'Semi-Detached', 'bungalow' => 'Bungalow',
                               'terrace'   => 'Terrace', 'land' => 'Land', 'office' => 'Office',
                               'shop'      => 'Shop', 'warehouse' => 'Warehouse', 'other' => 'Other' ];
                    foreach ( $types as $val => $label ) :
                    ?>
                        <option value="<?php echo esc_attr( $val ); ?>"
                            <?php selected( $meta['ofp_property_type'], $val ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ofp-meta-field">
                <label>Price (NGN)</label>
                <input type="number" name="ofp_price" value="<?php echo esc_attr( $meta['ofp_price'] ); ?>" placeholder="e.g. 1500000">
            </div>

            <div class="ofp-meta-field">
                <label>Price Period</label>
                <?php $pp = $meta['ofp_price_period']; if ( $pp === 'one-time' ) $pp = 'sales'; ?>
                <select name="ofp_price_period">
                    <option value="sales"  <?php selected( $pp, 'sales' ); ?>>Sales</option>
                    <option value="year"   <?php selected( $pp, 'year' ); ?>>Per Year (Rent)</option>
                    <option value="2years" <?php selected( $pp, '2years' ); ?>>Per 2 Years (Rent)</option>
                    <option value="month"  <?php selected( $pp, 'month' ); ?>>Per Month (Rent)</option>
                </select>
            </div>

            <div class="ofp-meta-field">
                <label>Bedrooms</label>
                <input type="number" name="ofp_bedrooms" value="<?php echo esc_attr( $meta['ofp_bedrooms'] ); ?>" min="0" max="20">
            </div>

            <div class="ofp-meta-field">
                <label>Bathrooms</label>
                <input type="number" name="ofp_bathrooms" value="<?php echo esc_attr( $meta['ofp_bathrooms'] ); ?>" min="0" max="20">
            </div>

            <div class="ofp-meta-field">
                <label>Parking Spaces</label>
                <input type="number" name="ofp_parking" value="<?php echo esc_attr( $meta['ofp_parking'] ); ?>" min="0" max="50">
            </div>

            <div class="ofp-meta-field">
                <label>Area (SQM)</label>
                <input type="number" name="ofp_area_sqm" value="<?php echo esc_attr( $meta['ofp_area_sqm'] ); ?>" min="0">
            </div>

            <script>
            (function(){
                var lt = document.querySelector('select[name="ofp_listing_type"]');
                var pt = document.querySelector('select[name="ofp_property_type"]');
                if (!lt) return;
                function wrap(n){ var e = document.querySelector('[name="' + n + '"]'); return e ? e.closest('.ofp-meta-field') : null; }
                function sync(){
                    var land = lt.value === 'land';
                    ['ofp_bedrooms','ofp_bathrooms','ofp_parking'].forEach(function(n){
                        var w = wrap(n); if (w) w.style.display = land ? 'none' : '';
                    });
                    if (land && pt) pt.value = 'land';
                }
                lt.addEventListener('change', sync);
                sync();
            })();
            </script>

            <div class="ofp-meta-field">
                <label>Title Document</label>
                <select name="ofp_title_document">
                    <option value="">-- Select Title --</option>
                    <?php
                    $docs = [
                        'C of O'               => 'Certificate of Occupancy (C of O)',
                        'Governor\'s Consent'  => 'Governor\'s Consent',
                        'Gazette'              => 'Gazette',
                        'Deed of Assignment'   => 'Deed of Assignment',
                        'R of O'               => 'Right of Occupancy (R of O)',
                        'Excision'             => 'Excision',
                        'Court Judgment'       => 'Court Judgment',
                        'Other'                => 'Other',
                    ];
                    foreach ( $docs as $val => $lbl ) :
                    ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $meta['ofp_title_document'], $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ofp-meta-field">
                <label>Condition</label>
                <select name="ofp_condition">
                    <option value="">-- Select Condition --</option>
                    <?php
                    $conditions = [ 'Newly Built', 'Fairly Used', 'Renovation Needed', 'Under Construction', 'Off-Plan' ];
                    foreach ( $conditions as $c ) :
                    ?>
                        <option value="<?php echo esc_attr( $c ); ?>" <?php selected( $meta['ofp_condition'], $c ); ?>><?php echo esc_html( $c ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ofp-meta-field">
                <label>Furnishing</label>
                <select name="ofp_furnishing">
                    <option value="">-- Select Furnishing --</option>
                    <?php
                    $furnishings = [ 'Furnished', 'Semi-Furnished', 'Unfurnished' ];
                    foreach ( $furnishings as $f ) :
                    ?>
                        <option value="<?php echo esc_attr( $f ); ?>" <?php selected( $meta['ofp_furnishing'], $f ); ?>><?php echo esc_html( $f ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ofp-meta-field" style="grid-column:1/-1;">
                <label>Video Tour Walkthrough URL (YouTube, Vimeo, or MP4 link)</label>
                <input type="url" name="ofp_video_url" value="<?php echo esc_attr( $meta['ofp_video_url'] ); ?>" placeholder="https://www.youtube.com/watch?v=...">
            </div>

            <div class="ofp-meta-field" style="grid-column:1/-1;">
                <label>Amenities &amp; Features</label>
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:8px; margin-top:4px;">
                    <?php
                    $all_amenities = [
                        'swimming_pool'   => 'Swimming Pool',
                        'smart_home'      => 'Smart Home Automation',
                        'power_247'       => '24/7 Electricity',
                        'cctv_security'   => 'CCTV & Uniformed Security',
                        'gym'             => 'Gym / Fitness Center',
                        'elevator'        => 'Elevator / Lift',
                        'playground'      => 'Children Play Area',
                        'bq'              => 'Boys Quarters (BQ)',
                        'water_treatment' => 'Water Treatment Plant',
                        'fitted_kitchen'  => 'Fully Fitted Kitchen',
                    ];
                    $selected_amenities = is_array( $meta['ofp_amenities'] ) ? $meta['ofp_amenities'] : [];
                    foreach ( $all_amenities as $key => $label ) :
                    ?>
                        <label style="display:flex; align-items:center; gap:6px; font-weight:normal; font-size:12px; cursor:pointer;">
                            <input type="checkbox" name="ofp_amenities[]" value="<?php echo esc_attr( $key ); ?>"
                                <?php checked( in_array( $key, $selected_amenities, true ) ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ofp-meta-field" style="grid-column:1/-1;">
                <label>Location Text</label>
                <input type="text" name="ofp_location_text" value="<?php echo esc_attr( $meta['ofp_location_text'] ); ?>" placeholder="e.g. Lekki Phase 1, Lagos">
            </div>

            <div class="ofp-meta-field">
                <label>Listing Status</label>
                <select name="ofp_status">
                    <option value="pending_upload" <?php selected( $meta['ofp_status'], 'pending_upload' ); ?>>Pending Upload</option>
                    <option value="live"           <?php selected( $meta['ofp_status'], 'live' ); ?>>Live</option>
                    <option value="taken"          <?php selected( $meta['ofp_status'], 'taken' ); ?>>Taken</option>
                    <option value="expired"        <?php selected( $meta['ofp_status'], 'expired' ); ?>>Expired</option>
                </select>
            </div>

            <div class="ofp-meta-field" style="justify-content:flex-end;padding-top:20px;">
                <?php
                $client_id = (int) $meta['ofp_client_id'];
                $can_feature = true;
                if ( $client_id ) {
                    $plan = 'free';
                    if ( class_exists( 'OFP_Subscription' ) ) {
                        $plan = OFP_Subscription::client_plan( $client_id );
                    }
                    if ( $plan === 'free' ) {
                        $can_feature = false;
                    }
                }
                ?>
                <label>
                    <input type="checkbox" name="ofp_is_featured" value="1" 
                        <?php checked( $meta['ofp_is_featured'], '1' ); ?>
                        <?php disabled( $can_feature, false ); ?>>
                    Featured listing (top placement)
                    <?php if ( ! $can_feature ) echo '<span style="color:#ef4444;font-size:11px;display:block;">(Not available on Free plan)</span>'; ?>
                </label>
            </div>
        </div>
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

        $fields = [
            'ofp_client_id'      => 'absint',
            'ofp_listing_type'   => 'sanitize_text_field',
            'ofp_property_type'  => 'sanitize_text_field',
            'ofp_price'          => 'floatval',
            'ofp_price_period'   => 'sanitize_text_field',
            'ofp_bedrooms'       => 'absint',
            'ofp_bathrooms'      => 'absint',
            'ofp_parking'        => 'absint',
            'ofp_area_sqm'       => 'absint',
            'ofp_title_document' => 'sanitize_text_field',
            'ofp_condition'      => 'sanitize_text_field',
            'ofp_furnishing'     => 'sanitize_text_field',
            'ofp_video_url'      => 'esc_url_raw',
            'ofp_location_text'  => 'sanitize_text_field',
            'ofp_status'         => 'sanitize_text_field',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            $value = isset( $_POST[ $key ] )
                ? $sanitizer( wp_unslash( $_POST[ $key ] ) )
                : '';
            // Normalize legacy one-time → sales.
            if ( $key === 'ofp_price_period' && $value === 'one-time' ) {
                $value = 'sales';
            }
            update_post_meta( $post_id, $key, $value );
        }

        // Save amenities array
        $amenities = isset( $_POST['ofp_amenities'] ) && is_array( $_POST['ofp_amenities'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ofp_amenities'] ) )
            : [];
        update_post_meta( $post_id, 'ofp_amenities', json_encode( array_values( $amenities ) ) );

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
                $plan = OFP_Subscription::client_plan( $client_id );
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
     * @param  int $post_id    WP post ID.
     * @param  int $client_id  OFP client ID.
     * @return void
     */
    public static function sync_to_plugin_table( int $post_id, int $client_id, bool $notify_owner = true ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $post = get_post( $post_id );
        if ( ! $post ) return;

        $data = [
            'title'          => $post->post_title,
            'description'    => $post->post_content,
            'property_type'  => get_post_meta( $post_id, 'ofp_property_type', true ),
            'listing_type'   => get_post_meta( $post_id, 'ofp_listing_type',  true ) ?: 'sale',
            'price'          => (float) get_post_meta( $post_id, 'ofp_price',   true ),
            'price_period'   => get_post_meta( $post_id, 'ofp_price_period',   true ),
            'bedrooms'      => (int) get_post_meta( $post_id, 'ofp_bedrooms',  true ),
            'bathrooms'     => (int) get_post_meta( $post_id, 'ofp_bathrooms', true ),
            'location_text'  => get_post_meta( $post_id, 'ofp_location_text',  true ),
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
                $status  = get_post_meta( $post_id, 'ofp_status', true );
                $colors  = [
                    'live'           => '#22c55e',
                    'pending_upload' => '#f59e0b',
                    'taken'          => '#6b7280',
                    'expired'        => '#ef4444',
                ];
                $color = $colors[ $status ] ?? '#9ca3af';
                echo '<span style="color:' . esc_attr( $color ) . ';font-weight:600;">'
                    . esc_html( ucwords( str_replace( '_', ' ', $status ?: 'unknown' ) ) )
                    . '</span>';
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

        $base_domain = get_option( 'ofp_crm_base_domain' );
        if ( ! $base_domain ) {
            return $post_link;
        }

        return preg_replace( '#^https?://[^/]+#', 'https://property.' . $base_domain, $post_link );
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
}
