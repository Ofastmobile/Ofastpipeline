<?php
/**
 * Class OFP_Property_Transfer_Reminders
 *
 * Reminds property owners about buyer and tenant transfers that are still
 * waiting for approval. One email and one in app notice per owner, listing every
 * waiting payment. Reminders go out at 24 hours, 3 days and 7 days. No SMS, so
 * no credits are used.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Transfer_Reminders {

	const HOOK   = 'ofp_transfer_reminders';
	const OPTION = 'ofp_transfer_reminded';

	/** Hours after submission at which a reminder is due. */
	const STEPS = [ 24, 72, 168 ];

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::HOOK );
		}
	}

	public static function run(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		$waiting = [];
		$buyers = (array) $wpdb->get_results(
			"SELECT py.id, py.amount, py.created_at, pu.client_id, pr.title, pu.buyer_name AS who
			 FROM {$p}ofp_property_payments py
			 INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
			 LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
			 WHERE py.status = 'pending_verification' AND py.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
		);
		foreach ( $buyers as $r ) {
			$waiting[ 'p' . $r->id ] = [ 'client_id' => (int) $r->client_id, 'row' => $r, 'kind' => 'Buyer' ];
		}
		$tenants = (array) $wpdb->get_results(
			"SELECT py.id, py.amount, py.created_at, l.client_id, pr.title, t.full_name AS who
			 FROM {$p}ofp_property_lease_payments py
			 INNER JOIN {$p}ofp_property_leases l ON l.id = py.lease_id
			 INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
			 LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
			 WHERE py.status = 'pending_verification' AND py.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
		);
		foreach ( $tenants as $r ) {
			$waiting[ 'l' . $r->id ] = [ 'client_id' => (int) $r->client_id, 'row' => $r, 'kind' => 'Tenant' ];
		}

		$state = get_option( self::OPTION, [] );
		if ( ! is_array( $state ) ) {
			$state = [];
		}
		// Forget payments that are no longer waiting.
		$state = array_intersect_key( $state, $waiting );

		$due_by_client = [];
		$now           = time();
		foreach ( $waiting as $key => $w ) {
			$sent  = (int) ( $state[ $key ] ?? 0 );
			if ( $sent >= count( self::STEPS ) ) {
				continue;
			}
			$age_h = ( $now - strtotime( $w['row']->created_at ) ) / HOUR_IN_SECONDS;
			if ( $age_h >= self::STEPS[ $sent ] ) {
				$due_by_client[ $w['client_id'] ][ $key ] = $w;
			}
		}

		foreach ( $due_by_client as $client_id => $items ) {
			$client = OFP_Client::get( (int) $client_id );
			if ( ! $client ) {
				continue;
			}
			$lines = '';
			foreach ( $items as $key => $w ) {
				$lines .= sprintf(
					'<li>%s <strong>%s</strong> · ₦%s · %s · sent %s</li>',
					esc_html( $w['kind'] ),
					esc_html( $w['row']->who ?: 'Someone' ),
					esc_html( number_format( (float) $w['row']->amount, 2 ) ),
					esc_html( $w['row']->title ?: 'Property' ),
					esc_html( human_time_diff( strtotime( $w['row']->created_at ), $now ) . ' ago' )
				);
				$state[ $key ] = (int) ( $state[ $key ] ?? 0 ) + 1;
			}
			if ( ! empty( $client->email ) ) {
				OFP_Mailer::send(
					$client->email,
					$client->owner_name ?: 'there',
					count( $items ) === 1 ? 'A payment is still waiting for your approval' : count( $items ) . ' payments are still waiting for your approval',
					'<p>Hello ' . esc_html( $client->owner_name ?: 'there' ) . ',</p><p>These payments are waiting for you. Check your bank account, then approve or reject each one:</p><ul>' . $lines . '</ul><p><a href="' . esc_url( home_url( '/property-payments/' ) ) . '">Open Transactions</a> (buyers) or <a href="' . esc_url( add_query_arg( 'tab', 'payments', home_url( '/tenants' ) ) ) . '">the Tenants page</a> (tenants).</p>'
				);
			}
			if ( class_exists( 'OFP_Notification' ) ) {
				OFP_Notification::create( (int) $client_id, 'transfer_reminder', 'Payments waiting for your approval', count( $items ) . ' payment(s) from buyers or tenants are still waiting for you to approve or reject them.' );
			}
		}

		update_option( self::OPTION, $state, false );
	}
}
