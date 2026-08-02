<?php
/**
 * uninstall.php
 *
 * WordPress calls this file automatically when the plugin is DELETED
 * (Plugins → Delete), never on deactivation.
 *
 * ⚠️  THIS PERMANENTLY DROPS ALL OFAST PIPELINE DATA. ⚠️
 * Only uncomment the DROP TABLE block when you're absolutely certain
 * you want to wipe everything (e.g. a fresh dev reset).
 * In production, keep the DROP block commented out so accidental deletion
 * does not destroy client data.
 */

// WordPress security check — must be called from WP's uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = [
    'ofp_admins',
    'ofp_clients',
    'ofp_leads',
    'ofp_trigger_queue',
    'ofp_communications_log',
    'ofp_ivr_responses',
    'ofp_credits',
    'ofp_credit_transactions',
    'ofp_subscriptions',
    'ofp_pipeline_configs',
    'ofp_rate_limits',
    'ofp_archives',
    'ofp_client_sessions',
    'ofp_properties',
    'ofp_property_inquiries',
];

// ── UNCOMMENT BELOW TO ENABLE TABLE DROPS ON DELETION ────────────────────────
// (leave commented in production)
/*
foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}

// Remove all plugin options.
$option_keys = [
    'ofp_db_version',
    'ofp_encryption_key',
    'ofp_encryption_iv',
    'ofp_at_username',
    'ofp_at_api_key',
    'ofp_at_sender_id',
    'ofp_at_phone_number',
    'ofp_bsmsn_api_key',
    'ofp_bsmsn_sender_id',
    'ofp_brevo_host',
    'ofp_brevo_port',
    'ofp_brevo_user',
    'ofp_brevo_pass',
    'ofp_brevo_from_email',
    'ofp_brevo_from_name',
    'ofp_monnify_api_key',
    'ofp_monnify_secret_key',
    'ofp_monnify_contract_code',
    'ofp_monnify_base_url',
    'ofp_turnstile_secret',
    'ofp_turnstile_site_key',
];
foreach ( $option_keys as $key ) {
    delete_option( $key );
}
*/
// ─────────────────────────────────────────────────────────────────────────────
