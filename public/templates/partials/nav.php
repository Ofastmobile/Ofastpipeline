<?php
/**
 * Client Portal — Navigation (Sidebar + Topbar)
 * Redesigned for Dark/Light theme toggle
 */
if ( ! defined( 'ABSPATH' ) ) exit;

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

$nav_items[] = [
    'label'  => 'My Properties',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>',
    'url'    => home_url( '/properties' ),
    'slug'   => 'properties',
    'locked' => false,
];

$pending_inspections_count = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}ofp_property_inquiries WHERE client_id = %d AND status = 'pending'",
    $client->id
) );

$nav_items[] = [
    'label'  => 'Inspections',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z" /></svg>',
    'url'    => home_url( '/inspections' ),
    'slug'   => 'inspections',
    'badge'  => $pending_inspections_count > 0 ? (string) $pending_inspections_count : null,
    'locked' => false,
];

$nav_items[] = [
    'label'  => 'Sales & Installments',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7.5 16.5l3-3 2.25 2.25L19.5 9" /></svg>',
    'url'    => OFP_Subscription::has_paid_plan( (int) $client->id ) ? home_url( '/property-sales' ) : home_url( '/pricing' ),
    'slug'   => 'property-sales',
    'locked' => ! OFP_Subscription::has_paid_plan( (int) $client->id ),
];

$nav_items[] = [
    'label'  => 'Transactions',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 5.25h-15A2.25 2.25 0 002.25 7.5v9A2.25 2.25 0 004.5 18.75h15a2.25 2.25 0 002.25-2.25v-9A2.25 2.25 0 0019.5 5.25zM2.25 9h19.5M6 13.5h3" /></svg>',
    'url'    => home_url( '/property-payments' ),
    'slug'   => 'property-payments',
    'locked' => false,
];

// Listing Billing is a plan subscription page, so solo companies do not get it.
if ( ! OFP_Brand::hide_plan_prompts( $client ) ) {
    $nav_items[] = [
        'label'  => 'Listing Billing',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zM7.5 12h.008v.008H7.5V12zm3 0h.008v.008h-.008V12z" /></svg>',
        'url'    => home_url( '/listing-billing' ),
        'slug'   => 'listing-billing',
        'locked' => false,
    ];
}

$nav_items[] = [
    'label'  => 'Rent & Tenants',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>',
    'url'    => OFP_Property_Rent::can_manage( (int) $client->id ) ? home_url( '/tenants' ) : home_url( '/pricing' ),
    'slug'   => 'tenants',
    'locked' => ! OFP_Property_Rent::can_manage( (int) $client->id ),
];

$nav_items[] = [
    'label'  => 'My Leads',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>',
    'url'    => home_url( '/leads' ),
    'slug'   => 'leads',
    'locked' => false,
];

$nav_items[] = [
    'label'  => 'Automation',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.56.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>',
    'url'    => home_url( '/pipeline-settings' ),
    'slug'   => 'pipeline-settings',
    'locked' => false,
];

$nav_items[] = [
    'label'  => 'Communications',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" /></svg>',
    'url'    => home_url( '/message-templates' ),
    'slug'   => 'message-templates',
    'locked' => false,
];

$nav_items[] = [
    'label'  => 'Credits & Billing',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>',
    'url'    => home_url( '/credits' ),
    'slug'   => 'credits',
    'locked' => false,
];

$nav_items[] = [
    'label'  => 'Reports',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>',
    'url'    => home_url( '/reports' ),
    'slug'   => 'reports',
    'locked' => false,
];

// Show Team menu to Main Clients and Managers
$can_view_team = false;
$current_user = OFP_Auth::current_user();
if ( $current_user ) {
    if ( ! $current_user->is_team_member ) {
        $can_view_team = true; // Main Client
    } elseif ( isset( $current_user->role_name ) && $current_user->role_name === 'Manager' ) {
        $can_view_team = true; // Manager
    }
}

if ( $can_view_team ) {
    $nav_items[] = [
        'label'  => 'Team',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>',
        'url'    => OFP_Subscription::has_paid_plan( (int) $client->id ) ? home_url( '/team' ) : home_url( '/pricing' ),
        'slug'   => 'team',
        'locked' => ! OFP_Subscription::has_paid_plan( (int) $client->id ),
    ];
}

