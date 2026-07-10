<?php
/**
 * Template: /reset-password?client={id}&token={raw_token} — set a new
 * password after clicking the emailed reset link.
 *
 * Public route, no login required — must be added as an exception in
 * OFP_Client_Portal::handle_routes() alongside 'login', 'signup', and
 * 'forgot-password' (see Patch 12).
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$client_id = isset( $_GET['client'] ) ? (int) $_GET['client'] : ( isset( $_POST['client'] ) ? (int) $_POST['client'] : 0 );
$token     = sanitize_text_field( $_GET['token'] ?? ( $_POST['token'] ?? '' ) );

$error   = '';
$success = false;

$token_valid = $client_id && $token && OFP_Auth::verify_reset_token( $client_id, $token );

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid ) {

    if ( ! wp_verify_nonce( $_POST['ofp_reset_nonce'] ?? '', 'ofp_reset_password_action' ) ) {
        $error = 'Security check failed — please try again.';
    } else {

        OFP_Security::check_rate_limit( $_SERVER['REMOTE_ADDR'] ?? '', 'reset_password', 5, 600 );

        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password_confirm'] ?? '';

        if ( strlen( $password ) < 8 ) {
            $error = 'Password must be at least 8 characters.';
        } elseif ( $password !== $password2 ) {
            $error = 'Passwords do not match.';
        } else {
            $done = OFP_Auth::complete_password_reset( $client_id, $token, $password );
            if ( $done ) {
                $success = true;
                // Token is now consumed — re-check so the form below
                // doesn't reappear if the client refreshes this page.
                $token_valid = false;
            } else {
                $error = 'This link is no longer valid. Please request a new one.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password — OFast Pipeline</title>
    <?php wp_head(); ?>
</head>
<body>
<div class="ofp-auth-wrapper">
    <h1>Set a new password</h1>

    <?php if ( $success ) : ?>
        <div class="ofp-notice ofp-notice-info">
            Your password has been updated. You can now log in.
        </div>
        <p class="ofp-auth-login-link">
            <a href="<?php echo esc_url( home_url( '/login' ) ); ?>">Go to login</a>
        </p>

    <?php elseif ( ! $token_valid ) : ?>
        <div class="ofp-notice ofp-notice-error">
            This password reset link is invalid or has expired.
        </div>
        <p class="ofp-auth-login-link">
            <a href="<?php echo esc_url( home_url( '/forgot-password' ) ); ?>">Request a new link</a>
        </p>

    <?php else : ?>

        <?php if ( $error ) : ?>
            <div class="ofp-notice ofp-notice-error"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>

        <form method="POST" class="ofp-auth-form">
            <?php wp_nonce_field( 'ofp_reset_password_action', 'ofp_reset_nonce' ); ?>
            <input type="hidden" name="client" value="<?php echo esc_attr( $client_id ); ?>">
            <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">

            <label>
                New Password
                <input type="password" name="password" minlength="8" required>
            </label>

            <label>
                Confirm New Password
                <input type="password" name="password_confirm" minlength="8" required>
            </label>

            <p class="ofp-muted" style="font-size:12px;">Minimum 8 characters.</p>

            <button type="submit" class="ofp-btn ofp-btn-primary">Update Password</button>
        </form>
    <?php endif; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
