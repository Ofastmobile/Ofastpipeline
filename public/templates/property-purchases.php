<?php
/**
 * Client portal: property purchases.
 *
 * Clients can create purchases for their own properties, using either an
 * existing property lead or an offline buyer. Buyers never receive a client
 * account from this flow.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

if ( ! OFP_Subscription::has_paid_plan( $client->id ) ) {
    wp_safe_redirect( add_query_arg( 'upgrade', 'sales', home_url( '/pricing' ) ) );
    exit;
}

global $wpdb;
$p = $wpdb->prefix;
$message = '';
$error = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_create_client_purchase'] ) ) {
    if ( ! wp_verify_nonce( $_POST['ofp_client_purchase_nonce'] ?? '', 'ofp_client_create_purchase' ) ) {
        $error = 'Security check failed. Please try again.';
    } else {
        $property_id = absint( $_POST['property_id'] ?? 0 );
        $buyer_source = sanitize_key( $_POST['buyer_source'] ?? 'offline' );
        $lead_id = absint( $_POST['lead_id'] ?? 0 );
        $buyer_name = sanitize_text_field( wp_unslash( $_POST['buyer_name'] ?? '' ) );
        $buyer_phone = sanitize_text_field( wp_unslash( $_POST['buyer_phone'] ?? '' ) );
        $buyer_email = sanitize_email( wp_unslash( $_POST['buyer_email'] ?? '' ) );
        $initial = max( 0.0, (float) ( $_POST['initial_payment'] ?? 0 ) );
        $installment_amount = max( 0.0, (float) ( $_POST['installment_amount'] ?? 0 ) );
        $installment_count = max( 0, absint( $_POST['installment_count'] ?? 0 ) );
        $amount_paid = max( 0.0, (float) ( $_POST['amount_paid'] ?? 0 ) );
        $payment_method = sanitize_key( $_POST['payment_method'] ?? 'bank_transfer' );
        $payment_reference = sanitize_text_field( wp_unslash( $_POST['payment_reference'] ?? '' ) );
        $frequency = sanitize_text_field( wp_unslash( $_POST['frequency'] ?? 'monthly' ) );
        $payment_type = sanitize_key( $_POST['payment_type'] ?? 'installment' );
        $is_full_payment = $payment_type === 'full_payment';

        $allowed_methods = [ 'bank_transfer', 'bank_deposit', 'cash', 'virtual_account', 'checkout', 'other' ];

        $property = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_properties WHERE id = %d AND listing_type IN ('sale','land') LIMIT 1",
            $property_id
        ) );

        if ( $is_full_payment && $property ) {
            $initial            = (float) $property->price;
            $installment_amount = 0.0;
            $installment_count  = 0;
            $frequency          = 'one_time';
        }

        if ( ! $property ) {
            $error = 'Please choose one of your sale properties.';
        } elseif ( (int) $property->client_id !== (int) $client->id ) {
            $error = 'You can only create purchases for your own properties.';
        } elseif ( 'taken' === $property->status ) {
            $error = 'This property has already been sold or is unavailable.';
        } elseif ( class_exists( 'OFP_Property_CPT' ) && OFP_Property_CPT::property_has_active_purchase( (int) $property->id ) ) {
            $error = 'This property already has an active or completed purchase.';

        } elseif ( ! $buyer_name || ! $buyer_phone ) {
            $error = 'Buyer name and phone are required.';
        } elseif ( $buyer_email && ! is_email( $buyer_email ) ) {
            $error = 'Buyer email is invalid.';
        } elseif ( ! $is_full_payment && $initial > (float) $property->price ) {
            $error = 'Initial payment cannot exceed the property price.';
        } elseif ( ! $is_full_payment && $initial < (float) $property->price && ( $installment_amount <= 0 || $installment_count <= 0 ) ) {
            $error = 'Installment amount and number of installments are required for partial payments.';
        } elseif ( ! $is_full_payment && $initial < (float) $property->price && abs( ( (float) $property->price - $initial ) - ( $installment_amount * $installment_count ) ) > 0.01 ) {
            $error = 'The installment schedule must exactly cover the remaining balance.';
        } elseif ( $amount_paid <= 0 ) {
            $error = 'Amount paid must be greater than zero.';
        } elseif ( ! in_array( $payment_method, $allowed_methods, true ) ) {
            $error = 'Invalid payment method.';
        } elseif ( empty( $_FILES['payment_receipt']['name'] ) || ! empty( $_FILES['payment_receipt']['error'] ) ) {
            $error = 'A payment receipt is required.';
        }

        if ( ! $error && $buyer_source === 'lead' ) {
            $lead = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$p}ofp_leads WHERE id = %d AND client_id = %d LIMIT 1",
                $lead_id,
                (int) $client->id
            ) );
            if ( ! $lead ) {
                $error = 'The selected lead was not found in your account.';
            } else {
                if ( ! $buyer_name ) $buyer_name = $lead->name;
                if ( ! $buyer_phone ) $buyer_phone = $lead->phone;
                if ( ! $buyer_email ) $buyer_email = $lead->email;
                if ( $lead->property_id && (int) $lead->property_id !== $property_id ) {
                    $error = 'The selected lead belongs to a different property.';
                }
            }
        }

        if ( ! $error ) {
            $purchase_id = OFP_Property_Purchase_Service::create([
                'property_id' => $property_id,
                'lead_id' => $buyer_source === 'lead' ? $lead_id : null,
                'buyer_name' => $buyer_name,
                'buyer_phone' => $buyer_phone,
                'buyer_email' => $buyer_email,
                'initial_payment' => $initial,
                'installment_amount' => $installment_amount,
                'installment_count' => $installment_count,
                'frequency' => $frequency,
                'payment_method' => $payment_method,
            ]);

            if ( is_wp_error( $purchase_id ) ) {
                $error = $purchase_id->get_error_message();
            } else {
                if ( $amount_paid > 0 && class_exists( 'OFP_Property_Payment_Record' ) ) {
                    $receipt = OFP_Property_Manual_Payment::store_receipt( $_FILES['payment_receipt'] );
                    if ( is_wp_error( $receipt ) ) {
                        $message = 'Purchase #' . (int) $purchase_id . ' created, but the receipt could not be saved: ' . $receipt->get_error_message();
                    } else {
                        $method_label = str_replace( '_', ' ', $payment_method );
                        $payment_id = OFP_Property_Payment_Record::create([
                            'purchase_id'    => (int) $purchase_id,
                            'payment_method' => 'manual',
                            'amount'         => $amount_paid,
                            'status'         => 'successful',
                            'payer_name'     => $buyer_name,
                            'payer_reference' => $payment_reference,
                            'note'           => 'Initial payment via ' . $method_label . ( $payment_reference ? ' (Ref: ' . $payment_reference . ')' : '' ),
                        ]);

                        if ( ! is_wp_error( $payment_id ) ) {
                            $wpdb->update(
                                "{$p}ofp_property_payments",
                                [ 'receipt_path' => $receipt['path'], 'receipt_mime' => $receipt['mime'], 'receipt_size' => $receipt['size'], 'updated_at' => current_time( 'mysql' ) ],
                                [ 'id' => (int) $payment_id ]
                            );
                            // create() only inserts the row; success() applies
                            // it to the schedule/balance and fires the buyer notification.
                            OFP_Property_Payment_Record::success( (int) $payment_id, (int) $client->id );
                        } else {
                            OFP_Property_Manual_Payment::delete_receipt( $receipt['path'] );
                        }
                        $message = 'Purchase #' . (int) $purchase_id . ' created successfully.';
                    }
                } else {
                    $message = 'Purchase #' . (int) $purchase_id . ' created successfully.';
                }
            }
        }
    }
}

$my_properties = $wpdb->get_results( $wpdb->prepare(
    "SELECT pr.id, pr.title, pr.price FROM {$p}ofp_properties pr
     LEFT JOIN {$p}postmeta pm_status ON pm_status.post_id = pr.wp_post_id AND pm_status.meta_key = 'ofp_status'
     WHERE pr.client_id = %d AND pr.listing_type IN ('sale','land') AND ( pr.status = 'live' OR pm_status.meta_value = 'live' )
     ORDER BY title ASC",
    (int) $client->id
) );

$my_leads = $wpdb->get_results( $wpdb->prepare(
    "SELECT id, name, phone, email, property_id FROM {$p}ofp_leads
     WHERE client_id = %d ORDER BY created_at DESC LIMIT 300",
    (int) $client->id
) );

// --- Log a manual payment against an existing purchase ---
$log_payment_error = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_log_manual_payment'] ) ) {
    $log_purchase_id = absint( $_POST['log_purchase_id'] ?? 0 );
    if ( ! wp_verify_nonce( $_POST['ofp_log_payment_nonce'] ?? '', 'ofp_log_manual_payment_' . $log_purchase_id ) ) {
        $log_payment_error = 'Security check failed. Please try again.';
    } else {
        $log_purchase = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, buyer_name FROM {$p}ofp_property_purchases WHERE id = %d AND client_id = %d LIMIT 1",
            $log_purchase_id,
            (int) $client->id
        ) );
        $log_amount = max( 0.0, (float) ( $_POST['log_amount'] ?? 0 ) );
        $log_reference = sanitize_text_field( wp_unslash( $_POST['log_reference'] ?? '' ) );
        $log_note = sanitize_textarea_field( wp_unslash( $_POST['log_note'] ?? '' ) );

        if ( ! $log_purchase ) {
            $log_payment_error = 'Purchase not found.';
        } elseif ( $log_amount <= 0 ) {
            $log_payment_error = 'Enter a valid payment amount.';
        } elseif ( empty( $_FILES['log_receipt']['name'] ) || ! empty( $_FILES['log_receipt']['error'] ) ) {
            $log_payment_error = 'A payment receipt is required.';
        } else {
            $log_receipt = OFP_Property_Manual_Payment::store_receipt( $_FILES['log_receipt'] );
            if ( is_wp_error( $log_receipt ) ) {
                $log_payment_error = $log_receipt->get_error_message();
            } else {
                $log_payment_id = OFP_Property_Payment_Record::create([
                    'purchase_id'    => $log_purchase_id,
                    'payment_method' => 'manual',
                    'amount'         => $log_amount,
                    'status'         => 'successful',
                    'payer_name'     => $log_purchase->buyer_name,
                    'payer_reference' => $log_reference,
                    'note'           => $log_note,
                ]);
                if ( is_wp_error( $log_payment_id ) ) {
                    OFP_Property_Manual_Payment::delete_receipt( $log_receipt['path'] );
                    $log_payment_error = $log_payment_id->get_error_message();
                } else {
                    $wpdb->update(
                        "{$p}ofp_property_payments",
                        [ 'receipt_path' => $log_receipt['path'], 'receipt_mime' => $log_receipt['mime'], 'receipt_size' => $log_receipt['size'], 'updated_at' => current_time( 'mysql' ) ],
                        [ 'id' => (int) $log_payment_id ]
                    );
                    OFP_Property_Payment_Record::success( (int) $log_payment_id, (int) $client->id );
                    wp_safe_redirect( add_query_arg( [ 'purchase_id' => $log_purchase_id, 'payment_logged' => 1 ], home_url( '/property-purchases' ) ) );
                    exit;
                }
            }
        }
    }
}

// --- Purchase Details View ---
$detail_purchase_id = absint( $_GET['purchase_id'] ?? ( $_GET['purchase'] ?? 0 ) );
if ( ! $detail_purchase_id && ( isset( $_GET['purchase'] ) || isset( $_GET['purchase_id'] ) ) ) {
    $detail_purchase_id = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$p}ofp_property_purchases WHERE client_id = %d ORDER BY id DESC LIMIT 1",
        (int) $client->id
    ) );
}

if ( $detail_purchase_id ) {
    // Dynamic sync of amount_paid & balance from recorded payments and installments
    if ( class_exists( 'OFP_Property_Commerce' ) ) {
        OFP_Property_Commerce::sync_purchase_totals( $detail_purchase_id );
    }

    $detail_purchase = $wpdb->get_row( $wpdb->prepare(
        "SELECT pu.*, p.title AS property_title
         FROM {$p}ofp_property_purchases pu
         LEFT JOIN {$p}ofp_properties p ON p.id = pu.property_id
         WHERE pu.id = %d AND pu.client_id = %d LIMIT 1",
        $detail_purchase_id,
        (int) $client->id
    ) );
    if ( ! $detail_purchase ) {
        wp_die( 'Purchase not found or access denied.' );
    }

    $installments = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$p}ofp_property_installments WHERE purchase_id = %d ORDER BY installment_no ASC",
        $detail_purchase_id
    ) );

    $recorded_payments = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$p}ofp_property_payments WHERE purchase_id = %d ORDER BY created_at DESC",
        $detail_purchase_id
    ) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Purchase Details', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
</head>
<body class="ofp-portal-body">
<?php 
$ofp_banner_btn = [ 'label' => 'Add Purchase', 'url' => home_url( '/property-purchases' ) ];
include OFP_PATH . 'public/templates/partials/nav.php'; 
?>
<div class="ofp-container">
    <div style="padding-bottom: 60px;">
        <div style="margin: 0 0 16px; text-align: left;">
            <a href="<?php echo esc_url( home_url( '/property-sales' ) ); ?>" class="ofp-back-link-left">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" style="width:14px;height:14px;display:inline-block;vertical-align:middle;"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                <span>Back to Sales</span>
            </a>
        </div>

        <?php $ofp_sales_tab = 'purchase'; include OFP_PATH . 'public/templates/partials/sales-tabs.php'; ?>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap:20px; margin-bottom:24px;">
            <!-- Buyer Details Card -->
            <div class="ofp-card" style="padding:22px; display:flex; flex-direction:column; justify-content:space-between; border-radius:14px;">
                <div>
                    <?php
                        $detail_status_styles = [
                            'active'    => 'background:#dcfce7; color:#16a34a;',
                            'completed' => 'background:#dbeafe; color:#2563eb;',
                            'defaulted' => 'background:#fee2e2; color:#ef4444;',
                            'cancelled' => 'background:rgba(128,128,128,0.1); color:var(--text-muted);'
                        ];
                        $detail_style = $detail_status_styles[ $detail_purchase->status ] ?? 'background:rgba(128,128,128,0.1); color:var(--text-muted);';
                        $b_initials = strtoupper( substr( trim( $detail_purchase->buyer_name ?: 'B' ), 0, 2 ) );
                    ?>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; gap:12px; flex-wrap:wrap;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="width:42px; height:42px; border-radius:10px; background:linear-gradient(135deg, rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0.22)); color:#2563eb; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:15px; flex-shrink:0;">
                                <?php echo esc_html( $b_initials ); ?>
                            </div>
                            <div>
                                <h3 style="margin:0 0 2px; font-size:17px; font-weight:700; color:var(--text-main); letter-spacing:-0.01em;">
                                    <?php echo esc_html( $detail_purchase->buyer_name ); ?>
                                </h3>
                                <div style="font-size:12px; color:var(--text-muted); display:flex; align-items:center; gap:6px;">
                                    <span>Buyer Details</span>
                                    <span>&bull;</span>
                                    <span style="font-weight:600; color:var(--text-main);">Purchase #<?php echo esc_html( $detail_purchase->id ); ?></span>
                                </div>
                            </div>
                        </div>
                        <span style="font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; text-transform:uppercase; letter-spacing:0.04em; <?php echo esc_attr( $detail_style ); ?>">
                            <?php echo esc_html( ucfirst( $detail_purchase->status ) ); ?>
                        </span>
                    </div>

                    <div style="font-size:13px; color:var(--text-muted); margin:0 0 16px; line-height:1.45; padding:9px 12px; background:var(--bg-body); border-radius:8px; border:1px solid var(--border-color);">
                        Purchase details and installment payment schedule for Purchase #<?php echo esc_html( $detail_purchase->id ); ?>.
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div style="padding:10px 12px; background:var(--bg-body); border-radius:10px; border:1px solid var(--border-color);">
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); font-weight:600; margin-bottom:3px;">Phone Number</div>
                            <div style="font-size:13px; font-weight:600; color:var(--text-main); word-break:break-all;">
                                <a href="tel:<?php echo esc_attr( $detail_purchase->buyer_phone ); ?>" style="color:var(--text-main); text-decoration:none;">
                                    <?php echo esc_html( $detail_purchase->buyer_phone ?: '—' ); ?>
                                </a>
                            </div>
                        </div>

                        <div style="padding:10px 12px; background:var(--bg-body); border-radius:10px; border:1px solid var(--border-color);">
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); font-weight:600; margin-bottom:3px;">Email Address</div>
                            <div style="font-size:13px; font-weight:600; color:var(--text-main); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo esc_attr( $detail_purchase->buyer_email ); ?>">
                                <?php if ( $detail_purchase->buyer_email ) : ?>
                                    <a href="mailto:<?php echo esc_attr( $detail_purchase->buyer_email ); ?>" style="color:#2563eb; text-decoration:none;">
                                        <?php echo esc_html( $detail_purchase->buyer_email ); ?>
                                    </a>
                                <?php else : ?>
                                    <span style="color:var(--text-muted);">—</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:16px; padding-top:12px; border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; font-size:12px; color:var(--text-muted);">
                    <span>Enrollment Date</span>
                    <span style="font-weight:600; color:var(--text-main);"><?php echo esc_html( wp_date( 'M j, Y g:ia', strtotime( $detail_purchase->created_at ) ) ); ?></span>
                </div>
            </div>

            <!-- Property & Plan Overview Card -->
            <div class="ofp-card" style="padding:22px; display:flex; flex-direction:column; justify-content:space-between; border-radius:14px;">
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; gap:12px;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="width:42px; height:42px; border-radius:10px; background:linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(16, 185, 129, 0.22)); color:#10b981; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            </div>
                            <div>
                                <h3 style="margin:0 0 2px; font-size:17px; font-weight:700; color:var(--text-main); letter-spacing:-0.01em;">
                                    <?php echo esc_html( $detail_purchase->property_title ?: 'Selected Property' ); ?>
                                </h3>
                                <div style="font-size:12px; color:var(--text-muted);">
                                    Property &amp; Payment Plan
                                </div>
                            </div>
                        </div>
                        <?php if ( (int) $detail_purchase->installment_count === 0 || (float) $detail_purchase->initial_payment >= (float) $detail_purchase->total_price ) : ?>
                            <span style="font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; background:#dbeafe; color:#2563eb; text-transform:uppercase; letter-spacing:0.04em;">
                                Outright
                            </span>
                        <?php else : ?>
                            <span style="font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; background:rgba(128,128,128,0.1); color:var(--text-main); text-transform:uppercase; letter-spacing:0.04em;">
                                <?php echo (int) $detail_purchase->installment_count; ?> Installments
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Financial Metric Highlights -->
                    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; margin-bottom:14px;">
                        <div style="padding:10px 12px; background:var(--bg-body); border-radius:10px; border:1px solid var(--border-color);">
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); font-weight:600; margin-bottom:2px;">Total Price</div>
                            <div style="font-size:14px; font-weight:700; color:var(--text-main);">₦<?php echo esc_html( number_format( (float) $detail_purchase->total_price ) ); ?></div>
                        </div>

                        <div style="padding:10px 12px; background:rgba(16, 185, 129, 0.08); border-radius:10px; border:1px solid rgba(16, 185, 129, 0.2);">
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:#059669; font-weight:600; margin-bottom:2px;">Paid</div>
                            <div style="font-size:14px; font-weight:700; color:#059669;">₦<?php echo esc_html( number_format( (float) $detail_purchase->amount_paid ) ); ?></div>
                        </div>

                        <div style="padding:10px 12px; background:<?php echo (float)$detail_purchase->balance > 0 ? 'rgba(239, 68, 68, 0.06)' : 'var(--bg-body)'; ?>; border-radius:10px; border:1px solid <?php echo (float)$detail_purchase->balance > 0 ? 'rgba(239, 68, 68, 0.2)' : 'var(--border-color)'; ?>;">
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:<?php echo (float)$detail_purchase->balance > 0 ? '#dc2626' : 'var(--text-muted)'; ?>; font-weight:600; margin-bottom:2px;">Balance</div>
                            <div style="font-size:14px; font-weight:700; color:<?php echo (float)$detail_purchase->balance > 0 ? '#dc2626' : 'var(--text-main)'; ?>;">₦<?php echo esc_html( number_format( (float) $detail_purchase->balance ) ); ?></div>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <?php 
                        $pct_paid = (float) $detail_purchase->total_price > 0 ? min( 100, round( ((float) $detail_purchase->amount_paid / (float) $detail_purchase->total_price) * 100 ) ) : 0;
                    ?>
                    <div style="margin-bottom:8px;">
                        <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:6px;">
                            <span style="color:var(--text-muted); font-weight:500;">Payment Progress</span>
                            <span style="font-weight:700; color:var(--text-main);"><?php echo (int) $pct_paid; ?>% Paid</span>
                        </div>
                        <div style="height:7px; background:var(--bg-body); border-radius:99px; overflow:hidden; border:1px solid var(--border-color);">
                            <div style="height:100%; width:<?php echo (int) $pct_paid; ?>%; background:linear-gradient(90deg, #10b981, #059669); border-radius:99px; transition:width 0.4s ease;"></div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:14px; padding-top:12px; border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; font-size:12px; color:var(--text-muted);">
                    <span>Structure:</span>
                    <span style="font-weight:600; color:var(--text-main);">
                        <?php if ( (int) $detail_purchase->installment_count === 0 || (float) $detail_purchase->initial_payment >= (float) $detail_purchase->total_price ) : ?>
                            100% Full Payment (Outright)
                        <?php else : ?>
                            Initial ₦<?php echo esc_html( number_format( (float) $detail_purchase->initial_payment ) ); ?> &bull; <?php echo (int) $detail_purchase->installment_count; ?> &times; ₦<?php echo esc_html( number_format( (float) $detail_purchase->installment_amount ) ); ?> (<?php echo esc_html( ucfirst( $detail_purchase->frequency ) ); ?>)
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="ofp-card">
            <h3 style="margin:0 0 16px; font-size:16px;">Installment Schedule</h3>
            <?php if ( empty( $installments ) ) : ?>
                <p class="ofp-hint">No installments found for this purchase.</p>
            <?php else : ?>
                <div class="ofp-table-responsive">
                    <table class="ofp-table" style="width:100%;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Paid At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $installments as $ins ) : ?>
                                <tr>
                                    <td style="color:var(--text-muted);"><?php echo $ins->installment_no == 1 && (float) $detail_purchase->initial_payment > 0 ? '—' : esc_html( $ins->installment_no ); ?></td>
                                    <td style="color:var(--text-main);"><?php echo $ins->installment_no == 1 && (float) $detail_purchase->initial_payment > 0 ? 'Initial Payment' : 'Installment'; ?></td>
                                    <td style="color:var(--text-main); font-weight:500;">₦<?php echo esc_html( number_format( (float) $ins->amount_due, 2 ) ); ?></td>
                                    <td style="color:var(--text-main); font-size:13px;"><?php echo esc_html( wp_date( 'M j, Y', strtotime( $ins->due_date ) ) ); ?></td>
                                    <td>
                                        <?php
                                            $ins_styles = [
                                                'paid'           => 'background:#dcfce7; color:#16a34a;',
                                                'partially_paid' => 'background:#fef3c7; color:#d97706;',
                                                'due'            => 'background:#fef3c7; color:#d97706;',
                                                'scheduled'      => 'background:#dbeafe; color:#2563eb;',
                                                'overdue'        => 'background:#fee2e2; color:#ef4444;',
                                            ];
                                            $ins_style = $ins_styles[ $ins->status ] ?? 'background:rgba(128,128,128,0.1); color:var(--text-muted);';
                                        ?>
                                        <span style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:100px; <?php echo esc_attr($ins_style); ?>">
                                            <?php echo esc_html( ucfirst( str_replace( '_', ' ', $ins->status ) ) ); ?>
                                        </span>
                                    </td>
                                    <td style="color:var(--text-muted); font-size:13px;"><?php echo $ins->paid_at ? esc_html( wp_date( 'M j, Y', strtotime( $ins->paid_at ) ) ) : '—'; ?></td>
                                    <td>
                                        <?php if ( $ins->status !== 'paid' && class_exists( 'OFP_Property_Checkout' ) ) : ?>
                                            <input type="hidden" value="<?php echo esc_attr( OFP_Property_Checkout::payment_link( (int) $detail_purchase->id ) ); ?>">
                                            <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-ghost" style="padding:4px 10px; font-size:12px;" onclick="var link=this.previousElementSibling.value; if(navigator.clipboard &amp;&amp; navigator.clipboard.writeText){ navigator.clipboard.writeText(link); } else { prompt('Copy this payment link:', link); } var b=this, t=b.innerHTML; b.innerHTML='Copied!'; setTimeout(function(){ b.innerHTML=t; }, 2000);">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:13px;height:13px;display:inline-block;vertical-align:middle;margin-right:4px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" /></svg>Copy Link
                                            </button>
                                        <?php else : ?>
                                            <span style="color:var(--text-muted); font-size:13px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="ofp-card" style="margin-top:20px;">
            <h3 style="margin:0 0 16px; font-size:16px;">Payment History &amp; Receipts</h3>
            <?php if ( empty( $recorded_payments ) ) : ?>
                <p class="ofp-hint">No payments logged yet for this purchase.</p>
            <?php else : ?>
                <div class="ofp-table-responsive">
                    <table class="ofp-table" style="width:100%;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $recorded_payments as $idx => $pmt ) : ?>
                                <tr>
                                    <td style="color:var(--text-muted);"><?php echo (int) ( count( $recorded_payments ) - $idx ); ?></td>
                                    <td style="color:var(--text-main); font-weight:600;">₦<?php echo esc_html( number_format( (float) $pmt->amount, 2 ) ); ?></td>
                                    <td style="color:var(--text-main);"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $pmt->payment_method ) ) ); ?></td>
                                    <td style="color:var(--text-muted); font-size:13px;"><?php echo esc_html( $pmt->gateway_reference ?: ( $pmt->payer_reference ?: '—' ) ); ?></td>
                                    <td>
                                        <?php
                                            $pmt_styles = [
                                                'successful'           => 'background:#dcfce7; color:#16a34a;',
                                                'verified'             => 'background:#dcfce7; color:#16a34a;',
                                                'pending_verification' => 'background:#fef3c7; color:#d97706;',
                                                'failed'               => 'background:#fee2e2; color:#ef4444;',
                                                'rejected'             => 'background:#fee2e2; color:#ef4444;',
                                            ];
                                            $pmt_style = $pmt_styles[ $pmt->status ] ?? 'background:rgba(128,128,128,0.1); color:var(--text-muted);';
                                        ?>
                                        <span style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:100px; <?php echo esc_attr($pmt_style); ?>">
                                            <?php echo esc_html( $pmt->status === 'pending_verification' ? 'Pending' : ucfirst( str_replace( '_', ' ', $pmt->status ) ) ); ?>
                                        </span>
                                    </td>
                                    <td style="color:var(--text-muted); font-size:13px;"><?php echo esc_html( wp_date( 'M j, Y g:ia', strtotime( $pmt->created_at ) ) ); ?></td>
                                    <td>
                                        <?php if ( ! empty( $pmt->receipt_path ) && class_exists( 'OFP_Property_Receipt_Viewer' ) ) : ?>
                                            <a href="<?php echo esc_url( OFP_Property_Receipt_Viewer::get_receipt_url( (int) $pmt->id, 'purchase' ) ); ?>" onclick="event.preventDefault(); ofpOpenProofModal(this.href);" class="ofp-btn ofp-btn-sm ofp-btn-ghost" style="padding:4px 10px; font-size:12px;">
                                                View Receipt
                                            </a>
                                        <?php else : ?>
                                            <span style="color:var(--text-muted); font-size:13px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ( 'completed' !== $detail_purchase->status ) : ?>
        <div class="ofp-card" style="margin-top:20px;">
            <h3 style="margin:0 0 4px; font-size:16px;">Log Manual Payment</h3>
            <p class="ofp-hint">Record money you've already received from this buyer directly (cash, bank transfer to your own account, etc). A receipt is required, and this marks the payment as received immediately.</p>
            <?php if ( ! empty( $_GET['payment_logged'] ) ) : ?>
                <div class="ofp-alert ofp-alert-success" style="margin:12px 0;">Payment logged and applied successfully.</div>
            <?php endif; ?>
            <?php if ( $log_payment_error ) : ?>
                <div class="ofp-alert ofp-alert-error" style="margin:12px 0;"><?php echo esc_html( $log_payment_error ); ?></div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" style="margin-top:16px;">
                <?php wp_nonce_field( 'ofp_log_manual_payment_' . $detail_purchase->id, 'ofp_log_payment_nonce' ); ?>
                <input type="hidden" name="ofp_log_manual_payment" value="1">
                <input type="hidden" name="log_purchase_id" value="<?php echo esc_attr( $detail_purchase->id ); ?>">
                <div class="ofp-form-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                    <div class="ofp-field">
                        <label>Amount received</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="number" name="log_amount" id="ofp-log-amount" step="0.01" min="0.01" max="<?php echo esc_attr( $detail_purchase->balance ); ?>" required style="flex:1;">
                            <?php if ( (float) $detail_purchase->balance > 0 ) : ?>
                                <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-ghost" style="white-space:nowrap;padding:6px 12px;font-size:12px;font-weight:600;border:1px solid var(--border-light,#e2e8f0);border-radius:8px;" onclick="document.getElementById('ofp-log-amount').value='<?php echo esc_attr( number_format( (float) $detail_purchase->balance, 2, '.', '' ) ); ?>'">
                                    Pay Full Balance (₦<?php echo esc_html( number_format( (float) $detail_purchase->balance, 2 ) ); ?>)
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ofp-field">
                        <label>Reference <span class="ofp-hint" style="display:inline;margin:0;">(Optional)</span></label>
                        <input type="text" name="log_reference" placeholder="Bank transfer ref, etc." style="width:100%;">
                    </div>
                    <div class="ofp-field">
                        <label>Receipt <span class="ofp-hint" style="display:inline;margin:0;">(Required)</span></label>
                        <input type="file" name="log_receipt" accept="image/jpeg,image/png,application/pdf" required style="width:100%;">
                    </div>
                    <div class="ofp-field">
                        <label>Note <span class="ofp-hint" style="display:inline;margin:0;">(Optional)</span></label>
                        <input type="text" name="log_note" placeholder="e.g. Installment 3, paid in cash" style="width:100%;">
                    </div>
                </div>
                <div style="margin-top:16px;">
                    <button class="ofp-btn ofp-btn-primary" type="submit">Log Payment</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->
<?php wp_footer(); ?>
</body>
</html>
<?php
    return; // Stop here — don't render the main page below.
}

$my_properties = $wpdb->get_results( $wpdb->prepare(
    "SELECT pr.* FROM {$p}ofp_properties pr
     WHERE pr.client_id = %d
       AND pr.listing_type IN ('sale', 'land')
       AND pr.status != 'taken'
       AND NOT EXISTS (
           SELECT 1 FROM {$p}ofp_property_purchases pu
           WHERE pu.property_id = pr.id
             AND pu.status IN ('active', 'completed')
       )
     ORDER BY pr.title ASC",
    (int) $client->id
) ) ?: [];

$my_leads = $wpdb->get_results( $wpdb->prepare(
    "SELECT id, name, phone, email, property_id FROM {$p}ofp_leads WHERE client_id = %d ORDER BY id DESC",
    (int) $client->id
) ) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Property Purchases', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
</head>
<body class="ofp-portal-body">
<?php 
$ofp_banner_btn = [ 'label' => 'Add Purchase', 'url' => home_url( '/property-purchases' ) ];
include OFP_PATH . 'public/templates/partials/nav.php'; 
?>
<div class="ofp-container">
    <div style="padding-bottom: 60px;">
        <div style="margin: 0 0 16px; text-align: left;">
            <a href="<?php echo esc_url( home_url( '/property-sales' ) ); ?>" class="ofp-back-link-left">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" style="width:14px;height:14px;display:inline-block;vertical-align:middle;"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                <span>Back to Sales</span>
            </a>
        </div>

        <?php $ofp_sales_tab = 'purchase'; include OFP_PATH . 'public/templates/partials/sales-tabs.php'; ?>

        <?php if ( $error ) : ?><div class="ofp-alert ofp-alert-error" style="margin-bottom:24px;"><?php echo esc_html( $error ); ?></div><?php endif; ?>
        <?php if ( $message ) : ?><div class="ofp-alert ofp-alert-success" style="margin-bottom:24px;"><?php echo esc_html( $message ); ?></div><?php endif; ?>

        <div style="display:grid; grid-template-columns: 1fr; gap:24px;">
            <div class="ofp-card">
                <h3 style="margin-bottom:4px;">Create Purchase</h3>
                <p class="ofp-hint">Create a purchase for a buyer who has agreed to buy one of your sale properties. No buyer account is created.</p>

                <form method="post" enctype="multipart/form-data" style="margin-top:24px;">
                    <?php wp_nonce_field( 'ofp_client_create_purchase', 'ofp_client_purchase_nonce' ); ?>
                    <input type="hidden" name="ofp_create_client_purchase" value="1">

                    <div class="ofp-form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="ofp-field">
                            <label>Property</label>
                            <select name="property_id" id="ofp-purchase-property" required class="ofp-select" style="width:100%;">
                                <option value="" hidden>— Select sale property —</option>
                                <?php foreach ( $my_properties as $property ) : ?>
                                    <option value="<?php echo esc_attr( $property->id ); ?>" data-price="<?php echo esc_attr( (float) $property->price ); ?>">
                                        <?php echo esc_html( $property->title . ' — ₦' . number_format( (float) $property->price, 2 ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ofp-field">
                            <label>Payment Type</label>
                            <select name="payment_type" id="ofp-purchase-payment-type" class="ofp-select" style="width:100%;">
                                <option value="installment">Installment</option>
                                <option value="full_payment">Full Payment</option>
                            </select>
                        </div>
                        <div class="ofp-field">
                            <label>Buyer Source</label>
                            <select name="buyer_source" id="ofp-buyer-source" class="ofp-select" style="width:100%;">
                                <option value="offline">Offline Buyer</option>
                                <option value="lead">Existing Lead</option>
                            </select>
                        </div>
                        <div class="ofp-field" id="ofp-lead-wrap" style="display:none;">
                            <label>Existing Lead</label>
                            <select name="lead_id" id="ofp-lead-id" class="ofp-select" style="width:100%;">
                                <option value="" hidden>— Select lead —</option>
                                <?php foreach ( $my_leads as $lead ) : ?>
                                    <option value="<?php echo esc_attr( $lead->id ); ?>"><?php echo esc_html( ( $lead->name ?: 'Unnamed lead' ) . ' — ' . $lead->phone ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ofp-field">
                            <label>Buyer Name</label>
                            <input type="text" name="buyer_name" placeholder="Full name" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Buyer Phone</label>
                            <input type="text" name="buyer_phone" placeholder="Phone number" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Buyer Email <span class="ofp-hint" style="display:inline;margin:0;">(Optional)</span></label>
                            <input type="email" name="buyer_email" placeholder="Optional" style="width:100%;">
                        </div>
                        <div class="ofp-field ofp-purchase-full-field" style="display:none;">
                            <label>Amount Due (100% Full Payment)</label>
                            <input type="text" id="ofp-purchase-full-price" readonly style="width:100%;font-weight:600;background:rgba(0,0,0,0.03);" value="—">
                            <p class="ofp-hint">Outright purchase — buyer pays the property in full.</p>
                        </div>
                        <div class="ofp-field ofp-purchase-installment-field">
                            <label>Initial Payment (₦)</label>
                            <input type="number" step="0.01" min="0" name="initial_payment" id="ofp-purchase-initial" required style="width:100%;">
                        </div>
                        <div class="ofp-field ofp-purchase-installment-field">
                            <label>Installment Amount (₦)</label>
                            <input type="number" step="0.01" min="0" name="installment_amount" id="ofp-purchase-inst-amount" style="width:100%;">
                        </div>
                        <div class="ofp-field ofp-purchase-installment-field">
                            <label>Number of Installments</label>
                            <input type="number" min="1" name="installment_count" id="ofp-purchase-inst-count" style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Amount Paid (₦)</label>
                            <input type="number" step="0.01" min="0" name="amount_paid" id="ofp-purchase-amount-paid" required style="width:100%;">
                            <p class="ofp-hint">The amount already received (e.g. initial deposit or full payment).</p>
                        </div>
                        <div class="ofp-field">
                            <label>Payment Method</label>
                            <select name="payment_method" required class="ofp-select" style="width:100%;">
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="bank_deposit">Bank Deposit</option>
                                <option value="cash">Cash</option>
                                <option value="virtual_account">Virtual Account</option>
                                <option value="checkout">Checkout</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="ofp-field ofp-purchase-installment-field">
                            <label>Payment Frequency</label>
                            <select name="frequency" class="ofp-select" style="width:100%;">
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly" selected>Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                        <div class="ofp-field">
                            <label>Payment Reference <span class="ofp-hint" style="display:inline;margin:0;">(Optional)</span></label>
                            <input type="text" name="payment_reference" placeholder="Receipt number, transaction ID, etc." style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Payment Receipt <span class="ofp-hint" style="display:inline;margin:0;">(Required)</span></label>
                            <input type="file" name="payment_receipt" accept="image/jpeg,image/png,application/pdf" required style="width:100%;">
                            <p class="ofp-hint">Proof of the amount paid above. JPG, PNG or PDF, max 5 MB.</p>
                        </div>
                    </div>

                    <div style="margin-top:24px;">
                        <button class="ofp-btn ofp-btn-primary" type="submit">Create Purchase</button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>
</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->
<script>
(function(){
    var source = document.getElementById('ofp-buyer-source');
    var wrap = document.getElementById('ofp-lead-wrap');
    if (source && wrap) {
        function toggle(){ wrap.style.display = source.value === 'lead' ? 'block' : 'none'; }
        source.addEventListener('change', toggle); toggle();
    }

    var typeEl = document.getElementById('ofp-purchase-payment-type');
    var propEl = document.getElementById('ofp-purchase-property');
    var initialEl = document.getElementById('ofp-purchase-initial');
    var paidEl = document.getElementById('ofp-purchase-amount-paid');
    var instAmountEl = document.getElementById('ofp-purchase-inst-amount');
    var instCountEl = document.getElementById('ofp-purchase-inst-count');
    var fullPriceEl = document.getElementById('ofp-purchase-full-price');
    var instFields = document.querySelectorAll('.ofp-purchase-installment-field');
    var fullFields = document.querySelectorAll('.ofp-purchase-full-field');

    if (typeEl && propEl) {
        function updatePrice() {
            var sel = propEl.options[propEl.selectedIndex];
            var price = sel && sel.dataset.price ? parseFloat(sel.dataset.price) : 0;
            if (fullPriceEl) fullPriceEl.value = price > 0 ? '₦' + price.toLocaleString('en-US', {minimumFractionDigits: 2}) : '—';
            if (typeEl.value === 'full_payment' && price > 0) {
                if (initialEl) initialEl.value = price;
                if (paidEl) paidEl.value = price;
            }
        }

        function togglePurchaseType() {
            var isFull = typeEl.value === 'full_payment';
            instFields.forEach(function(el){ el.style.display = isFull ? 'none' : ''; });
            fullFields.forEach(function(el){ el.style.display = isFull ? '' : 'none'; });

            if (isFull) {
                if (initialEl) initialEl.removeAttribute('required');
                if (instAmountEl) { instAmountEl.value = '0'; instAmountEl.removeAttribute('required'); }
                if (instCountEl) { instCountEl.value = '0'; instCountEl.removeAttribute('required'); }
            } else {
                if (initialEl) initialEl.setAttribute('required', '');
                if (instAmountEl) instAmountEl.setAttribute('required', '');
                if (instCountEl) instCountEl.setAttribute('required', '');
            }
            updatePrice();
        }

        typeEl.addEventListener('change', togglePurchaseType);
        propEl.addEventListener('change', updatePrice);
        togglePurchaseType();
    }
})();

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

<!-- PROOF LIGHTBOX MODAL -->
<div id="ofp-modal-proof" class="ofp-payment-modal-backdrop" style="display:none; position:fixed; inset:0; width:100vw; height:100vh; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); z-index:999999; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;" onclick="if(event.target===this) ofpClosePaymentModal('ofp-modal-proof');">
    <div style="background:var(--bg-card, #ffffff); color:var(--text-main, #0f172a); width:100%; max-width:760px; max-height:90vh; border-radius:14px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); border:1px solid var(--border-color, #e2e8f0); overflow:hidden; box-sizing:border-box; display:flex; flex-direction:column;">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid var(--border-color, #e2e8f0); background:var(--bg-card, #ffffff);">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:36px; height:36px; border-radius:50%; background:#e0e7ff; color:#4338ca; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <div>
                    <h3 style="margin:0; font-size:16px; font-weight:700; color:var(--text-main, #0f172a);">Payment Proof</h3>
                    <div style="font-size:12px; color:var(--text-muted, #64748b);">Uploaded receipt / transfer proof preview</div>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:14px;">
                <a id="ofp-proof-external-link" href="#" target="_blank" rel="noopener" style="font-size:12px; font-weight:600; color:#2563eb; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                    <span>Open original</span> &nearr;
                </a>
                <button type="button" onclick="ofpClosePaymentModal('ofp-modal-proof')" style="background:none; border:none; font-size:24px; cursor:pointer; color:var(--text-muted, #94a3b8); line-height:1; padding:0;">&times;</button>
            </div>
        </div>
        <div style="padding:0; flex:1; min-height:480px; background:#0f172a; position:relative; overflow:hidden;">
            <iframe id="ofp-proof-iframe" src="about:blank" style="width:100%; height:100%; min-height:480px; border:none; display:block;"></iframe>
        </div>
        <div style="display:flex; justify-content:flex-end; padding:14px 20px; background:var(--bg-body, #f8fafc); border-top:1px solid var(--border-color, #e2e8f0);">
            <button type="button" onclick="ofpClosePaymentModal('ofp-modal-proof')" class="ofp-btn ofp-btn-sm" style="background:var(--bg-card, #ffffff); color:var(--text-main, #0f172a); border:1px solid var(--border-color, #cbd5e1); border-radius:8px; padding:7px 16px; font-weight:600; cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<?php wp_footer(); ?>
</body>
</html>
