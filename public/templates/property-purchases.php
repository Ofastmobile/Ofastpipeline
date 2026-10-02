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

        $allowed_methods = [ 'bank_transfer', 'bank_deposit', 'cash', 'virtual_account', 'checkout', 'other' ];

        $property = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}ofp_properties WHERE id = %d AND listing_type IN ('sale','land') LIMIT 1",
            $property_id
        ) );

        if ( ! $property ) {
            $error = 'Please choose one of your sale properties.';
        } elseif ( (int) $property->client_id !== (int) $client->id ) {
            $error = 'You can only create purchases for your own properties.';
        } elseif ( ! $buyer_name || ! $buyer_phone ) {
            $error = 'Buyer name and phone are required.';
        } elseif ( $buyer_email && ! is_email( $buyer_email ) ) {
            $error = 'Buyer email is invalid.';
        } elseif ( $initial > (float) $property->price ) {
            $error = 'Initial payment cannot exceed the property price.';
        } elseif ( $initial < (float) $property->price && ( $installment_amount <= 0 || $installment_count <= 0 ) ) {
            $error = 'Installment amount and number of installments are required for partial payments.';
        } elseif ( $initial < (float) $property->price && abs( ( (float) $property->price - $initial ) - ( $installment_amount * $installment_count ) ) > 0.01 ) {
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
$detail_purchase_id = absint( $_GET['purchase_id'] ?? 0 );
if ( $detail_purchase_id ) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Details — OFast Pipeline</title>
    <script>
        (function() {
            var currentTheme = localStorage.getItem('ofp_theme') || 'dark';
            if (currentTheme === 'light') { document.documentElement.setAttribute('data-theme', 'light'); }
        })();
    </script>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
</head>
<body class="ofp-portal-body">
<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>
<div class="ofp-container">
    <div style="padding-bottom: 60px;">
        <div style="margin: 0 0 24px;">
            <p style="margin:0 0 8px;"><a href="<?php echo esc_url( home_url( '/property-sales' ) ); ?>" style="color:var(--primary); text-decoration:none; font-size:14px;">← Back to Sales</a></p>
            <h1 style="font-size:22px; font-weight:700; color:var(--text-main); margin:0 0 8px; letter-spacing:-0.01em;">
                Purchase #<?php echo esc_html( $detail_purchase->id ); ?>
            </h1>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:24px;">
            <div class="ofp-card" style="padding:20px;">
                <h3 style="margin:0 0 12px; font-size:16px;">Buyer Details</h3>
                <p style="margin:6px 0; color:var(--text-main);"><strong>Name:</strong> <?php echo esc_html( $detail_purchase->buyer_name ); ?></p>
                <p style="margin:6px 0; color:var(--text-main);"><strong>Phone:</strong> <?php echo esc_html( $detail_purchase->buyer_phone ); ?></p>
                <p style="margin:6px 0; color:var(--text-main);"><strong>Email:</strong> <?php echo esc_html( $detail_purchase->buyer_email ?: '—' ); ?></p>
                <?php
                    $detail_status_styles = [
                        'active'    => 'background:#dcfce7; color:#16a34a;',
                        'completed' => 'background:#dbeafe; color:#2563eb;',
                        'defaulted' => 'background:#fee2e2; color:#ef4444;',
                        'cancelled' => 'background:rgba(128,128,128,0.1); color:var(--text-muted);'
                    ];
                    $detail_style = $detail_status_styles[ $detail_purchase->status ] ?? 'background:rgba(128,128,128,0.1); color:var(--text-muted);';
                ?>
                <p style="margin:6px 0;"><strong>Status:</strong> <span style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:100px; <?php echo esc_attr($detail_style); ?>"><?php echo esc_html( ucfirst( $detail_purchase->status ) ); ?></span></p>
                <p style="margin:6px 0; color:var(--text-muted); font-size:13px;"><strong>Created:</strong> <?php echo esc_html( wp_date( 'M j, Y', strtotime( $detail_purchase->created_at ) ) ); ?></p>
            </div>

            <div class="ofp-card" style="padding:20px;">
                <h3 style="margin:0 0 12px; font-size:16px;">Property & Plan</h3>
                <p style="margin:6px 0; color:var(--text-main);"><strong>Property:</strong> <?php echo esc_html( $detail_purchase->property_title ?: '—' ); ?></p>
                <p style="margin:6px 0; color:var(--text-main);"><strong>Total Price:</strong> NGN <?php echo esc_html( number_format( (float) $detail_purchase->total_price, 2 ) ); ?></p>
                <p style="margin:6px 0; color:var(--text-main);"><strong>Amount Paid:</strong> NGN <?php echo esc_html( number_format( (float) $detail_purchase->amount_paid, 2 ) ); ?></p>
                <p style="margin:6px 0; color:var(--text-main);"><strong>Balance:</strong> <strong>NGN <?php echo esc_html( number_format( (float) $detail_purchase->balance, 2 ) ); ?></strong></p>
                <p style="margin:6px 0; color:var(--text-main);"><strong>Initial Payment:</strong> NGN <?php echo esc_html( number_format( (float) $detail_purchase->initial_payment, 2 ) ); ?></p>
                <p style="margin:6px 0; color:var(--text-main);"><strong>Plan:</strong> <?php echo esc_html( $detail_purchase->installment_count ); ?> × NGN <?php echo esc_html( number_format( (float) $detail_purchase->installment_amount, 2 ) ); ?> (<?php echo esc_html( ucfirst( $detail_purchase->frequency ) ); ?>)</p>
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $installments as $ins ) : ?>
                                <tr>
                                    <td style="color:var(--text-muted);"><?php echo $ins->installment_no == 1 && (float) $detail_purchase->initial_payment > 0 ? '—' : esc_html( $ins->installment_no ); ?></td>
                                    <td style="color:var(--text-main);"><?php echo $ins->installment_no == 1 && (float) $detail_purchase->initial_payment > 0 ? 'Initial Payment' : 'Installment'; ?></td>
                                    <td style="color:var(--text-main); font-weight:500;">NGN <?php echo esc_html( number_format( (float) $ins->amount_due, 2 ) ); ?></td>
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
                        <input type="number" name="log_amount" step="0.01" min="0.01" max="<?php echo esc_attr( $detail_purchase->balance ); ?>" required style="width:100%;">
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
<?php wp_footer(); ?>
</body>
</html>
<?php
    return; // Stop here — don't render the main page below.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Purchases — OFast Pipeline</title>
    <!-- Dark theme script to avoid FOUC -->
    <script>
        (function() {
            var currentTheme = localStorage.getItem('ofp_theme') || 'dark';
            if (currentTheme === 'light') { document.documentElement.setAttribute('data-theme', 'light'); }
        })();
    </script>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
