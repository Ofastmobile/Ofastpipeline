<?php
/**
 * OFP_SMS
 *
 * Handles SMS sending via Africa's Talking (primary) or BulkSMS Nigeria (fallback).
 *
 * RESELLER MODEL (confirmed architectural decision):
 *  OFast Pipeline holds the master account on Africa's Talking and BulkSMS Nigeria.
 *  Individual clients do NOT have their own platform credentials.
 *  ALL SMS sending goes through OFP's global API key configured in Settings.
 *  The per-client sms_api_key_encrypted column in wp_ofp_clients is intentionally
 *  left unused — it exists as a future escape hatch if the model ever changes,
 *  but must never be used in the current reseller arrangement.
 *
 *  This is a deliberate, permanent decision. Do not reintroduce per-client
 *  key lookup without reconsidering the entire billing and API quota model.
 *
 * PROVIDER SELECTION:
 *  Each client has an sms_provider field ('africastalking' or 'bulksms').
 *  This determines which platform's API we route through — but always using
 *  OFP's own master credentials for that platform, never the client's.
 *
 * ADDING NEW PROVIDERS:
 *  Add a new private method send_via_<provider>() and add a case in send().
 *  Provider name in ofp_clients.sms_provider drives the routing.
 *
 * Uses wp_remote_post() exclusively — no Guzzle, no Composer dependency.
 *
 * Depends on: OFP_Security (decryption for global keys), wp_remote_post().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_SMS {

    private string $provider;
    private string $api_key;
    private string $sender_id = '';
    private int $client_id = 0;

    /**
     * @param string $provider   Provider slug: 'africastalking', 'bulksms', or 'smartsms'.
     * @param int    $client_id  Client ID — used to read preferred provider and sms_sender_id.
     */
    public function __construct( string $provider, int $client_id ) {
        $this->provider  = $provider;
        $this->client_id = $client_id;
        $this->api_key   = $this->get_global_api_key( $provider );

        if ( $client_id > 0 ) {
            global $wpdb;
            $sid = $wpdb->get_var( $wpdb->prepare(
                "SELECT sms_sender_id FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
                $client_id
            ) );
            if ( is_string( $sid ) && $sid !== '' ) {
                $this->sender_id = substr( preg_replace( '/[^A-Za-z0-9]/', '', $sid ), 0, 11 );
            }
        }
    }

    /**
     * Send an SMS to a single phone number.
     *
     * @param  string      $phone      Recipient phone number.
     * @param  string      $message    Message body.
     * @param  string|null $sender_id  Optional override sender ID.
     */
    public function send( string $phone, string $message, ?string $sender_id = null ): array {

        $phone = $this->normalise_phone( $phone );
        if ( is_string( $sender_id ) && $sender_id !== '' ) {
            $this->sender_id = substr( preg_replace( '/[^A-Za-z0-9]/', '', $sender_id ), 0, 11 );
        }

        return match ( $this->provider ) {
            'africastalking' => $this->send_via_at( $phone, $message ),
            'bulksms'        => $this->send_via_bsmsn( $phone, $message ),
            'smartsms'       => $this->send_via_smartsms( $phone, $message ),
            default          => [
                'success'      => false,
                'provider_ref' => '',
                'error'        => "Unknown SMS provider: {$this->provider}",
            ],
        };
    }

    private function resolved_sender( string $fallback_option, string $default = 'OFastPipe' ): string {
        if ( $this->sender_id !== '' ) {
            return $this->sender_id;
        }
        $opt = get_option( $fallback_option, $default );
        return $opt !== '' ? (string) $opt : $default;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROVIDERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send via Africa's Talking using OFP's global master account.
     * Docs: https://developers.africastalking.com/docs/sms/sending
     */
    private function send_via_at( string $phone, string $message ): array {

        $response = wp_remote_post(
            'https://api.africastalking.com/version1/messaging',
            [
                'headers' => [
                    'apiKey' => $this->api_key,
                    'Accept' => 'application/json',
                ],
                'body'    => [
                    'username' => get_option( 'ofp_at_username', '' ),
                    'to'       => $phone,
                    'message'  => $message,
                    'from'     => $this->resolved_sender( 'ofp_at_sender_id' ),
                ],
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'success'      => false,
                'provider_ref' => '',
                'error'        => $response->get_error_message(),
            ];
        }

        $body      = json_decode( wp_remote_retrieve_body( $response ) );
        $recipient = $body->SMSMessageData->Recipients[0] ?? null;

        return [
            'success'      => $recipient && $recipient->status === 'Success',
            'provider_ref' => $recipient->messageId ?? '',
            'error'        => $recipient
                ? ( $recipient->status !== 'Success' ? $recipient->status : '' )
                : 'No recipient data',
        ];
    }

    /**
     * Send via BulkSMS Nigeria using OFP's global master account.
     * Docs: https://www.bulksmsnigeria.com/bulk-sms-api/v2
     */
    private function send_via_bsmsn( string $phone, string $message ): array {

        $response = wp_remote_post(
            'https://www.bulksmsnigeria.com/api/v2/sms/create',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body'    => wp_json_encode( [
                    'to'   => $phone,
                    'from' => $this->resolved_sender( 'ofp_bsmsn_sender_id' ),
                    'body' => $message,
                ] ),
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'success'      => false,
                'provider_ref' => '',
                'error'        => $response->get_error_message(),
            ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        return [
            'success'      => isset( $body->data->id ),
            'provider_ref' => $body->data->id ?? '',
            'error'        => isset( $body->data->id )
                ? ''
                : ( $body->message ?? 'Unknown error' ),
        ];
    }

    /**
     * Send via SmartSMSSolutions using OFP's global master account.
     * Docs: https://smartsmssolutions.com/api-doc
     */
    private function send_via_smartsms( string $phone, string $message ): array {
        $routing   = get_option( 'ofp_smartsms_routing', '3' );
        $sender_id = $this->resolved_sender( 'ofp_smartsms_sender_id' );

        $params = [
            'token'   => $this->api_key,
            'sender'  => $sender_id,
            'to'      => $phone,
            'message' => $message,
            'routing' => $routing,
            'type'    => '0', // Plain text
        ];

        $url = 'https://smartsmssolutions.com/api/json.php?' . http_build_query( $params );

        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success'      => false,
                'provider_ref' => '',
                'error'        => $response->get_error_message(),
            ];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // JSON response parsing
        if ( is_array( $data ) ) {
            $code = $data['code'] ?? $data['comment'] ?? '';
            if ( $code === '1000' || ( isset( $data['successful'] ) && $data['successful'] !== '' ) ) {
                return [
                    'success'      => true,
                    'provider_ref' => $data['message_id'] ?? '',
                    'error'        => '',
                ];
            }
            return [
                'success'      => false,
                'provider_ref' => '',
                'error'        => $data['comment'] ?? $data['message'] ?? 'Unknown SmartSMS error',
            ];
        }

        // Fallback: raw response parsing
        if ( strpos( $body, '1000' ) !== false || stripos( $body, 'success' ) !== false ) {
            return [
                'success'      => true,
                'provider_ref' => '',
                'error'        => '',
            ];
        }

        return [
            'success'      => false,
            'provider_ref' => '',
            'error'        => 'SmartSMS error: ' . substr( $body, 0, 200 ),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retrieve OFP's global decrypted API key for the given provider.
     *
     * RESELLER MODEL: this is the ONLY method that should ever be called
     * to get an SMS API key. Per-client keys are explicitly not used.
     *
     * @param  string $provider  'africastalking' or 'bulksms'.
     * @return string            Decrypted API key, or empty string if not configured.
     */
    private function get_global_api_key( string $provider ): string {
        return match ( $provider ) {
            'africastalking' => OFP_Security::decrypt( get_option( 'ofp_at_api_key',    '' ) ),
            'bulksms'        => OFP_Security::decrypt( get_option( 'ofp_bsmsn_api_key', '' ) ),
            'smartsms'       => OFP_Security::decrypt( get_option( 'ofp_smartsms_api_key', '' ) ),
            default          => '',
        };
    }

    /**
     * Normalise a Nigerian phone number to international format.
     * 08012345678    → +2348012345678
     * 2348012345678  → +2348012345678
     * +2348012345678 → +2348012345678 (unchanged)
     *
     * @param  string $phone
     * @return string
     */
    private function normalise_phone( string $phone ): string {
        $phone = preg_replace( '/[^0-9+]/', '', $phone );

        if ( str_starts_with( $phone, '0' ) ) {
            $phone = '+234' . substr( $phone, 1 );
        } elseif ( str_starts_with( $phone, '234' ) && ! str_starts_with( $phone, '+' ) ) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    /**
     * Static helper for sending a manual/broadcast SMS without a client context.
     * Uses the default provider (smartsms) with global credentials.
     *
     * @param  int|null $client_id  Optional client ID (null for super-admin broadcasts).
     * @param  string   $phone      Recipient phone.
     * @param  string   $message    Message body.
     * @return bool True on success.
     */
    public static function send_manual( ?int $client_id, string $phone, string $message ): bool {
        // Determine provider: use client's preference or fallback to smartsms
        $provider = 'smartsms';
        if ( $client_id ) {
            global $wpdb;
            $provider = $wpdb->get_var( $wpdb->prepare(
                "SELECT sms_provider FROM {$wpdb->prefix}ofp_clients WHERE id = %d",
                $client_id
            ) ) ?: 'smartsms';
        }

        $sms    = new self( $provider, $client_id ?? 0 );
        $result = $sms->send( $phone, $message );

        return $result['success'] ?? false;
    }

    /**
     * Platform OTP / system SMS (no client sender ID).
     */
    public static function send_system_sms( string $phone, string $message ): bool {
        return self::send_manual( null, $phone, $message );
    }
}
