<?php
/**
 * OFP_Cron_Handler
 *
 * Connects WP-Cron hooks to their handler methods.
 * All four scheduled events are registered here.
 *
 * CRON EVENTS:
 *  ofp_process_queue            → every 5 minutes → OFP_Queue::process_due()
 *  ofp_daily_subscription_check → daily           → OFP_Subscription::run_daily_check()
 *  ofp_daily_credit_check       → daily           → OFP_Credit::run_daily_check()
 *  ofp_monthly_archive          → monthly         → generates CSV reports per client
 *
 * RELIABILITY NOTE:
 *  WP-Cron only fires on page visits by default. On low-traffic sites this
 *  can cause delays. For production, disable WP-Cron in wp-config.php:
 *    define('DISABLE_WP_CRON', true);
 *  Then add a real server cron job:
 *    * / 5 * * * * wget -q -O - https://ofastpipeline.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
 *  This is a hosting-level change, not a code change.
 *
 * Depends on: OFP_Queue, OFP_Subscription, OFP_Credit, OFP_CSV, OFP_Auth.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Cron_Handler {

    public function __construct() {
        add_action( 'ofp_process_queue',            [ $this, 'process_queue' ] );
        add_action( 'ofp_daily_subscription_check', [ $this, 'check_subscriptions' ] );
        add_action( 'ofp_daily_credit_check',       [ $this, 'check_credits' ] );
        add_action( 'ofp_monthly_archive',          [ $this, 'monthly_archive' ] );
    }

    /**
     * Process due trigger queue items.
     * Fires every 5 minutes. Dispatches up to 10 triggers per run.
     */
    public function process_queue(): void {
        OFP_Queue::process_due();
    }

    /**
     * Run daily subscription lifecycle check.
     * Sends reminders, moves expired clients through grace → suspended → cancelled.
     */
    public function check_subscriptions(): void {
        OFP_Subscription::run_daily_check();

        // Purge old activity logs to prevent database bloat (30 days default).
        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::purge_old_logs( 30 );
        }
    }

    /**
     * Run daily credit balance check.
     * Sends low-credit warnings where the low_warned flag is not yet set.
     */
    public function check_credits(): void {
        OFP_Credit::run_daily_check();
    }

    /**
     * Generate monthly CSV reports for all active clients.
     * Fires on the 1st of each month at 2 AM (scheduled by OFP_Activator).
     * Also cleans up old completed triggers and expired rate limit rows.
     */
    public function monthly_archive(): void {
        global $wpdb;

        $clients    = $wpdb->get_results(
            "SELECT id FROM {$wpdb->prefix}ofp_clients WHERE status != 'cancelled'"
        );
        $last_month = (int) gmdate( 'm', strtotime( 'last month' ) );
        $last_year  = (int) gmdate( 'Y', strtotime( 'last month' ) );

        foreach ( $clients as $client ) {
            if ( class_exists( 'OFP_CSV' ) && method_exists( 'OFP_CSV', 'generate_monthly_report' ) ) {
                OFP_CSV::generate_monthly_report( $client->id, $last_month, $last_year );
            }
        }

        // Clean completed / cancelled / failed triggers older than 90 days.
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}ofp_trigger_queue
             WHERE status IN ('completed','cancelled','failed')
               AND created_at < DATE_SUB( NOW(), INTERVAL 90 DAY )"
        );

        // Clean rate limit records older than 24 hours.
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}ofp_rate_limits
             WHERE created_at < DATE_SUB( NOW(), INTERVAL 1 DAY )"
        );

        // Permanently purge clients that have been in trash for 30+ days.
        if ( class_exists( 'OFP_Client' ) && method_exists( 'OFP_Client', 'purge_old_trash' ) ) {
            $purged = OFP_Client::purge_old_trash();
            if ( $purged > 0 ) {
                error_log( "[OFP_Cron_Handler] Purged {$purged} client(s) from trash (30+ days old)." );
            }
        }

        // Purge expired client sessions.
        OFP_Auth::purge_expired_sessions();
    }
}
