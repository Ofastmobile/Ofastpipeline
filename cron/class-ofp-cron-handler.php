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
        // P4.01/P6.01: property table repair, once a day (it used to run on every request).
        if ( ! wp_next_scheduled( 'ofp_daily_property_reconcile' ) ) {
            wp_schedule_event( time() + 240, 'daily', 'ofp_daily_property_reconcile' );
        }
        // Monthly job: a single event on the 1st at 02:00 (site time) that books the
        // next one when it finishes. The old 30 day repeating event drifted off the
        // 1st, so it is replaced once (P4.05).
        if ( wp_get_schedule( 'ofp_monthly_archive' ) ) {
            wp_clear_scheduled_hook( 'ofp_monthly_archive' );
        }
        self::schedule_next_monthly();
    }

    /**
     * Books the next monthly run: the 1st of next month, 02:00, site time.
     * Does nothing if one is already booked.
     */
    public static function schedule_next_monthly(): void {
        if ( wp_next_scheduled( 'ofp_monthly_archive' ) ) {
            return;
        }
        $next = new DateTimeImmutable( 'first day of next month 02:00:00', wp_timezone() );
        wp_schedule_single_event( $next->getTimestamp(), 'ofp_monthly_archive' );
    }

    /**
     * Runs one job so that if it fails, the jobs after it still run (P4.06).
     */
    private static function run_safely( string $label, callable $job ): void {
        try {
            $job();
        } catch ( \Throwable $e ) {
            error_log( '[OFP_Cron_Handler] ' . $label . ' failed: ' . $e->getMessage() );
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
        self::run_safely( 'subscription check', [ 'OFP_Subscription', 'run_daily_check' ] );
        self::run_safely( 'installment reminders', [ 'OFP_Property_Installment_Reminders', 'run_daily' ] );
        self::run_safely( 'lease reminders', [ 'OFP_Property_Lease_Reminders', 'run_daily' ] );

        if ( class_exists( 'OFP_Logger' ) ) {
            self::run_safely( 'log purge', function (): void {
                OFP_Logger::purge_old_logs( 30 );
            } );
        }
    }

    public function check_credits(): void {
        self::run_safely( 'credit check', [ 'OFP_Credit', 'run_daily_check' ] );
    }

    public function monthly_archive(): void {
        global $wpdb;

        // Book next month first, so one failure below can never stop the monthly job for good.
        self::schedule_next_monthly();

        // First day of LAST month. (strtotime('last month') on the 31st gives the wrong month.)
        $last       = new DateTimeImmutable( 'first day of last month', wp_timezone() );
        $last_month = (int) $last->format( 'n' );
        $last_year  = (int) $last->format( 'Y' );

        $clients = $wpdb->get_results( "SELECT id FROM {$wpdb->prefix}ofp_clients WHERE status != 'cancelled'" );
        foreach ( $clients as $client ) {
            if ( class_exists( 'OFP_CSV' ) && method_exists( 'OFP_CSV', 'generate_monthly_report' ) ) {
                self::run_safely( 'monthly report for client ' . (int) $client->id, function () use ( $client, $last_month, $last_year ): void {
                    OFP_CSV::generate_monthly_report( $client->id, $last_month, $last_year );
                } );
            }
        }

        self::run_safely( 'queue and rate limit clean up', function () use ( $wpdb ): void {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}ofp_trigger_queue WHERE status IN ('completed','cancelled','failed') AND created_at < DATE_SUB( NOW(), INTERVAL 90 DAY )" );
            $wpdb->query( "DELETE FROM {$wpdb->prefix}ofp_rate_limits WHERE created_at < DATE_SUB( NOW(), INTERVAL 1 DAY )" );
        } );

        if ( class_exists( 'OFP_Client' ) && method_exists( 'OFP_Client', 'purge_old_trash' ) ) {
            self::run_safely( 'trash purge', function (): void {
                $purged = OFP_Client::purge_old_trash();
                if ( $purged > 0 ) error_log( "[OFP_Cron_Handler] Purged {$purged} client(s) from trash (30+ days old)." );
            } );
        }

        self::run_safely( 'session purge', [ 'OFP_Auth', 'purge_expired_sessions' ] );
    }
}
