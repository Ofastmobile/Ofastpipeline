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

    private static function get_rows( int $client_id = 0 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $sql = "SELECT py.*, pu.property_id, pu.client_id, pu.buyer_name, pu.buyer_phone,
                       pu.total_price, pu.amount_paid AS purchase_paid, pu.balance AS purchase_balance,
                       pr.title AS property_title, c.business_name
                FROM {$p}ofp_property_payments py
                INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
                LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
                LEFT JOIN {$p}ofp_clients c ON c.id = pu.client_id";

        if ( $client_id > 0 ) {
            $sql .= $wpdb->prepare( ' WHERE pu.client_id = %d', $client_id );
        }

        $sql .= ' ORDER BY py.created_at DESC, py.id DESC LIMIT 250';
        return $wpdb->get_results( $sql ) ?: [];
    }

    public static function render_admin(): void {
        $rows = self::get_rows();
        ?>
        <div class="wrap">
            <h1>Property Transactions</h1>
            <p>Every property transaction and payment record. Pending manual payments can be verified or rejected here.</p>
            <div style="overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;">
                <table class="widefat striped" style="min-width:1500px;">
                    <thead><tr>
                        <th>ID</th><th>Buyer</th><th>Property</th><th>Owner</th><th>Purchase</th>
                        <th>Amount</th><th>Method</th><th>Gateway</th><th>Reference</th><th>Status</th>
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
                            <td><code><?php echo esc_html( $row->gateway_reference ?: ( $row->payer_reference ?: '—' ) ); ?></code></td>
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

        $rows = self::get_rows( (int) $client->id );
        ?>
        <!doctype html><html lang="en"><head>
            <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Transactions — <?php echo esc_html( OFP_Brand::name() ); ?></title>
            <?php wp_head(); ?>
            <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
            <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
        </head><body class="ofp-portal-body">
        <?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>
        <div class="ofp-container">
            <div style="padding-bottom: 60px;">
                <?php $ofp_sales_tab = 'payments'; include OFP_PATH . 'public/templates/partials/sales-tabs.php'; ?>

                <div class="ofp-card">
                    <div style="overflow-x:auto;">
                        <table class="ofp-table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Buyer</th>
                                    <th>Property</th>
                                    <th>Purchase</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Reference</th>
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
                                <tr><td colspan="13" style="text-align:center; color:#64748b;">No property transactions recorded yet.</td></tr>
                            <?php else : foreach ( $rows as $row ) : ?>
                                <tr>
                                    <td style="color:var(--text-muted);">#<?php echo esc_html( $row->id ); ?></td>
                                    <td>
                                        <div style="font-weight: 500; color: var(--text-main);"><?php echo esc_html( $row->buyer_name ); ?></div>
                                        <div style="font-size: 12px; color: var(--text-muted);"><?php echo esc_html( $row->buyer_phone ); ?></div>
                                    </td>
                                    <td style="color:var(--text-main);"><?php echo esc_html( $row->property_title ?: '—' ); ?></td>
                                    <td style="color:var(--text-main);">#<?php echo esc_html( $row->purchase_id ); ?></td>
                                    <td><strong style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></strong></td>
                                    <td style="color:var(--text-main);"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->payment_method ) ) ); ?></td>
                                    <td>
                                        <code style="font-size:12px; color:var(--text-main); background:rgba(128,128,128,0.1); padding:2px 6px; border-radius:4px;">
                                            <?php echo esc_html( $row->payer_reference ?: ( $row->gateway_reference ?: '—' ) ); ?>
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
