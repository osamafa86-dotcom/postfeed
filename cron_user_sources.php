<?php
/**
 * Cron: ingest user-owned sources (RSS / website).
 * CLI: php cron_user_sources.php
 * HTTP: cron_user_sources.php?key=CRON_KEY[&limit=15]
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_source_ingest.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(120);
$limit = max(1, min(50, (int) ($_GET['limit'] ?? 15)));
$r = user_source_ingest_due($limit, 20);
echo "user sources: processed={$r['processed']} new={$r['new']}" . (isset($r['error']) ? " error={$r['error']}" : '') . "\n";
