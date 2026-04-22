<?php
/**
 * On-demand PWA icon generator.
 *
 * Accepts ?size=<px> (default 192). Outputs a PNG with the site's
 * brand "N" letter on a teal background. Results are written to
 * /assets/icons/<size>[-maskable].png the first time each variant
 * is requested so subsequent hits are served straight from disk
 * by the .htaccess static rewrites (far-future cache).
 *
 * Also supports:
 *   ?size=512&maskable=1  — adds a safe-area margin so the icon
 *                           stays inside Android's mask shapes.
 */

require_once __DIR__ . '/includes/config.php';

$size     = (int)($_GET['size'] ?? 192);
$maskable = !empty($_GET['maskable']);
if ($size < 48)  $size = 48;
if ($size > 1024) $size = 1024;

$cacheDir = __DIR__ . '/assets/icons';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
$cacheFile = $cacheDir . '/' . $size . ($maskable ? '-maskable' : '') . '.png';

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');

if (is_file($cacheFile)) {
    readfile($cacheFile);
    exit;
}

if (!function_exists('imagecreatetruecolor')) {
    http_response_code(500);
    exit;
}

$img = imagecreatetruecolor($size, $size);
imagealphablending($img, true);
imagesavealpha($img, true);

// Brand teal background with subtle gradient. Maskable icons need a
// bigger safe zone so iOS/Android mask shapes don't clip the "N".
$bg1 = [0x1a, 0x5c, 0x5c]; // teal
$bg2 = [0x0d, 0x94, 0x88]; // accent
for ($y = 0; $y < $size; $y++) {
    $t = $y / max(1, $size - 1);
    $r = (int)($bg1[0] + ($bg2[0] - $bg1[0]) * $t);
    $g = (int)($bg1[1] + ($bg2[1] - $bg1[1]) * $t);
    $b = (int)($bg1[2] + ($bg2[2] - $bg1[2]) * $t);
    $line = imagecolorallocate($img, $r, $g, $b);
    imageline($img, 0, $y, $size - 1, $y, $line);
}

// Rounded corners for the non-maskable variant. Maskable icons are
// expected to fill the square edge-to-edge.
if (!$maskable) {
    $radius = (int)round($size * 0.20);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagealphablending($img, false);
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $cx = $x < $radius ? $radius - $x : ($x >= $size - $radius ? $x - ($size - $radius - 1) : 0);
            $cy = $y < $radius ? $radius - $y : ($y >= $size - $radius ? $y - ($size - $radius - 1) : 0);
            if ($cx > 0 && $cy > 0) {
                if (sqrt($cx * $cx + $cy * $cy) > $radius) {
                    imagesetpixel($img, $x, $y, $transparent);
                }
            }
        }
    }
    imagealphablending($img, true);
}

$white = imagecolorallocate($img, 255, 255, 255);

// Draw "N" — either via the built-in bundled font (fallback) or, if
// FreeType + a TTF is available, render it nicely anti-aliased. The
// bundled Tajawal font isn't shipped in the repo so we try a couple
// of common system paths before falling back to imagestring.
$safe        = $maskable ? 0.18 : 0.08;  // inset fraction
$innerSize   = (int)round($size * (1 - 2 * $safe));
$innerOffset = (int)round($size * $safe);

$fontCandidates = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
    '/Library/Fonts/Arial Bold.ttf',
    'C:/Windows/Fonts/arialbd.ttf',
];
$ttf = null;
foreach ($fontCandidates as $f) {
    if (is_file($f)) { $ttf = $f; break; }
}

if ($ttf && function_exists('imagettftext')) {
    $fontSize = (int)round($innerSize * 0.7);
    $bbox = imagettfbbox($fontSize, 0, $ttf, 'N');
    $textW = abs($bbox[2] - $bbox[0]);
    $textH = abs($bbox[7] - $bbox[1]);
    $tx = (int)round(($size - $textW) / 2 - $bbox[0]);
    $ty = (int)round(($size + $textH) / 2 - ($bbox[1] + $textH));
    imagettftext($img, $fontSize, 0, $tx, $ty, $white, $ttf, 'N');
} else {
    // Fallback: use the largest built-in font, scaled up with a
    // couple of stamps so it doesn't look tiny on 512px icons.
    $glyph = imagecreatetruecolor(10, 15);
    imagesavealpha($glyph, true);
    $gTrans = imagecolorallocatealpha($glyph, 0, 0, 0, 127);
    imagefill($glyph, 0, 0, $gTrans);
    $gWhite = imagecolorallocate($glyph, 255, 255, 255);
    imagestring($glyph, 5, 1, 0, 'N', $gWhite);
    $dst = (int)round($innerSize * 0.85);
    $dx  = (int)round(($size - $dst) / 2);
    $dy  = (int)round(($size - $dst) / 2);
    imagecopyresampled($img, $glyph, $dx, $dy, 0, 0, $dst, $dst, 10, 15);
    imagedestroy($glyph);
}

ob_start();
imagepng($img, null, 9);
$png = ob_get_clean();
imagedestroy($img);

@file_put_contents($cacheFile, $png);
echo $png;
