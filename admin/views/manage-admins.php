<?php
/**
 * Admin View: Manage Admins (Super Admin Only)
 *
 * Lists all OFP admins. Enforces three-layer protection on super admin:
 *  - Layer 1: is_protected = 1 in DB (source of truth)
 *  - Layer 2: PHP handler blocks deletion (class-ofp-admin-menu.php)
 *  - Layer 3: UI suppresses delete button for protected rows (this file)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_super_admin() ) wp_die( 'Access denied.' );

global $wpdb;
$admins = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}ofp_admins ORDER BY is_protected DESC, created_at ASC"
);

$current_admin = OFP_Auth::current_admin();

include OFP_PATH . 'admin/views/partials/header.php';
?>

<h2>Manage Admins</h2>

<div class="ofp-info-box">
    <p>
        <strong>How admin access works:</strong> Each person listed here must ALSO have a
        WordPress user account (at wp-admin → Users) using the <strong>exact same email address</strong>.
        The email match is what grants them access to the OFast Pipeline menu after WordPress login.
    </p>
</div>

<?php
// Phase 22 — Pre-compute orphaned admin IDs for the warning box.
$orphaned_admin_ids = [];
foreach ( $admins as $a ) {
    if ( ! get_user_by( 'email', $a->email ) ) {
        $orphaned_admin_ids[] = (int) $a->id;
    }
}
?>

<?php if ( ! empty( $orphaned_admin_ids ) ) : ?>
    <div class="notice notice-warning" style="margin:12px 0 16px; padding:10px 14px; border-left:4px solid #dba617; background:#fff8e1;">
        <p>
            <strong>⚠️ <?php echo count( $orphaned_admin_ids ); ?> orphaned admin row<?php echo count( $orphaned_admin_ids ) > 1 ? 's' : ''; ?> detected.</strong>
            These OFP admin entries have no matching WordPress user account — they cannot log in.
            Either create a WP user with the same email, or delete the orphaned row below.
        </p>
    </div>
<?php endif; ?>

<!-- ── Current Admins ──────────────────────────────────────────────────────── -->
<div class="ofp-section">
    <h3>Current Admins</h3>
    <table class="widefat ofp-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Last Login</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $admins as $admin ) :
                $wp_user = get_user_by( 'email', $admin->email );
                $is_orphaned = ! $wp_user;
            ?>
                <tr class="<?php echo $admin->is_protected && ! $is_orphaned ? 'ofp-row-protected' : ''; ?>">
                    <td>
                        <strong><?php echo esc_html( $admin->name ); ?></strong>
                        <?php if ( $is_orphaned ) : ?>
                            <span class="ofp-badge ofp-badge-red">Orphaned Row</span>
                        <?php elseif ( $admin->is_protected ) : ?>
                            <span class="ofp-badge ofp-badge-blue">Protected</span>
                        <?php endif; ?>
                        <?php if ( $current_admin && (int) $admin->id === (int) $current_admin->id ) : ?>
                            <span class="ofp-badge ofp-badge-grey">You</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html( $admin->email ); ?>
                        <?php if ( $is_orphaned ) : ?><br><small style="color:#d63638;">No matching WP User found</small><?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $admin->role === 'super_admin' ) : ?>
                            <span class="ofp-badge ofp-badge-green">Super Admin</span>
                        <?php else : ?>
                            <span class="ofp-badge ofp-badge-yellow">Co-Admin</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo $admin->last_login
                            ? esc_html( human_time_diff( strtotime( $admin->last_login ) ) . ' ago' )
                            : '—'; ?>
                    </td>
                    <td>
                        <?php
                        $is_self      = $current_admin && (int) $admin->id === (int) $current_admin->id;
                        $can_delete   = ( ! $admin->is_protected || $is_orphaned ) && ! $is_self && ( $admin->role !== 'super_admin' || $is_orphaned );
                        ?>
                        <?php if ( $can_delete ) : ?>
                            <form method="POST"
                                  action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                  style="display:inline;">
                                <?php wp_nonce_field( 'ofp_delete_admin' ); ?>
                                <input type="hidden" name="action"   value="ofp_delete_admin">
                                <input type="hidden" name="admin_id" value="<?php echo esc_attr( $admin->id ); ?>">
                                <button type="submit"
                                        class="button ofp-btn-danger button-small"
                                        onclick="return confirm('Remove <?php echo esc_js( $admin->name ); ?> as an OFast Pipeline admin? Their WordPress account will not be deleted.')">
                                    Remove
                                </button>
                            </form>
                        <?php elseif ( $admin->is_protected ) : ?>
                            <span class="ofp-muted">Protected — cannot be removed</span>
                        <?php elseif ( $is_self ) : ?>
                            <span class="ofp-muted">Cannot remove yourself</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ── Add Co-Admin Form ───────────────────────────────────────────────────── -->
<div class="ofp-section">
    <h3>Add Co-Admin</h3>
    <p class="ofp-hint">
        Co-admins can manage clients, leads, and billing but cannot access Settings
        or Manage Admins. After adding them here, create a matching WordPress user
        account for them at <a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>" target="_blank">
        wp-admin → Users → Add New</a> using the same email address.
    </p>

    <form method="POST"
          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
          class="ofp-form">
        <?php wp_nonce_field( 'ofp_add_admin' ); ?>
        <input type="hidden" name="action" value="ofp_add_admin">

        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label for="ofp-admin-name">Full Name <span class="required">*</span></label>
                <input type="text"
                       id="ofp-admin-name"
                       name="name"
                       required
                       placeholder="e.g. Chioma Adeyemi">
            </div>

            <div class="ofp-field">
                <label for="ofp-admin-email">Email Address <span class="required">*</span></label>
                <input type="email"
                       id="ofp-admin-email"
                       name="email"
                       required
                       placeholder="chioma@example.com">
                <p class="ofp-hint">Must match their WordPress user account email exactly.</p>
            </div>

            <div class="ofp-field">
                <label for="ofp-admin-pass">Password <span class="required">*</span></label>
                <input type="password"
                       id="ofp-admin-pass"
                       name="password"
                       required
                       minlength="8"
                       placeholder="Minimum 8 characters">
                <p class="ofp-hint">
                    This is the OFP admin password — separate from their WordPress login password.
                    Share it with them securely.
                </p>
            </div>
        </div>

        <div class="ofp-form-actions">
            <button type="submit" class="button button-primary ofp-btn-primary">
                Add Co-Admin
            </button>
        </div>
    </form>
</div>

<!-- ── Permission Reference ───────────────────────────────────────────────── -->
<div class="ofp-section">
    <h3>Permission Reference</h3>
    <table class="widefat ofp-table">
        <thead>
            <tr>
                <th>Action</th>
                <th>Super Admin</th>
                <th>Co-Admin</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $permissions = [
                'View clients, leads, triggers, communications' => [ true,  true  ],
                'Add / edit clients'                           => [ true,  true  ],
                'Toggle client status'                         => [ true,  true  ],
                'Approve pending_review signups'               => [ true,  true  ],
                'View billing and payments'                     => [ true,  true  ],
                'Reset client password'                        => [ true,  true  ],
                'Access Settings page'                         => [ true,  false ],
                'Access Manage Admins page'                    => [ true,  false ],
                'Add a co-admin'                               => [ true,  false ],
                'Remove a co-admin'                            => [ true,  false ],
                'Delete super admin'                           => [ false, false ],
            ];
            foreach ( $permissions as $action => $access ) :
            ?>
                <tr>
                    <td><?php echo esc_html( $action ); ?></td>
                    <td><?php echo $access[0] ? '✅' : '❌'; ?></td>
                    <td><?php echo $access[1] ? '✅' : '❌'; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include OFP_PATH . 'admin/views/partials/footer.php'; ?>
