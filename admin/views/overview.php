<?php
/**
 * Admin View: Overview Dashboard
 * Shows key metrics, alerts, and recent activity across all clients.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

global $wpdb;
$p = $wpdb->prefix;

// ── Stats ──────────────────────────────────────────────────────────────────
$total_active    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_clients WHERE status = 'active'" );
$total_pending   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_clients WHERE status = 'pending_review'" );
$total_suspended = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_clients WHERE status IN ('suspended','grace')" );
$leads_today     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_leads WHERE DATE(created_at) = CURDATE()" );
$leads_month     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_leads WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())" );
$sms_today       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE type = 'sms' AND DATE(sent_at) = CURDATE()" );
$calls_today     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_communications_log WHERE type = 'voice' AND DATE(sent_at) = CURDATE()" );
$pending_queue   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_trigger_queue WHERE status = 'pending'" );
$failed_triggers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_trigger_queue WHERE status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)" );

// ── Alerts ─────────────────────────────────────────────────────────────────
$expiring_soon  = $wpdb->get_results( "SELECT business_name, subscription_expires FROM {$p}ofp_clients WHERE subscription_expires <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND status = 'active'" . OFP_White_Label::not_solo_sql() . " ORDER BY subscription_expires ASC" );
$low_credit     = $wpdb->get_results( "SELECT c.business_name FROM {$p}ofp_clients c JOIN {$p}ofp_credits cr ON cr.client_id = c.id WHERE cr.sms_remaining < (cr.sms_loaded * 0.2) AND cr.sms_loaded > 0 AND c.status = 'active'" );

// ── Recent leads ───────────────────────────────────────────────────────────
$recent_leads = $wpdb->get_results( "SELECT l.name, l.phone, l.created_at, c.business_name FROM {$p}ofp_leads l JOIN {$p}ofp_clients c ON c.id = l.client_id ORDER BY l.created_at DESC LIMIT 10" );

include OFP_PATH . 'admin/views/partials/header.php';
?>

<h2>Overview</h2>

<!-- Stat cards -->
<div class="ofp-stats-grid">
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( $total_active ); ?></span>
        <span class="ofp-stat-label">Active Clients</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number ofp-accent"><?php echo esc_html( $leads_today ); ?></span>
        <span class="ofp-stat-label">Leads Today</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( $leads_month ); ?></span>
        <span class="ofp-stat-label">Leads This Month</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( $sms_today ); ?></span>
        <span class="ofp-stat-label">SMS Sent Today</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( $calls_today ); ?></span>
        <span class="ofp-stat-label">Calls Made Today</span>
    </div>
    <div class="ofp-stat-card">
        <span class="ofp-stat-number"><?php echo esc_html( $pending_queue ); ?></span>
        <span class="ofp-stat-label">Queue Pending</span>
    </div>
</div>

<!-- Alerts -->
<?php if ( $total_pending > 0 || $failed_triggers > 0 || ! empty( $expiring_soon ) || ! empty( $low_credit ) ) : ?>
<div class="ofp-section">
    <h3>⚠️ Alerts</h3>
    <?php if ( $total_pending > 0 ) : ?>
        <div class="ofp-alert ofp-alert-warning">
            <?php echo esc_html( $total_pending ); ?> client(s) awaiting review.
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&filter=pending_review' ) ); ?>">Review now →</a>
        </div>
    <?php endif; ?>
    <?php if ( $failed_triggers > 0 ) : ?>
        <div class="ofp-alert ofp-alert-error">
            <?php echo esc_html( $failed_triggers ); ?> trigger(s) failed in the last 24 hours.
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-triggers&filter=failed' ) ); ?>">View →</a>
        </div>
    <?php endif; ?>
    <?php foreach ( $expiring_soon as $c ) : ?>
        <div class="ofp-alert ofp-alert-warning">
            <strong><?php echo esc_html( $c->business_name ); ?></strong> subscription expires on <?php echo esc_html( $c->subscription_expires ); ?>.
        </div>
    <?php endforeach; ?>
    <?php foreach ( $low_credit as $c ) : ?>
        <div class="ofp-alert ofp-alert-warning">
            <strong><?php echo esc_html( $c->business_name ); ?></strong> has low SMS credit.
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Recent Leads -->
<div class="ofp-section">
    <h3>Recent Leads</h3>
    <?php if ( empty( $recent_leads ) ) : ?>
        <p>No leads yet.</p>
    <?php else : ?>
        <table class="widefat ofp-table">
            <thead>
                <tr>
                    <th>Name</th><th>Phone</th><th>Client</th><th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $recent_leads as $lead ) : ?>
                    <tr>
                        <td><?php echo esc_html( $lead->name ?: '—' ); ?></td>
                        <td><?php echo esc_html( $lead->phone ); ?></td>
                        <td><?php echo esc_html( $lead->business_name ); ?></td>
                        <td><?php echo esc_html( human_time_diff( strtotime( $lead->created_at ) ) . ' ago' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include OFP_PATH . 'admin/views/partials/footer.php'; ?>
