<?php
/**
 * OFP_Voice
 *
 * Starts outbound voice calls through Africa's Talking and answers the call
 * webhook. Every call is a one way notice: the saved text is read out and the
 * call ends. There are no key presses and no menu.
 *
 * HOW IT WORKS:
 *  1. make_call() asks Africa's Talking to call the person.
 *  2. When the person answers, Africa's Talking opens our webhook with the
 *     notice token.
 *  3. handle_callback() reads out the text that belongs to that token.
 *
 * Depends on: wp_remote_post(), OFP_Security, OFP_Voice_Notice.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Voice {

    /**
     * Initiate an outbound voice call to a lead.
     *
     * @param  string $phone      Lead phone number to call.
     * @param  string $message    The text that is read out (also saved in the communications log).
     * @param  int    $client_id  Client ID (for the communications log).
     * @param  int    $lead_id    Lead ID (for the communications log).
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

        // The callback carries only the notice token. The webhook uses it to read out the saved text.
        $callback_args = [];
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
     * XML that makes Africa's Talking read a message and hang up.
     */
    public static function say_and_hangup( string $message ): string {
        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<Response>' .
                '<Say>' . esc_html( $message ) . '</Say>' .
            '</Response>';
    }

    /**
     * Webhook Africa's Talking opens when a call is answered. It reads out the
     * saved notice text and ends the call. Nothing else can happen here: no
     * key presses, no SMS, no changes to any lead.
     */
    public static function handle_callback( WP_REST_Request $request ): WP_REST_Response {
        header( 'Content-Type: text/xml; charset=utf-8' );

        // Africa's Talking also calls back when the call ends. Nothing to say then.
        if ( '0' === (string) ( $request->get_param( 'isActive' ) ?? '' ) ) {
            echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
            exit;
        }

        $token  = sanitize_text_field( (string) ( $request->get_param( 'notice' ) ?? '' ) );
        $notice = ( $token !== '' && class_exists( 'OFP_Voice_Notice' ) ) ? OFP_Voice_Notice::get_notice( $token ) : null;

        echo self::say_and_hangup( $notice ? $notice['text'] : 'Hello. This is an automated message. Thank you. Goodbye.' );
        exit;
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
