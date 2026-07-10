<?php
/**
 * Template: /notifications — client's full notifications page.
 *
 * The bell icon itself (with the unread badge) lives in whatever
 * shared header/nav partial your other dashboard pages already use.
 * See Patch I for the bell HTML snippet to add there.
 *
 * This page shows the full list — not just the last 10 from the
 * dropdown, but everything, paginated.
 *
 * Also handles the AJAX mark-as-read call from the bell dropdown
 * (same URL, POST request with ofp_mark_read action).
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();

// Handle mark-all-read — called when client opens the bell dropdown.
if (
	$_SERVER['REQUEST_METHOD'] === 'POST' &&
	isset( $_POST['ofp_mark_all_read'] ) &&
	wp_verify_nonce( $_POST['ofp_notif_nonce'] ?? '', 'ofp_notifications_action' )
) {
	OFP_Notification::mark_all_read( $client->id );
	if ( wp_doing_ajax() || ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) {
		wp_send_json_success();
	}
}

// Handle single mark-as-read.
if (
	$_SERVER['REQUEST_METHOD'] === 'POST' &&
	isset( $_POST['ofp_mark_read'] ) &&
	wp_verify_nonce( $_POST['ofp_notif_nonce'] ?? '', 'ofp_notifications_action' )
) {
	OFP_Notification::mark_read( (int) $_POST['notification_id'], $client->id );
	if ( wp_doing_ajax() || ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) {
		wp_send_json_success();
	}
}

global $wpdb;
$page     = max( 1, (int) ( $_GET['npage'] ?? 1 ) );
$per_page = 20;
$offset   = ( $page - 1 ) * $per_page;

$total = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}ofp_notifications WHERE client_id = %d",
	$client->id
) );

$notifications = $wpdb->get_results( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}ofp_notifications
	 WHERE client_id = %d
	 ORDER BY created_at DESC
	 LIMIT %d OFFSET %d",
	$client->id, $per_page, $offset
) );

$total_pages = ceil( $total / $per_page );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Notifications — OFast Pipeline</title>
	<?php wp_head(); ?>
</head>
<body>
<div class="ofp-dashboard-wrapper">
	<h1>Notifications</h1>

	<?php if ( empty( $notifications ) ) : ?>
		<p class="ofp-muted">No notifications yet.</p>
	<?php else : ?>
		<form method="POST" style="margin-bottom:16px;">
			<?php wp_nonce_field( 'ofp_notifications_action', 'ofp_notif_nonce' ); ?>
			<button type="submit" name="ofp_mark_all_read" value="1" class="ofp-btn ofp-btn-secondary">
				Mark all as read
			</button>
		</form>

		<div class="ofp-notifications-list">
			<?php foreach ( $notifications as $notif ) : ?>
				<div class="ofp-notification-item <?php echo $notif->is_read ? 'ofp-read' : 'ofp-unread'; ?>">
					<div class="ofp-notification-content">
						<strong><?php echo esc_html( $notif->title ); ?></strong>
						<p><?php echo esc_html( $notif->message ); ?></p>
						<span class="ofp-notification-time ofp-muted">
							<?php echo esc_html( human_time_diff( strtotime( $notif->created_at ), time() ) . ' ago' ); ?>
						</span>
					</div>
					<?php if ( ! $notif->is_read ) : ?>
						<form method="POST" style="display:inline;">
							<?php wp_nonce_field( 'ofp_notifications_action', 'ofp_notif_nonce' ); ?>
							<input type="hidden" name="notification_id" value="<?php echo esc_attr( $notif->id ); ?>">
							<button type="submit" name="ofp_mark_read" value="1" class="ofp-link-btn">
								Mark read
							</button>
						</form>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="ofp-pagination">
				<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
					<a href="?npage=<?php echo esc_attr( $i ); ?>"
					   class="ofp-page-btn <?php echo $i === $page ? 'ofp-active' : ''; ?>">
						<?php echo esc_html( $i ); ?>
					</a>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
