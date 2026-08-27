<?php
/**
 * Template: /team-invite
 *
 * Page for team members to accept an invitation and set their password.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$error   = '';
$success = false;

$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

if ( empty( $token ) ) {
    wp_safe_redirect( home_url( '/login' ) );
    exit;
}

global $wpdb;
$team_member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ofp_team_members WHERE invite_token = %s AND status = 'pending' LIMIT 1", $token ) );

if ( ! $team_member ) {
    $error = 'This invitation link is invalid or has already been used.';
}

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $team_member ) {
    $password = sanitize_text_field( wp_unslash( $_POST['password'] ?? '' ) );
    
    if ( empty( $password ) || strlen( $password ) < 6 ) {
        $error = 'Please enter a password of at least 6 characters.';
    } else {
        $result = OFP_Team_Member::accept_invite( $token, $password );
        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
        } else {
            $success = true;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Invitation — OFast Pipeline</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; background: #f0f4f8; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); width: 100%; max-width: 400px; text-align: center; }
        h1 { margin-top: 0; font-size: 24px; color: #1e293b; }
        p { color: #64748b; margin-bottom: 24px; font-size: 14px; line-height: 1.5; }
        .alert { padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; text-align: left; }
        .alert-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .form-group { text-align: left; margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; color: #334155; }
        input[type="password"] { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 16px; box-sizing: border-box; }
        .btn { display: inline-block; width: 100%; padding: 14px; background: #1a73e8; color: #fff; border: none; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; text-decoration: none; box-sizing: border-box; }
        .btn:hover { background: #1557b0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Welcome to OFast Pipeline</h1>
        
        <?php if ( $success ) : ?>
            <div class="alert alert-success">Your password has been set. You can now log in.</div>
            <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" class="btn">Go to Login</a>
        <?php elseif ( $error && ! $team_member ) : ?>
            <div class="alert alert-error"><?php echo esc_html( $error ); ?></div>
            <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" class="btn" style="background:#cbd5e1;color:#1e293b;">Go to Login</a>
        <?php else : ?>
            <p>You have been invited to join <strong><?php echo esc_html( OFP_Client::get( $team_member->client_id )->business_name ); ?></strong>. Please set a password to activate your account.</p>
            
            <?php if ( $error ) : ?>
                <div class="alert alert-error"><?php echo esc_html( $error ); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Set Password</label>
                    <input type="password" name="password" required minlength="6" placeholder="Enter at least 6 characters">
                </div>
                <button type="submit" class="btn">Activate Account</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
