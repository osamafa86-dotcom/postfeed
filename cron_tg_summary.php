<?php
/**
 * Telegram news briefing generator — every 3h (Palestinian-focus).
 *
 * Generates a comprehensive, de-duplicated briefing every 3 hours.
 * Pulls every Telegram message from the last 24 hours, dedupes across
 * channels, prioritizes Palestinian coverage (80% of sections), and
 * produces a publication-ready report with subheadline, sections,
 * key_numbers, regions, and topics. The 24h window keeps each refresh
 * comprehensive; the 3-hour cadence keeps it current.
 *
 * Cron schedule (cPanel) — every 3 hours:
 *   0 0,3,6,9,12,15,18,21 * * * curl -fsS "https://feedsnews.net/cron_tg_summary.php?key=YOUR_CRON_KEY" > /dev/null
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

// Comprehensive 24h window, refreshed every 3h. Generous caps so the
// briefing is not artificially limited to a few hundred posts.
$windowMins = 1440;
$maxMsgs    = 1500;
$maxTokens  = 7000;

$force = !empty($_GET['force']) || (PHP_SAPI === 'cli' && in_array('--force', $argv ?? [], true));

// Cadence guard: skip if we already generated a report within the last
// ~2.5 hours, unless &force=1 is passed. The 2.5h floor (vs. exactly 3h)
// gives a cushion for clock drift / schedule changes.
if (!$force) {
    $latest = tg_summary_get_latest();
    if ($latest) {
        $ageSecs = time() - strtotime($latest['generated_at']);
        if ($ageSecs < 9000) {
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
