<?php
/**
 * OFP_Logger
 *
 * Provides a simple API for logging system and client events
 * to the ofp_activity_logs database table.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Logger {
    /**
     * Log an action to the database.
     *
     * @param string   $action    A short descriptive string (e.g. 'client_created', 'settings_updated')
     * @param int|null $client_id ID of the client related to this action. Null if global.
     * @param array    $details   Any extra data to save as JSON.
     * @return bool
     */
    public static function log( string $action, ?int $client_id = null, array $details = [] ): bool {
        global $wpdb;

        $admin_id = null;
        if ( is_admin() && is_user_logged_in() ) {
            // Find OFP admin id if possible
            $current_user = wp_get_current_user();
            if ( $current_user->user_email ) {
                $admin = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ofp_admins WHERE email = %s LIMIT 1",
                    $current_user->user_email
                ) );
                if ( $admin ) {
                    $admin_id = $admin->id;
                }
            }
        }

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'ofp_activity_logs',
            [
                'client_id'  => $client_id,
                'admin_id'   => $admin_id,
                'action'     => substr( $action, 0, 100 ),
                'details'    => empty( $details ) ? null : wp_json_encode( $details ),
                'created_at' => current_time( 'mysql' ),
            ]
        );

        return (bool) $inserted;
    }

    /**
     * Purge logs older than a given number of days.
     * Default is 30 days to prevent database bloat.
     *
     * @param int $days Number of days to keep logs.
     * @return int Number of rows deleted.
     */
    public static function purge_old_logs( int $days = 30 ): int {
        global $wpdb;
        $days = max( 1, $days );
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ofp_activity_logs WHERE created_at < DATE_SUB( NOW(), INTERVAL %d DAY )",
            $days
        ) );

        return (int) $deleted;
    }
}
