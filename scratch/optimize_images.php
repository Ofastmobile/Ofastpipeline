<?php
$source_dir = dirname(__DIR__) . '/assets/images';
$out_dir    = dirname(__DIR__) . '/r2-upload-ready';

// Delete previous contents
array_map('unlink', glob("$out_dir/*.*"));

$active_images = [
    'dashboard-hero-banner.webp',
    'Real Estate Deal Handshake.webp',
    'Keys, Contract, and New Beginnings.webp',
    'Miniature House and Coin Stacks.webp',
    'oleksandrpidvalnyi-real-estate-6688945_1920.webp',
    'elg21-architecture-10265145_1920.webp',
    'pexels-apartment-2179337_1920.webp',
    '23555986-hallway-9011236_1920.webp',
    'ap301805-old-school-building-7576490_1920.webp',
    'piro4d-kitchen-1543493_1920.webp',
    'pexels-house-1836070.webp',
    'Smartphone-Mockup.png',
    'receipt-verified-stamp.png',
];

$total_before = 0;
$total_after  = 0;

echo "=== PREPARING ONLY ACTIVE IMAGES FOR CLOUDFLARE R2 ===\n";
printf("%-42s %-12s %-12s %-10s\n", "Filename", "Original", "Compressed", "Savings");
echo str_repeat("-", 78) . "\n";

foreach ($active_images as $file) {
    $src_path = $source_dir . '/' . $file;
    if (!file_exists($src_path)) continue;

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $orig_size = filesize($src_path);
    $total_before += $orig_size;

    $dst_path = $out_dir . '/' . $file;

    // Load image
    $img = null;
    if ($ext === 'webp') {
        $img = @imagecreatefromwebp($src_path);
    } elseif ($ext === 'png') {
        $img = @imagecreatefrompng($src_path);
    }

    if (!$img) {
        copy($src_path, $dst_path);
        $new_size = filesize($dst_path);
        $total_after += $new_size;
        printf("%-42s %-12s %-12s %-10s\n", substr($file, 0, 40), round($orig_size / 1024, 1) . " KB", round($new_size / 1024, 1) . " KB", "0%");
        continue;
    }

    $w = imagesx($img);
    $h = imagesy($img);

    // Max width 1600px for web banners, maintain crisp aspect ratio
    $max_w = ($file === 'Smartphone-Mockup.png' || $file === 'receipt-verified-stamp.png') ? 800 : 1600;
    if ($w > $max_w) {
        $new_w = $max_w;
        $new_h = (int) round(($h / $w) * $new_w);
        $resized = imagecreatetruecolor($new_w, $new_h);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
        imagedestroy($img);
        $img = $resized;
    }

    // Save with optimal compression
    if ($ext === 'webp') {
        imagewebp($img, $dst_path, 80);
    } elseif ($ext === 'png') {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagepng($img, $dst_path, 8);
    }

    imagedestroy($img);

    $new_size = filesize($dst_path);
    if ($new_size > $orig_size) {
        copy($src_path, $dst_path);
        $new_size = $orig_size;
    }

    $total_after += $new_size;
    $saved_pct = round((1 - ($new_size / $orig_size)) * 100, 1);
    printf("%-42s %-12s %-12s %-10s\n", substr($file, 0, 40), round($orig_size / 1024, 1) . " KB", round($new_size / 1024, 1) . " KB", "-{$saved_pct}%");
}

echo str_repeat("=", 78) . "\n";
$total_saved_pct = round((1 - ($total_after / $total_before)) * 100, 1);
printf("%-42s %-12s %-12s %-10s\n", "ACTIVE IMAGES TOTAL", round($total_before / 1024 / 1024, 2) . " MB", round($total_after / 1024 / 1024, 2) . " MB", "-{$total_saved_pct}%");
echo "\nTarget folder: " . $out_dir . "\n";
