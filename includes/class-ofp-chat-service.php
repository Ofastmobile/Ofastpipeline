<?php
/**
 * OFP_Chat_Service
 *
 * The website chat widget: an AI assistant for everyone, plus an optional hand-off
 * to a human agent. Staff answer from Telegram, but visitors never see Telegram;
 * it only appears on the admin settings screen.
 *
 * Responsibilities of this class
 *  - database tables and upgrades
 *  - settings and (encrypted) secrets
 *  - widget markup and assets
 *  - REST endpoints used by the widget
 *  - admin test/connect buttons
 *
 * Related classes
 *  - OFP_Chat_AI        Gemini / Groq calls, retry, fallback, cooldowns
 *  - OFP_Chat_Telegram  staff side: notifications, webhook, commands
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Chat_Service {

    const DB_VERSION         = '2.1.0';
    const SESSION_PATTERN    = '/^[A-Za-z0-9_-]{16,64}$/';
    const MAX_MESSAGE_LENGTH = 500;
    const SETTINGS_URL       = 'admin.php?page=ofp-settings&tab=tab-ai-chat';

    /** Models that no longer exist or are being retired. Replaced once on upgrade. */
    const RETIRED_MODELS = [
        'gemini-2.0-flash', 'gemini-2.0-flash-001', 'gemini-2.0-flash-lite',
        'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro',
        'gemini-3.5-flash', 'gemini-3.7-flash',
    ];

    public static function init(): void {
        self::maybe_upgrade();

        add_action( 'wp_footer', [ __CLASS__, 'render_widget_markup' ], 999 );
        add_action( 'admin_footer', [ __CLASS__, 'render_widget_markup' ], 999 );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );

        // Buttons on the admin settings screen. Saving settings is handled by the main settings form.
        add_action( 'admin_post_ofp_test_chat_ai', [ __CLASS__, 'handle_test_ai' ] );
        add_action( 'admin_post_ofp_test_chat_telegram', [ __CLASS__, 'handle_test_telegram' ] );
        add_action( 'admin_post_ofp_set_telegram_webhook', [ __CLASS__, 'handle_set_webhook' ] );
        add_action( 'admin_post_ofp_check_telegram_webhook', [ __CLASS__, 'handle_check_webhook' ] );
        add_action( 'admin_post_ofp_set_telegram_pull', [ __CLASS__, 'handle_set_pull' ] );
        add_action( 'ofp_chat_tg_pull', [ 'OFP_Chat_Telegram', 'pull_updates' ] ); // WP-Cron backstop for pull mode.
    }

    // ═══════════════════════════════════════════════════════════════════
    // Database
    // ═══════════════════════════════════════════════════════════════════

    /** Create or upgrade the tables when the schema version changes. */
    public static function maybe_upgrade(): void {
        global $wpdb;

        $installed = (string) get_option( 'ofp_chat_db_version', '' );
        if ( self::DB_VERSION === $installed ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $conv    = $wpdb->prefix . 'ofp_chat_conversations';
        $msgs    = $wpdb->prefix . 'ofp_chat_messages';

        dbDelta( "CREATE TABLE {$conv} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            user_type varchar(20) NOT NULL DEFAULT 'guest',
            user_id bigint(20) unsigned DEFAULT 0,
            user_name varchar(150) DEFAULT '',
            user_email varchar(150) DEFAULT '',
            mode varchar(20) NOT NULL DEFAULT 'ai',
            status varchar(20) NOT NULL DEFAULT 'active',
            telegram_chat_id varchar(64) DEFAULT '',
            last_agent_reply_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_id (session_id),
            KEY mode_status (mode,status,updated_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$msgs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) unsigned NOT NULL,
            sender varchar(20) NOT NULL,
            message text NOT NULL,
            telegram_message_id bigint(20) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY conv_msg (conversation_id,id),
            KEY telegram_message_id (telegram_message_id)
        ) {$charset};" );

        // One-time clean-up when coming from an older version.
        if ( '' !== $installed ) {
            // Earlier versions made one ticket per browser. Close the older duplicates of each account so /list stays tidy.
            $wpdb->query(
                "UPDATE {$conv} c
                 JOIN ( SELECT user_type, user_id, MAX(id) AS keep_id FROM {$conv} WHERE user_id > 0 GROUP BY user_type, user_id ) k
                   ON c.user_type = k.user_type AND c.user_id = k.user_id AND c.id <> k.keep_id
                 SET c.status = 'closed'
                 WHERE c.status <> 'closed'"
            );

            foreach ( [ 'ofp_chat_gemini_model' ] as $opt ) {
                if ( in_array( (string) get_option( $opt, '' ), self::RETIRED_MODELS, true ) ) {
                    delete_option( $opt ); // Falls back to the current default.
                }
            }
        }

        update_option( 'ofp_chat_db_version', self::DB_VERSION, false );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Settings and secrets
    // ═══════════════════════════════════════════════════════════════════

    /** Non-secret settings, with defaults. Secrets come from secret(). */
    public static function get_config(): array {
        static $cfg = null;
        if ( null !== $cfg ) {
            return $cfg;
        }

        $provider = (string) get_option( 'ofp_chat_ai_provider', 'gemini' );

        $cfg = [
            'enabled'               => (string) get_option( 'ofp_chat_enabled', '1' ),
            'guest_enabled'         => (string) get_option( 'ofp_chat_guest_enabled', '1' ),
            'guest_human'           => (string) get_option( 'ofp_chat_guest_human', '0' ),
            'ai_provider'           => in_array( $provider, [ 'gemini', 'groq' ], true ) ? $provider : 'gemini',
            'gemini_model'          => self::text_option( 'ofp_chat_gemini_model', 'gemini-3.5-flash-lite' ),
            'gemini_fallback_model' => self::text_option( 'ofp_chat_gemini_fallback_model', 'gemini-3.1-flash-lite' ),
            'groq_model'            => self::text_option( 'ofp_chat_groq_model', 'llama-3.3-70b-versatile' ),
            'telegram_chat_id'      => trim( (string) get_option( 'ofp_chat_telegram_chat_id', '' ) ),
            'handoff_message'       => self::text_option( 'ofp_chat_handoff_message', self::default_handoff_message() ),
            'guest_prompt'          => self::clean_prompt( (string) get_option( 'ofp_chat_guest_prompt', '' ) ?: self::default_guest_prompt() ),
            'user_prompt'           => self::clean_prompt( (string) get_option( 'ofp_chat_user_prompt', '' ) ?: self::default_user_prompt() ),
            // Flags only; the secrets themselves are never put in this array.
            'has_gemini_key'        => '' !== (string) get_option( 'ofp_chat_gemini_api_key', '' ),
            'has_groq_key'          => '' !== (string) get_option( 'ofp_chat_groq_api_key', '' ),
            'has_telegram_token'    => '' !== (string) get_option( 'ofp_chat_telegram_bot_token', '' ),
        ];
        return $cfg;
    }

    /**
     * Decrypt a saved secret on demand.
     *
     * @param string $name gemini_api_key | groq_api_key | telegram_bot_token
     */
    public static function secret( string $name ): string {
        static $cache = [];
        $options = [
            'gemini_api_key'     => 'ofp_chat_gemini_api_key',
            'groq_api_key'       => 'ofp_chat_groq_api_key',
            'telegram_bot_token' => 'ofp_chat_telegram_bot_token',
        ];
        if ( ! isset( $options[ $name ] ) ) {
            return '';
        }
        if ( isset( $cache[ $name ] ) ) {
            return $cache[ $name ];
        }

        $raw = (string) get_option( $options[ $name ], '' );
        if ( '' !== $raw && class_exists( 'OFP_Security' ) ) {
            $plain = OFP_Security::decrypt( $raw );
            if ( '' !== $plain ) {
                $raw = $plain;
            } elseif ( 0 === strncmp( $raw, 'v2:', 3 ) ) {
                $raw = ''; // Encrypted value that cannot be read (for example the site keys changed).
            }
        }

        $cache[ $name ] = trim( $raw );
        return $cache[ $name ];
    }

    private static function text_option( string $name, string $default ): string {
        $v = trim( (string) get_option( $name, '' ) );
        return '' !== $v ? $v : $default;
    }

    public static function default_handoff_message(): string {
        return 'Thanks! Our support team has your message and will reply here as soon as they can. You can keep this window open or come back later; your conversation is saved.';
    }

    /**
     * Visitors must never be told about Telegram. Older saved prompts may mention it,
     * so rewrite those mentions when the prompt is read.
     */
    private static function clean_prompt( string $prompt ): string {
        $prompt = preg_replace( '/\s+(?:on|via|through|using)\s+Telegram\b/i', '', $prompt );
        return (string) preg_replace( '/\bTelegram\b/i', 'live chat', (string) $prompt );
    }

    public static function default_guest_prompt(): string {
        $app = class_exists( 'OFP_Brand' ) ? OFP_Brand::name() : 'MyPropertyHQ';
        return "You are the friendly, professional AI Assistant for {$app}.
Your job is to assist website visitors and prospective clients with general enquiries.
Key details about {$app}:
- We are a premier real estate CRM, automated property pipeline, and tenant oversight engine.
- We help property developers, real estate agents, landlords, and investors automate sales, track rent collections, send automated WhatsApp/SMS/Voice reminders, and manage client inquiries.
- Visitors can view property listings on our Marketplace (/marketplace).
- Visitors can create an account by clicking Sign Up (/signup) or log in at /login.
- Pricing plans: Free Starter, Silver (Professional), and Gold (Enterprise).
Rules:
- Be welcoming, polite, and concise (under 3 sentences when possible).
- If the visitor asks about their private account or personal data, advise them: 'Please log into your account dashboard to access your private account features and live support.'
- NEVER reveal internal database structures, code, system files, API keys, or private customer records.";
    }

    public static function default_user_prompt(): string {
        $app = class_exists( 'OFP_Brand' ) ? OFP_Brand::name() : 'MyPropertyHQ';
        return "You are the intelligent Dashboard Copilot for {$app}.
You are currently talking to an authenticated user: {user_name} (Role: {user_role}).
Your job is to guide the user on navigating the dashboard and answering operational how-to questions.
Dashboard Guide:
- /dashboard: Real-time overview of revenue, active pipeline leads, inspections, and quick stats.
- /properties: Add, edit, archive, or publish property listings. Manage units, pricing, amenities, and photos.
- /leads: Manage incoming buyer and tenant inquiries, update stages (Contacted, Inspection, Negotiation, Won/Lost).
- /tenants & /rent-oversight: Manage active lease agreements, rent payment tracking, automated reminders, and payment receipts.
- /credits & /plans-pricing: Wallet top-up and subscription plans.
- /message-templates: Customize automated SMS, email, and voice message follow-up templates.
- /reports: Export activity reports, transaction receipts, and CSV audit logs.
- /settings: Company branding, bank account details for invoice display, and notification preferences.
Live Human Support:
- If the user needs immediate human assistance or has a complex dispute, tell them they can tap \"Talk to a human agent\" to message our support team directly.
Safety & Security:
- You are strictly a guidance assistant. You CANNOT execute raw SQL queries, change passwords, or modify database records.
- Only respond using the context provided.";
    }

    /** Who is chatting: a portal client, a WordPress user, or a guest. */
    public static function get_current_user_context(): array {
        if ( class_exists( 'OFP_Auth' ) && OFP_Auth::is_logged_in() ) {
            $client = OFP_Auth::current_client();
            if ( $client ) {
                return [
                    'is_logged_in' => true,
                    'user_type'    => 'client',
                    'user_id'      => (int) ( $client->id ?? 0 ),
                    'user_name'    => $client->business_name ?: ( $client->name ?? 'Client' ),
                    'user_email'   => $client->email ?? '',
                    'user_role'    => 'Client / Agent',
                ];
            }
        }

        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            return [
                'is_logged_in' => true,
                'user_type'    => 'admin',
                'user_id'      => (int) $user->ID,
                'user_name'    => $user->display_name ?: $user->user_login,
                'user_email'   => $user->user_email,
                'user_role'    => in_array( 'administrator', (array) $user->roles, true ) ? 'Super Admin' : 'Staff',
            ];
        }

        return [
            'is_logged_in' => false,
            'user_type'    => 'guest',
            'user_id'      => 0,
            'user_name'    => 'Guest Visitor',
            'user_email'   => '',
            'user_role'    => 'Guest',
        ];
    }

    /** Can this visitor ask for a human agent? */
    private static function human_allowed( array $ctx ): bool {
        return $ctx['is_logged_in'] || '1' === self::get_config()['guest_human'];
    }

    /** Should the widget appear for this visitor at all? */
    private static function widget_visible( array $ctx ): bool {
        $cfg = self::get_config();
        if ( '1' !== $cfg['enabled'] ) {
            return false;
        }
        return $ctx['is_logged_in'] || '1' === $cfg['guest_enabled'];
    }

    // ═══════════════════════════════════════════════════════════════════
    // Widget: assets and markup
    // ═══════════════════════════════════════════════════════════════════

    public static function enqueue_assets(): void {
        $ctx = self::get_current_user_context();
        if ( ! self::widget_visible( $ctx ) ) {
            return;
        }

        wp_enqueue_style( 'ofp-chat-widget-css', OFP_URL . 'assets/css/ofp-chat-widget.css', [], OFP_VERSION );
        wp_enqueue_script( 'ofp-chat-widget-js', OFP_URL . 'assets/js/ofp-chat-widget.js', [], OFP_VERSION, true );

        // Nothing about Telegram is sent to the browser.
        wp_localize_script( 'ofp-chat-widget-js', 'OFP_CHAT_DATA', [
            'apiUrl'     => esc_url_raw( rest_url( 'ofast/v1/chat' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'isLoggedIn' => $ctx['is_logged_in'],
            'userType'   => $ctx['user_type'],
            'userName'   => $ctx['user_name'],
            'allowHuman' => self::human_allowed( $ctx ),
            'maxLength'  => self::MAX_MESSAGE_LENGTH,
        ] );
    }

    public static function render_widget_markup(): void {
        static $rendered = false;
        if ( $rendered ) {
            return;
        }
        $ctx = self::get_current_user_context();
        if ( ! self::widget_visible( $ctx ) ) {
            return;
        }
        $rendered = true;

        $brand       = class_exists( 'OFP_Brand' ) ? OFP_Brand::name() : 'MyPropertyHQ';
        $allow_human = self::human_allowed( $ctx );
        ?>
        <!-- Live chat widget -->
        <div id="ofp-chat-root" class="ofp-chat-wrapper">
            <button id="ofp-chat-launcher" class="ofp-chat-launcher-btn" type="button" aria-label="Open chat" aria-expanded="false" aria-controls="ofp-chat-box">
                <span class="ofp-launcher-icon ofp-icon-open">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                </span>
                <span class="ofp-launcher-icon ofp-icon-close" style="display:none;">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </span>
                <span class="ofp-chat-badge" id="ofp-chat-unread-badge" style="display:none;" aria-label="Unread messages">1</span>
            </button>

            <div id="ofp-chat-box" class="ofp-chat-container" style="display:none;" role="dialog" aria-label="<?php echo esc_attr( $brand ); ?> chat">
                <div class="ofp-chat-header">
                    <div class="ofp-chat-header-info">
                        <div class="ofp-chat-avatar">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M12 2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2 2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"></path>
                                <rect x="4" y="8" width="16" height="12" rx="4"></rect>
                                <circle cx="9" cy="13" r="1.5" fill="currentColor"></circle>
                                <circle cx="15" cy="13" r="1.5" fill="currentColor"></circle>
                                <path d="M9 17h6"></path>
                            </svg>
                            <span class="ofp-status-dot"></span>
                        </div>
                        <div class="ofp-chat-title-group">
                            <h4 class="ofp-chat-title"><?php echo esc_html( $brand ); ?> Copilot</h4>
                            <span class="ofp-chat-status" id="ofp-chat-status-text">⚡ AI assistant · replies instantly</span>
                        </div>
                    </div>
                    <div class="ofp-chat-header-actions">
                        <button type="button" class="ofp-header-icon-btn" id="ofp-chat-minimize-btn" title="Minimize" aria-label="Minimize chat">
                            <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" fill="none" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </button>
                    </div>
                </div>

                <?php if ( $allow_human ) : ?>
                <div class="ofp-chat-mode-bar" role="tablist">
                    <button type="button" class="ofp-mode-tab is-active" id="ofp-tab-ai" data-mode="ai" role="tab" aria-selected="true">🤖 AI Assistant</button>
                    <button type="button" class="ofp-mode-tab" id="ofp-tab-human" data-mode="human" role="tab" aria-selected="false">💬 Human Agent</button>
                </div>
                <?php else : ?>
                <div class="ofp-chat-guest-banner">
                    <span>💡 Need help with your account? <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" class="ofp-guest-login-link">Log in</a> for live support.</span>
                </div>
                <?php endif; ?>

                <div class="ofp-chat-messages" id="ofp-chat-messages-body" role="log" aria-live="polite" aria-relevant="additions">
                    <div class="ofp-msg ofp-msg-bot">
                        <div class="ofp-msg-bubble">
                            <?php if ( $ctx['is_logged_in'] ) : ?>
                                👋 Hello <strong><?php echo esc_html( $ctx['user_name'] ); ?></strong>! How can I help you with your properties, leads, or rent oversight today?
                            <?php else : ?>
                                👋 Welcome to <strong><?php echo esc_html( $brand ); ?></strong>! Ask me about our property pipeline, features, pricing, or how to get started.
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ofp-chat-chips" id="ofp-chat-chips">
                        <?php if ( $ctx['is_logged_in'] ) : ?>
                            <button type="button" class="ofp-chip-btn" data-query="How do I add a new property listing?">🏠 Add property</button>
                            <button type="button" class="ofp-chip-btn" data-query="Where do I see my rent oversight and overdue rent?">📋 Rent oversight</button>
                            <button type="button" class="ofp-chip-btn" data-query="How do I top up my wallet credits?">💳 Wallet credits</button>
                        <?php else : ?>
                            <button type="button" class="ofp-chip-btn" data-query="What is <?php echo esc_attr( $brand ); ?> and how does it work?">ℹ️ What is this?</button>
                            <button type="button" class="ofp-chip-btn" data-query="How do I create an agent or landlord account?">📝 How to sign up?</button>
                            <button type="button" class="ofp-chip-btn" data-query="Where can I see available properties?">🏘️ Browse marketplace</button>
                            <button type="button" class="ofp-chip-btn" data-query="What are the subscription plans and pricing?">💰 Pricing plans</button>
                        <?php endif; ?>
                        <?php if ( $allow_human ) : ?>
                            <button type="button" class="ofp-chip-btn ofp-chip-human" id="ofp-chip-human-trigger">💬 Talk to a human agent</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $allow_human && ! $ctx['is_logged_in'] ) : ?>
                <form id="ofp-chat-prechat" class="ofp-prechat" style="display:none;" autocomplete="on">
                    <p class="ofp-prechat-title">Before we connect you</p>
                    <label class="ofp-sr-only" for="ofp-prechat-name">Your name</label>
                    <input type="text" id="ofp-prechat-name" class="ofp-prechat-input" placeholder="Your name" maxlength="80" required autocomplete="name" />
                    <label class="ofp-sr-only" for="ofp-prechat-email">Email (optional)</label>
                    <input type="email" id="ofp-prechat-email" class="ofp-prechat-input" placeholder="Email (optional, so we can follow up)" maxlength="120" autocomplete="email" />
                    <button type="submit" class="ofp-prechat-btn">Start chat</button>
                </form>
                <?php endif; ?>

                <div class="ofp-chat-footer">
                    <form id="ofp-chat-form" class="ofp-chat-form" autocomplete="off">
                        <label class="ofp-sr-only" for="ofp-chat-input">Your message</label>
                        <input type="text" id="ofp-chat-input" class="ofp-chat-input" placeholder="Type your message..." maxlength="<?php echo (int) self::MAX_MESSAGE_LENGTH; ?>" required />
                        <button type="submit" id="ofp-chat-send-btn" class="ofp-chat-send-btn" aria-label="Send message">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                        </button>
                    </form>
                    <div class="ofp-chat-footnote"><span>Powered by <?php echo esc_html( $brand ); ?> AI</span></div>
                </div>
            </div>
        </div>
        <?php
    }

    // ═══════════════════════════════════════════════════════════════════
    // REST API
    // ═══════════════════════════════════════════════════════════════════

    public static function register_routes(): void {
        // Public by design (guests can chat). Abuse is limited by rate limits and session checks below.
        register_rest_route( 'ofast/v1', '/chat/send', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_rest_send' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'ofast/v1', '/chat/history', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'handle_rest_history' ],
            'permission_callback' => '__return_true',
        ] );

        // Telegram calls this without WordPress cookies. The handler verifies a secret token instead.
        register_rest_route( 'ofast/v1', '/chat/telegram-webhook', [
            'methods'             => 'POST',
            'callback'            => [ 'OFP_Chat_Telegram', 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /** POST /chat/send */
    public static function handle_rest_send( WP_REST_Request $request ): WP_REST_Response {
        $cfg = self::get_config();
        $ctx = self::get_current_user_context();

        if ( ! self::widget_visible( $ctx ) ) {
            return self::json_error( 'disabled', 'Chat is currently unavailable.', 403 );
        }

        $session_id = (string) $request->get_param( 'session_id' );
        $message    = self::clean_text( (string) $request->get_param( 'message' ), self::MAX_MESSAGE_LENGTH );
        $mode       = 'human' === $request->get_param( 'mode' ) ? 'human' : 'ai';

        if ( ! preg_match( self::SESSION_PATTERN, $session_id ) || '' === $message ) {
            return self::json_error( 'bad_request', 'Please type a message.', 400 );
        }
        if ( 'human' === $mode && ! self::human_allowed( $ctx ) ) {
            return self::json_error( 'human_unavailable', 'Live support is available after you log in.', 403 );
        }

        $wait = self::throttle( $session_id );
        if ( $wait > 0 ) {
            return self::json_error( 'throttled', 'You are sending messages quickly. Please wait a moment.', 429, [ 'retry_after' => $wait ] );
        }

        $guest = $request->get_param( 'guest' );
        $conv  = self::get_or_create_conversation( $session_id, $ctx, $mode, is_array( $guest ) ? $guest : [] );
        if ( null === $conv ) {
            // This browser session belongs to a different account.
            return self::json_error( 'session_mismatch', 'Your chat session has expired. Starting a new one.', 403, [ 'reset' => true ] );
        }

        $conv_id         = (int) $conv['id'];
        $was_human_open  = 'human' === $conv['mode'] && 'closed' !== $conv['status'];
        $user_message_id = self::add_message( $conv_id, 'user', $message );

        // ── Human agent ───────────────────────────────────────────────
        if ( 'human' === $mode ) {
            $is_new_ticket = ! $was_human_open;
            $delivered     = false;

            $tg_id = OFP_Chat_Telegram::is_configured()
                ? OFP_Chat_Telegram::notify_agent( $conv_id, $conv, $message, $is_new_ticket )
                : null;

            if ( $tg_id ) {
                global $wpdb;
                $wpdb->update( $wpdb->prefix . 'ofp_chat_messages', [ 'telegram_message_id' => $tg_id ], [ 'id' => $user_message_id ] );
                $delivered = true;
            } else {
                // Telegram is down or not set up: make sure a human still finds out.
                $delivered = self::email_fallback( $conv, $message );
            }

            self::update_conversation( $conv_id, [ 'mode' => 'human', 'status' => 'waiting' ] );

            return self::json_ok( [
                'mode'            => 'human',
                'user_message_id' => $user_message_id,
                'delivered'       => $delivered,
                'notice'          => $is_new_ticket ? $cfg['handoff_message'] : null,
            ] );
        }

        // ── AI assistant ──────────────────────────────────────────────
        if ( 'human' === $conv['mode'] ) {
            self::update_conversation( $conv_id, [ 'mode' => 'ai' ] );
        }

        $system_prompt = self::build_system_prompt( $ctx );
        $history       = self::history_turns( $conv_id, 8, $user_message_id );

        // Guests tend to ask the same opening questions (the quick-reply chips), so reuse recent answers.
        $cache_key = null;
        if ( empty( $history ) && ! $ctx['is_logged_in'] ) {
            $cache_key = 'ofp_chat_faq_' . md5( strtolower( preg_replace( '/\s+/', ' ', $message ) ) . '|' . md5( $system_prompt ) );
            $cached    = get_transient( $cache_key );
            if ( is_string( $cached ) && '' !== $cached ) {
                $reply_id = self::add_message( $conv_id, 'ai', $cached );
                return self::json_ok( [ 'mode' => 'ai', 'user_message_id' => $user_message_id, 'reply' => $cached, 'reply_id' => $reply_id ] );
            }
        }

        $ai = OFP_Chat_AI::reply( $system_prompt, $history, $message );

        if ( ! $ai['ok'] && 'blocked' === $ai['code'] ) {
            $ai['ok']   = true;
            $ai['text'] = "I'm not able to help with that one. Is there something else about our service I can help with?";
        }

        if ( ! $ai['ok'] ) {
            // Do not keep a question that never got an answer, and never save error text as if the AI said it.
            self::delete_message( $user_message_id );
            return self::ai_failure_response( $ai, $ctx );
        }

        $reply_id = self::add_message( $conv_id, 'ai', $ai['text'] );
        if ( $cache_key ) {
            set_transient( $cache_key, $ai['text'], 6 * HOUR_IN_SECONDS );
        }

        return self::json_ok( [ 'mode' => 'ai', 'user_message_id' => $user_message_id, 'reply' => $ai['text'], 'reply_id' => $reply_id ] );
    }

    /** GET /chat/history?session_id=...&after_id=... */
    public static function handle_rest_history( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $empty = [ 'messages' => [], 'mode' => 'ai', 'status' => 'active' ];

        $session_id = (string) $request->get_param( 'session_id' );
        if ( ! preg_match( self::SESSION_PATTERN, $session_id ) ) {
            return self::json_ok( $empty );
        }

        $ctx  = self::get_current_user_context();
        $conv = self::resolve_conversation( $session_id, $ctx );
        if ( ! $conv ) {
            return self::json_ok( $empty );
        }
        if ( ! self::owns( $conv, $ctx ) ) {
            return self::json_ok( $empty + [ 'reset' => true ] );
        }

        // Pull mode: while this visitor waits for a human, fetch any new Telegram replies first,
        // then reload the chat so a reply or /close that just arrived is included in this response.
        if ( 'human' === $conv['mode'] && 'closed' !== $conv['status'] ) {
            OFP_Chat_Telegram::maybe_pull();
            $conv = self::resolve_conversation( $session_id, $ctx ) ?: $conv;
        }

        $after_id = max( 0, (int) $request->get_param( 'after_id' ) );
        $table    = $wpdb->prefix . 'ofp_chat_messages';

        if ( $after_id > 0 ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, sender, message, created_at FROM {$table} WHERE conversation_id = %d AND id > %d ORDER BY id ASC LIMIT 100",
                $conv['id'],
                $after_id
            ) );
        } else {
            // First load: the most recent 50, oldest first.
            $rows = array_reverse( (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT id, sender, message, created_at FROM {$table} WHERE conversation_id = %d ORDER BY id DESC LIMIT 50",
                $conv['id']
            ) ) );
        }

        $messages = [];
        foreach ( $rows as $row ) {
            $text = (string) $row->message;
            if ( 'system' === $row->sender && 'closed' === $text ) {
                $text = 'This chat was closed by our team. Send a message to start a new one.';
            }
            $messages[] = [
                'id'      => (int) $row->id,
                'sender'  => $row->sender,
                'message' => $text,
                'time'    => gmdate( 'c', (int) strtotime( $row->created_at . ' UTC' ) ),
            ];
        }

        return self::json_ok( [
            'messages' => $messages,
            'mode'     => $conv['mode'],
            'status'   => $conv['status'],
        ] );
    }

    // ── Response helpers ───────────────────────────────────────────────

    private static function json_ok( array $data ): WP_REST_Response {
        return self::no_cache( new WP_REST_Response( [ 'success' => true ] + $data, 200 ) );
    }

    private static function json_error( string $code, string $message, int $status, array $extra = [] ): WP_REST_Response {
        return self::no_cache( new WP_REST_Response( [ 'success' => false, 'error_code' => $code, 'error' => $message ] + $extra, $status ) );
    }

    /** Stop browsers, CDNs and cache plugins from replaying old chat responses. */
    private static function no_cache( WP_REST_Response $response ): WP_REST_Response {
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        return $response;
    }

    private static function ai_failure_response( array $ai, array $ctx ): WP_REST_Response {
        $wait = (int) $ai['retry_after'];

        switch ( $ai['code'] ) {
            case 'rate_limited':
                $when    = $wait > 0 && $wait <= 90 ? 'in about ' . $wait . ' seconds' : 'in a few minutes';
                $message = "I'm getting a lot of questions right now. Please try again {$when}.";
                $status  = 429;
                break;
            case 'not_configured':
                $message = "The assistant isn't available right now.";
                $status  = 503;
                break;
            default:
                $message = "I'm having trouble answering right now. Please try again in a moment.";
                $status  = 503;
        }

        $extra = [
            'retry_after'   => min( $wait, 120 ),
            'suggest_human' => self::human_allowed( $ctx ),
        ];
        if ( current_user_can( 'manage_options' ) ) {
            $extra['debug'] = sprintf( '%s/%s: %s (%s)', $ai['provider'], $ai['model'], $ai['message'], $ai['code'] );
        }
        return self::json_error( 'ai_' . $ai['code'], $message, $status, $extra );
    }

    // ── Conversations and messages ─────────────────────────────────────

    private static function find_conversation( string $session_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ofp_chat_conversations WHERE session_id = %s", $session_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * A chat session belongs to the account that started it. A guest chat can be
     * picked up by the same browser after logging in, but never the other way round.
     */
    private static function owns( array $conv, array $ctx ): bool {
        if ( 0 === (int) $conv['user_id'] ) {
            return true;
        }
        return $conv['user_type'] === $ctx['user_type'] && (int) $conv['user_id'] === (int) $ctx['user_id'];
    }

    /** The chat thread of a logged-in account, whichever device started it. Newest wins. */
    private static function find_user_conversation( array $ctx ): ?array {
        global $wpdb;
        if ( ! $ctx['is_logged_in'] || (int) $ctx['user_id'] <= 0 ) {
            return null;
        }
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ofp_chat_conversations WHERE user_type = %s AND user_id = %d ORDER BY id DESC LIMIT 1",
            $ctx['user_type'],
            $ctx['user_id']
        ), ARRAY_A );
        return $row ?: null;
    }

    /**
     * Which conversation this request belongs to. Logged-in people have ONE conversation
     * shared by all their devices; guests have one per browser session.
     */
    private static function resolve_conversation( string $session_id, array $ctx ): ?array {
        return self::find_user_conversation( $ctx ) ?: self::find_conversation( $session_id );
    }

    /**
     * Map any ticket number to the account's current conversation, so a reply to an old
     * ticket (from before devices were merged) still lands in the chat the visitor sees.
     */
    public static function canonical_conversation_id( int $conv_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ofp_chat_conversations';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT user_type, user_id FROM {$table} WHERE id = %d", $conv_id ) );
        if ( ! $row || (int) $row->user_id <= 0 ) {
            return $conv_id;
        }
        $latest = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_type = %s AND user_id = %d ORDER BY id DESC LIMIT 1",
            $row->user_type,
            $row->user_id
        ) );
        return $latest ? (int) $latest : $conv_id;
    }

    /** @return array|null The conversation row, or null when the session belongs to someone else. */
    private static function get_or_create_conversation( string $session_id, array $ctx, string $mode, array $guest ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'ofp_chat_conversations';
        $now   = current_time( 'mysql', true );

        // Identity: portal/WordPress account, or what a guest typed into the pre-chat form.
        $name  = $ctx['user_name'];
        $email = $ctx['user_email'];
        if ( ! $ctx['is_logged_in'] && 'human' === $mode ) {
            $given_name  = sanitize_text_field( (string) ( $guest['name'] ?? '' ) );
            $given_email = sanitize_email( (string) ( $guest['email'] ?? '' ) );
            $name  = '' !== $given_name ? mb_substr( $given_name, 0, 80 ) : $name;
            $email = $given_email ?: $email;
        }

        $conv = self::resolve_conversation( $session_id, $ctx );

        if ( ! $conv ) {
            $wpdb->insert( $table, [
                'session_id' => $session_id,
                'user_type'  => $ctx['user_type'],
                'user_id'    => $ctx['user_id'],
                'user_name'  => $name,
                'user_email' => $email,
                'mode'       => $mode,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ] );
            return self::find_conversation( $session_id );
        }

        if ( ! self::owns( $conv, $ctx ) ) {
            return null;
        }

        // Keep identity fresh (a guest who logs in, a renamed client, or a guest filling in the pre-chat form).
        $changes = [];
        if ( $ctx['is_logged_in'] ) {
            if ( 0 === (int) $conv['user_id'] ) {
                $changes = [ 'user_type' => $ctx['user_type'], 'user_id' => $ctx['user_id'], 'user_name' => $name, 'user_email' => $email ];
            } elseif ( $conv['user_name'] !== $name || $conv['user_email'] !== $email ) {
                $changes = [ 'user_name' => $name, 'user_email' => $email ];
            }
        } elseif ( 'human' === $mode && $name !== $conv['user_name'] && 'Guest Visitor' !== $name ) {
            $changes = [ 'user_name' => $name, 'user_email' => $email ];
        }
        if ( $changes ) {
            self::update_conversation( (int) $conv['id'], $changes );
            $conv = array_merge( $conv, $changes );
        }
        return $conv;
    }

    public static function add_message( int $conv_id, string $sender, string $text, ?int $telegram_message_id = null ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'ofp_chat_messages', [
            'conversation_id'     => $conv_id,
            'sender'              => $sender,
            'message'             => $text,
            'telegram_message_id' => $telegram_message_id,
            'created_at'          => current_time( 'mysql', true ),
        ] );
        $id = (int) $wpdb->insert_id;
        self::update_conversation( $conv_id, [] ); // Bump updated_at.
        return $id;
    }

    private static function delete_message( int $message_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'ofp_chat_messages', [ 'id' => $message_id ] );
    }

    private static function update_conversation( int $conv_id, array $fields ): void {
        global $wpdb;
        $fields['updated_at'] = current_time( 'mysql', true );
        $wpdb->update( $wpdb->prefix . 'ofp_chat_conversations', $fields, [ 'id' => $conv_id ] );
    }

    public static function set_status( int $conv_id, string $status ): void {
        self::update_conversation( $conv_id, [ 'status' => $status ] );
    }

    /** Called when a staff reply lands: the chat is now waiting on the visitor, not on staff. */
    public static function mark_agent_replied( int $conv_id ): void {
        self::update_conversation( $conv_id, [
            'mode'                => 'human',
            'status'              => 'active',
            'last_agent_reply_at' => current_time( 'mysql', true ),
        ] );
    }

    /**
     * Earlier turns for the AI, oldest first, without the message being answered now.
     *
     * @return array [ [ 'role' => 'user'|'assistant', 'content' => string ], ... ]
     */
    private static function history_turns( int $conv_id, int $limit, int $exclude_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT sender, message FROM {$wpdb->prefix}ofp_chat_messages
             WHERE conversation_id = %d AND id <> %d AND sender IN ('user','ai','admin')
             ORDER BY id DESC LIMIT %d",
            $conv_id,
            $exclude_id,
            $limit
        ) );

        $turns = [];
        foreach ( array_reverse( (array) $rows ) as $row ) {
            $turns[] = [
                'role'    => 'user' === $row->sender ? 'user' : 'assistant',
                'content' => (string) $row->message,
            ];
        }
        return $turns;
    }

    // ── Prompt building ────────────────────────────────────────────────

    private static function build_system_prompt( array $ctx ): string {
        $cfg = self::get_config();

        if ( $ctx['is_logged_in'] ) {
            $prompt = str_replace(
                [ '{user_name}', '{user_role}' ],
                [ $ctx['user_name'], $ctx['user_role'] ],
                $cfg['user_prompt']
            );
            $stats = self::get_safe_user_stats( $ctx );
            if ( '' !== $stats ) {
                $prompt .= "\n\nCurrent User Summary Context:\n" . $stats;
            }
        } else {
            $prompt = $cfg['guest_prompt'];
        }

        return $prompt . "\n\nNever mention Telegram, bots, or any internal tools. When a human is needed, refer to \"our support team\" or the \"Talk to a human agent\" option.";
    }

    /** Safe, read-only facts about the current client. */
    private static function get_safe_user_stats( array $ctx ): string {
        global $wpdb;
        $lines = [];

        if ( 'client' === $ctx['user_type'] && ! empty( $ctx['user_id'] ) ) {
            $client_id = (int) $ctx['user_id'];

            $props = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ofp_property' AND post_author = %d AND post_status = 'publish'",
                $client_id
            ) );
            if ( null !== $props ) {
                $lines[] = '- Active published listings: ' . (int) $props;
            }

            $lead_table = $wpdb->prefix . 'ofp_leads';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lead_table ) ) === $lead_table ) {
                $leads = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$lead_table} WHERE client_id = %d AND status != 'trashed'",
                    $client_id
                ) );
                if ( null !== $leads ) {
                    $lines[] = '- Total pipeline leads: ' . (int) $leads;
                }
            }
        }

        return implode( "\n", $lines );
    }

    // ── Abuse protection ───────────────────────────────────────────────

    /**
     * Rate limit per chat session and per IP address. The AI quota is shared by every
     * visitor, so one noisy visitor must not be able to use it all up.
     *
     * @return int Seconds to wait, or 0 when the message may go through.
     */
    private static function throttle( string $session_id ): int {
        $limits = apply_filters( 'ofp_chat_rate_limits', [
            'session' => [ 'max' => 10, 'window' => 60 ],
            'ip'      => [ 'max' => 60, 'window' => 600 ],
        ] );

        $ip = class_exists( 'OFP_Security' ) ? OFP_Security::get_client_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        $subjects = [ 'session' => $session_id, 'ip' => $ip ];
        $now      = time();

        foreach ( $limits as $name => $rule ) {
            $key    = 'ofp_chat_rl_' . $name . '_' . md5( (string) ( $subjects[ $name ] ?? '' ) );
            $bucket = get_transient( $key );
            if ( ! is_array( $bucket ) || $bucket['reset'] <= $now ) {
                $bucket = [ 'count' => 0, 'reset' => $now + (int) $rule['window'] ];
            }
            if ( $bucket['count'] >= (int) $rule['max'] ) {
                return max( 1, $bucket['reset'] - $now );
            }
            $bucket['count']++;
            set_transient( $key, $bucket, max( 1, $bucket['reset'] - $now ) );
        }
        return 0;
    }

    private static function clean_text( string $text, int $max ): string {
        return mb_substr( trim( sanitize_textarea_field( $text ) ), 0, $max );
    }

    /** Backup route when Telegram cannot be reached, so a human still hears about the message. */
    private static function email_fallback( array $conv, string $message ): bool {
        $to    = (string) get_option( 'admin_email', '' );
        $brand = class_exists( 'OFP_Brand' ) ? OFP_Brand::name() : get_bloginfo( 'name' );
        if ( '' === $to ) {
            return false;
        }

        $name = $conv['user_name'] ?: 'A visitor';
        $body = "{$name} sent a live chat message (chat #C{$conv['id']}):\n\n{$message}\n\n";
        if ( ! empty( $conv['user_email'] ) ) {
            $body .= 'Their email: ' . $conv['user_email'] . "\n\n";
        }
        $body .= "This arrived by email because the Telegram connection could not be reached. Check Settings > AI & Live Chat.";

        return (bool) wp_mail( $to, "[{$brand}] New live chat message from {$name}", $body );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Admin buttons (settings screen)
    // ═══════════════════════════════════════════════════════════════════

    private static function admin_guard( string $nonce_action ): void {
        check_admin_referer( $nonce_action ); // Stops with a 403 on failure.
        if ( ! class_exists( 'OFP_Auth' ) || ! OFP_Auth::is_super_admin() ) {
            wp_die( 'Access denied.', 403 );
        }
    }

    private static function finish( string $text, bool $ok ): void {
        set_transient( 'ofp_admin_message_' . get_current_user_id(), [
            'text' => $text,
            'type' => $ok ? 'success' : 'error',
        ], 60 );
        wp_safe_redirect( admin_url( self::SETTINGS_URL ) );
        exit;
    }

    public static function handle_test_ai(): void {
        self::admin_guard( 'ofp_test_chat_ai' );

        $results = OFP_Chat_AI::probe_all();
        if ( empty( $results ) ) {
            self::finish( 'No AI key is saved yet. Add a Gemini or Groq key, save, then test again.', false );
        }

        $parts  = [];
        $any_ok = false;
        foreach ( $results as $r ) {
            $label = $r['provider'] . ' / ' . $r['model'];
            if ( $r['ok'] ) {
                $any_ok  = true;
                $parts[] = '✅ ' . $label . ' (' . $r['ms'] . ' ms)';
            } else {
                $parts[] = '❌ ' . $label . ': ' . mb_substr( $r['message'], 0, 160 );
            }
        }
        self::finish( 'AI test: ' . implode( ' • ', $parts ), $any_ok );
    }

    public static function handle_test_telegram(): void {
        self::admin_guard( 'ofp_test_chat_telegram' );
        $res = OFP_Chat_Telegram::send_test();
        self::finish( ( $res['ok'] ? '✅ ' : '❌ ' ) . $res['message'], $res['ok'] );
    }

    public static function handle_set_webhook(): void {
        self::admin_guard( 'ofp_set_telegram_webhook' );
        $res = OFP_Chat_Telegram::connect_webhook();
        self::finish( ( $res['ok'] ? '✅ ' : '❌ ' ) . $res['message'], $res['ok'] );
    }

    public static function handle_set_pull(): void {
        self::admin_guard( 'ofp_set_telegram_pull' );
        $res = OFP_Chat_Telegram::connect_pull();
        self::finish( ( $res['ok'] ? '✅ ' : '❌ ' ) . $res['message'], $res['ok'] );
    }

    public static function handle_check_webhook(): void {
        self::admin_guard( 'ofp_check_telegram_webhook' );
        $res = OFP_Chat_Telegram::webhook_status();
        self::finish( ( $res['ok'] ? '✅ ' : '❌ ' ) . $res['message'], $res['ok'] );
    }
}
