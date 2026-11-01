<?php
/**
 * OFP_Chat_AI
 *
 * Talks to the AI providers (Google Gemini, Groq) for the chat widget.
 *
 * Why this class exists
 * ---------------------
 * Free API tiers are small and counted per model (for example 5 requests a
 * minute on some Gemini models). A chat widget that calls one model once and
 * gives up will fail after a handful of messages. This class instead:
 *
 *  1. Builds a chain of models to try (primary Gemini model, fallback Gemini
 *     model, then Groq if a key is saved).
 *  2. Skips any model that is cooling down after a 429, so visitors do not
 *     wait on a model we already know is out of quota.
 *  3. Retries once on short-lived server errors (500/503/timeout).
 *  4. Returns a structured result so the caller can show a useful message
 *     instead of a generic "trouble connecting" text.
 *
 * Nothing here writes to the chat tables.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Chat_AI {

    /** Reasoning models spend output tokens on thinking, so leave room. */
    const MAX_OUTPUT_TOKENS = 1024;

    /** Whole-reply time budget in seconds (all attempts together). */
    const TIME_BUDGET = 24;

    /** Option that remembers the last AI failure, shown in admin settings. */
    const LAST_ERROR_OPTION = 'ofp_chat_last_ai_error';

    /**
     * Get an AI reply.
     *
     * @param string $system_prompt System instructions.
     * @param array  $history       Earlier turns: [ [ 'role' => 'user'|'assistant', 'content' => '...' ], ... ]
     * @param string $user_message  The new visitor message.
     * @return array {
     *     ok: bool, text: string, provider: string, model: string,
     *     code: string, message: string, retry_after: int
     * }
     */
    public static function reply( string $system_prompt, array $history, string $user_message ): array {
        $chain = self::build_chain();
        if ( empty( $chain ) ) {
            return self::failure( 'not_configured', 'No AI provider has an API key saved.', '', '' );
        }

        $turns   = self::normalize_turns( $history, $user_message );
        $started = microtime( true );
        $last    = self::failure( 'unavailable', 'No model could be reached.', '', '' );
        $soonest = 0;

        foreach ( $chain as $step ) {
            $remaining = self::TIME_BUDGET - ( microtime( true ) - $started );
            if ( $remaining < 3 ) {
                break;
            }

            $cooldown = self::cooldown_left( $step );
            if ( $cooldown > 0 ) {
                $soonest = $soonest > 0 ? min( $soonest, $cooldown ) : $cooldown;
                $last    = self::failure( 'rate_limited', 'Model is cooling down after a quota error.', $step['provider'], $step['model'], $cooldown );
                continue;
            }

            $result = self::call_step( $step, $system_prompt, $turns, (int) min( 15, $remaining ) );

            // One quick retry for short-lived server problems (not for quota or auth errors).
            if ( ! $result['ok'] && 'unavailable' === $result['code'] && ( self::TIME_BUDGET - ( microtime( true ) - $started ) ) > 6 ) {
                usleep( 700000 );
                $result = self::call_step( $step, $system_prompt, $turns, (int) min( 12, self::TIME_BUDGET - ( microtime( true ) - $started ) ) );
            }

            if ( $result['ok'] ) {
                return $result;
            }

            self::handle_failure( $step, $result );
            $last = $result;
            if ( 'rate_limited' === $result['code'] && $result['retry_after'] > 0 ) {
                $soonest = $soonest > 0 ? min( $soonest, $result['retry_after'] ) : $result['retry_after'];
            }
        }

        if ( 'rate_limited' === $last['code'] && $soonest > 0 ) {
            $last['retry_after'] = $soonest;
        }
        return $last;
    }

    /**
     * Admin test: call every configured model once, ignoring cooldowns.
     *
     * @return array[] One entry per model: provider, model, ok, ms, message.
     */
    public static function probe_all(): array {
        $out = [];
        foreach ( self::build_chain() as $step ) {
            $t   = microtime( true );
            $res = self::call_step(
                $step,
                'You are a connectivity test. Reply with exactly: OK',
                [ [ 'role' => 'user', 'content' => 'ping' ] ],
                15
            );
            $out[] = [
                'provider' => $step['provider'],
                'model'    => $step['model'],
                'ok'       => $res['ok'],
                'ms'       => (int) round( ( microtime( true ) - $t ) * 1000 ),
                'message'  => $res['ok'] ? trim( $res['text'] ) : $res['message'],
            ];
            if ( $res['ok'] ) {
                self::clear_cooldown( $step );
            }
        }
        return $out;
    }

    /** Last recorded AI failure, or empty array. */
    public static function last_error(): array {
        $v = get_option( self::LAST_ERROR_OPTION, [] );
        return is_array( $v ) ? $v : [];
    }

    // ── Chain building ─────────────────────────────────────────────────

    /** @return array[] Each: [ 'provider' => 'gemini'|'groq', 'model' => string ] */
    private static function build_chain(): array {
        $cfg = OFP_Chat_Service::get_config();

        $gemini = [];
        if ( OFP_Chat_Service::secret( 'gemini_api_key' ) !== '' ) {
            foreach ( [ $cfg['gemini_model'], $cfg['gemini_fallback_model'] ] as $model ) {
                if ( $model && ! in_array( $model, array_column( $gemini, 'model' ), true ) ) {
                    $gemini[] = [ 'provider' => 'gemini', 'model' => $model ];
                }
            }
        }

        $groq = [];
        if ( OFP_Chat_Service::secret( 'groq_api_key' ) !== '' && ! empty( $cfg['groq_model'] ) ) {
            $groq[] = [ 'provider' => 'groq', 'model' => $cfg['groq_model'] ];
        }

        return 'groq' === $cfg['ai_provider'] ? array_merge( $groq, $gemini ) : array_merge( $gemini, $groq );
    }

    /**
     * Provider-neutral turns, cleaned so every provider accepts them:
     * starts with a user turn, no two turns in a row from the same role,
     * ends with the new user message.
     */
    private static function normalize_turns( array $history, string $user_message ): array {
        $history[] = [ 'role' => 'user', 'content' => $user_message ];

        $turns = [];
        foreach ( $history as $turn ) {
            $role = ( $turn['role'] ?? '' ) === 'assistant' ? 'assistant' : 'user';
            $text = trim( (string) ( $turn['content'] ?? '' ) );
            if ( '' === $text ) {
                continue;
            }
            if ( empty( $turns ) && 'assistant' === $role ) {
                continue; // Must start with the visitor.
            }
            $last = count( $turns ) - 1;
            if ( $last >= 0 && $turns[ $last ]['role'] === $role ) {
                $turns[ $last ]['content'] .= "\n" . $text;
            } else {
                $turns[] = [ 'role' => $role, 'content' => $text ];
            }
        }
        return $turns;
    }

    // ── Provider calls ─────────────────────────────────────────────────

    private static function call_step( array $step, string $system_prompt, array $turns, int $timeout ): array {
        $timeout = max( 4, $timeout );
        return 'groq' === $step['provider']
            ? self::call_groq( $step['model'], $system_prompt, $turns, $timeout )
            : self::call_gemini( $step['model'], $system_prompt, $turns, $timeout );
    }

    private static function call_gemini( string $model, string $system_prompt, array $turns, int $timeout ): array {
        $key = OFP_Chat_Service::secret( 'gemini_api_key' );
        if ( '' === $key ) {
            return self::failure( 'not_configured', 'Gemini API key is missing.', 'gemini', $model );
        }

        $contents = [];
        foreach ( $turns as $turn ) {
            $contents[] = [
                'role'  => 'assistant' === $turn['role'] ? 'model' : 'user',
                'parts' => [ [ 'text' => $turn['content'] ] ],
            ];
        }

        $response = wp_remote_post(
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
            [
                'timeout' => $timeout,
                'headers' => [
                    'Content-Type'   => 'application/json',
                    'x-goog-api-key' => $key, // Header, not URL, so the key never lands in server logs.
                ],
                'body'    => wp_json_encode( [
                    'system_instruction' => [ 'parts' => [ [ 'text' => $system_prompt ] ] ],
                    'contents'           => $contents,
                    'generationConfig'   => [
                        'temperature'     => 0.4,
                        'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
                    ],
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return self::failure( 'unavailable', 'Network error: ' . $response->get_error_message(), 'gemini', $model );
        }

        $http = (int) wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        $json = is_array( $json ) ? $json : [];

        if ( 200 === $http ) {
            if ( ! empty( $json['promptFeedback']['blockReason'] ) ) {
                return self::failure( 'blocked', 'Prompt blocked: ' . $json['promptFeedback']['blockReason'], 'gemini', $model );
            }

            // Join every visible text part. Skip "thought" parts some models return.
            $text = '';
            foreach ( (array) ( $json['candidates'][0]['content']['parts'] ?? [] ) as $part ) {
                if ( ! empty( $part['thought'] ) || ! isset( $part['text'] ) ) {
                    continue;
                }
                $text .= $part['text'];
            }
            $text = trim( $text );

            if ( '' === $text ) {
                $why = $json['candidates'][0]['finishReason'] ?? 'no text returned';
                return self::failure( 'unavailable', 'Empty reply (' . $why . ').', 'gemini', $model );
            }
            return self::success( $text, 'gemini', $model );
        }

        $detail = (string) ( $json['error']['message'] ?? 'HTTP ' . $http );
        return self::http_failure( $http, $detail, 'gemini', $model, self::gemini_retry_seconds( $json, $detail ), self::gemini_is_daily_quota( $json ) );
    }

    private static function call_groq( string $model, string $system_prompt, array $turns, int $timeout ): array {
        $key = OFP_Chat_Service::secret( 'groq_api_key' );
        if ( '' === $key ) {
            return self::failure( 'not_configured', 'Groq API key is missing.', 'groq', $model );
        }

        $messages = [ [ 'role' => 'system', 'content' => $system_prompt ] ];
        foreach ( $turns as $turn ) {
            $messages[] = [ 'role' => $turn['role'], 'content' => $turn['content'] ];
        }

        $response = wp_remote_post( 'https://api.groq.com/openai/v1/chat/completions', [
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'       => $model,
                'temperature' => 0.4,
                'max_tokens'  => self::MAX_OUTPUT_TOKENS,
                'messages'    => $messages,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return self::failure( 'unavailable', 'Network error: ' . $response->get_error_message(), 'groq', $model );
        }

        $http = (int) wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        $json = is_array( $json ) ? $json : [];

        if ( 200 === $http ) {
            $text = trim( (string) ( $json['choices'][0]['message']['content'] ?? '' ) );
            return '' === $text
                ? self::failure( 'unavailable', 'Empty reply from Groq.', 'groq', $model )
                : self::success( $text, 'groq', $model );
        }

        $detail = (string) ( $json['error']['message'] ?? 'HTTP ' . $http );
        $retry  = (int) wp_remote_retrieve_header( $response, 'retry-after' );
        return self::http_failure( $http, $detail, 'groq', $model, $retry, false );
    }

    // ── Error handling ─────────────────────────────────────────────────

    /** Map an HTTP error to one of our result codes. */
    private static function http_failure( int $http, string $detail, string $provider, string $model, int $retry_after, bool $daily ): array {
        if ( 429 === $http ) {
            // Per-minute limits clear fast. Per-day limits will not, so check back later.
            $wait = $daily ? 900 : ( $retry_after > 0 ? $retry_after : 60 );
            $wait = max( 10, min( 3600, $wait ) );
            return self::failure( 'rate_limited', $detail, $provider, $model, $wait );
        }
        if ( in_array( $http, [ 401, 403 ], true ) || ( 400 === $http && false !== stripos( $detail, 'api key' ) ) ) {
            return self::failure( 'auth', $detail, $provider, $model, 600 );
        }
        if ( 404 === $http ) {
            return self::failure( 'model_not_found', $detail, $provider, $model, 600 );
        }
        if ( $http >= 500 ) {
            return self::failure( 'unavailable', $detail, $provider, $model );
        }
        return self::failure( 'bad_request', $detail, $provider, $model );
    }

    /** Remember a failing model so the next visitor is not made to wait on it. */
    private static function handle_failure( array $step, array $result ): void {
        if ( in_array( $result['code'], [ 'rate_limited', 'auth', 'model_not_found' ], true ) && $result['retry_after'] > 0 ) {
            set_transient( self::cooldown_key( $step ), time() + $result['retry_after'], $result['retry_after'] );
        }

        // Keep the last problem for the admin screen (at most one write per 30 seconds).
        if ( false === get_transient( 'ofp_chat_err_throttle' ) ) {
            set_transient( 'ofp_chat_err_throttle', 1, 30 );
            update_option( self::LAST_ERROR_OPTION, [
                'time'     => time(),
                'provider' => $result['provider'],
                'model'    => $result['model'],
                'code'     => $result['code'],
                'message'  => mb_substr( $result['message'], 0, 300 ),
            ], false );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[OFP chat] %s/%s failed (%s): %s', $result['provider'], $result['model'], $result['code'], $result['message'] ) );
        }
    }

    private static function cooldown_key( array $step ): string {
        return 'ofp_chat_cd_' . md5( $step['provider'] . '|' . $step['model'] );
    }

    private static function cooldown_left( array $step ): int {
        $until = (int) get_transient( self::cooldown_key( $step ) );
        return max( 0, $until - time() );
    }

    private static function clear_cooldown( array $step ): void {
        delete_transient( self::cooldown_key( $step ) );
    }

    /** Seconds Google asks us to wait, from RetryInfo or the message text. */
    private static function gemini_retry_seconds( array $json, string $detail ): int {
        foreach ( (array) ( $json['error']['details'] ?? [] ) as $d ) {
            if ( ! empty( $d['retryDelay'] ) ) {
                return (int) ceil( (float) $d['retryDelay'] ); // e.g. "46s"
            }
        }
        if ( preg_match( '/retry in ([\d.]+)s/i', $detail, $m ) ) {
            return (int) ceil( (float) $m[1] );
        }
        return 0;
    }

    /** True when the exhausted quota is a per-day one. */
    private static function gemini_is_daily_quota( array $json ): bool {
        return false !== strpos( wp_json_encode( $json['error']['details'] ?? [] ), 'PerDay' );
    }

    // ── Result helpers ─────────────────────────────────────────────────

    private static function success( string $text, string $provider, string $model ): array {
        return [
            'ok' => true, 'text' => $text, 'provider' => $provider, 'model' => $model,
            'code' => '', 'message' => '', 'retry_after' => 0,
        ];
    }

    private static function failure( string $code, string $message, string $provider, string $model, int $retry_after = 0 ): array {
        return [
            'ok' => false, 'text' => '', 'provider' => $provider, 'model' => $model,
            'code' => $code, 'message' => $message, 'retry_after' => $retry_after,
        ];
    }
}
