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

$plan          = OFP_Subscription::client_plan( $client->id );
$max_members   = OFP_Subscription::team_member_limit( $plan );
$current_count = count( $team_members );
$can_invite    = ( $max_members > 0 && $current_count < $max_members );
$free_plan     = ( $plan === 'free' );

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team — OFast Pipeline</title>
    <!-- Dark theme script to avoid FOUC -->
    <script>
        (function() {
            var currentTheme = localStorage.getItem('ofp_theme') || 'dark';
            if (currentTheme === 'light') { document.documentElement.setAttribute('data-theme', 'light'); }
        })();
    </script>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <style>
        .ofp-team-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .ofp-team-table { width: 100%; border-collapse: collapse; background: var(--bg-card); border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color); }
        .ofp-team-table th, .ofp-team-table td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border-color); }
        .ofp-team-table th { font-weight: 600; color: var(--text-muted); font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .ofp-team-table tr:last-child td { border-bottom: none; }
        .ofp-badge-pending { background: #fef08a; color: #854d0e; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .ofp-badge-active { background: #bbf7d0; color: #166534; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        
        .ofp-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .ofp-modal.active { display: flex; }
        .ofp-modal-content { background: var(--bg-card); width: 100%; max-width: 500px; border-radius: 12px; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); border: 1px solid var(--border-color); }
        .ofp-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .ofp-modal-title { font-size: 18px; font-weight: 700; color: var(--text-main); }
        .ofp-modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted); }
        .ofp-form-group { margin-bottom: 16px; }
        .ofp-form-group label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: var(--text-main); }
        .ofp-form-group input, .ofp-form-group select { width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-body); color: var(--text-main); }
        
        .ofp-permissions-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
        .ofp-permission-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-main); }
    </style>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-team-header">
        <div>
            <h1 style="font-size:24px;font-weight:700;color:var(--text-main);margin-bottom:8px;">Team Members</h1>
            <p style="color:var(--text-muted);font-size:14px;">Manage your team and control access permissions.</p>
        </div>
        <div>
            <?php if ( $free_plan ) : ?>
                <button class="ofp-btn" disabled style="opacity:0.5;" title="Upgrade to Pro or Gold to add team members">Upgrade to Add Team</button>
            <?php elseif ( $can_invite ) : ?>
                <button class="ofp-btn" onclick="openInviteModal()">+ Invite Member</button>
            <?php else : ?>
                <button class="ofp-btn" disabled style="opacity:0.5;" title="Team member limit reached for your plan">Limit Reached (<?php echo esc_html($max_members); ?>)</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( empty( $team_members ) ) : ?>
        <div style="background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;padding:40px;text-align:center;">
            <div style="font-size:40px;margin-bottom:16px;">👥</div>
            <h3 style="font-size:18px;font-weight:600;color:var(--text-main);margin-bottom:8px;">No team members yet</h3>
            <p style="color:var(--text-muted);font-size:14px;margin-bottom:24px;">Invite members to help manage your leads and communications.</p>
            <?php if ( ! $free_plan && $can_invite ) : ?>
                <button class="ofp-btn" onclick="openInviteModal()">Invite First Member</button>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <table class="ofp-team-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $team_members as $member ) : 
                    $perms = $member->permissions ? json_decode( $member->permissions, true ) : [];
                ?>
                    <tr>
                        <td>
                            <div style="font-weight:600;color:var(--text-main);"><?php echo esc_html( $member->name ); ?></div>
                        </td>
                        <td>
                            <div style="font-size:13px;color:var(--text-main);"><?php echo esc_html( $member->email ); ?></div>
                            <div style="font-size:12px;color:var(--text-muted);"><?php echo esc_html( $member->phone ); ?></div>
                        </td>
                        <td>
                            <span style="font-weight:600;font-size:13px;color:var(--text-main);"><?php echo esc_html( $member->role_name ); ?></span>
                        </td>
                        <td>
                            <?php if ( $member->status === 'pending' ) : ?>
                                <span class="ofp-badge-pending">Pending</span>
                            <?php else : ?>
                                <span class="ofp-badge-active">Active</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="ofp-btn ofp-btn-small" style="background:transparent;color:var(--text-main);border:1px solid var(--border-color);" onclick="openEditModal(<?php echo esc_attr( wp_json_encode( $member ) ); ?>)">Edit</button>
                            <button class="ofp-btn ofp-btn-small" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;margin-left:8px;" onclick="deleteMember(<?php echo (int) $member->id; ?>)">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>


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

                <button type="submit" class="ofp-btn" style="width:100%;margin-top:16px;">Send Invitation</button>
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

                <button type="submit" class="ofp-btn" style="width:100%;margin-top:16px;">Save Changes</button>
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
    </script>

</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
