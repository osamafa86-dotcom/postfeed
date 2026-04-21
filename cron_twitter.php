<?php
/**
 * Cron endpoint — pulls latest tweets from every active Twitter source.
 * Usage: php cron_twitter.php  OR  curl https://yoursite/cron_twitter.php?key=YOUR_KEY
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/twitter_fetch.php';

// Shared cron key — same setting as the Telegram cron so ops only
// manages one secret.
if (php_sapi_name() !== 'cli') {
    $key = getSetting('cron_key', '');
    if (!$key || ($_GET['key'] ?? '') !== $key) {
        http_response_code(403);
        die('forbidden');
    }
}

$count = tw_sync_all_sources();
echo "Fetched $count new tweets\n";
