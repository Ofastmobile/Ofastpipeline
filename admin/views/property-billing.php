<?php
/**
 * Admin View: Property Listing Billing (Unified Forwarder)
 *
 * Deprecated as a separate view. Listing billing is now unified under
 * the main Billing & Subscriptions hub (admin/views/billing.php?type=listing).
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Forward directly into the unified billing view with listing type pre-selected
if ( ! isset( $_GET['type'] ) ) {
    $_GET['type'] = 'listing';
}

include OFP_PATH . 'admin/views/billing.php';
