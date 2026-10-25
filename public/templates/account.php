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

// Ensure database columns exist (cached to prevent queries on every request)
if ( false === get_transient( 'ofp_clients_account_schema_v2' ) ) {
    $has_logo = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'logo_url'");
    if (empty($has_logo)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}ofp_clients ADD COLUMN logo_url VARCHAR(255) DEFAULT NULL AFTER business_category");
    }
    $has_cover = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'cover_image_url'");
    if (empty($has_cover)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}ofp_clients ADD COLUMN cover_image_url VARCHAR(255) DEFAULT NULL AFTER logo_url");
    }
    $has_stamp = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'stamp_url'");
    if (empty($has_stamp)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}ofp_clients ADD COLUMN stamp_url VARCHAR(255) DEFAULT NULL AFTER cover_image_url");
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
    set_transient( 'ofp_clients_account_schema_v2', 1, MONTH_IN_SECONDS );
}

// Ensure verification audit columns exist
if ( class_exists( 'OFP_Prembly' ) ) {
    OFP_Prembly::ensure_client_schema();
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
        $slug          = sanitize_title( $_POST['profile_slug'] ?? '' );
        $bio           = sanitize_textarea_field( $_POST['bio'] ?? '' );
        $pixel         = sanitize_text_field( $_POST['meta_pixel_id'] ?? '' );
        $owner_name    = sanitize_text_field( $_POST['owner_name'] ?? '' );
        $phone          = sanitize_text_field( $_POST['phone'] ?? '' );
        $support_email  = sanitize_email( $_POST['support_email'] ?? '' );
        $support_phone  = sanitize_text_field( $_POST['support_phone'] ?? '' );
        $watermark_text = sanitize_text_field( $_POST['watermark_text'] ?? '' );

        // Preserve social links
        $extra_data = [
            'youtube_url'   => esc_url_raw( $_POST['youtube_url'] ?? '' ),
            'instagram_url' => esc_url_raw( $_POST['instagram_url'] ?? '' ),
            'tiktok_url'    => esc_url_raw( $_POST['tiktok_url'] ?? '' ),
            'whatsapp_url'  => esc_url_raw( $_POST['whatsapp_url'] ?? '' ),
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
            if ( isset( $_POST['support_email'] ) ) {
                $update_fields['support_email'] = $support_email;
                $client->support_email = $support_email;
            }
            if ( isset( $_POST['support_phone'] ) ) {
                $update_fields['support_phone'] = $support_phone;
                $client->support_phone = $support_phone;
            }
            // Save client's custom watermark text
            if ( isset( $_POST['watermark_text'] ) ) {
                $update_fields['watermark_text'] = $watermark_text;
                $client->watermark_text = $watermark_text;
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
            if ( $bank_msg ) {
                if ( ! empty( $client->email ) ) {
                    OFP_Mailer::send(
                        $client->email,
                        $client->owner_name ?: 'there',
                        'Your bank account details were changed',
                        '<p>' . esc_html( $bank_msg ) . ' Buyers and tenants will now see the new details.</p><p>If you did not do this, change your password right away.</p>'
                    );
                }
                wp_safe_redirect( add_query_arg( 'success', 'bank_updated', home_url( '/account' ) ) );
                exit;
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
    } elseif ( isset( $_POST['upload_cover'] ) && isset( $_FILES['cover_image'] ) ) {
        $file = $_FILES['cover_image'];
        
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            $error = 'There was an error uploading the cover image. Please try again.';
        } elseif ( $file['size'] > 3 * 1024 * 1024 ) {
            $error = 'Cover image is too large. Maximum size is 3MB.';
        } else {
            $allowed_types = [ 'image/jpeg', 'image/png', 'image/webp' ];
            $file_type = wp_check_filetype( $file['name'] );
            
            if ( ! in_array( $file_type['type'], $allowed_types ) ) {
                $error = 'Invalid file type. Only JPG, PNG, and WebP are allowed.';
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
                        [ 'cover_image_url' => $movefile['url'] ],
                        [ 'id' => $client->id ]
                    );
                    
                    wp_safe_redirect( add_query_arg( 'success', 'cover', home_url( '/account' ) ) );
                    exit;
                } else {
                    $error = $movefile['error'] ?? 'Failed to save uploaded cover image.';
                }
            }
        }
    } elseif ( isset( $_POST['remove_cover'] ) ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ofp_clients',
            [ 'cover_image_url' => null ],
            [ 'id' => $client->id ]
        );
        
        wp_safe_redirect( add_query_arg( 'success', 'cover_reset', home_url( '/account' ) ) );
        exit;
    } elseif ( isset( $_POST['upload_stamp'] ) && isset( $_FILES['stamp_image'] ) ) {
        $file = $_FILES['stamp_image'];
        
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            $error = 'There was an error uploading the stamp image. Please try again.';
        } elseif ( $file['size'] > 2 * 1024 * 1024 ) {
            $error = 'Stamp image is too large. Maximum size is 2MB.';
        } else {
            $allowed_types = [ 'image/png', 'image/jpeg', 'image/webp' ];
            $file_type = wp_check_filetype( $file['name'] );
            
            if ( ! in_array( $file_type['type'], $allowed_types ) ) {
                $error = 'Invalid file type. Only PNG, JPG, and WebP are allowed (transparent PNG recommended).';
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
                        [ 'stamp_url' => $movefile['url'] ],
                        [ 'id' => $client->id ]
                    );
                    
                    wp_safe_redirect( add_query_arg( 'success', 'stamp', home_url( '/account' ) ) );
                    exit;
                } else {
                    $error = $movefile['error'] ?? 'Failed to save uploaded stamp image.';
                }
            }
        }
    } elseif ( isset( $_POST['remove_stamp'] ) ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ofp_clients',
            [ 'stamp_url' => null ],
            [ 'id' => $client->id ]
        );
        
        wp_safe_redirect( add_query_arg( 'success', 'stamp_reset', home_url( '/account' ) ) );
        exit;
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
                    
                    wp_safe_redirect( add_query_arg( 'success', 'logo', home_url( '/account' ) ) );
                    exit;
                } else {
                    $error = $movefile['error'] ?? 'Failed to move uploaded file.';
                }
            }
        }
    }
}

// Handle success messages from redirects
if ( isset( $_GET['success'] ) ) {
    if ( $_GET['success'] === 'logo' ) {
        $success = 'Profile photo updated successfully.';
    } elseif ( $_GET['success'] === 'cover' ) {
        $success = 'Cover image updated successfully! It is now live on your public /agent profile.';
    } elseif ( $_GET['success'] === 'cover_reset' ) {
        $success = 'Cover image reset to default platform banner.';
    } elseif ( $_GET['success'] === 'stamp' ) {
        $success = 'Official verification stamp updated successfully! It is now applied to all your transaction receipts.';
    } elseif ( $_GET['success'] === 'stamp_reset' ) {
        $success = 'Official verification stamp reset to platform default verified seal.';
    } elseif ( $_GET['success'] === 'bank_updated' ) {
        $success = 'Bank account details updated successfully.';
    }
}

// ── Profile Data Extraction & Fallbacks ──────────────────────────────────────
$extra = [];
if ( ! empty( $client->profile_extra ) ) {
    $extra = json_decode( $client->profile_extra, true ) ?: [];
}

$display_name = ! empty( $client->owner_name ) ? $client->owner_name : ( ! empty( $client->business_name ) ? $client->business_name : 'Agent' );

$base_domain = get_option( 'ofp_crm_base_domain' ) ?: ( isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d+$/', '', $_SERVER['HTTP_HOST'] ) : 'ofastpipeline.com' );
$client_subdomain = ! empty( $client->subdomain ) ? $client->subdomain : '';
$portal_url = $client_subdomain ? ( 'https://' . $client_subdomain . '.' . $base_domain ) : '';

$youtube_url        = ! empty( $extra['youtube_url'] ) ? $extra['youtube_url'] : 'https://youtube.com';
$instagram_url      = ! empty( $extra['instagram_url'] ) ? $extra['instagram_url'] : 'https://instagram.com';
$tiktok_url         = ! empty( $extra['tiktok_url'] ) ? $extra['tiktok_url'] : 'https://tiktok.com';
$whatsapp_url       = ! empty( $extra['whatsapp_url'] ) ? $extra['whatsapp_url'] : ( ! empty( $client->whatsapp_number ) ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $client->whatsapp_number) : ( ! empty( $client->phone ) ? 'https://wa.me/' . preg_replace('/[^0-9]/', '', $client->phone) : 'https://whatsapp.com' ) );

