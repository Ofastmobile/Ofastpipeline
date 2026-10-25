<?php
foreach ([10013, 10014, 10015] as $port) {
    try {
        $m = @mysqli_connect('127.0.0.1', 'root', 'root', 'local', $port);
        if ($m) {
            echo "Connected on port $port!\n";
            $res = mysqli_query($m, "SELECT option_name, option_value FROM wp_options WHERE option_name IN ('ofp_rewrite_version', 'ofp_flush_rewrite_rules', 'active_plugins')");
            while ($row = mysqli_fetch_assoc($res)) {
                echo $row['option_name'] . " => " . substr($row['option_value'], 0, 100) . "\n";
            }
            break;
        }
    } catch (\Throwable $e) {}
}
