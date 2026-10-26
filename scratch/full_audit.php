<?php
/**
 * Comprehensive Codebase Audit Script for OFast Pipeline
 */

$plugin_dir = realpath(__DIR__ . '/..');

function get_all_plugin_files($dir) {
    $files = [];
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.git' || $item === 'scratch') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            $files = array_merge($files, get_all_plugin_files($path));
        } else {
            $files[] = $path;
        }
    }
    return $files;
}

$all_files = get_all_plugin_files($plugin_dir);

// 1. Line Counts & Breakdown
$by_ext = [];
$total_lines = 0;
$total_code_lines = 0;
$file_stats = [];

// 2. Inline CSS & JS
$inline_css_files = [];
$inline_js_files = [];
$inline_style_attr_count = 0;
$inline_style_by_file = [];

// 3. Security / Header check
$missing_abspath = [];

// 4. Classes & Requires mapping
$defined_classes = [];
$required_files = [];
$all_php_contents = [];

foreach ($all_files as $f) {
    $rel = str_replace($plugin_dir . DIRECTORY_SEPARATOR, '', $f);
    $rel = str_replace('\\', '/', $rel);
    $ext = pathinfo($f, PATHINFO_EXTENSION);
    $content = file_get_contents($f);
    $lines = substr_count($content, "\n") + 1;
    
    $raw_lines = explode("\n", $content);
    $code_lines = 0;
    foreach ($raw_lines as $l) {
        $trim = trim($l);
        if ($trim !== '' && !str_starts_with($trim, '//') && !str_starts_with($trim, '#') && !str_starts_with($trim, '/*') && !str_starts_with($trim, '*')) {
            $code_lines++;
        }
    }
    
    if (!isset($by_ext[$ext])) {
        $by_ext[$ext] = ['count' => 0, 'lines' => 0, 'code_lines' => 0];
    }
    $by_ext[$ext]['count']++;
    $by_ext[$ext]['lines'] += $lines;
    $by_ext[$ext]['code_lines'] += $code_lines;
    
    $total_lines += $lines;
    $total_code_lines += $code_lines;
    
    $file_stats[$rel] = [
        'ext' => $ext,
        'lines' => $lines,
        'code_lines' => $code_lines,
        'size' => strlen($content)
    ];

    if ($ext === 'php') {
        $all_php_contents[$rel] = $content;

        // Check ABSPATH
        if (!str_contains($content, 'ABSPATH') && !str_contains($rel, 'languages/') && basename($rel) !== 'index.php') {
            $missing_abspath[] = $rel;
        }

        // Inline <style> tags
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $content, $m)) {
            $tag_count = count($m[0]);
            $style_lines = 0;
            foreach ($m[1] as $block) {
                $style_lines += substr_count($block, "\n") + 1;
            }
            $inline_css_files[$rel] = ['count' => $tag_count, 'lines' => $style_lines];
        }

        // Inline <script> tags
        if (preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $content, $m)) {
            $js_tags = 0;
            $js_lines = 0;
            foreach ($m[0] as $idx => $full_tag) {
                // Ignore external script tags with src
                if (preg_match('/<script[^>]+src=/i', $full_tag)) continue;
                $js_tags++;
                $js_lines += substr_count($m[1][$idx], "\n") + 1;
            }
            if ($js_tags > 0) {
                $inline_js_files[$rel] = ['count' => $js_tags, 'lines' => $js_lines];
            }
        }

        // Inline style="..." attributes
        if (preg_match_all('/\bstyle="([^"]+)"/i', $content, $m)) {
            $inline_style_attr_count += count($m[0]);
            $inline_style_by_file[$rel] = count($m[0]);
        }

        // Classes defined
        if (preg_match_all('/(?:class|interface|trait)\s+([A-Za-z0-9_]+)/i', $content, $m)) {
            foreach ($m[1] as $cls) {
                $defined_classes[$cls] = $rel;
            }
        }

        // Require / include
        if (preg_match_all('/(?:require|require_once|include|include_once)\s*[\'"]([^\'"]+)[\'"]/i', $content, $m)) {
            foreach ($m[1] as $req) {
                $required_files[] = basename($req);
            }
        }
    }
}

// 5. Unused / Unreferenced Classes
$unused_classes = [];
foreach ($defined_classes as $cls => $origin_file) {
    $ref_count = 0;
    foreach ($all_php_contents as $rel => $c) {
        if ($rel === $origin_file) {
            // Count occurrences minus definition
            $matches = preg_match_all('/\b' . preg_quote($cls, '/') . '\b/', $c);
            if ($matches > 1) {
                $ref_count += ($matches - 1);
            }
        } else {
            if (preg_match('/\b' . preg_quote($cls, '/') . '\b/', $c)) {
                $ref_count++;
            }
        }
    }
    if ($ref_count === 0) {
        $unused_classes[] = ['class' => $cls, 'file' => $origin_file];
    }
}

// 6. PHP files not required in ofast-pipeline.php or any other file
$main_file_content = file_get_contents($plugin_dir . '/ofast-pipeline.php');
$orphan_php_files = [];
foreach ($all_files as $f) {
    $rel = str_replace($plugin_dir . DIRECTORY_SEPARATOR, '', $f);
    $rel = str_replace('\\', '/', $rel);
    if (!str_ends_with($rel, '.php')) continue;
    if ($rel === 'ofast-pipeline.php' || str_starts_with($rel, 'public/templates/') || str_starts_with($rel, 'admin/views/')) continue;
    
    $base = basename($rel);
    // Check if referenced anywhere
    $is_referenced = false;
    foreach ($all_php_contents as $c_rel => $c_text) {
        if ($c_rel === $rel) continue;
        if (str_contains($c_text, $base) || str_contains($c_text, str_replace('.php', '', $base))) {
            $is_referenced = true;
            break;
        }
    }
    if (!$is_referenced) {
        $orphan_php_files[] = $rel;
    }
}

// Sort largest files
uasort($file_stats, function($a, $b) { return $b['lines'] <=> $a['lines']; });
arsort($inline_style_by_file);

$results = [
    'summary' => [
        'total_files' => count($all_files),
        'total_lines' => $total_lines,
        'total_code_lines' => $total_code_lines,
        'inline_style_attributes' => $inline_style_attr_count,
    ],
    'by_extension' => $by_ext,
    'top_files' => array_slice($file_stats, 0, 20),
    'inline_css' => $inline_css_files,
    'inline_js' => $inline_js_files,
    'top_inline_styles' => array_slice($inline_style_by_file, 0, 15),
    'missing_abspath' => $missing_abspath,
    'unused_classes' => $unused_classes,
    'orphan_files' => $orphan_php_files,
];

file_put_contents(__DIR__ . '/audit_full_results.json', json_encode($results, JSON_PRETTY_PRINT));
echo "AUDIT COMPLETE: Output saved to scratch/audit_full_results.json\n";
