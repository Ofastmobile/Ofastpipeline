<?php
/**
 * OFP_Deactivator
 *
 * Runs when the plugin is deactivated (not deleted).
 * Only clears WP-Cron events — database tables and options are intentionally
 * preserved so re-activation restores full functionality without data loss.
 *
 * To actually drop all tables and wipe data, use uninstall.php (not implemented
 * by default to protect against accidental data loss in production).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Deactivator {

    /**
     * Main deactivation entry point.
     */
    public static function deactivate(): void {
        self::clear_cron_events();

        // NOTE: We deliberately do NOT call flush_rewrite_rules() here.
        // The same WordPress timing issue that affects activation applies
        // to deactivation — our custom routes are registered on 'init',
        // which has not run with this plugin's hooks in the current request
        // at the point this callback fires. Flushing here would just as
        // easily corrupt the rewrite cache as fix it. Once the plugin is
        // deactivated, its routes naturally stop resolving on the next
        // request regardless — no flush is needed to "turn them off".
        // If reactivating later, OFP_Activator::activate() schedules a
        // proper deferred flush via the 'ofp_flush_rewrite_rules' option.
    }

    /**
     * Removes every scheduled cron hook registered by this plugin.
     * Safe to call even if the event was never scheduled.
     */
    private static function clear_cron_events(): void {
        $hooks = [
            'ofp_process_queue',
            'ofp_daily_subscription_check',
            'ofp_daily_credit_check',
            'ofp_monthly_archive',
            'ofp_daily_property_reconcile',
        ];

        foreach ( $hooks as $hook ) {
            $timestamp = wp_next_scheduled( $hook );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $hook );
            }
            // Belt-and-suspenders: clear ALL instances of this hook.
            wp_clear_scheduled_hook( $hook );
        }
    }
}
