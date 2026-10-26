<?php
/**
 * Admin View: Clients List + Add/Edit Client Forms
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

$action     = sanitize_text_field( $_GET['action'] ?? '' );
$filter     = sanitize_text_field( $_GET['filter'] ?? '' );
$client_id  = (int) ( $_GET['client_id'] ?? 0 );

$client = $client_id ? OFP_Client::get( $client_id ) : null;

// Clients list — trash uses a dedicated method, everything else uses all()/filter.
$clients = $filter === 'trash'
    ? OFP_Client::get_trashed()
    : ( $filter ? OFP_Client::all( $filter ) : OFP_Client::all() );

$trash_count = count( OFP_Client::get_trashed() );

$status_labels = [
    'active'         => '<span class="ofp-badge ofp-badge-green">Active</span>',
    'pending_review' => '<span class="ofp-badge ofp-badge-yellow">Pending Review</span>',
    'grace'          => '<span class="ofp-badge ofp-badge-orange">Grace Period</span>',
    'suspended'      => '<span class="ofp-badge ofp-badge-red">Suspended</span>',
    'cancelled'      => '<span class="ofp-badge ofp-badge-grey">Cancelled</span>',
    'trash'          => '<span class="ofp-badge ofp-badge-grey">🗑 Trash</span>',
];

include OFP_PATH . 'admin/views/partials/header.php';
?>

<?php if ( $action === 'add' ) : ?>
<!-- ── ADD CLIENT FORM ──────────────────────────────────────────────────── -->
<div class="ofp-section">
    <h2>Add New Client</h2>
    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients' ) ); ?>">← Back to Clients</a></p>

    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ofp-form">
        <?php wp_nonce_field( 'ofp_add_client' ); ?>
        <input type="hidden" name="action" value="ofp_add_client">

        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label>Business Name <span class="required">*</span></label>
                <input type="text" name="business_name" required placeholder="e.g. Lekki Homes Realty">
            </div>
            <div class="ofp-field">
                <label>Owner Full Name <span class="required">*</span></label>
                <input type="text" name="owner_name" required placeholder="e.g. Mr. Adewale Johnson">
            </div>
            <div class="ofp-field">
                <label>Email Address <span class="required">*</span></label>
                <input type="email" name="email" required placeholder="owner@business.com">
                <p class="ofp-hint">Login credentials will be sent to this email.</p>
            </div>
            <div class="ofp-field">
                <label>Primary Phone <span class="required">*</span></label>
                <input type="tel" name="phone" required placeholder="08012345678">
            </div>
            <div class="ofp-field">
                <label>Business Phone (for IVR transfer)</label>
                <input type="tel" name="business_phone" placeholder="Same as primary if blank">
            </div>
            <div class="ofp-field">
                <label>WhatsApp Number</label>
                <input type="tel" name="whatsapp_number" placeholder="Same as primary if blank">
            </div>


        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label>Subdomain <span class="required">*</span></label>
                <input type="text" name="subdomain" required placeholder="supremehomes">
                <small style="color:#6b7280;">Their dashboard address: name.yourdomain.com. At least 3 letters or numbers.</small>
            </div>
            <div class="ofp-field" id="ofp-wl-field">
                <label>White Label (Solo Company)</label>
                <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;">
                    <input type="checkbox" name="is_white_label" id="ofp-wl-checkbox" value="1" style="margin-top:3px;">
                    <span>No plan, no renewals, no limits. Their properties show only on their own address and never on your main marketplace.</span>
                </label>
                <input type="hidden" name="ofp_wl_fields" value="1">
            </div>
            <div class="ofp-field" id="ofp-domain-field" style="display:none;">
                <label>Custom Domain (optional)</label>
                <input type="text" name="custom_domain" placeholder="crm.supremehomes.com">
                <small style="color:#6b7280;">Only the domain. Leave empty to use the subdomain only.</small>
            </div>
            <div class="ofp-field" id="ofp-plan-field">
                <label>Plan</label>
                <select name="plan" class="ofp-select">
                    <option value="free">Free</option>
                    <option value="silver">Silver</option>
                    <option value="gold">Gold</option>
                </select>
            </div>
        </div>
        <script>
        (function () {
            var box = document.getElementById('ofp-wl-checkbox');
            if (!box) return;
            function sync() {
                var on = box.checked;
                document.getElementById('ofp-plan-field').style.display   = on ? 'none' : '';
                document.getElementById('ofp-domain-field').style.display = on ? '' : 'none';
                if (on) { var sel = document.querySelector('#ofp-plan-field select'); if (sel) sel.value = 'free'; }
            }
            box.addEventListener('change', sync);
            sync();
        })();
        </script>



        <div class="ofp-form-actions">
            <button type="submit" class="button button-primary ofp-btn-primary">
                Create Client & Send Welcome Email
            </button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients' ) ); ?>" class="button">Cancel</a>
        </div>
    </form>
</div>

<?php elseif ( $action === 'edit' && $client ) : ?>
<!-- ── EDIT CLIENT FORM ─────────────────────────────────────────────────── -->
<div class="ofp-section">
    <h2>Edit Client — <?php echo esc_html( $client->business_name ); ?></h2>
    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&client_id=' . $client->id ) ); ?>">← Back to Client</a></p>

    <div class="ofp-info-box">
        Correct any details below. This is especially useful for self-serve signups
        where the client may have entered incorrect information. Email changes are
        checked for duplicates before saving.
    </div>

    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ofp-form">
        <?php wp_nonce_field( 'ofp_edit_client' ); ?>
        <input type="hidden" name="action" value="ofp_edit_client">
        <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">

        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label>Business Name <span class="required">*</span></label>
                <input type="text" name="business_name" required value="<?php echo esc_attr( $client->business_name ); ?>">
            </div>
            <div class="ofp-field">
                <label>Owner Full Name <span class="required">*</span></label>
                <input type="text" name="owner_name" required value="<?php echo esc_attr( $client->owner_name ); ?>">
            </div>
            <div class="ofp-field">
                <label>Email Address <span class="required">*</span></label>
                <input type="email" name="email" required value="<?php echo esc_attr( $client->email ); ?>">
            </div>
            <div class="ofp-field">
                <label>Primary Phone <span class="required">*</span></label>
                <input type="tel" name="phone" required value="<?php echo esc_attr( $client->phone ); ?>">
            </div>
            <div class="ofp-field">
                <label>Business Phone</label>
                <input type="tel" name="business_phone" value="<?php echo esc_attr( $client->business_phone ); ?>">
            </div>
            <div class="ofp-field">
                <label>WhatsApp Number</label>
                <input type="tel" name="whatsapp_number" value="<?php echo esc_attr( $client->whatsapp_number ); ?>">
            </div>
            <div class="ofp-field">
                <label>Plan</label>
                <?php
                // Map old CRM plan keys to unified plan keys
                $edit_plan_map = ['starter'=>'free','growth'=>'silver','pro'=>'gold','bronze'=>'free'];
                $edit_plan = $edit_plan_map[$client->plan] ?? $client->plan;
                ?>
                <select name="plan" class="ofp-select">
                    <option value="free"   <?php selected( $edit_plan, 'free' ); ?>>Free</option>
                    <option value="silver" <?php selected( $edit_plan, 'silver' ); ?>>Silver</option>
                    <option value="gold"   <?php selected( $edit_plan, 'gold' ); ?>>Gold</option>
                </select>
            </div>
            <div class="ofp-field">
                <label>Subdomain</label>
                <input type="text" name="subdomain" value="<?php echo esc_attr( $client->subdomain ); ?>" placeholder="supremehomes">
                <small style="color:#6b7280;">Their dashboard address. Changing it breaks any link already shared.</small>
            </div>
            <div class="ofp-field">
                <label>Custom Domain</label>
                <input type="text" name="custom_domain" value="<?php echo esc_attr( $client->custom_domain ?? '' ); ?>" placeholder="crm.supremehomes.com">
                <small style="color:#6b7280;">Only the domain, no https:// and no path. The client points their DNS to your server first.</small>
            </div>
            <div class="ofp-field">
                <label>White Label (Solo Company)</label>
                <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;">
                    <input type="checkbox" id="ofp-edit-wl-checkbox" name="is_white_label" value="1" <?php checked( ! empty( $client->is_white_label ) ); ?> style="margin-top:3px;">
                    <span>Their properties show only on their own domain and never on your main marketplace.</span>
                </label>
                <input type="hidden" name="ofp_wl_fields" value="1">
            </div>
        </div>

        <div class="ofp-form-actions">
            <button type="submit" class="button button-primary ofp-btn-primary">Save Changes</button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&client_id=' . $client->id ) ); ?>" class="button">Cancel</a>
        </div>
    </form>
</div>

<?php
$wl_is_active = ! empty( $client->is_white_label );
$wl_sum  = OFP_White_Label::summary( $client );
$wl_type = OFP_White_Label::billing_type( $client );
$wl_post = esc_url( admin_url( 'admin-post.php' ) );
?>
<!-- ── WHITE LABEL BILLING (paid by hand) ───────────────────────────────── -->
<div class="ofp-section" id="ofp-wl-billing-section" style="margin-top:24px;<?php echo empty( $client->is_white_label ) ? 'display:none;' : ''; ?>">
    <h2>White Label Billing</h2>
    <div class="ofp-info-box">
        <?php if ( $wl_type === '' ) : ?>
            <strong>No billing set.</strong> Record their first payment below to start Yearly or Lifetime billing.
        <?php elseif ( $wl_type === 'yearly' ) : ?>
            <strong>Yearly.</strong>
            <?php echo OFP_White_Label::yearly_expired( $client )
                ? 'Ended on ' . esc_html( (string) $client->subscription_expires ) . '. Dashboard is on Free limits. Website and domain still work.'
                : 'Active until ' . esc_html( (string) $client->subscription_expires ) . ' (' . esc_html( $wl_sum['note'] ) . ').'; ?>
        <?php else : ?>
            <strong>Lifetime.</strong> Maintenance fee: <?php echo esc_html( $wl_sum['note'] ); ?>.
        <?php endif; ?>
    </div>

    <form method="POST" action="<?php echo $wl_post; ?>" class="ofp-form" style="margin-bottom:16px;">
        <?php wp_nonce_field( 'ofp_wl_billing' ); ?>
        <input type="hidden" name="action" value="ofp_wl_billing">
        <input type="hidden" name="wl_do" value="mark_paid">
        <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">
        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label>Billing Type</label>
                <select name="wl_type" class="ofp-select">
                    <option value="yearly"   <?php selected( $wl_type, 'yearly' ); ?>>Yearly (365 days)</option>
                    <option value="lifetime" <?php selected( $wl_type, 'lifetime' ); ?>>Lifetime</option>
                </select>
            </div>
            <div class="ofp-field">
                <label>Amount Received (NGN) <span class="required">*</span></label>
                <input type="number" name="wl_amount" min="1" step="0.01" required placeholder="e.g. 400000">
            </div>
            <div class="ofp-field">
                <label>Note (optional)</label>
                <input type="text" name="wl_note" placeholder="Bank reference or any note">
            </div>
            <div class="ofp-field">
                <label>Starting Credit</label>
                <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;">
                    <input type="checkbox" name="wl_credits" value="1" <?php checked( $wl_type === '' ); ?> style="margin-top:3px;">
                    <span>Add starting credit: NGN <?php echo esc_html( number_format( OFP_White_Label::starting_credit( 'yearly' ), 0 ) ); ?> SMS + NGN <?php echo esc_html( number_format( OFP_White_Label::starting_credit( 'yearly' ), 0 ) ); ?> voice (Yearly), NGN <?php echo esc_html( number_format( OFP_White_Label::starting_credit( 'lifetime' ), 0 ) ); ?> each (Lifetime). Leave off when renewing.</span>
                </label>
            </div>
        </div>
        <div class="ofp-form-actions">
            <button type="submit" class="button button-primary ofp-btn-primary" onclick="return confirm('Record this payment and tell the client by email and SMS?');">Mark as Paid</button>
        </div>
    </form>

    <?php if ( $wl_type === 'lifetime' ) : ?>
    <form method="POST" action="<?php echo $wl_post; ?>" class="ofp-form" style="margin-bottom:16px;">
        <?php wp_nonce_field( 'ofp_wl_billing' ); ?>
        <input type="hidden" name="action" value="ofp_wl_billing">
        <input type="hidden" name="wl_do" value="maintenance">
        <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">
        <div class="ofp-form-grid">
            <div class="ofp-field">
                <label>Maintenance Fee Received (NGN)</label>
                <input type="number" name="wl_amount" min="1" step="0.01" required placeholder="e.g. 100000">
            </div>
            <div class="ofp-field">
                <label>Note (optional)</label>
                <input type="text" name="wl_note">
            </div>
        </div>
        <div class="ofp-form-actions">
            <button type="submit" class="button" onclick="return confirm('Record this maintenance payment?');">Mark Maintenance as Paid</button>
        </div>
    </form>
    <?php endif; ?>

    <?php if ( $wl_type !== '' ) : ?>
    <form method="POST" action="<?php echo $wl_post; ?>" onsubmit="return confirm('Remove billing? This client goes back to unlimited with nothing to pay or track.');">
        <?php wp_nonce_field( 'ofp_wl_billing' ); ?>
        <input type="hidden" name="action" value="ofp_wl_billing">
        <input type="hidden" name="wl_do" value="clear">
        <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">
        <button type="submit" class="button-link" style="color:#b91c1c;">Remove billing (back to unlimited)</button>
    </form>
    <?php endif; ?>
</div>
<script>
(function () {
    var box = document.getElementById('ofp-edit-wl-checkbox');
    var wlSection = document.getElementById('ofp-wl-billing-section');
    if (!box || !wlSection) return;
    function sync() {
        wlSection.style.display = box.checked ? '' : 'none';
    }
    box.addEventListener('change', sync);
    sync();
})();
</script>

<?php elseif ( $client ) : ?>
<!-- ── CLIENT DETAIL VIEW ────────────────────────────────────────────────── -->
<div class="ofp-section">
    <h2><?php echo esc_html( $client->business_name ); ?></h2>
    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients' ) ); ?>">← Back to Clients</a></p>

    <?php $temp_password = OFP_Client::get_temp_password( $client->id ); ?>
    <?php if ( $temp_password ) : ?>
        <div class="ofp-alert ofp-alert-warning" style="margin-bottom:16px;">
            🔑 <strong>Temporary password (visible for 1 hour after creation):</strong>
            <code style="background:#fff;padding:3px 10px;border-radius:4px;margin-left:6px;"><?php echo esc_html( $temp_password ); ?></code>
            <br><span style="font-size:12px;">Use this if the welcome email has not arrived yet (e.g. SMTP not configured locally).</span>
        </div>
    <?php endif; ?>

    <div class="ofp-detail-grid">
        <div><strong>Client ID:</strong> <code><?php echo esc_html( $client->id ); ?></code></div>
        <div style="grid-column:1/-1;"><strong>Lead Capture Endpoint (API):</strong> <code><?php echo esc_html( home_url( '/wp-json/ofp/v1/capture-lead' ) ); ?></code></div>
        <div><strong>Owner:</strong> <?php echo esc_html( $client->owner_name ); ?></div>
        <div><strong>Email:</strong> <?php echo esc_html( $client->email ); ?></div>
        <div><strong>Phone:</strong> <?php echo esc_html( $client->phone ); ?></div>
        <div><strong>Status:</strong> <?php echo $status_labels[ $client->status ] ?? esc_html( $client->status ); ?></div>
        <?php
        $detail_plan_map = ['free' => 'Free', 'silver' => 'Silver', 'gold' => 'Gold'];
        $detail_plan_label = $detail_plan_map[$client->plan] ?? ucfirst($client->plan ?: '—');
        ?>
        <div><strong>Plan:</strong> <?php echo esc_html( $detail_plan_label ); ?></div>
        <?php
        $detail_sms_all   = OFP_SMS::providers();
        $detail_sms_label = $detail_sms_all[ OFP_SMS::platform_provider() ]['label'] ?? '—';
        ?>
        <div><strong>SMS Provider (platform wide):</strong> <?php echo esc_html( $detail_sms_label ); ?></div>
        <?php if ( ! empty( $client->is_white_label ) && OFP_White_Label::billing_type( $client ) !== '' ) : $wl_s = OFP_White_Label::summary( $client ); ?>
        <div><strong>White Label Billing:</strong> <?php echo esc_html( ucfirst( $wl_s['type'] ) ); ?> — <?php echo esc_html( $wl_s['note'] ); ?></div>
        <div><strong><?php echo $wl_s['type'] === 'lifetime' ? 'Maintenance Due' : 'Subscription Expires'; ?>:</strong> <?php echo esc_html( $wl_s['type'] === 'lifetime' ? ( $wl_s['due'] ?: '—' ) : ( $wl_s['expires'] ?: '—' ) ); ?></div>
        <?php else : ?>
        <div><strong>Subscription Expires:</strong> <?php echo esc_html( $client->subscription_expires ?: '—' ); ?></div>
        <?php endif; ?>
        <div>
            <strong>KYC / KYB Status:</strong>
            <?php if ( ! empty( $client->is_verified ) ) : ?>
                <span style="color:#15803d; font-weight:700;">
                    ✅ Verified (CAC: <?php echo esc_html( $client->cac_number ?: 'Yes' ); ?><?php echo ! empty( $client->verified_company_name ) ? ' — ' . esc_html( $client->verified_company_name ) : ''; ?>)
                </span>
            <?php else : ?>
                <span style="color:#9ca3af;">Unverified</span>
            <?php endif; ?>
        </div>

        <div><strong>Created:</strong> <?php echo esc_html( $client->created_at ); ?></div>
        <div><strong>Source:</strong> <?php echo esc_html( $client->onboarding_source ); ?></div>
        <?php
        $base_domain      = get_option( 'ofp_crm_base_domain', '' );
        $client_subdomain = ! empty( $client->subdomain ) ? trim( $client->subdomain ) : '';
        if ( $client_subdomain && $base_domain ) :
            $full_domain_url = 'https://' . $client_subdomain . '.' . $base_domain;
        ?>
        <div style="grid-column:1/-1;">
            <strong>Domain:</strong>
            <a href="<?php echo esc_url( $full_domain_url ); ?>" target="_blank" style="color:#1a73e8;">
                <?php echo esc_html( $client_subdomain . '.' . $base_domain ); ?>
            </a>
        </div>
        <?php elseif ( $client_subdomain ) : ?>
        <div><strong>Subdomain Slug:</strong> <?php echo esc_html( $client_subdomain ); ?> <span style="color:#9ca3af;">(base domain not set in Settings)</span></div>
        <?php else : ?>
        <div><strong>Domain:</strong> <span style="color:#9ca3af;">Not set</span></div>
        <?php endif; ?>
        <?php if ( ! empty( $client->custom_domain ) ) : ?>
        <div><strong>Custom Domain:</strong> <?php echo esc_html( $client->custom_domain ); ?></div>
        <?php endif; ?>
        <?php if ( ! empty( $client->is_white_label ) ) : ?>
        <div><strong>White Label:</strong> <span style="color:#15803d;font-weight:600;">On</span><?php echo OFP_White_Label::billing_type( $client ) === '' ? ' <span style="color:#6b7280;">(no billing set, unlimited)</span>' : ''; ?></div>
        <?php endif; ?>
    </div>

    <?php $stats = OFP_Client::get_stats( $client->id ); ?>
    <div class="ofp-stats-grid ofp-stats-mini">
        <div class="ofp-stat-card"><span class="ofp-stat-number"><?php echo esc_html( $stats['total_leads'] ); ?></span><span class="ofp-stat-label">Total Leads</span></div>
        <div class="ofp-stat-card"><span class="ofp-stat-number"><?php echo esc_html( $stats['leads_today'] ); ?></span><span class="ofp-stat-label">Today</span></div>
        <div class="ofp-stat-card"><span class="ofp-stat-number"><?php echo esc_html( $stats['leads_converted'] ); ?></span><span class="ofp-stat-label">Converted</span></div>
        <div class="ofp-stat-card"><span class="ofp-stat-number"><?php echo esc_html( $stats['sms_sent'] ); ?></span><span class="ofp-stat-label">SMS Sent</span></div>
        <div class="ofp-stat-card"><span class="ofp-stat-number"><?php echo esc_html( $stats['calls_made'] ); ?></span><span class="ofp-stat-label">Calls Made</span></div>
        <div class="ofp-stat-card"><span class="ofp-stat-number"><?php echo esc_html( $stats['pending_triggers'] ); ?></span><span class="ofp-stat-label">Queue Pending</span></div>
    </div>

    <!-- Actions -->
    <div class="ofp-actions">

        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&client_id=' . $client->id . '&action=edit' ) ); ?>"
           class="button">✏️ Edit Details</a>

        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-customers&client_id=' . $client->id ) ); ?>"
           class="button">👥 View Customers</a>

        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-leads&client_id=' . $client->id ) ); ?>"
           class="button">📋 View Leads</a>

        <?php if ( $client->status !== 'trash' ) : ?>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
                  onsubmit="return confirm('Open a new tab logged in as this client? The link expires in 15 minutes.');">
                <?php wp_nonce_field( 'ofp_preview_client' ); ?>
                <input type="hidden" name="action" value="ofp_preview_client">
                <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">
                <button type="submit" class="button" formtarget="_blank">🔍 View as Client</button>
            </form>
        <?php endif; ?>

        <?php if ( $client->status === 'pending_review' ) : ?>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'ofp_approve_client' ); ?>
                <input type="hidden" name="action" value="ofp_approve_client">
                <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">
                <button type="submit" class="button button-primary">✅ Approve Client</button>
            </form>
        <?php endif; ?>

        <?php if ( $client->status === 'active' ) : ?>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'ofp_toggle_client' ); ?>
                <input type="hidden" name="action" value="ofp_toggle_client">
                <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">
                <input type="hidden" name="status" value="suspended">
                <button type="submit" class="button" onclick="return confirm('Suspend this client?')">Suspend</button>
            </form>
        <?php elseif ( in_array( $client->status, ['suspended','grace','cancelled'], true ) ) : ?>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'ofp_toggle_client' ); ?>
                <input type="hidden" name="action" value="ofp_toggle_client">
                <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">
                <input type="hidden" name="status" value="active">
                <button type="submit" class="button button-primary">Reactivate</button>
            </form>
        <?php endif; ?>

        <?php if ( $client->status !== 'trash' ) : ?>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'ofp_reset_password' ); ?>
                <input type="hidden" name="action" value="ofp_reset_password">
                <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">
                <button type="submit" class="button" onclick="return confirm('Reset and email new password?')">Reset Password</button>
            </form>
        <?php endif; ?>

        <?php if ( $client->status === 'trash' ) : ?>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'ofp_restore_client' ); ?>
                <input type="hidden" name="action" value="ofp_restore_client">
                <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">
                <button type="submit" class="button button-primary">♻️ Restore</button>
            </form>
            <?php if ( OFP_Auth::is_super_admin() ) : ?>
                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                    <?php wp_nonce_field( 'ofp_delete_client' ); ?>
                    <input type="hidden" name="action" value="ofp_delete_client">
                    <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">
                    <button type="submit" class="button ofp-btn-danger"
                            onclick="return confirm('PERMANENTLY delete this client and all their data? This cannot be undone.')">
                        ⚠️ Delete Permanently
                    </button>
                </form>
            <?php endif; ?>
        <?php else : ?>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'ofp_trash_client' ); ?>
                <input type="hidden" name="action" value="ofp_trash_client">
                <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">
                <button type="submit" class="button ofp-btn-danger"
                        onclick="return confirm('Move this client to trash? Their automation will stop and they will be hidden from lists, but data is kept for 30 days.')">
                    🗑 Move to Trash
                </button>
            </form>
        <?php endif; ?>

    </div><!-- .ofp-actions -->

    <?php if ( $client->status !== 'trash' ) : ?>
    <!-- Manual Credit Top-Up (reseller model — admin loads credit after receiving client payment) -->
    <div class="ofp-section" style="margin-top:24px;">
        <h3>Load Credit to Account</h3>
        <p class="ofp-hint" style="margin-bottom:16px;">
            Load SMS or voice credit to this client's balance after confirming their
            manual payment. This immediately updates their balance and logs the
            transaction with a reference for your records.
        </p>
        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              class="ofp-form">
            <?php wp_nonce_field( 'ofp_topup_credit' ); ?>
            <input type="hidden" name="action"    value="ofp_topup_credit">
            <input type="hidden" name="client_id" value="<?php echo esc_attr( $client->id ); ?>">

            <div class="ofp-form-grid">
                <div class="ofp-field">
                    <label>Credit Channel <span class="required">*</span></label>
                    <select name="channel" required>
                        <option value="sms">SMS Credit</option>
                        <option value="voice">Voice / IVR Credit</option>
                    </select>
                </div>
                <div class="ofp-field">
                    <label>Amount (NGN) <span class="required">*</span></label>
                    <input type="number" name="amount" min="100" step="100"
                           required placeholder="e.g. 5000">
                    <p class="ofp-hint">NGN 6.99 per SMS · NGN 15.00 per voice minute</p>
                </div>
                <div class="ofp-field ofp-field-full">
                    <label>Payment Reference (optional)</label>
                    <input type="text" name="reference"
                           placeholder="e.g. Monnify/Paystack transaction reference">
                    <p class="ofp-hint">Stored in the transaction log for auditing.</p>
                </div>
            </div>

            <div class="ofp-form-actions">
                <button type="submit" class="button button-primary ofp-btn-primary">
                    Load Credit to Account
                </button>
            </div>
        </form>

        <!-- Current balance quick view -->
        <?php
        $credits = OFP_Credit::get( $client->id );
        if ( $credits ) :
        ?>
        <div style="margin-top:16px;padding:14px 16px;background:#f8fafc;border-radius:8px;
                    display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
            <div>
                <span style="color:#6b7280;">SMS Balance:</span>
                <strong style="margin-left:8px;">
                    NGN <?php echo esc_html( number_format( (float) $credits->sms_remaining, 2 ) ); ?>
                </strong>
                <?php if ( $credits->paused ) : ?>
                    <span class="ofp-badge ofp-badge-red" style="margin-left:6px;">Paused</span>
                <?php endif; ?>
            </div>
            <div>
                <span style="color:#6b7280;">Voice Balance:</span>
                <strong style="margin-left:8px;">
                    NGN <?php echo esc_html( number_format( (float) $credits->voice_remaining, 2 ) ); ?>
                </strong>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Client Activity Logs (Phase 21) -->
    <div class="ofp-section" style="margin-top:24px;">
        <h3>Activity Logs</h3>
        <?php
        global $wpdb;
        $client_logs = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, a.name AS admin_name
             FROM {$wpdb->prefix}ofp_activity_logs l
             LEFT JOIN {$wpdb->prefix}ofp_admins a ON l.admin_id = a.id
             WHERE l.client_id = %d
             ORDER BY l.created_at DESC
             LIMIT 20",
            $client->id
        ) );
        ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
            <thead>
                <tr>
                    <th style="width:160px;">Date</th>
                    <th>Action</th>
                    <th>User / Admin</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $client_logs ) ) : ?>
                    <tr>
                        <td colspan="3">No activity logs found for this client.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $client_logs as $log ) : ?>
                        <tr>
                            <td><?php echo esc_html( wp_date( 'M j, Y g:i a', strtotime( $log->created_at ) ) ); ?></td>
                            <td><strong><?php echo esc_html( $log->action ); ?></strong></td>
                            <td><?php echo esc_html( $log->admin_name ?: 'System / Unknown' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p style="margin-top:8px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-activity-logs' ) ); ?>">View all global logs &rarr;</a></p>
    </div>

    <!-- Team Members -->
    <div class="ofp-section" style="margin-top:24px;">
        <h3>Team Members</h3>
        <?php
        $team_members = OFP_Team_Member::get_all_for_client( $client->id );
        ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $team_members ) ) : ?>
                    <tr>
                        <td colspan="5">No team members found for this client.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $team_members as $member ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $member->name ); ?></strong></td>
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
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p style="margin-top:8px; font-size:12px; color:#6b7280;">Full team member management (invites, edits, deletions) is currently handled via the client portal. Read-only view provided here.</p>
    </div>
</div>

<?php else : ?>
<!-- ── CLIENTS LIST ───────────────────────────────────────────────────────── -->
<div class="ofp-section">
    <div class="ofp-section-header">
        <h2>Clients
            <?php if ( $filter ) : ?>
                <span class="ofp-filter-label">— <?php echo esc_html( ucwords( str_replace( '_', ' ', $filter ) ) ); ?></span>
            <?php endif; ?>
        </h2>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&action=add' ) ); ?>"
           class="button button-primary">+ Add New Client</a>
    </div>

    <div class="ofp-filter-tabs">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients' ) ); ?>"
           class="<?php echo ! $filter ? 'active' : ''; ?>">All</a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&filter=active' ) ); ?>"
           class="<?php echo $filter === 'active' ? 'active' : ''; ?>">Active</a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&filter=pending_review' ) ); ?>"
           class="<?php echo $filter === 'pending_review' ? 'active' : ''; ?>">Pending Review</a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&filter=suspended' ) ); ?>"
           class="<?php echo $filter === 'suspended' ? 'active' : ''; ?>">Suspended</a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&filter=trash' ) ); ?>"
           class="<?php echo $filter === 'trash' ? 'active' : ''; ?>">🗑 Trash<?php echo $trash_count ? ' (' . esc_html( $trash_count ) . ')' : ''; ?></a>
    </div>

    <?php if ( $filter === 'trash' && $trash_count > 0 ) : ?>
        <div class="ofp-info-box">
            Trashed clients are hidden from normal lists and cannot log in.
            They are permanently deleted automatically after 30 days. Restore
            them anytime before then.
        </div>
    <?php endif; ?>

    <?php if ( empty( $clients ) ) : ?>
        <p>No clients found. <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&action=add' ) ); ?>">Add your first client →</a></p>
    <?php else : ?>
        <table class="widefat ofp-table">
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Owner</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th><?php echo $filter === 'trash' ? 'Trashed' : 'Expires'; ?></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $clients as $c ) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&client_id=' . $c->id ) ); ?>">
                                <strong><?php echo esc_html( $c->business_name ); ?></strong>
                            </a>
                            <?php if ( ! empty( $c->is_verified ) ) : ?>
                                <span style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-size:10.5px; padding:2px 7px; border-radius:4px; font-weight:600; margin-left:6px; display:inline-flex; align-items:center; gap:3px;" title="CAC &amp; Identity Verified">✓ Verified</span>
                            <?php endif; ?>
                            <br>
                            <small><?php echo esc_html( $c->email ); ?></small>
                        </td>
                        <td><?php echo esc_html( $c->owner_name ); ?></td>
                        <?php
                        $table_plan_map = ['starter'=>'Free','growth'=>'Silver','pro'=>'Gold','bronze'=>'Free','free'=>'Free','silver'=>'Silver','gold'=>'Gold'];
                        ?>
                        <td><?php echo esc_html( $table_plan_map[$c->plan] ?? ucfirst($c->plan ?: '—') ); ?></td>
                        <td><?php echo $status_labels[ $c->status ] ?? esc_html( $c->status ); ?></td>
                        <td>
                            <?php echo $filter === 'trash'
                                ? esc_html( $c->trashed_at ? gmdate( 'M j, Y', strtotime( $c->trashed_at ) ) : '—' )
                                : esc_html( $c->subscription_expires ?: '—' ); ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&client_id=' . $c->id ) ); ?>"
                               class="button button-small">View</a>

                            <?php if ( $c->status === 'pending_review' ) : ?>
                                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'ofp_approve_client' ); ?>
                                    <input type="hidden" name="action" value="ofp_approve_client">
                                    <input type="hidden" name="client_id" value="<?php echo esc_attr( $c->id ); ?>">
                                    <button type="submit" class="button button-small button-primary">Approve</button>
                                </form>
                            <?php endif; ?>

                            <?php if ( $filter === 'trash' ) : ?>
                                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'ofp_restore_client' ); ?>
                                    <input type="hidden" name="action" value="ofp_restore_client">
                                    <input type="hidden" name="client_id" value="<?php echo esc_attr( $c->id ); ?>">
                                    <button type="submit" class="button button-small button-primary">Restore</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include OFP_PATH . 'admin/views/partials/footer.php'; ?>
