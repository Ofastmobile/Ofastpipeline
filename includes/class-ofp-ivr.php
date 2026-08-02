<?php
/**
 * OFP_IVR
 *
 * Builds Africa's Talking IVR XML responses and handles digit callbacks.
 *
 * FLOW:
 *  1. OFP_Voice::make_call() initiates the call with a callback URL.
 *  2. When the lead answers, AT fetches our callback URL.
 *  3. handle_callback() is called with no digit yet → returns build_menu() XML.
 *  4. Lead presses a digit → AT POSTs back to the same URL with dtmfDigits.
 *  5. handle_callback() processes the digit and returns the appropriate XML.
 *
 * DIGIT ACTIONS (configured per client in pipeline_configs):
 *  1 → transfer    : Live call transfer to business phone
 *  2 → sms         : Send WhatsApp link via SMS, end call
 *  3 → schedule    : Schedule a callback in 2 hours, end call
 *
 * Depends on: OFP_Lead, OFP_Credit, OFP_SMS, ofp_pipeline_configs,
 *             ofp_ivr_responses tables.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_IVR {

    // ─────────────────────────────────────────────────────────────────────────
    // XML BUILDERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the IVR menu XML.
     * Africa's Talking reads the <Say> text (or plays the audio file, if the
     * client has uploaded one) to the lead and waits for a digit.
     *
     * @param  string      $message    The script read to the lead (TTS fallback).
     * @param  string|null $audio_url  Optional custom audio URL — used instead
     *                                 of TTS when present (Phase 22).
     * @return string                  Valid AT Voice XML.
     */
    public static function build_menu( string $message, ?string $audio_url = null ): string {
        $callback = home_url( '/wp-json/ofp/v1/webhook/voice-ivr' );

        $prompt = $audio_url
            ? '<Play url="' . esc_url( $audio_url ) . '"/>'
            : '<Say>' . esc_html( $message ) . '</Say>';

        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<Response>' .
                '<GetDigits timeout="30" finishOnKey="#" callbackUrl="' . esc_url( $callback ) . '">' .
                    $prompt .
                '</GetDigits>' .
                '<Say>We did not receive your response. We will try again soon. Goodbye.</Say>' .
            '</Response>';
    }

    /**
     * Build a live call transfer XML.
     *
     * @param  string $phone  Phone number to transfer to.
     * @return string
     */
    public static function transfer( string $phone ): string {
        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<Response>' .
                '<Dial phoneNumbers="' . esc_attr( $phone ) . '"/>' .
            '</Response>';
    }

    /**
     * Build a say-and-hangup XML.
     *
     * @param  string $message  Message to read before hanging up.
     * @return string
     */
    public static function say_and_hangup( string $message ): string {
        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<Response>' .
                '<Say>' . esc_html( $message ) . '</Say>' .
            '</Response>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CALLBACK HANDLER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Handle the Africa's Talking IVR callback.
     *
     * Called by OFP_REST_API::voice_ivr_webhook() on every AT callback.
     * Returns XML directly — Africa's Talking reads the XML, not JSON.
     *
     * @param  WP_REST_Request $request
     * @return WP_REST_Response  Empty response — output is via header()/echo/exit.
     */
    public static function handle_callback( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $p = $wpdb->prefix;

        // AT sends these as POST or GET params.
        $client_id  = (int) ( $request->get_param( 'client_id' )  ?? 0 );
        $lead_id    = (int) ( $request->get_param( 'lead_id' )    ?? 0 );
        $session_id = sanitize_text_field( $request->get_param( 'sessionId' ) ?? '' );
        $digit      = sanitize_text_field( $request->get_param( 'dtmfDigits' ) ?? '' );

        // Must output XML directly for AT to parse.
        header( 'Content-Type: text/xml; charset=utf-8' );

        if ( ! $client_id || ! $lead_id ) {
            echo self::say_and_hangup( 'Sorry, there was a system error. Please call us directly. Goodbye.' );
            exit;
        }

        $config = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}ofp_pipeline_configs WHERE client_id = %d LIMIT 1",
                $client_id
            )
        );

        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}ofp_clients WHERE id = %d LIMIT 1",
                $client_id
            )
        );

        $lead = OFP_Lead::get( $lead_id );

        if ( ! $config || ! $client || ! $lead ) {
            echo self::say_and_hangup( 'Sorry, we could not locate your record. Please call us directly. Goodbye.' );
            exit;
        }

        // ── No digit yet: serve the IVR menu ─────────────────────────────────
        if ( empty( $digit ) ) {
            $menu_message = $config->followup_2_message
                ?: 'Hello, thank you for your interest. Press 1 to speak with us now. Press 2 to receive our WhatsApp contact via SMS. Press 3 to request a callback later. Press hash when done.';

            // Personalise the message.
            $menu_message = str_replace(
                [ '{{name}}', '{{business_name}}' ],
                [ $lead->name ?: 'there', $client->business_name ],
                $menu_message
            );

            echo self::build_menu( $menu_message, $config->voice_audio_url ?? null );
            exit;
        }

        // ── Digit received: log it and act ────────────────────────────────────
        $wpdb->insert(
            $p . 'ofp_ivr_responses',
            [
                'client_id'       => $client_id,
                'lead_id'         => $lead_id,
                'call_session_id' => $session_id,
                'digit_pressed'   => $digit,
                'action_taken'    => '',
                'responded_at'    => current_time( 'mysql' ),
            ]
        );

        OFP_Lead::record_ivr_response( $lead_id, $digit );

        // Determine the configured action for this digit.
        $action_map = [
            '1' => $config->ivr_option_1_action ?? 'transfer',
            '2' => $config->ivr_option_2_action ?? 'sms',
            '3' => $config->ivr_option_3_action ?? 'schedule',
        ];

        $action = $action_map[ $digit ] ?? 'unknown';

        // Update the action_taken in the IVR log.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$p}ofp_ivr_responses
                 SET action_taken = %s
                 WHERE lead_id = %d AND call_session_id = %s
                 ORDER BY responded_at DESC LIMIT 1",
                $action, $lead_id, $session_id
            )
        );

        switch ( $action ) {

            // ── 1: Live transfer ──────────────────────────────────────────────
            case 'transfer':
                $transfer_to = $config->transfer_phone ?: $client->business_phone;
                if ( empty( $transfer_to ) ) {
                    echo self::say_and_hangup(
                        'We are unable to connect you right now. We will call you back soon. Goodbye.'
                    );
                } else {
                    echo self::transfer( $transfer_to );
                }
                break;

            // ── 2: Send WhatsApp link via SMS ─────────────────────────────────
            case 'sms':
                $wa_number = preg_replace( '/[^0-9]/', '', $config->whatsapp_link ?: $client->whatsapp_number );
                $wa_link   = 'https://wa.me/' . $wa_number;
                $sms_msg   = 'Chat with ' . $client->business_name . ' on WhatsApp: ' . $wa_link;

                if ( OFP_Credit::has_balance( $client_id, 'sms', OFP_Queue::SMS_COST ) ) {
                    $sms = new OFP_SMS( $client->sms_provider, $client_id );
                    $sms->send( $lead->phone, $sms_msg );
                    OFP_Credit::deduct( $client_id, 'sms', OFP_Queue::SMS_COST );
                }

                echo self::say_and_hangup(
                    'Perfect! We just sent you our WhatsApp contact via SMS. We look forward to hearing from you. Goodbye!'
                );
                break;

            // ── 3: Schedule callback ──────────────────────────────────────────
            case 'schedule':
                // Queue a new voice trigger 2 hours from now.
                $wpdb->insert(
                    $p . 'ofp_trigger_queue',
                    [
                        'client_id'    => $client_id,
                        'lead_id'      => $lead_id,
                        'type'         => 'voice',
                        'message'      => $config->followup_2_message,
                        'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 7200 ),
                        'status'       => 'pending',
                        'created_at'   => current_time( 'mysql' ),
                    ]
                );

                echo self::say_and_hangup(
                    'Perfect! We will call you back in approximately 2 hours. Have a great day. Goodbye!'
                );
                break;

            // ── Unknown digit ─────────────────────────────────────────────────
            default:
                echo self::say_and_hangup(
                    'Sorry, we did not recognise that option. Please call us directly. Goodbye.'
                );
                break;
        }

        exit;
    }
}
