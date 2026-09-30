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

$active_plan = OFP_Subscription::client_plan( $client->id );
$can_edit_properties = OFP_Subscription::has_paid_plan( $client->id );
$plan_prices = OFP_Property_CPT::get_plan_prices();
$plan_caps   = OFP_Property_CPT::get_plan_caps();
$plan_labels = [ 'free' => 'Free', 'silver' => 'Silver', 'gold' => 'Gold' ];
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
        } elseif ( OFP_Subscription::has_paid_plan( $client->id ) ) {
            $error = 'Your plan is currently active. Upgrade or renew from Funding.';
        } else {
            $plan_price = OFP_Property_CPT::get_plan_price( $chosen_plan );

            if ( $plan_price <= 0 ) {
                // Free tier (e.g. Bronze) — activate immediately, no checkout needed.
                OFP_Subscription::activate_free_tier( $client->id, 'listing', $chosen_plan );

                if ( class_exists( 'OFP_Notification' ) ) {
                    OFP_Notification::create(
                        $client->id,
                        'listing_plan_activated_free',
                        'Listing plan activated',
                        'Your ' . ucfirst( $chosen_plan ) . ' listing plan is now active — no payment required.'
                    );
                }

                wp_safe_redirect( add_query_arg( 'success', 'plan_free', home_url( '/properties' ) ) );
                exit;
            }

            OFP_Subscription::create( $client->id, 'listing', $chosen_plan );

            if ( class_exists( 'OFP_Notification' ) ) {
                OFP_Notification::create(
                    $client->id,
                    'listing_plan_selected',
                    'Listing plan selected — payment needed',
                    'You selected the ' . ucfirst( $chosen_plan ) . ' plan. Head to Funding to complete payment and activate it.'
                );
            }

            wp_safe_redirect( add_query_arg( 'success', 'plan', home_url( '/funding' ) ) );
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
        elseif ( ! $is_new && ! OFP_Subscription::has_paid_plan( $client->id ) ) {
            $error = 'Your listing plan has expired. Renew or choose a plan to edit your existing listings.';
        }
        elseif ( $is_new && ! OFP_Property_CPT::can_add_property( $client->id ) ) {
            $error = OFP_Subscription::has_paid_plan( $client->id )
                ? 'You have reached your plan\'s property limit. Choose a higher plan to add more.'
                : 'Upgrade your plan to add more properties, or you may have reached the Free plan limit of 1 listing.';
        }
        elseif ( empty( $_POST['title'] ) || empty( $_POST['price'] ) ) {
            $error = 'Title and price are required.';
        } else {

            $title         = sanitize_text_field( $_POST['title'] );
            $description   = sanitize_textarea_field( $_POST['description'] ?? '' );
            $price         = (float) $_POST['price'];
            $price_period  = sanitize_text_field( $_POST['price_period'] ?? 'year' );
            $listing_type  = in_array( $_POST['listing_type'] ?? '', [ 'sale', 'rent', 'shortlet', 'commercial' ], true ) ? $_POST['listing_type'] : 'sale';
            $property_type = sanitize_text_field( $_POST['property_type'] ?? 'apartment' );
            $bedrooms      = (int) ( $_POST['bedrooms'] ?? 0 );
            $bathrooms     = (int) ( $_POST['bathrooms'] ?? 0 );
            $location_text = sanitize_text_field( $_POST['location_text'] ?? '' );
            $status        = in_array( $_POST['status'] ?? '', [ 'live', 'pending_upload', 'taken', 'expired' ], true )
                ? $_POST['status'] : 'pending_upload';

            $can_feature = $active_plan !== 'free';
            $is_featured = ( $can_feature && isset( $_POST['is_featured'] ) ) ? '1' : '0';

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
                if ( $listing_type === 'sale' ) {
                    $price_period = 'sales';
                } elseif ( $price_period === 'one-time' ) {
                    $price_period = 'sales';
                }
                update_post_meta( $post_id, 'ofp_price_period', $price_period );
                update_post_meta( $post_id, 'ofp_listing_type', $listing_type );
                update_post_meta( $post_id, 'ofp_property_type', $property_type );
                update_post_meta( $post_id, 'ofp_bedrooms', $bedrooms );
                update_post_meta( $post_id, 'ofp_bathrooms', $bathrooms );
                update_post_meta( $post_id, 'ofp_location_text', $location_text );
                update_post_meta( $post_id, 'ofp_status', $status );
                update_post_meta( $post_id, 'ofp_is_featured', $is_featured );

                // Additional property detail fields
                $parking       = (int) ( $_POST['parking'] ?? 0 );
                $area_sqm      = (int) ( $_POST['area_sqm'] ?? 0 );
                $title_doc     = sanitize_text_field( $_POST['title_document'] ?? '' );
                $condition_val = sanitize_text_field( $_POST['condition'] ?? '' );
                $furnishing    = sanitize_text_field( $_POST['furnishing'] ?? '' );
                $video_url     = esc_url_raw( $_POST['video_url'] ?? '' );
                $amenities     = json_encode( array_map( 'sanitize_text_field', (array) ( $_POST['amenities'] ?? [] ) ) );

                update_post_meta( $post_id, 'ofp_parking', $parking );
                update_post_meta( $post_id, 'ofp_area_sqm', $area_sqm );
                update_post_meta( $post_id, 'ofp_title_document', $title_doc );
                update_post_meta( $post_id, 'ofp_condition', $condition_val );
                update_post_meta( $post_id, 'ofp_furnishing', $furnishing );
                update_post_meta( $post_id, 'ofp_video_url', $video_url );
                update_post_meta( $post_id, 'ofp_amenities', $amenities );

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
    if ( $_GET['success'] === 'plan_free' ) $success = 'Your free listing plan is active — you can add properties now.';
}

$my_properties  = OFP_Property_CPT::get_client_properties( $client->id );
$editing_post   = null;
$edit_blocked   = false;
if ( isset( $_GET['edit'] ) ) {
    $edit_id = (int) $_GET['edit'];
    if ( OFP_Property_CPT::is_owned_by( $edit_id, $client->id ) ) {
        if ( OFP_Subscription::has_paid_plan( $client->id ) ) {
            $editing_post = get_post( $edit_id );
        } else {
            $edit_blocked = true;
            $error = 'Your listing plan has expired. Renew or choose a plan to edit your existing listings.';
        }
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
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
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
                    $has_active_paid_plan = OFP_Subscription::has_paid_plan( $client->id );
                    $active_sub = OFP_Subscription::get_active( 'listing', $client->id )
                        ?: OFP_Subscription::get_active( 'crm', $client->id );
                    $plan_expires = $active_sub ? $active_sub->period_end : ( $client->subscription_expires ?? null );
                    ?>

                    <?php if ( $has_active_paid_plan ) : ?>
                        <h3>
                            <?php echo esc_html( $plan_labels[ $active_plan ] ?? ucfirst( $active_plan ) ); ?> Plan
                            <span style="background:#dcfce7; color:#16a34a; font-size:12px; font-weight:600; padding:3px 10px; border-radius:100px; vertical-align:middle; margin-left:8px;">Active</span>
                        </h3>
                        <p class="ofp-hint">
                            Using <?php echo esc_html( $used_count ); ?> of <?php echo esc_html( $plan_caps[ $active_plan ] ?? 1 ); ?> properties.
                            <?php if ( $plan_expires ) : ?>
                            &nbsp; Expires <strong><?php echo esc_html( date( 'd M Y', strtotime( $plan_expires ) ) ); ?></strong>.
                            <?php endif; ?>
                        </p>
                        <p class="ofp-hint" style="margin-top:8px;">
                            Upgrade or renew from the <a href="<?php echo esc_url( home_url('/funding') ); ?>" style="color:#3b82f6;">Funding page</a>
                            or see <a href="<?php echo esc_url( home_url('/pricing') ); ?>" style="color:#3b82f6;">Plans & Pricing</a>.
                        </p>

                    <?php else : ?>
                        <h3>
                            Free Plan
                            <span style="background:#e0e7ff; color:#4338ca; font-size:12px; font-weight:600; padding:3px 10px; border-radius:100px; vertical-align:middle; margin-left:8px;">Active</span>
                        </h3>
                        <p class="ofp-hint">
                            Using <?php echo esc_html( $used_count ); ?> of <?php echo esc_html( $plan_caps['free'] ?? 1 ); ?> properties.
                            CRM, leads, and 1 listing are included. Upgrade to Silver or Gold for more listings, templates, and team seats.
                        </p>
                        <p style="margin-top:16px;">
                            <a href="<?php echo esc_url( home_url( '/pricing' ) ); ?>" class="ofp-btn ofp-btn-primary">Upgrade plan</a>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Add / Edit property form -->
                <div class="ofp-card">
                    <h3><?php echo $editing_post ? 'Edit Property' : 'Add New Property'; ?></h3>

                    <?php if ( ! $editing_post && ! OFP_Property_CPT::can_add_property( $client->id ) ) : ?>
                        <p class="ofp-hint">
                            You have reached your plan's property limit.
                            <a href="<?php echo esc_url( home_url( '/pricing' ) ); ?>">Upgrade</a> to add more listings.
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
                                    <select name="listing_type" class="ofp-select">
                                        <option value="" hidden>— Select —</option>
                                        <?php $current_ltype = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_listing_type', true ) : 'sale'; ?>
                                        <option value="sale" <?php selected( $current_ltype, 'sale' ); ?>>For Sale</option>
                                        <option value="rent" <?php selected( $current_ltype, 'rent' ); ?>>For Rent</option>
                                        <option value="shortlet" <?php selected( $current_ltype, 'shortlet' ); ?>>Short Let</option>
                                        <option value="commercial" <?php selected( $current_ltype, 'commercial' ); ?>>Commercial</option>
                                    </select>
                                </div>

                                <div class="ofp-field">
                                    <label>Property Type</label>
                                    <select name="property_type" class="ofp-select">
                                        <option value="" hidden>— Select —</option>
                                        <?php 
                                        $current_ptype = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_property_type', true ) : 'apartment'; 
                                        $types = [ 'apartment' => 'Apartment', 'duplex' => 'Duplex', 'semi-detached' => 'Semi-Detached', 'bungalow' => 'Bungalow', 'terrace' => 'Terrace', 'land' => 'Land', 'office' => 'Office', 'shop' => 'Shop', 'warehouse' => 'Warehouse', 'other' => 'Other' ];
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
                                    <select name="price_period" class="ofp-select">
                                        <option value="" hidden>— N/A —</option>
                                        <?php 
                                        $current_period = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_price_period', true ) : 'year'; 
                                        if ( $current_period === 'one-time' ) $current_period = 'sales';
                                        ?>
                                        <option value="sales" <?php selected( $current_period, 'sales' ); ?>>Sales</option>
                                        <option value="year" <?php selected( $current_period, 'year' ); ?>>Per Year (Rent)</option>
                                        <option value="2years" <?php selected( $current_period, '2years' ); ?>>Per 2 Years (Rent)</option>
                                        <option value="month" <?php selected( $current_period, 'month' ); ?>>Per Month (Rent)</option>
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
                                        <select name="status" class="ofp-select">
                                            <option value="pending_upload" <?php selected( $current_status, 'pending_upload' ); ?>>Pending Upload</option>
                                            <option value="live" <?php selected( $current_status, 'live' ); ?>>Live</option>
                                            <option value="taken" <?php selected( $current_status, 'taken' ); ?>>Taken / Sold / Rented</option>
                                            <option value="expired" <?php selected( $current_status, 'expired' ); ?>>Expired</option>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $can_feature = $active_plan !== 'free';
                                $is_featured = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_is_featured', true ) : '0';
                                ?>
                                <div class="ofp-field" style="grid-column: 1 / -1;">
                                    <label>
                                        <input type="checkbox" name="is_featured" value="1"
                                            <?php checked( $is_featured, '1' ); ?>
                                            <?php disabled( ! $can_feature, true ); ?>>
                                        Featured Listing (Display at the top of search results)
                                        <?php if ( ! $can_feature ) echo '<span style="color:#ef4444;font-size:12px;display:block;margin-top:4px;">(Not available on Free plan)</span>'; ?>
                                    </label>
                                </div>

                                <div class="ofp-field" style="grid-column: 1 / -1;">
                                    <label>Photos <span class="ofp-hint" style="display:inline;margin:0;">(first photo becomes the main image)</span></label>
                                    <input type="file" name="photos[]" accept="image/*" multiple style="font-size:14px; padding:10px 0;">
                                </div>

                                <div class="ofp-field">
                                    <label>Parking Spaces</label>
                                    <input type="number" name="parking" min="0"
                                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_parking', true ) : '' ); ?>">
                                </div>

                                <div class="ofp-field">
                                    <label>Area (SQM)</label>
                                    <input type="number" name="area_sqm" min="0"
                                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_area_sqm', true ) : '' ); ?>">
                                </div>

                                <div class="ofp-field">
                                    <label>Title Document</label>
                                    <?php $current_title_doc = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_title_document', true ) : ''; ?>
                                    <select name="title_document" class="ofp-select">
                                        <option value="">-- Select --</option>
                                        <option value="C of O" <?php selected( $current_title_doc, 'C of O' ); ?>>C of O</option>
                                        <option value="Governor's Consent" <?php selected( $current_title_doc, "Governor's Consent" ); ?>>Governor's Consent</option>
                                        <option value="Gazette" <?php selected( $current_title_doc, 'Gazette' ); ?>>Gazette</option>
                                        <option value="Deed of Assignment" <?php selected( $current_title_doc, 'Deed of Assignment' ); ?>>Deed of Assignment</option>
                                        <option value="R of O" <?php selected( $current_title_doc, 'R of O' ); ?>>R of O</option>
                                        <option value="Excision" <?php selected( $current_title_doc, 'Excision' ); ?>>Excision</option>
                                        <option value="Other" <?php selected( $current_title_doc, 'Other' ); ?>>Other</option>
                                    </select>
                                </div>

                                <div class="ofp-field">
                                    <label>Condition</label>
                                    <?php $current_condition = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_condition', true ) : ''; ?>
                                    <select name="condition" class="ofp-select">
                                        <option value="">-- Select --</option>
                                        <option value="Newly Built" <?php selected( $current_condition, 'Newly Built' ); ?>>Newly Built</option>
                                        <option value="Fairly Used" <?php selected( $current_condition, 'Fairly Used' ); ?>>Fairly Used</option>
                                        <option value="Renovation Needed" <?php selected( $current_condition, 'Renovation Needed' ); ?>>Renovation Needed</option>
                                        <option value="Under Construction" <?php selected( $current_condition, 'Under Construction' ); ?>>Under Construction</option>
                                        <option value="Off-Plan" <?php selected( $current_condition, 'Off-Plan' ); ?>>Off-Plan</option>
                                    </select>
                                </div>

                                <div class="ofp-field">
                                    <label>Furnishing</label>
                                    <?php $current_furnishing = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_furnishing', true ) : ''; ?>
                                    <select name="furnishing" class="ofp-select">
                                        <option value="">-- Select --</option>
                                        <option value="Furnished" <?php selected( $current_furnishing, 'Furnished' ); ?>>Furnished</option>
                                        <option value="Semi-Furnished" <?php selected( $current_furnishing, 'Semi-Furnished' ); ?>>Semi-Furnished</option>
                                        <option value="Unfurnished" <?php selected( $current_furnishing, 'Unfurnished' ); ?>>Unfurnished</option>
                                    </select>
                                </div>

                                <div class="ofp-field" style="grid-column: 1 / -1;">
                                    <label>Video Tour URL</label>
                                    <input type="url" name="video_url" placeholder="https://www.youtube.com/watch?v=..."
                                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_video_url', true ) : '' ); ?>">
                                </div>

                                <div class="ofp-field" style="grid-column: 1 / -1;">
                                    <label>Amenities</label>
                                    <?php
                                    $all_amenities = [
                                        'swimming_pool' => 'Swimming Pool', 'smart_home' => 'Smart Home', 'power_247' => '24/7 Electricity',
                                        'cctv_security' => 'CCTV / Security', 'gym' => 'Gym / Fitness', 'elevator' => 'Elevator',
                                        'playground' => 'Children Play Area', 'bq' => 'Boys Quarters (BQ)',
                                        'water_treatment' => 'Water Treatment', 'fitted_kitchen' => 'Fitted Kitchen',
                                    ];
                                    $selected_amenities = $editing_post ? ( json_decode( get_post_meta( $editing_post->ID, 'ofp_amenities', true ) ?: '[]', true ) ?: [] ) : [];
                                    ?>
                                    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)); gap:8px; margin-top:4px;">
                                        <?php foreach ( $all_amenities as $key => $label ) : ?>
                                            <label style="display:flex; align-items:center; gap:6px; font-weight:normal; font-size:13px; cursor:pointer;">
                                                <input type="checkbox" name="amenities[]" value="<?php echo esc_attr( $key ); ?>"
                                                    <?php checked( in_array( $key, $selected_amenities, true ) ); ?>>
                                                <?php echo esc_html( $label ); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div style="margin-top:24px;">
                                <button type="submit" name="ofp_save_property" value="1" class="ofp-btn ofp-btn-primary">
                                    <?php echo $editing_post ? 'Save Changes' : 'Submit Property'; ?>
                                </button>
                                <?php if ( $editing_post ) : ?>
                                    <a href="?_x=<?php echo rand(); ?>" class="ofp-btn" style="background:var(--bg-body); color:var(--text-muted); border:1px solid var(--border-color); margin-left:12px; text-decoration:none;">Cancel Edit</a>
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
                        <style>
                            .property-title-link {
                                color: #ffffff;
                                text-decoration: none;
                                transition: color 0.2s ease;
                            }
                            .property-title-link:hover {
                                color: #3b82f6;
                            }
                        </style>
                        <div class="ofp-table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; white-space: nowrap; min-width: 800px;">
                                <thead>
                                    <tr style="border-bottom: 2px solid #e2e8f0; color: #64748b;">
                                        <th style="padding: 12px 16px;">Title</th>
                                        <th style="padding: 12px 16px;">Listing Type</th>
                                        <th style="padding: 12px 16px;">Property Type</th>
                                        <th style="padding: 12px 16px;">Location</th>
                                        <th style="padding: 12px 16px;">Date</th>
                                        <th style="padding: 12px 16px;">Status</th>
                                        <th style="padding: 12px 16px;">Price</th>
                                        <th style="padding: 12px 16px; text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $my_properties as $property ) : ?>
                                        <tr style="border-bottom: 1px solid #f1f5f9;">
                                            <td style="padding: 12px 16px; font-weight: 500;">
                                                <a href="<?php echo esc_url( get_permalink( $property->ID ) ); ?>" target="_blank" class="property-title-link">
                                                    <?php echo esc_html( $property->post_title ); ?>
                                                </a>
                                            </td>
                                            <td style="padding: 12px 16px;">
                                                <?php 
                                                    $ltype = get_post_meta( $property->ID, 'ofp_listing_type', true ) ?: 'sale';
                                                    echo esc_html( ucfirst( $ltype ) );
                                                ?>
                                            </td>
                                            <td style="padding: 12px 16px;">
                                                <?php 
                                                    $ptype = get_post_meta( $property->ID, 'ofp_property_type', true ) ?: '—';
                                                    echo esc_html( ucfirst( $ptype ) );
                                                ?>
                                            </td>
                                            <td style="padding: 12px 16px;">
                                                <?php 
                                                    $loc = get_post_meta( $property->ID, 'ofp_location_text', true ) ?: '—';
                                                    echo esc_html( $loc );
                                                ?>
                                            </td>
                                            <td style="padding: 12px 16px;">
                                                <?php echo esc_html( wp_date( 'M j, Y', strtotime( $property->post_date ) ) ); ?>
                                            </td>
                                            <td style="padding: 12px 16px;">
                                                <?php 
                                                    $db_status = get_post_meta( $property->ID, 'ofp_status', true ) ?: 'pending_upload';
                                                    $is_occupied = class_exists( 'OFP_Property_Rent' ) && OFP_Property_Rent::wp_property_is_occupied( (int) $property->ID );
                                                    if ( $is_occupied ) {
                                                        echo '<span style="color:#7c3aed;font-weight:600;">Occupied</span>';
                                                    } elseif ( $property->post_status === 'pending' ) {
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
                                                <?php if ( $can_edit_properties ) : ?>
                                                    <a href="?edit=<?php echo esc_attr( $property->ID ); ?>" style="color:#3b82f6; text-decoration:none; margin-right:16px; font-weight:500;">Edit</a>
                                                <?php else : ?>
                                                    <span style="color:#94a3b8; margin-right:16px; font-weight:500;" title="Renew or choose a plan to edit">Renew to Edit</span>
                                                <?php endif; ?>
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
