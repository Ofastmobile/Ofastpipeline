<?php
/**
 * uninstall.php
 *
 * WordPress runs this file only when the plugin is DELETED from the Plugins
 * page. It never runs on deactivation.
 *
 * By default NOTHING is removed, so an accidental delete does not destroy
 * client data. Data is removed only if a super admin ticked
 * Settings > System Hardening > "Delete ALL data when this plugin is deleted"
 * (option ofp_delete_data_on_uninstall = 1) BEFORE deleting the plugin.
 *
 * What the wipe removes: every table that starts with <prefix>ofp_ (this
 * includes the field agent tables), every option and transient that starts
 * with ofp_, and all ofp_property listings. It does NOT remove uploaded files
 * (receipts, imports, photos) in wp-content/uploads. Single site only.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( get_option( 'ofp_delete_data_on_uninstall' ) !== '1' ) {
    return; // Keep everything (default).
}

global $wpdb;

// 1. Property listings (custom post type) and their meta, done through WordPress.
$listing_ids = get_posts( [
    'post_type'      => 'ofp_property',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'no_found_rows'  => true,
] );
foreach ( $listing_ids as $listing_id ) {
    wp_delete_post( (int) $listing_id, true );
}

// 2. Every plugin table, found by name so none is forgotten.
$like   = $wpdb->esc_like( $wpdb->prefix . 'ofp_' ) . '%';
$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
foreach ( (array) $tables as $table ) {
    // The name came from the database itself; backticks are for safety.
    $wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $table ) . '`' );
}

// 3. Every plugin option and transient.
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like( 'ofp_' ) . '%',
    $wpdb->esc_like( '_transient_ofp_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_ofp_' ) . '%'
) );

wp_cache_flush();
