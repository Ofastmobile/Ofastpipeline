<?php
/**
 * Class OFP_Voice_Notice
 *
 * Simple one way phone calls. The call speaks a short message and hangs up.
 * There is no key press menu and clients do not upload audio. Clients only
 * edit the text.
 *
 * Three kinds of call:
 *  1. interest     A new lead filled the contact or inspection form.
 *  2. installment  A buyer's installment is due today.
 *  3. rent         A tenant's rent is due today (the lease end date).
 *
 * Rules that apply to every call:
 *  - The client must have switched that call type on. It is OFF by default,
 *    because every call uses voice credit.
 *  - The client must have voice credit.
 *  - Calls only go out inside the calling hours set by admin. Outside those
 *    hours the call waits for the next morning.
 *  - Each call is placed once.
 *
 * Settings live in one option per client, so this works for every client,
 * whether or not they have a CRM pipeline.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Voice_Notice {

    const OPT_PREFIX   = 'ofp_voice_notice_';
    const TOKEN_PREFIX = 'ofp_vnotice_';
    const TOKEN_TTL    = 3600;
    const MAX_CHARS    = 400;

    /** @var int Spreads reminder calls apart inside one cron run. */
    private static int $spread = 0;

    public static function init(): void {
        add_action( 'ofp_send_interest_call',  [ __CLASS__, 'run_interest_call' ], 10, 1 );
        add_action( 'ofp_send_voice_reminder', [ __CLASS__, 'run_reminder_call' ], 10, 1 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SETTINGS
    // ─────────────────────────────────────────────────────────────────────────

    public static function types(): array {
        return [
            'interest'    => 'Lead interest call',
            'installment' => 'Installment due day call',
            'rent'        => 'Rent due day call',
        ];
    }

    /** Text used when the client has not written their own. Admin can change these. */
    public static function default_script( string $type ): string {
        $saved = trim( (string) get_option( 'ofp_default_call_' . $type, '' ) );
        if ( $saved !== '' ) {
            return $saved;
        }
        switch ( $type ) {
            case 'installment':
                return 'Hello {{name}}. This is {{business_name}}. This is a reminder that your installment payment of {{amount}} naira for {{property}} is due today. Please check your email or SMS for your payment link. Thank you.';
            case 'rent':
                return 'Hello {{name}}. This is {{business_name}}. This is a reminder that your rent for {{property}} is due today. Please check your email or SMS for the details. Thank you.';
            default:
                return 'Hello {{name}}. This is {{business_name}}. Thank you for your interest in {{property}}. One of our agents will reach out to you shortly. Thank you.';
        }
    }

    /** @return array<string,mixed> interest_enabled, interest_message, and the same for installment and rent. */
    public static function settings( int $client_id ): array {
        $saved = get_option( self::OPT_PREFIX . $client_id, [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        $out = [];
        foreach ( array_keys( self::types() ) as $type ) {
            $out[ $type . '_enabled' ] = ! empty( $saved[ $type . '_enabled' ] ) ? 1 : 0;
            $message                   = trim( (string) ( $saved[ $type . '_message' ] ?? '' ) );
            $out[ $type . '_message' ] = $message !== '' ? $message : self::default_script( $type );
        }
        return $out;
    }

    /** Saves what the client submitted from the Call Scripts tab. */
    public static function save_settings( int $client_id, array $input ): void {
        $out = [];
        foreach ( array_keys( self::types() ) as $type ) {
            $out[ $type . '_enabled' ] = ! empty( $input[ 'call_' . $type . '_enabled' ] ) ? 1 : 0;
            $message                   = sanitize_textarea_field( wp_unslash( $input[ 'call_' . $type . '_message' ] ?? '' ) );
            $out[ $type . '_message' ] = mb_substr( trim( $message ), 0, self::MAX_CHARS );
        }
        update_option( self::OPT_PREFIX . $client_id, $out, false );
    }

    /**
     * Fills in the placeholders and makes the text safe for the phone voice.
     * Placeholders: {{name}} {{business_name}} {{property}} {{amount}} {{due_date}}
     */
    public static function render_script( string $template, array $vars ): string {
        $amount = '';
        if ( isset( $vars['amount'] ) && $vars['amount'] !== '' ) {
            $value  = (float) $vars['amount'];
            $amount = ( floor( $value ) === $value ) ? number_format( $value, 0 ) : number_format( $value, 2 );
        }

        $map = [
            '{{name}}'          => trim( (string) ( $vars['name'] ?? '' ) ) ?: 'there',
            '{{business_name}}' => trim( (string) ( $vars['business_name'] ?? '' ) ),
            '{{property}}'      => trim( (string) ( $vars['property'] ?? '' ) ) ?: 'the property',
            '{{amount}}'        => $amount,
            '{{due_date}}'      => trim( (string) ( $vars['due_date'] ?? '' ) ),
        ];

        $text = strtr( $template, $map );
        $text = wp_strip_all_tags( $text );
        $text = preg_replace( '/\s+/u', ' ', $text );
        return mb_substr( trim( (string) $text ), 0, 600 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CALLING HOURS
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{enabled:bool,start:int,end:int} Hours are in the site time zone. */
    public static function hours(): array {
        $start = (int) get_option( 'ofp_voice_hours_start', 8 );
        $end   = (int) get_option( 'ofp_voice_hours_end', 19 );
        if ( $start < 0 || $start > 23 ) $start = 8;
        if ( $end < 1 || $end > 24 )     $end   = 19;
        if ( $end <= $start ) {
            $start = 8;
            $end   = 19;
        }
        return [
            'enabled' => '0' !== (string) get_option( 'ofp_voice_hours_enabled', '1' ),
            'start'   => $start,
            'end'     => $end,
        ];
    }

    /** 0 means "you may call now". Otherwise the time stamp when calling opens again. */
    public static function next_allowed_time( ?DateTimeImmutable $now = null ): int {
        $hours = self::hours();
        if ( ! $hours['enabled'] ) {
            return 0;
        }

        $now  = $now ?: new DateTimeImmutable( 'now', wp_timezone() );
        $hour = (int) $now->format( 'G' );

        if ( $hour >= $hours['start'] && $hour < $hours['end'] ) {
            return 0;
        }

        $open = ( $hour < $hours['start'] )
            ? $now->setTime( $hours['start'], 0, 0 )
            : $now->modify( '+1 day' )->setTime( $hours['start'], 0, 0 );

        return $open->getTimestamp();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PLACING A CALL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Places one call. Checks credit, remembers the text for the phone webhook,
     * charges the credit only when the call was accepted.
     *
     * @return array{success:bool,error:string}
     */
    public static function place_call( int $client_id, string $phone, string $script, int $lead_id = 0 ): array {
        if ( trim( $phone ) === '' || $script === '' ) {
            return [ 'success' => false, 'error' => 'missing_phone_or_text' ];
        }
        if ( ! class_exists( 'OFP_Credit' ) || ! OFP_Credit::has_balance( $client_id, 'voice', OFP_Queue::VOICE_COST ) ) {
            return [ 'success' => false, 'error' => 'no_credits' ];
        }

        $token = wp_generate_password( 32, false, false );
        set_transient( self::TOKEN_PREFIX . $token, [ 'text' => $script, 'client_id' => $client_id ], self::TOKEN_TTL );

        $voice  = new OFP_Voice();
        $result = $voice->make_call( $phone, $script, $client_id, $lead_id, $token, OFP_Queue::VOICE_COST );

        if ( empty( $result['success'] ) ) {
            delete_transient( self::TOKEN_PREFIX . $token );
            return [ 'success' => false, 'error' => (string) ( $result['error'] ?? 'call_failed' ) ];
        }

        OFP_Credit::deduct( $client_id, 'voice', OFP_Queue::VOICE_COST );
        return [ 'success' => true, 'error' => '' ];
    }

    /** Used by the phone webhook to read out the text for a call. */
    public static function get_notice( string $token ): ?array {
        $notice = get_transient( self::TOKEN_PREFIX . $token );
        return is_array( $notice ) && ! empty( $notice['text'] ) ? $notice : null;
    }

    private static function note_problem( int $client_id, string $what, string $error ): void {
        if ( ! class_exists( 'OFP_Logger' ) || ! $client_id ) {
            return;
        }
        $reason = ( 'no_credits' === $error ) ? 'there is no voice credit left' : 'the call could not be placed (' . $error . ')';
        OFP_Logger::log( 'Phone notice not placed', $client_id, [
            'message' => sprintf( 'The %s was not placed because %s.', $what, $reason ),
        ] );
    }

    private static function client_row( int $client_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, business_name FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
            $client_id
        ) );
        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. LEAD INTEREST CALL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called from lead capture, right after a form lead is saved. Manual uploads
     * and imports do not go through lead capture, so they never trigger this.
     *
     * The call is scheduled for now and cron is nudged, so the form is never
     * slowed down and the call goes out within seconds. If the server blocks
     * the nudge, the call goes out on the next cron run.
     */
    public static function schedule_interest_call( int $client_id, int $lead_id ): void {
        if ( $client_id < 1 || $lead_id < 1 ) {
            return;
        }
        $settings = self::settings( $client_id );
        if ( empty( $settings['interest_enabled'] ) ) {
            return;
        }

        wp_schedule_single_event( time(), 'ofp_send_interest_call', [ $lead_id ] );
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
    }

    public static function run_interest_call( $lead_id ): void {
        global $wpdb;
        $lead_id = (int) $lead_id;

        $lead = class_exists( 'OFP_Lead' ) ? OFP_Lead::get( $lead_id ) : null;
        if ( ! $lead || empty( $lead->phone ) || empty( $lead->client_id ) ) {
            return;
        }

        $client_id = (int) $lead->client_id;
        $settings  = self::settings( $client_id );
        if ( empty( $settings['interest_enabled'] ) ) {
            return;
        }

        // One interest call per lead. Any earlier voice call to this lead means we are done.
        $already = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ofp_communications_log WHERE lead_id = %d AND type = 'voice' AND ( status IS NULL OR status <> 'failed' )",
            $lead_id
        ) );
        if ( $already ) {
            return;
        }

        // Outside calling hours: wait for the next morning.
        $wait = self::next_allowed_time();
        if ( $wait ) {
            wp_schedule_single_event( $wait, 'ofp_send_interest_call', [ $lead_id ] );
            return;
        }

        $client = self::client_row( $client_id );
        $script = self::render_script( $settings['interest_message'], [
            'name'          => $lead->name,
            'business_name' => $client ? $client->business_name : '',
            'property'      => ! empty( $lead->property_id ) ? get_the_title( (int) $lead->property_id ) : '',
        ] );

        $result = self::place_call( $client_id, (string) $lead->phone, $script, $lead_id );
        if ( ! $result['success'] ) {
            self::note_problem( $client_id, 'interest call to ' . ( $lead->name ?: 'a new lead' ), $result['error'] );

            // Try again in 30 minutes, at most 2 more times. No point when there is no credit.
            if ( 'no_credits' !== $result['error'] ) {
                $tries_key = 'ofp_vn_try_' . $lead_id;
                $tries     = (int) get_transient( $tries_key );
                if ( $tries < 2 ) {
                    set_transient( $tries_key, $tries + 1, DAY_IN_SECONDS );
                    wp_schedule_single_event( time() + 1800, 'ofp_send_interest_call', [ $lead_id ] );
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 and 3. DUE DAY REMINDER CALLS (installments and rent)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Called by the installment and rent reminder jobs on the due day only.
     * The call is scheduled, not placed inline, so a busy day cannot slow the
     * reminder job down. Calls are spread 20 seconds apart.
     *
     * @param string $type       installment or rent
     * @param array  $vars       name, property, amount, due_date
     * @param string $unique_key Same key means same call, so it is never placed twice.
     */
    public static function queue_reminder( string $type, int $client_id, string $phone, array $vars, string $unique_key ): void {
        if ( ! in_array( $type, [ 'installment', 'rent' ], true ) || $client_id < 1 || trim( $phone ) === '' ) {
            return;
        }
        $settings = self::settings( $client_id );
        if ( empty( $settings[ $type . '_enabled' ] ) ) {
            return;
        }

        $when = max( time(), self::next_allowed_time() ) + ( 20 * self::$spread++ );

        wp_schedule_single_event( $when, 'ofp_send_voice_reminder', [ [
            'type'      => $type,
            'client_id' => $client_id,
            'phone'     => $phone,
            'vars'      => $vars,
            'key'       => $unique_key,
        ] ] );
    }

    public static function run_reminder_call( $payload ): void {
        if ( ! is_array( $payload ) ) {
            return;
        }
        $type      = (string) ( $payload['type'] ?? '' );
        $client_id = (int) ( $payload['client_id'] ?? 0 );
        $phone     = (string) ( $payload['phone'] ?? '' );
        $vars      = is_array( $payload['vars'] ?? null ) ? $payload['vars'] : [];
        $key       = (string) ( $payload['key'] ?? '' );

        if ( ! in_array( $type, [ 'installment', 'rent' ], true ) || $client_id < 1 || $phone === '' ) {
            return;
        }

        $settings = self::settings( $client_id );
        if ( empty( $settings[ $type . '_enabled' ] ) ) {
            return;
        }

        // Each call is placed once. The retry counter is not part of the identity.
        $identity = $payload;
        unset( $identity['try'] );
        $done_key = 'ofp_vn_done_' . md5( $key ?: wp_json_encode( $identity ) );
        $lock_key = $done_key . '_lock';
        if ( get_transient( $done_key ) || get_transient( $lock_key ) ) {
            return;
        }

        // Outside calling hours: wait for the next morning.
        $wait = self::next_allowed_time();
        if ( $wait ) {
            wp_schedule_single_event( $wait, 'ofp_send_voice_reminder', [ $payload ] );
            return;
        }

        // Short lock so two runs cannot place the same call at the same time.
        set_transient( $lock_key, 1, 600 );

        $client = self::client_row( $client_id );
        $vars['business_name'] = $client ? $client->business_name : '';
        $script = self::render_script( $settings[ $type . '_message' ], $vars );

        $result = self::place_call( $client_id, $phone, $script, 0 );
        if ( ! empty( $result['success'] ) ) {
            set_transient( $done_key, 1, 2 * DAY_IN_SECONDS );
            return;
        }

        // The call was not placed. Free the lock and try again in 30 minutes,
        // at most 2 more times. No point when there is no credit.
        delete_transient( $lock_key );
        self::note_problem( $client_id, $type . ' reminder call to ' . ( $vars['name'] ?? 'a customer' ), $result['error'] );

        $try = (int) ( $payload['try'] ?? 0 );
        if ( 'no_credits' !== $result['error'] && $try < 2 ) {
            $payload['try'] = $try + 1;
            wp_schedule_single_event( time() + 1800, 'ofp_send_voice_reminder', [ $payload ] );
        }
    }
}
