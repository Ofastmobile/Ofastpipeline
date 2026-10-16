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
        $slug          = sanitize_title( $_POST['profile_slug'] ?? '' );
        $bio           = sanitize_textarea_field( $_POST['bio'] ?? '' );
        $pixel         = sanitize_text_field( $_POST['meta_pixel_id'] ?? '' );
        $owner_name    = sanitize_text_field( $_POST['owner_name'] ?? '' );
        $phone         = sanitize_text_field( $_POST['phone'] ?? '' );
        $support_email = sanitize_email( $_POST['support_email'] ?? '' );
        $support_phone = sanitize_text_field( $_POST['support_phone'] ?? '' );

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

    <!-- ── Bottom Card: Branding & Bank Details ───────────────────────────── -->
    <div class="ofp-profile-card ofp-branding-card">
        <div class="ofp-productions-header-row" style="margin-bottom: 22px; border-bottom: 1px solid var(--prof-border); padding-bottom: 16px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:38px; height:38px; border-radius:10px; background:rgba(0,188,181,0.12); display:flex; align-items:center; justify-content:center; color:var(--prof-teal); flex-shrink:0;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" /></svg>
                </div>
                <div>
                    <h3 class="ofp-productions-title" style="margin:0;">Branding & Bank Details</h3>
                    <p style="margin:2px 0 0; font-size:12.5px; color:var(--prof-text-sub);">Custom white-label portal identity and client payout bank accounts</p>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <button type="button" class="ofp-productions-view-all" onclick="openEditModal('tab-branding')" style="background:rgba(255,255,255,0.04); border:1px solid var(--prof-border); border-radius:8px; padding:6px 14px; cursor:pointer;">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                    <span>Customize Branding</span>
                </button>
                <button type="button" class="ofp-productions-view-all" onclick="openEditModal('tab-banking')" style="background:rgba(255,255,255,0.04); border:1px solid var(--prof-border); border-radius:8px; padding:6px 14px; cursor:pointer;">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                    <span>Manage Bank Accounts</span>
                </button>
            </div>
        </div>

        <!-- 2-Column Split: Left = Branding, Right = Bank Accounts -->
        <div class="ofp-branding-bank-content-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 26px;">
            
            <!-- Column 1: Branding Identity -->
            <div class="ofp-branding-col">
                <h4 style="font-size:13.5px; font-weight:600; color:#ffffff; margin:0 0 14px; display:flex; align-items:center; gap:8px;">
                    <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--prof-teal);"></span>
                    Branding & White-Label Portal
                </h4>

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
                </div>
            </div>

            <!-- Column 2: Bank Accounts -->
            <div class="ofp-bank-col">
                <h4 style="font-size:13.5px; font-weight:600; color:#ffffff; margin:0 0 14px; display:flex; align-items:center; gap:8px;">
                    <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#22c55e;"></span>
                    Client Bank & Payout Accounts
                </h4>

                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php if ( ! empty( $client_banks ) ) : foreach ( $client_banks as $bank ) : ?>
                        <div style="background:var(--prof-card-inner, #131722); border:1px solid var(--prof-border); border-radius:12px; padding:14px 16px; display:flex; justify-content:space-between; align-items:center; gap:12px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div style="width:38px; height:38px; border-radius:10px; background:rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.2); display:flex; align-items:center; justify-content:center; color:#22c55e; flex-shrink:0;">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                                </div>
                                <div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <strong style="color:#ffffff; font-size:14px;"><?php echo esc_html( $bank->bank_name ); ?></strong>
                                        <?php if ( (int) $bank->is_default === 1 ) : ?>
                                            <span style="font-size:10px; background:rgba(34,197,94,0.15); color:#22c55e; padding:2px 8px; border-radius:10px; font-weight:600;">Primary</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-family:monospace; font-size:14px; font-weight:600; color:var(--prof-teal); margin-top:2px;">
                                        <?php echo esc_html( $bank->account_number ); ?>
                                    </div>
                                    <div style="font-size:11.5px; color:var(--prof-text-sub); margin-top:2px;">
                                        <?php echo esc_html( $bank->account_name ); ?>
                                    </div>
                                </div>
                            </div>

                            <button type="button" onclick="openEditModal('tab-banking')" style="background:none; border:none; color:var(--prof-text-sub); font-size:12px; cursor:pointer; text-decoration:underline;">
                                Details
                            </button>
                        </div>
                    <?php endforeach; else : ?>
                        <div style="background:var(--prof-card-inner, #131722); border:1px dashed var(--prof-border); border-radius:12px; padding:26px 20px; text-align:center;">
                            <div style="width:40px; height:40px; border-radius:50%; background:rgba(255,255,255,0.04); display:inline-flex; align-items:center; justify-content:center; color:var(--prof-text-sub); margin-bottom:10px;">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                            </div>
                            <div style="font-size:13.5px; color:#ffffff; font-weight:500;">No bank account configured yet</div>
                            <p style="font-size:12px; color:var(--prof-text-sub); margin:4px 0 14px;">Add your payout account so buyers and tenants can send payments directly.</p>
                            <button type="button" onclick="openEditModal('tab-banking')" class="ofp-btn-edit-profile" style="padding:6px 16px; font-size:12px; display:inline-flex; align-items:center; gap:6px;">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                                <span>Add Bank Account</span>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

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

                    <div class="ofp-field-group full-width">
                        <label>Biographical Info / About Agency</label>
                        <textarea name="bio" rows="4" placeholder="Tell visitors and clients about your real estate agency or company..."><?php echo esc_textarea( $client->bio ?? '' ); ?></textarea>
                    </div>

                    <!-- Preserve social links in same submit -->
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
                    <input type="hidden" name="owner_name" value="<?php echo esc_attr( $client->owner_name ); ?>">
                    <input type="hidden" name="phone" value="<?php echo esc_attr( $client->phone ); ?>">
                    <input type="hidden" name="support_email" value="<?php echo esc_attr( $client->support_email ?? '' ); ?>">
                    <input type="hidden" name="support_phone" value="<?php echo esc_attr( $client->support_phone ?? '' ); ?>">
                    <input type="hidden" name="bio" value="<?php echo esc_attr( $client->bio ?? '' ); ?>">

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
