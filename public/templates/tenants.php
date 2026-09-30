<?php
/**
 * Template: /tenants (client portal) — Tenants & Rent Management.
 *
 * Full-featured rent management workspace:
 * - Gold plan gated (server-side & sidebar)
 * - Multi-period rent rate options per rental property
 * - Tenant directory with full lease & payment history
 * - Secure lease offers with shareable token links (no tenant login required)
 * - Flexible rent payment tracking (Online Paystack, Virtual Account, Cash)
 * - Automated occupied property status & marketplace visibility integration
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

$can_rent = $client && OFP_Property_Rent::can_manage( (int) $client->id );
global $wpdb;
$p = $wpdb->prefix;

$notice     = '';
$error      = '';
$offer_link = '';
$offer_tenant_name = '';
$offer_tenant_phone = '';

// ── Action Handlers (Gold Only) ─────────────────────────────────────────────
if ( $can_rent && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    $action = sanitize_key( $_POST['ofp_rent_action'] ?? '' );

    // 1. Save or Update Rent Option
    if ( $action === 'save_option' ) {
        if ( ! wp_verify_nonce( $_POST['ofp_rent_nonce'] ?? '', 'ofp_rent_save_option' ) ) {
            $error = 'Security check failed. Please refresh and try again.';
        } else {
            $prop_id   = absint( $_POST['property_id'] ?? 0 );
            $period    = sanitize_key( $_POST['period'] ?? 'monthly' );
            $custom_d  = absint( $_POST['custom_days'] ?? 0 );
            $amount    = (float) ( $_POST['amount'] ?? 0 );
            $option_id = absint( $_POST['option_id'] ?? 0 );

            $result = OFP_Property_Rent::save_rent_option(
                (int) $client->id,
                $prop_id,
                [
                    'period'      => $period,
                    'custom_days' => $custom_d,
                    'amount'      => $amount,
                    'is_active'   => 1,
                ],
                $option_id
            );

            if ( is_wp_error( $result ) ) {
                $error = $result->get_error_message();
            } else {
                wp_safe_redirect( add_query_arg( [ 'success' => 'option_saved', 'tab' => 'rates' ], home_url( '/tenants' ) ) );
                exit;
            }
        }
    }

    // 2. Toggle Rent Option Active State
    elseif ( $action === 'toggle_option' ) {
        if ( ! wp_verify_nonce( $_POST['ofp_rent_nonce'] ?? '', 'ofp_rent_toggle_option' ) ) {
            $error = 'Security check failed. Please refresh and try again.';
        } else {
            $opt_id = absint( $_POST['option_id'] ?? 0 );
            $opt = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}ofp_property_rent_options WHERE id = %d AND client_id = %d LIMIT 1",
                $opt_id,
                $client->id
            ) );
            if ( $opt ) {
                $new_state = $opt->is_active ? 0 : 1;
                $wpdb->update( "{$p}ofp_property_rent_options", [ 'is_active' => $new_state, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $opt_id ] );
                wp_safe_redirect( add_query_arg( [ 'success' => 'option_updated', 'tab' => 'rates' ], home_url( '/tenants' ) ) );
                exit;
            }
        }
    }

    // 3. Create Lease Offer
    elseif ( $action === 'create_offer' ) {
        if ( ! wp_verify_nonce( $_POST['ofp_rent_nonce'] ?? '', 'ofp_rent_create_offer' ) ) {
            $error = 'Security check failed. Please refresh and try again.';
        } else {
            $prop_id   = absint( $_POST['property_id'] ?? 0 );
            $opt_id    = absint( $_POST['rent_option_id'] ?? 0 );
            $t_name    = sanitize_text_field( wp_unslash( $_POST['tenant_name'] ?? '' ) );
            $t_phone   = sanitize_text_field( wp_unslash( $_POST['tenant_phone'] ?? '' ) );
            $t_email   = sanitize_email( wp_unslash( $_POST['tenant_email'] ?? '' ) );
            $terms     = sanitize_textarea_field( wp_unslash( $_POST['terms_text'] ?? '' ) );
            $expires   = sanitize_text_field( wp_unslash( $_POST['offer_expires_at'] ?? '' ) );

            $tenant = OFP_Property_Rent::find_or_create_tenant( (int) $client->id, [
                'full_name' => $t_name,
                'phone'     => $t_phone,
                'email'     => $t_email,
            ] );

            if ( is_wp_error( $tenant ) ) {
                $error = $tenant->get_error_message();
            } else {
                $offer = OFP_Property_Rent::create_lease_offer(
                    (int) $client->id,
                    $prop_id,
                    (int) $tenant['tenant_id'],
                    $opt_id,
                    [
                        'terms_text'       => $terms,
                        'offer_expires_at' => $expires ? $expires . ' 23:59:59' : null,
                    ]
                );

                if ( is_wp_error( $offer ) ) {
                    $error = $offer->get_error_message();
                } else {
                    $raw_token = $offer['offer_token'];
                    wp_safe_redirect( add_query_arg( [
                        'success'     => 'offer_created',
                        'token'       => rawurlencode( $raw_token ),
                        'tenant_name' => rawurlencode( $t_name ),
                        'phone'       => rawurlencode( $t_phone ),
                        'tab'         => 'leases',
                    ], home_url( '/tenants' ) ) );
                    exit;
                }
            }
        }
    }

    // 4. Record Cash / Manual Payment
    elseif ( $action === 'cash_payment' ) {
        if ( ! wp_verify_nonce( $_POST['ofp_rent_nonce'] ?? '', 'ofp_rent_cash_payment' ) ) {
            $error = 'Security check failed. Please refresh and try again.';
        } else {
            $lease_id  = absint( $_POST['lease_id'] ?? 0 );
            $amount    = (float) ( $_POST['amount'] ?? 0 );
            $reference = sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) );
            $note      = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

            $payment = OFP_Property_Lease_Payment::record_cash_payment(
                (int) $client->id,
                $lease_id,
                $amount,
                $reference,
                $note
            );

            if ( is_wp_error( $payment ) ) {
                $error = $payment->get_error_message();
            } else {
                wp_safe_redirect( add_query_arg( [ 'success' => 'payment_recorded', 'tab' => 'leases' ], home_url( '/tenants' ) ) );
                exit;
            }
        }
    }
}

// Flash messages from query parameters
if ( isset( $_GET['success'] ) ) {
    $s = sanitize_key( $_GET['success'] );
    if ( $s === 'option_saved' ) $notice = 'Rent rate option saved successfully.';
    if ( $s === 'option_updated' ) $notice = 'Rent rate status updated.';
    if ( $s === 'payment_recorded' ) $notice = 'Cash payment successfully recorded and applied to lease balance.';
    if ( $s === 'offer_created' ) {
        $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        if ( $token ) {
            $offer_link = home_url( '/tenant-lease/' . rawurlencode( $token ) . '/' );
            $offer_tenant_name = sanitize_text_field( wp_unslash( $_GET['tenant_name'] ?? '' ) );
            $offer_tenant_phone = sanitize_text_field( wp_unslash( $_GET['phone'] ?? '' ) );
            $notice = 'Lease offer created successfully! Share the secure link below with your tenant.';
        }
    }
}

// Active Tab
$active_tab = sanitize_key( $_GET['tab'] ?? 'leases' );
if ( ! in_array( $active_tab, [ 'leases', 'tenants', 'rates', 'payments' ], true ) ) {
    $active_tab = 'leases';
}

// ── Query Data for Gold Plan ───────────────────────────────────────────────
$rental_properties = [];
$rent_options      = [];
$leases            = [];
$tenants           = [];
$payments          = [];

$stat_active_leases = 0;
$stat_total_tenants = 0;
$stat_rent_collected = 0.0;
$stat_balance_due   = 0.0;

if ( $can_rent ) {
    // 1. Rental properties
    $rental_properties = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, title, price, price_period, listing_type
         FROM {$p}ofp_properties
         WHERE client_id = %d AND listing_type IN ('rent', 'shortlet')
         ORDER BY title ASC",
        $client->id
    ) );

    // 2. Rent Options
    $rent_options = $wpdb->get_results( $wpdb->prepare(
        "SELECT ro.*, pr.title AS property_title, pr.listing_type
         FROM {$p}ofp_property_rent_options ro
         JOIN {$p}ofp_properties pr ON pr.id = ro.property_id
         WHERE ro.client_id = %d
         ORDER BY pr.title ASC, ro.amount ASC",
        $client->id
    ) );

    // 3. Leases
    $leases = $wpdb->get_results( $wpdb->prepare(
        "SELECT l.*, t.full_name AS tenant_name, t.phone AS tenant_phone, t.email AS tenant_email, pr.title AS property_title
         FROM {$p}ofp_property_leases l
         JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
         LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
         WHERE l.client_id = %d
         ORDER BY l.created_at DESC",
        $client->id
    ) );

    // 4. Tenants Directory
    $tenants = $wpdb->get_results( $wpdb->prepare(
        "SELECT t.*,
                COUNT(l.id) AS total_leases,
                SUM(CASE WHEN l.status = 'active' THEN 1 ELSE 0 END) AS active_leases,
                COALESCE(SUM(l.amount_paid), 0) AS total_paid,
                COALESCE(SUM(l.balance), 0) AS total_balance
         FROM {$p}ofp_property_tenants t
         LEFT JOIN {$p}ofp_property_leases l ON l.tenant_id = t.id
         WHERE t.client_id = %d
         GROUP BY t.id
         ORDER BY t.created_at DESC",
        $client->id
    ) );

    // 5. Payment Records
    $payments = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.*, l.rent_period, l.custom_days, t.full_name AS tenant_name, pr.title AS property_title
         FROM {$p}ofp_property_lease_payments p
         JOIN {$p}ofp_property_leases l ON l.id = p.lease_id
         JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
         LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
         WHERE l.client_id = %d
         ORDER BY p.created_at DESC
         LIMIT 100",
        $client->id
    ) );

    // Stats calculations
    foreach ( $leases as $l ) {
        if ( $l->status === 'active' ) {
            $stat_active_leases++;
        }
        $stat_rent_collected += (float) $l->amount_paid;
        if ( in_array( $l->status, [ 'active', 'pending_offer' ], true ) && ! empty( $l->accepted_at ) ) {
            $stat_balance_due += (float) $l->balance;
        }
    }
    $stat_total_tenants = count( $tenants );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenants & Leases — OFast Pipeline</title>
    <script>
        (function() {
            var currentTheme = localStorage.getItem('ofp_theme') || 'dark';
            if (currentTheme === 'light') { document.documentElement.setAttribute('data-theme', 'light'); }
        })();
    </script>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
    <style>
        .ofp-tab-btn {
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .ofp-tab-btn:hover {
            color: var(--text-main);
        }
        .ofp-tab-btn.active {
            color: var(--accent-blue);
            border-bottom-color: var(--accent-blue);
        }
        .ofp-tab-badge {
            background: var(--bg-card-hover);
            color: var(--text-muted);
            padding: 2px 7px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }
        .ofp-tab-btn.active .ofp-tab-badge {
            background: rgba(59, 130, 246, 0.15);
            color: var(--accent-blue);
        }
        .ofp-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .ofp-kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .ofp-kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .ofp-kpi-icon svg {
            width: 22px;
            height: 22px;
        }
        .ofp-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .ofp-modal-content {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            padding: 24px 28px;
        }
        .ofp-progress-track {
            background: var(--bg-body);
            border-radius: 999px;
            height: 6px;
            overflow: hidden;
            width: 100%;
            margin-top: 4px;
        }
        .ofp-progress-bar {
            background: var(--accent-green);
            height: 100%;
            border-radius: 999px;
            transition: width 0.3s ease;
        }
    </style>
</head>
<body class="ofp-portal-body">
<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">
    <div style="padding-bottom: 60px;">

        <!-- ── Page Header ── -->
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
            <div>
                <h1 style="font-size:24px; font-weight:700; color:var(--text-main); margin:0 0 6px; letter-spacing:-0.01em;">
                    Tenants &amp; Rent Management
                </h1>
                <p style="color:var(--text-muted); margin:0; font-size:14px;">
                    Track tenants, configure property rent rates, send digital lease offers, and collect rent cycles.
                </p>
            </div>

            <?php if ( $can_rent ) : ?>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" onclick="ofpOpenModal('ofp-modal-offer')" class="ofp-btn ofp-btn-primary" style="display:inline-flex; align-items:center; gap:8px;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        New Lease Offer
                    </button>
                    <button type="button" onclick="ofpOpenModal('ofp-modal-rate')" class="ofp-btn" style="background:var(--bg-card); color:var(--text-main); border:1px solid var(--border-color); display:inline-flex; align-items:center; gap:8px;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Add Rent Rate
                    </button>
                    <button type="button" onclick="ofpOpenModal('ofp-modal-payment')" class="ofp-btn" style="background:var(--bg-card); color:var(--text-main); border:1px solid var(--border-color); display:inline-flex; align-items:center; gap:8px;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Record Payment
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Alerts & Notifications ── -->
        <?php if ( $notice ) : ?>
            <div class="ofp-alert ofp-alert-success" style="margin-bottom: 20px;">
                <div style="font-weight: 600; margin-bottom: 4px;"><?php echo esc_html( $notice ); ?></div>
                <?php if ( $offer_link ) : ?>
                    <div style="margin-top: 12px; display: flex; flex-direction: column; gap: 8px;">
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                            <input type="text" readonly value="<?php echo esc_attr( $offer_link ); ?>" style="flex: 1; min-width: 260px; padding: 9px 12px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-body); color: var(--text-main); font-size: 13px;" id="ofp-new-offer-url">
                            <button type="button" class="ofp-btn ofp-btn-primary" onclick="ofpCopyLink('ofp-new-offer-url', this)">Copy Link</button>
                            <?php if ( $offer_tenant_phone ) : ?>
                                <?php
                                $wa_phone = preg_replace( '/[^0-9]/', '', $offer_tenant_phone );
                                $wa_msg = rawurlencode( "Hello " . ( $offer_tenant_name ?: 'there' ) . ", here is your secure lease offer link: " . $offer_link );
                                ?>
                                <a href="https://wa.me/<?php echo esc_attr( $wa_phone ); ?>?text=<?php echo $wa_msg; ?>" target="_blank" class="ofp-btn" style="background:#25D366; color:#fff; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                                    WhatsApp Tenant
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( $offer_link ); ?>" target="_blank" class="ofp-btn" style="background:var(--bg-card); color:var(--text-main); border:1px solid var(--border-color); text-decoration:none;">
                                View Offer Page ↗
                            </a>
                        </div>
                        <small style="color: var(--text-muted);">This is a zero-login link. Once the tenant signs and verifies their first rent payment, this tenancy becomes active automatically.</small>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $error ) : ?>
            <div class="ofp-alert ofp-alert-error" style="margin-bottom: 20px;">
                <?php echo esc_html( $error ); ?>
            </div>
        <?php endif; ?>

        <?php if ( ! $can_rent ) : ?>
            <!-- ── Gold Feature Gating Lock Card ── -->
            <div class="ofp-card" style="text-align: center; padding: 56px 24px; max-width: 760px; margin: 20px auto;">
                <div style="width: 64px; height: 64px; border-radius: 16px; background: rgba(245, 158, 11, 0.15); color: #f59e0b; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                    <svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M18 18.75c0-1.243-1.343-2.25-3-2.25s-3 1.007-3 2.25m6 0a2.25 2.25 0 01-2.25 2.25h-1.5A2.25 2.25 0 0112 18.75m6 0v-1.5a3 3 0 00-3-3h-1.5a3 3 0 00-3 3v1.5M12 9a3 3 0 100-6 3 3 0 000 6z"/></svg>
                </div>
                <h2 style="font-size: 22px; font-weight: 700; color: var(--text-main); margin: 0 0 10px;">
                    Rent &amp; Tenant Management is a Gold Feature
                </h2>
                <p style="color: var(--text-muted); font-size: 14px; line-height: 1.6; max-width: 580px; margin: 0 auto 28px;">
                    Take full control of your rental portfolio. Gold plan members can manage tenants, configure flexible rent cycles (Monthly, Quarterly, Biannual, Yearly, Shortlets), issue digital lease agreements, and automate Paystack rent collections.
                </p>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; text-align: left; margin-bottom: 32px;">
                    <div style="padding: 14px; background: var(--bg-body); border-radius: 10px; border: 1px solid var(--border-color);">
                        <strong style="color: var(--text-main); font-size: 13px; display: block; margin-bottom: 4px;">🏢 Automated Occupancy</strong>
                        <span style="color: var(--text-muted); font-size: 12px;">Active leases automatically flag properties as "Occupied" and hide them from the public marketplace.</span>
                    </div>
                    <div style="padding: 14px; background: var(--bg-body); border-radius: 10px; border: 1px solid var(--border-color);">
                        <strong style="color: var(--text-main); font-size: 13px; display: block; margin-bottom: 4px;">💳 Paystack Virtual Accounts</strong>
                        <span style="color: var(--text-muted); font-size: 12px;">Tenants receive dedicated bank transfer details and online checkout links for instant reconciliation.</span>
                    </div>
                    <div style="padding: 14px; background: var(--bg-body); border-radius: 10px; border: 1px solid var(--border-color);">
                        <strong style="color: var(--text-main); font-size: 13px; display: block; margin-bottom: 4px;">🔗 No-Login Tenant Portal</strong>
                        <span style="color: var(--text-muted); font-size: 12px;">Tenants sign lease terms and inspect rent balance on branded mobile pages with zero login friction.</span>
                    </div>
                </div>

                <div>
                    <a href="<?php echo esc_url( home_url( '/pricing' ) ); ?>" class="ofp-btn ofp-btn-primary" style="padding: 12px 32px; font-size: 14px;">
                        Upgrade to Gold Plan
                    </a>
                </div>
            </div>

        <?php else : ?>

            <!-- ── Micro-Stats KPI Cards ── -->
            <div class="ofp-kpi-grid">
                <div class="ofp-kpi-card">
                    <div class="ofp-kpi-icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--text-muted); font-weight: 500;">Active Tenancies</div>
                        <div style="font-size: 22px; font-weight: 700; color: var(--text-main); margin-top: 2px;"><?php echo esc_html( $stat_active_leases ); ?></div>
                    </div>
                </div>

                <div class="ofp-kpi-card">
                    <div class="ofp-kpi-icon" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--text-muted); font-weight: 500;">Total Tenants</div>
                        <div style="font-size: 22px; font-weight: 700; color: var(--text-main); margin-top: 2px;"><?php echo esc_html( $stat_total_tenants ); ?></div>
                    </div>
                </div>

                <div class="ofp-kpi-card">
                    <div class="ofp-kpi-icon" style="background: rgba(139, 92, 246, 0.15); color: #8b5cf6;">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--text-muted); font-weight: 500;">Rent Collected</div>
                        <div style="font-size: 22px; font-weight: 700; color: var(--text-main); margin-top: 2px;">NGN <?php echo esc_html( number_format( $stat_rent_collected, 2 ) ); ?></div>
                    </div>
                </div>

                <div class="ofp-kpi-card">
                    <div class="ofp-kpi-icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--text-muted); font-weight: 500;">Balance Due</div>
                        <div style="font-size: 22px; font-weight: 700; color: var(--text-main); margin-top: 2px;">NGN <?php echo esc_html( number_format( $stat_balance_due, 2 ) ); ?></div>
                    </div>
                </div>
            </div>

            <!-- ── Tabs Header ── -->
            <div style="display:flex; border-bottom:1px solid var(--border-color); margin-bottom:20px; overflow-x:auto;">
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'leases', home_url( '/tenants' ) ) ); ?>" class="ofp-tab-btn <?php echo $active_tab === 'leases' ? 'active' : ''; ?>">
                    Leases &amp; Offers
                    <span class="ofp-tab-badge"><?php echo count( $leases ); ?></span>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'tenants', home_url( '/tenants' ) ) ); ?>" class="ofp-tab-btn <?php echo $active_tab === 'tenants' ? 'active' : ''; ?>">
                    Tenants Directory
                    <span class="ofp-tab-badge"><?php echo count( $tenants ); ?></span>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'rates', home_url( '/tenants' ) ) ); ?>" class="ofp-tab-btn <?php echo $active_tab === 'rates' ? 'active' : ''; ?>">
                    Property Rent Rates
                    <span class="ofp-tab-badge"><?php echo count( $rent_options ); ?></span>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'payments', home_url( '/tenants' ) ) ); ?>" class="ofp-tab-btn <?php echo $active_tab === 'payments' ? 'active' : ''; ?>">
                    Payment Records
                    <span class="ofp-tab-badge"><?php echo count( $payments ); ?></span>
                </a>
            </div>

            <!-- ══════════════════════════════════════════════════════════════ -->
            <!-- TAB 1: LEASES & OFFERS                                         -->
            <!-- ══════════════════════════════════════════════════════════════ -->
            <?php if ( $active_tab === 'leases' ) : ?>
                <div class="ofp-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                        <h3 style="margin:0; font-size:16px; font-weight:600; color:var(--text-main);">All Lease Records</h3>
                        <div style="display:flex; gap:8px;">
                            <input type="text" id="ofp-lease-search" placeholder="Search lease or tenant..." style="padding:6px 12px; font-size:13px; border:1px solid var(--border-color); border-radius:8px; background:var(--bg-body); color:var(--text-main);" onkeyup="ofpFilterTable('ofp-lease-search', 'ofp-leases-table')">
                        </div>
                    </div>

                    <?php if ( empty( $leases ) ) : ?>
                        <div style="text-align:center; padding:48px 16px; color:var(--text-muted);">
                            <svg width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-bottom:12px; opacity:0.5;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <p style="font-size:15px; font-weight:500; color:var(--text-main); margin-bottom:4px;">No lease offers created yet</p>
                            <p style="font-size:13px; margin-bottom:16px;">Create a lease offer and send it to your tenant with a single click.</p>
                            <button type="button" onclick="ofpOpenModal('ofp-modal-offer')" class="ofp-btn ofp-btn-primary">Create First Lease Offer</button>
                        </div>
                    <?php else : ?>
                        <div class="ofp-table-responsive">
                            <table class="ofp-table" id="ofp-leases-table">
                                <thead>
                                    <tr>
                                        <th>Tenant</th>
                                        <th>Property</th>
                                        <th>Period</th>
                                        <th>Rent Amount</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                        <th style="text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $leases as $l ) : ?>
                                        <?php
                                        $share_url = $l->offer_token ? home_url( '/tenant-lease/' . rawurlencode( $l->offer_token ) . '/' ) : '';
                                        $pct_paid = $l->rent_amount > 0 ? min( 100, round( ( (float) $l->amount_paid / (float) $l->rent_amount ) * 100 ) ) : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong style="color:var(--text-main); display:block;"><?php echo esc_html( $l->tenant_name ); ?></strong>
                                                <small style="color:var(--text-muted);"><?php echo esc_html( $l->tenant_phone ); ?></small>
                                            </td>
                                            <td>
                                                <span style="font-weight:500; color:var(--text-main);"><?php echo esc_html( $l->property_title ?: 'Property #' . $l->property_id ); ?></span>
                                                <?php if ( $l->start_date && $l->end_date ) : ?>
                                                    <small style="display:block; color:var(--text-muted);">
                                                        <?php echo esc_html( date( 'd M Y', strtotime( $l->start_date ) ) ); ?> – <?php echo esc_html( date( 'd M Y', strtotime( $l->end_date ) ) ); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo esc_html( OFP_Property_Rent::period_label( (string) $l->rent_period, (int) $l->custom_days ) ); ?>
                                            </td>
                                            <td>
                                                <strong>NGN <?php echo esc_html( number_format( (float) $l->rent_amount, 2 ) ); ?></strong>
                                                <div class="ofp-progress-track" title="<?php echo esc_attr( $pct_paid . '% paid' ); ?>">
                                                    <div class="ofp-progress-bar" style="width: <?php echo esc_attr( $pct_paid ); ?>%;"></div>
                                                </div>
                                                <small style="color:var(--text-muted); font-size:11px;"><?php echo esc_html( $pct_paid ); ?>% paid (NGN <?php echo esc_html( number_format( (float) $l->amount_paid, 2 ) ); ?>)</small>
                                            </td>
                                            <td>
                                                <?php if ( (float) $l->balance > 0 ) : ?>
                                                    <span style="color:#ef4444; font-weight:600;">NGN <?php echo esc_html( number_format( (float) $l->balance, 2 ) ); ?></span>
                                                <?php else : ?>
                                                    <span style="color:#10b981; font-weight:600;">Paid in Full</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ( $l->status === 'active' ) : ?>
                                                    <span class="ofp-badge" style="background:rgba(16, 185, 129, 0.15); color:#10b981;">Active</span>
                                                <?php elseif ( $l->status === 'pending_offer' ) : ?>
                                                    <span class="ofp-badge" style="background:rgba(245, 158, 11, 0.15); color:#f59e0b;">Pending Offer</span>
                                                <?php elseif ( $l->status === 'expired' ) : ?>
                                                    <span class="ofp-badge" style="background:rgba(239, 68, 68, 0.15); color:#ef4444;">Expired</span>
                                                <?php elseif ( $l->status === 'renewed' ) : ?>
                                                    <span class="ofp-badge" style="background:rgba(139, 92, 246, 0.15); color:#8b5cf6;">Renewed</span>
                                                <?php else : ?>
                                                    <span class="ofp-badge" style="background:var(--bg-body); color:var(--text-muted);"><?php echo esc_html( ucfirst( $l->status ) ); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <div style="display:inline-flex; gap:6px; align-items:center;">
                                                    <?php if ( $share_url && $l->status === 'pending_offer' ) : ?>
                                                        <button type="button" class="ofp-btn ofp-btn-sm" style="background:var(--bg-body); color:var(--text-main); border:1px solid var(--border-color);" onclick="navigator.clipboard.writeText('<?php echo esc_js( $share_url ); ?>'); alert('Offer link copied to clipboard!');">
                                                            Copy Link
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ( (float) $l->balance > 0 && $l->accepted_at ) : ?>
                                                        <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-primary" onclick="ofpOpenPayModal(<?php echo (int) $l->id; ?>, '<?php echo esc_js( $l->tenant_name ); ?>', <?php echo (float) $l->balance; ?>)">
                                                            Record Cash
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ( in_array( $l->status, [ 'active', 'completed', 'expired' ], true ) ) : ?>
                                                        <button type="button" class="ofp-btn ofp-btn-sm" style="background:var(--bg-body); color:var(--text-main); border:1px solid var(--border-color);" onclick="ofpRenewLease(<?php echo (int) $l->property_id; ?>, '<?php echo esc_js( $l->tenant_name ); ?>', '<?php echo esc_js( $l->tenant_phone ); ?>', '<?php echo esc_js( $l->tenant_email ); ?>')">
                                                            Renew
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <!-- ══════════════════════════════════════════════════════════════ -->
            <!-- TAB 2: TENANTS DIRECTORY                                       -->
            <!-- ══════════════════════════════════════════════════════════════ -->
            <?php elseif ( $active_tab === 'tenants' ) : ?>
                <div class="ofp-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                        <h3 style="margin:0; font-size:16px; font-weight:600; color:var(--text-main);">Tenants Directory</h3>
                        <div>
                            <input type="text" id="ofp-tenant-search" placeholder="Search tenant..." style="padding:6px 12px; font-size:13px; border:1px solid var(--border-color); border-radius:8px; background:var(--bg-body); color:var(--text-main);" onkeyup="ofpFilterTable('ofp-tenant-search', 'ofp-tenants-table')">
                        </div>
                    </div>

                    <?php if ( empty( $tenants ) ) : ?>
                        <div style="text-align:center; padding:48px 16px; color:var(--text-muted);">
                            <p style="font-size:15px; font-weight:500; color:var(--text-main); margin-bottom:4px;">No tenants in directory</p>
                            <p style="font-size:13px; margin-bottom:16px;">Tenants are automatically cataloged when you issue a lease offer.</p>
                            <button type="button" onclick="ofpOpenModal('ofp-modal-offer')" class="ofp-btn ofp-btn-primary">Add Tenant via Lease Offer</button>
                        </div>
                    <?php else : ?>
                        <div class="ofp-table-responsive">
                            <table class="ofp-table" id="ofp-tenants-table">
                                <thead>
                                    <tr>
                                        <th>Tenant</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>Active Leases</th>
                                        <th>Total Paid</th>
                                        <th>Joined</th>
                                        <th style="text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $tenants as $t ) : ?>
                                        <tr>
                                            <td>
                                                <strong style="color:var(--text-main);"><?php echo esc_html( $t->full_name ); ?></strong>
                                            </td>
                                            <td><?php echo esc_html( $t->phone ); ?></td>
                                            <td><?php echo esc_html( $t->email ?: '—' ); ?></td>
                                            <td>
                                                <?php if ( (int) $t->active_leases > 0 ) : ?>
                                                    <span class="ofp-badge" style="background:rgba(16, 185, 129, 0.15); color:#10b981;"><?php echo (int) $t->active_leases; ?> Active</span>
                                                <?php else : ?>
                                                    <span style="color:var(--text-muted); font-size:12px;">0 Active</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong>NGN <?php echo esc_html( number_format( (float) $t->total_paid, 2 ) ); ?></strong>
                                            </td>
                                            <td>
                                                <small style="color:var(--text-muted);"><?php echo esc_html( date( 'd M Y', strtotime( $t->created_at ) ) ); ?></small>
                                            </td>
                                            <td style="text-align:right;">
                                                <div style="display:inline-flex; gap:6px;">
                                                    <?php if ( $t->phone ) : ?>
                                                        <a href="tel:<?php echo esc_attr( $t->phone ); ?>" class="ofp-btn ofp-btn-sm" style="background:var(--bg-body); color:var(--text-main); border:1px solid var(--border-color); text-decoration:none;">Call</a>
                                                    <?php endif; ?>
                                                    <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-primary" onclick="ofpRenewLease(0, '<?php echo esc_js( $t->full_name ); ?>', '<?php echo esc_js( $t->phone ); ?>', '<?php echo esc_js( $t->email ); ?>')">
                                                        New Lease
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <!-- ══════════════════════════════════════════════════════════════ -->
            <!-- TAB 3: PROPERTY RENT RATES                                     -->
            <!-- ══════════════════════════════════════════════════════════════ -->
            <?php elseif ( $active_tab === 'rates' ) : ?>
                <div class="ofp-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                        <div>
                            <h3 style="margin:0; font-size:16px; font-weight:600; color:var(--text-main);">Configured Rent Rates</h3>
                            <p style="color:var(--text-muted); font-size:13px; margin:4px 0 0;">Each rental property can offer multiple billing periods (e.g. Monthly or Yearly).</p>
                        </div>
                        <button type="button" onclick="ofpOpenModal('ofp-modal-rate')" class="ofp-btn ofp-btn-primary">
                            + Add Rate Option
                        </button>
                    </div>

                    <?php if ( empty( $rent_options ) ) : ?>
                        <div style="text-align:center; padding:48px 16px; color:var(--text-muted);">
                            <p style="font-size:15px; font-weight:500; color:var(--text-main); margin-bottom:4px;">No rental rates configured</p>
                            <p style="font-size:13px; margin-bottom:16px;">Define pricing rates on your rental properties so you can issue leases easily.</p>
                            <button type="button" onclick="ofpOpenModal('ofp-modal-rate')" class="ofp-btn ofp-btn-primary">Configure First Rate</button>
                        </div>
                    <?php else : ?>
                        <div class="ofp-table-responsive">
                            <table class="ofp-table">
                                <thead>
                                    <tr>
                                        <th>Property</th>
                                        <th>Period</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th style="text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $rent_options as $ro ) : ?>
                                        <tr>
                                            <td>
                                                <strong style="color:var(--text-main);"><?php echo esc_html( $ro->property_title ); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo esc_html( OFP_Property_Rent::period_label( (string) $ro->period, (int) $ro->custom_days ) ); ?>
                                            </td>
                                            <td>
                                                <strong style="color:var(--text-main);">NGN <?php echo esc_html( number_format( (float) $ro->amount, 2 ) ); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ( $ro->is_active ) : ?>
                                                    <span class="ofp-badge" style="background:rgba(16, 185, 129, 0.15); color:#10b981;">Active</span>
                                                <?php else : ?>
                                                    <span class="ofp-badge" style="background:rgba(148, 163, 184, 0.15); color:#94a3b8;">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <form method="post" style="display:inline;">
                                                    <?php wp_nonce_field( 'ofp_rent_toggle_option', 'ofp_rent_nonce' ); ?>
                                                    <input type="hidden" name="ofp_rent_action" value="toggle_option">
                                                    <input type="hidden" name="option_id" value="<?php echo esc_attr( $ro->id ); ?>">
                                                    <button type="submit" class="ofp-btn ofp-btn-sm" style="background:var(--bg-body); color:var(--text-main); border:1px solid var(--border-color);">
                                                        <?php echo $ro->is_active ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <!-- ══════════════════════════════════════════════════════════════ -->
            <!-- TAB 4: PAYMENT RECORDS                                         -->
            <!-- ══════════════════════════════════════════════════════════════ -->
            <?php elseif ( $active_tab === 'payments' ) : ?>
                <div class="ofp-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                        <h3 style="margin:0; font-size:16px; font-weight:600; color:var(--text-main);">Rent Payment Records</h3>
                        <div>
                            <input type="text" id="ofp-pay-search" placeholder="Search payment..." style="padding:6px 12px; font-size:13px; border:1px solid var(--border-color); border-radius:8px; background:var(--bg-body); color:var(--text-main);" onkeyup="ofpFilterTable('ofp-pay-search', 'ofp-payments-table')">
                        </div>
                    </div>

                    <?php if ( empty( $payments ) ) : ?>
                        <div style="text-align:center; padding:48px 16px; color:var(--text-muted);">
                            <p style="font-size:15px; font-weight:500; color:var(--text-main); margin-bottom:4px;">No rent payments logged yet</p>
                            <p style="font-size:13px;">Payments from Paystack Virtual Accounts, checkout links, and cash receipts appear here.</p>
                        </div>
                    <?php else : ?>
                        <div class="ofp-table-responsive">
                            <table class="ofp-table" id="ofp-payments-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Tenant</th>
                                        <th>Property</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                        <th style="text-align:right;">Receipt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $payments as $p_row ) : ?>
                                        <tr>
                                            <td>
                                                <small style="color:var(--text-muted);"><?php echo esc_html( date( 'd M Y, H:i', strtotime( $p_row->created_at ) ) ); ?></small>
                                            </td>
                                            <td>
                                                <strong style="color:var(--text-main);"><?php echo esc_html( $p_row->tenant_name ); ?></strong>
                                            </td>
                                            <td><?php echo esc_html( $p_row->property_title ?: 'Lease #' . $p_row->lease_id ); ?></td>
                                            <td>
                                                <strong style="color:#10b981;">NGN <?php echo esc_html( number_format( (float) $p_row->amount, 2 ) ); ?></strong>
                                            </td>
                                            <td>
                                                <span style="font-size:12px; text-transform:uppercase; font-weight:600; color:var(--text-muted);">
                                                    <?php echo esc_html( str_replace( '_', ' ', $p_row->payment_method ) ); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <code style="font-size:11px; background:var(--bg-body); padding:2px 6px; border-radius:4px;"><?php echo esc_html( $p_row->payer_reference ?: $p_row->gateway_reference ?: '—' ); ?></code>
                                            </td>
                                            <td>
                                                <?php if ( $p_row->status === 'successful' ) : ?>
                                                    <span class="ofp-badge" style="background:rgba(16, 185, 129, 0.15); color:#10b981;">Verified</span>
                                                <?php else : ?>
                                                    <span class="ofp-badge" style="background:rgba(245, 158, 11, 0.15); color:#f59e0b;"><?php echo esc_html( ucfirst( $p_row->status ) ); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <?php if ( $p_row->status === 'successful' ) : ?>
                                                    <?php $receipt_url = OFP_Property_Receipt_Viewer::get_receipt_url( (int) $p_row->id, 'rent' ); ?>
                                                    <a href="<?php echo esc_url( $receipt_url ); ?>" target="_blank" class="ofp-btn ofp-btn-sm" style="background:var(--bg-body); color:var(--text-main); border:1px solid var(--border-color); display:inline-flex; align-items:center; gap:5px; text-decoration:none;">
                                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                        Receipt
                                                    </a>
                                                <?php else : ?>
                                                    <span style="color:var(--text-muted); font-size:12px;">—</span>
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

    </div>
</div>

<?php if ( $can_rent ) : ?>
<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- MODAL 1: NEW LEASE OFFER                                           -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div id="ofp-modal-offer" class="ofp-modal">
    <div class="ofp-modal-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="margin:0; font-size:18px; font-weight:700; color:var(--text-main);">Create Lease Offer</h3>
            <button type="button" onclick="ofpCloseModal('ofp-modal-offer')" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:20px;">&times;</button>
        </div>

        <form method="post">
            <?php wp_nonce_field( 'ofp_rent_create_offer', 'ofp_rent_nonce' ); ?>
            <input type="hidden" name="ofp_rent_action" value="create_offer">

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Select Rental Property <span style="color:red;">*</span></label>
                <select name="property_id" id="ofp-offer-prop-id" required class="ofp-select" style="width:100%;" onchange="ofpUpdateRateOptionsForProperty(this.value)">
                    <option value="">— Choose property —</option>
                    <?php foreach ( $rental_properties as $rp ) : ?>
                        <option value="<?php echo esc_attr( $rp->id ); ?>"><?php echo esc_html( $rp->title ); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ( empty( $rental_properties ) ) : ?>
                    <small style="color:var(--accent-orange); display:block; margin-top:4px;">No rental properties found. Please ensure your property has listing type "Rent" or "Shortlet".</small>
                <?php endif; ?>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Select Rent Rate Option <span style="color:red;">*</span></label>
                <select name="rent_option_id" id="ofp-offer-rate-id" required class="ofp-select" style="width:100%;">
                    <option value="">— Select property first —</option>
                    <?php foreach ( $rent_options as $ro ) : ?>
                        <?php if ( ! $ro->is_active ) continue; ?>
                        <option value="<?php echo esc_attr( $ro->id ); ?>" data-property="<?php echo esc_attr( $ro->property_id ); ?>">
                            <?php echo esc_html( $ro->property_title . ' — ' . OFP_Property_Rent::period_label( $ro->period, (int) $ro->custom_days ) . ' — NGN ' . number_format( $ro->amount, 2 ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Tenant Full Name <span style="color:red;">*</span></label>
                    <input type="text" name="tenant_name" id="ofp-offer-tname" required class="ofp-input" placeholder="e.g. John Doe" style="width:100%;">
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Tenant Phone <span style="color:red;">*</span></label>
                    <input type="text" name="tenant_phone" id="ofp-offer-tphone" required class="ofp-input" placeholder="e.g. 08012345678" style="width:100%;">
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Tenant Email <span style="color:var(--text-muted); font-weight:normal;">(Recommended for online checkout &amp; receipts)</span></label>
                <input type="email" name="tenant_email" id="ofp-offer-temail" class="ofp-input" placeholder="tenant@example.com" style="width:100%;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Offer Expiry Date</label>
                <input type="date" name="offer_expires_at" class="ofp-input" value="<?php echo esc_attr( date( 'Y-m-d', strtotime( '+7 days' ) ) ); ?>" style="width:100%;">
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Lease Terms &amp; Conditions</label>
                <textarea name="terms_text" class="ofp-textarea" rows="3" style="width:100%;" placeholder="Specify any security deposits, utility arrangements, or notice period rules..."></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="ofpCloseModal('ofp-modal-offer')" class="ofp-btn" style="background:var(--bg-body); border:1px solid var(--border-color); color:var(--text-main);">Cancel</button>
                <button type="submit" class="ofp-btn ofp-btn-primary">Generate Offer Link</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- MODAL 2: ADD RENT RATE                                             -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div id="ofp-modal-rate" class="ofp-modal">
    <div class="ofp-modal-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="margin:0; font-size:18px; font-weight:700; color:var(--text-main);">Add Property Rent Rate</h3>
            <button type="button" onclick="ofpCloseModal('ofp-modal-rate')" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:20px;">&times;</button>
        </div>

        <form method="post">
            <?php wp_nonce_field( 'ofp_rent_save_option', 'ofp_rent_nonce' ); ?>
            <input type="hidden" name="ofp_rent_action" value="save_option">

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Rental Property <span style="color:red;">*</span></label>
                <select name="property_id" required class="ofp-select" style="width:100%;">
                    <option value="">— Select property —</option>
                    <?php foreach ( $rental_properties as $rp ) : ?>
                        <option value="<?php echo esc_attr( $rp->id ); ?>"><?php echo esc_html( $rp->title ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Billing Period <span style="color:red;">*</span></label>
                    <select name="period" id="ofp-rate-period-select" class="ofp-select" style="width:100%;" onchange="ofpToggleCustomDays(this.value)">
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="biannual">Biannual (6 Months)</option>
                        <option value="yearly">Yearly</option>
                        <option value="shortlet">Shortlet</option>
                        <option value="custom">Custom Days</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Amount (NGN) <span style="color:red;">*</span></label>
                    <input type="number" step="0.01" min="0.01" name="amount" required class="ofp-input" placeholder="e.g. 500000" style="width:100%;">
                </div>
            </div>

            <div id="ofp-custom-days-wrap" style="display:none; margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Number of Days</label>
                <input type="number" min="1" name="custom_days" class="ofp-input" placeholder="e.g. 14" style="width:100%;">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px;">
                <button type="button" onclick="ofpCloseModal('ofp-modal-rate')" class="ofp-btn" style="background:var(--bg-body); border:1px solid var(--border-color); color:var(--text-main);">Cancel</button>
                <button type="submit" class="ofp-btn ofp-btn-primary">Save Rent Rate</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- MODAL 3: RECORD CASH PAYMENT                                       -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div id="ofp-modal-payment" class="ofp-modal">
    <div class="ofp-modal-content">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="margin:0; font-size:18px; font-weight:700; color:var(--text-main);">Record Cash / Offline Payment</h3>
            <button type="button" onclick="ofpCloseModal('ofp-modal-payment')" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:20px;">&times;</button>
        </div>

        <form method="post">
            <?php wp_nonce_field( 'ofp_rent_cash_payment', 'ofp_rent_nonce' ); ?>
            <input type="hidden" name="ofp_rent_action" value="cash_payment">

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Select Lease <span style="color:red;">*</span></label>
                <select name="lease_id" id="ofp-pay-lease-id" required class="ofp-select" style="width:100%;" onchange="ofpUpdatePaymentMax(this)">
                    <option value="">— Select accepted lease —</option>
                    <?php foreach ( $leases as $l ) : ?>
                        <?php if ( (float) $l->balance <= 0 || ! $l->accepted_at ) continue; ?>
                        <option value="<?php echo esc_attr( $l->id ); ?>" data-balance="<?php echo esc_attr( $l->balance ); ?>">
                            <?php echo esc_html( $l->tenant_name . ' — ' . ( $l->property_title ?: 'Property #' . $l->property_id ) . ' (Due: NGN ' . number_format( $l->balance, 2 ) . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Payment Amount (NGN) <span style="color:red;">*</span></label>
                <input type="number" step="0.01" min="0.01" id="ofp-pay-amount" name="amount" required class="ofp-input" placeholder="Amount paid" style="width:100%;">
                <small id="ofp-pay-balance-hint" style="color:var(--text-muted); display:block; margin-top:4px;"></small>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Bank Ref / Receipt Number</label>
                <input type="text" name="reference" class="ofp-input" placeholder="e.g. CASH-001 or Bank Transfer Ref" style="width:100%;">
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px;">Notes / Memo</label>
                <textarea name="note" class="ofp-textarea" rows="2" style="width:100%;" placeholder="e.g. Paid in cash directly to landlord at property office."></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="ofpCloseModal('ofp-modal-payment')" class="ofp-btn" style="background:var(--bg-body); border:1px solid var(--border-color); color:var(--text-main);">Cancel</button>
                <button type="submit" class="ofp-btn ofp-btn-primary">Record Payment</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function ofpOpenModal(id) {
    var m = document.getElementById(id);
    if (m) m.style.display = 'flex';
}

function ofpCloseModal(id) {
    var m = document.getElementById(id);
    if (m) m.style.display = 'none';
}

function ofpCopyLink(inputId, btn) {
    var input = document.getElementById(inputId);
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(function() {
        var orig = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(function() { btn.textContent = orig; }, 2000);
    });
}

function ofpToggleCustomDays(period) {
    var wrap = document.getElementById('ofp-custom-days-wrap');
    if (wrap) wrap.style.display = (period === 'custom') ? 'block' : 'none';
}

function ofpUpdateRateOptionsForProperty(propertyId) {
    var select = document.getElementById('ofp-offer-rate-id');
    if (!select) return;
    var options = select.querySelectorAll('option');
    var foundFirst = false;
    options.forEach(function(opt) {
        if (!opt.value) return;
        var p = opt.getAttribute('data-property');
        if (!propertyId || p === propertyId) {
            opt.style.display = '';
            if (!foundFirst) {
                select.value = opt.value;
                foundFirst = true;
            }
        } else {
            opt.style.display = 'none';
        }
    });
    if (!foundFirst) select.value = '';
}

function ofpOpenPayModal(leaseId, tenantName, balance) {
    var select = document.getElementById('ofp-pay-lease-id');
    if (select) {
        select.value = leaseId;
        ofpUpdatePaymentMax(select);
    }
    var amountInput = document.getElementById('ofp-pay-amount');
    if (amountInput) amountInput.value = balance;
    ofpOpenModal('ofp-modal-payment');
}

function ofpUpdatePaymentMax(select) {
    var opt = select.options[select.selectedIndex];
    var bal = opt ? opt.getAttribute('data-balance') : 0;
    var amountInput = document.getElementById('ofp-pay-amount');
    var hint = document.getElementById('ofp-pay-balance-hint');
    if (bal && amountInput) {
        amountInput.max = bal;
        amountInput.value = bal;
        if (hint) hint.textContent = 'Outstanding balance: NGN ' + parseFloat(bal).toLocaleString(undefined, {minimumFractionDigits: 2});
    } else if (hint) {
        hint.textContent = '';
    }
}

function ofpRenewLease(propertyId, tenantName, tenantPhone, tenantEmail) {
    var propSelect = document.getElementById('ofp-offer-prop-id');
    if (propSelect && propertyId) {
        propSelect.value = propertyId;
        ofpUpdateRateOptionsForProperty(propertyId);
    }
    var nameInput = document.getElementById('ofp-offer-tname');
    if (nameInput) nameInput.value = tenantName || '';
    var phoneInput = document.getElementById('ofp-offer-tphone');
    if (phoneInput) phoneInput.value = tenantPhone || '';
    var emailInput = document.getElementById('ofp-offer-temail');
    if (emailInput) emailInput.value = tenantEmail || '';
    ofpOpenModal('ofp-modal-offer');
}

function ofpFilterTable(inputId, tableId) {
    var input = document.getElementById(inputId);
    var filter = input ? input.value.toLowerCase() : '';
    var table = document.getElementById(tableId);
    if (!table) return;
    var trs = table.getElementsByTagName('tr');
    for (var i = 1; i < trs.length; i++) {
        var text = trs[i].textContent || trs[i].innerText;
        trs[i].style.display = (text.toLowerCase().indexOf(filter) > -1) ? '' : 'none';
    }
}

// Close modals when clicking outside
window.addEventListener('click', function(e) {
    document.querySelectorAll('.ofp-modal').forEach(function(m) {
        if (e.target === m) m.style.display = 'none';
    });
});
</script>

<?php wp_footer(); ?>
</body>
</html>
