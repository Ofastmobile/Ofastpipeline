<?php
$f = dirname(__DIR__) . '/public/templates/partials/nav.php';
$lines = file($f);
$js_lines = array_slice($lines, 812, 1246 - 812);
$js = implode('', $js_lines);

preg_match_all('/<\?.*?\?>/s', $js, $matches);
echo "PHP tags in nav.php search modal JS: " . count($matches[0]) . "\n";
foreach ($matches[0] as $idx => $m) {
    echo "  [$idx]: " . trim($m) . "\n";
}
