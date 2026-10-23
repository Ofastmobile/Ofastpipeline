<?php
/**
 * Class OFP_Admin_Portal
 *
 * Dedicated Modern Executive Admin Dashboard matching Design #2.
 * Serves the two main menus (OFast Pipeline CRM + Property Listings CPT) and their submenus
 * inside a standalone, high-performance, dark/light mode responsive app.
 * Includes a seamless one-click toggle to return to classic WordPress Admin anytime.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Admin_Portal {

    public static function init(): void {
        $instance = new self();
        add_action( 'init', [ $instance, 'register_rewrite' ] );
        add_filter( 'query_vars', [ $instance, 'register_query_vars' ] );
        add_action( 'template_redirect', [ $instance, 'handle_request' ] );
        add_action( 'admin_bar_menu', [ $instance, 'add_admin_bar_link' ], 80 );
        add_action( 'admin_menu', [ $instance, 'add_wp_menu_shortcut' ], 2 );
        add_filter( 'wp_redirect', [ $instance, 'filter_admin_redirects' ], 999 );

        // ── Default Dashboard Redirect ──────────────────────────────────
        // When the "Use Modern Portal as Default Dashboard" setting is on,
        // redirect admins to /admin-portal after WP login and when they
        // visit /wp-admin/ (the dashboard index page).
        // Safety hatch: append ?classic=1 to any wp-admin URL to bypass.
        add_filter( 'login_redirect', [ $instance, 'redirect_after_login' ], 999, 3 );
        add_action( 'admin_init', [ $instance, 'redirect_dashboard_to_portal' ], 1 );
    }

    public function register_rewrite(): void {
        add_rewrite_rule( '^admin-portal/?$', 'index.php?ofp_admin_portal=1', 'top' );
        add_rewrite_rule( '^admin-portal/([^/]+)/?$', 'index.php?ofp_admin_portal=1&ofp_admin_tab=$matches[1]', 'top' );
    }

    public function register_query_vars( array $vars ): array {
        $vars[] = 'ofp_admin_portal';
        $vars[] = 'ofp_admin_tab';
        return $vars;
    }

    /**
     * Intercept request and render portal if matched.
     */
    public function handle_request(): void {
        $is_portal = get_query_var( 'ofp_admin_portal' );
        
        // Zero-configuration fallback matching request URI directly
        if ( ! $is_portal ) {
            $req_path = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
            if ( $req_path && ( str_starts_with( $req_path, '/admin-portal' ) || str_starts_with( $req_path, '/admin-dashboard' ) ) ) {
                $is_portal = 1;
            }
        }

        if ( ! $is_portal ) {
            return;
        }

        // Security Check 1: Must be logged in
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( home_url( '/admin-portal' ) ) );
            exit;
        }

        // Security Check 2: Must be an Administrator
        if ( ! current_user_can( 'manage_options' ) && ! ( class_exists( 'OFP_Auth' ) && OFP_Auth::is_admin_user() ) ) {
            wp_die(
                '<h1>Access Denied</h1><p>You do not have administrative privileges to access the ' . esc_html( OFP_Brand::name() ) . ' Admin Portal.</p>',
                'Access Denied',
                [ 'response' => 403 ]
            );
        }

        // Resolve active tab from URL query params or path
        $tab = sanitize_key( get_query_var( 'ofp_admin_tab' ) ?: ( $_GET['tab'] ?? '' ) );
        if ( ! $tab ) {
            $page = sanitize_key( $_GET['page'] ?? '' );
            if ( $page ) {
                $tab = str_replace( 'ofp-', '', $page );
                if ( $tab === 'admins' ) {
                    $tab = 'manage-admins';
                }
            } elseif ( ! empty( $_GET['taxonomy'] ) ) {
                $tax = sanitize_key( $_GET['taxonomy'] );
                if ( $tax === 'ofp_property_type' ) {
                    $tab = 'property-types';
                } elseif ( $tax === 'ofp_property_location' ) {
                    $tab = 'property-locations';
                }
            } elseif ( ( $_GET['post_type'] ?? '' ) === 'ofp_property' || strpos( $_SERVER['REQUEST_URI'] ?? '', 'ofp_property' ) !== false ) {
                $action = sanitize_key( $_GET['action'] ?? '' );
                $tab = ( $action === 'add' ) ? 'property-add' : 'properties';
            } else {
                $tab = 'overview';
            }
        }

        // Process Portal In-App Actions (Property CRUD, Taxonomy Terms)
        $this->process_portal_actions( $tab );

        $this->render_portal( $tab );
        exit;
    }

    /**
     * Process internal portal actions (Save Property, Delete Property, Taxonomy additions/deletions)
     */
    private function process_portal_actions( string &$tab ): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        global $wpdb;
        $p = $wpdb->prefix;

        // 1. Save Property (Create / Edit)
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_action'] ) && $_POST['ofp_action'] === 'save_property' ) {
            check_admin_referer( 'ofp_save_property_action', 'ofp_save_property_nonce' );

            $prop_id           = absint( $_POST['property_id'] ?? 0 );
            $post_id           = absint( $_POST['wp_post_id'] ?? 0 );
            $title             = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
            $content           = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );
            $price             = floatval( $_POST['price'] ?? 0 );
            $price_period      = sanitize_text_field( wp_unslash( $_POST['price_period'] ?? 'sales' ) );
            if ( $price_period === 'one-time' ) {
                $price_period = 'sales';
            }
            $total_package     = floatval( $_POST['total_package'] ?? 0 );
            $allow_installment = ! empty( $_POST['allow_installment'] ) ? '1' : '0';
            $title_document    = sanitize_text_field( wp_unslash( $_POST['title_document'] ?? '' ) );
            $list_type         = sanitize_text_field( wp_unslash( $_POST['listing_type'] ?? 'sale' ) );
            $prop_type         = sanitize_text_field( wp_unslash( $_POST['property_type'] ?? 'apartment' ) );
            $location          = sanitize_text_field( wp_unslash( $_POST['location_text'] ?? '' ) );
            $client_id         = absint( $_POST['client_id'] ?? 0 );
            $bank_account_id   = absint( $_POST['bank_account_id'] ?? 0 );
            $status            = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'live' ) );
            $is_featured       = ! empty( $_POST['is_featured'] ) ? '1' : '0';
            $bedrooms          = absint( $_POST['bedrooms'] ?? 0 );
            $bathrooms         = absint( $_POST['bathrooms'] ?? 0 );
            $parking           = absint( $_POST['parking'] ?? 0 );
            $area_sqm          = absint( $_POST['area_sqm'] ?? 0 );
            $plots_count       = floatval( $_POST['plots_count'] ?? 0 );
            $land_topography   = sanitize_text_field( wp_unslash( $_POST['land_topography'] ?? '' ) );
            $land_zoning       = sanitize_text_field( wp_unslash( $_POST['land_zoning'] ?? '' ) );
            $land_dimensions   = sanitize_text_field( wp_unslash( $_POST['land_dimensions'] ?? '' ) );
            $survey_status     = sanitize_text_field( wp_unslash( $_POST['survey_status'] ?? '' ) );
            $condition         = sanitize_text_field( wp_unslash( $_POST['condition'] ?? '' ) );
            $furnishing        = sanitize_text_field( wp_unslash( $_POST['furnishing'] ?? '' ) );
            $video_url         = esc_url_raw( wp_unslash( $_POST['video_url'] ?? '' ) );
            $inspection_days   = sanitize_text_field( wp_unslash( $_POST['inspection_days'] ?? 'Mondays - Saturdays' ) );
            $inspection_hours  = sanitize_text_field( wp_unslash( $_POST['inspection_hours'] ?? '10:00 AM - 5:00 PM' ) );
            $inspection_notice = sanitize_text_field( wp_unslash( $_POST['inspection_notice'] ?? '' ) );

            // Amenities array
            $amenities = isset( $_POST['amenities'] ) && is_array( $_POST['amenities'] )
                ? array_values( array_map( 'sanitize_text_field', wp_unslash( $_POST['amenities'] ) ) )
                : [];
            if ( class_exists( 'OFP_Property_Amenities' ) && ! empty( $amenities ) ) {
                OFP_Property_Amenities::register_custom_items( $amenities );
            }

            // Gallery Attachment IDs
            $gallery_ids = [];
            if ( ! empty( $_POST['gallery_ids'] ) ) {
                $raw_g = is_array( $_POST['gallery_ids'] ) ? $_POST['gallery_ids'] : json_decode( wp_unslash( $_POST['gallery_ids'] ), true );
                if ( is_array( $raw_g ) ) {
                    $gallery_ids = array_values( array_unique( array_filter( array_map( 'absint', $raw_g ) ) ) );
                }
            }

            // Cover photo
            $featured_image_id  = absint( $_POST['featured_image_id'] ?? 0 );
            $featured_image_url = esc_url_raw( wp_unslash( $_POST['featured_image'] ?? '' ) );

            // Ensure sale listing locks to sales billing period
            if ( $list_type === 'sale' || $list_type === 'land' ) {
                $price_period = 'sales';
            }
            if ( $list_type === 'land' ) {
                $prop_type = 'land';
                $bedrooms  = 0;
                $bathrooms = 0;
                $parking   = 0;
                if ( $area_sqm <= 0 && $plots_count > 0 ) {
                    $area_sqm = (int) round( $plots_count * 600 );
                }
            }

            $wp_post_status = ( $status === 'live' ) ? 'publish' : 'draft';

            if ( $post_id && get_post( $post_id ) ) {
                wp_update_post( [
                    'ID'           => $post_id,
                    'post_title'   => $title,
                    'post_content' => $content,
                    'post_status'  => $wp_post_status,
                ] );
            } else {
                $post_id = wp_insert_post( [
                    'post_type'    => 'ofp_property',
                    'post_title'   => $title,
                    'post_content' => $content,
                    'post_status'  => $wp_post_status,
                ] );
            }

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                update_post_meta( $post_id, 'ofp_client_id', $client_id );
                update_post_meta( $post_id, 'ofp_price', $price );
                update_post_meta( $post_id, 'ofp_price_period', $price_period );
                update_post_meta( $post_id, 'ofp_total_package', $total_package );
                update_post_meta( $post_id, 'ofp_allow_installment', $allow_installment );
                update_post_meta( $post_id, 'ofp_title_document', $title_document );
                update_post_meta( $post_id, 'ofp_bank_account_id', $bank_account_id );
                update_post_meta( $post_id, 'ofp_listing_type', $list_type );
                update_post_meta( $post_id, 'ofp_property_type', $prop_type );
                update_post_meta( $post_id, 'ofp_location_text', $location );
                update_post_meta( $post_id, 'ofp_status', $status );
                update_post_meta( $post_id, 'ofp_is_featured', $is_featured );
                update_post_meta( $post_id, 'ofp_bedrooms', $bedrooms );
                update_post_meta( $post_id, 'ofp_bathrooms', $bathrooms );
                update_post_meta( $post_id, 'ofp_parking', $parking );
                update_post_meta( $post_id, 'ofp_area_sqm', $area_sqm );
                update_post_meta( $post_id, 'ofp_plots_count', $plots_count );
                update_post_meta( $post_id, 'ofp_land_topography', $land_topography );
                update_post_meta( $post_id, 'ofp_land_zoning', $land_zoning );
                update_post_meta( $post_id, 'ofp_land_dimensions', $land_dimensions );
                update_post_meta( $post_id, 'ofp_survey_status', $survey_status );
                update_post_meta( $post_id, 'ofp_condition', $condition );
                update_post_meta( $post_id, 'ofp_furnishing', $furnishing );
                update_post_meta( $post_id, 'ofp_video_url', $video_url );
                update_post_meta( $post_id, 'ofp_inspection_days', $inspection_days );
                update_post_meta( $post_id, 'ofp_inspection_hours', $inspection_hours );
                update_post_meta( $post_id, 'ofp_inspection_notice', $inspection_notice );
                update_post_meta( $post_id, 'ofp_amenities', json_encode( $amenities ) );
                update_post_meta( $post_id, 'ofp_gallery_ids', json_encode( $gallery_ids ) );

                if ( $featured_image_id > 0 ) {
                    set_post_thumbnail( $post_id, $featured_image_id );
                } elseif ( empty( $featured_image_url ) && empty( $featured_image_id ) ) {
                    delete_post_thumbnail( $post_id );
                }

                // Sync WordPress Taxonomies
                if ( ! empty( $_POST['property_type_term'] ) ) {
                    wp_set_object_terms( $post_id, sanitize_text_field( $_POST['property_type_term'] ), 'ofp_property_type' );
                }
                if ( ! empty( $_POST['location_term'] ) ) {
                    wp_set_object_terms( $post_id, sanitize_text_field( $_POST['location_term'] ), 'ofp_property_location' );
                }

                if ( class_exists( 'OFP_Property_CPT' ) ) {
                    OFP_Property_CPT::sync_to_plugin_table( $post_id, $client_id );
                }

                // If a manual URL was provided and no WP attachment thumbnail set, preserve in database table
                if ( $featured_image_url && ! $featured_image_id ) {
                    $wpdb->update( "{$p}ofp_properties", [ 'featured_image' => $featured_image_url ], [ 'wp_post_id' => $post_id ] );
                }

                set_transient( 'ofp_admin_message_' . get_current_user_id(), [
                    'type' => 'success',
                    'text' => 'Property "' . $title . '" saved successfully and synchronized across the platform.'
                ], 60 );

                wp_safe_redirect( home_url( '/admin-portal?tab=properties' ) );
                exit;
            }
        }

        // 2. Delete Property
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_property' && ! empty( $_GET['id'] ) ) {
            check_admin_referer( 'ofp_delete_prop_' . $_GET['id'] );
            $del_post_id = absint( $_GET['id'] );
            wp_trash_post( $del_post_id );
            
            // Delete from ofp_properties
            $wpdb->delete( "{$p}ofp_properties", [ 'wp_post_id' => $del_post_id ] );

            set_transient( 'ofp_admin_message_' . get_current_user_id(), [
                'type' => 'success',
                'text' => 'Property has been moved to trash.'
            ], 60 );

            wp_safe_redirect( home_url( '/admin-portal?tab=properties' ) );
            exit;
        }

        // 3. Property Types Taxonomy
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_action'] ) && $_POST['ofp_action'] === 'add_property_type' ) {
            check_admin_referer( 'ofp_type_action', 'ofp_type_nonce' );
            $name = sanitize_text_field( wp_unslash( $_POST['type_name'] ?? '' ) );
            $slug = sanitize_title( wp_unslash( $_POST['type_slug'] ?: $name ) );
            $desc = sanitize_textarea_field( wp_unslash( $_POST['type_description'] ?? '' ) );

            if ( $name ) {
                wp_insert_term( $name, 'ofp_property_type', [
                    'slug'        => $slug,
                    'description' => $desc,
                ] );
                set_transient( 'ofp_admin_message_' . get_current_user_id(), [
                    'type' => 'success',
                    'text' => 'Property Type "' . $name . '" created successfully.'
                ], 60 );
            }
            wp_safe_redirect( home_url( '/admin-portal?tab=property-types' ) );
            exit;
        }

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_type' && ! empty( $_GET['term_id'] ) ) {
            check_admin_referer( 'ofp_del_type_' . $_GET['term_id'] );
            wp_delete_term( absint( $_GET['term_id'] ), 'ofp_property_type' );
            set_transient( 'ofp_admin_message_' . get_current_user_id(), [
                'type' => 'success',
                'text' => 'Property Type removed.'
            ], 60 );
            wp_safe_redirect( home_url( '/admin-portal?tab=property-types' ) );
            exit;
        }

        // 4. Locations Taxonomy
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_action'] ) && $_POST['ofp_action'] === 'add_property_location' ) {
            check_admin_referer( 'ofp_loc_action', 'ofp_loc_nonce' );
            $name = sanitize_text_field( wp_unslash( $_POST['loc_name'] ?? '' ) );
            $slug = sanitize_title( wp_unslash( $_POST['loc_slug'] ?: $name ) );
            $desc = sanitize_textarea_field( wp_unslash( $_POST['loc_description'] ?? '' ) );

            if ( $name ) {
                wp_insert_term( $name, 'ofp_property_location', [
                    'slug'        => $slug,
                    'description' => $desc,
                ] );
                set_transient( 'ofp_admin_message_' . get_current_user_id(), [
                    'type' => 'success',
                    'text' => 'Location "' . $name . '" created successfully.'
                ], 60 );
            }
            wp_safe_redirect( home_url( '/admin-portal?tab=property-locations' ) );
            exit;
        }

        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_location' && ! empty( $_GET['term_id'] ) ) {
            check_admin_referer( 'ofp_del_loc_' . $_GET['term_id'] );
            wp_delete_term( absint( $_GET['term_id'] ), 'ofp_property_location' );
            set_transient( 'ofp_admin_message_' . get_current_user_id(), [
                'type' => 'success',
                'text' => 'Location removed.'
            ], 60 );
            wp_safe_redirect( home_url( '/admin-portal?tab=property-locations' ) );
            exit;
        }
    }

    /**
     * Adds an "Admin Portal" button to the WordPress top admin bar.
     */
    public function add_admin_bar_link( WP_Admin_Bar $admin_bar ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $admin_bar->add_node( [
            'id'    => 'ofp-admin-portal-switch',
            'title' => '<span style="color:#10b981;font-weight:700;">Admin Portal</span>',
            'href'  => home_url( '/admin-portal' ),
            'meta'  => [
                'title' => 'Launch Modern Admin Dashboard',
            ],
        ] );
    }

    /**
     * Adds a shortcut in the WP Admin menu sidebar.
     */
    public function add_wp_menu_shortcut(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        add_submenu_page(
            'ofp-overview',
            'Modern Admin Portal',
            '<span style="color:#10b981;font-weight:600;">Modern Portal</span>',
            'read',
            'ofp-launch-modern-portal',
            function() {
                wp_safe_redirect( home_url( '/admin-portal' ) );
                exit;
            },
            1
        );
    }

    /**
     * Intercept and reroute admin redirects back into /admin-portal if triggered from the portal.
     */
    public function filter_admin_redirects( string $location ): string {
        $referer = wp_get_referer() ?: ( $_SERVER['HTTP_REFERER'] ?? '' );
        $in_portal = ! empty( $_REQUEST['ofp_in_portal'] ) || strpos( $referer, 'admin-portal' ) !== false;

        if ( ! $in_portal ) {
            return $location;
        }

        // Map admin.php?page=ofp-XXX or edit.php?post_type=ofp_property to /admin-portal?tab=XXX
        if ( preg_match( '/(?:admin\.php\?page=ofp-([a-z0-9_-]+)|edit\.php\?post_type=ofp_property|edit-tags\.php|post\.php|post-new\.php)/i', $location ) ) {
            $parsed = parse_url( $location );
            $query_params = [];
            if ( ! empty( $parsed['query'] ) ) {
                parse_str( $parsed['query'], $query_params );
            }

            $page = $query_params['page'] ?? '';
            unset( $query_params['page'] );

            $tab = 'overview';
            if ( $page ) {
                $tab = str_replace( 'ofp-', '', $page );
                if ( $tab === 'admins' ) {
                    $tab = 'manage-admins';
                }
            } elseif ( ! empty( $query_params['taxonomy'] ) ) {
                $tax = $query_params['taxonomy'];
                if ( $tax === 'ofp_property_type' ) {
                    $tab = 'property-types';
                } elseif ( $tax === 'ofp_property_location' ) {
                    $tab = 'property-locations';
                }
            } elseif ( ( $query_params['post_type'] ?? '' ) === 'ofp_property' || strpos( $location, 'ofp_property' ) !== false ) {
                $action = $query_params['action'] ?? '';
                $tab = ( $action === 'add' ) ? 'property-add' : 'properties';
                unset( $query_params['post_type'] );
            }

            // If query params had an inner tab (e.g. &tab=send, &tab=templates, &tab=tenants),
            // move it to subtab so it does not overwrite the main portal tab
            if ( isset( $query_params['tab'] ) && $query_params['tab'] !== $tab ) {
                $query_params['subtab'] = $query_params['tab'];
                unset( $query_params['tab'] );
            }

            $new_url = home_url( '/admin-portal?tab=' . $tab );
            if ( ! empty( $query_params ) ) {
                $new_url = add_query_arg( $query_params, $new_url );
            }
            return $new_url;
        }

        return $location;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DEFAULT DASHBOARD REDIRECT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * After a successful WordPress login, redirect administrators straight
     * to the modern Admin Portal instead of /wp-admin/.
     *
     * Security:
     * - Only fires when ofp_portal_default_dashboard option is '1'
     * - Only affects users with manage_options capability (admins)
     * - Preserves any explicit redirect_to that points outside wp-admin
     * - ?classic=1 on the login form's redirect_to bypasses this entirely
     *
     * @param string           $redirect_to           The destination URL.
     * @param string           $requested_redirect_to The originally requested redirect.
     * @param WP_User|WP_Error $user                  The logged-in user object.
     * @return string
     */
    public function redirect_after_login( string $redirect_to, string $requested_redirect_to, $user ): string {
        // Only when the feature is enabled
        if ( '1' !== (string) get_option( 'ofp_portal_default_dashboard', '0' ) ) {
            return $redirect_to;
        }

        // Must be a successful login (not a WP_Error)
        if ( ! ( $user instanceof WP_User ) ) {
            return $redirect_to;
        }

        // Only redirect administrators
        if ( ! user_can( $user, 'manage_options' ) ) {
            return $redirect_to;
        }

        // Safety escape: if the requested URL has ?classic=1, honour it
        if ( strpos( $requested_redirect_to, 'classic=1' ) !== false ) {
            return $redirect_to;
        }

        // If the user explicitly requested a specific admin page (not just
        // /wp-admin/ or /wp-admin/index.php), honour that destination
        if ( $requested_redirect_to && ! preg_match( '#/wp-admin/?(?:index\.php)?(?:\?.*)?$#', $requested_redirect_to ) ) {
            return $redirect_to;
        }

        return home_url( '/admin-portal' );
    }

    /**
     * When an administrator navigates directly to /wp-admin/ or
     * /wp-admin/index.php (the WordPress Dashboard), redirect them
     * to the modern Admin Portal.
     *
     * Does NOT intercept other wp-admin pages (posts, plugins, settings…)
     * so nothing in WordPress breaks.
     *
     * Safety hatches:
     * - ?classic=1 in the URL always lets you through
     * - AJAX, REST, and WP-CLI requests are never redirected
     * - POST requests are never redirected (form submissions)
     */
    public function redirect_dashboard_to_portal(): void {
        // Only when the feature is enabled
        if ( '1' !== (string) get_option( 'ofp_portal_default_dashboard', '0' ) ) {
            return;
        }

        // Never redirect AJAX, REST API, Cron, or CLI requests
        if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }

        // Never redirect POST requests (form submissions, plugin actions)
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            return;
        }

        // Only redirect administrators
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Safety escape: ?classic=1 bypasses the redirect
        if ( ! empty( $_GET['classic'] ) && $_GET['classic'] === '1' ) {
            return;
        }

        // Only intercept the Dashboard page itself, not other admin pages
        global $pagenow;
        if ( $pagenow !== 'index.php' ) {
            return;
        }

        // Don't redirect if a specific dashboard widget/action is requested
        if ( ! empty( $_GET['action'] ) || ! empty( $_GET['page'] ) ) {
            return;
        }

        wp_safe_redirect( home_url( '/admin-portal' ) );
        exit;
    }

    /**
     * Render the main portal template.
     */
    private function render_portal( string $current_tab ): void {
        // Load WordPress admin core dependencies so functions like submit_button(), settings_fields(), etc. are available
        if ( ! class_exists( 'WP_Screen' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-screen.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        }
        if ( ! function_exists( 'get_current_screen' ) && file_exists( ABSPATH . 'wp-admin/includes/screen.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }
        if ( ! function_exists( 'submit_button' ) && file_exists( ABSPATH . 'wp-admin/includes/template.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/template.php';
        }
        if ( ! function_exists( 'get_sample_permalink' ) && file_exists( ABSPATH . 'wp-admin/includes/post.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }
        if ( ! function_exists( 'settings_fields' ) && file_exists( ABSPATH . 'wp-admin/includes/options.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/options.php';
        }
        if ( ! function_exists( 'wp_clean_plugins_cache' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Enqueue media library scripts and core WordPress assets
        wp_enqueue_media();
        wp_enqueue_script( 'jquery' );
        wp_enqueue_style( 'dashicons' );

        // Enqueue Admin Portal styles
        wp_enqueue_style( 'ofp-admin', OFP_URL . 'assets/css/admin.css', [], OFP_VERSION );
        wp_enqueue_style( 'ofp-admin-portal', OFP_URL . 'assets/css/admin-portal.css', [ 'ofp-admin' ], OFP_VERSION );
        wp_enqueue_style( 'ofp-admin-activity-logs', OFP_URL . 'assets/css/admin-activity-logs.css', [ 'ofp-admin' ], OFP_VERSION );

        // Enqueue Admin Portal scripts with localization
        wp_enqueue_script( 'ofp-admin', OFP_URL . 'assets/js/admin.js', [ 'jquery' ], OFP_VERSION, true );
        wp_localize_script( 'ofp-admin', 'ofpAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ofp_admin_nonce' ),
        ] );

        wp_enqueue_script( 'ofp-admin-portal', OFP_URL . 'assets/js/admin-portal.js', [ 'jquery', 'ofp-admin' ], OFP_VERSION, true );
        wp_enqueue_script( 'ofp-admin-activity-logs', OFP_URL . 'assets/js/admin-activity-logs.js', [ 'jquery', 'ofp-admin' ], OFP_VERSION, true );

        $template_file = OFP_PATH . 'admin/views/portal/admin-portal-template.php';
        if ( file_exists( $template_file ) ) {
            include $template_file;
        } else {
            wp_die( 'Portal template file missing.' );
        }
    }
}
