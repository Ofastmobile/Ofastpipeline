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

if ( ! OFP_Subscription::has_active( 'listing', $client->id ) ) {
    wp_safe_redirect( home_url( '/dashboard' ) );
    exit;
}

global $wpdb;
$p = $wpdb->prefix;
$notice = '';
$error  = '';
$share_url = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_create_property_offer'] ) ) {
    if ( ! isset( $_POST['ofp_property_offer_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ofp_property_offer_nonce'] ) ), 'ofp_property_offer_' . $client->id ) ) {
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
        } elseif ( $property->listing_type !== 'sale' ) {
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
            $error = 'The installment amount × number of installments must exactly cover the remaining balance of NGN ' . number_format( $remaining, 2 ) . '. Adjust the amount or count.';
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
    "SELECT id, title, price, location_text, listing_type FROM {$p}ofp_properties
     WHERE client_id = %d AND status IN ('live','pending_upload')
     ORDER BY created_at DESC",
    $client->id
) );

$existing_offers = $wpdb->get_results( $wpdb->prepare(
    "SELECT o.*, p.title AS property_title
     FROM {$p}ofp_property_offers o
     LEFT JOIN {$p}ofp_properties p ON p.id = o.property_id
     WHERE o.client_id = %d
     ORDER BY o.created_at DESC
     LIMIT 20",
    $client->id
) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Property Sales — OFast Pipeline</title>
    <!-- Dark theme script to avoid FOUC -->
    <script>
        (function() {
            var currentTheme = localStorage.getItem('ofp_theme') || 'dark';
            if (currentTheme === 'light') { document.documentElement.setAttribute('data-theme', 'light'); }
        })();
    </script>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
    <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
</head>
<body class="ofp-portal-body">
<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>
<div class="ofp-container">
    <div style="padding-bottom: 60px;">
        <div style="margin: 0 0 24px;">
            <h1 style="font-size:22px; font-weight:700; color:var(--text-main); margin:0 0 8px; letter-spacing:-0.01em;">
                Property Sales
            </h1>
            <p style="color:#64748b; margin:0; font-size:14px;">Create installment offers for buyers and track the offers you have sent.</p>
        </div>

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
            <div class="ofp-card">
                <h3 style="margin-bottom:4px;">Create Installment Offer</h3>
                <p class="ofp-hint">This creates an offer only. No payment or virtual account is created until the buyer accepts it.</p>

                <form method="post" style="margin-top:24px;">
                    <?php wp_nonce_field( 'ofp_property_offer_' . $client->id, 'ofp_property_offer_nonce' ); ?>
                    <input type="hidden" name="ofp_create_property_offer" value="1">

                    <div class="ofp-form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="ofp-field">
                            <label>Property</label>
                            <select name="property_id" required class="ofp-select" style="width:100%;">
                                <option value="" hidden>— Select property —</option>
                                <?php foreach ( $properties as $property ) : ?>
                                    <?php if ( $property->listing_type !== 'sale' ) continue; ?>
                                    <option value="<?php echo esc_attr( $property->id ); ?>">
                                        <?php echo esc_html( $property->title . ' — NGN ' . number_format( (float) $property->price, 0 ) ); ?>
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
                            <label>Initial Payment (NGN)</label>
                            <input type="number" step="0.01" min="0" name="initial_payment" value="0" required style="width:100%;">
                        </div>
                        <div class="ofp-field">
                            <label>Monthly Installment (NGN)</label>
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
                        <button type="submit" class="ofp-btn ofp-btn-primary">Create Installment Offer</button>
                    </div>
                </form>
            </div>

            <div class="ofp-card">
                <h3>Recent Offers</h3>
                <?php if ( empty( $existing_offers ) ) : ?>
                    <p class="ofp-hint">No installment offers created yet.</p>
                <?php else : ?>
                    <div class="ofp-table-responsive">
                        <table class="ofp-table" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>Buyer</th>
                                    <th>Property</th>
                                    <th>Amount</th>
                                    <th>Plan</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $existing_offers as $offer ) : ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 500; color: var(--text-main);"><?php echo esc_html( $offer->buyer_name ); ?></div>
                                        <div style="font-size: 12px; color: var(--text-muted);"><?php echo esc_html( $offer->buyer_phone ); ?></div>
                                    </td>
                                    <td style="color: var(--text-main);"><?php echo esc_html( $offer->property_title ?: '—' ); ?></td>
                                    <td style="color: var(--text-main);">NGN <?php echo esc_html( number_format( (float) $offer->total_price, 2 ) ); ?></td>
                                    <td style="color: var(--text-muted); font-size:13px;">
                                        Initial: NGN <?php echo esc_html( number_format( (float) $offer->initial_payment, 2 ) ); ?><br>
                                        <?php echo esc_html( $offer->installment_count ); ?> × NGN <?php echo esc_html( number_format( (float) $offer->installment_amount, 2 ) ); ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $status_styles = [
                                                'pending'  => 'background:#fef3c7; color:#d97706;',
                                                'accepted' => 'background:#dcfce7; color:#16a34a;',
                                                'rejected' => 'background:#fee2e2; color:#ef4444;',
                                                'expired'  => 'background:rgba(128,128,128,0.1); color:var(--text-muted);'
                                            ];
                                            $style = $status_styles[ $offer->status ] ?? 'background:rgba(128,128,128,0.1); color:var(--text-muted);';
                                        ?>
                                        <span style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:100px; <?php echo esc_attr($style); ?>">
                                            <?php echo esc_html( ucfirst( $offer->status ) ); ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right; color:var(--text-muted); font-size:13px;">
                                        <?php echo esc_html( wp_date( 'M j, Y', strtotime( $offer->created_at ) ) ); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
