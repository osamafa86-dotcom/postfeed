<?php
/**
 * نيوزفلو — Image proxy / resizer
 * URL: /api/img.php?url=ENCODED_URL&w=400&q=75
 *
 * Downloads external images, resizes them using GD, caches locally,
 * and optionally converts to WebP. Serves cached copy on subsequent
 * requests. This enables srcset with multiple widths from a single
 * external source URL.
 *
 * Parameters:
 *   url  (required) — External image URL
 *   w    (optional) — Target width in px (default: original)
 *   q    (optional) — Quality 1-100 (default: 80)
 *   fmt  (optional) — 'webp' | 'jpg' (default: auto based on Accept header)
 */

// Allowed widths to prevent abuse — only these sizes are served
$ALLOWED_WIDTHS = [160, 320, 480, 640, 800, 1200];
$CACHE_DIR = __DIR__ . '/../cache/img';
$CACHE_TTL = 86400 * 7; // 7 days

// Ensure cache dir exists
if (!is_dir($CACHE_DIR)) {
    @mkdir($CACHE_DIR, 0755, true);
}

$url = isset($_GET['url']) ? trim($_GET['url']) : '';
$w   = isset($_GET['w']) ? (int)$_GET['w'] : 0;
$q   = isset($_GET['q']) ? max(10, min(100, (int)$_GET['q'])) : 80;

if (empty($url) || !preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    exit('Bad URL');
}

// Snap width to nearest allowed size
if ($w > 0) {
    $best = $ALLOWED_WIDTHS[0];
    foreach ($ALLOWED_WIDTHS as $aw) {
        if (abs($aw - $w) < abs($best - $w)) $best = $aw;
    }
    $w = $best;
}

// Determine output format
$acceptWebp = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'image/webp') !== false;
$fmt = $_GET['fmt'] ?? ($acceptWebp ? 'webp' : 'jpg');
if (!in_array($fmt, ['webp', 'jpg'])) $fmt = 'jpg';

// Cache key
$cacheKey = md5($url . '|' . $w . '|' . $q . '|' . $fmt);
$cachePath = $CACHE_DIR . '/' . $cacheKey . '.' . $fmt;

// Serve from cache if fresh
if (file_exists($cachePath) && (time() - filemtime($cachePath)) < $CACHE_TTL) {
    $mime = $fmt === 'webp' ? 'image/webp' : 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=2592000, immutable');
    header('X-Cache: HIT');
    readfile($cachePath);
    exit;
}

// Download the original image
$ctx = stream_context_create([
    'http' => [
        'timeout' => 8,
        'user_agent' => 'NewsFlow-ImgProxy/1.0',
        'follow_location' => true,
        'max_redirects' => 3,
    ],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
]);

$imgData = @file_get_contents($url, false, $ctx);
if ($imgData === false || strlen($imgData) < 100) {
    http_response_code(502);
    exit('Failed to fetch image');
}

// Create GD image
$src = @imagecreatefromstring($imgData);
if (!$src) {
    http_response_code(502);
    exit('Invalid image');
}

$origW = imagesx($src);
$origH = imagesy($src);

// Resize if width specified and smaller than original
if ($w > 0 && $w < $origW) {
    $newH = (int)round($origH * ($w / $origW));
    $dst = imagecreatetruecolor($w, $newH);
    // Preserve transparency for PNG sources
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $newH, $origW, $origH);
    imagedestroy($src);
    $src = $dst;
}

// Output & cache
if ($fmt === 'webp' && function_exists('imagewebp')) {
    imagewebp($src, $cachePath, $q);
    $mime = 'image/webp';
} else {
    imagejpeg($src, $cachePath, $q);
    $mime = 'image/jpeg';
    $fmt = 'jpg';
}
imagedestroy($src);

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=2592000, immutable');
header('X-Cache: MISS');
readfile($cachePath);
