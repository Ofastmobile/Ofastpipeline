<?php
/**
 * OFP_Activator
 *
 * Runs once when the plugin is activated.
 * Responsibilities:
 *  - Create / upgrade all 15 custom DB tables via dbDelta()
 *  - Seed the protected super-admin row (Olabode)
 *  - Schedule all WP-Cron events
 *  - Flush rewrite rules so /login, /dashboard etc. work immediately
 *
 * Tables created (v2.0 = 13 core | v2.1 adds 2 more):
 *  1.  ofp_admins
 *  2.  ofp_clients
 *  3.  ofp_leads
 *  4.  ofp_trigger_queue
 *  5.  ofp_communications_log
 *  6.  ofp_ivr_responses
 *  7.  ofp_credits
 *  8.  ofp_credit_transactions
 *  9.  ofp_subscriptions          ← v2.1: includes `type` column
 *  10. ofp_pipeline_configs
 *  11. ofp_rate_limits
 *  12. ofp_archives
 *  13. ofp_client_sessions
 *  14. ofp_properties             ← NEW in v2.1
 *  15. ofp_property_leads         ← NEW in v2.1 (property_id column on leads handled via ALTER)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Activator {

    /**
     * Main activation entry point.
     */
    public static function activate(): void {
        self::create_tables();
        self::maybe_upgrade_schema();
        self::seed_super_admin();
        self::schedule_cron_events();
        self::generate_encryption_keys();

        // IMPORTANT: We do NOT call flush_rewrite_rules() directly here.
        //
        // WordPress fires register_activation_hook callbacks BEFORE this
        // plugin's own 'init' hooks have run in the SAME request (the plugin
        // wasn't active yet during the 'init' that already fired earlier in
        // this admin page load). Our custom routes (/login, /dashboard,
        // /signup etc. in OFP_Client_Portal, and the ofp_property CPT) are
        // only registered via add_rewrite_rule() / register_post_type() on
        // 'init' — so flushing here would flush a rule set that does NOT
        // yet include them, permanently wiping them from the rewrite cache
        // until someone manually visits Settings → Permalinks → Save.
        //
        // Instead, we set a flag and perform the actual flush on the NEXT
        // normal page load via the 'init' hook in ofast-pipeline.php (after
        // all routes have been registered for that request).
        update_option( 'ofp_flush_rewrite_rules', '1' );
    }

    /**
     * Handles incremental schema upgrades for sites that activated the
     * plugin before a new column was introduced. dbDelta() can add new
     * tables and new columns to CREATE TABLE statements on fresh installs,
     * but does not reliably ALTER existing columns in all environments,
     * so explicit ALTER TABLE guards are used here for safety.
     *
     * Safe to run on every activation — each ALTER is guarded by a
     * column-existence check first.
     */
    private static function maybe_upgrade_schema(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // trashed_at column on ofp_clients (added for client trash/recovery system).
        $column_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM {$p}ofp_clients LIKE 'trashed_at'"
        );
        if ( empty( $column_exists ) ) {
            $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN trashed_at DATETIME DEFAULT NULL AFTER business_category" );
        }

        // reset_token_hash and reset_token_expires columns on ofp_clients (added for Phase 13 password reset).
        $reset_column_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM {$p}ofp_clients LIKE 'reset_token_hash'"
        );
        if ( empty( $reset_column_exists ) ) {
            $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN reset_token_hash VARCHAR(64) DEFAULT NULL AFTER trashed_at" );
            $wpdb->query( "ALTER TABLE {$p}ofp_clients ADD COLUMN reset_token_expires DATETIME DEFAULT NULL AFTER reset_token_hash" );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TABLE CREATION
    // ─────────────────────────────────────────────────────────────────────────

    private static function create_tables(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $p               = $wpdb->prefix; // e.g. "wp_"

        // ── 1. ofp_admins ────────────────────────────────────────────────────
        // Stores Olabode (super_admin, protected) and the marketing partner (co_admin).
        // Intentionally separate from wp_users so this plugin stays self-contained.
        dbDelta( "CREATE TABLE {$p}ofp_admins (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name         VARCHAR(100)    NOT NULL,
            email        VARCHAR(150)    NOT NULL,
            password     VARCHAR(255)    NOT NULL,
            role         VARCHAR(20)     NOT NULL DEFAULT 'co_admin',
            is_protected TINYINT(1)      NOT NULL DEFAULT 0,
            last_login   DATETIME                 DEFAULT NULL,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   email (email)
        ) {$charset_collate};" );

        // ── 2. ofp_clients ───────────────────────────────────────────────────
        // Every paying client (tailor, restaurant, property agent, etc.)
        // status values: active | expiring_soon | grace | suspended | cancelled | pending_review
        dbDelta( "CREATE TABLE {$p}ofp_clients (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            business_name          VARCHAR(150)    NOT NULL,
            owner_name             VARCHAR(100)    NOT NULL,
            email                  VARCHAR(150)    NOT NULL,
            phone                  VARCHAR(20)     NOT NULL,
            password               VARCHAR(255)    NOT NULL,
            subdomain              VARCHAR(100)             DEFAULT NULL,
            custom_domain          VARCHAR(150)             DEFAULT NULL,
            plan                   VARCHAR(20)              DEFAULT 'starter',
            status                 VARCHAR(20)     NOT NULL DEFAULT 'active',
            onboarding_source      VARCHAR(20)     NOT NULL DEFAULT 'manual',
            business_category      VARCHAR(50)              DEFAULT NULL,
            trashed_at             DATETIME                 DEFAULT NULL,
            subscription_expires   DATE                     DEFAULT NULL,
            setup_fee_paid         TINYINT(1)      NOT NULL DEFAULT 0,
            virtual_account_number VARCHAR(50)              DEFAULT NULL,
            virtual_bank_name      VARCHAR(100)             DEFAULT NULL,
            at_phone_number        VARCHAR(20)              DEFAULT NULL,
            sms_provider           VARCHAR(30)     NOT NULL DEFAULT 'africastalking',
            sms_api_key_encrypted  TEXT                     DEFAULT NULL,
            voice_api_key_encrypted TEXT                    DEFAULT NULL,
            business_phone         VARCHAR(20)              DEFAULT NULL,
            whatsapp_number        VARCHAR(20)              DEFAULT NULL,
            ads_managed            TINYINT(1)      NOT NULL DEFAULT 0,
            created_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at             DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY  email (email),
            KEY         status (status),
            KEY         business_category (business_category)
        ) {$charset_collate};" );

        // ── 3. ofp_leads ─────────────────────────────────────────────────────
        // Leads submitted from landing pages OR property listing inquiry forms.
        // property_id (v2.1): NULL for regular landing-page leads; set for property inquiries.
        dbDelta( "CREATE TABLE {$p}ofp_leads (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id    BIGINT UNSIGNED NOT NULL,
            property_id  BIGINT UNSIGNED          DEFAULT NULL,
            name         VARCHAR(100)             DEFAULT NULL,
            phone        VARCHAR(20)     NOT NULL,
            email        VARCHAR(150)             DEFAULT NULL,
            source       VARCHAR(150)             DEFAULT NULL,
            ip_address   VARCHAR(45)              DEFAULT NULL,
            status       VARCHAR(20)     NOT NULL DEFAULT 'new',
            ivr_response VARCHAR(10)              DEFAULT NULL,
            notes        TEXT                     DEFAULT NULL,
            converted_at DATETIME                 DEFAULT NULL,
            created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id  (client_id),
            KEY property_id (property_id),
            KEY status     (status),
            KEY created_at (created_at)
        ) {$charset_collate};" );

        // ── 4. ofp_trigger_queue ─────────────────────────────────────────────
        // Every scheduled communication (instant SMS, follow-up, voice call) lives here.
        // WP-Cron picks up due rows every 5 minutes.
        dbDelta( "CREATE TABLE {$p}ofp_trigger_queue (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id     BIGINT UNSIGNED NOT NULL,
            lead_id       BIGINT UNSIGNED NOT NULL,
            type          VARCHAR(10)     NOT NULL,
            message       TEXT                     DEFAULT NULL,
            scheduled_at  DATETIME        NOT NULL,
            status        VARCHAR(20)     NOT NULL DEFAULT 'pending',
            attempts      INT             NOT NULL DEFAULT 0,
            last_attempt  DATETIME                 DEFAULT NULL,
            response_data TEXT                     DEFAULT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY scheduled_status (scheduled_at, status),
            KEY client_lead      (client_id, lead_id)
        ) {$charset_collate};" );

        // ── 5. ofp_communications_log ─────────────────────────────────────────
        // Permanent record of every SMS / voice / email sent or received.
        dbDelta( "CREATE TABLE {$p}ofp_communications_log (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id    BIGINT UNSIGNED NOT NULL,
            lead_id      BIGINT UNSIGNED NOT NULL,
            type         VARCHAR(10)     NOT NULL,
            direction    VARCHAR(10)     NOT NULL DEFAULT 'outbound',
            message      TEXT                     DEFAULT NULL,
            status       VARCHAR(50)              DEFAULT NULL,
            provider     VARCHAR(50)              DEFAULT NULL,
            provider_ref VARCHAR(100)             DEFAULT NULL,
            cost         DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
            sent_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY lead_id   (lead_id)
        ) {$charset_collate};" );

        // ── 6. ofp_ivr_responses ─────────────────────────────────────────────
        // Digit presses from IVR calls, linked back to the lead.
        dbDelta( "CREATE TABLE {$p}ofp_ivr_responses (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id       BIGINT UNSIGNED NOT NULL,
            lead_id         BIGINT UNSIGNED NOT NULL,
            call_session_id VARCHAR(100)             DEFAULT NULL,
            digit_pressed   VARCHAR(5)               DEFAULT NULL,
            action_taken    VARCHAR(100)             DEFAULT NULL,
            responded_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY lead_id   (lead_id)
        ) {$charset_collate};" );

        // ── 7. ofp_credits ───────────────────────────────────────────────────
        // One row per client. Tracks SMS and voice credit balances.
        // paused = 1 means automation has been halted due to zero balance.
        dbDelta( "CREATE TABLE {$p}ofp_credits (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id       BIGINT UNSIGNED NOT NULL,
            sms_loaded      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            sms_used        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            sms_remaining   DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            voice_loaded    DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            voice_used      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            voice_remaining DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            low_warned      TINYINT(1)      NOT NULL DEFAULT 0,
            paused          TINYINT(1)      NOT NULL DEFAULT 0,
            updated_at      DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id)
        ) {$charset_collate};" );

        // ── 8. ofp_credit_transactions ────────────────────────────────────────
        // Full audit trail — every top-up and deduction.
        dbDelta( "CREATE TABLE {$p}ofp_credit_transactions (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id     BIGINT UNSIGNED NOT NULL,
            channel       VARCHAR(10)     NOT NULL,
            type          VARCHAR(10)     NOT NULL,
            amount        DECIMAL(10,4)   NOT NULL,
            balance_after DECIMAL(10,2)   NOT NULL,
            reference     VARCHAR(100)             DEFAULT NULL,
            note          VARCHAR(255)             DEFAULT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id)
        ) {$charset_collate};" );

        // ── 9. ofp_subscriptions  (v2.1: includes `type` column) ─────────────
        // A client can have multiple rows: one for 'crm', one for 'listing', or both.
        // `plan` is only meaningful when type = 'crm'.
        dbDelta( "CREATE TABLE {$p}ofp_subscriptions (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id      BIGINT UNSIGNED NOT NULL,
            type           VARCHAR(20)     NOT NULL DEFAULT 'crm',
            plan           VARCHAR(20)              DEFAULT NULL,
            amount         DECIMAL(10,2)   NOT NULL,
            payment_method VARCHAR(20)     NOT NULL,
            payment_ref    VARCHAR(100)             DEFAULT NULL,
            status         VARCHAR(20)     NOT NULL DEFAULT 'pending',
            period_start   DATE                     DEFAULT NULL,
            period_end     DATE                     DEFAULT NULL,
            paid_at        DATETIME                 DEFAULT NULL,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY type      (type),
            KEY status    (status)
        ) {$charset_collate};" );

        // ── 10. ofp_pipeline_configs ──────────────────────────────────────────
        // One row per CRM client. Stores their personalised SMS/Voice/IVR sequence.
        // Only created when the client has a CRM subscription (not listing-only).
        dbDelta( "CREATE TABLE {$p}ofp_pipeline_configs (
            id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id              BIGINT UNSIGNED NOT NULL,
            instant_sms_enabled    TINYINT(1)      NOT NULL DEFAULT 1,
            instant_sms_message    TEXT                     DEFAULT NULL,
            followup_1_delay_hours INT             NOT NULL DEFAULT 1,
            followup_1_type        VARCHAR(10)     NOT NULL DEFAULT 'sms',
            followup_1_message     TEXT                     DEFAULT NULL,
            followup_2_delay_hours INT             NOT NULL DEFAULT 24,
            followup_2_type        VARCHAR(10)     NOT NULL DEFAULT 'voice',
            followup_2_message     TEXT                     DEFAULT NULL,
            followup_3_delay_hours INT             NOT NULL DEFAULT 72,
            followup_3_type        VARCHAR(10)     NOT NULL DEFAULT 'sms',
            followup_3_message     TEXT                     DEFAULT NULL,
            max_followups          INT             NOT NULL DEFAULT 3,
            ivr_option_1_action    VARCHAR(20)     NOT NULL DEFAULT 'transfer',
            ivr_option_2_action    VARCHAR(20)     NOT NULL DEFAULT 'sms',
            ivr_option_3_action    VARCHAR(20)     NOT NULL DEFAULT 'schedule',
            transfer_phone         VARCHAR(20)              DEFAULT NULL,
            whatsapp_link          VARCHAR(255)             DEFAULT NULL,
            updated_at             DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY client_id (client_id)
        ) {$charset_collate};" );

        // ── 11. ofp_rate_limits ───────────────────────────────────────────────
        // Simple IP-based rate limiting. Rows older than 24 h are cleaned by cron.
        dbDelta( "CREATE TABLE {$p}ofp_rate_limits (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip         VARCHAR(45)     NOT NULL,
            action     VARCHAR(50)     NOT NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ip_action  (ip, action),
            KEY created_at (created_at)
        ) {$charset_collate};" );

        // ── 12. ofp_archives ──────────────────────────────────────────────────
        // Tracks generated monthly CSV report files and their download tokens.
        dbDelta( "CREATE TABLE {$p}ofp_archives (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id      BIGINT UNSIGNED NOT NULL,
            period         VARCHAR(20)              DEFAULT NULL,
            file_path      VARCHAR(255)             DEFAULT NULL,
            file_size      INT                      DEFAULT NULL,
            download_token VARCHAR(64)              DEFAULT NULL,
            token_expires  DATETIME                 DEFAULT NULL,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id)
        ) {$charset_collate};" );

        // ── 13. ofp_client_sessions ───────────────────────────────────────────
        // Custom session tokens for the client dashboard (/login).
        // Clients are NOT WordPress users — we manage our own session table.
        dbDelta( "CREATE TABLE {$p}ofp_client_sessions (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id  BIGINT UNSIGNED NOT NULL,
            token      VARCHAR(64)     NOT NULL,
            ip_address VARCHAR(45)              DEFAULT NULL,
            expires_at DATETIME        NOT NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token     (token),
            KEY        client_id (client_id)
        ) {$charset_collate};" );

        // ── 14. ofp_properties  (NEW — v2.1) ─────────────────────────────────
        // Billing / ownership source-of-truth for property listings.
        // The public-facing page is a WordPress CPT (ofp_property), linked via wp_post_id.
        // status: pending_upload | live | taken | expired
        dbDelta( "CREATE TABLE {$p}ofp_properties (
            id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            client_id      BIGINT UNSIGNED  NOT NULL,
            title          VARCHAR(200)     NOT NULL,
            description    TEXT                      DEFAULT NULL,
            property_type  VARCHAR(50)               DEFAULT NULL,
            listing_type   VARCHAR(20)               DEFAULT NULL,
            price          DECIMAL(14,2)             DEFAULT NULL,
            price_period   VARCHAR(20)               DEFAULT NULL,
            bedrooms       INT                       DEFAULT NULL,
            bathrooms      INT                       DEFAULT NULL,
            location_text  VARCHAR(255)              DEFAULT NULL,
            featured_image VARCHAR(255)              DEFAULT NULL,
            gallery_images TEXT                      DEFAULT NULL,
            status         VARCHAR(20)      NOT NULL DEFAULT 'pending_upload',
            is_featured    TINYINT(1)       NOT NULL DEFAULT 0,
            wp_post_id     BIGINT UNSIGNED           DEFAULT NULL,
            created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME                  DEFAULT NULL,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY status    (status),
            KEY wp_post_id (wp_post_id)
        ) {$charset_collate};" );

        // ── 15. ofp_property_inquiries  (NEW — v2.1) ─────────────────────────
        // Optional supplementary table for property-specific inquiry metadata.
        // Core lead data still lives in ofp_leads (property_id column links them).
        // This table stores extras: viewing date preference, message, etc.
        dbDelta( "CREATE TABLE {$p}ofp_property_inquiries (
            id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_id               BIGINT UNSIGNED NOT NULL,
            property_id           BIGINT UNSIGNED NOT NULL,
            client_id             BIGINT UNSIGNED NOT NULL,
            preferred_viewing_date DATE                    DEFAULT NULL,
            message               TEXT                    DEFAULT NULL,
            created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY lead_id     (lead_id),
            KEY property_id (property_id),
            KEY client_id   (client_id)
        ) {$charset_collate};" );

        // Store the schema version so future upgrades can run targeted migrations.
        update_option( 'ofp_db_version', OFP_VERSION );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SUPER ADMIN SEED
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Creates the protected super-admin row for Olabode on first activation.
     * If already exists, skips silently (safe to re-activate).
     */
    private static function seed_super_admin(): void {
        global $wpdb;

        $exists = $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}ofp_admins WHERE is_protected = 1 LIMIT 1"
        );

        if ( $exists ) {
            return;
        }

        $wpdb->insert(
            $wpdb->prefix . 'ofp_admins',
            [
                'name'         => 'Olabode',
                'email'        => 'admin@ofastpipeline.com', // ← change this on first login
                'password'     => password_hash( 'ChangeMe@2025!', PASSWORD_BCRYPT ),
                'role'         => 'super_admin',
                'is_protected' => 1,
                'created_at'   => current_time( 'mysql' ),
            ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRON SCHEDULING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Registers all four WP-Cron events if they are not already scheduled.
     * Safe to call on every activation (wp_next_scheduled guard).
     */
    private static function schedule_cron_events(): void {
        if ( ! wp_next_scheduled( 'ofp_process_queue' ) ) {
            wp_schedule_event( time(), 'ofp_five_minutes', 'ofp_process_queue' );
        }

        if ( ! wp_next_scheduled( 'ofp_daily_subscription_check' ) ) {
            wp_schedule_event( time(), 'daily', 'ofp_daily_subscription_check' );
        }

        if ( ! wp_next_scheduled( 'ofp_daily_credit_check' ) ) {
            wp_schedule_event( time(), 'daily', 'ofp_daily_credit_check' );
        }

        // Monthly archive fires on the 1st of next month at 2 AM.
        if ( ! wp_next_scheduled( 'ofp_monthly_archive' ) ) {
            $first_of_next_month = strtotime( 'first day of next month 02:00:00' );
            wp_schedule_event( $first_of_next_month, 'monthly', 'ofp_monthly_archive' );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENCRYPTION KEYS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generates AES-256 encryption key + IV and stores them in wp_options
     * on first activation only. Used by OFP_Security::encrypt() / decrypt()
     * to protect client API keys at rest.
     */
    private static function generate_encryption_keys(): void {
        if ( ! get_option( 'ofp_encryption_key' ) ) {
            update_option( 'ofp_encryption_key', bin2hex( random_bytes( 32 ) ), false );
        }

        if ( ! get_option( 'ofp_encryption_iv' ) ) {
            update_option( 'ofp_encryption_iv', bin2hex( random_bytes( 16 ) ), false );
        }
    }
}
