<?php
/**
 * Class OFP_Contacts
 *
 * One place that knows who a client's people are.
 *
 *  - A LEAD is someone who showed interest. It has a status
 *    (new, contacted, interested, converted, dead).
 *  - A CUSTOMER is someone who bought or rented. It is NOT a separate table.
 *    The Customers list is built from three sources and merged by phone:
 *      1. buyers (property purchases)
 *      2. tenants
 *      3. leads marked converted
 *  - When a purchase or a rent payment is recorded for a phone or email that
 *    is also a lead, that lead is marked converted automatically.
 *
 * Phones are matched on their last 10 digits, so 08031234567, +2348031234567
 * and 2348031234567 are the same person.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Contacts {

    const LIMIT = 5000;

    public static function init(): void {
        add_action( 'ofp_property_purchase_created',        [ __CLASS__, 'on_purchase_created' ], 20, 1 );
        add_action( 'ofp_property_lease_payment_processed', [ __CLASS__, 'on_lease_payment' ],    20, 1 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MATCHING
    // ─────────────────────────────────────────────────────────────────────────

    /** Last 10 digits. Empty string when there is no usable phone. */
    public static function phone_key( string $phone ): string {
        $digits = preg_replace( '/\D+/', '', $phone );
        if ( strlen( $digits ) < 7 ) {
            return '';
        }
        return strlen( $digits ) > 10 ? substr( $digits, -10 ) : $digits;
    }

    public static function email_key( string $email ): string {
        $email = strtolower( trim( $email ) );
        return is_email( $email ) ? $email : '';
    }

    /** The key a person is filed under: phone first, then email. */
    public static function person_key( string $phone, string $email ): string {
        $p = self::phone_key( $phone );
        if ( $p !== '' ) {
            return 'p:' . $p;
        }
        $e = self::email_key( $email );
        return $e !== '' ? 'e:' . $e : '';
    }

    /**
     * Phones and emails a client already has as leads, so imports can skip them.
     *
     * @return array{phones:array<string,int>,emails:array<string,int>}  key => lead id
     */
    public static function lead_keys( int $client_id ): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, phone, email FROM {$wpdb->prefix}ofp_leads WHERE client_id = %d LIMIT %d",
            $client_id,
            self::LIMIT * 10
        ) );
        $out = [ 'phones' => [], 'emails' => [] ];
        foreach ( $rows as $r ) {
            $pk = self::phone_key( (string) $r->phone );
            if ( $pk !== '' ) {
                $out['phones'][ $pk ] = (int) $r->id;
            }
            $ek = self::email_key( (string) $r->email );
            if ( $ek !== '' ) {
                $out['emails'][ $ek ] = (int) $r->id;
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AUTO CONVERT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Marks every open lead of this client with the same phone or email as converted,
     * and stops any automatic follow ups for it.
     *
     * @return int How many leads were converted.
     */
    public static function mark_converted( int $client_id, string $phone, string $email ): int {
        global $wpdb;
        $pk = self::phone_key( $phone );
        $ek = self::email_key( $email );
        if ( ! $client_id || ( $pk === '' && $ek === '' ) ) {
            return 0;
        }

        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, phone, email FROM {$wpdb->prefix}ofp_leads WHERE client_id = %d AND status <> 'converted' LIMIT %d",
            $client_id,
            self::LIMIT * 10
        ) );

        $done = 0;
        foreach ( $rows as $r ) {
            $same = ( $pk !== '' && self::phone_key( (string) $r->phone ) === $pk )
                 || ( $ek !== '' && self::email_key( (string) $r->email ) === $ek );
            if ( ! $same ) {
                continue;
            }
            OFP_Lead::update_status( (int) $r->id, 'converted' );
            if ( class_exists( 'OFP_Queue' ) ) {
                OFP_Queue::cancel_for_lead( (int) $r->id );
            }
            $done++;
        }
        return $done;
    }

    public static function on_purchase_created( $purchase_id ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT client_id, buyer_phone, buyer_email FROM {$wpdb->prefix}ofp_property_purchases WHERE id = %d LIMIT 1",
            (int) $purchase_id
        ) );
        if ( $row && $row->client_id ) {
            self::mark_converted( (int) $row->client_id, (string) $row->buyer_phone, (string) $row->buyer_email );
        }
    }

    public static function on_lease_payment( $lease_id ): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.client_id, t.phone, t.email
             FROM {$p}ofp_property_leases l
             INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
             WHERE l.id = %d LIMIT 1",
            (int) $lease_id
        ) );
        if ( $row && $row->client_id ) {
            self::mark_converted( (int) $row->client_id, (string) $row->phone, (string) $row->email );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CUSTOMERS (built from buyers, tenants and converted leads)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param int $client_id 0 for all clients (admin view), or a specific client ID.
     * @return array<string,array> keyed by person key, newest activity first.
     *   Each: key, name, phone, email, tags[], purchases, total, paid, balance, leases,
     *         last_at, purchase_ids[], tenant_ids[], lead_ids[], client_id, client_name, client_names[]
     */
    public static function customers( int $client_id = 0 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $people   = [];
        $by_email = [];

        $touch = static function ( string $name, string $phone, string $email, string $when, int $row_client_id = 0, string $client_name = '' ) use ( &$people, &$by_email ): ?string {
            $key = self::person_key( $phone, $email );
            $ek  = self::email_key( $email );
            if ( $key === '' ) {
                return null;
            }
            // Same email under a different phone is still the same person.
            if ( ! isset( $people[ $key ] ) && $ek !== '' && isset( $by_email[ $ek ] ) ) {
                $key = $by_email[ $ek ];
            }
            if ( ! isset( $people[ $key ] ) ) {
                $people[ $key ] = [
                    'key' => $key, 'name' => '', 'phone' => '', 'email' => '', 'tags' => [],
                    'purchases' => 0, 'total' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'leases' => 0,
                    'last_at' => '', 'purchase_ids' => [], 'tenant_ids' => [], 'lead_ids' => [],
                    'client_id' => $row_client_id, 'client_name' => $client_name, 'client_names' => [],
                ];
            }
            $person = &$people[ $key ];
            if ( $person['name'] === '' && $name !== '' ) $person['name'] = $name;
            if ( $person['phone'] === '' && $phone !== '' ) $person['phone'] = $phone;
            if ( $person['email'] === '' && $email !== '' ) $person['email'] = $email;
            if ( $when !== '' && $when > $person['last_at'] ) $person['last_at'] = $when;
            if ( $row_client_id > 0 ) {
                if ( empty( $person['client_id'] ) ) {
                    $person['client_id'] = $row_client_id;
                }
                if ( $client_name !== '' ) {
                    $person['client_names'][ $row_client_id ] = $client_name;
                    if ( empty( $person['client_name'] ) ) {
                        $person['client_name'] = $client_name;
                    }
                }
            }
            if ( $ek !== '' ) $by_email[ $ek ] = $key;
            return $key;
        };

        if ( $client_id > 0 ) {
            $buyers = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT pu.id, pu.buyer_name, pu.buyer_phone, pu.buyer_email, pu.total_price, pu.amount_paid, pu.balance, pu.created_at,
                        pu.client_id, c.business_name AS client_name
                 FROM {$p}ofp_property_purchases pu
                 LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
                 WHERE pu.client_id = %d AND pu.status IN ('active','completed')
                 ORDER BY pu.created_at DESC LIMIT %d",
                $client_id, self::LIMIT
            ) );
        } else {
            $buyers = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT pu.id, pu.buyer_name, pu.buyer_phone, pu.buyer_email, pu.total_price, pu.amount_paid, pu.balance, pu.created_at,
                        pu.client_id, c.business_name AS client_name
                 FROM {$p}ofp_property_purchases pu
                 LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
                 WHERE pu.status IN ('active','completed')
                 ORDER BY pu.created_at DESC LIMIT %d",
                self::LIMIT
            ) );
        }
        foreach ( $buyers as $b ) {
            $key = $touch( (string) $b->buyer_name, (string) $b->buyer_phone, (string) $b->buyer_email, (string) $b->created_at, (int) ( $b->client_id ?? 0 ), (string) ( $b->client_name ?? '' ) );
            if ( $key === null ) continue;
            $people[ $key ]['tags']['Buyer'] = true;
            $people[ $key ]['purchases']++;
            $people[ $key ]['total']   += (float) $b->total_price;
            $people[ $key ]['paid']    += (float) $b->amount_paid;
            $people[ $key ]['balance'] += (float) $b->balance;
            $people[ $key ]['purchase_ids'][] = (int) $b->id;
        }

        if ( $client_id > 0 ) {
            $tenants = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT t.id, t.full_name, t.phone, t.email, t.created_at, t.client_id, c.business_name AS client_name,
                        (SELECT COUNT(*) FROM {$p}ofp_property_leases l WHERE l.tenant_id = t.id) AS lease_count
                 FROM {$p}ofp_property_tenants t
                 LEFT JOIN {$p}ofp_clients c ON c.id = t.client_id
                 WHERE t.client_id = %d ORDER BY t.created_at DESC LIMIT %d",
                $client_id, self::LIMIT
            ) );
        } else {
            $tenants = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT t.id, t.full_name, t.phone, t.email, t.created_at, t.client_id, c.business_name AS client_name,
                        (SELECT COUNT(*) FROM {$p}ofp_property_leases l WHERE l.tenant_id = t.id) AS lease_count
                 FROM {$p}ofp_property_tenants t
                 LEFT JOIN {$p}ofp_clients c ON c.id = t.client_id
                 ORDER BY t.created_at DESC LIMIT %d",
                self::LIMIT
            ) );
        }
        foreach ( $tenants as $t ) {
            $key = $touch( (string) $t->full_name, (string) $t->phone, (string) $t->email, (string) $t->created_at, (int) ( $t->client_id ?? 0 ), (string) ( $t->client_name ?? '' ) );
            if ( $key === null ) continue;
            $people[ $key ]['tags']['Tenant'] = true;
            $people[ $key ]['leases']      += (int) $t->lease_count;
            $people[ $key ]['tenant_ids'][] = (int) $t->id;
        }

        if ( $client_id > 0 ) {
            $leads = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT l.id, l.name, l.phone, l.email, COALESCE(l.converted_at, l.created_at) AS at, l.client_id, c.business_name AS client_name
                 FROM {$p}ofp_leads l
                 LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
                 WHERE l.client_id = %d AND l.status = 'converted'
                 ORDER BY l.created_at DESC LIMIT %d",
                $client_id, self::LIMIT
            ) );
        } else {
            $leads = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT l.id, l.name, l.phone, l.email, COALESCE(l.converted_at, l.created_at) AS at, l.client_id, c.business_name AS client_name
                 FROM {$p}ofp_leads l
                 LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
                 WHERE l.status = 'converted'
                 ORDER BY l.created_at DESC LIMIT %d",
                self::LIMIT
            ) );
        }
        foreach ( $leads as $l ) {
            $key = $touch( (string) $l->name, (string) $l->phone, (string) $l->email, (string) $l->at, (int) ( $l->client_id ?? 0 ), (string) ( $l->client_name ?? '' ) );
            if ( $key === null ) continue;
            $people[ $key ]['tags']['Converted lead'] = true;
            $people[ $key ]['lead_ids'][] = (int) $l->id;
        }

        foreach ( $people as &$person ) {
            $person['tags'] = array_keys( $person['tags'] );
            // "Converted lead" is only worth showing when it is the only reason they are here.
            if ( count( $person['tags'] ) > 1 ) {
                $person['tags'] = array_values( array_diff( $person['tags'], [ 'Converted lead' ] ) );
            }
            if ( ! empty( $person['client_names'] ) ) {
                $person['client_name'] = implode( ', ', array_unique( $person['client_names'] ) );
            }
        }
        unset( $person );

        uasort( $people, static fn( $a, $b ) => strcmp( (string) $b['last_at'], (string) $a['last_at'] ) );
        return $people;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BROADCAST AUDIENCES
    // ─────────────────────────────────────────────────────────────────────────

    public static function audience_types(): array {
        return [
            'leads_all'      => 'All leads',
            'leads_status'   => 'Leads by status',
            'leads_batch'    => 'Leads from one import batch',
            'leads_selected' => 'Leads I selected',
            'buyers'         => 'Buyers',
            'tenants'        => 'Tenants',
            'customers'      => 'All customers (buyers, tenants, converted leads)',
        ];
    }

    /**
     * People to message, already filtered for the channel and with duplicates removed.
     *
     * @param string $channel sms or email
     * @param string $type    one of audience_types()
     * @param array  $opts    status, batch, ids
     * @param int|null $left_out Set to how many people were left out (no usable phone or email, or a repeat).
     * @return array<int,array{name:string,phone:string,email:string}>
     */
    public static function audience( int $client_id, string $channel, string $type, array $opts = [], ?int &$left_out = null ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = [];

        switch ( $type ) {
            case 'leads_all':
            case 'leads_status':
            case 'leads_batch':
            case 'leads_selected':
                $where = 'client_id = %d';
                $args  = [ $client_id ];
                if ( 'leads_status' === $type ) {
                    $where .= ' AND status = %s';
                    $args[] = (string) ( $opts['status'] ?? 'new' );
                } elseif ( 'leads_batch' === $type ) {
                    $where .= ' AND source = %s';
                    $args[] = (string) ( $opts['batch'] ?? '' );
                } elseif ( 'leads_selected' === $type ) {
                    $ids = array_values( array_filter( array_map( 'absint', (array) ( $opts['ids'] ?? [] ) ) ) );
                    if ( empty( $ids ) ) {
                        return [];
                    }
                    $where .= ' AND id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
                    $args   = array_merge( $args, $ids );
                }
                $args[] = self::LIMIT;
                $rows   = (array) $wpdb->get_results( $wpdb->prepare(
                    "SELECT name, phone, email FROM {$p}ofp_leads WHERE {$where} ORDER BY created_at DESC LIMIT %d",
                    ...$args
                ) );
                break;

            case 'buyers':
                $rows = (array) $wpdb->get_results( $wpdb->prepare(
                    "SELECT buyer_name AS name, buyer_phone AS phone, buyer_email AS email
                     FROM {$p}ofp_property_purchases WHERE client_id = %d AND status IN ('active','completed')
                     ORDER BY created_at DESC LIMIT %d",
                    $client_id, self::LIMIT
                ) );
                break;

            case 'tenants':
                $rows = (array) $wpdb->get_results( $wpdb->prepare(
                    "SELECT full_name AS name, phone, email FROM {$p}ofp_property_tenants
                     WHERE client_id = %d ORDER BY created_at DESC LIMIT %d",
                    $client_id, self::LIMIT
                ) );
                break;

            case 'customers':
                foreach ( self::customers( $client_id ) as $c ) {
                    $rows[] = (object) [ 'name' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email'] ];
                }
                break;

            default:
                return [];
        }

        $out  = [];
        $seen = [];
        $total = count( $rows );
        foreach ( $rows as $r ) {
            $phone = trim( (string) $r->phone );
            $email = trim( (string) $r->email );
            if ( 'sms' === $channel ) {
                $key = self::phone_key( $phone );
                if ( $key === '' ) continue;
            } else {
                $key = self::email_key( $email );
                if ( $key === '' ) continue;
            }
            if ( isset( $seen[ $key ] ) ) continue;
            $seen[ $key ] = true;
            $out[] = [ 'name' => (string) $r->name, 'phone' => $phone, 'email' => $email ];
        }
        $left_out = $total - count( $out );
        return $out;
    }

    /**
     * The lead filter used by the Leads page and by "Message all in this filter".
     *
     * @return array{0:string,1:array} SQL where text (table alias l) and its values.
     */
    public static function lead_filter( int $client_id, string $status = '', string $q = '', string $batch = '' ): array {
        global $wpdb;
        $where = 'l.client_id = %d';
        $args  = [ $client_id ];
        if ( $status !== '' ) {
            $where .= ' AND l.status = %s';
            $args[] = $status;
        }
        if ( $batch !== '' ) {
            $where .= ' AND l.source = %s';
            $args[] = $batch;
        }
        if ( $q !== '' ) {
            $like   = '%' . $wpdb->esc_like( $q ) . '%';
            $where .= ' AND ( l.name LIKE %s OR l.phone LIKE %s OR l.email LIKE %s )';
            array_push( $args, $like, $like, $like );
        }
        return [ $where, $args ];
    }

    /** @return int[] Lead ids matching a filter, newest first. */
    public static function lead_ids_matching( int $client_id, string $status, string $q, string $batch, int $limit ): array {
        global $wpdb;
        [ $where, $args ] = self::lead_filter( $client_id, $status, $q, $batch );
        $args[] = $limit;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT l.id FROM {$wpdb->prefix}ofp_leads l WHERE {$where} ORDER BY l.created_at DESC LIMIT %d",
            ...$args
        ) ) );
    }

    /** Keeps only the ids that really belong to this client. */
    public static function own_lead_ids( int $client_id, array $ids ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        if ( empty( $ids ) ) {
            return [];
        }
        $in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_leads WHERE client_id = %d AND id IN ({$in})",
            array_merge( [ $client_id ], $ids )
        ) ) );
    }

    /** Import batches a client has used. Each import labels its leads with the source "import: <name>". */
    public static function batches( int $client_id ): array {
        global $wpdb;
        return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT source FROM {$wpdb->prefix}ofp_leads
             WHERE client_id = %d AND source LIKE %s ORDER BY source ASC LIMIT 200",
            $client_id,
            $wpdb->esc_like( 'import: ' ) . '%'
        ) ) );
    }
}
