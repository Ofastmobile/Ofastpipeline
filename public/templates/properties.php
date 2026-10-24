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

$active_plan = OFP_Subscription::feature_plan( $client->id );
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
        elseif ( empty( OFP_Bank_Account::list( (int) $client->id ) ) ) {
            $error = 'A receiving bank account is required before submitting properties. Please add your bank details in <a href="' . esc_url( home_url( '/account' ) ) . '" style="text-decoration:underline; font-weight:700;">Account Settings &rarr; Bank Accounts</a>.';
        }
        elseif ( ( in_array( OFP_Subscription::normalize_plan( $client->plan ?? 'free' ), [ 'silver', 'gold' ], true ) || ! empty( $client->is_white_label ) ) && empty( $client->is_verified ) ) {
            $client_plan_name = ! empty( $client->is_white_label ) ? 'White-Label / Solo' : ucfirst( OFP_Subscription::normalize_plan( $client->plan ?? 'free' ) );
            $error = 'Business verification (CAC & NIN/BVN) is required for ' . esc_html( $client_plan_name ) . ' plan before submitting properties. Please complete your verification in <a href="' . esc_url( home_url( '/account' ) ) . '" style="text-decoration:underline; font-weight:700;">Account Settings &rarr; Verification</a>.';
        }
        elseif ( empty( $_POST['title'] ) || empty( $_POST['price'] ) ) {
            $error = 'Title and price are required.';
        } else {

            $title         = sanitize_text_field( $_POST['title'] );
            $description   = sanitize_textarea_field( $_POST['description'] ?? '' );
            $price         = (float) $_POST['price'];
            $price_period  = sanitize_text_field( $_POST['price_period'] ?? 'year' );
            $listing_type  = in_array( $_POST['listing_type'] ?? '', OFP_Property_CPT::LISTING_TYPES, true ) ? $_POST['listing_type'] : 'sale';
            $property_type = sanitize_text_field( $_POST['property_type'] ?? 'apartment' );
            $bedrooms      = (int) ( $_POST['bedrooms'] ?? 0 );
            $bathrooms     = (int) ( $_POST['bathrooms'] ?? 0 );
            if ( $listing_type === 'land' ) {
                // Land has no rooms and always uses the Land property type.
                $property_type = 'land';
                $bedrooms      = 0;
                $bathrooms     = 0;
            }
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
                if ( OFP_Property_CPT::is_sale_like( $listing_type ) ) {
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
                if ( $listing_type === 'land' ) {
                    $parking    = 0;
                    $furnishing = '';
                }
                $video_url     = esc_url_raw( $_POST['video_url'] ?? '' );
                $raw_amenities = (array) ( $_POST['amenities'] ?? [] );
                if ( class_exists( 'OFP_Property_Amenities' ) ) {
                    OFP_Property_Amenities::register_custom_items( $raw_amenities );
                }
                $amenities     = json_encode( array_values( array_map( 'sanitize_text_field', $raw_amenities ) ) );

                update_post_meta( $post_id, 'ofp_parking', $parking );
                update_post_meta( $post_id, 'ofp_area_sqm', $area_sqm );
                update_post_meta( $post_id, 'ofp_title_document', $title_doc );

                // Nigerian Land fields
                $plots_count     = isset( $_POST['plots_count'] ) && $_POST['plots_count'] !== '' ? (float) $_POST['plots_count'] : 0.0;
                $land_topography = sanitize_text_field( $_POST['land_topography'] ?? '' );
                $land_zoning     = sanitize_text_field( $_POST['land_zoning'] ?? '' );
                $land_dimensions = sanitize_text_field( $_POST['land_dimensions'] ?? '' );
                $survey_status   = sanitize_text_field( $_POST['survey_status'] ?? '' );

                update_post_meta( $post_id, 'ofp_plots_count', $plots_count );
                update_post_meta( $post_id, 'ofp_land_topography', $land_topography );
                update_post_meta( $post_id, 'ofp_land_zoning', $land_zoning );
                update_post_meta( $post_id, 'ofp_land_dimensions', $land_dimensions );
                update_post_meta( $post_id, 'ofp_survey_status', $survey_status );

                $bank_pick = (int) ( $_POST['bank_account_id'] ?? 0 );
                if ( $bank_pick && ! OFP_Bank_Account::get( $bank_pick, (int) $client->id ) ) {
                    $bank_pick = 0;
                }
                update_post_meta( $post_id, 'ofp_bank_account_id', $bank_pick );
                update_post_meta( $post_id, 'ofp_condition', $condition_val );
                update_post_meta( $post_id, 'ofp_furnishing', $furnishing );
                update_post_meta( $post_id, 'ofp_video_url', $video_url );
                update_post_meta( $post_id, 'ofp_amenities', $amenities );

                $total_package     = (float) ( $_POST['total_package'] ?? 0 );
                $allow_installment = ! empty( $_POST['allow_installment'] ) ? '1' : '0';
                $inspection_days   = sanitize_text_field( $_POST['inspection_days'] ?? '' );
                $inspection_hours  = sanitize_text_field( $_POST['inspection_hours'] ?? '' );
                $inspection_notice = sanitize_text_field( $_POST['inspection_notice'] ?? '' );

                update_post_meta( $post_id, 'ofp_total_package', $total_package );
                update_post_meta( $post_id, 'ofp_allow_installment', $allow_installment );
                update_post_meta( $post_id, 'ofp_inspection_days', $inspection_days ?: 'Mondays - Saturdays' );
                update_post_meta( $post_id, 'ofp_inspection_hours', $inspection_hours ?: '10:00 AM - 5:00 PM' );
                update_post_meta( $post_id, 'ofp_inspection_notice', $inspection_notice );

                // 1. Existing photos management (for edit)
                if ( ! empty( $_POST['remove_gallery_ids'] ) && is_array( $_POST['remove_gallery_ids'] ) ) {
                    $remove_ids = array_map( 'absint', $_POST['remove_gallery_ids'] );
                    $existing_gallery = json_decode( get_post_meta( $post_id, 'ofp_gallery_ids', true ) ?: '[]', true );
                    if ( is_array( $existing_gallery ) ) {
                        $updated_gallery = array_values( array_diff( $existing_gallery, $remove_ids ) );
                        update_post_meta( $post_id, 'ofp_gallery_ids', json_encode( $updated_gallery ) );
                    }
                }

                if ( ! empty( $_POST['delete_main_thumbnail'] ) ) {
                    delete_post_thumbnail( $post_id );
                    $existing_gallery = json_decode( get_post_meta( $post_id, 'ofp_gallery_ids', true ) ?: '[]', true );
                    if ( is_array( $existing_gallery ) && ! empty( $existing_gallery ) ) {
                        $new_main = array_shift( $existing_gallery );
                        set_post_thumbnail( $post_id, (int) $new_main );
                        update_post_meta( $post_id, 'ofp_gallery_ids', json_encode( array_values( $existing_gallery ) ) );
                    }
                }

                if ( ! empty( $_POST['set_main_attachment_id'] ) ) {
                    $new_main_id = absint( $_POST['set_main_attachment_id'] );
                    if ( $new_main_id > 0 ) {
                        $current_thumb = get_post_thumbnail_id( $post_id );
                        $existing_gallery = json_decode( get_post_meta( $post_id, 'ofp_gallery_ids', true ) ?: '[]', true ) ?: [];
                        $existing_gallery = array_values( array_diff( $existing_gallery, [ $new_main_id ] ) );
                        if ( $current_thumb && $current_thumb !== $new_main_id ) {
                            $existing_gallery[] = (int) $current_thumb;
                        }
                        set_post_thumbnail( $post_id, $new_main_id );
                        update_post_meta( $post_id, 'ofp_gallery_ids', json_encode( array_values( array_unique( $existing_gallery ) ) ) );
                    }
                }

                // 2. Multi-photo upload
                if ( ! empty( $_FILES['photos']['name'] ) && is_array( $_FILES['photos']['name'] ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';

                    $gallery_ids = json_decode( get_post_meta( $post_id, 'ofp_gallery_ids', true ) ?: '[]', true );
                    if ( ! is_array( $gallery_ids ) ) {
                        $gallery_ids = [];
                    }

                    $primary_index = isset( $_POST['primary_photo_index'] ) ? (int) $_POST['primary_photo_index'] : 0;
                    $uploaded_items = []; // [ index => attachment_id ]

                    $file_count = count( $_FILES['photos']['name'] );
                    for ( $i = 0; $i < $file_count; $i++ ) {
                        if ( empty( $_FILES['photos']['name'][ $i ] ) ) continue;
                        if ( isset( $_FILES['photos']['error'][ $i ] ) && $_FILES['photos']['error'][ $i ] !== UPLOAD_ERR_OK ) continue;

                        $single_file = [
                            'name'     => $_FILES['photos']['name'][ $i ],
                            'type'     => $_FILES['photos']['type'][ $i ],
                            'tmp_name' => $_FILES['photos']['tmp_name'][ $i ],
                            'error'    => $_FILES['photos']['error'][ $i ],
                            'size'     => $_FILES['photos']['size'][ $i ],
                        ];

                        $_FILES['ofp_single_photo'] = $single_file;
                        $attachment_id = media_handle_upload( 'ofp_single_photo', $post_id );

                        if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
                            $uploaded_items[ $i ] = (int) $attachment_id;
                        }
                    }

                    if ( ! empty( $uploaded_items ) ) {
                        // If no main thumbnail exists yet, pick the primary photo or first uploaded photo
                        if ( ! has_post_thumbnail( $post_id ) ) {
                            $thumb_att_id = $uploaded_items[ $primary_index ] ?? reset( $uploaded_items );
                            set_post_thumbnail( $post_id, $thumb_att_id );

                            foreach ( $uploaded_items as $att_id ) {
                                if ( $att_id !== $thumb_att_id ) {
                                    $gallery_ids[] = $att_id;
                                }
                            }
                        } else {
                            // If user explicitly chose a newly uploaded photo to become main cover
                            if ( isset( $_POST['replace_main_with_new_primary'] ) && $_POST['replace_main_with_new_primary'] === '1' && isset( $uploaded_items[ $primary_index ] ) ) {
                                $old_thumb = get_post_thumbnail_id( $post_id );
                                if ( $old_thumb ) {
                                    $gallery_ids[] = (int) $old_thumb;
                                }
                                $new_main_id = $uploaded_items[ $primary_index ];
                                set_post_thumbnail( $post_id, $new_main_id );
                                foreach ( $uploaded_items as $att_id ) {
                                    if ( $att_id !== $new_main_id ) {
                                        $gallery_ids[] = $att_id;
                                    }
                                }
                            } else {
                                foreach ( $uploaded_items as $att_id ) {
                                    $gallery_ids[] = $att_id;
                                }
                            }
                        }

                        update_post_meta( $post_id, 'ofp_gallery_ids', json_encode( array_values( array_unique( $gallery_ids ) ) ) );
                    }
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

$active_tab = sanitize_key( $_GET['tab'] ?? ( isset( $_GET['edit'] ) ? 'form' : 'table' ) );
if ( ! in_array( $active_tab, [ 'table', 'form' ], true ) ) {
    $active_tab = 'table';
}

$prop_tabs = [
    'table' => [
        'label' => 'All Properties',
        'url'   => home_url( '/properties' ),
        'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>',
        'badge' => count( $my_properties ),
    ],
    'form' => [
        'label' => $editing_post ? 'Edit Property' : 'Add New Property',
        'url'   => $editing_post ? add_query_arg( [ 'tab' => 'form', 'edit' => $editing_post->ID ], home_url( '/properties' ) ) : add_query_arg( 'tab', 'form', home_url( '/properties' ) ),
        'icon'  => $editing_post
            ? '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'My Properties', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <script src="<?php echo esc_url( OFP_URL . 'assets/js/client-portal.js' ); ?>" defer></script>
</head>
<body class="ofp-portal-body">
    <?php 
    $ofp_banner_btn = [ 'label' => 'Add New Property', 'url' => add_query_arg( 'tab', 'form', home_url( '/properties' ) ) ];
    include OFP_PATH . 'public/templates/partials/nav.php'; 
    ?>

    <div class="ofp-container">
        <div style="padding-bottom: 60px;">
            <!-- Segmented Pill Tab Bar -->
            <div class="ofp-segmented-nav">
                <?php foreach ( $prop_tabs as $key => $tab ) :
                    $is_active = ( $key === $active_tab );
                ?>
                    <a href="<?php echo esc_url( $tab['url'] ); ?>"
                       data-tab="<?php echo esc_attr( $key ); ?>"
                       class="ofp-segmented-tab <?php echo $is_active ? 'active' : ''; ?>">
                        <?php echo $tab['icon']; ?>
                        <span><?php echo esc_html( $tab['label'] ); ?></span>
                        <?php if ( ! empty( $tab['badge'] ) ) : ?>
                            <span class="ofp-tab-badge"><?php echo esc_html( $tab['badge'] ); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ( $error ) : ?>
                <div class="ofp-alert ofp-alert-error" style="margin-bottom:20px;"><?php echo esc_html( $error ); ?></div>
            <?php endif; ?>
            <?php if ( $success ) : ?>
                <div class="ofp-alert ofp-alert-success" style="margin-bottom:20px;"><?php echo esc_html( $success ); ?></div>
            <?php endif; ?>

            <div id="ofp-prop-pane-table" class="ofp-prop-pane" style="<?php echo $active_tab === 'table' ? '' : 'display:none;'; ?>">
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TAB 1: ALL PROPERTIES TABLE (MATCHING THE MONITOR IMAGE)      -->
                <!-- ═══════════════════════════════════════════════════════════════ -->

                <?php if ( ! OFP_Brand::hide_plan_prompts( $client ) ) : ?>
                    <?php
                    $has_active_paid_plan = OFP_Subscription::has_paid_plan( $client->id );
                    $active_sub = OFP_Subscription::get_active( 'listing', $client->id ) ?: OFP_Subscription::get_active( 'crm', $client->id );
                    $plan_expires = $active_sub ? $active_sub->period_end : ( $client->subscription_expires ?? null );
                    ?>
                    <div class="ofp-card" style="padding:16px 20px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="width:36px; height:36px; border-radius:8px; background:rgba(59,130,246,0.1); color:var(--accent-blue); display:flex; align-items:center; justify-content:center;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m1-3l1 3m6-3l1 3m-1-3l-1 3M3 10.5h18" /></svg>
                            </div>
                            <div>
                                <div style="font-size:14px; font-weight:700; color:var(--text-main);">
                                    <?php echo esc_html( $plan_labels[ $active_plan ] ?? ucfirst( $active_plan ) ); ?> Plan
                                    <span style="font-size:11px; font-weight:600; padding:2px 8px; border-radius:100px; <?php echo $has_active_paid_plan ? 'background:#dcfce7; color:#16a34a;' : 'background:#e0e7ff; color:#4338ca;'; ?> margin-left:6px;">Active</span>
                                </div>
                                <div style="font-size:12.5px; color:var(--text-muted); margin-top:2px;">
                                    Using <strong><?php echo esc_html( $used_count ); ?></strong> of <strong><?php echo esc_html( $plan_caps[ $active_plan ] ?? 1 ); ?></strong> listings allowed.
                                    <?php if ( $plan_expires ) : ?>
                                        &bull; Renews: <?php echo esc_html( date( 'd M Y', strtotime( $plan_expires ) ) ); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if ( ! $has_active_paid_plan ) : ?>
                            <a href="<?php echo esc_url( home_url( '/pricing' ) ); ?>" class="ofp-btn-plan-action primary">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                                </svg>
                                <span>Upgrade Plan</span>
                            </a>
                        <?php else : ?>
                            <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>" class="ofp-btn-plan-action">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                <span>Funding &amp; Renewals</span>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="ofp-card" style="padding:0; overflow:hidden;">
                    <!-- Table Card Header -->
                    <div style="padding:20px 24px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                        <div>
                            <h3 style="margin:0 0 4px; font-size:18px; font-weight:700; color:var(--text-main);">Manage Properties</h3>
                            <p style="margin:0; font-size:13px; color:var(--text-muted);">Real-time inventory of all property listings and unit statuses.</p>
                        </div>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <span style="background:var(--bg-body); border:1px solid var(--border-color); font-size:12.5px; font-weight:600; padding:6px 12px; border-radius:8px; color:var(--text-muted);">
                                Total Properties: <strong style="color:var(--text-main);"><?php echo count( $my_properties ); ?></strong>
                            </span>
                            <a href="<?php echo esc_url( add_query_arg( 'tab', 'form', home_url( '/properties' ) ) ); ?>" data-tab="form" class="ofp-btn-header-add">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                <span>Add Property</span>
                            </a>
                        </div>
                    </div>

                    <?php if ( empty( $my_properties ) ) : ?>
                        <div style="text-align:center; padding:56px 24px; background:rgba(255,255,255,0.01);">
                            <div style="width:56px; height:56px; border-radius:50%; background:rgba(59,130,246,0.1); color:var(--accent-blue); display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:28px;height:28px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
                            </div>
                            <h4 style="margin:0 0 8px; font-size:16px; font-weight:700; color:var(--text-main);">No Properties Listed Yet</h4>
                            <p style="margin:0 auto 20px; max-width:440px; font-size:13.5px; color:var(--text-muted); line-height:1.5;">You haven't added any listings to your portfolio. Create your first property with photos, pricing, and specs.</p>
                            <a href="<?php echo esc_url( add_query_arg( 'tab', 'form', home_url( '/properties' ) ) ); ?>" data-tab="form" class="ofp-btn ofp-btn-primary" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; padding:9px 18px; text-decoration:none;">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:15px;height:15px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                <span>Add Your First Property</span>
                            </a>
                        </div>
                    <?php else : ?>
                        <div class="ofp-table-responsive" style="overflow-x:auto; -webkit-overflow-scrolling:touch;">
                            <table class="ofp-table" style="width:100%; border-collapse:collapse; white-space:nowrap;">
                                <thead>
                                    <tr style="border-bottom:1px solid var(--border-color); background:rgba(255,255,255,0.02);">
                                        <th style="padding:12px 16px; width:40px; font-size:12px; color:var(--text-muted);">#</th>
                                        <th style="padding:12px 16px; font-size:12px; color:var(--text-muted); min-width:280px;">Property</th>
                                        <th style="padding:12px 16px; font-size:12px; color:var(--text-muted);">Price</th>
                                        <th style="padding:12px 16px; font-size:12px; color:var(--text-muted);">Listing Type</th>
                                        <th style="padding:12px 16px; font-size:12px; color:var(--text-muted);">Property Type</th>
                                        <th style="padding:12px 16px; font-size:12px; color:var(--text-muted);">Specs</th>
                                        <th style="padding:12px 16px; font-size:12px; color:var(--text-muted);">Status</th>
                                        <th style="padding:12px 16px; font-size:12px; color:var(--text-muted); text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $my_properties as $idx => $property ) :
                                        $prop_id      = (int) $property->ID;
                                        $thumb_url    = get_the_post_thumbnail_url( $prop_id, 'medium' ) ?: get_the_post_thumbnail_url( $prop_id, 'thumbnail' );
                                        $ltype        = get_post_meta( $prop_id, 'ofp_listing_type', true ) ?: 'sale';
                                        $ptype        = get_post_meta( $prop_id, 'ofp_property_type', true ) ?: '—';
                                        $loc          = get_post_meta( $prop_id, 'ofp_location_text', true ) ?: '—';
                                        $price        = (float) get_post_meta( $prop_id, 'ofp_price', true );
                                        $price_period = get_post_meta( $prop_id, 'ofp_price_period', true );
                                        if ( $price_period === 'one-time' ) $price_period = 'sales';
                                        $beds         = get_post_meta( $prop_id, 'ofp_bedrooms', true );
                                        $baths        = get_post_meta( $prop_id, 'ofp_bathrooms', true );
                                        $area         = get_post_meta( $prop_id, 'ofp_area_sqm', true );
                                        $is_featured  = ( '1' === (string) get_post_meta( $prop_id, 'ofp_is_featured', true ) );
                                        $status_ctx   = class_exists( 'OFP_Property_CPT' )
                                            ? OFP_Property_CPT::get_status_context( $prop_id )
                                            : [
                                                'key'   => ( get_post_meta( $prop_id, 'ofp_status', true ) ?: 'pending_upload' ),
                                                'label' => ucfirst( get_post_meta( $prop_id, 'ofp_status', true ) ?: 'pending_upload' ),
                                            ];

                                        // Badge style for listing type
                                        $ltype_colors = [
                                            'sale'       => 'background:rgba(37, 99, 235, 0.12); color:#3b82f6;',
                                            'rent'       => 'background:rgba(139, 92, 246, 0.12); color:#8b5cf6;',
                                            'shortlet'   => 'background:rgba(217, 119, 6, 0.12); color:#d97706;',
                                            'commercial' => 'background:rgba(124, 58, 237, 0.12); color:#7c3aed;',
                                            'land'       => 'background:rgba(77, 124, 15, 0.12); color:#4d7c0f;'
                                        ];
                                        $ltype_badge_style = $ltype_colors[ $ltype ] ?? 'background:rgba(128,128,128,0.1); color:var(--text-muted);';

                                        // Status badge
                                        if ( $status_ctx['key'] === 'occupied' ) {
                                            $status_html = '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:4px 10px;border-radius:100px;background:rgba(139,92,246,0.14);color:#8b5cf6;border:1px solid rgba(139,92,246,0.28);"><span style="width:6px;height:6px;border-radius:50%;background:#8b5cf6;"></span>' . esc_html( $status_ctx['label'] ) . '</span>';
                                        } elseif ( $status_ctx['key'] === 'taken' ) {
                                            $status_html = '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:4px 10px;border-radius:100px;background:rgba(239,68,68,0.12);color:#ef4444;border:1px solid rgba(239,68,68,0.28);"><span style="width:6px;height:6px;border-radius:50%;background:#ef4444;"></span>' . esc_html( $status_ctx['label'] ) . '</span>';
                                        } elseif ( $status_ctx['key'] === 'under_offer' ) {
                                            $status_html = '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:4px 10px;border-radius:100px;background:rgba(245,158,11,0.14);color:#d97706;border:1px solid rgba(245,158,11,0.28);"><span style="width:6px;height:6px;border-radius:50%;background:#d97706;"></span>' . esc_html( $status_ctx['label'] ) . '</span>';
                                        } elseif ( $status_ctx['key'] === 'pending_upload' ) {
                                            $status_html = '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:4px 10px;border-radius:100px;background:rgba(245,158,11,0.12);color:#d97706;border:1px solid rgba(245,158,11,0.2);"><span style="width:6px;height:6px;border-radius:50%;background:#d97706;"></span>' . esc_html( $status_ctx['label'] ) . '</span>';
                                        } elseif ( $status_ctx['key'] === 'expired' ) {
                                            $status_html = '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:4px 10px;border-radius:100px;background:rgba(239,68,68,0.12);color:#ef4444;border:1px solid rgba(239,68,68,0.28);"><span style="width:6px;height:6px;border-radius:50%;background:#ef4444;"></span>' . esc_html( $status_ctx['label'] ) . '</span>';
                                        } else {
                                            $status_html = '<span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;padding:4px 10px;border-radius:100px;background:rgba(16,185,129,0.12);color:#10b981;border:1px solid rgba(16,185,129,0.28);"><span style="width:6px;height:6px;border-radius:50%;background:#10b981;"></span>' . esc_html( $status_ctx['label'] ) . '</span>';
                                        }

                                        $period_labels = [ 'sales' => '', 'year' => '/ yr', '2years' => '/ 2 yrs', 'month' => '/ mo' ];
                                        $period_suffix = $period_labels[ $price_period ] ?? ( $price_period ? ' / ' . $price_period : '' );
                                    ?>
                                        <tr class="ofp-property-row" style="border-bottom:1px solid var(--border-color);">
                                            <td style="padding:14px 16px; color:var(--text-muted); font-size:12px; font-weight:600;">
                                                <?php echo esc_html( $idx + 1 ); ?>
                                            </td>
                                            <td style="padding:14px 16px;">
                                                <div class="ofp-prop-info-cell">
                                                    <div class="ofp-prop-thumb-wrap">
                                                        <?php if ( $thumb_url ) : ?>
                                                            <img src="<?php echo esc_url( $thumb_url ); ?>" alt="" class="ofp-prop-thumb-img" loading="lazy">
                                                        <?php else : ?>
                                                            <div class="ofp-prop-thumb-placeholder">
                                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:22px;height:22px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="min-width:0; max-width:320px;">
                                                        <a href="<?php echo esc_url( get_permalink( $prop_id ) ); ?>" target="_blank" class="ofp-prop-meta-title" title="<?php echo esc_attr( $property->post_title ); ?>">
                                                            <?php echo esc_html( $property->post_title ); ?>
                                                        </a>
                                                        <?php if ( $is_featured ) : ?>
                                                            <span style="display:inline-flex;align-items:center;gap:3px;font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:100px;background:rgba(245,158,11,0.15);color:#d97706;border:1px solid rgba(245,158,11,0.3);margin-left:5px;vertical-align:middle;" title="Featured Listing">
                                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" style="width:10px;height:10px;"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                                                Featured
                                                            </span>
                                                        <?php endif; ?>
                                                        <div class="ofp-prop-meta-loc">
                                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:13px;height:13px;flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                                                            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html( $loc ); ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="padding:14px 16px; font-weight:700; color:var(--text-main); font-size:14px;">
                                                ₦<?php echo esc_html( number_format( $price, 0 ) ); ?>
                                                <?php if ( $period_suffix ) : ?>
                                                    <span style="font-size:11.5px; font-weight:500; color:var(--text-muted); margin-left:2px;"><?php echo esc_html( $period_suffix ); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:14px 16px;">
                                                <span style="font-size:12px; font-weight:600; padding:4px 10px; border-radius:100px; <?php echo esc_attr( $ltype_badge_style ); ?>">
                                                    <?php echo esc_html( ucfirst( $ltype ) ); ?>
                                                </span>
                                            </td>
                                            <td style="padding:14px 16px; color:var(--text-main); font-weight:500; font-size:13.5px;">
                                                <?php echo esc_html( ucfirst( $ptype ) ); ?>
                                            </td>
                                            <td style="padding:14px 16px;">
                                                <?php if ( $ltype === 'land' ) : ?>
                                                    <span class="ofp-spec-pill"><?php echo $area ? esc_html($area) . ' SQM' : 'Plot'; ?></span>
                                                <?php else : ?>
                                                    <div style="display:flex; align-items:center; gap:10px;">
                                                        <?php if ( $beds !== '' && $beds !== null && (int)$beds > 0 ) : ?>
                                                            <span class="ofp-spec-pill" title="<?php echo esc_attr($beds); ?> Bedrooms">
                                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                                                                <?php echo esc_html( $beds ); ?> Bed
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ( $baths !== '' && $baths !== null && (int)$baths > 0 ) : ?>
                                                            <span class="ofp-spec-pill" title="<?php echo esc_attr($baths); ?> Bathrooms">
                                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V3.75m0 3.75a3 3 0 01-3-3m3 3a3 3 0 00-3-3m0 0H6.75" /></svg>
                                                                <?php echo esc_html( $baths ); ?> Bath
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ( ( $beds === '' || (int)$beds === 0 ) && ( $baths === '' || (int)$baths === 0 ) ) : ?>
                                                            <span style="color:var(--text-muted); font-size:12px;">—</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:14px 16px;">
                                                <?php echo $status_html; ?>
                                            </td>
                                            <td style="padding:14px 16px; text-align:right;">
                                                <div style="display:inline-flex; align-items:center; gap:8px;">
                                                    <a href="<?php echo esc_url( get_permalink( $prop_id ) ); ?>" target="_blank" class="ofp-table-action-btn view" title="View Public Listing">
                                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:15px;height:15px;"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                                    </a>
                                                    <?php if ( $can_edit_properties ) : ?>
                                                        <a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'form', 'edit' => $prop_id ], home_url( '/properties' ) ) ); ?>" class="ofp-table-action-btn edit" title="Edit Property">
                                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:15px;height:15px;"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>
                                                        </a>
                                                    <?php else : ?>
                                                        <span class="ofp-table-action-btn view" style="opacity:0.4;cursor:not-allowed;" title="Renew or upgrade to edit">
                                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:15px;height:15px;"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                                                        </span>
                                                    <?php endif; ?>
                                                    <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Are you sure you want to delete this property listing?');">
                                                        <?php wp_nonce_field( 'ofp_delete_property_action', 'ofp_delete_nonce' ); ?>
                                                        <input type="hidden" name="property_id" value="<?php echo esc_attr( $prop_id ); ?>">
                                                        <button type="submit" name="ofp_delete_property" value="1" class="ofp-table-action-btn delete" title="Delete Property">
                                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:15px;height:15px;"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div><!-- #ofp-prop-pane-table -->

            <div id="ofp-prop-pane-form" class="ofp-prop-pane" style="<?php echo $active_tab === 'form' ? '' : 'display:none;'; ?>">
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TAB 2: REDESIGNED PROPERTY FORM BUILDER                       -->
                <!-- ═══════════════════════════════════════════════════════════════ -->

                <?php if ( $editing_post ) : ?>
                    <div style="margin: 0 0 16px; text-align: left;">
                        <a href="<?php echo esc_url( home_url( '/properties' ) ); ?>" data-tab="table" class="ofp-back-link-left">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" style="width:14px;height:14px;display:inline-block;vertical-align:middle;"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                            <span>Back to Properties</span>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if ( ! $editing_post && ! OFP_Property_CPT::can_add_property( $client->id ) ) : ?>
                    <div class="ofp-card" style="padding:32px 24px; text-align:center;">
                        <div style="width:52px; height:52px; border-radius:50%; background:rgba(217,119,6,0.1); color:#d97706; display:inline-flex; align-items:center; justify-content:center; margin-bottom:16px;">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                        </div>
                        <h3 style="margin:0 0 8px; font-size:18px; font-weight:700; color:var(--text-main);">Plan Limit Reached</h3>
                        <p class="ofp-hint" style="max-width:480px; margin:0 auto 20px;">
                            You have reached your plan's maximum property limit. Upgrade your listing tier to add more properties to your portfolio.
                        </p>
                        <a href="<?php echo esc_url( home_url( '/pricing' ) ); ?>" class="ofp-btn ofp-btn-primary" style="padding:10px 24px;">Upgrade Plan</a>
                    </div>
                <?php else : 
                    $ofp_client_banks = OFP_Bank_Account::list( (int) $client->id );
                    $ofp_client_tier  = OFP_Subscription::normalize_plan( $client->plan ?? 'free' );
                    $ofp_req_kyc      = in_array( $ofp_client_tier, [ 'silver', 'gold' ], true ) || ! empty( $client->is_white_label );
                    $ofp_is_verified  = ! empty( $client->is_verified );
                    $ofp_has_banks    = ! empty( $ofp_client_banks );
                    $ofp_can_publish  = $ofp_has_banks && ( ! $ofp_req_kyc || $ofp_is_verified );
                ?>
                    <?php if ( ! $ofp_has_banks ) : ?>
                        <div style="background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.3); border-radius:14px; padding:18px 22px; margin-bottom:24px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                            <div style="display:flex; align-items:center; gap:14px;">
                                <span style="font-size:24px;">&#9888;&#65039;</span>
                                <div>
                                    <strong style="color:#d97706; font-size:14px; display:block; margin-bottom:2px;">Receiving Bank Account Required</strong>
                                    <span style="color:var(--text-muted); font-size:13px;">All clients must add at least one verified bank account before properties can be saved.</span>
                                </div>
                            </div>
                            <a href="<?php echo esc_url( home_url( '/account' ) ); ?>" class="ofp-btn ofp-btn-sm" style="background:#d97706; color:#fff; border-radius:8px; padding:8px 18px; font-weight:600; text-decoration:none; white-space:nowrap;">Add Bank Details &rarr;</a>
                        </div>
                    <?php endif; ?>

                    <?php if ( $ofp_req_kyc && ! $ofp_is_verified ) : ?>
                        <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.3); border-radius:14px; padding:18px 22px; margin-bottom:24px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                            <div style="display:flex; align-items:center; gap:14px;">
                                <span style="font-size:24px;">&#128274;</span>
                                <div>
                                    <strong style="color:#dc2626; font-size:14px; display:block; margin-bottom:2px;">Business Verification Compulsory (<?php echo esc_html( ! empty( $client->is_white_label ) ? 'White-Label / Solo' : ucfirst( $ofp_client_tier ) ); ?> Plan)</strong>
                                    <span style="color:var(--text-muted); font-size:13px;">Your plan requires official business verification (CAC and NIN or BVN) before listings can be published.</span>
                                </div>
                            </div>
                            <a href="<?php echo esc_url( home_url( '/account' ) ); ?>" class="ofp-btn ofp-btn-sm" style="background:#dc2626; color:#fff; border-radius:8px; padding:8px 18px; font-weight:600; text-decoration:none; white-space:nowrap;">Verify Business &rarr;</a>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" style="margin-top:0;">
                        <?php wp_nonce_field( 'ofp_save_property_action', 'ofp_property_nonce' ); ?>
                        <?php if ( $editing_post ) : ?>
                            <input type="hidden" name="property_id" value="<?php echo esc_attr( $editing_post->ID ); ?>">
                        <?php endif; ?>

                        <!-- 1. Basic Information -->
                        <div class="ofp-form-card-section">
                            <div class="ofp-form-section-header">
                                <div class="ofp-form-section-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                                </div>
                                <div>
                                    <h4 class="ofp-form-section-title">1. Basic Information</h4>
                                    <p class="ofp-form-section-desc">Primary title, detailed description, and real estate categorization.</p>
                                </div>
                            </div>

                            <div class="ofp-form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:18px;">
                                <div class="ofp-field" style="grid-column:1/-1;">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Property Title <span style="color:#ef4444;">*</span></label>
                                    <input type="text" name="title" required placeholder="e.g. Luxury 4-Bedroom Semi-Detached Duplex with BQ"
                                           value="<?php echo esc_attr( $editing_post->post_title ?? '' ); ?>"
                                           style="width:100%; font-size:14.5px; padding:11px 14px; border-radius:8px;">
                                </div>

                                <div class="ofp-field" style="grid-column:1/-1;">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Full Description</label>
                                    <textarea name="description" rows="5" placeholder="Detail the property layout, accessibility, neighborhood highlights, and finishes..." style="width:100%; font-size:14px; padding:12px 14px; border-radius:8px;"><?php echo esc_textarea( $editing_post->post_content ?? '' ); ?></textarea>
                                </div>

                                <div class="ofp-field">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Listing Type <span style="color:#ef4444;">*</span></label>
                                    <select name="listing_type" class="ofp-select" style="width:100%; padding:11px 14px; border-radius:8px;">
                                        <option value="" hidden>— Select Listing Type —</option>
                                        <?php $current_ltype = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_listing_type', true ) : 'sale'; ?>
                                        <option value="sale" <?php selected( $current_ltype, 'sale' ); ?>>For Sale</option>
                                        <option value="rent" <?php selected( $current_ltype, 'rent' ); ?>>For Rent</option>
                                        <option value="shortlet" <?php selected( $current_ltype, 'shortlet' ); ?>>Short Let</option>
                                        <option value="commercial" <?php selected( $current_ltype, 'commercial' ); ?>>Commercial</option>
                                        <option value="land" <?php selected( $current_ltype, 'land' ); ?>>Land</option>
                                    </select>
                                </div>

                                <div class="ofp-field">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Property Type <span style="color:#ef4444;">*</span></label>
                                    <select name="property_type" class="ofp-select" style="width:100%; padding:11px 14px; border-radius:8px;">
                                        <option value="" hidden>— Select Property Type —</option>
                                        <?php 
                                        $current_ptype = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_property_type', true ) : 'apartment'; 
                                        $types = [ 'apartment' => 'Apartment', 'duplex' => 'Duplex', 'semi-detached' => 'Semi-Detached', 'bungalow' => 'Bungalow', 'terrace' => 'Terrace', 'land' => 'Land', 'office' => 'Office', 'shop' => 'Shop', 'warehouse' => 'Warehouse', 'other' => 'Other' ];
                                        foreach ( $types as $val => $label ) {
                                            echo '<option value="' . esc_attr($val) . '" ' . selected($current_ptype, $val, false) . '>' . esc_html($label) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Pricing & Commerce -->
                        <div class="ofp-form-card-section">
                            <div class="ofp-form-section-header">
                                <div class="ofp-form-section-icon" style="background:rgba(16,185,129,0.1); color:#10b981;">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                </div>
                                <div>
                                    <h4 class="ofp-form-section-title">2. Pricing &amp; Commerce</h4>
                                    <p class="ofp-form-section-desc">Valuation, billing period, title documentation, and receiving bank details.</p>
                                </div>
                            </div>

                            <div class="ofp-form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:18px;">
                                <div class="ofp-field">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Price (₦ Nigerian Naira) <span style="color:#ef4444;">*</span></label>
                                    <div style="position:relative;">
                                        <span style="position:absolute; left:14px; top:50%; transform:translateY(-50%); font-weight:700; color:var(--text-muted); font-size:15px;">₦</span>
                                        <input type="number" step="0.01" name="price" required placeholder="0.00"
                                               value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_price', true ) : '' ); ?>"
                                               style="width:100%; padding:11px 14px 11px 32px; border-radius:8px; font-weight:600;">
                                    </div>
                                </div>

                                <div class="ofp-field">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Price Period</label>
                                    <select name="price_period" class="ofp-select" style="width:100%; padding:11px 14px; border-radius:8px;">
                                        <option value="" hidden>— Select Period —</option>
                                        <?php 
                                        $current_period = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_price_period', true ) : 'year'; 
                                        if ( $current_period === 'one-time' ) $current_period = 'sales';
                                        ?>
                                        <option value="sales" <?php selected( $current_period, 'sales' ); ?>>Sales (One-off Total)</option>
                                        <option value="year" <?php selected( $current_period, 'year' ); ?>>Per Year (Rent)</option>
                                        <option value="2years" <?php selected( $current_period, '2years' ); ?>>Per 2 Years (Rent)</option>
                                        <option value="month" <?php selected( $current_period, 'month' ); ?>>Per Month (Shortlet/Rent)</option>
                                    </select>
                                </div>

                                <div class="ofp-field" id="ofp-total-package-field">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Total Move-in Package (₦) <span class="ofp-hint" style="display:inline;margin:0;">(Rent Only)</span></label>
                                    <div style="position:relative;">
                                        <span style="position:absolute; left:14px; top:50%; transform:translateY(-50%); font-weight:700; color:var(--text-muted); font-size:15px;">₦</span>
                                        <input type="number" step="0.01" name="total_package" placeholder="e.g. Rent + Legal + Agency + Caution"
                                               value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_total_package', true ) : '' ); ?>"
                                               style="width:100%; padding:11px 14px 11px 32px; border-radius:8px;">
                                    </div>
                                    <span class="ofp-hint" style="font-size:11.5px; margin-top:4px; display:block;">In Nigeria, rent is listed separately while total package includes agency, legal, and caution fees.</span>
                                </div>

                                <div class="ofp-field" style="margin-bottom:8px;">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; font-weight:600; color:var(--text-main);">
                                        <input type="checkbox" name="allow_installment" value="1" <?php checked( $editing_post ? (string) get_post_meta( $editing_post->ID, 'ofp_allow_installment', true ) : '0', '1' ); ?>>
                                        Allow Installment / Mortgage Financing Plan
                                    </label>
                                    <span class="ofp-hint" style="font-size:11.5px; margin-top:2px; display:block;">Enable to activate the Installment &amp; Mortgage Estimator on the single property page.</span>
                                </div>

                                <div class="ofp-field">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Title Document</label>
                                    <?php $current_title_doc = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_title_document', true ) : ''; ?>
                                    <select name="title_document" class="ofp-select" style="width:100%; padding:11px 14px; border-radius:8px;">
                                        <option value="">-- Select Title Document --</option>
                                        <option value="C of O" <?php selected( $current_title_doc, 'C of O' ); ?>>Certificate of Occupancy (C of O)</option>
                                        <option value="Governor's Consent" <?php selected( $current_title_doc, "Governor's Consent" ); ?>>Governor's Consent</option>
                                        <option value="Gazette" <?php selected( $current_title_doc, 'Gazette' ); ?>>Government Gazette</option>
                                        <option value="Deed of Assignment" <?php selected( $current_title_doc, 'Deed of Assignment' ); ?>>Deed of Assignment</option>
                                        <option value="R of O" <?php selected( $current_title_doc, 'R of O' ); ?>>Right of Occupancy (R of O)</option>
                                        <option value="Excision" <?php selected( $current_title_doc, 'Excision' ); ?>>Excision</option>
                                        <option value="Other" <?php selected( $current_title_doc, 'Other' ); ?>>Other</option>
                                    </select>
                                </div>

                                <div class="ofp-field">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Receiving Bank Account</label>
                                    <?php
                                    $bank_list    = OFP_Bank_Account::list( (int) $client->id );
                                    $current_bank = $editing_post ? (int) get_post_meta( $editing_post->ID, 'ofp_bank_account_id', true ) : 0;
                                    ?>
                                    <select name="bank_account_id" class="ofp-select" style="width:100%; padding:11px 14px; border-radius:8px;">
                                        <option value="0">Default primary account</option>
                                        <?php foreach ( $bank_list as $b ) : ?>
                                            <option value="<?php echo (int) $b->id; ?>" <?php selected( $current_bank, (int) $b->id ); ?>>
                                                <?php echo esc_html( $b->bank_name . ' — ' . $b->account_number . ' (' . $b->account_name . ')' ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Specifications & Condition -->
                        <div class="ofp-form-card-section">
                            <div class="ofp-form-section-header">
                                <div class="ofp-form-section-icon" style="background:rgba(139,92,246,0.1); color:#8b5cf6;">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.32l-3.27 3.287a1.5 1.5 0 01-2.122-2.122l3.287-3.27a4.5 4.5 0 00-6.32 4.486c.048.58.024 1.193-.14 1.743" /></svg>
                                </div>
                                <div>
                                    <h4 class="ofp-form-section-title">3. Specifications &amp; Features</h4>
                                    <p class="ofp-form-section-desc">Room counts, land sizing, construction condition, and furnishing grade.</p>
                                </div>
                            </div>

                            <!-- Spec Counter Cards: Bedrooms, Bathrooms, Parking -->
                            <div class="ofp-specs-card-grid">
                                <div class="ofp-field ofp-spec-counter-card">
                                    <div class="ofp-spec-card-head">
                                        <div class="ofp-spec-card-icon is-blue">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <h5 class="ofp-spec-card-title">Bedrooms</h5>
                                            <p class="ofp-spec-card-sub">En-suite or standard</p>
                                        </div>
                                    </div>
                                    <div class="ofp-spec-stepper-wrap">
                                        <button type="button" class="ofp-spec-stepper-btn ofp-stepper-minus" aria-label="Decrease bedrooms">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12h-15" /></svg>
                                        </button>
                                        <input type="number" name="bedrooms" min="0" placeholder="0" class="ofp-spec-number-input"
                                               value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_bedrooms', true ) : '' ); ?>">
                                        <button type="button" class="ofp-spec-stepper-btn ofp-stepper-plus" aria-label="Increase bedrooms">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </button>
                                    </div>
                                    <div class="ofp-spec-chips-row">
                                        <button type="button" class="ofp-spec-chip" data-val="1">1</button>
                                        <button type="button" class="ofp-spec-chip" data-val="2">2</button>
                                        <button type="button" class="ofp-spec-chip" data-val="3">3</button>
                                        <button type="button" class="ofp-spec-chip" data-val="4">4</button>
                                        <button type="button" class="ofp-spec-chip" data-val="5">5</button>
                                        <button type="button" class="ofp-spec-chip" data-val="6+">6+</button>
                                    </div>
                                </div>

                                <div class="ofp-field ofp-spec-counter-card">
                                    <div class="ofp-spec-card-head">
                                        <div class="ofp-spec-card-icon is-purple">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M9 6 6.5 3.5a1.5 1.5 0 0 0-2.1 2.1l2.5 2.5"/><path d="M2 12h20"/><path d="M4 12v5a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-5"/><path d="M6 20v2"/><path d="M18 20v2"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <h5 class="ofp-spec-card-title">Bathrooms</h5>
                                            <p class="ofp-spec-card-sub">Baths &amp; guest toilets</p>
                                        </div>
                                    </div>
                                    <div class="ofp-spec-stepper-wrap">
                                        <button type="button" class="ofp-spec-stepper-btn ofp-stepper-minus" aria-label="Decrease bathrooms">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12h-15" /></svg>
                                        </button>
                                        <input type="number" name="bathrooms" min="0" placeholder="0" class="ofp-spec-number-input"
                                               value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_bathrooms', true ) : '' ); ?>">
                                        <button type="button" class="ofp-spec-stepper-btn ofp-stepper-plus" aria-label="Increase bathrooms">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </button>
                                    </div>
                                    <div class="ofp-spec-chips-row">
                                        <button type="button" class="ofp-spec-chip" data-val="1">1</button>
                                        <button type="button" class="ofp-spec-chip" data-val="2">2</button>
                                        <button type="button" class="ofp-spec-chip" data-val="3">3</button>
                                        <button type="button" class="ofp-spec-chip" data-val="4">4</button>
                                        <button type="button" class="ofp-spec-chip" data-val="5">5</button>
                                        <button type="button" class="ofp-spec-chip" data-val="6+">6+</button>
                                    </div>
                                </div>

                                <div class="ofp-field ofp-spec-counter-card">
                                    <div class="ofp-spec-card-head">
                                        <div class="ofp-spec-card-icon is-orange">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="3" y="11" width="18" height="9" rx="2"/><circle cx="7.5" cy="16.5" r="1.5"/><circle cx="16.5" cy="16.5" r="1.5"/><path d="M5 11l2-5h10l2 5"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <h5 class="ofp-spec-card-title">Parking Spaces</h5>
                                            <p class="ofp-spec-card-sub">Secured bays / garage</p>
                                        </div>
                                    </div>
                                    <div class="ofp-spec-stepper-wrap">
                                        <button type="button" class="ofp-spec-stepper-btn ofp-stepper-minus" aria-label="Decrease parking spaces">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12h-15" /></svg>
                                        </button>
                                        <input type="number" name="parking" min="0" placeholder="0" class="ofp-spec-number-input"
                                               value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_parking', true ) : '' ); ?>">
                                        <button type="button" class="ofp-spec-stepper-btn ofp-stepper-plus" aria-label="Increase parking spaces">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </button>
                                    </div>
                                    <div class="ofp-spec-chips-row">
                                        <button type="button" class="ofp-spec-chip" data-val="1">1</button>
                                        <button type="button" class="ofp-spec-chip" data-val="2">2</button>
                                        <button type="button" class="ofp-spec-chip" data-val="3">3</button>
                                        <button type="button" class="ofp-spec-chip" data-val="4">4</button>
                                        <button type="button" class="ofp-spec-chip" data-val="5">5</button>
                                        <button type="button" class="ofp-spec-chip" data-val="6+">6+</button>
                                    </div>
                                </div>
                            </div>

                            <!-- Sizing & Land Footprint Card -->
                            <div class="ofp-field ofp-sizing-card">
                                <div class="ofp-sizing-card-header">
                                    <div class="ofp-sizing-card-title-group">
                                        <div class="ofp-spec-card-icon is-green">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="12" y1="7" x2="12" y2="17"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <h5 class="ofp-spec-card-title">Total Area &amp; Land Footprint</h5>
                                            <p class="ofp-spec-card-sub">Gross built area or land footprint in square meters</p>
                                        </div>
                                    </div>
                                    
                                    <?php
                                    $curr_sqm   = $editing_post ? (float) get_post_meta( $editing_post->ID, 'ofp_area_sqm', true ) : 0;
                                    $curr_plots = $editing_post ? (float) get_post_meta( $editing_post->ID, 'ofp_plots_count', true ) : 0;
                                    $init_label = 'Standard Lagos Plot = 600 SQM (60ft × 120ft)';
                                    if ( $curr_sqm > 0 || $curr_plots > 0 ) {
                                        $bk = class_exists( 'OFP_Property_Land' ) ? OFP_Property_Land::calculate( $curr_sqm, 0, $curr_plots ) : [];
                                        if ( ! empty( $bk['plot_label'] ) ) {
                                            $init_label = $bk['plot_label'];
                                        }
                                    }
                                    ?>
                                    <!-- Live Nigerian Plot Benchmark Badge -->
                                    <div id="ofp-sqm-live-badge" class="ofp-benchmark-live-badge">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                        </svg>
                                        <span id="ofp-sqm-live-text"><?php echo esc_html( $init_label ); ?></span>
                                    </div>
                                </div>

                                <div class="ofp-input-unit-wrap">
                                    <input type="number" name="area_sqm" min="0" placeholder="e.g. 600"
                                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_area_sqm', true ) : '' ); ?>">
                                    <div class="ofp-input-unit-tag">SQM (m²)</div>
                                </div>

                                <!-- Quick Plot Preset Clickers -->
                                <div class="ofp-preset-clickers-row">
                                    <span class="ofp-preset-label">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                        Plot Presets:
                                    </span>
                                    <button type="button" class="ofp-sqm-preset-btn" data-sqm="300" data-plots="0.5">Half Plot (300m²)</button>
                                    <button type="button" class="ofp-sqm-preset-btn" data-sqm="600" data-plots="1">1 Plot (600m²)</button>
                                    <button type="button" class="ofp-sqm-preset-btn" data-sqm="1200" data-plots="2">2 Plots (1,200m²)</button>
                                    <button type="button" class="ofp-sqm-preset-btn" data-sqm="1800" data-plots="3">3 Plots (1,800m²)</button>
                                    <button type="button" class="ofp-sqm-preset-btn" data-sqm="4047" data-plots="6.7">1 Acre (4,047m²)</button>
                                    <button type="button" class="ofp-sqm-preset-btn" data-sqm="10000" data-plots="16.7">1 Hectare (10,000m²)</button>
                                </div>
                            </div>

                            <!-- Condition & Furnishing Grid -->
                            <div class="ofp-condition-furnishing-grid">
                                <div class="ofp-field ofp-meta-picker-card">
                                    <div class="ofp-spec-card-head">
                                        <div class="ofp-spec-card-icon is-blue">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <h5 class="ofp-spec-card-title">Construction Condition</h5>
                                            <p class="ofp-spec-card-sub">State of build and finishes</p>
                                        </div>
                                    </div>
                                    <?php $current_condition = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_condition', true ) : ''; ?>
                                    <select name="condition" class="ofp-select-custom">
                                        <option value="">-- Select Condition --</option>
                                        <option value="Newly Built" <?php selected( $current_condition, 'Newly Built' ); ?>>Newly Built (Turnkey / Mint)</option>
                                        <option value="Fairly Used" <?php selected( $current_condition, 'Fairly Used' ); ?>>Fairly Used (Move-in Ready)</option>
                                        <option value="Renovation Needed" <?php selected( $current_condition, 'Renovation Needed' ); ?>>Renovation Needed (Distressed / TLC)</option>
                                        <option value="Under Construction" <?php selected( $current_condition, 'Under Construction' ); ?>>Under Construction (Ongoing Build)</option>
                                        <option value="Off-Plan" <?php selected( $current_condition, 'Off-Plan' ); ?>>Off-Plan (Pre-construction)</option>
                                    </select>
                                </div>

                                <div class="ofp-field ofp-meta-picker-card">
                                    <div class="ofp-spec-card-head">
                                        <div class="ofp-spec-card-icon is-purple">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M19 9V6a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v3"/><path d="M3 11v5a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5a2 2 0 0 0-4 0v2H7v-2a2 2 0 0 0-4 0Z"/><path d="M5 18v2"/><path d="M19 18v2"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <h5 class="ofp-spec-card-title">Furnishing Grade</h5>
                                            <p class="ofp-spec-card-sub">Fixtures, appliances &amp; interiors</p>
                                        </div>
                                    </div>
                                    <?php $current_furnishing = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_furnishing', true ) : ''; ?>
                                    <select name="furnishing" class="ofp-select-custom">
                                        <option value="">-- Select Furnishing --</option>
                                        <option value="Furnished" <?php selected( $current_furnishing, 'Furnished' ); ?>>Fully Furnished (Turnkey)</option>
                                        <option value="Semi-Furnished" <?php selected( $current_furnishing, 'Semi-Furnished' ); ?>>Semi-Furnished (Kitchen / Wardrobes / ACs)</option>
                                        <option value="Unfurnished" <?php selected( $current_furnishing, 'Unfurnished' ); ?>>Unfurnished (Bare Shell)</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Dedicated Nigerian Land Breakdown Fields (visible when Land is selected) -->
                            <?php
                            $current_plots   = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_plots_count', true ) : '';
                            $current_topo    = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_land_topography', true ) : '';
                            $current_zoning  = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_land_zoning', true ) : '';
                            $current_dims    = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_land_dimensions', true ) : '';
                            $current_survey  = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_survey_status', true ) : '';
                            $is_land_listing = ( $editing_post && get_post_meta( $editing_post->ID, 'ofp_listing_type', true ) === 'land' );
                            ?>
                            <div id="ofp-land-specifics-box" class="ofp-land-studio-box" style="<?php echo $is_land_listing ? 'display:block;' : 'display:none;'; ?>">
                                <div class="ofp-land-studio-header">
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <div class="ofp-spec-card-icon is-green">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
                                            </svg>
                                        </div>
                                        <div>
                                            <h5 class="ofp-spec-card-title">Nigerian Land Sizing &amp; Boundary Specs</h5>
                                            <p class="ofp-spec-card-sub">Registry parameters, zoning, topography &amp; survey beacons</p>
                                        </div>
                                    </div>
                                    <span class="ofp-land-studio-badge">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        Lagos / NG Standard (1 Plot = 600 SQM)
                                    </span>
                                </div>

                                <div class="ofp-land-grid">
                                    <div class="ofp-field">
                                        <label style="font-size:12.5px; font-weight:700; color:var(--text-main); margin-bottom:6px; display:block;">
                                            Number of Plots <span class="ofp-hint" style="display:inline; margin:0; font-size:11px; color:var(--text-muted);">(e.g. 1, 0.5, 2, 6)</span>
                                        </label>
                                        <div class="ofp-input-unit-wrap">
                                            <input type="number" step="0.1" min="0" name="plots_count" id="ofp_portal_plots_input" placeholder="e.g. 1"
                                                   value="<?php echo esc_attr( $current_plots ); ?>">
                                            <div class="ofp-input-unit-tag">Plots</div>
                                        </div>
                                        <span style="display:block; font-size:11.5px; color:var(--text-muted); margin-top:6px;" id="ofp_plots_hint_preview">
                                            Auto-syncs with Area (SQM)
                                        </span>
                                    </div>

                                    <div class="ofp-field">
                                        <label style="font-size:12.5px; font-weight:700; color:var(--text-main); margin-bottom:6px; display:block;">Topography</label>
                                        <select name="land_topography" class="ofp-select-custom">
                                            <option value="">-- Select Topography --</option>
                                            <option value="100% Dry Land" <?php selected( $current_topo, '100% Dry Land' ); ?>>100% Dry Land</option>
                                            <option value="Dry Table Land" <?php selected( $current_topo, 'Dry Table Land' ); ?>>Dry Table Land</option>
                                            <option value="Mixed / Firm Land" <?php selected( $current_topo, 'Mixed / Firm Land' ); ?>>Mixed / Firm Land</option>
                                            <option value="Sandfilling Required" <?php selected( $current_topo, 'Sandfilling Required' ); ?>>Sandfilling Required</option>
                                            <option value="Waterfront Land" <?php selected( $current_topo, 'Waterfront Land' ); ?>>Waterfront Land</option>
                                        </select>
                                    </div>

                                    <div class="ofp-field">
                                        <label style="font-size:12.5px; font-weight:700; color:var(--text-main); margin-bottom:6px; display:block;">Zoning / Land Use</label>
                                        <select name="land_zoning" class="ofp-select-custom">
                                            <option value="">-- Select Zoning --</option>
                                            <option value="Residential" <?php selected( $current_zoning, 'Residential' ); ?>>Residential</option>
                                            <option value="Commercial" <?php selected( $current_zoning, 'Commercial' ); ?>>Commercial</option>
                                            <option value="Mixed-Use" <?php selected( $current_zoning, 'Mixed-Use' ); ?>>Mixed-Use (Commercial &amp; Residential)</option>
                                            <option value="Industrial" <?php selected( $current_zoning, 'Industrial' ); ?>>Industrial</option>
                                            <option value="Agricultural" <?php selected( $current_zoning, 'Agricultural' ); ?>>Agricultural / Farm</option>
                                        </select>
                                    </div>

                                    <div class="ofp-field">
                                        <label style="font-size:12.5px; font-weight:700; color:var(--text-main); margin-bottom:6px; display:block;">Land Dimensions</label>
                                        <div class="ofp-input-unit-wrap">
                                            <input type="text" name="land_dimensions" placeholder="e.g. 60ft × 120ft (18m × 36m)"
                                                   value="<?php echo esc_attr( $current_dims ); ?>">
                                        </div>
                                    </div>

                                    <div class="ofp-field ofp-field-full">
                                        <label style="font-size:12.5px; font-weight:700; color:var(--text-main); margin-bottom:6px; display:block;">Survey &amp; Boundary Status</label>
                                        <select name="survey_status" class="ofp-select-custom">
                                            <option value="">-- Select Survey / Boundary Status --</option>
                                            <option value="Registered Survey with Coordinates" <?php selected( $current_survey, 'Registered Survey with Coordinates' ); ?>>Registered Survey with Coordinates</option>
                                            <option value="Provisional Survey / Layout" <?php selected( $current_survey, 'Provisional Survey / Layout' ); ?>>Provisional Survey / Layout</option>
                                            <option value="Perimeter Fenced &amp; Gated" <?php selected( $current_survey, 'Perimeter Fenced &amp; Gated' ); ?>>Perimeter Fenced &amp; Gated</option>
                                            <option value="Beaconed / Pegged" <?php selected( $current_survey, 'Beaconed / Pegged' ); ?>>Beaconed / Pegged</option>
                                            <option value="Survey in Progress" <?php selected( $current_survey, 'Survey in Progress' ); ?>>Survey in Progress</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 4. Location & Media -->
                        <div class="ofp-form-card-section">
                            <div class="ofp-form-section-header">
                                <div class="ofp-form-section-icon" style="background:rgba(217,119,6,0.1); color:#d97706;">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
                                </div>
                                <div>
                                    <h4 class="ofp-form-section-title">4. Location &amp; Media</h4>
                                    <p class="ofp-form-section-desc">Physical address, video tour link, and high-resolution photo uploads.</p>
                                </div>
                            </div>

                            <div class="ofp-form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:18px;">
                                <div class="ofp-field" style="grid-column:1/-1;">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Location / Address <span style="color:#ef4444;">*</span></label>
                                    <input type="text" name="location_text" required placeholder="e.g. Lekki Phase 1, off Admiralty Way, Lagos"
                                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_location_text', true ) : '' ); ?>"
                                           style="width:100%; padding:11px 14px; border-radius:8px;">
                                </div>

                                <div class="ofp-field" style="grid-column:1/-1;">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Video Tour URL <span class="ofp-hint" style="display:inline;margin:0;">(Optional)</span></label>
                                    <input type="url" name="video_url" placeholder="https://www.youtube.com/watch?v=... or Vimeo"
                                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_video_url', true ) : '' ); ?>"
                                           style="width:100%; padding:11px 14px; border-radius:8px;">
                                </div>

                                <div class="ofp-field" style="grid-column:1/-1;">
                                    <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">
                                        Upload Photos <span class="ofp-hint" style="display:inline;margin:0;">(Select multiple images or drag &amp; drop. Choose your main cover photo)</span>
                                    </label>

                                    <!-- Dropzone -->
                                    <div class="ofp-file-dropzone" id="ofp-photos-dropzone">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:36px;height:36px;color:var(--text-muted);margin:0 auto 10px;display:block;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" /></svg>
                                        <div style="font-size:14px; font-weight:600; color:var(--text-main); margin-bottom:4px;">Select photos or drag and drop</div>
                                        <div style="font-size:12.5px; color:var(--text-muted); margin-bottom:10px;">Supports PNG, JPG, JPEG, WEBP. You can select multiple images from your device.</div>
                                        <div style="display:inline-flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:center;">
                                            <button type="button" class="ofp-btn-browse-photos" id="ofp-btn-browse">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                                <span>Choose Photos</span>
                                            </button>
                                            <button type="button" class="ofp-btn-browse-photos" id="ofp-btn-add-more" style="display:none; background:rgba(16,185,129,0.12); color:#10b981; border-color:rgba(16,185,129,0.3);">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                                <span>+ Add More Photos</span>
                                            </button>
                                        </div>
                                        <input type="file" id="ofp-photos-file-input" name="photos[]" accept="image/png,image/jpeg,image/webp,image/jpg" multiple style="display:none;">
                                        <input type="hidden" name="primary_photo_index" id="ofp-primary-photo-index" value="0">
                                        <input type="hidden" name="replace_main_with_new_primary" id="ofp-replace-main" value="0">
                                        <div id="ofp-uploader-status" style="margin-top:12px; font-size:13px; font-weight:600; color:var(--accent-blue);"></div>
                                    </div>

                                    <!-- Live Previews Grid for Newly Selected Files -->
                                    <div id="ofp-photo-previews" class="ofp-photo-previews-grid" style="display:none;"></div>

                                    <!-- Existing Uploaded Photos (When Editing) -->
                                    <?php if ( $editing_post ) :
                                        $post_thumb_id  = get_post_thumbnail_id( $editing_post->ID );
                                        $post_thumb_url = $post_thumb_id ? wp_get_attachment_image_url( $post_thumb_id, 'medium' ) : '';
                                        $gallery_meta   = get_post_meta( $editing_post->ID, 'ofp_gallery_ids', true );
                                        $gallery_ids    = json_decode( $gallery_meta ?: '[]', true ) ?: [];
                                    ?>
                                        <?php if ( $post_thumb_url || ! empty( $gallery_ids ) ) : ?>
                                            <div style="margin-top:20px; padding-top:16px; border-top:1px solid var(--border-color);" id="ofp-existing-gallery-section">
                                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
                                                    <div style="font-size:13px; font-weight:600; color:var(--text-main);">
                                                        Currently Uploaded Listing Photos:
                                                    </div>
                                                    <span style="font-size:12px; color:var(--text-muted);">Click &times; to remove or "Set Main" to change cover</span>
                                                </div>
                                                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(130px, 1fr)); gap:14px;" id="ofp-existing-photos-grid">
                                                    <?php if ( $post_thumb_url ) : ?>
                                                        <div class="ofp-existing-photo-card is-main" id="ofp-existing-thumb-card">
                                                            <img src="<?php echo esc_url( $post_thumb_url ); ?>" alt="Main Photo">
                                                            <span class="ofp-photo-badge ofp-badge-main">Main Cover</span>
                                                            <button type="button" class="ofp-photo-remove-btn" title="Delete main photo" onclick="ofpRemoveExistingThumb();">&times;</button>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php foreach ( $gallery_ids as $gid ) :
                                                        $g_url = wp_get_attachment_image_url( (int) $gid, 'medium' ) ?: wp_get_attachment_image_url( (int) $gid, 'thumbnail' );
                                                        if ( ! $g_url ) continue;
                                                    ?>
                                                        <div class="ofp-existing-photo-card" id="ofp-existing-photo-<?php echo (int) $gid; ?>">
                                                            <img src="<?php echo esc_url( $g_url ); ?>" alt="Gallery Photo">
                                                            <span class="ofp-photo-badge ofp-badge-set-main" onclick="ofpSetExistingMain(<?php echo (int) $gid; ?>);" title="Make this the cover photo">Set Main</span>
                                                            <button type="button" class="ofp-photo-remove-btn" title="Remove photo" onclick="ofpRemoveExistingGallery(<?php echo (int) $gid; ?>);">&times;</button>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div id="ofp-removed-photos-inputs"></div>
                                                <input type="hidden" name="delete_main_thumbnail" id="ofp-delete-main-input" value="0">
                                                <input type="hidden" name="set_main_attachment_id" id="ofp-set-main-input" value="0">
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 5 & 6 Dual Row (50% / 50%) -->
                        <div class="ofp-form-row-duo">
                            <!-- 5. Features & Amenities (Scrollable + Add Custom) -->
                            <div class="ofp-form-card-section" style="display:flex; flex-direction:column;">
                                <div class="ofp-form-section-header" style="margin-bottom:14px; padding-bottom:12px;">
                                    <div class="ofp-form-section-icon" style="background:rgba(236,72,153,0.1); color:#ec4899;">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" /></svg>
                                    </div>
                                    <div style="flex:1;">
                                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
                                            <h4 class="ofp-form-section-title">5. Features &amp; Amenities</h4>
                                            <div style="display:flex; gap:6px;">
                                                <button type="button" class="ofp-mini-chip-btn" id="ofp-btn-amenities-select-all" title="Select All">+ All</button>
                                                <button type="button" class="ofp-mini-chip-btn" id="ofp-btn-amenities-clear-all" title="Clear All">&times; Clear</button>
                                            </div>
                                        </div>
                                        <p class="ofp-form-section-desc" style="font-size:12px;">Select on-site features or type below to add custom amenities.</p>
                                    </div>
                                </div>

                                <!-- Add Custom Amenity Input Bar -->
                                <div class="ofp-add-amenity-bar">
                                    <input type="text" id="ofp-new-amenity-input" class="ofp-add-amenity-input" placeholder="Type custom amenity (e.g. Solar Inverter, Rooftop Bar)...">
                                    <button type="button" id="ofp-btn-add-custom-amenity" class="ofp-add-amenity-btn">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                        <span>Add</span>
                                    </button>
                                </div>

                                <?php
                                $catalog_amenities = class_exists( 'OFP_Property_Amenities' ) ? OFP_Property_Amenities::get_all() : [];
                                $selected_amenities = $editing_post ? ( json_decode( get_post_meta( $editing_post->ID, 'ofp_amenities', true ) ?: '[]', true ) ?: [] ) : [];

                                // Make sure any selected custom amenities on the current post are in the catalog
                                foreach ( $selected_amenities as $s_key ) {
                                    if ( ! isset( $catalog_amenities[ $s_key ] ) ) {
                                        $label = class_exists( 'OFP_Property_Amenities' ) ? OFP_Property_Amenities::get_label( $s_key ) : ucwords( str_replace( '_', ' ', $s_key ) );
                                        $catalog_amenities[ $s_key ] = [
                                            'label'    => $label,
                                            'icon_svg' => class_exists( 'OFP_Property_Amenities' ) ? OFP_Property_Amenities::get_icon_svg( $s_key, 'w-4 h-4' ) : '',
                                        ];
                                    }
                                }
                                ?>

                                <!-- Scrollable Amenities List -->
                                <div class="ofp-amenities-scrollable-container" id="ofp-amenities-grid-container">
                                    <?php foreach ( $catalog_amenities as $key => $adata ) :
                                        $is_checked = in_array( $key, $selected_amenities, true ) || in_array( $adata['label'], $selected_amenities, true );
                                        $svg_icon = ! empty( $adata['icon_svg'] ) ? $adata['icon_svg'] : ( class_exists( 'OFP_Property_Amenities' ) ? OFP_Property_Amenities::get_icon_svg( $key . ' ' . $adata['label'], 'w-4 h-4' ) : '' );
                                    ?>
                                        <label class="ofp-amenity-card <?php echo $is_checked ? 'is-checked' : ''; ?>">
                                            <input type="checkbox" name="amenities[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $is_checked ); ?>>
                                            <span class="ofp-amenity-icon-mini"><?php echo $svg_icon; ?></span>
                                            <span><?php echo esc_html( $adata['label'] ); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- 6. Inspection & Viewing Schedule -->
                            <div class="ofp-form-card-section" style="display:flex; flex-direction:column;">
                                <div class="ofp-form-section-header" style="margin-bottom:14px; padding-bottom:12px;">
                                    <div class="ofp-form-section-icon" style="background:rgba(16,185,129,0.1); color:#10b981;">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z" /></svg>
                                    </div>
                                    <div>
                                        <h4 class="ofp-form-section-title">6. Inspection &amp; Viewing Schedule</h4>
                                        <p class="ofp-form-section-desc" style="font-size:12px;">Set showing days, hours, and gate clearance notice.</p>
                                    </div>
                                </div>

                                <div class="ofp-form-grid" style="display:grid; grid-template-columns:1fr; gap:14px; flex:1;">
                                    <div class="ofp-field">
                                        <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:5px; display:block;">Available Inspection Days</label>
                                        <input type="text" name="inspection_days" placeholder="e.g. Mondays - Saturdays, or Weekends Only"
                                               value="<?php echo esc_attr( $editing_post ? ( get_post_meta( $editing_post->ID, 'ofp_inspection_days', true ) ?: 'Mondays - Saturdays' ) : 'Mondays - Saturdays' ); ?>"
                                               style="width:100%; padding:10px 14px; border-radius:8px;">
                                        <span class="ofp-hint" style="font-size:11px; margin-top:3px; display:block;">Days available for physical tours &amp; video walkthroughs.</span>
                                    </div>

                                    <div class="ofp-field">
                                        <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:5px; display:block;">Available Inspection Hours</label>
                                        <input type="text" name="inspection_hours" placeholder="e.g. 10:00 AM - 5:00 PM"
                                               value="<?php echo esc_attr( $editing_post ? ( get_post_meta( $editing_post->ID, 'ofp_inspection_hours', true ) ?: '10:00 AM - 5:00 PM' ) : '10:00 AM - 5:00 PM' ); ?>"
                                               style="width:100%; padding:10px 14px; border-radius:8px;">
                                        <span class="ofp-hint" style="font-size:11px; margin-top:3px; display:block;">Daily inspection time window.</span>
                                    </div>

                                    <div class="ofp-field">
                                        <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:5px; display:block;">Estate Gate Access &amp; Notice Protocol</label>
                                        <input type="text" name="inspection_notice" placeholder="e.g. 24 hours advance notice required for estate security clearance code."
                                               value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_inspection_notice', true ) : '' ); ?>"
                                               style="width:100%; padding:10px 14px; border-radius:8px;">
                                        <span class="ofp-hint" style="font-size:11px; margin-top:3px; display:block;">Displayed on single property booking card for entry requirements.</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 7. Status & Publishing Settings -->
                        <div class="ofp-form-card-section">
                            <div class="ofp-form-section-header">
                                <div class="ofp-form-section-icon" style="background:rgba(59,130,246,0.1); color:var(--accent-blue);">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" /></svg>
                                </div>
                                <div>
                                    <h4 class="ofp-form-section-title">7. Status &amp; Visibility</h4>
                                    <p class="ofp-form-section-desc">Manage public visibility, availability status, and featured promotion.</p>
                                </div>
                            </div>

                            <div class="ofp-form-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:18px;">
                                <?php if ( $editing_post ) : ?>
                                    <div class="ofp-field">
                                        <label style="font-size:13px; font-weight:600; color:var(--text-main); margin-bottom:6px; display:block;">Listing Status</label>
                                        <?php $current_status = get_post_meta( $editing_post->ID, 'ofp_status', true ); ?>
                                        <select name="status" class="ofp-select" style="width:100%; padding:11px 14px; border-radius:8px;">
                                            <option value="pending_upload" <?php selected( $current_status, 'pending_upload' ); ?>>Pending Upload / Draft</option>
                                            <option value="live" <?php selected( $current_status, 'live' ); ?>>Live (Publicly visible)</option>
                                            <option value="taken" <?php selected( $current_status, 'taken' ); ?>>Taken / Sold / Rented</option>
                                            <option value="expired" <?php selected( $current_status, 'expired' ); ?>>Expired</option>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <?php
                                $can_feature = $active_plan !== 'free';
                                $is_featured = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_is_featured', true ) : '0';
                                ?>
                                <div class="ofp-field" style="<?php echo $editing_post ? '' : 'grid-column:1/-1;'; ?> display:flex; flex-direction:column; justify-content:center;">
                                    <label class="ofp-amenity-card" style="margin-top:<?php echo $editing_post ? '24px' : '0'; ?>;">
                                        <input type="checkbox" name="is_featured" value="1"
                                            <?php checked( $is_featured, '1' ); ?>
                                            <?php disabled( ! $can_feature, true ); ?>>
                                        <span style="font-weight:600;">Featured Property (Highlight at the top of directory results)</span>
                                    </label>
                                    <?php if ( ! $can_feature ) : ?>
                                        <span style="color:#ef4444; font-size:12px; margin-top:4px;">(Featured promotion requires Silver or Gold plan)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 7. Actions Bar -->
                        <div class="ofp-form-actions-bar">
                            <button type="submit" name="ofp_save_property" value="1" class="ofp-btn-form-save">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" style="width:17px;height:17px;"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                <span><?php echo $editing_post ? 'Save Changes' : 'Publish Property'; ?></span>
                            </button>
                            <a href="<?php echo esc_url( add_query_arg( 'tab', 'table', home_url( '/properties' ) ) ); ?>" data-tab="table" class="ofp-btn-form-cancel">
                                Cancel
                            </a>
                            <?php if ( $editing_post ) : ?>
                                <button type="button" class="ofp-btn-form-delete" onclick="if(confirm('Are you sure you want to permanently delete this property listing?')){document.getElementById('ofp-delete-property-form-direct').submit();}">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                    <span>Delete Property</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if ( $editing_post ) : ?>
                        <form id="ofp-delete-property-form-direct" method="POST" style="display:none;">
                            <?php wp_nonce_field( 'ofp_delete_property_action', 'ofp_delete_nonce' ); ?>
                            <input type="hidden" name="property_id" value="<?php echo esc_attr( $editing_post->ID ); ?>">
                            <input type="hidden" name="ofp_delete_property" value="1">
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

            </div><!-- #ofp-prop-pane-form -->

            <script>
            (function() {
                function switchPropTab(targetTab, updateHistory) {
                    if (!targetTab) return;
                    var targetPane = document.getElementById('ofp-prop-pane-' + targetTab);
                    if (!targetPane) return;

                    // Toggle tabs active state
                    document.querySelectorAll('.ofp-segmented-tab[data-tab]').forEach(function(t) {
                        t.classList.toggle('active', t.getAttribute('data-tab') === targetTab);
                    });

                    // Toggle pane visibility
                    document.querySelectorAll('.ofp-prop-pane').forEach(function(p) {
                        p.style.display = (p.id === 'ofp-prop-pane-' + targetTab) ? '' : 'none';
                    });

                    // Update URL via pushState
                    if (updateHistory !== false && window.history && window.history.pushState) {
                        var url = new URL(window.location.href);
                        if (targetTab === 'table') {
                            url.searchParams.delete('tab');
                            url.searchParams.delete('edit');
                        } else {
                            url.searchParams.set('tab', targetTab);
                        }
                        window.history.pushState({ propTab: targetTab }, '', url.toString());
                    }
                }

                document.addEventListener('DOMContentLoaded', function() {
                    document.querySelectorAll('[data-tab="table"], [data-tab="form"]').forEach(function(el) {
                        el.addEventListener('click', function(e) {
                            var tab = this.getAttribute('data-tab');
                            if (tab && document.getElementById('ofp-prop-pane-' + tab)) {
                                e.preventDefault();
                                switchPropTab(tab, true);
                            }
                        });
                    });

                    window.addEventListener('popstate', function() {
                        var params = new URLSearchParams(window.location.search);
                        var tab = params.get('tab') || 'table';
                        if (document.getElementById('ofp-prop-pane-' + tab)) {
                            switchPropTab(tab, false);
                        }
                    });
                });
            })();
            </script>
        </div>
    </div>
</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<script>
(function(){
    var form = document.querySelector('select[name="listing_type"]');
    if (!form) return;
    function field(name){ var e = document.querySelector('[name="' + name + '"]'); return e ? e.closest('.ofp-field') : null; }
    var landBox = document.getElementById('ofp-land-specifics-box');

    function sync(){
        var land = form.value === 'land';
        ['bedrooms','bathrooms','parking','furnishing','condition'].forEach(function(n){
            var f = field(n); if (f) f.style.display = land ? 'none' : '';
        });
        if (landBox) landBox.style.display = land ? 'block' : 'none';

        var pt = document.querySelector('select[name="property_type"]');
        var pp = document.querySelector('select[name="price_period"]');
        if (land) {
            if (pt) pt.value = 'land';
            if (pp) pp.value = 'sales';
        }
    }
    form.addEventListener('change', sync);
    sync();

    // Auto-calculate SQM <-> Plots (1 standard Lagos plot = 600 SQM)
    var plotsInput = document.getElementById('ofp_portal_plots_input');
    var sqmInput = document.querySelector('input[name="area_sqm"]');
    var plotsHint = document.getElementById('ofp_plots_hint_preview');
    var sqmLiveText = document.getElementById('ofp-sqm-live-text');

    function computePlotDescription(sqm, plots) {
        if (sqm <= 0 && plots <= 0) {
            return 'Standard Lagos Plot = 600 SQM (60ft × 120ft)';
        }
        if (sqm <= 0 && plots > 0) sqm = plots * 600;
        if (plots <= 0 && sqm > 0) plots = sqm / 600;

        if (sqm >= 280 && sqm <= 325) {
            return 'Half Plot (~300 SQM / ~3,229 Sq Ft / 50ft × 60ft)';
        } else if (sqm >= 580 && sqm <= 650) {
            return '1 Standard Plot (~600 SQM / ~6,458 Sq Ft / 60ft × 120ft)';
        } else if (sqm >= 880 && sqm <= 950) {
            return '1.5 Plots (~900 SQM)';
        } else if (sqm >= 1150 && sqm <= 1300) {
            return '2 Standard Plots (~1,200 SQM / ~12,917 Sq Ft / 120ft × 120ft)';
        } else if (sqm >= 1700 && sqm <= 1900) {
            return '3 Standard Plots (~1,800 SQM)';
        } else if (sqm >= 2300 && sqm <= 2500) {
            return '4 Standard Plots (~2,400 SQM)';
        } else if (sqm >= 3900 && sqm <= 4200) {
            return '1 Acre (~6 Standard Plots / 4,047 SQM)';
        } else if (sqm >= 7800 && sqm <= 8400) {
            return '2 Acres (~12 Standard Plots)';
        } else if (sqm >= 9800 && sqm <= 10500) {
            return '1 Hectare (~16.7 Plots / 10,000 SQM)';
        } else {
            var roundedPlots = Math.round(plots * 10) / 10;
            return roundedPlots + ' Plot(s) (' + Math.round(sqm).toLocaleString() + ' SQM)';
        }
    }

    function updatePlotHint(plots, sqm) {
        var desc = computePlotDescription(sqm, plots);
        if (sqmLiveText) sqmLiveText.textContent = desc;
        if (plotsHint) {
            if (plots > 0 && sqm > 0) {
                plotsHint.textContent = plots + ' Plot(s) = ' + sqm.toLocaleString() + ' SQM (~' + Math.round(sqm * 10.764).toLocaleString() + ' Sq Ft)';
            } else {
                plotsHint.textContent = desc;
            }
        }
    }

    // Quick Plot Preset clickers
    document.querySelectorAll('.ofp-sqm-preset-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var s = parseFloat(btn.dataset.sqm) || 0;
            var p = parseFloat(btn.dataset.plots) || 0;
            if (sqmInput) {
                sqmInput.value = s;
                sqmInput.dataset.autoCalc = '1';
            }
            if (plotsInput) {
                plotsInput.value = p;
                plotsInput.dataset.autoCalc = '1';
            }
            updatePlotHint(p, s);
        });
    });

    if (plotsInput && sqmInput) {
        plotsInput.addEventListener('input', function() {
            var p = parseFloat(plotsInput.value);
            if (!isNaN(p) && p > 0) {
                if (!sqmInput.value || sqmInput.dataset.autoCalc === '1') {
                    sqmInput.value = Math.round(p * 600);
                    sqmInput.dataset.autoCalc = '1';
                }
            }
            updatePlotHint(parseFloat(plotsInput.value) || 0, parseFloat(sqmInput.value) || 0);
        });

        sqmInput.addEventListener('input', function() {
            sqmInput.dataset.autoCalc = '0';
            var s = parseFloat(sqmInput.value);
            if (!isNaN(s) && s > 0 && (!plotsInput.value || plotsInput.dataset.autoCalc === '1')) {
                var derivedPlots = Math.round((s / 600) * 10) / 10;
                plotsInput.value = derivedPlots;
                plotsInput.dataset.autoCalc = '1';
            }
            updatePlotHint(parseFloat(plotsInput.value) || 0, parseFloat(sqmInput.value) || 0);
        });

        updatePlotHint(parseFloat(plotsInput.value) || 0, parseFloat(sqmInput.value) || 0);
    } else if (sqmInput) {
        sqmInput.addEventListener('input', function() {
            var s = parseFloat(sqmInput.value) || 0;
            updatePlotHint(0, s);
        });
        updatePlotHint(0, parseFloat(sqmInput.value) || 0);
    }
})();