// Avatar image URL
$avatar_url = ! empty( $client->logo_url ) ? $client->logo_url : OFP_URL . 'assets/images/default-avatar.png';

// Cover banner image URL
$default_cover    = OFP_URL . 'assets/images/dashboard-hero-banner.webp';
$cover_url        = ! empty( $client->cover_image_url ) ? $client->cover_image_url : $default_cover;
$has_custom_cover = ! empty( $client->cover_image_url );

// Stamp / seal image URL (used on receipts & vouchers)
$default_stamp    = OFP_URL . 'assets/images/receipt-verified-stamp.png';
$stamp_url        = ! empty( $client->stamp_url ) ? $client->stamp_url : $default_stamp;
$has_custom_stamp = ! empty( $client->stamp_url );

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

// ── Bank Accounts & Branding Data ───────────────────────────────────────────
$client_banks = class_exists( 'OFP_Bank_Account' ) ? OFP_Bank_Account::list( (int) $client->id ) : [];
$can_brand    = class_exists( 'OFP_Brand' ) && OFP_Brand::can_brand( $client );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Profile', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/profile.css?v=' . ( file_exists( OFP_PATH . 'assets/css/profile.css' ) ? filemtime( OFP_PATH . 'assets/css/profile.css' ) : OFP_VERSION ) ); ?>">
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

    <!-- ── Top 2-Column Grid ─────────────────────────────────────────────── -->
    <div class="ofp-profile-top-grid">

        <!-- Left Card: User Identity & Avatar -->
        <div class="ofp-profile-card ofp-avatar-card">
            <div class="ofp-avatar-meta">
                <h2 class="ofp-avatar-name"><?php echo esc_html( $display_name ); ?></h2>
                <div style="display:flex; align-items:center; gap:8px; justify-content:center; flex-wrap:wrap;">
                    <div class="ofp-avatar-tier"><?php echo esc_html( $plan_badge ); ?></div>
                    <?php if ( ! empty( $client->is_verified ) ) : ?>
                        <span style="display:inline-flex; align-items:center; gap:4px; background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3); font-size:11px; font-weight:600; padding:2px 8px; border-radius:12px;" title="Government CAC & Identity Verified">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            Verified Agency
                        </span>
                    <?php endif; ?>
                </div>
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
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    <span style="font-size:11px; font-weight:600;">Change</span>
                </div>
            </div>

            <!-- About Us / Bio under Profile Image -->
            <div class="ofp-avatar-bio" style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--prof-border); width: 100%; text-align: center;">
                <span class="ofp-detail-label" style="display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.8px; color: var(--prof-text-sub); font-weight: 600; margin-bottom: 8px;">About Us</span>
                <p style="margin: 0; font-size: 13.5px; line-height: 1.6; color: var(--prof-text-val); text-align: center; max-width: 92%; margin-left: auto; margin-right: auto;">
                    <?php 
                    $bio_raw = trim( (string) ( $client->bio ?: 'No agency bio provided yet. Click "Edit Profile" in the top bar to add your agency description and details.' ) );
                    $bio_words = preg_split( '/\s+/', $bio_raw );
                    if ( count( $bio_words ) > 18 ) {
                        $bio_display = implode( ' ', array_slice( $bio_words, 0, 18 ) ) . '..........';
                    } else {
                        $bio_display = $bio_raw;
                    }
                    echo nl2br( esc_html( $bio_display ) );
                    ?>
                </p>
            </div>
        </div>

        <!-- Right Card: Bio & Other Details -->
        <div class="ofp-profile-card ofp-bio-card">
            <div class="ofp-bio-card-header">
                <h3 class="ofp-bio-card-title">Company & Profile Details</h3>
                <span class="ofp-online-indicator" title="Status: Active"></span>
            </div>

            <div class="ofp-details-matrix">
                <!-- Row 1: Business Name & Owner Name -->
                <div class="ofp-details-row">
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Business / Agency Name</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $client->business_name ); ?></span>
                    </div>
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Account Owner</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $client->owner_name ); ?></span>
                    </div>
                </div>

                <!-- Row 2: Email & Phone -->
                <div class="ofp-details-row">
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Email Address</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $client->email ); ?></span>
                    </div>
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Mobile / Phone Number</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $client->phone ?: 'Not provided' ); ?></span>
                    </div>
                </div>

                <!-- Row 3: Branded Portal Address & Status -->
                <div class="ofp-details-row">
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Branded Portal Address</span>
                        <span class="ofp-detail-val">
                            <?php if ( $portal_url ) : ?>
                                <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" style="color:var(--prof-teal); text-decoration:none; font-weight:600;"><?php echo esc_html( $portal_url ); ?></a>
                            <?php else : ?>
                                <span style="color:var(--prof-text-sub);">No custom subdomain assigned</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Account Status</span>
                        <div class="ofp-availability-badge">
                            <span class="ofp-pill-pulse-dot"></span>
                            <span style="text-transform:capitalize;"><?php echo esc_html( $client->status ?: 'Active' ); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Row 4: Public Landing Page & Meta Pixel -->
                <div class="ofp-details-row">
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Public Landing Page</span>
                        <span class="ofp-detail-val">
                            <?php $agent_url = home_url( '/agent/' . ( $client->profile_slug ?: 'agent-' . $client->id ) ); ?>
                            <a href="<?php echo esc_url( $agent_url ); ?>" target="_blank" style="color:var(--prof-teal); text-decoration:none; font-weight:600;"><?php echo esc_html( $agent_url ); ?></a>
                        </span>
                    </div>
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Facebook Meta Pixel ID</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $client->meta_pixel_id ?: 'Not configured' ); ?></span>
                    </div>
                </div>

                <!-- Row 5: Support Contact -->
                <?php if ( ! empty( $client->support_email ) || ! empty( $client->support_phone ) ) : ?>
                <div class="ofp-details-row">
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Support Email</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $client->support_email ?: $client->email ); ?></span>
                    </div>
                    <div class="ofp-detail-col">
                        <span class="ofp-detail-label">Support Phone</span>
                        <span class="ofp-detail-val"><?php echo esc_html( $client->support_phone ?: 'Same as mobile' ); ?></span>
                    </div>
                </div>
                <?php endif; ?>


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

    <!-- ── 2-Column Split: Branding (Left) & Bank/CAC (Right) ───────────── -->
    <div class="ofp-branding-bank-split-grid">
        
        <!-- Column 1: Branding & White-Label Portal Card -->
        <div class="ofp-profile-card ofp-branding-card" style="min-width:0 !important; margin-bottom:0 !important; height:100% !important;">
            <div class="ofp-productions-header-row" style="margin-bottom: 22px; border-bottom: 1px solid var(--prof-border); padding-bottom: 16px;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <div style="width:38px; height:38px; border-radius:10px; background:rgba(0,188,181,0.12); display:flex; align-items:center; justify-content:center; color:var(--prof-teal); flex-shrink:0;">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" /></svg>
                        </div>
                        <div>
                            <h3 class="ofp-productions-title" style="margin:0;">Branding &amp; White-Label Portal</h3>
                            <p style="margin:2px 0 0; font-size:12.5px; color:var(--prof-text-sub);">Custom white-label portal identity &amp; receipt seal</p>
                        </div>
                    </div>
                    <button type="button" class="ofp-productions-view-all" onclick="openEditModal('tab-branding')" style="background:rgba(255,255,255,0.04); border:1px solid var(--prof-border); border-radius:8px; padding:6px 14px; cursor:pointer;">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        <span>Customize Branding</span>
                    </button>
                </div>

                <div class="ofp-branding-col">

                <div style="background:var(--prof-card-inner, #131722); border:1px solid var(--prof-border); border-radius:12px; padding:18px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
                        <div>
                            <span style="display:block; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--prof-text-sub); font-weight:600;">Branded Portal Address</span>
                            <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" style="color:var(--prof-teal); text-decoration:none; font-weight:600; font-size:13.5px; word-break:break-all;">
                                <?php echo esc_html( $portal_url ); ?>
                            </a>
                        </div>
                        <span class="ofp-status-pill pill-live" style="font-size:11px; padding:3px 9px;">
                            <span class="ofp-pill-pulse-dot"></span> Active Portal
                        </span>
                    </div>

                    <!-- Theme Color Swatches -->
                    <span style="display:block; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--prof-text-sub); font-weight:600; margin:14px 0 8px;">Portal Color Palette</span>
                    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px;">
                        <?php
                        $accent_color   = ! empty( $client->brand_color ) ? $client->brand_color : '#3b82f6';
                        $sidebar_color  = ! empty( $client->sidebar_color ) ? $client->sidebar_color : '#1c2233';
                        $menu_hov_color = ! empty( $client->menu_hover_color ) ? $client->menu_hover_color : '#252e42';
                        ?>
                        <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.05); border-radius:8px; padding:8px 10px; display:flex; align-items:center; gap:8px;">
                            <span style="width:20px; height:20px; border-radius:6px; background:<?php echo esc_attr($accent_color); ?>; border:1px solid rgba(255,255,255,0.2); flex-shrink:0;"></span>
                            <div>
                                <span style="display:block; font-size:10px; color:var(--prof-text-sub);">Accent</span>
                                <span style="font-size:11px; font-weight:600; color:#fff; font-family:monospace;"><?php echo esc_html($accent_color); ?></span>
                            </div>
                        </div>

                        <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.05); border-radius:8px; padding:8px 10px; display:flex; align-items:center; gap:8px;">
                            <span style="width:20px; height:20px; border-radius:6px; background:<?php echo esc_attr($sidebar_color); ?>; border:1px solid rgba(255,255,255,0.2); flex-shrink:0;"></span>
                            <div>
                                <span style="display:block; font-size:10px; color:var(--prof-text-sub);">Sidebar</span>
                                <span style="font-size:11px; font-weight:600; color:#fff; font-family:monospace;"><?php echo esc_html($sidebar_color); ?></span>
                            </div>
                        </div>

                        <div style="background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.05); border-radius:8px; padding:8px 10px; display:flex; align-items:center; gap:8px;">
                            <span style="width:20px; height:20px; border-radius:6px; background:<?php echo esc_attr($menu_hov_color); ?>; border:1px solid rgba(255,255,255,0.2); flex-shrink:0;"></span>
                            <div>
                                <span style="display:block; font-size:10px; color:var(--prof-text-sub);">Hover</span>
                                <span style="font-size:11px; font-weight:600; color:#fff; font-family:monospace;"><?php echo esc_html($menu_hov_color); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Favicon & Support Info -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:14px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.05);">
                        <div>
                            <span style="display:block; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--prof-text-sub); font-weight:600;">Favicon</span>
                            <?php if ( ! empty( $client->favicon_url ) ) : ?>
                                <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
                                    <img src="<?php echo esc_url( $client->favicon_url ); ?>" alt="Favicon" style="width:20px; height:20px; object-fit:contain; border-radius:4px;">
                                    <span style="font-size:12px; color:#ffffff;">Custom Favicon</span>
                                </div>
                            <?php else : ?>
                                <span style="font-size:12px; color:var(--prof-text-sub); margin-top:4px; display:inline-block;">Default System Icon</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span style="display:block; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--prof-text-sub); font-weight:600;">Support Inquiries</span>
                            <span style="font-size:12px; color:#ffffff; margin-top:4px; display:inline-block;"><?php echo esc_html( $client->support_email ?: ($client->email ?: 'Not specified') ); ?></span>
                        </div>
                    </div>

                    <!-- Official Receipt Stamp in Branding card -->
                    <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--prof-border); display:flex; align-items:center; justify-content:space-between; gap:12px;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="width:42px; height:42px; border-radius:8px; border:1px solid var(--prof-border); background:rgba(255,255,255,0.03); display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0;">
                                <img src="<?php echo esc_url( $stamp_url ); ?>" alt="Stamp" style="max-width:100%; max-height:100%; object-fit:contain; transform:rotate(-10deg);">
                            </div>
                            <div>
                                <span style="display:block; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--prof-text-sub); font-weight:600;">Receipt Verification Stamp</span>
                                <span style="font-size:12px; color:<?php echo $has_custom_stamp ? '#10b981' : '#94a3b8'; ?>; font-weight:600;">
                                    <?php echo $has_custom_stamp ? '✓ Custom Stamp Active' : 'Default Platform Seal (Fallback)'; ?>
                                </span>
                            </div>
                        </div>
                        <button type="button" onclick="openEditModal('tab-profile')" style="background:none; border:none; color:var(--prof-teal); font-size:12px; cursor:pointer; font-weight:600; text-decoration:underline;">
                            Change
                        </button>
                    </div>
                </div>

                <!-- ── Help Shape the Platform (Feedback & Bug Report) under Stamp ── -->
                <div style="margin-top:16px;">
                    <h4 style="font-size:13.5px; font-weight:600; color:#ffffff; margin:0 0 10px; display:flex; align-items:center; gap:8px;">
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#10b981;"></span>
                        Platform Feedback &amp; Suggestions
                    </h4>
                    <div style="background:var(--prof-card-inner, #131722); border:1px solid var(--prof-border); border-radius:12px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="width:38px; height:38px; border-radius:10px; background:rgba(16, 185, 129, 0.12); color:#10b981; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                            </div>
                            <div>
                                <h4 style="margin:0 0 3px 0; font-size:13.5px; font-weight:700; color:#ffffff;">Help Shape the Platform</h4>
                                <p style="margin:0; font-size:11.5px; color:var(--prof-text-sub, #94a3b8); line-height:1.4;">Got a feature idea or ran into an unexpected glitch? Share your thoughts with optional YouTube video walkthroughs.</p>
                            </div>
                        </div>
                        <button type="button" class="ofp-open-feedback-trigger" data-type="feature_request" style="display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:8px; background:#10b981; color:#ffffff; border:none; font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap; transition:background 0.15s ease;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                            <span>Feedback &amp; Bug Report</span>
                        </button>
                    </div>
                </div>
            </div>
        </div> <!-- /.ofp-branding-card -->

        <!-- Column 2: Bank Accounts & CAC / KYC Verification Card -->
        <div class="ofp-profile-card ofp-bank-card-section" style="display:flex; flex-direction:column; justify-content:space-between; min-width:0 !important; margin-bottom:0 !important; height:100% !important;">
            <div>
                <div class="ofp-productions-header-row" style="margin-bottom: 22px; border-bottom: 1px solid var(--prof-border); padding-bottom: 16px;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <div style="width:38px; height:38px; border-radius:10px; background:rgba(34,197,94,0.12); display:flex; align-items:center; justify-content:center; color:#22c55e; flex-shrink:0;">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                        </div>
                        <div>
                            <h3 class="ofp-productions-title" style="margin:0;">Client Bank &amp; Payout Accounts</h3>
                            <p style="margin:2px 0 0; font-size:12.5px; color:var(--prof-text-sub);">Payout bank accounts &amp; government CAC verification</p>
                        </div>
                    </div>
                    <button type="button" class="ofp-productions-view-all" onclick="openEditModal('tab-banking')" style="background:rgba(255,255,255,0.04); border:1px solid var(--prof-border); border-radius:8px; padding:6px 14px; cursor:pointer;">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                        <span><?php echo ! empty( $client_banks ) ? 'Manage Accounts' : '+ Link Account'; ?></span>
                    </button>
                </div>

                <div class="ofp-bank-col">

                <div style="display:flex; flex-direction:column; gap:12px;">
                    <?php if ( ! empty( $client_banks ) ) : foreach ( $client_banks as $bank ) : ?>
                        <div class="ofp-bank-card-container">
                            <div class="ofp-bank-preview ofp-profile-bank-card <?php echo ( (int) $bank->is_default === 1 ) ? 'is-primary' : ''; ?>" onclick="openEditModal('tab-banking')" style="cursor:pointer;" title="Click to manage bank accounts">
                                <!-- Abuja City Gate Landmark Silhouette Overlay -->
                                <svg class="ofp-bank-card-landmark" viewBox="0 0 220 160" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <defs>
                                        <linearGradient id="ofpAbujaGateGrad_<?php echo (int) $bank->id; ?>" x1="0" y1="0" x2="1" y2="1">
                                            <stop offset="0%" stop-color="#fbbf24" stop-opacity="0.55"/>
                                            <stop offset="50%" stop-color="#34d399" stop-opacity="0.38"/>
                                            <stop offset="100%" stop-color="#38bdf8" stop-opacity="0.22"/>
                                        </linearGradient>
                                    </defs>
                                    <path d="M 18 150 C 45 130 65 85 80 20 L 90 28 C 76 88 56 130 36 150 Z" fill="url(#ofpAbujaGateGrad_<?php echo (int) $bank->id; ?>)" stroke="#fbbf24" stroke-width="0.8" stroke-opacity="0.6"/>
                                    <path d="M 202 150 C 175 130 155 85 140 20 L 130 28 C 144 88 164 130 184 150 Z" fill="url(#ofpAbujaGateGrad_<?php echo (int) $bank->id; ?>)" stroke="#fbbf24" stroke-width="0.8" stroke-opacity="0.6"/>
                                    <line x1="80" y1="20" x2="110" y2="65" stroke="#38bdf8" stroke-width="0.75" stroke-opacity="0.45"/>
                                    <line x1="140" y1="20" x2="110" y2="65" stroke="#38bdf8" stroke-width="0.75" stroke-opacity="0.45"/>
                                    <line x1="84" y1="38" x2="110" y2="65" stroke="#34d399" stroke-width="0.6" stroke-opacity="0.35"/>
                                    <line x1="136" y1="38" x2="110" y2="65" stroke="#34d399" stroke-width="0.6" stroke-opacity="0.35"/>
                                    <polygon points="110,48 118,58 110,68 102,58" fill="#fbbf24" fill-opacity="0.35" stroke="#fbbf24" stroke-width="1" stroke-opacity="0.7"/>
                                    <rect x="88" y="36" width="44" height="26" rx="2" fill="none" stroke="#34d399" stroke-width="0.8" stroke-opacity="0.5"/>
                                    <path d="M 64 150 L 64 92 L 80 76 L 140 76 L 156 92 L 156 150" fill="none" stroke="#34d399" stroke-width="1" stroke-opacity="0.6"/>
                                    <line x1="60" y1="92" x2="160" y2="92" stroke="#fbbf24" stroke-width="0.8" stroke-opacity="0.5"/>
                                    <path d="M 88 150 L 88 116 C 88 100 132 100 132 116 L 132 150" fill="none" stroke="#fbbf24" stroke-width="1.4" stroke-opacity="0.75"/>
                                    <path d="M 94 150 L 94 118 C 94 106 126 106 126 118 L 126 150" fill="none" stroke="#34d399" stroke-width="0.8" stroke-opacity="0.45"/>
                                    <line x1="10" y1="150" x2="210" y2="150" stroke="#34d399" stroke-width="1.2" stroke-opacity="0.5"/>
                                </svg>

                                <!-- Top Bar: EMV Chip + Contactless Waves + Bank Name (Capitalized by default) -->
                                <div class="ofp-bank-preview-top">
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <div class="ofp-bank-chip"></div>
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2" stroke-linecap="round"><path d="M8.5 16.5a5 5 0 0 1 0-7"/><path d="M12 19a8.5 8.5 0 0 0 0-12"/><path d="M15.5 21.5a12 12 0 0 0 0-17"/></svg>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <?php if ( (int) $bank->is_default === 1 ) : ?>
                                            <span class="ofp-card-badge-primary">
                                                <span class="ofp-badge-dot"></span> Primary
                                            </span>
                                        <?php endif; ?>
                                        <span class="ofp-bank-preview-label">
                                            <?php echo esc_html( strtoupper( $bank->bank_name ?: 'BANK NAME' ) ); ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Middle: Account Number (Grouped 4-digits, Capitalized / Monospace) -->
                                <div class="ofp-bank-preview-num">
                                    <?php 
                                        $raw_acc = preg_replace('/\s+/', '', (string) $bank->account_number);
                                        $formatted_acc = trim(chunk_split($raw_acc, 4, ' '));
                                        echo esc_html( $formatted_acc ?: '0000 0000 00' ); 
                                    ?>
                                </div>

                                <!-- Bottom Bar: Beneficiary (Capitalized by default) + NGN Currency Pill + Verve/Debit mark -->
                                <div class="ofp-bank-preview-bottom">
                                    <div class="ofp-bank-preview-field">
                                        <span class="ofp-bank-preview-sub">Beneficiary</span>
                                        <span class="ofp-bank-preview-val">
                                            <?php echo esc_html( strtoupper( $bank->account_name ?: 'OFFICIAL BENEFICIARY' ) ); ?>
                                        </span>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span class="ofp-card-currency-pill">
                                            <svg width="14" height="10" viewBox="0 0 18 12" fill="none"><rect width="6" height="12" fill="#008751"/><rect x="6" width="6" height="12" fill="#ffffff"/><rect x="12" width="6" height="12" fill="#008751"/></svg> NGN
                                        </span>
                                        <span class="ofp-card-brand-mark">DEBIT</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; else : ?>
                        <!-- Default Physical ATM Card with Abuja City Gate Landmark (Clean, no buttons on card) -->
                        <div class="ofp-bank-card-container">
                            <div class="ofp-bank-preview ofp-profile-bank-card" onclick="openEditModal('tab-banking')" style="cursor:pointer;" title="Click to link your bank account">
                                <!-- Abuja City Gate Landmark Silhouette Overlay -->
                                <svg class="ofp-bank-card-landmark" viewBox="0 0 220 160" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <defs>
                                        <linearGradient id="ofpAbujaGateGrad_default" x1="0" y1="0" x2="1" y2="1">
                                            <stop offset="0%" stop-color="#fbbf24" stop-opacity="0.55"/>
                                            <stop offset="50%" stop-color="#34d399" stop-opacity="0.38"/>
                                            <stop offset="100%" stop-color="#38bdf8" stop-opacity="0.22"/>
                                        </linearGradient>
                                    </defs>
                                    <path d="M 18 150 C 45 130 65 85 80 20 L 90 28 C 76 88 56 130 36 150 Z" fill="url(#ofpAbujaGateGrad_default)" stroke="#fbbf24" stroke-width="0.8" stroke-opacity="0.6"/>
                                    <path d="M 202 150 C 175 130 155 85 140 20 L 130 28 C 144 88 164 130 184 150 Z" fill="url(#ofpAbujaGateGrad_default)" stroke="#fbbf24" stroke-width="0.8" stroke-opacity="0.6"/>
                                    <line x1="80" y1="20" x2="110" y2="65" stroke="#38bdf8" stroke-width="0.75" stroke-opacity="0.45"/>
                                    <line x1="140" y1="20" x2="110" y2="65" stroke="#38bdf8" stroke-width="0.75" stroke-opacity="0.45"/>
                                    <line x1="84" y1="38" x2="110" y2="65" stroke="#34d399" stroke-width="0.6" stroke-opacity="0.35"/>
                                    <line x1="136" y1="38" x2="110" y2="65" stroke="#34d399" stroke-width="0.6" stroke-opacity="0.35"/>
                                    <polygon points="110,48 118,58 110,68 102,58" fill="#fbbf24" fill-opacity="0.35" stroke="#fbbf24" stroke-width="1" stroke-opacity="0.7"/>
                                    <rect x="88" y="36" width="44" height="26" rx="2" fill="none" stroke="#34d399" stroke-width="0.8" stroke-opacity="0.5"/>
                                    <path d="M 64 150 L 64 92 L 80 76 L 140 76 L 156 92 L 156 150" fill="none" stroke="#34d399" stroke-width="1" stroke-opacity="0.6"/>
                                    <line x1="60" y1="92" x2="160" y2="92" stroke="#fbbf24" stroke-width="0.8" stroke-opacity="0.5"/>
                                    <path d="M 88 150 L 88 116 C 88 100 132 100 132 116 L 132 150" fill="none" stroke="#fbbf24" stroke-width="1.4" stroke-opacity="0.75"/>
                                    <path d="M 94 150 L 94 118 C 94 106 126 106 126 118 L 126 150" fill="none" stroke="#34d399" stroke-width="0.8" stroke-opacity="0.45"/>
                                    <line x1="10" y1="150" x2="210" y2="150" stroke="#34d399" stroke-width="1.2" stroke-opacity="0.5"/>
                                </svg>

                                <!-- Top Bar: EMV Chip + Contactless Waves + Bank Name (Capitalized by default) -->
                                <div class="ofp-bank-preview-top">
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <div class="ofp-bank-chip"></div>
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2" stroke-linecap="round"><path d="M8.5 16.5a5 5 0 0 1 0-7"/><path d="M12 19a8.5 8.5 0 0 0 0-12"/><path d="M15.5 21.5a12 12 0 0 0 0-17"/></svg>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span class="ofp-bank-preview-label">
                                            BANK NAME
                                        </span>
                                    </div>
                                </div>

                                <!-- Middle: Account Number (Grouped 4-digits, Capitalized / Monospace) -->
                                <div class="ofp-bank-preview-num" style="letter-spacing:0.18em;">
                                    0000 0000 00
                                </div>

                                <!-- Bottom Bar: Beneficiary (Capitalized by default) + NGN Currency Pill + Verve/Debit mark -->
                                <div class="ofp-bank-preview-bottom">
                                    <div class="ofp-bank-preview-field">
                                        <span class="ofp-bank-preview-sub">Beneficiary</span>
                                        <span class="ofp-bank-preview-val">
                                            <?php 
                                                $default_holder = ! empty( $client->business_name ) ? $client->business_name : ( ! empty( $user->display_name ) ? $user->display_name : 'OFFICIAL BENEFICIARY' );
                                                echo esc_html( strtoupper( $default_holder ) ); 
                                            ?>
                                        </span>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span class="ofp-card-currency-pill">
                                            <svg width="14" height="10" viewBox="0 0 18 12" fill="none"><rect width="6" height="12" fill="#008751"/><rect x="6" width="6" height="12" fill="#ffffff"/><rect x="12" width="6" height="12" fill="#008751"/></svg> NGN
                                        </span>
                                        <span class="ofp-card-brand-mark">DEBIT</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Official Business & Identity Verification Status ──────────── -->
        <div style="margin-top:16px;">
                    <h4 style="font-size:13.5px; font-weight:600; color:#ffffff; margin:0 0 10px; display:flex; align-items:center; gap:8px;">
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?php echo ! empty( $client->is_verified ) ? '#10b981' : '#f59e0b'; ?>;"></span>
                        KYC / KYB Verification
                    </h4>

                    <?php if ( ! empty( $client->is_verified ) ) : ?>
                        <div style="background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.25); border-radius:12px; padding:16px; position:relative; overflow:hidden;">
                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <div style="width:34px; height:34px; border-radius:50%; background:#10b981; color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 2px 10px rgba(16,185,129,0.4);">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    </div>
                                    <div>
                                        <h5 style="margin:0; font-size:13.5px; font-weight:700; color:#ffffff;">Business &amp; Identity Verified</h5>
                                        <span style="font-size:11.5px; color:#10b981; font-weight:600;">CAC Government Registry Validated</span>
                                    </div>
                                </div>
                                <button type="button" onclick="openEditModal('tab-verification')" style="background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.3); color:#10b981; font-size:11.5px; padding:4px 10px; border-radius:6px; cursor:pointer; font-weight:600;">
                                    View Certificate
                                </button>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; background:rgba(0,0,0,0.25); border-radius:8px; padding:12px; border:1px solid rgba(255,255,255,0.06); font-size:12px;">
                                <div>
                                    <span style="color:var(--prof-text-sub); display:block; font-size:10px; text-transform:uppercase; letter-spacing:0.5px; font-weight:600;">Registered Entity</span>
                                    <strong style="color:#ffffff; font-size:12.5px; word-break:break-word;"><?php echo esc_html( $client->verified_company_name ?: $client->business_name ); ?></strong>
                                </div>
                                <div>
                                    <span style="color:var(--prof-text-sub); display:block; font-size:10px; text-transform:uppercase; letter-spacing:0.5px; font-weight:600;">CAC Reg. No</span>
                                    <strong style="color:var(--prof-teal); font-family:monospace; font-size:12.5px;"><?php echo esc_html( $client->cac_number ?: 'Verified' ); ?></strong>
                                </div>
                                <div>
                                    <span style="color:var(--prof-text-sub); display:block; font-size:10px; text-transform:uppercase; letter-spacing:0.5px; font-weight:600;">Identity Check</span>
                                    <span style="color:#10b981; font-weight:600; font-size:12px;">✓ <?php echo esc_html( $client->id_type_verified ?: 'NIN/BVN' ); ?> Matched</span>
                                </div>
                                <div>
                                    <span style="color:var(--prof-text-sub); display:block; font-size:10px; text-transform:uppercase; letter-spacing:0.5px; font-weight:600;">Verified Since</span>
                                    <strong style="color:#ffffff; font-size:12px;"><?php echo esc_html( ! empty( $client->verified_at ) ? date( 'M d, Y', strtotime( $client->verified_at ) ) : 'Active' ); ?></strong>
                                </div>
                            </div>
                        </div>
                    <?php else : ?>
                        <div style="background:rgba(255,255,255,0.02); border:1px dashed rgba(255,255,255,0.1); border-radius:12px; padding:14px 16px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div style="width:34px; height:34px; border-radius:8px; background:rgba(234,179,8,0.1); color:#eab308; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                </div>
                                <div>
                                    <div style="color:#ffffff; font-size:13px; font-weight:600;">Business Unverified</div>
                                    <div style="color:var(--prof-text-sub); font-size:11.5px;">Verify CAC &amp; NIN/BVN to unlock listing publishing and Verified Partner status.</div>
                                </div>
                            </div>
                            <button type="button" onclick="openEditModal('tab-verification')" style="background:rgba(59,130,246,0.15); border:1px solid rgba(59,130,246,0.3); color:#60a5fa; font-size:12px; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:600; white-space:nowrap;">
                                Verify Business →
                            </button>
                        </div>
                    <?php endif; ?>
                </div> <!-- /KYC block -->
        </div> <!-- /.ofp-bank-card-section -->
    </div> <!-- /.ofp-branding-bank-split-grid -->

