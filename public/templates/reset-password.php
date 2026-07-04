<?php
/**
 * Template: /reset-password?email={email}&token={raw_token}
 *
 * Public route, no login required.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$email = sanitize_email( wp_unslash( $_GET['email'] ?? ( $_POST['email'] ?? '' ) ) );
$token = sanitize_text_field( wp_unslash( $_GET['token'] ?? ( $_POST['token'] ?? '' ) ) );

$error   = '';
$success = false;

$token_valid = $email && $token && OFP_Auth::verify_reset_token( $email, $token );

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid ) {

    if ( ! wp_verify_nonce( $_POST['ofp_reset_nonce'] ?? '', 'ofp_reset_password_action' ) ) {
        $error = 'Security check failed — please try again.';
    } else {

        OFP_Security::check_rate_limit( $_SERVER['REMOTE_ADDR'] ?? '', 'reset_password', 5, 600 );

        $password  = wp_unslash( $_POST['password'] ?? '' );
        $password2 = wp_unslash( $_POST['password_confirm'] ?? '' );

        if ( strlen( $password ) < 8 ) {
            $error = 'Password must be at least 8 characters.';
        } elseif ( $password !== $password2 ) {
            $error = 'Passwords do not match.';
        } else {
            $done = OFP_Auth::complete_password_reset( $email, $token, $password );
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
    <title>Set New Password — OFast Pipeline</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .ofp-login-wrap {
            width: 100%;
            max-width: 420px;
        }

        .ofp-brand {
            text-align: center;
            margin-bottom: 32px;
        }

        .ofp-brand h1 {
            font-size: 28px;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.5px;
        }

        .ofp-brand p {
            color: #94a3b8;
            font-size: 14px;
            margin-top: 6px;
        }

        .ofp-card {
            background: #fff;
            border-radius: 16px;
            padding: 40px 36px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .ofp-card h2 {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 12px;
        }

        .ofp-card p.subtitle {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 24px;
            line-height: 1.5;
        }

        .ofp-alert {
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .ofp-alert.error   { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .ofp-alert.success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }

        .ofp-field {
            margin-bottom: 18px;
        }

        .ofp-field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .ofp-field input {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            font-size: 15px;
            color: #0f172a;
            transition: border-color 0.15s;
            outline: none;
            background: #fafafa;
        }

        .ofp-field input:focus {
            border-color: #1a73e8;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(26,115,232,0.12);
        }

        .ofp-btn {
            width: 100%;
            padding: 13px;
            background: #1a73e8;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s, transform 0.1s;
        }

        .ofp-btn:hover   { background: #1557b0; }
        .ofp-btn:active  { transform: scale(0.99); }

        .ofp-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 13px;
            color: #94a3b8;
        }

        .ofp-footer a { color: #1a73e8; text-decoration: none; }
        .ofp-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="ofp-login-wrap">

    <div class="ofp-brand">
        <h1>OFast Pipeline</h1>
        <p>Client Portal — Password Recovery</p>
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
                <input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>">
                <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">

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

</body>
</html>
