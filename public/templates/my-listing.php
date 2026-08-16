<?php
/**
 * Template: /my-listing
 * Client's property listing management page (v2.1).
 * Full implementation follows after the core CRM phases are complete.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

if ( ! OFP_Subscription::has_active( 'listing', $client->id ) ) {
    wp_safe_redirect( home_url( '/dashboard' ) );
    exit;
}

global $wpdb;
$properties = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ofp_properties
         WHERE client_id = %d
         ORDER BY created_at DESC",
        $client->id
    )
);

$status_badges = [
    'pending_upload' => '<span class="ofp-badge ofp-badge-yellow">Pending Upload</span>',
    'live'           => '<span class="ofp-badge ofp-badge-green">Live</span>',
    'taken'          => '<span class="ofp-badge ofp-badge-grey">Taken</span>',
    'expired'        => '<span class="ofp-badge ofp-badge-red">Expired</span>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Listing — OFast Pipeline</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <div class="ofp-page-header">
        <h1>My Listing</h1>
        <p>Your property listings on the OFast Pipeline directory.</p>
    </div>

    <div class="ofp-alert ofp-alert-info">
        📸 To add or update a listing, send your property details and photos to us directly
        and we will publish it to the directory within 24 hours.
    </div>

    <?php if ( empty( $properties ) ) : ?>
        <div class="ofp-card">
            <div class="ofp-empty" style="padding:48px;">
                <div class="ofp-empty-icon">🏠</div>
                <h3>No listings yet</h3>
                <p>Contact us to get your first property listed on the directory.</p>
            </div>
        </div>
    <?php else : ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;">
            <?php foreach ( $properties as $prop ) : ?>
                <div class="ofp-card" style="padding:0;overflow:hidden;">

                    <?php if ( $prop->featured_image ) : ?>
                        <div style="height:180px;overflow:hidden;background:#f1f5f9;">
                            <img src="<?php echo esc_url( $prop->featured_image ); ?>"
                                 alt="<?php echo esc_attr( $prop->title ); ?>"
                                 style="width:100%;height:100%;object-fit:cover;">
                        </div>
                    <?php else : ?>
                        <div style="height:120px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:36px;">🏠</div>
                    <?php endif; ?>

                    <div style="padding:20px;">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:8px;">
                            <h3 style="font-size:15px;font-weight:600;color:#0f172a;margin:0;line-height:1.3;">
                                <?php echo esc_html( $prop->title ); ?>
                            </h3>
                            <?php echo $status_badges[ $prop->status ] ?? esc_html( $prop->status ); ?>
                        </div>

                        <div style="font-size:13px;color:#6b7280;margin-bottom:8px;">
                            📍 <?php echo esc_html( $prop->location_text ?: '—' ); ?>
                        </div>

                        <?php if ( $prop->price ) : ?>
                            <div style="font-size:16px;font-weight:700;color:#1a73e8;margin-bottom:8px;">
                                NGN <?php echo esc_html( number_format( (float) $prop->price, 0 ) ); ?>
                                <?php if ( $prop->price_period && $prop->listing_type === 'rent' ) : ?>
                                    <span style="font-size:12px;font-weight:400;color:#9ca3af;">/ <?php echo esc_html( $prop->price_period ); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div style="display:flex;gap:12px;font-size:12px;color:#6b7280;">
                            <?php if ( $prop->bedrooms ) : ?>
                                <span>🛏 <?php echo esc_html( $prop->bedrooms ); ?> bed</span>
                            <?php endif; ?>
                            <?php if ( $prop->bathrooms ) : ?>
                                <span>🚿 <?php echo esc_html( $prop->bathrooms ); ?> bath</span>
                            <?php endif; ?>
                            <?php if ( $prop->property_type ) : ?>
                                <span>🏠 <?php echo esc_html( ucfirst( $prop->property_type ) ); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if ( $prop->is_featured ) : ?>
                            <div style="margin-top:10px;">
                                <span class="ofp-badge ofp-badge-orange">⭐ Featured</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
