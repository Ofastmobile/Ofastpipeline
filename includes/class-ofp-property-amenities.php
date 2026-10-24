<?php
/**
 * Amenities Catalog & Icon Service for OFast Pipeline
 *
 * Provides standard and custom amenities management, keyword-based SVG icon
 * matching, and persistence for both Client Portal and Admin Studio.
 *
 * @package OFastPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_Amenities {

    const OPTION_CUSTOM_AMENITIES = 'ofp_custom_amenities';

    /**
     * Initialize hooks (AJAX handlers).
     */
    public static function init(): void {
        add_action( 'wp_ajax_ofp_add_custom_amenity', [ __CLASS__, 'ajax_add_custom_amenity' ] );
        add_action( 'wp_ajax_nopriv_ofp_add_custom_amenity', [ __CLASS__, 'ajax_add_custom_amenity' ] );
    }

    /**
     * Standard built-in amenities catalog.
     *
     * @return array<string, array{label: string, icon: string, category: string}>
     */
    public static function get_standard_catalog(): array {
        return [
            'swimming_pool'   => [ 'label' => 'Swimming Pool',            'icon' => '🏊', 'category' => 'Leisure' ],
            'smart_home'      => [ 'label' => 'Smart Home Automation',   'icon' => '🏠', 'category' => 'Technology' ],
            'power_247'       => [ 'label' => '24/7 Electricity / Gen',  'icon' => '⚡', 'category' => 'Utilities' ],
            'cctv_security'   => [ 'label' => 'CCTV & Uniformed Security','icon' => '🛡️', 'category' => 'Security' ],
            'gym'             => [ 'label' => 'Gym / Fitness Center',    'icon' => '🏋️', 'category' => 'Wellness' ],
            'elevator'        => [ 'label' => 'Elevator / Lift',         'icon' => '🛗', 'category' => 'Access' ],
            'playground'      => [ 'label' => "Children's Play Area",     'icon' => '🛝', 'category' => 'Family' ],
            'bq'              => [ 'label' => 'Boys Quarters (BQ)',      'icon' => '🏡', 'category' => 'Living' ],
            'water_treatment' => [ 'label' => 'Water Treatment Plant',   'icon' => '💧', 'category' => 'Utilities' ],
            'fitted_kitchen'  => [ 'label' => 'Fully Fitted Kitchen',    'icon' => '🍳', 'category' => 'Interior' ],
            'wifi'            => [ 'label' => 'High-Speed Wi-Fi / Fiber', 'icon' => '📶', 'category' => 'Technology' ],
            'solar_power'     => [ 'label' => 'Solar Power / Inverter',  'icon' => '☀️', 'category' => 'Utilities' ],
            'air_conditioning'=> [ 'label' => 'Air Conditioning (AC)',   'icon' => '❄️', 'category' => 'Comfort' ],
            'car_parking'     => [ 'label' => 'Ample Parking / Garage',  'icon' => '🚗', 'category' => 'Access' ],
            'security_gate'   => [ 'label' => 'Automated Estate Gate',   'icon' => '🚪', 'category' => 'Security' ],
            'balcony'         => [ 'label' => 'Balcony / Terrace',       'icon' => '🌅', 'category' => 'Living' ],
            'jacuzzi'         => [ 'label' => 'Jacuzzi / Hot Tub',       'icon' => '🛁', 'category' => 'Wellness' ],
            'furnished'       => [ 'label' => 'Furnished / Turnkey',     'icon' => '🛋️', 'category' => 'Interior' ],
            'water_heater'    => [ 'label' => 'Water Heaters',           'icon' => '🔥', 'category' => 'Utilities' ],
            'green_area'      => [ 'label' => 'Garden / Landscaped Lawn','icon' => '🌿', 'category' => 'Leisure' ],
        ];
    }

    /**
     * Retrieve all custom amenities created by clients or admin.
     *
     * @return array<string, array{label: string, icon: string, category: string, is_custom: bool}>
     */
    public static function get_custom_amenities(): array {
        $stored = get_option( self::OPTION_CUSTOM_AMENITIES, [] );
        if ( ! is_array( $stored ) ) {
            return [];
        }
        $clean = [];
        foreach ( $stored as $key => $item ) {
            if ( is_string( $item ) ) {
                $clean[ sanitize_key( $key ?: $item ) ] = [
                    'label'     => sanitize_text_field( $item ),
                    'icon'      => '✨',
                    'category'  => 'Custom',
                    'is_custom' => true,
                ];
            } elseif ( is_array( $item ) && ! empty( $item['label'] ) ) {
                $k = sanitize_key( $key ?: $item['label'] );
                $clean[ $k ] = [
                    'label'     => sanitize_text_field( $item['label'] ),
                    'icon'      => sanitize_text_field( $item['icon'] ?? '✨' ),
                    'category'  => sanitize_text_field( $item['category'] ?? 'Custom' ),
                    'is_custom' => true,
                ];
            }
        }
        return $clean;
    }

    /**
     * Get combined catalog of standard + custom amenities.
     *
     * @return array<string, array{label: string, icon: string, category: string, is_custom: bool, icon_svg: string}>
     */
    public static function get_all(): array {
        $standard = self::get_standard_catalog();
        $custom   = self::get_custom_amenities();

        $all = [];
        foreach ( $standard as $key => $data ) {
            $all[ $key ] = array_merge( $data, [
                'is_custom' => false,
                'icon_svg'  => self::get_icon_svg( $key, 'w-4 h-4' ),
            ] );
        }
        foreach ( $custom as $key => $data ) {
            if ( ! isset( $all[ $key ] ) ) {
                $all[ $key ] = array_merge( $data, [
                    'icon_svg' => self::get_icon_svg( $key . ' ' . $data['label'], 'w-4 h-4' ),
                ] );
            }
        }
        return $all;
    }

    /**
     * Add a custom amenity globally.
     *
     * @param string $label Raw label from user input.
     * @return array{key: string, label: string, icon: string, icon_svg: string, is_custom: bool}
     */
    public static function add_custom_amenity( string $label ): array {
        $label = trim( sanitize_text_field( $label ) );
        if ( empty( $label ) ) {
            return [ 'key' => '', 'label' => '', 'icon' => '✨', 'icon_svg' => '', 'is_custom' => true ];
        }

        $key = sanitize_key( strtolower( str_replace( [ ' ', '-', '/' ], '_', $label ) ) );
        if ( empty( $key ) ) {
            $key = 'amenity_' . substr( md5( $label ), 0, 8 );
        }

        $custom = self::get_custom_amenities();
        if ( ! isset( $custom[ $key ] ) ) {
            $custom[ $key ] = [
                'label'     => $label,
                'icon'      => '✨',
                'category'  => 'Custom',
                'is_custom' => true,
            ];
            update_option( self::OPTION_CUSTOM_AMENITIES, $custom );
        }

        return [
            'key'       => $key,
            'label'     => $label,
            'icon'      => '✨',
            'icon_svg'  => self::get_icon_svg( $key . ' ' . $label, 'w-4 h-4' ),
            'is_custom' => true,
        ];
    }

    /**
     * Inspect an array of submitted amenity keys/labels and register any unknown items as custom amenities.
     *
     * @param array $items Array of amenity keys or labels.
     */
    public static function register_custom_items( array $items ): void {
        $standard = self::get_standard_catalog();
        $custom   = self::get_custom_amenities();
        $updated  = false;

        foreach ( $items as $raw ) {
            if ( ! is_string( $raw ) ) {
                continue;
            }
            $raw = trim( sanitize_text_field( $raw ) );
            if ( empty( $raw ) ) {
                continue;
            }

            $key = sanitize_key( strtolower( str_replace( [ ' ', '-', '/' ], '_', $raw ) ) );
            if ( ! isset( $standard[ $key ] ) && ! isset( $custom[ $key ] ) && ! isset( $standard[ $raw ] ) && ! isset( $custom[ $raw ] ) ) {
                $custom[ $key ] = [
                    'label'     => ucwords( str_replace( '_', ' ', $raw ) ),
                    'icon'      => '✨',
                    'category'  => 'Custom',
                    'is_custom' => true,
                ];
                $updated = true;
            }
        }

        if ( $updated ) {
            update_option( self::OPTION_CUSTOM_AMENITIES, $custom );
        }
    }

    /**
     * AJAX handler for inline custom amenity creation.
     */
    /** Most custom amenities the whole platform will store (stops the list growing forever). */
    const MAX_CUSTOM_AMENITIES = 300;

    public static function ajax_add_custom_amenity(): void {
        // Clients add amenities that are not in the built-in list. That is allowed,
        // but only for a logged-in client (or a WordPress editor), with a valid
        // security token. check_ajax_referer(..., false) only RETURNS false, it does
        // not stop the request, and the result used to be ignored, so anyone on the
        // internet could add amenities.
        $allowed = ( class_exists( 'OFP_Auth' ) && OFP_Auth::current_client() ) || current_user_can( 'edit_posts' );
        if ( ! $allowed ) {
            wp_send_json_error( [ 'message' => 'Please log in again to add an amenity.' ], 401 );
        }
        if ( ! check_ajax_referer( 'ofp_amenity_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'This page has expired. Please reload it and try again.' ], 403 );
        }

        if ( ! defined( 'OFP_JSON_API' ) ) {
            define( 'OFP_JSON_API', true ); // rate limit answers in JSON
        }
        OFP_Security::check_rate_limit( OFP_Security::get_client_ip(), 'add_amenity', 30, 600 );

        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 40 ) : substr( $name, 0, 40 );
        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => 'Amenity name cannot be empty.' ] );
        }
        if ( count( self::get_custom_amenities() ) >= self::MAX_CUSTOM_AMENITIES ) {
            wp_send_json_error( [ 'message' => 'The custom amenity list is full. Please pick an existing amenity.' ] );
        }

        $created = self::add_custom_amenity( $name );
        wp_send_json_success( $created );
    }

    /**
     * Returns a crisp SVG icon specifically matched to the amenity key or keywords in its name.
     *
     * @param string $key_or_name Amenity slug or full name.
     * @param string $class CSS classes to inject into the SVG.
     * @return string Valid SVG markup.
     */
    public static function get_icon_svg( string $key_or_name, string $class = 'w-4 h-4' ): string {
        $n = strtolower( trim( $key_or_name ) );
        $attr = 'class="' . esc_attr( $class ) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

        // 1. Swimming Pool
        if ( str_contains( $n, 'pool' ) || str_contains( $n, 'swim' ) ) {
            return '<svg ' . $attr . '><path d="M3 15c1.5 0 2.5-1 4-1s2.5 1 4 1 2.5-1 4-1 2.5 1 4 1M3 19c1.5 0 2.5-1 4-1s2.5 1 4 1 2.5-1 4-1 2.5 1 4 1M14 6a2 2 0 10-4 0 2 2 0 004 0zM8 11l3-2 3 2"/></svg>';
        }

        // 2. Solar Power / Inverter
        if ( str_contains( $n, 'solar' ) || str_contains( $n, 'inverter' ) || str_contains( $n, 'panel' ) ) {
            return '<svg ' . $attr . '><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>';
        }

        // 3. 24/7 Electricity / Generator / Power
        if ( str_contains( $n, 'power' ) || str_contains( $n, '247' ) || str_contains( $n, 'gen' ) || str_contains( $n, 'light' ) || str_contains( $n, 'electric' ) ) {
            return '<svg ' . $attr . '><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>';
        }

        // 4. CCTV / Security / Patrol / Guard
        if ( str_contains( $n, 'cctv' ) || str_contains( $n, 'security' ) || str_contains( $n, 'guard' ) || str_contains( $n, 'camera' ) || str_contains( $n, 'patrol' ) || str_contains( $n, 'alarm' ) ) {
            return '<svg ' . $attr . '><path d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>';
        }

        // 5. Gym / Fitness / Workout
        if ( str_contains( $n, 'gym' ) || str_contains( $n, 'fitness' ) || str_contains( $n, 'workout' ) || str_contains( $n, 'athletic' ) ) {
            return '<svg ' . $attr . '><path d="M6 5v14M18 5v14M3 8v8M21 8v8M6 12h12"/></svg>';
        }

        // 6. Elevator / Lift
        if ( str_contains( $n, 'elevator' ) || str_contains( $n, 'lift' ) ) {
            return '<svg ' . $attr . '><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M12 3v18M7.5 9.5L9 8l1.5 1.5M9 8v5M15 16l1.5-1.5L18 16M16.5 16v-5"/></svg>';
        }

        // 7. Children Play Area / Playground / Kids
        if ( str_contains( $n, 'playground' ) || str_contains( $n, 'play' ) || str_contains( $n, 'kid' ) || str_contains( $n, 'child' ) ) {
            return '<svg ' . $attr . '><path d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/></svg>';
        }

        // 8. Boys Quarters / BQ / Guest House
        if ( str_contains( $n, 'bq' ) || str_contains( $n, 'boy' ) || str_contains( $n, 'quarter' ) || str_contains( $n, 'guest' ) || str_contains( $n, 'servant' ) ) {
            return '<svg ' . $attr . '><path d="M3 21h18M5 21V9l7-5 7 5v12M9 13h2v4H9v-4zM15 13h.01"/></svg>';
        }

        // 9. Water Treatment / Borehole / Water Supply
        if ( str_contains( $n, 'water' ) || str_contains( $n, 'treatment' ) || str_contains( $n, 'borehole' ) || str_contains( $n, 'tank' ) ) {
            return '<svg ' . $attr . '><path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z"/></svg>';
        }

        // 10. Fitted Kitchen / Cooker / Dining
        if ( str_contains( $n, 'kitchen' ) || str_contains( $n, 'cook' ) || str_contains( $n, 'stove' ) || str_contains( $n, 'fridge' ) || str_contains( $n, 'dining' ) ) {
            return '<svg ' . $attr . '><path d="M6 4v7a2 2 0 002 2h0a2 2 0 002-2V4M8 13v7M16 4v7a2 2 0 002 2h1v7M14 4h4"/></svg>';
        }

        // 11. Wi-Fi / Internet / Fiber
        if ( str_contains( $n, 'wifi' ) || str_contains( $n, 'internet' ) || str_contains( $n, 'fiber' ) || str_contains( $n, 'broadband' ) ) {
            return '<svg ' . $attr . '><path d="M8.53 16.11a6 6 0 016.95 0M5 12.55a11 11 0 0114.08 0M1.64 9a16 16 0 0120.72 0M12 20h.01"/></svg>';
        }

        // 12. Air Conditioning / AC / Cooling
        if ( str_contains( $n, 'air' ) || str_contains( $n, 'ac' ) || str_contains( $n, 'cool' ) || str_contains( $n, 'condition' ) || str_contains( $n, 'hvac' ) ) {
            return '<svg ' . $attr . '><path d="M12 3v18M3 12h18M6.5 6.5l11 11M6.5 17.5l11-11"/></svg>';
        }

        // 13. Car Parking / Garage / Carport
        if ( str_contains( $n, 'park' ) || str_contains( $n, 'car' ) || str_contains( $n, 'garage' ) || str_contains( $n, 'drive' ) ) {
            return '<svg ' . $attr . '><path d="M5 11l2-5h10l2 5M5 11h14v7a1 1 0 01-1 1h-1a1 1 0 01-1-1v-1H7v1a1 1 0 01-1 1H5a1 1 0 01-1-1v-7zM7.5 14h.01M16.5 14h.01"/></svg>';
        }

        // 14. Gate Access / Automated Gate
        if ( str_contains( $n, 'gate' ) || str_contains( $n, 'access' ) || str_contains( $n, 'barrier' ) || str_contains( $n, 'intercom' ) ) {
            return '<svg ' . $attr . '><path d="M3 21h18M5 21V5a2 2 0 012-2h10a2 2 0 012 2v16M9 9h1M9 13h1M9 17h1M14 9h1M14 13h1M14 17h1"/></svg>';
        }

        // 15. Balcony / Terrace / Patio
        if ( str_contains( $n, 'balcony' ) || str_contains( $n, 'terrace' ) || str_contains( $n, 'patio' ) || str_contains( $n, 'deck' ) ) {
            return '<svg ' . $attr . '><path d="M3 10h18M3 14h18M3 18h18M5 10v8M19 10v8M9 10v8M15 10v8M3 6l9-3 9 3"/></svg>';
        }

        // 16. Jacuzzi / Hot Tub / Bath / Spa
        if ( str_contains( $n, 'jacuzzi' ) || str_contains( $n, 'tub' ) || str_contains( $n, 'bath' ) || str_contains( $n, 'spa' ) ) {
            return '<svg ' . $attr . '><path d="M4 12h16v5a4 4 0 01-4 4H8a4 4 0 01-4-4v-5zM6 12V6a2 2 0 012-2h1M6 21l1-2M18 21l-1-2"/></svg>';
        }

        // 17. Furnished / Furniture / Couch / Interior
        if ( str_contains( $n, 'furnish' ) || str_contains( $n, 'couch' ) || str_contains( $n, 'sofa' ) || str_contains( $n, 'bed' ) || str_contains( $n, 'interior' ) ) {
            return '<svg ' . $attr . '><path d="M4 14h16v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM4 14V8a2 2 0 012-2h12a2 2 0 012 2v6M2 12h2v6H2zM20 12h2v6h-2z"/></svg>';
        }

        // 18. Water Heater / Geyser / Boiler
        if ( str_contains( $n, 'heater' ) || str_contains( $n, 'geyser' ) || str_contains( $n, 'boiler' ) || str_contains( $n, 'flame' ) ) {
            return '<svg ' . $attr . '><path d="M12 2c2 4 4 6 4 9a4 4 0 11-8 0c0-3 2-5 4-9z"/></svg>';
        }

        // 19. Garden / Lawn / Green / Landscaping
        if ( str_contains( $n, 'garden' ) || str_contains( $n, 'green' ) || str_contains( $n, 'lawn' ) || str_contains( $n, 'landscape' ) || str_contains( $n, 'plant' ) ) {
            return '<svg ' . $attr . '><path d="M12 21a9 9 0 009-9c0-4.97-4.03-9-9-9-4.97 0-9 4.03-9 9 0 4.97 4.03 9 9 9zM12 3v18M3 12c4.97 0 9 4.03 9 9"/></svg>';
        }

        // 20. Cinema / Theater / Media Room
        if ( str_contains( $n, 'cinema' ) || str_contains( $n, 'theater' ) || str_contains( $n, 'theatre' ) || str_contains( $n, 'movie' ) || str_contains( $n, 'media' ) ) {
            return '<svg ' . $attr . '><rect x="2" y="4" width="20" height="14" rx="2"/><path d="M8 20h8M12 18v2M7 8l3 2-3 2V8z"/></svg>';
        }

        // 21. Tennis Court / Squash / Sports Court
        if ( str_contains( $n, 'tennis' ) || str_contains( $n, 'court' ) || str_contains( $n, 'squash' ) || str_contains( $n, 'basketball' ) ) {
            return '<svg ' . $attr . '><circle cx="12" cy="12" r="9"/><path d="M12 3a13 13 0 010 18M12 3a13 13 0 000 18M3 12h18"/></svg>';
        }

        // 22. Laundry / Washing Machine
        if ( str_contains( $n, 'laundry' ) || str_contains( $n, 'wash' ) || str_contains( $n, 'dryer' ) ) {
            return '<svg ' . $attr . '><rect x="4" y="2" width="16" height="20" rx="2"/><circle cx="12" cy="13" r="5"/><path d="M12 10a3 3 0 00-3 3M8 6h.01M12 6h.01"/></svg>';
        }

        // 23. EV Charger / Electric Vehicle
        if ( str_contains( $n, 'ev' ) || str_contains( $n, 'charger' ) ) {
            return '<svg ' . $attr . '><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8zM18 10h4v10h-4z"/></svg>';
        }

        // 24. Smart Home Automation / Alexa
        if ( str_contains( $n, 'smart' ) || str_contains( $n, 'auto' ) || str_contains( $n, 'home' ) ) {
            return '<svg ' . $attr . '><path d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h12a1 1 0 001-1V10M12 18a2.5 2.5 0 100-5 2.5 2.5 0 000 5z"/><path d="M9.17 11.17a4 4 0 015.66 0"/></svg>';
        }

        // Default Fallback: Sleek Sparkles / Diamond Star
        return '<svg ' . $attr . '><path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z"/></svg>';
    }

    /**
     * Resolves human-readable label for any amenity key.
     *
     * @param string $key_or_name Slug or name.
     * @return string
     */
    public static function get_label( string $key_or_name ): string {
        $catalog = self::get_all();
        if ( isset( $catalog[ $key_or_name ] ) ) {
            return $catalog[ $key_or_name ]['label'];
        }
        $key = sanitize_key( strtolower( str_replace( [ ' ', '-', '/' ], '_', $key_or_name ) ) );
        if ( isset( $catalog[ $key ] ) ) {
            return $catalog[ $key ]['label'];
        }
        return ucwords( str_replace( '_', ' ', $key_or_name ) );
    }

    /**
     * Prepares full display items for single property view.
     *
     * @param array $active_keys Active amenity keys saved on the property.
     * @return array{active: array, inactive: array}
     */
    public static function get_single_display_lists( array $active_keys ): array {
        $catalog = self::get_all();
        $normalized_active = [];
        foreach ( $active_keys as $k ) {
            $normalized_active[ sanitize_key( (string) $k ) ] = (string) $k;
        }

        $active_items   = [];
        $inactive_items = [];

        // First pass: add all active items in order
        foreach ( $active_keys as $raw_key ) {
            $k = sanitize_key( (string) $raw_key );
            $label = self::get_label( (string) $raw_key );
            $svg   = self::get_icon_svg( (string) $raw_key . ' ' . $label, 'w-4 h-4 sm:w-5 sm:h-5' );
            $active_items[ $k ] = [
                'key'       => $k,
                'label'     => $label,
                'icon_svg'  => $svg,
                'is_active' => true,
            ];
        }

        // Second pass: catalog standard items not selected
        foreach ( $catalog as $k => $data ) {
            if ( ! isset( $active_items[ $k ] ) ) {
                $inactive_items[ $k ] = [
                    'key'       => $k,
                    'label'     => $data['label'],
                    'icon_svg'  => self::get_icon_svg( $k . ' ' . $data['label'], 'w-4 h-4 sm:w-5 sm:h-5' ),
                    'is_active' => false,
                ];
            }
        }

        return [
            'active'   => array_values( $active_items ),
            'inactive' => array_values( $inactive_items ),
        ];
    }
}
