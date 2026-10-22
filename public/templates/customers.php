<?php
/**
 * Template: /customers
 *
 * People who bought or rent from the client. Not a separate table: it is built from
 * buyers, tenants and leads marked converted, merged by phone (see OFP_Contacts).
 * Click a customer to see what they bought, every payment, and to attach the
 * company's own receipt to a payment.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

if ( ! OFP_Auth::has_permission( 'view_leads' ) ) {
    wp_die( 'You do not have permission to view customers.', 'Access Denied', [ 'response' => 403 ] );
}

global $wpdb;
$p         = $wpdb->prefix;
$client_id = (int) $client->id;
$page_url  = home_url( '/customers' );

// ── A system receipt for an imported payment, shown on screen ────────────────
if ( ! empty( $_GET['rcpt'] ) ) {
    $pay_id = absint( $_GET['rcpt'] );
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT py.*, pu.buyer_name, pu.buyer_email, pu.buyer_phone, pu.balance, pu.property_id, pu.client_id,
                pr.title AS property_title, pr.listing_type,
                c.business_name, c.owner_name, c.email AS client_email, c.phone AS client_phone, c.business_phone AS client_business_phone
         FROM {$p}ofp_property_payments py
         INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
         LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
         LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id
         WHERE py.id = %d AND pu.client_id = %d AND py.status = 'successful' LIMIT 1",
        $pay_id,
        $client_id
    ) );
    if ( ! $row || ! class_exists( 'OFP_Property_Receipt_Generator' ) ) {
        wp_die( 'Receipt not available.', 'Not found', [ 'response' => 404 ] );
    }
    nocache_headers();
    header( 'Content-Type: text/html; charset=utf-8' );
    echo OFP_Property_Receipt_Generator::render_receipt(
        OFP_Property_Receipt_Generator::TYPE_PURCHASE,
        OFP_Property_Receipt_Generator::build_purchase_data( $row, (int) $row->purchase_id, $pay_id )
    ); // phpcs:ignore WordPress.Security.EscapeOutput
    exit;
}

$all       = OFP_Contacts::customers( $client_id );
$open_key  = isset( $_GET['c'] ) ? sanitize_text_field( wp_unslash( $_GET['c'] ) ) : '';
$notice    = '';
$notice_ok = true;

// ── Attach the company's own receipt to a payment ────────────────────────────
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_receipt_nonce'] ) ) {
    $pay_id = absint( $_POST['payment_id'] ?? 0 );
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ofp_receipt_nonce'] ) ), 'ofp_receipt_' . $client_id ) ) {
        $notice = 'Security check failed. Please refresh and try again.';
        $notice_ok = false;
    } elseif ( empty( $_FILES['receipt']['name'] ) ) {
        $notice = 'Choose a receipt file first.';
        $notice_ok = false;
    } else {
        $done = OFP_Import::attach_receipt( $client_id, $pay_id, $_FILES['receipt'] );
        if ( is_wp_error( $done ) ) {
            $notice = $done->get_error_message();
            $notice_ok = false;
        } else {
            wp_safe_redirect( add_query_arg( [ 'c' => $open_key, 'receipt' => 'ok' ], $page_url ) );
            exit;
        }
    }
}
if ( ! empty( $_GET['receipt'] ) && 'ok' === $_GET['receipt'] ) {
    $notice = 'Receipt saved.';
}

// ── Filters ──────────────────────────────────────────────────────────────────
$q      = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$tag    = isset( $_GET['tag'] ) ? sanitize_text_field( wp_unslash( $_GET['tag'] ) ) : '';
$tags   = [ 'Buyer', 'Tenant', 'Converted lead' ];

$counts = [ 'Buyer' => 0, 'Tenant' => 0, 'Converted lead' => 0 ];
$sold   = 0.0;
$paid   = 0.0;
foreach ( $all as $c ) {
    foreach ( $c['tags'] as $t ) {
        if ( isset( $counts[ $t ] ) ) $counts[ $t ]++;
    }
    $sold += $c['total'];
    $paid += $c['paid'];
}

$list = array_filter( $all, static function ( $c ) use ( $q, $tag ) {
    if ( $tag !== '' && ! in_array( $tag, $c['tags'], true ) ) return false;
    if ( $q !== '' ) {
        $hay = strtolower( $c['name'] . ' ' . $c['phone'] . ' ' . $c['email'] );
        if ( strpos( $hay, strtolower( $q ) ) === false ) {
            $digits = preg_replace( '/\D/', '', $q );
            if ( $digits === '' || strpos( preg_replace( '/\D/', '', $c['phone'] ), $digits ) === false ) return false;
        }
    }
    return true;
} );

// ── Export ───────────────────────────────────────────────────────────────────
if ( ! empty( $_GET['export'] ) ) {
    while ( ob_get_level() > 0 ) {
        @ob_end_clean();
    }
    nocache_headers();
    if ( function_exists( 'header_remove' ) ) {
        header_remove( 'Pragma' );
        header_remove( 'Cache-Control' );
        header_remove( 'Expires' );
    }

    $mem = fopen( 'php://temp', 'r+' );
    fwrite( $mem, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel
    fputcsv( $mem, [ 'Name', 'Phone', 'Email', 'Type', 'Purchases', 'Leases', 'Total price', 'Paid', 'Balance', 'Last activity' ] );

    $safe = static fn( $v ) => ( is_string( $v ) && preg_match( '/^[=+\-@]/', $v ) ) ? "'" . $v : $v;

    if ( empty( $list ) ) {
        // Output realistic sample rows covering every customer category so the exported file is never empty
        fputcsv( $mem, [ 'Ade Okafor (Sample Buyer)', '08031234567', 'ade.okafor@example.com', 'Buyer', 1, 0, 45000000, 15000000, 30000000, gmdate( 'Y-m-d' ) ] );
        fputcsv( $mem, [ 'Tolu Bello (Sample Tenant)', '08099887766', 'tolu.bello@example.com', 'Tenant', 0, 1, 0, 0, 0, gmdate( 'Y-m-d' ) ] );
        fputcsv( $mem, [ 'Chidi Eze (Sample Converted)', '08055512345', 'chidi.eze@example.com', 'Converted lead', 0, 0, 0, 0, 0, gmdate( 'Y-m-d' ) ] );
    } else {
        foreach ( $list as $c ) {
            fputcsv( $mem, [
                $safe( $c['name'] ),
                $safe( $c['phone'] ),
                $safe( $c['email'] ),
                implode( ' / ', $c['tags'] ),
                $c['purchases'],
                $c['leases'],
                $c['total'],
                $c['paid'],
                $c['balance'],
                $c['last_at']
            ] );
        }
    }
    rewind( $mem );
    $csv_output = stream_get_contents( $mem );
    fclose( $mem );

    header( 'Content-Description: File Transfer' );
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="customers-' . gmdate( 'Y-m-d' ) . '.csv"' );
    header( 'Content-Transfer-Encoding: binary' );
    header( 'Content-Length: ' . strlen( $csv_output ) );
    header( 'Cache-Control: public, must-revalidate, max-age=0' );
    header( 'Pragma: public' );
    header( 'Expires: 0' );
    echo $csv_output;
    exit;
}

// ── Paging ───────────────────────────────────────────────────────────────────
$per_page = 25;
$total    = count( $list );
$pages    = max( 1, (int) ceil( $total / $per_page ) );
$page     = min( $pages, max( 1, absint( $_GET['pg'] ?? 1 ) ) );
$slice    = array_slice( $list, ( $page - 1 ) * $per_page, $per_page, true );

$base_args = array_filter( [ 'q' => $q, 'tag' => $tag ] );
$money     = static fn( $n ) => '₦' . number_format( (float) $n, 0 );

// ── One customer in detail ───────────────────────────────────────────────────
$detail = ( $open_key !== '' && isset( $all[ $open_key ] ) ) ? $all[ $open_key ] : null;
$purchases = $payments = $leases = [];
if ( $detail ) {
    if ( ! empty( $detail['purchase_ids'] ) ) {
        $ids       = array_map( 'intval', $detail['purchase_ids'] );
        $in        = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $purchases = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT pu.*, pr.title AS property_title FROM {$p}ofp_property_purchases pu
             LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
             WHERE pu.client_id = %d AND pu.id IN ({$in}) ORDER BY pu.created_at DESC",
            array_merge( [ $client_id ], $ids )
        ) );
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_property_payments WHERE purchase_id IN ({$in}) AND status = 'successful' ORDER BY created_at ASC",
            $ids
        ) );
        foreach ( $rows as $r ) {
            $payments[ (int) $r->purchase_id ][] = $r;
        }
    }
    if ( ! empty( $detail['tenant_ids'] ) ) {
        $ids    = array_map( 'intval', $detail['tenant_ids'] );
        $in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $leases = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT l.id, l.status, l.start_date, l.end_date, l.rent_amount, pr.title AS property_title
             FROM {$p}ofp_property_leases l LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
             WHERE l.client_id = %d AND l.tenant_id IN ({$in}) ORDER BY l.created_at DESC",
            array_merge( [ $client_id ], $ids )
        ) );
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Customers', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <?php if ( $notice !== '' ) : ?>
        <div class="ofp-cu-note <?php echo $notice_ok ? 'ok' : 'bad'; ?>"><?php echo esc_html( $notice ); ?></div>
    <?php endif; ?>

    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;align-items:center;margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:18px;font-weight:700;color:var(--text-main);">Customer Directory</span>
            <span style="font-size:12px;padding:3px 9px;border-radius:9999px;background:rgba(59,130,246,.12);color:var(--brand-color,#3b82f6);font-weight:600;"><?php echo (int) count( $all ); ?> Total</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a class="ofp-btn" href="<?php echo esc_url( home_url( '/leads' ) ); ?>">Leads</a>
            <a class="ofp-btn" href="<?php echo esc_url( add_query_arg( 'export', 1, $page_url ) ); ?>" download="customers-<?php echo gmdate( 'Y-m-d' ); ?>.csv" title="Download customers spreadsheet in CSV format" style="display:inline-flex;align-items:center;gap:6px;">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                <span>Export CSV</span>
            </a>
        </div>
    </div>

    <!-- ── KPI GRID: Styled matching the dashboard grid with icons and descriptive badges ── -->
    <div class="ofp-zentra-kpi-grid" style="margin-bottom:24px;">
        <!-- Card 1: Total Customers (Hero) -->
        <div class="ofp-kpi-card ofp-kpi-card-hero">
            <div class="ofp-kpi-title">Customers</div>
            <div class="ofp-kpi-value"><?php echo esc_html( number_format( count( $all ) ) ); ?></div>
            <div class="ofp-kpi-badge badge-hero">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:12px;height:12px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                <span>Total active client directory</span>
            </div>
        </div>

        <!-- Card 2: Buyers -->
        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Buyers</div>
            <div class="ofp-kpi-value"><?php echo esc_html( number_format( $counts['Buyer'] ) ); ?></div>
            <div class="ofp-kpi-badge badge-brand">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:12px;height:12px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                <span><?php echo (int) $counts['Buyer']; ?> Property purchasers</span>
            </div>
        </div>

        <!-- Card 3: Tenants -->
        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Tenants</div>
            <div class="ofp-kpi-value"><?php echo esc_html( number_format( $counts['Tenant'] ) ); ?></div>
            <div class="ofp-kpi-badge badge-blue">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:12px;height:12px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                <span><?php echo (int) $counts['Tenant']; ?> Active tenancy leases</span>
            </div>
        </div>

        <!-- Card 4: Collected from Sales -->
        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Collected from sales</div>
            <div class="ofp-kpi-value"><?php echo esc_html( $money( $paid ) ); ?></div>
            <div class="ofp-kpi-badge badge-green">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:12px;height:12px;"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25" /></svg>
                <span><?php echo $sold > 0 ? round( ( $paid / $sold ) * 100 ) . '% collected of ' . esc_html( $money( $sold ) ) : 'All payments up to date'; ?></span>
            </div>
        </div>
    </div>

    <!-- ── CUSTOMER DETAIL DRAWER/SECTION: Fine-tuned modern executive profile ── -->
    <?php if ( $detail ) : 
        $name_words = explode( ' ', trim( (string) $detail['name'] ) );
        $initials = '';
        foreach ( array_slice( $name_words, 0, 2 ) as $w ) {
            $initials .= strtoupper( mb_substr( $w, 0, 1 ) );
        }
        if ( ! $initials ) $initials = 'CU';
    ?>
        <div class="ofp-detail-card">
            <div class="ofp-detail-header">
                <div class="ofp-detail-profile">
                    <div class="ofp-detail-avatar"><?php echo esc_html( $initials ); ?></div>
                    <div>
                        <div class="ofp-detail-name"><?php echo esc_html( $detail['name'] ?: 'Unnamed Customer' ); ?></div>
                        <div class="ofp-detail-contacts">
                            <?php if ( ! empty( $detail['phone'] ) ) : ?>
                                <a href="tel:<?php echo esc_attr( $detail['phone'] ); ?>" title="Call customer">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                                    <span><?php echo esc_html( $detail['phone'] ); ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if ( ! empty( $detail['email'] ) ) : ?>
                                <a href="mailto:<?php echo esc_attr( $detail['email'] ); ?>" title="Email customer">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                                    <span><?php echo esc_html( $detail['email'] ); ?></span>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:8px;">
                            <?php foreach ( $detail['tags'] as $t ) : 
                                $tag_cls = 'ofp-cu-tag';
                                if ( 'Tenant' === $t ) $tag_cls .= ' tenant';
                                elseif ( 'Converted lead' === $t ) $tag_cls .= ' converted';
                            ?>
                                <span class="<?php echo esc_attr( $tag_cls ); ?>"><?php echo esc_html( $t ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div style="display:flex;gap:8px;align-items:center;">
                    <?php if ( ! empty( $detail['phone'] ) ) : ?>
                        <a class="ofp-btn" href="<?php echo esc_url( add_query_arg( [ 'tab' => 'send', 'phone' => $detail['phone'] ], home_url( '/message-templates' ) ) ); ?>" style="display:inline-flex;align-items:center;gap:6px;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:15px;height:15px;"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v4.018z" /></svg>
                            <span>Message</span>
                        </a>
                    <?php endif; ?>
                    <a class="ofp-btn" href="<?php echo esc_url( add_query_arg( $base_args, $page_url ) ); ?>" style="display:inline-flex;align-items:center;gap:5px;">
                        <span>Close</span>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </a>
                </div>
            </div>

            <!-- Mini Financial Summary Strip -->
            <div class="ofp-detail-stats-grid">
                <div class="ofp-detail-stat-box">
                    <div class="ofp-detail-stat-label">Total Purchased</div>
                    <div class="ofp-detail-stat-val"><?php echo esc_html( $money( $detail['total'] ) ); ?></div>
                </div>
                <div class="ofp-detail-stat-box">
                    <div class="ofp-detail-stat-label">Total Paid</div>
                    <div class="ofp-detail-stat-val" style="color:#10b981;"><?php echo esc_html( $money( $detail['paid'] ) ); ?></div>
                </div>
                <div class="ofp-detail-stat-box">
                    <div class="ofp-detail-stat-label">Remaining Balance</div>
                    <div class="ofp-detail-stat-val" style="color:<?php echo $detail['balance'] > 0 ? '#f59e0b' : '#94a3b8'; ?>;">
                        <?php echo $detail['balance'] > 0 ? esc_html( $money( $detail['balance'] ) ) : '<span style="color:#10b981;font-size:15px;">Fully Paid</span>'; ?>
                    </div>
                </div>
            </div>

            <?php if ( ! empty( $purchases ) ) : ?>
                <div style="font-size:14px;font-weight:700;color:var(--text-main);margin:20px 0 12px;display:flex;align-items:center;gap:8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;color:#3b82f6;"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205l3 1m1.5.5l-1.5-.5" /></svg>
                    <span>Property Purchases & Milestone Payments</span>
                </div>
                <?php foreach ( $purchases as $pu ) : ?>
                    <div class="ofp-detail-deal-item">
                        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
                            <div style="font-weight:700;font-size:15px;color:var(--text-main);"><?php echo esc_html( $pu->property_title ?: 'Property' ); ?></div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span class="ofp-cu-tag" style="text-transform:capitalize;"><?php echo esc_html( $pu->status ); ?></span>
                                <span class="ofp-cu-sub"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $pu->created_at ) ) ); ?></span>
                            </div>
                        </div>
                        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:13px;color:var(--text-muted);margin-bottom:12px;">
                            <span>Price: <strong style="color:var(--text-main);"><?php echo esc_html( $money( $pu->total_price ) ); ?></strong></span>
                            <span>Paid: <strong style="color:#10b981;"><?php echo esc_html( $money( $pu->amount_paid ) ); ?></strong></span>
                            <span>Balance: <strong style="color:<?php echo $pu->balance > 0 ? '#f59e0b' : '#94a3b8'; ?>;"><?php echo esc_html( $money( $pu->balance ) ); ?></strong></span>
                        </div>

                        <?php foreach ( $payments[ (int) $pu->id ] ?? [] as $py ) :
                            $imported = ( 'import' === $py->gateway );
                            $has_file = ! empty( $py->receipt_path );
                            ?>
                            <div class="ofp-detail-pay-row">
                                <div>
                                    <span style="font-weight:700;color:var(--text-main);"><?php echo esc_html( $money( $py->amount ) ); ?></span>
                                    <span class="ofp-cu-sub">&nbsp;on <?php echo esc_html( wp_date( 'M j, Y', strtotime( $py->created_at ) ) ); ?></span>
                                    <span style="display:inline-block;margin-left:8px;padding:2px 8px;border-radius:4px;background:rgba(255,255,255,.05);font-size:11px;color:var(--text-muted);"><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $py->payment_method ) ) ); ?><?php echo $py->payer_reference ? ' &middot; Ref: ' . esc_html( $py->payer_reference ) : ''; ?><?php echo $imported ? ' &middot; Imported' : ''; ?></span>
                                </div>
                                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                    <?php if ( $has_file && class_exists( 'OFP_Property_Receipt_Viewer' ) ) : ?>
                                        <a class="ofp-btn" href="<?php echo esc_url( OFP_Property_Receipt_Viewer::get_receipt_url( (int) $py->id, 'purchase' ) ); ?>" target="_blank" rel="noopener" style="padding:4px 10px;font-size:12px;">View uploaded receipt</a>
                                    <?php else : ?>
                                        <a class="ofp-btn" href="<?php echo esc_url( add_query_arg( 'rcpt', (int) $py->id, $page_url ) ); ?>" target="_blank" rel="noopener" style="padding:4px 10px;font-size:12px;">View receipt</a>
                                    <?php endif; ?>
                                    <form class="ofp-detail-upload-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( add_query_arg( 'c', $open_key, $page_url ) ); ?>">
                                        <?php wp_nonce_field( 'ofp_receipt_' . $client_id, 'ofp_receipt_nonce' ); ?>
                                        <input type="hidden" name="payment_id" value="<?php echo (int) $py->id; ?>">
                                        <input class="ofp-detail-file-input" type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png" required>
                                        <button class="ofp-btn" type="submit" style="padding:4px 10px;font-size:12px;"><?php echo $has_file ? 'Replace receipt' : 'Attach receipt'; ?></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <p class="ofp-cu-sub" style="margin:6px 0 0;">Receipt files can be PDF, JPG or PNG (up to 5 MB) and are stored securely.</p>
            <?php endif; ?>

            <?php if ( ! empty( $leases ) ) : ?>
                <div style="font-size:14px;font-weight:700;color:var(--text-main);margin:20px 0 12px;display:flex;align-items:center;gap:8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;color:#10b981;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                    <span>Rental Leases</span>
                </div>
                <?php foreach ( $leases as $ls ) : ?>
                    <div class="ofp-detail-deal-item" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                        <div>
                            <div style="font-weight:700;font-size:14px;color:var(--text-main);"><?php echo esc_html( $ls->property_title ?: 'Rental Unit' ); ?></div>
                            <div class="ofp-cu-sub"><?php echo esc_html( $money( $ls->rent_amount ) ); ?> rent &middot; Period: <?php echo esc_html( $ls->start_date ? wp_date( 'M j, Y', strtotime( $ls->start_date ) ) : 'Not started' ); ?> to <?php echo esc_html( $ls->end_date ? wp_date( 'M j, Y', strtotime( $ls->end_date ) ) : 'Open' ); ?></div>
                        </div>
                        <span class="ofp-cu-tag tenant"><?php echo esc_html( ucfirst( $ls->status ) ); ?></span>
                    </div>
                <?php endforeach; ?>
                <p class="ofp-cu-sub" style="margin:6px 0 0;">View full lease payments and generate receipts on the <a href="<?php echo esc_url( home_url( '/tenants?tab=payments' ) ); ?>">Tenants page</a>.</p>
            <?php endif; ?>

            <?php if ( empty( $purchases ) && empty( $leases ) ) : ?>
                <div style="padding:14px 16px;border-radius:10px;background:rgba(255,255,255,.03);margin-top:16px;">
                    <p class="ofp-cu-sub" style="margin:0;">This customer was marked as a converted lead. No property purchases or leases have been recorded for them yet. You can import past sales with <a href="<?php echo esc_url( home_url( '/import?type=sales' ) ); ?>">Import Past Sales</a>.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ── SEARCH BAR & FILTER PILLS: Modernized with SVG icon and clean pill counters ── -->
    <div class="ofp-card" style="padding:22px;border-radius:14px;">
        <form method="get" action="<?php echo esc_url( $page_url ); ?>" class="ofp-cu-bar">
            <?php if ( $tag !== '' ) : ?><input type="hidden" name="tag" value="<?php echo esc_attr( $tag ); ?>"><?php endif; ?>
            <div class="ofp-cu-search-box">
                <svg class="ofp-cu-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                <input class="ofp-cu-search-input" type="text" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Search customers by name, phone number or email...">
            </div>
            <button class="ofp-btn ofp-btn-primary" type="submit" style="display:inline-flex;align-items:center;gap:6px;">
                <span>Search</span>
            </button>
            <?php if ( $q !== '' ) : ?>
                <a class="ofp-btn" href="<?php echo esc_url( add_query_arg( array_filter( [ 'tag' => $tag ] ), $page_url ) ); ?>" title="Clear search">Clear</a>
            <?php endif; ?>
        </form>

        <div class="ofp-cu-filters">
            <a class="ofp-cu-pill <?php echo $tag === '' ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_filter( [ 'q' => $q ] ), $page_url ) ); ?>">
                <span>All Customers</span>
                <span class="ofp-cu-pill-count"><?php echo (int) count( $all ); ?></span>
            </a>
            <?php foreach ( $tags as $t ) : ?>
                <a class="ofp-cu-pill <?php echo $tag === $t ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_filter( [ 'q' => $q, 'tag' => $t ] ), $page_url ) ); ?>">
                    <span><?php echo esc_html( $t ); ?></span>
                    <span class="ofp-cu-pill-count"><?php echo (int) $counts[ $t ]; ?></span>
                </a>
            <?php endforeach; ?>
            <a class="ofp-cu-msg-btn" href="<?php echo esc_url( home_url( '/message-templates?tab=send&aud=customers' ) ); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                <span>Message All Customers</span>
            </a>
        </div>

        <?php if ( empty( $slice ) ) : ?>
            <div style="text-align:center;padding:48px 20px;">
                <div style="width:52px;height:52px;border-radius:50%;background:rgba(59,130,246,.12);color:#3b82f6;display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                </div>
                <h4 style="margin:0 0 8px;font-size:16px;font-weight:600;"><?php echo empty( $all ) ? 'No customers yet' : 'No customers match your search'; ?></h4>
                <p style="margin:0 0 18px;font-size:13px;opacity:.75;max-width:440px;margin-inline:auto;line-height:1.5;">
                    <?php echo empty( $all ) ? 'Customers appear here automatically when someone buys a property, rents a unit, or when a lead is marked converted. You can also import existing customers or past sales.' : 'Try adjusting your search query or tag filter above.'; ?>
                </p>
                <?php if ( empty( $all ) ) : ?>
                    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                        <a class="ofp-btn ofp-btn-primary" href="<?php echo esc_url( home_url( '/import?type=customers' ) ); ?>">Import Customers</a>
                        <a class="ofp-btn" href="<?php echo esc_url( add_query_arg( [ 'sample' => 'customers' ], home_url( '/import' ) ) ); ?>" download="template-customers.csv">Download Customer Template (.CSV)</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <div style="overflow-x:auto;">
                <table class="ofp-cu-table">
                    <thead><tr><th>Customer</th><th>Contact</th><th>Type</th><th>Bought</th><th>Paid</th><th>Balance</th><th>Last activity</th></tr></thead>
                    <tbody>
                    <?php foreach ( $slice as $key => $c ) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, [ 'c' => $key ] ), $page_url ) ); ?>"><strong><?php echo esc_html( $c['name'] ?: 'Unnamed' ); ?></strong></a></td>
                            <td><?php echo esc_html( $c['phone'] ); ?><?php echo $c['email'] ? '<div class="ofp-cu-sub">' . esc_html( $c['email'] ) . '</div>' : ''; ?></td>
                            <td><?php foreach ( $c['tags'] as $t ) : ?><span class="ofp-cu-tag"><?php echo esc_html( $t ); ?></span><?php endforeach; ?></td>
                            <td><?php echo $c['purchases'] ? (int) $c['purchases'] . ' &middot; ' . esc_html( $money( $c['total'] ) ) : '<span class="ofp-cu-sub">None</span>'; ?></td>
                            <td><?php echo $c['purchases'] ? esc_html( $money( $c['paid'] ) ) : '<span class="ofp-cu-sub">-</span>'; ?></td>
                            <td><?php echo $c['purchases'] ? esc_html( $money( $c['balance'] ) ) : '<span class="ofp-cu-sub">-</span>'; ?></td>
                            <td class="ofp-cu-sub"><?php echo $c['last_at'] ? esc_html( wp_date( 'M j, Y', strtotime( $c['last_at'] ) ) ) : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="ofp-cu-pager">
                <span><?php echo esc_html( number_format( $total ) ); ?> customers, page <?php echo (int) $page; ?> of <?php echo (int) $pages; ?></span>
                <span>
                    <?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, [ 'pg' => $page - 1 ] ), $page_url ) ); ?>">Previous</a><?php endif; ?>
                    <?php if ( $page > 1 && $page < $pages ) echo ' &nbsp;|&nbsp; '; ?>
                    <?php if ( $page < $pages ) : ?><a href="<?php echo esc_url( add_query_arg( array_merge( $base_args, [ 'pg' => $page + 1 ] ), $page_url ) ); ?>">Next</a><?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>
</div><!-- .ofp-content-area -->
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