// Funding — main client only, team members can't pay or manage billing
if ( $current_user && empty( $current_user->is_team_member ) && ! OFP_Brand::hide_plan_prompts( $client ) ) {
    $nav_items[] = [
        'label'  => 'Funding',
        'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
        'url'    => home_url( '/funding' ),
        'slug'   => 'funding',
        'locked' => false,
    ];
}

// Plans & Pricing: hidden for solo companies, they have no plan to choose
if ( ! OFP_Brand::hide_plan_prompts( $client ) ) {
$nav_items[] = [
    'label'  => 'Plans & Pricing',
    'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" /></svg>',
    'url'    => home_url( '/pricing' ),
    'slug'   => 'pricing',
    'locked' => false,
];
}

?>

<?php OFP_Brand::print_head( $client ); ?>
<link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>" id="ofp-client-portal-css-bundle">
<style id="ofp-svg-failsafe">
svg { max-width: 100%; box-sizing: border-box; }
.ofp-sidebar svg, .ofp-nav-icon svg { width: 20px !important; height: 20px !important; max-width: 20px !important; max-height: 20px !important; display: inline-block; vertical-align: middle; }
.ofp-sidebar-logo-icon svg { width: 36px !important; height: 36px !important; max-width: 36px !important; max-height: 36px !important; }
.ofp-topbar svg, .ofp-hero-full-header svg { max-width: 24px; max-height: 24px; }
.ofp-kpi-badge svg { width: 14px !important; height: 14px !important; }
</style>
<div class="ofp-shell" id="ofp-shell">
    <div class="ofp-sidebar-backdrop" id="ofp-sidebar-backdrop"></div>

    <aside class="ofp-sidebar" id="ofp-sidebar">
        <div class="ofp-sidebar-header">
            <a class="ofp-nav-brand" href="<?php echo esc_url( home_url( '/dashboard' ) ); ?>">
                <?php echo OFP_Brand::sidebar_brand_html( $client ); ?>
            </a>
        </div>
        
        <nav class="ofp-sidebar-nav" aria-label="Client portal navigation">
            <div class="ofp-nav-group">
                <div class="ofp-nav-group-label">Overview</div>
                <ul>
                    <?php foreach ( $nav_items as $item ) :
                        $is_active = ! $item['locked'] && $item['slug'] && (
                            strpos( $current_url, '/' . $item['slug'] ) !== false ||
                            ( $item['slug'] === 'tenants' && strpos( $current_url, '/rent' ) !== false )
                        );
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

        <div class="ofp-sidebar-footer">
            <!-- Sidebar User Profile Widget -->
            <div class="ofp-user-menu ofp-sidebar-user-card" id="ofp-user-menu">
                <div class="ofp-sidebar-user-trigger" id="ofp-user-avatar">
                    <div class="ofp-user-avatar">
                        <?php if ( ! empty( $client->logo_url ) ) : ?>
                            <img src="<?php echo esc_url( $client->logo_url ); ?>" alt="Logo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                        <?php else : ?>
                            <?php echo esc_html( strtoupper( substr( $client->business_name, 0, 2 ) ) ); ?>
                        <?php endif; ?>
                    </div>
                    <div class="ofp-sidebar-user-info">
                        <strong class="ofp-sidebar-user-name"><?php echo esc_html( $client->owner_name ); ?></strong>
                        <span class="ofp-sidebar-user-email"><?php echo esc_html( $client->email ); ?></span>
                    </div>
                    <div class="ofp-sidebar-user-chevron">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
                    </div>
                </div>
                <div class="ofp-dropdown ofp-sidebar-dropdown">
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
    </aside>

    <main class="ofp-main">
        <div class="ofp-hero-full-header" style="background-image: url('<?php echo esc_url( OFP_URL . 'assets/images/dashboard-hero-banner.jpg' ); ?>');">
            <div class="ofp-hero-full-header-overlay">

        <header class="ofp-topbar ofp-topbar-transparent">
            
            <button class="ofp-sidebar-toggle" id="ofp-sidebar-toggle" aria-label="Toggle navigation">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" /></svg>
            </button>

            <div class="ofp-hero-greeting-pill">
                <span class="ofp-status-dot-pulse"></span>
                <span>
                    <?php 
                        $hour = current_time('H');
                        $greeting = 'Good evening';
                        if ($hour < 12) { $greeting = 'Good morning'; }
                        elseif ($hour < 18) { $greeting = 'Good afternoon'; }
                        echo esc_html( $greeting . ', ' . explode(' ', trim($client->owner_name))[0] ); 
                    ?>!
                </span>
                <span class="ofp-hero-date-divider">&bull;</span>
                <span class="ofp-hero-date"><?php echo date('M j, Y'); ?></span>
            </div>

            <div class="ofp-topbar-spacer" style="flex:1;"></div>

            <div class="ofp-topbar-actions">
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
                                <div style="padding:12px; text-align:center; background:var(--dropdown-bg);">
                                    <a href="<?php echo esc_url( home_url( '/notification-settings' ) ); ?>" style="font-size:12px; color:var(--accent-blue); text-decoration:none; font-weight:500;">Show all</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Theme Toggle Desktop Icon (L&D button sticks to the far right, after notification) -->
                <button class="ofp-icon-btn" id="ofp-theme-toggle" type="button" aria-label="Toggle Theme" title="Toggle Light/Dark Theme">
                    <svg class="theme-toggle-moon" style="width:20px;height:20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                    </svg>
                    <svg class="theme-toggle-sun" style="width:20px;height:20px;color:#f59e0b;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                </button>
            </div>
        </header>

            <!-- Bottom Controls Row inside full-width hero header -->
            <div class="ofp-hero-topbar-controls">
                <!-- Search bar with Ctrl+L / ⌘+L shortcut -->
                <div class="ofp-hero-search-box" id="ofp-hero-search-trigger" style="cursor:pointer;" title="Press Ctrl+L or ⌘+L anywhere to search">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input type="text" id="ofp-hero-quick-search" placeholder="Search everywhere..." readonly style="cursor:pointer;" />
                    <span class="ofp-hero-kbd" id="ofp-hero-kbd-badge">Ctrl + L</span>
                </div>

                <!-- Action buttons -->
                <div class="ofp-hero-action-buttons">
                    <button type="button" class="ofp-hero-btn-more" title="Refresh" onclick="window.location.reload();">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:18px;height:18px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0zM12.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0zM18.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
                        </svg>
                    </button>

                    <a href="<?php echo esc_url( home_url( '/reports' ) ); ?>" class="ofp-hero-btn-export">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px;height:16px;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                        </svg>
                        <span>Export Report</span>
                    </a>

                    <?php
                    $banner_btn_label   = 'Add New Property';
                    $banner_btn_url     = home_url( '/properties' );
                    $banner_btn_onclick = '';
                    $banner_btn_icon    = 'plus';

                    if ( ! empty( $ofp_banner_btn['label'] ) && ! empty( $ofp_banner_btn['url'] ) ) {
                        $banner_btn_label   = $ofp_banner_btn['label'];
                        $banner_btn_url     = $ofp_banner_btn['url'];
                        $banner_btn_onclick = $ofp_banner_btn['onclick'] ?? '';
                        $banner_btn_icon    = $ofp_banner_btn['icon'] ?? 'plus';
                    } elseif (
                        strpos( $_SERVER['REQUEST_URI'] ?? '', 'team' ) !== false ||
                        strpos( $current_url, '/team' ) !== false
                    ) {
                        $banner_btn_label   = '+ Invite Member';
                        $banner_btn_url     = home_url( '/team-invite' );
                        $banner_btn_onclick = "if(typeof openInviteModal==='function'){ openInviteModal(); return false; }";
                        $banner_btn_icon    = 'none'; // Plus is already included in label
                    } elseif (
                        strpos( $_SERVER['REQUEST_URI'] ?? '', 'communication' ) !== false ||
                        strpos( $_SERVER['REQUEST_URI'] ?? '', 'message-templates' ) !== false ||
                        strpos( $current_url, '/communications' ) !== false ||
                        strpos( $current_url, '/message-templates' ) !== false
                    ) {
                        $banner_btn_label = 'Email Brand & Layout';
                        $banner_btn_url   = home_url( '/message-templates?tab=branding' );
                        $banner_btn_icon  = 'branding';
                    } elseif (
                        strpos( $_SERVER['REQUEST_URI'] ?? '', 'property-sales' ) !== false ||
                        strpos( $_SERVER['REQUEST_URI'] ?? '', 'property-purchases' ) !== false ||
                        strpos( $_SERVER['REQUEST_URI'] ?? '', 'property-payments' ) !== false ||
                        strpos( $current_url, '/property-sales' ) !== false ||
                        strpos( $current_url, '/property-purchases' ) !== false ||
                        strpos( $current_url, '/property-payments' ) !== false ||
                        isset( $ofp_sales_tab )
                    ) {
                        $banner_btn_label = 'Add Purchase';
                        $banner_btn_url   = home_url( '/property-purchases' );
                    }
                    ?>
                    <a href="<?php echo esc_url( $banner_btn_url ); ?>" 
                       <?php if ( ! empty( $banner_btn_onclick ) ) : ?>onclick="<?php echo esc_attr( $banner_btn_onclick ); ?>"<?php endif; ?>
                       class="ofp-hero-btn-add">
                        <?php if ( $banner_btn_icon === 'branding' ) : ?>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.763m3.42 3.42a6.776 6.776 0 00-3.42-3.42" />
                            </svg>
                        <?php elseif ( $banner_btn_icon !== 'none' ) : ?>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" style="width:16px;height:16px;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                        <?php endif; ?>
                        <span><?php echo esc_html( $banner_btn_label ); ?></span>
                    </a>
                </div>
            </div>
            </div> <!-- .ofp-hero-full-header-overlay -->
        </div> <!-- .ofp-hero-full-header -->

        <!-- ── GLOBAL SPOTLIGHT / COMMAND PALETTE SEARCH POPUP (Ctrl+L / ⌘+L) ── -->
        <div class="ofp-search-modal-backdrop" id="ofp-search-modal" aria-hidden="true">
            <div class="ofp-search-modal-box" role="dialog" aria-modal="true" aria-label="Quick Search">
                <!-- Header with Input -->
                <div class="ofp-search-modal-header">
                    <svg class="ofp-search-modal-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input type="text" id="ofp-modal-search-input" class="ofp-search-modal-input" placeholder="Search properties, sales, leads, reports, settings..." autocomplete="off" />
                    <button type="button" class="ofp-search-modal-close" id="ofp-search-modal-close-btn" title="Close Search (Esc)">
                        <span>ESC</span>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" style="width:12px;height:12px;"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <!-- Category Filter Tabs -->
                <div class="ofp-search-modal-tabs" id="ofp-search-modal-filter-tabs">
                    <button type="button" class="ofp-search-tab-pill active" data-filter="all">All</button>
                    <button type="button" class="ofp-search-tab-pill" data-filter="properties">Properties</button>
                    <button type="button" class="ofp-search-tab-pill" data-filter="sales">Sales & Purchases</button>
                    <button type="button" class="ofp-search-tab-pill" data-filter="reports">Reports</button>
                    <button type="button" class="ofp-search-tab-pill" data-filter="crm">Leads & CRM</button>
                    <button type="button" class="ofp-search-tab-pill" data-filter="settings">Settings</button>
                </div>

                <!-- Dynamic Results / Suggested Shortcuts -->
                <div class="ofp-search-modal-results" id="ofp-search-modal-results-list">
                    <!-- Populated dynamically via JS -->
                </div>

                <!-- Footer with Navigation Hints -->
                <div class="ofp-search-modal-footer">
                    <div class="ofp-search-hints">
                        <span class="ofp-search-hint-kbd"><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
                        <span class="ofp-search-hint-kbd"><kbd>↵</kbd> select</span>
                        <span class="ofp-search-hint-kbd"><kbd>esc</kbd> close</span>
                    </div>
                    <div style="font-weight:600; opacity:0.8;">
                        <span id="ofp-modal-kbd-hint">Ctrl + L</span> anywhere to toggle
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var modal = document.getElementById('ofp-search-modal');
            var modalInput = document.getElementById('ofp-modal-search-input');
            var closeBtn = document.getElementById('ofp-search-modal-close-btn');
            var resultsList = document.getElementById('ofp-search-modal-results-list');
            var filterTabs = document.querySelectorAll('#ofp-search-modal-filter-tabs .ofp-search-tab-pill');
            var triggerBox = document.getElementById('ofp-hero-search-trigger');
            var heroInput = document.getElementById('ofp-hero-quick-search');
            var heroKbdBadge = document.getElementById('ofp-hero-kbd-badge');
            var modalKbdHint = document.getElementById('ofp-modal-kbd-hint');

            var currentFilter = 'all';
            var selectedIndex = 0;

            // Detect OS to update badge
            var isMac = typeof navigator !== 'undefined' && /Mac|iPod|iPhone|iPad/.test(navigator.platform);
            var shortcutText = isMac ? '⌘ + L' : 'Ctrl + L';
            if (heroKbdBadge) heroKbdBadge.textContent = shortcutText;
            if (modalKbdHint) modalKbdHint.textContent = shortcutText;

            // Search catalog
            var searchData = [
                {
                    title: 'Properties Portfolio',
                    desc: 'Browse and manage all properties in portfolio',
                    url: '<?php echo esc_js( home_url( '/properties' ) ); ?>',
                    cat: 'properties',
                    badge: 'Properties',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21" /></svg>'
                },
                {
                    title: 'Add New Property',
                    desc: 'Create a new property listing with images and price',
                    url: '<?php echo esc_js( home_url( '/properties?tab=add' ) ); ?>',
                    cat: 'properties',
                    badge: 'Action',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>'
                },
                {
                    title: 'Property Sales & Installments',
                    desc: 'Track sales records, installment offers and payment plans',
                    url: '<?php echo esc_js( home_url( '/property-sales' ) ); ?>',
                    cat: 'sales',
                    badge: 'Sales',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" /></svg>'
                },
                {
                    title: 'Add Purchase (Manual)',
                    desc: 'Record a new buyer contract or purchase agreement',
                    url: '<?php echo esc_js( home_url( '/property-purchases' ) ); ?>',
                    cat: 'sales',
                    badge: 'Purchases',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>'
                },
                {
                    title: 'Transactions',
                    desc: 'View incoming customer transactions, receipts and history',
                    url: '<?php echo esc_js( home_url( '/property-payments' ) ); ?>',
                    cat: 'sales',
                    badge: 'Transactions',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>'
                },
                {
                    title: 'Financial & Sales Reports',
                    desc: 'Performance metrics, statements and exportable revenue logs',
                    url: '<?php echo esc_js( home_url( '/reports' ) ); ?>',
                    cat: 'reports',
                    badge: 'Reports',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>'
                },
                {
                    title: 'Leads & Pipeline CRM',
                    desc: 'Track prospective buyers, inquiries and sales stages',
                    url: '<?php echo esc_js( home_url( '/leads' ) ); ?>',
                    cat: 'crm',
                    badge: 'Leads',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>'
                },
                {
                    title: 'Rent & Tenants',
                    desc: 'Manage active tenants, rental rates, lease agreements and rent payments',
                    url: '<?php echo esc_js( home_url( '/tenants' ) ); ?>',
                    cat: 'crm',
                    badge: 'Rent',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>'
                },
                {
                    title: 'Communications & SMS',
                    desc: 'Automated messaging, broadcast reminders and phone logs',
                    url: '<?php echo esc_js( home_url( '/communications' ) ); ?>',
                    cat: 'crm',
                    badge: 'Comms',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.502 49.177 49.177 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" /></svg>'
                },
                {
                    title: 'SMS & Voice Credits',
                    desc: 'Top up wallet, view airtime and carrier usage balance',
                    url: '<?php echo esc_js( home_url( '/credits' ) ); ?>',
                    cat: 'settings',
                    badge: 'Credits',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
                },
                {
                    title: 'Funding & Subscription Plan',
                    desc: 'Manage your active subscription tier and renewals',
                    url: '<?php echo esc_js( home_url( '/funding' ) ); ?>',
                    cat: 'settings',
                    badge: 'Billing',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>'
                },
                {
                    title: 'Team Members & Permissions',
                    desc: 'Invite collaborators, assign roles and manage access',
                    url: '<?php echo esc_js( home_url( '/team' ) ); ?>',
                    cat: 'settings',
                    badge: 'Team',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.765z" /></svg>'
                },
                {
                    title: 'API & Gateway Settings',
                    desc: 'Webhook keys, Termii / Sendchamp integration and credentials',
                    url: '<?php echo esc_js( home_url( '/api-settings' ) ); ?>',
                    cat: 'settings',
                    badge: 'Settings',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9.75L16.5 12l-2.25 2.25m-4.5 0L7.5 12l2.25-2.25M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z" /></svg>'
                },
                {
                    title: 'Notification Settings',
                    desc: 'Configure email alerts, payment reminders and lead pings',
                    url: '<?php echo esc_js( home_url( '/notification-settings' ) ); ?>',
                    cat: 'settings',
                    badge: 'Settings',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>'
                },
                {
                    title: 'Account Profile',
                    desc: 'Personal details, avatar, password and security',
                    url: '<?php echo esc_js( home_url( '/account' ) ); ?>',
                    cat: 'settings',
                    badge: 'Account',
                    icon: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>'
                }
            ];

            function isModalOpen() {
                return modal && modal.classList.contains('open');
            }

            function openSearchModal() {
                if (!modal) return;
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                renderSearchResults();
                setTimeout(function() {
                    if (modalInput) {
                        modalInput.focus();
                        modalInput.select();
                    }
                }, 50);
            }

            function closeSearchModal() {
                if (!modal) return;
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }

            function toggleSearchModal() {
                if (isModalOpen()) {
                    closeSearchModal();
                } else {
                    openSearchModal();
                }
            }

            window.openGlobalSearchModal = openSearchModal;
            window.closeGlobalSearchModal = closeSearchModal;

            // Highlight matches
            function highlightMatch(text, term) {
                if (!term) return text;
                var idx = text.toLowerCase().indexOf(term.toLowerCase());
                if (idx === -1) return text;
                return text.substring(0, idx) + '<strong style="color:var(--btn-primary, var(--accent-purple, #8b5cf6)); text-decoration:underline;">' + text.substring(idx, idx + term.length) + '</strong>' + text.substring(idx + term.length);
            }

            function renderSearchResults() {
                if (!resultsList) return;
                var query = modalInput ? modalInput.value.trim() : '';
                var queryLower = query.toLowerCase();

                var filtered = searchData.filter(function(item) {
                    var matchesCat = currentFilter === 'all' || item.cat === currentFilter;
                    if (!matchesCat) return false;
                    if (!queryLower) return true;
                    return item.title.toLowerCase().indexOf(queryLower) !== -1 ||
                           item.desc.toLowerCase().indexOf(queryLower) !== -1 ||
                           item.badge.toLowerCase().indexOf(queryLower) !== -1;
                });

                var html = '';

                // If user is searching a keyword, add primary dynamic actions
                if (queryLower) {
                    html += '<div class="ofp-search-result-group-title">Live Search Actions</div>';
                    
                    var propSearchUrl = '<?php echo esc_js( home_url( '/properties' ) ); ?>?s=' + encodeURIComponent(query);
                    html += '<a href="' + propSearchUrl + '" class="ofp-search-item' + (selectedIndex === 0 ? ' selected' : '') + '" data-url="' + propSearchUrl + '">';
                    html += '  <div class="ofp-search-item-icon" style="background:rgba(16,185,129,0.12); color:#10b981;">';
                    html += '    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>';
                    html += '  </div>';
                    html += '  <div class="ofp-search-item-info">';
                    html += '    <div class="ofp-search-item-title">Search properties for &ldquo;<strong>' + query + '</strong>&rdquo;</div>';
                    html += '    <div class="ofp-search-item-desc">Filter all listings, locations, and units</div>';
                    html += '  </div>';
                    html += '  <span class="ofp-search-item-badge" style="background:#10b981; color:#ffffff; border-color:#10b981;">Press ↵</span>';
                    html += '</a>';

                    // Contextual page search if applicable
                    var path = window.location.pathname;
                    if (path.indexOf('/leads') !== -1) {
                        var leadUrl = '<?php echo esc_js( home_url( '/leads' ) ); ?>?s=' + encodeURIComponent(query);
                        html += '<a href="' + leadUrl + '" class="ofp-search-item" data-url="' + leadUrl + '">';
                        html += '  <div class="ofp-search-item-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07" /></svg></div>';
                        html += '  <div class="ofp-search-item-info"><div class="ofp-search-item-title">Search leads for &ldquo;<strong>' + query + '</strong>&rdquo;</div></div>';
                        html += '  <span class="ofp-search-item-badge">Leads</span>';
                        html += '</a>';
                    }
                }

                if (filtered.length > 0) {
                    html += '<div class="ofp-search-result-group-title">' + (queryLower ? 'Navigation & Shortcuts' : 'Suggested Shortcuts') + '</div>';
                    
                    var offset = queryLower ? 1 : 0;
                    filtered.forEach(function(item, idx) {
                        var isSel = (idx + offset) === selectedIndex;
                        html += '<a href="' + item.url + '" class="ofp-search-item' + (isSel ? ' selected' : '') + '" data-url="' + item.url + '">';
                        html += '  <div class="ofp-search-item-icon">' + item.icon + '</div>';
                        html += '  <div class="ofp-search-item-info">';
                        html += '    <div class="ofp-search-item-title">' + highlightMatch(item.title, query) + '</div>';
                        html += '    <div class="ofp-search-item-desc">' + highlightMatch(item.desc, query) + '</div>';
                        html += '  </div>';
                        html += '  <span class="ofp-search-item-badge">' + item.badge + '</span>';
                        html += '</a>';
                    });
                } else if (!queryLower) {
                    html += '<div style="padding:24px; text-align:center; color:var(--text-muted); font-size:13px;">No items in this category.</div>';
                }

                resultsList.innerHTML = html;
            }

            // Global Keydown Listener (Capture Phase)
            window.addEventListener('keydown', function(e) {
                // Check for Ctrl+L or Cmd+L (also support Ctrl+K / Cmd+K)
                if ((e.metaKey || e.ctrlKey) && (e.key === 'l' || e.key === 'L' || e.key === 'k' || e.key === 'K')) {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleSearchModal();
                    return false;
                }

                if (e.key === 'Escape' && isModalOpen()) {
                    e.preventDefault();
                    closeSearchModal();
                    return false;
                }

                if (isModalOpen()) {
                    var items = resultsList.querySelectorAll('.ofp-search-item');
                    if (items.length === 0) return;

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        selectedIndex = (selectedIndex + 1) % items.length;
                        items.forEach(function(el, i) {
                            if (i === selectedIndex) {
                                el.classList.add('selected');
                                el.scrollIntoView({ block: 'nearest' });
                            } else {
                                el.classList.remove('selected');
                            }
                        });
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        selectedIndex = (selectedIndex - 1 + items.length) % items.length;
                        items.forEach(function(el, i) {
                            if (i === selectedIndex) {
                                el.classList.add('selected');
                                el.scrollIntoView({ block: 'nearest' });
                            } else {
                                el.classList.remove('selected');
                            }
                        });
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        var activeItem = items[selectedIndex] || items[0];
                        if (activeItem) {
                            var targetUrl = activeItem.getAttribute('data-url') || activeItem.getAttribute('href');
                            if (targetUrl) {
                                window.location.href = targetUrl;
                            }
                        }
                    }
                }
            }, true);

            // Open modal when clicking hero search box
            if (triggerBox) {
                triggerBox.addEventListener('click', function(e) {
                    e.preventDefault();
                    openSearchModal();
                });
            }
            if (heroInput) {
                heroInput.addEventListener('focus', function(e) {
                    e.preventDefault();
                    this.blur();
                    openSearchModal();
                });
            }

            // Close modal button
            if (closeBtn) {
                closeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    closeSearchModal();
                });
            }

            // Close when clicking backdrop
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeSearchModal();
                    }
                });
            }

            // Modal input typing live filtering
            if (modalInput) {
                modalInput.addEventListener('input', function() {
                    selectedIndex = 0;
                    renderSearchResults();
                });
            }

            // Category filter pills
            filterTabs.forEach(function(pill) {
                pill.addEventListener('click', function(e) {
                    e.preventDefault();
                    filterTabs.forEach(function(p) { p.classList.remove('active'); });
                    this.classList.add('active');
                    currentFilter = this.getAttribute('data-filter') || 'all';
                    selectedIndex = 0;
                    renderSearchResults();
                    if (modalInput) modalInput.focus();
                });
            });
        })();
        </script>
        
        <div class="ofp-content-area">
