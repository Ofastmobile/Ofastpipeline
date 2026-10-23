<?php
/**
 * OFP_REST_API
 *
 * Registers all public-facing REST endpoints for OFast Pipeline.
 *
 * ENDPOINTS:
 *  POST /wp-json/ofp/v1/capture-lead       Lead submission from landing pages
 *  POST /wp-json/ofp/v1/webhook/payment    Payment gateway webhook (provider-agnostic)
 *  POST /wp-json/ofp/v1/webhook/voice-ivr  Africa's Talking IVR callback
 *  POST /wp-json/ofp/v1/webhook/sms-dlr    SMS delivery receipt
 *
 * SECURITY ON capture-lead:
 *  1. Honeypot field check (bot trap)
 *  2. Rate limiting (3 submissions per IP per 10 minutes)
 *  3. Cloudflare Turnstile verification (bypassed if no secret key configured)
 *  4. Client ID validation (must exist and be active)
 *  5. Duplicate phone check (same phone + client within 24 hours)
 *
 * CORS:
 *  All endpoints allow cross-origin requests so client landing pages on
 *  separate domains/subdomains can POST to this site's REST API.
 *
 * Depends on: OFP_Security, OFP_Lead, OFP_Queue, OFP_IVR, OFP_Voice.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_REST_API {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_action( 'rest_api_init', [ $this, 'add_cors_headers' ] );
        add_action( 'init',          [ $this, 'handle_stealth_routes' ], 1 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CORS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add CORS headers to all REST responses.
     * Required because client landing pages live on different domains.
     *
     * @return void
     */
    public function add_cors_headers(): void {
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

        add_filter( 'rest_pre_serve_request', function ( $value ) {
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With' );
            header( 'Access-Control-Max-Age: 86400' );

            // Handle preflight OPTIONS requests.
            if ( 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
                status_header( 200 );
                exit;
            }

            return $value;
        } );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ROUTE REGISTRATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Register all REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {

        // Lead capture — called from landing page forms.
        register_rest_route( 'ofp/v1', '/capture-lead', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'capture_lead' ],
            'permission_callback' => '__return_true',
        ] );

        // Payment gateway webhook — provider-agnostic, built in Phase 6.
        register_rest_route( 'ofp/v1', '/webhook/payment', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'payment_webhook' ],
            'permission_callback' => '__return_true',
        ] );

        // Africa's Talking IVR callback.
        register_rest_route( 'ofp/v1', '/webhook/voice-ivr', [
            'methods'             => [ 'POST', 'GET' ],
            'callback'            => [ $this, 'voice_ivr_webhook' ],
            'permission_callback' => '__return_true',
        ] );

        // SMS delivery receipt callback.
        register_rest_route( 'ofp/v1', '/webhook/sms-dlr', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'sms_dlr_webhook' ],
            'permission_callback' => '__return_true',
        ] );

        // Public property feed for website embed widgets.
        register_rest_route( 'ofp/v1', '/properties', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_properties' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEAD CAPTURE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Handle a lead submission from a client's landing page.
     *
     * This is the most important endpoint in the entire plugin.
     * Every lead that comes through the pipeline starts here.
     *
     * Expected POST body:
     *  client_id              (int, required)
     *  name                   (string, optional)
     *  phone                  (string, required)
     *  email                  (string, optional)
     *  cf-turnstile-response  (string, required in production)
     *  website                (string, honeypot — must be empty)
     *  property_id            (int, optional — for listing inquiries, v2.1)
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function capture_lead( WP_REST_Request $request ): WP_REST_Response {

        // ── 1. Honeypot check ─────────────────────────────────────────────────
        // Bots fill all fields including hidden ones. Humans never see 'website'.
        if ( ! empty( $request->get_param( 'website' ) ) ) {
            // Return a success-looking response so bots don't retry.
            return new WP_REST_Response( [ 'success' => true ], 200 );
        }

        // ── 2. Rate limit ─────────────────────────────────────────────────────
        // Max 3 submissions per IP per 10 minutes.
        OFP_Security::check_rate_limit(
            OFP_Security::get_client_ip(),
            'lead_submit',
            3,
            600
        );

        // ── 3. Turnstile verification ─────────────────────────────────────────
        $turnstile_token = $request->get_param( 'cf-turnstile-response' );
        if ( ! OFP_Security::verify_turnstile( $turnstile_token ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => 'Security verification failed. Please try again.' ],
                200
            );
        }

        // ── 4. Validate inputs ────────────────────────────────────────────────
        $client_id   = (int) $request->get_param( 'client_id' );
        $phone       = OFP_Security::sanitize_phone( (string) $request->get_param( 'phone' ) );
        $name        = sanitize_text_field( (string) $request->get_param( 'name' ) );
        $email       = sanitize_email( (string) $request->get_param( 'email' ) );
        $property_id = (int) $request->get_param( 'property_id' ) ?: null;
        $message     = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
        $source      = $request->get_header( 'referer' ) ?: 'property_detail';

        // Support platform-owned listings (client_id 0)
        $is_platform_property = false;
        if ( ! $client_id && $property_id ) {
            $prop_owner = (int) get_post_meta( $property_id, 'ofp_client_id', true );
            if ( $prop_owner === 0 ) {
                $is_platform_property = true;
            } else {
                $client_id = $prop_owner;
            }
        }

        if ( ! $client_id && ! $is_platform_property ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => 'Invalid request.' ],
                200
            );
        }

        if ( ! OFP_Security::is_valid_phone( $phone ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => 'Please enter a valid phone number.' ],
                200
            );
        }

        // ── 5. Validate client ────────────────────────────────────────────────
        global $wpdb;
        if ( ! $is_platform_property && $client_id > 0 ) {
            $client = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, status FROM {$wpdb->prefix}ofp_clients
                     WHERE id = %d AND status = 'active'
                     LIMIT 1",
                    $client_id
                )
            );

            if ( ! $client ) {
                // Client not found or not active — return generic success to avoid
                // exposing client status information to the public.
                return new WP_REST_Response(
                    [ 'success' => true, 'message' => 'Thank you! We will be in touch shortly.' ],
                    200
                );
            }
        }

        // ── 6. Duplicate check ────────────────────────────────────────────────
        // Same phone number for the same client within 24 hours = duplicate.
        if ( $client_id > 0 && OFP_Lead::is_duplicate( $client_id, $phone, 24 ) ) {
            // Still record inquiry if this is for a property
            if ( $property_id ) {
                $existing_lead_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ofp_leads WHERE client_id = %d AND phone = %s ORDER BY id DESC LIMIT 1",
                    $client_id,
                    $phone
                ) );
                if ( $existing_lead_id ) {
                    $this->record_property_inquiry( $existing_lead_id, $property_id, $client_id, $request );
                }
            }
            return new WP_REST_Response(
                [ 'success' => true, 'message' => 'Thank you! We already have your details and will be in touch.' ],
                200
            );
        }

        // ── 7. Create lead and populate trigger queue ─────────────────────────
        $lead_id = OFP_Lead::create(
            $client_id,
            $name,
            $phone,
            $email ?: null,
            $source,
            $property_id,
            $message ?: null
        );

        if ( ! $lead_id ) {
            error_log( "[OFP_REST_API] Failed to create lead for client {$client_id}" );
            return new WP_REST_Response(
                [ 'success' => false, 'message' => 'Something went wrong. Please try again.' ],
                200
            );
        }

        // Automation queue only runs for clients with CRM configs
        if ( $client_id > 0 ) {
            OFP_Queue::populate_triggers( $client_id, $lead_id );

            // Simple "an agent will reach out" call, if the client switched it on.
            // Only form leads reach this point. Manual uploads never do.
            if ( class_exists( 'OFP_Voice_Notice' ) ) {
                OFP_Voice_Notice::schedule_interest_call( $client_id, $lead_id );
            }
        }

        // ── 8. Handle property inquiry extras (v2.1) ──────────────────────────
        if ( $property_id ) {
            $this->record_property_inquiry( $lead_id, $property_id, $client_id, $request );
        }

        return new WP_REST_Response(
            [ 'success' => true, 'message' => 'Thank you! We will be in touch with you shortly.' ],
            200
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WEBHOOKS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Payment gateway webhook — provider-agnostic handler.
     * Full implementation in Phase 6 (OFP_Payment).
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function payment_webhook( WP_REST_Request $request ): WP_REST_Response {

        if ( class_exists( 'OFP_Payment' ) ) {
            return OFP_Payment::handle_webhook( $request );
        }

        // Phase 6 not built yet — log and acknowledge.
        error_log( '[OFP_REST_API] Payment webhook received but OFP_Payment not built yet.' );
        return new WP_REST_Response( [ 'status' => 'received' ], 200 );
    }

    /**
     * Africa's Talking IVR callback — handled by OFP_IVR.
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function voice_ivr_webhook( WP_REST_Request $request ): WP_REST_Response {

        if ( class_exists( 'OFP_IVR' ) && method_exists( 'OFP_IVR', 'handle_callback' ) ) {
            return OFP_IVR::handle_callback( $request );
        }

        return new WP_REST_Response( [ 'status' => 'received' ], 200 );
    }

    /**
     * SMS delivery receipt — logs final delivery status from provider.
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function sms_dlr_webhook( WP_REST_Request $request ): WP_REST_Response {

        $message_id = sanitize_text_field( $request->get_param( 'id' ) ?? '' );
        $status     = sanitize_text_field( $request->get_param( 'status' ) ?? '' );

        if ( $message_id && $status ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'ofp_communications_log',
                [ 'status' => $status ],
                [ 'provider_ref' => $message_id ]
            );
        }

        return new WP_REST_Response( [ 'status' => 'received' ], 200 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROPERTY INQUIRY (v2.1)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Record supplementary property inquiry details.
     * Core lead data is already in ofp_leads — this stores extras
     * like viewing date preference and message from the inquiry form.
     *
     * @param  int             $lead_id      Lead ID.
     * @param  int             $property_id  Property ID.
     * @param  int             $client_id    Client ID.
     * @param  WP_REST_Request $request      Original request.
     * @return void
     */
    private function record_property_inquiry(
        int $lead_id,
        int $property_id,
        int $client_id,
        WP_REST_Request $request
    ): void {
        global $wpdb;

        $viewing_date = sanitize_text_field( $request->get_param( 'viewing_date' ) ?? '' );
        $message      = sanitize_textarea_field( $request->get_param( 'message' ) ?? '' );

        $wpdb->insert(
            $wpdb->prefix . 'ofp_property_inquiries',
            [
                'lead_id'               => $lead_id,
                'property_id'           => $property_id,
                'client_id'             => $client_id,
                'preferred_viewing_date' => $viewing_date ?: null,
                'message'               => $message ?: null,
                'created_at'            => current_time( 'mysql' ),
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC PROPERTY FEED (Website Embed Widget)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Public property feed for website embed widgets.
     * Returns active properties for a given client with permalinks.
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_properties( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $client_id = (int) $request->get_param( 'client_id' );
        if ( ! $client_id ) {
            return new WP_REST_Response( [
                'success'    => false,
                'message'    => 'Missing required client_id parameter.',
                'properties' => [],
            ], 400 );
        }

        $limit = min( 24, max( 1, (int) ( $request->get_param( 'limit' ) ?: 6 ) ) );
        $type  = sanitize_text_field( $request->get_param( 'type' ) ?: '' );

        $p = $wpdb->prefix;
        $client = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, business_name, owner_name, custom_domain, is_white_label FROM {$p}ofp_clients WHERE id = %d LIMIT 1",
            $client_id
        ) );

        if ( ! $client ) {
            return new WP_REST_Response( [
                'success'    => false,
                'message'    => 'Client not found.',
                'properties' => [],
            ], 404 );
        }

        $where = [ 'client_id = %d', "status = 'live'" ];
        $args  = [ $client_id ];

        if ( in_array( $type, [ 'sale', 'rent', 'land' ], true ) ) {
            $where[] = 'listing_type = %s';
            $args[]  = $type;
        }

        $where_sql = implode( ' AND ', $where );
        $args[]    = $limit;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, wp_post_id, title, price, location_text, listing_type, created_at 
             FROM {$p}ofp_properties 
             WHERE {$where_sql} 
             ORDER BY id DESC 
             LIMIT %d",
            ...$args
        ) );

        $properties = [];
        foreach ( (array) $rows as $row ) {
            $post_id   = (int) $row->wp_post_id;
            $permalink = get_permalink( $post_id ) ?: '';

            // If white label client has a custom domain configured, ensure permalink targets their domain
            if ( ! empty( $client->is_white_label ) && ! empty( $client->custom_domain ) ) {
                $custom_host = preg_replace( '#^https?://#', '', trim( $client->custom_domain ) );
                $permalink   = preg_replace( '#^https?://[^/]+#', 'https://' . $custom_host, $permalink );
            }

            // Cover Image
            $img_url = get_the_post_thumbnail_url( $post_id, 'medium_large' );
            if ( ! $img_url ) {
                $gallery = get_post_meta( $post_id, 'ofp_gallery', true );
                if ( is_array( $gallery ) && ! empty( $gallery[0] ) ) {
                    $img_url = wp_get_attachment_image_url( (int) $gallery[0], 'medium_large' ) ?: '';
                }
            }
            if ( ! $img_url ) {
                $img_url = defined( 'OFP_URL' ) ? OFP_URL . 'assets/images/placeholder-property.jpg' : '';
            }

            $bedrooms    = (int) get_post_meta( $post_id, 'ofp_bedrooms', true );
            $bathrooms   = (int) get_post_meta( $post_id, 'ofp_bathrooms', true );
            $currency    = get_post_meta( $post_id, 'ofp_currency', true ) ?: 'NGN';
            $curr_symbol = ( $currency === 'USD' ) ? '$' : ( ( $currency === 'GBP' ) ? '£' : '₦' );

            $properties[] = [
                'id'              => (int) $row->id,
                'title'           => $row->title,
                'price'           => (float) $row->price,
                'formatted_price' => $curr_symbol . number_format( (float) $row->price ),
                'location'        => $row->location_text ?: '',
                'listing_type'    => $row->listing_type ?: 'sale',
                'bedrooms'        => $bedrooms,
                'bathrooms'       => $bathrooms,
                'image_url'       => $img_url,
                'url'             => $permalink,
            ];
        }

        return new WP_REST_Response( [
            'success'     => true,
            'client_name' => $client->business_name ?: $client->owner_name,
            'count'       => count( $properties ),
            'properties'  => $properties,
        ], 200 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STEALTH SAAS ALIASES & REWRITES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Handles clean, stealth SaaS routes:
     *  - /widget.js          -> Serves embed script without plugin path
     *  - /api/v1/inquire     -> Stealth lead capture endpoint
     *  - /api/v1/properties  -> Stealth property feed endpoint
     *
     * Supports automatic client identification from custom domains / subdomains.
     */
    public function handle_stealth_routes(): void {
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        $path = trim( (string) parse_url( $uri, PHP_URL_PATH ), '/' );

        // 1. /widget.js
        if ( $path === 'widget.js' ) {
            header( 'Content-Type: application/javascript; charset=utf-8' );
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Cache-Control: public, max-age=3600' );
            $file = OFP_PATH . 'assets/js/ofp-embed.js';
            if ( file_exists( $file ) ) {
                readfile( $file );
            }
            exit;
        }

        // 2. /api/v1/inquire
        if ( $path === 'api/v1/inquire' ) {
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization' );

            if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'OPTIONS' ) {
                status_header( 204 );
                exit;
            }

            // Parse body
            $raw_input   = file_get_contents( 'php://input' );
            $json_body   = json_decode( $raw_input, true );
            $body_params = is_array( $json_body ) ? array_merge( $_POST, $json_body ) : $_POST;

            // Auto-resolve client_id from host if on custom domain or client subdomain
            if ( empty( $body_params['client_id'] ) && class_exists( 'OFP_Host_Router' ) ) {
                $host_client = OFP_Host_Router::current_client_record();
                if ( $host_client && ! empty( $host_client->id ) ) {
                    $body_params['client_id'] = (int) $host_client->id;
                }
            }

            $req = new WP_REST_Request( 'POST', '/ofp/v1/capture-lead' );
            $req->set_body_params( $body_params );
            $res = $this->capture_lead( $req );

            wp_send_json( $res->get_data(), $res->get_status() );
            exit;
        }

        // 3. /api/v1/properties
        if ( $path === 'api/v1/properties' ) {
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With' );

            if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'OPTIONS' ) {
                status_header( 204 );
                exit;
            }

            $query_params = $_GET;

            // Auto-resolve client_id from host if on custom domain or client subdomain
            if ( empty( $query_params['client_id'] ) && class_exists( 'OFP_Host_Router' ) ) {
                $host_client = OFP_Host_Router::current_client_record();
                if ( $host_client && ! empty( $host_client->id ) ) {
                    $query_params['client_id'] = (int) $host_client->id;
                }
            }

            $req = new WP_REST_Request( 'GET', '/ofp/v1/properties' );
            $req->set_query_params( $query_params );
            $res = $this->get_properties( $req );

            wp_send_json( $res->get_data(), $res->get_status() );
            exit;
        }
    }
}
