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
 *  ONE provider is active for the whole platform. Admin chooses it in
 *  Settings, SMS tab (option ofp_sms_provider). Clients do not choose.
 *  Use OFP_SMS::for_client( $client_id ) everywhere. Voice calls are separate
 *  and always use Africa's Talking.
 *
 * ADDING NEW PROVIDERS:
 *  Add an entry in OFP_SMS::providers() (label, key_option, send) and the
 *  send_via_<name>() method. Or from another file, use the ofp_sms_providers
 *  filter and give 'send' a callable( $phone, $message ): array.
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
    private int $client_id;

    /**
     * @param string $provider   Provider slug: 'africastalking' or 'bulksms'.
     * @param int    $client_id  Client ID — used only to read their preferred
     *                           provider, NOT to retrieve per-client API keys.
     *                           Under the reseller model, all API calls use the
     *                           global key from OFP Settings.
     */
    public function __construct( string $provider, int $client_id ) {
        $this->provider  = $provider;
        $this->client_id = $client_id;
        $this->api_key   = $this->get_global_api_key( $provider );
    }

    /**
     * Every SMS company the platform can use.
     * label       Shown in Admin Settings.
     * key_option  Option that holds the encrypted API key.
     * send        Name of a send_via_ method, or a callable( $phone, $message ): array
     *             returning success, provider_ref, error.
     *
     * @return array<string,array{label:string,key_option:string,send:mixed}>
     */
    public static function providers(): array {
        $list = [
            'africastalking' => [ 'label' => "Africa's Talking",   'key_option' => 'ofp_at_api_key',       'send' => 'send_via_at' ],
            'bulksms'        => [ 'label' => 'BulkSMS Nigeria',    'key_option' => 'ofp_bsmsn_api_key',    'send' => 'send_via_bsmsn' ],
            'smartsms'       => [ 'label' => 'SmartSMSSolutions',  'key_option' => 'ofp_smartsms_api_key', 'send' => 'send_via_smartsms' ],
        ];
        $list = apply_filters( 'ofp_sms_providers', $list );
        return is_array( $list ) ? $list : [];
    }

    /** The one provider admin switched on for the whole platform. */
    public static function platform_provider(): string {
        $chosen = (string) get_option( 'ofp_sms_provider', 'africastalking' );
        return isset( self::providers()[ $chosen ] ) ? $chosen : 'africastalking';
    }

    /** The way to get an SMS sender. Clients no longer pick a provider. */
    public static function for_client( int $client_id ): self {
        return new self( self::platform_provider(), $client_id );
    }

    /** True when the platform provider has an API key saved, so a send can work. */
    public static function ready(): bool {
        return ( new self( self::platform_provider(), 0 ) )->api_key !== '';
    }

    /**
     * How many SMS segments a message uses. One segment holds 160 characters.
     * A longer message is split into pieces of 153 characters.
     * This is an estimate for plain text messages.
     */
    public static function segments( string $message ): int {
        $length = mb_strlen( $message );
        if ( $length <= 160 ) {
            return 1;
        }
        return (int) ceil( $length / 153 );
    }

    /**
     * Send an SMS to a single phone number.
     *
     * @param  string $phone    Recipient phone number.
     * @param  string $message  Message body (max 160 chars for single SMS).
     * @return array {
     *     @type bool   $success      True if provider accepted the message.
     *     @type string $provider_ref Provider's message ID for tracking.
     *     @type string $error        Error message if success = false.
     * }
     */
    public function send( string $phone, string $message, ?int $lead_id = null ): array {

        $phone    = $this->normalise_phone( $phone );
        $entry    = self::providers()[ $this->provider ] ?? null;
        $sender   = $entry['send'] ?? null;
        if ( is_string( $sender ) && method_exists( $this, $sender ) ) {
            $result = $this->{$sender}( $phone, $message );
        } elseif ( is_callable( $sender ) ) {
            $result = (array) call_user_func( $sender, $phone, $message );
        } else {
            $result = [
                'success'      => false,
                'provider_ref' => '',
                'error'        => "Unknown SMS provider: {$this->provider}",
            ];
        }

        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( ! empty( $result['success'] ) ? 'SMS sent' : 'SMS failed', $this->client_id ?: null, [
                'to'       => $phone,
                'message'  => mb_substr( $message, 0, 160 ),
                'provider' => $this->provider,
                'error'    => $result['error'] ?? null,
                'reply'    => $result['raw'] ?? null,
            ] );
        }

        if ( class_exists( 'OFP_Communications_Log' ) && ! empty( $this->client_id ) ) {
            OFP_Communications_Log::log(
                (int) $this->client_id,
                $lead_id ?: null,
                'sms',
                $phone,
                'outbound',
                $message,
                ! empty( $result['success'] ) ? ( ( class_exists( 'OFP_Queue' ) ? OFP_Queue::SMS_COST : 6.99 ) * self::segments( $message ) ) : 0.00,
                ! empty( $result['success'] ) ? 'sent' : 'failed',
                $this->provider,
                $result['provider_ref'] ?? null
            );
        }

        return $result;
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
                    'from'     => get_option( 'ofp_at_sender_id', 'OFastPipe' ),
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
                    'from' => get_option( 'ofp_bsmsn_sender_id', 'OFastPipe' ),
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
        $routing   = get_option( 'ofp_smartsms_routing', '3' ); // 3 = Corporate DND bypass
        $sender_id = get_option( 'ofp_smartsms_sender_id', 'OFastPipe' );

        // SmartSMS wants digits only in international form: 2348012345678 (no plus).
        $params = [
            'token'   => $this->api_key,
            'sender'  => $sender_id,
            'to'      => ltrim( $phone, '+' ),
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
                    'raw'          => substr( $body, 0, 300 ),
                ];
            }
            return [
                'success'      => false,
                'provider_ref' => '',
                'error'        => $data['comment'] ?? $data['message'] ?? 'Unknown SmartSMS error',
                'raw'          => substr( $body, 0, 300 ),
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
        $entry = self::providers()[ $provider ] ?? null;
        if ( ! $entry || empty( $entry['key_option'] ) ) {
            return '';
        }
        return (string) OFP_Security::decrypt( get_option( $entry['key_option'], '' ) );
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
        // One platform provider for everyone, chosen by admin.
        $sms    = self::for_client( (int) ( $client_id ?? 0 ) );
        $result = $sms->send( $phone, $message );

        return $result['success'] ?? false;
    }

    /**
     * Static helper for sending system-level SMS (like OTPs) without a client context.
     *
     * @param  string   $phone      Recipient phone.
     * @param  string   $message    Message body.
     * @return bool True on success.
     */
    public static function send_system_sms( string $phone, string $message ): bool {
        return self::send_manual( null, $phone, $message );
    }
}
