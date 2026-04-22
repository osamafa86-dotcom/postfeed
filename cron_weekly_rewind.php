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
    echo "backfill mode: generating last {$backfill} weeks...\n";
    $self = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
    $now = time();
    for ($i = 0; $i < $backfill; $i++) {
        $weekTs = $now - ($i * 7 * 86400);
        $yw     = wr_year_week_for($weekTs);
        echo "\n=== week {$yw} ===\n";
        $cmd = PHP_BINARY . ' ' . escapeshellarg($self) . ' --force --week=' . escapeshellarg($yw) . ' 2>&1';
        passthru($cmd);
    }
    echo "\nbackfill complete.\n";
    exit;
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

// --- Skip if we already have one for this week --------------------
$existing = wr_get_by_week($targetWeek);
if ($existing && !$force) {
    echo "skip: rewind #{$existing['id']} already exists for {$targetWeek}. Use --force to regenerate.\n";
    exit;
}

// --- Collect candidate articles -----------------------------------
[$startDate, $endDate] = wr_dates_for_year_week($targetWeek);
echo "building rewind for {$targetWeek} ({$startDate} → {$endDate})...\n";

$candidates = wr_collect_candidates($startDate, $endDate, 1, 60);
if (count($candidates) < 15) {
    echo "skip: only " . count($candidates) . " candidates (need 15+).\n";
    exit;
}
echo "candidates: " . count($candidates) . "\n";

// --- AI generation ------------------------------------------------
$t0 = microtime(true);
$ai = wr_generate_with_ai($candidates, $startDate, $endDate);
$elapsed = round(microtime(true) - $t0, 2);

if (empty($ai['ok'])) {
    $err = $ai['error'] ?? 'unknown';
    echo "fail: generation error — {$err} ({$elapsed}s)\n";
    error_log("[weekly_rewind] {$targetWeek}: {$err}");
    exit(1);
}

$payload = $ai['payload'];
$storyCount = count($payload['content']['stories'] ?? []);
echo "ai: picked {$storyCount} stories in {$elapsed}s\n";

// --- Persist ------------------------------------------------------
$id = wr_save($targetWeek, $payload);
if (!$id) {
    echo "fail: could not save rewind\n"; exit(1);
}

echo "ok: saved rewind #{$id} for {$targetWeek}\n";
