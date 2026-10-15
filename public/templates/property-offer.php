<?php
/**
 * Secure buyer installment offer page.
 * Buyers do not need an OFastpipeline account.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$p = $wpdb->prefix;
$token = sanitize_text_field( wp_unslash( $_GET['offer'] ?? '' ) );
$token_hash = $token ? hash( 'sha256', $token ) : '';

$offer = $token_hash ? $wpdb->get_row( $wpdb->prepare(
    "SELECT o.*, p.title AS property_title, p.description AS property_description,
            p.location_text, p.featured_image, c.business_name, c.phone AS client_phone
     FROM {$p}ofp_property_offers o
     LEFT JOIN {$p}ofp_properties p ON p.id = o.property_id
     LEFT JOIN {$p}ofp_clients c ON c.id = o.client_id
     WHERE o.offer_token_hash = %s
     LIMIT 1",
    $token_hash
) ) : null;

$error = '';
$notice = '';

if ( ! $offer ) {
    $error = 'This offer link is invalid or no longer available.';
} elseif ( $offer->status === 'pending' && $offer->expires_at && strtotime( $offer->expires_at ) < current_time( 'timestamp' ) ) {
    $wpdb->update(
        "{$p}ofp_property_offers",
        [ 'status' => 'expired', 'updated_at' => current_time( 'mysql' ) ],
        [ 'id' => (int) $offer->id ]
    );
    $offer->status = 'expired';
} elseif ( $offer->status === 'pending' && class_exists( 'OFP_Property_CPT' ) && ( ! OFP_Property_CPT::is_property_available_for_sale( (int) $offer->property_id ) ) ) {
    $wpdb->update(
        "{$p}ofp_property_offers",
        [ 'status' => 'expired', 'updated_at' => current_time( 'mysql' ) ],
        [ 'id' => (int) $offer->id ]
    );
    $offer->status = 'expired';
    $error = 'This property is no longer available as it has already been sold or committed to another purchase.';
}

if ( $offer && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_offer_action'] ) ) {
    $nonce = sanitize_text_field( wp_unslash( $_POST['ofp_offer_nonce'] ?? '' ) );
    if ( ! wp_verify_nonce( $nonce, 'ofp_offer_action_' . $offer->id ) ) {
        $error = 'Security check failed. Please refresh the page and try again.';
    } elseif ( $offer->status !== 'pending' ) {
        $error = 'This offer can no longer be accepted or declined.';
    } else {
        $action = sanitize_key( $_POST['ofp_offer_action'] );

        if ( $action === 'decline' ) {
            $updated = $wpdb->update(
                "{$p}ofp_property_offers",
                [
                    'status' => 'declined',
                    'declined_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' ),
                ],
                [ 'id' => (int) $offer->id, 'status' => 'pending' ]
            );

            if ( $updated ) {
                $offer->status = 'declined';
                $notice = 'The offer has been declined. The seller can send a new offer later.';
            } else {
                $error = 'The offer could not be updated. Please try again.';
            }
        } elseif ( $action === 'accept' ) {
            $accept = isset( $_POST['accept_terms'] ) && $_POST['accept_terms'] === '1';
            if ( ! $accept ) {
                $error = 'Please confirm that you agree to the installment terms before accepting the offer.';
            } else {
                $purchase_id = OFP_Property_Commerce::create_purchase_from_offer(
                    (int) $offer->id,
                    isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''
                );

                if ( is_wp_error( $purchase_id ) ) {
                    $error = $purchase_id->get_error_message();
                } else {
                    wp_safe_redirect( OFP_Property_Checkout::payment_link( (int) $purchase_id ) );
                    exit;
                }
            }
        }
    }
}

$status_message = [
    'accepted' => 'This offer has already been accepted.',
    'declined' => 'This offer was declined. The seller may issue a new offer.',
    'expired' => 'This offer has expired. Please contact the seller for a new offer.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $offer ? 'Installment Offer — ' . $offer->property_title : 'Installment Offer' ); ?></title>
    <?php wp_head(); ?>
</head>
<body>
<div class="ofp-offer-wrap">
    <?php if ( $error ) : ?>
        <div class="ofp-alert ofp-alert-error"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>

    <?php if ( $notice ) : ?>
        <div class="ofp-alert ofp-alert-success"><?php echo esc_html( $notice ); ?></div>
    <?php endif; ?>

    <?php if ( $offer ) : ?>
        <div class="ofp-offer-card">
            <?php if ( $offer->featured_image ) : ?>
                <img class="ofp-offer-image" src="<?php echo esc_url( $offer->featured_image ); ?>" alt="<?php echo esc_attr( $offer->property_title ); ?>">
            <?php endif; ?>

            <div class="ofp-offer-body">
                <p class="ofp-muted" style="margin-top:0;">Installment Purchase Offer</p>
                <h1 style="margin:0 0 8px;"><?php echo esc_html( $offer->property_title ); ?></h1>
                <?php if ( $offer->location_text ) : ?>
                    <p class="ofp-muted">📍 <?php echo esc_html( $offer->location_text ); ?></p>
                <?php endif; ?>

                <div class="ofp-grid" style="margin:24px 0;">
                    <div class="ofp-stat"><span>Total property price</span><strong>NGN <?php echo esc_html( number_format( (float) $offer->total_price, 2 ) ); ?></strong></div>
                    <div class="ofp-stat"><span>Initial payment</span><strong>NGN <?php echo esc_html( number_format( (float) $offer->initial_payment, 2 ) ); ?></strong></div>
                    <div class="ofp-stat"><span>Monthly installment</span><strong>NGN <?php echo esc_html( number_format( (float) $offer->installment_amount, 2 ) ); ?></strong></div>
                    <div class="ofp-stat"><span>Number of installments</span><strong><?php echo esc_html( $offer->installment_count ); ?></strong></div>
                    <div class="ofp-stat"><span>Payments start</span><strong><?php echo esc_html( $offer->payment_start_date ? wp_date( 'M j, Y', strtotime( $offer->payment_start_date ) ) : '—' ); ?></strong></div>
                    <div class="ofp-stat"><span>First due date</span><strong><?php echo esc_html( $offer->first_due_date ? wp_date( 'M j, Y', strtotime( $offer->first_due_date ) ) : '—' ); ?></strong></div>
                    <div class="ofp-stat"><span>Grace period</span><strong><?php echo esc_html( $offer->grace_period_days ); ?> days</strong></div>
                </div>

                <?php if ( $offer->terms_text ) : ?>
                    <h2 style="font-size:20px;">Installment Terms & Agreement</h2>
                    <div class="ofp-terms"><?php echo wp_kses_post( wpautop( $offer->terms_text ) ); ?></div>
                <?php endif; ?>

                <?php if ( isset( $status_message[ $offer->status ] ) ) : ?>
                    <div class="ofp-alert ofp-alert-success" style="margin-top:20px;">
                        <?php echo esc_html( $status_message[ $offer->status ] ); ?>
                    </div>
                <?php endif; ?>

                <?php if ( $offer->status === 'pending' ) : ?>
                    <form method="post">
                        <?php wp_nonce_field( 'ofp_offer_action_' . $offer->id, 'ofp_offer_nonce' ); ?>
                        <label class="ofp-check">
                            <input type="checkbox" name="accept_terms" value="1">
                            <span>I have read and agree to the installment terms shown above and understand the payment schedule.</span>
                        </label>

                        <div class="ofp-actions">
                            <button class="ofp-btn ofp-btn-primary" type="submit" name="ofp_offer_action" value="accept">Accept Offer</button>
                            <button class="ofp-btn ofp-btn-danger" type="submit" name="ofp_offer_action" value="decline" onclick="return confirm('Decline this installment offer?');">Decline Offer</button>
                        </div>
                    </form>
                <?php endif; ?>

                <div style="margin-top:26px;font-size:13px;color:#64748b;">
                    Offer for: <?php echo esc_html( $offer->buyer_name ); ?>
                    <?php if ( $offer->business_name ) : ?>
                        · Seller: <?php echo esc_html( $offer->business_name ); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
