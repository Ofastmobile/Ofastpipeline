<?php
/**
 * OFP_Chat_Telegram
 *
 * The staff side of live support. Visitors never see Telegram: their messages
 * arrive in the admin's Telegram chat, and the admin's replies are written back
 * into the website chat. Everything Telegram-related lives in this class and in
 * the admin settings screen.
 *
 * Admin experience
 * ----------------
 *  - Every visitor message arrives tagged with a ticket number, e.g. "#C12".
 *  - Swipe-reply to that message to answer. If the reply link is ever lost, the
 *    "#C12" tag in the quoted text is used instead.
 *  - Commands: /r 12 text (reply), /close 12, /list, /help.
 *
 * Security
 * --------
 *  - setWebhook registers a secret token. Telegram sends it back on every call
 *    and we reject anything without it, so nobody can post fake agent replies.
 *  - Only messages from the configured chat ID are accepted.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Chat_Telegram {

    const SECRET_OPTION = 'ofp_chat_tg_webhook_secret';
    const ME_TRANSIENT  = 'ofp_chat_tg_me';
    const MODE_OPTION   = 'ofp_chat_tg_mode';
    const OFFSET_OPTION = 'ofp_chat_tg_offset';
    const PULL_STATE_OPTION = 'ofp_chat_tg_pull_state';

    public static function is_configured(): bool {
        $cfg = OFP_Chat_Service::get_config();
        return OFP_Chat_Service::secret( 'telegram_bot_token' ) !== '' && '' !== $cfg['telegram_chat_id'];
    }

    // ── Sending to the admin ───────────────────────────────────────────

    /**
     * Forward a visitor message to the admin chat.
     *
     * @return int|null Telegram message ID, or null when delivery failed.
     */
    public static function notify_agent( int $conv_id, array $conv, string $message, bool $is_new_ticket ): ?int {
        $who = $conv['user_name'] ?: 'Visitor';
        $kind = [ 'client' => 'Client', 'admin' => 'Staff', 'guest' => 'Guest' ][ $conv['user_type'] ] ?? 'Visitor';

        $lines = [];
        if ( $is_new_ticket ) {
            $lines[] = '🆕 <b>New live chat</b> · <b>#C' . $conv_id . '</b>';
            $lines[] = '👤 ' . self::h( $who ) . ' <i>(' . $kind . ')</i>';
            if ( ! empty( $conv['user_email'] ) ) {
                $lines[] = '📧 ' . self::h( $conv['user_email'] );
            }
            if ( ! empty( $conv['user_id'] ) ) {
                $lines[] = '🔖 Account ID: ' . (int) $conv['user_id'];
            }
            $lines[] = '━━━━━━━━━━━━';
        } else {
            $lines[] = '💬 <b>#C' . $conv_id . '</b> · ' . self::h( $who );
        }
        $lines[] = self::h( $message );
        if ( $is_new_ticket ) {
            $lines[] = '';
            $lines[] = '↩️ <i>Swipe-reply to this message to answer. /help for commands.</i>';
        }

        $res = self::api( 'sendMessage', [
            'chat_id'    => OFP_Chat_Service::get_config()['telegram_chat_id'],
            'text'       => implode( "\n", $lines ),
            'parse_mode' => 'HTML',
        ] );

        return ( $res['ok'] && ! empty( $res['result']['message_id'] ) ) ? (int) $res['result']['message_id'] : null;
    }

    /** Plain note to the admin chat (help text, errors, confirmations). */
    public static function say( string $html ): void {
        self::api( 'sendMessage', [
            'chat_id'    => OFP_Chat_Service::get_config()['telegram_chat_id'],
            'text'       => $html,
            'parse_mode' => 'HTML',
        ] );
    }

    // ── Webhook (Telegram -> website) ──────────────────────────────────

    public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        // Prove the call came from Telegram.
        $expected = (string) get_option( self::SECRET_OPTION, '' );
        $given    = (string) $request->get_header( 'x_telegram_bot_api_secret_token' );
        if ( '' === $expected || ! hash_equals( $expected, $given ) ) {
            return new WP_REST_Response( [ 'ok' => false ], 403 );
        }

        $update = $request->get_json_params();
        if ( is_array( $update ) ) {
            self::process_update( $update );
        }
        return new WP_REST_Response( [ 'ok' => true ], 200 ); // Always 200, or Telegram keeps retrying.
    }

    /** Handle one Telegram update, whether it arrived by webhook or was pulled. */
    private static function process_update( array $update ): void {
        // Telegram can deliver the same update twice, so ignore ones we already handled.
        $update_id = (int) ( $update['update_id'] ?? 0 );
        if ( $update_id > 0 ) {
            $dedupe = 'ofp_chat_tg_upd_' . $update_id;
            if ( false !== get_transient( $dedupe ) ) {
                return;
            }
            set_transient( $dedupe, 1, HOUR_IN_SECONDS );
        }

        $msg = $update['message'] ?? null;
        if ( ! is_array( $msg ) ) {
            return;
        }

        // Only the configured admin chat may act as an agent.
        $configured = OFP_Chat_Service::get_config()['telegram_chat_id'];
        if ( '' === $configured || (string) ( $msg['chat']['id'] ?? '' ) !== (string) $configured ) {
            return;
        }

        $text = trim( (string) ( $msg['text'] ?? '' ) );

        if ( '' !== $text && '/' === $text[0] ) {
            self::handle_command( $text );
            return;
        }

        // A swipe-reply to a ticket message.
        if ( ! empty( $msg['reply_to_message'] ) ) {
            if ( '' === $text ) {
                self::say( '⚠️ Only text replies reach the website chat for now.' );
                return;
            }
            $conv_id = self::resolve_conversation( $msg['reply_to_message'] );
            if ( $conv_id ) {
                self::deliver_reply( $conv_id, $text, (int) ( $msg['message_id'] ?? 0 ) );
            } else {
                self::say( "⚠️ I couldn't tell which chat that belongs to. Reply to a ticket message, or use <code>/r 12 your message</code>." );
            }
            return;
        }

        // A loose message with no reply.
        if ( '' !== $text ) {
            self::say( '↩️ Swipe-reply to a ticket message, or use <code>/r 12 your message</code>. /help lists commands.' );
        }
    }

    // ── Pull mode (no inbound traffic needed) ──────────────────────────

    /** 'webhook' (Telegram calls the site) or 'pull' (the site asks Telegram). */
    public static function mode(): string {
        return 'pull' === get_option( self::MODE_OPTION, 'webhook' ) ? 'pull' : 'webhook';
    }

    /**
     * Called while a visitor is waiting in a human chat. Fetches new Telegram
     * messages at most once every 3 seconds across all visitors.
     */
    public static function maybe_pull(): void {
        if ( 'pull' !== self::mode() || ! self::is_configured() ) {
            return;
        }
        if ( false !== get_transient( 'ofp_chat_tg_pull_lock' ) ) {
            return;
        }
        set_transient( 'ofp_chat_tg_pull_lock', 1, 3 );
        self::pull_updates();
    }

    /** Ask Telegram for new messages and handle them. Also runs from WP-Cron as a backstop. */
    public static function pull_updates(): void {
        if ( 'pull' !== self::mode() || ! self::is_configured() ) {
            return;
        }

        $params = [ 'timeout' => 0, 'limit' => 50, 'allowed_updates' => [ 'message' ] ];
        $offset = (int) get_option( self::OFFSET_OPTION, 0 );
        if ( $offset > 0 ) {
            $params['offset'] = $offset;
        }

        $res   = self::api( 'getUpdates', $params, 6 );
        $state = [ 'time' => time(), 'ok' => $res['ok'], 'error' => $res['description'] ];

        if ( $res['ok'] ) {
            $max = 0;
            foreach ( (array) $res['result'] as $update ) {
                if ( ! is_array( $update ) ) {
                    continue;
                }
                $max = max( $max, (int) ( $update['update_id'] ?? 0 ) );
                self::process_update( $update );
            }
            if ( $max > 0 ) {
                update_option( self::OFFSET_OPTION, $max + 1, false ); // Tells Telegram these are done.
            }
        } else {
            set_transient( 'ofp_chat_tg_pull_lock', 1, 30 ); // Back off while Telegram is unreachable.
        }

        // Record health for the admin screen, without a database write on every poll.
        $prev = get_option( self::PULL_STATE_OPTION, [] );
        if ( ! is_array( $prev ) || ( $prev['ok'] ?? null ) !== $state['ok'] || ( $prev['error'] ?? '' ) !== $state['error'] || time() - (int) ( $prev['time'] ?? 0 ) > 60 ) {
            update_option( self::PULL_STATE_OPTION, $state, false );
        }
    }

    /** Switch to pull mode. Works behind Cloudflare because Telegram never calls the site. */
    public static function connect_pull(): array {
        if ( OFP_Chat_Service::secret( 'telegram_bot_token' ) === '' ) {
            return [ 'ok' => false, 'message' => 'Save a bot token first.' ];
        }

        // Telegram refuses to hand out messages while a webhook is registered.
        $res = self::api( 'deleteWebhook', [ 'drop_pending_updates' => false ] );
        if ( ! $res['ok'] ) {
            return [ 'ok' => false, 'message' => $res['description'] ];
        }

        update_option( self::MODE_OPTION, 'pull', false );
        self::register_commands();
        self::bot_username( true );

        if ( ! wp_next_scheduled( 'ofp_chat_tg_pull' ) ) {
            wp_schedule_event( time() + 60, 'ofp_two_minutes', 'ofp_chat_tg_pull' );
        }
        return [ 'ok' => true, 'message' => 'Pull mode is on. Replies are fetched while a visitor is in a live chat. No webhook or Cloudflare rule is needed.' ];
    }

    private static function register_commands(): void {
        self::api( 'setMyCommands', [ 'commands' => [
            [ 'command' => 'list',  'description' => 'Open live chats' ],
            [ 'command' => 'r',     'description' => 'Reply: /r 12 your message' ],
            [ 'command' => 'close', 'description' => 'Close a chat: /close 12' ],
            [ 'command' => 'help',  'description' => 'How replies work' ],
        ] ] );
    }

    /** Work out which conversation a replied-to Telegram message belongs to. */
    private static function resolve_conversation( array $replied ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ofp_chat_messages';

        $tg_id = (int) ( $replied['message_id'] ?? 0 );
        if ( $tg_id > 0 ) {
            $found = $wpdb->get_var( $wpdb->prepare( "SELECT conversation_id FROM {$table} WHERE telegram_message_id = %d LIMIT 1", $tg_id ) );
            if ( $found ) {
                return (int) $found;
            }
        }

        // Fallback: the "#C12" tag in the quoted text.
        $quoted = (string) ( $replied['text'] ?? '' );
        if ( preg_match( '/#C(\d+)/', $quoted, $m ) ) {
            $id    = (int) $m[1];
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ofp_chat_conversations WHERE id = %d", $id ) );
            if ( $exists ) {
                return $id;
            }
        }
        return 0;
    }

    private static function handle_command( string $text ): void {
        global $wpdb;

        // "/r@MyBot 12 hello" in groups: drop the @botname part.
        $parts = preg_split( '/\s+/', $text, 3 );
        $cmd   = strtolower( preg_replace( '/@\w+$/', '', $parts[0] ) );

        switch ( $cmd ) {
            case '/r':
            case '/reply':
                $id   = (int) ltrim( (string) ( $parts[1] ?? '' ), '#Cc' );
                $body = trim( (string) ( $parts[2] ?? '' ) );
                if ( $id <= 0 || '' === $body ) {
                    self::say( 'Usage: <code>/r 12 your message</code>' );
                    return;
                }
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ofp_chat_conversations WHERE id = %d", $id ) );
                if ( ! $exists ) {
                    self::say( '⚠️ No chat #C' . $id . ' found.' );
                    return;
                }
                self::deliver_reply( $id, $body, 0 );
                return;

            case '/close':
                $id = (int) ltrim( (string) ( $parts[1] ?? '' ), '#Cc' );
                if ( $id <= 0 ) {
                    self::say( 'Usage: <code>/close 12</code>' );
                    return;
                }
                $id = OFP_Chat_Service::canonical_conversation_id( $id );
                OFP_Chat_Service::set_status( $id, 'closed' );
                OFP_Chat_Service::add_message( $id, 'system', 'closed' );
                self::say( '✅ Chat #C' . $id . ' closed.' );
                return;

            case '/list':
                $rows = $wpdb->get_results(
                    "SELECT id, user_name, status FROM {$wpdb->prefix}ofp_chat_conversations
                     WHERE mode = 'human' AND status IN ('waiting','active')
                     ORDER BY updated_at DESC LIMIT 10"
                );
                if ( empty( $rows ) ) {
                    self::say( 'No open live chats right now.' );
                    return;
                }
                $out = [ '<b>Open live chats</b>' ];
                foreach ( $rows as $r ) {
                    $out[] = ( 'waiting' === $r->status ? '🟡' : '🟢' ) . ' #C' . (int) $r->id . ' · ' . self::h( $r->user_name ?: 'Visitor' );
                }
                $out[] = '';
                $out[] = '🟡 waiting for you · 🟢 you replied last';
                self::say( implode( "\n", $out ) );
                return;

            case '/start':
            case '/help':
            default:
                self::say(
                    "<b>Live chat help</b>\n" .
                    "↩️ Swipe-reply to a ticket message to answer it.\n" .
                    "<code>/r 12 text</code> reply to chat #C12\n" .
                    "<code>/close 12</code> close chat #C12\n" .
                    "<code>/list</code> open chats"
                );
        }
    }

    /** Write an agent reply into the website chat. */
    private static function deliver_reply( int $conv_id, string $text, int $tg_message_id ): void {
        $text = mb_substr( sanitize_textarea_field( $text ), 0, 2000 );
        if ( '' === $text ) {
            return;
        }
        $conv_id = OFP_Chat_Service::canonical_conversation_id( $conv_id );
        OFP_Chat_Service::add_message( $conv_id, 'admin', $text, $tg_message_id > 0 ? $tg_message_id : null );
        OFP_Chat_Service::mark_agent_replied( $conv_id );
    }

    // ── Admin screen helpers ───────────────────────────────────────────

    /** Register the webhook with Telegram. @return array { ok: bool, message: string } */
    public static function connect_webhook(): array {
        if ( OFP_Chat_Service::secret( 'telegram_bot_token' ) === '' ) {
            return [ 'ok' => false, 'message' => 'Save a bot token first.' ];
        }

        $url = rest_url( 'ofast/v1/chat/telegram-webhook' );
        if ( 0 !== strpos( $url, 'https://' ) ) {
            return [ 'ok' => false, 'message' => 'Telegram only talks to public HTTPS addresses. This site is on ' . $url ];
        }

        $secret = (string) get_option( self::SECRET_OPTION, '' );
        if ( '' === $secret ) {
            $secret = wp_generate_password( 40, false, false );
            update_option( self::SECRET_OPTION, $secret, false );
        }

        $res = self::api( 'setWebhook', [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => [ 'message' ],
            'max_connections' => 10,
        ] );
        if ( ! $res['ok'] ) {
            return [ 'ok' => false, 'message' => $res['description'] ];
        }

        update_option( self::MODE_OPTION, 'webhook', false );
        wp_clear_scheduled_hook( 'ofp_chat_tg_pull' );
        self::register_commands();
        self::bot_username( true );

        return [ 'ok' => true, 'message' => 'Webhook connected: ' . $url ];
    }

    /** Ask Telegram what it knows about our webhook. @return array { ok: bool, message: string } */
    public static function webhook_status(): array {
        $res = self::api( 'getWebhookInfo', [] );
        if ( ! $res['ok'] ) {
            return [ 'ok' => false, 'message' => $res['description'] ];
        }

        if ( 'pull' === self::mode() ) {
            if ( '' !== (string) ( $res['result']['url'] ?? '' ) ) {
                return [ 'ok' => false, 'message' => 'Pull mode is on but a webhook is still registered, which blocks it. Click "Use pull mode" again.' ];
            }
            $state = get_option( self::PULL_STATE_OPTION, [] );
            if ( empty( $state['time'] ) ) {
                return [ 'ok' => true, 'message' => 'Pull mode is on. No check has run yet; it runs when a visitor is in a live chat.' ];
            }
            $ago = human_time_diff( (int) $state['time'] ) . ' ago';
            return ! empty( $state['ok'] )
                ? [ 'ok' => true, 'message' => 'Pull mode healthy. Last check ' . $ago . '.' ]
                : [ 'ok' => false, 'message' => 'Pull mode could not reach Telegram (' . $ago . '): ' . ( $state['error'] ?? 'unknown error' ) ];
        }

        $info     = $res['result'];
        $expected = rest_url( 'ofast/v1/chat/telegram-webhook' );
        $url      = (string) ( $info['url'] ?? '' );

        if ( '' === $url ) {
            return [ 'ok' => false, 'message' => 'No webhook is registered, so agent replies cannot reach the website. Click "Connect Webhook".' ];
        }
        if ( $url !== $expected ) {
            return [ 'ok' => false, 'message' => 'Webhook points to a different address (' . $url . '). Click "Connect Webhook" to fix it.' ];
        }
        if ( '' === (string) get_option( self::SECRET_OPTION, '' ) ) {
            return [ 'ok' => false, 'message' => 'Webhook is registered without a secret. Click "Connect Webhook" to secure it.' ];
        }

        $pending = (int) ( $info['pending_update_count'] ?? 0 );
        $err     = (string) ( $info['last_error_message'] ?? '' );
        if ( '' !== $err ) {
            $when = ! empty( $info['last_error_date'] ) ? ' at ' . wp_date( 'M j, g:i a', (int) $info['last_error_date'] ) : '';
            return [ 'ok' => false, 'message' => 'Telegram cannot reach this site' . $when . ': ' . $err . ' (' . $pending . ' waiting). Check that the site is public, HTTPS is valid, and no firewall or security plugin blocks /wp-json/.' ];
        }
        return [ 'ok' => true, 'message' => 'Webhook healthy. ' . $pending . ' update(s) waiting.' ];
    }

    /** Send a test note to the admin chat. @return array { ok: bool, message: string } */
    public static function send_test(): array {
        if ( ! self::is_configured() ) {
            return [ 'ok' => false, 'message' => 'Bot token or chat ID is missing.' ];
        }
        $res = self::api( 'sendMessage', [
            'chat_id'    => OFP_Chat_Service::get_config()['telegram_chat_id'],
            'text'       => "✅ <b>Live chat is connected.</b>\nVisitor messages will arrive here. Swipe-reply to answer. /help for commands.",
            'parse_mode' => 'HTML',
        ] );
        self::bot_username( true );
        return [ 'ok' => $res['ok'], 'message' => $res['ok'] ? 'Test message delivered.' : $res['description'] ];
    }

    /** Bot username for the admin screen. Cached; only calls Telegram when asked to refresh. */
    public static function bot_username( bool $refresh = false ): string {
        $cached = get_transient( self::ME_TRANSIENT );
        if ( ! $refresh ) {
            return is_string( $cached ) ? $cached : '';
        }
        $res  = self::api( 'getMe', [] );
        $name = $res['ok'] ? (string) ( $res['result']['username'] ?? '' ) : '';
        if ( '' !== $name ) {
            set_transient( self::ME_TRANSIENT, $name, DAY_IN_SECONDS );
        }
        return $name;
    }

    // ── Low-level API call ─────────────────────────────────────────────

    /** @return array { ok: bool, result: mixed, description: string } */
    private static function api( string $method, array $params, int $timeout = 10 ): array {
        $token = OFP_Chat_Service::secret( 'telegram_bot_token' );
        if ( '' === $token ) {
            return [ 'ok' => false, 'result' => null, 'description' => 'Bot token is missing.' ];
        }

        $response = wp_remote_post( 'https://api.telegram.org/bot' . $token . '/' . $method, [
            'timeout' => $timeout,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( (object) $params ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'result' => null, 'description' => 'Could not reach Telegram: ' . $response->get_error_message() ];
        }

        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        $ok   = is_array( $json ) && ! empty( $json['ok'] );

        return [
            'ok'          => $ok,
            'result'      => $ok ? ( $json['result'] ?? null ) : null,
            'description' => $ok ? '' : (string) ( $json['description'] ?? 'Telegram returned HTTP ' . wp_remote_retrieve_response_code( $response ) ),
        ];
    }

    /** Escape text for Telegram's HTML parse mode (only & < > need it). */
    private static function h( string $s ): string {
        return htmlspecialchars( $s, ENT_NOQUOTES, 'UTF-8' );
    }
}
