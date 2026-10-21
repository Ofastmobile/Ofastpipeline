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
}
