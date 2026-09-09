<?php
/**
 * OFP_Queue
 *
 * Manages the trigger queue — the engine that powers all automated
 * SMS, voice, and email follow-ups for every client.
 *
 * HOW IT WORKS:
 *  1. populate_triggers() is called immediately when a lead is captured.
 *     It reads the client's pipeline_config and schedules every trigger
 *     (instant SMS, follow-up 1, follow-up 2, follow-up 3) as rows in
 *     ofp_trigger_queue with their scheduled_at timestamps.
 *
 *  2. process_due() is called by WP-Cron every 5 minutes via OFP_Cron_Handler.
 *     It fetches up to 10 pending triggers whose scheduled_at <= NOW(),
 *     dispatches each one to OFP_SMS, OFP_Voice, or OFP_Mailer,
 *     deducts credit, logs the communication, and updates the row status.
 *
 * SAFETY CHECKS before any trigger fires:
 *  - Client status must be 'active'
 *  - Credit balance must not be paused (cr.paused = 0)
 *  - Sufficient credit for the channel (OFP_Credit::has_balance())
 *
 * MESSAGE PERSONALISATION:
 *  Placeholders in message templates are replaced before sending:
 *  {{name}}          → lead's name
 *  {{business_name}} → client's business name
 *  {{phone}}         → lead's phone number
 *
 * Depends on: ofp_trigger_queue, ofp_pipeline_configs, OFP_Lead,
 *             OFP_SMS, OFP_Voice, OFP_Mailer, OFP_Credit.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Queue {

    // SMS cost in NGN per message (Africa's Talking / BulkSMS Nigeria rate).
    const SMS_COST   = 6.99;

    // Voice cost in NGN per minute.
    const VOICE_COST = 15.00;

    // How many triggers to process per cron run.
    // Keep low (10) to stay within PHP execution time limits on shared hosting.
    const BATCH_SIZE = 10;

    // ─────────────────────────────────────────────────────────────────────────
    // POPULATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Schedule all triggers for a newly captured lead.
     *
     * Reads the client's pipeline_config and inserts one row per enabled
     * trigger into ofp_trigger_queue. The instant SMS is scheduled for NOW
     * so it fires on the very next cron run (within 5 minutes).
     *
     * Called by OFP_REST_API::capture_lead() immediately after OFP_Lead::create().
     *
     * @param  int $client_id  Client ID.
     * @param  int $lead_id    Newly created lead ID.
     * @return void
     */
    public static function populate_triggers( int $client_id, int $lead_id ): void {
        global $wpdb;

        $config = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_pipeline_configs WHERE client_id = %d LIMIT 1",
                $client_id
            )
        );

        // Listing-only clients have no pipeline_config — nothing to queue.
        if ( ! $config ) {
            return;
        }

        $now      = current_time( 'timestamp' );
        $triggers = [];

        // ── Instant SMS ───────────────────────────────────────────────────────
        if ( $config->instant_sms_enabled && ! empty( $config->instant_sms_message ) ) {
            $triggers[] = [
                'type'    => 'sms',
                'message' => $config->instant_sms_message,
                'time'    => $now, // Fire immediately on next cron run.
            ];
        }

        // ── Follow-up 1 ───────────────────────────────────────────────────────
        if ( ! empty( $config->followup_1_message ) ) {
            $triggers[] = [
                'type'    => $config->followup_1_type,
                'message' => $config->followup_1_message,
                'time'    => $now + ( (int) $config->followup_1_delay_hours * HOUR_IN_SECONDS ),
            ];
        }

        // ── Follow-up 2 ───────────────────────────────────────────────────────
        if ( ! empty( $config->followup_2_message ) ) {
            $triggers[] = [
                'type'    => $config->followup_2_type,
                'message' => $config->followup_2_message,
                'time'    => $now + ( (int) $config->followup_2_delay_hours * HOUR_IN_SECONDS ),
            ];
        }

        // ── Follow-up 3 ───────────────────────────────────────────────────────
        if ( ! empty( $config->followup_3_message ) ) {
            $triggers[] = [
                'type'    => $config->followup_3_type,
                'message' => $config->followup_3_message,
                'time'    => $now + ( (int) $config->followup_3_delay_hours * HOUR_IN_SECONDS ),
            ];
        }

        // Insert all triggers in one loop.
        foreach ( $triggers as $trigger ) {
            $wpdb->insert(
                $wpdb->prefix . 'ofp_trigger_queue',
                [
                    'client_id'    => $client_id,
                    'lead_id'      => $lead_id,
                    'type'         => $trigger['type'],
                    'message'      => $trigger['message'],
                    'scheduled_at' => gmdate( 'Y-m-d H:i:s', $trigger['time'] ),
                    'status'       => 'pending',
                    'attempts'     => 0,
                    'created_at'   => current_time( 'mysql' ),
                ]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROCESS (called by WP-Cron every 5 minutes)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Process due triggers from the queue.
     *
     * Fetches BATCH_SIZE pending rows whose scheduled_at <= NOW() and whose
     * client is active with unpaused credits, then dispatches each one.
     *
     * @return void
     */
    public static function process_due(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $triggers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.*, c.sms_provider, c.business_name, c.business_phone,
                        c.whatsapp_number, c.status AS client_status
                 FROM {$p}ofp_trigger_queue q
                 JOIN {$p}ofp_clients c  ON c.id  = q.client_id
                 JOIN {$p}ofp_credits cr ON cr.client_id = q.client_id
                 WHERE q.status       = 'pending'
                   AND q.scheduled_at <= NOW()
                   AND c.status       = 'active'
                   AND cr.paused      = 0
                 ORDER BY q.scheduled_at ASC
                 LIMIT %d",
                self::BATCH_SIZE
            )
        );

        foreach ( $triggers as $trigger ) {
            self::dispatch( $trigger );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DISPATCH
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Dispatch a single trigger row.
     *
     * Marks the row as 'processing' first to prevent double-firing if cron
     * overlaps. Then routes to the correct handler based on type.
     *
     * @param  object $trigger  A row from ofp_trigger_queue with client data joined.
     * @return void
     */
    private static function dispatch( object $trigger ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Mark as processing immediately — prevents duplicate fire on cron overlap.
        $wpdb->update(
            $p . 'ofp_trigger_queue',
            [
                'status'       => 'processing',
                'last_attempt' => current_time( 'mysql' ),
                'attempts'     => (int) $trigger->attempts + 1,
            ],
            [ 'id' => $trigger->id ]
        );

        $lead    = OFP_Lead::get( $trigger->lead_id );
        $success = false;
        $result  = [ 'success' => false, 'provider_ref' => '' ];

        if ( ! $lead ) {
            // Lead was deleted — cancel this trigger.
            $wpdb->update( $p . 'ofp_trigger_queue', [ 'status' => 'cancelled' ], [ 'id' => $trigger->id ] );
            return;
        }

        // Personalise the message before sending.
        $message = self::personalise( $trigger->message, $lead, $trigger );

        try {
            switch ( $trigger->type ) {

                case 'sms':
                    if ( ! OFP_Credit::has_balance( $trigger->client_id, 'sms', self::SMS_COST ) ) {
                        $wpdb->update( $p . 'ofp_trigger_queue', [ 'status' => 'cancelled' ], [ 'id' => $trigger->id ] );
                        return;
                    }
                    $sms    = new OFP_SMS( $trigger->sms_provider, $trigger->client_id );
                    $result = $sms->send( $lead->phone, $message );
                    if ( $result['success'] ) {
                        OFP_Credit::deduct( $trigger->client_id, 'sms', self::SMS_COST );
                        OFP_Lead::update_status( $lead->id, 'contacted' );
                    }
                    self::log( $trigger, $result, $message, self::SMS_COST );
                    $success = $result['success'];
                    break;

                case 'voice':
                    if ( ! OFP_Credit::has_balance( $trigger->client_id, 'voice', self::VOICE_COST ) ) {
                        $wpdb->update( $p . 'ofp_trigger_queue', [ 'status' => 'cancelled' ], [ 'id' => $trigger->id ] );
                        return;
                    }
                    $voice  = new OFP_Voice();
                    $result = $voice->make_call( $lead->phone, $message, $trigger->client_id, $trigger->lead_id );
                    if ( $result['success'] ) {
                        OFP_Credit::deduct( $trigger->client_id, 'voice', self::VOICE_COST );
                        OFP_Lead::update_status( $lead->id, 'contacted' );
                    }
                    self::log( $trigger, $result, $message, self::VOICE_COST );
                    $success = $result['success'];
                    break;

                case 'email':
                    if ( empty( $lead->email ) ) {
                        // No email address — skip silently, mark cancelled.
                        $wpdb->update( $p . 'ofp_trigger_queue', [ 'status' => 'cancelled' ], [ 'id' => $trigger->id ] );
                        return;
                    }
                    $success = OFP_Mailer::send(
                        $lead->email,
                        $lead->name ?: 'there',
                        'A message from ' . $trigger->business_name,
                        nl2br( esc_html( $message ) )
                    );
                    self::log( $trigger, [ 'success' => $success, 'provider_ref' => 'wp_mail' ], $message, 0 );
                    break;

                default:
                    error_log( "[OFP_Queue] Unknown trigger type: {$trigger->type}" );
                    $wpdb->update( $p . 'ofp_trigger_queue', [ 'status' => 'cancelled' ], [ 'id' => $trigger->id ] );
                    return;
            }

            $wpdb->update(
                $p . 'ofp_trigger_queue',
                [ 'status' => $success ? 'completed' : 'failed' ],
                [ 'id'     => $trigger->id ]
            );

        } catch ( \Throwable $e ) {
            error_log( '[OFP_Queue] dispatch error for trigger #' . $trigger->id . ': ' . $e->getMessage() );
            $wpdb->update(
                $p . 'ofp_trigger_queue',
                [ 'status' => 'failed', 'response_data' => $e->getMessage() ],
                [ 'id'     => $trigger->id ]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOG
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Log a dispatched trigger to ofp_communications_log.
     *
     * @param  object $trigger  Trigger row with client data joined.
     * @param  array  $result   Result from the provider: ['success', 'provider_ref'].
     * @param  string $message  The personalised message that was sent.
     * @param  float  $cost     Credit cost deducted.
     * @return void
     */
    private static function log(
        object $trigger,
        array $result,
        string $message,
        float $cost
    ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'ofp_communications_log',
            [
                'client_id'    => $trigger->client_id,
                'lead_id'      => $trigger->lead_id,
                'type'         => $trigger->type,
                'direction'    => 'outbound',
                'message'      => $message,
                'status'       => $result['success'] ? 'sent' : 'failed',
                'provider'     => $trigger->sms_provider ?? $trigger->type,
                'provider_ref' => $result['provider_ref'] ?? '',
                'cost'         => $cost,
                'sent_at'      => current_time( 'mysql' ),
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MESSAGE PERSONALISATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Replace template placeholders in a message with actual lead/client values.
     *
     * Supported placeholders:
     *  {{name}}          → lead's name (falls back to 'there' if empty)
     *  {{phone}}         → lead's phone number
     *  {{business_name}} → client's business name
     *
     * @param  string $message  Raw template message from pipeline_config.
     * @param  object $lead     Lead row from ofp_leads.
     * @param  object $trigger  Trigger row with client data joined.
     * @return string           Personalised message ready to send.
     */
    private static function personalise(
        string $message,
        object $lead,
        object $trigger
    ): string {
        return str_replace(
            [ '{{name}}', '{{phone}}', '{{business_name}}' ],
            [
                $lead->name ?: 'there',
                $lead->phone,
                $trigger->business_name ?? '',
            ],
            $message
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cancel all pending triggers for a specific lead.
     * Called when a lead is converted so we stop chasing them.
     *
     * @param  int $lead_id  Lead ID.
     * @return void
     */
    public static function cancel_for_lead( int $lead_id ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'ofp_trigger_queue',
            [ 'status' => 'cancelled' ],
            [ 'lead_id' => $lead_id, 'status' => 'pending' ]
        );
    }

    /**
     * Cancel all pending triggers for a suspended/cancelled client.
     *
     * @param  int $client_id  Client ID.
     * @return void
     */
    public static function cancel_for_client( int $client_id ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'ofp_trigger_queue',
            [ 'status' => 'cancelled' ],
            [ 'client_id' => $client_id, 'status' => 'pending' ]
        );
    }

    /**
     * Reschedule a failed trigger for retry.
     * Pushes scheduled_at forward by 30 minutes.
     *
     * @param  int $trigger_id  Trigger ID.
     * @return void
     */
    public static function retry( int $trigger_id ): void {
        global $wpdb;

        $trigger = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_trigger_queue WHERE id = %d LIMIT 1",
                $trigger_id
            )
        );

        if ( ! $trigger || $trigger->attempts >= 3 ) {
            // Max 3 attempts — give up after that.
            $wpdb->update(
                $wpdb->prefix . 'ofp_trigger_queue',
                [ 'status' => 'cancelled' ],
                [ 'id'     => $trigger_id ]
            );
            return;
        }

        $wpdb->update(
            $wpdb->prefix . 'ofp_trigger_queue',
            [
                'status'       => 'pending',
                'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 1800 ), // +30 minutes
            ],
            [ 'id' => $trigger_id ]
        );
    }

    /**
     * Cancel a single pending or failed trigger by ID.
     * Counterpart to retry() — for admin use on the Trigger Queue page.
     *
     * @param  int $trigger_id  Trigger ID.
     * @return bool             True if a row was updated.
     */
    public static function cancel_trigger( int $trigger_id ): bool {
        global $wpdb;

        return (bool) $wpdb->update(
            $wpdb->prefix . 'ofp_trigger_queue',
            [ 'status' => 'cancelled' ],
            [ 'id' => $trigger_id ]
        );
    }
}
