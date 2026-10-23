<?php
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
if ( ! $client ) {
    wp_safe_redirect( home_url( '/dashboard' ) );
    exit;
}

global $wpdb;
$p = $wpdb->prefix;
$client_id = (int) $client->id;

// Filters
$filter_s      = sanitize_text_field( wp_unslash( $_GET['s'] ?? $_GET['q'] ?? '' ) );
$filter_status = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );
$filter_method = sanitize_text_field( wp_unslash( $_GET['method'] ?? '' ) );

// Aggregate counts for filter pills
$counts_raw = $wpdb->get_results( $wpdb->prepare(
    "SELECT py.status, COUNT(*) as cnt
     FROM {$p}ofp_property_payments py
     INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
     WHERE pu.client_id = %d
     GROUP BY py.status",
    $client_id
) );
$counts = [
    'all'        => 0,
    'successful' => 0,
    'pending'    => 0,
    'failed'     => 0,
];
foreach ( (array) $counts_raw as $cr ) {
    $st  = strtolower( (string) $cr->status );
    $cnt = (int) $cr->cnt;
    $counts['all'] += $cnt;
    if ( in_array( $st, [ 'successful', 'verified' ], true ) ) {
        $counts['successful'] += $cnt;
    } elseif ( in_array( $st, [ 'pending', 'pending_verification' ], true ) ) {
        $counts['pending'] += $cnt;
    } elseif ( in_array( $st, [ 'failed', 'rejected' ], true ) ) {
        $counts['failed'] += $cnt;
    }
}

// Build query
$where_clauses   = [ 'pu.client_id = %d' ];
$params          = [ $client_id ];
$exact_target_id = 0;

if ( '' !== $filter_s ) {
    $s_trim  = trim( $filter_s );
    $s_clean = preg_replace( '/^#/', '', $s_trim );

    // 1. Receipt format (e.g. PUR-000001 or PUR-1)
    if ( preg_match( '/^PUR-?0*(\d+)$/i', $s_clean, $m_pur ) ) {
        $target_id = (int) $m_pur[1];
        $where_clauses[] = '( py.id = %d OR py.purchase_id = %d )';
        $params[] = $target_id;
        $params[] = $target_id;
        $exact_target_id = $target_id;
    }
    // 2. Formatted Transaction ID (e.g. TXN-DYCQQJB9QW or TXN-ABC12345)
    elseif ( preg_match( '/^TXN-?([A-Z0-9_\-]+)$/i', $s_clean, $m_txn ) ) {
        $core = $m_txn[1];
        $like_core = '%' . $wpdb->esc_like( $core ) . '%';
        $where_clauses[] = '( py.gateway_reference LIKE %s OR py.payer_reference LIKE %s )';
        $params[] = $like_core;
        $params[] = $like_core;
    }
    // 3. Digits: direct ID lookup or phone
    elseif ( ctype_digit( $s_clean ) ) {
        $num_id = (int) $s_clean;
        $like_num = '%' . $wpdb->esc_like( $s_clean ) . '%';
        $where_clauses[] = '( py.id = %d OR py.purchase_id = %d OR pu.buyer_phone LIKE %s OR py.gateway_reference LIKE %s OR py.payer_reference LIKE %s )';
        $params[] = $num_id;
        $params[] = $num_id;
        $params[] = $like_num;
        $params[] = $like_num;
        $params[] = $like_num;
        $exact_target_id = $num_id;
    }
    // 4. General search: buyer name, phone, email, property title, reference
    else {
        $like_s = '%' . $wpdb->esc_like( $s_trim ) . '%';
        $where_clauses[] = '( pu.buyer_name LIKE %s OR pu.buyer_phone LIKE %s OR pu.buyer_email LIKE %s OR pr.title LIKE %s OR py.gateway_reference LIKE %s OR py.payer_reference LIKE %s )';
        $params[] = $like_s;
        $params[] = $like_s;
        $params[] = $like_s;
        $params[] = $like_s;
        $params[] = $like_s;
        $params[] = $like_s;
    }
}

