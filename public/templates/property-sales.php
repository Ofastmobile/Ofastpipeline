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
                    'frequency'          => 'monthly',
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
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">
<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>
<div class="ofp-container">
    <div class="ofp-page-header">
        <h1>Property Sales</h1>
        <p>Create installment offers for buyers and track the offers you have sent.</p>
    </div>

    <?php if ( $notice ) : ?>
        <div class="ofp-alert ofp-alert-success" style="margin-bottom:20px;">
            <?php echo esc_html( $notice ); ?>
            <?php if ( $share_url ) : ?>
                <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="text" readonly value="<?php echo esc_attr( $share_url ); ?>" style="max-width:600px;flex:1;padding:9px;border:1px solid #d1d5db;border-radius:6px;background:#fff;">
                    <button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">Copy Link</button>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( $error ) : ?>
        <div class="ofp-alert ofp-alert-error" style="margin-bottom:20px;"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>

    <div class="ofp-card" style="margin-bottom:24px;">
        <h2 style="margin-top:0;">Create Installment Offer</h2>
        <p style="color:#64748b;font-size:13px;">This creates an offer only. No payment or virtual account is created until the buyer accepts it.</p>

        <form method="post">
            <?php wp_nonce_field( 'ofp_property_offer_' . $client->id, 'ofp_property_offer_nonce' ); ?>
            <input type="hidden" name="ofp_create_property_offer" value="1">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;">
                <div>
                    <label>Property</label>
                    <select name="property_id" required style="width:100%;">
                        <option value="">Select property</option>
                        <?php foreach ( $properties as $property ) : ?>
                            <?php if ( $property->listing_type !== 'sale' ) continue; ?>
                            <option value="<?php echo esc_attr( $property->id ); ?>">
                                <?php echo esc_html( $property->title . ' — NGN ' . number_format( (float) $property->price, 0 ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Buyer name</label>
                    <input type="text" name="buyer_name" required style="width:100%;">
                </div>
                <div>
                    <label>Buyer phone</label>
                    <input type="text" name="buyer_phone" required style="width:100%;">
                </div>
                <div>
                    <label>Buyer email</label>
                    <input type="email" name="buyer_email" style="width:100%;">
                </div>
                <div>
                    <label>Initial payment</label>
                    <input type="number" step="0.01" min="0" name="initial_payment" value="0" required style="width:100%;">
                </div>
                <div>
                    <label>Monthly installment</label>
                    <input type="number" step="0.01" min="0" name="installment_amount" required style="width:100%;">
                </div>
                <div>
                    <label>Number of installments</label>
                    <input type="number" min="1" name="installment_count" required style="width:100%;">
                </div>
                <div>
                    <label>Payment starts</label>
                    <input type="date" name="payment_start_date" required style="width:100%;">
                </div>
                <div>
                    <label>First payment due date</label>
                    <input type="date" name="first_due_date" required style="width:100%;">
                </div>
                <div>
                    <label>Grace period (days)</label>
                    <input type="number" min="0" max="365" name="grace_period_days" value="7" required style="width:100%;">
                </div>
                <div>
                    <label>Offer expires</label>
                    <input type="date" name="offer_expires" style="width:100%;">
                </div>
            </div>

            <div style="margin-top:18px;">
                <label>Installment Terms / Agreement</label>
                <textarea name="terms_text" rows="8" style="width:100%;" placeholder="Enter the seller's payment, cancellation, default, refund and property-specific terms.&#10;&#10;The buyer will see these terms before accepting the offer."></textarea>
                <p style="font-size:12px;color:#64748b;">Use your approved business/legal wording. The accepted version will be stored with the purchase.</p>
            </div>

            <button type="submit" class="button button-primary" style="margin-top:16px;">Create Installment Offer</button>
        </form>
    </div>

    <div class="ofp-card">
        <h2 style="margin-top:0;">Recent Offers</h2>
        <?php if ( empty( $existing_offers ) ) : ?>
            <p style="color:#64748b;">No installment offers created yet.</p>
        <?php else : ?>
            <div style="overflow-x:auto;overflow-y:hidden;width:100%;-webkit-overflow-scrolling:touch;">
                <table class="widefat striped" style="min-width:1250px;">
                    <thead><tr><th>Buyer</th><th>Property</th><th>Amount</th><th>Plan</th><th>Payment Starts</th><th>First Due Date</th><th>Grace Period</th><th>Offer Expires</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                    <?php foreach ( $existing_offers as $offer ) : ?>
                        <tr>
                            <td><?php echo esc_html( $offer->buyer_name ); ?><br><small><?php echo esc_html( $offer->buyer_phone ); ?></small></td>
                            <td><?php echo esc_html( $offer->property_title ?: '—' ); ?></td>
                            <td>NGN <?php echo esc_html( number_format( (float) $offer->total_price, 2 ) ); ?></td>
                            <td>Initial NGN <?php echo esc_html( number_format( (float) $offer->initial_payment, 2 ) ); ?><br>× <?php echo esc_html( $offer->installment_count ); ?> monthly</td>
                            <td><?php echo $offer->payment_start_date ? esc_html( wp_date( 'M j, Y', strtotime( $offer->payment_start_date ) ) ) : '—'; ?></td>
                            <td><?php echo $offer->first_due_date ? esc_html( wp_date( 'M j, Y', strtotime( $offer->first_due_date ) ) ) : '—'; ?></td>
                            <td><?php echo esc_html( (int) $offer->grace_period_days ); ?> days</td>
                            <td><?php echo $offer->expires_at ? esc_html( wp_date( 'M j, Y', strtotime( $offer->expires_at ) ) ) : '—'; ?></td>
                            <td><?php echo esc_html( ucfirst( $offer->status ) ); ?></td>
                            <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $offer->created_at ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
