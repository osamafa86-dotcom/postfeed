<?php
/**
 * Telegram news briefing generator.
 *
 * Two modes controlled by crontab scheduling:
 *
 *   1) Regular update (every 4 hours) — collects messages from the
 *      last 240 minutes and generates a standard thematic briefing.
 *
 *   2) Comprehensive daily summary (noon) — collects messages from
 *      the last 24 hours and generates a detailed end-of-morning
 *      wrap-up with deeper context and more sections.
 *
 * Usage:
 *   # Regular 4-hour update:
 *   php cron_tg_summary.php
 *   curl "https://yoursite/cron_tg_summary.php?key=YOUR_CRON_KEY"
 *
 *   # Comprehensive daily summary (noon run):
 *   php cron_tg_summary.php --daily
 *   curl "https://yoursite/cron_tg_summary.php?key=YOUR_CRON_KEY&mode=daily"
 *
 * Recommended crontab lines:
 *   # Every 4 hours (00:00, 04:00, 08:00, 16:00, 20:00 — skip 12:00):
 *   0 0,4,8,16,20 * * * curl -fsS "https://yoursite/cron_tg_summary.php?key=YOUR_CRON_KEY" > /dev/null
 *
 *   # Comprehensive noon summary (12:00 daily):
 *   0 12 * * * curl -fsS "https://yoursite/cron_tg_summary.php?key=YOUR_CRON_KEY&mode=daily" > /dev/null
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

// Determine mode: "daily" for the comprehensive noon wrap-up,
// "regular" for the standard 4-hour update.
$mode = 'regular';
if (isset($_GET['mode']) && $_GET['mode'] === 'daily') {
    $mode = 'daily';
} elseif (PHP_SAPI === 'cli' && in_array('--daily', $argv ?? [], true)) {
    $mode = 'daily';
}

if ($mode === 'daily') {
    // Comprehensive daily summary: 24-hour window, more tokens
    $windowMins = 1440; // 24 hours
    $maxMsgs    = 500;
    $maxTokens  = 5000;
    $minGapMins = 120; // 2h gap to avoid overlap with a regular run
} else {
    // Regular 4-hour update
    $windowMins = (int)($_GET['window'] ?? ($argv[1] ?? 240));
    if ($windowMins < 15)  $windowMins = 15;
    if ($windowMins > 480) $windowMins = 480;
    $maxMsgs    = 250;
    $maxTokens  = 3500;
    $minGapMins = 120; // 2h gap protects against too-frequent runs
}

// Dedup guard: if the most recent briefing is younger than minGapMins,
// skip and exit — protects against an overzealous cron frequency
// or a manual poke right after a run.
$latest = tg_summary_get_latest();
if ($latest) {
    $ageSecs = time() - strtotime($latest['generated_at']);
    if ($ageSecs < $minGapMins * 60) {
        $mins = (int)round($ageSecs / 60);
        echo "skip: latest briefing is only {$mins}m old (id={$latest['id']})\n";
        exit;
    }
}

$messages = tg_summary_collect_messages($windowMins, $maxMsgs);
if (count($messages) < 3) {
    echo "skip: only " . count($messages) . " messages available\n";
    exit;
}

$start = microtime(true);

if ($mode === 'daily') {
    $ai = ai_summarize_telegram_daily($messages, $maxTokens);
} else {
    $ai = ai_summarize_telegram($messages, $maxTokens);
}

$elapsed = round(microtime(true) - $start, 2);

if (empty($ai['ok'])) {
    $err = $ai['error'] ?? 'unknown';
    echo "fail: {$err} ({$elapsed}s)\n";
    error_log("[cron_tg_summary] generation failed ({$mode}): {$err}");
    exit(1);
}

$id = tg_summary_save($ai, count($messages), $windowMins);
if (!$id) {
    echo "fail: could not save briefing to DB\n";
    exit(1);
}

// Keep the archive to a reasonable size (6 runs/day × 12 days ≈ 72).
tg_summary_prune(72);

echo "ok [{$mode}]: saved briefing #{$id} | " . count($messages) . " msgs | {$elapsed}s\n";