</div> <!-- /.ofp-profile-page-wrap -->

<!-- ── Hidden form for Instant Logo/Avatar upload ────────────────────────── -->
<form method="POST" action="" enctype="multipart/form-data" id="ofp-logo-form" style="display:none;">
    <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
    <input type="hidden" name="upload_logo" value="1">
    <input type="file" name="logo" id="ofp-logo-input" accept="image/jpeg,image/png,image/gif,image/webp">
</form>

<!-- ── Hidden form for Instant Cover Banner upload ───────────────────────── -->
<form method="POST" action="" enctype="multipart/form-data" id="ofp-cover-form" style="display:none;">
    <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
    <input type="hidden" name="upload_cover" value="1">
    <input type="file" name="cover_image" id="ofp-cover-input" accept="image/jpeg,image/png,image/webp">
</form>
<form method="POST" action="" id="ofp-reset-cover-form" style="display:none;">
    <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
    <input type="hidden" name="remove_cover" value="1">
</form>

<!-- ── Hidden form for Instant Stamp upload ───────────────────────────────── -->
<form method="POST" action="" enctype="multipart/form-data" id="ofp-stamp-form" style="display:none;">
    <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
    <input type="hidden" name="upload_stamp" value="1">
    <input type="file" name="stamp_image" id="ofp-stamp-input" accept="image/png,image/jpeg,image/webp">
