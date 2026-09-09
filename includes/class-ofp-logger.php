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
     * Action strings that are safe to also surface as a client-facing
     * notification when the actor was a team member.
     *
     * Deliberately an ALLOW list, not a block list — new log call sites
     * added later default to "not notified" until someone deliberately
     * adds them here. This also protects against a recursion loop:
     * OFP_Notification::create() can send an email via OFP_Mailer::send(),
     * which itself calls OFP_Logger::log('Email sent', ...) — if 'Email
     * sent' were on this list, that would trigger ANOTHER notification,
     * which sends ANOTHER email, forever. So mailer-originated actions
     * ('Email sent', 'Email failed') are never on this list.
     */
    const TEAM_MEMBER_NOTIFY_ACTIONS = [
        'SMS sent',
        'SMS failed',
        'Team member invited',
        'Team member removed',
        'Team member role updated',
    ];

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

        // Auto-detect the front-end actor (main client or a specific team
        // member) so every log line says WHO actually did it, not just
        // which client's account it happened under. This is what lets
        // wp-admin tell "the client did this" apart from "team member
        // Chidi did this" at a glance.
        $actor_type = 'system';
        $actor_id   = null;
        $actor_name = null;

        if ( class_exists( 'OFP_Auth' ) ) {
            $current = OFP_Auth::current_user();
            if ( $current ) {
                if ( ! empty( $current->is_team_member ) ) {
                    $actor_type = 'team_member';
                    $actor_id   = $current->id ?? null;
                    $actor_name = $current->name ?? null;
                } else {
                    $actor_type = 'client';
                    $actor_id   = $current->id ?? null;
                    $actor_name = $current->owner_name ?? null;
                }
            }
        }

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

        if ( $admin_id ) {
            $actor_type = 'admin';
            $actor_id   = $admin_id;
            $actor_name = null;
        }

        $details['actor_type'] = $actor_type;
        if ( $actor_id )   $details['actor_id']   = $actor_id;
        if ( $actor_name ) $details['actor_name'] = $actor_name;

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

        // If a team member did this (and it's on the allow list), make sure
        // the main client sees it — the whole point is a team member can
        // never quietly do something the client isn't shown.
        if (
            $actor_type === 'team_member'
            && $client_id
            && in_array( $action, self::TEAM_MEMBER_NOTIFY_ACTIONS, true )
            && class_exists( 'OFP_Notification' )
        ) {
            $who = $actor_name ?: 'A team member';
            OFP_Notification::create(
                $client_id,
                'team_member_activity',
                'Team activity',
                $who . ' — ' . $action . '.'
            );
        }

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
