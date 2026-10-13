<?php
/**
 * Template: /account
 * Client profile and password management.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();

$success = '';
$error   = '';

global $wpdb;
$wpdb->query( "ALTER TABLE {$wpdb->prefix}ofp_clients ADD COLUMN IF NOT EXISTS logo_url VARCHAR(255) DEFAULT NULL AFTER business_category" );
// MySQL 8+ supports IF NOT EXISTS on ADD COLUMN. If MariaDB/older MySQL, it will just fail silently which is fine.

// Ensure all Phase 23 columns exist so users don't have to deactivate/reactivate
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
        $slug  = sanitize_title( $_POST['profile_slug'] ?? '' );
        $bio   = sanitize_textarea_field( $_POST['bio'] ?? '' );
        $pixel = sanitize_text_field( $_POST['meta_pixel_id'] ?? '' );

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
            $wpdb->update(
                $wpdb->prefix . 'ofp_clients',
                [
                    'profile_slug'  => $slug,
                    'bio'           => $bio,
                    'meta_pixel_id' => $pixel,
                ],
                [ 'id' => $client->id ]
            );
            $success = 'Profile & Tracking updated successfully.';
            
            // Update client object in memory so UI updates immediately on this page load
            $client->profile_slug = $slug;
            $client->bio = $bio;
            $client->meta_pixel_id = $pixel;
        }
    } elseif ( isset( $_POST['bank_do'] ) ) {
        // Bank accounts shown to buyers and tenants. Every change needs the password.
        $bank_pw   = (string) wp_unslash( $_POST['bank_password'] ?? '' );
        $bank_auth = OFP_Auth::check_credentials( $client->email, $bank_pw );
        $bank_cid  = is_array( $bank_auth ) ? (int) ( $bank_auth['client_id'] ?? 0 ) : ( is_object( $bank_auth ) ? (int) ( $bank_auth->client_id ?? 0 ) : 0 );
        $bank_do   = sanitize_text_field( wp_unslash( $_POST['bank_do'] ) );

        if ( $bank_cid !== (int) $client->id ) {
            $error = 'Your password is not correct, so the bank details were not changed.';
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
                    $success  = 'Bank account added.';
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
                    '<p>' . esc_html( $bank_msg ) . ' Buyers and tenants will now see the new details.</p><p>If you did not do this, change your password right away and contact support.</p>'
                );
            }
        }
    } elseif ( isset( $_POST['update_branding'] ) ) {
        // Branding (Gold and solo company only). Business name is never editable here.
        if ( ! OFP_Brand::can_brand( $client ) ) {
            $error = 'Branding is available on the Gold plan.';
        } elseif ( ! OFP_White_Label::schema_ready() ) {
            $error = 'Branding is not ready yet. Please reload the page and try again.';
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
            $b_email = trim( (string) wp_unslash( $_POST['support_email'] ?? '' ) );
            $b_phone = sanitize_text_field( wp_unslash( $_POST['support_phone'] ?? '' ) );
            $b_fav   = null;

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
                    'brand_color'   => $b_color,
                    'sidebar_color' => $b_sidebar,
                    'menu_hover_color' => $b_hover,
                    'support_email' => $b_email !== '' ? sanitize_email( $b_email ) : null,
                    'support_phone' => $b_phone !== '' ? $b_phone : null,
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
        } elseif ( $file['size'] > 300 * 1024 ) { // 300KB
            $error = 'File is too large. Maximum size is 300KB.';
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
                    
                    // Post-Redirect-Get pattern to avoid "Confirm Form Resubmission"
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
if ( isset( $_GET['success'] ) && $_GET['success'] === 'logo' ) {
    $success = 'Logo updated successfully.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'My Account', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">
    <div class="ofp-settings-dashboard">
        <h1 class="ofp-page-title">Account Settings</h1>

        <?php if ( $success ) : ?>
            <div class="ofp-notice success">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <?php echo esc_html( $success ); ?>
            </div>
        <?php endif; ?>
        <?php if ( $error ) : ?>
            <div class="ofp-notice error">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <?php echo esc_html( $error ); ?>
            </div>
        <?php endif; ?>

        <div class="ofp-settings-grid">
            
            <!-- LEFT COLUMN -->
            <div class="ofp-settings-left">
                <!-- Avatar Card -->
                <div class="ofp-card ofp-avatar-section">
                    <img src="<?php echo !empty($client->logo_url) ? esc_url($client->logo_url) : esc_url(OFP_URL . 'assets/images/default-avatar.png'); ?>" alt="Avatar" class="ofp-avatar-lg">
                    <h3 style="font-size:18px; font-weight:600; margin:0 0 4px; color:var(--text-main);"><?php echo esc_html( $client->owner_name ?: $client->business_name ); ?></h3>
                    <p style="font-size:13px; color:var(--text-muted); margin:0 0 24px;"><?php echo esc_html( $client->email ); ?></p>
                    
                    <label for="ofp-logo-input" class="ofp-btn">Upload New Photo</label>
                </div>

                <?php if ( OFP_Brand::can_brand( $client ) && OFP_White_Label::schema_ready() ) : ?>
                <!-- Branding Card (Gold and solo company) -->
                <div class="ofp-card">
                    <h3 style="font-size:16px; font-weight:600; margin:0 0 6px; color:var(--text-main);">Branding</h3>
                    <p style="font-size:13px; color:var(--text-muted); margin:0 0 16px;">Your logo is set with the photo upload above. Your business name is fixed. Contact support to change it.</p>
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="ofp_account_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ofp_account_' . $client->id ) ); ?>">
                        <input type="hidden" name="update_branding" value="1">

                        <?php
                        $color_rows = [
                            'brand_color'      => [ 'Main Color', 'Buttons, links and highlights. The button hover color is made from it.', '#3b82f6' ],
                            'sidebar_color'    => [ 'Sidebar Color', 'The menu background. Menu text turns light or dark by itself so it stays readable.', '#1c2233' ],
                            'menu_hover_color' => [ 'Menu Hover Color', 'Needs a Sidebar Color. If it is too hard to read, a safe one is used instead.', '#252e42' ],
                        ];
                        foreach ( $color_rows as $field => $row ) :
                            $saved = $client->$field ?? '';
                        ?>
                        <div class="ofp-form-group">
                            <label class="ofp-label"><?php echo esc_html( $row[0] ); ?></label>
                            <input type="color" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $saved ?: $row[2] ); ?>" oninput="this.form.elements['use_default_<?php echo esc_attr( $field ); ?>'].checked=false;" style="width:60px;height:38px;padding:2px;border-radius:6px;">
                            <label style="display:inline-block;font-size:12px;color:var(--text-muted);margin-left:10px;">
                                <input type="checkbox" name="use_default_<?php echo esc_attr( $field ); ?>" value="1" <?php checked( empty( $saved ) ); ?>> Use default
                            </label>
                            <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0;"><?php echo esc_html( $row[1] ); ?></p>
                        </div>
                        <?php endforeach; ?>

                        <div class="ofp-form-group">
                            <label class="ofp-label">Favicon (PNG, ICO, WebP or JPG, max 100KB)</label>
                            <?php if ( ! empty( $client->favicon_url ) ) : ?>
                                <img src="<?php echo esc_url( $client->favicon_url ); ?>" alt="Favicon" style="width:32px;height:32px;object-fit:contain;display:block;margin-bottom:8px;">
                                <label style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:8px;">
                                    <input type="checkbox" name="remove_favicon" value="1"> Remove favicon
                                </label>
                            <?php endif; ?>
                            <input type="file" name="favicon" accept="image/png,image/x-icon,image/webp,image/jpeg">
                        </div>

                        <div class="ofp-form-group">
                            <label class="ofp-label">Support Email</label>
                            <input type="email" name="support_email" class="ofp-input" value="<?php echo esc_attr( $client->support_email ?? '' ); ?>" placeholder="support@yourcompany.com">
                        </div>

                        <div class="ofp-form-group">
                            <label class="ofp-label">Support Phone</label>
                            <input type="text" name="support_phone" class="ofp-input" value="<?php echo esc_attr( $client->support_phone ?? '' ); ?>" placeholder="+234...">
                        </div>

                        <button type="submit" class="ofp-btn ofp-btn-primary">Save Branding</button>
                    </form>
                </div>
                <?php endif; ?>

                <?php $ofp_current_user = OFP_Auth::current_user(); if ( empty( $ofp_current_user->is_team_member ) ) : $ofp_banks = OFP_Bank_Account::list( (int) $client->id ); ?>
                <!-- Bank Accounts Card -->
                <div class="ofp-card">
                    <h3 style="font-size:16px; font-weight:600; margin:0 0 6px; color:var(--text-main);">Bank Accounts for Payments</h3>
                    <p style="font-size:13px; color:var(--text-muted); margin:0 0 16px;">Buyers and tenants send money to these accounts. You approve each payment after you see it in your bank. You can pick an account for each property when you add or edit it. Every change needs your password.</p>
                    <form method="POST" action="">
                        <input type="hidden" name="ofp_account_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ofp_account_' . $client->id ) ); ?>">

                        <?php if ( $ofp_banks ) : foreach ( $ofp_banks as $b ) : ?>
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border-color);flex-wrap:wrap;">
                                <div style="font-size:14px;color:var(--text-main);">
                                    <strong><?php echo esc_html( $b->bank_name ); ?></strong> <?php echo esc_html( $b->account_number ); ?><br>
                                    <span style="color:var(--text-muted);"><?php echo esc_html( $b->account_name ); ?></span>
                                    <?php if ( (int) $b->is_default === 1 ) : ?><span style="margin-left:6px;font-size:11px;background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;">Default</span><?php endif; ?>
                                </div>
                                <div>
                                    <?php if ( (int) $b->is_default !== 1 ) : ?>
                                        <button type="submit" name="bank_do" value="default:<?php echo (int) $b->id; ?>" class="ofp-btn ofp-btn-secondary" style="padding:4px 10px;font-size:12px;">Make default</button>
                                    <?php endif; ?>
                                    <button type="submit" name="bank_do" value="delete:<?php echo (int) $b->id; ?>" class="ofp-btn ofp-btn-secondary" style="padding:4px 10px;font-size:12px;" onclick="return confirm('Remove this bank account?');">Remove</button>
                                </div>
                            </div>
                        <?php endforeach; else : ?>
                            <p style="font-size:13px;color:var(--text-muted);">No bank account yet. Add one so buyers and tenants know where to pay.</p>
                        <?php endif; ?>

                        <h4 style="font-size:14px;margin:18px 0 8px;color:var(--text-main);">Add an account</h4>
                        <div class="ofp-form-group">
                            <label class="ofp-label">Bank</label>
                            <input type="text" name="bank_name" class="ofp-input" list="ofp-bank-names" placeholder="e.g. GTBank">
                            <datalist id="ofp-bank-names">
                                <?php foreach ( [ 'Access Bank', 'GTBank', 'First Bank', 'UBA', 'Zenith Bank', 'Fidelity Bank', 'Stanbic IBTC', 'Sterling Bank', 'Union Bank', 'Wema Bank', 'Polaris Bank', 'FCMB', 'Ecobank', 'Kuda', 'Opay', 'Palmpay', 'Moniepoint', '9PSB' ] as $bn ) : ?>
                                    <option value="<?php echo esc_attr( $bn ); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="ofp-form-group">
                            <label class="ofp-label">Account number (10 digits)</label>
                            <input type="text" name="bank_account_number" class="ofp-input" inputmode="numeric" maxlength="10" placeholder="0123456789">
                        </div>
                        <div class="ofp-form-group">
                            <label class="ofp-label">Account name</label>
                            <input type="text" name="bank_account_name" class="ofp-input" placeholder="Name on the account">
                        </div>
                        <div class="ofp-form-group">
                            <label class="ofp-label">Your password (to confirm)</label>
                            <input type="password" name="bank_password" class="ofp-input" autocomplete="current-password">
                        </div>
                        <button type="submit" name="bank_do" value="add" class="ofp-btn ofp-btn-primary">Add Account</button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Password Card -->
                <div class="ofp-card">
                    <h2 class="ofp-card-title">Security</h2>
                    <form method="POST" action="">
                        <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
                        <input type="hidden" name="change_password" value="1">
                        
                        <div class="ofp-form-group" style="margin-bottom:16px;">
                            <label class="ofp-label">Current Password</label>
                            <input type="password" name="current_password" class="ofp-input" required>
                        </div>
                        
                        <div class="ofp-form-group" style="margin-bottom:24px;">
                            <label class="ofp-label">New Password</label>
                            <input type="password" name="new_password" class="ofp-input" required minlength="8">
                        </div>
                        
                        <div class="ofp-form-group" style="margin-bottom:24px;">
                            <label class="ofp-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="ofp-input" required minlength="8">
                        </div>
                        
                        <button type="submit" class="ofp-btn" style="width:100%; justify-content:center;">Change Password</button>
                    </form>
                    
                    <a href="<?php echo esc_url( OFP_Client_Portal::logout_url() ); ?>" class="ofp-btn" style="width:100%; justify-content:center; background:transparent; border:1px solid var(--accent-red); color:var(--accent-red); margin-top:16px;">Log Out Everywhere</a>
                </div>
            </div>

            <!-- RIGHT COLUMN -->
            <div class="ofp-settings-right">
                
                <div class="ofp-card">
                    <h2 class="ofp-card-title">Profile Information</h2>
                    
                    <form method="POST" action="">
                        <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
                        <input type="hidden" name="update_profile" value="1">

                        <div class="ofp-form-grid">
                            <div class="ofp-form-group">
                                <label class="ofp-label">Business Name (Read Only)</label>
                                <input type="text" class="ofp-input" value="<?php echo esc_attr( $client->business_name ); ?>" readonly>
                            </div>
                            
                            <div class="ofp-form-group">
                                <label class="ofp-label">Owner Name (Read Only)</label>
                                <input type="text" class="ofp-input" value="<?php echo esc_attr( $client->owner_name ); ?>" readonly>
                            </div>
                            
                            <div class="ofp-form-group">
                                <label class="ofp-label">Email Address (Read Only)</label>
                                <input type="email" class="ofp-input" value="<?php echo esc_attr( $client->email ); ?>" readonly>
                            </div>
                            
                            <div class="ofp-form-group">
                                <label class="ofp-label">Mobile Number (Read Only)</label>
                                <input type="text" class="ofp-input" value="<?php echo esc_attr( $client->phone ); ?>" readonly>
                            </div>

                            <div class="ofp-form-group full-width">
                                <label class="ofp-label">Branded Portal Address (Read Only)</label>
                                <?php
                                $base_domain = get_option( 'ofp_crm_base_domain' ) ?: ( isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d+$/', '', $_SERVER['HTTP_HOST'] ) : 'ofastpipeline.com' );
                                $client_subdomain = ! empty( $client->subdomain ) ? $client->subdomain : '';
                                $portal_url = $client_subdomain ? ( 'https://' . $client_subdomain . '.' . $base_domain ) : '';
                                ?>
                                <input type="text" class="ofp-input" value="<?php echo esc_attr( $portal_url ?: 'No custom subdomain assigned' ); ?>" readonly>
                                <span style="font-size:12px; color:var(--text-muted); margin-top:4px;">
                                    <?php if ( $portal_url ) : ?>
                                        Your agency's direct dashboard link. (To change your subdomain, please contact support.)
                                    <?php else : ?>
                                        Contact support to configure a custom branded subdomain for your team.
                                    <?php endif; ?>
                                </span>
                            </div>

                            <!-- Phase 23 Additions -->
                            <div class="ofp-form-group full-width" style="margin-top:16px;">
                                <h3 style="font-size:14px; font-weight:600; color:var(--text-main); margin:0;">Public Profile & Tracking</h3>
                                <p style="font-size:13px; color:var(--text-muted); margin:4px 0 0;">Customize your public profile page and tracking settings.</p>
                            </div>

                            <div class="ofp-form-group">
                                <label class="ofp-label">Profile URL Slug</label>
                                <input type="text" name="profile_slug" class="ofp-input" value="<?php echo esc_attr( $client->profile_slug ?? '' ); ?>" placeholder="e.g. paymonthly-ng" required>
                                <?php if ( ! empty( $client->profile_slug ) ) : ?>
                                    <span style="font-size:12px; color:var(--text-muted); margin-top:4px;">
                                        Your profile link: <a href="<?php echo esc_url( home_url( '/agent/' . $client->profile_slug ) ); ?>" target="_blank" style="color:var(--accent-blue); text-decoration:underline; font-weight:500;"><?php echo esc_html( home_url( '/agent/' . $client->profile_slug ) ); ?></a>
                                    </span>
                                <?php else : ?>
                                    <span style="font-size:12px; color:var(--text-muted); margin-top:4px;">
                                        Your profile link: app.domain.com/agent/<strong>slug</strong>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="ofp-form-group">
                                <label class="ofp-label">Facebook Meta Pixel ID</label>
                                <input type="text" name="meta_pixel_id" class="ofp-input" value="<?php echo esc_attr( $client->meta_pixel_id ?? '' ); ?>" placeholder="e.g. 1234567890">
                            </div>

                            <div class="ofp-form-group full-width">
                                <label class="ofp-label">Biographical Info (About Us)</label>
                                <textarea name="bio" class="ofp-input" placeholder="Tell visitors about your agency..."><?php echo esc_textarea( $client->bio ?? '' ); ?></textarea>
                            </div>
                            
                            <div class="ofp-form-group full-width" style="align-items:flex-end; margin-top:16px;">
                                <button type="submit" class="ofp-btn-primary">Save Profile Changes</button>
                            </div>
                        </div>
                    </form>

                </div> <!-- /.ofp-card -->

            </div> <!-- /.ofp-settings-right -->
            
        </div> <!-- /.ofp-settings-grid -->
    </div> <!-- /.ofp-settings-dashboard -->
</div> <!-- /.ofp-container -->

<!-- Hidden form for logo upload -->
<form method="POST" action="" enctype="multipart/form-data" id="ofp-logo-form" style="display:none;">
    <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
    <input type="hidden" name="upload_logo" value="1">
    <input type="file" name="logo" id="ofp-logo-input" accept="image/jpeg,image/png,image/gif,image/webp">
</form>

<script>
    document.getElementById('ofp-logo-input').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        if (file.size > 300 * 1024) {
            alert('File is too large! Please select an image under 300KB.');
            e.target.value = '';
            return;
        }
        // Auto submit the hidden form when file is selected
        document.getElementById('ofp-logo-form').submit();
    });
</script>

</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
