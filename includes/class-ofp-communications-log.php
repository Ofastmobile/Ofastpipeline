<?php
/**
 * OFP_Communications_Log
 *
 * Centralized logger and query service for all outgoing and incoming communications
 * (SMS, Email, Voice) across Leads, Transactions, Tenants, Inquiries, Broadcasts, and System Alerts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Communications_Log {

    /**
     * Ensure the database table schema accommodates non-lead communications.
     */
    public static function ensure_schema(): void {
        static $checked = false;
        if ( $checked ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofp_communications_log';

        // Check if recipient column exists
        $col = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'recipient'" );
        if ( ! $col ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN recipient VARCHAR(255) DEFAULT NULL AFTER lead_id" );
        }
        // Email is a structured message.  Keeping its subject and original HTML
        // separately prevents a preview/resend from having to guess at content.
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'email_subject'" ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN email_subject VARCHAR(255) NULL AFTER message" );
        }
        if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'email_body_html'" ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN email_body_html LONGTEXT NULL AFTER email_subject" );
        }
        $wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN lead_id BIGINT UNSIGNED DEFAULT NULL" );

        $checked = true;
    }

    /**
     * Log a communication record.
     *
     * @param int         $client_id    Client ID.
     * @param int|null    $lead_id      Lead ID (optional, null for transactions/tenants/direct).
     * @param string      $type         Channel type: 'sms', 'email', 'voice'.
     * @param string      $recipient    Recipient identifier (Phone number or Email address).
     * @param string      $direction    'outbound' or 'inbound'.
     * @param string|null $message      Message body / content.
     * @param float       $cost         Credit cost.
     * @param string      $status       'sent', 'delivered', 'failed', 'pending', 'queued'.
     * @param string|null $provider     Provider slug (e.g. 'smartsms', 'africastalking', 'smtp').
     * @param string|null $provider_ref Reference code from provider.
     * @return int Inserted ID.
     */
    public static function log(
        int $client_id,
        ?int $lead_id,
        string $type,
        string $recipient,
        string $direction = 'outbound',
        ?string $message = '',
        float $cost = 0.00,
        string $status = 'sent',
        ?string $provider = null,
        ?string $provider_ref = null,
        ?string $email_subject = null,
        ?string $email_body_html = null
    ): int {
        self::ensure_schema();

        global $wpdb;
        $table = $wpdb->prefix . 'ofp_communications_log';

        $data = [
            'client_id'    => $client_id,
            'lead_id'      => $lead_id ? (int) $lead_id : 0,
            'recipient'    => sanitize_text_field( $recipient ),
            'type'         => sanitize_key( $type ),
            'direction'    => sanitize_key( $direction ?: 'outbound' ),
            'message'      => $message,
            'status'       => sanitize_key( $status ?: 'sent' ),
            'provider'     => $provider ? sanitize_text_field( $provider ) : null,
            'provider_ref' => $provider_ref ? sanitize_text_field( $provider_ref ) : null,
            'email_subject' => $email_subject ? sanitize_text_field( $email_subject ) : null,
            'email_body_html' => $email_body_html,
            'cost'         => (float) $cost,
            'sent_at'      => current_time( 'mysql' ),
        ];

        $wpdb->insert( $table, $data );
        return (int) $wpdb->insert_id;
    }

    /**
     * Sync historical communication events from activity logs and pending triggers into ofp_communications_log.
     * Ensures all past transaction emails/SMS and current pending triggers show up in the Communications Log.
     */
    /**
     * One time clean up of the double rows that older versions wrote for queued
     * follow up SMS and voice calls (once by the sender, once by the queue).
     *
     *  Voice: keep the sender's row (it has the provider reference), copy the cost
     *         from the queue's row onto it, and remove the queue's row.
     *  SMS:   keep the queue's row (it knows the lead), remove the sender's row that
     *         has no lead. Rows are only paired when the provider reference is the same.
     *
     * It only runs once. Anything that does not pair up exactly is left alone.
     */
    public static function merge_duplicate_queue_rows(): void {
        if ( get_option( 'ofp_comms_dupes_merged_v1' ) ) {
            return;
        }
        global $wpdb;
        $t = $wpdb->prefix . 'ofp_communications_log';

        $voice_pairs = (array) $wpdb->get_results(
            "SELECT a.id AS keep_id, b.id AS dup_id, b.cost AS dup_cost
             FROM {$t} a
             INNER JOIN {$t} b
                ON b.client_id = a.client_id AND b.lead_id = a.lead_id AND b.id <> a.id
               AND ABS( TIMESTAMPDIFF( SECOND, a.sent_at, b.sent_at ) ) <= 120
             WHERE a.type = 'voice' AND b.type = 'voice'
               AND a.lead_id IS NOT NULL
               AND a.status = 'queued' AND a.cost = 0
               AND b.status = 'sent' AND b.cost > 0
               AND ( b.provider_ref IS NULL OR b.provider_ref = '' )
             LIMIT 2000"
        );

        $used = [];
        foreach ( $voice_pairs as $pair ) {
            if ( isset( $used[ $pair->keep_id ] ) || isset( $used[ $pair->dup_id ] ) ) {
                continue;
            }
            $used[ $pair->keep_id ] = $used[ $pair->dup_id ] = true;
            $wpdb->update( $t, [ 'cost' => $pair->dup_cost ], [ 'id' => (int) $pair->keep_id ] );
            $wpdb->delete( $t, [ 'id' => (int) $pair->dup_id ] );
        }

        $sms_pairs = (array) $wpdb->get_results(
            "SELECT a.id AS dup_id
             FROM {$t} a
             INNER JOIN {$t} b
                ON b.client_id = a.client_id AND b.id <> a.id
               AND b.provider_ref = a.provider_ref
               AND ABS( TIMESTAMPDIFF( SECOND, a.sent_at, b.sent_at ) ) <= 120
             WHERE a.type = 'sms' AND b.type = 'sms'
               AND a.lead_id IS NULL AND b.lead_id IS NOT NULL
               AND a.provider_ref IS NOT NULL AND a.provider_ref <> ''
               AND a.message = b.message
             LIMIT 2000"
        );
        foreach ( $sms_pairs as $pair ) {
            if ( isset( $used[ $pair->dup_id ] ) ) {
                continue;
            }
            $used[ $pair->dup_id ] = true;
            $wpdb->delete( $t, [ 'id' => (int) $pair->dup_id ] );
        }

        update_option( 'ofp_comms_dupes_merged_v1', 1, false );
    }

    public static function sync_historical_activity( int $client_id ): void {
        self::ensure_schema();
        self::merge_duplicate_queue_rows();
        global $wpdb;

        $p = $wpdb->prefix;

        // 1. Sync any pending items from ofp_trigger_queue
        $pending_triggers = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.*, l.phone, l.email FROM {$p}ofp_trigger_queue q 
             LEFT JOIN {$p}ofp_leads l ON l.id = q.lead_id
             WHERE q.client_id = %d AND q.status = 'pending'",
            $client_id
        ) );
        if ( ! empty( $pending_triggers ) ) {
            foreach ( $pending_triggers as $pt ) {
                $recip = $pt->phone ?: ( $pt->email ?: '' );
                $pref  = 'queue_' . $pt->id;
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$p}ofp_communications_log WHERE client_id = %d AND provider_ref = %s LIMIT 1",
                    $client_id,
                    $pref
                ) );
                if ( ! $exists ) {
                    $wpdb->insert(
                        $p . 'ofp_communications_log',
                        [
                            'client_id'    => $client_id,
                            'lead_id'      => (int) ( $pt->lead_id ?? 0 ),
                            'recipient'    => $recip,
                            'type'         => $pt->type,
                            'direction'    => 'outbound',
                            'message'      => $pt->message,
                            'status'       => 'pending',
                            'provider'     => null,
                            'provider_ref' => $pref,
                            'cost'         => ( $pt->type === 'sms' ) ? 6.99 : 0.00,
                            'sent_at'      => $pt->scheduled_at ?: current_time( 'mysql' ),
                        ]
                    );
                }
            }
        }

        // 2. Fetch communication activity logs for this client that aren't already synced
        $like_sms   = '%' . $wpdb->esc_like( 'sms' ) . '%';
        $like_email = '%' . $wpdb->esc_like( 'email' ) . '%';
        $like_voice = '%' . $wpdb->esc_like( 'voice' ) . '%';
        $like_mail  = '%' . $wpdb->esc_like( 'mail' ) . '%';
        $like_to    = '%' . $wpdb->esc_like( '"to":' ) . '%';

        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_activity_logs 
              WHERE client_id = %d
               AND (action LIKE %s OR action LIKE %s OR action LIKE %s OR action LIKE %s OR details LIKE %s)
             ORDER BY created_at DESC 
             LIMIT 250",
            $client_id,
            $like_sms,
            $like_email,
            $like_voice,
            $like_mail,
            $like_to
        ) );

        // Older versions copied unowned activity rows into every client's log.
        // Remove only those derived copies; source activity records are untouched.
        $wpdb->query(
            "DELETE cl FROM {$p}ofp_communications_log cl
             INNER JOIN {$p}ofp_activity_logs al ON cl.provider_ref = CONCAT('act_', al.id)
             WHERE cl.provider_ref LIKE 'act_%'
               AND (al.client_id IS NULL OR al.client_id = 0 OR al.client_id <> cl.client_id)"
        );

        if ( empty( $activities ) ) {
            return;
        }

        foreach ( $activities as $act ) {
            $details = $act->details ? json_decode( $act->details, true ) : [];
            if ( ! is_array( $details ) ) {
                $details = [];
            }

            $act_lower = strtolower( $act->action );
            if ( strpos( $act_lower, 'sms' ) !== false ) {
                $type = 'sms';
            } elseif ( strpos( $act_lower, 'voice' ) !== false || strpos( $act_lower, 'call' ) !== false ) {
                $type = 'voice';
            } else {
                $type = 'email';
            }

            $recipient = $details['to'] 
                      ?? ( $details['recipient'] 
                      ?? ( $details['phone'] 
                      ?? ( $details['email'] 
                      ?? ( $details['buyer_email'] 
                      ?? ( $details['buyer_phone'] 
                      ?? '' ) ) ) ) );

            $lead_id = ! empty( $details['lead_id'] ) ? (int) $details['lead_id'] : 0;
            if ( empty( $recipient ) && $lead_id > 0 ) {
                $lead_row = $wpdb->get_row( $wpdb->prepare( "SELECT phone, email FROM {$p}ofp_leads WHERE id = %d LIMIT 1", $lead_id ) );
                if ( $lead_row ) {
                    $recipient = ( $type === 'email' ) ? ( $lead_row->email ?: $lead_row->phone ) : ( $lead_row->phone ?: $lead_row->email );
                }
            }

            if ( empty( $recipient ) ) {
                continue;
            }

            $message = ! empty( $details['subject'] ) && ! empty( $details['message'] )
                ? $details['subject'] . ' — ' . $details['message']
                : ( $details['message'] ?? ( $details['subject'] ?? ( $details['body'] ?? ( $details['content'] ?? $act->action ) ) ) );

            $status = ( strpos( $act_lower, 'fail' ) !== false || strpos( $act_lower, 'error' ) !== false ) ? 'failed' : 'sent';
            if ( strpos( $act_lower, 'pending' ) !== false || strpos( $act_lower, 'queued' ) !== false ) {
                $status = 'pending';
            }

            $pref = 'act_' . $act->id;

            // Check if already in communications log
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}ofp_communications_log 
                 WHERE client_id = %d AND (provider_ref = %s OR (type = %s AND (recipient = %s OR message = %s) AND sent_at BETWEEN DATE_SUB(%s, INTERVAL 2 MINUTE) AND DATE_ADD(%s, INTERVAL 2 MINUTE)))
                 LIMIT 1",
                $client_id,
                $pref,
                $type,
                $recipient,
                $message,
                $act->created_at,
                $act->created_at
            ) );

            if ( ! $exists ) {
                $wpdb->insert(
                    $p . 'ofp_communications_log',
                    [
                        'client_id'    => $client_id,
                        'lead_id'      => $lead_id,
                        'recipient'    => $recipient,
                        'type'         => $type,
                        'direction'    => 'outbound',
                        'message'      => $message,
                        'status'       => $status,
                        'provider'     => $details['provider'] ?? ( $type === 'email' ? 'smtp' : ( $type === 'voice' ? 'africastalking' : 'smartsms' ) ),
                        'provider_ref' => $pref,
                        'cost'         => (float) ( $details['cost'] ?? ( $type === 'sms' && $status !== 'failed' ? 6.99 : 0.00 ) ),
                        'sent_at'      => $act->created_at ?: current_time( 'mysql' ),
                    ]
                );
            }
        }
    }

    /**
     * Query communications log with filtering and pagination.
     *
     * @param int   $client_id Client ID.
     * @param array $filters   Associative array: ['type' => ..., 'status' => ..., 's' => ...].
     * @param int   $page      Page number (1-indexed).
     * @param int   $per_page  Items per page.
     * @return array ['items' => array, 'total' => int, 'page' => int, 'total_pages' => int, 'per_page' => int]
     */
    public static function fetch( int $client_id, array $filters = [], int $page = 1, int $per_page = 25 ): array {
        self::ensure_schema();
        global $wpdb;
        $p = $wpdb->prefix;

        $page     = max( 1, $page );
        $per_page = max( 1, $per_page );
        $offset   = ( $page - 1 ) * $per_page;

        $where_parts = [ 'cl.client_id = %d' ];
        $where_args  = [ $client_id ];

        $filter_type   = sanitize_text_field( $filters['type'] ?? '' );
        $filter_status = sanitize_text_field( $filters['status'] ?? '' );
        $search        = sanitize_text_field( $filters['s'] ?? '' );

        if ( $filter_type ) {
            $where_parts[] = 'cl.type = %s';
            $where_args[]  = $filter_type;
        }

        if ( $filter_status ) {
            if ( $filter_status === 'sent' ) {
                $where_parts[] = "cl.status IN ('sent', 'delivered', 'completed')";
            } elseif ( $filter_status === 'failed' ) {
                $where_parts[] = "cl.status IN ('failed', 'error')";
            } elseif ( $filter_status === 'pending' ) {
                $where_parts[] = "cl.status IN ('pending', 'queued')";
            } else {
                $where_parts[] = 'cl.status = %s';
                $where_args[]  = $filter_status;
            }
        }

        if ( $search !== '' ) {
            $search_like = '%' . $wpdb->esc_like( $search ) . '%';
            $where_parts[] = "(cl.recipient LIKE %s OR cl.message LIKE %s OR l.name LIKE %s OR l.phone LIKE %s)";
            $where_args[]  = $search_like;
            $where_args[]  = $search_like;
            $where_args[]  = $search_like;
            $where_args[]  = $search_like;
        }

        $where_sql = implode( ' AND ', $where_parts );

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(cl.id) 
                 FROM {$p}ofp_communications_log cl
                 LEFT JOIN {$p}ofp_leads l ON l.id = cl.lead_id
                 WHERE {$where_sql}",
                ...$where_args
            )
        );

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cl.*, 
                        l.name as lead_name, 
                        l.phone as lead_phone
                 FROM {$p}ofp_communications_log cl
                 LEFT JOIN {$p}ofp_leads l ON l.id = cl.lead_id
                 WHERE {$where_sql}
                 ORDER BY cl.sent_at DESC
                 LIMIT %d OFFSET %d",
                ...array_merge( $where_args, [ $per_page, $offset ] )
            )
        );

        $total_pages = (int) ceil( max( 1, $total ) / $per_page );

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $total_pages,
            'per_page'    => $per_page,
        ];
    }

    /**
     * Render the feed HTML cards for communications list.
     *
     * @param array $comms     Array of communication rows.
     * @param int   $client_id Client ID.
     * @return string Rendered HTML.
     */
    public static function render_feed_html( array $comms, int $client_id ): string {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( empty( $comms ) ) {
            ob_start();
            ?>
            <div class="ofp-empty" style="padding:48px;">
                <div class="ofp-empty-icon" style="display:flex; justify-content:center; margin-bottom:12px;">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                </div>
                <h3>No communications found</h3>
                <p>All transaction emails/SMS, lead sequences, and calls will appear here automatically.</p>
            </div>
            <?php
            return ob_get_clean();
        }

        static $buyer_cache = [];

        ob_start();
        ?>
        <div style="display: flex; flex-direction: column; gap: 16px; padding: 24px;">
            <?php foreach ( $comms as $comm ) :
                $status     = strtolower( $comm->status ?? 'sent' );
                $is_pending = in_array( $status, [ 'pending', 'queued' ], true );
                $is_failed  = in_array( $status, [ 'failed', 'error' ], true );
                $is_sent    = ! $is_pending && ! $is_failed;

                $bg = 'rgba(139, 92, 246, 0.1)'; $ic = '#8b5cf6'; $type_label = 'SMS';
                $svg_icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
                if ( $comm->type === 'voice' ) {
                    $bg = 'rgba(16, 185, 129, 0.1)'; $ic = '#10b981'; $type_label = 'Voice';
                    $svg_icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>';
                } elseif ( $comm->type === 'email' ) {
                    $bg = 'rgba(245, 158, 11, 0.1)'; $ic = '#f59e0b'; $type_label = 'Email';
                    $svg_icon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>';
                }

                $recip_title = '';
                $recip_sub   = '';
                $context_tag = '';

                if ( ! empty( $comm->lead_name ) ) {
                    $recip_title = $comm->lead_name;
                    $recip_sub   = $comm->lead_phone ?: ( $comm->recipient ?: '' );
                    $context_tag = 'Lead';
                } elseif ( ! empty( $comm->recipient ) ) {
                    $recip_title = $comm->recipient;
                    $recip_sub   = is_email( $comm->recipient ) ? 'Email' : 'Direct Recipient';
                    $context_tag = 'Direct';

                    if ( ! isset( $buyer_cache[ $comm->recipient ] ) ) {
                        $buyer_cache[ $comm->recipient ] = $wpdb->get_row( $wpdb->prepare(
                            "SELECT pu.buyer_name, pr.title as property_title 
                             FROM {$p}ofp_property_purchases pu
                             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
                             WHERE pu.client_id = %d AND (pu.buyer_email = %s OR pu.buyer_phone = %s)
                             LIMIT 1",
                            $client_id,
                            $comm->recipient,
                            $comm->recipient
                        ) );
                    }
                    $buyer_info = $buyer_cache[ $comm->recipient ];
                    if ( $buyer_info && ! empty( $buyer_info->buyer_name ) ) {
                        $recip_title = $buyer_info->buyer_name;
                        $recip_sub   = $comm->recipient . ( ! empty( $buyer_info->property_title ) ? ' • ' . $buyer_info->property_title : '' );
                        $context_tag = 'Transaction';
                    }
                } else {
                    $recip_title = 'Direct Recipient';
                    $recip_sub   = $type_label;
                    $context_tag = 'Broadcast';
                }
            ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-radius: 12px; background: var(--bg-lighter); border: 1px solid var(--border-color); transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)';" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='none';">
                    
                    <!-- Left: Icon & Message Preview -->
                    <div style="display: flex; gap: 16px; align-items: center; flex: 1; min-width: 0;">
                        <!-- Icon Box -->
                        <div style="width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: <?php echo $bg; ?>; color: <?php echo $ic; ?>; flex-shrink: 0;" title="<?php echo esc_attr( $type_label ); ?>">
                            <?php echo $svg_icon; ?>
                        </div>
                        
                        <!-- Text Stack -->
                        <div style="min-width: 0; flex: 1;">
                            <div style="font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 4px; display: flex; align-items: center; flex-wrap: wrap; gap: 8px;">
                                <span><?php echo esc_html( $recip_title ); ?></span>
                                <?php if ( $recip_sub && $recip_sub !== $recip_title ) : ?>
                                    <span style="font-size: 12px; font-weight: 400; color: var(--text-muted);">(<?php echo esc_html( $recip_sub ); ?>)</span>
                                <?php endif; ?>

                                <span style="font-size: 10px; padding: 2px 6px; border-radius: 4px; background: var(--bg-surface, #fff); border: 1px solid var(--border-color); color: var(--text-muted); font-weight: 600;">
                                    <?php echo esc_html( $context_tag ); ?>
                                </span>

                                <?php if ( $is_sent ) : ?>
                                    <span style="font-size: 10px; padding: 2px 7px; border-radius: 4px; background: rgba(16, 185, 129, 0.12); color: #059669; font-weight: 700; text-transform: uppercase;">Sent</span>
                                <?php elseif ( $is_pending ) : ?>
                                    <span style="font-size: 10px; padding: 2px 7px; border-radius: 4px; background: rgba(245, 158, 11, 0.15); color: #d97706; font-weight: 700; text-transform: uppercase;">Pending</span>
                                <?php elseif ( $is_failed ) : ?>
                                    <span style="font-size: 10px; padding: 2px 7px; border-radius: 4px; background: rgba(239, 68, 68, 0.12); color: var(--accent-red); font-weight: 700; text-transform: uppercase;">Failed</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 13px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 580px;" title="<?php echo esc_attr( $comm->message ); ?>">
                                <?php echo esc_html( mb_substr( $comm->message ?? '', 0, 100 ) . ( mb_strlen( $comm->message ?? '' ) > 100 ? '…' : '' ) ); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Cost & Time -->
                    <div style="text-align: right; flex-shrink: 0; margin-left: 16px;">
                        <div style="font-size: 14px; font-weight: 700; margin-bottom: 4px;">
                            <?php if ( $comm->type === 'email' ) : ?>
                                <span style="color: var(--text-muted); font-size: 12px; font-weight: 600;">₦0.00</span>
                            <?php elseif ( $is_failed ) : ?>
                                <span style="color: var(--text-muted); font-size: 12px; text-decoration: line-through;">₦<?php echo esc_html( number_format( (float) $comm->cost, 2 ) ); ?></span>
                            <?php elseif ( $is_pending ) : ?>
                                <span style="color: #d97706; font-size: 12px;">Est. ₦<?php echo esc_html( number_format( (float) $comm->cost, 2 ) ); ?></span>
                            <?php else : ?>
                                <span style="color: var(--accent-red);">- ₦<?php echo esc_html( number_format( (float) $comm->cost, 2 ) ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 12px; color: var(--text-muted);">
                            <?php echo esc_html( human_time_diff( strtotime( $comm->sent_at ), current_time( 'timestamp' ) ) . ' ago' ); ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the pagination buttons HTML.
     *
     * @param int    $total_pages  Total pages count.
     * @param int    $current_page Active page number.
     * @param string $base_url     Base URL for fallback hrefs.
     * @param array  $query_args   Extra query arguments.
     * @return string Rendered pagination HTML.
     */
    public static function render_pagination_html( int $total_pages, int $current_page, string $base_url = '', array $query_args = [] ): string {
        if ( $total_pages <= 1 ) {
            return '';
        }

        ob_start();
        ?>
        <div class="ofp-pagination" style="padding:16px;">
            <?php for ( $i = 1; $i <= $total_pages; $i++ ) : 
                $href = $base_url ? esc_url( add_query_arg( array_filter( array_merge( $query_args, [ 'paged' => $i ] ) ), $base_url ) ) : '#';
                $active = ( $i === $current_page ) ? 'active' : '';
            ?>
                <a href="<?php echo $href; ?>" 
                   data-page="<?php echo esc_attr( $i ); ?>" 
                   class="ofp-page-btn ofp-comm-page-btn <?php echo $active; ?>">
                    <?php echo esc_html( $i ); ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
