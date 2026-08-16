<?php
/**
 * Template: /signup
 * Self-serve client onboarding (v2.1).
 *
 * Security:
 *  - Rate limited: 3 signups per IP per 10 minutes
 *  - Duplicate email check
 *  - Self-serve accounts start as 'pending_review' — admin must approve
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$error   = '';
$success = false;

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {

    OFP_Security::check_rate_limit( OFP_Security::get_client_ip(), 'signup', 3, 600 );

    $business_name = sanitize_text_field( wp_unslash( $_POST['business_name']  ?? '' ) );
    $owner_name    = sanitize_text_field( wp_unslash( $_POST['owner_name']     ?? '' ) );
    $email         = sanitize_email(      wp_unslash( $_POST['email']          ?? '' ) );
    $phone         = OFP_Security::sanitize_phone( wp_unslash( $_POST['phone'] ?? '' ) );
    $category      = sanitize_text_field( wp_unslash( $_POST['business_category'] ?? '' ) );
    $plan          = sanitize_text_field( wp_unslash( $_POST['plan']           ?? 'starter' ) );
    $listing_plan  = sanitize_text_field( wp_unslash( $_POST['listing_plan']   ?? 'free' ) );
    $want_crm      = ! empty( $_POST['want_crm'] );
    $want_listing  = ! empty( $_POST['want_listing'] );

    if ( ! $business_name || ! $owner_name || ! $email || ! $phone ) {
        $error = 'Please fill in all required fields.';
    } elseif ( ! is_email( $email ) ) {
        $error = 'Please enter a valid email address.';
    } elseif ( ! OFP_Security::is_valid_phone( $phone ) ) {
        $error = 'Please enter a valid phone number.';
    } elseif ( OFP_Client::email_exists( $email ) ) {
        $error = 'An account with this email address already exists. Please log in instead.';
    } elseif ( ! $want_crm && ! $want_listing ) {
        $error = 'Please select at least one subscription type.';
    } else {
        $subscriptions = [];
        if ( $want_crm )     $subscriptions[] = 'crm';
        if ( $want_listing ) $subscriptions[] = 'listing';

        $client_id = OFP_Client::create( [
            'business_name'     => $business_name,
            'owner_name'        => $owner_name,
            'email'             => $email,
            'phone'             => $phone,
            'business_category' => $category,
            'plan'              => $want_crm ? $plan : null,
            'listing_plan'      => $want_listing ? $listing_plan : null,
            'subscriptions'     => $subscriptions,
            'onboarding_source' => 'self_serve',
        ] );

        if ( $client_id ) {
            $success = true;
        } else {
            $error = 'Something went wrong creating your account. Please try again or contact us.';
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

        /* Step 2 Redesign */
        .ofp-service-cards { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px; }
        @media (max-width: 480px) { .ofp-service-cards { grid-template-columns:1fr; } }
        .ofp-service-card { border:2px solid #e5e7eb; border-radius:12px; padding:20px; cursor:pointer; transition:all 0.2s ease; position:relative; background:#fff; display:flex; flex-direction:column; align-items:center; text-align:center; }
        .ofp-service-card:hover { border-color:#cbd5e1; transform:translateY(-2px); box-shadow:0 10px 15px -3px rgba(0,0,0,0.05); }
        .ofp-service-card:has(input:checked) { border-color:#1a73e8; background:#f0f6ff; }
        .ofp-service-card input { position:absolute; opacity:0; pointer-events:none; }
        .ofp-service-icon { font-size:32px; margin-bottom:12px; }
        .ofp-service-title { font-size:15px; font-weight:700; color:#0f172a; margin-bottom:6px; }
        .ofp-service-desc { font-size:12px; color:#64748b; line-height:1.4; }
        
        .ofp-service-check { position:absolute; top:12px; right:12px; width:20px; height:20px; border-radius:50%; border:2px solid #cbd5e1; display:flex; align-items:center; justify-content:center; transition:0.2s; background:#fff; }
        .ofp-service-check svg { width:10px; height:10px; fill:#fff; opacity:0; transition:0.2s; }
        .ofp-service-card:has(input:checked) .ofp-service-check { background:#1a73e8; border-color:#1a73e8; }
        .ofp-service-card:has(input:checked) .ofp-service-check svg { opacity:1; }

        .ofp-plan-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:20px; }
        @media (max-width: 540px) { .ofp-plan-grid { grid-template-columns:1fr; } }
        .ofp-plan-option { border:2px solid #e5e7eb; border-radius:12px; padding:16px 12px; text-align:center; cursor:pointer; transition:all 0.2s ease; background:#fff; position:relative; overflow:hidden; display:flex; flex-direction:column; align-items:center; justify-content:center; }
        .ofp-plan-option:hover { transform:translateY(-2px); box-shadow:0 8px 12px -3px rgba(0,0,0,0.05); border-color:#cbd5e1; }
        .ofp-plan-option:has(input:checked) { border-color:#1a73e8; background:#1a73e8; transform:translateY(-2px); box-shadow:0 8px 16px -4px rgba(26,115,232,0.3); }
        .ofp-plan-option input { position:absolute; opacity:0; }
        .ofp-plan-name { font-weight:800; font-size:14px; color:#0f172a; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.5px; transition:color 0.2s; }
        .ofp-plan-option:has(input:checked) .ofp-plan-name { color:#fff; }
        .ofp-plan-price { font-size:16px; font-weight:700; color:#334155; margin-bottom:4px; transition:color 0.2s; }
        .ofp-plan-option:has(input:checked) .ofp-plan-price { color:#f8fafc; }
        .ofp-plan-leads { font-size:11px; color:#64748b; font-weight:500; transition:color 0.2s; }
        .ofp-plan-option:has(input:checked) .ofp-plan-leads { color:#bfdbfe; }
        .ofp-section-fade { animation:fadeIn 0.3s ease; }
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
               class="ofp-btn ofp-btn-secondary" style="margin-top:24px;">
                Go to Login →
            </a>
        </div>
    </div>

<?php else : ?>

    <div class="ofp-signup-wrap">

        <div class="ofp-signup-brand">
            <h1>⚡ OFast Pipeline</h1>
            <p>Done-for-you lead automation for Nigerian businesses</p>
        </div>

        <div class="ofp-signup-card">
            <h2>Create your account</h2>

            <?php if ( $error ) : ?>
                <div class="ofp-alert ofp-alert-error" style="margin-bottom:20px;">
                    <?php echo esc_html( $error ); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="ofp-signup-form">

                <!-- Step Indicator -->
                <div class="ofp-signup-steps">
                    <div class="ofp-signup-steps-line"></div>
                    <div class="ofp-signup-steps-line-progress" id="step-progress"></div>
                    <div class="ofp-step-item">
                        <div class="ofp-step-dot active" id="dot-1">1</div>
                        <div class="ofp-step-label active" id="label-1">Details</div>
                    </div>
                    <div class="ofp-step-item">
                        <div class="ofp-step-dot" id="dot-2">2</div>
                        <div class="ofp-step-label" id="label-2">Plan</div>
                    </div>
                </div>

                <!-- STEP 1: Details -->
                <div id="step-1" class="ofp-step-content active">

                <!-- Business details -->
                <div class="ofp-field">
                    <label>Business Name <span class="required">*</span></label>
                    <input type="text" name="business_name" required
                           value="<?php echo esc_attr( sanitize_text_field( $_POST['business_name'] ?? '' ) ); ?>"
                           placeholder="e.g. Lekki Homes Realty">
                </div>

                <div class="ofp-field">
                    <label>Your Full Name <span class="required">*</span></label>
                    <input type="text" name="owner_name" required
                           value="<?php echo esc_attr( sanitize_text_field( $_POST['owner_name'] ?? '' ) ); ?>"
                           placeholder="e.g. Adewale Johnson">
                </div>

                <div class="ofp-field">
                    <label>Email Address <span class="required">*</span></label>
                    <input type="email" name="email" required
                           value="<?php echo esc_attr( sanitize_email( $_POST['email'] ?? '' ) ); ?>"
                           placeholder="you@example.com" autocomplete="email">
                    <p class="ofp-hint">Your login credentials will be sent here.</p>
                </div>

                <div class="ofp-field">
                    <label>Phone Number <span class="required">*</span></label>
                    <input type="tel" name="phone" required
                           value="<?php echo esc_attr( sanitize_text_field( $_POST['phone'] ?? '' ) ); ?>"
                           placeholder="e.g. 08012345678">
                </div>

                <div class="ofp-field">
                    <label>Business Category <span class="required">*</span></label>
                    <select name="business_category" class="ofp-select" required>
                        <option value="" hidden>— Select Category —</option>
                        <?php
                        $cats = [
                            'property'  => 'Property / Real Estate',
                            'food'      => 'Food & Restaurant',
                            'fashion'   => 'Fashion & Clothing',
                            'beauty'    => 'Beauty & Wellness',
                            'education' => 'Education & Training',
                            'logistics' => 'Logistics & Delivery',
                            'health'    => 'Health & Pharmacy',
                            'tech'      => 'Technology & Services',
                            'other'     => 'Other',
                        ];
                        $selected_cat = sanitize_text_field( $_POST['business_category'] ?? '' );
                        foreach ( $cats as $val => $label ) :
                        ?>
                            <option value="<?php echo esc_attr( $val ); ?>"
                                <?php selected( $selected_cat, $val ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="button" id="btn-next" class="ofp-btn ofp-btn-primary" style="width:100%;justify-content:center;padding:13px;margin-top:8px;">
                    Next: Choose Plan &rarr;
                </button>
                </div> <!-- End Step 1 -->

                <!-- STEP 2: Plan -->
                <div id="step-2" class="ofp-step-content">

                <!-- Subscription type -->
                <!-- Subscription type -->
                <div class="ofp-field" style="margin-bottom:28px;">
                    <label style="font-size:16px; margin-bottom:14px; color:#0f172a; font-weight:700;">What services do you need? <span class="required">*</span></label>
                    <div class="ofp-service-cards">
                        <!-- CRM Card -->
                        <label class="ofp-service-card">
                            <input type="checkbox" name="want_crm" value="1" id="want_crm_cb"
                                <?php checked( ! isset( $_POST['want_crm'] ) || ! empty( $_POST['want_crm'] ) ); ?>>
                            <div class="ofp-service-check">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M438.6 105.4c12.5 12.5 12.5 32.8 0 45.3l-256 256c-12.5 12.5-32.8 12.5-45.3 0l-128-128c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0L160 338.7 393.4 105.4c12.5-12.5 32.8-12.5 45.3 0z"/></svg>
                            </div>
                            <div class="ofp-service-icon">⚡</div>
                            <div class="ofp-service-title">Lead Automation</div>
                            <div class="ofp-service-desc">Automated SMS, voice calls, and IVR follow-ups.</div>
                        </label>
                        <!-- Listing Card -->
                        <label class="ofp-service-card">
                            <input type="checkbox" name="want_listing" value="1" id="want_listing_cb"
                                <?php checked( ! empty( $_POST['want_listing'] ) ); ?>>
                            <div class="ofp-service-check">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path d="M438.6 105.4c12.5 12.5 12.5 32.8 0 45.3l-256 256c-12.5 12.5-32.8 12.5-45.3 0l-128-128c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0L160 338.7 393.4 105.4c12.5-12.5 32.8-12.5 45.3 0z"/></svg>
                            </div>
                            <div class="ofp-service-icon">🏢</div>
                            <div class="ofp-service-title">Property Directory</div>
                            <div class="ofp-service-desc">List properties on the OFast public directory.</div>
                        </label>
                    </div>
                </div>

                <!-- CRM Plan selector -->
                <div class="ofp-section-fade" id="ofp-plan-section" style="<?php echo ( ! isset( $_POST['want_crm'] ) || ! empty( $_POST['want_crm'] ) ) ? 'display:block;' : 'display:none;'; ?>">
                    <label style="font-size:15px; margin-bottom:12px; color:#0f172a; font-weight:600;">Choose Your Automation Plan</label>
                    <div class="ofp-plan-grid">
                        <label class="ofp-plan-option">
                            <input type="radio" name="plan" value="starter"
                                <?php checked( ( $_POST['plan'] ?? 'starter' ), 'starter' ); ?>>
                            <div class="ofp-plan-name">Starter</div>
                            <div class="ofp-plan-price">₦25k<span style="font-size:12px;font-weight:400;opacity:0.8">/mo</span></div>
                            <div class="ofp-plan-leads">100 leads incl.</div>
                        </label>
                        <label class="ofp-plan-option">
                            <input type="radio" name="plan" value="growth"
                                <?php checked( ( $_POST['plan'] ?? '' ), 'growth' ); ?>>
                            <div class="ofp-plan-name">Growth</div>
                            <div class="ofp-plan-price">₦45k<span style="font-size:12px;font-weight:400;opacity:0.8">/mo</span></div>
                            <div class="ofp-plan-leads">300 leads incl.</div>
                        </label>
                        <label class="ofp-plan-option">
                            <input type="radio" name="plan" value="pro"
                                <?php checked( ( $_POST['plan'] ?? '' ), 'pro' ); ?>>
                            <div class="ofp-plan-name">Pro</div>
                            <div class="ofp-plan-price">₦75k<span style="font-size:12px;font-weight:400;opacity:0.8">/mo</span></div>
                            <div class="ofp-plan-leads">700 leads incl.</div>
                        </label>
                    </div>
                    <p class="ofp-hint" style="margin-top:-8px; margin-bottom:24px;">Plus a one-time setup fee (Starter: ₦15k | Growth: ₦25k | Pro: ₦40k).</p>
                </div>

                <!-- Listing Plan selector -->
                <div class="ofp-section-fade" id="ofp-listing-plan-section" style="<?php echo ( ! empty( $_POST['want_listing'] ) ) ? 'display:block;' : 'display:none;'; ?>">
                    <label style="font-size:15px; margin-bottom:12px; color:#0f172a; font-weight:600;">Choose Your Directory Plan</label>
                    <div class="ofp-plan-grid">
                        <label class="ofp-plan-option">
                            <input type="radio" name="listing_plan" value="free"
                                <?php checked( ( $_POST['listing_plan'] ?? 'free' ), 'free' ); ?>>
                            <div class="ofp-plan-name">Free</div>
                            <div class="ofp-plan-price">₦<?php echo number_format(OFP_Property_CPT::get_plan_price('free')); ?><span style="font-size:12px;font-weight:400;opacity:0.8">/mo</span></div>
                            <div class="ofp-plan-leads">Up to <?php echo OFP_Property_CPT::get_plan_cap('free'); ?> props</div>
                        </label>
                        <label class="ofp-plan-option">
                            <input type="radio" name="listing_plan" value="silver"
                                <?php checked( ( $_POST['listing_plan'] ?? '' ), 'silver' ); ?>>
                            <div class="ofp-plan-name">Silver</div>
                            <div class="ofp-plan-price">₦<?php echo number_format(OFP_Property_CPT::get_plan_price('silver')); ?><span style="font-size:12px;font-weight:400;opacity:0.8">/mo</span></div>
                            <div class="ofp-plan-leads">Up to <?php echo OFP_Property_CPT::get_plan_cap('silver'); ?> props</div>
                        </label>
                        <label class="ofp-plan-option">
                            <input type="radio" name="listing_plan" value="gold"
                                <?php checked( ( $_POST['listing_plan'] ?? '' ), 'gold' ); ?>>
                            <div class="ofp-plan-name">Gold</div>
                            <div class="ofp-plan-price">₦<?php echo number_format(OFP_Property_CPT::get_plan_price('gold')); ?><span style="font-size:12px;font-weight:400;opacity:0.8">/mo</span></div>
                            <div class="ofp-plan-leads">Up to <?php echo OFP_Property_CPT::get_plan_cap('gold'); ?> props</div>
                        </label>
                    </div>
                </div>

                <div style="display:flex; gap:12px;">
                    <button type="button" id="btn-back" class="ofp-btn" style="padding:13px; background:#f1f5f9; color:#475569; border:none; border-radius: 8px; cursor:pointer;">
                        &larr; Back
                    </button>
                    <button type="submit" class="ofp-btn ofp-btn-primary" style="flex:1; justify-content:center; padding:13px;">
                        Create Account
                    </button>
                </div>

                <p style="font-size:11px;color:#9ca3af;text-align:center;margin-top:12px;line-height:1.5;">
                    By creating an account you agree to our terms. Accounts are subject to review before activation.
                </p>

                </div> <!-- End Step 2 -->
            </form>

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const btnNext = document.getElementById('btn-next');
                    const btnBack = document.getElementById('btn-back');
                    const step1 = document.getElementById('step-1');
                    const step2 = document.getElementById('step-2');
                    const progress = document.getElementById('step-progress');
                    const dot2 = document.getElementById('dot-2');
                    const label2 = document.getElementById('label-2');

                    // Inputs to validate in Step 1
                    const inputsStep1 = step1.querySelectorAll('input[required], select[required]');

                    // Remove invalid styling instantly when an option is selected
                    step1.addEventListener('change', function(e) {
                        if (e.target.tagName === 'SELECT' && e.target.classList.contains('ofp-select')) {
                            let wrapper = e.target.closest('.ofp-custom-select-wrapper');
                            if (wrapper) {
                                let trigger = wrapper.querySelector('.ofp-custom-select-trigger');
                                if (trigger) trigger.classList.remove('invalid');
                            }
                        }
                    });

                    if(btnNext) {
                        btnNext.addEventListener('click', function() {
                            let isValid = true;
                            
                            // Clear previous invalid states on custom dropdowns
                            step1.querySelectorAll('.ofp-custom-select-trigger').forEach(function(el) {
                                el.classList.remove('invalid');
                            });

                            // Simple HTML5 validation fallback
                            for (let i = 0; i < inputsStep1.length; i++) {
                                let input = inputsStep1[i];
                                if (!input.checkValidity()) {
                                    isValid = false;
                                    // Custom visual feedback for hidden selects
                                    if (input.tagName === 'SELECT' && input.classList.contains('ofp-select')) {
                                        let wrapper = input.closest('.ofp-custom-select-wrapper');
                                        if (wrapper) {
                                            let trigger = wrapper.querySelector('.ofp-custom-select-trigger');
                                            if (trigger) trigger.classList.add('invalid');
                                        }
                                    } else {
                                        // Standard HTML5 popup for visible inputs
                                        input.reportValidity();
                                    }
                                    break;
                                }
                            }

                            if (isValid) {
                                step1.classList.remove('active');
                                step2.classList.add('active');
                                progress.style.width = '100%';
                                dot2.classList.add('active');
                                label2.classList.add('active');
                            }
                        });
                    }

                    if(btnBack) {
                        btnBack.addEventListener('click', function() {
                            step2.classList.remove('active');
                            step1.classList.add('active');
                            progress.style.width = '0%';
                            dot2.classList.remove('active');
                            label2.classList.remove('active');
                        });
                    }

                    // Step 2 dynamic toggles
                    const wantCrm = document.getElementById('want_crm_cb');
                    const crmSection = document.getElementById('ofp-plan-section');
                    const wantListing = document.getElementById('want_listing_cb');
                    const listingSection = document.getElementById('ofp-listing-plan-section');

                    if(wantCrm && crmSection) {
                        wantCrm.addEventListener('change', function() {
                            crmSection.style.display = this.checked ? 'block' : 'none';
                        });
                    }
                    if(wantListing && listingSection) {
                        wantListing.addEventListener('change', function() {
                            listingSection.style.display = this.checked ? 'block' : 'none';
                        });
                    }
                });
            </script>
        </div>

        <div class="ofp-footer-link">
            Already have an account? <a href="<?php echo esc_url( home_url( '/login' ) ); ?>">Log in →</a>
        </div>

    </div>

<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