</head>
<body class="ofp-portal-body">
<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>
<div class="ofp-container">
    <div style="padding-bottom: 60px;">
        <div style="margin: 0 0 24px;">
            <p style="margin:0 0 8px;"><a href="<?php echo esc_url( home_url( '/property-sales' ) ); ?>" style="color:var(--primary); text-decoration:none; font-size:14px;">← Back to Sales</a></p>
            <h1 style="font-size:22px; font-weight:700; color:var(--text-main); margin:0 0 8px; letter-spacing:-0.01em;">
                Add Purchase
            </h1>
            <p style="color:#64748b; margin:0; font-size:14px;">Manage manual property purchases and installments for your buyers.</p>
        </div>

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
                            <select name="property_id" required class="ofp-select" style="width:100%;">
                                <option value="" hidden>— Select sale property —</option>
                                <?php foreach ( $my_properties as $property ) : ?>
                                    <option value="<?php echo esc_attr( $property->id ); ?>">
                                        <?php echo esc_html( $property->title . ' — NGN ' . number_format( (float) $property->price, 2 ) ); ?>
                                    </option>
                                <?php endforeach; ?>
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
                        <div class="ofp-field">
                            <label>Initial Payment (NGN)</label>
                            <input type="number" step="0.01" min="0" name="initial_payment" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Installment Amount (NGN)</label>
                            <input type="number" step="0.01" min="0" name="installment_amount" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Number of Installments</label>
                            <input type="number" min="1" name="installment_count" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Amount Paid (NGN)</label>
                            <input type="number" step="0.01" min="0" name="amount_paid" required style="width:100%;">
                            <p class="ofp-hint">The amount already received (e.g. initial deposit).</p>
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
                        <div class="ofp-field">
                            <label>Payment Frequency</label>
                            <select name="frequency" required class="ofp-select" style="width:100%;">
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
<script>
(function(){
    var source = document.getElementById('ofp-buyer-source');
    var wrap = document.getElementById('ofp-lead-wrap');
    if (!source || !wrap) return;
    function toggle(){ wrap.style.display = source.value === 'lead' ? 'block' : 'none'; }
    source.addEventListener('change', toggle); toggle();
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
