<?php
/**
 * Client Portal — Navigation (Sidebar + Topbar)
 * Redesigned for Dark/Light theme toggle
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$has_crm     = OFP_Subscription::has_active( 'crm',     $client->id );
$has_listing = OFP_Subscription::has_active( 'listing', $client->id );
$current_url = home_url( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) );

// ── Notification Action Handlers ───────────────────────────────────────────
if (
	$_SERVER['REQUEST_METHOD'] === 'POST' &&
	isset( $_POST['ofp_mark_all_read'] ) &&
	wp_verify_nonce( $_POST['ofp_notif_nonce'] ?? '', 'ofp_notifications_action' )
) {
	OFP_Notification::mark_all_read( $client->id );
}

if (
	$_SERVER['REQUEST_METHOD'] === 'POST' &&
	isset( $_POST['ofp_mark_read'] ) &&
	wp_verify_nonce( $_POST['ofp_notif_nonce'] ?? '', 'ofp_notifications_action' )
) {
	OFP_Notification::mark_read( (int) $_POST['notification_id'], $client->id );
}

// Fetch recent notifications for the dropdown
global $wpdb;
$ofp_recent_notifications = $wpdb->get_results( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}ofp_notifications
	 WHERE client_id = %d
	 ORDER BY created_at DESC
	 LIMIT 5",
	$client->id
) );

// ── SVG allowlist ────────────────────────────────────────────────────────
$allowed_svg = [
    'svg'  => [ 'xmlns' => true, 'fill' => true, 'viewbox' => true, 'stroke-width' => true, 'stroke' => true, 'class' => true, 'aria-hidden' => true ],
    'path' => [ 'stroke-linecap' => true, 'stroke-linejoin' => true, 'd' => true, 'fill' => true, 'stroke' => true ],
];

// ── Navigation items ──────────────────────────────────────────────────────────
$nav_items = [];

$nav_items[] = [
    'label'  => 'Dashboard',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>',
    'url'    => home_url( '/dashboard' ),
    'slug'   => 'dashboard',
    'locked' => false,
];

if ( $has_crm ) {
    $nav_items[] = [
        'label'  => 'My Leads',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>',
        'url'    => home_url( '/leads' ),
        'slug'   => 'leads',
        'locked' => false,
    ];
    $nav_items[] = [
        'label'  => 'Pipeline Settings',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.56.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>',
        'url'    => home_url( '/pipeline-settings' ),
        'slug'   => 'pipeline-settings',
        'locked' => false,
    ];
    $nav_items[] = [
        'label'  => 'Communications',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" /></svg>',
        'url'    => home_url( '/communications' ),
        'slug'   => 'communications',
        'locked' => false,
    ];
    $nav_items[] = [
        'label'  => 'Credits & Billing',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>',
        'url'    => home_url( '/credits' ),
        'slug'   => 'credits',
        'locked' => false,
    ];
} else {
    $nav_items[] = [
        'label'  => 'Lead Automation',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.59 14.37a6 6 0 01-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 006.16-12.12A14.98 14.98 0 009.631 8.41m5.96 5.96a14.926 14.926 0 01-5.841 2.58m-.119-8.54a6 6 0 00-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 00-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 01-2.448-2.448 14.9 14.9 0 01.06-.312m-2.24 2.39a4.493 4.493 0 00-1.757 4.306 4.493 4.493 0 004.306-1.758M16.5 9a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" /></svg>',
        'url'    => home_url( '/dashboard?upgrade=crm' ),
        'slug'   => '',
        'locked' => true,
        'badge'  => 'Upgrade',
    ];
}

if ( $has_listing ) {
    $nav_items[] = [
        'label'  => 'My Properties',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>',
        'url'    => home_url( '/properties' ),
        'slug'   => 'properties',
        'locked' => false,
    ];
} elseif ( $client->business_category === 'property' ) {
    $nav_items[] = [
        'label'  => 'List Property',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>',
        'url'    => home_url( '/dashboard?upgrade=listing' ),
        'slug'   => '',
        'locked' => true,
        'badge'  => 'New',
    ];
}

$nav_items[] = [
    'label'  => 'Reports',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>',
    'url'    => home_url( '/reports' ),
    'slug'   => 'reports',
    'locked' => false,
];

// Phase 17 — Funding (always visible to all clients)
$nav_items[] = [
    'label'  => 'Funding',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
    'url'    => home_url( '/funding' ),
    'slug'   => 'funding',
    'locked' => false,
];
?>

<div class="ofp-shell" id="ofp-shell">
    <div class="ofp-sidebar-backdrop" id="ofp-sidebar-backdrop"></div>

    <aside class="ofp-sidebar" id="ofp-sidebar">
        <div class="ofp-sidebar-header">
            <a class="ofp-nav-brand" href="<?php echo esc_url( home_url( '/dashboard' ) ); ?>">
                ⚡ <span>OFast Pipeline</span>
            </a>
        </div>
        
        <nav class="ofp-sidebar-nav" aria-label="Client portal navigation">
            <div class="ofp-nav-group">
                <div class="ofp-nav-group-label">Overview</div>
                <ul>
                    <?php foreach ( $nav_items as $item ) :
                        $is_active = ! $item['locked'] && $item['slug'] && strpos( $current_url, '/' . $item['slug'] ) !== false;
                        $classes = 'ofp-nav-item';
                        if ( $is_active )      $classes .= ' active';
                        if ( $item['locked'] ) $classes .= ' locked';
                    ?>
                        <li>
                            <a href="<?php echo esc_url( $item['url'] ); ?>"
                               class="<?php echo esc_attr( $classes ); ?>"
                               <?php echo $item['locked'] ? 'aria-disabled="true"' : ''; ?>>
                                <span class="ofp-nav-icon">
                                    <?php echo wp_kses( $item['icon'], $allowed_svg ); ?>
                                </span>
                                <span class="ofp-nav-label"><?php echo esc_html( $item['label'] ); ?></span>
                                <?php if ( ! empty( $item['badge'] ) ) : ?>
                                    <span class="ofp-nav-badge"><?php echo esc_html( $item['badge'] ); ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </nav>

        <div class="ofp-sidebar-footer" style="padding: 16px 24px; border-top: 1px solid rgba(255,255,255,0.04); font-size: 12px; font-weight: 500; color: var(--text-muted); text-align: left; margin-top: auto;">
            Version <?php echo esc_html( OFP_VERSION ); ?>
        </div>
    </aside>

    <main class="ofp-main">
        <header class="ofp-topbar">
            
            <button class="ofp-sidebar-toggle" id="ofp-sidebar-toggle" aria-label="Toggle navigation">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" /></svg>
            </button>

            <!-- Search Bar Match -->
            <div class="ofp-topbar-search">
                <div class="search-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                    <input type="text" placeholder="Search...">
                </div>
            </div>

            <div class="ofp-topbar-spacer" style="flex:1;"></div>

            <div class="ofp-topbar-actions">
                <?php if ( $has_crm ) : ?>
                    <a href="<?php echo esc_url( home_url( '/credits' ) ); ?>" class="ofp-btn-balance" title="Credit Balance">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:18px;height:18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                        <span>Top Up</span>
                    </a>
                <?php endif; ?>

                <!-- Theme Toggle Desktop Icon -->
                <button class="ofp-icon-btn" id="ofp-theme-toggle" title="Toggle Theme">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg>
                </button>

                <!-- Notification Dropdown Menu -->
                <?php $ofp_unread = OFP_Notification::unread_count( $client->id ); ?>
                <div class="ofp-user-menu" style="position:relative;">
                    <div class="ofp-icon-btn" style="cursor:pointer; position:relative;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
                        <?php if ( $ofp_unread > 0 ) : ?>
                            <span style="position:absolute; top:2px; right:2px; background:var(--accent-red); color:#fff; font-size:10px; font-weight:bold; height:16px; min-width:16px; border-radius:100px; display:flex; align-items:center; justify-content:center; padding:0 4px;"><?php echo esc_html( $ofp_unread ); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="ofp-dropdown" style="width: 320px; right: -10px; padding-bottom:0;">
                        <div class="ofp-dropdown-header" style="display:flex; justify-content:space-between; align-items:center; padding-bottom:12px;">
                            <strong style="margin:0; font-size:14px;">Notifications</strong>
                            <?php if ( $ofp_unread > 0 ) : ?>
                            <form method="POST" style="margin:0;">
                                <?php wp_nonce_field( 'ofp_notifications_action', 'ofp_notif_nonce' ); ?>
                                <button type="submit" name="ofp_mark_all_read" value="1" style="background:none;border:none;color:#3b82f6;font-size:12px;font-weight:500;cursor:pointer;padding:0;">Mark all read</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        
                        <div class="ofp-dropdown-body" style="max-height: 350px; overflow-y: auto; padding: 0;">
                            <?php if ( empty( $ofp_recent_notifications ) ) : ?>
                                <div style="padding:24px 16px; text-align:center; color:var(--text-muted); font-size:13px;">
                                    No notifications yet.
                                </div>
                            <?php else : ?>
                                <?php foreach ( $ofp_recent_notifications as $notif ) : ?>
                                    <div style="padding: 12px 16px; border-bottom: 1px solid var(--dropdown-border); <?php echo $notif->is_read ? 'opacity:0.7;' : 'background:rgba(59, 130, 246, 0.04);'; ?>">
                                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:4px;">
                                            <strong style="font-size:13px; color:var(--text-main); line-height:1.4; padding-right:8px;"><?php echo esc_html( $notif->title ); ?></strong>
                                            <span style="font-size:11px; color:var(--text-muted); white-space:nowrap;"><?php echo esc_html( human_time_diff( strtotime( $notif->created_at ), time() ) ); ?></span>
                                        </div>
                                        <div style="font-size:12px; color:var(--text-muted); margin-bottom:8px; line-height:1.5;">
                                            <?php echo esc_html( $notif->message ); ?>
                                        </div>
                                        <?php if ( ! $notif->is_read ) : ?>
                                            <form method="POST" style="margin:0; text-align:right;">
                                                <?php wp_nonce_field( 'ofp_notifications_action', 'ofp_notif_nonce' ); ?>
                                                <input type="hidden" name="notification_id" value="<?php echo esc_attr( $notif->id ); ?>">
                                                <button type="submit" name="ofp_mark_read" value="1" style="background:none;border:none;color:#3b82f6;font-size:11px;font-weight:500;cursor:pointer;padding:0;">Mark as read</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <div style="padding:8px; text-align:center; background:var(--dropdown-bg);">
                                    <!-- A link could go here to view all history if needed in the future -->
                                    <span style="font-size:11px; color:var(--text-muted);">Showing recent 5</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- User Dropdown Menu -->
                <div class="ofp-user-menu" id="ofp-user-menu">
                    <div class="ofp-user-avatar" id="ofp-user-avatar">
                        <?php if ( ! empty( $client->logo_url ) ) : ?>
                            <img src="<?php echo esc_url( $client->logo_url ); ?>" alt="Logo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                        <?php else : ?>
                            <?php echo esc_html( strtoupper( substr( $client->business_name, 0, 2 ) ) ); ?>
                        <?php endif; ?>
                    </div>
                    <div class="ofp-dropdown">
                        <div class="ofp-dropdown-header">
                            <strong><?php echo esc_html( $client->owner_name ); ?></strong>
                            <span><?php echo esc_html( $client->email ); ?></span>
                        </div>
                        <div class="ofp-dropdown-body">
                            <a href="<?php echo esc_url( home_url( '/account' ) ); ?>" class="ofp-dropdown-item">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                                Profile Settings
                            </a>
                            <a href="<?php echo esc_url( home_url( '/notification-settings' ) ); ?>" class="ofp-dropdown-item">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
                                Notification Settings
                            </a>
                            <a href="<?php echo esc_url( home_url( '/api-settings' ) ); ?>" class="ofp-dropdown-item">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                API Settings
                            </a>
                            <a href="<?php echo esc_url( OFP_Client_Portal::logout_url() ); ?>" class="ofp-dropdown-item danger">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" /></svg>
                                Sign out
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        
        <div class="ofp-content-area">
