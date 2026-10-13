<?php
/**
 * Shared admin header partial.
 * Included at the top of every OFP admin view.
 * @var array|false $message  ['text' => string, 'type' => 'success|error']
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap ofp-wrap">
    <div class="ofp-admin-header">
        <h1 class="ofp-admin-title">
            <span class="ofp-logo">⚡</span> <?php echo esc_html( OFP_Brand::name() ); ?>
        </h1>
        <span class="ofp-version">v<?php echo esc_html( OFP_VERSION ); ?></span>
    </div>

    <?php if ( ! empty( $message ) ) : ?>
        <div class="notice notice-<?php echo $message['type'] === 'success' ? 'success' : 'error'; ?> is-dismissible ofp-notice">
            <p><?php echo esc_html( $message['text'] ); ?></p>
        </div>
    <?php endif; ?>
