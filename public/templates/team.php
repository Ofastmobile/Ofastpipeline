<?php
/**
 * Template: /team
 * Client's Team Management Page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

if ( ! OFP_Subscription::has_paid_plan( $client->id ) ) {
    wp_safe_redirect( add_query_arg( 'upgrade', 'team', home_url( '/pricing' ) ) );
    exit;
}

// Only the main client can manage team members, or maybe a manager with permission.
// "team menu will only be seen by main clients or the sub admin"
$user_type = OFP_Auth::get_user_type();
$current_user = OFP_Auth::current_user();
$is_manager = ( $user_type === 'team_member' && isset( $current_user->role_name ) && $current_user->role_name === 'Manager' );

if ( $user_type !== 'client' && ! $is_manager ) {
    wp_die( 'Only the main account owner or Managers can manage team members.', 'Unauthorized', [ 'response' => 403 ] );
}

$team_members = OFP_Team_Member::get_all_for_client( $client->id );

$plan          = OFP_Subscription::feature_plan( $client->id );
$max_members   = OFP_Subscription::team_limit_for_client( $client->id );
$current_count = count( $team_members );
$can_invite    = ( $max_members > 0 && $current_count < $max_members );
$free_plan     = ( $plan === 'free' );

// Pass hero banner button configuration to nav.php
if ( $free_plan ) {
    $ofp_banner_btn = [
        'label'   => 'Upgrade to Add Team',
        'url'     => home_url( '/pricing' ),
        'onclick' => '',
        'icon'    => 'none',
    ];
} elseif ( $can_invite ) {
    $ofp_banner_btn = [
        'label'   => '+ Invite Member',
        'url'     => '#',
        'onclick' => 'if(typeof openInviteModal===\'function\'){ openInviteModal(); return false; }',
        'icon'    => 'none',
    ];
} else {
    $ofp_banner_btn = [
        'label'   => 'Limit Reached (' . $max_members . ')',
        'url'     => home_url( '/pricing' ),
        'onclick' => '',
        'icon'    => 'none',
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Team', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>



<?php
$avatar_gradients = [
    'linear-gradient(135deg, #3b82f6, #1d4ed8)',
    'linear-gradient(135deg, #10b981, #047857)',
    'linear-gradient(135deg, #f59e0b, #b45309)',
    'linear-gradient(135deg, #8b5cf6, #6d28d9)',
    'linear-gradient(135deg, #ec4899, #be185d)',
    'linear-gradient(135deg, #06b6d4, #0e7490)',
];
?>

    <div class="ofp-table-card" style="margin-top: 10px;">
        <div class="ofp-table-toolbar">
            <div class="ofp-table-search">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                <input type="text" id="ofp-team-search-input" placeholder="Search team members, role, status, contact...">
            </div>

            <div class="ofp-table-actions">
                <span class="ofp-btn ofp-btn-outline" style="font-size:12px; padding:6px 14px; pointer-events:none; font-weight:600;">
                    Team Members (<?php echo esc_html( $current_count . ( $max_members ? ' / ' . $max_members : '' ) ); ?>)
                </span>
            </div>
        </div>

        <?php if ( empty( $team_members ) ) : ?>
            <div style="padding:60px 24px; text-align:center;">
                <div style="font-size:44px; margin-bottom:14px; opacity:0.85;">👥</div>
                <h3 style="font-size:18px; font-weight:700; color:var(--text-main); margin-bottom:8px;">No team members yet</h3>
                <p style="color:var(--text-muted); font-size:14px; margin-bottom:24px; max-width:400px; margin-left:auto; margin-right:auto;">
                    Invite team members to assist with properties, buyer follow-ups, and customer communications.
                </p>
                <?php if ( ! $free_plan && $can_invite ) : ?>
                    <button class="ofp-btn ofp-btn-primary" onclick="openInviteModal()">+ Invite First Member</button>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <div class="ofp-table-wrap">
                <table class="ofp-data-table ofp-team-table" id="ofp-team-table">
                    <thead>
                        <tr>
                            <th>Team Member</th>
                            <th>Contact Info</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $idx = 0;
                        foreach ( $team_members as $member ) : 
                            $parts = preg_split( '/\s+/', trim( $member->name ) );
                            $inits = '';
                            if ( ! empty( $parts[0] ) ) $inits .= mb_substr( $parts[0], 0, 1 );
                            if ( ! empty( $parts[1] ) ) $inits .= mb_substr( $parts[1], 0, 1 );
                            if ( empty( $inits ) ) $inits = strtoupper( substr( $member->name, 0, 2 ) );
                            $inits = strtoupper( $inits );
                            $bg_grad = $avatar_gradients[ $idx % count( $avatar_gradients ) ];
                            $idx++;

                            $is_manager_role = strtolower( $member->role_name ?? '' ) === 'manager';
                            $role_badge_style = $is_manager_role 
                                ? 'background:rgba(59, 130, 246, 0.12); color:#3b82f6; border:1px solid rgba(59, 130, 246, 0.25);' 
                                : 'background:rgba(139, 92, 246, 0.12); color:#8b5cf6; border:1px solid rgba(139, 92, 246, 0.25);';
                        ?>
                            <tr>
                                <td>
                                    <div class="ofp-user-cell" style="display:flex; align-items:center; gap:12px;">
                                        <div class="ofp-user-thumb" style="display:flex; align-items:center; justify-content:center; background:<?php echo esc_attr( $bg_grad ); ?>; color:#fff; font-weight:700; font-size:12px; border-radius:8px; width:36px; height:36px; flex-shrink:0; box-shadow:0 2px 8px rgba(0,0,0,0.15);">
                                            <span><?php echo esc_html( $inits ); ?></span>
                                        </div>
                                        <div class="ofp-user-meta">
                                            <span class="ofp-user-title" style="font-weight:600; font-size:14px; color:var(--text-main); display:block;"><?php echo esc_html( $member->name ); ?></span>
                                            <span class="ofp-user-sub" style="font-size:12px; color:var(--text-muted); display:block;">Member #<?php echo (int) $member->id; ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-size:13px; font-weight:500; color:var(--text-main);"><?php echo esc_html( $member->email ); ?></div>
                                    <?php if ( ! empty( $member->phone ) ) : ?>
                                        <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">
                                            <?php echo esc_html( $member->phone ); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="ofp-tbl-status-pill" style="<?php echo esc_attr( $role_badge_style ); ?> font-weight:600;">
                                        <?php echo esc_html( $member->role_name ); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ( $member->status === 'pending' ) : ?>
                                        <span class="ofp-tbl-status-pill pending" style="background:rgba(245, 158, 11, 0.14); color:#d97706; border:1px solid rgba(245, 158, 11, 0.25);">Pending</span>
                                    <?php else : ?>
                                        <span class="ofp-tbl-status-pill active" style="background:rgba(16, 185, 129, 0.12); color:#10b981; border:1px solid rgba(16, 185, 129, 0.25);">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <button class="ofp-btn ofp-btn-outline" style="font-size:12px; padding:5px 12px; border-radius:7px;" onclick="openEditModal(<?php echo esc_attr( wp_json_encode( $member ) ); ?>)">Edit</button>
                                    <button class="ofp-btn ofp-btn-outline" style="font-size:12px; padding:5px 12px; border-radius:7px; color:#ef4444; border-color:rgba(239, 68, 68, 0.35); margin-left:6px;" onclick="deleteMember(<?php echo (int) $member->id; ?>)">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>


    <!-- Invite Modal -->
    <div id="inviteModal" class="ofp-modal">
        <div class="ofp-modal-content">
            <div class="ofp-modal-header">
                <h3 class="ofp-modal-title">Invite Team Member</h3>
                <button class="ofp-modal-close" onclick="closeInviteModal()">&times;</button>
            </div>
            <form id="inviteForm" onsubmit="submitInvite(event)">
                <div class="ofp-form-group">
                    <label>Full Name *</label>
                    <input type="text" name="name" required>
                </div>
                <div class="ofp-form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" required>
                </div>
                <div class="ofp-form-group">
                    <label>Phone Number * (Must be WhatsApp ready)</label>
                    <input type="tel" name="phone" required>
                </div>
                
                <div class="ofp-form-group">
                    <label>Role</label>
                    <select name="role_name" id="inviteRoleSelect" onchange="togglePermissions('invite')">
                        <option value="Manager">Manager</option>
                        <option value="Agent">Agent</option>
                    </select>
                </div>

                <div class="ofp-form-group" id="invitePermissionsWrap" style="display:none;">
                    <label>Permissions (Agent)</label>
                    <div class="ofp-permissions-grid">
                        <label class="ofp-permission-item"><input type="checkbox" name="permissions[]" value="view_leads"> View Leads</label>
                        <label class="ofp-permission-item"><input type="checkbox" name="permissions[]" value="edit_leads"> Edit Leads</label>
                        <label class="ofp-permission-item"><input type="checkbox" name="permissions[]" value="send_messages"> Send Messages</label>
                        <label class="ofp-permission-item"><input type="checkbox" name="permissions[]" value="view_reports"> View Reports</label>
                    </div>
                    <p style="font-size:12px;color:var(--text-muted);margin-top:8px;">Managers have full access automatically.</p>
                </div>

                <button type="submit" class="ofp-btn ofp-btn-primary" style="width:100%;margin-top:16px;">Send Invitation</button>
                <p style="font-size:12px;color:var(--text-muted);margin-top:12px;text-align:center;">Sending an invite deducts 1 SMS credit.</p>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="ofp-modal">
        <div class="ofp-modal-content">
            <div class="ofp-modal-header">
                <h3 class="ofp-modal-title">Edit Team Member</h3>
                <button class="ofp-modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form id="editForm" onsubmit="submitEdit(event)">
                <input type="hidden" name="team_member_id" id="edit_team_member_id">
                
                <div class="ofp-form-group">
                    <label>Role</label>
                    <select name="role_name" id="editRoleSelect" onchange="togglePermissions('edit')">
                        <option value="Manager">Manager</option>
                        <option value="Agent">Agent</option>
                    </select>
                </div>

                <div class="ofp-form-group" id="editPermissionsWrap" style="display:none;">
                    <label>Permissions (Agent)</label>
                    <div class="ofp-permissions-grid">
                        <label class="ofp-permission-item"><input type="checkbox" name="permissions[]" value="view_leads" id="perm_view_leads"> View Leads</label>
                        <label class="ofp-permission-item"><input type="checkbox" name="permissions[]" value="edit_leads" id="perm_edit_leads"> Edit Leads</label>
                        <label class="ofp-permission-item"><input type="checkbox" name="permissions[]" value="send_messages" id="perm_send_messages"> Send Messages</label>
                        <label class="ofp-permission-item"><input type="checkbox" name="permissions[]" value="view_reports" id="perm_view_reports"> View Reports</label>
                    </div>
                </div>

                <button type="submit" class="ofp-btn ofp-btn-primary" style="width:100%;margin-top:16px;">Save Changes</button>
            </form>
        </div>
    </div>


    <script>
        const nonce = '<?php echo esc_js( wp_create_nonce( 'ofp_client_action' ) ); ?>';

        function togglePermissions(type) {
            const role = document.getElementById(type + 'RoleSelect').value;
            document.getElementById(type + 'PermissionsWrap').style.display = (role === 'Agent') ? 'block' : 'none';
        }

        function openInviteModal() {
            document.getElementById('inviteModal').classList.add('active');
            togglePermissions('invite');
        }

        function closeInviteModal() {
            document.getElementById('inviteModal').classList.remove('active');
        }

        function openEditModal(member) {
            document.getElementById('edit_team_member_id').value = member.id;
            document.getElementById('editRoleSelect').value = member.role_name;
            
            // Clear checkboxes
            document.querySelectorAll('#editPermissionsWrap input[type="checkbox"]').forEach(cb => cb.checked = false);
            
            // Check based on data
            if (member.permissions) {
                try {
                    const perms = JSON.parse(member.permissions);
                    if (Array.isArray(perms)) {
                        perms.forEach(p => {
                            const cb = document.getElementById('perm_' + p);
                            if (cb) cb.checked = true;
                        });
                    }
                } catch(e) {}
            }
            
            document.getElementById('editModal').classList.add('active');
            togglePermissions('edit');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        async function submitInvite(e) {
            e.preventDefault();
            const form = e.target;
            const btn = form.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.innerText = 'Inviting...';

            const formData = new FormData(form);
            formData.append('action', 'ofp_invite_team_member');
            formData.append('nonce', nonce);

            try {
                const res = await fetch(ofpClientData.ajaxurl, { method: 'POST', body: formData });
                const json = await res.json();
                if (json.success) {
                    alert('Invitation sent successfully!');
                    window.location.reload();
                } else {
                    alert('Error: ' + json.data);
                    btn.disabled = false;
                    btn.innerText = 'Send Invitation';
                }
            } catch (err) {
                alert('Connection error');
                btn.disabled = false;
                btn.innerText = 'Send Invitation';
            }
        }

        async function submitEdit(e) {
            e.preventDefault();
            const form = e.target;
            const btn = form.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.innerText = 'Saving...';

            const formData = new FormData(form);
            formData.append('action', 'ofp_update_team_member');
            formData.append('nonce', nonce);

            try {
                const res = await fetch(ofpClientData.ajaxurl, { method: 'POST', body: formData });
                const json = await res.json();
                if (json.success) {
                    alert('Team member updated!');
                    window.location.reload();
                } else {
                    alert('Error: ' + json.data);
                    btn.disabled = false;
                    btn.innerText = 'Save Changes';
                }
            } catch (err) {
                alert('Connection error');
                btn.disabled = false;
                btn.innerText = 'Save Changes';
            }
        }

        async function deleteMember(id) {
            if (!confirm('Are you sure you want to remove this team member? They will lose access immediately.')) return;
            
            const formData = new FormData();
            formData.append('action', 'ofp_delete_team_member');
            formData.append('nonce', nonce);
            formData.append('team_member_id', id);

            try {
                const res = await fetch(ofpClientData.ajaxurl, { method: 'POST', body: formData });
                const json = await res.json();
                if (json.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + json.data);
                }
            } catch (err) {
                alert('Connection error');
            }
        }

        // Live search filter for team table
        (function() {
            var searchInput = document.getElementById('ofp-team-search-input');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var q = this.value.toLowerCase().trim();
                    var rows = document.querySelectorAll('#ofp-team-table tbody tr');
                    rows.forEach(function(r) {
                        var txt = r.textContent.toLowerCase();
                        r.style.display = txt.indexOf(q) !== -1 ? '' : 'none';
                    });
                });
            }
        })();
    </script>

</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
