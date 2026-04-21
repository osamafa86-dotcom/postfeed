<?php
/**
 * Cron endpoint — pulls latest videos from every active YouTube source.
 * Usage: php cron_youtube.php  OR  curl https://yoursite/cron_youtube.php?key=YOUR_KEY
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/youtube_fetch.php';

if (php_sapi_name() !== 'cli') {
    $key = getSetting('cron_key', '');
    if (!$key || ($_GET['key'] ?? '') !== $key) {
        http_response_code(403);
        die('forbidden');
    }
}

$count = yt_sync_all_sources();
echo "Fetched $count new videos\n";
