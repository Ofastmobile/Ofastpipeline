<?php
/**
 * Template: /notification-settings
 * Client chooses how they want to receive notifications and views history.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();

$success = '';
$error   = '';

// Handle Preference Save
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_save_notif_pref'] ) ) {
	if ( ! wp_verify_nonce( $_POST['ofp_notif_pref_nonce'] ?? '', 'ofp_notif_pref_action' ) ) {
		$error = 'Security check failed — please try again.';
	} else {
		$pref = sanitize_text_field( $_POST['notification_pref'] ?? 'both' );
		OFP_Notification::save_preference( $client->id, $pref );
		$success = 'Notification preference saved.';
		$client = OFP_Auth::current_client(); // Refresh
	}
}

// Handle Mark Read
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_mark_read'] ) ) {
	if ( wp_verify_nonce( $_POST['ofp_notif_nonce'] ?? '', 'ofp_notifications_action' ) ) {
		OFP_Notification::mark_read( (int) $_POST['notification_id'], $client->id );
	}
}

// Handle Mark All Read
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_mark_all_read'] ) ) {
	if ( wp_verify_nonce( $_POST['ofp_notif_nonce'] ?? '', 'ofp_notifications_action' ) ) {
		OFP_Notification::mark_all_read( $client->id );
	}
}

global $wpdb;
$all_notifications = $wpdb->get_results( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}ofp_notifications
	 WHERE client_id = %d
	 ORDER BY created_at DESC
	 LIMIT 150",
	$client->id
) );

$current_pref = $client->ofp_notification_pref ?? OFP_Notification::PREF_BOTH;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Notification Settings — OFast Pipeline</title>
	<?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
</head>
<body class="ofp-portal-body">
    <?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-container">
        <div style="padding-bottom: 60px;">
            
            <div class="ofp-notif-tabs">
                <button class="ofp-notif-tab active" data-target="panel-settings">Settings</button>
                <button class="ofp-notif-tab" data-target="panel-history">All Notifications</button>
            </div>

            <div class="ofp-notif-layout">
                
                <!-- Left Column: Settings -->
                <div class="ofp-notif-main ofp-notif-panel active" id="panel-settings">
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
                                <label style="display:flex; align-items:flex-start; gap:16px; padding:20px; border:1px solid var(--border-color); border-radius:12px; cursor:pointer; background:var(--bg-lighter); transition:all 0.2s;" onmouseover="this.style.borderColor='var(--accent-blue)'; this.style.background='var(--bg-card)';" onmouseout="this.style.borderColor='var(--border-color)'; this.style.background='var(--bg-lighter)';">
                                    <input type="radio" name="notification_pref" value="both" style="margin-top:2px; transform: scale(1.2); accent-color: var(--accent-blue);"
                                        <?php checked( $current_pref, 'both' ); ?>>
                                    <div style="display:flex; flex-direction:column; gap:4px;">
                                        <strong style="color:var(--text-main); font-size:15px;">Bell + Email</strong>
                                        <span class="ofp-hint" style="margin:0; font-size:13px;">Get notified inside the app AND by email.</span>
                                    </div>
                                </label>

                                <label style="display:flex; align-items:flex-start; gap:16px; padding:20px; border:1px solid var(--border-color); border-radius:12px; cursor:pointer; background:var(--bg-lighter); transition:all 0.2s;" onmouseover="this.style.borderColor='var(--accent-blue)'; this.style.background='var(--bg-card)';" onmouseout="this.style.borderColor='var(--border-color)'; this.style.background='var(--bg-lighter)';">
                                    <input type="radio" name="notification_pref" value="bell" style="margin-top:2px; transform: scale(1.2); accent-color: var(--accent-blue);"
                                        <?php checked( $current_pref, 'bell' ); ?>>
                                    <div style="display:flex; flex-direction:column; gap:4px;">
                                        <strong style="color:var(--text-main); font-size:15px;">Bell only</strong>
                                        <span class="ofp-hint" style="margin:0; font-size:13px;">Only see notifications inside the app. No emails.</span>
                                    </div>
                                </label>

                                <label style="display:flex; align-items:flex-start; gap:16px; padding:20px; border:1px solid var(--border-color); border-radius:12px; cursor:pointer; background:var(--bg-lighter); transition:all 0.2s;" onmouseover="this.style.borderColor='var(--accent-blue)'; this.style.background='var(--bg-card)';" onmouseout="this.style.borderColor='var(--border-color)'; this.style.background='var(--bg-lighter)';">
                                    <input type="radio" name="notification_pref" value="email" style="margin-top:2px; transform: scale(1.2); accent-color: var(--accent-blue);"
                                        <?php checked( $current_pref, 'email' ); ?>>
                                    <div style="display:flex; flex-direction:column; gap:4px;">
                                        <strong style="color:var(--text-main); font-size:15px;">Email only</strong>
                                        <span class="ofp-hint" style="margin:0; font-size:13px;">Only receive notifications by email. Nothing shows in the bell.</span>
                                    </div>
                                </label>
                            </div>

                            <div style="margin-top: 32px;">
                                <button type="submit" name="ofp_save_notif_pref" value="1" class="ofp-btn ofp-btn-primary" style="padding: 14px 32px; font-size: 14px;">
                                    Save Preferences
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Right Column: History -->
                <div class="ofp-notif-sidebar ofp-notif-panel" id="panel-history">
                    <div class="ofp-card" style="padding: 0; display: flex; flex-direction: column; height: 100%; max-height: calc(100vh - 120px);">
                        <div class="ofp-card-header" style="padding: 16px 24px; display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0; font-size: 16px;">Notification History</h3>
                            <form method="POST" style="margin:0;">
                                <?php wp_nonce_field( 'ofp_notifications_action', 'ofp_notif_nonce' ); ?>
                                <button type="submit" name="ofp_mark_all_read" value="1" style="background:none;border:none;color:var(--accent-blue);font-size:12px;font-weight:500;cursor:pointer;padding:0;">Mark all read</button>
                            </form>
                        </div>
                        
                        <div class="ofp-notif-list" id="ofpNotifList" style="overflow-y: auto; flex: 1; padding: 0;">
                            <?php if ( empty( $all_notifications ) ) : ?>
                                <div style="padding: 32px; text-align: center; color: var(--text-muted);">
                                    No notifications yet.
                                </div>
                            <?php else : ?>
                                <?php foreach ( $all_notifications as $idx => $notif ) : ?>
                                    <div class="ofp-notif-item <?php echo $notif->is_read ? '' : 'unread'; ?>" style="<?php echo $idx >= 15 ? 'display: none;' : ''; ?>">
                                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:4px;">
                                            <strong style="font-size:14px; color:var(--text-main); line-height:1.4; padding-right:8px;"><?php echo esc_html( $notif->title ); ?></strong>
                                            <span style="font-size:11px; color:var(--text-muted); white-space:nowrap;"><?php echo esc_html( human_time_diff( strtotime( $notif->created_at ), time() ) ); ?> ago</span>
                                        </div>
                                        <div style="font-size:13px; color:var(--text-muted); margin-bottom:8px; line-height:1.5;">
                                            <?php echo esc_html( $notif->message ); ?>
                                        </div>
                                        <?php if ( ! $notif->is_read ) : ?>
                                            <form method="POST" style="margin:0; text-align:right;">
                                                <?php wp_nonce_field( 'ofp_notifications_action', 'ofp_notif_nonce' ); ?>
                                                <input type="hidden" name="notification_id" value="<?php echo esc_attr( $notif->id ); ?>">
                                                <button type="submit" name="ofp_mark_read" value="1" style="background:none;border:none;color:var(--accent-blue);font-size:12px;font-weight:500;cursor:pointer;padding:0;">Mark as read</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ( count( $all_notifications ) > 15 ) : ?>
                            <div id="ofpLoadMoreContainer" style="padding: 16px; text-align: center; background: var(--bg-card);">
                                <button type="button" id="ofpLoadMoreBtn" class="ofp-btn ofp-btn-secondary" style="font-size: 13px;">Load More</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Tab switching logic (Mobile only via CSS, but JS handles state)
        const tabs = document.querySelectorAll('.ofp-notif-tab');
        const panels = document.querySelectorAll('.ofp-notif-panel');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                panels.forEach(p => p.classList.remove('active'));
                
                tab.classList.add('active');
                const targetId = tab.getAttribute('data-target');
                document.getElementById(targetId).classList.add('active');
            });
        });

        // Load More logic
        const loadMoreBtn = document.getElementById('ofpLoadMoreBtn');
        if (loadMoreBtn) {
            let currentlyVisible = 15;
            const step = 15;
            const items = document.querySelectorAll('.ofp-notif-item');
            
            loadMoreBtn.addEventListener('click', function() {
                let shown = 0;
                for (let i = currentlyVisible; i < currentlyVisible + step; i++) {
                    if (items[i]) {
                        items[i].style.display = 'block';
                        shown++;
                    }
                }
                currentlyVisible += shown;
                
                if (currentlyVisible >= items.length) {
                    document.getElementById('ofpLoadMoreContainer').style.display = 'none';
                }
            });
        }
    });
    </script>
<?php wp_footer(); ?>
</body>
</html>
