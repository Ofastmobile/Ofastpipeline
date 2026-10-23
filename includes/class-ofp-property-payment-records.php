<?php
/**
 * Canonical property payment records UI.
 * One admin page and one client page; pending manual payments are verified
 * from the same records table rather than a separate verification screen.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Payment_Records {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ], 99 );
        add_action( 'template_redirect', [ __CLASS__, 'client_page' ], 1 );
        add_action( 'template_redirect', [ __CLASS__, 'redirect_legacy_verification' ], 1 );
        add_action( 'admin_post_ofp_property_payment_verify', [ __CLASS__, 'admin_verify' ] );
        add_action( 'admin_post_ofp_property_payment_reject', [ __CLASS__, 'admin_reject' ] );
        add_action( 'admin_post_ofp_client_payment_verify', [ __CLASS__, 'client_verify' ] );
        add_action( 'admin_post_ofp_client_payment_reject', [ __CLASS__, 'client_reject' ] );

        // The manual-payment engine only owns buyer receipt submission now;
        // verification lives entirely here (see class-ofp-property-manual-payment.php).
    }

    public static function admin_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        add_submenu_page(
            'edit.php?post_type=ofp_property',
            'Property Transactions',
            'Transactions',
            'manage_options',
            'ofp-property-payments',
            [ __CLASS__, 'render_admin' ]
        );
    }

    public static function get_rows( int $client_id = 0, string $search = '', string $status = '', string $method = '' ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $where  = [];
        $params = [];

        if ( $client_id > 0 ) {
            $where[]  = 'pu.client_id = %d';
            $params[] = $client_id;
        }

        $search = trim( $search );
        if ( '' !== $search ) {
            $s_clean = preg_replace( '/^#/', '', $search );

            // Receipt format PUR-000001
            if ( preg_match( '/^PUR-?0*(\d+)$/i', $s_clean, $m_pur ) ) {
                $target_id = (int) $m_pur[1];
                $where[]   = '( py.id = %d OR py.purchase_id = %d )';
                $params[]  = $target_id;
                $params[]  = $target_id;
            }
            // Formatted TXN-XXXXXX
            elseif ( preg_match( '/^TXN-?([A-Z0-9_\-]+)$/i', $s_clean, $m_txn ) ) {
                $like_core = '%' . $wpdb->esc_like( $m_txn[1] ) . '%';
                $where[]   = '( py.gateway_reference LIKE %s OR py.payer_reference LIKE %s )';
                $params[]  = $like_core;
                $params[]  = $like_core;
            }
            // Digits (ID, phone, reference)
            elseif ( ctype_digit( $s_clean ) ) {
                $num_id   = (int) $s_clean;
                $like_num = '%' . $wpdb->esc_like( $s_clean ) . '%';
                $where[]  = '( py.id = %d OR py.purchase_id = %d OR pu.buyer_phone LIKE %s OR py.gateway_reference LIKE %s OR py.payer_reference LIKE %s )';
                $params[] = $num_id;
                $params[] = $num_id;
                $params[] = $like_num;
                $params[] = $like_num;
                $params[] = $like_num;
            }
            // General text
            else {
                $like_s   = '%' . $wpdb->esc_like( $search ) . '%';
                $where[]  = '( pu.buyer_name LIKE %s OR pu.buyer_phone LIKE %s OR pu.buyer_email LIKE %s OR pr.title LIKE %s OR py.gateway_reference LIKE %s OR py.payer_reference LIKE %s OR c.business_name LIKE %s )';
                $params[] = $like_s;
                $params[] = $like_s;
                $params[] = $like_s;
                $params[] = $like_s;
                $params[] = $like_s;
                $params[] = $like_s;
                $params[] = $like_s;
            }
        }

        if ( '' !== $status && 'all' !== $status ) {
            if ( in_array( $status, [ 'successful', 'verified' ], true ) ) {
                $where[] = "py.status IN ('successful', 'verified')";
            } elseif ( in_array( $status, [ 'pending', 'pending_verification' ], true ) ) {
                $where[] = "py.status IN ('pending', 'pending_verification')";
            } elseif ( in_array( $status, [ 'failed', 'rejected' ], true ) ) {
                $where[] = "py.status IN ('failed', 'rejected')";
            } else {
                $where[]  = 'py.status = %s';
                $params[] = $status;
            }
        }

        if ( '' !== $method && 'all' !== $method ) {
            $where[]  = 'py.payment_method = %s';
            $params[] = $method;
        }

        $sql = "SELECT py.*, pu.property_id, pu.client_id, pu.buyer_name, pu.buyer_phone, pu.buyer_email,
                       pu.total_price, pu.amount_paid AS purchase_paid, pu.balance AS purchase_balance,
                       pr.title AS property_title, c.business_name
                FROM {$p}ofp_property_payments py
                INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
                LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
                LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id";

        if ( ! empty( $where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }

        $sql .= ' ORDER BY py.created_at DESC, py.id DESC LIMIT 250';

        if ( ! empty( $params ) ) {
            return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) ?: [];
        }

        return $wpdb->get_results( $sql ) ?: [];
    }

    public static function render_admin(): void {
        $search = sanitize_text_field( wp_unslash( $_GET['s'] ?? $_GET['q'] ?? '' ) );
        $status = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );
        $method = sanitize_text_field( wp_unslash( $_GET['method'] ?? '' ) );
        $rows   = self::get_rows( 0, $search, $status, $method );
        ?>
        <div class="wrap">
            <h1>Property Transactions</h1>
            <p>Every property transaction and payment record. Pending manual payments can be verified or rejected here.</p>

            <form method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:nowrap; margin:16px 0 20px;">
                <?php if ( is_admin() ) : ?>
                    <input type="hidden" name="post_type" value="ofp_property">
                    <input type="hidden" name="page" value="ofp-property-payments">
                <?php else : ?>
                    <input type="hidden" name="tab" value="property-payments">
                <?php endif; ?>
                <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search by Transaction ID, Receipt #, Buyer, Phone, Property..." class="ofp-input ofp-txn-search-input" style="flex:1; max-width:480px; min-width:240px;" id="ofp-admin-txn-search">
                <select name="status" class="ofp-select ofp-txn-select" style="min-width:180px; flex-shrink:0;">
                    <option value="">All Statuses</option>
                    <option value="successful" <?php selected( in_array( $status, [ 'successful', 'verified' ], true ) ); ?>>Verified / Successful</option>
                    <option value="pending_verification" <?php selected( in_array( $status, [ 'pending', 'pending_verification' ], true ) ); ?>>Pending Verification</option>
                    <option value="failed" <?php selected( in_array( $status, [ 'failed', 'rejected' ], true ) ); ?>>Failed / Rejected</option>
                </select>
                <button type="submit" class="button button-primary ofp-btn ofp-btn-primary" style="height:38px; display:inline-flex; align-items:center; flex-shrink:0; white-space:nowrap;">Filter</button>
                <?php if ( '' !== $search || '' !== $status || '' !== $method ) : ?>
                    <a href="<?php echo esc_url( remove_query_arg( [ 's', 'q', 'status', 'method' ] ) ); ?>" class="button" style="flex-shrink:0; white-space:nowrap;">Clear</a>
                <?php endif; ?>
            </form>
            <div style="overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;">
                <table class="widefat striped" id="ofp-admin-transactions-table" style="min-width:1500px;">
                    <thead><tr>
                        <th>ID</th><th>Buyer</th><th>Property</th><th>Owner</th><th>Purchase</th>
                        <th>Amount</th><th>Method</th><th>Gateway</th><th>Transaction ID</th><th>Status</th>
                        <th>Paid</th><th>Balance</th><th>Receipt</th><th>Action</th><th>Created</th>
                    </tr></thead>
                    <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="15">No property transactions recorded yet.</td></tr>
                    <?php else : foreach ( $rows as $row ) : 
                        $admin_status_styles = [
                            'verified'             => 'background:#dcfce7; color:#16a34a;',
                            'successful'           => 'background:#dcfce7; color:#16a34a;',
                            'pending'              => 'background:#fef3c7; color:#d97706;',
                            'pending_verification' => 'background:#fef3c7; color:#d97706;',
                            'failed'               => 'background:#fee2e2; color:#ef4444;',
                            'rejected'             => 'background:#fee2e2; color:#ef4444;',
                            'refunded'             => 'background:#f1f5f9; color:#64748b;',
                        ];
                        $admin_st = strtolower( $row->status );
                        $admin_st_style = $admin_status_styles[ $admin_st ] ?? 'background:#f1f5f9; color:#64748b;';
                        $admin_st_label = ( $admin_st === 'pending_verification' ) ? 'Pending' : ucfirst( str_replace( '_', ' ', $row->status ) );
                    ?>
                        <tr>
                            <td>#<?php echo esc_html( $row->id ); ?></td>
                            <td><strong><?php echo esc_html( $row->buyer_name ); ?></strong><br><small><?php echo esc_html( $row->buyer_phone ); ?></small></td>
                            <td><?php echo esc_html( $row->property_title ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row->business_name ?: 'Platform' ); ?></td>
                            <td>#<?php echo esc_html( $row->purchase_id ); ?></td>
                            <td><strong>NGN <?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></strong></td>
                            <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->payment_method ) ) ); ?></td>
                            <td><?php echo esc_html( $row->gateway ?: '—' ); ?></td>
                            <?php 
                                $raw_ref     = (string) ( $row->gateway_reference ?: ( $row->payer_reference ?: '' ) );
                                $display_ref = class_exists( 'OFP_Property_Receipt_Generator' ) 
                                    ? OFP_Property_Receipt_Generator::format_display_reference( $raw_ref, '—' ) 
                                    : ( $raw_ref ?: '—' );
                            ?>
                            <td><code title="<?php echo esc_attr( $raw_ref ); ?>"><?php echo esc_html( $display_ref ); ?></code></td>
                            <td>
                                <span style="font-size:11.5px; font-weight:600; padding:3px 8px; border-radius:12px; <?php echo esc_attr( $admin_st_style ); ?>">
                                    <?php echo esc_html( $admin_st_label ); ?>
                                </span>
                            </td>
                            <td>NGN <?php echo esc_html( number_format( (float) $row->purchase_paid, 2 ) ); ?></td>
                            <td>NGN <?php echo esc_html( number_format( (float) $row->purchase_balance, 2 ) ); ?></td>
                            <td><?php echo $row->receipt_path ? '<a href="' . esc_url( OFP_Property_Receipt_Viewer::get_receipt_url( (int) $row->id, 'purchase' ) ) . '" onclick="event.preventDefault(); ofpOpenProofModal(this.href);" style="color:#2563eb; font-weight:600; text-decoration:none;">View proof</a>' : '—'; ?></td>
                            <td>
                                <?php if ( 'pending_verification' === $row->status ) : 
                                    $v_nonce = wp_create_nonce( 'ofp_property_payment_verify_' . $row->id );
                                    $r_nonce = wp_create_nonce( 'ofp_property_payment_reject_' . $row->id );
                                    $post_url = admin_url( 'admin-post.php' );
                                    $amt_fmt = 'NGN ' . number_format( (float) $row->amount, 2 );
                                ?>
                                    <div style="display:flex; gap:6px; align-items:center;">
                                        <button type="button" 
                                                onclick="ofpOpenVerifyModal(<?php echo (int) $row->id; ?>, '<?php echo esc_js( $row->buyer_name ); ?>', '<?php echo esc_js( $amt_fmt ); ?>', '<?php echo esc_js( $v_nonce ); ?>', '<?php echo esc_url( $post_url ); ?>', 'ofp_property_payment_verify')" 
                                                style="padding:3px 9px; font-size:11.5px; font-weight:600; border-radius:5px; background:#059669; color:#fff; border:1px solid #047857; cursor:pointer; line-height:1.4;">
                                            Verify
                                        </button>
                                        <button type="button" 
                                                onclick="ofpOpenRejectModal(<?php echo (int) $row->id; ?>, '<?php echo esc_js( $row->buyer_name ); ?>', '<?php echo esc_js( $amt_fmt ); ?>', '<?php echo esc_js( $r_nonce ); ?>', '<?php echo esc_url( $post_url ); ?>', 'ofp_property_payment_reject')" 
                                                style="padding:3px 9px; font-size:11.5px; font-weight:600; border-radius:5px; background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; cursor:pointer; line-height:1.4;">
                                            Reject
                                        </button>
                                    </div>
                                <?php else : ?>—<?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $row->created_at ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php self::render_payment_modals(); ?>
            <script>
            (function() {
                var searchInput = document.getElementById('ofp-admin-txn-search');
                var table = document.getElementById('ofp-admin-transactions-table');
                if (!searchInput || !table) return;
                searchInput.addEventListener('input', function() {
                    var val = this.value.trim().toLowerCase();
                    var rows = table.querySelectorAll('tbody tr');
                    rows.forEach(function(r) {
                        var text = r.textContent.toLowerCase();
                        r.style.display = (!val || text.indexOf(val) !== -1) ? '' : 'none';
                    });
                });
            })();
            </script>
        </div>
        <?php
    }

    public static function client_page(): void {
        $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        if ( 'property-payments' !== $path ) return;

        $client = OFP_Auth::current_client();
        if ( ! $client ) {
            wp_safe_redirect( home_url( '/login' ) );
            exit;
        }
        if ( ! OFP_Subscription::has_platform_access( $client->id ) ) {
            wp_safe_redirect( home_url( '/dashboard' ) );
            exit;
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $client_id = (int) $client->id;

        $search = sanitize_text_field( wp_unslash( $_GET['s'] ?? $_GET['q'] ?? '' ) );
        $status = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );
        $method = sanitize_text_field( wp_unslash( $_GET['method'] ?? '' ) );

        $rows = self::get_rows( $client_id, $search, $status, $method );

        // Aggregate counts for status pills
        $counts_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT py.status, COUNT(*) as cnt
             FROM {$p}ofp_property_payments py
             INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
             WHERE pu.client_id = %d
             GROUP BY py.status",
            $client_id
        ) );
        $counts = [ 'all' => 0, 'successful' => 0, 'pending' => 0, 'failed' => 0 ];
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
        $has_filters = ( '' !== $search || ( '' !== $status && 'all' !== $status ) || ( '' !== $method && 'all' !== $method ) );
        $total_amount = array_reduce( $rows, static fn( $acc, $it ) => $acc + (float) $it->amount, 0.0 );
        ?>
        <!doctype html><html lang="en"><head>
            <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Transactions — <?php echo esc_html( OFP_Brand::name() ); ?></title>
            <?php wp_head(); ?>
            <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
            <style>
            /* Exact Match Row Highlighting */
            .ofp-row-matched {
                background: rgba(59, 130, 246, 0.12) !important;
                position: relative;
                box-shadow: inset 3px 0 0 var(--accent-blue, #3b82f6);
                animation: ofpPulseRow2 2s ease-in-out infinite alternate;
            }
            @keyframes ofpPulseRow2 {
                0% { background: rgba(59, 130, 246, 0.10); }
                100% { background: rgba(59, 130, 246, 0.20); }
            }
            </style>
            <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
        </head><body class="ofp-portal-body">
        <?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>
        <div class="ofp-container">
            <div style="padding-bottom: 60px;">
                <?php $ofp_sales_tab = 'payments'; include OFP_PATH . 'public/templates/partials/sales-tabs.php'; ?>

                <!-- Search & Filter Card -->
                <div class="ofp-txn-filter-card">
                    <!-- Mobile toggle bar (hidden on desktop) -->
                    <div class="ofp-filter-toggle-bar" id="ofp-client-filter-toggle-bar">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="font-size:13.5px; font-weight:700; color:var(--text-main,#f8fafc);">&#9881; Filter &amp; Search</span>
                            <?php if ( $has_filters ) : ?>
                                <span style="font-size:10.5px; padding:2px 7px; border-radius:9999px; background:var(--brand-color,#3b82f6); color:#fff; font-weight:600;">Active</span>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="ofp-filter-toggle-btn" id="ofp-client-filter-toggle-btn" aria-expanded="<?php echo $has_filters ? 'true' : 'false'; ?>" aria-controls="ofp-client-filter-collapsible">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" style="width:13px;height:13px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h5.25m5.25-.75L17.25 9m0 0L21 12.75M17.25 9v12"/></svg>
                            <span id="ofp-client-filter-toggle-text"><?php echo $has_filters ? 'Hide Filters' : 'Show Filters'; ?></span>
                        </button>
                    </div>

                    <form method="get" action="<?php echo esc_url( home_url( '/property-payments' ) ); ?>">
                        <!-- Collapsible search and filter section -->
                        <div class="ofp-filter-collapsible <?php echo $has_filters ? 'is-open' : ''; ?>" id="ofp-client-filter-collapsible">
                            <div class="ofp-txn-search-row">
                                <div class="ofp-txn-search-box">
                                    <svg class="ofp-txn-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                    </svg>
                                    <input type="text" 
                                           id="ofp-client-txn-search" 
                                           name="s" 
                                           class="ofp-input ofp-txn-search-input" 
                                           value="<?php echo esc_attr( $search ); ?>" 
                                           placeholder="Search Transaction ID (TXN-...), Receipt #, Buyer, Phone, Property..." 
                                           autocomplete="off" />
                                </div>

                                <select name="status" class="ofp-select ofp-txn-select">
                                    <option value="">All Statuses</option>
                                    <option value="successful" <?php selected( in_array( $status, [ 'successful', 'verified' ], true ) ); ?>>Verified / Successful</option>
                                    <option value="pending_verification" <?php selected( in_array( $status, [ 'pending', 'pending_verification' ], true ) ); ?>>Pending Verification</option>
                                    <option value="failed" <?php selected( in_array( $status, [ 'failed', 'rejected' ], true ) ); ?>>Failed / Rejected</option>
                                    <option value="refunded" <?php selected( $status, 'refunded' ); ?>>Refunded</option>
                                </select>

                                <select name="method" class="ofp-select ofp-txn-select">
                                    <option value="">All Methods</option>
                                    <option value="checkout" <?php selected( $method, 'checkout' ); ?>>Online Checkout (Paystack)</option>
                                    <option value="manual" <?php selected( $method, 'manual' ); ?>>Bank Transfer / Manual</option>
                                    <option value="bank_transfer" <?php selected( $method, 'bank_transfer' ); ?>>Direct Transfer</option>
                                    <option value="pos" <?php selected( $method, 'pos' ); ?>>POS</option>
                                    <option value="cash" <?php selected( $method, 'cash' ); ?>>Cash</option>
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
                        </div>

                        <!-- Status Filter Pills (Tabs with horizontal scroll on mobile) -->
                        <div class="ofp-txn-filter-pills">
                            <span style="font-size:12px; font-weight:600; color:var(--text-muted); margin-right:4px; white-space:nowrap; flex-shrink:0;">Filter:</span>
                            <?php
                            $pills = [
                                ''           => [ 'label' => 'All',        'count' => $counts['all'] ],
                                'successful' => [ 'label' => 'Verified',   'count' => $counts['successful'] ],
                                'pending'    => [ 'label' => 'Pending',    'count' => $counts['pending'] ],
                                'failed'     => [ 'label' => 'Failed',     'count' => $counts['failed'] ],
                            ];
                            foreach ( $pills as $pval => $pinfo ) :
                                $is_active = ( $status === $pval || ( '' === $pval && '' === $status ) );
                                $p_url = add_query_arg( array_merge(
                                    array_diff_key( array_filter( [ 's' => $search, 'method' => $method ] ), [ 'status' => 1 ] ),
                                    $pval !== '' ? [ 'status' => $pval ] : []
                                ), home_url( '/property-payments' ) );
                            ?>
                                <a href="<?php echo esc_url( $p_url ); ?>" class="ofp-txn-pill <?php echo $is_active ? 'active' : ''; ?>">
                                    <span><?php echo esc_html( $pinfo['label'] ); ?></span>
                                    <span class="ofp-txn-pill-count"><?php echo esc_html( $pinfo['count'] ); ?></span>
                                </a>
                            <?php endforeach; ?>

                            <span id="ofp-client-txn-live-count" style="display:none; margin-left:auto; font-size:12px; color:var(--brand-color); font-weight:600; flex-shrink:0;"></span>
                        </div>
                    </form>
                </div>

                <?php if ( '' !== $search ) : ?>
                    <?php if ( ! empty( $rows ) ) : ?>
                        <div style="background:rgba(59,130,246,0.1); border:1px solid rgba(59,130,246,0.25); border-radius:10px; padding:12px 18px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:18px; height:18px; color:var(--accent-blue);"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                <span style="font-size:13.5px; color:var(--text-main);">
                                    Found <strong><?php echo count( $rows ); ?></strong> transaction(s) matching &ldquo;<strong><?php echo esc_html( $search ); ?></strong>&rdquo; 
                                    (Total: <strong style="color:var(--accent-green);">₦<?php echo esc_html( number_format( $total_amount, 2 ) ); ?></strong>)
                                </span>
                            </div>
                            <a href="<?php echo esc_url( home_url( '/property-payments' ) ); ?>" class="ofp-btn ofp-btn-sm" style="padding:4px 10px; font-size:12px; text-decoration:none;">Show All Transactions</a>
                        </div>
                    <?php else : ?>
                        <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:10px; padding:14px 18px; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                            <span style="font-size:13.5px; color:#ef4444;">
                                No transactions found matching &ldquo;<strong><?php echo esc_html( $search ); ?></strong>&rdquo;. Please check the reference or receipt number.
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
                                       value="<?php echo esc_attr( $search ); ?>">
                            </div>
                            <span id="ofp-live-match-badge" class="ofp-btn ofp-btn-outline" style="font-size:12px; font-weight:600; padding:6px 14px; border-radius:8px; pointer-events:none;">
                                <?php echo count( $rows ); ?> Records
                            </span>
                        </div>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="ofp-table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>Receipt / ID</th>
                                    <th>Buyer</th>
                                    <th>Property</th>
                                    <th>Purchase</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Transaction ID</th>
                                    <th>Status</th>
                                    <th>Receipt</th>
                                    <th>Paid</th>
                                    <th>Balance</th>
                                    <th>Action</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ( empty( $rows ) ) : ?>
                                <tr><td colspan="13" style="text-align:center; color:#64748b; padding:32px;">No property transactions recorded yet.</td></tr>
                            <?php else : foreach ( $rows as $row ) : 
                                $raw_ref     = (string) ( $row->gateway_reference ?: ( $row->payer_reference ?: '' ) );
                                $display_ref = class_exists( 'OFP_Property_Receipt_Generator' ) 
                                    ? OFP_Property_Receipt_Generator::format_display_reference( $raw_ref, '—' ) 
                                    : ( $raw_ref ?: '—' );
                                $receipt_no  = 'PUR-' . str_pad( (string) $row->id, 6, '0', STR_PAD_LEFT );

                                $is_matched = false;
                                if ( '' !== $search ) {
                                    $s_clean = strtoupper( trim( preg_replace( '/^#/', '', $search ) ) );
                                    if ( (int) $row->id === (int) $s_clean ||
                                         $s_clean === strtoupper( $receipt_no ) ||
                                         $s_clean === strtoupper( $display_ref ) ||
                                         ( $raw_ref !== '' && stripos( $raw_ref, $s_clean ) !== false ) ||
                                         count( $rows ) === 1 ) {
                                        $is_matched = true;
                                    }
                                }

                                $search_blob = strtolower( $receipt_no . ' ' . $row->id . ' ' . $display_ref . ' ' . $raw_ref . ' ' . $row->buyer_name . ' ' . $row->buyer_phone . ' ' . $row->property_title . ' ' . $row->payment_method . ' ' . $row->status );
                            ?>
                                <tr class="ofp-txn-row <?php echo $is_matched ? 'ofp-row-matched' : ''; ?>" data-search="<?php echo esc_attr( $search_blob ); ?>">
                                    <td>
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <strong style="color:var(--text-main); font-size:12.5px;"><?php echo esc_html( $receipt_no ); ?></strong>
                                            <?php if ( $is_matched ) : ?>
                                                <span style="font-size:10px; font-weight:700; background:var(--accent-blue); color:#fff; padding:1px 5px; border-radius:4px; letter-spacing:0.3px;">MATCH</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:11px; color:var(--text-muted);">#<?php echo esc_html( $row->id ); ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500; color: var(--text-main);"><?php echo esc_html( $row->buyer_name ); ?></div>
                                        <div style="font-size: 12px; color: var(--text-muted);"><?php echo esc_html( $row->buyer_phone ); ?></div>
                                    </td>
                                    <td style="color:var(--text-main);"><?php echo esc_html( $row->property_title ?: '—' ); ?></td>
                                    <td style="color:var(--text-main);">#<?php echo esc_html( $row->purchase_id ); ?></td>
                                    <td><strong style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></strong></td>
                                    <td style="color:var(--text-main);"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->payment_method ) ) ); ?></td>
                                    <td>
                                        <code style="font-size:12px; color:var(--text-main); background:rgba(128,128,128,0.1); padding:2px 6px; border-radius:4px; font-weight:600;" title="<?php echo esc_attr( $raw_ref ); ?>">
                                            <?php echo esc_html( $display_ref ); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <?php 
                                            $status_styles = [
                                                'verified'             => 'background:#dcfce7; color:#16a34a;',
                                                'successful'           => 'background:#dcfce7; color:#16a34a;',
                                                'pending'              => 'background:#fef3c7; color:#d97706;',
                                                'pending_verification' => 'background:#fef3c7; color:#d97706;',
                                                'failed'               => 'background:#fee2e2; color:#ef4444;',
                                                'rejected'             => 'background:#fee2e2; color:#ef4444;',
                                                'refunded'             => 'background:rgba(128,128,128,0.1); color:var(--text-muted);'
                                            ];
                                            $style = $status_styles[ strtolower( $row->status ) ] ?? 'background:rgba(128,128,128,0.1); color:var(--text-muted);';
                                            $status_display = ( strtolower( $row->status ) === 'pending_verification' ) ? 'Pending' : ucfirst( str_replace( '_', ' ', $row->status ) );
                                        ?>
                                        <span style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:100px; <?php echo esc_attr($style); ?>">
                                            <?php echo esc_html( $status_display ); ?>
                                        </span>
                                    </td>
                                    <td style="font-size:13px;"><?php echo $row->receipt_path ? '<a href="' . esc_url( OFP_Property_Receipt_Viewer::get_receipt_url( (int) $row->id, 'purchase' ) ) . '" onclick="event.preventDefault(); ofpOpenProofModal(this.href);" style="color:#2563eb; font-weight:600; text-decoration:none;">View proof</a>' : '<span style="color:var(--text-muted);">—</span>'; ?></td>
                                    <td style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $row->purchase_paid, 2 ) ); ?></td>
                                    <td style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $row->purchase_balance, 2 ) ); ?></td>
                                    <td>
                                        <?php if ( 'pending_verification' === $row->status ) : 
                                            $client_v_nonce = wp_create_nonce( 'ofp_client_payment_verify_' . $row->id );
                                            $client_r_nonce = wp_create_nonce( 'ofp_client_payment_reject_' . $row->id );
                                            $client_post_url = admin_url( 'admin-post.php' );
                                            $client_amt_fmt = 'NGN ' . number_format( (float) $row->amount, 2 );
                                        ?>
                                            <div style="display:flex; gap:6px; align-items:center;">
                                                <button type="button" 
                                                        onclick="ofpOpenVerifyModal(<?php echo (int) $row->id; ?>, '<?php echo esc_js( $row->buyer_name ); ?>', '<?php echo esc_js( $client_amt_fmt ); ?>', '<?php echo esc_js( $client_v_nonce ); ?>', '<?php echo esc_url( $client_post_url ); ?>', 'ofp_client_payment_verify')" 
                                                        style="padding:4px 10px; font-size:11.5px; font-weight:600; border-radius:6px; background:#059669; color:#fff; border:1px solid #047857; cursor:pointer; line-height:1.4; display:inline-flex; align-items:center; gap:4px; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                                                    Verify
                                                </button>
                                                <button type="button" 
                                                        onclick="ofpOpenRejectModal(<?php echo (int) $row->id; ?>, '<?php echo esc_js( $row->buyer_name ); ?>', '<?php echo esc_js( $client_amt_fmt ); ?>', '<?php echo esc_js( $client_r_nonce ); ?>', '<?php echo esc_url( $client_post_url ); ?>', 'ofp_client_payment_reject')" 
                                                        style="padding:4px 10px; font-size:11.5px; font-weight:600; border-radius:6px; background:rgba(239,68,68,0.08); color:#dc2626; border:1px solid rgba(239,68,68,0.25); cursor:pointer; line-height:1.4; display:inline-flex; align-items:center; gap:4px;">
                                                    Reject
                                                </button>
                                            </div>
                                        <?php else : ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
                                    </td>
                                    <td style="color:var(--text-muted); font-size:13px;"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $row->created_at ) ) ); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var topSearch = document.getElementById('ofp-client-txn-search');
            var tableSearch = document.getElementById('ofp-table-live-search');
            var rows = document.querySelectorAll('.ofp-table tbody tr.ofp-txn-row');
            var liveCounter = document.getElementById('ofp-client-txn-live-count');
            var matchBadge = document.getElementById('ofp-live-match-badge');

            var matchedRow = document.querySelector('.ofp-row-matched');
            if (matchedRow) {
                setTimeout(function() {
                    matchedRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 250);
            }

            function doFilter(query, sourceInput) {
                var val = (query || '').trim().toLowerCase();
                var visible = 0;

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

            // Mobile Filter Toggle (Show/Hide Filters)
            var filterToggleBtn = document.getElementById('ofp-client-filter-toggle-btn');
            var filterToggleBar = document.getElementById('ofp-client-filter-toggle-bar');
            var filterCollapsible = document.getElementById('ofp-client-filter-collapsible');
            var filterToggleText = document.getElementById('ofp-client-filter-toggle-text');

            function toggleFilterPanel() {
                if (!filterCollapsible) return;
                var isOpen = filterCollapsible.classList.toggle('is-open');
                if (filterToggleBtn) {
                    filterToggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                }
                if (filterToggleText) {
                    filterToggleText.textContent = isOpen ? 'Hide Filters' : 'Show Filters';
                }
            }

            if (filterToggleBtn) {
                filterToggleBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleFilterPanel();
                });
            }
            if (filterToggleBar) {
                filterToggleBar.addEventListener('click', function(e) {
                    if (e.target !== filterToggleBtn && !filterToggleBtn.contains(e.target)) {
                        toggleFilterPanel();
                    }
                });
            }
        });
        </script>
        <?php self::render_payment_modals(); ?>
        <?php wp_footer(); ?></body></html>
        <?php
        exit;
    }

    public static function admin_verify(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        $payment_id = absint( $_POST['payment_id'] ?? 0 );
        check_admin_referer( 'ofp_property_payment_verify_' . $payment_id );
        OFP_Property_Payment_Record::success( $payment_id, get_current_user_id() );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-payments' ) );
        exit;
    }

    public static function admin_reject(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );
        $payment_id = absint( $_POST['payment_id'] ?? 0 );
        check_admin_referer( 'ofp_property_payment_reject_' . $payment_id );
        $reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
        OFP_Property_Payment_Record::reject( $payment_id, get_current_user_id(), $reason !== '' ? $reason : 'Rejected from property payment review.' );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=ofp_property&page=ofp-property-payments' ) );
        exit;
    }

    public static function client_verify(): void {
        self::client_action( true );
    }

    public static function client_reject(): void {
        self::client_action( false );
    }

    private static function client_action( bool $approve ): void {
        $payment_id = absint( $_POST['payment_id'] ?? 0 );
        check_admin_referer( 'ofp_client_payment_' . ( $approve ? 'verify_' : 'reject_' ) . $payment_id );
        OFP_Auth::require_client_login();
        $client = OFP_Auth::current_client();
        if ( ! $client || ! OFP_Subscription::has_platform_access( $client->id ) ) wp_die( 'Access denied.' );

        global $wpdb;
        $owned = $wpdb->get_var( $wpdb->prepare(
            "SELECT py.id FROM {$wpdb->prefix}ofp_property_payments py INNER JOIN {$wpdb->prefix}ofp_property_purchases pu ON pu.id = py.purchase_id WHERE py.id = %d AND pu.client_id = %d LIMIT 1",
            $payment_id,
            (int) $client->id
        ) );
        if ( ! $owned ) wp_die( 'Access denied.' );

        if ( $approve ) {
            OFP_Property_Payment_Record::success( $payment_id, 0 );
        } else {
            $reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
            OFP_Property_Payment_Record::reject( $payment_id, 0, $reason !== '' ? $reason : 'Rejected by property client.' );
        }

        wp_safe_redirect( wp_get_referer() ?: home_url( '/property-payments/' ) );
        exit;
    }

    public static function redirect_legacy_verification(): void {
        $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        if ( 'property-payment-verification' !== $path ) return;
        wp_safe_redirect( home_url( '/property-payments/' ) );
        exit;
    }

    public static function render_payment_modals(): void {
        ?>
        <style>
            .ofp-payment-modal-backdrop {
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                width: 100vw; height: 100vh;
                background: rgba(15, 23, 42, 0.65);
                backdrop-filter: blur(4px);
                -webkit-backdrop-filter: blur(4px);
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 16px;
                box-sizing: border-box;
                animation: ofpFadeIn 0.15s ease-out;
            }
            @keyframes ofpFadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            .ofp-payment-modal-card {
                background: #ffffff;
                color: #0f172a;
                width: 100%;
                max-width: 460px;
                border-radius: 14px;
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.2);
                border: 1px solid #e2e8f0;
                overflow: hidden;
                box-sizing: border-box;
                animation: ofpPopIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            }
            @keyframes ofpPopIn {
                from { opacity: 0; transform: scale(0.96) translateY(8px); }
                to { opacity: 1; transform: scale(1) translateY(0); }
            }
            .ofp-payment-modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                border-bottom: 1px solid #e2e8f0;
                background: #ffffff;
            }
            .ofp-payment-modal-body {
                padding: 18px 20px;
                background: #ffffff;
                box-sizing: border-box;
            }
            .ofp-payment-modal-footer {
                display: flex;
                justify-content: flex-end;
                gap: 10px;
                padding: 14px 20px;
                background: #f8fafc;
                border-top: 1px solid #e2e8f0;
            }
            .ofp-modal-btn-cancel {
                padding: 8px 16px;
                font-size: 13px;
                font-weight: 600;
                border-radius: 8px;
                border: 1px solid #cbd5e1;
                background: #ffffff;
                color: #475569;
                cursor: pointer;
                transition: all 0.15s ease;
                line-height: 1.4;
            }
            .ofp-modal-btn-cancel:hover {
                background: #f1f5f9;
                color: #0f172a;
            }
            .ofp-modal-btn-confirm {
                padding: 8px 18px;
                font-size: 13px;
                font-weight: 600;
                border-radius: 8px;
                border: 1px solid #059669;
                background: #059669;
                color: #ffffff;
                cursor: pointer;
                transition: all 0.15s ease;
                line-height: 1.4;
            }
            .ofp-modal-btn-confirm:hover {
                background: #047857;
            }
            .ofp-modal-btn-danger {
                padding: 8px 18px;
                font-size: 13px;
                font-weight: 600;
                border-radius: 8px;
                border: 1px solid #dc2626;
                background: #dc2626;
                color: #ffffff;
                cursor: pointer;
                transition: all 0.15s ease;
                line-height: 1.4;
            }
            .ofp-modal-btn-danger:hover {
                background: #b91c1c;
            }
            /* Dark theme support when on client portal with dark mode */
            [data-theme="dark"] .ofp-payment-modal-card {
                background: var(--bg-card, #1e293b);
                color: var(--text-main, #f8fafc);
                border-color: var(--border-color, #334155);
            }
            [data-theme="dark"] .ofp-payment-modal-header,
            [data-theme="dark"] .ofp-payment-modal-body {
                background: var(--bg-card, #1e293b);
                border-color: var(--border-color, #334155);
            }
            [data-theme="dark"] .ofp-payment-modal-footer {
                background: var(--bg-body, #0f172a);
                border-color: var(--border-color, #334155);
            }
            [data-theme="dark"] .ofp-modal-btn-cancel {
                background: var(--bg-card, #1e293b);
                border-color: var(--border-color, #334155);
                color: var(--text-muted, #94a3b8);
            }
            [data-theme="dark"] .ofp-modal-btn-cancel:hover {
                background: var(--bg-body, #0f172a);
                color: var(--text-main, #f8fafc);
            }
        </style>

        <!-- VERIFY MODAL -->
        <div id="ofp-modal-verify" class="ofp-payment-modal-backdrop" style="display:none;" onclick="if(event.target===this) ofpClosePaymentModal('ofp-modal-verify');">
            <div class="ofp-payment-modal-card">
                <div class="ofp-payment-modal-header">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:36px; height:36px; border-radius:50%; background:#dcfce7; color:#16a34a; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <div>
                            <h3 style="margin:0; font-size:16px; font-weight:700;">Confirm Verification</h3>
                            <div style="font-size:12px; color:#64748b;">Ascertain and credit payment</div>
                        </div>
                    </div>
                    <button type="button" onclick="ofpClosePaymentModal('ofp-modal-verify')" style="background:none; border:none; font-size:24px; cursor:pointer; color:#94a3b8; line-height:1; padding:0;">&times;</button>
                </div>
                <form method="post" id="ofp-form-verify">
                    <input type="hidden" name="action" id="ofp-verify-action" value="">
                    <input type="hidden" name="payment_id" id="ofp-verify-payment-id" value="">
                    <div id="ofp-verify-nonce-holder"></div>

                    <div class="ofp-payment-modal-body">
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; margin-bottom:14px;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-size:13px;">
                                <span style="color:#64748b;">Buyer:</span>
                                <strong id="ofp-verify-buyer" style="color:#0f172a;">—</strong>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:13px;">
                                <span style="color:#64748b;">Amount:</span>
                                <strong id="ofp-verify-amount" style="color:#059669; font-size:14px;">—</strong>
                            </div>
                        </div>
                        <p style="margin:0; font-size:13px; color:#475569; line-height:1.5;">
                            Are you sure you want to verify this payment? This will confirm receipt of funds, credit the buyer's balance, and update their installment status.
                        </p>
                    </div>
                    <div class="ofp-payment-modal-footer">
                        <button type="button" onclick="ofpClosePaymentModal('ofp-modal-verify')" class="ofp-modal-btn-cancel">Cancel</button>
                        <button type="submit" class="ofp-modal-btn-confirm">Verify &amp; Confirm</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- REJECT MODAL -->
        <div id="ofp-modal-reject" class="ofp-payment-modal-backdrop" style="display:none;" onclick="if(event.target===this) ofpClosePaymentModal('ofp-modal-reject');">
            <div class="ofp-payment-modal-card">
                <div class="ofp-payment-modal-header">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:36px; height:36px; border-radius:50%; background:#fee2e2; color:#ef4444; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </div>
                        <div>
                            <h3 style="margin:0; font-size:16px; font-weight:700;">Reject Payment</h3>
                            <div style="font-size:12px; color:#64748b;">Decline submitted payment proof</div>
                        </div>
                    </div>
                    <button type="button" onclick="ofpClosePaymentModal('ofp-modal-reject')" style="background:none; border:none; font-size:24px; cursor:pointer; color:#94a3b8; line-height:1; padding:0;">&times;</button>
                </div>
                <form method="post" id="ofp-form-reject">
                    <input type="hidden" name="action" id="ofp-reject-action" value="">
                    <input type="hidden" name="payment_id" id="ofp-reject-payment-id" value="">
                    <div id="ofp-reject-nonce-holder"></div>

                    <div class="ofp-payment-modal-body">
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; margin-bottom:14px;">
                            <div style="display:flex; justify-content:space-between; margin-bottom:6px; font-size:13px;">
                                <span style="color:#64748b;">Buyer:</span>
                                <strong id="ofp-reject-buyer" style="color:#0f172a;">—</strong>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:13px;">
                                <span style="color:#64748b;">Amount:</span>
                                <strong id="ofp-reject-amount" style="color:#dc2626; font-size:14px;">—</strong>
                            </div>
                        </div>
                        <div>
                            <label for="ofp-reject-reason-input" style="display:block; font-size:13px; font-weight:600; color:#1e293b; margin-bottom:6px;">
                                Reason for Rejection <span style="color:#ef4444;">*</span>
                            </label>
                            <textarea name="reason" id="ofp-reject-reason-input" required rows="3" placeholder="Please enter reason (e.g. Transfer not received, incorrect amount)..." style="width:100%; box-sizing:border-box; padding:10px 12px; font-size:13px; border-radius:8px; border:1px solid #cbd5e1; background:#ffffff; color:#0f172a; resize:vertical; font-family:inherit;"></textarea>
                        </div>
                    </div>
                    <div class="ofp-payment-modal-footer">
                        <button type="button" onclick="ofpClosePaymentModal('ofp-modal-reject')" class="ofp-modal-btn-cancel">Cancel</button>
                        <button type="submit" class="ofp-modal-btn-danger">Reject Payment</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- PROOF LIGHTBOX MODAL -->
        <div id="ofp-modal-proof" class="ofp-payment-modal-backdrop" style="display:none;" onclick="if(event.target===this) ofpClosePaymentModal('ofp-modal-proof');">
            <div class="ofp-payment-modal-card" style="max-width:760px; width:92%; max-height:90vh; display:flex; flex-direction:column;">
                <div class="ofp-payment-modal-header">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:36px; height:36px; border-radius:50%; background:#e0e7ff; color:#4338ca; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div>
                            <h3 style="margin:0; font-size:16px; font-weight:700;">Payment Proof</h3>
                            <div style="font-size:12px; color:#64748b;">Uploaded receipt / transfer proof preview</div>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:14px;">
                        <a id="ofp-proof-external-link" href="#" target="_blank" rel="noopener" style="font-size:12px; font-weight:600; color:#2563eb; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                            <span>Open original</span> &nearr;
                        </a>
                        <button type="button" onclick="ofpClosePaymentModal('ofp-modal-proof')" style="background:none; border:none; font-size:24px; cursor:pointer; color:#94a3b8; line-height:1; padding:0;">&times;</button>
                    </div>
                </div>
                <div class="ofp-payment-modal-body" style="padding:0; flex:1; min-height:480px; background:#0f172a; position:relative; overflow:hidden;">
                    <iframe id="ofp-proof-iframe" src="about:blank" style="width:100%; height:100%; min-height:480px; border:none; display:block;"></iframe>
                </div>
                <div class="ofp-payment-modal-footer">
                    <button type="button" onclick="ofpClosePaymentModal('ofp-modal-proof')" class="ofp-modal-btn-cancel">Close</button>
                </div>
            </div>
        </div>

        <script>
        function ofpOpenVerifyModal(id, buyer, amount, nonce, actionUrl, actionName) {
            document.getElementById('ofp-verify-payment-id').value = id;
            document.getElementById('ofp-verify-action').value = actionName;
            document.getElementById('ofp-verify-buyer').textContent = buyer;
            document.getElementById('ofp-verify-amount').textContent = amount;
            document.getElementById('ofp-form-verify').action = actionUrl;
            document.getElementById('ofp-verify-nonce-holder').innerHTML = '<input type="hidden" name="_wpnonce" value="' + nonce + '">';
            var m = document.getElementById('ofp-modal-verify');
            if (m) m.style.display = 'flex';
        }

        function ofpOpenRejectModal(id, buyer, amount, nonce, actionUrl, actionName) {
            document.getElementById('ofp-reject-payment-id').value = id;
            document.getElementById('ofp-reject-action').value = actionName;
            document.getElementById('ofp-reject-buyer').textContent = buyer;
            document.getElementById('ofp-reject-amount').textContent = amount;
            document.getElementById('ofp-reject-reason-input').value = '';
            document.getElementById('ofp-form-reject').action = actionUrl;
            document.getElementById('ofp-reject-nonce-holder').innerHTML = '<input type="hidden" name="_wpnonce" value="' + nonce + '">';
            var m = document.getElementById('ofp-modal-reject');
            if (m) m.style.display = 'flex';
        }

        function ofpOpenProofModal(url) {
            var iframe = document.getElementById('ofp-proof-iframe');
            var ext = document.getElementById('ofp-proof-external-link');
            if (iframe) iframe.src = url;
            if (ext) ext.href = url;
            var m = document.getElementById('ofp-modal-proof');
            if (m) m.style.display = 'flex';
        }

        function ofpClosePaymentModal(id) {
            var m = document.getElementById(id);
            if (m) m.style.display = 'none';
            if (id === 'ofp-modal-proof') {
                var iframe = document.getElementById('ofp-proof-iframe');
                if (iframe) iframe.src = 'about:blank';
            }
        }
        </script>
        <?php
    }
}

OFP_Property_Payment_Records::init();
