<?php
/**
 * Template: /forgot-password — request a password reset link.
 *
 * Public route, no login required.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$submitted = false;
$error     = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {

    if ( ! wp_verify_nonce( $_POST['ofp_forgot_nonce'] ?? '', 'ofp_forgot_password_action' ) ) {
        $error = 'Security check failed — please try again.';
    } else {

        OFP_Security::check_rate_limit( $_SERVER['REMOTE_ADDR'] ?? '', 'forgot_password', 5, 600 );

        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Forgot Password', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <?php OFP_Brand::print_host_head(); ?>
</head>
<body class="ofp-auth-body">

<div class="ofp-login-wrap">

    <div class="ofp-brand">
        <?php echo OFP_Brand::auth_heading( 'Client Portal — Password Recovery' ); ?>
    </div>

    <div class="ofp-card">
        <h2>Forgot your password?</h2>

        <?php if ( $submitted ) : ?>
            <div class="ofp-alert success">
                If that email address has an account with us, a password
                reset link has been sent. It expires in 30 minutes.
            </div>
            <div style="text-align:center; margin-top: 24px;">
                <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" style="color: #1a73e8; text-decoration: none; font-weight: 600; font-size: 14px;">← Back to login</a>
            </div>
        <?php else : ?>
            <p class="subtitle">
                Enter the email address on your account and we'll send you
                a link to reset your password.
            </p>

            <?php if ( $error ) : ?>
                <div class="ofp-alert error"><?php echo esc_html( $error ); ?></div>
            <?php endif; ?>

            <form method="POST" action="<?php echo esc_url( home_url( '/forgot-password' ) ); ?>" novalidate>
                <?php wp_nonce_field( 'ofp_forgot_password_action', 'ofp_forgot_nonce' ); ?>

                <div class="ofp-field">
                    <label for="ofp-email">Email Address</label>
                    <input
                        type="email"
                        id="ofp-email"
                        name="email"
                        value="<?php echo isset( $_POST['email'] ) ? esc_attr( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : ''; ?>"
                        placeholder="you@example.com"
                        required
                        autocomplete="email"
                    >
                </div>

                <button type="submit" class="ofp-btn">Send Reset Link</button>
            </form>

            <div style="text-align:center; margin-top: 24px;">
                <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" style="color: #64748b; text-decoration: none; font-size: 14px;">Cancel and go back</a>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php wp_footer(); ?>
<?php wp_footer(); ?>
<?php wp_footer(); ?>
</body>
</html>
