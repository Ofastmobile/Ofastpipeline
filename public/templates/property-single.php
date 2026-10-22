<?php
/**
 * Template: /property/{slug}/ — public single property detail page loader.
 *
 * @package OFast_Pipeline
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( file_exists( __DIR__ . '/property-single-detail.php' ) ) {
    include __DIR__ . '/property-single-detail.php';
    return;
}
