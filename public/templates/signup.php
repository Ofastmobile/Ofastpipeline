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

$error   = '';
$success = false;
$step    = 'details'; // 'details' or 'otp'
$user_email = '';
$user_phone = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {

    OFP_Security::check_rate_limit( OFP_Security::get_client_ip(), 'signup', 10, 600 );

    $business_name = sanitize_text_field( wp_unslash( $_POST['business_name']  ?? '' ) );
    $owner_name    = sanitize_text_field( wp_unslash( $_POST['owner_name']     ?? '' ) );
    $email         = sanitize_email(      wp_unslash( $_POST['email']          ?? '' ) );
    $phone         = OFP_Security::sanitize_phone( wp_unslash( $_POST['phone'] ?? '' ) );
    $plan          = sanitize_text_field( wp_unslash( $_POST['plan']           ?? 'free' ) );
    $subdomain     = sanitize_title(      wp_unslash( $_POST['subdomain']      ?? '' ) );
    if ( empty( $subdomain ) && ! empty( $business_name ) ) {
        $subdomain = sanitize_title( $business_name );
    }

    if ( isset( $_POST['otp_step'] ) && $_POST['otp_step'] === '1' ) {
        // --- STEP 2: Verify OTP and Create Account ---
        $step = 'otp';
        $otp = isset( $_POST['otp'] ) ? sanitize_text_field( wp_unslash( $_POST['otp'] ) ) : '';

        if ( empty( $otp ) ) {
            $error = 'Please enter the verification code.';
            $user_email = $email;
            $user_phone = $phone;
        } else {
            if ( OFP_Auth::verify_otp( $email, $otp, 'signup' ) ) {
                $subscriptions = [ 'crm', 'listing' ];

                $client_id = OFP_Client::create( [
                    'business_name'     => $business_name,
                    'owner_name'        => $owner_name,
                    'email'             => $email,
                    'phone'             => $phone,
                    'subdomain'         => $subdomain,
                    'business_category' => 'property',
                    'plan'              => $plan,
                    'listing_plan'      => $plan,
                    'subscriptions'     => $subscriptions,
                    'onboarding_source' => 'self_serve',
                ] );

                if ( $client_id ) {
                    $success = true;
                } else {
                    $error = 'Something went wrong creating your account. Please try again or contact us.';
                    $step = 'details'; // allow retry
                }
            } else {
                $error = 'Invalid or expired verification code.';
                $user_email = $email;
                $user_phone = $phone;
            }
        }
    } else {
        // --- STEP 1: Validate Details & Send OTP ---
        if ( ! $business_name || ! $owner_name || ! $email || ! $phone ) {
            $error = 'Please fill in all required fields.';
        } elseif ( ! is_email( $email ) ) {
            $error = 'Please enter a valid email address.';
        } elseif ( ! OFP_Security::is_valid_phone( $phone ) ) {
            $error = 'Please enter a valid phone number.';
        } elseif ( OFP_Client::email_exists( $email ) ) {
            $error = 'An account with this email address already exists. Please log in instead.';
        } elseif ( empty( $subdomain ) || strlen( $subdomain ) < 3 ) {
            $error = 'Please enter a portal subdomain of at least 3 characters.';
        } elseif ( class_exists( 'OFP_Host_Router' ) && OFP_Host_Router::is_reserved( $subdomain ) ) {
            $error = 'That subdomain is reserved by the system. Please choose another.';
        } elseif ( class_exists( 'OFP_Client' ) && OFP_Client::subdomain_exists( $subdomain ) ) {
            $error = 'That subdomain is already taken. Please choose another.';
        } else {
            // Generate OTP
            OFP_Auth::generate_and_send_otp( $email, $phone, 'signup' );
            $step = 'otp';
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
    <title>Create Account — OFast Pipeline</title>
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
            <h1>⚡ OFast Pipeline</h1>
        </div>
        <div class="ofp-signup-card" style="text-align:center;">
            <div style="font-size:52px;margin-bottom:16px;">🎉</div>
            <h2>Account Created!</h2>
            <p style="color:#6b7280;line-height:1.7;margin-bottom:20px;">
                Your account is being reviewed. We will email you at
                <strong><?php echo esc_html( sanitize_email( $_POST['email'] ?? '' ) ); ?></strong>
                once approved — usually within 24 hours.
            </p>
            <p style="color:#6b7280;font-size:13px;">
                Check your inbox for a welcome email with your login credentials
                and payment details to activate your subscription.
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
            <h1>⚡ OFast Pipeline</h1>
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
                    <input type="hidden" name="business_name" value="<?php echo esc_attr( $_POST['business_name'] ); ?>">
                    <input type="hidden" name="owner_name" value="<?php echo esc_attr( $_POST['owner_name'] ); ?>">
                    <input type="hidden" name="email" value="<?php echo esc_attr( $_POST['email'] ); ?>">
                    <input type="hidden" name="phone" value="<?php echo esc_attr( $_POST['phone'] ); ?>">
                    <input type="hidden" name="plan" value="<?php echo esc_attr( $_POST['plan'] ?? 'free' ); ?>">
                    <input type="hidden" name="subdomain" value="<?php echo esc_attr( $subdomain ); ?>">

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
