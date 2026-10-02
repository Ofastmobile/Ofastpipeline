<?php
/**
 * Secure, no-login lease acceptance and payment page for tenants.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Tenant_Portal {
    const ROUTE_VERSION = '2';

    public static function init(): void {
        if ( get_option( 'ofp_tenant_lease_route_version' ) !== self::ROUTE_VERSION ) {
            update_option( 'ofp_tenant_lease_route_version', self::ROUTE_VERSION, false );
            update_option( 'ofp_flush_rewrite_rules', '1' );
        }
        add_action( 'init', [ __CLASS__, 'register_route' ] );
        add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_route' ] );
    }

    public static function register_route(): void {
        add_rewrite_rule( '^tenant-lease/([^/]+)/?$', 'index.php?ofp_tenant_lease=$matches[1]', 'top' );
    }

    public static function query_vars( array $vars ): array {
        $vars[] = 'ofp_tenant_lease';
        return $vars;
    }

    public static function handle_route(): void {
        $token = sanitize_text_field( get_query_var( 'ofp_tenant_lease', '' ) );
        if ( ! $token ) return;
        self::render( $token );
        exit;
    }

    private static function render( string $token ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $lease = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.*, t.full_name, t.email, t.phone, pr.title AS property_title, c.business_name
             FROM {$p}ofp_property_leases l
             INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
             LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
             LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
             WHERE l.offer_token_hash = %s LIMIT 1",
            hash( 'sha256', $token )
        ) );
        $error = '';
        $notice = '';
        if ( ! $lease ) {
            $error = 'This lease link is invalid or has expired.';
        } elseif ( $lease->status === 'pending_offer' && $lease->offer_expires_at && strtotime( $lease->offer_expires_at ) < time() ) {
            $error = 'This lease offer has expired. Please contact your property manager to request a new offer.';
        }

        if ( $lease && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_accept_lease'] ) ) {
            if ( ! wp_verify_nonce( $_POST['ofp_tenant_lease_nonce'] ?? '', 'ofp_tenant_lease_' . $lease->id ) ) {
                $error = 'Security check failed. Please refresh the page and try again.';
            } elseif ( empty( $_POST['accept_terms'] ) ) {
                $error = 'Please accept the lease terms before continuing.';
            } else {
                $accepted = OFP_Property_Rent::accept_lease_offer( $token, $_SERVER['REMOTE_ADDR'] ?? '' );
                if ( is_wp_error( $accepted ) ) {
                    $error = $accepted->get_error_message();
                } else {
                    $notice = 'Lease agreement accepted successfully. Your first verified payment activates the tenancy.';
                    $lease = $wpdb->get_row( $wpdb->prepare(
                        "SELECT l.*, t.full_name, t.email, t.phone, pr.title AS property_title, c.business_name
                         FROM {$p}ofp_property_leases l
                         INNER JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
                         LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
                         LEFT JOIN {$p}ofp_clients c ON c.id = l.client_id
                         WHERE l.id = %d",
                        $lease->id
                    ) );
                }
            }
        }

        if ( $lease && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_tenant_checkout'] ) && $lease->accepted_at ) {
            if ( ! wp_verify_nonce( $_POST['ofp_tenant_checkout_nonce'] ?? '', 'ofp_tenant_checkout_' . $lease->id ) ) {
                $error = 'Security check failed. Please refresh the page and try again.';
            } else {
                $checkout = OFP_Property_Lease_Payment::initiate_checkout( (int) $lease->id, (float) ( $_POST['amount'] ?? 0 ) );
                if ( is_wp_error( $checkout ) ) {
                    $error = $checkout->get_error_message();
                } else {
                    wp_redirect( $checkout );
                    exit;
                }
            }
        }

        $va = ( $lease && $lease->accepted_at ) ? OFP_Property_Lease_Payment::ensure_virtual_account( (int) $lease->id ) : null;

        // Fetch payments made on this lease
        $payments = [];
        if ( $lease && $lease->accepted_at ) {
            $payments = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$p}ofp_property_lease_payments WHERE lease_id = %d ORDER BY created_at DESC",
                (int) $lease->id
            ) );
        }
        ?>
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html( $lease ? ( 'Lease Offer — ' . ( $lease->property_title ?: 'Tenancy' ) ) : 'Lease Offer' ); ?></title>
            <script>
                (function() {
                    var t = localStorage.getItem('ofp_theme') || (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
                    if (t === 'light') { document.documentElement.setAttribute('data-theme', 'light'); document.documentElement.classList.add('light'); }
                })();
            </script>
            <?php wp_head(); ?>
            <style>
                :root {
                    --bg-body: #f8fafc;
                    --bg-card: #ffffff;
                    --text-main: #0f172a;
                    --text-muted: #64748b;
                    --border-color: #e2e8f0;
                    --accent-blue: #2563eb;
                    --accent-green: #10b981;
                }
                [data-theme="dark"], html.dark {
                    --bg-body: #0b0f19;
                    --bg-card: #111827;
                    --text-main: #f8fafc;
                    --text-muted: #94a3b8;
                    --border-color: #1f2937;
                    --accent-blue: #3b82f6;
                    --accent-green: #10b981;
                }
                body {
                    font-family: Inter, system-ui, -apple-system, sans-serif;
                    background: var(--bg-body);
                    color: var(--text-main);
                    margin: 0;
                    padding: 0;
                    line-height: 1.5;
                }
                .wrap {
                    max-width: 720px;
                    margin: 40px auto;
                    padding: 0 16px;
                }
                .card {
                    background: var(--bg-card);
                    border: 1px solid var(--border-color);
                    border-radius: 16px;
                    padding: 32px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.04);
                    margin-bottom: 24px;
                }
                .meta {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 12px;
                    margin: 24px 0;
                }
                .meta div {
                    background: var(--bg-body);
                    border: 1px solid var(--border-color);
                    border-radius: 10px;
                    padding: 14px;
                }
                .meta small {
                    display: block;
                    color: var(--text-muted);
                    font-size: 11px;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                    font-weight: 600;
                    margin-bottom: 4px;
                }
                .btn {
                    border: 0;
                    border-radius: 8px;
                    padding: 12px 20px;
                    font-weight: 600;
                    font-size: 14px;
                    background: var(--accent-blue);
                    color: #fff;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    text-decoration: none;
                    transition: opacity 0.2s ease;
                }
                .btn:hover { opacity: 0.92; }
                .btn-sm { padding: 6px 12px; font-size: 12px; }
                .field { margin: 16px 0; }
                .field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
                .field input {
                    width: 100%;
                    box-sizing: border-box;
                    padding: 12px;
                    border: 1px solid var(--border-color);
                    border-radius: 8px;
                    background: var(--bg-body);
                    color: var(--text-main);
                    font-size: 14px;
                }
                .alert {
                    padding: 14px 18px;
                    border-radius: 10px;
                    margin: 20px 0;
                    font-size: 14px;
                }
                .err { background: rgba(239, 68, 68, 0.12); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); }
                .ok { background: rgba(16, 185, 129, 0.12); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
                .terms {
                    padding: 18px;
                    background: var(--bg-body);
                    border: 1px solid var(--border-color);
                    border-radius: 10px;
                    white-space: pre-wrap;
                    line-height: 1.6;
                    font-size: 13px;
                    max-height: 240px;
                    overflow-y: auto;
                    margin: 18px 0;
                }
                .bank {
                    padding: 20px;
                    background: rgba(37, 99, 235, 0.08);
                    border: 1px solid rgba(37, 99, 235, 0.2);
                    border-radius: 12px;
                    margin-top: 20px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 13px;
                }
                th, td {
                    padding: 12px 14px;
                    text-align: left;
                    border-bottom: 1px solid var(--border-color);
                }
                th {
                    color: var(--text-muted);
                    font-weight: 600;
                    text-transform: uppercase;
                    font-size: 11px;
                }
            </style>
        </head>
        <body>
        <main class="wrap">
            <section class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px;">
                    <p style="color:var(--text-muted); margin:0; font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em;">
                        <?php echo esc_html( $lease->business_name ?? 'Property Management' ); ?>
                    </p>
                    <?php if ( $lease ) : ?>
                        <span style="font-size:12px; padding:4px 10px; border-radius:99px; background:<?php echo $lease->status === 'active' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(245, 158, 11, 0.15)'; ?>; color:<?php echo $lease->status === 'active' ? '#10b981' : '#f59e0b'; ?>; font-weight:700;">
                            <?php echo esc_html( ucfirst( str_replace( '_', ' ', $lease->status ) ) ); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <h1 style="margin:0 0 4px; font-size:24px; font-weight:700;">Lease Agreement &amp; Portal</h1>
                <p style="color:var(--text-muted); margin:0; font-size:14px;">Secure, direct tenancy access for <?php echo esc_html( $lease ? $lease->full_name : 'tenant' ); ?></p>

                <?php if ( $error ) : ?>
                    <div class="alert err"><?php echo esc_html( $error ); ?></div>
                <?php endif; ?>

                <?php if ( $notice ) : ?>
                    <div class="alert ok"><?php echo esc_html( $notice ); ?></div>
                <?php endif; ?>

                <?php if ( $lease ) : ?>
                    <div class="meta">
                        <div>
                            <small>Tenant Name</small>
                            <strong><?php echo esc_html( $lease->full_name ); ?></strong>
                        </div>
                        <div>
                            <small>Property</small>
                            <strong><?php echo esc_html( $lease->property_title ?: 'Rental Property' ); ?></strong>
                        </div>
                        <div>
                            <small>Rent Period</small>
                            <strong><?php echo esc_html( OFP_Property_Rent::period_label( $lease->rent_period, (int) $lease->custom_days ) ); ?></strong>
                        </div>
                        <div>
                            <small>Tenancy Cycle</small>
                            <strong>Cycle #<?php echo esc_html( (int) ( $lease->cycle_number ?: 1 ) ); ?> <?php echo (int) ( $lease->cycle_number ?: 1 ) > 1 ? '<span style="font-size:11px; font-weight:normal; color:#10b981;">(Renewal)</span>' : '<span style="font-size:11px; font-weight:normal; color:var(--text-muted);">(Move-in)</span>'; ?></strong>
                        </div>
                    </div>

                    <?php
                    $has_move_in_fees = ( (float) ( $lease->legal_fee ?? 0 ) > 0 )
                        || ( (float) ( $lease->agency_fee ?? 0 ) > 0 )
                        || ( (float) ( $lease->caution_fee ?? 0 ) > 0 )
                        || ( (float) ( $lease->service_charge ?? 0 ) > 0 );
                    ?>

                    <?php if ( $has_move_in_fees || (float) ( $lease->total_initial_package ?? 0 ) > 0 ) : ?>
                        <div style="background:var(--bg-body); border:1px solid var(--border-color); border-radius:10px; padding:16px; margin:20px 0;">
                            <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); margin-bottom:12px;">
                                Financial Breakdown &amp; Package Schedule
                            </div>
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; font-size:13px;">
                                <div>
                                    <span style="color:var(--text-muted); display:block; font-size:11px;">Base Recurring Rent</span>
                                    <strong style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $lease->rent_amount, 2 ) ); ?></strong>
                                </div>
                                <?php if ( (float) ( $lease->legal_fee ?? 0 ) > 0 ) : ?>
                                    <div>
                                        <span style="color:var(--text-muted); display:block; font-size:11px;">Legal &amp; Agreement Fee <small>(One-off)</small></span>
                                        <strong style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $lease->legal_fee, 2 ) ); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if ( (float) ( $lease->agency_fee ?? 0 ) > 0 ) : ?>
                                    <div>
                                        <span style="color:var(--text-muted); display:block; font-size:11px;">Agency Fee <small>(One-off)</small></span>
                                        <strong style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $lease->agency_fee, 2 ) ); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if ( (float) ( $lease->caution_fee ?? 0 ) > 0 ) : ?>
                                    <div>
                                        <span style="color:var(--text-muted); display:block; font-size:11px;">Caution Deposit <small>(Refundable)</small></span>
                                        <strong style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $lease->caution_fee, 2 ) ); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if ( (float) ( $lease->service_charge ?? 0 ) > 0 ) : ?>
                                    <div>
                                        <span style="color:var(--text-muted); display:block; font-size:11px;">Service Charge <?php echo ! empty( $lease->is_service_charge_recurring ) ? '<small>(Recurring)</small>' : '<small>(One-off)</small>'; ?></span>
                                        <strong style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $lease->service_charge, 2 ) ); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <div style="border-top:1px dashed var(--border-color); padding-top:8px; grid-column: 1 / -1; display:flex; justify-content:space-between; align-items:center;">
                                    <span style="font-weight:600; font-size:13px;">Total Package Due:</span>
                                    <strong style="font-size:16px; color:var(--accent-blue);">NGN <?php echo esc_html( number_format( (float) ( $lease->total_initial_package ?: $lease->rent_amount ), 2 ) ); ?></strong>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( ! $lease->accepted_at ) : ?>
                        <h3 style="font-size:16px; margin: 24px 0 8px;">Lease Terms &amp; Conditions</h3>
                        <div class="terms"><?php echo esc_html( $lease->terms_text ?: "1. Rent is due at the beginning of each cycle.\n2. The tenant agrees to maintain the premises in good condition.\n3. Subletting is prohibited without written landlord consent." ); ?></div>
                        
                        <form method="post" style="margin-top:20px;">
                            <div class="field">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                                    <input type="checkbox" name="accept_terms" value="1" required style="width:auto;">
                                    <span>I, <strong><?php echo esc_html( $lease->full_name ); ?></strong>, have read and agree to the lease terms above.</span>
                                </label>
                            </div>
                            <?php wp_nonce_field( 'ofp_tenant_lease_' . $lease->id, 'ofp_tenant_lease_nonce' ); ?>
                            <button type="submit" class="btn" name="ofp_accept_lease" value="1" style="width:100%;">
                                Accept Lease Agreement
                            </button>
                        </form>
                    <?php else : ?>
                        <div style="background:var(--bg-body); border:1px solid var(--border-color); border-radius:12px; padding:18px; margin:20px 0;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                                <div>
                                    <small style="color:var(--text-muted); font-size:11px; text-transform:uppercase; font-weight:600; display:block;">Current Cycle Balance</small>
                                    <strong style="font-size:22px; color:<?php echo (float) $lease->balance > 0 ? '#ef4444' : '#10b981'; ?>;">
                                        NGN <?php echo esc_html( number_format( (float) $lease->balance, 2 ) ); ?>
                                    </strong>
                                </div>
                                <div style="text-align:right;">
                                    <small style="color:var(--text-muted); font-size:11px; text-transform:uppercase; font-weight:600; display:block;">Total Paid This Cycle</small>
                                    <strong style="font-size:16px; color:#10b981;">NGN <?php echo esc_html( number_format( (float) $lease->amount_paid, 2 ) ); ?></strong>
                                </div>
                            </div>
                        </div>

                        <?php if ( (float) $lease->balance > 0 ) : ?>
                            <div style="margin:24px 0;">
                                <h3 style="font-size:16px; margin: 0 0 12px;">Make Rent Payment</h3>
                                <form method="post" style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <div class="field" style="margin:0; flex:1; min-width:200px;">
                                        <input type="number" name="amount" min="0.01" max="<?php echo esc_attr( $lease->balance ); ?>" step="0.01" value="<?php echo esc_attr( $lease->balance ); ?>" required placeholder="Amount in NGN">
                                    </div>
                                    <?php wp_nonce_field( 'ofp_tenant_checkout_' . $lease->id, 'ofp_tenant_checkout_nonce' ); ?>
                                    <button class="btn" name="ofp_tenant_checkout" value="1">
                                        Pay Online with Paystack
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <?php if ( $va ) : ?>
                            <div class="bank">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                    <div>
                                        <strong style="font-size:13px; text-transform:uppercase; letter-spacing:0.05em; color:var(--accent-blue);">Dedicated Bank Transfer Account</strong>
                                        <div style="font-size:14px; margin-top:4px; font-weight:600;"><?php echo esc_html( $va->bank_name ); ?></div>
                                        <div style="font-size:24px; font-weight:800; letter-spacing:0.03em; margin:6px 0;"><?php echo esc_html( $va->account_number ); ?></div>
                                        <small style="color:var(--text-muted); display:block;">Transfers made to this account are verified automatically and credited to this lease instantly.</small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- ── Payment History & Receipts ── -->
                        <div style="margin-top:32px;">
                            <h3 style="font-size:16px; margin:0 0 14px;">Payment History &amp; Receipts</h3>
                            <?php if ( empty( $payments ) ) : ?>
                                <p style="color:var(--text-muted); font-size:13px; margin:0;">No payments recorded for this lease cycle yet. Once you make a payment, your official receipt will appear here.</p>
                            <?php else : ?>
                                <div style="overflow-x:auto;">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Method</th>
                                                <th>Status</th>
                                                <th style="text-align:right;">Receipt</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $payments as $pay ) : ?>
                                                <tr>
                                                    <td><?php echo esc_html( date( 'd M Y', strtotime( $pay->created_at ) ) ); ?></td>
                                                    <td><strong>NGN <?php echo esc_html( number_format( (float) $pay->amount, 2 ) ); ?></strong></td>
                                                    <td style="text-transform:capitalize;"><?php echo esc_html( str_replace( '_', ' ', $pay->payment_method ) ); ?></td>
                                                    <td>
                                                        <?php if ( $pay->status === 'successful' ) : ?>
                                                            <span style="color:#10b981; font-weight:600;">Verified</span>
                                                        <?php else : ?>
                                                            <span style="color:#f59e0b; font-weight:600;"><?php echo esc_html( ucfirst( $pay->status ) ); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="text-align:right;">
                                                        <?php if ( $pay->status === 'successful' ) : ?>
                                                            <?php $receipt_href = OFP_Property_Receipt_Viewer::get_receipt_url( (int) $pay->id, 'rent', $token ); ?>
                                                            <a href="<?php echo esc_url( $receipt_href ); ?>" target="_blank" class="btn btn-sm" style="background:var(--bg-body); color:var(--text-main); border:1px solid var(--border-color);">
                                                                View Receipt ↗
                                                            </a>
                                                        <?php else : ?>
                                                            <span style="color:var(--text-muted);">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </main>
        <?php wp_footer(); ?>
        </body>
        </html>
        <?php
    }
}