// ── Room Spec Steppers & Quick Chips Synchronizer ──
(function(){
    document.querySelectorAll('.ofp-spec-counter-card').forEach(function(card) {
        var input = card.querySelector('.ofp-spec-number-input');
        var minusBtn = card.querySelector('.ofp-stepper-minus');
        var plusBtn = card.querySelector('.ofp-stepper-plus');
        var chips = card.querySelectorAll('.ofp-spec-chip');
        if (!input) return;

        function syncChips(val) {
            var num = parseInt(val, 10);
            chips.forEach(function(chip) {
                var cVal = chip.dataset.val;
                if (cVal === '6+') {
                    chip.classList.toggle('is-active', !isNaN(num) && num >= 6);
                } else {
                    chip.classList.toggle('is-active', !isNaN(num) && num === parseInt(cVal, 10));
                }
            });
        }

        if (minusBtn) {
            minusBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var cur = parseInt(input.value, 10) || 0;
                var next = Math.max(0, cur - 1);
                input.value = next > 0 ? next : '';
                syncChips(input.value);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        if (plusBtn) {
            plusBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var cur = parseInt(input.value, 10) || 0;
                var next = cur + 1;
                input.value = next;
                syncChips(next);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        chips.forEach(function(chip) {
            chip.addEventListener('click', function(e) {
                e.preventDefault();
                var val = chip.dataset.val;
                if (val === '6+') {
                    var cur = parseInt(input.value, 10) || 0;
                    input.value = cur >= 6 ? cur : 6;
                } else {
                    input.value = val;
                }
                syncChips(input.value);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        input.addEventListener('input', function() {
            syncChips(input.value);
        });

        // Initialize state on page load
        if (input.value) {
            syncChips(input.value);
        }
    });

    // Also highlight active plot preset button
    var sqmInput = document.querySelector('input[name="area_sqm"]');
    var presetBtns = document.querySelectorAll('.ofp-sqm-preset-btn');
    function syncPresetBtns() {
        if (!sqmInput) return;
        var curSqm = parseFloat(sqmInput.value) || 0;
        presetBtns.forEach(function(b) {
            var bSqm = parseFloat(b.dataset.sqm) || 0;
            b.classList.toggle('is-active', Math.abs(curSqm - bSqm) < 2);
        });
    }
    if (sqmInput) {
        sqmInput.addEventListener('input', syncPresetBtns);
        presetBtns.forEach(function(b) {
            b.addEventListener('click', function() {
                setTimeout(syncPresetBtns, 10);
            });
        });
        syncPresetBtns();
    }
})();

// ── Multi-Photo Uploader Logic ──
(function(){
    var fileInput = document.getElementById('ofp-photos-file-input');
    var dropzone = document.getElementById('ofp-photos-dropzone');
    var browseBtn = document.getElementById('ofp-btn-browse');
    var addMoreBtn = document.getElementById('ofp-btn-add-more');
    var previewContainer = document.getElementById('ofp-photo-previews');
    var statusText = document.getElementById('ofp-uploader-status');
    var primaryIndexInput = document.getElementById('ofp-primary-photo-index');
    var replaceMainInput = document.getElementById('ofp-replace-main');

    if (!fileInput || !dropzone) return;

    var selectedFiles = []; // Accumulated Array of File objects
    var primaryIndex = 0;

    function syncInputFiles() {
        if (window.DataTransfer) {
            var dt = new DataTransfer();
            selectedFiles.forEach(function(f) {
                dt.items.add(f);
            });
            fileInput.files = dt.files;
        }
        if (primaryIndexInput) {
            primaryIndexInput.value = primaryIndex;
        }
    }

    function renderPreviews() {
        previewContainer.innerHTML = '';
        if (selectedFiles.length === 0) {
            previewContainer.style.display = 'none';
            if (statusText) statusText.innerHTML = '';
            if (addMoreBtn) addMoreBtn.style.display = 'none';
            syncInputFiles();
            return;
        }

        previewContainer.style.display = 'grid';
        if (addMoreBtn) addMoreBtn.style.display = 'inline-flex';

        if (primaryIndex >= selectedFiles.length) {
            primaryIndex = 0;
        }

        if (statusText) {
            statusText.innerHTML = '&#10003; <strong>' + selectedFiles.length + ' photo(s) selected</strong> &bull; Photo #' + (primaryIndex + 1) + ' will be the main cover.';
        }

        selectedFiles.forEach(function(file, index) {
            var card = document.createElement('div');
            card.className = 'ofp-photo-preview-card' + (index === primaryIndex ? ' is-primary' : '');
            card.setAttribute('data-index', index);

            var img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.alt = file.name;

            // Remove button
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'ofp-photo-remove-btn';
            removeBtn.title = 'Remove photo';
            removeBtn.innerHTML = '&times;';
            removeBtn.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                URL.revokeObjectURL(img.src);
                selectedFiles.splice(index, 1);
                if (primaryIndex >= selectedFiles.length) {
                    primaryIndex = Math.max(0, selectedFiles.length - 1);
                }
                syncInputFiles();
                renderPreviews();
            };

            // Badge / Make Primary
            var badge = document.createElement('div');
            badge.className = 'ofp-photo-badge ' + (index === primaryIndex ? 'ofp-badge-main' : 'ofp-badge-set-main');
            badge.textContent = index === primaryIndex ? 'Main Cover' : 'Set as Main';
            badge.title = index === primaryIndex ? 'Current cover photo' : 'Click to make this the cover photo';
            badge.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                primaryIndex = index;
                if (replaceMainInput) replaceMainInput.value = '1';
                syncInputFiles();
                renderPreviews();
            };

            // Info bar
            var info = document.createElement('div');
            info.className = 'ofp-photo-info';
            var sizeKb = Math.round(file.size / 1024);
            var sizeStr = sizeKb > 1024 ? (sizeKb / 1024).toFixed(1) + ' MB' : sizeKb + ' KB';
            info.textContent = file.name + ' (' + sizeStr + ')';
            info.title = file.name;

            card.appendChild(img);
            card.appendChild(removeBtn);
            card.appendChild(badge);
            card.appendChild(info);
            previewContainer.appendChild(card);
        });

        syncInputFiles();
    }

    function addFiles(files) {
        var validExts = /\.(jpe?g|png|webp|gif)$/i;
        var addedCount = 0;
        for (var i = 0; i < files.length; i++) {
            var f = files[i];
            if (f.type && !f.type.startsWith('image/') && !validExts.test(f.name)) {
                continue;
            }
            var isDuplicate = selectedFiles.some(function(sf) {
                return sf.name === f.name && sf.size === f.size;
            });
            if (!isDuplicate) {
                selectedFiles.push(f);
                addedCount++;
            }
        }
        if (addedCount > 0) {
            renderPreviews();
        }
    }

    fileInput.addEventListener('change', function() {
        if (this.files && this.files.length) {
            addFiles(this.files);
        }
    });

    dropzone.addEventListener('click', function(e) {
        if (e.target.closest('button') || e.target.closest('.ofp-photo-preview-card')) {
            return;
        }
        fileInput.click();
    });

    if (browseBtn) {
        browseBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileInput.click();
        });
    }

    if (addMoreBtn) {
        addMoreBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fileInput.click();
        });
    }

    // Drag and Drop
    ['dragenter', 'dragover'].forEach(function(evtName) {
        dropzone.addEventListener(evtName, function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('ofp-dropzone-dragover');
        });
    });

    ['dragleave', 'drop'].forEach(function(evtName) {
        dropzone.addEventListener(evtName, function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('ofp-dropzone-dragover');
        });
    });

    dropzone.addEventListener('drop', function(e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            addFiles(e.dataTransfer.files);
        }
    });
})();