if ( '' !== $filter_status && 'all' !== $filter_status ) {
    if ( in_array( $filter_status, [ 'successful', 'verified' ], true ) ) {
        $where_clauses[] = "py.status IN ('successful', 'verified')";
    } elseif ( in_array( $filter_status, [ 'pending', 'pending_verification' ], true ) ) {
        $where_clauses[] = "py.status IN ('pending', 'pending_verification')";
    } elseif ( in_array( $filter_status, [ 'failed', 'rejected' ], true ) ) {
        $where_clauses[] = "py.status IN ('failed', 'rejected')";
    } else {
        $where_clauses[] = 'py.status = %s';
        $params[]        = $filter_status;
    }
}

if ( '' !== $filter_method && 'all' !== $filter_method ) {
    $where_clauses[] = 'py.payment_method = %s';
    $params[]        = $filter_method;
}

$where_sql = implode( ' AND ', $where_clauses );
$payments  = (array) $wpdb->get_results( $wpdb->prepare(
    "SELECT py.*, pu.property_id, pu.buyer_name, pu.buyer_phone, pu.buyer_email, pr.title AS property_title
     FROM {$p}ofp_property_payments py
     INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
     INNER JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
     WHERE {$where_sql}
     ORDER BY py.created_at DESC, py.id DESC
     LIMIT 250",
    ...$params
) );

