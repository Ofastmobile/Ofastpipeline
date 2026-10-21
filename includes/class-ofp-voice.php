<?php
/**
 * OFP_Voice
 *
 * Initiates outbound voice calls via Africa's Talking Voice API.
 *
 * HOW IT WORKS:
 *  1. make_call() sends a POST to Africa's Talking to initiate the call.
 *  2. Africa's Talking calls the lead's phone number.
 *  3. When the lead answers, AT fetches our IVR XML from the voice-ivr webhook.
 *  4. OFP_IVR::handle_callback() serves the XML and handles digit responses.
 *
 * The callback URL is built dynamically using home_url() so it works on any domain.
 *
 * Depends on: wp_remote_post(), OFP_Security (for API key decryption).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Voice {

    /**
     * Initiate an outbound voice call to a lead.
     *
     * @param  string $phone      Lead phone number to call.
     * @param  string $message    IVR script/message (passed to OFP_IVR via callback).
     * @param  int    $client_id  Client ID (passed to callback URL for context).
     * @param  int    $lead_id    Lead ID (passed to callback URL for context).
     * @return array {
     *     @type bool   $success     True if call was queued successfully.
     *     @type string $session_id  Africa's Talking session ID for tracking.
     *     @type string $error       Error message if success = false.
     * }
     */
    public function make_call(
        string $phone,
        string $message,
        int $client_id,
        int $lead_id,
        string $notice_token = '',
        float $cost = 0.0
    ): array {

        $phone = $this->normalise_phone( $phone );

        // Build the IVR callback URL — AT will POST digit responses here.
        $callback_args = [
            'client_id' => $client_id,
            'lead_id'   => $lead_id,
        ];
        // One way notice calls carry a token. The webhook uses it to read out the saved text.
        if ( $notice_token !== '' ) {
            $callback_args['notice'] = $notice_token;
        }
        $callback_url = add_query_arg(
            $callback_args,
            home_url( '/wp-json/ofp/v1/webhook/voice-ivr' )
        );

        $api_key  = OFP_Security::decrypt( get_option( 'ofp_at_api_key', '' ) );
        $username = get_option( 'ofp_at_username', '' );
        $from     = get_option( 'ofp_at_phone_number', '' );

        if ( empty( $api_key ) || empty( $username ) ) {
            if ( class_exists( 'OFP_Communications_Log' ) && ! empty( $client_id ) ) {
                OFP_Communications_Log::log( (int) $client_id, $lead_id ?: null, 'voice', $phone, 'outbound', $message, 0.00, 'failed', 'africastalking', null );
            }
            return [
                'success'    => false,
                'session_id' => '',
                'error'      => 'Africa\'s Talking credentials not configured.',
            ];
        }

        $response = wp_remote_post(
            'https://voice.africastalking.com/call',
            [
                'headers' => [
                    'apiKey'       => $api_key,
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body'    => [
                    'username'    => $username,
                    'to'          => $phone,
                    'from'        => $from,
                    'callbackUrl' => $callback_url,
                ],
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $err = $response->get_error_message();
            if ( class_exists( 'OFP_Communications_Log' ) && ! empty( $client_id ) ) {
                OFP_Communications_Log::log(
                    (int) $client_id,
                    $lead_id ?: null,
                    'voice',
                    $phone,
                    'outbound',
                    $message,
                    0.00,
                    'failed',
                    'africastalking',
                    null
                );
            }
            return [
                'success'    => false,
                'session_id' => '',
                'error'      => $err,
            ];
        }

        $body  = json_decode( wp_remote_retrieve_body( $response ) );
        $entry = $body->entries[0] ?? null;

        $is_success = $entry && $entry->status === 'Queued';
        $provider_ref = $entry->sessionId ?? '';

        if ( class_exists( 'OFP_Communications_Log' ) && ! empty( $client_id ) ) {
            OFP_Communications_Log::log(
                (int) $client_id,
                $lead_id ?: null,
                'voice',
                $phone,
                'outbound',
                $message,
                $is_success ? $cost : 0.00,
                $is_success ? 'queued' : 'failed',
                'africastalking',
                $provider_ref
            );
        }

        return [
            'success'      => $is_success,
            'session_id'   => $provider_ref,
            'provider_ref' => $provider_ref,
            'error'        => $entry && $entry->status !== 'Queued' ? $entry->status : '',
        ];
    }

    /**
     * Normalise a Nigerian phone number to international format.
     * 08012345678 → +2348012345678
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
}
