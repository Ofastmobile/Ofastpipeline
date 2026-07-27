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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — OFast Pipeline</title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css' ); ?>">
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <div class="ofp-page-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1>Notifications</h1>
            <p>Recent activity and alerts on your account.</p>
        </div>
        <?php if ( ! empty( $notifications ) ) : ?>
            <form method="POST" style="margin: 0;">
                <?php wp_nonce_field( 'ofp_notifications_action', 'ofp_notif_nonce' ); ?>
                <button type="submit" name="ofp_mark_all_read" value="1" class="ofp-btn ofp-btn-secondary" style="font-size: 13px; padding: 8px 16px;">
                    ✓ Mark all as read
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ( empty( $notifications ) ) : ?>
        <div class="ofp-empty-state">
            <span style="font-size: 32px; margin-bottom: 16px; display: block;">📭</span>
            <h3>No notifications yet</h3>
            <p>You're all caught up! New alerts will appear here.</p>
        </div>
    <?php else : ?>
        
        <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 32px;">
            <?php foreach ( $notifications as $notif ) : 
                $bg_color = $notif->is_read ? 'transparent' : 'var(--bg-lighter)';
                $border_color = $notif->is_read ? 'var(--border-color)' : 'var(--accent-blue)';
                $opacity = $notif->is_read ? '0.6' : '1';
            ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 16px; border-radius: 12px; background: <?php echo $bg_color; ?>; border: 1px solid <?php echo $border_color; ?>; opacity: <?php echo $opacity; ?>; transition: transform 0.2s;" onmouseover="this.style.transform='translateY(-2px)';" onmouseout="this.style.transform='translateY(0)';">
                    
                    <div style="display: flex; gap: 16px; align-items: center; flex: 1; min-width: 0;">
                        
                        <!-- Icon Box -->
                        <div style="width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; background: rgba(59, 130, 246, 0.1); color: var(--accent-blue); flex-shrink: 0; position: relative;">
                            🔔
                            <?php if ( ! $notif->is_read ) : ?>
                                <span style="position: absolute; top: 12px; right: 12px; width: 8px; height: 8px; background: var(--accent-blue); border-radius: 50%; box-shadow: 0 0 8px var(--accent-blue);"></span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Text Content -->
                        <div style="min-width: 0;">
                            <div style="font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 4px;">
                                <?php echo esc_html( $notif->title ); ?>
                            </div>
                            <div style="font-size: 13px; color: var(--text-muted); line-height: 1.5;">
                                <?php echo esc_html( $notif->message ); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Action / Time -->
                    <div style="text-align: right; flex-shrink: 0; padding-left: 24px;">
                        <div style="font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 8px;">
                            <?php echo esc_html( human_time_diff( strtotime( $notif->created_at ), current_time('timestamp') ) . ' ago' ); ?>
                        </div>
                        <?php if ( ! $notif->is_read ) : ?>
                            <form method="POST" style="margin: 0;">
                                <?php wp_nonce_field( 'ofp_notifications_action', 'ofp_notif_nonce' ); ?>
                                <input type="hidden" name="notification_id" value="<?php echo esc_attr( $notif->id ); ?>">
                                <button type="submit" name="ofp_mark_read" value="1" style="background: none; border: none; color: var(--accent-blue); font-size: 12px; font-weight: 600; cursor: pointer; padding: 0;">
                                    Mark as read
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
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
</main>
</div><!-- .ofp-shell -->
<?php wp_footer(); ?>
</body>
</html>
