<?php
/**
 * Client portal: property installment offers and purchases.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

if ( ! OFP_Subscription::has_paid_plan( $client->id ) ) {
    wp_safe_redirect( add_query_arg( 'upgrade', 'sales', home_url( '/pricing' ) ) );
    exit;
}

$can_create_installments = OFP_Subscription::allows_installments( (int) $client->id );

global $wpdb;
$p = $wpdb->prefix;
$notice = '';
$error  = '';
$share_url = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_create_property_offer'] ) ) {
    if ( ! $can_create_installments ) {
        $error = 'Installment offers are available on the Gold plan. Upgrade to create new offers.';
    } elseif ( ! isset( $_POST['ofp_property_offer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ofp_property_offer_nonce'] ) ), 'ofp_property_offer_' . $client->id ) ) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $property_id        = absint( $_POST['property_id'] ?? 0 );
        $buyer_name         = sanitize_text_field( wp_unslash( $_POST['buyer_name'] ?? '' ) );
        $buyer_phone        = sanitize_text_field( wp_unslash( $_POST['buyer_phone'] ?? '' ) );
        $buyer_email        = sanitize_email( wp_unslash( $_POST['buyer_email'] ?? '' ) );
        $initial_payment    = max( 0.0, (float) ( $_POST['initial_payment'] ?? 0 ) );
        $installment_amount = max( 0.0, (float) ( $_POST['installment_amount'] ?? 0 ) );
        $installment_count  = max( 0, absint( $_POST['installment_count'] ?? 0 ) );
        $frequency          = sanitize_text_field( wp_unslash( $_POST['frequency'] ?? 'monthly' ) );
        $payment_start_date = sanitize_text_field( wp_unslash( $_POST['payment_start_date'] ?? '' ) );
        $first_due_date     = sanitize_text_field( wp_unslash( $_POST['first_due_date'] ?? '' ) );
        $grace_days         = min( 365, max( 0, absint( $_POST['grace_period_days'] ?? 7 ) ) );
        $expiry_date        = sanitize_text_field( wp_unslash( $_POST['offer_expires'] ?? '' ) );
        $terms_text         = wp_kses_post( wp_unslash( $_POST['terms_text'] ?? '' ) );

        $property = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_properties WHERE id = %d AND client_id = %d LIMIT 1",
            $property_id,
            $client->id
        ) );

        if ( ! $property ) {
            $error = 'Property not found or you do not have access to it.';
        } elseif ( ! OFP_Property_CPT::is_sale_like( (string) $property->listing_type ) ) {
            $error = 'Installment purchase offers can only be created for properties listed for sale.';
        } elseif ( ! $buyer_name || ! $buyer_phone ) {
            $error = 'Buyer name and phone are required.';
        } elseif ( ! is_email( $buyer_email ) && $buyer_email !== '' ) {
            $error = 'Please enter a valid buyer email or leave it blank.';
        } elseif ( (float) $property->price <= 0 ) {
            $error = 'This property does not have a valid sale price.';
        } elseif ( $initial_payment >= (float) $property->price ) {
            $error = 'Initial payment must be less than the total property price.';
        } elseif ( $installment_amount <= 0 || $installment_count <= 0 ) {
            $error = 'Installment amount and number of installments are required.';
        } elseif ( abs( ( (float) $property->price - $initial_payment ) - ( $installment_amount * $installment_count ) ) > 0.01 ) {
            $remaining = (float) $property->price - $initial_payment;
            $error = 'The installment amount × number of installments must exactly cover the remaining balance of ₦' . number_format( $remaining, 2 ) . '. Adjust the amount or count.';
        } elseif ( ! $payment_start_date || strtotime( $payment_start_date ) === false ) {
            $error = 'Please choose when payments will start.';
        } elseif ( ! $first_due_date || strtotime( $first_due_date ) === false ) {
            $error = 'Please choose the first payment due date.';
        } elseif ( strtotime( $first_due_date ) < strtotime( $payment_start_date ) ) {
            $error = 'First due date cannot be before the payment start date.';
        } else {
            [ $raw_token, $token_hash ] = OFP_Property_Commerce::create_offer_token();

            $inserted = $wpdb->insert(
                "{$p}ofp_property_offers",
                [
                    'property_id'        => (int) $property_id,
                    'client_id'          => (int) $client->id,
                    'buyer_name'         => $buyer_name,
                    'buyer_phone'        => $buyer_phone,
                    'buyer_email'        => $buyer_email ?: null,
                    'total_price'        => (float) $property->price,
                    'initial_payment'    => $initial_payment,
                    'installment_amount' => $installment_amount,
                    'frequency'          => $frequency,
                    'installment_count'  => $installment_count,
                    'payment_start_date' => $payment_start_date,
                    'first_due_date'     => $first_due_date,
                    'grace_period_days'  => $grace_days,
                    'reminder_days'      => '7,3,1',
                    'terms_text'         => $terms_text ?: null,
                    'terms_version'      => '1',
                    'offer_token_hash'   => $token_hash,
                    'status'             => 'pending',
                    'expires_at'         => $expiry_date ? $expiry_date . ' 23:59:59' : null,
                    'created_at'         => current_time( 'mysql' ),
                    'updated_at'         => current_time( 'mysql' ),
                ]
            );

            if ( $inserted ) {
                $share_url = add_query_arg( 'offer', rawurlencode( $raw_token ), home_url( '/property-offer' ) );
                $notice = 'Installment offer created. Send the secure offer link to the buyer. The buyer must accept the offer before payment setup begins.';
            } else {
                $error = 'The offer could not be created. Please try again.';
            }
        }
    }
}

$properties = $wpdb->get_results( $wpdb->prepare(
    "SELECT pr.id, pr.title, pr.price, pr.location_text, pr.listing_type FROM {$p}ofp_properties pr
     LEFT JOIN {$p}postmeta pm_status ON pm_status.post_id = pr.wp_post_id AND pm_status.meta_key = 'ofp_status'
     WHERE pr.client_id = %d AND pr.listing_type IN ('sale','land') AND ( pr.status = 'live' OR pm_status.meta_value = 'live' )
     ORDER BY pr.created_at DESC",
    $client->id
) );

$existing_offers = $wpdb->get_results( $wpdb->prepare(
    "SELECT o.*, p.title AS property_title
     FROM {$p}ofp_property_offers o
     LEFT JOIN {$p}ofp_properties p ON p.id = o.property_id
     WHERE o.client_id = %d AND o.status != 'accepted'
     ORDER BY o.created_at DESC
     LIMIT 20",
    $client->id
) );

$my_purchases = $wpdb->get_results( $wpdb->prepare(
    "SELECT pu.*, p.title AS property_title
     FROM {$p}ofp_property_purchases pu
     LEFT JOIN {$p}ofp_properties p ON p.id = pu.property_id
     WHERE pu.client_id = %d
     ORDER BY pu.created_at DESC LIMIT 100",
    (int) $client->id
) );

$total_sales_volume = 0;
$total_paid = 0;
$total_balance = 0;
$active_deals = 0;

foreach ( $my_purchases as $pu ) {
    $total_sales_volume += (float) $pu->total_price;
    $total_paid += (float) $pu->amount_paid;
    $total_balance += (float) $pu->balance;
    if ( $pu->status === 'active' ) {
        $active_deals++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Sales & Installments', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
    <style>
        html { scroll-behavior: smooth; }
    </style>
</head>
<body class="ofp-portal-body">
<?php 
$ofp_banner_btn = [ 'label' => 'Add Purchase', 'url' => home_url( '/property-purchases' ) ];
include OFP_PATH . 'public/templates/partials/nav.php'; 
?>
<div class="ofp-container">
    <div style="padding-bottom: 60px;">
        <div style="margin: 0 0 24px;">
            <h1 style="font-size:24px; font-weight:700; color:var(--text-main); margin:0 0 8px; letter-spacing:-0.01em;">
                Sales &amp; Installments
            </h1>
            <p style="color:var(--text-muted); margin:0; font-size:14px;">Every buyer deal in one place — pending offers awaiting a decision, and active or completed purchases with their paid amount and balance.</p>
        </div>

        <!-- ── Sales & Installments KPI Summary ────────────────────────── -->
        <div class="ofp-zentra-kpi-grid" style="margin-bottom:24px;">
            <div class="ofp-kpi-card ofp-kpi-card-hero">
                <div class="ofp-kpi-title">Total Sales Volume</div>
                <div class="ofp-kpi-value">₦<?php echo number_format($total_sales_volume); ?></div>
                <div class="ofp-kpi-badge badge-hero"><span><?php echo count($my_purchases); ?> Closed / Active Deals</span></div>
            </div>
            <div class="ofp-kpi-card">
                <div class="ofp-kpi-title">Revenue Collected</div>
                <div class="ofp-kpi-value">₦<?php echo number_format($total_paid); ?></div>
                <div class="ofp-kpi-badge badge-green"><span>Verified Payments</span></div>
            </div>
            <div class="ofp-kpi-card">
                <div class="ofp-kpi-title">Outstanding Balance</div>
                <div class="ofp-kpi-value">₦<?php echo number_format($total_balance); ?></div>
                <div class="ofp-kpi-badge badge-purple"><span>Installments Due</span></div>
            </div>
            <div class="ofp-kpi-card">
                <div class="ofp-kpi-title">Pending Offers</div>
                <div class="ofp-kpi-value"><?php echo count($existing_offers); ?></div>
                <div class="ofp-kpi-badge badge-blue"><span>Awaiting Buyer Sign</span></div>
            </div>
        </div>

        <?php 
            $active_tab = sanitize_key( $_GET['tab'] ?? 'sales' );
            if ( ! in_array( $active_tab, [ 'sales', 'offer' ], true ) ) {
                $active_tab = 'sales';
            }
            $ofp_sales_tab   = $active_tab;
            $ofp_deals_count = count( $my_purchases ) + count( $existing_offers );
            include OFP_PATH . 'public/templates/partials/sales-tabs.php'; 
        ?>

        <?php if ( $notice ) : ?>
            <div class="ofp-alert ofp-alert-success" style="margin-bottom:24px;">
                <?php echo esc_html( $notice ); ?>
                <?php if ( $share_url ) : ?>
                    <div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <input type="text" readonly value="<?php echo esc_attr( $share_url ); ?>" style="max-width:600px;flex:1;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;background:#f8fafc;color:#334155;font-size:14px;">
                        <button type="button" class="ofp-btn ofp-btn-primary" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">Copy Link</button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ( $error ) : ?>
            <div class="ofp-alert ofp-alert-error" style="margin-bottom:24px;"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns: 1fr; gap:24px;">
            <?php if ( $active_tab === 'sales' ) : ?>
            <!-- 1. Active Deals & Sales Portfolio Table (Front & Center) -->
            <div class="ofp-card" id="my-sales" style="padding:24px;">
                <div style="margin-bottom:20px;">
                    <h3 style="margin:0 0 4px;font-size:18px;font-weight:700;color:var(--text-main);">Active Deals &amp; Sales Portfolio</h3>
                    <p style="margin:0;font-size:13px;color:var(--text-muted);">Real-time tracking of buyer purchases, verification status, and installment schedules.</p>
                </div>

                <?php if ( empty( $existing_offers ) && empty( $my_purchases ) ) : ?>
                    <div style="text-align:center;padding:48px 24px;background:rgba(255,255,255,0.02);border:1px dashed var(--border-color);border-radius:14px;">
                        <div style="width:52px;height:52px;border-radius:50%;background:rgba(217,119,6,0.1);color:#d97706;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m1-3l1 3m6-3l1 3m-1-3l-1 3M3 10.5h18" /></svg>
                        </div>
                        <h4 style="margin:0 0 8px;font-size:16px;font-weight:700;color:var(--text-main);">No Active Sales or Installment Plans Yet</h4>
                        <p style="margin:0 auto 20px;max-width:460px;font-size:13.5px;color:var(--text-muted);line-height:1.5;">Start by generating an installment offer for an interested buyer, or record an existing off-platform transaction manually.</p>
                        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                            <a href="<?php echo esc_url( add_query_arg( 'tab', 'offer', home_url( '/property-sales' ) ) ); ?>" class="ofp-btn ofp-btn-primary" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;padding:9px 18px;text-decoration:none;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:15px;height:15px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                <span>Create Installment Offer</span>
                            </a>
                            <a href="<?php echo esc_url( home_url( '/property-purchases' ) ); ?>" class="ofp-btn ofp-btn-secondary" style="font-size:13px;padding:9px 18px;text-decoration:none;">
                                <span>Record Manual Purchase</span>
                            </a>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="ofp-table-responsive">
                        <table class="ofp-table" style="width: 100%; white-space: nowrap;">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Buyer</th>
                                    <th>Property</th>
                                    <th>Total</th>
                                    <th>Paid / Plan</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $my_purchases as $purchase ) :
                                $purchase_status_styles = [
                                    'active'    => 'background:#dbeafe; color:#2563eb;',
                                    'completed' => 'background:#dcfce7; color:#16a34a;',
                                    'defaulted' => 'background:#fee2e2; color:#ef4444;',
                                ];
                                $purchase_style = $purchase_status_styles[ $purchase->status ] ?? 'background:rgba(128,128,128,0.1); color:var(--text-muted);';
                            ?>
                                <tr>
                                    <td style="color:var(--text-muted); font-weight:600;">#<?php echo esc_html( $purchase->id ); ?></td>
                                    <td>
                                        <div style="font-weight: 600;">
                                            <a href="<?php echo esc_url( add_query_arg( 'purchase_id', $purchase->id, home_url( '/property-purchases' ) ) ); ?>" style="color:var(--primary); text-decoration:none;">
                                                <?php echo esc_html( $purchase->buyer_name ); ?>
                                            </a>
                                        </div>
                                        <div style="font-size: 12px; color: var(--text-muted);"><?php echo esc_html( $purchase->buyer_phone ); ?></div>
                                    </td>
                                    <td style="color:var(--text-main); font-weight:500;"><?php echo esc_html( $purchase->property_title ?: '—' ); ?></td>
                                    <td style="color:var(--text-main); font-weight:600;">₦<?php echo esc_html( number_format( (float) $purchase->total_price, 2 ) ); ?></td>
                                    <td>
                                        <?php $pct_paid = $purchase->total_price > 0 ? min(100, round(($purchase->amount_paid / $purchase->total_price) * 100)) : 0; ?>
                                        <div style="font-weight:600; color:var(--text-main);">₦<?php echo esc_html( number_format( (float) $purchase->amount_paid, 2 ) ); ?></div>
                                        <div style="width:110px; height:6px; background:rgba(0,0,0,0.08); border-radius:9999px; overflow:hidden; margin-top:5px;">
                                            <div style="width:<?php echo $pct_paid; ?>%; height:100%; background:#10b981; border-radius:9999px;"></div>
                                        </div>
                                        <span style="font-size:11px; color:var(--text-muted); font-weight:500;"><?php echo $pct_paid; ?>% completed</span>
                                    </td>
                                    <td><strong style="color:var(--text-main); font-size:14px;">₦<?php echo esc_html( number_format( (float) $purchase->balance, 2 ) ); ?></strong></td>
                                    <td>
                                        <span style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:100px; <?php echo esc_attr($purchase_style); ?>">
                                            <?php echo esc_html( ucfirst( $purchase->status ) ); ?>
                                        </span>
                                    </td>
                                    <td style="color: var(--text-muted); font-size:13px;"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $purchase->created_at ) ) ); ?></td>
                                    <td style="text-align:right;">
                                        <a href="<?php echo esc_url( add_query_arg( 'purchase_id', $purchase->id, home_url( '/property-purchases' ) ) ); ?>" class="ofp-btn ofp-btn-small" style="display:inline-flex;align-items:center;gap:4px;text-decoration:none;font-weight:600;">
                                            <span>View Schedule</span>
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:13px;height:13px;"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php foreach ( $existing_offers as $offer ) :
                                $offer_status_styles = [
                                    'pending'  => 'background:#fef3c7; color:#d97706;',
                                    'declined' => 'background:#fee2e2; color:#ef4444;',
                                    'expired'  => 'background:rgba(128,128,128,0.1); color:var(--text-muted);',
                                ];
                                $offer_style = $offer_status_styles[ $offer->status ] ?? 'background:rgba(128,128,128,0.1); color:var(--text-muted);';
                            ?>
                                <tr>
                                    <td style="color:var(--text-muted); font-weight:600;">#<?php echo esc_html( $offer->id ); ?></td>
                                    <td>
                                        <div style="font-weight: 600; color: var(--text-main);"><?php echo esc_html( $offer->buyer_name ); ?></div>
                                        <div style="font-size: 12px; color: var(--text-muted);"><?php echo esc_html( $offer->buyer_phone ); ?></div>
                                    </td>
                                    <td style="color: var(--text-main); font-weight:500;"><?php echo esc_html( $offer->property_title ?: '—' ); ?></td>
                                    <td style="color: var(--text-main); font-weight: 600;">₦<?php echo esc_html( number_format( (float) $offer->total_price, 2 ) ); ?></td>
                                    <td style="color: var(--text-muted); font-size:13px;">
                                        Initial: ₦<?php echo esc_html( number_format( (float) $offer->initial_payment, 2 ) ); ?><br>
                                        <?php echo esc_html( $offer->installment_count ); ?> × ₦<?php echo esc_html( number_format( (float) $offer->installment_amount, 2 ) ); ?>
                                    </td>
                                    <td style="color: var(--text-muted);">—</td>
                                    <td>
                                        <span style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:100px; <?php echo esc_attr($offer_style); ?>">
                                            <?php echo $offer->status === 'pending' ? 'Offer sent, waiting' : esc_html( ucfirst( $offer->status ) ); ?>
                                        </span>
                                    </td>
                                    <td style="color: var(--text-muted); font-size:13px;"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $offer->created_at ) ) ); ?></td>
                                    <td style="text-align:right;">
                                        <?php if ( ! empty( $offer->offer_token ) && $offer->status === 'pending' ) : ?>
                                            <input type="hidden" value="<?php echo esc_attr( add_query_arg( 'offer', rawurlencode( $offer->offer_token ), home_url( '/property-offer' ) ) ); ?>">
                                            <button type="button" class="ofp-btn ofp-btn-small" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);alert('Link copied!')" style="margin-right:4px;">Copy Link</button>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ofp_client_resend_offer&offer_id=' . $offer->id ), 'ofp_client_resend_offer' ) ); ?>" class="ofp-btn ofp-btn-small" style="background:var(--bg-card); color:var(--text-main); border:1px solid #dcdcde;">Resend</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php elseif ( $active_tab === 'offer' ) : ?>
            <!-- 2. Create Installment Offer Form Card -->
            <div class="ofp-card" id="create-offer" style="padding:24px;">
                <div style="margin-bottom:16px; text-align: left;">
                    <a href="<?php echo esc_url( home_url( '/property-sales' ) ); ?>" class="ofp-back-link-left">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" style="width:14px;height:14px;display:inline-block;vertical-align:middle;"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                        <span>Back to Sales</span>
                    </a>
                </div>
                <div style="margin-bottom:16px;">
                    <h3 style="margin:0 0 4px;font-size:18px;font-weight:700;color:var(--text-main);">Create Installment Offer</h3>
                    <p class="ofp-hint" style="margin:0;">This creates a secure offer link for the buyer. No virtual account is charged until they accept.</p>
                </div>

                <?php if ( ! $can_create_installments ) : ?>
                    <div style="padding:24px;background:rgba(217,119,6,0.06);border:1px solid rgba(217,119,6,0.25);border-radius:12px;margin-top:16px;">
                        <h4 style="margin:0 0 6px;color:var(--text-main);font-size:15px;font-weight:700;">Installment Offers Require Gold Plan</h4>
                        <p class="ofp-hint" style="margin:0 0 16px;">Flexible multi-installment deal creation with automated reminder schedules is a premium Gold feature. Existing deals stay visible above.</p>
                        <a href="<?php echo esc_url( home_url( '/pricing' ) ); ?>" class="ofp-btn ofp-btn-primary">Upgrade to Gold</a>
                    </div>
                <?php else : ?>

                <form method="post" style="margin-top:20px;">
                    <?php wp_nonce_field( 'ofp_property_offer_' . $client->id, 'ofp_property_offer_nonce' ); ?>
                    <input type="hidden" name="ofp_create_property_offer" value="1">

                    <div class="ofp-form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="ofp-field">
                            <label>Property</label>
                            <select name="property_id" required class="ofp-select" style="width:100%;">
                                <option value="" hidden>— Select property —</option>
                                <?php foreach ( $properties as $property ) : ?>
                                    <?php if ( ! OFP_Property_CPT::is_sale_like( (string) $property->listing_type ) ) continue; ?>
                                    <option value="<?php echo esc_attr( $property->id ); ?>">
                                        <?php echo esc_html( $property->title . ' — ₦' . number_format( (float) $property->price, 0 ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ofp-field">
                            <label>Buyer Name</label>
                            <input type="text" name="buyer_name" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Buyer Phone</label>
                            <input type="text" name="buyer_phone" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Buyer Email <span class="ofp-hint" style="display:inline;margin:0;">(Optional)</span></label>
                            <input type="email" name="buyer_email" style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Initial Payment (₦)</label>
                            <input type="number" step="0.01" min="0" name="initial_payment" value="0" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Monthly Installment (₦)</label>
                            <input type="number" step="0.01" min="0" name="installment_amount" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Number of Installments</label>
                            <input type="number" min="1" name="installment_count" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Payment Starts</label>
                            <input type="date" name="payment_start_date" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>First Payment Due Date</label>
                            <input type="date" name="first_due_date" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Grace Period (days)</label>
                            <input type="number" min="0" max="365" name="grace_period_days" value="7" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Payment Frequency</label>
                            <select name="frequency" required class="ofp-select" style="width:100%;">
                                <option value="weekly">Weekly</option>
                                <option value="monthly" selected>Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="biannually">Bi-annually</option>
                                <option value="annually">Annually</option>
                            </select>
                        </div>
                        <div class="ofp-field">
                            <label>Offer Expires <span class="ofp-hint" style="display:inline;margin:0;">(Optional)</span></label>
                            <input type="date" name="offer_expires" style="width:100%;">
                        </div>
                    </div>

                    <div class="ofp-field" style="margin-top:20px;">
                        <label>Installment Terms / Agreement</label>
                        <textarea name="terms_text" rows="6" style="width:100%;" placeholder="Enter the seller's payment, cancellation, default, refund and property-specific terms.&#10;&#10;The buyer will see these terms before accepting the offer."></textarea>
                        <p class="ofp-hint">Use your approved business/legal wording. The accepted version will be stored with the purchase.</p>
                    </div>

                    <div style="margin-top:24px;">
                        <button type="submit" class="ofp-btn ofp-btn-primary" style="padding:10px 24px;font-size:14px;font-weight:600;">Create Installment Offer</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->
<?php wp_footer(); ?>
</body>
</html>
