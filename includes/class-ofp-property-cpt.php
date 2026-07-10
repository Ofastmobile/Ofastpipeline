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

    const PLAN_KEYS = [ 'bronze', 'silver', 'gold' ];

    const DEFAULT_PLAN_PRICES = [
        'bronze' => 7500.00,
        'silver' => 15000.00,
        'gold'   => 30000.00,
    ];

    const DEFAULT_PLAN_CAPS = [
        'bronze' => 3,
        'silver' => 10,
        'gold'   => 25,
    ];

    public function __construct() {
        add_action( 'init',                    [ $this, 'register_post_type' ] );
        add_action( 'init',                    [ $this, 'register_taxonomies' ] );
        add_action( 'add_meta_boxes',          [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_ofp_property',  [ $this, 'save_meta' ] );
        add_filter( 'manage_ofp_property_posts_columns',       [ $this, 'custom_columns' ] );
        add_action( 'manage_ofp_property_posts_custom_column', [ $this, 'render_columns' ], 10, 2 );
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
            'show_in_menu'        => 'ofp-overview', // Appears under OFP admin menu.
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
            'ofp_client_id'     => get_post_meta( $post->ID, 'ofp_client_id',     true ),
            'ofp_listing_type'  => get_post_meta( $post->ID, 'ofp_listing_type',  true ),
            'ofp_property_type' => get_post_meta( $post->ID, 'ofp_property_type', true ),
            'ofp_price'         => get_post_meta( $post->ID, 'ofp_price',         true ),
            'ofp_price_period'  => get_post_meta( $post->ID, 'ofp_price_period',  true ),
            'ofp_bedrooms'      => get_post_meta( $post->ID, 'ofp_bedrooms',      true ),
            'ofp_bathrooms'     => get_post_meta( $post->ID, 'ofp_bathrooms',     true ),
            'ofp_location_text' => get_post_meta( $post->ID, 'ofp_location_text', true ),
            'ofp_is_featured'   => get_post_meta( $post->ID, 'ofp_is_featured',   true ),
            'ofp_status'        => get_post_meta( $post->ID, 'ofp_status',        true ),
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

        <div class="ofp-meta-grid">
            <div class="ofp-meta-field" style="grid-column:1/-1;">
                <label>Client (Property Owner / Agent)</label>
                <select name="ofp_client_id">
                    <option value="">— Select Client —</option>
                    <?php foreach ( $clients as $c ) : ?>
                        <option value="<?php echo esc_attr( $c->id ); ?>"
                            <?php selected( $meta['ofp_client_id'], $c->id ); ?>>
                            <?php echo esc_html( $c->business_name . ' (' . $c->owner_name . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ofp-meta-field">
                <label>Listing Type</label>
                <select name="ofp_listing_type">
                    <option value="rent" <?php selected( $meta['ofp_listing_type'], 'rent' ); ?>>For Rent</option>
                    <option value="sale" <?php selected( $meta['ofp_listing_type'], 'sale' ); ?>>For Sale</option>
                </select>
            </div>

            <div class="ofp-meta-field">
                <label>Property Type</label>
                <select name="ofp_property_type">
                    <?php
                    $types = [ 'apartment' => 'Apartment', 'duplex' => 'Duplex', 'bungalow' => 'Bungalow',
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
                <label>Price Period (for rent)</label>
                <select name="ofp_price_period">
                    <option value="year"     <?php selected( $meta['ofp_price_period'], 'year' ); ?>>Per Year</option>
                    <option value="month"    <?php selected( $meta['ofp_price_period'], 'month' ); ?>>Per Month</option>
                    <option value="one-time" <?php selected( $meta['ofp_price_period'], 'one-time' ); ?>>One-Time (Sale)</option>
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
                <label>
                    <input type="checkbox" name="ofp_is_featured" value="1" <?php checked( $meta['ofp_is_featured'], '1' ); ?>>
                    Featured listing (top placement)
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
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = [
            'ofp_client_id'     => 'absint',
            'ofp_listing_type'  => 'sanitize_text_field',
            'ofp_property_type' => 'sanitize_text_field',
            'ofp_price'         => 'floatval',
            'ofp_price_period'  => 'sanitize_text_field',
            'ofp_bedrooms'      => 'absint',
            'ofp_bathrooms'     => 'absint',
            'ofp_location_text' => 'sanitize_text_field',
            'ofp_status'        => 'sanitize_text_field',
        ];

        foreach ( $fields as $key => $sanitizer ) {
            $value = isset( $_POST[ $key ] )
                ? $sanitizer( wp_unslash( $_POST[ $key ] ) )
                : '';
            update_post_meta( $post_id, $key, $value );
        }

        // Checkbox — absent means unchecked.
        update_post_meta( $post_id, 'ofp_is_featured', isset( $_POST['ofp_is_featured'] ) ? '1' : '0' );

        // Sync back to ofp_properties table if a client is assigned.
        $client_id = absint( $_POST['ofp_client_id'] ?? 0 );
        if ( $client_id ) {
            self::sync_to_plugin_table( $post_id, $client_id );
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
    public static function sync_to_plugin_table( int $post_id, int $client_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $post = get_post( $post_id );
        if ( ! $post ) return;

        $data = [
            'title'          => $post->post_title,
            'description'    => $post->post_content,
            'property_type'  => get_post_meta( $post_id, 'ofp_property_type', true ),
            'listing_type'   => get_post_meta( $post_id, 'ofp_listing_type',  true ),
            'price'          => (float) get_post_meta( $post_id, 'ofp_price',   true ),
            'price_period'   => get_post_meta( $post_id, 'ofp_price_period',   true ),
            'bedrooms'       => (int) get_post_meta( $post_id, 'ofp_bedrooms',  true ),
            'bathrooms'      => (int) get_post_meta( $post_id, 'ofp_bathrooms', true ),
            'location_text'  => get_post_meta( $post_id, 'ofp_location_text',  true ),
            'status'         => get_post_meta( $post_id, 'ofp_status',          true ) ?: 'pending_upload',
            'is_featured'    => (int) get_post_meta( $post_id, 'ofp_is_featured', true ),
            'wp_post_id'     => $post_id,
            'updated_at'     => current_time( 'mysql' ),
        ];

        // Check if a row already exists for this wp_post_id.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$p}ofp_properties WHERE wp_post_id = %d LIMIT 1",
                $post_id
            )
        );

        if ( $existing ) {
            $wpdb->update( $p . 'ofp_properties', $data, [ 'wp_post_id' => $post_id ] );
        } else {
            $data['client_id']  = $client_id;
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $p . 'ofp_properties', $data );
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
            'post_status'  => 'publish',
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
        $columns['ofp_client']   = 'Client';
        $columns['ofp_location'] = 'Location';
        $columns['ofp_price']    = 'Price';
        $columns['ofp_status']   = 'Status';
        $columns['date']         = 'Date';
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
                if ( $client_id ) {
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

            case 'ofp_price':
                $price  = (float) get_post_meta( $post_id, 'ofp_price',        true );
                $period = get_post_meta( $post_id, 'ofp_price_period', true );
                echo $price
                    ? '₦' . esc_html( number_format( $price, 0 ) ) . ( $period ? ' / ' . esc_html( $period ) : '' )
                    : '—';
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
    // PUBLIC TEMPLATES & PRICING HELPERS (Phase 14)
    // ─────────────────────────────────────────────────────────────────────────

    public function load_templates( string $template ): string {
        // Phase 16: property.crmdomain.com's homepage IS the property
        // archive — nicer than making visitors go to /properties/ on their
        // own subdomain.
        if ( is_front_page() && OFP_Host_Router::current_zone() === 'property' ) {
            $theme_override = locate_template( 'archive-ofp_property.php' );
            if ( $theme_override ) return $theme_override;
            return OFP_PLUGIN_DIR . 'public/templates/property-archive.php';
        }

        if ( is_post_type_archive( 'ofp_property' ) && OFP_Host_Router::current_zone() !== 'app' ) {
            $theme_override = locate_template( 'archive-ofp_property.php' );
            if ( $theme_override ) return $theme_override;
            return OFP_PLUGIN_DIR . 'public/templates/property-archive.php';
        }

        if ( is_singular( 'ofp_property' ) ) {
            $theme_override = locate_template( 'single-ofp_property.php' );
            if ( $theme_override ) return $theme_override;
            return OFP_PLUGIN_DIR . 'public/templates/property-single.php';
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

    public static function count_for_client( int $client_id ): int {
        $query = new WP_Query( [
            'post_type'      => 'ofp_property',
            'post_status'    => [ 'publish', 'pending', 'draft' ],
            'meta_key'       => 'ofp_client_id',
            'meta_value'     => $client_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        return count( $query->posts );
    }

    public static function can_add_property( int $client_id ): bool {
        $plan = OFP_Subscription::get_active_listing_plan( $client_id );
        if ( ! $plan ) return false;

        $cap = self::get_plan_cap( $plan );
        return self::count_for_client( $client_id ) < $cap;
    }

    public static function get_client_properties( int $client_id ): array {
        $query = new WP_Query( [
            'post_type'      => 'ofp_property',
            'post_status'    => [ 'publish', 'pending', 'draft' ],
            'meta_key'       => 'ofp_client_id',
            'meta_value'     => $client_id,
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
