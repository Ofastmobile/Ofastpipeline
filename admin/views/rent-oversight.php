<?php
/**
 * Admin View: Rent Oversight
 *
 * Read-only cross-client view of all tenants, leases, and rent payments
 * for platform owner wp-admin oversight. Blueprint §1.7.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

global $wpdb;
$p = $wpdb->prefix;

// ── Actions ───────────────────────────────────────────────────────────────────
$action_msg = '';
$action_err = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_admin_action'] ) ) {
    check_admin_referer( 'ofp_rent_admin_action', '_ofp_rent_admin_nonce' );

    $post_action = sanitize_text_field( $_POST['ofp_admin_action'] );

    if ( $post_action === 'update_tenant' ) {
        $tenant_id = absint( $_POST['tenant_id'] ?? 0 );
        $full_name = sanitize_text_field( $_POST['full_name'] ?? '' );
        $phone     = OFP_Property_Rent::normalize_phone( $_POST['phone'] ?? '' );
        $email     = sanitize_email( $_POST['email'] ?? '' );
        $status    = sanitize_key( $_POST['status'] ?? 'active' );
        if ( ! in_array( $status, [ 'active', 'inactive' ], true ) ) {
            $status = 'active';
        }

        if ( ! $tenant_id || empty( $full_name ) || empty( $phone ) ) {
            $action_err = 'Tenant name and phone number are required.';
        } else {
            $updated = $wpdb->update(
                "{$p}ofp_property_tenants",
                [
                    'full_name'  => $full_name,
                    'phone'      => $phone,
                    'email'      => $email ?: null,
                    'status'     => $status,
                    'updated_at' => current_time( 'mysql' ),
                ],
                [ 'id' => $tenant_id ]
            );
            if ( false !== $updated ) {
                $action_msg = 'Tenant details updated successfully.';
            } else {
                $action_err = 'Failed to update tenant details.';
            }
        }
    } elseif ( $post_action === 'update_lease' ) {
        $lease_id   = absint( $_POST['lease_id'] ?? 0 );
        $new_status = sanitize_key( $_POST['status'] ?? '' );
        $allowed    = [ 'pending_offer', 'active', 'expired', 'renewed', 'cancelled' ];

        if ( ! $lease_id || ! in_array( $new_status, $allowed, true ) ) {
            $action_err = 'Invalid lease or status selected.';
        } else {
            $lease = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_property_leases WHERE id = %d LIMIT 1", $lease_id ) );
            if ( ! $lease ) {
                $action_err = 'Lease not found.';
            } else {
                if ( $new_status === 'active' ) {
                    $other_active = $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM {$p}ofp_property_leases WHERE property_id = %d AND status = 'active' AND id != %d LIMIT 1",
                        $lease->property_id,
                        $lease->id
                    ) );
                    if ( $other_active ) {
                        $action_err = 'Cannot activate lease: Property already has another active lease (Lease #' . $other_active . ').';
                    }
                }

                if ( empty( $action_err ) ) {
                    $update_fields = [
                        'status'     => $new_status,
                        'updated_at' => current_time( 'mysql' ),
                    ];
                    if ( ! empty( $_POST['start_date'] ) ) {
                        $update_fields['start_date'] = sanitize_text_field( $_POST['start_date'] );
                    }
                    if ( ! empty( $_POST['end_date'] ) ) {
                        $update_fields['end_date'] = sanitize_text_field( $_POST['end_date'] );
                    }

                    $updated = $wpdb->update(
                        "{$p}ofp_property_leases",
                        $update_fields,
                        [ 'id' => $lease->id ]
                    );

                    if ( false !== $updated ) {
                        $action_msg = 'Lease status updated to ' . ucwords( str_replace( '_', ' ', $new_status ) ) . '.';
                    } else {
                        $action_err = 'Failed to update lease status.';
                    }
                }
            }
        }
    }
}

// ── Filters ──────────────────────────────────────────────────────────────────
$filter_client = absint( $_GET['client_id'] ?? 0 );
$filter_status = sanitize_key( $_GET['status'] ?? '' );
$active_tab    = sanitize_key( $_GET['tab'] ?? 'leases' );
if ( ! in_array( $active_tab, [ 'leases', 'tenants', 'payments' ], true ) ) {
    $active_tab = 'leases';
}
$per_page     = 50;
$current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
$offset       = ( $current_page - 1 ) * $per_page;

$clients = $wpdb->get_results( "SELECT id, business_name FROM {$p}ofp_clients WHERE status != 'cancelled' ORDER BY business_name ASC" );

// ── Stats ────────────────────────────────────────────────────────────────────
$stat_total_tenants  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_property_tenants" );
$stat_active_leases  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_property_leases WHERE status = 'active'" );
$stat_expired_leases = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_property_leases WHERE status = 'expired'" );
$stat_total_rent     = (float) $wpdb->get_var(
    "SELECT COALESCE(SUM(p.amount), 0) FROM {$p}ofp_property_lease_payments p WHERE p.status = 'successful'"
);
$stat_rent_month     = (float) $wpdb->get_var(
    "SELECT COALESCE(SUM(p.amount), 0) FROM {$p}ofp_property_lease_payments p
     WHERE p.status = 'successful' AND MONTH(p.created_at) = MONTH(NOW()) AND YEAR(p.created_at) = YEAR(NOW())"
);

// ── Lease data ───────────────────────────────────────────────────────────────
$lease_where = [ '1=1' ];
$lease_args  = [];
if ( $filter_client ) { $lease_where[] = 'l.client_id = %d'; $lease_args[] = $filter_client; }
if ( $filter_status ) { $lease_where[] = 'l.status = %s';    $lease_args[] = $filter_status; }
$lease_where_sql = implode( ' AND ', $lease_where );

$lease_total = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}ofp_property_leases l WHERE {$lease_where_sql}",
    ...$lease_args
) );
$leases = $wpdb->get_results( $wpdb->prepare(
    "SELECT l.*, t.full_name AS tenant_name, t.phone AS tenant_phone,
            pr.title AS property_title, c.business_name
     FROM {$p}ofp_property_leases l
     JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
     LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
     LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
     WHERE {$lease_where_sql}
     ORDER BY l.created_at DESC LIMIT %d OFFSET %d",
    ...array_merge( $lease_args, [ $per_page, $offset ] )
) );

// ── Tenant data ──────────────────────────────────────────────────────────────
$tenant_where = [ '1=1' ];
$tenant_args  = [];
if ( $filter_client ) { $tenant_where[] = 't.client_id = %d'; $tenant_args[] = $filter_client; }
$tenant_where_sql = implode( ' AND ', $tenant_where );

$tenant_total = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}ofp_property_tenants t WHERE {$tenant_where_sql}",
    ...$tenant_args
) );
$tenants = $wpdb->get_results( $wpdb->prepare(
    "SELECT t.*, c.business_name,
            COUNT(l.id) AS total_leases,
            SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END) AS active_leases,
            COALESCE(SUM(l.amount_paid), 0) AS total_paid
     FROM {$p}ofp_property_tenants t
     LEFT JOIN {$p}ofp_clients c ON c.id = t.client_id
     LEFT JOIN {$p}ofp_property_leases l ON l.tenant_id = t.id
     WHERE {$tenant_where_sql}
     GROUP BY t.id
     ORDER BY t.created_at DESC LIMIT %d OFFSET %d",
    ...array_merge( $tenant_args, [ $per_page, $offset ] )
) );

// ── Payment data ─────────────────────────────────────────────────────────────
$pay_where = [ '1=1' ];
$pay_args  = [];
if ( $filter_client ) { $pay_where[] = 'l.client_id = %d'; $pay_args[] = $filter_client; }
$pay_where_sql = implode( ' AND ', $pay_where );

$pay_total = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}ofp_property_lease_payments p JOIN {$p}ofp_property_leases l ON l.id = p.lease_id WHERE {$pay_where_sql}",
    ...$pay_args
) );
$payments = $wpdb->get_results( $wpdb->prepare(
    "SELECT p.*, l.rent_period, l.custom_days, t.full_name AS tenant_name,
            pr.title AS property_title, c.business_name
     FROM {$p}ofp_property_lease_payments p
     JOIN {$p}ofp_property_leases l ON l.id = p.lease_id
     JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
     LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
     LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
     WHERE {$pay_where_sql}
     ORDER BY p.created_at DESC LIMIT %d OFFSET %d",
    ...array_merge( $pay_args, [ $per_page, $offset ] )
) );

// Pagination counts per active tab
$total_for_tab = match ( $active_tab ) {
    'tenants'  => $tenant_total,
    'payments' => $pay_total,
    default    => $lease_total,
};
$total_pages = max( 1, (int) ceil( $total_for_tab / $per_page ) );

include OFP_PATH . 'admin/views/partials/header.php';
?>

<h2>Rent Management Oversight</h2>
<p>Cross-client view of all tenants, leases, and rent payments on the platform.</p>

<?php if ( $action_msg ) : ?>
    <div class="notice notice-success is-dismissible" style="margin: 12px 0;"><p><strong><?php echo esc_html( $action_msg ); ?></strong></p></div>
<?php endif; ?>
<?php if ( $action_err ) : ?>
    <div class="notice notice-error is-dismissible" style="margin: 12px 0;"><p><strong><?php echo esc_html( $action_err ); ?></strong></p></div>
<?php endif; ?>

<div class="ofp-stats-grid">
    <div class="ofp-stat-card"><span class="ofp-stat-number"><?php echo esc_html( $stat_total_tenants ); ?></span><span class="ofp-stat-label">Total Tenants</span></div>
    <div class="ofp-stat-card"><span class="ofp-stat-number ofp-accent"><?php echo esc_html( $stat_active_leases ); ?></span><span class="ofp-stat-label">Active Leases</span></div>
    <div class="ofp-stat-card"><span class="ofp-stat-number" style="color:#dc2626"><?php echo esc_html( $stat_expired_leases ); ?></span><span class="ofp-stat-label">Expired Leases</span></div>
    <div class="ofp-stat-card"><span class="ofp-stat-number">₦<?php echo esc_html( number_format( $stat_total_rent, 0 ) ); ?></span><span class="ofp-stat-label">Total Rent Collected</span></div>
    <div class="ofp-stat-card"><span class="ofp-stat-number ofp-accent">₦<?php echo esc_html( number_format( $stat_rent_month, 0 ) ); ?></span><span class="ofp-stat-label">This Month</span></div>
</div>

<!-- Filters -->
<div class="ofp-filters" style="margin-bottom:18px;">
    <form method="GET" action="" class="ofp-filter-form" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="page" value="ofp-rent-oversight">
        <input type="hidden" name="tab" value="<?php echo esc_attr( $active_tab ); ?>">
        <select name="client_id" onchange="this.form.submit()">
            <option value="">All Clients</option>
            <?php foreach ( $clients as $c ) : ?>
                <option value="<?php echo esc_attr( $c->id ); ?>" <?php selected( $filter_client, (int) $c->id ); ?>><?php echo esc_html( $c->business_name ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ( $active_tab === 'leases' ) : ?>
        <select name="status" onchange="this.form.submit()">
            <option value="">All Statuses</option>
            <?php foreach ( [ 'pending_offer', 'active', 'expired', 'renewed' ] as $s ) : ?>
                <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filter_status, $s ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $s ) ) ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ( $filter_client || $filter_status ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-rent-oversight&tab=' . $active_tab ) ); ?>" class="button">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Tabs -->
<h2 class="nav-tab-wrapper">
    <?php
    $tabs = [ 'leases' => 'Leases (' . $lease_total . ')', 'tenants' => 'Tenants (' . $tenant_total . ')', 'payments' => 'Payments (' . $pay_total . ')' ];
    foreach ( $tabs as $slug => $label ) :
        $url = add_query_arg( [ 'tab' => $slug, 'paged' => 1, 'client_id' => $filter_client ?: '' ] );
    ?>
        <a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
    <?php endforeach; ?>
</h2>

<div class="ofp-section" style="margin-top:16px;">

<?php if ( $active_tab === 'leases' ) : ?>
    <?php if ( empty( $leases ) ) : ?>
        <p>No leases found.</p>
    <?php else : ?>
        <div style="overflow-x:auto;">
            <table class="widefat ofp-table" style="min-width:1150px;">
                <thead>
                    <tr><th>Client</th><th>Tenant</th><th>Property</th><th>Period</th><th>Rent</th><th>Paid</th><th>Balance</th><th>Status</th><th>Start</th><th>End</th><th>Created</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $leases as $l ) : ?>
                    <?php
                    $badge = match ( $l->status ) {
                        'active'        => 'ofp-badge-green',
                        'expired'       => 'ofp-badge-red',
                        'pending_offer' => 'ofp-badge-yellow',
                        'renewed'       => 'ofp-badge-blue',
                        default         => 'ofp-badge-yellow',
                    };
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $l->business_name ?: '—' ); ?></strong></td>
                        <td><?php echo esc_html( $l->tenant_name ); ?><br><small><?php echo esc_html( $l->tenant_phone ); ?></small></td>
                        <td><?php echo esc_html( $l->property_title ?: '—' ); ?></td>
                        <td><?php echo esc_html( OFP_Property_Rent::period_label( $l->rent_period, (int) $l->custom_days ) ); ?></td>
                        <td><strong>₦<?php echo esc_html( number_format( (float) $l->rent_amount, 0 ) ); ?></strong></td>
                        <td>₦<?php echo esc_html( number_format( (float) $l->amount_paid, 0 ) ); ?></td>
                        <td>₦<?php echo esc_html( number_format( (float) $l->balance, 0 ) ); ?></td>
                        <td><span class="ofp-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $l->status ) ) ); ?></span></td>
                        <td><?php echo esc_html( $l->start_date ?: '—' ); ?></td>
                        <td><?php echo esc_html( $l->end_date ?: '—' ); ?></td>
                        <td><small><?php echo esc_html( $l->created_at ); ?></small></td>
                        <td>
                            <button type="button" class="button button-small"
                                onclick="ofpOpenAdminLeaseModal({
                                    id: <?php echo (int) $l->id; ?>,
                                    tenant: '<?php echo esc_js( $l->tenant_name ); ?>',
                                    property: '<?php echo esc_js( $l->property_title ?: ( 'Property #' . $l->property_id ) ); ?>',
                                    status: '<?php echo esc_js( $l->status ); ?>',
                                    start: '<?php echo esc_js( $l->start_date ?: '' ); ?>',
                                    end: '<?php echo esc_js( $l->end_date ?: '' ); ?>'
                                })">
                                Update
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ( $active_tab === 'tenants' ) : ?>
    <?php if ( empty( $tenants ) ) : ?>
        <p>No tenants found.</p>
    <?php else : ?>
        <div style="overflow-x:auto;">
            <table class="widefat ofp-table" style="min-width:960px;">
                <thead>
                    <tr><th>Client</th><th>Tenant Name</th><th>Phone</th><th>Email</th><th>Leases</th><th>Active</th><th>Total Paid</th><th>Status</th><th>Created</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $tenants as $t ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $t->business_name ?: '—' ); ?></strong></td>
                        <td><strong><?php echo esc_html( $t->full_name ); ?></strong></td>
                        <td><?php echo esc_html( $t->phone ); ?></td>
                        <td><?php echo esc_html( $t->email ?: '—' ); ?></td>
                        <td><?php echo esc_html( $t->total_leases ); ?></td>
                        <td><?php echo esc_html( $t->active_leases ); ?></td>
                        <td>₦<?php echo esc_html( number_format( (float) $t->total_paid, 0 ) ); ?></td>
                        <td><span class="ofp-badge <?php echo $t->status === 'active' ? 'ofp-badge-green' : 'ofp-badge-red'; ?>"><?php echo esc_html( ucfirst( $t->status ) ); ?></span></td>
                        <td><small><?php echo esc_html( $t->created_at ); ?></small></td>
                        <td>
                            <button type="button" class="button button-small"
                                onclick="ofpOpenAdminTenantModal({
                                    id: <?php echo (int) $t->id; ?>,
                                    name: '<?php echo esc_js( $t->full_name ); ?>',
                                    phone: '<?php echo esc_js( $t->phone ); ?>',
                                    email: '<?php echo esc_js( $t->email ?: '' ); ?>',
                                    status: '<?php echo esc_js( $t->status ); ?>'
                                })">
                                Edit
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ( $active_tab === 'payments' ) : ?>
    <?php if ( empty( $payments ) ) : ?>
        <p>No rent payments found.</p>
    <?php else : ?>
        <div style="overflow-x:auto;">
            <table class="widefat ofp-table" style="min-width:1100px;">
                <thead>
                    <tr><th>Client</th><th>Tenant</th><th>Property</th><th>Method</th><th>Amount</th><th>Status</th><th>Reference</th><th>Receipt</th><th>Date</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $payments as $pay ) : ?>
                    <?php
                    $pay_badge = match ( $pay->status ) {
                        'successful'           => 'ofp-badge-green',
                        'pending_verification' => 'ofp-badge-yellow',
                        'failed','rejected','cancelled' => 'ofp-badge-red',
                        default                => 'ofp-badge-yellow',
                    };
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $pay->business_name ?: '—' ); ?></strong></td>
                        <td><?php echo esc_html( $pay->tenant_name ); ?></td>
                        <td><?php echo esc_html( $pay->property_title ?: '—' ); ?></td>
                        <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $pay->payment_method ) ) ); ?></td>
                        <td><strong>₦<?php echo esc_html( number_format( (float) $pay->amount, 2 ) ); ?></strong></td>
                        <td><span class="ofp-badge <?php echo esc_attr( $pay_badge ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $pay->status ) ) ); ?></span></td>
                        <td><small><?php echo esc_html( $pay->gateway_reference ?: $pay->payer_reference ?: '—' ); ?></small></td>
                        <td><?php echo ! empty( $pay->receipt_path ) ? '<span class="ofp-badge ofp-badge-green">Yes</span>' : '—'; ?></td>
                        <td><small><?php echo esc_html( $pay->created_at ); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php if ( $total_pages > 1 ) : ?>
    <div class="ofp-pagination" style="margin-top:16px;">
        <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>" class="button button-small <?php echo $i === $current_page ? 'button-primary' : ''; ?>"><?php echo esc_html( $i ); ?></a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

</div>

<!-- Admin Modal: Edit Tenant -->
<div id="ofp-admin-tenant-modal" style="display:none; position:fixed; z-index:99999; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(3px); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; width:100%; max-width:480px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); overflow:hidden;">
        <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:16px; font-weight:600;">Edit Tenant Information</h3>
            <button type="button" onclick="ofpCloseAdminModal('ofp-admin-tenant-modal')" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
        </div>
        <form method="POST" action="" style="padding:20px;">
            <?php wp_nonce_field( 'ofp_rent_admin_action', '_ofp_rent_admin_nonce' ); ?>
            <input type="hidden" name="ofp_admin_action" value="update_tenant">
            <input type="hidden" name="tab" value="tenants">
            <input type="hidden" name="tenant_id" id="ofp-edit-tenant-id" value="">

            <div style="margin-bottom:14px;">
                <label style="display:block; font-weight:600; margin-bottom:4px;" for="ofp-edit-tenant-name">Full Name <span style="color:#ef4444;">*</span></label>
                <input type="text" name="full_name" id="ofp-edit-tenant-name" class="widefat" required>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-weight:600; margin-bottom:4px;" for="ofp-edit-tenant-phone">Phone Number <span style="color:#ef4444;">*</span></label>
                <input type="text" name="phone" id="ofp-edit-tenant-phone" class="widefat" required>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-weight:600; margin-bottom:4px;" for="ofp-edit-tenant-email">Email Address</label>
                <input type="email" name="email" id="ofp-edit-tenant-email" class="widefat">
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; font-weight:600; margin-bottom:4px;" for="ofp-edit-tenant-status">Status</label>
                <select name="status" id="ofp-edit-tenant-status" class="widefat">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="button" onclick="ofpCloseAdminModal('ofp-admin-tenant-modal')">Cancel</button>
                <button type="submit" class="button button-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Admin Modal: Update Lease Status -->
<div id="ofp-admin-lease-modal" style="display:none; position:fixed; z-index:99999; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(3px); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; width:100%; max-width:480px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); overflow:hidden;">
        <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; font-size:16px; font-weight:600;">Update Lease Status</h3>
            <button type="button" onclick="ofpCloseAdminModal('ofp-admin-lease-modal')" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
        </div>
        <form method="POST" action="" style="padding:20px;">
            <?php wp_nonce_field( 'ofp_rent_admin_action', '_ofp_rent_admin_nonce' ); ?>
            <input type="hidden" name="ofp_admin_action" value="update_lease">
            <input type="hidden" name="tab" value="leases">
            <input type="hidden" name="lease_id" id="ofp-edit-lease-id" value="">

            <div style="margin-bottom:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; font-size:13px; line-height:1.5;">
                <div><strong>Tenant:</strong> <span id="ofp-edit-lease-tenant">—</span></div>
                <div><strong>Property:</strong> <span id="ofp-edit-lease-property">—</span></div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-weight:600; margin-bottom:4px;" for="ofp-edit-lease-status">Lease Status <span style="color:#ef4444;">*</span></label>
                <select name="status" id="ofp-edit-lease-status" class="widefat" required>
                    <option value="pending_offer">Pending Offer</option>
                    <option value="active">Active (Occupied)</option>
                    <option value="expired">Expired</option>
                    <option value="renewed">Renewed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <p class="description" style="margin-top:4px;">Setting to Expired or Cancelled frees up the property on public listings immediately.</p>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:20px;">
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:4px;" for="ofp-edit-lease-start">Start Date</label>
                    <input type="date" name="start_date" id="ofp-edit-lease-start" class="widefat">
                </div>
                <div>
                    <label style="display:block; font-weight:600; margin-bottom:4px;" for="ofp-edit-lease-end">End Date</label>
                    <input type="date" name="end_date" id="ofp-edit-lease-end" class="widefat">
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="button" onclick="ofpCloseAdminModal('ofp-admin-lease-modal')">Cancel</button>
                <button type="submit" class="button button-primary">Update Lease</button>
            </div>
        </form>
    </div>
</div>

<script>
function ofpOpenAdminTenantModal(data) {
    document.getElementById('ofp-edit-tenant-id').value = data.id || '';
    document.getElementById('ofp-edit-tenant-name').value = data.name || '';
    document.getElementById('ofp-edit-tenant-phone').value = data.phone || '';
    document.getElementById('ofp-edit-tenant-email').value = data.email || '';
    document.getElementById('ofp-edit-tenant-status').value = data.status || 'active';
    var modal = document.getElementById('ofp-admin-tenant-modal');
    modal.style.display = 'flex';
}

function ofpOpenAdminLeaseModal(data) {
    document.getElementById('ofp-edit-lease-id').value = data.id || '';
    document.getElementById('ofp-edit-lease-tenant').textContent = data.tenant || '—';
    document.getElementById('ofp-edit-lease-property').textContent = data.property || '—';
    document.getElementById('ofp-edit-lease-status').value = data.status || 'pending_offer';
    document.getElementById('ofp-edit-lease-start').value = data.start || '';
    document.getElementById('ofp-edit-lease-end').value = data.end || '';
    var modal = document.getElementById('ofp-admin-lease-modal');
    modal.style.display = 'flex';
}

function ofpCloseAdminModal(id) {
    var modal = document.getElementById(id);
    if (modal) modal.style.display = 'none';
}

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ofpCloseAdminModal('ofp-admin-tenant-modal');
        ofpCloseAdminModal('ofp-admin-lease-modal');
    }
});
</script>

<?php include OFP_PATH . 'admin/views/partials/footer.php'; ?>
