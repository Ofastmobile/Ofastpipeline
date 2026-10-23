<?php
/**
 * Admin View: Customers List
 *
 * Displays all customers across all clients (or filtered by a specific client).
 * Built from property buyers, tenants, and converted leads merged by phone/email.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

global $wpdb;
$p = $wpdb->prefix;

$filter_client = (int) ( $_GET['client_id'] ?? 0 );
$filter_tag    = sanitize_text_field( $_GET['tag'] ?? '' );
$search_q      = sanitize_text_field( $_GET['q'] ?? '' );
$open_key      = sanitize_text_field( $_GET['c'] ?? '' );
$per_page      = 50;
$current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

// Fetch customers via OFP_Contacts (0 = all clients, or filtered by client_id)
$all_customers = OFP_Contacts::customers( $filter_client );

// Calculate overall KPI counts across the dataset before search/tag filtering
$counts = [ 'Buyer' => 0, 'Tenant' => 0, 'Converted lead' => 0 ];
$total_sold = 0.0;
$total_paid = 0.0;
foreach ( $all_customers as $cust ) {
    foreach ( (array) ( $cust['tags'] ?? [] ) as $t ) {
        if ( isset( $counts[ $t ] ) ) {
            $counts[ $t ]++;
        }
    }
    $total_sold += (float) ( $cust['total'] ?? 0 );
    $total_paid += (float) ( $cust['paid'] ?? 0 );
}

// Filter dataset
$filtered = array_filter( $all_customers, static function ( $c ) use ( $filter_tag, $search_q ) {
    if ( $filter_tag !== '' && ! in_array( $filter_tag, (array) ( $c['tags'] ?? [] ), true ) ) {
        return false;
    }
    if ( $search_q !== '' ) {
        $hay = strtolower( ( $c['name'] ?? '' ) . ' ' . ( $c['phone'] ?? '' ) . ' ' . ( $c['email'] ?? '' ) . ' ' . ( $c['client_name'] ?? '' ) );
        if ( strpos( $hay, strtolower( $search_q ) ) === false ) {
            $digits = preg_replace( '/\D/', '', $search_q );
            if ( $digits === '' || strpos( preg_replace( '/\D/', '', (string) ( $c['phone'] ?? '' ) ), $digits ) === false ) {
                return false;
            }
        }
    }
    return true;
} );

// ── View System Receipt for Purchase Payment ─────────────────────────────────
if ( ! empty( $_GET['rcpt'] ) ) {
    $pay_id = absint( $_GET['rcpt'] );
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT py.*, pu.buyer_name, pu.buyer_email, pu.buyer_phone, pu.balance, pu.property_id, pu.client_id,
                pr.title AS property_title, pr.listing_type, pr.location_text,
                c.business_name, c.owner_name, c.email AS client_email, c.phone AS client_phone, c.business_phone AS client_business_phone
         FROM {$p}ofp_property_payments py
         INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
         LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
         LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
         WHERE py.id = %d AND py.status = 'successful' LIMIT 1",
        $pay_id
    ) );
    if ( $row && class_exists( 'OFP_Property_Receipt_Generator' ) ) {
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        echo OFP_Property_Receipt_Generator::render_receipt(
            OFP_Property_Receipt_Generator::TYPE_PURCHASE,
            OFP_Property_Receipt_Generator::build_purchase_data( $row, (int) $row->purchase_id, $pay_id )
        );
        exit;
    }
    wp_die( 'Receipt not found.', 'Not Found', [ 'response' => 404 ] );
}

// ── View System Receipt for Lease/Rent Payment ───────────────────────────────
if ( ! empty( $_GET['rcpt_lease'] ) ) {
    $pay_id = absint( $_GET['rcpt_lease'] );
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT p.*, l.rent_amount, l.legal_fee, l.agency_fee, l.caution_fee, l.service_charge,
                l.total_initial_package, l.cycle_number, l.amount_paid, l.balance, l.rent_period, l.custom_days,
                l.start_date, l.end_date, l.property_id AS lease_property_id,
                t.full_name AS tenant_name, t.email AS tenant_email, t.phone AS tenant_phone, t.id AS tenant_record_id,
                pr.title AS property_title, pr.listing_type, pr.location_text,
                c.business_name, c.owner_name, c.email AS client_email, c.phone AS client_phone,
                c.business_phone AS client_business_phone, l.client_id AS receipt_client_id
         FROM {$p}ofp_property_lease_payments p
         INNER JOIN {$p}ofp_property_leases l ON l.id = p.lease_id
         INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
         LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
         LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
         WHERE p.id = %d AND p.status = 'successful' LIMIT 1",
        $pay_id
    ) );
    if ( $row && class_exists( 'OFP_Property_Receipt_Generator' ) ) {
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        echo OFP_Property_Receipt_Generator::render_receipt(
            OFP_Property_Receipt_Generator::TYPE_RENT,
            OFP_Property_Receipt_Generator::build_rent_data( $row, (int) $row->lease_id, $pay_id )
        );
        exit;
    }
    wp_die( 'Receipt not found.', 'Not Found', [ 'response' => 404 ] );
}

// ── Attach/Replace Receipt Upload Handler ────────────────────────────────────
$receipt_notice_ok  = '';
$receipt_notice_err = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_admin_receipt_nonce'] ) ) {
    $pay_id        = absint( $_POST['payment_id'] ?? 0 );
    $pay_client_id = absint( $_POST['client_id'] ?? 0 );
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ofp_admin_receipt_nonce'] ) ), 'ofp_receipt_admin_' . $pay_id ) ) {
        $receipt_notice_err = 'Security token expired. Please refresh and try again.';
    } elseif ( empty( $_FILES['receipt']['name'] ) ) {
        $receipt_notice_err = 'Please select a valid receipt file (PDF, JPG, PNG).';
    } else {
        $done = class_exists( 'OFP_Import' ) 
            ? OFP_Import::attach_receipt( $pay_client_id, $pay_id, $_FILES['receipt'] ) 
            : new WP_Error( 'unavailable', 'Receipt upload handler not loaded.' );
        if ( is_wp_error( $done ) ) {
            $receipt_notice_err = $done->get_error_message();
        } else {
            $is_portal = ( strpos( $_SERVER['REQUEST_URI'] ?? '', 'admin-portal' ) !== false );
            $base_u    = $is_portal ? home_url( '/admin-portal?tab=customers' ) : admin_url( 'admin.php?page=ofp-customers' );
            wp_safe_redirect( add_query_arg( [ 'c' => $open_key, 'receipt_saved' => 1 ], $base_u ) );
            exit;
        }
    }
}
if ( ! empty( $_GET['receipt_saved'] ) ) {
    $receipt_notice_ok = 'Receipt file attached successfully.';
}

// CSV Export Handler
if ( ! empty( $_GET['export'] ) ) {
    while ( ob_get_level() > 0 ) {
        @ob_end_clean();
    }
    nocache_headers();
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="admin-customers-' . gmdate( 'Y-m-d' ) . '.csv"' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, [ 'Customer Name', 'Phone', 'Email', 'Client / Business', 'Type', 'Purchases Count', 'Total Purchases (NGN)', 'Amount Paid (NGN)', 'Balance (NGN)', 'Leases Count', 'Last Activity' ] );
    foreach ( $filtered as $row ) {
        fputcsv( $out, [
            $row['name'] ?: 'Unnamed Customer',
            $row['phone'] ?? '',
            $row['email'] ?? '',
            $row['client_name'] ?: '—',
            implode( ', ', (array) ( $row['tags'] ?? [] ) ),
            (int) ( $row['purchases'] ?? 0 ),
            number_format( (float) ( $row['total'] ?? 0 ), 2, '.', '' ),
            number_format( (float) ( $row['paid'] ?? 0 ), 2, '.', '' ),
            number_format( (float) ( $row['balance'] ?? 0 ), 2, '.', '' ),
            (int) ( $row['leases'] ?? 0 ),
            $row['last_at'] ?? '',
        ] );
    }
    fclose( $out );
    exit;
}

$total_filtered = count( $filtered );
$total_pages    = max( 1, (int) ceil( $total_filtered / $per_page ) );
$offset         = ( $current_page - 1 ) * $per_page;
$customers_page = array_slice( $filtered, $offset, $per_page, true );

// Clients dropdown list
$clients = OFP_Client::all();

// Customer detail data if a customer key is selected
$detail = ( $open_key !== '' && isset( $all_customers[ $open_key ] ) ) ? $all_customers[ $open_key ] : null;
$detail_purchases      = [];
$detail_payments       = [];
$detail_leases         = [];
$detail_lease_payments = [];

if ( $detail ) {
    if ( ! empty( $detail['purchase_ids'] ) ) {
        $p_ids = array_map( 'intval', $detail['purchase_ids'] );
        $in_p  = implode( ',', array_fill( 0, count( $p_ids ), '%d' ) );
        $detail_purchases = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT pu.*, pr.title AS property_title, c.business_name
             FROM {$p}ofp_property_purchases pu
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
             WHERE pu.id IN ({$in_p})
             ORDER BY pu.created_at DESC",
            ...$p_ids
        ) );

        $pay_rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_payments WHERE purchase_id IN ({$in_p}) AND status = 'successful' ORDER BY created_at ASC",
            ...$p_ids
        ) );
        foreach ( $pay_rows as $pr ) {
            $detail_payments[ (int) $pr->purchase_id ][] = $pr;
        }
    }

    if ( ! empty( $detail['tenant_ids'] ) ) {
        $t_ids = array_map( 'intval', $detail['tenant_ids'] );
        $in_t  = implode( ',', array_fill( 0, count( $t_ids ), '%d' ) );
        $detail_leases = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, pr.title AS property_title, c.business_name
             FROM {$p}ofp_property_leases l
             LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
             WHERE l.tenant_id IN ({$in_t})
             ORDER BY l.created_at DESC",
            ...$t_ids
        ) );

        $lease_ids = array_values( array_filter( array_map( 'intval', wp_list_pluck( $detail_leases, 'id' ) ) ) );
        if ( ! empty( $lease_ids ) ) {
            $in_l = implode( ',', array_fill( 0, count( $lease_ids ), '%d' ) );
            $l_pay_rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$p}ofp_property_lease_payments WHERE lease_id IN ({$in_l}) AND status = 'successful' ORDER BY created_at ASC",
                ...$lease_ids
            ) );
            foreach ( $l_pay_rows as $lpr ) {
                $detail_lease_payments[ (int) $lpr->lease_id ][] = $lpr;
            }
        }
    }
}

$is_portal   = ( strpos( $_SERVER['REQUEST_URI'] ?? '', 'admin-portal' ) !== false );
$base_url    = $is_portal ? home_url( '/admin-portal?tab=customers' ) : admin_url( 'admin.php?page=ofp-customers' );
$format_money = static fn( $val ) => '₦' . number_format( (float) $val, 0 );

include OFP_PATH . 'admin/views/partials/header.php';
?>

<div class="ofp-section-header" style="margin-bottom: 20px;">
    <h2>Customers <span class="ofp-count">(<?php echo esc_html( number_format( count( $all_customers ) ) ); ?> total)</span></h2>
    <div style="display:flex; gap:10px; align-items:center;">
        <a href="<?php echo esc_url( add_query_arg( [ 'export' => 1 ], $base_url ) ); ?>" class="button">
            📥 Export CSV
        </a>
    </div>
</div>

<!-- ── KPI Stats Cards ──────────────────────────────────────────────────────── -->
<div class="ofp-stats-grid" style="margin-bottom: 24px;">
    <div class="ofp-stat-card">
        <span class="ofp-stat-number ofp-accent"><?php echo esc_html( number_format( count( $all_customers ) ) ); ?></span>
        <span class="ofp-stat-label">Total Customers</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( number_format( $counts['Buyer'] ) ); ?></span>
        <span class="ofp-stat-label">Buyers</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( number_format( $counts['Tenant'] ) ); ?></span>
        <span class="ofp-stat-label">Tenants</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( number_format( $counts['Converted lead'] ) ); ?></span>
        <span class="ofp-stat-label">Converted Leads</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number" style="color: #166534;"><?php echo esc_html( $format_money( $total_paid ) ); ?></span>
        <span class="ofp-stat-label">Total Collections</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( $format_money( $total_sold ) ); ?></span>
        <span class="ofp-stat-label">Total Sales Value</span>
    </div>
</div>

<!-- ── Filter Bar ───────────────────────────────────────────────────────────── -->
<div class="ofp-filters" style="background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:16px; margin-bottom:24px;">
    <form method="GET" action="" class="ofp-filter-form" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
        <?php if ( $is_portal ) : ?>
            <input type="hidden" name="tab" value="customers">
        <?php else : ?>
            <input type="hidden" name="page" value="ofp-customers">
        <?php endif; ?>

        <select name="client_id" onchange="this.form.submit()" style="min-width: 180px;">
            <option value="">All Clients</option>
            <?php foreach ( $clients as $c ) : ?>
                <option value="<?php echo esc_attr( $c->id ); ?>" <?php selected( $filter_client, $c->id ); ?>>
                    <?php echo esc_html( $c->business_name ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="tag" onchange="this.form.submit()" style="min-width: 150px;">
            <option value="">All Types / Tags</option>
            <option value="Buyer" <?php selected( $filter_tag, 'Buyer' ); ?>>Buyer</option>
            <option value="Tenant" <?php selected( $filter_tag, 'Tenant' ); ?>>Tenant</option>
            <option value="Converted lead" <?php selected( $filter_tag, 'Converted lead' ); ?>>Converted lead</option>
        </select>

        <input type="text" name="q" value="<?php echo esc_attr( $search_q ); ?>" placeholder="Search name, phone, email, client..." style="min-width: 260px; padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 4px;">

        <button type="submit" class="button button-secondary">Search</button>

        <?php if ( $filter_client || $filter_tag || $search_q ) : ?>
            <a href="<?php echo esc_url( $base_url ); ?>" class="button">Clear Filters</a>
        <?php endif; ?>
    </form>
</div>

<!-- ── Customer Detail Drawer (if selected) ─────────────────────────────────── -->
<?php if ( $detail ) : 
    $base_params = [
        'client_id' => $filter_client,
        'tag'       => $filter_tag,
        'q'         => $search_q,
        'paged'     => $current_page,
    ];
    $close_url = add_query_arg( array_filter( $base_params ), $base_url );
?>
<div class="ofp-section" style="border: 2px solid #2563eb; background: #f8fafc; margin-bottom: 24px; position: relative; border-radius: 10px;">
    <?php if ( ! empty( $receipt_notice_ok ) ) : ?>
        <div style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; border-radius:6px; padding:10px 14px; margin-bottom:14px; font-size:13px; font-weight:600;">
            ✅ <?php echo esc_html( $receipt_notice_ok ); ?>
        </div>
    <?php endif; ?>
    <?php if ( ! empty( $receipt_notice_err ) ) : ?>
        <div style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; padding:10px 14px; margin-bottom:14px; font-size:13px; font-weight:600;">
            ⚠️ <?php echo esc_html( $receipt_notice_err ); ?>
        </div>
    <?php endif; ?>
    <!-- Customer Header Profile -->
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px;">
        <div>
            <h3 style="margin:0 0 6px 0; font-size:20px; color:#0f172a; font-weight:700;">
                👤 <?php echo esc_html( $detail['name'] ?: 'Unnamed Customer' ); ?>
            </h3>
            <div style="display:flex; flex-wrap:wrap; gap:16px; font-size:13px; color:#475569; align-items:center;">
                <span><strong>Phone:</strong> <a href="tel:<?php echo esc_attr( $detail['phone'] ); ?>" style="text-decoration:none; color:#2563eb;"><?php echo esc_html( $detail['phone'] ); ?></a></span>
                <?php if ( ! empty( $detail['email'] ) ) : ?>
                    <span><strong>Email:</strong> <a href="mailto:<?php echo esc_attr( $detail['email'] ); ?>" style="text-decoration:none; color:#2563eb;"><?php echo esc_html( $detail['email'] ); ?></a></span>
                <?php endif; ?>
                <?php if ( ! empty( $detail['client_name'] ) ) : ?>
                    <span><strong>Client:</strong> <?php echo esc_html( $detail['client_name'] ); ?></span>
                <?php endif; ?>
                <span>
                    <strong>Type:</strong>
                    <?php foreach ( (array) ( $detail['tags'] ?? [] ) as $tag_item ) : 
                        $b_cls = 'ofp-badge-blue';
                        if ( $tag_item === 'Buyer' ) $b_cls = 'ofp-badge-green';
                        elseif ( $tag_item === 'Tenant' ) $b_cls = 'ofp-badge-orange';
                        elseif ( $tag_item === 'Converted lead' ) $b_cls = 'ofp-badge-yellow';
                    ?>
                        <span class="ofp-badge <?php echo esc_attr( $b_cls ); ?>"><?php echo esc_html( $tag_item ); ?></span>
                    <?php endforeach; ?>
                </span>
            </div>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <?php if ( ! empty( $detail['phone'] ) ) : ?>
                <a href="tel:<?php echo esc_attr( $detail['phone'] ); ?>" class="button button-small">📞 Call</a>
            <?php endif; ?>
            <a href="<?php echo esc_url( $close_url ); ?>" class="button button-small" style="text-decoration:none;">✕ Close Detail</a>
        </div>
    </div>

    <!-- Mini Financial Summary Strip (like frontend) -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:20px;">
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">
            <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Total Purchased</div>
            <div style="font-size:18px; font-weight:700; color:#0f172a;"><?php echo esc_html( $format_money( $detail['total'] ) ); ?></div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">
            <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Total Paid</div>
            <div style="font-size:18px; font-weight:700; color:#166534;"><?php echo esc_html( $format_money( $detail['paid'] ) ); ?></div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">
            <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Remaining Balance</div>
            <div style="font-size:18px; font-weight:700; color:<?php echo $detail['balance'] > 0 ? '#dc2626' : '#166534'; ?>;">
                <?php echo $detail['balance'] > 0 ? esc_html( $format_money( $detail['balance'] ) ) : 'Fully Paid'; ?>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px;">
            <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Last Activity</div>
            <div style="font-size:14px; font-weight:600; color:#475569; margin-top:2px;">
                <?php echo ! empty( $detail['last_at'] ) ? esc_html( human_time_diff( strtotime( $detail['last_at'] ), current_time( 'timestamp' ) ) . ' ago' ) : '—'; ?>
            </div>
        </div>
    </div>

    <!-- ── Property Purchases & Milestone Payments ──────────────────────────── -->
    <?php if ( ! empty( $detail_purchases ) ) : ?>
        <div style="font-size:14px; font-weight:700; color:#0f172a; margin:20px 0 12px; display:flex; align-items:center; gap:8px;">
            <span>🏢 Property Purchases &amp; Milestone Payments (<?php echo count( $detail_purchases ); ?>)</span>
        </div>

        <?php foreach ( $detail_purchases as $pu ) : ?>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <!-- Deal Header -->
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                    <div>
                        <span style="font-weight:700; font-size:15px; color:#0f172a;"><?php echo esc_html( $pu->property_title ?: ( 'Property #' . $pu->property_id ) ); ?></span>
                        <?php if ( ! empty( $pu->business_name ) ) : ?>
                            <span style="color:#64748b; font-size:13px; margin-left:8px;">&middot; Client: <strong><?php echo esc_html( $pu->business_name ); ?></strong></span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="ofp-badge <?php echo $pu->status === 'completed' ? 'ofp-badge-green' : 'ofp-badge-yellow'; ?>" style="text-transform:capitalize;">
                            <?php echo esc_html( $pu->status ); ?>
                        </span>
                        <span style="color:#64748b; font-size:12px;"><?php echo esc_html( gmdate( 'M j, Y', strtotime( $pu->created_at ) ) ); ?></span>
                    </div>
                </div>

                <!-- Deal Financials -->
                <div style="display:flex; gap:16px; flex-wrap:wrap; font-size:13px; margin-bottom:14px; background:#f8fafc; padding:10px 14px; border-radius:6px; border:1px solid #f1f5f9;">
                    <span>Price: <strong style="color:#0f172a;"><?php echo esc_html( $format_money( $pu->total_price ) ); ?></strong></span>
                    <span>Paid: <strong style="color:#166534;"><?php echo esc_html( $format_money( $pu->amount_paid ) ); ?></strong></span>
                    <span>Balance: <strong style="color:<?php echo $pu->balance > 0 ? '#dc2626' : '#166534'; ?>;"><?php echo esc_html( $format_money( $pu->balance ) ); ?></strong></span>
                </div>

                <!-- Individual Payments List with Transaction ID & View Receipt -->
                <div>
                    <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:8px; letter-spacing:0.04em;">
                        Transactions &amp; Receipts
                    </div>
                    <?php if ( empty( $detail_payments[ (int) $pu->id ] ) ) : ?>
                        <div style="color:#94a3b8; font-size:13px; font-style:italic; padding:6px 0;">
                            No successful transactions recorded for this property purchase yet.
                        </div>
                    <?php else : ?>
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <?php foreach ( $detail_payments[ (int) $pu->id ] as $py ) :
                                $imported       = ( 'import' === ( $py->gateway ?? '' ) );
                                $has_file       = ! empty( $py->receipt_path );
                                $receipt_no     = 'PUR-' . str_pad( (string) $py->id, 6, '0', STR_PAD_LEFT );
                                $raw_ref        = (string) ( $py->gateway_reference ?: ( $py->payer_reference ?: '' ) );
                                $display_ref    = class_exists( 'OFP_Property_Receipt_Generator' ) 
                                    ? OFP_Property_Receipt_Generator::format_display_reference( $raw_ref, $receipt_no ) 
                                    : ( $raw_ref ?: $receipt_no );
                                $method_label   = ucwords( str_replace( '_', ' ', (string) ( $py->payment_method ?: 'Payment' ) ) );
                                $rcpt_url       = add_query_arg( [ 'rcpt' => (int) $py->id ], $base_url );
                            ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 14px;">
                                    <div>
                                        <span style="font-weight:700; font-size:14px; color:#166534;"><?php echo esc_html( $format_money( $py->amount ) ); ?></span>
                                        <span style="color:#64748b; font-size:12px; margin-left:6px;">paid on <?php echo esc_html( gmdate( 'M j, Y g:ia', strtotime( $py->created_at ) ) ); ?></span>
                                        
                                        <div style="margin-top:4px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; font-size:12px;">
                                            <span style="background:#e2e8f0; color:#334155; padding:2px 8px; border-radius:4px; font-weight:500;">
                                                <?php echo esc_html( $method_label ); ?>
                                            </span>
                                            <span style="background:#fff; color:#1e293b; padding:2px 8px; border-radius:4px; border:1px solid #cbd5e1; font-family:monospace; font-size:12px;">
                                                Transaction ID: <strong><?php echo esc_html( $display_ref ); ?></strong>
                                            </span>
                                            <?php if ( $imported ) : ?>
                                                <span style="background:#fef3c7; color:#92400e; padding:2px 6px; border-radius:4px; font-size:11px;">Imported</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                        <?php if ( $has_file && class_exists( 'OFP_Property_Receipt_Viewer' ) ) : ?>
                                            <a href="<?php echo esc_url( OFP_Property_Receipt_Viewer::get_receipt_url( (int) $py->id, 'purchase' ) ); ?>" target="_blank" rel="noopener" class="button button-small" style="display:inline-flex; align-items:center; gap:4px; font-weight:600;">
                                                <span>📎 Uploaded Receipt</span>
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url( $rcpt_url ); ?>" target="_blank" rel="noopener" class="button button-small button-primary" style="display:inline-flex; align-items:center; gap:4px; font-weight:600;">
                                            <span>🧾 View Receipt</span>
                                        </a>
                                        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( add_query_arg( 'c', $open_key, $base_url ) ); ?>" style="display:inline-flex; align-items:center; margin:0;">
                                            <?php wp_nonce_field( 'ofp_receipt_admin_' . $py->id, 'ofp_admin_receipt_nonce' ); ?>
                                            <input type="hidden" name="payment_id" value="<?php echo (int) $py->id; ?>">
                                            <input type="hidden" name="client_id" value="<?php echo (int) ( $pu->client_id ?? 0 ); ?>">
                                            <label class="button button-small" style="cursor:pointer; display:inline-flex; align-items:center; gap:4px; margin:0;" title="<?php echo $has_file ? 'Replace uploaded receipt' : 'Attach company receipt file (PDF, JPG, PNG)'; ?>">
                                                <span>📁 <?php echo $has_file ? 'Replace Receipt' : 'Attach Receipt'; ?></span>
                                                <input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png" style="display:none;" onchange="this.form.submit()">
                                            </label>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- ── Rental Leases & Payments ─────────────────────────────────────────── -->
    <?php if ( ! empty( $detail_leases ) ) : ?>
        <div style="font-size:14px; font-weight:700; color:#0f172a; margin:24px 0 12px; display:flex; align-items:center; gap:8px;">
            <span>🔑 Tenancy Leases &amp; Rent Transactions (<?php echo count( $detail_leases ); ?>)</span>
        </div>

        <?php foreach ( $detail_leases as $le ) : ?>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <!-- Lease Header -->
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                    <div>
                        <span style="font-weight:700; font-size:15px; color:#0f172a;"><?php echo esc_html( $le->property_title ?: ( 'Rental Unit #' . $le->property_id ) ); ?></span>
                        <?php if ( ! empty( $le->business_name ) ) : ?>
                            <span style="color:#64748b; font-size:13px; margin-left:8px;">&middot; Client: <strong><?php echo esc_html( $le->business_name ); ?></strong></span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="ofp-badge ofp-badge-green" style="text-transform:capitalize;">
                            <?php echo esc_html( $le->status ); ?>
                        </span>
                    </div>
                </div>

                <!-- Lease Terms -->
                <div style="display:flex; gap:16px; flex-wrap:wrap; font-size:13px; margin-bottom:14px; background:#f8fafc; padding:10px 14px; border-radius:6px; border:1px solid #f1f5f9;">
                    <span>Rent Amount: <strong style="color:#0f172a;"><?php echo esc_html( $format_money( $le->rent_amount ) ); ?></strong></span>
                    <span>Lease Term: <strong><?php echo esc_html( ( $le->start_date ? gmdate( 'M j, Y', strtotime( $le->start_date ) ) : '—' ) . ' to ' . ( $le->end_date ? gmdate( 'M j, Y', strtotime( $le->end_date ) ) : 'Open' ) ); ?></strong></span>
                </div>

                <!-- Rent Payments & Receipts -->
                <div>
                    <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:8px; letter-spacing:0.04em;">
                        Rent Payments &amp; Receipts
                    </div>
                    <?php if ( empty( $detail_lease_payments[ (int) $le->id ] ) ) : ?>
                        <div style="color:#94a3b8; font-size:13px; font-style:italic; padding:6px 0;">
                            No lease payments recorded for this rental yet.
                        </div>
                    <?php else : ?>
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <?php foreach ( $detail_lease_payments[ (int) $le->id ] as $lpy ) :
                                $has_file       = ! empty( $lpy->receipt_path );
                                $receipt_no     = 'LSE-' . str_pad( (string) $lpy->id, 6, '0', STR_PAD_LEFT );
                                $raw_ref        = (string) ( $lpy->gateway_reference ?: ( $lpy->payer_reference ?: '' ) );
                                $display_ref    = class_exists( 'OFP_Property_Receipt_Generator' ) 
                                    ? OFP_Property_Receipt_Generator::format_display_reference( $raw_ref, $receipt_no ) 
                                    : ( $raw_ref ?: $receipt_no );
                                $method_label   = ucwords( str_replace( '_', ' ', (string) ( $lpy->payment_method ?: 'Rent Payment' ) ) );
                                $rcpt_lease_url = add_query_arg( [ 'rcpt_lease' => (int) $lpy->id ], $base_url );
                            ?>
                                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 14px;">
                                    <div>
                                        <span style="font-weight:700; font-size:14px; color:#166534;"><?php echo esc_html( $format_money( $lpy->amount ) ); ?></span>
                                        <span style="color:#64748b; font-size:12px; margin-left:6px;">paid on <?php echo esc_html( gmdate( 'M j, Y g:ia', strtotime( $lpy->created_at ) ) ); ?></span>
                                        
                                        <div style="margin-top:4px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; font-size:12px;">
                                            <span style="background:#e2e8f0; color:#334155; padding:2px 8px; border-radius:4px; font-weight:500;">
                                                <?php echo esc_html( $method_label ); ?>
                                            </span>
                                            <span style="background:#fff; color:#1e293b; padding:2px 8px; border-radius:4px; border:1px solid #cbd5e1; font-family:monospace; font-size:12px;">
                                                Transaction ID: <strong><?php echo esc_html( $display_ref ); ?></strong>
                                            </span>
                                        </div>
                                    </div>

                                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                        <?php if ( $has_file && class_exists( 'OFP_Property_Receipt_Viewer' ) ) : ?>
                                            <a href="<?php echo esc_url( OFP_Property_Receipt_Viewer::get_receipt_url( (int) $lpy->id, 'rent' ) ); ?>" target="_blank" rel="noopener" class="button button-small" style="display:inline-flex; align-items:center; gap:4px; font-weight:600;">
                                                <span>📎 Uploaded Receipt</span>
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url( $rcpt_lease_url ); ?>" target="_blank" rel="noopener" class="button button-small button-primary" style="display:inline-flex; align-items:center; gap:4px; font-weight:600;">
                                            <span>🧾 View Receipt</span>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ( empty( $detail_purchases ) && empty( $detail_leases ) ) : ?>
        <div style="padding:16px; border-radius:8px; background:#fff; border:1px dashed #cbd5e1; color:#64748b; font-size:13px;">
            This customer is an active contact (converted lead). No property purchases or leases have been recorded for them yet.
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Customers Table ──────────────────────────────────────────────────────── -->
<div class="ofp-section">
    <?php if ( empty( $customers_page ) ) : ?>
        <p style="padding: 24px; text-align: center; color: #64748b;">
            No customers found matching your criteria.
        </p>
    <?php else : ?>
        <table class="widefat ofp-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th>Client</th>
                    <th>Type</th>
                    <th>Purchases</th>
                    <th>Total Paid</th>
                    <th>Balance</th>
                    <th>Last Activity</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $customers_page as $key => $c ) : 
                    $view_url = add_query_arg( [
                        'c'         => $key,
                        'client_id' => $filter_client,
                        'tag'       => $filter_tag,
                        'q'         => $search_q,
                        'paged'     => $current_page,
                    ], $base_url );
                    $client_link = ! empty( $c['client_id'] )
                        ? ( $is_portal
                            ? home_url( '/admin-portal?tab=clients&action=edit&client_id=' . (int) $c['client_id'] )
                            : admin_url( 'admin.php?page=ofp-clients&client_id=' . (int) $c['client_id'] ) )
                        : '';
                ?>
                    <tr style="<?php echo ( $open_key === $key ) ? 'background:#eff6ff;' : ''; ?>">
                        <td>
                            <a href="<?php echo esc_url( $view_url ); ?>">
                                <strong><?php echo esc_html( $c['name'] ?: 'Unnamed Customer' ); ?></strong>
                            </a>
                        </td>
                        <td>
                            <strong><?php echo esc_html( $c['phone'] ); ?></strong>
                            <?php if ( ! empty( $c['email'] ) ) : ?>
                                <br><small style="color:#64748b;"><?php echo esc_html( $c['email'] ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $client_link ) : ?>
                                <a href="<?php echo esc_url( $client_link ); ?>">
                                    <?php echo esc_html( $c['client_name'] ?: 'View Client' ); ?>
                                </a>
                            <?php else : ?>
                                <span style="color:#94a3b8;"><?php echo esc_html( $c['client_name'] ?: '—' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php foreach ( (array) ( $c['tags'] ?? [] ) as $tag_val ) : 
                                $badge_class = 'ofp-badge-blue';
                                if ( $tag_val === 'Buyer' ) $badge_class = 'ofp-badge-green';
                                elseif ( $tag_val === 'Tenant' ) $badge_class = 'ofp-badge-orange';
                                elseif ( $tag_val === 'Converted lead' ) $badge_class = 'ofp-badge-yellow';
                            ?>
                                <span class="ofp-badge <?php echo esc_attr( $badge_class ); ?>" style="margin-right: 4px;">
                                    <?php echo esc_html( $tag_val ); ?>
                                </span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php if ( ! empty( $c['purchases'] ) ) : ?>
                                <strong><?php echo (int) $c['purchases']; ?></strong> (<?php echo esc_html( $format_money( $c['total'] ) ); ?>)
                            <?php else : ?>
                                <span style="color:#94a3b8;">None</span>
                            <?php endif; ?>
                        </td>
                        <td style="<?php echo ! empty( $c['paid'] ) ? 'color:#166534; font-weight:600;' : 'color:#94a3b8;'; ?>">
                            <?php echo ! empty( $c['paid'] ) ? esc_html( $format_money( $c['paid'] ) ) : '—'; ?>
                        </td>
                        <td style="<?php echo ! empty( $c['balance'] ) ? 'color:#dc2626; font-weight:600;' : 'color:#94a3b8;'; ?>">
                            <?php echo ! empty( $c['balance'] ) ? esc_html( $format_money( $c['balance'] ) ) : '—'; ?>
                        </td>
                        <td style="color:#64748b; font-size:12px;">
                            <?php echo ! empty( $c['last_at'] ) ? esc_html( human_time_diff( strtotime( $c['last_at'] ), current_time( 'timestamp' ) ) . ' ago' ) : '—'; ?>
                        </td>
                        <td style="text-align: right;">
                            <a href="<?php echo esc_url( $view_url ); ?>" class="button button-small">View Details</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- ── Pagination ───────────────────────────────────────────────────── -->
        <?php if ( $total_pages > 1 ) : ?>
            <div class="ofp-pagination" style="margin-top: 20px; display: flex; gap: 4px; align-items: center;">
                <?php for ( $i = 1; $i <= $total_pages; $i++ ) : 
                    $page_link = add_query_arg( [
                        'paged'     => $i,
                        'client_id' => $filter_client,
                        'tag'       => $filter_tag,
                        'q'         => $search_q,
                    ], $base_url );
                ?>
                    <a href="<?php echo esc_url( $page_link ); ?>"
                       class="button button-small <?php echo $i === $current_page ? 'button-primary' : ''; ?>">
                        <?php echo esc_html( $i ); ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php include OFP_PATH . 'admin/views/partials/footer.php'; ?>
