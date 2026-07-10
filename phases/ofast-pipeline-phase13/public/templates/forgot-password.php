<?php
/**
 * Template: /forgot-password — request a password reset link.
 *
 * Public route, no login required — must be added as an exception in
 * OFP_Client_Portal::handle_routes() alongside 'login' and 'signup'
 * (see Patch 12).
 *
 * Always shows the same generic success message regardless of
 * whether the submitted email matched a real client — this is
 * enforced by OFP_Auth::request_password_reset() itself always
 * returning true, not by any logic in this template, so there's no
 * way to accidentally leak which emails have accounts here.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$submitted = false;
$error     = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {

    if ( ! wp_verify_nonce( $_POST['ofp_forgot_nonce'] ?? '', 'ofp_forgot_password_action' ) ) {
        $error = 'Security check failed — please try again.';
    } else {

        OFP_Security::check_rate_limit( $_SERVER['REMOTE_ADDR'] ?? '', 'forgot_password', 5, 600 );

        $email = sanitize_email( $_POST['email'] ?? '' );

        if ( ! is_email( $email ) ) {
            $error = 'Please enter a valid email address.';
        } else {
            OFP_Auth::request_password_reset( $email );
            $submitted = true;
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
    <h1>Forgot your password?</h1>

    <?php if ( $submitted ) : ?>
        <div class="ofp-notice ofp-notice-info">
            If that email address has an account with us, a password
            reset link has been sent. It expires in 30 minutes.
        </div>
        <p class="ofp-auth-login-link">
            <a href="<?php echo esc_url( home_url( '/login' ) ); ?>">Back to login</a>
        </p>
    <?php else : ?>

        <?php if ( $error ) : ?>
            <div class="ofp-notice ofp-notice-error"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>

        <p class="ofp-muted">
            Enter the email address on your account and we'll send you
            a link to reset your password.
        </p>

        <form method="POST" class="ofp-auth-form">
            <?php wp_nonce_field( 'ofp_forgot_password_action', 'ofp_forgot_nonce' ); ?>

            <label>
                Email
                <input type="email" name="email" required
                       value="<?php echo isset( $_POST['email'] ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; ?>">
            </label>

            <button type="submit" class="ofp-btn ofp-btn-primary">Send Reset Link</button>
        </form>

        <p class="ofp-auth-login-link">
            <a href="<?php echo esc_url( home_url( '/login' ) ); ?>">Back to login</a>
        </p>
    <?php endif; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
