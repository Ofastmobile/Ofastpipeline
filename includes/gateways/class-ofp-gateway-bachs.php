<?php
/**
 * OFP_Gateway_Bachs
 *
 * Bachs hosted checkout adapter. Implements OFP_Gateway_Interface.
 *
 * HOW IT WORKS
 *  1. initiate_transaction() creates a Bachs checkout session with a raw
 *     NGN amount (the "pricing" option, no catalog product needed) and
 *     returns the hosted checkout_url. The customer pays on Bachs.
 *  2. Bachs calls our webhook (POST /wp-json/ofp/v1/webhook/payment).
 *     handle_webhook() checks the signature, then confirms the payment
 *     through OFP_Payment, exactly as the old gateway did.
 *  3. The customer is sent back to /funding or /credits with
 *     ?checkout_id=... added by Bachs. OFP_Payment turns that id back into
 *     our own reference and settles it right away if the webhook is late.
 *
 * WEBHOOK SIGNATURE (from the Bachs docs)
 *  HMAC SHA256 hex digest of "{timestamp}.{raw_body}", using the endpoint
 *  signing secret. Headers: X-Bachs-Timestamp and X-Bachs-Signature, and
 *  X-Bachs-Signature-V2 which looks like "t=...,v1=...,v1=...". V2 can carry
 *  more than one v1 value while a secret is being rotated, so any v1 match
 *  is accepted. Deliveries older than 5 minutes are refused.
 *
 * KEYS
 *  ofp_bachs_api_key        sk_sandbox_... or sk_live_... (encrypted)
 *  ofp_bachs_webhook_secret signing secret of the webhook endpoint (encrypted)
 *  The key prefix decides the server: sandbox keys use sandbox-api.bachs.io,
 *  live keys use api.bachs.io. Going live is a key swap.
 *
 * Docs: https://docs.bachs.io
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Gateway_Bachs implements OFP_Gateway_Interface {

    const OPT_API_KEY        = 'ofp_bachs_api_key';
    const OPT_WEBHOOK_SECRET = 'ofp_bachs_webhook_secret';

    /** Our reference to Bachs checkout id, kept so a return or a webhook can be matched. */
    const MAP_OPTION = 'ofp_bachs_checkout_map';

    /** Seconds a webhook timestamp may differ from our clock. */
    const SIGNATURE_TOLERANCE = 300;

    private string $api_key;
    private string $webhook_secret;

    public function __construct() {
        $this->api_key        = (string) OFP_Security::decrypt( get_option( self::OPT_API_KEY, '' ) );
        $this->webhook_secret = (string) OFP_Security::decrypt( get_option( self::OPT_WEBHOOK_SECRET, '' ) );
    }

    /** Both the API key and the webhook secret must be saved. */
    public function is_configured(): bool {
        return $this->api_key !== '' && $this->webhook_secret !== '';
    }

    private function base_url(): string {
        return str_starts_with( $this->api_key, 'sk_sandbox_' )
            ? 'https://sandbox-api.bachs.io'
            : 'https://api.bachs.io';
    }

    private function headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // START A PAYMENT
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Creates a Bachs checkout session and returns the hosted checkout URL.
     *
     * @param  array $args client_id, amount (NGN), reference, email, name,
     *                     description, redirect_url
     * @return string|null Checkout URL, or null when Bachs refused or could not be reached.
     */
    public function initiate_transaction( array $args ): ?string {
        if ( $this->api_key === '' ) {
            error_log( 'OFP Bachs initiate_transaction: missing API key' );
            return null;
        }

        $reference = (string) ( $args['reference'] ?? '' );
        $amount    = (float) ( $args['amount'] ?? 0 );
        $email     = (string) ( $args['email'] ?? '' );
        if ( $reference === '' || $amount <= 0 || $email === '' ) {
            error_log( 'OFP Bachs initiate_transaction: reference, amount and email are all required' );
            return null;
        }

        $success_url = (string) ( $args['redirect_url'] ?? home_url( '/' ) );
        // Bachs adds ?checkout_id= to the success URL, so keep that URL free of other query parts.
        $success_url = remove_query_arg( [ 'sub_status', 'topup_status' ], $success_url );

        $customer = [ 'email' => $email ];
        $name     = trim( (string) ( $args['name'] ?? '' ) );
        if ( $name !== '' ) {
            $customer['name'] = $name;
        }

        $payload = [
            // Money is always a decimal string at the currency precision.
            'pricing'     => [
                'currency' => 'NGN',
                'amount'   => number_format( $amount, 2, '.', '' ),
            ],
            'customer'    => $customer,
            'success_url' => $success_url,
            'cancel_url'  => $success_url,
            // Unique for good at Bachs. Our references already carry a random part.
            'reference'   => $reference,
            'metadata'    => [
                'ofp_reference' => $reference,
                'client_id'     => (string) (int) ( $args['client_id'] ?? 0 ),
                'description'   => mb_substr( (string) ( $args['description'] ?? '' ), 0, 100 ),
            ],
        ];

        $response = wp_remote_post( $this->base_url() . '/v1/checkout-sessions', [
            'headers' => $this->headers(),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'OFP Bachs initiate_transaction request error: ' . $response->get_error_message() );
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        $checkout_id  = is_array( $body ) ? (string) ( $body['checkout_id'] ?? '' ) : '';
        $checkout_url = is_array( $body ) ? (string) ( $body['checkout_url'] ?? '' ) : '';

        if ( $code < 200 || $code >= 300 || $checkout_id === '' || ! str_starts_with( $checkout_url, 'https://' ) ) {
            error_log( 'OFP Bachs initiate_transaction unexpected response (' . $code . '): ' . mb_substr( wp_remote_retrieve_body( $response ), 0, 500 ) );
            return null;
        }

        $this->remember_checkout( $reference, $checkout_id );

        return $checkout_url;
    }

    // ─────────────────────────────────────────────────────────────────────
    // CHECK ONE PAYMENT (return page and hourly safety net)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Asks Bachs about one checkout.
     *
     * The webhook is the source of truth. This is only a safety net for a late
     * or missed webhook. It reports 'success' only when Bachs returns the amount
     * that was actually paid. If the session shows as completed but carries no
     * paid amount, it answers 'pending' and the webhook does the work.
     *
     * @return array|null [ 'status' => 'success'|'failed'|'pending', 'amount' => naira, 'id' => string ]
     *                    or null when we cannot tell.
     */
    public function verify_transaction( string $reference ): ?array {
        $checkout_id = $this->checkout_id_for_reference( $reference );
        if ( $this->api_key === '' || $checkout_id === '' ) {
            return null;
        }

        $session = $this->get_session( $checkout_id, 15 );
        if ( ! $session ) {
            return null;
        }

        $status = strtolower( (string) ( $session['status'] ?? '' ) );

        if ( $status === 'expired' ) {
            return [ 'status' => 'failed', 'amount' => 0.0, 'id' => $checkout_id ];
        }

        if ( in_array( $status, [ 'completed', 'complete', 'paid', 'succeeded' ], true ) ) {
            $paid = $this->paid_amount_from( $session );
            if ( $paid !== null && $paid > 0 ) {
                return [ 'status' => 'success', 'amount' => $paid, 'id' => $checkout_id ];
            }
            // Never guess the paid amount. Wait for the signed webhook, which carries it.
            error_log( 'OFP Bachs verify: session ' . $checkout_id . ' is ' . $status . ' but has no paid amount field. Fields seen: ' . implode( ',', array_keys( $session ) ) );
        }

        return [ 'status' => 'pending', 'amount' => 0.0, 'id' => $checkout_id ];
    }

    /** Paid amount from a retrieved checkout session, only if Bachs states it. */
    private function paid_amount_from( array $session ): ?float {
        foreach ( [ 'amount_paid', 'amount_received', 'paid_amount' ] as $key ) {
            if ( isset( $session[ $key ] ) && is_numeric( $session[ $key ] ) ) {
                return (float) $session[ $key ];
            }
        }
        return null;
    }

    private function get_session( string $checkout_id, int $timeout ): ?array {
        $response = wp_remote_get(
            $this->base_url() . '/v1/checkout-sessions/' . rawurlencode( $checkout_id ),
            [ 'headers' => $this->headers(), 'timeout' => $timeout ]
        );
        if ( is_wp_error( $response ) ) {
            error_log( 'OFP Bachs get_session request error: ' . $response->get_error_message() );
            return null;
        }
        if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? $body : null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // WEBHOOK
    // ─────────────────────────────────────────────────────────────────────

    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        // With no secret saved, a signature check would be meaningless. Refuse.
        if ( $this->webhook_secret === '' ) {
            return new WP_REST_Response( [ 'error' => 'Gateway not configured.' ], 401 );
        }

        // The raw body, exactly as Bachs sent it. Never re-encode it before checking the signature.
        $raw = (string) $request->get_body();

        $valid = $this->signature_is_valid(
            $raw,
            (string) $request->get_header( 'x-bachs-signature-v2' ),
            (string) $request->get_header( 'x-bachs-timestamp' ),
            (string) $request->get_header( 'x-bachs-signature' )
        );
        if ( ! $valid ) {
            error_log( '[OFP_Bachs] Webhook signature invalid or too old.' );
            // Bachs does not retry a 400, which is right for a delivery we reject.
            return new WP_REST_Response( [ 'error' => 'Invalid signature.' ], 400 );
        }

        $event = json_decode( $raw, true );
        if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) || ! isset( $event['data'] ) || ! is_array( $event['data'] ) ) {
            return new WP_REST_Response( [ 'error' => 'Malformed event.' ], 400 );
        }

        $event_id = (string) $event['id'];
        $type     = (string) $event['type'];
        $data     = $event['data'];

        // Bachs delivers at least once, so the same event can arrive twice.
        $seen_key = 'ofp_bachs_evt_' . md5( $event_id );
        if ( get_transient( $seen_key ) ) {
            return new WP_REST_Response( [ 'status' => 'duplicate' ], 200 );
        }

        if ( $type !== 'collection.succeeded' && $type !== 'collection.underpaid' ) {
            // checkout.completed, checkout.expired, collection.failed and the rest need no action here.
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $reference = $this->resolve_reference( $data );
        if ( $reference === '' ) {
            // Not one of our checkouts, for example another site on the same Bachs account.
            // Answer 200 so Bachs does not retry and later switch the endpoint off.
            error_log( '[OFP_Bachs] Event ' . $event_id . ' (' . $type . ') did not match any checkout of this site.' );
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $is_credit = OFP_Payment::is_credit_topup_reference( $reference );
        $is_sub    = OFP_Payment::is_subscription_checkout_reference( $reference );
        if ( ! $is_credit && ! $is_sub ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        // Work out the amount that was really paid.
        if ( $type === 'collection.succeeded' ) {
            $currency = strtoupper( (string) ( $data['currency'] ?? '' ) );
            if ( $currency !== 'NGN' ) {
                error_log( '[OFP_Bachs] Event ' . $event_id . ' for ' . $reference . ' was in ' . $currency . ', not NGN. Not applied. Check it in the Bachs dashboard.' );
                return new WP_REST_Response( [ 'status' => 'ignored_currency' ], 200 );
            }
            $amount_paid = is_numeric( $data['amount'] ?? null ) ? (float) $data['amount'] : 0.0;
        } else {
            // collection.underpaid: only act when Bachs states the amount received.
            $received = $data['amount_received'] ?? ( $data['amount_paid'] ?? null );
            if ( ! is_numeric( $received ) ) {
                error_log( '[OFP_Bachs] Underpaid event ' . $event_id . ' for ' . $reference . ' has no received amount. Review it in the Bachs dashboard.' );
                return new WP_REST_Response( [ 'status' => 'underpaid_logged' ], 200 );
            }
            $amount_paid = (float) $received;
        }

        $provider_ref = (string) ( $data['charge_id'] ?? ( $data['checkout_id'] ?? '' ) );

        try {
            if ( $is_credit ) {
                $done   = OFP_Payment::confirm_credit_topup( $reference, $amount_paid, $provider_ref );
                $status = 'credit_topup_processed';
            } else {
                $done   = OFP_Payment::confirm_subscription_checkout( $reference, $amount_paid, $provider_ref );
                $status = 'subscription_checkout_processed';
            }
        } catch ( \Throwable $e ) {
            error_log( '[OFP_Bachs] Processing failed for ' . $reference . ': ' . $e->getMessage() );
            // A 5xx makes Bachs retry later.
            return new WP_REST_Response( [ 'error' => 'Processing failed.' ], 500 );
        }

        if ( ! $done ) {
            error_log( '[OFP_Bachs] Event ' . $event_id . ' for ' . $reference . ' was not applied.' );
            return new WP_REST_Response( [ 'status' => 'not_applied' ], 200 );
        }

        set_transient( $seen_key, 1, 7 * DAY_IN_SECONDS );
        return new WP_REST_Response( [ 'status' => $status ], 200 );
    }

    /**
     * Checks the Bachs signature.
     * Prefers X-Bachs-Signature-V2 ("t=...,v1=...,v1=...") and falls back to the
     * older timestamp and signature headers.
     */
    private function signature_is_valid( string $raw, string $v2, string $ts_header, string $sig_header ): bool {
        $timestamp  = 0;
        $signatures = [];

        if ( $v2 !== '' ) {
            foreach ( explode( ',', $v2 ) as $part ) {
                $kv = explode( '=', trim( $part ), 2 );
                if ( count( $kv ) !== 2 ) {
                    continue;
                }
                if ( $kv[0] === 't' ) {
                    $timestamp = (int) $kv[1];
                } elseif ( $kv[0] === 'v1' ) {
                    $signatures[] = strtolower( $kv[1] );
                }
            }
        } else {
            $timestamp = (int) $ts_header;
            if ( $sig_header !== '' ) {
                $signatures[] = strtolower( $sig_header );
            }
        }

        if ( $timestamp <= 0 || ! $signatures ) {
            return false;
        }
        if ( abs( time() - $timestamp ) > self::SIGNATURE_TOLERANCE ) {
            return false; // too old, possible replay
        }

        $expected = hash_hmac( 'sha256', $timestamp . '.' . $raw, $this->webhook_secret );
        foreach ( $signatures as $signature ) {
            if ( hash_equals( $expected, $signature ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Finds our reference for a webhook payload.
     * Order: reference on the payload, our metadata, the saved map, then one
     * look at the checkout session at Bachs.
     */
    private function resolve_reference( array $data ): string {
        $direct = (string) ( $data['reference'] ?? '' );
        if ( $direct !== '' && $this->looks_like_ours( $direct ) ) {
            return $direct;
        }

        $meta = $data['metadata'] ?? null;
        if ( is_array( $meta ) && ! empty( $meta['ofp_reference'] ) && $this->looks_like_ours( (string) $meta['ofp_reference'] ) ) {
            return (string) $meta['ofp_reference'];
        }

        $checkout_id = (string) ( $data['checkout_id'] ?? '' );
        if ( $checkout_id === '' ) {
            return '';
        }

        $mapped = $this->reference_for_checkout( $checkout_id );
        if ( $mapped !== '' ) {
            return $mapped;
        }

        // Last try. The webhook must answer within 10 seconds, so keep this short.
        $session = $this->get_session( $checkout_id, 6 );
        if ( $session ) {
            $ref = (string) ( $session['reference'] ?? '' );
            if ( $ref === '' && isset( $session['metadata']['ofp_reference'] ) ) {
                $ref = (string) $session['metadata']['ofp_reference'];
            }
            if ( $ref !== '' && $this->looks_like_ours( $ref ) ) {
                return $ref;
            }
        }
        return '';
    }

    private function looks_like_ours( string $reference ): bool {
        return OFP_Payment::is_credit_topup_reference( $reference )
            || OFP_Payment::is_subscription_checkout_reference( $reference );
    }

    // ─────────────────────────────────────────────────────────────────────
    // REFERENCE AND CHECKOUT ID MAP
    // ─────────────────────────────────────────────────────────────────────

    private function remember_checkout( string $reference, string $checkout_id ): void {
        $map = get_option( self::MAP_OPTION, [] );
        if ( ! is_array( $map ) ) {
            $map = [];
        }
        $now = time();
        foreach ( $map as $ref => $row ) {
            if ( ! is_array( $row ) || ( $now - (int) ( $row['t'] ?? 0 ) ) > 14 * DAY_IN_SECONDS ) {
                unset( $map[ $ref ] );
            }
        }
        $map[ $reference ] = [ 'id' => $checkout_id, 't' => $now ];
        if ( count( $map ) > 500 ) {
            uasort( $map, fn( $a, $b ) => (int) $a['t'] <=> (int) $b['t'] );
            $map = array_slice( $map, -500, null, true );
        }
        update_option( self::MAP_OPTION, $map, false );
    }

    public function checkout_id_for_reference( string $reference ): string {
        $map = get_option( self::MAP_OPTION, [] );
        return ( is_array( $map ) && isset( $map[ $reference ]['id'] ) ) ? (string) $map[ $reference ]['id'] : '';
    }

    /** Our reference for a Bachs checkout id, or an empty string. */
    public function reference_for_checkout( string $checkout_id ): string {
        if ( ! preg_match( '/^chk_[A-Za-z0-9]+$/', $checkout_id ) ) {
            return '';
        }
        $map = get_option( self::MAP_OPTION, [] );
        if ( ! is_array( $map ) ) {
            return '';
        }
        foreach ( $map as $reference => $row ) {
            if ( is_array( $row ) && ( $row['id'] ?? '' ) === $checkout_id ) {
                return (string) $reference;
            }
        }
        return '';
    }
}
