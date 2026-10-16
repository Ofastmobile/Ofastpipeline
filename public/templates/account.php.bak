<?php
/**
 * Template: /account & /profile
 * Client Profile Page (Modern Redesign matching reference UI)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();

$success = '';
$error   = '';

global $wpdb;

// Ensure database columns exist
$has_logo = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'logo_url'");
if (empty($has_logo)) {
    $wpdb->query("ALTER TABLE {$wpdb->prefix}ofp_clients ADD COLUMN logo_url VARCHAR(255) DEFAULT NULL AFTER business_category");
}
$has_slug = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'profile_slug'");
if (empty($has_slug)) {
    $wpdb->query("ALTER TABLE {$wpdb->prefix}ofp_clients ADD COLUMN profile_slug VARCHAR(255) DEFAULT NULL");
}
$has_bio = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'bio'");
if (empty($has_bio)) {
    $wpdb->query("ALTER TABLE {$wpdb->prefix}ofp_clients ADD COLUMN bio TEXT DEFAULT NULL");
}
$has_pixel = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'meta_pixel_id'");
if (empty($has_pixel)) {
    $wpdb->query("ALTER TABLE {$wpdb->prefix}ofp_clients ADD COLUMN meta_pixel_id VARCHAR(50) DEFAULT NULL");
}
$has_extra = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'profile_extra'");
if (empty($has_extra)) {
    $wpdb->query("ALTER TABLE {$wpdb->prefix}ofp_clients ADD COLUMN profile_extra TEXT DEFAULT NULL");
}

// ── Form POST Handlers ───────────────────────────────────────────────────────
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_account_nonce'] ) ) {
    if ( ! wp_verify_nonce(
        sanitize_text_field( wp_unslash( $_POST['ofp_account_nonce'] ) ),
        'ofp_account_' . $client->id
    ) ) {
        $error = 'Security check failed.';
    } elseif ( isset( $_POST['change_password'] ) ) {
        $current = wp_unslash( $_POST['current_password'] ?? '' );
        $new_pw  = wp_unslash( $_POST['new_password']     ?? '' );
        $confirm = wp_unslash( $_POST['confirm_password']  ?? '' );

        if ( strlen( $new_pw ) < 8 ) {
            $error = 'New password must be at least 8 characters.';
        } elseif ( $new_pw !== $confirm ) {
            $error = 'New passwords do not match.';
        } elseif ( ! OFP_Auth::change_password( $client->id, $current, $new_pw ) ) {
            $error = 'Current password is incorrect.';
        } else {
            $success = 'Password changed successfully. Please log in again.';
            OFP_Auth::logout();
            wp_safe_redirect( home_url( '/login?session_expired=1' ) );
            exit;
        }
    } elseif ( isset( $_POST['update_profile'] ) ) {
        $slug       = sanitize_title( $_POST['profile_slug'] ?? '' );
        $bio        = sanitize_textarea_field( $_POST['bio'] ?? '' );
        $pixel      = sanitize_text_field( $_POST['meta_pixel_id'] ?? '' );
        $owner_name = sanitize_text_field( $_POST['owner_name'] ?? '' );
        $phone      = sanitize_text_field( $_POST['phone'] ?? '' );

        // Rich custom profile details matching reference mockup
        $extra_data = [
            'role'               => sanitize_text_field( $_POST['role'] ?? '' ),
            'experience'         => sanitize_text_field( $_POST['experience'] ?? '' ),
            'favorite_artists'   => sanitize_text_field( $_POST['favorite_artists'] ?? '' ),
            'favorite_genre'     => sanitize_text_field( $_POST['favorite_genre'] ?? '' ),
            'software_equipment' => sanitize_text_field( $_POST['software_equipment'] ?? '' ),
            'music_mood'         => sanitize_text_field( $_POST['music_mood'] ?? '' ),
            'city_region'        => sanitize_text_field( $_POST['city_region'] ?? '' ),
            'availability'       => sanitize_text_field( $_POST['availability'] ?? '' ),
            'badges'             => sanitize_text_field( $_POST['badges'] ?? '' ),
            'tags'               => sanitize_text_field( $_POST['tags'] ?? '' ),
            'youtube_url'        => esc_url_raw( $_POST['youtube_url'] ?? '' ),
            'instagram_url'      => esc_url_raw( $_POST['instagram_url'] ?? '' ),
            'tiktok_url'         => esc_url_raw( $_POST['tiktok_url'] ?? '' ),
            'whatsapp_url'       => esc_url_raw( $_POST['whatsapp_url'] ?? '' ),
        ];
        $extra_json = wp_json_encode( $extra_data );

        // Check if slug is taken by someone else
        $slug_taken = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_clients WHERE profile_slug = %s AND id != %d LIMIT 1",
                $slug,
                $client->id
            )
        );

        if ( empty( $slug ) ) {
            $error = 'Profile URL Slug cannot be empty.';
        } elseif ( $slug_taken ) {
            $error = 'That Profile URL Slug is already taken. Please choose another.';
        } else {
            $update_fields = [
                'profile_slug'  => $slug,
                'bio'           => $bio,
                'meta_pixel_id' => $pixel,
                'profile_extra' => $extra_json,
            ];
            if ( ! empty( $owner_name ) ) {
                $update_fields['owner_name'] = $owner_name;
                $client->owner_name = $owner_name;
            }
            if ( ! empty( $phone ) ) {
                $update_fields['phone'] = $phone;
                $client->phone = $phone;
            }

            $wpdb->update(
                $wpdb->prefix . 'ofp_clients',
                $update_fields,
                [ 'id' => $client->id ]
            );
            $success = 'Profile details updated successfully.';
            
            // Update client object in memory so UI reflects immediately
            $client->profile_slug  = $slug;
            $client->bio           = $bio;
            $client->meta_pixel_id = $pixel;
            $client->profile_extra = $extra_json;
        }
    } elseif ( isset( $_POST['bank_do'] ) ) {
        // Bank accounts shown to buyers and tenants.
        $bank_pw   = (string) wp_unslash( $_POST['bank_password'] ?? '' );
        $bank_auth = OFP_Auth::check_credentials( $client->email, $bank_pw );
        $bank_cid  = is_array( $bank_auth ) ? (int) ( $bank_auth['client_id'] ?? 0 ) : ( is_object( $bank_auth ) ? (int) ( $bank_auth->client_id ?? 0 ) : 0 );
        $bank_do   = sanitize_text_field( wp_unslash( $_POST['bank_do'] ) );

        if ( $bank_cid !== (int) $client->id ) {
            $error = 'Your password is not correct, so bank details were not modified.';
        } else {
            $bank_msg = '';
            if ( $bank_do === 'add' ) {
                $new_bank = OFP_Bank_Account::add(
                    (int) $client->id,
                    (string) wp_unslash( $_POST['bank_name'] ?? '' ),
                    (string) wp_unslash( $_POST['bank_account_number'] ?? '' ),
                    (string) wp_unslash( $_POST['bank_account_name'] ?? '' )
                );
                if ( is_wp_error( $new_bank ) ) {
                    $error = $new_bank->get_error_message();
                } else {
                    $success  = 'Bank account added successfully.';
                    $bank_msg = 'A bank account was added.';
                }
            } elseif ( strpos( $bank_do, 'delete:' ) === 0 ) {
                if ( OFP_Bank_Account::delete( (int) substr( $bank_do, 7 ), (int) $client->id ) ) {
                    $success  = 'Bank account removed.';
                    $bank_msg = 'A bank account was removed.';
                }
            } elseif ( strpos( $bank_do, 'default:' ) === 0 ) {
                if ( OFP_Bank_Account::set_default( (int) substr( $bank_do, 8 ), (int) $client->id ) ) {
                    $success  = 'Default account changed.';
                    $bank_msg = 'The default bank account was changed.';
                }
            }
            if ( $bank_msg && ! empty( $client->email ) ) {
                OFP_Mailer::send(
                    $client->email,
                    $client->owner_name ?: 'there',
                    'Your bank account details were changed',
                    '<p>' . esc_html( $bank_msg ) . ' Buyers and tenants will now see the new details.</p><p>If you did not do this, change your password right away.</p>'
                );
            }
        }
    } elseif ( isset( $_POST['update_branding'] ) ) {
        // Branding (Gold and solo company only)
        if ( ! OFP_Brand::can_brand( $client ) ) {
            $error = 'Branding is available on the Gold plan.';
        } elseif ( ! OFP_White_Label::schema_ready() ) {
            $error = 'Branding is not ready yet. Please reload the page.';
        } else {
            $pick_color = static function ( string $field ): ?string {
                if ( ! empty( $_POST[ 'use_default_' . $field ] ) ) {
                    return null;
                }
                $v = sanitize_hex_color( trim( (string) wp_unslash( $_POST[ $field ] ?? '' ) ) );
                return $v ?: null;
            };
            $b_color   = $pick_color( 'brand_color' );
            $b_sidebar = $pick_color( 'sidebar_color' );
            $b_hover   = $pick_color( 'menu_hover_color' );
            $b_email   = trim( (string) wp_unslash( $_POST['support_email'] ?? '' ) );
            $b_phone   = sanitize_text_field( wp_unslash( $_POST['support_phone'] ?? '' ) );
            $b_fav     = null;

            if ( $b_email !== '' && ! is_email( $b_email ) ) {
                $error = 'Enter a valid support email address.';
            } elseif ( isset( $_FILES['favicon'] ) && (int) $_FILES['favicon']['error'] === UPLOAD_ERR_OK ) {
                $fav = $_FILES['favicon'];
                $fav_type = wp_check_filetype( $fav['name'] );
                if ( $fav['size'] > 100 * 1024 ) {
                    $error = 'The favicon is too large. Maximum size is 100KB.';
                } elseif ( ! in_array( $fav_type['type'], [ 'image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp', 'image/jpeg' ], true ) ) {
                    $error = 'Invalid favicon type. Use PNG, ICO, WebP or JPG.';
                } else {
                    if ( ! function_exists( 'wp_handle_upload' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                    }
                    $moved = wp_handle_upload( $fav, [ 'test_form' => false ] );
                    if ( $moved && ! isset( $moved['error'] ) ) {
                        $b_fav = $moved['url'];
                    } else {
                        $error = $moved['error'] ?? 'Failed to save the favicon.';
                    }
                }
            }

            if ( ! $error ) {
                $b_data = [
                    'brand_color'      => $b_color,
                    'sidebar_color'    => $b_sidebar,
                    'menu_hover_color' => $b_hover,
                    'support_email'    => $b_email !== '' ? sanitize_email( $b_email ) : null,
                    'support_phone'    => $b_phone !== '' ? $b_phone : null,
                ];
                if ( $b_fav ) {
                    $b_data['favicon_url'] = $b_fav;
                } elseif ( ! empty( $_POST['remove_favicon'] ) ) {
                    $b_data['favicon_url'] = null;
                }
                global $wpdb;
                $wpdb->update( $wpdb->prefix . 'ofp_clients', $b_data, [ 'id' => $client->id ] );
                foreach ( $b_data as $k => $v ) {
                    $client->$k = $v;
                }
                $success = 'Branding saved.';
            }
        }
    } elseif ( isset( $_POST['upload_logo'] ) && isset( $_FILES['logo'] ) ) {
        $file = $_FILES['logo'];
        
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            $error = 'There was an error uploading the file. Please try again.';
        } elseif ( $file['size'] > 500 * 1024 ) {
            $error = 'File is too large. Maximum size is 500KB.';
        } else {
            $allowed_types = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
            $file_type = wp_check_filetype( $file['name'] );
            
            if ( ! in_array( $file_type['type'], $allowed_types ) ) {
                $error = 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.';
            } else {
                if ( ! function_exists( 'wp_handle_upload' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                
                $upload_overrides = [ 'test_form' => false ];
                $movefile = wp_handle_upload( $file, $upload_overrides );
                
                if ( $movefile && ! isset( $movefile['error'] ) ) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->prefix . 'ofp_clients',
                        [ 'logo_url' => $movefile['url'] ],
                        [ 'id' => $client->id ]
                    );
                    
                    wp_safe_redirect( add_query_arg( 'success', 'logo', home_url( '/profile' ) ) );
                    exit;
                } else {
                    $error = $movefile['error'] ?? 'Failed to move uploaded file.';
                }
            }
        }
    }
}

// Handle success messages from redirects
if ( isset( $_GET['success'] ) && $_GET['success'] === 'logo' ) {
    $success = 'Profile photo updated successfully.';
}

// ── Profile Data Extraction & Fallbacks ──────────────────────────────────────
$extra = [];
if ( ! empty( $client->profile_extra ) ) {
    $extra = json_decode( $client->profile_extra, true ) ?: [];
}

$display_name       = ! empty( $client->owner_name ) ? $client->owner_name : ( ! empty( $client->business_name ) ? $client->business_name : 'Maria Fernanda' );
$role               = ! empty( $extra['role'] ) ? $extra['role'] : ( ! empty( $client->business_category ) ? $client->business_category : 'Beatmaker' );
$experience         = ! empty( $extra['experience'] ) ? $extra['experience'] : 'Intermediate';
$favorite_artists   = ! empty( $extra['favorite_artists'] ) ? $extra['favorite_artists'] : 'Ninho, Travis Scott, Metro Boomin';
$favorite_genre     = ! empty( $extra['favorite_genre'] ) ? $extra['favorite_genre'] : 'Trap';
$software_equipment = ! empty( $extra['software_equipment'] ) ? $extra['software_equipment'] : 'Ableton';
$music_mood         = ! empty( $extra['music_mood'] ) ? $extra['music_mood'] : 'Melancholic';
$city_region        = ! empty( $extra['city_region'] ) ? $extra['city_region'] : ( ! empty( $client->city ) ? $client->city : 'California, USA' );
$availability       = ! empty( $extra['availability'] ) ? $extra['availability'] : 'Available for Collaboration';
$badges             = ! empty( $extra['badges'] ) ? $extra['badges'] : 'Top Collaborator';
$tags               = ! empty( $extra['tags'] ) ? $extra['tags'] : '#Drill, #Melancholic, #Rap-US';

$youtube_url        = ! empty( $extra['youtube_url'] ) ? $extra['youtube_url'] : 'https://youtube.com';
$instagram_url      = ! empty( $extra['instagram_url'] ) ? $extra['instagram_url'] : 'https://instagram.com';
$tiktok_url         = ! empty( $extra['tiktok_url'] ) ? $extra['tiktok_url'] : 'https://tiktok.com';
$whatsapp_url       = ! empty( $extra['whatsapp_url'] ) ? $extra['whatsapp_url'] : ( ! empty( $client->whatsapp_number ) ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $client->whatsapp_number) : ( ! empty( $client->phone ) ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $client->phone) : 'https://whatsapp.com' ) );

// Avatar image URL
$avatar_url = ! empty( $client->logo_url ) ? $client->logo_url : OFP_URL . 'assets/images/default-avatar.png';

// Tier badge text
$plan_badge = 'Premium User';
if ( class_exists( 'OFP_Subscription' ) ) {
    $plan_key = OFP_Subscription::feature_plan( $client->id );
    if ( $plan_key === 'gold' ) {
        $plan_badge = 'Premium User';
    } elseif ( $plan_key === 'silver' ) {
        $plan_badge = 'Silver User';
    } elseif ( ! empty( $client->plan ) ) {
        $plan_badge = ucfirst( $client->plan ) . ' User';
    }
}

// ── "My Productions" / Portfolio Listings ───────────────────────────────────
$productions = [];
$client_properties = $wpdb->get_results( $wpdb->prepare(
    "SELECT id, title, price, listing_type, wp_post_id, created_at, status 
     FROM {$wpdb->prefix}ofp_properties 
     WHERE client_id = %d 
     ORDER BY id DESC LIMIT 5",
    $client->id
) );

if ( ! empty( $client_properties ) ) {
    foreach ( $client_properties as $cp ) {
        $thumb_url = '';
        if ( ! empty( $cp->wp_post_id ) ) {
            $main_img_id = get_post_meta( $cp->wp_post_id, 'ofp_main_image', true );
            if ( $main_img_id ) {
                $thumb_url = wp_get_attachment_image_url( $main_img_id, 'thumbnail' );
            }
        }
        if ( ! $thumb_url ) {
            $thumb_url = OFP_URL . 'assets/images/dashboard-hero-banner.jpg';
        }

        $inq_cnt = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ofp_property_inquiries WHERE property_id = %d OR property_id = %d",
            $cp->id,
            $cp->wp_post_id ?: 0
        ) );

        $productions[] = [
            'id'         => $cp->id,
            'title'      => $cp->title ?: 'Featured Property',
            'subtitle'   => ucfirst( $cp->listing_type ?? 'Sale' ) . ' • ' . ucfirst( $cp->status ?? 'Live' ),
            'thumb'      => $thumb_url,
            'timing'     => is_numeric( $cp->price ) ? '₦' . number_format( $cp->price ) : ( $cp->price ?: '₦45,000,000' ),
            'listenings' => $inq_cnt > 0 ? $inq_cnt . ' inquiries' : '182 views',
            'url'        => $cp->wp_post_id ? get_permalink( $cp->wp_post_id ) : home_url( '/properties' ),
        ];
    }
}

// Fallback items to match reference mockup if the client has no properties yet
if ( empty( $productions ) ) {
    $productions = [
        [
            'id'         => 1,
            'title'      => 'Sunset Melody Beats (Track #01)',
            'subtitle'   => 'Trap & Drill Production • 140 BPM',
            'thumb'      => OFP_URL . 'assets/images/dashboard-hero-banner.jpg',
            'timing'     => '03:42',
            'listenings' => '12.4k',
            'url'        => home_url( '/properties' ),
        ],
        [
            'id'         => 2,
            'title'      => 'Ocean Drive Luxury Villa (Plot 4)',
            'subtitle'   => 'Residential • Lekki Phase 1',
            'thumb'      => OFP_URL . 'assets/images/dashboard-hero-banner.jpg',
            'timing'     => '₦180,000,000',
            'listenings' => '48 inquiries',
            'url'        => home_url( '/properties' ),
        ],
        [
            'id'         => 3,
            'title'      => 'Melancholic Piano & Trap 808',
            'subtitle'   => 'Instrumental • Key of C Minor',
            'thumb'      => OFP_URL . 'assets/images/dashboard-hero-banner.jpg',
            'timing'     => '02:58',
            'listenings' => '8.9k',
            'url'        => home_url( '/properties' ),
        ],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Profile', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/profile.css?v=' . OFP_VERSION ); ?>">
</head>
<body class="ofp-portal-body ofp-profile-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-profile-page-wrap">

    <!-- Success & Error Notices -->
    <?php if ( $success ) : ?>
        <div class="ofp-profile-notice success">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <?php echo esc_html( $success ); ?>
        </div>
    <?php endif; ?>
    <?php if ( $error ) : ?>
        <div class="ofp-profile-notice error">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <?php echo esc_html( $error ); ?>
        </div>
    <?php endif; ?>

    <!-- ── Page Hero Header ──────────────────────────────────────────────── -->
    <div class="ofp-profile-hero-header">
        <div class="ofp-profile-title-group">
            <h1 class="ofp-profile-main-title">Profile</h1>
            <p class="ofp-profile-main-subtitle">View all your profile details here.</p>
        </div>
    </div>

    <!-- Dashed Divider -->
    <div class="ofp-profile-dashed-divider"></div>

    <!-- ── Top 2-Column Grid ─────────────────────────────────────────────── -->
    <div class="ofp-profile-top-grid">

        <!-- Left Card: User Identity & Avatar -->
        <div class="ofp-profile-card ofp-avatar-card">
            <div class="ofp-avatar-meta">
                <h2 class="ofp-avatar-name"><?php echo esc_html( $display_name ); ?></h2>
                <div class="ofp-avatar-tier"><?php echo esc_html( $plan_badge ); ?></div>
            </div>

            <div class="ofp-avatar-frame-outer" onclick="document.getElementById('ofp-logo-input').click();" title="Click to upload profile photo">
                <div class="ofp-avatar-frame-inner">
                    <?php if ( ! empty( $client->logo_url ) ) : ?>
                        <img src="<?php echo esc_url( $client->logo_url ); ?>" alt="<?php echo esc_attr( $display_name ); ?>" class="ofp-avatar-img">
                    <?php else : ?>
                        <img src="<?php echo esc_url( OFP_URL . 'assets/images/default-avatar.png' ); ?>" alt="<?php echo esc_attr( $display_name ); ?>" class="ofp-avatar-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                        <span class="ofp-avatar-initials" style="display:none;"><?php echo esc_html( strtoupper( substr( $display_name, 0, 2 ) ) ); ?></span>
                    <?php endif; ?>
                </div>

                <div class="ofp-avatar-change-overlay">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="28" height="28"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <span>Change Photo</span>
                </div>
            </div>
        </div>

        <!-- Right Card: Bio & Other Details -->
        <div class="ofp-profile-card ofp-bio-card">
            <div class="ofp-bio-card-header">
                <h3 class="ofp-bio-card-title">Bio & other details</h3>
                <span class="ofp-online-indicator" title="Status: Online"></span>
            </div>

            <div class="ofp-details-matrix">
                <!-- Row 1: Role & Experience -->
                <div class="ofp-details-row">
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">My Role</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $role ); ?></span>
                    </div>
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">My Experience Level</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $experience ); ?></span>
                    </div>
                </div>

                <!-- Row 2: Favorite Artists & Genre -->
                <div class="ofp-details-row">
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">My 3 Favorite Artists</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $favorite_artists ); ?></span>
                    </div>
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">My Favorite Music Genre</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $favorite_genre ); ?></span>
                    </div>
                </div>

                <!-- Row 3: Equipment & Mood -->
                <div class="ofp-details-row">
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">The Software or Equipment I Use</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $software_equipment ); ?></span>
                    </div>
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">My Preferred Music Mood</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $music_mood ); ?></span>
                    </div>
                </div>

                <!-- Row 4: City/Region & Availability -->
                <div class="ofp-details-row">
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">My City or Region</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $city_region ); ?></span>
                    </div>
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Availability</span>
                        <div class="ofp-availability-badge">
                            <span class="ofp-pill-pulse-dot"></span>
                            <span><?php echo esc_html( $availability ); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Row 5: Badges & Tags -->
                <div class="ofp-details-row">
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Badges</span>
                        <div class="ofp-badge-item">
                            <svg class="ofp-badge-icon" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                            <span><?php echo esc_html( $badges ); ?></span>
                        </div>
                    </div>
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Tags</span>
                        <span class="ofp-tags-text"><?php echo esc_html( $tags ); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Middle Card: Social Media ─────────────────────────────────────── -->
    <div class="ofp-profile-card ofp-social-media-card">
        <h3 class="ofp-social-card-title">Social Media</h3>

        <div class="ofp-social-icons-list">
            <!-- YouTube -->
            <a href="<?php echo esc_url( $youtube_url ?: 'https://youtube.com' ); ?>" target="_blank" class="ofp-social-bubble yt" title="YouTube">
                <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
            </a>

            <!-- Instagram -->
            <a href="<?php echo esc_url( $instagram_url ?: 'https://instagram.com' ); ?>" target="_blank" class="ofp-social-bubble ig" title="Instagram">
                <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
            </a>

            <!-- TikTok -->
            <a href="<?php echo esc_url( $tiktok_url ?: 'https://tiktok.com' ); ?>" target="_blank" class="ofp-social-bubble tt" title="TikTok">
                <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>
            </a>

            <!-- WhatsApp -->
            <a href="<?php echo esc_url( $whatsapp_url ?: '#' ); ?>" target="_blank" class="ofp-social-bubble wa" title="WhatsApp">
                <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2z"/><path d="M17.84 16.03c-.24.68-1.2 1.26-1.98 1.42-.53.11-1.22.2-3.56-.76-2.99-1.24-4.91-4.28-5.06-4.48-.15-.2-1.2-1.6-1.2-3.05s.76-2.16 1.03-2.46c.27-.3.59-.37.78-.37.2 0 .39 0 .56.01.18.01.42-.07.66.5.24.58.83 2.03.9 2.18.07.15.12.32.02.52-.1.2-.15.32-.3.5-.15.18-.32.4-.45.53-.15.15-.31.31-.13.61.18.3.8 1.32 1.72 2.14 1.18 1.05 2.18 1.38 2.48 1.53.3.15.48.13.66-.08.18-.2.76-.89.96-1.19.2-.3.4-.25.66-.15.27.1 1.72.81 2.01.96.3.15.49.22.56.35.07.13.07.73-.17 1.41z" fill="#ffffff"/></svg>
            </a>

            <!-- Public Profile / Website -->
            <?php if ( ! empty( $client->profile_slug ) ) : ?>
                <a href="<?php echo esc_url( home_url( '/agent/' . $client->profile_slug ) ); ?>" target="_blank" class="ofp-social-bubble web" title="Public Agent Profile">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" /></svg>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Bottom Card: My Productions / Portfolio ───────────────────────── -->
    <div class="ofp-profile-card ofp-productions-card">
        <div class="ofp-productions-header-row">
            <h3 class="ofp-productions-title">My Productions</h3>
            <a href="<?php echo esc_url( home_url( '/properties' ) ); ?>" class="ofp-productions-view-all">
                <span>View All</span>
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
            </a>
        </div>

        <div class="ofp-table-responsive">
            <table class="ofp-productions-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Timing</th>
                        <th>No. of listenings</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $productions as $prod ) : ?>
                        <tr>
                            <td>
                                <div class="ofp-prod-title-cell">
                                    <img src="<?php echo esc_url( $prod['thumb'] ); ?>" alt="" class="ofp-prod-thumb">
                                    <div class="ofp-prod-info">
                                        <span class="ofp-prod-main-title"><?php echo esc_html( $prod['title'] ); ?></span>
                                        <span class="ofp-prod-sub-title"><?php echo esc_html( $prod['subtitle'] ); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="ofp-prod-timing"><?php echo esc_html( $prod['timing'] ); ?></span>
                            </td>
                            <td>
                                <span class="ofp-prod-listenings"><?php echo esc_html( $prod['listenings'] ); ?></span>
                            </td>
                            <td style="text-align:right;">
                                <a href="<?php echo esc_url( $prod['url'] ); ?>" class="ofp-prod-action-btn" title="View Details">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div> <!-- /.ofp-profile-page-wrap -->

<!-- ── Hidden form for Instant Logo/Avatar upload ────────────────────────── -->
<form method="POST" action="" enctype="multipart/form-data" id="ofp-logo-form" style="display:none;">
    <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
    <input type="hidden" name="upload_logo" value="1">
    <input type="file" name="logo" id="ofp-logo-input" accept="image/jpeg,image/png,image/gif,image/webp">
</form>

<!-- ── Interactive Edit Profile Modal Drawer ─────────────────────────────── -->
<div class="ofp-modal-backdrop" id="ofp-edit-modal-backdrop" aria-hidden="true">
    <div class="ofp-modal-window" role="dialog" aria-modal="true" aria-labelledby="ofp-modal-title">
        
        <div class="ofp-modal-header">
            <h3 class="ofp-modal-title" id="ofp-modal-title">Edit Profile & Account Settings</h3>
            <button type="button" class="ofp-modal-close-btn" id="ofp-close-modal-btn" aria-label="Close">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <div class="ofp-modal-tabs">
            <button type="button" class="ofp-modal-tab-btn active" data-tab="tab-profile">Profile Details</button>
            <button type="button" class="ofp-modal-tab-btn" data-tab="tab-social">Social & Links</button>
            <button type="button" class="ofp-modal-tab-btn" data-tab="tab-banking">Bank Accounts</button>
            <button type="button" class="ofp-modal-tab-btn" data-tab="tab-security">Security</button>
            <?php if ( OFP_Brand::can_brand( $client ) && OFP_White_Label::schema_ready() ) : ?>
                <button type="button" class="ofp-modal-tab-btn" data-tab="tab-branding">Branding</button>
            <?php endif; ?>
        </div>

        <div class="ofp-modal-body">
            
            <!-- Tab 1: Profile & Bio Details -->
            <div class="ofp-tab-panel active" id="tab-profile">
                <form method="POST" action="">
                    <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
                    <input type="hidden" name="update_profile" value="1">

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>Display / Full Name</label>
                            <input type="text" name="owner_name" value="<?php echo esc_attr( $client->owner_name ); ?>" required>
                        </div>
                        <div class="ofp-field-group">
                            <label>Business / Agency Name</label>
                            <input type="text" value="<?php echo esc_attr( $client->business_name ); ?>" readonly style="opacity:0.75;" title="Business name is fixed. Contact support to change it.">
                        </div>
                    </div>

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>My Role</label>
                            <input type="text" name="role" value="<?php echo esc_attr( $role ); ?>" placeholder="e.g. Beatmaker, Real Estate Broker">
                        </div>
                        <div class="ofp-field-group">
                            <label>My Experience Level</label>
                            <input type="text" name="experience" value="<?php echo esc_attr( $experience ); ?>" placeholder="e.g. Intermediate, 5+ Years">
                        </div>
                    </div>

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>My 3 Favorite Artists / Focus Areas</label>
                            <input type="text" name="favorite_artists" value="<?php echo esc_attr( $favorite_artists ); ?>" placeholder="e.g. Ninho, Travis Scott, Metro Boomin">
                        </div>
                        <div class="ofp-field-group">
                            <label>My Favorite Music Genre / Category</label>
                            <input type="text" name="favorite_genre" value="<?php echo esc_attr( $favorite_genre ); ?>" placeholder="e.g. Trap, Residential & Commercial">
                        </div>
                    </div>

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>The Software or Equipment I Use</label>
                            <input type="text" name="software_equipment" value="<?php echo esc_attr( $software_equipment ); ?>" placeholder="e.g. Ableton, OFast CRM, Logic">
                        </div>
                        <div class="ofp-field-group">
                            <label>My Preferred Music Mood / Deal Type</label>
                            <input type="text" name="music_mood" value="<?php echo esc_attr( $music_mood ); ?>" placeholder="e.g. Melancholic, Installment Sales">
                        </div>
                    </div>

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>My City or Region</label>
                            <input type="text" name="city_region" value="<?php echo esc_attr( $city_region ); ?>" placeholder="e.g. California, USA">
                        </div>
                        <div class="ofp-field-group">
                            <label>Availability Status</label>
                            <input type="text" name="availability" value="<?php echo esc_attr( $availability ); ?>" placeholder="e.g. Available for Collaboration">
                        </div>
                    </div>

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>Badges Text</label>
                            <input type="text" name="badges" value="<?php echo esc_attr( $badges ); ?>" placeholder="e.g. Top Collaborator, Verified Agency">
                        </div>
                        <div class="ofp-field-group">
                            <label>Tags (Hashtags)</label>
                            <input type="text" name="tags" value="<?php echo esc_attr( $tags ); ?>" placeholder="e.g. #Drill, #Melancholic, #Rap-US">
                        </div>
                    </div>

                    <div class="ofp-field-group">
                        <label>Biographical Info (About)</label>
                        <textarea name="bio" placeholder="Tell the world about yourself and your work..."><?php echo esc_textarea( $client->bio ?? '' ); ?></textarea>
                    </div>

                    <!-- Preserve hidden / existing fields in same submit -->
                    <input type="hidden" name="profile_slug" value="<?php echo esc_attr( $client->profile_slug ?? 'agent-' . $client->id ); ?>">
                    <input type="hidden" name="meta_pixel_id" value="<?php echo esc_attr( $client->meta_pixel_id ?? '' ); ?>">
                    <input type="hidden" name="phone" value="<?php echo esc_attr( $client->phone ?? '' ); ?>">
                    <input type="hidden" name="youtube_url" value="<?php echo esc_attr( $youtube_url ); ?>">
                    <input type="hidden" name="instagram_url" value="<?php echo esc_attr( $instagram_url ); ?>">
                    <input type="hidden" name="tiktok_url" value="<?php echo esc_attr( $tiktok_url ); ?>">
                    <input type="hidden" name="whatsapp_url" value="<?php echo esc_attr( $whatsapp_url ); ?>">

                    <div class="ofp-modal-footer">
                        <button type="button" class="ofp-modal-btn-cancel" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="ofp-modal-btn-save">Save Profile Details</button>
                    </div>
                </form>
            </div>

            <!-- Tab 2: Social Media & Public Link -->
            <div class="ofp-tab-panel" id="tab-social">
                <form method="POST" action="">
                    <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
                    <input type="hidden" name="update_profile" value="1">

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>YouTube Link</label>
                            <input type="text" name="youtube_url" value="<?php echo esc_attr( $youtube_url ); ?>" placeholder="https://youtube.com/@channel">
                        </div>
                        <div class="ofp-field-group">
                            <label>Instagram Link</label>
                            <input type="text" name="instagram_url" value="<?php echo esc_attr( $instagram_url ); ?>" placeholder="https://instagram.com/username">
                        </div>
                    </div>

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>TikTok Link</label>
                            <input type="text" name="tiktok_url" value="<?php echo esc_attr( $tiktok_url ); ?>" placeholder="https://tiktok.com/@username">
                        </div>
                        <div class="ofp-field-group">
                            <label>WhatsApp Link / Number</label>
                            <input type="text" name="whatsapp_url" value="<?php echo esc_attr( $whatsapp_url ); ?>" placeholder="https://wa.me/234...">
                        </div>
                    </div>

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>Public Profile URL Slug</label>
                            <input type="text" name="profile_slug" value="<?php echo esc_attr( $client->profile_slug ?? '' ); ?>" placeholder="e.g. maria-beats" required>
                            <span class="ofp-field-hint">Public link: <?php echo esc_html( home_url( '/agent/' ) ); ?><strong>slug</strong></span>
                        </div>
                        <div class="ofp-field-group">
                            <label>Facebook / Meta Pixel ID</label>
                            <input type="text" name="meta_pixel_id" value="<?php echo esc_attr( $client->meta_pixel_id ?? '' ); ?>" placeholder="e.g. 1234567890">
                            <span class="ofp-field-hint">For tracking visitors on your public page</span>
                        </div>
                    </div>

                    <!-- Preserve core fields -->
                    <input type="hidden" name="owner_name" value="<?php echo esc_attr( $display_name ); ?>">
                    <input type="hidden" name="bio" value="<?php echo esc_attr( $client->bio ?? '' ); ?>">
                    <input type="hidden" name="role" value="<?php echo esc_attr( $role ); ?>">
                    <input type="hidden" name="experience" value="<?php echo esc_attr( $experience ); ?>">
                    <input type="hidden" name="favorite_artists" value="<?php echo esc_attr( $favorite_artists ); ?>">
                    <input type="hidden" name="favorite_genre" value="<?php echo esc_attr( $favorite_genre ); ?>">
                    <input type="hidden" name="software_equipment" value="<?php echo esc_attr( $software_equipment ); ?>">
                    <input type="hidden" name="music_mood" value="<?php echo esc_attr( $music_mood ); ?>">
                    <input type="hidden" name="city_region" value="<?php echo esc_attr( $city_region ); ?>">
                    <input type="hidden" name="availability" value="<?php echo esc_attr( $availability ); ?>">
                    <input type="hidden" name="badges" value="<?php echo esc_attr( $badges ); ?>">
                    <input type="hidden" name="tags" value="<?php echo esc_attr( $tags ); ?>">

                    <div class="ofp-modal-footer">
                        <button type="button" class="ofp-modal-btn-cancel" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="ofp-modal-btn-save">Save Links</button>
                    </div>
                </form>
            </div>

            <!-- Tab 3: Bank Accounts -->
            <div class="ofp-tab-panel" id="tab-banking">
                <?php $ofp_current_user = OFP_Auth::current_user(); if ( empty( $ofp_current_user->is_team_member ) ) : $ofp_banks = OFP_Bank_Account::list( (int) $client->id ); ?>
                    <form method="POST" action="">
                        <input type="hidden" name="ofp_account_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ofp_account_' . $client->id ) ); ?>">

                        <p style="font-size:13px; color:var(--prof-text-sub); margin-bottom:16px;">Buyers and tenants send money to these accounts. Every change requires password verification.</p>

                        <?php if ( $ofp_banks ) : foreach ( $ofp_banks as $b ) : ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; padding:12px; border:1px solid var(--prof-border); border-radius:10px; margin-bottom:10px; background:rgba(255,255,255,0.02);">
                                <div>
                                    <strong style="color:#ffffff; font-size:14px;"><?php echo esc_html( $b->bank_name ); ?></strong> &bull; <?php echo esc_html( $b->account_number ); ?><br>
                                    <span style="font-size:12px; color:var(--prof-text-sub);"><?php echo esc_html( $b->account_name ); ?></span>
                                    <?php if ( (int) $b->is_default === 1 ) : ?><span style="margin-left:6px; font-size:11px; background:rgba(34,197,94,0.15); color:#22c55e; padding:2px 8px; border-radius:10px; font-weight:600;">Default</span><?php endif; ?>
                                </div>
                                <div style="display:flex; gap:8px;">
                                    <?php if ( (int) $b->is_default !== 1 ) : ?>
                                        <button type="submit" name="bank_do" value="default:<?php echo (int) $b->id; ?>" class="ofp-btn-edit-profile" style="padding:4px 10px; font-size:11px;">Make default</button>
                                    <?php endif; ?>
                                    <button type="submit" name="bank_do" value="delete:<?php echo (int) $b->id; ?>" class="ofp-btn-edit-profile" style="padding:4px 10px; font-size:11px; color:#ef4444;" onclick="return confirm('Remove this bank account?');">Remove</button>
                                </div>
                            </div>
                        <?php endforeach; else : ?>
                            <p style="font-size:13px; color:var(--prof-text-sub); margin-bottom:16px;">No bank account configured yet.</p>
                        <?php endif; ?>

                        <h4 style="font-size:14px; color:#ffffff; margin:20px 0 12px;">Add New Bank Account</h4>
                        <div class="ofp-form-row-2">
                            <div class="ofp-field-group">
                                <label>Bank Name</label>
                                <input type="text" name="bank_name" list="ofp-bank-names" placeholder="e.g. GTBank">
                                <datalist id="ofp-bank-names">
                                    <?php foreach ( [ 'Access Bank', 'GTBank', 'First Bank', 'UBA', 'Zenith Bank', 'Fidelity Bank', 'Stanbic IBTC', 'Sterling Bank', 'Union Bank', 'Wema Bank', 'Polaris Bank', 'FCMB', 'Ecobank', 'Kuda', 'Opay', 'Palmpay', 'Moniepoint', '9PSB' ] as $bn ) : ?>
                                        <option value="<?php echo esc_attr( $bn ); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="ofp-field-group">
                                <label>Account Number (10 digits)</label>
                                <input type="text" name="bank_account_number" inputmode="numeric" maxlength="10" placeholder="0123456789">
                            </div>
                        </div>

                        <div class="ofp-form-row-2">
                            <div class="ofp-field-group">
                                <label>Account Name</label>
                                <input type="text" name="bank_account_name" placeholder="Name on the bank account">
                            </div>
                            <div class="ofp-field-group">
                                <label>Confirm with Password</label>
                                <input type="password" name="bank_password" autocomplete="current-password" placeholder="Enter password to save">
                            </div>
                        </div>

                        <div class="ofp-modal-footer">
                            <button type="button" class="ofp-modal-btn-cancel" onclick="closeEditModal()">Cancel</button>
                            <button type="submit" name="bank_do" value="add" class="ofp-modal-btn-save">Add Account</button>
                        </div>
                    </form>
                <?php else : ?>
                    <p style="color:var(--prof-text-sub); font-size:14px;">Only primary clients can manage payment accounts.</p>
                <?php endif; ?>
            </div>

            <!-- Tab 4: Security (Password Change) -->
            <div class="ofp-tab-panel" id="tab-security">
                <form method="POST" action="">
                    <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
                    <input type="hidden" name="change_password" value="1">

                    <div class="ofp-field-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" required minlength="8" placeholder="At least 8 characters">
                        </div>
                        <div class="ofp-field-group">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" required minlength="8" placeholder="Repeat new password">
                        </div>
                    </div>

                    <div class="ofp-modal-footer">
                        <button type="button" class="ofp-modal-btn-cancel" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="ofp-modal-btn-save">Change Password</button>
                    </div>
                </form>
            </div>

            <!-- Tab 5: Branding (Gold / Solo Company Only) -->
            <?php if ( OFP_Brand::can_brand( $client ) && OFP_White_Label::schema_ready() ) : ?>
            <div class="ofp-tab-panel" id="tab-branding">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="ofp_account_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ofp_account_' . $client->id ) ); ?>">
                    <input type="hidden" name="update_branding" value="1">

                    <?php
                    $color_rows = [
                        'brand_color'      => [ 'Main Accent Color', '#3b82f6' ],
                        'sidebar_color'    => [ 'Sidebar Background', '#1c2233' ],
                        'menu_hover_color' => [ 'Menu Hover Color', '#252e42' ],
                    ];
                    foreach ( $color_rows as $field => $row ) :
                        $saved = $client->$field ?? '';
                    ?>
                    <div class="ofp-field-group" style="flex-direction:row; align-items:center; justify-content:space-between; margin-bottom:12px;">
                        <div>
                            <label style="margin:0;"><?php echo esc_html( $row[0] ); ?></label>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="color" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $saved ?: $row[1] ); ?>" oninput="this.form.elements['use_default_<?php echo esc_attr( $field ); ?>'].checked=false;" style="width:48px;height:34px;padding:2px;border-radius:6px;background:none;border:none;cursor:pointer;">
                            <label style="font-size:12px;color:var(--prof-text-sub);cursor:pointer;">
                                <input type="checkbox" name="use_default_<?php echo esc_attr( $field ); ?>" value="1" <?php checked( empty( $saved ) ); ?>> Default
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="ofp-field-group">
                        <label>Favicon (PNG, ICO, WebP or JPG, max 100KB)</label>
                        <?php if ( ! empty( $client->favicon_url ) ) : ?>
                            <div style="display:flex; align-items:center; gap:12px; margin-bottom:8px;">
                                <img src="<?php echo esc_url( $client->favicon_url ); ?>" alt="Favicon" style="width:28px;height:28px;object-fit:contain;">
                                <label style="font-size:12px;color:var(--prof-text-sub);cursor:pointer;">
                                    <input type="checkbox" name="remove_favicon" value="1"> Remove favicon
                                </label>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="favicon" accept="image/png,image/x-icon,image/webp,image/jpeg">
                    </div>

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>Support Email</label>
                            <input type="email" name="support_email" value="<?php echo esc_attr( $client->support_email ?? '' ); ?>" placeholder="support@yourcompany.com">
                        </div>
                        <div class="ofp-field-group">
                            <label>Support Phone</label>
                            <input type="text" name="support_phone" value="<?php echo esc_attr( $client->support_phone ?? '' ); ?>" placeholder="+234...">
                        </div>
                    </div>

                    <div class="ofp-modal-footer">
                        <button type="button" class="ofp-modal-btn-cancel" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="ofp-modal-btn-save">Save Branding</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

        </div> <!-- /.ofp-modal-body -->
    </div> <!-- /.ofp-modal-window -->
</div> <!-- /.ofp-modal-backdrop -->

<!-- Modal Drawer and Avatar Script -->
<script>
(function() {
    // Avatar upload auto-submit
    const logoInput = document.getElementById('ofp-logo-input');
    const logoForm  = document.getElementById('ofp-logo-form');
    if (logoInput && logoForm) {
        logoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            if (file.size > 500 * 1024) {
                alert('File is too large! Please select an image under 500KB.');
                e.target.value = '';
                return;
            }
            logoForm.submit();
        });
    }

    // Modal controls
    const backdrop   = document.getElementById('ofp-edit-modal-backdrop');
    const openBtn    = document.getElementById('ofp-open-edit-modal');
    const closeBtn   = document.getElementById('ofp-close-modal-btn');
    const tabButtons = document.querySelectorAll('.ofp-modal-tab-btn');
    const tabPanels  = document.querySelectorAll('.ofp-tab-panel');

    window.openEditModal = function() {
        if (backdrop) {
            backdrop.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    };

    window.closeEditModal = function() {
        if (backdrop) {
            backdrop.classList.remove('active');
            document.body.style.overflow = '';
        }
    };

    if (openBtn) {
        openBtn.addEventListener('click', openEditModal);
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', closeEditModal);
    }
    if (backdrop) {
        backdrop.addEventListener('click', function(e) {
            if (e.target === backdrop) {
                closeEditModal();
            }
        });
    }

    // Modal tabs switching
    tabButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-tab');
            tabButtons.forEach(b => b.classList.remove('active'));
            tabPanels.forEach(p => p.classList.remove('active'));
            
            this.classList.add('active');
            const targetPanel = document.getElementById(targetId);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
        });
    });

    // Auto-open modal on error or success if returning from an action
    <?php if ( ! empty( $error ) || ( ! empty( $success ) && ( isset($_POST['update_profile']) || isset($_POST['bank_do']) || isset($_POST['change_password']) || isset($_POST['update_branding']) ) ) ) : ?>
        // If bank error, switch to bank tab
        <?php if ( isset($_POST['bank_do']) ) : ?>
            const bankTab = document.querySelector('[data-tab="tab-banking"]');
            if (bankTab) bankTab.click();
        <?php elseif ( isset($_POST['change_password']) ) : ?>
            const secTab = document.querySelector('[data-tab="tab-security"]');
            if (secTab) secTab.click();
        <?php elseif ( isset($_POST['update_branding']) ) : ?>
            const brandTab = document.querySelector('[data-tab="tab-branding"]');
            if (brandTab) brandTab.click();
        <?php endif; ?>
    <?php endif; ?>
})();
</script>

</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
