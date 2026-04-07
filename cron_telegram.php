<?php
/**
 * Cron endpoint - fetches latest messages from all Telegram sources.
 * Usage: php cron_telegram.php  OR  curl https://yoursite/cron_telegram.php?key=YOUR_KEY
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/telegram_fetch.php';

// Optional security key (set TELEGRAM_CRON_KEY in config)
if (php_sapi_name() !== 'cli') {
    $key = getSetting('cron_key', '');
    if (!$key || ($_GET['key'] ?? '') !== $key) {
        http_response_code(403);
        die('forbidden');
    }
}

$count = tg_sync_all_sources();
echo "Fetched $count new messages\n";
