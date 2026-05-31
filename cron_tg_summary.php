<?php
/**
 * Telegram news briefing generator — DAILY (Palestinian-focus).
 *
 * Generates ONE comprehensive summary per day, intended to run at
 * 22:00 (10 PM) Jerusalem time. Pulls every Telegram message from
 * the last 24 hours, dedupes across channels, prioritizes Palestinian
 * coverage (80% of sections), and produces a publication-ready
 * report with subheadline, sections, key_numbers, regions, and
 * topics — same structure as the morning briefing.
 *
 * Cron schedule (cPanel — server runs UTC, 22:00 Jerusalem = 19:00 UTC):
 *   0 19 * * * curl -fsS "https://feedsnews.net/cron_tg_summary.php?key=YOUR_CRON_KEY" > /dev/null
 *
 * Manual / CLI:
 *   php cron_tg_summary.php
 *   curl "https://feedsnews.net/cron_tg_summary.php?key=YOUR_CRON_KEY"
 *   curl "https://feedsnews.net/cron_tg_summary.php?key=YOUR_CRON_KEY&force=1"   # ignore the once-per-day guard
 *
 * The 4-hour update mode was retired — only the daily end-of-day report
 * runs now. The function `ai_summarize_telegram()` in includes/ai_helper.php
 * is still there if you ever want to revive it.
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

@set_time_limit(300);

// Daily report — full 24h window, room for 800 messages.
$windowMins = 1440;
$maxMsgs    = 800;
$maxTokens  = 6000;

$force = !empty($_GET['force']) || (PHP_SAPI === 'cli' && in_array('--force', $argv ?? [], true));

// One-per-day guard: skip if we already generated a report within the
// last 20 hours, unless &force=1 is passed. The 20h floor (vs. exactly
// 24h) gives a 4-hour cushion for clock drift / schedule changes.
if (!$force) {
    $latest = tg_summary_get_latest();
    if ($latest) {
        $ageSecs = time() - strtotime($latest['generated_at']);
        if ($ageSecs < 20 * 3600) {
            $hrs = round($ageSecs / 3600, 1);
            echo "skip: latest report is only {$hrs}h old (id={$latest['id']}). Use ?force=1 to regenerate.\n";
            exit;
        }
    }
}

$messages = tg_summary_collect_messages($windowMins, $maxMsgs);
if (count($messages) < 5) {
    echo "skip: only " . count($messages) . " messages available in the last 24h\n";
    exit;
}

$start = microtime(true);
$ai    = ai_summarize_telegram_daily($messages, $maxTokens);
$elapsed = round(microtime(true) - $start, 2);

if (empty($ai['ok'])) {
    $err = $ai['error'] ?? 'unknown';
    echo "fail: {$err} ({$elapsed}s)\n";
    error_log("[cron_tg_summary] daily generation failed: {$err}");
    exit(1);
}

$id = tg_summary_save($ai, count($messages), $windowMins);
if (!$id) {
    echo "fail: could not save briefing to DB\n";
    exit(1);
}

// Keep ~60 days of archive (was 72 entries when we ran 6x/day).
tg_summary_prune(60);

echo "ok: saved daily briefing #{$id} | " . count($messages) . " msgs | {$elapsed}s\n";
echo "  headline: " . ($ai['headline'] ?? '') . "\n";
