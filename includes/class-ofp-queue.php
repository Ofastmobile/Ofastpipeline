<?php
/**
 * OFP_Queue
 *
 * The trigger queue. It powers the automatic SMS and email follow ups for
 * every client. Voice calls are NOT part of follow ups any more. Calls are
 * handled only by OFP_Voice_Notice (call scripts).
 *
 * HOW IT WORKS:
 *  1. populate_triggers() runs when a lead is captured. It reads the client's
 *     pipeline_config and adds one row per message to ofp_trigger_queue.
 *  2. process_due() runs from cron. It claims due rows, sends them, charges
 *     credit, and updates the row.
 *
 * TIME RULE: scheduled_at is always saved in UTC and compared with
 * UTC_TIMESTAMP(). Never save site time in scheduled_at.
 *
 * SAFETY CHECKS before a message goes out:
 *  - Client status must be active.
 *  - The lead must still exist and must not be converted, dead or lost.
 *  - SMS needs enough SMS credit for every segment of the message.
 *
 * INSTANT SEND: when a form lead is captured, the instant SMS and instant email
 * to the lead, and the new lead alert to the client, are sent right away after the
 * visitor has received the thank you reply. See send_instant_after_response().
 * Cron stays as the safety net and handles the follow ups.
 *
 * PLACEHOLDERS: {{name}} {{phone}} {{business_name}} {{email}} {{property}} {{inspection_date}} {{inspection_time}}
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Queue {

    // SMS cost in NGN for one SMS segment.
    const SMS_COST   = 6.99;

    // Voice cost in NGN per call. Used by call scripts only.
    const VOICE_COST = 15.00;

    // Most rows handled in one cron run, and the most seconds one run may take.
    const BATCH_SIZE  = 30;
    const MAX_RUNTIME = 90;

    // A failed send is tried again after 30 minutes, up to 3 attempts in total.
    const MAX_ATTEMPTS = 3;
    const RETRY_DELAY  = 1800;

    // A pending row that is more than 48 hours late is skipped, not sent.
    const STALE_AFTER = 172800;

    // A row stuck in "processing" for more than 15 minutes is marked failed.
    const STUCK_AFTER = 900;

    // Lead statuses that stop all follow ups.
    const STOP_STATUSES = [ 'converted', 'dead', 'lost' ];

    // ─────────────────────────────────────────────────────────────────────────
    // POPULATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Schedule all follow ups for a newly captured lead.
     * Called after OFP_Lead::create() from lead capture and from imports.
     */
    public static function populate_triggers( int $client_id, int $lead_id ): void {
        global $wpdb;

        $config = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_pipeline_configs WHERE client_id = %d LIMIT 1",
                $client_id
            )
        );

        // No pipeline config means nothing to queue.
        if ( ! $config ) {
            return;
        }

        // Real time in UTC. Never use site time here.
        $now      = time();
        $triggers = [];

        $lead       = OFP_Lead::get( $lead_id );
        $lead_email = $lead ? trim( (string) $lead->email ) : '';
        $has_email  = $lead_email !== '' && is_email( $lead_email );
        $cols_ready = self::columns_ready();

        if ( $config->instant_sms_enabled && ! empty( $config->instant_sms_message ) ) {
            $triggers[] = [
                'type'    => 'sms',
                'subject' => '',
                'message' => $config->instant_sms_message,
                'time'    => $now,
            ];
        }

        // Instant email. An empty message uses a safe default, because email costs no credit.
        if ( $cols_ready && $has_email && ! empty( $config->instant_email_enabled ) ) {
            $email_message = trim( (string) ( $config->instant_email_message ?? '' ) );
            $email_subject = trim( (string) ( $config->instant_email_subject ?? '' ) );
            $triggers[] = [
                'type'    => 'email',
                'subject' => $email_subject !== '' ? $email_subject : self::default_instant_email_subject(),
                'message' => $email_message !== '' ? $email_message : self::default_instant_email_message(),
                'time'    => $now,
            ];
        }

        foreach ( [ 1, 2, 3 ] as $n ) {
            $message = $config->{"followup_{$n}_message"} ?? '';
            if ( empty( $message ) ) {
                continue;
            }
            $type = self::clean_type( (string) ( $config->{"followup_{$n}_type"} ?? 'sms' ) );
            // An email follow up needs an email address.
            if ( 'email' === $type && ! $has_email ) {
                continue;
            }
            $hours = max( 1, (int) ( $config->{"followup_{$n}_delay_hours"} ?? 1 ) );
            $triggers[] = [
                'type'    => $type,
                'subject' => '',
                'message' => $message,
                'time'    => $now + ( $hours * HOUR_IN_SECONDS ),
            ];
        }

        foreach ( $triggers as $trigger ) {
            $row = [
                'client_id'    => $client_id,
                'lead_id'      => $lead_id,
                'type'         => $trigger['type'],
                'message'      => $trigger['message'],
                'scheduled_at' => gmdate( 'Y-m-d H:i:s', $trigger['time'] ),
                'status'       => 'pending',
                'attempts'     => 0,
                'created_at'   => current_time( 'mysql' ),
            ];
            if ( $cols_ready ) {
                $row['subject'] = $trigger['subject'] !== '' ? $trigger['subject'] : null;
            }
            $wpdb->insert( $wpdb->prefix . 'ofp_trigger_queue', $row );
        }
    }

    /**
     * Text used for the instant email when the client left the message empty.
     * The admin can set it in Settings. If not, the built in text is used.
     */
    public static function default_instant_email_message(): string {
        $saved = trim( (string) get_option( 'ofp_default_instant_email_message', '' ) );
        if ( $saved !== '' ) {
            return $saved;
        }
        return 'Hi {{name}}, thank you for contacting {{business_name}}. We have received your message and one of our team will reach out to you shortly. If you need us urgently, reply to this email or call us.';
    }

    /** Subject used for the instant email when the client left the subject empty. */
    public static function default_instant_email_subject(): string {
        $saved = trim( (string) get_option( 'ofp_default_instant_email_subject', '' ) );
        return $saved !== '' ? $saved : 'Thank you for contacting {{business_name}}';
    }

    /**
     * Instant email and alert fields for a brand new client config.
     * Empty until the new columns exist, so an insert can never fail.
     */
    public static function default_new_config_fields(): array {
        if ( ! self::columns_ready() ) {
            return [];
        }
        return [
            'instant_email_enabled' => 1,
            'instant_email_subject' => get_option( 'ofp_default_instant_email_subject', '' ),
            'instant_email_message' => get_option( 'ofp_default_instant_email_message', '' ),
            'alert_sms_enabled'     => '0' === (string) get_option( 'ofp_default_alert_sms', '1' ) ? 0 : 1,
        ];
    }

    /** Follow ups can only be sms or email. Anything else becomes sms. */
    public static function clean_type( string $type ): string {
        return in_array( $type, [ 'sms', 'email' ], true ) ? $type : 'sms';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROCESS (called by cron)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Process due rows. Stops after BATCH_SIZE rows or MAX_RUNTIME seconds.
     */
    public static function process_due(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $started = time();

        self::housekeeping();

        $triggers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.*, c.sms_provider, c.business_name, c.business_phone,
                        c.whatsapp_number, c.status AS client_status
                 FROM {$p}ofp_trigger_queue q
                 JOIN {$p}ofp_clients c ON c.id = q.client_id
                 WHERE q.status       = 'pending'
                   AND q.scheduled_at <= UTC_TIMESTAMP()
                   AND c.status       = 'active'
                 ORDER BY q.scheduled_at ASC
                 LIMIT %d",
                self::BATCH_SIZE
            )
        );

        foreach ( $triggers as $trigger ) {
            if ( ( time() - $started ) >= self::MAX_RUNTIME ) {
                break;
            }
            self::dispatch( $trigger );
        }
    }

    /**
     * Clean up two kinds of problem rows before each run.
     */
    private static function housekeeping(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Pending rows that are far too late. Better to skip than to send a
        // message days after the lead asked.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$p}ofp_trigger_queue
                 SET status = 'cancelled', response_data = %s
                 WHERE status = 'pending'
                   AND scheduled_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )",
                'Skipped because it was more than 48 hours late.',
                self::STALE_AFTER
            )
        );

        // Rows that started sending but never finished. We do not resend them
        // automatically because the message may already have gone out.
        // last_attempt is saved in site time, so build the cutoff the same way.
        $cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::STUCK_AFTER );
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$p}ofp_trigger_queue
                 SET status = 'failed', response_data = %s
                 WHERE status = 'processing' AND last_attempt < %s",
                'Stopped while sending. Check the communications log before you retry.',
                $cutoff
            )
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DISPATCH
    // ─────────────────────────────────────────────────────────────────────────

    private static function dispatch( object $trigger ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Claim the row. Only one runner can win this update, so the same
        // message can never be sent twice by two overlapping runs.
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$p}ofp_trigger_queue
                 SET status = 'processing', last_attempt = %s, attempts = attempts + 1
                 WHERE id = %d AND status = 'pending'",
                current_time( 'mysql' ),
                (int) $trigger->id
            )
        );
        if ( ! $claimed ) {
            return;
        }
        $attempts = (int) $trigger->attempts + 1;

        $lead = OFP_Lead::get( $trigger->lead_id );
        if ( ! $lead ) {
            self::close( (int) $trigger->id, 'cancelled', 'The lead was deleted.' );
            return;
        }

        // Safety net. A lead that is converted, dead or lost gets nothing more.
        if ( in_array( (string) $lead->status, self::STOP_STATUSES, true ) ) {
            self::close( (int) $trigger->id, 'cancelled', 'The lead is ' . $lead->status . '.' );
            return;
        }

        $message = self::personalise( (string) $trigger->message, $lead, $trigger );
        $success = false;
        $error   = '';

        try {
            switch ( $trigger->type ) {

                case 'sms':
                    if ( empty( $lead->phone ) ) {
                        self::close( (int) $trigger->id, 'cancelled', 'The lead has no phone number.' );
                        return;
                    }

                    $cost = self::SMS_COST * OFP_SMS::segments( $message );

                    if ( ! OFP_Credit::has_balance( (int) $trigger->client_id, 'sms', $cost ) ) {
                        self::close( (int) $trigger->id, 'cancelled', 'Not enough SMS credit.' );
                        return;
                    }

                    // OFP_SMS records the message in the communications log itself.
                    $result  = OFP_SMS::for_client( (int) $trigger->client_id )->send( $lead->phone, $message, (int) $lead->id );
                    $success = ! empty( $result['success'] );
                    $error   = (string) ( $result['error'] ?? '' );

                    if ( $success ) {
                        OFP_Credit::deduct( (int) $trigger->client_id, 'sms', $cost );
                    }
                    break;

                case 'email':
                    if ( ! is_email( (string) $lead->email ) ) {
                        self::close( (int) $trigger->id, 'cancelled', 'The lead has no valid email address.' );
                        return;
                    }

                    $subject = ! empty( $trigger->subject )
                        ? self::personalise( (string) $trigger->subject, $lead, $trigger )
                        : 'A message from ' . $trigger->business_name;

                    // Sent with the client's own email template and reply to address.
                    // The mailer records this email in the communications log.
                    $success = OFP_Mailer::send_client_email(
                        (string) $lead->email,
                        wp_strip_all_tags( $subject ),
                        nl2br( esc_html( $message ) ),
                        (int) $trigger->client_id,
                        (int) $lead->id
                    );
                    $error = $success ? '' : 'The email could not be sent.';
                    break;

                default:
                    // Old voice rows and anything unknown. Follow ups are SMS or email only.
                    self::close( (int) $trigger->id, 'cancelled', 'This type is no longer supported: ' . $trigger->type );
                    return;
            }

        } catch ( \Throwable $e ) {
            error_log( '[OFP_Queue] dispatch error for trigger #' . $trigger->id . ': ' . $e->getMessage() );
            $success = false;
            $error   = $e->getMessage();
        }

        if ( $success ) {
            // Move a new lead to contacted. Never move a lead backwards.
            if ( 'new' === (string) $lead->status ) {
                OFP_Lead::update_status( (int) $lead->id, 'contacted' );
            }
            self::close( (int) $trigger->id, 'completed', '' );
            return;
        }

        // Failed. Try again later, up to MAX_ATTEMPTS.
        if ( $attempts < self::MAX_ATTEMPTS ) {
            $wpdb->update(
                $p . 'ofp_trigger_queue',
                [
                    'status'        => 'pending',
                    'scheduled_at'  => gmdate( 'Y-m-d H:i:s', time() + self::RETRY_DELAY ),
                    'response_data' => 'Attempt ' . $attempts . ' failed. ' . $error,
                ],
                [ 'id' => (int) $trigger->id ]
            );
            return;
        }

        self::close( (int) $trigger->id, 'failed', 'Gave up after ' . $attempts . ' attempts. ' . $error );
    }

    /** Set the final status of a row and keep a short reason. */
    private static function close( int $id, string $status, string $note ): void {
        global $wpdb;
        $data = [ 'status' => $status ];
        if ( $note !== '' ) {
            $data['response_data'] = $note;
        }
        $wpdb->update( $wpdb->prefix . 'ofp_trigger_queue', $data, [ 'id' => $id ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MESSAGE PERSONALISATION
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Replace placeholders with real values.
     *  {{name}}            lead name, or "there" when empty
     *  {{phone}}           lead phone number
     *  {{business_name}}   client business name
     *  {{email}}           lead email address
     *  {{property}}        title of the property the lead asked about, or "the property"
     *  {{inspection_date}} preferred viewing date if booked
     *  {{inspection_time}} preferred viewing time if booked
     */
    private static function personalise(
        string $message,
        object $lead,
        object $trigger
    ): string {
        global $wpdb;

        // Property + inspection details are looked up only when the template uses them.
        $property = '';
        if ( ! empty( $lead->property_id ) ) {
            $property = trim( (string) get_the_title( (int) $lead->property_id ) );
        }

        $insp_date = '';
        $insp_time = '';
        if ( ( false !== strpos( $message, '{{inspection_date}}' ) || false !== strpos( $message, '{{inspection_time}}' ) )
            && ! empty( $lead->id ) && get_option( 'ofp_schema_inquiries_v2' ) ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT preferred_viewing_date, preferred_viewing_time FROM {$wpdb->prefix}ofp_property_inquiries
                 WHERE lead_id = %d ORDER BY id DESC LIMIT 1",
                (int) $lead->id
            ) );
            if ( $row ) {
                $insp_date = (string) ( $row->preferred_viewing_date ?? '' );
                $insp_time = (string) ( $row->preferred_viewing_time ?? '' );
            }
        }

        return str_replace(
            [ '{{name}}', '{{phone}}', '{{business_name}}', '{{email}}', '{{property}}', '{{inspection_date}}', '{{inspection_time}}' ],
            [
                $lead->name ?: 'there',
                $lead->phone,
                $trigger->business_name ?? '',
                $lead->email ?? '',
                $property !== '' ? $property : 'the property',
                $insp_date,
                $insp_time,
            ],
            $message
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INSTANT SEND AND NEW LEAD ALERT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send the instant messages as soon as the visitor has their reply.
     *
     * The visitor sees the thank you message first. The sending then runs
     * at the end of the same request. If the server cannot release the visitor
     * early, they wait a few seconds more, and nothing else changes.
     * Anything that fails here stays in the queue, and cron sends it later.
     */
    public static function send_instant_after_response( int $client_id, int $lead_id ): void {
        ignore_user_abort( true );

        register_shutdown_function(
            static function () use ( $client_id, $lead_id ): void {
                // Release the visitor first where the server supports it.
                while ( ob_get_level() > 0 ) {
                    @ob_end_flush();
                }
                @flush();
                if ( function_exists( 'fastcgi_finish_request' ) ) {
                    @fastcgi_finish_request();
                } elseif ( function_exists( 'litespeed_finish_request' ) ) {
                    @litespeed_finish_request();
                }

                try {
                    OFP_Queue::send_instant_now( $client_id, $lead_id );
                } catch ( \Throwable $e ) {
                    error_log( '[OFP_Queue] instant send failed for lead #' . $lead_id . ': ' . $e->getMessage() );
                }
            }
        );
    }

    /**
     * Send the messages for this lead that are due right now, then alert the client.
     * Follow ups are not touched, because their time is hours away.
     */
    public static function send_instant_now( int $client_id, int $lead_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.*, c.sms_provider, c.business_name, c.business_phone,
                        c.whatsapp_number, c.status AS client_status
                 FROM {$p}ofp_trigger_queue q
                 JOIN {$p}ofp_clients c ON c.id = q.client_id
                 WHERE q.lead_id      = %d
                   AND q.client_id    = %d
                   AND q.status       = 'pending'
                   AND q.scheduled_at <= DATE_ADD( UTC_TIMESTAMP(), INTERVAL 5 SECOND )
                   AND c.status       = 'active'
                 ORDER BY q.id ASC",
                $lead_id,
                $client_id
            )
        );

        // dispatch() claims each row first, so cron can never send the same one twice.
        foreach ( (array) $rows as $row ) {
            self::dispatch( $row );
        }

        self::alert_client_of_new_lead( $client_id, $lead_id );
    }

    /**
     * Tell the client about the new lead right away.
     *  Bell and email follow the client's notification setting.
     *  SMS goes to the client's own phone, uses their SMS credit, and has its own switch.
     */
    private static function alert_client_of_new_lead( int $client_id, int $lead_id ): void {
        global $wpdb;

        // One alert per lead, even if this runs twice.
        $once_key = 'ofp_lead_alert_' . $lead_id;
        if ( get_transient( $once_key ) ) {
            return;
        }
        set_transient( $once_key, 1, HOUR_IN_SECONDS );

        $client = OFP_Client::get( $client_id );
        $lead   = OFP_Lead::get( $lead_id );
        if ( ! $client || ! $lead || 'active' !== (string) $client->status ) {
            return;
        }

        $name     = trim( wp_strip_all_tags( (string) $lead->name ) );
        $name     = $name !== '' ? $name : 'Someone';
        $phone    = wp_strip_all_tags( (string) $lead->phone );
        $email    = wp_strip_all_tags( (string) $lead->email );
        $notes    = trim( wp_strip_all_tags( (string) ( $lead->notes ?? '' ) ) );
        $property = ! empty( $lead->property_id ) ? trim( (string) get_the_title( (int) $lead->property_id ) ) : '';

        // Bell and email.
        $body = $name . ' just contacted you' . ( $property !== '' ? ' about ' . $property : '' ) . '. Phone: ' . $phone;
        if ( $email !== '' ) {
            $body .= '. Email: ' . $email;
        }
        if ( $notes !== '' ) {
            $body .= '. Message: ' . mb_substr( $notes, 0, 200 );
        }
        $body .= '. Reach out now while they are still interested.';

        if ( class_exists( 'OFP_Notification' ) ) {
            OFP_Notification::create( $client_id, 'new_lead', 'New lead: ' . $name, $body );
        }

        // SMS to the client's own phone.
        $config = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_pipeline_configs WHERE client_id = %d LIMIT 1",
                $client_id
            )
        );
        if ( ! $config || empty( $config->alert_sms_enabled ) || empty( $client->phone ) ) {
            return;
        }

        $sms = 'New lead: ' . $name . ' ' . $phone . ( $property !== '' ? ' (' . $property . ')' : '' ) . '. Reach out now.';
        $sms = mb_substr( $sms, 0, 160 );

        if ( ! OFP_Credit::has_balance( $client_id, 'sms', self::SMS_COST ) ) {
            return;
        }

        $result = OFP_SMS::for_client( $client_id )->send( (string) $client->phone, $sms, null );
        if ( ! empty( $result['success'] ) ) {
            OFP_Credit::deduct( $client_id, 'sms', self::SMS_COST );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DATABASE COLUMNS
    // ─────────────────────────────────────────────────────────────────────────

    /** True once the new columns are confirmed to exist. */
    public static function columns_ready(): bool {
        return '1' === get_option( 'ofp_instant_cols_v1' );
    }

    /**
     * Add the new columns once. WordPress only creates tables on activation,
     * so a plugin update has to add its own columns.
     * Tried at most once every 10 minutes until it works.
     */
    public static function ensure_columns(): void {
        if ( self::columns_ready() || get_transient( 'ofp_instant_cols_try' ) ) {
            return;
        }
        set_transient( 'ofp_instant_cols_try', 1, 600 );

        global $wpdb;
        $p = $wpdb->prefix;

        $wanted = [
            [ 'ofp_pipeline_configs', 'instant_email_enabled', 'TINYINT(1) NOT NULL DEFAULT 1' ],
            [ 'ofp_pipeline_configs', 'instant_email_subject', 'VARCHAR(190) DEFAULT NULL' ],
            [ 'ofp_pipeline_configs', 'instant_email_message', 'TEXT DEFAULT NULL' ],
            [ 'ofp_pipeline_configs', 'alert_sms_enabled',     'TINYINT(1) NOT NULL DEFAULT 1' ],
            [ 'ofp_trigger_queue',    'subject',               'VARCHAR(190) DEFAULT NULL' ],
        ];

        $all_ok = true;
        foreach ( $wanted as $item ) {
            list( $table, $column, $definition ) = $item;
            $check = "SHOW COLUMNS FROM {$p}{$table} LIKE '{$column}'";
            if ( empty( $wpdb->get_results( $check ) ) ) {
                $wpdb->query( "ALTER TABLE {$p}{$table} ADD COLUMN {$column} {$definition}" );
                if ( empty( $wpdb->get_results( $check ) ) ) {
                    $all_ok = false;
                }
            }
        }

        if ( $all_ok ) {
            update_option( 'ofp_instant_cols_v1', '1', true );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ONE TIME CLEAN UP FOR REMOVED VOICE FOLLOW UPS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Voice and IVR follow ups were removed. Run once:
     *  - Any follow up saved as voice becomes sms.
     *  - Any pending or processing voice row in the queue is cancelled.
     */
    public static function migrate_voice_followups_once(): void {
        if ( get_option( 'ofp_voice_followups_removed' ) ) {
            return;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( [ 1, 2, 3 ] as $n ) {
            $col = "followup_{$n}_type";
            $wpdb->query( "UPDATE {$p}ofp_pipeline_configs SET {$col} = 'sms' WHERE {$col} = 'voice'" );
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$p}ofp_trigger_queue
                 SET status = 'cancelled', response_data = %s
                 WHERE type = 'voice' AND status IN ( 'pending', 'processing' )",
                'Voice follow ups were removed.'
            )
        );

        update_option( 'ofp_voice_followups_removed', 1, false );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /** Cancel all pending rows for one lead. */
    public static function cancel_for_lead( int $lead_id ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'ofp_trigger_queue',
            [ 'status' => 'cancelled' ],
            [ 'lead_id' => $lead_id, 'status' => 'pending' ]
        );
    }

    /** Cancel all pending rows for a suspended or cancelled client. */
    public static function cancel_for_client( int $client_id ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'ofp_trigger_queue',
            [ 'status' => 'cancelled' ],
            [ 'client_id' => $client_id, 'status' => 'pending' ]
        );
    }

    /**
     * Manual retry from the admin Trigger Queue page.
     * The attempt counter starts again and the row runs in about a minute.
     */
    public static function retry( int $trigger_id ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'ofp_trigger_queue',
            [
                'status'       => 'pending',
                'attempts'     => 0,
                'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ),
            ],
            [ 'id' => $trigger_id ]
        );
    }

    /** Cancel one pending or failed row. */
    public static function cancel_trigger( int $trigger_id ): bool {
        global $wpdb;

        return (bool) $wpdb->update(
            $wpdb->prefix . 'ofp_trigger_queue',
            [ 'status' => 'cancelled' ],
            [ 'id' => $trigger_id ]
        );
    }
}
