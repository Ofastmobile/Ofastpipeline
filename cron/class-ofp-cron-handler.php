<?php
/**
 * OFP_Cron_Handler
 * Connects WP-Cron hooks to their handler methods.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once OFP_PATH . 'includes/class-ofp-property-installment-reminders.php';
require_once OFP_PATH . 'includes/class-ofp-property-lease-reminders.php';

class OFP_Cron_Handler {

    public function __construct() {
        add_action( 'ofp_process_queue',            [ $this, 'process_queue' ] );
        add_action( 'ofp_daily_subscription_check', [ $this, 'check_subscriptions' ] );
        add_action( 'ofp_daily_credit_check',       [ $this, 'check_credits' ] );
        add_action( 'ofp_monthly_archive',          [ $this, 'monthly_archive' ] );

        // Make sure every cron event exists, even after a plugin update with no reactivation.
        add_action( 'init', [ __CLASS__, 'ensure_schedules' ] );

        // Add the new instant email columns once, then clean up removed voice follow ups once.
        OFP_Queue::ensure_columns();
        OFP_Queue::migrate_voice_followups_once();
    }

    /**
     * Create any cron event that is missing. Safe to run on every request,
     * because an event that already exists is left alone.
     */
    public static function ensure_schedules(): void {
        // The queue runs every 2 minutes. An older 5 minute event is replaced once.
        $queue_schedule = wp_get_schedule( 'ofp_process_queue' );
        if ( $queue_schedule && 'ofp_two_minutes' !== $queue_schedule ) {
            wp_clear_scheduled_hook( 'ofp_process_queue' );
            $queue_schedule = false;
        }
        if ( ! $queue_schedule ) {
            wp_schedule_event( time() + 30, 'ofp_two_minutes', 'ofp_process_queue' );
        }
        if ( ! wp_next_scheduled( 'ofp_daily_subscription_check' ) ) {
            wp_schedule_event( time() + 120, 'daily', 'ofp_daily_subscription_check' );
        }
        if ( ! wp_next_scheduled( 'ofp_daily_credit_check' ) ) {
            wp_schedule_event( time() + 180, 'daily', 'ofp_daily_credit_check' );
        }
        if ( ! wp_next_scheduled( 'ofp_monthly_archive' ) ) {
            wp_schedule_event( strtotime( 'first day of next month 02:00:00' ), 'monthly', 'ofp_monthly_archive' );
        }
    }

    public function process_queue(): void {
        try {
            OFP_Queue::process_due();
        } catch ( \Throwable $e ) {
            error_log( '[OFP_Cron_Handler] process_queue failed: ' . $e->getMessage() );
        }
    }

    public function check_subscriptions(): void {
        OFP_Subscription::run_daily_check();
        OFP_Property_Installment_Reminders::run_daily();
        OFP_Property_Lease_Reminders::run_daily();

        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::purge_old_logs( 30 );
        }
    }

    public function check_credits(): void {
        OFP_Credit::run_daily_check();
    }

    public function monthly_archive(): void {
        global $wpdb;

        $clients = $wpdb->get_results( "SELECT id FROM {$wpdb->prefix}ofp_clients WHERE status != 'cancelled'" );
        $last_month = (int) gmdate( 'm', strtotime( 'last month' ) );
        $last_year  = (int) gmdate( 'Y', strtotime( 'last month' ) );

        foreach ( $clients as $client ) {
            if ( class_exists( 'OFP_CSV' ) && method_exists( 'OFP_CSV', 'generate_monthly_report' ) ) {
                OFP_CSV::generate_monthly_report( $client->id, $last_month, $last_year );
            }
        }

        $wpdb->query( "DELETE FROM {$wpdb->prefix}ofp_trigger_queue WHERE status IN ('completed','cancelled','failed') AND created_at < DATE_SUB( NOW(), INTERVAL 90 DAY )" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}ofp_rate_limits WHERE created_at < DATE_SUB( NOW(), INTERVAL 1 DAY )" );

        if ( class_exists( 'OFP_Client' ) && method_exists( 'OFP_Client', 'purge_old_trash' ) ) {
            $purged = OFP_Client::purge_old_trash();
            if ( $purged > 0 ) error_log( "[OFP_Cron_Handler] Purged {$purged} client(s) from trash (30+ days old)." );
        }

        OFP_Auth::purge_expired_sessions();
    }
}
