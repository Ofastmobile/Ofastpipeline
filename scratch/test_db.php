<?php
$mysqli = new mysqli('127.0.0.1', 'root', 'root', 'local', 10023);
// Let's check client 4
$res = $mysqli->query("SELECT * FROM wp_ofp_clients WHERE id = 4");
$client = $res->fetch_assoc();
echo "Client 4 before update:\n";
echo "is_white_label: " . var_export($client['is_white_label'], true) . "\n";
echo "custom_domain: " . var_export($client['custom_domain'], true) . "\n";
