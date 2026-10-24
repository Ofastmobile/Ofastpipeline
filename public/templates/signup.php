<?php
/**
 * Template: /signup
 * Self-serve client onboarding (v2.1).
 *
 * OTP Flow:
 * 1. User submits details.
 * 2. System validates, generates & sends OTP.
 * 3. User submits OTP.
 * 4. System verifies OTP and creates account.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// A white label (solo company) domain has no public signup. Go to login.
if ( class_exists( 'OFP_Brand' ) && OFP_Brand::is_solo_host() ) {
    wp_redirect( home_url( '/login' ) );
    exit;
}

$error   = '';
$success = false;
$step    = 'details'; // 'details' or 'otp'
$user_email = '';
$user_phone = '';
$signup_token = '';

/**
 * Signup protection:
 *  - a page security token (nonce) on every form
 *  - a hidden trap field that only bots fill in
 *  - a minimum fill time, so scripts that post instantly are refused
 *  - limits on how many codes one email, one phone and the whole site can request
 *  - the details are kept on the server between step 1 and step 2, so nothing
 *    can be changed after the code is sent
 *  - only 5 wrong codes, then the person must start again
 *  - every check is repeated at step 2 before the account is made
 */
$ofp_form_stamp = static function (): string {
    $t = (string) time();
    return $t . '.' . hash_hmac( 'sha256', $t, wp_salt( 'auth' ) );
};
$ofp_stamp_age = static function ( string $stamp ): int {
    $parts = explode( '.', $stamp, 2 );
    if ( count( $parts ) !== 2 || ! ctype_digit( $parts[0] ) ) {
        return -1;
    }
    if ( ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'auth' ) ), $parts[1] ) ) {
        return -1;
    }
    return time() - (int) $parts[0];
};
$ofp_hit_limit = static function ( string $key, int $max, int $window ): bool {
    $k = 'ofp_su_' . md5( $key );
    $n = (int) get_transient( $k );
    if ( $n >= $max ) {
        return true;
    }
    set_transient( $k, $n + 1, $window );
    return false;
};
$ofp_check_details = static function ( string $business_name, string $owner_name, string $email, string $phone, string $subdomain ): string {
    if ( ! $business_name || ! $owner_name || ! $email || ! $phone ) {
        return 'Please fill in all required fields.';
    }
    if ( strlen( $business_name ) > 100 || strlen( $owner_name ) > 100 || strlen( $email ) > 150 ) {
        return 'One of your entries is too long. Please shorten it.';
    }
    if ( ! is_email( $email ) ) {
        return 'Please enter a valid email address.';
    }
    if ( ! OFP_Security::is_valid_phone( $phone ) ) {
        return 'Please enter a valid phone number.';
    }
    if ( OFP_Client::email_exists( $email ) ) {
        return 'An account with this email address already exists. Please log in instead.';
    }
    if ( strlen( $subdomain ) < 3 || strlen( $subdomain ) > 40 ) {
        return 'Please enter a portal subdomain of 3 to 40 characters.';
    }
    if ( ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $subdomain ) ) {
        return 'The subdomain can only use letters, numbers and single hyphens.';
    }
    if ( class_exists( 'OFP_Host_Router' ) && OFP_Host_Router::is_reserved( $subdomain ) ) {
        return 'That subdomain is reserved by the system. Please choose another.';
    }
    if ( class_exists( 'OFP_Client' ) && OFP_Client::subdomain_exists( $subdomain ) ) {
        return 'That subdomain is already taken. Please choose another.';
    }
    return '';
};

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {

    OFP_Security::check_rate_limit( OFP_Security::get_client_ip(), 'signup', 10, 600 );

    $nonce_ok = isset( $_POST['ofp_signup_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ofp_signup_nonce'] ) ), 'ofp_signup' );
    $trap_ok  = empty( $_POST['ofp_website'] ); // real people never see this field

    if ( ! $nonce_ok ) {
        $error = 'This page has expired. Please reload it and try again.';
    } elseif ( ! $trap_ok ) {
        $error = 'We could not process this request. Please try again.';
    } elseif ( isset( $_POST['otp_step'] ) && $_POST['otp_step'] === '1' ) {

        // --- STEP 2: Verify OTP and Create Account ---
        $step         = 'otp';
        $signup_token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) wp_unslash( $_POST['signup_token'] ?? '' ) );
        $saved        = $signup_token ? get_transient( 'ofp_signup_' . $signup_token ) : false;
        $otp          = isset( $_POST['otp'] ) ? sanitize_text_field( wp_unslash( $_POST['otp'] ) ) : '';

        if ( ! is_array( $saved ) ) {
            $error = 'Your sign up session has expired. Please start again.';
            $step  = 'details';
        } elseif ( empty( $otp ) ) {
            $error      = 'Please enter the verification code.';
            $user_email = $saved['email'];
            $user_phone = $saved['phone'];
        } elseif ( (int) $saved['attempts'] >= 5 ) {
            delete_transient( 'ofp_signup_' . $signup_token );
            $error = 'Too many wrong codes. Please start again.';
            $step  = 'details';
        } elseif ( ! OFP_Auth::verify_otp( $saved['email'], $otp, 'signup' ) ) {
            $saved['attempts'] = (int) $saved['attempts'] + 1;
            set_transient( 'ofp_signup_' . $signup_token, $saved, 15 * MINUTE_IN_SECONDS );
            $error      = 'Invalid or expired verification code.';
            $user_email = $saved['email'];
            $user_phone = $saved['phone'];
        } else {
            // The code is right. Use ONLY the details saved on the server, and
            // check everything again in case something changed meanwhile.
            $business_name = $saved['business_name'];
            $owner_name    = $saved['owner_name'];
            $email         = $saved['email'];
            $phone         = $saved['phone'];
            $subdomain     = $saved['subdomain'];

            $again = $ofp_check_details( $business_name, $owner_name, $email, $phone, $subdomain );
            if ( $again ) {
                delete_transient( 'ofp_signup_' . $signup_token );
                $error = $again;
                $step  = 'details';
            } else {
                $client_id = OFP_Client::create( [
                    'business_name'     => $business_name,
                    'owner_name'        => $owner_name,
                    'email'             => $email,
                    'phone'             => $phone,
                    'subdomain'         => $subdomain,
                    'business_category' => 'property',
                    // Everyone starts on the Free plan. A paid plan is chosen later
                    // from Plans & Pricing. Nothing sent by the form can change this.
                    'plan'              => 'free',
                    'listing_plan'      => 'free',
                    'subscriptions'     => [ 'crm', 'listing' ],
                    'onboarding_source' => 'self_serve',
                ] );

                delete_transient( 'ofp_signup_' . $signup_token );

                if ( $client_id ) {
                    $success = true;
                    $_POST['email'] = $email; // used by the welcome screen
                } else {
                    $error = 'Something went wrong creating your account. Please try again or contact us.';
                    $step  = 'details';
                }
            }
        }

    } else {

        // --- STEP 1: Validate Details & Send OTP ---
        $business_name = sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) );
        $owner_name    = sanitize_text_field( wp_unslash( $_POST['owner_name']    ?? '' ) );
        $email         = sanitize_email(      wp_unslash( $_POST['email']         ?? '' ) );
        $phone         = OFP_Security::sanitize_phone( wp_unslash( $_POST['phone'] ?? '' ) );
        $subdomain     = sanitize_title(      wp_unslash( $_POST['subdomain']     ?? '' ) );
        if ( empty( $subdomain ) && ! empty( $business_name ) ) {
            $subdomain = sanitize_title( $business_name );
        }

        $age = $ofp_stamp_age( (string) wp_unslash( $_POST['ofp_stamp'] ?? '' ) );

        if ( $age < 2 || $age > 12 * HOUR_IN_SECONDS ) {
            $error = $age < 0 ? 'This page has expired. Please reload it and try again.' : 'That was too fast. Please check your details and try again.';
        } else {
            $error = $ofp_check_details( $business_name, $owner_name, $email, $phone, $subdomain );
        }

        if ( ! $error && ! OFP_Security::verify_turnstile_request() ) {
            $error = 'Please complete the security check and try again.';
        }

        if ( ! $error ) {
            if (
                $ofp_hit_limit( 'email|' . strtolower( $email ), 3, HOUR_IN_SECONDS ) ||
                $ofp_hit_limit( 'phone|' . $phone, 3, HOUR_IN_SECONDS ) ||
                $ofp_hit_limit( 'day|' . gmdate( 'Y-m-d' ), 500, DAY_IN_SECONDS )
            ) {
                $error = 'Too many codes have been requested. Please try again later.';
            }
        }

        if ( ! $error ) {
            // Keep the details on the server. The next page only gets a random token.
            $signup_token = wp_generate_password( 32, false, false );
            set_transient( 'ofp_signup_' . $signup_token, [
                'business_name' => $business_name,
                'owner_name'    => $owner_name,
                'email'         => $email,
                'phone'         => $phone,
                'subdomain'     => $subdomain,
                'attempts'      => 0,
            ], 15 * MINUTE_IN_SECONDS );

            OFP_Auth::generate_and_send_otp( $email, $phone, 'signup' );
            $step       = 'otp';
            $user_email = $email;
            $user_phone = $phone;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Create Agency Account', $client ?? null ) ); ?></title>
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
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ofp-split-logo-badge">
                            <span class="ofp-split-logo-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </span>
                            <span class="ofp-split-logo-text"><?php echo esc_html( OFP_Brand::name() ); ?></span>
                        </a>
                    </div>

                    <?php if ( $success ) : ?>

                        <!-- Success State -->
                        <div class="ofp-success-panel">
                            <div class="ofp-success-icon-badge">🎉</div>
                            <h2>Welcome, <?php echo esc_html( $owner_name ); ?>!</h2>
                            <p>
                                Your agency account has been created successfully. We sent your temporary login credentials to
                                <strong><?php echo esc_html( sanitize_email( $_POST['email'] ?? '' ) ); ?></strong>.
                            </p>
                            <p style="font-size:12.5px;">
                                You start on the Free plan with zero upfront charges. You can upgrade or add listings anytime from your portal dashboard.
                            </p>
                            <div style="margin-top:24px;">
                                <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" class="ofp-split-submit-btn" style="text-decoration:none; display:inline-flex;">
                                    <span>Proceed to Login</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                                </a>
                            </div>
                        </div>

                    <?php else : ?>

                        <!-- Heading & Step Badge -->
                        <div class="ofp-split-heading">
                            <?php if ( $step === 'otp' ) : ?>
                                <span class="ofp-step-badge">Step 2 of 2: Security Verification</span>
                                <h2>Verify your contact</h2>
                                <p>Enter the 7-character verification code sent to your email and phone.</p>
                            <?php else : ?>
                                <span class="ofp-step-badge">Step 1 of 2: Agency Details</span>
                                <h2>Create your account</h2>
                                <p>Launch your automated real estate pipeline. Start on the Free plan.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Alerts -->
                        <?php if ( $error ) : ?>
                            <div class="ofp-alert error" style="margin-bottom:18px;">
                                <?php echo esc_html( $error ); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $step === 'otp' ) : ?>

                            <!-- STEP 2: OTP Verification Form -->
                            <div class="ofp-alert success" style="margin-bottom:18px;">
                                Verification code sent to <?php echo esc_html( $user_email ); ?> and phone.
                            </div>

                            <form method="POST" action="<?php echo esc_url( home_url( '/signup' ) ); ?>" id="ofp-signup-form" class="ofp-auth-form" novalidate>
                                <input type="hidden" name="otp_step" value="1">
                                <input type="hidden" name="signup_token" value="<?php echo esc_attr( $signup_token ); ?>">
                                <?php wp_nonce_field( 'ofp_signup', 'ofp_signup_nonce' ); ?>
                                <div style="position:absolute;left:-9999px;" aria-hidden="true">
                                    <input type="text" name="ofp_website" tabindex="-1" autocomplete="off" value="">
                                </div>

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
                                    <span>Verify &amp; Create Account</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                                </button>

                                <div class="ofp-split-alt-action">
                                    <a href="<?php echo esc_url( home_url( '/signup' ) ); ?>" class="ofp-link-subtle">← Edit details and resend</a>
                                </div>
                            </form>

                        <?php else : ?>

                            <?php
                            $base_domain = get_option( 'ofp_crm_base_domain' )
                                ?: ( isset( $_SERVER['HTTP_HOST'] )
                                    ? preg_replace( '/:\d+$/', '', $_SERVER['HTTP_HOST'] )
                                    : 'ofastpipeline.com' );
                            ?>

                            <!-- STEP 1: Details Form -->
                            <form method="POST" action="<?php echo esc_url( home_url( '/signup' ) ); ?>" id="ofp-signup-form" class="ofp-auth-form" novalidate>
                                <?php wp_nonce_field( 'ofp_signup', 'ofp_signup_nonce' ); ?>
                                <input type="hidden" name="ofp_stamp" value="<?php echo esc_attr( $ofp_form_stamp() ); ?>">
                                <div style="position:absolute;left:-9999px;" aria-hidden="true">
                                    <input type="text" name="ofp_website" tabindex="-1" autocomplete="off" value="">
                                </div>

                                <!-- Business Name -->
                                <div class="ofp-input-group">
                                    <label for="ofp-biz-name">Agency / Business Name</label>
                                    <div class="ofp-input-wrap">
                                        <span class="ofp-input-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
                                        </span>
                                        <input
                                            type="text"
                                            id="ofp-biz-name"
                                            name="business_name"
                                            value="<?php echo esc_attr( sanitize_text_field( $_POST['business_name'] ?? '' ) ); ?>"
                                            placeholder="e.g. Lekki Homes Realty"
                                            class="ofp-input-styled"
                                            required
                                        >
                                    </div>
                                </div>

                                <!-- Subdomain -->
                                <div class="ofp-input-group">
                                    <label for="ofp-subdomain-input">Choose Your Portal Subdomain</label>
                                    <div class="ofp-subdomain-wrap">
                                        <span class="ofp-subdomain-prefix-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-.778.099-1.533.284-2.253"/></svg>
                                        </span>
                                        <input
                                            type="text"
                                            name="subdomain"
                                            id="ofp-subdomain-input"
                                            value="<?php echo esc_attr( sanitize_title( $_POST['subdomain'] ?? '' ) ); ?>"
                                            placeholder="lekkihomes"
                                            pattern="[a-z0-9-]+"
                                            minlength="3"
                                            class="ofp-subdomain-input"
                                            required
                                        >
                                        <span class="ofp-subdomain-suffix">.<?php echo esc_html( $base_domain ); ?></span>
                                    </div>
                                    <div class="ofp-subdomain-preview-note">
                                        Your direct portal: <strong id="ofp-subdomain-preview">https://youragency.<?php echo esc_html( $base_domain ); ?></strong>
                                    </div>
                                </div>

                                <!-- Owner Name -->
                                <div class="ofp-input-group">
                                    <label for="ofp-owner-name">Your Full Name</label>
                                    <div class="ofp-input-wrap">
                                        <span class="ofp-input-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                                        </span>
                                        <input
                                            type="text"
                                            id="ofp-owner-name"
                                            name="owner_name"
                                            value="<?php echo esc_attr( sanitize_text_field( $_POST['owner_name'] ?? '' ) ); ?>"
                                            placeholder="e.g. Adewale Johnson"
                                            class="ofp-input-styled"
                                            required
                                        >
                                    </div>
                                </div>

                                <!-- Email -->
                                <div class="ofp-input-group">
                                    <label for="ofp-email">Work Email Address</label>
                                    <div class="ofp-input-wrap">
                                        <span class="ofp-input-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                                        </span>
                                        <input
                                            type="email"
                                            id="ofp-email"
                                            name="email"
                                            value="<?php echo esc_attr( sanitize_email( $_POST['email'] ?? '' ) ); ?>"
                                            placeholder="adewale@lekkihomes.com"
                                            class="ofp-input-styled"
                                            required
                                            autocomplete="email"
                                        >
                                    </div>
                                    <div class="ofp-field-hint">Your login credentials and account verification will be delivered here.</div>
                                </div>

                                <!-- Phone Number -->
                                <div class="ofp-input-group">
                                    <label for="ofp-phone">Phone Number (SMS &amp; Voice Relay)</label>
                                    <div class="ofp-input-wrap">
                                        <span class="ofp-input-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                                        </span>
                                        <input
                                            type="tel"
                                            id="ofp-phone"
                                            name="phone"
                                            value="<?php echo esc_attr( sanitize_text_field( $_POST['phone'] ?? '' ) ); ?>"
                                            placeholder="e.g. 08012345678"
                                            class="ofp-input-styled"
                                            required
                                            autocomplete="tel"
                                        >
                                    </div>
                                </div>

                                <?php echo OFP_Security::turnstile_widget(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

                                <button type="submit" class="ofp-split-submit-btn" style="margin-top:10px;">
                                    <span>Create Free Agency Portal</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                                </button>
                            </form>

                        <?php endif; ?>

                        <!-- Footer Note -->
                        <div class="ofp-split-panel-footer">
                            <p class="ofp-register-prompt">
                                Already have an account?
                                <a href="<?php echo esc_url( home_url( '/login' ) ); ?>">Log in &rarr;</a>
                            </p>
                        </div>

                    <?php endif; ?>

                </div>
            </div>

            <!-- RIGHT PANEL: High-Conversion PropTech Showcase -->
            <div class="ofp-split-showcase-panel">
                <div class="ofp-showcase-content">

                    <div>
                        <div class="ofp-showcase-pill">
                            <span class="ofp-showcase-sparkle">&#10022;</span>
                            <span><?php echo esc_html( OFP_Brand::context_name() ); ?> Agency Onboarding</span>
                        </div>

                        <h3 class="ofp-showcase-headline">
                            Scale your real estate agency with automated pipelines.
                        </h3>

                        <p class="ofp-showcase-subhead">
                            Everything you need to capture, nurture, and close property buyers—delivered on autopilot.
                        </p>

                        <!-- Key Feature Perks -->
                        <ul class="ofp-perks-list">
                            <li class="ofp-perk-item">
                                <span class="ofp-perk-check">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                </span>
                                <span>Instant CRM &amp; automated lead assignment</span>
                            </li>
                            <li class="ofp-perk-item">
                                <span class="ofp-perk-check">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                </span>
                                <span>Under 60s SMS follow-ups &amp; auto voice relay</span>
                            </li>
                            <li class="ofp-perk-item">
                                <span class="ofp-perk-check">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                </span>
                                <span>Buyer installment schedules &amp; payment tracking</span>
                            </li>
                            <li class="ofp-perk-item">
                                <span class="ofp-perk-check">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                </span>
                                <span>Unified client portal for your buyers &amp; tenants</span>
                            </li>
                        </ul>

                        <!-- Key Metrics Grid -->
                        <div class="ofp-showcase-stats-grid">
                            <div class="ofp-stat-card">
                                <span class="ofp-stat-val">&#8358;0</span>
                                <span class="ofp-stat-lbl">Free Tier</span>
                            </div>
                            <div class="ofp-stat-card">
                                <span class="ofp-stat-val">&lt; 2m</span>
                                <span class="ofp-stat-lbl">Setup Time</span>
                            </div>
                            <div class="ofp-stat-card">
                                <span class="ofp-stat-val">24/7</span>
                                <span class="ofp-stat-lbl">Auto Relay</span>
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
                                        "Setting up took less than two minutes. The automated SMS lead follow-ups and installment management saved us countless hours and closed multiple properties for our agency."
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
        // 1. Theme toggle
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

        // 2. Tiny live ticking clock in top corner
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

        // 3. Testimonials Horizontal Auto-Slide Carousel (5000ms ease, no arrows)
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
                    startAutoSlide();
                });
            });

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
