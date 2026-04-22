<?php
/**
 * نيوز فيد — Daily Podcast cron entry point.
 *
 * Thin wrapper: guards HTTP access with cron_key, then
 * delegates to pod_run_generate_day() in
 * includes/podcast_run.php so the panel can share the same
 * codepath without tripping the guard.
 *
 * Invocation:
 *   CLI:   php cron_podcast.php [--force] [--date=YYYY-MM-DD] [--script-only]
 *   HTTP:  curl "…/cron_podcast.php?key=CRON_KEY[&force=1][&date=…][&script_only=1]"
 *
 * Recommended schedule: 0 6 * * *  (6 AM local time)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/includes/podcast_run.php';
@set_time_limit(600);

// ---- Flags ---------------------------------------------------
$force      = !empty($_GET['force']);
$scriptOnly = !empty($_GET['script_only']) || !empty($_GET['script-only']);
$targetDate = $_GET['date'] ?? null;
if (PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $a) {
        if ($a === '--force') $force = true;
        if ($a === '--script-only') $scriptOnly = true;
        if (strpos($a, '--date=') === 0) $targetDate = substr($a, 7);
    }
}
if (!$targetDate) $targetDate = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$targetDate)) {
    echo "bad date: {$targetDate}\n"; exit(1);
}

$r = pod_run_generate_day($targetDate, $force, $scriptOnly);
echo $r['log'] . "\n";
exit($r['ok'] ? 0 : 1);
