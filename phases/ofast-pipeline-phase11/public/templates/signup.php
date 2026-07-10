<?php
/**
 * Template: /signup — self-serve client signup (v2.1 amendment, Section A5).
 *
 * Public route, no login required (registered as an exception in
 * OFP_Client_Portal::handle_routes() alongside 'login').
 *
 * PHASE 11 CHANGE: every price shown on this page (plan monthly fees,
 * plan setup fees, listing fee) is now read live from
 * OFP_Subscription's pricing getters, which in turn read wp_options.
 * Previously these were a hardcoded $plan_prices array duplicated in
 * this file. A pricing change saved in Settings > Plans & Pricing now
 * shows up here immediately, with no code deploy.
 *
 * Self-serve signups land in 'pending_review' status (Section A5.3) —
 * they are never auto-active, regardless of plan or listing choice.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$error = '';

// Phase 11: live pricing, not hardcoded.
$plan_prices = OFP_Subscription::get_plan_prices();
$setup_fees  = OFP_Subscription::get_setup_fees();
$listing_fee = OFP_Subscription::get_listing_fee();
$plan_labels = [
	'starter' => 'Starter',
	'growth'  => 'Growth',
	'pro'     => 'Pro',
];

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {

	OFP_Security::check_rate_limit( $_SERVER['REMOTE_ADDR'] ?? '', 'signup', 3, 600 );

	$wants_crm     = ! empty( $_POST['want_crm'] );
	$wants_listing = ! empty( $_POST['want_listing'] );
	$plan          = sanitize_text_field( $_POST['plan'] ?? 'starter' );

	if ( ! in_array( $plan, OFP_Subscription::PLAN_KEYS, true ) ) {
		$plan = 'starter';
	}

	if ( ! $wants_crm && ! $wants_listing ) {
		$error = 'Please choose at least one: Lead Automation (CRM) or List a Property.';
	} elseif (
		empty( $_POST['business_name'] ) ||
		empty( $_POST['owner_name'] )    ||
		empty( $_POST['email'] )         ||
		empty( $_POST['phone'] )
	) {
		$error = 'Please fill in all required fields.';
	} else {

		$client_id = OFP_Client::create( [
			'business_name'     => sanitize_text_field( $_POST['business_name'] ),
			'owner_name'        => sanitize_text_field( $_POST['owner_name'] ),
			'email'             => sanitize_email( $_POST['email'] ),
			'phone'             => OFP_Security::sanitize_phone( $_POST['phone'] ),
			'business_phone'    => OFP_Security::sanitize_phone( $_POST['business_phone'] ?? $_POST['phone'] ),
			'whatsapp_number'   => OFP_Security::sanitize_phone( $_POST['whatsapp_number'] ?? $_POST['phone'] ),
			'plan'              => $wants_crm ? $plan : null,
			'onboarding_source' => 'self_serve',
			// Section A5.3 — self-serve signups always start in
			// pending_review, never auto-active, regardless of what
			// they signed up for. Manually onboarded clients
			// (OFP_Client::create() called from wp-admin) are the
			// only path that goes straight to 'active'.
			'status'            => 'pending_review',
		] );

		if ( $wants_crm ) {
			OFP_Subscription::create( $client_id, 'crm', $plan );
		}
		if ( $wants_listing ) {
			OFP_Subscription::create( $client_id, 'listing', null );
		}

		wp_redirect( OFP_Payment::generate_link( $client_id ) );
		exit;
	}
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Sign Up — OFast Pipeline</title>
	<?php wp_head(); ?>
</head>
<body>
<div class="ofp-signup-wrapper">
	<h1>Get Started with OFast Pipeline</h1>

	<?php if ( $error ) : ?>
		<p class="ofp-error"><?php echo esc_html( $error ); ?></p>
	<?php endif; ?>

	<form method="POST" class="ofp-signup-form">

		<fieldset>
			<legend>Business Details</legend>

			<label>
				Business Name
				<input type="text" name="business_name" required
					   value="<?php echo isset( $_POST['business_name'] ) ? esc_attr( wp_unslash( $_POST['business_name'] ) ) : ''; ?>">
			</label>

			<label>
				Your Name
				<input type="text" name="owner_name" required
					   value="<?php echo isset( $_POST['owner_name'] ) ? esc_attr( wp_unslash( $_POST['owner_name'] ) ) : ''; ?>">
			</label>

			<label>
				Email
				<input type="email" name="email" required
					   value="<?php echo isset( $_POST['email'] ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; ?>">
			</label>

			<label>
				Phone Number
				<input type="tel" name="phone" required
					   value="<?php echo isset( $_POST['phone'] ) ? esc_attr( wp_unslash( $_POST['phone'] ) ) : ''; ?>">
			</label>

			<label>
				Business Phone <span class="ofp-optional">(optional — defaults to phone above)</span>
				<input type="tel" name="business_phone">
			</label>

			<label>
				WhatsApp Number <span class="ofp-optional">(optional — defaults to phone above)</span>
				<input type="tel" name="whatsapp_number">
			</label>
		</fieldset>

		<fieldset>
			<legend>What do you want?</legend>

			<label class="ofp-checkbox-row">
				<input type="checkbox" name="want_crm" value="1" id="ofp-want-crm"
					<?php checked( ! empty( $_POST['want_crm'] ) ); ?>>
				Lead Automation (CRM)
			</label>

			<div id="ofp-plan-options" class="ofp-plan-options">
				<?php foreach ( OFP_Subscription::PLAN_KEYS as $plan_key ) : ?>
					<label class="ofp-plan-option">
						<input type="radio" name="plan" value="<?php echo esc_attr( $plan_key ); ?>"
							<?php checked( ( $_POST['plan'] ?? 'starter' ), $plan_key ); ?>>
						<strong><?php echo esc_html( $plan_labels[ $plan_key ] ); ?></strong>
						— NGN <?php echo esc_html( number_format( $plan_prices[ $plan_key ], 2 ) ); ?>/month
						<span class="ofp-setup-fee">
							(+ NGN <?php echo esc_html( number_format( $setup_fees[ $plan_key ], 2 ) ); ?> one-time setup)
						</span>
					</label>
				<?php endforeach; ?>
			</div>

			<label class="ofp-checkbox-row">
				<input type="checkbox" name="want_listing" value="1"
					<?php checked( ! empty( $_POST['want_listing'] ) ); ?>>
				List a Property
				— NGN <?php echo esc_html( number_format( $listing_fee, 2 ) ); ?>/month per property
			</label>
		</fieldset>

		<button type="submit" class="ofp-btn ofp-btn-primary">Sign Up</button>
	</form>

	<p class="ofp-signup-note">
		Your account will be reviewed before it goes live — this usually takes less than one business day.
		Once approved, you'll receive an email with your login details.
	</p>

	<p class="ofp-signup-login-link">
		Already have an account? <a href="<?php echo esc_url( home_url( '/login' ) ); ?>">Log in</a>
	</p>
</div>

<script>
// Purely cosmetic: dim/disable the plan radio buttons when "Lead Automation"
// isn't checked, so it's visually obvious the plan choice only matters if
// CRM is selected. Server-side validation above does not depend on this —
// it's a UX nicety only.
(function () {
	var crmCheckbox = document.getElementById( 'ofp-want-crm' );
	var planOptions  = document.getElementById( 'ofp-plan-options' );
	function sync() {
		planOptions.style.opacity = crmCheckbox.checked ? '1' : '0.5';
		var radios = planOptions.querySelectorAll( 'input[type=radio]' );
		radios.forEach( function ( r ) { r.disabled = ! crmCheckbox.checked; } );
	}
	crmCheckbox.addEventListener( 'change', sync );
	sync();
})();
</script>

<?php wp_footer(); ?>
</body>
</html>
