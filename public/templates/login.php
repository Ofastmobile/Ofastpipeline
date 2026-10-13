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

            // A client's own domain only accepts that client's people.
            $host_client = OFP_Host_Router::current_client_record();
            if ( $credentials_valid && $host_client && ! OFP_Auth::is_admin_user()
                && (int) $credentials_valid['client_id'] !== (int) $host_client->id ) {
                $credentials_valid = false;
            }
            
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
    <title><?php echo esc_html( OFP_Brand::title( 'Login', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <?php OFP_Brand::print_host_head(); ?>
</head>
<body class="ofp-split-auth-body">

    <!-- Top Floating Controls -->
    <div class="ofp-split-topbar">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ofp-split-back-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
            <span>Back to website</span>
        </a>

        <!-- Top Right Controls: Tiny Clock & Theme Toggle -->
        <div class="ofp-split-topbar-right">
            <div class="ofp-tiny-clock" id="ofp-tiny-clock" title="Current time">
                <span class="ofp-dt-live-dot" aria-hidden="true"></span>
                <span id="ofp-live-clock"><?php echo esc_html( wp_date( 'D, M j • H:i:s' ) ); ?></span>
            </div>

            <!-- Theme Toggle Button -->
            <button class="ofp-auth-theme-toggle" id="ofp-auth-theme-toggle" type="button" aria-label="Toggle Theme" title="Toggle Light/Dark Theme">
                <svg class="theme-toggle-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                </svg>
                <svg class="theme-toggle-sun" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
            </button>
        </div>
    </div>

    <!-- Main Split Card -->
    <div class="ofp-split-container">
        <div class="ofp-split-card">

            <!-- LEFT PANEL: Auth Form -->
            <div class="ofp-split-form-panel">
                <div class="ofp-split-form-inner">
                    
                    <!-- Brand Header -->
                    <div class="ofp-split-brand">
                        <?php if ( OFP_Brand::is_solo_host() ) : ?>
                            <?php echo OFP_Brand::auth_heading( 'Client Portal Sign In' ); ?>
                        <?php else : ?>
                            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ofp-split-logo-badge">
                                <span class="ofp-split-logo-icon">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                </span>
                                <span class="ofp-split-logo-text"><?php echo esc_html( OFP_Brand::name() ); ?></span>
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- Heading -->
                    <div class="ofp-split-heading">
                        <?php if ( $step === 'otp' ) : ?>
                            <h2>Security Verification</h2>
                            <p>Enter the 7-character verification code sent to your registered contact.</p>
                        <?php else : ?>
                            <h2>Welcome back</h2>
                            <p>Sign in to access your properties, leads, and automation pipeline.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Alerts -->
                    <?php if ( $error ) : ?>
                        <div class="ofp-alert error">
                            <?php echo esc_html( $error ); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( $success ) : ?>
                        <div class="ofp-alert success">
                            <?php echo esc_html( $success ); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Form Content -->
                    <?php if ( $step === 'otp' ) : ?>
                        <form method="POST" action="<?php echo esc_url( home_url( '/login' ) ); ?>" class="ofp-auth-form" novalidate>
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

                            <div class="ofp-input-group">
                                <label for="ofp-otp">7-Character Verification Code (OTP)</label>
                                <div class="ofp-input-wrap">
                                    <span class="ofp-input-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                                    </span>
                                    <input
                                        type="text"
                                        id="ofp-otp"
                                        name="otp"
                                        placeholder="e.g. A1B2C3D"
                                        class="ofp-input-styled ofp-input-otp"
                                        required
                                        autofocus
                                        maxlength="10"
                                    >
                                </div>
                            </div>

                            <button type="submit" class="ofp-split-submit-btn">
                                <span>Verify &amp; Sign In</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                            </button>

                            <div class="ofp-split-alt-action">
                                <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" class="ofp-link-subtle">← Back to password login</a>
                            </div>
                        </form>
                    <?php else : ?>
                        <form method="POST" action="<?php echo esc_url( home_url( '/login' ) ); ?>" class="ofp-auth-form" novalidate>
                            <div class="ofp-input-group">
                                <label for="ofp-email">Email Address</label>
                                <div class="ofp-input-wrap">
                                    <span class="ofp-input-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                                    </span>
                                    <input
                                        type="email"
                                        id="ofp-email"
                                        name="email"
                                        value="<?php echo isset( $_POST['email'] ) ? esc_attr( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : ''; ?>"
                                        placeholder="name@company.com"
                                        class="ofp-input-styled"
                                        required
                                        autocomplete="email"
                                    >
                                </div>
                            </div>

                            <div class="ofp-input-group">
                                <div class="ofp-label-split">
                                    <label for="ofp-password">Password</label>
                                    <a class="ofp-forgot-link" href="<?php echo esc_url( home_url( '/forgot-password' ) ); ?>">Forgot Password?</a>
                                </div>
                                <div class="ofp-input-wrap">
                                    <span class="ofp-input-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/></svg>
                                    </span>
                                    <input
                                        type="password"
                                        id="ofp-password"
                                        name="password"
                                        placeholder="••••••••••••"
                                        class="ofp-input-styled"
                                        required
                                        autocomplete="current-password"
                                    >
                                    <button type="button" class="ofp-password-toggle-btn" id="ofp-password-toggle" aria-label="Toggle password visibility">
                                        <svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        <svg class="icon-eye-off" style="display:none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                                    </button>
                                </div>
                            </div>

                            <div class="ofp-form-row-utility">
                                <label class="ofp-checkbox-label">
                                    <input type="checkbox" name="remember_me" value="1" checked>
                                    <span class="ofp-checkbox-text">Remember me for 7 days</span>
                                </label>
                            </div>

                            <button type="submit" class="ofp-split-submit-btn">
                                <span>Sign In</span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- Footer Note -->
                    <div class="ofp-split-panel-footer">
                        <?php if ( OFP_Brand::is_solo_host() ) : ?>
                            <?php echo OFP_Brand::support_line_html(); ?>
                        <?php else : ?>
                            <p class="ofp-register-prompt">
                                New client?
                                <a href="<?php echo esc_url( home_url( '/signup' ) ); ?>">Create an account &rarr;</a>
                            </p>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <!-- RIGHT PANEL: High-Conversion PropTech Showcase -->
            <div class="ofp-split-showcase-panel">
                <div class="ofp-showcase-content">

                    <div>
                        <div class="ofp-showcase-pill">
                            <span class="ofp-showcase-sparkle">&#10022;</span>
                            <span><?php echo esc_html( OFP_Brand::context_name() ); ?> CRM Automations</span>
                        </div>

                        <h3 class="ofp-showcase-headline">
                            Automate property leads, buyer follow-ups, and sales on autopilot.
                        </h3>

                        <p class="ofp-showcase-subhead">
                            Done-for-you SMS pipelines, automated voice calls, buyer installment tracking, and unified real estate client portals.
                        </p>

                        <!-- Key Metrics Grid -->
                        <div class="ofp-showcase-stats-grid">
                            <div class="ofp-stat-card">
                                <span class="ofp-stat-val">&#8358;1.5B+</span>
                                <span class="ofp-stat-lbl">Tracked Volume</span>
                            </div>
                            <div class="ofp-stat-card">
                                <span class="ofp-stat-val">&lt; 60s</span>
                                <span class="ofp-stat-lbl">Instant Lead SMS</span>
                            </div>
                            <div class="ofp-stat-card">
                                <span class="ofp-stat-val">99.8%</span>
                                <span class="ofp-stat-lbl">Relay Delivery</span>
                            </div>
                        </div>
                    </div>

                    <!-- Testimonials Carousel (Horizontal slide, 5000ms ease, no arrows) -->
                    <div class="ofp-testimonials-carousel" id="ofp-testimonials-carousel">
                        <div class="ofp-testimonials-track" id="ofp-testimonials-track">

                            <!-- Slide 1 -->
                            <div class="ofp-testimonial-slide">
                                <div class="ofp-testimonial-card">
                                    <div class="ofp-testimonial-header">
                                        <div class="ofp-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                                        <span class="ofp-google-badge">
                                            <svg viewBox="0 0 24 24" width="14" height="14"><path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-9.17z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.36 24 12 24z"/><path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 10.03 0 12s.45 3.82 1.25 5.42l4.03-3.15z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.36 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/></svg>
                                            Verified Review
                                        </span>
                                    </div>
                                    <p class="ofp-testimonial-quote">
                                        "Believe me, this pipeline system is exceptional. The automated SMS lead follow-ups and installment management saved us countless hours and closed multiple properties for our agency."
                                    </p>
                                    <div class="ofp-testimonial-author">
                                        <div class="ofp-author-avatar">TA</div>
                                        <div class="ofp-author-meta">
                                            <strong>Tosin Adomorkhai</strong>
                                            <span>Managing Partner, PrimeKey Realtors</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Slide 2 -->
                            <div class="ofp-testimonial-slide">
                                <div class="ofp-testimonial-card">
                                    <div class="ofp-testimonial-header">
                                        <div class="ofp-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                                        <span class="ofp-google-badge">
                                            <svg viewBox="0 0 24 24" width="14" height="14"><path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-9.17z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.36 24 12 24z"/><path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 10.03 0 12s.45 3.82 1.25 5.42l4.03-3.15z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.36 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/></svg>
                                            Verified Review
                                        </span>
                                    </div>
                                    <p class="ofp-testimonial-quote">
                                        "Instant lead SMS response in under 60 seconds completely changed our conversion rate. Our agents never miss an inquiry, and buyers love the automated milestones."
                                    </p>
                                    <div class="ofp-testimonial-author">
                                        <div class="ofp-author-avatar" style="background:linear-gradient(135deg, #ec4899, #8b5cf6);">CN</div>
                                        <div class="ofp-author-meta">
                                            <strong>Chioma Nnamdi</strong>
                                            <span>Principal Broker, Havencrest Luxury Properties</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Slide 3 -->
                            <div class="ofp-testimonial-slide">
                                <div class="ofp-testimonial-card">
                                    <div class="ofp-testimonial-header">
                                        <div class="ofp-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                                        <span class="ofp-google-badge">
                                            <svg viewBox="0 0 24 24" width="14" height="14"><path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-9.17z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.36 24 12 24z"/><path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 10.03 0 12s.45 3.82 1.25 5.42l4.03-3.15z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.36 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/></svg>
                                            Verified Review
                                        </span>
                                    </div>
                                    <p class="ofp-testimonial-quote">
                                        "Managing multi-property installment schedules across dozens of off-plan buyers was chaotic. Now everything runs seamlessly on autopilot with clear customer ledgers."
                                    </p>
                                    <div class="ofp-testimonial-author">
                                        <div class="ofp-author-avatar" style="background:linear-gradient(135deg, #10b981, #059669);">BA</div>
                                        <div class="ofp-author-meta">
                                            <strong>Babatunde Adeleke</strong>
                                            <span>Director of Sales, Apex Horizon Developments</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Slide 4 -->
                            <div class="ofp-testimonial-slide">
                                <div class="ofp-testimonial-card">
                                    <div class="ofp-testimonial-header">
                                        <div class="ofp-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                                        <span class="ofp-google-badge">
                                            <svg viewBox="0 0 24 24" width="14" height="14"><path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-9.17z"/><path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.1-6.72-4.93H1.25v3.15C3.26 21.36 7.36 24 12 24z"/><path fill="#FBBC05" d="M5.28 14.27c-.25-.72-.38-1.49-.38-2.27s.13-1.55.38-2.27V6.58H1.25C.45 8.18 0 10.03 0 12s.45 3.82 1.25 5.42l4.03-3.15z"/><path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.36 0 3.26 2.64 1.25 6.58l4.03 3.15c.95-2.83 3.6-4.98 6.72-4.98z"/></svg>
                                            Verified Review
                                        </span>
                                    </div>
                                    <p class="ofp-testimonial-quote">
                                        "Our buyers love their dedicated portal with real-time payment receipts. It built exceptional trust and made our brand stand out in the competitive real estate market."
                                    </p>
                                    <div class="ofp-testimonial-author">
                                        <div class="ofp-author-avatar" style="background:linear-gradient(135deg, #0ea5e9, #2563eb);">FB</div>
                                        <div class="ofp-author-meta">
                                            <strong>Folashade Balogun</strong>
                                            <span>Lead Consultant, UrbanNest Realty</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- Seamless Dots Pagination (No arrows) -->
                        <div class="ofp-carousel-dots" id="ofp-carousel-dots">
                            <button type="button" class="ofp-carousel-dot active" data-index="0" aria-label="Review 1"></button>
                            <button type="button" class="ofp-carousel-dot" data-index="1" aria-label="Review 2"></button>
                            <button type="button" class="ofp-carousel-dot" data-index="2" aria-label="Review 3"></button>
                            <button type="button" class="ofp-carousel-dot" data-index="3" aria-label="Review 4"></button>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>

    <!-- Interactive Client Scripts -->
    <script>
    (function() {
        // 1. Password peek toggle
        var toggleBtn = document.getElementById('ofp-password-toggle');
        var pwInput = document.getElementById('ofp-password');
        if (toggleBtn && pwInput) {
            toggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var isPw = pwInput.type === 'password';
                pwInput.type = isPw ? 'text' : 'password';
                var eye = toggleBtn.querySelector('.icon-eye');
                var eyeOff = toggleBtn.querySelector('.icon-eye-off');
                if (eye && eyeOff) {
                    eye.style.display = isPw ? 'none' : 'block';
                    eyeOff.style.display = isPw ? 'block' : 'none';
                }
            });
        }

        // 2. Theme toggle
        var themeBtn = document.getElementById('ofp-auth-theme-toggle');
        if (themeBtn) {
            themeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (typeof window.ofpToggleTheme === 'function') {
                    window.ofpToggleTheme();
                    return;
                }
                var html = document.documentElement;
                var isDark = html.classList.toggle('dark');
                if (isDark) {
                    html.classList.remove('light');
                    html.setAttribute('data-theme', 'dark');
                    try { localStorage.setItem('ofp_portal_theme', 'dark'); } catch(err){}
                } else {
                    html.classList.add('light');
                    html.setAttribute('data-theme', 'light');
                    try { localStorage.setItem('ofp_portal_theme', 'light'); } catch(err){}
                }
            });
        }

        // 3. Tiny live ticking clock in top corner
        var clockEl = document.getElementById('ofp-live-clock');
        if (clockEl) {
            function updateClock() {
                var now = new Date();
                var options = {
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                };
                try {
                    clockEl.textContent = now.toLocaleDateString(undefined, options).replace(/,([^,]*)$/, ' •$1');
                } catch(e) {
                    clockEl.textContent = now.toUTCString();
                }
            }
            updateClock();
            setInterval(updateClock, 1000);
        }

        // 4. Testimonials Horizontal Auto-Slide Carousel (5000ms ease, no arrows)
        var track = document.getElementById('ofp-testimonials-track');
        var dots = document.querySelectorAll('#ofp-carousel-dots .ofp-carousel-dot');
        if (track && dots.length > 0) {
            var totalSlides = dots.length;
            var currentIndex = 0;
            var autoSlideTimer = null;

            function goToSlide(index) {
                currentIndex = (index + totalSlides) % totalSlides;
                track.style.transform = 'translateX(-' + (currentIndex * 100) + '%)';
                dots.forEach(function(dot, i) {
                    if (i === currentIndex) {
                        dot.classList.add('active');
                    } else {
                        dot.classList.remove('active');
                    }
                });
            }

            function startAutoSlide() {
                if (autoSlideTimer) clearInterval(autoSlideTimer);
                autoSlideTimer = setInterval(function() {
                    goToSlide(currentIndex + 1);
                }, 5000);
            }

            dots.forEach(function(dot) {
                dot.addEventListener('click', function() {
                    var idx = parseInt(this.getAttribute('data-index'), 10);
                    goToSlide(idx);
                    startAutoSlide(); // restart 5000ms cycle on manual tap
                });
            });

            // Pause slightly on hover so user can read review comfortably
            var carouselWrap = document.getElementById('ofp-testimonials-carousel');
            if (carouselWrap) {
                carouselWrap.addEventListener('mouseenter', function() {
                    if (autoSlideTimer) clearInterval(autoSlideTimer);
                });
                carouselWrap.addEventListener('mouseleave', function() {
                    startAutoSlide();
                });
            }

            startAutoSlide();
        }
    })();
    </script>

    <?php wp_footer(); ?>
</body>
</html>
