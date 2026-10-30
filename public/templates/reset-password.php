<?php
/**
 * Template: /reset-password?token={raw_token}
 *
 * Public route, no login required.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? ( $_POST['token'] ?? '' ) ) );
$is_team_member = ! empty( $_GET['tm'] ) || ! empty( $_POST['tm'] );

$error   = '';
$success = false;

$token_valid = $token && OFP_Auth::verify_reset_token( $token, $is_team_member );

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid ) {

    if ( ! wp_verify_nonce( $_POST['ofp_reset_nonce'] ?? '', 'ofp_reset_password_action' ) ) {
        $error = 'Security check failed — please try again.';
    } else {

        OFP_Security::check_rate_limit( OFP_Security::get_client_ip(), 'reset_password', 5, 600 );

        $password  = wp_unslash( $_POST['password'] ?? '' );
        $password2 = wp_unslash( $_POST['password_confirm'] ?? '' );

        if ( strlen( $password ) < 8 ) {
            $error = 'Password must be at least 8 characters.';
        } elseif ( $password !== $password2 ) {
            $error = 'Passwords do not match.';
        } else {
            $done = OFP_Auth::complete_password_reset( $token, $password, $is_team_member );
            if ( $done ) {
                $success = true;
                // Token is now consumed
                $token_valid = false;
            } else {
                $error = 'Failed to reset password. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Set New Password', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <?php OFP_Brand::print_host_head(); ?>
</head>
<body class="ofp-auth-body">

<div class="ofp-login-wrap">

    <div class="ofp-brand">
        <?php echo OFP_Brand::auth_heading( 'Client Portal — Password Recovery' ); ?>
    </div>

    <div class="ofp-card">
        <h2>Set a new password</h2>

        <?php if ( $success ) : ?>
            <div class="ofp-alert success">
                Your password has been successfully updated. You can now log in.
            </div>
            <div style="text-align:center; margin-top: 24px;">
                <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" style="color: #1a73e8; text-decoration: none; font-weight: 600; font-size: 14px;">Go to login →</a>
            </div>

        <?php elseif ( ! $token_valid ) : ?>
            <div class="ofp-alert error">
                This password reset link is invalid or has expired. Links are only valid for 30 minutes.
            </div>
            <div style="text-align:center; margin-top: 24px;">
                <a href="<?php echo esc_url( home_url( '/forgot-password' ) ); ?>" style="color: #1a73e8; text-decoration: none; font-weight: 600; font-size: 14px;">Request a new link</a>
            </div>

        <?php else : ?>

            <p class="subtitle">Please enter your new password below.</p>

            <?php if ( $error ) : ?>
                <div class="ofp-alert error"><?php echo esc_html( $error ); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <?php wp_nonce_field( 'ofp_reset_password_action', 'ofp_reset_nonce' ); ?>
                <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
                <input type="hidden" name="tm" value="<?php echo esc_attr( $is_team_member ? '1' : '0' ); ?>">

                <div class="ofp-field">
                    <label for="ofp-password">New Password</label>
                    <input
                        type="password"
                        id="ofp-password"
                        name="password"
                        minlength="8"
                        required
                    >
                </div>

                <div class="ofp-field">
                    <label for="ofp-password-confirm">Confirm New Password</label>
                    <input
                        type="password"
                        id="ofp-password-confirm"
                        name="password_confirm"
                        minlength="8"
                        required
                    >
                    <p style="font-size:12px; color:#64748b; margin-top:6px;">Minimum 8 characters.</p>
                </div>

                <button type="submit" class="ofp-btn" style="margin-top: 8px;">Update Password</button>
            </form>
        <?php endif; ?>
    </div>

</div>

<?php wp_footer(); ?>
</body>
</html>
