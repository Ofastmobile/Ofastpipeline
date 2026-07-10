<?php
/**
 * Template: /properties (client portal) — "My Properties" dashboard.
 *
 * Logged-in clients only.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client(); 

$error   = '';
$success = '';

$active_plan = OFP_Subscription::get_active_listing_plan( $client->id );
$plan_prices = OFP_Property_CPT::get_plan_prices();
$plan_caps   = OFP_Property_CPT::get_plan_caps();
$plan_labels = [ 'bronze' => 'Bronze', 'silver' => 'Silver', 'gold' => 'Gold' ];
$used_count  = OFP_Property_CPT::count_for_client( $client->id );

/* -----------------------------------------------------------
 * Handle: choose/change listing plan
 * --------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_choose_listing_plan'] ) ) {

    if ( ! wp_verify_nonce( $_POST['ofp_listing_plan_nonce'] ?? '', 'ofp_listing_plan_action' ) ) {
        $error = 'Security check failed — please try again.';
    } else {
        $chosen_plan = sanitize_text_field( $_POST['listing_plan'] ?? '' );

        if ( ! in_array( $chosen_plan, OFP_Property_CPT::PLAN_KEYS, true ) ) {
            $error = 'Please choose a valid plan.';
        } elseif ( $active_plan && OFP_Subscription::has_active( 'listing', $client->id ) ) {
            // Block submitting a new plan while one is already paid and active.
            // They can only change when the plan has expired.
            $error = 'Your listing plan is currently active. You can choose a different plan when it expires.';
        } else {
            OFP_Subscription::create( $client->id, 'listing', $chosen_plan );
            wp_safe_redirect( add_query_arg( 'success', 'plan', home_url( '/properties' ) ) );
            exit;
        }
    }
}

/* -----------------------------------------------------------
 * Handle: add or edit a property
 * --------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_save_property'] ) ) {

    if ( ! wp_verify_nonce( $_POST['ofp_property_nonce'] ?? '', 'ofp_save_property_action' ) ) {
        $error = 'Security check failed — please try again.';
    } else {

        OFP_Security::check_rate_limit( $_SERVER['REMOTE_ADDR'] ?? '', 'property_save', 10, 600 );

        $editing_id = (int) ( $_POST['property_id'] ?? 0 );
        $is_new     = $editing_id === 0;

        if ( ! $is_new && ! OFP_Property_CPT::is_owned_by( $editing_id, $client->id ) ) {
            $error = 'You do not have permission to edit that listing.';
        }
        elseif ( $is_new && ! OFP_Property_CPT::can_add_property( $client->id ) ) {
            $error = $active_plan
                ? 'You have reached your plan\'s property limit. Choose a higher plan to add more.'
                : 'Please choose a listing plan before adding a property.';
        }
        elseif ( empty( $_POST['title'] ) || empty( $_POST['price'] ) ) {
            $error = 'Title and price are required.';
        } else {

            $title         = sanitize_text_field( $_POST['title'] );
            $description   = sanitize_textarea_field( $_POST['description'] ?? '' );
            $price         = (float) $_POST['price'];
            $price_period  = sanitize_text_field( $_POST['price_period'] ?? 'year' );
            $listing_type  = in_array( $_POST['listing_type'] ?? '', [ 'sale', 'rent' ], true ) ? $_POST['listing_type'] : 'sale';
            $property_type = sanitize_text_field( $_POST['property_type'] ?? 'apartment' );
            $bedrooms      = (int) ( $_POST['bedrooms'] ?? 0 );
            $bathrooms     = (int) ( $_POST['bathrooms'] ?? 0 );
            $location_text = sanitize_text_field( $_POST['location_text'] ?? '' );
            $status        = in_array( $_POST['status'] ?? '', [ 'live', 'pending_upload', 'taken', 'expired' ], true )
                ? $_POST['status'] : 'pending_upload';

            $post_data = [
                'post_title'   => $title,
                'post_content' => $description,
                'post_type'    => 'ofp_property',
                'post_status'  => $is_new ? 'pending' : get_post_status( $editing_id ),
            ];

            if ( $is_new ) {
                $post_id = wp_insert_post( $post_data );
            } else {
                $post_data['ID'] = $editing_id;
                $post_id = wp_update_post( $post_data );
            }

            if ( is_wp_error( $post_id ) || ! $post_id ) {
                $error = 'Something went wrong saving your listing. Please try again.';
            } else {
                update_post_meta( $post_id, 'ofp_client_id', $client->id );
                update_post_meta( $post_id, 'ofp_price', $price );
                update_post_meta( $post_id, 'ofp_price_period', $price_period );
                update_post_meta( $post_id, 'ofp_listing_type', $listing_type );
                update_post_meta( $post_id, 'ofp_property_type', $property_type );
                update_post_meta( $post_id, 'ofp_bedrooms', $bedrooms );
                update_post_meta( $post_id, 'ofp_bathrooms', $bathrooms );
                update_post_meta( $post_id, 'ofp_location_text', $location_text );
                update_post_meta( $post_id, 'ofp_status', $status );

                // Photo upload
                if ( ! empty( $_FILES['photos']['name'][0] ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';

                    $gallery_ids = json_decode( get_post_meta( $post_id, 'ofp_gallery_ids', true ) ?: '[]', true );

                    $file_count = count( $_FILES['photos']['name'] );
                    for ( $i = 0; $i < $file_count; $i++ ) {
                        if ( empty( $_FILES['photos']['name'][ $i ] ) ) continue;

                        $single_file = [
                            'name'     => $_FILES['photos']['name'][ $i ],
                            'type'     => $_FILES['photos']['type'][ $i ],
                            'tmp_name' => $_FILES['photos']['tmp_name'][ $i ],
                            'error'    => $_FILES['photos']['error'][ $i ],
                            'size'     => $_FILES['photos']['size'][ $i ],
                        ];

                        $_FILES['ofp_single_photo'] = $single_file;
                        $attachment_id = media_handle_upload( 'ofp_single_photo', $post_id );

                        if ( ! is_wp_error( $attachment_id ) ) {
                            if ( ! has_post_thumbnail( $post_id ) ) {
                                set_post_thumbnail( $post_id, $attachment_id );
                            } else {
                                $gallery_ids[] = $attachment_id;
                            }
                        }
                    }

                    update_post_meta( $post_id, 'ofp_gallery_ids', json_encode( array_values( array_unique( $gallery_ids ) ) ) );
                }

                // Sync to custom table
                OFP_Property_CPT::sync_to_plugin_table( $post_id, $client->id );

                $success = $is_new
                    ? 'Your property has been submitted and is awaiting review — it will appear publicly once approved.'
                    : 'Your property has been updated.';

                $used_count = OFP_Property_CPT::count_for_client( $client->id );
                
                // POST-Redirect-GET to avoid form resubmission
                wp_safe_redirect( add_query_arg( 'success', 'saved', home_url( '/properties' ) ) );
                exit;
            }
        }
    }
}

/* -----------------------------------------------------------
 * Handle: delete a property
 * --------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_delete_property'] ) ) {

    if ( ! wp_verify_nonce( $_POST['ofp_delete_nonce'] ?? '', 'ofp_delete_property_action' ) ) {
        $error = 'Security check failed — please try again.';
    } else {
        $delete_id = (int) ( $_POST['property_id'] ?? 0 );

        if ( ! OFP_Property_CPT::is_owned_by( $delete_id, $client->id ) ) {
            $error = 'You do not have permission to delete that listing.';
        } else {
            wp_trash_post( $delete_id );
            
            // Note: Trashing a post doesn't automatically delete the custom table row right now, 
            // but the count_for_client ignores trashed posts anyway.
            // wpdb->delete could be run here if needed.

            wp_safe_redirect( add_query_arg( 'success', 'deleted', home_url( '/properties' ) ) );
            exit;
        }
    }
}

if ( isset($_GET['success']) ) {
    if ( $_GET['success'] === 'saved' ) $success = 'Property saved successfully.';
    if ( $_GET['success'] === 'deleted' ) $success = 'Property deleted.';
    if ( $_GET['success'] === 'plan' ) $success = 'Listing plan selected! Please transfer the plan amount to your virtual account to activate it.';
}

$my_properties  = OFP_Property_CPT::get_client_properties( $client->id );
$editing_post   = null;
if ( isset( $_GET['edit'] ) ) {
    $edit_id = (int) $_GET['edit'];
    if ( OFP_Property_CPT::is_owned_by( $edit_id, $client->id ) ) {
        $editing_post = get_post( $edit_id );
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Properties — OFast Pipeline</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">
    <?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-container">
        <div style="padding-bottom: 60px;">
            <h1 style="font-size:22px; font-weight:700; color:var(--text-main); margin:0 0 24px; letter-spacing:-0.01em;">
                My Properties
            </h1>
            <?php if ( $error ) : ?>
                <div class="ofp-alert ofp-alert-error"><?php echo esc_html( $error ); ?></div>
            <?php endif; ?>
            <?php if ( $success ) : ?>
                <div class="ofp-alert ofp-alert-success"><?php echo esc_html( $success ); ?></div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 1fr; gap:24px;">

                <!-- Plan status / picker -->
                <div class="ofp-card">
                    <?php
                    // Determine if client has a currently paid+active listing subscription.
                    $has_active_paid_plan = $active_plan && OFP_Subscription::has_active( 'listing', $client->id );
                    // Get the subscription end date for display.
                    $active_sub = OFP_Subscription::get_active( 'listing', $client->id );
                    $plan_expires = $active_sub ? $active_sub->period_end : null;
                    ?>

                    <?php if ( $has_active_paid_plan ) : ?>
                        <!-- Plan is currently PAID & ACTIVE: show status, no form -->
                        <h3>
                            <?php echo esc_html( $plan_labels[ $active_plan ] ); ?> Plan
                            <span style="background:#dcfce7; color:#16a34a; font-size:12px; font-weight:600; padding:3px 10px; border-radius:100px; vertical-align:middle; margin-left:8px;">Active</span>
                        </h3>
                        <p class="ofp-hint">
                            Using <?php echo esc_html( $used_count ); ?> of <?php echo esc_html( $plan_caps[ $active_plan ] ); ?> properties.
                            <?php if ( $plan_expires ) : ?>
                            &nbsp; Expires <strong><?php echo esc_html( date( 'd M Y', strtotime( $plan_expires ) ) ); ?></strong>.
                            <?php endif; ?>
                        </p>
                        <p class="ofp-hint" style="margin-top:8px;">
                            You can choose a different plan after your current plan expires. To top up or renew, use the <a href="<?php echo esc_url( home_url('/funding') ); ?>" style="color:#3b82f6;">Funding page</a>.
                        </p>

                    <?php elseif ( $active_plan && ! $has_active_paid_plan ) : ?>
                        <!-- Plan selected but PENDING payment (not yet paid) -->
                        <h3>
                            <?php echo esc_html( $plan_labels[ $active_plan ] ); ?> Plan
                            <span style="background:#fef3c7; color:#d97706; font-size:12px; font-weight:600; padding:3px 10px; border-radius:100px; vertical-align:middle; margin-left:8px;">Pending Payment</span>
                        </h3>
                        <p class="ofp-hint">
                            Your plan selection is awaiting payment. Please transfer <strong>NGN <?php echo esc_html( number_format( $plan_prices[ $active_plan ], 2 ) ); ?>/month</strong>
                            to your virtual account or company account, then notify us via the <a href="<?php echo esc_url( home_url('/funding') ); ?>" style="color:#3b82f6;">Funding page</a>.
                        </p>
                        <p class="ofp-hint" style="margin-top:12px;">Want to change your plan selection? Choose a different one below:</p>

                        <!-- Allow changing plan while still pending -->
                        <form method="POST" style="margin-top:16px; display:flex; flex-direction:column; gap:16px;">
                            <?php wp_nonce_field( 'ofp_listing_plan_action', 'ofp_listing_plan_nonce' ); ?>
                            <div style="display:flex; flex-direction:column; gap:12px;">
                                <?php foreach ( OFP_Property_CPT::PLAN_KEYS as $plan ) : ?>
                                    <label style="display:flex; align-items:center; gap:12px; padding:12px; border:1px solid var(--border-light); border-radius:8px; cursor:pointer;">
                                        <input type="radio" name="listing_plan" value="<?php echo esc_attr( $plan ); ?>" <?php checked( $active_plan, $plan ); ?>>
                                        <div style="display:flex; flex-direction:column; gap:4px;">
                                            <strong style="color:var(--text-dark);"><?php echo esc_html( $plan_labels[ $plan ] ); ?></strong>
                                            <span class="ofp-hint" style="margin:0;">Up to <?php echo esc_html( $plan_caps[ $plan ] ); ?> properties &mdash; NGN <?php echo esc_html( number_format( $plan_prices[ $plan ], 2 ) ); ?>/month</span>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div>
                                <button type="submit" name="ofp_choose_listing_plan" value="1" class="ofp-btn ofp-btn-primary">Change Selection</button>
                            </div>
                        </form>

                    <?php else : ?>
                        <!-- No plan yet: full picker shown -->
                        <h3>Choose a Listing Plan</h3>
                        <p class="ofp-hint">You need an active listing plan before you can add a property.</p>

                        <form method="POST" style="margin-top:20px; display:flex; flex-direction:column; gap:16px;">
                            <?php wp_nonce_field( 'ofp_listing_plan_action', 'ofp_listing_plan_nonce' ); ?>
                            <div style="display:flex; flex-direction:column; gap:12px;">
                                <?php foreach ( OFP_Property_CPT::PLAN_KEYS as $plan ) : ?>
                                    <label style="display:flex; align-items:center; gap:12px; padding:12px; border:1px solid var(--border-light); border-radius:8px; cursor:pointer;">
                                        <input type="radio" name="listing_plan" value="<?php echo esc_attr( $plan ); ?>">
                                        <div style="display:flex; flex-direction:column; gap:4px;">
                                            <strong style="color:var(--text-dark);"><?php echo esc_html( $plan_labels[ $plan ] ); ?></strong>
                                            <span class="ofp-hint" style="margin:0;">Up to <?php echo esc_html( $plan_caps[ $plan ] ); ?> properties &mdash; NGN <?php echo esc_html( number_format( $plan_prices[ $plan ], 2 ) ); ?>/month</span>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div>
                                <button type="submit" name="ofp_choose_listing_plan" value="1" class="ofp-btn ofp-btn-primary">Choose Plan</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Add / Edit property form -->
                <div class="ofp-card">
                    <h3><?php echo $editing_post ? 'Edit Property' : 'Add New Property'; ?></h3>

                    <?php if ( ! $editing_post && ! OFP_Property_CPT::can_add_property( $client->id ) ) : ?>
                        <p class="ofp-hint">
                            <?php echo $active_plan
                                ? 'You have reached your plan\'s property limit — choose a higher plan above to add more.'
                                : 'Choose a listing plan above to start adding properties.'; ?>
                        </p>
                    <?php else : ?>
                        <form method="POST" enctype="multipart/form-data" style="margin-top:20px;">
                            <?php wp_nonce_field( 'ofp_save_property_action', 'ofp_property_nonce' ); ?>
                            <?php if ( $editing_post ) : ?>
                                <input type="hidden" name="property_id" value="<?php echo esc_attr( $editing_post->ID ); ?>">
                            <?php endif; ?>

                            <div class="ofp-form-grid" style="grid-template-columns: 1fr 1fr; gap:16px;">
                                <div class="ofp-field" style="grid-column: 1 / -1;">
                                    <label>Title</label>
                                    <input type="text" name="title" required
                                           value="<?php echo esc_attr( $editing_post->post_title ?? '' ); ?>">
                                </div>

                                <div class="ofp-field" style="grid-column: 1 / -1;">
                                    <label>Description</label>
                                    <textarea name="description" rows="4"><?php echo esc_textarea( $editing_post->post_content ?? '' ); ?></textarea>
                                </div>
                                
                                <div class="ofp-field">
                                    <label>Listing Type</label>
                                    <select name="listing_type">
                                        <?php $current_ltype = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_listing_type', true ) : 'sale'; ?>
                                        <option value="sale" <?php selected( $current_ltype, 'sale' ); ?>>For Sale</option>
                                        <option value="rent" <?php selected( $current_ltype, 'rent' ); ?>>For Rent</option>
                                    </select>
                                </div>

                                <div class="ofp-field">
                                    <label>Property Type</label>
                                    <select name="property_type">
                                        <?php 
                                        $current_ptype = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_property_type', true ) : 'apartment'; 
                                        $types = [ 'apartment' => 'Apartment', 'duplex' => 'Duplex', 'bungalow' => 'Bungalow', 'terrace' => 'Terrace', 'land' => 'Land', 'office' => 'Office', 'shop' => 'Shop', 'warehouse' => 'Warehouse', 'other' => 'Other' ];
                                        foreach ( $types as $val => $label ) {
                                            echo '<option value="' . esc_attr($val) . '" ' . selected($current_ptype, $val, false) . '>' . esc_html($label) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="ofp-field">
                                    <label>Price (NGN)</label>
                                    <input type="number" step="0.01" name="price" required
                                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_price', true ) : '' ); ?>">
                                </div>
                                
                                <div class="ofp-field">
                                    <label>Price Period</label>
                                    <select name="price_period">
                                        <?php $current_period = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_price_period', true ) : 'year'; ?>
                                        <option value="year" <?php selected( $current_period, 'year' ); ?>>Per Year (Rent)</option>
                                        <option value="month" <?php selected( $current_period, 'month' ); ?>>Per Month (Rent)</option>
                                        <option value="one-time" <?php selected( $current_period, 'one-time' ); ?>>One-Time (Sale)</option>
                                    </select>
                                </div>

                                <div class="ofp-field">
                                    <label>Bedrooms</label>
                                    <input type="number" name="bedrooms"
                                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_bedrooms', true ) : '' ); ?>">
                                </div>

                                <div class="ofp-field">
                                    <label>Bathrooms</label>
                                    <input type="number" name="bathrooms"
                                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_bathrooms', true ) : '' ); ?>">
                                </div>

                                <div class="ofp-field" style="grid-column: 1 / -1;">
                                    <label>Location / Address</label>
                                    <input type="text" name="location_text" placeholder="e.g. Lekki Phase 1, Lagos"
                                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_location_text', true ) : '' ); ?>">
                                </div>

                                <?php if ( $editing_post ) : ?>
                                    <div class="ofp-field" style="grid-column: 1 / -1;">
                                        <label>Status</label>
                                        <?php $current_status = get_post_meta( $editing_post->ID, 'ofp_status', true ); ?>
                                        <select name="status">
                                            <option value="pending_upload" <?php selected( $current_status, 'pending_upload' ); ?>>Pending Upload</option>
                                            <option value="live" <?php selected( $current_status, 'live' ); ?>>Live</option>
                                            <option value="taken" <?php selected( $current_status, 'taken' ); ?>>Taken / Sold / Rented</option>
                                            <option value="expired" <?php selected( $current_status, 'expired' ); ?>>Expired</option>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <div class="ofp-field" style="grid-column: 1 / -1;">
                                    <label>Photos <span class="ofp-hint" style="display:inline;margin:0;">(first photo becomes the main image)</span></label>
                                    <input type="file" name="photos[]" accept="image/*" multiple style="font-size:14px; padding:10px 0;">
                                </div>
                            </div>

                            <div style="margin-top:24px;">
                                <button type="submit" name="ofp_save_property" value="1" class="ofp-btn ofp-btn-primary">
                                    <?php echo $editing_post ? 'Save Changes' : 'Submit Property'; ?>
                                </button>
                                <?php if ( $editing_post ) : ?>
                                    <a href="?_x=<?php echo rand(); ?>" class="ofp-btn" style="background:#f1f5f9; color:#475569; margin-left:12px; text-decoration:none;">Cancel Edit</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- My Properties list -->
                <div class="ofp-card">
                    <h3>Your Listings</h3>
                    <?php if ( empty( $my_properties ) ) : ?>
                        <p class="ofp-hint">You haven't added any properties yet.</p>
                    <?php else : ?>
                        <div style="overflow-x:auto;">
                            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
                                <thead>
                                    <tr style="border-bottom: 2px solid #e2e8f0; color: #64748b;">
                                        <th style="padding: 12px 16px;">Title</th>
                                        <th style="padding: 12px 16px;">Status</th>
                                        <th style="padding: 12px 16px;">Price</th>
                                        <th style="padding: 12px 16px; text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $my_properties as $property ) : ?>
                                        <tr style="border-bottom: 1px solid #f1f5f9;">
                                            <td style="padding: 12px 16px; color: #0f172a; font-weight: 500;"><?php echo esc_html( $property->post_title ); ?></td>
                                            <td style="padding: 12px 16px;">
                                                <?php 
                                                    $db_status = get_post_meta( $property->ID, 'ofp_status', true ) ?: 'pending_upload';
                                                    if ( $property->post_status === 'pending' ) {
                                                        echo '<span style="color:#f59e0b;font-weight:500;">Pending Review</span>';
                                                    } else {
                                                        $status_labels = [
                                                            'live' => '<span style="color:#10b981;">Live</span>',
                                                            'pending_upload' => '<span style="color:#f59e0b;">Draft</span>',
                                                            'taken' => '<span style="color:#64748b;">Taken</span>',
                                                            'expired' => '<span style="color:#ef4444;">Expired</span>',
                                                        ];
                                                        echo $status_labels[$db_status] ?? esc_html($db_status);
                                                    }
                                                ?>
                                            </td>
                                            <td style="padding: 12px 16px;">
                                                NGN <?php echo esc_html( number_format( (float) get_post_meta( $property->ID, 'ofp_price', true ), 2 ) ); ?>
                                            </td>
                                            <td style="padding: 12px 16px; text-align:right;">
                                                <a href="?edit=<?php echo esc_attr( $property->ID ); ?>" style="color:#3b82f6; text-decoration:none; margin-right:16px; font-weight:500;">Edit</a>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this property?');">
                                                    <?php wp_nonce_field( 'ofp_delete_property_action', 'ofp_delete_nonce' ); ?>
                                                    <input type="hidden" name="property_id" value="<?php echo esc_attr( $property->ID ); ?>">
                                                    <button type="submit" name="ofp_delete_property" value="1" style="background:none; border:none; color:#ef4444; font-weight:500; cursor:pointer; padding:0;">Delete</button>
                                                </form>
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
