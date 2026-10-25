<?php
$t0 = microtime(true);
require_once dirname(__DIR__, 4) . '/wp-load.php';
$t_wp = microtime(true) - $t0;

echo "WP Load time: " . round($t_wp, 3) . "s\n";

// Check active plugins
$active_plugins = get_option('active_plugins');
echo "Active plugins count: " . count($active_plugins) . "\n";

// Check DB queries count & performance
global $wpdb;
echo "Total queries so far: " . count($wpdb->queries ?? []) . "\n";