// Existing photos management helpers
function ofpRemoveExistingGallery(id) {
    if (!confirm('Remove this photo from the listing gallery?')) return;
    var card = document.getElementById('ofp-existing-photo-' + id);
    if (card) card.remove();
    var container = document.getElementById('ofp-removed-photos-inputs');
    if (container) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'remove_gallery_ids[]';
        inp.value = id;
        container.appendChild(inp);
    }
}

function ofpRemoveExistingThumb() {
    if (!confirm('Remove this main cover photo? A gallery photo will become the new cover.')) return;
    var card = document.getElementById('ofp-existing-thumb-card');
    if (card) card.remove();
    var inp = document.getElementById('ofp-delete-main-input');
    if (inp) inp.value = '1';
}

function ofpSetExistingMain(id) {
    var inp = document.getElementById('ofp-set-main-input');
    if (inp) inp.value = id;
    var grid = document.getElementById('ofp-existing-photos-grid');
    if (grid) {
        var allCards = grid.querySelectorAll('.ofp-existing-photo-card');
        allCards.forEach(function(c) {
            c.classList.remove('is-main');
            var b = c.querySelector('.ofp-photo-badge');
            if (b) {
                b.className = 'ofp-photo-badge ofp-badge-set-main';
                b.textContent = 'Set Main';
            }
        });
        var target = document.getElementById('ofp-existing-photo-' + id);
        if (target) {
            target.classList.add('is-main');
            var tb = target.querySelector('.ofp-photo-badge');
            if (tb) {
                tb.className = 'ofp-photo-badge ofp-badge-main';
                tb.textContent = 'Main Cover (On Save)';
            }
        }
// Amenities selection & Custom addition handlers
(function() {
    var btnAll = document.getElementById('ofp-btn-amenities-select-all');
    var btnNone = document.getElementById('ofp-btn-amenities-clear-all');
    var container = document.getElementById('ofp-amenities-grid-container');
    var btnAdd = document.getElementById('ofp-btn-add-custom-amenity');
    var inpAdd = document.getElementById('ofp-new-amenity-input');

    if (btnAll && container) {
        btnAll.addEventListener('click', function(e) {
            e.preventDefault();
            var boxes = container.querySelectorAll('input[type="checkbox"]');
            boxes.forEach(function(b) {
                b.checked = true;
                var card = b.closest('.ofp-amenity-card');
                if (card) card.classList.add('is-checked');
            });
        });
    }

    if (btnNone && container) {
        btnNone.addEventListener('click', function(e) {
            e.preventDefault();
            var boxes = container.querySelectorAll('input[type="checkbox"]');
            boxes.forEach(function(b) {
                b.checked = false;
                var card = b.closest('.ofp-amenity-card');
                if (card) card.classList.remove('is-checked');
            });
        });
    }

    if (container) {
        container.addEventListener('change', function(e) {
            if (e.target && e.target.type === 'checkbox') {
                var card = e.target.closest('.ofp-amenity-card');
                if (card) {
                    if (e.target.checked) card.classList.add('is-checked');
                    else card.classList.remove('is-checked');
                }
            }
        });
    }

    function addCustomAmenity() {
        if (!inpAdd || !container) return;
        var val = inpAdd.value.trim();
        if (!val) {
            inpAdd.focus();
            return;
        }

        var key = val.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
        if (!key) key = 'amenity_' + Date.now();

        // Check if already in container
        var existing = container.querySelector('input[value="' + key + '"]');
        if (existing) {
            existing.checked = true;
            var card = existing.closest('.ofp-amenity-card');
            if (card) card.classList.add('is-checked');
            existing.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            inpAdd.value = '';
            return;
        }

        // Send AJAX to register in background
        if (window.fetch) {
            var formData = new FormData();
            formData.append('action', 'ofp_add_custom_amenity');
            formData.append('name', val);
            formData.append('nonce', '<?php echo esc_js( wp_create_nonce( "ofp_amenity_nonce" ) ); ?>');
            fetch('<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>', {
                method: 'POST',
                body: formData
            }).then(function(r) { return r.json(); }).catch(function() {});
        }

        // Fallback icon SVG
        var iconSvg = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z"/></svg>';

        var labelEl = document.createElement('label');
        labelEl.className = 'ofp-amenity-card is-checked';
        labelEl.innerHTML = '<input type="checkbox" name="amenities[]" value="' + key + '" checked>' +
            '<span class="ofp-amenity-icon-mini">' + iconSvg + '</span>' +
            '<span>' + val + '</span>';

        container.appendChild(labelEl);
        labelEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        inpAdd.value = '';
        inpAdd.focus();
    }

    if (btnAdd) {
        btnAdd.addEventListener('click', function(e) {
            e.preventDefault();
            addCustomAmenity();
        });
    }

    if (inpAdd) {
        inpAdd.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addCustomAmenity();
            }
        });
    }
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
