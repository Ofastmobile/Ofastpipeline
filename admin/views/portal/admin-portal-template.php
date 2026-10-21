<?php
/**
 * Modern Admin Portal Template — Inspired by Design #2
 * Executive Dashboard with Deep Emerald Sidebar and Clean SaaS Layout
 * Complete project management hub with all CRM and Property CPT modules embedded
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Ensure WordPress admin helper functions are always available in portal scope
if ( ! class_exists( 'WP_Screen' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-screen.php' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
}
if ( ! function_exists( 'get_current_screen' ) && file_exists( ABSPATH . 'wp-admin/includes/screen.php' ) ) {
    require_once ABSPATH . 'wp-admin/includes/screen.php';
}
if ( ! function_exists( 'submit_button' ) && file_exists( ABSPATH . 'wp-admin/includes/template.php' ) ) {
    require_once ABSPATH . 'wp-admin/includes/template.php';
}
if ( ! function_exists( 'get_sample_permalink' ) && file_exists( ABSPATH . 'wp-admin/includes/post.php' ) ) {
    require_once ABSPATH . 'wp-admin/includes/post.php';
}

global $wpdb;
$p = $wpdb->prefix;
$current_user = wp_get_current_user();
$tab = $current_tab ?? 'overview';

// ── Metrics Calculation for Overview & Sidebar Badges ─────────────────────────
$total_clients     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_clients" );
$active_clients    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_clients WHERE status = 'active'" );
$pending_clients   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_clients WHERE status = 'pending_review'" );
$white_label_cnt   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_clients WHERE is_white_label = 1" );

$total_props       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_properties" );
$props_sale        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_properties WHERE listing_type = 'sale'" );
$props_rent        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_properties WHERE listing_type = 'rent'" );
$props_live        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_properties WHERE status = 'live'" );

$leads_month       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_leads WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())" );
$leads_today       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_leads WHERE DATE(created_at) = CURDATE()" );

$inquiries_total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_property_inquiries" );
$inquiries_month   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_property_inquiries WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())" );

// Estimated MRR calculation based on client plans
$gold_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_clients WHERE plan = 'gold' AND status = 'active'" );
$silver_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_clients WHERE plan = 'silver' AND status = 'active'" );
$est_mrr      = ( $gold_count * 100000 ) + ( $silver_count * 50000 );

// Alerts
$expiring_soon  = $wpdb->get_results( "SELECT business_name, subscription_expires FROM {$p}ofp_clients WHERE subscription_expires <= DATE_ADD(CURDATE(), INTERVAL 5 DAY) AND status = 'active' ORDER BY subscription_expires ASC LIMIT 5" );
$failed_triggers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ofp_trigger_queue WHERE status = 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)" );

// Recent Clients (For Design #2 Data Table)
$recent_clients = $wpdb->get_results( "
    SELECT c.id, c.business_name, c.owner_name, c.email, c.plan, c.status, c.is_white_label, c.created_at, c.logo_url,
           (SELECT COUNT(*) FROM {$p}ofp_properties WHERE client_id = c.id) as prop_count
    FROM {$p}ofp_clients c
    ORDER BY c.id DESC
    LIMIT 8
" );

$admin_avatar = get_avatar_url( $current_user->ID, [ 'size' => 80 ] );
$admin_name   = $current_user->display_name ?: $current_user->user_login;

// Transient message
$admin_msg = get_transient( 'ofp_admin_message_' . get_current_user_id() );
if ( $admin_msg ) {
    delete_transient( 'ofp_admin_message_' . get_current_user_id() );
}
$message = $admin_msg; // Pass to embedded views

// Page Titles
$titles = [
    'overview'              => 'Dashboard Overview',
    'clients'               => 'Clients Management',
    'leads'                 => 'Leads & CRM Pipeline',
    'team'                  => 'Team Members',
    'communications'        => 'Communications & Templates',
    'triggers'              => 'Automated Trigger Queue',
    'rent-oversight'        => 'Rent & Tenant Oversight',
    'billing'               => 'Billing & Subscriptions',
    'funding-requests'      => 'Funding Requests',
    'send-notification'     => 'Broadcast Notification',
    'reports'               => 'Reports & Performance',
    'properties'            => 'Property Listings Catalog',
    'property-add'          => 'Add New Property',
    'property-types'        => 'Property Types Taxonomy',
    'property-locations'    => 'Locations Taxonomy',
    'property-purchases'    => 'Property Sales & Deals',
    'property-add-purchase' => 'Add Property Purchase',
    'property-create-offer' => 'Create Installment Offer',
    'property-payments'     => 'Transactions & Payment Records',
    'inspections'           => 'Property Inspections & Inquiries',
    'inquiries'             => 'Property Inspections & Inquiries',
    'property-billing'      => 'Listing Subscriptions Billing',
    'settings'              => 'Platform Settings',
    'manage-admins'         => 'Admin Access & Permissions',
    'activity-logs'         => 'System Activity Audit Log',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $titles[ $tab ] ?? 'Admin Portal' ); ?> — OFast Pipeline</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php wp_head(); ?>
</head>
<body class="ofp-admin-portal-body">

<div class="ofp-portal-wrapper">

    <!-- ─────────────────────────────────────────────────────────────────────
         SIDEBAR (Design #2 Deep Emerald Aesthetics - Sleek Compact Weight)
         ───────────────────────────────────────────────────────────────────── -->
    <aside class="ofp-sb" id="ofp-sidebar">
        <!-- Brand Header -->
        <div class="ofp-sb-header">
            <a href="<?php echo esc_url( home_url( '/admin-portal' ) ); ?>" class="ofp-sb-brand">
                <div class="ofp-sb-logo-icon" style="font-size:16px;">⚡</div>
                <div class="ofp-sb-brand-info">
                    <span class="ofp-sb-brand-title">NaijaHub</span>
                    <span class="ofp-sb-brand-badge">v2.2.3</span>
                </div>
            </a>
            <button type="button" class="ofp-sb-collapse-btn" id="ofp-sb-collapse" title="Collapse sidebar">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" /></svg>
            </button>
        </div>

        <!-- Sidebar Search Input (⌘F) -->
        <div class="ofp-sb-search-wrap">
            <div class="ofp-sb-search">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                <input type="text" id="ofp-sb-search-input" placeholder="Quick find menu...">
                <kbd>⌘F</kbd>
            </div>
        </div>

        <!-- Nav Menu Groups -->
        <nav class="ofp-sb-nav">
            <!-- Group 1: CRM & Pipeline -->
            <div class="ofp-nav-group">
                <div class="ofp-nav-group-title">CRM & Pipeline</div>
                <ul class="ofp-nav-list">
                    <li class="ofp-nav-item <?php echo $tab === 'overview' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>
                            <span>Dashboard Overview</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'clients' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=clients' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                            <span>Clients Management</span>
                            <?php if ( $pending_clients > 0 ) : ?>
                                <span class="ofp-nav-badge warning"><?php echo esc_html( $pending_clients ); ?> new</span>
                            <?php else : ?>
                                <span class="ofp-nav-badge"><?php echo esc_html( $active_clients ); ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'leads' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=leads' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                            <span>Leads & Pipeline</span>
                            <span class="ofp-nav-badge"><?php echo esc_html( $leads_today ); ?> today</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'team' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=team' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                            <span>Team Members</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'communications' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=communications' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
                            <span>Communications</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'triggers' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=triggers' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                            <span>Trigger Queue</span>
                            <?php if ( $failed_triggers > 0 ) : ?>
                                <span class="ofp-nav-badge warning"><?php echo esc_html( $failed_triggers ); ?> fail</span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'rent-oversight' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=rent-oversight' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
                            <span>Rent Oversight</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'billing' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=billing' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                            <span>Billing & Subscriptions</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'funding-requests' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=funding-requests' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <span>Funding Requests</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'send-notification' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=send-notification' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" /></svg>
                            <span>Send Notification</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'reports' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=reports' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                            <span>Reports & Analytics</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Group 2: Property Listings (CPT) - COMPLETE SUBMENUS -->
            <div class="ofp-nav-group">
                <div class="ofp-nav-group-title">Property Listings</div>
                <ul class="ofp-nav-list">
                    <li class="ofp-nav-item <?php echo ( $tab === 'properties' && empty($_GET['action']) ) ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=properties' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                            <span>All Properties</span>
                            <span class="ofp-nav-badge"><?php echo esc_html( $total_props ); ?></span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo ( $tab === 'property-add' || ( $tab === 'properties' && ( $_GET['action'] ?? '' ) === 'add' ) ) ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=property-add' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <span>+ Add Property</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'property-types' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=property-types' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>
                            <span>Property Types</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'property-locations' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=property-locations' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                            <span>Locations</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'property-purchases' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=property-purchases' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" /></svg>
                            <span>Sales & Purchases</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'property-add-purchase' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=property-add-purchase' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                            <span>Add Purchase</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'property-create-offer' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=property-create-offer' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                            <span>Create Offer</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'property-payments' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=property-payments' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                            <span>Transactions & Payments</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo ( $tab === 'inspections' || $tab === 'inquiries' ) ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=inspections' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                            <span>Property Inspections</span>
                            <span class="ofp-nav-badge"><?php echo esc_html( $inquiries_month ); ?></span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'property-billing' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=property-billing' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z" /></svg>
                            <span>Listing Subscriptions</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Group 3: System Administration -->
            <div class="ofp-nav-group">
                <div class="ofp-nav-group-title">Administration</div>
                <ul class="ofp-nav-list">
                    <li class="ofp-nav-item <?php echo $tab === 'settings' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=settings' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                            <span>Platform Settings</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'manage-admins' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=manage-admins' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                            <span>Manage Admins</span>
                        </a>
                    </li>
                    <li class="ofp-nav-item <?php echo $tab === 'activity-logs' ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( home_url( '/admin-portal?tab=activity-logs' ) ); ?>">
                            <svg class="ofp-nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <span>Activity Logs</span>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Sidebar Footer (Admin User Info & Return to WP Admin) -->
        <div class="ofp-sb-footer">
            <a href="<?php echo esc_url( admin_url( 'index.php?classic=1' ) ); ?>" class="ofp-wp-switch-btn" title="Return to WordPress Admin Dashboard">
                <svg width="15" height="15" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.373 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.6.11.793-.26.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/></svg>
                <span>Classic WP Admin</span>
            </a>

            <div class="ofp-sb-user-card">
                <img src="<?php echo esc_url( $admin_avatar ); ?>" alt="" class="ofp-sb-user-avatar">
                <div class="ofp-sb-user-info">
                    <div class="ofp-sb-user-name"><?php echo esc_html( $admin_name ); ?></div>
                    <div class="ofp-sb-user-role">Administrator</div>
                </div>
                <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="ofp-sb-logout" title="Sign Out">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
                </a>
            </div>
        </div>
    </aside>

    <!-- ─────────────────────────────────────────────────────────────────────
         MAIN APP CONTENT
         ───────────────────────────────────────────────────────────────────── -->
    <main class="ofp-portal-main">

        <!-- Top Header Navigation Bar (Reduced Height) -->
        <header class="ofp-portal-header">
            <div class="ofp-portal-header-left">
                <div class="ofp-nav-app-branding">
                    <span class="ofp-nav-app-name">⚡ NaijaHub</span>
                    <span class="ofp-nav-app-version">v2.2.3</span>
                </div>
                <div class="ofp-portal-header-title-row">
                    <h3 class="ofp-page-title">
                        <?php echo esc_html( $titles[ $tab ] ?? 'Dashboard' ); ?>
                    </h3>
                    <span class="ofp-topbar-badge">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;margin-right:2px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        Super Admin Console
                    </span>
                </div>
            </div>

            <div class="ofp-portal-header-right">
                <!-- Theme Toggle Button (Light/Dark Mode) -->
                <button type="button" class="ofp-theme-toggle" id="ofp-theme-toggle" title="Toggle Light / Dark Mode">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                </button>

                <!-- Return to WP Admin Button -->
                <a href="<?php echo esc_url( admin_url( 'index.php?classic=1' ) ); ?>" class="ofp-btn ofp-btn-wp" title="Switch to Classic WordPress Admin">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                    <span>WP Admin</span>
                </a>
            </div>
        </header>

        <!-- Dynamic Portal Content -->
        <div class="ofp-portal-content">

            <?php if ( ! empty( $admin_msg ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $admin_msg['type'] ?? 'success' ); ?> ofp-notice is-dismissible" style="margin-bottom: 20px;">
                    <p><strong><?php echo esc_html( $admin_msg['text'] ?? '' ); ?></strong></p>
                </div>
            <?php endif; ?>

            <?php if ( $tab === 'overview' ) : ?>
                <!-- ─────────────────────────────────────────────────────────────
                     EXECUTIVE OVERVIEW (Design #2 Layout)
                     ───────────────────────────────────────────────────────────── -->
                
                <!-- 1. Top 4 Metric Cards -->
                <div class="ofp-kpi-grid">
                    <!-- KPI 1: Estimated MRR -->
                    <div class="ofp-kpi-card">
                        <div class="ofp-kpi-top">
                            <span class="ofp-kpi-label">Active MRR</span>
                            <div class="ofp-kpi-icon-wrap">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            </div>
                        </div>
                        <div class="ofp-kpi-value">₦<?php echo number_format( $est_mrr ); ?></div>
                        <div class="ofp-kpi-bottom">
                            <span class="ofp-kpi-pill positive">
                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" /></svg>
                                Active Base
                            </span>
                            <a href="<?php echo esc_url( home_url( '/admin-portal?tab=billing' ) ); ?>" class="ofp-kpi-arrow">→</a>
                        </div>
                    </div>

                    <!-- KPI 2: Active Clients -->
                    <div class="ofp-kpi-card">
                        <div class="ofp-kpi-top">
                            <span class="ofp-kpi-label">Active Clients</span>
                            <div class="ofp-kpi-icon-wrap">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                            </div>
                        </div>
                        <div class="ofp-kpi-value"><?php echo esc_html( $active_clients ); ?> <span style="font-size:13px; color:var(--ofp-text-muted); font-weight:500;">/ <?php echo esc_html( $total_clients ); ?> total</span></div>
                        <div class="ofp-kpi-bottom">
                            <?php if ( $pending_clients > 0 ) : ?>
                                <span class="ofp-kpi-pill warning"><?php echo esc_html( $pending_clients ); ?> pending</span>
                            <?php else : ?>
                                <span class="ofp-kpi-pill positive">100% Verified</span>
                            <?php endif; ?>
                            <a href="<?php echo esc_url( home_url( '/admin-portal?tab=clients' ) ); ?>" class="ofp-kpi-arrow">→</a>
                        </div>
                    </div>

                    <!-- KPI 3: Total Properties Listed -->
                    <div class="ofp-kpi-card">
                        <div class="ofp-kpi-top">
                            <span class="ofp-kpi-label">Total Properties</span>
                            <div class="ofp-kpi-icon-wrap">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
                            </div>
                        </div>
                        <div class="ofp-kpi-value"><?php echo esc_html( $total_props ); ?></div>
                        <div class="ofp-kpi-bottom">
                            <span><?php echo esc_html( $props_sale ); ?> Sale • <?php echo esc_html( $props_rent ); ?> Rent</span>
                            <a href="<?php echo esc_url( home_url( '/admin-portal?tab=properties' ) ); ?>" class="ofp-kpi-arrow">→</a>
                        </div>
                    </div>

                    <!-- KPI 4: Leads This Month -->
                    <div class="ofp-kpi-card">
                        <div class="ofp-kpi-top">
                            <span class="ofp-kpi-label">Leads This Month</span>
                            <div class="ofp-kpi-icon-wrap">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                            </div>
                        </div>
                        <div class="ofp-kpi-value"><?php echo esc_html( $leads_month ); ?></div>
                        <div class="ofp-kpi-bottom">
                            <span class="ofp-kpi-pill positive"><?php echo esc_html( $leads_today ); ?> today</span>
                            <a href="<?php echo esc_url( home_url( '/admin-portal?tab=leads' ) ); ?>" class="ofp-kpi-arrow">→</a>
                        </div>
                    </div>
                </div>

                <!-- 2. Middle Row: Performance Area Chart + Pipeline Funnel -->
                <div class="ofp-middle-grid">
                    <!-- Area Chart -->
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <div>
                                <h3 class="ofp-card-title">Overall Platform Trajectory</h3>
                                <div style="font-size:20px; font-weight:700; color:var(--ofp-text-main); margin-top:4px;">
                                    ₦<?php echo number_format( $est_mrr * 12 ); ?> <span style="font-size:12px; font-weight:500; color:#10b981;">+18.4% ARR</span>
                                </div>
                            </div>
                            <div class="ofp-card-filters">
                                <select class="ofp-select">
                                    <option>This Year (2026)</option>
                                    <option>Last 6 Months</option>
                                    <option>Last 30 Days</option>
                                </select>
                            </div>
                        </div>

                        <!-- Smooth Wave Vector SVG Chart -->
                        <div class="ofp-chart-container">
                            <svg class="ofp-chart-svg" viewBox="0 0 600 200" preserveAspectRatio="none">
                                <defs>
                                    <linearGradient id="ofpGreenGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                        <stop offset="0%" stop-color="#10b981" stop-opacity="0.35"/>
                                        <stop offset="100%" stop-color="#10b981" stop-opacity="0.0"/>
                                    </linearGradient>
                                </defs>
                                <line x1="0" y1="40" x2="600" y2="40" stroke="var(--ofp-card-border)" stroke-dasharray="3,3" />
                                <line x1="0" y1="90" x2="600" y2="90" stroke="var(--ofp-card-border)" stroke-dasharray="3,3" />
                                <line x1="0" y1="140" x2="600" y2="140" stroke="var(--ofp-card-border)" stroke-dasharray="3,3" />
                                <path d="M0,160 C100,120 180,150 280,80 C380,20 480,90 600,40 L600,200 L0,200 Z" fill="url(#ofpGreenGrad)" />
                                <path d="M0,160 C100,120 180,150 280,80 C380,20 480,90 600,40" fill="none" stroke="#10b981" stroke-width="3" />
                                <circle cx="280" cy="80" r="4.5" fill="#10b981" stroke="#ffffff" stroke-width="2" />
                                <circle cx="600" cy="40" r="4.5" fill="#10b981" stroke="#ffffff" stroke-width="2" />
                            </svg>
                        </div>
                    </div>

                    <!-- Pipeline Conversion Funnel -->
                    <div class="ofp-card">
                        <div class="ofp-card-header">
                            <h3 class="ofp-card-title">Pipeline Conversion</h3>
                            <span class="ofp-kpi-pill positive">Real-Time</span>
                        </div>

                        <div class="ofp-funnel-list">
                            <div>
                                <div class="ofp-funnel-item">
                                    <span class="ofp-funnel-label">Leads Captured</span>
                                    <span class="ofp-funnel-num"><?php echo esc_html( $leads_month ); ?></span>
                                </div>
                                <div class="ofp-funnel-bar-bg">
                                    <div class="ofp-funnel-bar-fill" style="width: 100%;"></div>
                                </div>
                            </div>

                            <div>
                                <div class="ofp-funnel-item">
                                    <span class="ofp-funnel-label">Property Inquiries</span>
                                    <span class="ofp-funnel-num"><?php echo esc_html( $inquiries_month ); ?></span>
                                </div>
                                <div class="ofp-funnel-bar-bg">
                                    <div class="ofp-funnel-bar-fill" style="width: 68%;"></div>
                                </div>
                            </div>

                            <div>
                                <div class="ofp-funnel-item">
                                    <span class="ofp-funnel-label">Inspections Completed</span>
                                    <span class="ofp-funnel-num"><?php echo esc_html( max( 0, (int)($inquiries_month * 0.45) ) ); ?></span>
                                </div>
                                <div class="ofp-funnel-bar-bg">
                                    <div class="ofp-funnel-bar-fill" style="width: 45%;"></div>
                                </div>
                            </div>

                            <div>
                                <div class="ofp-funnel-item">
                                    <span class="ofp-funnel-label">Offers & Closed Deals</span>
                                    <span class="ofp-funnel-num"><?php echo esc_html( max( 0, (int)($inquiries_month * 0.22) ) ); ?></span>
                                </div>
                                <div class="ofp-funnel-bar-bg">
                                    <div class="ofp-funnel-bar-fill" style="width: 22%;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. Bottom Row: Priority Action Promo Box + Interactive Clients Table -->
                <div class="ofp-bottom-grid">
                    <!-- Left: Priority Action Promo Card -->
                    <div class="ofp-promo-card">
                        <div>
                            <span class="ofp-promo-tag">Platform Health</span>
                            <h3 class="ofp-promo-title">Priority Oversight</h3>
                            <p class="ofp-promo-desc">Client compliance, automated SMS/voice triggers, and white-label hubs are monitored 24/7.</p>
                            
                            <div class="ofp-promo-stats">
                                <div class="ofp-promo-stat-box">
                                    <span class="ofp-promo-stat-val"><?php echo esc_html( $pending_clients ); ?></span>
                                    <span class="ofp-promo-stat-lbl">Pending Review</span>
                                </div>
                                <div class="ofp-promo-stat-box">
                                    <span class="ofp-promo-stat-val"><?php echo esc_html( $white_label_cnt ); ?></span>
                                    <span class="ofp-promo-stat-lbl">White Label Hubs</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <a href="<?php echo esc_url( home_url( '/admin-portal?tab=clients' ) ); ?>" class="ofp-btn ofp-btn-primary" style="width:100%;">
                                Review Clients Now →
                            </a>
                        </div>
                    </div>

                    <!-- Right: Clients Data Table with Horizontal Scroll -->
                    <div class="ofp-table-card">
                        <div class="ofp-table-toolbar">
                            <div class="ofp-table-search">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                                <input type="text" id="ofp-table-search-input" placeholder="Search clients, plans, status...">
                            </div>

                            <div class="ofp-table-actions">
                                <a href="<?php echo esc_url( home_url( '/admin-portal?tab=clients' ) ); ?>" class="ofp-btn ofp-btn-outline" style="font-size:12px; padding:5px 10px;">
                                    View All (<?php echo esc_html( $total_clients ); ?>)
                                </a>
                            </div>
                        </div>

                        <div class="ofp-table-wrap">
                            <table class="ofp-data-table">
                                <thead>
                                    <tr>
                                        <th>Client Name / Business</th>
                                        <th>Plan</th>
                                        <th>Properties</th>
                                        <th>White Label</th>
                                        <th>Status</th>
                                        <th style="text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ( empty( $recent_clients ) ) : ?>
                                        <tr><td colspan="6" style="text-align:center; padding:24px; color:var(--ofp-text-muted);">No clients registered yet.</td></tr>
                                    <?php else : ?>
                                        <?php foreach ( $recent_clients as $rc ) : ?>
                                            <tr>
                                                <td>
                                                    <div class="ofp-user-cell">
                                                        <img src="<?php echo esc_url( $rc->logo_url ?: OFP_URL . 'assets/images/default-avatar.png' ); ?>" alt="" class="ofp-user-thumb">
                                                        <div class="ofp-user-meta">
                                                            <span class="ofp-user-title"><?php echo esc_html( $rc->business_name ?: $rc->owner_name ); ?></span>
                                                            <span class="ofp-user-sub"><?php echo esc_html( $rc->email ); ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span style="font-weight:600; text-transform:capitalize;"><?php echo esc_html( $rc->plan ?: 'Free' ); ?></span>
                                                </td>
                                                <td>
                                                    <span><?php echo esc_html( $rc->prop_count ); ?> listed</span>
                                                </td>
                                                <td>
                                                    <?php if ( ! empty( $rc->is_white_label ) ) : ?>
                                                        <span class="ofp-status-pill white_label">Enabled</span>
                                                    <?php else : ?>
                                                        <span style="color:var(--ofp-text-light);">Standard</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="ofp-status-pill <?php echo esc_attr( $rc->status ); ?>">
                                                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $rc->status ) ) ); ?>
                                                    </span>
                                                </td>
                                                <td style="text-align:right;">
                                                    <a href="<?php echo esc_url( home_url( '/admin-portal?tab=clients&action=edit&client_id=' . $rc->id ) ); ?>" class="ofp-btn ofp-btn-outline" style="font-size:11.5px; padding:4px 8px;">
                                                        Manage
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ( $tab === 'clients' ) : ?>
                <!-- Embedded Clients Module -->
                <?php include OFP_PATH . 'admin/views/clients-list.php'; ?>

            <?php elseif ( $tab === 'leads' ) : ?>
                <!-- Embedded Leads Module -->
                <?php include OFP_PATH . 'admin/views/leads-list.php'; ?>

            <?php elseif ( $tab === 'team' ) : ?>
                <!-- Embedded Team Module -->
                <?php include OFP_PATH . 'admin/views/team-list.php'; ?>

            <?php elseif ( $tab === 'communications' ) : ?>
                <!-- Embedded Communications Module -->
                <?php include OFP_PATH . 'admin/views/communications.php'; ?>

            <?php elseif ( $tab === 'triggers' ) : ?>
                <!-- Embedded Triggers Module -->
                <?php include OFP_PATH . 'admin/views/triggers-list.php'; ?>

            <?php elseif ( $tab === 'rent-oversight' ) : ?>
                <!-- Embedded Rent Oversight Module -->
                <?php include OFP_PATH . 'admin/views/rent-oversight.php'; ?>

            <?php elseif ( $tab === 'billing' ) : ?>
                <!-- Embedded Billing Module -->
                <?php include OFP_PATH . 'admin/views/billing.php'; ?>

            <?php elseif ( $tab === 'funding-requests' ) : ?>
                <!-- Embedded Funding Requests Module -->
                <?php
                if ( class_exists( 'OFP_Admin_Menu' ) ) {
                    ( new OFP_Admin_Menu() )->render_funding_requests();
                }
                ?>

            <?php elseif ( $tab === 'send-notification' ) : ?>
                <!-- Embedded Send Notification Module -->
                <?php
                if ( class_exists( 'OFP_Admin_Menu' ) ) {
                    ( new OFP_Admin_Menu() )->render_send_notification();
                }
                ?>

            <?php elseif ( $tab === 'reports' ) : ?>
                <!-- Embedded Reports Module -->
                <?php include OFP_PATH . 'admin/views/reports.php'; ?>

            <?php elseif ( $tab === 'settings' ) : ?>
                <!-- Embedded Settings Module -->
                <?php include OFP_PATH . 'admin/views/settings.php'; ?>

            <?php elseif ( $tab === 'manage-admins' ) : ?>
                <!-- Embedded Admins Module -->
                <?php include OFP_PATH . 'admin/views/manage-admins.php'; ?>

            <?php elseif ( $tab === 'activity-logs' ) : ?>
                <!-- Embedded Activity Logs Module -->
                <?php include OFP_PATH . 'admin/views/activity-logs.php'; ?>

            <?php elseif ( $tab === 'property-purchases' ) : ?>
                <!-- Embedded Property Sales & Purchases Table -->
                <?php
                if ( class_exists( 'OFP_Property_Commerce_Admin' ) ) {
                    ( new OFP_Property_Commerce_Admin() )->render_purchases();
                }
                ?>

            <?php elseif ( $tab === 'property-add-purchase' ) : ?>
                <!-- Embedded Add Property Purchase -->
                <?php
                if ( class_exists( 'OFP_Property_Purchase_Admin' ) ) {
                    OFP_Property_Purchase_Admin::render_create();
                }
                ?>

            <?php elseif ( $tab === 'property-create-offer' ) : ?>
                <!-- Embedded Create Offer -->
                <?php
                if ( class_exists( 'OFP_Property_Commerce_Actions' ) ) {
                    OFP_Property_Commerce_Actions::render_create_offer();
                }
                ?>

            <?php elseif ( $tab === 'property-payments' ) : ?>
                <!-- Embedded Property Transactions / Payments -->
                <?php
                if ( class_exists( 'OFP_Property_Payment_Records' ) ) {
                    OFP_Property_Payment_Records::render_admin();
                }
                ?>

            <?php elseif ( $tab === 'inspections' || $tab === 'inquiries' ) : ?>
                <!-- Embedded Property Inspections -->
                <?php
                if ( class_exists( 'OFP_Property_Inquiry' ) ) {
                    OFP_Property_Inquiry::render_admin_inspections();
                }
                ?>

            <?php elseif ( $tab === 'property-billing' ) : ?>
                <!-- Embedded Property Subscriptions Billing -->
                <?php
                if ( class_exists( 'OFP_Property_Commerce_Admin' ) ) {
                    ( new OFP_Property_Commerce_Admin() )->render_billing();
                }
                ?>

            <?php elseif ( $tab === 'property-types' ) : ?>
                <!-- ─────────────────────────────────────────────────────────────
                     PROPERTY TYPES TAXONOMY MANAGER (In-Portal)
                     ───────────────────────────────────────────────────────────── -->
                <?php
                $types = get_terms( [ 'taxonomy' => 'ofp_property_type', 'hide_empty' => false ] );
                ?>
                <div class="ofp-card">
                    <div class="ofp-card-header">
                        <h3 class="ofp-card-title">Property Types Taxonomy</h3>
                        <span class="ofp-kpi-pill positive"><?php echo esc_html( is_array( $types ) ? count( $types ) : 0 ); ?> Types</span>
                    </div>

                    <div class="ofp-tax-grid">
                        <!-- Add New Type Form -->
                        <div class="ofp-card" style="padding:18px; border:1px solid var(--ofp-card-border);">
                            <h4 style="margin-bottom:14px; font-size:14px; color:var(--ofp-text-main);">Add Property Type</h4>
                            <form method="POST" action="<?php echo esc_url( home_url( '/admin-portal?tab=property-types' ) ); ?>">
                                <?php wp_nonce_field( 'ofp_type_action', 'ofp_type_nonce' ); ?>
                                <input type="hidden" name="ofp_action" value="add_property_type">

                                <div class="ofp-form-group">
                                    <label>Type Name <span style="color:#ef4444;">*</span></label>
                                    <input type="text" name="type_name" class="ofp-input-text" required placeholder="e.g. Penthouse Suite">
                                </div>

                                <div class="ofp-form-group">
                                    <label>Slug (Optional)</label>
                                    <input type="text" name="type_slug" class="ofp-input-text" placeholder="e.g. penthouse-suite">
                                </div>

                                <div class="ofp-form-group">
                                    <label>Description</label>
                                    <textarea name="type_description" class="ofp-textarea" rows="3" placeholder="Brief details about this architectural type..."></textarea>
                                </div>

                                <button type="submit" class="ofp-btn ofp-btn-primary" style="width:100%; margin-top:8px;">
                                    + Add Type
                                </button>
                            </form>
                        </div>

                        <!-- Types Table (Horizontal Scroll) -->
                        <div class="ofp-table-wrap">
                            <table class="ofp-data-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Slug</th>
                                        <th>Description</th>
                                        <th>Total Properties</th>
                                        <th style="text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ( empty( $types ) || is_wp_error( $types ) ) : ?>
                                        <tr><td colspan="5" style="text-align:center; padding:24px; color:var(--ofp-text-muted);">No property types registered.</td></tr>
                                    <?php else : ?>
                                        <?php foreach ( $types as $t ) : ?>
                                            <tr>
                                                <td><strong style="color:var(--ofp-text-main);"><?php echo esc_html( $t->name ); ?></strong></td>
                                                <td><code><?php echo esc_html( $t->slug ); ?></code></td>
                                                <td><?php echo esc_html( $t->description ?: '—' ); ?></td>
                                                <td><span class="ofp-kpi-pill positive"><?php echo esc_html( $t->count ); ?> listings</span></td>
                                                <td style="text-align:right;">
                                                    <a href="<?php echo esc_url( wp_nonce_url( home_url( '/admin-portal?tab=property-types&action=delete_type&term_id=' . $t->term_id ), 'ofp_del_type_' . $t->term_id ) ); ?>" 
                                                       onclick="return confirm('Delete property type <?php echo esc_js( $t->name ); ?>?');"
                                                       class="ofp-btn ofp-btn-outline" style="font-size:11px; padding:3px 8px; color:#ef4444; border-color:rgba(239,68,68,0.3);">
                                                        Delete
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ( $tab === 'property-locations' ) : ?>
                <!-- ─────────────────────────────────────────────────────────────
                     LOCATIONS TAXONOMY MANAGER (In-Portal)
                     ───────────────────────────────────────────────────────────── -->
                <?php
                $locations = get_terms( [ 'taxonomy' => 'ofp_property_location', 'hide_empty' => false ] );
                ?>
                <div class="ofp-card">
                    <div class="ofp-card-header">
                        <h3 class="ofp-card-title">Property Locations Taxonomy</h3>
                        <span class="ofp-kpi-pill positive"><?php echo esc_html( is_array( $locations ) ? count( $locations ) : 0 ); ?> Locations</span>
                    </div>

                    <div class="ofp-tax-grid">
                        <!-- Add Location Form -->
                        <div class="ofp-card" style="padding:18px; border:1px solid var(--ofp-card-border);">
                            <h4 style="margin-bottom:14px; font-size:14px; color:var(--ofp-text-main);">Add Location / Area</h4>
                            <form method="POST" action="<?php echo esc_url( home_url( '/admin-portal?tab=property-locations' ) ); ?>">
                                <?php wp_nonce_field( 'ofp_loc_action', 'ofp_loc_nonce' ); ?>
                                <input type="hidden" name="ofp_action" value="add_property_location">

                                <div class="ofp-form-group">
                                    <label>Location Name <span style="color:#ef4444;">*</span></label>
                                    <input type="text" name="loc_name" class="ofp-input-text" required placeholder="e.g. Lekki Phase 1, Lagos">
                                </div>

                                <div class="ofp-form-group">
                                    <label>Slug (Optional)</label>
                                    <input type="text" name="loc_slug" class="ofp-input-text" placeholder="e.g. lekki-phase-1">
                                </div>

                                <div class="ofp-form-group">
                                    <label>Description</label>
                                    <textarea name="loc_description" class="ofp-textarea" rows="3" placeholder="Area highlights, landmarks, accessibility..."></textarea>
                                </div>

                                <button type="submit" class="ofp-btn ofp-btn-primary" style="width:100%; margin-top:8px;">
                                    + Add Location
                                </button>
                            </form>
                        </div>

                        <!-- Locations Table (Horizontal Scroll) -->
                        <div class="ofp-table-wrap">
                            <table class="ofp-data-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Slug</th>
                                        <th>Description</th>
                                        <th>Total Properties</th>
                                        <th style="text-align:right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ( empty( $locations ) || is_wp_error( $locations ) ) : ?>
                                        <tr><td colspan="5" style="text-align:center; padding:24px; color:var(--ofp-text-muted);">No locations registered.</td></tr>
                                    <?php else : ?>
                                        <?php foreach ( $locations as $loc ) : ?>
                                            <tr>
                                                <td><strong style="color:var(--ofp-text-main);"><?php echo esc_html( $loc->name ); ?></strong></td>
                                                <td><code><?php echo esc_html( $loc->slug ); ?></code></td>
                                                <td><?php echo esc_html( $loc->description ?: '—' ); ?></td>
                                                <td><span class="ofp-kpi-pill positive"><?php echo esc_html( $loc->count ); ?> listings</span></td>
                                                <td style="text-align:right;">
                                                    <a href="<?php echo esc_url( wp_nonce_url( home_url( '/admin-portal?tab=property-locations&action=delete_location&term_id=' . $loc->term_id ), 'ofp_del_loc_' . $loc->term_id ) ); ?>" 
                                                       onclick="return confirm('Delete location <?php echo esc_js( $loc->name ); ?>?');"
                                                       class="ofp-btn ofp-btn-outline" style="font-size:11px; padding:3px 8px; color:#ef4444; border-color:rgba(239,68,68,0.3);">
                                                        Delete
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ( $tab === 'property-add' || ( $tab === 'properties' && in_array( $_GET['action'] ?? '', [ 'add', 'edit' ], true ) ) ) : ?>
                <!-- ─────────────────────────────────────────────────────────────
                     PROPERTY CREATION & EDITING STUDIO (In-Portal Modern SaaS)
                     ───────────────────────────────────────────────────────────── -->
                <?php
                $edit_id   = absint( $_GET['id'] ?? 0 );
                $edit_prop = null;
                $edit_post = null;
                if ( $edit_id ) {
                    $edit_prop = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_properties WHERE id = %d LIMIT 1", $edit_id ) );
                    if ( ! $edit_prop ) {
                        // Fallback: check if id was wp_post_id
                        $edit_prop = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_properties WHERE wp_post_id = %d LIMIT 1", $edit_id ) );
                    }
                    if ( $edit_prop && ! empty( $edit_prop->wp_post_id ) ) {
                        $edit_post = get_post( $edit_prop->wp_post_id );
                    } elseif ( ! $edit_prop && get_post( $edit_id ) && get_post( $edit_id )->post_type === 'ofp_property' ) {
                        $edit_post = get_post( $edit_id );
                        $edit_prop = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ofp_properties WHERE wp_post_id = %d LIMIT 1", $edit_post->ID ) );
                    }
                }

                $post_id               = $edit_post ? $edit_post->ID : 0;
                $val_title             = $edit_post ? $edit_post->post_title : ( $edit_prop->title ?? '' );
                $val_content           = $edit_post ? $edit_post->post_content : ( $edit_prop->description ?? '' );
                $val_listing_type      = $post_id ? get_post_meta( $post_id, 'ofp_listing_type', true ) : ( $edit_prop->listing_type ?? 'sale' );
                if ( empty( $val_listing_type ) ) $val_listing_type = 'sale';
                $val_property_type     = $post_id ? get_post_meta( $post_id, 'ofp_property_type', true ) : ( $edit_prop->property_type ?? 'apartment' );
                if ( empty( $val_property_type ) ) $val_property_type = 'apartment';
                $val_price             = $post_id ? get_post_meta( $post_id, 'ofp_price', true ) : ( $edit_prop->price ?? '' );
                $val_price_period      = $post_id ? get_post_meta( $post_id, 'ofp_price_period', true ) : ( $edit_prop->price_period ?? 'sales' );
                if ( $val_price_period === 'one-time' || empty( $val_price_period ) ) $val_price_period = 'sales';
                $val_total_package     = $post_id ? get_post_meta( $post_id, 'ofp_total_package', true ) : '';
                $val_allow_installment = $post_id ? get_post_meta( $post_id, 'ofp_allow_installment', true ) : '0';
                $val_title_document    = $post_id ? get_post_meta( $post_id, 'ofp_title_document', true ) : '';
                $val_client_id         = $post_id ? (int) get_post_meta( $post_id, 'ofp_client_id', true ) : (int) ( $edit_prop->client_id ?? 0 );
                $val_bank_id           = $post_id ? (int) get_post_meta( $post_id, 'ofp_bank_account_id', true ) : 0;
                $val_location_text     = $post_id ? get_post_meta( $post_id, 'ofp_location_text', true ) : ( $edit_prop->location_text ?? '' );
                $val_bedrooms          = $post_id ? get_post_meta( $post_id, 'ofp_bedrooms', true ) : ( $edit_prop->bedrooms ?? 4 );
                $val_bathrooms         = $post_id ? get_post_meta( $post_id, 'ofp_bathrooms', true ) : ( $edit_prop->bathrooms ?? 4 );
                $val_parking           = $post_id ? get_post_meta( $post_id, 'ofp_parking', true ) : ( $edit_prop->parking ?? 2 );
                $val_area_sqm          = $post_id ? get_post_meta( $post_id, 'ofp_area_sqm', true ) : ( $edit_prop->area_sqm ?? 350 );
                $val_plots_count       = $post_id ? get_post_meta( $post_id, 'ofp_plots_count', true ) : '';
                $val_land_topography   = $post_id ? get_post_meta( $post_id, 'ofp_land_topography', true ) : '';
                $val_land_zoning       = $post_id ? get_post_meta( $post_id, 'ofp_land_zoning', true ) : '';
                $val_land_dimensions   = $post_id ? get_post_meta( $post_id, 'ofp_land_dimensions', true ) : '';
                $val_survey_status     = $post_id ? get_post_meta( $post_id, 'ofp_survey_status', true ) : '';
                $val_condition         = $post_id ? get_post_meta( $post_id, 'ofp_condition', true ) : '';
                $val_furnishing        = $post_id ? get_post_meta( $post_id, 'ofp_furnishing', true ) : '';
                $val_video_url         = $post_id ? get_post_meta( $post_id, 'ofp_video_url', true ) : '';
                $val_inspection_days   = $post_id ? ( get_post_meta( $post_id, 'ofp_inspection_days', true ) ?: 'Mondays - Saturdays' ) : 'Mondays - Saturdays';
                $val_inspection_hours  = $post_id ? ( get_post_meta( $post_id, 'ofp_inspection_hours', true ) ?: '10:00 AM - 5:00 PM' ) : '10:00 AM - 5:00 PM';
                $val_inspection_notice = $post_id ? get_post_meta( $post_id, 'ofp_inspection_notice', true ) : '';
                $val_status            = $post_id ? ( get_post_meta( $post_id, 'ofp_status', true ) ?: ( $edit_prop->status ?? 'live' ) ) : ( $edit_prop->status ?? 'live' );
                $val_is_featured       = $post_id ? get_post_meta( $post_id, 'ofp_is_featured', true ) : ( $edit_prop->is_featured ?? 0 );
                $val_amenities         = $post_id ? ( json_decode( get_post_meta( $post_id, 'ofp_amenities', true ) ?: '[]', true ) ?: [] ) : [];
                $val_gallery_ids       = $post_id ? ( json_decode( get_post_meta( $post_id, 'ofp_gallery_ids', true ) ?: '[]', true ) ?: [] ) : [];
                $post_thumb_id         = $post_id ? (int) get_post_thumbnail_id( $post_id ) : 0;
                $post_thumb_url        = $post_thumb_id ? wp_get_attachment_image_url( $post_thumb_id, 'medium' ) : ( $edit_prop->featured_image ?? '' );
                $public_url            = $post_id ? get_permalink( $post_id ) : '';

                $clients_list  = $wpdb->get_results( "SELECT id, business_name, owner_name, email, phone FROM {$p}ofp_clients ORDER BY business_name ASC" ) ?: [];
                $bank_accounts = $wpdb->get_results( "SELECT id, client_id, bank_name, account_number, account_name, is_default FROM {$p}ofp_bank_accounts ORDER BY is_default DESC, bank_name ASC" ) ?: [];

                $tax_locations = get_terms( [ 'taxonomy' => 'ofp_property_location', 'hide_empty' => false ] );
                if ( is_wp_error( $tax_locations ) ) $tax_locations = [];

                $tax_types = get_terms( [ 'taxonomy' => 'ofp_property_type', 'hide_empty' => false ] );
                if ( is_wp_error( $tax_types ) ) $tax_types = [];

                $curr_loc_terms  = $post_id ? wp_get_object_terms( $post_id, 'ofp_property_location', [ 'fields' => 'names' ] ) : [];
                $curr_type_terms = $post_id ? wp_get_object_terms( $post_id, 'ofp_property_type', [ 'fields' => 'names' ] ) : [];
                $sel_loc_term    = ! empty( $curr_loc_terms ) ? $curr_loc_terms[0] : '';
                $sel_type_term   = ! empty( $curr_type_terms ) ? $curr_type_terms[0] : '';

                // Catalog of all 10 amenities
                $amenity_items = [
                    'swimming_pool'   => [ 'label' => 'Swimming Pool',            'icon' => '🏊' ],
                    'smart_home'      => [ 'label' => 'Smart Home Automation',   'icon' => '🏠' ],
                    'power_247'       => [ 'label' => '24/7 Electricity / Gen',  'icon' => '⚡' ],
                    'cctv_security'   => [ 'label' => 'CCTV & Uniformed Security','icon' => '🛡️' ],
                    'gym'             => [ 'label' => 'Gym / Fitness Center',    'icon' => '🏋️' ],
                    'elevator'        => [ 'label' => 'Elevator / Lift',         'icon' => '🛗' ],
                    'playground'      => [ 'label' => "Children's Play Area",     'icon' => '🛝' ],
                    'bq'              => [ 'label' => 'Boys Quarters (BQ)',      'icon' => '🏡' ],
                    'water_treatment' => [ 'label' => 'Water Treatment Plant',   'icon' => '💧' ],
                    'fitted_kitchen'  => [ 'label' => 'Fully Fitted Kitchen',    'icon' => '🍳' ],
                ];

                // Gallery preview list
                $display_gallery = (array) $val_gallery_ids;
                if ( $post_thumb_id && ! in_array( $post_thumb_id, $display_gallery, true ) ) {
                    array_unshift( $display_gallery, $post_thumb_id );
                }
                ?>

                <!-- Property Studio styles are enqueued from assets/css/admin-portal.css -->
                <div class="ofp-studio-container">
                    <form method="POST" action="<?php echo esc_url( home_url( '/admin-portal?tab=properties' ) ); ?>" id="ofp_studio_form">
                        <?php wp_nonce_field( 'ofp_save_property_action', 'ofp_save_property_nonce' ); ?>
                        <input type="hidden" name="ofp_action" value="save_property">
                        <input type="hidden" name="property_id" value="<?php echo esc_attr( $edit_prop->id ?? 0 ); ?>">
                        <input type="hidden" name="wp_post_id" value="<?php echo esc_attr( $post_id ); ?>">
                        <input type="hidden" name="featured_image_id" id="ofp_featured_image_id" value="<?php echo esc_attr( $post_thumb_id ); ?>">
                        <input type="hidden" name="gallery_ids" id="ofp_gallery_ids_input" value="<?php echo esc_attr( json_encode( $display_gallery ) ); ?>">

                        <!-- Top Studio Action Bar -->
                        <div class="ofp-card" style="margin-bottom:20px;">
                            <div class="ofp-card-header" style="padding:16px 22px;">
                                <div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span class="ofp-kpi-pill <?php echo ( $val_status === 'live' ) ? 'positive' : 'warning'; ?>" style="font-size:11px;">
                                            ● <?php echo esc_html( ucfirst( $val_status ) ); ?>
                                        </span>
                                        <h2 style="font-size:18px; font-weight:800; color:var(--ofp-text-main); margin:0;">
                                            <?php echo $post_id ? 'Edit Property: ' . esc_html( $val_title ) : 'Add New Property Listing'; ?>
                                        </h2>
                                    </div>
                                    <span class="ofp-page-subtitle">Fully integrated real estate management studio — synchronized with public marketplace, CRM leads, and client dashboards.</span>
                                </div>
                                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <?php if ( $public_url ) : ?>
                                        <a href="<?php echo esc_url( $public_url ); ?>" target="_blank" rel="noopener" class="ofp-btn ofp-btn-outline" style="font-size:12px; padding:6px 12px; border-color:rgba(16,185,129,0.3); color:#10b981;">
                                            View Live ↗
                                        </a>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url( home_url( '/admin-portal?tab=properties' ) ); ?>" class="ofp-btn ofp-btn-outline" style="font-size:12px; padding:6px 12px;">
                                        ← Back to Properties
                                    </a>
                                    <button type="submit" class="ofp-btn ofp-btn-primary" style="padding:7px 18px; font-size:13px;">
                                        Save Property Listing
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- ── 1. Basic Information & Categorization ──────────────── -->
                        <div class="ofp-sec-card">
                            <div class="ofp-sec-header">
                                <div class="ofp-sec-icon">🏛️</div>
                                <div>
                                    <h4 class="ofp-sec-title">1. Basic Information &amp; Architecture</h4>
                                    <p class="ofp-sec-subtitle">Primary marketing headline, commercial intent, and real estate categorization.</p>
                                </div>
                            </div>

                            <div class="ofp-form-group">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <label>Property Title &amp; Marketing Headline <span style="color:#ef4444;">*</span></label>
                                    <span id="ofp_title_len" style="font-size:11.5px; color:var(--ofp-text-muted);">0 / 120 chars</span>
                                </div>
                                <input type="text" name="title" id="ofp_title_input" class="ofp-input-text" required
                                       value="<?php echo esc_attr( $val_title ); ?>"
                                       placeholder="e.g. Luxurious 4-Bedroom Fully Detached Waterfront Duplex with Private Jetty &amp; BQ">
                                <?php if ( $public_url ) : ?>
                                    <div style="font-size:11.5px; color:var(--ofp-text-muted); margin-top:4px;">
                                        Marketplace Link: <a href="<?php echo esc_url( $public_url ); ?>" target="_blank" style="color:#10b981; font-weight:600;"><?php echo esc_html( $public_url ); ?></a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="ofp-form-grid-2">
                                <div class="ofp-form-group">
                                    <label>Listing Commercial Mode <span style="color:#ef4444;">*</span></label>
                                    <select name="listing_type" id="ofp_listing_type_sel" class="ofp-input-select">
                                        <option value="sale" <?php selected( $val_listing_type, 'sale' ); ?>>For Sale (Outright Purchase / Installment)</option>
                                        <option value="rent" <?php selected( $val_listing_type, 'rent' ); ?>>For Rent (Tenancy)</option>
                                        <option value="shortlet" <?php selected( $val_listing_type, 'shortlet' ); ?>>Short Let (Serviced Daily/Monthly)</option>
                                        <option value="commercial" <?php selected( $val_listing_type, 'commercial' ); ?>>Commercial (Office / Retail / Warehouse)</option>
                                        <option value="land" <?php selected( $val_listing_type, 'land' ); ?>>Bare Land / Plot Development</option>
                                        <option value="lease" <?php selected( $val_listing_type, 'lease' ); ?>>Commercial Lease</option>
                                    </select>
                                </div>

                                <div class="ofp-form-group">
                                    <label>Property Architecture Type <span style="color:#ef4444;">*</span></label>
                                    <select name="property_type" id="ofp_property_type_sel" class="ofp-input-select">
                                        <option value="apartment" <?php selected( $val_property_type, 'apartment' ); ?>>Apartment / Flat</option>
                                        <option value="duplex" <?php selected( $val_property_type, 'duplex' ); ?>>Detached / Semi-Detached Duplex</option>
                                        <option value="terrace" <?php selected( $val_property_type, 'terrace' ); ?>>Terraced House / Townhouse</option>
                                        <option value="penthouse" <?php selected( $val_property_type, 'penthouse' ); ?>>Penthouse Suite</option>
                                        <option value="mansion" <?php selected( $val_property_type, 'mansion' ); ?>>Luxury Villa / Mansion</option>
                                        <option value="bungalow" <?php selected( $val_property_type, 'bungalow' ); ?>>Bungalow</option>
                                        <option value="office" <?php selected( $val_property_type, 'office' ); ?>>Commercial Office Space</option>
                                        <option value="shop" <?php selected( $val_property_type, 'shop' ); ?>>Retail Shop / Mall Space</option>
                                        <option value="warehouse" <?php selected( $val_property_type, 'warehouse' ); ?>>Industrial Warehouse</option>
                                        <option value="land" <?php selected( $val_property_type, 'land' ); ?>>Bare Land / Plot</option>
                                    </select>
                                </div>
                            </div>

                            <div class="ofp-form-group">
                                <label>WordPress Property Type Taxonomy</label>
                                <select name="property_type_term" class="ofp-input-select">
                                    <option value="">-- Match / Sync with Taxonomy Term --</option>
                                    <?php foreach ( $tax_types as $tt ) : ?>
                                        <option value="<?php echo esc_attr( $tt->name ); ?>" <?php selected( $sel_type_term, $tt->name ); ?>>
                                            <?php echo esc_html( $tt->name ); ?> (<?php echo esc_html( $tt->count ); ?> listings)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- ── 2. Pricing, Commercial Terms & Title Deed ─────────── -->
                        <div class="ofp-sec-card">
                            <div class="ofp-sec-header">
                                <div class="ofp-sec-icon">💰</div>
                                <div>
                                    <h4 class="ofp-sec-title">2. Valuation, Billing Terms &amp; Title Verification</h4>
                                    <p class="ofp-sec-subtitle">Financial valuation, Nigerian Naira verbalizer, rent package, and official registered title deed.</p>
                                </div>
                            </div>

                            <div class="ofp-form-grid-2">
                                <div class="ofp-form-group">
                                    <label>Asking Price (₦ NGN) <span style="color:#ef4444;">*</span></label>
                                    <div style="position:relative;">
                                        <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-weight:700; color:var(--ofp-text-muted);">₦</span>
                                        <input type="number" step="0.01" name="price" id="ofp_price_input" class="ofp-input-text" required
                                               value="<?php echo esc_attr( $val_price ); ?>" placeholder="0.00" style="padding-left:30px; font-weight:700;">
                                    </div>
                                    <div id="ofp_price_verbalizer" class="ofp-verbalizer"></div>
                                </div>

                                <div class="ofp-form-group">
                                    <label>Price Billing Period</label>
                                    <select name="price_period" id="ofp_price_period_sel" class="ofp-input-select">
                                        <option value="sales" <?php selected( $val_price_period, 'sales' ); ?>>Sales (One-off Total Amount)</option>
                                        <option value="year" <?php selected( $val_price_period, 'year' ); ?>>Per Year (Rent)</option>
                                        <option value="2years" <?php selected( $val_price_period, '2years' ); ?>>Per 2 Years (Rent)</option>
                                        <option value="month" <?php selected( $val_price_period, 'month' ); ?>>Per Month (Shortlet / Rent)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="ofp-form-grid-2" style="margin-top:10px;">
                                <div class="ofp-form-group" id="ofp_total_package_box">
                                    <label>Total Move-in Package (₦) <span style="color:var(--ofp-text-muted); font-size:11px;">(Rent / Lease Only)</span></label>
                                    <div style="position:relative;">
                                        <span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-weight:700; color:var(--ofp-text-muted);">₦</span>
                                        <input type="number" step="0.01" name="total_package" class="ofp-input-text"
                                               value="<?php echo esc_attr( $val_total_package ); ?>" placeholder="e.g. Rent + Legal + Agency + Caution" style="padding-left:30px;">
                                    </div>
                                    <span style="font-size:11.5px; color:var(--ofp-text-muted); margin-top:4px;">In Nigeria, rent is displayed alongside the total move-in package including legal, agency, and caution deposits.</span>
                                </div>

                                <div class="ofp-form-group">
                                    <label>Official Registered Title Document</label>
                                    <select name="title_document" class="ofp-input-select">
                                        <option value="">-- Select Registered Title Deed --</option>
                                        <?php
                                        $title_deeds = [
                                            'C of O'             => 'Certificate of Occupancy (C of O)',
                                            "Governor's Consent" => "Governor's Consent",
                                            'Gazette'            => 'Government Gazette',
                                            'Deed of Assignment' => 'Deed of Assignment',
                                            'R of O'             => 'Right of Occupancy (R of O)',
                                            'Excision'           => 'Government Excision',
                                            'Court Judgment'     => 'Court Judgment / Ruling',
                                            'Other'              => 'Other Registered Deed',
                                        ];
                                        foreach ( $title_deeds as $td_val => $td_label ) {
                                            echo '<option value="' . esc_attr( $td_val ) . '" ' . selected( $val_title_document, $td_val, false ) . '>' . esc_html( $td_label ) . '</option>';
                                        }
                                        ?>
                                    </select>
                                    <span style="font-size:11.5px; color:var(--ofp-text-muted); margin-top:4px;">Verifiable title deeds give prospective buyers and legal teams immediate certainty.</span>
                                </div>
                            </div>

                            <div style="margin-top:12px; padding:12px 16px; background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.2); border-radius:8px;">
                                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:600; color:var(--ofp-text-main);">
                                    <input type="checkbox" name="allow_installment" value="1" <?php checked( $val_allow_installment, '1' ); ?>>
                                    <span>Allow Installment &amp; Mortgage Financing Plan</span>
                                </label>
                                <span style="font-size:12px; color:var(--ofp-text-muted); margin-left:26px; display:block; margin-top:2px;">
                                    When checked, prospective buyers will see the interactive Installment &amp; Mortgage Estimator on the property page.
                                </span>
                            </div>
                        </div>

                        <!-- ── 3. Physical Location & Neighborhood ────────────────── -->
                        <div class="ofp-sec-card">
                            <div class="ofp-sec-header">
                                <div class="ofp-sec-icon">📍</div>
                                <div>
                                    <h4 class="ofp-sec-title">3. Location &amp; Geographic Registry</h4>
                                    <p class="ofp-sec-subtitle">Physical neighborhood address, Nigerian prime location presets, and taxonomy linking.</p>
                                </div>
                            </div>

                            <div class="ofp-form-group">
                                <label>Physical Address &amp; Neighborhood <span style="color:#ef4444;">*</span></label>
                                <input type="text" name="location_text" id="ofp_location_input" class="ofp-input-text" required
                                       value="<?php echo esc_attr( $val_location_text ); ?>"
                                       placeholder="e.g. Admiralty Way, Lekki Phase 1, Lagos">

                                <div style="margin-top:8px;">
                                    <span style="font-size:11.5px; color:var(--ofp-text-muted); font-weight:600;">Click preset prime location to append:</span>
                                    <div class="ofp-chips-row">
                                        <?php
                                        $preset_locs = [
                                            'Lekki Phase 1, Lagos', 'Victoria Island, Lagos', 'Ikoyi, Lagos', 'Ikeja GRA, Lagos',
                                            'Banana Island, Lagos', 'Chevy View, Lekki', 'Ajah, Lagos', 'Maitama, Abuja',
                                            'Guzape, Abuja', 'Wuse 2, Abuja'
                                        ];
                                        foreach ( $preset_locs as $ploc ) :
                                        ?>
                                            <button type="button" class="ofp-chip-btn ofp-preset-loc" data-loc="<?php echo esc_attr( $ploc ); ?>">
                                                + <?php echo esc_html( $ploc ); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="ofp-form-group" style="margin-top:14px;">
                                <label>WordPress Locations Taxonomy</label>
                                <select name="location_term" class="ofp-input-select">
                                    <option value="">-- Match / Sync with Taxonomy Term --</option>
                                    <?php foreach ( $tax_locations as $tloc ) : ?>
                                        <option value="<?php echo esc_attr( $tloc->name ); ?>" <?php selected( $sel_loc_term, $tloc->name ); ?>>
                                            <?php echo esc_html( $tloc->name ); ?> (<?php echo esc_html( $tloc->count ); ?> listings)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- ── 4. Dimensions, Spaces & Land Sizing ─────────────────── -->
                        <div class="ofp-sec-card">
                            <div class="ofp-sec-header">
                                <div class="ofp-sec-icon">📐</div>
                                <div>
                                    <h4 class="ofp-sec-title">4. Dimensions, Spaces &amp; Finish Specifications</h4>
                                    <p class="ofp-sec-subtitle">Capacities, finish condition, Nigerian land sizing, and plot benchmarks.</p>
                                </div>
                            </div>

                            <!-- Dedicated Land Specifics (Toggled dynamically or if listing is Land) -->
                            <div id="ofp_land_box" style="<?php echo ( $val_listing_type === 'land' || $val_property_type === 'land' ) ? 'display:block;' : 'display:none;'; ?> margin-bottom:20px; padding:18px; background:rgba(16,185,129,0.04); border:1px solid rgba(16,185,129,0.2); border-radius:10px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                                    <strong style="color:#10b981; font-size:13.5px; display:flex; align-items:center; gap:6px;">
                                        🌱 Nigerian Land Sizing &amp; Plot Breakdown
                                    </strong>
                                    <span class="ofp-kpi-pill positive" style="font-size:11px;">Standard Lagos Plot = 600 SQM</span>
                                </div>

                                <div class="ofp-form-grid-2">
                                    <div class="ofp-form-group">
                                        <label>Number of Plots (e.g. 1, 0.5, 2, 6)</label>
                                        <input type="number" step="0.1" min="0" name="plots_count" id="ofp_plots_input" class="ofp-input-text"
                                               value="<?php echo esc_attr( $val_plots_count ); ?>" placeholder="e.g. 1">
                                        <span style="font-size:11.5px; color:var(--ofp-text-muted); margin-top:4px;">1 Plot ≈ 600 SQM (~60ft × 120ft). Auto-syncs with Area SQM.</span>
                                    </div>

                                    <div class="ofp-form-group">
                                        <label>Topography</label>
                                        <select name="land_topography" class="ofp-input-select">
                                            <option value="">-- Select Topography --</option>
                                            <?php
                                            $topos = [ '100% Dry Land', 'Dry Table Land', 'Mixed / Firm Land', 'Sandfilling Required', 'Waterfront Land' ];
                                            foreach ( $topos as $top ) {
                                                echo '<option value="' . esc_attr( $top ) . '" ' . selected( $val_land_topography, $top, false ) . '>' . esc_html( $top ) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="ofp-form-grid-3" style="display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-top:12px;">
                                    <div class="ofp-form-group">
                                        <label>Zoning / Land Use</label>
                                        <select name="land_zoning" class="ofp-input-select">
                                            <option value="">-- Select Zoning --</option>
                                            <?php
                                            $zonings = [ 'Residential', 'Commercial', 'Mixed-Use', 'Industrial', 'Agricultural' ];
                                            foreach ( $zonings as $zn ) {
                                                echo '<option value="' . esc_attr( $zn ) . '" ' . selected( $val_land_zoning, $zn, false ) . '>' . esc_html( $zn ) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>

                                    <div class="ofp-form-group">
                                        <label>Land Dimensions</label>
                                        <input type="text" name="land_dimensions" class="ofp-input-text"
                                               value="<?php echo esc_attr( $val_land_dimensions ); ?>" placeholder="e.g. 60ft × 120ft">
                                    </div>

                                    <div class="ofp-form-group">
                                        <label>Survey &amp; Boundaries</label>
                                        <select name="survey_status" class="ofp-input-select">
                                            <option value="">-- Select Survey Status --</option>
                                            <?php
                                            $surveys = [ 'Registered Survey with Coordinates', 'Provisional Survey / Layout', 'Perimeter Fenced & Gated', 'Beaconed / Pegged', 'Survey in Progress' ];
                                            foreach ( $surveys as $sv ) {
                                                echo '<option value="' . esc_attr( $sv ) . '" ' . selected( $val_survey_status, $sv, false ) . '>' . esc_html( $sv ) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Building Spaces (Hidden when land) -->
                            <div id="ofp_building_box" style="<?php echo ( $val_listing_type === 'land' || $val_property_type === 'land' ) ? 'display:none;' : 'display:block;'; ?>">
                                <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:16px;">
                                    <div class="ofp-form-group">
                                        <label>Bedrooms</label>
                                        <div class="ofp-counter-pill">
                                            <button type="button" class="ofp-counter-btn" onclick="stepCount('ofp_beds_input', -1);">&minus;</button>
                                            <input type="number" min="0" max="30" name="bedrooms" id="ofp_beds_input" class="ofp-counter-input" value="<?php echo esc_attr( $val_bedrooms ); ?>">
                                            <button type="button" class="ofp-counter-btn" onclick="stepCount('ofp_beds_input', 1);">&plus;</button>
                                        </div>
                                        <div class="ofp-chips-row">
                                            <?php foreach ( [ 1, 2, 3, 4, 5, 6 ] as $num ) : ?>
                                                <button type="button" class="ofp-chip-btn" onclick="document.getElementById('ofp_beds_input').value=<?php echo $num; ?>;"><?php echo $num; ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="ofp-form-group">
                                        <label>Bathrooms</label>
                                        <div class="ofp-counter-pill">
                                            <button type="button" class="ofp-counter-btn" onclick="stepCount('ofp_baths_input', -1);">&minus;</button>
                                            <input type="number" min="0" max="30" name="bathrooms" id="ofp_baths_input" class="ofp-counter-input" value="<?php echo esc_attr( $val_bathrooms ); ?>">
                                            <button type="button" class="ofp-counter-btn" onclick="stepCount('ofp_baths_input', 1);">&plus;</button>
                                        </div>
                                        <div class="ofp-chips-row">
                                            <?php foreach ( [ 1, 2, 3, 4, 5, 6 ] as $num ) : ?>
                                                <button type="button" class="ofp-chip-btn" onclick="document.getElementById('ofp_baths_input').value=<?php echo $num; ?>;"><?php echo $num; ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="ofp-form-group">
                                        <label>Parking Spaces</label>
                                        <div class="ofp-counter-pill">
                                            <button type="button" class="ofp-counter-btn" onclick="stepCount('ofp_parking_input', -1);">&minus;</button>
                                            <input type="number" min="0" max="50" name="parking" id="ofp_parking_input" class="ofp-counter-input" value="<?php echo esc_attr( $val_parking ); ?>">
                                            <button type="button" class="ofp-counter-btn" onclick="stepCount('ofp_parking_input', 1);">&plus;</button>
                                        </div>
                                        <div class="ofp-chips-row">
                                            <?php foreach ( [ 1, 2, 3, 4, 6 ] as $num ) : ?>
                                                <button type="button" class="ofp-chip-btn" onclick="document.getElementById('ofp_parking_input').value=<?php echo $num; ?>;"><?php echo $num; ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="ofp-form-grid-2" style="margin-bottom:16px;">
                                    <div class="ofp-form-group">
                                        <label>Construction Condition</label>
                                        <select name="condition" class="ofp-input-select">
                                            <option value="">-- Select Condition --</option>
                                            <?php
                                            $conditions = [ 'Newly Built', 'Fairly Used', 'Renovation Needed', 'Under Construction', 'Off-Plan' ];
                                            foreach ( $conditions as $cond ) {
                                                echo '<option value="' . esc_attr( $cond ) . '" ' . selected( $val_condition, $cond, false ) . '>' . esc_html( $cond ) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>

                                    <div class="ofp-form-group">
                                        <label>Furnishing Grade</label>
                                        <select name="furnishing" class="ofp-input-select">
                                            <option value="">-- Select Furnishing --</option>
                                            <?php
                                            $furnishings = [ 'Furnished', 'Semi-Furnished', 'Unfurnished' ];
                                            foreach ( $furnishings as $furn ) {
                                                echo '<option value="' . esc_attr( $furn ) . '" ' . selected( $val_furnishing, $furn, false ) . '>' . esc_html( $furn ) . '</option>';
                                            }
                                            ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Total Area SQM & Quick Presets (Available for both Building & Land) -->
                            <div class="ofp-form-group">
                                <label>Total Built Area / Land Footprint (SQM - m²)</label>
                                <input type="number" min="0" name="area_sqm" id="ofp_area_sqm_input" class="ofp-input-text"
                                       value="<?php echo esc_attr( $val_area_sqm ); ?>" placeholder="e.g. 600">

                                <div style="margin-top:8px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                                    <div class="ofp-chips-row" style="margin-top:0;">
                                        <span style="font-size:11.5px; color:var(--ofp-text-muted); font-weight:600; display:flex; align-items:center;">Plot Presets:</span>
                                        <button type="button" class="ofp-chip-btn ofp-sqm-preset" data-sqm="300" data-plots="0.5">Half Plot (300m²)</button>
                                        <button type="button" class="ofp-chip-btn ofp-sqm-preset" data-sqm="600" data-plots="1">1 Plot (600m²)</button>
                                        <button type="button" class="ofp-chip-btn ofp-sqm-preset" data-sqm="1200" data-plots="2">2 Plots (1,200m²)</button>
                                        <button type="button" class="ofp-chip-btn ofp-sqm-preset" data-sqm="1800" data-plots="3">3 Plots (1,800m²)</button>
                                        <button type="button" class="ofp-chip-btn ofp-sqm-preset" data-sqm="4047" data-plots="6.7">1 Acre (4,047m²)</button>
                                        <button type="button" class="ofp-chip-btn ofp-sqm-preset" data-sqm="10000" data-plots="16.7">1 Hectare (10,000m²)</button>
                                    </div>
                                    <span id="ofp_sqm_live_badge" style="font-size:11.5px; font-weight:700; color:#10b981; background:rgba(16,185,129,0.08); padding:3px 8px; border-radius:5px;">
                                        Standard Lagos Plot = 600 SQM
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- ── 5. Detailed Walkthrough Description & Story ────────── -->
                        <div class="ofp-sec-card">
                            <div class="ofp-sec-header">
                                <div class="ofp-sec-icon">📝</div>
                                <div>
                                    <h4 class="ofp-sec-title">5. Walkthrough Description &amp; Marketing Story</h4>
                                    <p class="ofp-sec-subtitle">Detailed architectural write-up, room distribution, kitchen layout, and estate features.</p>
                                </div>
                            </div>

                            <div class="ofp-form-group">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <div style="display:flex; gap:8px;">
                                        <button type="button" class="ofp-chip-btn" id="ofp_btn_add_bullet">• Add Bullet</button>
                                        <button type="button" class="ofp-chip-btn" id="ofp_btn_add_highlights">✨ Insert Key Highlights Template</button>
                                    </div>
                                    <span id="ofp_desc_words" style="font-size:11.5px; color:var(--ofp-text-muted);">0 words</span>
                                </div>
                                <textarea name="content" id="ofp_content_input" class="ofp-textarea" rows="8"
                                          placeholder="Provide an evocative architectural walkthrough highlighting master suite, kitchen finishes, security access, and nearby landmarks..."><?php echo esc_textarea( $val_content ); ?></textarea>
                            </div>
                        </div>

                        <!-- ── 6. Media Studio, Gallery & Virtual Tour ────────────── -->
                        <div class="ofp-sec-card">
                            <div class="ofp-sec-header">
                                <div class="ofp-sec-icon">🖼️</div>
                                <div>
                                    <h4 class="ofp-sec-title">6. Media Studio, Gallery &amp; Virtual Walkthrough</h4>
                                    <p class="ofp-sec-subtitle">High-resolution property photos, primary cover image, and YouTube/Vimeo walkthrough links.</p>
                                </div>
                            </div>

                            <!-- Cover Image Row -->
                            <div class="ofp-form-grid-2" style="align-items:start;">
                                <div class="ofp-form-group">
                                    <label>Cover Photo</label>
                                    <div style="display:flex; gap:10px; align-items:center;">
                                        <button type="button" class="ofp-btn ofp-btn-outline" id="ofp_btn_pick_cover" style="font-size:12.5px; padding:7px 14px;">
                                            Choose from Media Library
                                        </button>
                                        <span style="font-size:12px; color:var(--ofp-text-muted);">or paste direct URL below:</span>
                                    </div>
                                    <input type="text" name="featured_image" id="ofp_feat_image_url" class="ofp-input-text" style="margin-top:8px;"
                                           value="<?php echo esc_attr( $post_thumb_url ); ?>"
                                           placeholder="https://example.com/property-cover.jpg">
                                </div>

                                <div>
                                    <label style="font-size:12px; font-weight:600; color:var(--ofp-text-main); display:block; margin-bottom:6px;">Cover Photo Preview</label>
                                    <div id="ofp_cover_preview_box" style="width:100%; max-width:240px; height:140px; border-radius:8px; overflow:hidden; border:1px solid var(--ofp-input-border, #e2e8f0); background:#f8fafc; display:flex; align-items:center; justify-content:center;">
                                        <?php if ( $post_thumb_url ) : ?>
                                            <img src="<?php echo esc_url( $post_thumb_url ); ?>" id="ofp_cover_preview_img" alt="Cover" style="width:100%; height:100%; object-fit:cover;">
                                        <?php else : ?>
                                            <span style="font-size:12px; color:var(--ofp-text-muted);">No cover photo chosen</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Gallery Section -->
                            <div style="margin-top:20px; padding-top:16px; border-top:1px solid rgba(0,0,0,0.06);">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                    <div>
                                        <strong style="font-size:13px; color:var(--ofp-text-main);">Property Photo Gallery</strong>
                                        <span style="font-size:12px; color:var(--ofp-text-muted); display:block;">Select multiple photos. Click "Set Main" to choose the cover image.</span>
                                    </div>
                                    <button type="button" class="ofp-btn ofp-btn-outline" id="ofp_btn_add_gallery" style="font-size:12px; padding:6px 14px; border-color:#10b981; color:#10b981;">
                                        + Add Photos from Media Library
                                    </button>
                                </div>

                                <div class="ofp-gallery-cards" id="ofp_gallery_cards_grid">
                                    <?php if ( empty( $display_gallery ) ) : ?>
                                        <div class="ofp-media-empty" id="ofp_gallery_empty" style="grid-column: 1 / -1;">
                                            No gallery photos added yet. Click <strong>+ Add Photos from Media Library</strong> above to pick property pictures.
                                        </div>
                                    <?php else :
                                        foreach ( $display_gallery as $gid ) :
                                            $gid = (int) $gid;
                                            $thumb_url = wp_get_attachment_image_url( $gid, 'medium' ) ?: wp_get_attachment_image_url( $gid, 'full' );
                                            if ( ! $thumb_url ) continue;
                                            $is_cover = ( $gid === $post_thumb_id );
                                        ?>
                                            <div class="ofp-gallery-card <?php echo $is_cover ? 'is-cover' : ''; ?>" data-id="<?php echo esc_attr( $gid ); ?>">
                                                <img src="<?php echo esc_url( $thumb_url ); ?>" alt="Gallery image">
                                                <?php if ( $is_cover ) : ?>
                                                    <span class="ofp-gallery-badge-cover">★ Cover</span>
                                                <?php endif; ?>
                                                <div class="ofp-gallery-actions">
                                                    <button type="button" class="ofp-gallery-btn ofp-btn-set-cover" data-id="<?php echo esc_attr( $gid ); ?>" data-url="<?php echo esc_url( $thumb_url ); ?>">
                                                        <?php echo $is_cover ? 'Main' : 'Set Main'; ?>
                                                    </button>
                                                    <button type="button" class="ofp-gallery-btn ofp-gallery-btn-del" data-id="<?php echo esc_attr( $gid ); ?>">
                                                        &times;
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Video Walkthrough Tour -->
                            <div style="margin-top:20px; padding-top:16px; border-top:1px solid rgba(0,0,0,0.06);">
                                <label style="font-size:12.5px; font-weight:600; color:var(--ofp-text-main); display:block; margin-bottom:6px;">Virtual Video Walkthrough URL (YouTube, Vimeo, or MP4)</label>
                                <input type="url" name="video_url" id="ofp_video_url_input" class="ofp-input-text"
                                       value="<?php echo esc_attr( $val_video_url ); ?>"
                                       placeholder="https://www.youtube.com/watch?v=... or Vimeo walkthrough link">
                                <div id="ofp_video_embed_preview" style="margin-top:10px; display:none;"></div>
                            </div>
                        </div>

                        <!-- ── 7. Amenities & Facility Provisions ─────────────────── -->
                        <div class="ofp-sec-card">
                            <div class="ofp-sec-header">
                                <div class="ofp-sec-icon">✨</div>
                                <div style="flex:1;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <h4 class="ofp-sec-title">7. Amenities &amp; Facility Provisions</h4>
                                        <div style="display:flex; gap:8px;">
                                            <button type="button" class="ofp-chip-btn" id="ofp_amenities_all">+ Select All</button>
                                            <button type="button" class="ofp-chip-btn" id="ofp_amenities_clear">&times; Clear All</button>
                                        </div>
                                    </div>
                                    <p class="ofp-sec-subtitle">Select on-site residential security, backup utilities, smart automation, and leisure provisions.</p>
                                </div>
                            </div>

                            <div class="ofp-amenities-tiles">
                                <?php foreach ( $amenity_items as $akey => $adata ) :
                                    $is_checked = in_array( $akey, (array) $val_amenities, true );
                                ?>
                                    <label class="ofp-amenity-tile <?php echo $is_checked ? 'is-checked' : ''; ?>">
                                        <input type="checkbox" name="amenities[]" value="<?php echo esc_attr( $akey ); ?>" <?php checked( $is_checked ); ?>>
                                        <span style="font-size:16px;"><?php echo esc_html( $adata['icon'] ); ?></span>
                                        <span><?php echo esc_html( $adata['label'] ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- ── 8. Inspection & Viewing Schedule ───────────────────── -->
                        <div class="ofp-sec-card">
                            <div class="ofp-sec-header">
                                <div class="ofp-sec-icon">📅</div>
                                <div>
                                    <h4 class="ofp-sec-title">8. Inspection &amp; Viewing Schedule</h4>
                                    <p class="ofp-sec-subtitle">Showing availability windows and estate security gate protocol.</p>
                                </div>
                            </div>

                            <div class="ofp-form-grid-2">
                                <div class="ofp-form-group">
                                    <label>Available Inspection Days</label>
                                    <input type="text" name="inspection_days" class="ofp-input-text"
                                           value="<?php echo esc_attr( $val_inspection_days ); ?>"
                                           placeholder="e.g. Mondays - Saturdays, or Weekends Only">
                                </div>

                                <div class="ofp-form-group">
                                    <label>Available Inspection Hours</label>
                                    <input type="text" name="inspection_hours" class="ofp-input-text"
                                           value="<?php echo esc_attr( $val_inspection_hours ); ?>"
                                           placeholder="e.g. 10:00 AM - 5:00 PM">
                                </div>
                            </div>

                            <div class="ofp-form-group" style="margin-top:10px;">
                                <label>Estate Gate Access Protocol &amp; Advance Notice</label>
                                <input type="text" name="inspection_notice" class="ofp-input-text"
                                       value="<?php echo esc_attr( $val_inspection_notice ); ?>"
                                       placeholder="e.g. 24 hours advance notice required for estate security gate clearance code.">
                                <span style="font-size:11.5px; color:var(--ofp-text-muted); margin-top:4px;">Displayed on the public booking card so prospects know estate entry requirements in advance.</span>
                            </div>
                        </div>

                        <!-- ── 9. Ownership, Bank Routing & Status ────────────────── -->
                        <div class="ofp-sec-card">
                            <div class="ofp-sec-header">
                                <div class="ofp-sec-icon">👤</div>
                                <div>
                                    <h4 class="ofp-sec-title">9. Ownership, Bank Routing &amp; Publishing Controls</h4>
                                    <p class="ofp-sec-subtitle">Assign agency ownership, configure direct bank account for transfers, and manage visibility.</p>
                                </div>
                            </div>

                            <div class="ofp-form-grid-2">
                                <div class="ofp-form-group">
                                    <label>Assigned Client / Agency Owner</label>
                                    <select name="client_id" id="ofp_client_owner_sel" class="ofp-input-select">
                                        <option value="0">Admin Direct Listing (Platform Core Asset)</option>
                                        <?php foreach ( $clients_list as $cl ) : ?>
                                            <option value="<?php echo esc_attr( $cl->id ); ?>" <?php selected( $val_client_id, (int) $cl->id ); ?>>
                                                <?php echo esc_html( $cl->business_name ?: $cl->owner_name ); ?> (#<?php echo esc_html( $cl->id ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="ofp-form-group">
                                    <label>Receiving Bank Account for Transfers</label>
                                    <select name="bank_account_id" id="ofp_bank_account_sel" class="ofp-input-select">
                                        <option value="0">Default Primary Account</option>
                                        <?php foreach ( $bank_accounts as $ba ) : ?>
                                            <option value="<?php echo esc_attr( $ba->id ); ?>" data-client="<?php echo esc_attr( $ba->client_id ); ?>" <?php selected( $val_bank_id, (int) $ba->id ); ?>>
                                                <?php echo esc_html( $ba->bank_name . ' — ' . $ba->account_number . ' (' . $ba->account_name . ')' ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span style="font-size:11.5px; color:var(--ofp-text-muted); margin-top:4px;">Direct transfers from buyers or tenants will be instructed to route into this account.</span>
                                </div>
                            </div>

                            <div class="ofp-form-grid-2" style="margin-top:14px; align-items:center;">
                                <div class="ofp-form-group">
                                    <label>Listing Status</label>
                                    <select name="status" class="ofp-input-select">
                                        <option value="live" <?php selected( $val_status, 'live' ); ?>>● Live &amp; Published (Active Marketplace)</option>
                                        <option value="pending_upload" <?php selected( $val_status, 'pending_upload' ); ?>>● Pending Upload / Draft</option>
                                        <option value="taken" <?php selected( $val_status, 'taken' ); ?>>● Taken (Sold / Rented)</option>
                                        <option value="expired" <?php selected( $val_status, 'expired' ); ?>>● Expired</option>
                                    </select>
                                </div>

                                <div class="ofp-form-group" style="padding:10px 14px; background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.2); border-radius:8px; margin-top:18px;">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; color:var(--ofp-text-main); margin:0;">
                                        <input type="checkbox" name="is_featured" value="1" <?php checked( $val_is_featured, '1' ); ?>>
                                        <span>Featured Property (Highlight at the top of marketplace results)</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- ── 10. Sticky Bottom Actions Bar ─────────────────────── -->
                        <div class="ofp-studio-sticky-bar">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <a href="<?php echo esc_url( home_url( '/admin-portal?tab=properties' ) ); ?>" class="ofp-btn ofp-btn-outline">
                                    Cancel
                                </a>
                                <?php if ( $post_id ) : ?>
                                    <a href="<?php echo esc_url( wp_nonce_url( home_url( '/admin-portal?tab=properties&action=delete_property&id=' . $post_id ), 'ofp_delete_prop_' . $post_id ) ); ?>"
                                       onclick="return confirm('Move this property to trash? This will deactivate public listing pages.');"
                                       class="ofp-btn ofp-btn-outline" style="color:#ef4444; border-color:rgba(239,68,68,0.3);">
                                        Delete Property
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div style="display:flex; align-items:center; gap:12px;">
                                <span style="font-size:12px; color:var(--ofp-text-muted);">Ready to commit changes?</span>
                                <button type="submit" class="ofp-btn ofp-btn-primary" style="padding:10px 28px; font-weight:700;">
                                    Save Property Listing
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                

            <?php elseif ( $tab === 'properties' ) : ?>
                <!-- ─────────────────────────────────────────────────────────────
                     ALL PROPERTIES LISTINGS TABLE (In-Portal Horizontal Scroll)
                     ───────────────────────────────────────────────────────────── -->
                <?php
                $all_props = $wpdb->get_results( "
                    SELECT pr.*, c.business_name, c.is_white_label
                    FROM {$p}ofp_properties pr
                    LEFT JOIN {$p}ofp_clients c ON c.id = pr.client_id
                    ORDER BY pr.id DESC
                " );
                ?>
                <div class="ofp-card">
                    <div class="ofp-card-header">
                        <div>
                            <h3 class="ofp-card-title">All Property Listings</h3>
                            <span class="ofp-page-subtitle"><?php echo count( $all_props ); ?> properties across all client networks and system channels.</span>
                        </div>

                        <div class="ofp-table-actions">
                            <a href="<?php echo esc_url( home_url( '/admin-portal?tab=property-add' ) ); ?>" class="ofp-btn ofp-btn-primary">
                                + Add New Property
                            </a>
                        </div>
                    </div>

                    <div class="ofp-table-toolbar">
                        <div class="ofp-table-search">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                            <input type="text" id="ofp-table-search-input" placeholder="Search property title, client, location...">
                        </div>
                    </div>

                    <!-- Table with strict horizontal scroll & anti-shrink -->
                    <div class="ofp-table-wrap">
                        <table class="ofp-data-table">
                            <thead>
                                <tr>
                                    <th>Property Title / ID</th>
                                    <th>Client Owner</th>
                                    <th>Architecture</th>
                                    <th>Listing Type</th>
                                    <th>Price</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( empty( $all_props ) ) : ?>
                                    <tr><td colspan="8" style="text-align:center; padding:28px; color:var(--ofp-text-muted);">No property listings found. Click "+ Add Property" to create one.</td></tr>
                                <?php else : ?>
                                    <?php foreach ( $all_props as $prop ) : ?>
                                        <tr>
                                            <td>
                                                <div class="ofp-user-cell">
                                                    <img src="<?php echo esc_url( $prop->featured_image ?: OFP_URL . 'assets/images/dashboard-hero-banner.webp' ); ?>" alt="" class="ofp-user-thumb">
                                                    <div class="ofp-user-meta">
                                                        <span class="ofp-user-title"><?php echo esc_html( $prop->title ); ?></span>
                                                        <span class="ofp-user-sub">#<?php echo esc_html( $prop->id ); ?> • Added <?php echo esc_html( date( 'M j, Y', strtotime( $prop->created_at ) ) ); ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?php echo esc_html( $prop->business_name ?: 'System Direct' ); ?></strong>
                                                <?php if ( ! empty( $prop->is_white_label ) ) : ?>
                                                    <div style="font-size:11px; color:#8b5cf6;">(White Label)</div>
                                                <?php endif; ?>
                                            </td>
                                            <td><span style="text-transform:capitalize;"><?php echo esc_html( $prop->property_type ); ?></span></td>
                                            <td><span style="font-weight:600; text-transform:uppercase; font-size:11px;"><?php echo esc_html( $prop->listing_type ); ?></span></td>
                                            <td style="font-weight:700;">₦<?php echo number_format( $prop->price ); ?></td>
                                            <td><?php echo esc_html( $prop->location_text ?: '—' ); ?></td>
                                            <td>
                                                <span class="ofp-status-pill <?php echo esc_attr( $prop->status ); ?>">
                                                    <?php echo esc_html( ucfirst( $prop->status ) ); ?>
                                                </span>
                                            </td>
                                            <td style="text-align:right;">
                                                <a href="<?php echo esc_url( home_url( '/admin-portal?tab=properties&action=edit&id=' . $prop->id ) ); ?>" class="ofp-btn ofp-btn-outline" style="font-size:11px; padding:3px 7px;">
                                                    Edit
                                                </a>
                                                <?php if ( ! empty( $prop->wp_post_id ) ) : ?>
                                                    <a href="<?php echo esc_url( get_permalink( $prop->wp_post_id ) ); ?>" target="_blank" class="ofp-btn ofp-btn-outline" style="font-size:11px; padding:3px 7px;">
                                                        View
                                                    </a>
                                                    <a href="<?php echo esc_url( wp_nonce_url( home_url( '/admin-portal?tab=properties&action=delete_property&id=' . $prop->wp_post_id ), 'ofp_delete_prop_' . $prop->wp_post_id ) ); ?>" 
                                                       onclick="return confirm('Move this property to trash?');"
                                                       class="ofp-btn ofp-btn-outline" style="font-size:11px; padding:3px 7px; color:#ef4444; border-color:rgba(239,68,68,0.3);">
                                                        Trash
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php else : ?>
                <!-- Fallback: Module Renderer -->
                <div class="ofp-card">
                    <div class="ofp-card-header">
                        <h3 class="ofp-card-title"><?php echo esc_html( $titles[ $tab ] ?? ucfirst( $tab ) ); ?></h3>
                    </div>
                    <div style="padding: 24px; text-align: center; color: var(--ofp-text-muted);">
                        <p style="font-size: 15px;">Module active and loaded in OFast Pipeline engine.</p>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </main>

</div>

<?php wp_footer(); ?>
</body>
</html>
