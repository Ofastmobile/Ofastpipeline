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
            <div class="ofp-field">
                <label>Subdomain Slug</label>
                <input type="text" name="subdomain" placeholder="e.g. lekki-homes">
            </div>
            <div class="ofp-field">
                <label>Business Category</label>
                <select name="business_category">
                    <option value="">— Select Category —</option>
                    <option value="property">Property / Real Estate</option>
                    <option value="food">Food & Restaurant</option>
                    <option value="fashion">Fashion & Clothing</option>
                    <option value="beauty">Beauty & Wellness</option>
                    <option value="education">Education & Training</option>
                    <option value="logistics">Logistics & Delivery</option>
                    <option value="health">Health & Pharmacy</option>
                    <option value="tech">Technology & Services</option>
                    <option value="other">Other</option>
                </select>
            </div>
        </div>

        <div class="ofp-field ofp-field-full">
            <label>Subscription Type <span class="required">*</span></label>
            <div class="ofp-checkbox-group">
                <label class="ofp-checkbox">
                    <input type="checkbox" name="want_crm" value="1" checked>
                    <span>CRM Pipeline (lead automation, SMS, voice)</span>
                </label>
                <label class="ofp-checkbox">
                    <input type="checkbox" name="want_listing" value="1">
                    <span>Property Listing Directory</span>
                </label>
            </div>
        </div>

        <div class="ofp-field" id="ofp-plan-field">
            <label>CRM Plan</label>
            <select name="plan">
                <option value="starter">Starter — NGN 25,000/month (100 leads)</option>
                <option value="growth">Growth — NGN 45,000/month (300 leads)</option>
                <option value="pro">Pro — NGN 75,000/month (700 leads)</option>
            </select>
        </div>

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
                <label>Subdomain Slug</label>
                <input type="text" name="subdomain" value="<?php echo esc_attr( $client->subdomain ); ?>">
            </div>
            <div class="ofp-field">
                <label>Business Category</label>
                <select name="business_category">
                    <?php
                    $cats = [ 'property' => 'Property / Real Estate', 'food' => 'Food & Restaurant',
                              'fashion'  => 'Fashion & Clothing', 'beauty' => 'Beauty & Wellness',
                              'education' => 'Education & Training', 'logistics' => 'Logistics & Delivery',
                              'health'   => 'Health & Pharmacy', 'tech' => 'Technology & Services', 'other' => 'Other' ];
                    foreach ( $cats as $val => $label ) :
                    ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $client->business_category, $val ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ofp-field">
                <label>CRM Plan</label>
                <select name="plan">
                    <option value="starter" <?php selected( $client->plan, 'starter' ); ?>>Starter</option>
                    <option value="growth"  <?php selected( $client->plan, 'growth' ); ?>>Growth</option>
                    <option value="pro"     <?php selected( $client->plan, 'pro' ); ?>>Pro</option>
                </select>
            </div>
        </div>

        <div class="ofp-form-actions">
            <button type="submit" class="button button-primary ofp-btn-primary">Save Changes</button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ofp-clients&client_id=' . $client->id ) ); ?>" class="button">Cancel</a>
        </div>
    </form>
</div>

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
        <div><strong>Owner:</strong> <?php echo esc_html( $client->owner_name ); ?></div>
        <div><strong>Email:</strong> <?php echo esc_html( $client->email ); ?></div>
        <div><strong>Phone:</strong> <?php echo esc_html( $client->phone ); ?></div>
        <div><strong>Status:</strong> <?php echo $status_labels[ $client->status ] ?? esc_html( $client->status ); ?></div>
        <div><strong>Plan:</strong> <?php echo esc_html( strtoupper( $client->plan ?: '—' ) ); ?></div>
        <div><strong>Subscription Expires:</strong> <?php echo esc_html( $client->subscription_expires ?: '—' ); ?></div>
        <div><strong>Virtual Account:</strong> <?php echo esc_html( $client->virtual_bank_name ); ?> — <?php echo esc_html( $client->virtual_account_number ?: 'Not set' ); ?></div>
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
                            </a><br>
                            <small><?php echo esc_html( $c->email ); ?></small>
                        </td>
                        <td><?php echo esc_html( $c->owner_name ); ?></td>
                        <td><?php echo esc_html( strtoupper( $c->plan ?: '—' ) ); ?></td>
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