</form>
<form method="POST" action="" id="ofp-reset-stamp-form" style="display:none;">
    <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
    <input type="hidden" name="remove_stamp" value="1">
</form>

<!-- ── Interactive Edit Profile Modal Drawer ─────────────────────────────── -->
<div class="ofp-modal-backdrop" id="ofp-edit-modal-backdrop" aria-hidden="true">
    <div class="ofp-modal-window" style="max-width: 620px;" role="dialog" aria-modal="true" aria-labelledby="ofp-modal-title">
        
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
                    <button type="button" class="ofp-modal-tab-btn" data-tab="tab-verification">Verification (KYC/KYB)</button>
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
                            <label>Email Address</label>
                            <input type="email" value="<?php echo esc_attr( $client->email ); ?>" readonly style="opacity:0.75;" title="Contact support to change your registered email address.">
                        </div>
                        <div class="ofp-field-group">
                            <label>Mobile / Phone Number</label>
                            <input type="text" name="phone" value="<?php echo esc_attr( $client->phone ); ?>" required placeholder="+234...">
                        </div>
                    </div>

                    <div class="ofp-field-group full-width" style="margin-bottom:14px;">
                        <label>Branded Portal Address</label>
                        <input type="text" value="<?php echo esc_attr( $portal_url ?: 'No custom subdomain assigned' ); ?>" readonly style="opacity:0.75;">
                        <span class="ofp-field-hint">
                            <?php if ( $portal_url ) : ?>
                                Your agency's direct portal address. (Contact support if you need to change your subdomain).
                            <?php else : ?>
                                Contact support to configure a custom branded subdomain for your agency.
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="ofp-form-row-2">
                        <div class="ofp-field-group">
                            <label>Public Profile URL Slug</label>
                            <input type="text" name="profile_slug" value="<?php echo esc_attr( $client->profile_slug ?? 'agent-' . $client->id ); ?>" placeholder="e.g. your-agency" required>
                            <span class="ofp-field-hint">Public link: <?php echo esc_html( home_url( '/agent/' ) ); ?><strong>slug</strong></span>
                        </div>
                        <div class="ofp-field-group">
                            <label>Facebook / Meta Pixel ID</label>
                            <input type="text" name="meta_pixel_id" value="<?php echo esc_attr( $client->meta_pixel_id ?? '' ); ?>" placeholder="e.g. 1234567890">
                            <span class="ofp-field-hint">Track visitors and conversions on your public pages</span>
                        </div>
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

                    <!-- Public Agent Profile Cover Banner (/agent/) -->
                    <div class="ofp-field-group full-width" style="margin-bottom:20px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                            <label style="margin:0; font-weight:600; font-size:13.5px; color:var(--prof-text-title);">Public Agent Profile Cover Banner</label>
                            <?php if ( ! empty( $client->profile_slug ) ) : ?>
                                <a href="<?php echo esc_url( home_url( '/agent/' . $client->profile_slug ) ); ?>" target="_blank" style="font-size:12px; color:var(--prof-teal); text-decoration:none; display:inline-flex; align-items:center; gap:4px; font-weight:500;">
                                    Preview /agent profile
                                    <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                            <?php endif; ?>
                        </div>
                        <span class="ofp-field-hint" style="margin-bottom:12px; display:block;">
                            This image appears as the showcase hero banner at the top of your public <strong>/agent/</strong> profile page.
                        </span>

                        <div style="background:rgba(255,255,255,0.03); border:1px solid var(--prof-border); border-radius:14px; padding:16px; display:flex; flex-direction:column; gap:14px;">
                            <!-- Banner Preview Box -->
                            <div style="position:relative; width:100%; height:130px; border-radius:10px; overflow:hidden; border:1px solid rgba(255,255,255,0.1); background-image:url('<?php echo esc_url( $cover_url ); ?>'); background-size:cover; background-position:center; display:flex; align-items:flex-end; justify-content:space-between; padding:12px 14px; box-shadow:inset 0 -50px 30px rgba(0,0,0,0.5);">
                                <span style="background:rgba(16,19,26,0.75); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); color:#fff; font-size:11px; font-weight:600; padding:4px 10px; border-radius:12px; border:1px solid rgba(255,255,255,0.15);">
                                    <?php echo $has_custom_cover ? '✓ Custom Cover Active' : 'Default Platform Banner'; ?>
                                </span>
                                <span style="color:rgba(255,255,255,0.8); font-size:11px; text-shadow:0 1px 2px rgba(0,0,0,0.8);">
                                    1920 × 480px recommended
                                </span>
                            </div>

                            <!-- Buttons Bar -->
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <button type="button" class="ofp-btn ofp-btn-sm" onclick="document.getElementById('ofp-cover-input').click();" style="background:var(--prof-teal); color:#fff; border-radius:8px; padding:8px 16px; font-size:12.5px; font-weight:600; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                                        <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        <span><?php echo $has_custom_cover ? 'Change Cover Image' : 'Upload Cover Image'; ?></span>
                                    </button>

                                    <?php if ( $has_custom_cover ) : ?>
                                        <button type="button" class="ofp-btn ofp-btn-sm" onclick="if(confirm('Reset your public profile cover back to default?')){ document.getElementById('ofp-reset-cover-form').submit(); }" style="background:rgba(239,68,68,0.12); color:#f87171; border:1px solid rgba(239,68,68,0.25); border-radius:8px; padding:7px 14px; font-size:12px; font-weight:600; cursor:pointer;">
                                            Reset to Default
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <span style="font-size:11.5px; color:var(--prof-text-sub);">Supports JPG, PNG or WebP up to 3MB</span>
                            </div>
                        </div>
                    </div>

                    <!-- Official Verification Stamp / Seal (Receipts & Invoices) -->
                    <div class="ofp-field-group full-width" style="margin-bottom:20px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                            <label style="margin:0; font-weight:600; font-size:13.5px; color:var(--prof-text-title);">Official Receipt Verification Stamp / Seal</label>
                            <span style="font-size:12px; color:var(--prof-teal); display:inline-flex; align-items:center; gap:4px; font-weight:500;">
                                Appears on all payment receipts
                            </span>
                        </div>
                        <span class="ofp-field-hint" style="margin-bottom:12px; display:block;">
                            Upload your agency's official stamp or verification seal (transparent PNG recommended). If left empty, the platform default verified seal will be used automatically.
                        </span>

                        <div style="background:rgba(255,255,255,0.03); border:1px solid var(--prof-border); border-radius:14px; padding:16px; display:flex; flex-direction:column; gap:14px;">
                            <!-- Stamp Preview Box -->
                            <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
                                <div style="width:110px; height:110px; border-radius:12px; border:1px dashed var(--prof-border); background:rgba(255,255,255,0.02); display:flex; align-items:center; justify-content:center; padding:8px; overflow:hidden; flex-shrink:0;">
                                    <img src="<?php echo esc_url( $stamp_url ); ?>" alt="Receipt Stamp" style="max-width:100%; max-height:100%; object-fit:contain; transform:rotate(-8deg);">
                                </div>
                                <div style="flex:1; min-width:220px;">
                                    <div style="margin-bottom:8px;">
                                        <span style="background:<?php echo $has_custom_stamp ? 'rgba(16,185,129,0.15)' : 'rgba(59,130,246,0.15)'; ?>; color:<?php echo $has_custom_stamp ? '#10b981' : '#60a5fa'; ?>; font-size:11px; font-weight:600; padding:3px 10px; border-radius:12px; border:1px solid <?php echo $has_custom_stamp ? 'rgba(16,185,129,0.3)' : 'rgba(59,130,246,0.3)'; ?>;">
                                            <?php echo $has_custom_stamp ? '✓ Custom Stamp Active' : 'Platform Default Seal Active (Fallback)'; ?>
                                        </span>
                                    </div>
                                    <p style="margin:0 0 10px; font-size:12.5px; color:var(--prof-text-sub); line-height:1.5;">
                                        <?php echo $has_custom_stamp ? 'Your custom seal is stamped directly onto all client payment receipts and vouchers.' : 'You have not uploaded a custom stamp yet. The platform verified seal will be used as a fallback.'; ?>
                                    </p>
                                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                        <button type="button" class="ofp-btn ofp-btn-sm" onclick="document.getElementById('ofp-stamp-input').click();" style="background:var(--prof-teal); color:#fff; border-radius:8px; padding:7px 14px; font-size:12px; font-weight:600; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                                            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                            <span><?php echo $has_custom_stamp ? 'Change Stamp Image' : 'Upload Custom Stamp'; ?></span>
                                        </button>
                                        <?php if ( $has_custom_stamp ) : ?>
                                            <button type="button" class="ofp-btn ofp-btn-sm" onclick="if(confirm('Reset back to platform default verified seal?')){ document.getElementById('ofp-reset-stamp-form').submit(); }" style="background:rgba(239,68,68,0.12); color:#f87171; border:1px solid rgba(239,68,68,0.25); border-radius:8px; padding:6px 12px; font-size:11.5px; font-weight:600; cursor:pointer;">
                                                Reset to Default
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ofp-field-group full-width">
                        <label>Biographical Info / About Agency</label>
                        <textarea name="bio" rows="4" placeholder="Tell visitors and clients about your real estate agency or company..."><?php echo esc_textarea( $client->bio ?? '' ); ?></textarea>
                    </div>

                    <?php if ( OFP_Watermark::is_enabled() ) : ?>
                    <div class="ofp-field-group full-width" style="margin-top:6px;">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                            <label style="margin:0;">Custom Watermark Name</label>
                            <span style="background:rgba(14,165,233,0.12); color:#0ea5e9; font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:6px; letter-spacing:0.04em; text-transform:uppercase;">Optional</span>
                        </div>
                        <input type="text" name="watermark_text" value="<?php echo esc_attr( $client->watermark_text ?? '' ); ?>" placeholder="e.g. <?php echo esc_attr( $client->business_name ); ?>" maxlength="100">
                        <span class="ofp-field-hint">
                            This text will appear as a watermark on all your uploaded property photos.
                            Leave blank to use the platform default (<code><?php echo esc_html( OFP_Watermark::get_watermark_text() ); ?></code>).
                        </span>
                    </div>
                    <?php endif; ?>

                    <!-- Preserve social links in same submit -->
                    <input type="hidden" name="youtube_url" value="<?php echo esc_attr( $youtube_url ); ?>">
                    <input type="hidden" name="instagram_url" value="<?php echo esc_attr( $instagram_url ); ?>">
                    <input type="hidden" name="tiktok_url" value="<?php echo esc_attr( $tiktok_url ); ?>">
                    <input type="hidden" name="whatsapp_url" value="<?php echo esc_attr( $whatsapp_url ); ?>">
                    <input type="hidden" name="watermark_text" value="<?php echo esc_attr( $client->watermark_text ?? '' ); ?>">

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
                    <input type="hidden" name="owner_name" value="<?php echo esc_attr( $client->owner_name ); ?>">
                    <input type="hidden" name="phone" value="<?php echo esc_attr( $client->phone ); ?>">
                    <input type="hidden" name="support_email" value="<?php echo esc_attr( $client->support_email ?? '' ); ?>">
                    <input type="hidden" name="support_phone" value="<?php echo esc_attr( $client->support_phone ?? '' ); ?>">
                    <input type="hidden" name="bio" value="<?php echo esc_attr( $client->bio ?? '' ); ?>">
                    <input type="hidden" name="watermark_text" value="<?php echo esc_attr( $client->watermark_text ?? '' ); ?>">

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

                        <h4 style="font-size:14px; color:#ffffff; margin:20px 0 14px;">Add New Bank Account</h4>

                        <div class="ofp-form-row-2">
                            <div class="ofp-field-group">
                                <label>Bank Name</label>
                                <select name="bank_code" id="ofp-bank-code-select" required style="width:100%; padding:10px 14px; border-radius:10px; border:1px solid var(--prof-border); background:#1e293b; color:#fff; font-size:14px;">
                                    <option value="">Select your bank...</option>
                                    <?php foreach ( OFP_Bank_Account::get_banks() as $bank_item ) : ?>
                                        <option value="<?php echo esc_attr( $bank_item['code'] ); ?>" data-name="<?php echo esc_attr( $bank_item['name'] ); ?>">
                                            <?php echo esc_html( $bank_item['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="bank_name" id="ofp-bank-name-hidden" value="">
                            </div>
                            <div class="ofp-field-group">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <label>Account Number (10 digits)</label>
                                    <span id="ofp-bank-resolving-spin" style="display:none; font-size:11px; color:#38bdf8; font-weight:600;">
                                        Verifying...
                                    </span>
                                </div>
                                <input type="text" name="bank_account_number" id="ofp-bank-account-num" inputmode="numeric" maxlength="10" placeholder="0123456789" required autocomplete="off">
                            </div>
                        </div>

                        <div class="ofp-form-row-2">
                            <div class="ofp-field-group">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <label>Account Name</label>
                                    <span id="ofp-bank-verified-badge" style="display:none; font-size:11px; background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3); padding:2px 8px; border-radius:12px; font-weight:700;">
                                        ✓ Verified Account
                                    </span>
                                </div>
                                <input type="text" name="bank_account_name" id="ofp-bank-account-name" placeholder="Auto-fetches upon typing account number" required>
                                <p id="ofp-bank-resolve-err" style="display:none; color:#f87171; font-size:12px; margin:6px 0 0; line-height:1.4;"></p>
                            </div>
                            <div class="ofp-field-group">
                                <label>Confirm with Password</label>
                                <input type="password" name="bank_password" autocomplete="current-password" placeholder="Enter password to save" required>
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

                        <!-- Tab: Verification (KYC / KYB) -->
            <div class="ofp-tab-panel" id="tab-verification">
                <?php if ( ! empty( $client->is_verified ) ) : ?>
                    <!-- Verified State -->
                    <div style="background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.25); border-radius:16px; padding:24px; text-align:center; margin-bottom:20px;">
                        <div style="width:56px; height:56px; border-radius:50%; background:#10b981; color:#fff; display:inline-flex; align-items:center; justify-content:center; margin-bottom:12px; box-shadow:0 4px 14px rgba(16,185,129,0.4);">
                            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <h3 style="color:#ffffff; font-size:18px; font-weight:700; margin:0 0 4px;">Business &amp; Identity Verified</h3>
                        <p style="color:var(--prof-text-sub); font-size:13px; margin:0 0 16px;">Your business credentials have been officially validated against government registries.</p>
                        
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; text-align:left; background:rgba(0,0,0,0.2); padding:16px; border-radius:12px; border:1px solid rgba(255,255,255,0.06);">
                            <div>
                                <span style="font-size:11px; color:var(--prof-text-sub); display:block; text-transform:uppercase; letter-spacing:0.5px;">Registered Company</span>
                                <strong style="font-size:14px; color:#ffffff;"><?php echo esc_html( $client->verified_company_name ?: $client->business_name ); ?></strong>
                            </div>
                            <div>
                                <span style="font-size:11px; color:var(--prof-text-sub); display:block; text-transform:uppercase; letter-spacing:0.5px;">CAC Registration No</span>
                                <strong style="font-size:14px; color:#ffffff; font-family:monospace;"><?php echo esc_html( $client->cac_number ?: 'Verified' ); ?></strong>
                            </div>
                            <div>
                                <span style="font-size:11px; color:var(--prof-text-sub); display:block; text-transform:uppercase; letter-spacing:0.5px;">Identity Check</span>
                                <strong style="font-size:14px; color:#10b981;">✓ <?php echo esc_html( $client->id_type_verified ?: 'NIN/BVN' ); ?> Matched</strong>
                            </div>
                            <div>
                                <span style="font-size:11px; color:var(--prof-text-sub); display:block; text-transform:uppercase; letter-spacing:0.5px;">Verified Since</span>
                                <strong style="font-size:14px; color:#ffffff;"><?php echo esc_html( ! empty( $client->verified_at ) ? date( 'M d, Y', strtotime( $client->verified_at ) ) : 'Active' ); ?></strong>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                    <!-- Unverified State Form -->
                    <div style="background:rgba(59,130,246,0.06); border:1px solid rgba(59,130,246,0.2); border-radius:12px; padding:16px; margin-bottom:20px; display:flex; align-items:flex-start; gap:12px;">
                        <svg width="22" height="22" fill="none" stroke="#38bdf8" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0; margin-top:2px;"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <div>
                            <strong style="color:#ffffff; font-size:14px; display:block; margin-bottom:2px;">Official Business Verification (CAC + NIN or BVN)</strong>
                            <span style="color:var(--prof-text-sub); font-size:13px; line-height:1.4;">Submit your CAC business registration number and either your National Identity Number (NIN) or Bank Verification Number (BVN) to unlock Verified Partner status and property listings. Verification cost is covered by your subscription plan.</span>
                        </div>
                    </div>

                    <form id="ofp-kyc-verify-form" onsubmit="return false;">
                        <div class="ofp-form-row-2">
                            <div class="ofp-field-group">
                                <label>CAC Registration Number (RC / BN Number)</label>
                                <input type="text" name="cac_number" id="ofp-kyc-cac" placeholder="e.g. RC-123456 or BN-987654" required>
                                <span class="ofp-field-hint">Enter your Corporate Affairs Commission number</span>
                            </div>
                            <div class="ofp-field-group">
                                <label>Identity Verification Method</label>
                                <div style="display:flex; gap:16px; margin-top:8px;">
                                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:#ffffff; cursor:pointer; font-weight:normal;">
                                        <input type="radio" name="id_type" value="nin" checked onchange="document.getElementById('ofp-kyc-id-label').textContent='11-Digit National Identity Number (NIN)'; document.getElementById('ofp-kyc-id-num').placeholder='11-digit NIN';">
                                        National Identity (NIN)
                                    </label>
                                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:#ffffff; cursor:pointer; font-weight:normal;">
                                        <input type="radio" name="id_type" value="bvn" onchange="document.getElementById('ofp-kyc-id-label').textContent='11-Digit Bank Verification Number (BVN)'; document.getElementById('ofp-kyc-id-num').placeholder='11-digit BVN';">
                                        Bank Verification (BVN)
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="ofp-form-row-2">
                            <div class="ofp-field-group">
                                <label id="ofp-kyc-id-label">11-Digit National Identity Number (NIN)</label>
                                <input type="text" name="id_number" id="ofp-kyc-id-num" maxlength="11" inputmode="numeric" placeholder="11-digit NIN" required>
                                <span class="ofp-field-hint">Validated securely via official registry. Never shared publicly.</span>
                            </div>
                            <div class="ofp-field-group" style="display:flex; flex-direction:column; justify-content:center;">
                                <div id="ofp-kyc-status-msg" style="display:none; font-size:13px; padding:10px 14px; border-radius:8px;"></div>
                            </div>
                        </div>

                        <div class="ofp-modal-footer">
                            <button type="button" class="ofp-modal-btn-cancel" onclick="closeEditModal()">Close</button>
                            <button type="button" id="ofp-btn-submit-kyc" class="ofp-modal-btn-save" style="background:#10b981;">
                                <span id="ofp-kyc-btn-text">Verify My Business</span>
                                <span id="ofp-kyc-btn-spin" style="display:none;">Verifying with Prembly...</span>
                            </button>
                        </div>
                    </form>
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

    // Cover banner upload auto-submit
    const coverInput = document.getElementById('ofp-cover-input');
    const coverForm  = document.getElementById('ofp-cover-form');
    if (coverInput && coverForm) {
        coverInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            if (file.size > 3 * 1024 * 1024) {
                alert('File is too large! Please select an image under 3MB.');
                e.target.value = '';
                return;
            }
            coverForm.submit();
        });
    }

    // Stamp upload auto-submit
    const stampInput = document.getElementById('ofp-stamp-input');
    const stampForm  = document.getElementById('ofp-stamp-form');
    if (stampInput && stampForm) {
        stampInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) {
                alert('File is too large! Please select an image under 2MB.');
                e.target.value = '';
                return;
            }
            stampForm.submit();
        });
    }

    // Modal controls
    const backdrop   = document.getElementById('ofp-edit-modal-backdrop');
    const openBtn    = document.getElementById('ofp-open-edit-modal');
    const closeBtn   = document.getElementById('ofp-close-modal-btn');
    const tabButtons = document.querySelectorAll('.ofp-modal-tab-btn');
    const tabPanels  = document.querySelectorAll('.ofp-tab-panel');

    window.openEditModal = function(tabId) {
        if (backdrop) {
            backdrop.classList.add('active');
            document.body.style.overflow = 'hidden';
            if (tabId) {
                const targetBtn = document.querySelector('.ofp-modal-tab-btn[data-tab="' + tabId + '"]');
                if (targetBtn) {
                    targetBtn.click();
                }
            }
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
        // If bank action/error, open bank tab in modal
        <?php if ( isset($_POST['bank_do']) ) : ?>
            if (typeof openEditModal === 'function') openEditModal('tab-banking');
        <?php elseif ( isset($_POST['change_password']) ) : ?>
            if (typeof openEditModal === 'function') openEditModal('tab-security');
        <?php elseif ( isset($_POST['update_branding']) ) : ?>
            if (typeof openEditModal === 'function') openEditModal('tab-branding');
        <?php endif; ?>
    <?php endif; ?>

    // Check URL hash to auto-open specific tab (e.g. #tab-verification, #tab-banking)
    if (window.location.hash) {
        var hashTab = window.location.hash.replace('#', '');
        if (hashTab && document.getElementById(hashTab)) {
            if (typeof openEditModal === 'function') {
                openEditModal(hashTab);
            }
        }
    }

    // ── Live Bank Account Resolution & Modal Card Preview Sync ────────────────
    (function() {
        const bankSelect   = document.getElementById('ofp-bank-code-select');
        const bankHidden   = document.getElementById('ofp-bank-name-hidden');
        const accNumInput  = document.getElementById('ofp-bank-account-num');
        const accNameInput = document.getElementById('ofp-bank-account-name');
        const spinBadge    = document.getElementById('ofp-bank-resolving-spin');
        const verifyBadge  = document.getElementById('ofp-bank-verified-badge');
        const errText      = document.getElementById('ofp-bank-resolve-err');

        if (!bankSelect || !accNumInput) return;

        function syncBankName() {
            const selectedOpt = bankSelect.options[bankSelect.selectedIndex];
            if (selectedOpt && selectedOpt.value) {
                bankHidden.value = selectedOpt.getAttribute('data-name') || selectedOpt.text;
            } else {
                bankHidden.value = '';
            }
        }

        bankSelect.addEventListener('change', function() {
            syncBankName();
            if (accNumInput.value.trim().length === 10) {
                triggerResolution();
            }
        });

        let debounceTimer = null;
        accNumInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '');
            if (this.value.length === 10) {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(triggerResolution, 250);
            } else {
                if (verifyBadge) verifyBadge.style.display = 'none';
                if (errText) errText.style.display = 'none';
            }
        });

        function triggerResolution() {
            const bankCode = bankSelect.value;
            const accNum   = accNumInput.value.trim();

            if (!bankCode) {
                if (errText) {
                    errText.textContent = 'Please select a bank first.';
                    errText.style.display = 'block';
                }
                if (verifyBadge) verifyBadge.style.display = 'none';
                return;
            }

            if (accNum.length !== 10) return;

            syncBankName();
            if (spinBadge) spinBadge.style.display = 'inline-block';
            if (errText) errText.style.display = 'none';
            if (verifyBadge) verifyBadge.style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'ofp_resolve_bank_account');
            formData.append('nonce', '<?php echo esc_js( wp_create_nonce( 'ofp_bank_lookup' ) ); ?>');
            formData.append('account_number', accNum);
            formData.append('bank_code', bankCode);

            fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (spinBadge) spinBadge.style.display = 'none';
                if (data && data.success && data.data && data.data.account_name) {
                    accNameInput.value = data.data.account_name;
                    if (verifyBadge) verifyBadge.style.display = 'inline-block';
                    if (errText) errText.style.display = 'none';
                } else {
                    const msg = (data && data.data && data.data.message) ? data.data.message : 'Could not resolve account name for selected bank.';
                    if (errText) {
                        errText.textContent = msg;
                        errText.style.display = 'block';
                    }
                    if (verifyBadge) verifyBadge.style.display = 'none';
                }
            })
            .catch(err => {
                if (spinBadge) spinBadge.style.display = 'none';
                console.error('Bank resolve error:', err);
            });
        }
    })();


    // ── Prembly Business & Identity Verification ──────────────────────────────
    (function() {
        const btnSubmit = document.getElementById('ofp-btn-submit-kyc');
        if (!btnSubmit) return;

        btnSubmit.addEventListener('click', function() {
            const cacInput  = document.getElementById('ofp-kyc-cac');
            const idInput   = document.getElementById('ofp-kyc-id-num');
            const typeRadio = document.querySelector('input[name="id_type"]:checked');
            const msgBox    = document.getElementById('ofp-kyc-status-msg');
            const btnText   = document.getElementById('ofp-kyc-btn-text');
            const btnSpin   = document.getElementById('ofp-kyc-btn-spin');

            const cac = cacInput ? cacInput.value.trim() : '';
            const idVal = idInput ? idInput.value.trim() : '';
            const idType = typeRadio ? typeRadio.value : 'nin';

            if (!cac) {
                showMsg('Please enter your CAC registration number.', false);
                return;
            }
            if (!idVal || idVal.length !== 11) {
                showMsg('Please enter a valid 11-digit ' + idType.toUpperCase() + '.', false);
                return;
            }

            btnSubmit.disabled = true;
            btnText.style.display = 'none';
            btnSpin.style.display = 'inline';
            msgBox.style.display = 'none';

            const formData = new FormData();
            formData.append('action', 'ofp_submit_client_verification');
            formData.append('cac_number', cac);
            formData.append('id_type', idType);
            formData.append('id_number', idVal);

            fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                btnSubmit.disabled = false;
                btnText.style.display = 'inline';
                btnSpin.style.display = 'none';

                if (data && data.success) {
                    showMsg('✓ ' + (data.data.message || 'Verification successful! Reloading...'), true);
                    setTimeout(() => {
                        window.location.hash = 'tab-verification';
                        window.location.reload();
                    }, 1100);
                } else {
                    const err = (data && data.data && data.data.message) ? data.data.message : 'Verification check failed. Please check your details.';
                    showMsg(err, false);
                }
            })
            .catch(err => {
                btnSubmit.disabled = false;
                btnText.style.display = 'inline';
                btnSpin.style.display = 'none';
                showMsg('Network error connecting to verification service.', false);
            });

            function showMsg(text, isSuccess) {
                msgBox.textContent = text;
                msgBox.style.display = 'block';
                if (isSuccess) {
                    msgBox.style.background = 'rgba(16,185,129,0.15)';
                    msgBox.style.color = '#10b981';
                    msgBox.style.border = '1px solid rgba(16,185,129,0.3)';
                } else {
                    msgBox.style.background = 'rgba(239,68,68,0.15)';
                    msgBox.style.color = '#f87171';
                    msgBox.style.border = '1px solid rgba(239,68,68,0.3)';
                }
            }
        });
    })();

})();
</script>

</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
