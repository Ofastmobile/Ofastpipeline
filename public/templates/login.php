<?php
/**
 * Template: /login
 *
 * The client login page. Custom authentication flow.
 * Handles both GET (show form) and POST (process login).
 *
 * OTP Flow:
 * 1. User submits email & password.
 * 2. System verifies credentials. If valid, generates & sends OTP (SMS/Email).
 * 3. User submits OTP.
 * 4. System verifies OTP and issues session cookie.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$error   = '';
$success = '';
$step    = 'credentials'; // 'credentials' or 'otp'
$user_email = '';
$user_phone = '';
$user_data = null; // Store basic info for session creation

// ── Admin preview token (debugging) ───────────────────────────────────────
if ( isset( $_GET['admin_preview'] ) ) {
    $preview_token = sanitize_text_field( wp_unslash( $_GET['admin_preview'] ) );

    if ( OFP_Auth::consume_admin_preview_token( $preview_token ) ) {
        wp_safe_redirect( home_url( '/dashboard?preview=1' ) );
        exit;
    }
    $error = 'This preview link has expired or already been used.';
}

if ( isset( $_GET['logged_out'] ) && $_GET['logged_out'] === '1' ) {
    $success = 'You have been logged out successfully.';
}
if ( isset( $_GET['suspended'] ) && $_GET['suspended'] === '1' ) {
    $error = 'Your account has been suspended. Please contact support.';
}
if ( isset( $_GET['session_expired'] ) && $_GET['session_expired'] === '1' ) {
    $error = 'Your session has expired. Please log in again.';
}

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {

    OFP_Security::check_rate_limit(
        OFP_Security::get_client_ip(),
        'client_login',
        10,
        300
    );

    if ( isset( $_POST['otp_step'] ) && $_POST['otp_step'] === '1' ) {
        // --- STEP 2: Verify OTP ---
        $step = 'otp';
        $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $otp      = isset( $_POST['otp'] ) ? sanitize_text_field( wp_unslash( $_POST['otp'] ) ) : '';
        $user_id  = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
        $user_type = isset( $_POST['user_type'] ) ? sanitize_text_field( $_POST['user_type'] ) : 'client';
        $client_id = isset( $_POST['client_id'] ) ? (int) $_POST['client_id'] : 0;
        $team_member_id = isset( $_POST['team_member_id'] ) && $_POST['team_member_id'] !== '' ? (int) $_POST['team_member_id'] : null;

        if ( empty( $otp ) ) {
            $error = 'Please enter the verification code.';
            $user_email = $email;
        } else {
            if ( OFP_Auth::verify_otp( $email, $otp, 'login' ) ) {
                // Success! Issue session
                OFP_Auth::issue_session( $client_id, $user_type, $team_member_id );
                
                $redirect_to = isset( $_GET['redirect_to'] )
                    ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) )
                    : home_url( '/dashboard' );

                wp_safe_redirect( $redirect_to );
                exit;
            } else {
                $error = 'Invalid or expired verification code.';
                $user_email = $email;
            }
        }
    } else {
        // --- STEP 1: Verify Credentials ---
        $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $password = isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : '';

        if ( empty( $email ) || empty( $password ) ) {
            $error = 'Please enter your email and password.';
        } else {
            $credentials_valid = OFP_Auth::check_credentials( $email, $password );
            
            if ( $credentials_valid ) {
                // Admin bypass: Skip OTP and login directly
                if ( OFP_Auth::is_admin_user() ) {
                    OFP_Auth::issue_session( $credentials_valid['client_id'], $credentials_valid['user_type'], $credentials_valid['team_member_id'] );
                    $redirect_to = isset( $_GET['redirect_to'] )
                        ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) )
                        : home_url( '/dashboard' );
                    wp_safe_redirect( $redirect_to );
                    exit;
                }

                // Generate OTP
                OFP_Auth::generate_and_send_otp( $email, $credentials_valid['phone'], 'login' );
                
                $step = 'otp';
                $success = 'A verification code has been sent to your email and phone.';
                $user_email = $email;
                $user_data = $credentials_valid;
            } else {
                $error = 'Invalid email address or password.';
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
    <title>Login — OFast Pipeline</title>
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
            margin-bottom: 24px;
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

        .ofp-forgot {
            display: block;
            text-align: right;
            font-size: 13px;
            color: #1a73e8;
            text-decoration: none;
            margin-top: -12px;
            margin-bottom: 20px;
        }

        .ofp-forgot:hover { text-decoration: underline; }

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
        <p>Client Portal — Sign in to your account</p>
    </div>

    <div class="ofp-card">
        <h2>Welcome back</h2>

        <?php if ( $error ) : ?>
            <div class="ofp-alert error"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>

        <?php if ( $success ) : ?>
            <div class="ofp-alert success"><?php echo esc_html( $success ); ?></div>
        <?php endif; ?>

        <?php if ( $step === 'otp' ) : ?>
            
            <form method="POST" action="<?php echo esc_url( home_url( '/login' ) ); ?>" novalidate>
                <input type="hidden" name="otp_step" value="1">
                <input type="hidden" name="email" value="<?php echo esc_attr( $user_email ); ?>">
                <?php if ( $user_data ) : ?>
                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $user_data['id'] ); ?>">
                    <input type="hidden" name="user_type" value="<?php echo esc_attr( $user_data['user_type'] ); ?>">
                    <input type="hidden" name="client_id" value="<?php echo esc_attr( $user_data['client_id'] ); ?>">
                    <input type="hidden" name="team_member_id" value="<?php echo esc_attr( $user_data['team_member_id'] ?? '' ); ?>">
                <?php elseif ( isset( $_POST['user_type'] ) ) : ?>
                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $_POST['user_id'] ); ?>">
                    <input type="hidden" name="user_type" value="<?php echo esc_attr( $_POST['user_type'] ); ?>">
                    <input type="hidden" name="client_id" value="<?php echo esc_attr( $_POST['client_id'] ); ?>">
                    <input type="hidden" name="team_member_id" value="<?php echo esc_attr( $_POST['team_member_id'] ); ?>">
                <?php endif; ?>

                <div class="ofp-field">
                    <label for="ofp-otp">7-Character Verification Code (OTP)</label>
                    <input
                        type="text"
                        id="ofp-otp"
                        name="otp"
                        placeholder="e.g. A1B2C3D"
                        required
                        autofocus
                    >
                </div>

                <button type="submit" class="ofp-btn">Verify & Sign In</button>
            </form>

        <?php else : ?>

            <form method="POST" action="<?php echo esc_url( home_url( '/login' ) ); ?>" novalidate>
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

                <div class="ofp-field">
                    <label for="ofp-password">Password</label>
                    <input
                        type="password"
                        id="ofp-password"
                        name="password"
                        placeholder="••••••••••"
                        required
                        autocomplete="current-password"
                    >
                </div>

                <a class="ofp-forgot" href="<?php echo esc_url( home_url( '/forgot-password' ) ); ?>">
                    Forgot password?
                </a>

                <button type="submit" class="ofp-btn">Sign In</button>
            </form>

        <?php endif; ?>
    </div>

    <div class="ofp-footer">
        New client?
        <a href="<?php echo esc_url( home_url( '/signup' ) ); ?>">Create your account →</a>
    </div>

</div>

</body>
</html>
