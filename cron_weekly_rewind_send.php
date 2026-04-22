<?php
/**
 * نيوز فيد — Weekly Rewind mailer.
 *
 * Runs Sunday ~07:00 to deliver the current week's digest to all
 * confirmed newsletter subscribers. Uses the shared mailer_send()
 * + newsletter_email_html() pair so throttling and SMTP config
 * match the daily newsletter.
 *
 * Safety:
 *   - Finds the latest rewind by year_week.
 *   - Skips if it was already emailed (weekly_rewinds.emailed_at).
 *   - Records one row per recipient in weekly_rewind_deliveries so
 *     re-runs don't double-send.
 *   - --force / ?force=1 bypasses both guards (admin only).
 *
 * Invocation:
 *   CLI:   php cron_weekly_rewind_send.php [--force] [--week=2026-17] [--dry]
 *   HTTP:  curl "https://site/cron_weekly_rewind_send.php?key=CRON_KEY[&force=1][&week=YYYY-WW][&dry=1]"
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/weekly_rewind.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(600);
$start = microtime(true);

// ---- Flags -------------------------------------------------------
$force = !empty($_GET['force']) || (PHP_SAPI === 'cli' && in_array('--force', $argv ?? [], true));
$dry   = !empty($_GET['dry'])   || (PHP_SAPI === 'cli' && in_array('--dry',   $argv ?? [], true));
$week  = $_GET['week'] ?? null;
if (!$week && PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $a) {
        if (strpos($a, '--week=') === 0) $week = substr($a, 7);
    }
}

// Send loop lives in wr_run_send() so the panel ("📧 إرسال لكل المشتركين"
// button) and this cron run identical code.
$r = wr_run_send((string)($week ?: ''), $force, $dry);
$elapsed = round(microtime(true) - $start, 2);
echo $r['log'] . "\n— {$elapsed}s\n";
exit($r['ok'] ? 0 : 1);
