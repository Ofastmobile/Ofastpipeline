<?php
/**
 * Template: /notification-settings — client chooses how they want
 * to receive notifications (bell only, email only, or both).
 *
 * Route: logged-in clients only. Add 'notification-settings' to
 * your portal routes (Patch I).
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();

$success = '';
$error   = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_save_notif_pref'] ) ) {

	if ( ! wp_verify_nonce( $_POST['ofp_notif_pref_nonce'] ?? '', 'ofp_notif_pref_action' ) ) {
		$error = 'Security check failed — please try again.';
	} else {
		$pref = sanitize_text_field( $_POST['notification_pref'] ?? 'both' );
		OFP_Notification::save_preference( $client->id, $pref );
		$success = 'Notification preference saved.';
		// Refresh client so the form shows the updated value.
		$client = OFP_Auth::current_client();
	}
}

$current_pref = $client->ofp_notification_pref ?? OFP_Notification::PREF_BOTH;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Notification Settings — OFast Pipeline</title>
	<?php wp_head(); ?>
</head>
<body>
<div class="ofp-dashboard-wrapper">
	<h1>Notification Settings</h1>

	<?php if ( $error ) : ?>
		<div class="ofp-notice ofp-notice-error"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
	<?php if ( $success ) : ?>
		<div class="ofp-notice ofp-notice-success"><?php echo esc_html( $success ); ?></div>
	<?php endif; ?>

	<div class="ofp-card">
		<p class="ofp-muted">
			Choose how you want to receive notifications — when a
			funding request is reviewed, a property is approved, or
			anything else happens on your account.
		</p>

		<form method="POST" class="ofp-notif-pref-form">
			<?php wp_nonce_field( 'ofp_notif_pref_action', 'ofp_notif_pref_nonce' ); ?>

			<label class="ofp-radio-row">
				<input type="radio" name="notification_pref" value="both"
					<?php checked( $current_pref, 'both' ); ?>>
				<span>
					<strong>Bell + Email</strong><br>
					<span class="ofp-muted">Get notified inside the app AND by email.</span>
				</span>
			</label>

			<label class="ofp-radio-row">
				<input type="radio" name="notification_pref" value="bell"
					<?php checked( $current_pref, 'bell' ); ?>>
				<span>
					<strong>Bell only</strong><br>
					<span class="ofp-muted">Only see notifications inside the app. No emails.</span>
				</span>
			</label>

			<label class="ofp-radio-row">
				<input type="radio" name="notification_pref" value="email"
					<?php checked( $current_pref, 'email' ); ?>>
				<span>
					<strong>Email only</strong><br>
					<span class="ofp-muted">Only receive notifications by email. Nothing shows in the bell.</span>
				</span>
			</label>

			<button type="submit" name="ofp_save_notif_pref" value="1" class="ofp-btn ofp-btn-primary">
				Save Preference
			</button>
		</form>
	</div>
</div>
<?php wp_footer(); ?>
</body>
</html>
