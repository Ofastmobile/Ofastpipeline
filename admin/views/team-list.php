<?php
/**
 * Admin View: Global Team Members List
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

global $wpdb;
$p = $wpdb->prefix;

$team_members = $wpdb->get_results(
    "SELECT t.*, c.business_name
     FROM {$p}ofp_team_members t
     LEFT JOIN {$p}ofp_clients c ON t.client_id = c.id
     ORDER BY t.created_at DESC"
);

include OFP_PATH . 'admin/views/partials/header.php';
?>

<div class="ofp-section">
    <div class="ofp-section-header">
        <h2>Global Team Members</h2>
    </div>

    <p style="margin-bottom:20px; color:#6b7280;">This page provides a global read-only view of all team members across all clients. Team member invitations and permissions are managed by the clients inside their portal.</p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Name</th>
                <th>Client (Business)</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Status</th>
                <th>Joined</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $team_members ) ) : ?>
                <tr>
                    <td colspan="7">No team members found in the system.</td>
                </tr>
            <?php else : ?>
                <?php foreach ( $team_members as $member ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $member->name ); ?></strong></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&client_id=' . $member->client_id ) ); ?>">
                                <?php echo esc_html( $member->business_name ?: 'Unknown Client ID ' . $member->client_id ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( $member->email ); ?></td>
                        <td><?php echo esc_html( $member->phone ); ?></td>
                        <td><?php echo esc_html( $member->role_name ); ?></td>
                        <td>
                            <?php if ( $member->status === 'pending' ) : ?>
                                <span class="ofp-badge ofp-badge-yellow">Pending</span>
                            <?php else : ?>
                                <span class="ofp-badge ofp-badge-green">Active</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $member->created_at ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
