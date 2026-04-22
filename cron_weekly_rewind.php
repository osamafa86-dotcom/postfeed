<?php
/**
 * نيوز فيد — Weekly Rewind generator.
 *
 * Runs every Saturday ~20:00 to compose the week's digest. The
 * generated content lives in `weekly_rewinds` and is surfaced by
 * /weekly/<year>-<week> and emailed to subscribers on Sunday
 * morning by cron_weekly_rewind_send.php (next chunk).
 *
 * Safety:
 *   - Dedup guard: skips if this year-week already exists unless
 *     --force / ?force=1 is provided.
 *   - Needs at least 15 candidate articles to bother composing.
 *
 * Invocation:
 *   CLI:   php cron_weekly_rewind.php [--force] [--week=2026-17]
 *   HTTP:  curl "https://site/cron_weekly_rewind.php?key=CRON_KEY[&force=1][&week=YYYY-WW]"
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/weekly_rewind.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(300);

// --- Backfill mode ------------------------------------------------
// One-shot helper to seed the archive with the last N weeks. Useful
// on first deploy so the /weekly/archive isn't empty until next
// Saturday. Each week is generated sequentially with --force.
//   CLI:   php cron_weekly_rewind.php --backfill=4
//   HTTP:  ?backfill=4&key=...
$backfill = 0;
if (!empty($_GET['backfill'])) $backfill = (int)$_GET['backfill'];
if (PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $a) {
        if (strpos($a, '--backfill=') === 0) $backfill = (int)substr($a, 11);
    }
}
$backfill = max(0, min(12, $backfill));
if ($backfill > 0) {
    $r = wr_run_backfill($backfill);
    echo $r['log'] . "\n";
    exit($r['ok'] ? 0 : 1);
}

// --- Resolve the target week --------------------------------------
// Default: "this week" at the time of running. Override with
// --week=YYYY-WW or ?week=YYYY-WW for back-filling past weeks.
$targetWeek = $_GET['week'] ?? null;
if (!$targetWeek && PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $a) {
        if (strpos($a, '--week=') === 0) $targetWeek = substr($a, 7);
    }
}
$targetWeek = $targetWeek ?: wr_year_week_for(time());
if (!preg_match('/^\d{4}-\d{1,2}$/', (string)$targetWeek)) {
    echo "invalid week: {$targetWeek}\n"; exit(1);
}

$force = !empty($_GET['force']) || (PHP_SAPI === 'cli' && in_array('--force', $argv ?? [], true));

// Single-week generation. Logic lives in includes/weekly_rewind.php
// so the panel ("توليد بالـ AI" button) and this cron run identical
// code — no chance of behaviour drift between the two paths.
$r = wr_run_generate($targetWeek, $force);
echo $r['log'] . "\n";
exit($r['ok'] ? 0 : 1);
