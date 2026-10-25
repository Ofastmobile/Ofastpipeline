<?php
$urls = [
    'http://localhost:10016/login/',
    'http://localhost:10016/dashboard',
];

foreach ($urls as $url) {
    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $res = curl_exec($ch);
    $info = curl_getinfo($ch);
    $dur = microtime(true) - $t0;
    echo "URL: $url | Code: {$info['http_code']} | Total Time: " . round($dur, 3) . "s | Connect: " . round($info['connect_time'], 3) . "s\n";
}
