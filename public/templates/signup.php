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
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Create Account', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <style>
        body { display:flex; align-items:flex-start; justify-content:center; min-height:100vh; padding:40px 20px; overflow-y:auto !important; height:auto !important; }
        .ofp-signup-wrap { width:100%; max-width:520px; }
        .ofp-signup-brand { text-align:center; margin-bottom:28px; }
        .ofp-signup-brand h1 { font-size:26px; font-weight:800; color:#0f172a; }
        .ofp-signup-brand p  { color:#6b7280; font-size:14px; margin-top:6px; }
        .ofp-signup-card { background:#fff; border-radius:16px; padding:36px; border:1px solid #e5e7eb; box-shadow:0 4px 24px rgba(0,0,0,0.06); }
        .ofp-signup-card h2 { font-size:18px; font-weight:700; color:#0f172a; margin-bottom:20px; }
        .ofp-plan-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:16px; }
        .ofp-plan-option { border:2px solid #e5e7eb; border-radius:8px; padding:12px 8px; text-align:center; cursor:pointer; transition:border-color 0.15s; }
        .ofp-plan-option:has(input:checked) { border-color:#1a73e8; background:#eff6ff; }
        .ofp-plan-option input { display:none; }
        .ofp-plan-name  { font-weight:700; font-size:13px; color:#0f172a; }
        .ofp-plan-price { font-size:12px; color:#6b7280; margin-top:2px; }
        .ofp-plan-leads { font-size:11px; color:#9ca3af; }
        .ofp-checkbox-row { display:flex; align-items:flex-start; gap:10px; margin-bottom:12px; font-size:14px; color:#374151; cursor:pointer; }
        .ofp-checkbox-row input { margin-top:2px; flex-shrink:0; width:16px; height:16px; cursor:pointer; }
        .ofp-footer-link { text-align:center; margin-top:20px; font-size:13px; color:#6b7280; }
        .ofp-footer-link a { color:#1a73e8; text-decoration:none; }
        /* Wizard Steps */
        .ofp-signup-steps { display:flex; justify-content:space-between; margin-bottom:32px; position:relative; padding:0 20px; }
        .ofp-signup-steps-line { position:absolute; top:12px; left:30px; right:30px; height:2px; background:#e5e7eb; z-index:1; }
        .ofp-signup-steps-line-progress { position:absolute; top:12px; left:30px; width:0%; height:2px; background:#1a73e8; z-index:1; transition:width 0.4s ease; }
        .ofp-step-item { display:flex; flex-direction:column; align-items:center; z-index:2; background:#fff; padding:0 12px; }
        .ofp-step-dot { width:26px; height:26px; border-radius:50%; background:#e5e7eb; color:#6b7280; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; transition:0.3s; border:2px solid #fff; }
        .ofp-step-dot.active { background:#1a73e8; color:#fff; }
        .ofp-step-label { font-size:12px; font-weight:600; color:#6b7280; margin-top:8px; transition:0.3s; }
        .ofp-step-label.active { color:#1a73e8; }
        .ofp-step-content { display:none; animation:fadeIn 0.4s ease; }
        .ofp-step-content.active { display:block; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
        /* Custom Select Enhancements */
        .ofp-custom-select-options { max-height: 84px !important; overflow-y: auto !important; }
        .ofp-custom-select-trigger.invalid { border-color: #ef4444 !important; background-color: #fef2f2 !important; }
        
        .ofp-alert-success { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;}
        .ofp-alert-error { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body class="ofp-portal-body" style="background:#f0f4f8;">

<?php if ( $success ) : ?>

    <!-- Success State -->
    <div class="ofp-signup-wrap">
        <div class="ofp-signup-brand">
            <h1><?php echo esc_html( '⚡ ' . OFP_Brand::name() ); ?></h1>
        </div>
        <div class="ofp-signup-card" style="text-align:center;">
            <div style="font-size:52px;margin-bottom:16px;">🎉</div>
            <h2>Welcome, <?php echo esc_html( $owner_name ); ?>!</h2>
            <p style="color:#6b7280;line-height:1.7;margin-bottom:20px;">
                Your account is ready. We sent your temporary password to
                <strong><?php echo esc_html( sanitize_email( $_POST['email'] ?? '' ) ); ?></strong>.
                Log in with it, then change it from My Account.
            </p>
            <p style="color:#6b7280;font-size:13px;">
                You start on the Free plan. There is nothing to pay now.
                You can pick a paid plan later from Plans &amp; Pricing if you need more.
            </p>
            <a href="<?php echo esc_url( home_url( '/login' ) ); ?>"
               class="ofp-btn ofp-btn-secondary" style="margin-top:24px; padding: 10px; display:inline-block; border-radius:8px; text-decoration:none; background:#e5e7eb; color:#000;">
                Go to Login →
            </a>
        </div>
    </div>

<?php else : ?>

    <div class="ofp-signup-wrap">

        <div class="ofp-signup-brand">
            <h1><?php echo esc_html( '⚡ ' . OFP_Brand::name() ); ?></h1>
            <p>Your unified real estate sales & management platform</p>
        </div>

        <div class="ofp-signup-card">
            <h2>Create your account</h2>

            <?php if ( $error ) : ?>
                <div class="ofp-alert ofp-alert-error">
                    <?php echo esc_html( $error ); ?>
                </div>
            <?php endif; ?>

            <?php if ( $step === 'otp' ) : ?>
                <div class="ofp-alert ofp-alert-success">
                    A verification code has been sent to your email and phone.
                </div>

                <form method="POST" action="" id="ofp-signup-form">
                    <input type="hidden" name="otp_step" value="1">
                    <input type="hidden" name="signup_token" value="<?php echo esc_attr( $signup_token ); ?>">
                    <?php wp_nonce_field( 'ofp_signup', 'ofp_signup_nonce' ); ?>
                    <div style="position:absolute;left:-9999px;" aria-hidden="true">
                        <input type="text" name="ofp_website" tabindex="-1" autocomplete="off" value="">
                    </div>

                    <div class="ofp-field" style="margin-bottom: 20px;">
                        <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">7-Character Verification Code (OTP) <span style="color:red">*</span></label>
                        <input
                            type="text"
                            name="otp"
                            placeholder="e.g. A1B2C3D"
                            required
                            autofocus
                            style="width:100%; padding:12px 14px; border:1.5px solid #e5e7eb; border-radius:8px; font-size:15px;"
                        >
                    </div>

                    <button type="submit" style="width:100%; padding:13px; background:#1a73e8; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer;">
                        Verify & Create Account
                    </button>
                </form>

            <?php else : ?>

                <?php
                $base_domain = get_option( 'ofp_crm_base_domain' ) ?: ( isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d+$/', '', $_SERVER['HTTP_HOST'] ) : 'ofastpipeline.com' );
                ?>
                <form method="POST" action="" id="ofp-signup-form">
                    <?php wp_nonce_field( 'ofp_signup', 'ofp_signup_nonce' ); ?>
                    <input type="hidden" name="ofp_stamp" value="<?php echo esc_attr( $ofp_form_stamp() ); ?>">
                    <div style="position:absolute;left:-9999px;" aria-hidden="true">
                        <input type="text" name="ofp_website" tabindex="-1" autocomplete="off" value="">
                    </div>

                    <!-- STEP 1: Details -->
                    <div id="step-1" class="ofp-step-content active">

                        <!-- Business details -->
                        <div class="ofp-field" style="margin-bottom: 15px;">
                            <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">Business Name <span style="color:red">*</span></label>
                            <input type="text" name="business_name" required
                                   value="<?php echo esc_attr( sanitize_text_field( $_POST['business_name'] ?? '' ) ); ?>"
                                   placeholder="e.g. Lekki Homes Realty" style="width:100%; padding:12px 14px; border:1.5px solid #e5e7eb; border-radius:8px; font-size:15px;">
                        </div>

                        <div class="ofp-field" style="margin-bottom: 15px;">
                            <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">
                                Choose Your Portal Subdomain <span style="color:red">*</span>
                            </label>
                            <div style="display:flex; align-items:center; border:1.5px solid #e5e7eb; border-radius:8px; background:#fff; overflow:hidden;">
                                <input type="text" name="subdomain" id="ofp-subdomain-input" required
                                       value="<?php echo esc_attr( sanitize_title( $_POST['subdomain'] ?? '' ) ); ?>"
                                       placeholder="e.g. lekkihomes"
                                       pattern="[a-z0-9-]+"
                                       minlength="3"
                                       style="flex:1; border:none; padding:12px 14px; font-size:15px; outline:none; background:transparent;">
                                <span style="padding:0 14px; color:#6b7280; font-size:14px; background:#f9fafb; border-left:1px solid #e5e7eb; height:46px; display:flex; align-items:center;">
                                    .<?php echo esc_html( $base_domain ); ?>
                                </span>
                            </div>
                            <p style="font-size:12px; color:#6b7280; margin-top:4px;">
                                Your agency's direct address: <strong id="ofp-subdomain-preview">https://youragency.<?php echo esc_html( $base_domain ); ?></strong>
                            </p>
                        </div>

                        <div class="ofp-field" style="margin-bottom: 15px;">
                            <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">Your Full Name <span style="color:red">*</span></label>
                            <input type="text" name="owner_name" required
                                   value="<?php echo esc_attr( sanitize_text_field( $_POST['owner_name'] ?? '' ) ); ?>"
                                   placeholder="e.g. Adewale Johnson" style="width:100%; padding:12px 14px; border:1.5px solid #e5e7eb; border-radius:8px; font-size:15px;">
                        </div>

                        <div class="ofp-field" style="margin-bottom: 15px;">
                            <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">Email Address <span style="color:red">*</span></label>
                            <input type="email" name="email" required
                                   value="<?php echo esc_attr( sanitize_email( $_POST['email'] ?? '' ) ); ?>"
                                   placeholder="you@example.com" autocomplete="email" style="width:100%; padding:12px 14px; border:1.5px solid #e5e7eb; border-radius:8px; font-size:15px;">
                            <p style="font-size:12px; color:#6b7280; margin-top:4px;">Your login credentials will be sent here.</p>
                        </div>

                        <div class="ofp-field" style="margin-bottom: 25px;">
                            <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">Phone Number <span style="color:red">*</span></label>
                            <input type="tel" name="phone" required
                                   value="<?php echo esc_attr( sanitize_text_field( $_POST['phone'] ?? '' ) ); ?>"
                                   placeholder="e.g. 08012345678" style="width:100%; padding:12px 14px; border:1.5px solid #e5e7eb; border-radius:8px; font-size:15px;">
                        </div>

                        <button type="submit" style="width:100%; padding:13px; background:#1a73e8; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:600; cursor:pointer;">
                            Continue
                        </button>
                    </div> <!-- End Step 1 -->

                </form>

                <script>
                (function() {
                    var bizInput = document.querySelector('input[name="business_name"]');
                    var subInput = document.getElementById('ofp-subdomain-input');
                    var preview  = document.getElementById('ofp-subdomain-preview');
                    var baseDomain = <?php echo json_encode( $base_domain ); ?>;
                    var userModified = false;

                    if (!bizInput || !subInput) return;

                    subInput.addEventListener('input', function() {
                        userModified = true;
                        updatePreview();
                    });

                    bizInput.addEventListener('input', function() {
                        if (!userModified && (!subInput.value || subInput.value === slugify(bizInput.dataset.lastVal || ''))) {
                            subInput.value = slugify(bizInput.value);
                            updatePreview();
                        }
                        bizInput.dataset.lastVal = bizInput.value;
                    });

                    function slugify(text) {
                        return (text || '').toString().toLowerCase()
                            .trim()
                            .replace(/\s+/g, '-')
                            .replace(/[^a-z0-9\-]+/g, '')
                            .replace(/\-\-+/g, '-')
                            .replace(/^-+/, '')
                            .replace(/-+$/, '');
                    }

                    function updatePreview() {
                        var slug = slugify(subInput.value) || 'youragency';
                        if (preview) {
                            preview.textContent = 'https://' + slug + '.' + baseDomain;
                        }
                    }

                    if (bizInput.value && !subInput.value) {
                        subInput.value = slugify(bizInput.value);
                    }
                    updatePreview();
                })();
                </script>

            <?php endif; ?>
        </div>

        <div class="ofp-footer-link">
            Already have an account? <a href="<?php echo esc_url( home_url( '/login' ) ); ?>">Log in →</a>
        </div>

    </div>

<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
