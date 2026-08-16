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
        body { display:flex; align-items:flex-start; justify-content:center; min-height:100vh; padding:40px 20px; }
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

            <form method="POST" action="">

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
                    <label>Business Category</label>
                    <select name="business_category" class="ofp-select">
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

                <!-- Subscription type -->
                <div class="ofp-field">
                    <label>What do you need? <span class="required">*</span></label>
                    <label class="ofp-checkbox-row">
                        <input type="checkbox" name="want_crm" value="1"
                            <?php checked( ! isset( $_POST['want_crm'] ) || ! empty( $_POST['want_crm'] ) ); ?>>
                        <span>
                            <strong>Lead Automation (CRM Pipeline)</strong><br>
                            <span style="font-size:12px;color:#6b7280;">Automated SMS, voice calls, and IVR follow-ups for your leads.</span>
                        </span>
                    </label>
                    <label class="ofp-checkbox-row">
                        <input type="checkbox" name="want_listing" value="1" id="want_listing_cb"
                            <?php checked( ! empty( $_POST['want_listing'] ) ); ?>>
                        <span>
                            <strong>Property Listing Directory</strong><br>
                            <span style="font-size:12px;color:#6b7280;">List your property on the OFast Pipeline directory. Choose a plan below.</span>
                        </span>
                    </label>
                </div>

                <!-- Listing Plan selector -->
                <div class="ofp-field" id="ofp-listing-plan-section" style="display:none;">
                    <label>Choose Your Listing Plan</label>
                    <div class="ofp-plan-grid">
                        <label class="ofp-plan-option">
                            <input type="radio" name="listing_plan" value="free"
                                <?php checked( ( $_POST['listing_plan'] ?? 'free' ), 'free' ); ?>>
                            <div class="ofp-plan-name">Free</div>
                            <div class="ofp-plan-price">NGN <?php echo number_format(OFP_Property_CPT::get_plan_price('free')); ?>/mo</div>
                            <div class="ofp-plan-leads">Up to <?php echo OFP_Property_CPT::get_plan_cap('free'); ?> properties</div>
                        </label>
                        <label class="ofp-plan-option">
                            <input type="radio" name="listing_plan" value="silver"
                                <?php checked( ( $_POST['listing_plan'] ?? '' ), 'silver' ); ?>>
                            <div class="ofp-plan-name">Silver</div>
                            <div class="ofp-plan-price">NGN <?php echo number_format(OFP_Property_CPT::get_plan_price('silver')); ?>/mo</div>
                            <div class="ofp-plan-leads">Up to <?php echo OFP_Property_CPT::get_plan_cap('silver'); ?> properties</div>
                        </label>
                        <label class="ofp-plan-option">
                            <input type="radio" name="listing_plan" value="gold"
                                <?php checked( ( $_POST['listing_plan'] ?? '' ), 'gold' ); ?>>
                            <div class="ofp-plan-name">Gold</div>
                            <div class="ofp-plan-price">NGN <?php echo number_format(OFP_Property_CPT::get_plan_price('gold')); ?>/mo</div>
                            <div class="ofp-plan-leads">Up to <?php echo OFP_Property_CPT::get_plan_cap('gold'); ?> properties</div>
                        </label>
                    </div>
                </div>

                <!-- CRM Plan selector -->
                <div class="ofp-field" id="ofp-plan-section">
                    <label>Choose Your CRM Plan</label>
                    <div class="ofp-plan-grid">
                        <label class="ofp-plan-option">
                            <input type="radio" name="plan" value="starter"
                                <?php checked( ( $_POST['plan'] ?? 'starter' ), 'starter' ); ?>>
                            <div class="ofp-plan-name">Starter</div>
                            <div class="ofp-plan-price">NGN 25,000/mo</div>
                            <div class="ofp-plan-leads">100 leads</div>
                        </label>
                        <label class="ofp-plan-option">
                            <input type="radio" name="plan" value="growth"
                                <?php checked( ( $_POST['plan'] ?? '' ), 'growth' ); ?>>
                            <div class="ofp-plan-name">Growth</div>
                            <div class="ofp-plan-price">NGN 45,000/mo</div>
                            <div class="ofp-plan-leads">300 leads</div>
                        </label>
                        <label class="ofp-plan-option">
                            <input type="radio" name="plan" value="pro"
                                <?php checked( ( $_POST['plan'] ?? '' ), 'pro' ); ?>>
                            <div class="ofp-plan-name">Pro</div>
                            <div class="ofp-plan-price">NGN 75,000/mo</div>
                            <div class="ofp-plan-leads">700 leads</div>
                        </label>
                    </div>
                    <p class="ofp-hint">Plus a one-time setup fee (Starter: NGN 15,000 | Growth: NGN 25,000 | Pro: NGN 40,000).</p>
                </div>

                <button type="submit" class="ofp-btn ofp-btn-primary" style="width:100%;justify-content:center;padding:13px;">
                    Create Account
                </button>

                <p style="font-size:11px;color:#9ca3af;text-align:center;margin-top:12px;line-height:1.5;">
                    By creating an account you agree to our terms. Accounts are subject to review before activation.
                </p>

            </form>
        </div>

        <div class="ofp-footer-link">
            Already have an account? <a href="<?php echo esc_url( home_url( '/login' ) ); ?>">Log in →</a>
        </div>

    </div>

<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
