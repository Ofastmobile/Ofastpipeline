<?php
/**
 * Class OFP_Notification
 *
 * Phase 17 — notification system.
 *
 * Handles creating notifications, fetching them for the bell,
 * marking them as read, and checking a client's delivery preference
 * (bell only, email only, or both).
 *
 * Every other part of the plugin that needs to notify a client
 * (manual funding approved, subscription renewed, property approved,
 * etc.) should call OFP_Notification::create() — one method, one
 * place, handles both bell and email automatically based on what the
 * client chose in their settings.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Notification {

	/**
	 * Delivery preference values stored in ofp_notification_pref column.
	 * 'both' is the default for new clients.
	 */
	const PREF_BOTH  = 'both';
	const PREF_BELL  = 'bell';
	const PREF_EMAIL = 'email';

	/**
	 * Creates a notification for a client. Automatically delivers
	 * via bell, email, or both based on the client's saved preference.
	 *
	 * @param int    $client_id
	 * @param string $type      short machine key, e.g. 'manual_funding_received', 'property_approved'
	 * @param string $title     short title shown in the bell dropdown
	 * @param string $message   full message — used in both bell and email body
	 * @return int|false  new notification row ID, or false on failure
	 */
	public static function create( int $client_id, string $type, string $title, string $message ) {
		global $wpdb;

		$client = OFP_Client::get( $client_id );
		if ( ! $client ) return false;

		$pref = $client->ofp_notification_pref ?? self::PREF_BOTH;

		// Always insert a bell row unless preference is email-only.
		$notification_id = false;
		if ( in_array( $pref, [ self::PREF_BOTH, self::PREF_BELL ], true ) ) {
			$wpdb->insert( $wpdb->prefix . 'ofp_notifications', [
				'client_id'  => $client_id,
				'type'       => sanitize_text_field( $type ),
				'title'      => sanitize_text_field( $title ),
				'message'    => sanitize_textarea_field( $message ),
				'is_read'    => 0,
				'created_at' => current_time( 'mysql' ),
			] );
			$notification_id = $wpdb->insert_id;
		}

		// Send email unless preference is bell-only.
		if ( in_array( $pref, [ self::PREF_BOTH, self::PREF_EMAIL ], true ) ) {
			OFP_Mailer::send_transactional(
				$client->email,
				$client->owner_name,
				$title,
				$message
			);
		}

		return $notification_id;
	}

	/**
	 * How many unread notifications a client has — used for the
	 * red badge number on the bell icon.
	 *
	 * @param int $client_id
	 * @return int
	 */
	public static function unread_count( int $client_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "
			SELECT COUNT(*) FROM {$wpdb->prefix}ofp_notifications
			WHERE client_id = %d AND is_read = 0
		", $client_id ) );
	}

	/**
	 * Fetch recent notifications for the bell dropdown, newest first.
	 *
	 * @param int $client_id
	 * @param int $limit     how many to return (default 10)
	 * @return array
	 */
	public static function get_recent( int $client_id, int $limit = 10 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "
			SELECT * FROM {$wpdb->prefix}ofp_notifications
			WHERE client_id = %d
			ORDER BY created_at DESC
			LIMIT %d
		", $client_id, $limit ) );
	}

	/**
	 * Mark one specific notification as read.
	 *
	 * @param int $notification_id
	 * @param int $client_id used to verify ownership before marking
	 */
	public static function mark_read( int $notification_id, int $client_id ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'ofp_notifications',
			[ 'is_read' => 1 ],
			[ 'id' => $notification_id, 'client_id' => $client_id ]
		);
	}

	/**
	 * Mark ALL of a client's notifications as read — called when
	 * they open the bell dropdown.
	 *
	 * @param int $client_id
	 */
	public static function mark_all_read( int $client_id ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'ofp_notifications',
			[ 'is_read' => 1 ],
			[ 'client_id' => $client_id ]
		);
	}

	/**
	 * Saves a client's notification delivery preference.
	 * Called from their Settings page form handler.
	 *
	 * @param int    $client_id
	 * @param string $pref 'both'|'bell'|'email'
	 */
	public static function save_preference( int $client_id, string $pref ): void {
		if ( ! in_array( $pref, [ self::PREF_BOTH, self::PREF_BELL, self::PREF_EMAIL ], true ) ) {
			$pref = self::PREF_BOTH;
		}
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'ofp_clients',
			[ 'ofp_notification_pref' => $pref ],
			[ 'id' => $client_id ]
		);
	}
}
