<?php
/**
 * Final payment-plan frequency reconciliation.
 * Keeps accepted-offer schedules aligned with the stored Purchase frequency.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_footer', function (): void {
    if ( empty( $_POST['ofp_offer_action'] ) || 'accept' !== sanitize_key( $_POST['ofp_offer_action'] ) ) return;

    $token = sanitize_text_field( wp_unslash( $_GET['offer'] ?? '' ) );
    if ( ! $token ) return;

    global $wpdb;
    $p = $wpdb->prefix;
    $hash = hash( 'sha256', $token );

    $offer = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$p}ofp_property_offers WHERE offer_token_hash = %s LIMIT 1",
        $hash
    ) );
    if ( ! $offer || 'accepted' !== $offer->status ) return;

    $purchase = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$p}ofp_property_purchases WHERE offer_id = %d ORDER BY id DESC LIMIT 1",
        (int) $offer->id
    ) );
    if ( ! $purchase ) return;

    $installments = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$p}ofp_property_installments WHERE purchase_id = %d ORDER BY installment_no ASC",
        (int) $purchase->id
    ) );

    foreach ( $installments as $installment ) {
        if ( (float) $installment->amount_paid > 0 ) return;
    }

    $frequency = sanitize_key( $purchase->frequency ?: 'monthly' );
    $initial   = (float) $purchase->initial_payment;
    $base_date = $purchase->first_due_date ?: current_time( 'Y-m-d' );

    foreach ( $installments as $installment ) {
        $number = (int) $installment->installment_no;
        $offset = $initial > 0 ? max( 0, $number - 1 ) : max( 0, $number - 1 );
        $base_ts = strtotime( $base_date );
        if ( false === $base_ts ) continue;

        switch ( $frequency ) {
            case 'daily':
                $due = gmdate( 'Y-m-d', strtotime( "+{$offset} days", $base_ts ) );
                break;
            case 'weekly':
                $due = gmdate( 'Y-m-d', strtotime( "+{$offset} weeks", $base_ts ) );
                break;
            case 'quarterly':
                $due = gmdate( 'Y-m-d', strtotime( '+' . ( $offset * 3 ) . ' months', $base_ts ) );
                break;
            case 'yearly':
                $due = gmdate( 'Y-m-d', strtotime( "+{$offset} years", $base_ts ) );
                break;
            case 'monthly':
            default:
                $due = gmdate( 'Y-m-d', strtotime( "+{$offset} months", $base_ts ) );
                break;
        }

        $grace = gmdate(
            'Y-m-d',
            strtotime( $due . ' +' . (int) $purchase->grace_period_days . ' days' )
        );

        $wpdb->update(
            "{$p}ofp_property_installments",
            [
                'due_date'      => $due,
                'grace_ends_at' => $grace,
                'updated_at'    => current_time( 'mysql' ),
            ],
            [ 'id' => (int) $installment->id ]
        );
    }
}, 1002 );
