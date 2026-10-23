<?php
/**
 * OFP_Property_Inquiry
 *
 * Domain class for managing property inspection bookings and prospect inquiries.
 * Bridges public single property submissions with client dashboards and WP-Admin.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_Inquiry {

    const STATUSES = [
        'pending'   => 'Pending Confirmation',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'ensure_schema' ], 20 );
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_menu' ] );
        add_action( 'wp_ajax_ofp_update_inspection_status', [ __CLASS__, 'ajax_update_status' ] );
    }

    /**
     * Ensure required columns exist on ofp_property_inquiries.
     */
    public static function ensure_schema(): void {
        if ( get_option( 'ofp_schema_inquiries_v2' ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofp_property_inquiries';

        $columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
        if ( empty( $columns ) ) {
            return;
        }

        if ( ! in_array( 'tour_type', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN tour_type VARCHAR(30) NOT NULL DEFAULT 'physical' AFTER client_id" );
        }
        if ( ! in_array( 'preferred_viewing_time', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN preferred_viewing_time VARCHAR(30) DEFAULT NULL AFTER preferred_viewing_date" );
        }
        if ( ! in_array( 'status', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER message" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY status (status)" );
        }
        if ( ! in_array( 'updated_at', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at" );
        }

        update_option( 'ofp_schema_inquiries_v2', 1, false );
    }

    /**
     * Register Admin Submenu under 'edit.php?post_type=ofp_property'.
     */
    public static function register_admin_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        add_submenu_page(
            'edit.php?post_type=ofp_property',
            'Property Inspections & Inquiries',
            'Inspections',
            'manage_options',
            'ofp-property-inspections',
            [ __CLASS__, 'render_admin_inspections' ]
        );
    }

    /**
     * Fetch inspection requests for a specific client.
     */
    public static function get_client_inquiries( int $client_id, array $filters = [] ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $where = [ 'i.client_id = %d' ];
        $args  = [ $client_id ];

        if ( ! empty( $filters['status'] ) && array_key_exists( $filters['status'], self::STATUSES ) ) {
            $where[] = 'i.status = %s';
            $args[]  = $filters['status'];
        }

        if ( ! empty( $filters['tour_type'] ) && in_array( $filters['tour_type'], [ 'physical', 'video' ], true ) ) {
            $where[] = 'i.tour_type = %s';
            $args[]  = $filters['tour_type'];
        }

        if ( ! empty( $filters['property_id'] ) ) {
            $where[] = 'i.property_id = %d';
            $args[]  = (int) $filters['property_id'];
        }

        $where_sql = implode( ' AND ', $where );

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, 
                    l.name AS lead_name, 
                    l.phone AS lead_phone, 
                    l.email AS lead_email,
                    p.title AS property_title,
                    p.location_text AS property_location,
                    p.featured_image AS property_image,
                    p.wp_post_id
             FROM {$p}ofp_property_inquiries i
             LEFT JOIN {$p}ofp_leads l ON l.id = i.lead_id
             LEFT JOIN {$p}ofp_properties p ON p.wp_post_id = i.property_id OR p.id = i.property_id
             WHERE {$where_sql}
             ORDER BY i.created_at DESC",
            ...$args
        ) );
    }

    /**
     * Fetch all inquiries across platform (admin).
     */
    public static function get_all_inquiries( array $filters = [] ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $where = [ '1=1' ];
        $args  = [];

        if ( ! empty( $filters['client_id'] ) ) {
            $where[] = 'i.client_id = %d';
            $args[]  = (int) $filters['client_id'];
        }

        if ( ! empty( $filters['status'] ) && array_key_exists( $filters['status'], self::STATUSES ) ) {
            $where[] = 'i.status = %s';
            $args[]  = $filters['status'];
        }

        if ( ! empty( $filters['tour_type'] ) && in_array( $filters['tour_type'], [ 'physical', 'video' ], true ) ) {
            $where[] = 'i.tour_type = %s';
            $args[]  = $filters['tour_type'];
        }

        $where_sql = implode( ' AND ', $where );
        $query     = "SELECT i.*, 
                             l.name AS lead_name, 
                             l.phone AS lead_phone, 
                             l.email AS lead_email,
                             p.title AS property_title,
                             p.location_text AS property_location,
                             p.featured_image AS property_image,
                             c.business_name AS client_company,
                             c.owner_name AS client_owner
                      FROM {$p}ofp_property_inquiries i
                      LEFT JOIN {$p}ofp_leads l ON l.id = i.lead_id
                      LEFT JOIN {$p}ofp_properties p ON p.wp_post_id = i.property_id OR p.id = i.property_id
                      LEFT JOIN {$p}ofp_clients c ON c.id = i.client_id
                      WHERE {$where_sql}
                      ORDER BY i.created_at DESC";

        if ( ! empty( $args ) ) {
            return (array) $wpdb->get_results( $wpdb->prepare( $query, ...$args ) );
        }
        return (array) $wpdb->get_results( $query );
    }

    /**
     * Update inquiry status.
     */
    public static function update_status( int $id, string $status, ?int $client_id = null ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'ofp_property_inquiries';

        if ( ! array_key_exists( $status, self::STATUSES ) ) {
            return false;
        }

        $where = [ 'id' => $id ];
        if ( null !== $client_id && $client_id > 0 ) {
            $where['client_id'] = $client_id;
        }

        return false !== $wpdb->update(
            $table,
            [
                'status'     => $status,
                'updated_at' => current_time( 'mysql' ),
            ],
            $where
        );
    }

    /**
     * AJAX handler for updating inspection status.
     */
    public static function ajax_update_status(): void {
        $has_valid_nonce = check_ajax_referer( 'ofp_inspection_action', 'nonce', false )
                        || check_ajax_referer( 'ofp_client_ajax', 'nonce', false )
                        || check_ajax_referer( 'ofp_client_action', 'nonce', false );

        if ( ! $has_valid_nonce ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page.' ] );
        }

        $inquiry_id = absint( $_POST['inquiry_id'] ?? 0 );
        $new_status = sanitize_key( $_POST['status'] ?? '' );
        if ( ! $inquiry_id || ! array_key_exists( $new_status, self::STATUSES ) ) {
            wp_send_json_error( [ 'message' => 'Invalid parameters.' ] );
        }

        // Verify authorization
        $client_id = null;
        if ( ! current_user_can( 'manage_options' ) ) {
            $current_client = class_exists( 'OFP_Auth' ) ? OFP_Auth::current_client() : null;
            if ( ! $current_client ) {
                wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
            }
            $client_id = (int) $current_client->id;
        }

        $success = self::update_status( $inquiry_id, $new_status, $client_id );
        if ( $success ) {
            wp_send_json_success( [ 'message' => 'Inspection status updated to ' . self::STATUSES[ $new_status ] ] );
        } else {
            wp_send_json_error( [ 'message' => 'Failed to update inspection.' ] );
        }
    }

    /**
     * Render the Admin Inspections list page in WP Admin.
     */
    public static function render_admin_inspections(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied.' );
        }

        global $wpdb;
        $status_filter = sanitize_key( $_GET['status'] ?? '' );
        $tour_filter   = sanitize_key( $_GET['tour_type'] ?? '' );
        $client_filter = absint( $_GET['client_id'] ?? 0 );

        $inquiries = self::get_all_inquiries( [
            'status'    => $status_filter,
            'tour_type' => $tour_filter,
            'client_id' => $client_filter,
        ] );

        $clients = (array) $wpdb->get_results( "SELECT id, business_name, owner_name FROM {$wpdb->prefix}ofp_clients ORDER BY business_name ASC" );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Property Inspections &amp; Tour Bookings</h1>
            <hr class="wp-header-end">

            <!-- Filters Bar -->
            <div style="background:#fff; border:1px solid #c3c4c7; padding:12px 16px; margin:16px 0; border-radius:4px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <form method="GET" action="" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0;">
                    <input type="hidden" name="post_type" value="ofp_property">
                    <input type="hidden" name="page" value="ofp-property-inspections">

                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ( self::STATUSES as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status_filter, $k ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="tour_type">
                        <option value="">All Tour Types</option>
                        <option value="physical" <?php selected( $tour_filter, 'physical' ); ?>>Physical Tour</option>
                        <option value="video"    <?php selected( $tour_filter, 'video' ); ?>>WhatsApp Video Call</option>
                    </select>

                    <select name="client_id">
                        <option value="">All Agents / Owners</option>
                        <option value="0" <?php selected( (string) $client_filter, '0' ); ?>>Admin (Internal Platform)</option>
                        <?php foreach ( $clients as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->id ); ?>" <?php selected( $client_filter, $c->id ); ?>><?php echo esc_html( $c->business_name ?: $c->owner_name ); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="button button-secondary">Filter</button>
                    <?php if ( $status_filter || $tour_filter || $client_filter ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-inspections' ) ); ?>" class="button">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Inquiries Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:50px;">ID</th>
                        <th style="width:160px;">Date &amp; Time</th>
                        <th style="width:120px;">Tour Type</th>
                        <th>Property</th>
                        <th>Prospect / Buyer</th>
                        <th>Requested Message / Questions</th>
                        <th style="width:140px;">Owner / Agent</th>
                        <th style="width:120px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $inquiries ) ) : ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:30px; color:#64748b;">No property inspection bookings recorded yet.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $inquiries as $inq ) : 
                            $clean_phone = preg_replace( '/[^0-9]/', '', (string) $inq->lead_phone );
                            if ( strpos( $clean_phone, '0' ) === 0 ) {
                                $clean_phone = '234' . substr( $clean_phone, 1 );
                            }
                            $wa_link = $clean_phone ? 'https://wa.me/' . $clean_phone . '?text=' . rawurlencode( "Hello " . ( $inq->lead_name ?: 'there' ) . ", regarding your inspection request for " . ( $inq->property_title ?: 'our property' ) ) : '';
                        ?>
                            <tr>
                                <td>#<?php echo (int) $inq->id; ?></td>
                                <td>
                                    <strong><?php echo esc_html( $inq->preferred_viewing_date ? gmdate( 'M j, Y', strtotime( $inq->preferred_viewing_date ) ) : 'Flexible Date' ); ?></strong>
                                    <div style="font-size:12px; color:#64748b;"><?php echo esc_html( $inq->preferred_viewing_time ?: '10:00 AM' ); ?></div>
                                </td>
                                <td>
                                    <?php if ( $inq->tour_type === 'video' ) : ?>
                                        <span style="display:inline-block; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600; background:#dbeafe; color:#1e40af;">📱 WhatsApp Video</span>
                                    <?php else : ?>
                                        <span style="display:inline-block; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600; background:#f1f5f9; color:#475569;">📍 Physical Tour</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html( $inq->property_title ?: ( 'PID: #' . $inq->property_id ) ); ?></strong>
                                    <?php if ( ! empty( $inq->property_location ) ) : ?>
                                        <div style="font-size:12px; color:#64748b;"><?php echo esc_html( $inq->property_location ); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html( $inq->lead_name ?: 'Prospect' ); ?></strong>
                                    <div><a href="tel:<?php echo esc_attr( $inq->lead_phone ); ?>"><?php echo esc_html( $inq->lead_phone ); ?></a></div>
                                    <?php if ( $wa_link ) : ?>
                                        <div style="margin-top:4px;">
                                            <a href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener" style="color:#059669; font-weight:600; text-decoration:none; font-size:11px;">
                                                💬 Chat on WhatsApp &rarr;
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( ! empty( $inq->message ) ) : ?>
                                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 10px; font-size:12.5px; line-height:1.4; color:#334155; max-height:90px; overflow-y:auto;">
                                            <?php echo nl2br( esc_html( $inq->message ) ); ?>
                                        </div>
                                    <?php else : ?>
                                        <span style="color:#94a3b8; font-size:12px;">Standard inspection request.</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( (int) $inq->client_id === 0 ) : ?>
                                        <span style="color:#2563eb; font-weight:600;">Platform / Admin</span>
                                    <?php else : ?>
                                        <span><?php echo esc_html( $inq->client_company ?: ( $inq->client_owner ?: ( 'Client #' . $inq->client_id ) ) ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight:600; text-transform:capitalize; <?php 
                                        echo $inq->status === 'confirmed' ? 'color:#059669;' : ( $inq->status === 'cancelled' ? 'color:#dc2626;' : 'color:#d97706;' ); 
                                    ?>">
                                        ● <?php echo esc_html( ucfirst( $inq->status ?: 'pending' ) ); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
