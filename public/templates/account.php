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
    <title>My Account — OFast Pipeline</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<style>
    .ofp-settings-dashboard {
        max-width: 1200px;
        margin: 0 auto;
        padding: 40px 20px;
    }
    .ofp-page-title {
        font-size: 28px;
        font-weight: 700;
        color: var(--text-main);
        margin-bottom: 40px;
        letter-spacing: -0.02em;
    }

    /* Grid Layout */
    .ofp-settings-grid {
        display: grid;
        grid-template-columns: 320px 1fr;
        gap: 40px;
        align-items: start;
    }

    /* Cards */
    .ofp-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 32px;
        margin-bottom: 32px;
        box-shadow: var(--shadow-md);
    }
    .ofp-card-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--text-main);
        margin-bottom: 24px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--border-color);
    }

    /* Avatar Section */
    .ofp-avatar-section {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    .ofp-avatar-lg {
        width: 160px;
        height: 160px;
        border-radius: 20px;
        object-fit: cover;
        margin-bottom: 20px;
        background: var(--bg-body);
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-md);
    }
    .ofp-upload-btn {
        background: var(--bg-body);
        border: 1px solid var(--border-color);
        color: var(--text-main);
        padding: 10px 24px;
        border-radius: 100px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-block;
    }
    .ofp-upload-btn:hover {
        background: var(--bg-card-hover);
        border-color: var(--border-highlight);
    }

    /* Forms */
    .ofp-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
    }
    .ofp-form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .ofp-form-group.full-width {
        grid-column: 1 / -1;
    }
    .ofp-label {
        font-size: 13px;
        font-weight: 500;
        color: var(--text-muted);
    }
    .ofp-input {
        width: 100%;
        height: 48px;
        background: var(--bg-body);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 0 16px;
        color: var(--text-main);
        font-size: 14px;
        transition: all 0.2s;
    }
    .ofp-input:focus {
        outline: none;
        border-color: var(--accent-blue);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    .ofp-input[readonly] {
        opacity: 0.6;
        background: var(--bg-card);
        cursor: not-allowed;
    }
    textarea.ofp-input {
        height: auto;
        padding-top: 16px;
        resize: vertical;
        min-height: 120px;
    }

    /* Buttons */
    .ofp-btn-primary {
        background: var(--btn-primary);
        border: none;
        color: #fff;
        padding: 0 32px;
        height: 48px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .ofp-btn:hover {
        background: var(--btn-primary-hover);
    }
    
    .ofp-btn-secondary {
        background: var(--bg-body);
        border: 1px solid var(--border-color);
        color: var(--text-main);
        padding: 0 32px;
        height: 48px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        width: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .ofp-btn-secondary:hover {
        background: var(--bg-card-hover);
        border-color: var(--border-highlight);
    }

    .ofp-logout-link {
        color: var(--accent-red);
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        display: inline-block;
        margin-top: 16px;
        text-align: center;
        width: 100%;
    }
    .ofp-logout-link:hover {
        text-decoration: underline;
    }

    /* Notices */
    .ofp-notice {
        padding: 16px 20px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 500;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .ofp-notice.success {
        background: rgba(16, 185, 129, 0.1);
        color: var(--accent-green);
        border: 1px solid rgba(16, 185, 129, 0.2);
    }
    .ofp-notice.error {
        background: rgba(239, 68, 68, 0.1);
        color: var(--accent-red);
        border: 1px solid rgba(239, 68, 68, 0.2);
    }

    /* Mobile Fallback */
    @media (max-width: 900px) {
        .ofp-settings-grid {
            grid-template-columns: 1fr;
        }
        .ofp-form-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

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
