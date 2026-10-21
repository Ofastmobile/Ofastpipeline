<?php
/**
 * Shared admin header partial.
 * Included at the top of every OFP admin view.
 * @var array|false $message  ['text' => string, 'type' => 'success|error']
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap ofp-wrap">

    <?php if ( ! empty( $message ) ) : ?>
        <div class="notice notice-<?php echo $message['type'] === 'success' ? 'success' : 'error'; ?> is-dismissible ofp-notice">
            <p><?php echo esc_html( $message['text'] ); ?></p>
        </div>
    <?php endif; ?>
