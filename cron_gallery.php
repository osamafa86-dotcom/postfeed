<?php
/**
 * نيوز فيد — توليد معرض المشهد اليومي (Daily Gallery cron)
 *
 * Recommended schedule: once daily at 12:00 Cairo time.
 *   0 12 * * * curl -fsS "https://postfeed.emdatra.org/cron_gallery.php?key=XXX" > /dev/null 2>&1
 *
 * HTTP access: cron_gallery.php?key=XXX
 * CLI access:  php cron_gallery.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/gallery.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(120);

$today = date('Y-m-d');

// Don't regenerate if today's gallery already exists.
$existing = gallery_get($today);
if ($existing && empty($_GET['force'])) {
    echo "gallery already exists for {$today} (#{$existing['id']})\n";
    exit;
}

echo "generating daily gallery for {$today}...\n";
$t0 = microtime(true);

$gallery = gallery_build($today);
$dt = round(microtime(true) - $t0, 2);

if ($gallery && !empty($gallery['photos'])) {
    $count = count($gallery['photos']);
    echo "ok: saved gallery — \"{$gallery['headline']}\" ({$count} photos, {$dt}s)\n";
} else {
    echo "failed: no gallery generated (not enough images?)\n";
    exit(1);
}
