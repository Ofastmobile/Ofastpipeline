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
        // Priority 20, not 1: the property CPT registers on init priority 10. At
        // priority 1 get_permalink() cannot build pretty /property/{slug}/ URLs
        // yet and returns ?post_type=ofp_property&p=ID instead.
        add_action( 'init',          [ $this, 'handle_stealth_routes' ], 20 );
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

        add_filter( 'rest_pre_serve_request', function ( $value, $result = null, $request = null ) {
            // Only OUR namespace is open to any origin. Every other REST route
            // (wp/v2, WooCommerce, other plugins) keeps WordPress's own CORS
            // handling. Before, this plugin replaced it site-wide.
            if ( ! ( $request instanceof WP_REST_Request ) || 0 !== strpos( $request->get_route(), '/ofp/v1/' ) ) {
                return rest_send_cors_headers( $value );
            }

            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With' );
            header( 'Access-Control-Max-Age: 86400' );

            // Handle preflight OPTIONS requests.
            if ( 'OPTIONS' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
                status_header( 200 );
                exit;
            }

            return $value;
        }, 10, 3 );
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

        // ── 3. (no Turnstile here) ────────────────────────────────────────────
        // Turnstile is only on login and sign-up. Lead and inspection forms are
        // protected by the honeypot, the phone check, the duplicate check and the
        // per-IP rate limit above/below. For heavy bot traffic add a Cloudflare
        // rate limiting rule on /api/v1/inquire and /wp-json/ofp/v1/capture-lead.

        // ── 4. Validate inputs ────────────────────────────────────────────────
        $client_id   = (int) $request->get_param( 'client_id' );
        $phone       = OFP_Security::sanitize_phone( (string) $request->get_param( 'phone' ) );
        $name        = sanitize_text_field( (string) $request->get_param( 'name' ) );
        $email       = sanitize_email( (string) $request->get_param( 'email' ) );
        $property_id = (int) $request->get_param( 'property_id' ) ?: null;
        $message     = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
        $source      = $request->get_header( 'referer' ) ?: 'property_detail';

        // Match the column sizes (ofp_leads.name = 100, source = 150). A longer
        // value makes the INSERT fail in strict SQL mode and the lead is lost.
        $name   = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 )   : substr( $name, 0, 100 );
        $source = function_exists( 'mb_substr' ) ? mb_substr( $source, 0, 150 ) : substr( $source, 0, 150 );

        // A property id sent from outside is only trusted when it is a real
        // listing AND belongs to the client it is sent with. Otherwise it is
        // dropped (the lead itself is still saved).
        if ( $property_id ) {
            if ( 'ofp_property' !== get_post_type( $property_id ) ) {
                $property_id = null;
            } elseif ( $client_id && (int) get_post_meta( $property_id, 'ofp_client_id', true ) !== $client_id ) {
                $property_id = null;
            }
        }

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
                    // Keep the lead record in step with the new inspection request.
                    $note = $this->inspection_note( $property_id, $request );
                    if ( $note ) {
                        $old = (string) $wpdb->get_var( $wpdb->prepare(
                            "SELECT notes FROM {$wpdb->prefix}ofp_leads WHERE id = %d",
                            $existing_lead_id
                        ) );
                        $wpdb->update(
                            $wpdb->prefix . 'ofp_leads',
                            [ 'notes' => trim( $old . "\n" . $note ) ],
                            [ 'id' => $existing_lead_id ]
                        );
                    }
                }
            }
            return new WP_REST_Response(
                [ 'success' => true, 'message' => 'Thank you! We already have your details and will be in touch.' ],
                200
            );
        }

        // ── 7. Create lead and populate trigger queue ─────────────────────────
        // An inspection request IS a lead. Its details (property, tour type, date,
        // time) are written into the lead notes so the lead screen, triggers and
        // automation all see them, not only the Inspections page.
        $inspection = $property_id ? $this->inspection_note( $property_id, $request ) : '';
        if ( $inspection ) {
            $message = trim( $message . "\n" . $inspection );
        }

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

            // Instant SMS and email to the lead, and a new lead alert to the client.
            // This runs after the visitor has their thank you reply.
            OFP_Queue::send_instant_after_response( $client_id, $lead_id );

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
    /**
     * One readable line describing an inspection request, or '' when the
     * request carries no inspection details.
     */
    private function inspection_note( int $property_id, WP_REST_Request $request ): string {
        $tour = sanitize_key( (string) ( $request->get_param( 'tour_type' ) ?? '' ) );
        $date = sanitize_text_field( (string) ( $request->get_param( 'viewing_date' ) ?? '' ) );
        $time = sanitize_text_field( (string) ( $request->get_param( 'viewing_time' ) ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $date = '';
        }
        if ( '' === $tour && '' === $date && '' === $time ) {
            return '';
        }
        $parts = [ 'Inspection request: ' . get_the_title( $property_id ) . ' (#' . $property_id . ')' ];
        $parts[] = 'whatsapp_video' === $tour ? 'WhatsApp video tour' : 'Physical visit';
        if ( $date ) { $parts[] = $date; }
        if ( $time ) { $parts[] = substr( $time, 0, 30 ); }
        return implode( ' | ', $parts );
    }

    private function record_property_inquiry(
        int $lead_id,
        int $property_id,
        int $client_id,
        WP_REST_Request $request
    ): void {
        global $wpdb;

        $viewing_date = sanitize_text_field( $request->get_param( 'viewing_date' ) ?? '' );
        $message      = sanitize_textarea_field( $request->get_param( 'message' ) ?? '' );

        // The column is DATE. Anything that is not YYYY-MM-DD would make the
        // whole insert fail, so it is stored as NULL instead.
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $viewing_date ) ) {
            $viewing_date = '';
        }

        $row = [
            'lead_id'                => $lead_id,
            'property_id'            => $property_id,
            'client_id'              => $client_id,
            'preferred_viewing_date' => $viewing_date ?: null,
            'message'                => $message ?: null,
            'created_at'             => current_time( 'mysql' ),
        ];

        // tour_type and preferred_viewing_time exist once OFP_Property_Inquiry has
        // migrated the table. The property page sends both and the Inspections
        // screen shows them, but they were never saved. Only written when the
        // columns exist, so an un-migrated site does not lose the whole row.
        if ( get_option( 'ofp_schema_inquiries_v2' ) ) {
            $tour_type = sanitize_key( (string) ( $request->get_param( 'tour_type' ) ?? '' ) );
            $view_time = sanitize_text_field( (string) ( $request->get_param( 'viewing_time' ) ?? '' ) );

            $row['tour_type']              = in_array( $tour_type, [ 'physical', 'whatsapp_video' ], true ) ? $tour_type : 'physical';
            $row['preferred_viewing_time'] = $view_time !== '' ? substr( $view_time, 0, 30 ) : null;
        }

        $wpdb->insert( $wpdb->prefix . 'ofp_property_inquiries', $row );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC PROPERTY FEED (Website Embed Widget)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Public property feed for website embed widgets.
     *
     * What this returns, and why it is safe to leave public:
     *  - Only a fixed allow-list of fields (see $properties below). No raw rows,
     *    no table names, no file paths, no owner contact details.
     *  - Only listings that are live in ofp_properties AND published in WordPress
     *    AND not currently let (OFP_Property_Rent_Visibility applies through
     *    WP_Query). The old raw SQL feed skipped the last two checks.
     *  - Unknown, suspended, cancelled or trashed clients all get the same empty
     *    200 answer, so client ids cannot be enumerated by status.
     *
     * Where each card links (decided by OFP_Property_CPT::rewrite_permalink_...):
     *  - normal subscriber      -> marketplace.{base_domain}/property/{slug}/
     *  - white label client     -> their own host (custom domain, else subdomain)
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

        $limit    = min( 24, max( 1, (int) ( $request->get_param( 'limit' ) ?: 6 ) ) );
        $type     = sanitize_key( (string) ( $request->get_param( 'type' ) ?: '' ) );
        $type_key = in_array( $type, [ 'sale', 'rent', 'land', 'shortlet' ], true ) ? $type : '';

        // Short cache: the feed is public and every visitor of every embedding
        // website calls it. 60 seconds keeps the database quiet and new listings
        // still appear within a minute.
        $cache_key = 'ofp_feed_' . md5( $client_id . '|' . $limit . '|' . $type_key );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $this->feed_response( $cached );
        }

        $empty = [ 'success' => true, 'client_name' => '', 'count' => 0, 'properties' => [] ];
        $p     = $wpdb->prefix;

        $client = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, business_name, status FROM {$p}ofp_clients WHERE id = %d LIMIT 1",
            $client_id
        ) );

        if ( ! $client || in_array( $client->status, [ 'suspended', 'cancelled', 'trash' ], true ) ) {
            return $this->feed_response( $empty );
        }

        $where = [ 'client_id = %d', "status = 'live'", 'wp_post_id > 0' ];
        $args  = [ $client_id ];

        if ( $type_key !== '' ) {
            $where[] = 'listing_type = %s';
            $args[]  = $type_key;
        }

        // Ask for more rows than the limit: some are removed below (not published,
        // currently let), and the visitor should still get a full grid.
        $args[] = $limit * 3;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, wp_post_id, title, price, location_text, listing_type
             FROM {$p}ofp_properties
             WHERE " . implode( ' AND ', $where ) . "
             ORDER BY id DESC
             LIMIT %d",
            ...$args
        ) );

        $by_post = [];
        foreach ( (array) $rows as $row ) {
            $by_post[ (int) $row->wp_post_id ] = $row;
        }

        if ( ! $by_post ) {
            $empty['client_name'] = (string) $client->business_name;
            return $this->feed_response( $empty );
        }

        // WP_Query so that publish status and the "currently let" rule are applied.
        // ofp_all_clients lifts the white label host scoping, because this feed is
        // called from the main host or from a client's external website, not from
        // the white label client's own domain.
        $query = new WP_Query( [
            'post_type'           => 'ofp_property',
            'post_status'         => 'publish',
            'post__in'            => array_keys( $by_post ),
            'orderby'             => 'post__in',
            'posts_per_page'      => $limit,
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'ofp_all_clients'     => true,
        ] );

        $period_suffix = [ 'year' => '/yr', 'month' => '/mo', 'night' => '/night' ];
        $properties    = [];

        foreach ( $query->posts as $post ) {
            $post_id = (int) $post->ID;
            $row     = $by_post[ $post_id ] ?? null;
            if ( ! $row ) {
                continue;
            }

            // The post_type_link filter puts white label listings on their own host
            // (or subdomain) and normal subscribers on the marketplace subdomain.
            $permalink = (string) get_permalink( $post_id );

            $img_url = get_the_post_thumbnail_url( $post_id, 'medium_large' );
            if ( ! $img_url ) {
                $gallery = get_post_meta( $post_id, 'ofp_gallery', true );
                if ( is_array( $gallery ) && ! empty( $gallery[0] ) ) {
                    $img_url = wp_get_attachment_image_url( (int) $gallery[0], 'medium_large' ) ?: '';
                }
            }
            if ( ! $img_url ) {
                $img_url = function_exists( 'ofp_image_url' ) ? ofp_image_url( 'placeholder-property.jpg' ) : ( defined( 'OFP_URL' ) ? OFP_URL . 'assets/images/placeholder-property.jpg' : '' );
            }

            $currency    = get_post_meta( $post_id, 'ofp_currency', true ) ?: 'NGN';
            $curr_symbol = ( $currency === 'USD' ) ? '$' : ( ( $currency === 'GBP' ) ? '£' : '₦' );
            $rent_period = (string) get_post_meta( $post_id, 'ofp_rent_period', true );
            $suffix      = ( 'rent' === $row->listing_type && isset( $period_suffix[ $rent_period ] ) )
                ? ' ' . $period_suffix[ $rent_period ]
                : '';

            $properties[] = [
                'id'              => (int) $row->id,
                'title'           => (string) $row->title,
                'price'           => (float) $row->price,
                'formatted_price' => $curr_symbol . number_format( (float) $row->price ) . $suffix,
                'location'        => (string) ( $row->location_text ?: '' ),
                'listing_type'    => (string) ( $row->listing_type ?: 'sale' ),
                'bedrooms'        => (int) get_post_meta( $post_id, 'ofp_bedrooms', true ),
                'bathrooms'       => (int) get_post_meta( $post_id, 'ofp_bathrooms', true ),
                'image_url'       => (string) $img_url,
                'url'             => $permalink,
            ];
        }

        $payload = [
            'success'     => true,
            // Brand name only. owner_name is a private person's name.
            'client_name' => (string) $client->business_name,
            'count'       => count( $properties ),
            'properties'  => $properties,
        ];

        set_transient( $cache_key, $payload, 60 );

        return $this->feed_response( $payload );
    }

    /**
     * Wrap a feed payload with a short public cache header, so browsers and
     * Cloudflare can absorb repeat hits from busy client websites.
     */
    private function feed_response( array $payload ): WP_REST_Response {
        $response = new WP_REST_Response( $payload, 200 );
        $response->header( 'Cache-Control', 'public, max-age=60' );
        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STEALTH SAAS ALIASES & REWRITES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Handles clean, branded SaaS routes:
     *  - /widget.js          -> Serves embed script without plugin path
     *  - /api/v1/inquire     -> Lead capture endpoint
     *  - /api/v1/properties  -> Property feed endpoint
     *
     * Supports automatic client identification from custom domains / subdomains.
     *
     * NOTE: these hide the plugin folder from the SNIPPET a client pastes, not from
     * the site. WordPress still prints /wp-content/plugins/ofast-pipeline/ asset
     * URLs on public pages, so do not rely on this for secrecy. The real
     * protections are the allow-listed feed above, the ABSPATH guard on every PHP
     * file, and web server settings (no directory listing).
     */
    public function handle_stealth_routes(): void {
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        $path = trim( (string) parse_url( $uri, PHP_URL_PATH ), '/' );

        if ( ! in_array( $path, [ 'widget.js', 'api/v1/inquire', 'api/v1/properties' ], true ) ) {
            return;
        }

        $method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

        // 1. /widget.js
        if ( $path === 'widget.js' ) {
            header( 'Content-Type: application/javascript; charset=utf-8' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Cache-Control: public, max-age=3600' );
            $file = OFP_PATH . 'assets/js/ofp-embed.js';
            if ( file_exists( $file ) ) {
                readfile( $file );
            }
            exit;
        }

        // Both API routes below answer in JSON and are called cross-origin.
        header( 'Access-Control-Allow-Origin: *' );
        header( 'X-Content-Type-Options: nosniff' );

        // 2. /api/v1/inquire
        if ( $path === 'api/v1/inquire' ) {
            header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization' );

            if ( $method === 'OPTIONS' ) {
                status_header( 204 );
                exit;
            }
            if ( $method !== 'POST' ) {
                header( 'Allow: POST, OPTIONS' );
                wp_send_json( [ 'success' => false, 'message' => 'Method not allowed.' ], 405 );
            }

            // Tell OFP_Security::check_rate_limit() to answer in JSON, not an HTML page.
            if ( ! defined( 'OFP_JSON_API' ) ) {
                define( 'OFP_JSON_API', true );
            }
            nocache_headers();

            // WordPress adds slashes to $_POST. The REST server removes them before
            // a handler sees the data, this route has to do the same, otherwise
            // "O'Brien" is saved as "O\'Brien".
            $raw_input   = file_get_contents( 'php://input' );
            $json_body   = json_decode( (string) $raw_input, true );
            $body_params = is_array( $json_body )
                ? array_merge( wp_unslash( $_POST ), $json_body )
                : wp_unslash( $_POST );

            // Auto-resolve client_id from host if on custom domain or client subdomain
            if ( empty( $body_params['client_id'] ) && class_exists( 'OFP_Host_Router' ) ) {
                $host_client = OFP_Host_Router::current_client_record();
                if ( $host_client && ! empty( $host_client->id ) ) {
                    $body_params['client_id'] = (int) $host_client->id;
                }
            }

            $req = new WP_REST_Request( 'POST', '/ofp/v1/capture-lead' );
            $req->set_body_params( $body_params );

            // A hand-built request has no headers. Pass the real referer through so
            // the lead's "source" shows which page of the client's site it came from.
            if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
                $req->set_header( 'referer', esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
            }

            $res = $this->capture_lead( $req );

            wp_send_json( $res->get_data(), $res->get_status() );
        }

        // 3. /api/v1/properties
        if ( $path === 'api/v1/properties' ) {
            header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type, X-Requested-With' );

            if ( $method === 'OPTIONS' ) {
                status_header( 204 );
                exit;
            }
            if ( $method !== 'GET' && $method !== 'HEAD' ) {
                header( 'Allow: GET, HEAD, OPTIONS' );
                wp_send_json( [ 'success' => false, 'message' => 'Method not allowed.', 'properties' => [] ], 405 );
            }

            $query_params = wp_unslash( $_GET );

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

            foreach ( $res->get_headers() as $name => $value ) {
                header( $name . ': ' . $value );
            }
            wp_send_json( $res->get_data(), $res->get_status() );
        }
    }
}
