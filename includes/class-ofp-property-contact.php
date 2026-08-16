<?php
/**
 * Standalone property buyer/contact records.
 *
 * This is NOT a CRM lead table and does not create a user account.
 * A contact may optionally be linked to a lead and can have multiple purchases.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Contact {

    public static function init(): void {
        // Reconcile purchases at shutdown so admin, client and public offer
        // creation paths all receive a contact without duplicated hooks.
        add_action( 'shutdown', [ __CLASS__, 'sync_missing_purchase_contacts' ], 999 );
    }

    public static function normalize_phone( string $phone ): string {
        return OFP_Security::sanitize_phone( $phone );
    }

    public static function find_or_create( array $data ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $client_id = array_key_exists( 'client_id', $data ) && $data['client_id'] !== null ? absint( $data['client_id'] ) : null;
        $phone     = self::normalize_phone( (string) ( $data['phone'] ?? '' ) );
        $email     = ! empty( $data['email'] ) ? sanitize_email( $data['email'] ) : null;
        $name      = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        $source    = sanitize_key( (string) ( $data['source'] ?? 'offline' ) );
        $notes     = ! empty( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null;

        if ( ! $name || ! $phone ) return 0;

        $sql  = "SELECT id FROM {$p}ofp_property_contacts WHERE phone = %s AND " . ( $client_id === null ? 'client_id IS NULL' : 'client_id = %d' );
        $args = $client_id === null ? [ $phone ] : [ $phone, $client_id ];
        $existing = $wpdb->get_var( $wpdb->prepare( $sql . ' ORDER BY id ASC LIMIT 1', ...$args ) );

        if ( $existing ) {
            $wpdb->update(
                "{$p}ofp_property_contacts",
                [
                    'name'       => $name,
                    'email'      => $email,
                    'source'     => $source,
                    'notes'      => $notes,
                    'updated_at' => current_time( 'mysql' ),
                ],
                [ 'id' => (int) $existing ]
            );
            return (int) $existing;
        }

        $inserted = $wpdb->insert(
            "{$p}ofp_property_contacts",
            [
                'client_id'  => $client_id,
                'name'       => $name,
                'phone'      => $phone,
                'email'      => $email,
                'source'     => $source,
                'notes'      => $notes,
                'created_at' => current_time( 'mysql' ),
                'updated_at' => current_time( 'mysql' ),
            ]
        );

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public static function ensure_for_purchase( int $purchase_id ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $purchase = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_purchases WHERE id = %d LIMIT 1",
            $purchase_id
        ) );
        if ( ! $purchase ) return 0;

        $contact_id = self::find_or_create([
            'client_id' => $purchase->client_id !== null ? (int) $purchase->client_id : null,
            'name'      => $purchase->buyer_name,
            'phone'     => $purchase->buyer_phone,
            'email'     => $purchase->buyer_email,
            'source'    => $purchase->lead_id ? 'lead' : 'offline',
        ]);

        if ( $contact_id ) {
            $wpdb->update(
                "{$p}ofp_property_purchases",
                [ 'contact_id' => $contact_id, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => $purchase_id ]
            );
        }

        return $contact_id;
    }

    public static function sync_missing_purchase_contacts(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $ids = $wpdb->get_col(
            "SELECT id FROM {$p}ofp_property_purchases
             WHERE contact_id IS NULL
             ORDER BY id ASC
             LIMIT 50"
        );

        foreach ( $ids as $purchase_id ) {
            self::ensure_for_purchase( (int) $purchase_id );
        }
    }

    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ofp_property_contacts WHERE id = %d LIMIT 1",
            $id
        ) );
    }
}
