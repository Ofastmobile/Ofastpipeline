<?php
/**
 * Template: /properties (client portal) — "My Properties" dashboard.
 *
 * Logged-in clients only (assumes OFP_Auth::require_client_login()
 * has already run and $client is available, same pattern as
 * credits.php and dashboard.php).
 *
 * Three things happen on this one page, distinguished by which named
 * submit button was pressed:
 *   - ofp_choose_listing_plan : client picks Bronze/Silver/Gold,
 *     creates a 'listing' subscription row, redirected to payment.
 *   - ofp_save_property       : add-new or edit-existing property
 *     (existence of 'property_id' in the POST decides which).
 *   - ofp_delete_property     : trashes a property the client owns.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client(); // adjust to whatever your existing dashboard.php/credits.php already uses to get the logged-in client

$error   = '';
$success = '';

$active_plan = OFP_Subscription::get_active_listing_plan( $client->id );
$plan_prices = OFP_Property::get_plan_prices();
$plan_caps   = OFP_Property::get_plan_caps();
$plan_labels = [ 'bronze' => 'Bronze', 'silver' => 'Silver', 'gold' => 'Gold' ];
$used_count  = OFP_Property::count_for_client( $client->id );

/* -----------------------------------------------------------
 * Handle: choose/change listing plan
 * --------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_choose_listing_plan'] ) ) {

    if ( ! wp_verify_nonce( $_POST['ofp_listing_plan_nonce'] ?? '', 'ofp_listing_plan_action' ) ) {
        $error = 'Security check failed — please try again.';
    } else {
        $chosen_plan = sanitize_text_field( $_POST['listing_plan'] ?? '' );

        if ( ! in_array( $chosen_plan, OFP_Property::PLAN_KEYS, true ) ) {
            $error = 'Please choose a valid plan.';
        } else {
            OFP_Subscription::create( $client->id, 'listing', $chosen_plan );
            $checkout_url = OFP_Payment::generate_link( $client->id );
            if ( $checkout_url ) {
                wp_redirect( $checkout_url );
                exit;
            }
            $error = 'We could not start your payment right now. Please try again shortly.';
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

        // Ownership check on edit — a client can never edit a listing
        // that isn't theirs, even if they guess/tamper with the ID.
        if ( ! $is_new && ! OFP_Property::is_owned_by( $editing_id, $client->id ) ) {
            $error = 'You do not have permission to edit that listing.';
        }
        // Cap check on new listings only — editing an existing one
        // never counts against the cap a second time.
        elseif ( $is_new && ! OFP_Property::can_add_property( $client->id ) ) {
            $error = $active_plan
                ? 'You have reached your plan\'s property limit. Choose a higher plan to add more.'
                : 'Please choose a listing plan before adding a property.';
        }
        elseif ( empty( $_POST['title'] ) || empty( $_POST['price'] ) ) {
            $error = 'Title and price are required.';
        } else {

            $title        = sanitize_text_field( $_POST['title'] );
            $description  = sanitize_textarea_field( $_POST['description'] ?? '' );
            $price        = (float) $_POST['price'];
            $property_type = in_array( $_POST['property_type'] ?? '', [ 'sale', 'rent' ], true ) ? $_POST['property_type'] : 'sale';
            $bedrooms     = (int) ( $_POST['bedrooms'] ?? 0 );
            $bathrooms    = (int) ( $_POST['bathrooms'] ?? 0 );
            $size_sqm     = (float) ( $_POST['size_sqm'] ?? 0 );
            $address      = sanitize_text_field( $_POST['address'] ?? '' );
            $city         = sanitize_text_field( $_POST['city'] ?? '' );
            $listing_status = in_array( $_POST['listing_status'] ?? '', [ 'available', 'sold', 'rented' ], true )
                ? $_POST['listing_status'] : 'available';

            $post_data = [
                'post_title'   => $title,
                'post_content' => $description,
                'post_type'    => OFP_Property::POST_TYPE,
                // New listings always start pending — admin reviews
                // before anything goes public. Edits to an already-
                // published listing keep their current status rather
                // than being re-sent for review each time (a client
                // marking their own property "sold" shouldn't
                // un-publish it).
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
                update_post_meta( $post_id, 'ofp_property_type', $property_type );
                update_post_meta( $post_id, 'ofp_bedrooms', $bedrooms );
                update_post_meta( $post_id, 'ofp_bathrooms', $bathrooms );
                update_post_meta( $post_id, 'ofp_size_sqm', $size_sqm );
                update_post_meta( $post_id, 'ofp_address', $address );
                update_post_meta( $post_id, 'ofp_city', $city );
                update_post_meta( $post_id, 'ofp_listing_status', $listing_status );

                // Photo upload — featured image is the first file,
                // any additional files go into the gallery meta.
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

                $success = $is_new
                    ? 'Your property has been submitted and is awaiting review — it will appear publicly once approved.'
                    : 'Your property has been updated.';

                $used_count = OFP_Property::count_for_client( $client->id );
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

        if ( ! OFP_Property::is_owned_by( $delete_id, $client->id ) ) {
            $error = 'You do not have permission to delete that listing.';
        } else {
            wp_trash_post( $delete_id );
            $success = 'Property deleted.';
            $used_count = OFP_Property::count_for_client( $client->id );
        }
    }
}

$my_properties  = OFP_Property::get_client_properties( $client->id );
$editing_post   = null;
if ( isset( $_GET['edit'] ) ) {
    $edit_id = (int) $_GET['edit'];
    if ( OFP_Property::is_owned_by( $edit_id, $client->id ) ) {
        $editing_post = get_post( $edit_id );
    }
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Properties — OFast Pipeline</title>
    <?php wp_head(); ?>
</head>
<body>
<div class="ofp-dashboard-wrapper">
    <h1>My Properties</h1>

    <?php if ( $error ) : ?>
        <div class="ofp-notice ofp-notice-error"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>
    <?php if ( $success ) : ?>
        <div class="ofp-notice ofp-notice-success"><?php echo esc_html( $success ); ?></div>
    <?php endif; ?>

    <!-- Plan status / picker -->
    <div class="ofp-card">
        <?php if ( $active_plan ) : ?>
            <h2><?php echo esc_html( $plan_labels[ $active_plan ] ); ?> Plan</h2>
            <p class="ofp-muted">
                Using <?php echo esc_html( $used_count ); ?> of <?php echo esc_html( $plan_caps[ $active_plan ] ); ?> properties.
            </p>
        <?php else : ?>
            <h2>Choose a Listing Plan</h2>
            <p class="ofp-muted">You need an active listing plan before you can add a property.</p>
        <?php endif; ?>

        <form method="POST" class="ofp-plan-picker">
            <?php wp_nonce_field( 'ofp_listing_plan_action', 'ofp_listing_plan_nonce' ); ?>
            <?php foreach ( OFP_Property::PLAN_KEYS as $plan ) : ?>
                <label class="ofp-plan-option">
                    <input type="radio" name="listing_plan" value="<?php echo esc_attr( $plan ); ?>"
                        <?php checked( $active_plan, $plan ); ?>>
                    <strong><?php echo esc_html( $plan_labels[ $plan ] ); ?></strong>
                    — up to <?php echo esc_html( $plan_caps[ $plan ] ); ?> properties
                    — NGN <?php echo esc_html( number_format( $plan_prices[ $plan ], 2 ) ); ?>/month
                </label>
            <?php endforeach; ?>
            <button type="submit" name="ofp_choose_listing_plan" value="1" class="ofp-btn ofp-btn-primary">
                <?php echo $active_plan ? 'Change Plan' : 'Choose Plan'; ?>
            </button>
        </form>
    </div>

    <!-- Add / Edit property form -->
    <div class="ofp-card">
        <h2><?php echo $editing_post ? 'Edit Property' : 'Add New Property'; ?></h2>

        <?php if ( ! $editing_post && ! OFP_Property::can_add_property( $client->id ) ) : ?>
            <p class="ofp-muted">
                <?php echo $active_plan
                    ? 'You have reached your plan\'s property limit — choose a higher plan above to add more.'
                    : 'Choose a listing plan above to start adding properties.'; ?>
            </p>
        <?php else : ?>
            <form method="POST" enctype="multipart/form-data" class="ofp-property-form">
                <?php wp_nonce_field( 'ofp_save_property_action', 'ofp_property_nonce' ); ?>
                <?php if ( $editing_post ) : ?>
                    <input type="hidden" name="property_id" value="<?php echo esc_attr( $editing_post->ID ); ?>">
                <?php endif; ?>

                <label>Title
                    <input type="text" name="title" required
                           value="<?php echo esc_attr( $editing_post->post_title ?? '' ); ?>">
                </label>

                <label>Description
                    <textarea name="description" rows="4"><?php echo esc_textarea( $editing_post->post_content ?? '' ); ?></textarea>
                </label>

                <label>Price (NGN)
                    <input type="number" step="0.01" name="price" required
                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_price', true ) : '' ); ?>">
                </label>

                <label>Type
                    <select name="property_type">
                        <?php $current_type = $editing_post ? get_post_meta( $editing_post->ID, 'ofp_property_type', true ) : 'sale'; ?>
                        <option value="sale" <?php selected( $current_type, 'sale' ); ?>>For Sale</option>
                        <option value="rent" <?php selected( $current_type, 'rent' ); ?>>For Rent</option>
                    </select>
                </label>

                <label>Bedrooms
                    <input type="number" name="bedrooms"
                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_bedrooms', true ) : '' ); ?>">
                </label>

                <label>Bathrooms
                    <input type="number" name="bathrooms"
                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_bathrooms', true ) : '' ); ?>">
                </label>

                <label>Size (sqm)
                    <input type="number" step="0.01" name="size_sqm"
                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_size_sqm', true ) : '' ); ?>">
                </label>

                <label>Address
                    <input type="text" name="address"
                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_address', true ) : '' ); ?>">
                </label>

                <label>City
                    <input type="text" name="city"
                           value="<?php echo esc_attr( $editing_post ? get_post_meta( $editing_post->ID, 'ofp_city', true ) : '' ); ?>">
                </label>

                <?php if ( $editing_post ) : ?>
                    <label>Status
                        <?php $current_status = get_post_meta( $editing_post->ID, 'ofp_listing_status', true ); ?>
                        <select name="listing_status">
                            <option value="available" <?php selected( $current_status, 'available' ); ?>>Available</option>
                            <option value="sold" <?php selected( $current_status, 'sold' ); ?>>Sold</option>
                            <option value="rented" <?php selected( $current_status, 'rented' ); ?>>Rented</option>
                        </select>
                    </label>
                <?php endif; ?>

                <label>Photos <span class="ofp-muted">(first photo becomes the main image)</span>
                    <input type="file" name="photos[]" accept="image/*" multiple>
                </label>

                <button type="submit" name="ofp_save_property" value="1" class="ofp-btn ofp-btn-primary">
                    <?php echo $editing_post ? 'Save Changes' : 'Submit Property'; ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- My Properties list -->
    <div class="ofp-card">
        <h2>Your Listings</h2>
        <?php if ( empty( $my_properties ) ) : ?>
            <p class="ofp-muted">You haven't added any properties yet.</p>
        <?php else : ?>
            <table class="ofp-table">
                <thead>
                    <tr><th>Title</th><th>Status</th><th>Price</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ( $my_properties as $property ) : ?>
                        <tr>
                            <td><?php echo esc_html( $property->post_title ); ?></td>
                            <td>
                                <?php echo esc_html( ucfirst( $property->post_status ) ); ?>
                                <?php if ( $property->post_status === 'pending' ) : ?>
                                    <span class="ofp-muted">(awaiting review)</span>
                                <?php endif; ?>
                            </td>
                            <td>NGN <?php echo esc_html( number_format( (float) get_post_meta( $property->ID, 'ofp_price', true ), 2 ) ); ?></td>
                            <td>
                                <a href="?edit=<?php echo esc_attr( $property->ID ); ?>">Edit</a>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this property?');">
                                    <?php wp_nonce_field( 'ofp_delete_property_action', 'ofp_delete_nonce' ); ?>
                                    <input type="hidden" name="property_id" value="<?php echo esc_attr( $property->ID ); ?>">
                                    <button type="submit" name="ofp_delete_property" value="1" class="ofp-link-btn">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php wp_footer(); ?>
</body>
</html>
