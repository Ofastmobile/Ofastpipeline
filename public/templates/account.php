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

// Better yet, just check manually:
$has_logo = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}ofp_clients LIKE 'logo_url'");
if (empty($has_logo)) {
    $wpdb->query("ALTER TABLE {$wpdb->prefix}ofp_clients ADD COLUMN logo_url VARCHAR(255) DEFAULT NULL AFTER business_category");
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
    .ofp-mobile-view {
        max-width: 420px;
        margin: 0 auto;
        background: rgba(255, 255, 255, 0.03);
        backdrop-filter: blur(24px);
        -webkit-backdrop-filter: blur(24px);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        position: relative;
    }
    .ofp-m-header {
        padding: 40px 20px 60px 20px;
        display: flex;
        flex-direction: column;
        align-items: center;
        color: #fff;
        position: relative;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        background: rgba(255, 255, 255, 0.01);
    }
    .ofp-m-avatar {
        width: 100px;
        height: 100px;
        border-radius: 50%;
        position: relative;
        margin-bottom: 16px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    }
    .ofp-m-avatar img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
        background: var(--bg-body);
        border: 2px solid rgba(255,255,255,0.1);
    }
    .ofp-m-avatar-badge {
        position: absolute;
        top: -5px;
        right: -5px;
        background: rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #fff;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        transition: transform 0.2s, background 0.2s;
    }
    .ofp-m-avatar-badge:hover { 
        transform: scale(1.1); 
        background: rgba(255,255,255,0.2); 
    }
    .ofp-m-avatar-badge svg { width: 14px; height: 14px; }
    
    .ofp-m-name {
        font-size: 18px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        position: relative;
        z-index: 10;
        letter-spacing: 0.02em;
        color: var(--text-main);
    }
    .ofp-m-name-edit {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        cursor: pointer;
        transition: background 0.2s;
    }
    .ofp-m-name-edit:hover {
        background: rgba(255,255,255,0.1);
        color: var(--text-main);
    }
    .ofp-m-name-edit svg { width: 12px; height: 12px; }

    .ofp-m-card {
        background: transparent;
        border-radius: 20px 20px 0 0;
        margin-top: -24px;
        padding: 32px 24px;
        position: relative;
        z-index: 20;
    }
    .ofp-m-title {
        text-align: center;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.1em;
        color: var(--text-main);
        margin-bottom: 32px;
        text-transform: uppercase;
        opacity: 0.8;
    }

    .ofp-m-field {
        margin-bottom: 20px;
    }
    .ofp-m-label {
        font-size: 13px;
        color: var(--text-muted);
        margin-bottom: 8px;
        display: block;
        font-weight: 500;
    }
    .ofp-m-input-wrap {
        position: relative;
        display: flex;
        align-items: center;
    }
    .ofp-m-input-icon {
        position: absolute;
        left: 8px;
        width: 36px;
        height: 36px;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.05);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-main);
        opacity: 0.8;
    }
    .ofp-m-input-icon svg { width: 16px; height: 16px; }
    .ofp-m-input {
        width: 100%;
        height: 52px;
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 100px;
        padding: 0 20px 0 56px;
        font-size: 14px;
        color: var(--text-main);
        background: rgba(0,0,0,0.2);
        transition: all 0.2s;
    }
    .ofp-m-input:focus {
        outline: none;
        border-color: rgba(255,255,255,0.3);
        background: rgba(0,0,0,0.3);
    }
    .ofp-m-input[readonly] {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .ofp-m-save-btn {
        width: 100%;
        height: 52px;
        background: rgba(255,255,255,0.1);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 100px;
        color: var(--text-main);
        font-size: 14px;
        font-weight: 600;
        letter-spacing: 0.05em;
        cursor: pointer;
        margin-top: 16px;
        transition: all 0.2s;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
    .ofp-m-save-btn:hover {
        background: rgba(255,255,255,0.15);
        border-color: rgba(255,255,255,0.3);
        transform: translateY(-1px);
    }
</style>

<div class="ofp-container">

    <div class="ofp-mobile-view">
        <div class="ofp-m-header">
            <div class="ofp-m-avatar">
                <img src="<?php echo !empty($client->logo_url) ? esc_url($client->logo_url) : esc_url(OFP_URL . 'assets/images/default-avatar.png'); ?>" alt="Avatar">
                <label for="ofp-logo-input" class="ofp-m-avatar-badge">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                </label>
            </div>
            <div class="ofp-m-name">
                <?php echo esc_html( $client->owner_name ?: $client->business_name ); ?>
                <label for="ofp-logo-input" class="ofp-m-name-edit">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                </label>
            </div>
        </div>

        <div class="ofp-m-card">
            <div class="ofp-m-title">USER PROFILE</div>

            <?php if ( $success ) : ?>
                <div style="background:rgba(16,185,129,0.1);color:var(--accent-green);border-radius:100px;font-size:12px;padding:10px 16px;margin-bottom:20px;text-align:center;font-weight:500;">
                    <?php echo esc_html( $success ); ?>
                </div>
            <?php endif; ?>
            <?php if ( $error ) : ?>
                <div style="background:rgba(239,68,68,0.1);color:var(--accent-red);border-radius:100px;font-size:12px;padding:10px 16px;margin-bottom:20px;text-align:center;font-weight:500;">
                    <?php echo esc_html( $error ); ?>
                </div>
            <?php endif; ?>

            <div class="ofp-m-field">
                <span class="ofp-m-label">User Name</span>
                <div class="ofp-m-input-wrap">
                    <div class="ofp-m-input-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg></div>
                    <input type="text" class="ofp-m-input" value="<?php echo esc_attr($client->owner_name ?: $client->business_name); ?>" readonly>
                </div>
            </div>

            <div class="ofp-m-field">
                <span class="ofp-m-label">Email Id</span>
                <div class="ofp-m-input-wrap">
                    <div class="ofp-m-input-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg></div>
                    <input type="email" class="ofp-m-input" value="<?php echo esc_attr($client->email); ?>" readonly>
                </div>
            </div>

            <div class="ofp-m-field">
                <span class="ofp-m-label">Mobile Number</span>
                <div class="ofp-m-input-wrap">
                    <div class="ofp-m-input-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg></div>
                    <input type="text" class="ofp-m-input" value="<?php echo esc_attr($client->phone); ?>" readonly>
                </div>
            </div>

            <!-- Password form -->
            <form method="POST" action="">
                <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
                <input type="hidden" name="change_password" value="1">
                
                <div class="ofp-m-field" style="margin-top:32px;">
                    <span class="ofp-m-label">Update Password</span>
                    <div class="ofp-m-input-wrap" style="margin-bottom:12px;">
                        <div class="ofp-m-input-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg></div>
                        <input type="password" name="current_password" class="ofp-m-input" placeholder="Current Password" required>
                    </div>
                    <div class="ofp-m-input-wrap">
                        <div class="ofp-m-input-icon"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg></div>
                        <input type="password" name="new_password" class="ofp-m-input" placeholder="New Password" required minlength="8">
                    </div>
                </div>

                <button type="submit" class="ofp-m-save-btn">SAVE</button>
            </form>
            
            <div style="text-align:center;margin-top:24px;">
                <a href="<?php echo esc_url( OFP_Client_Portal::logout_url() ); ?>" style="font-size:13px;color:var(--accent-red);font-weight:600;text-decoration:none;">Log Out Account</a>
            </div>
        </div>
    </div><!-- /.ofp-mobile-view -->

    <!-- Hidden form for logo upload -->
    <form method="POST" action="" enctype="multipart/form-data" id="ofp-logo-form" style="display:none;">
        <?php wp_nonce_field( 'ofp_account_' . $client->id, 'ofp_account_nonce' ); ?>
        <input type="hidden" name="upload_logo" value="1">
        <input type="file" name="logo" id="ofp-logo-input" accept="image/jpeg,image/png,image/gif,image/webp">
    </form>
    
    <script>
        document.querySelectorAll('.ofp-copy-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                navigator.clipboard.writeText(btn.dataset.copy).then(function() {
                    btn.textContent = 'Copied!';
                    setTimeout(function() { btn.textContent = 'Copy'; }, 2000);
                });
            });
        });
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
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