$has_filters = ( '' !== $filter_s || ( '' !== $filter_status && 'all' !== $filter_status ) || ( '' !== $filter_method && 'all' !== $filter_method ) );
$total_matching_amount = array_reduce( $payments, static fn( $acc, $it ) => $acc + (float) $it->amount, 0.0 );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( OFP_Brand::title( 'Transactions', $client ?? null ) ); ?></title>
<?php wp_head(); ?>
<link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
<style>
/* Exact Match Row Highlighting */
.ofp-row-matched {
    background: rgba(59, 130, 246, 0.12) !important;
    position: relative;
    box-shadow: inset 3px 0 0 var(--accent-blue, #3b82f6);
    animation: ofpPulseRow 2s ease-in-out infinite alternate;
}
@keyframes ofpPulseRow {
    0% { background: rgba(59, 130, 246, 0.10); }
    100% { background: rgba(59, 130, 246, 0.20); }
}
</style>
<script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
</head>
<body class="ofp-portal-body">
<?php 
$ofp_banner_btn = [ 'label' => 'Add Purchase', 'url' => home_url( '/property-purchases' ) ];
include OFP_PATH . 'public/templates/partials/nav.php'; 
?>
<div class="ofp-container">
    <div style="padding-bottom: 60px;">
        <?php $ofp_sales_tab = 'payments'; include OFP_PATH . 'public/templates/partials/sales-tabs.php'; ?>

        <!-- Search & Filter Card -->
        <div class="ofp-txn-filter-card">
            <form method="get" action="<?php echo esc_url( home_url( '/property-payments' ) ); ?>">
                <div class="ofp-txn-search-row">
                    <div class="ofp-txn-search-box">
                        <svg class="ofp-txn-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                        <input type="text" 
                               id="ofp-txn-search-input" 
                               name="s" 
                               class="ofp-input ofp-txn-search-input" 
                               value="<?php echo esc_attr( $filter_s ); ?>" 
                               placeholder="Search Transaction ID (TXN-...), Receipt #, Buyer, Phone, Property..." 
                               autocomplete="off" />
                    </div>

                    <select name="status" class="ofp-select ofp-txn-select">
                        <option value="">All Statuses</option>
                        <option value="successful" <?php selected( in_array( $filter_status, [ 'successful', 'verified' ], true ) ); ?>>Verified / Successful</option>
                        <option value="pending" <?php selected( in_array( $filter_status, [ 'pending', 'pending_verification' ], true ) ); ?>>Pending Verification</option>
                        <option value="failed" <?php selected( in_array( $filter_status, [ 'failed', 'rejected' ], true ) ); ?>>Failed / Rejected</option>
                        <option value="refunded" <?php selected( $filter_status, 'refunded' ); ?>>Refunded</option>
                    </select>

                    <select name="method" class="ofp-select ofp-txn-select">
                        <option value="">All Methods</option>
                        <option value="checkout" <?php selected( $filter_method, 'checkout' ); ?>>Online Checkout (Paystack)</option>
                        <option value="manual" <?php selected( $filter_method, 'manual' ); ?>>Bank Transfer / Manual</option>
                        <option value="bank_transfer" <?php selected( $filter_method, 'bank_transfer' ); ?>>Direct Transfer</option>
                        <option value="pos" <?php selected( $filter_method, 'pos' ); ?>>POS</option>
                        <option value="cash" <?php selected( $filter_method, 'cash' ); ?>>Cash</option>
                    </select>

                    <button type="submit" class="ofp-btn ofp-btn-primary" style="padding:9px 18px; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:15px;height:15px;"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                        <span>Filter</span>
                    </button>

                    <?php if ( $has_filters ) : ?>
                        <a href="<?php echo esc_url( home_url( '/property-payments' ) ); ?>" class="ofp-btn" style="padding:9px 14px; font-size:13px; text-decoration:none;">
                            Clear
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Status Filter Pills -->
                <div class="ofp-txn-filter-pills">
                    <span style="font-size:12px; font-weight:600; color:var(--text-muted); margin-right:4px;">Filter Status:</span>
                    <?php
                    $pills = [
                        ''           => [ 'label' => 'All',        'count' => $counts['all'] ],
                        'successful' => [ 'label' => 'Verified',   'count' => $counts['successful'] ],
                        'pending'    => [ 'label' => 'Pending',    'count' => $counts['pending'] ],
                        'failed'     => [ 'label' => 'Failed',     'count' => $counts['failed'] ],
                    ];
                    foreach ( $pills as $pval => $pinfo ) :
                        $is_active = ( $filter_status === $pval || ( '' === $pval && '' === $filter_status ) );
                        $p_url = add_query_arg( array_merge(
                            array_diff_key( array_filter( [ 's' => $filter_s, 'method' => $filter_method ] ), [ 'status' => 1 ] ),
                            $pval !== '' ? [ 'status' => $pval ] : []
                        ), home_url( '/property-payments' ) );
                    ?>
                        <a href="<?php echo esc_url( $p_url ); ?>" class="ofp-txn-pill <?php echo $is_active ? 'active' : ''; ?>">
                            <span><?php echo esc_html( $pinfo['label'] ); ?></span>
                            <span class="ofp-txn-pill-count"><?php echo esc_html( $pinfo['count'] ); ?></span>
                        </a>
                    <?php endforeach; ?>

                    <span id="ofp-txn-live-count" style="display:none; margin-left:auto; font-size:12px; color:var(--brand-color); font-weight:600;"></span>
                </div>
            </form>
        </div>

        <?php if ( '' !== $filter_s ) : ?>
            <?php if ( ! empty( $payments ) ) : ?>
                <div style="background:rgba(59,130,246,0.1); border:1px solid rgba(59,130,246,0.25); border-radius:10px; padding:12px 18px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:18px; height:18px; color:var(--accent-blue);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span style="font-size:13.5px; color:var(--text-main);">
                            Found <strong><?php echo count( $payments ); ?></strong> transaction(s) matching &ldquo;<strong><?php echo esc_html( $filter_s ); ?></strong>&rdquo; 
                            (Total: <strong style="color:var(--accent-green);">₦<?php echo esc_html( number_format( $total_matching_amount, 2 ) ); ?></strong>)
                        </span>
                    </div>
                    <a href="<?php echo esc_url( home_url( '/property-payments' ) ); ?>" class="ofp-btn ofp-btn-sm" style="padding:4px 10px; font-size:12px; text-decoration:none;">Show All Transactions</a>
                </div>
            <?php else : ?>
                <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:10px; padding:14px 18px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <span style="font-size:13.5px; color:#ef4444;">
                        No transactions found matching &ldquo;<strong><?php echo esc_html( $filter_s ); ?></strong>&rdquo;. Please check the reference or receipt number.
                    </span>
                    <a href="<?php echo esc_url( home_url( '/property-payments' ) ); ?>" class="ofp-btn ofp-btn-sm" style="padding:4px 10px; font-size:12px; text-decoration:none;">Reset Search</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="ofp-card">
            <!-- Table Header & Direct Search Bar -->
            <div class="ofp-table-toolbar" style="margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div>
                    <h3 style="margin:0 0 4px; font-size:16px; font-weight:700; color:var(--text-main);">Transactions Ledger</h3>
                    <p style="margin:0; font-size:12.5px; color:var(--text-muted);">Real-time financial transactions, checkout verifications, and payment receipts.</p>
                </div>
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <div class="ofp-table-search" style="min-width:300px;">
                        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        <input type="text" 
                               id="ofp-table-live-search" 
                               class="ofp-input ofp-txn-search-input" 
                               placeholder="Search transaction table (ID, buyer, ref...)" 
                               value="<?php echo esc_attr( $filter_s ); ?>">
                    </div>
                    <span id="ofp-live-match-badge" class="ofp-btn ofp-btn-outline" style="font-size:12px; font-weight:600; padding:6px 14px; border-radius:8px; pointer-events:none;">
                        <?php echo count( $payments ); ?> Records
                    </span>
                </div>
            </div>

            <div class="ofp-table-responsive">
                <table class="ofp-table" id="ofp-property-payments-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Receipt / ID</th>
                            <th>Buyer</th>
                            <th>Property</th>
                            <th>Purchase</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Gateway</th>
                            <th>Transaction ID</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $payments ) ) : ?>
                        <tr><td colspan="11" style="text-align:center; color:#64748b; padding:32px;">No transactions recorded.</td></tr>
                    <?php else : foreach ( $payments as $payment ) : 
                        $raw_ref     = (string) ( $payment->gateway_reference ?: ( $payment->payer_reference ?: '' ) );
                        $display_ref = class_exists( 'OFP_Property_Receipt_Generator' ) 
                            ? OFP_Property_Receipt_Generator::format_display_reference( $raw_ref, '—' ) 
                            : ( $raw_ref ?: '—' );
                        $receipt_no  = 'PUR-' . str_pad( (string) $payment->id, 6, '0', STR_PAD_LEFT );

                        // Check if this row is the targeted/exact search match
                        $is_matched = false;
                        if ( '' !== $filter_s ) {
                            $s_clean = strtoupper( trim( preg_replace( '/^#/', '', $filter_s ) ) );
                            if ( (int) $payment->id === $exact_target_id ||
                                 $s_clean === strtoupper( $receipt_no ) ||
                                 $s_clean === strtoupper( $display_ref ) ||
                                 ( $raw_ref !== '' && stripos( $raw_ref, $s_clean ) !== false ) ||
                                 count( $payments ) === 1 ) {
                                $is_matched = true;
                            }
                        }

                        $search_blob = strtolower( $receipt_no . ' ' . $payment->id . ' ' . $display_ref . ' ' . $raw_ref . ' ' . $payment->buyer_name . ' ' . $payment->buyer_phone . ' ' . $payment->property_title . ' ' . $payment->payment_method . ' ' . $payment->status );
                    ?>
                        <tr class="ofp-txn-row <?php echo $is_matched ? 'ofp-row-matched' : ''; ?>" data-search="<?php echo esc_attr( $search_blob ); ?>">
                            <td>
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <strong style="color:var(--text-main); font-size:12.5px;"><?php echo esc_html( $receipt_no ); ?></strong>
                                    <?php if ( $is_matched ) : ?>
                                        <span style="font-size:10px; font-weight:700; background:var(--accent-blue); color:#fff; padding:1px 5px; border-radius:4px; letter-spacing:0.3px;">MATCH</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:11px; color:#64748b;">#<?php echo esc_html( $payment->id ); ?></div>
                            </td>
                            <td>
                                <div style="font-weight: 500; color: var(--text-main);"><?php echo esc_html( $payment->buyer_name ); ?></div>
                                <div style="font-size: 12px; color: var(--text-muted);"><?php echo esc_html( $payment->buyer_phone ); ?></div>
                            </td>
                            <td style="color:var(--text-dark);"><?php echo esc_html( $payment->property_title ); ?></td>
                            <td style="color:var(--text-dark);">#<?php echo esc_html( $payment->purchase_id ); ?></td>
                            <td><strong style="color:var(--text-main);">₦<?php echo esc_html( number_format( (float) $payment->amount, 2 ) ); ?></strong></td>
                            <td style="color:var(--text-dark);"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $payment->payment_method ) ) ); ?></td>
                            <td style="color:var(--text-muted); font-size:13px;"><?php echo esc_html( $payment->gateway ?: '—' ); ?></td>
                            <td>
                                <code style="font-size:12px; color:#475569; background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:600;" title="<?php echo esc_attr( $raw_ref ); ?>">
                                    <?php echo esc_html( $display_ref ); ?>
                                </code>
                            </td>
                            <td>
                                <?php 
                                    $status_styles = [
                                        'verified'   => 'background:#dcfce7; color:#16a34a;',
                                        'successful' => 'background:#dcfce7; color:#16a34a;',
                                        'pending'    => 'background:#fef3c7; color:#d97706;',
                                        'failed'     => 'background:#fee2e2; color:#ef4444;',
                                        'refunded'   => 'background:#f1f5f9; color:#64748b;'
                                    ];
                                    $style = $status_styles[ strtolower( $payment->status ) ] ?? 'background:#f1f5f9; color:#64748b;';
                                ?>
                                <span style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:100px; <?php echo esc_attr($style); ?>">
                                    <?php echo esc_html( ucfirst( str_replace( '_', ' ', $payment->status ) ) ); ?>
                                </span>
                            </td>
                            <td style="color:var(--text-muted); font-size:13px;"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $payment->created_at ) ) ); ?></td>
                            <td>
                                <?php if ( class_exists( 'OFP_Property_Receipt_Viewer' ) ) : ?>
                                    <a href="<?php echo esc_url( OFP_Property_Receipt_Viewer::get_receipt_url( (int) $payment->id, 'purchase' ) ); ?>" target="_blank" rel="noopener" style="display:inline-flex; align-items:center; gap:4px; font-size:12px; font-weight:600; color:#2563eb; text-decoration:none;">
                                        Receipt &rarr;
                                    </a>
                                <?php else : ?>
                                    <span style="color:var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    var topSearch = document.getElementById('ofp-txn-search-input');
    var tableSearch = document.getElementById('ofp-table-live-search');
    var rows = document.querySelectorAll('.ofp-table tbody tr.ofp-txn-row');
    var liveCounter = document.getElementById('ofp-txn-live-count');
    var matchBadge = document.getElementById('ofp-live-match-badge');

    // Auto-scroll to matched transaction if one is highlighted
    var matchedRow = document.querySelector('.ofp-row-matched');
    if (matchedRow) {
        setTimeout(function() {
            matchedRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 250);
    }

    function doFilter(query, sourceInput) {
        var val = (query || '').trim().toLowerCase();
        var visible = 0;

        // Keep both inputs in sync
        if (sourceInput === topSearch && tableSearch && tableSearch.value !== query) {
            tableSearch.value = query;
        } else if (sourceInput === tableSearch && topSearch && topSearch.value !== query) {
            topSearch.value = query;
        }

        rows.forEach(function(row) {
            var searchData = (row.getAttribute('data-search') || row.textContent).toLowerCase();
            if (!val || searchData.indexOf(val) !== -1) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });

        if (liveCounter) {
            if (val) {
                liveCounter.textContent = 'Showing ' + visible + ' of ' + rows.length;
                liveCounter.style.display = 'inline-block';
            } else {
                liveCounter.style.display = 'none';
            }
        }

        if (matchBadge) {
            matchBadge.textContent = val ? (visible + ' matching') : (rows.length + ' Records');
        }
    }

    if (topSearch) {
        topSearch.addEventListener('input', function() { doFilter(this.value, topSearch); });
    }
    if (tableSearch) {
        tableSearch.addEventListener('input', function() { doFilter(this.value, tableSearch); });
        tableSearch.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var form = document.querySelector('.ofp-txn-filter-card form');
                if (form) {
                    if (topSearch) topSearch.value = this.value;
                    form.submit();
                }
            }
        });
    }
});
</script>

<?php wp_footer(); ?>
</body>
</html>
