<?php
/**
 * Hourly news briefing generator for the Telegram page.
 *
 * Pulls the last 60 minutes of messages from telegram_messages,
 * hands them to Claude via ai_summarize_telegram(), and saves the
 * structured briefing to the telegram_summaries table. The public
 * telegram_summary.php endpoint just reads from that table — no
 * Claude call per visitor — so this script is the only place the
 * API is hit, at a cost of at most one call per hour.
 *
 * Usage:
 *   php cron_tg_summary.php
 *   curl "https://yoursite/cron_tg_summary.php?key=YOUR_CRON_KEY"
 *
 * Recommended crontab line (runs at minute 0 every hour):
 *   0 * * * * curl -fsS "https://yoursite/cron_tg_summary.php?key=YOUR_CRON_KEY" > /dev/null
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ai_helper.php';

// HTTP access is key-gated; CLI is always allowed.
if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(180);

$windowMins = (int)($_GET['window'] ?? ($argv[1] ?? 60));
if ($windowMins < 15)  $windowMins = 15;
if ($windowMins > 240) $windowMins = 240;

// Dedup guard: if the most recent briefing is younger than this,
// skip and exit — protects against an overzealous cron frequency
// or a manual poke right after an hourly run.
$minGapMins = 30;
$latest = tg_summary_get_latest();
if ($latest) {
    $ageSecs = time() - strtotime($latest['generated_at']);
    if ($ageSecs < $minGapMins * 60) {
        $mins = (int)round($ageSecs / 60);
        echo "skip: latest briefing is only {$mins}m old (id={$latest['id']})\n";
        exit;
    }
}

$messages = tg_summary_collect_messages($windowMins, 250);
if (count($messages) < 3) {
    echo "skip: only " . count($messages) . " messages available\n";
    exit;
}

$start = microtime(true);
$ai = ai_summarize_telegram($messages);
$elapsed = round(microtime(true) - $start, 2);

if (empty($ai['ok'])) {
    $err = $ai['error'] ?? 'unknown';
    echo "fail: {$err} ({$elapsed}s)\n";
    error_log("[cron_tg_summary] generation failed: {$err}");
    exit(1);
}

$id = tg_summary_save($ai, count($messages), $windowMins);
if (!$id) {
    echo "fail: could not save briefing to DB\n";
    exit(1);
}

// Keep the archive to a reasonable size (two days of hourly runs
// is 48, with a little slack for manual triggers).
tg_summary_prune(72);

echo "ok: saved briefing #{$id} | " . count($messages) . " msgs | {$elapsed}s\n";
