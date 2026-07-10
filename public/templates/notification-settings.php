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
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">
    <?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-container">
        <div style="padding-bottom: 60px;">
            <h1 style="font-size:22px; font-weight:700; color:var(--text-main); margin:0 0 24px; letter-spacing:-0.01em;">
                Notification Settings
            </h1>

            <?php if ( $error ) : ?>
                <div class="ofp-alert ofp-alert-error"><?php echo esc_html( $error ); ?></div>
            <?php endif; ?>
            <?php if ( $success ) : ?>
                <div class="ofp-alert ofp-alert-success"><?php echo esc_html( $success ); ?></div>
            <?php endif; ?>

            <div class="ofp-card" style="max-width: 600px;">
                <h3 style="margin-top:0; margin-bottom:12px; color:var(--text-main); font-size:18px;">Preferences</h3>
                <p class="ofp-hint" style="margin-bottom: 24px;">
                    Choose how you want to receive notifications — when a
                    funding request is reviewed, a property is approved, or
                    anything else happens on your account.
                </p>

                <form method="POST" class="ofp-notif-pref-form">
                    <?php wp_nonce_field( 'ofp_notif_pref_action', 'ofp_notif_pref_nonce' ); ?>

                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <label style="display:flex; align-items:flex-start; gap:12px; padding:16px; border:1px solid var(--border-light); border-radius:12px; cursor:pointer; background:#fff; transition:border-color 0.2s;">
                            <input type="radio" name="notification_pref" value="both" style="margin-top:4px;"
                                <?php checked( $current_pref, 'both' ); ?>>
                            <div style="display:flex; flex-direction:column; gap:4px;">
                                <strong style="color:var(--text-dark); font-size:15px;">Bell + Email</strong>
                                <span class="ofp-hint" style="margin:0;">Get notified inside the app AND by email.</span>
                            </div>
                        </label>

                        <label style="display:flex; align-items:flex-start; gap:12px; padding:16px; border:1px solid var(--border-light); border-radius:12px; cursor:pointer; background:#fff; transition:border-color 0.2s;">
                            <input type="radio" name="notification_pref" value="bell" style="margin-top:4px;"
                                <?php checked( $current_pref, 'bell' ); ?>>
                            <div style="display:flex; flex-direction:column; gap:4px;">
                                <strong style="color:var(--text-dark); font-size:15px;">Bell only</strong>
                                <span class="ofp-hint" style="margin:0;">Only see notifications inside the app. No emails.</span>
                            </div>
                        </label>

                        <label style="display:flex; align-items:flex-start; gap:12px; padding:16px; border:1px solid var(--border-light); border-radius:12px; cursor:pointer; background:#fff; transition:border-color 0.2s;">
                            <input type="radio" name="notification_pref" value="email" style="margin-top:4px;"
                                <?php checked( $current_pref, 'email' ); ?>>
                            <div style="display:flex; flex-direction:column; gap:4px;">
                                <strong style="color:var(--text-dark); font-size:15px;">Email only</strong>
                                <span class="ofp-hint" style="margin:0;">Only receive notifications by email. Nothing shows in the bell.</span>
                            </div>
                        </label>
                    </div>

                    <div style="margin-top: 24px;">
                        <button type="submit" name="ofp_save_notif_pref" value="1" class="ofp-btn ofp-btn-primary">
                            Save Preference
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php wp_footer(); ?>
</body>
</html>
