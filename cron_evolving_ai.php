<?php
/**
 * نيوز فيد — استخراج كيانات واقتباسات القصص المتطوّرة
 * (Phase 2: entity + quote extraction nightly cron)
 *
 * For every active evolving story, walk the articles that haven't been
 * through the AI extractor yet and push them to Claude Haiku. Keeps the
 * "Story by Numbers" dashboard and the Quote Wall up to date without
 * blocking the RSS ingest path — this runs on its own cron schedule
 * (recommended: nightly at a low-traffic hour).
 *
 * HTTP access: cron_evolving_ai.php?key=XXX[&story=slug][&limit=8]
 * CLI access:  php cron_evolving_ai.php [limit]
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/evolving_stories.php';
require_once __DIR__ . '/includes/evolving_stories_ai.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(600);

// Cost kill-switch — see cron_ai.php for the rationale. This script
// fans 8 AI calls per active story per run, so it's the second most
// expensive after cron_ai.
if ((string)getSetting('cron_evolving_ai_enabled', '1') !== '1') {
    echo "skip: cron_evolving_ai_enabled = 0\n";
    exit;
}

// Per-story budget. Default is small so a nightly run stays cheap even
// when a story has a big backlog — the cron catches up over a few runs.
$perStoryBudget = (int)($_GET['limit'] ?? ($argv[1] ?? 8));
if ($perStoryBudget < 1 || $perStoryBudget > 30) $perStoryBudget = 8;

// Optionally target a single story by slug (handy when debugging).
$onlySlug = trim((string)($_GET['story'] ?? ''));

$stories = evolving_stories_list(true);
if (empty($stories)) {
    echo "no active stories\n";
    exit;
}

$totals = ['processed' => 0, 'failed' => 0, 'entities' => 0, 'quotes' => 0];
$start  = microtime(true);

foreach ($stories as $story) {
    if ($onlySlug !== '' && $story['slug'] !== $onlySlug) continue;

    echo "\n→ {$story['icon']} {$story['name']} (#{$story['id']})\n";
    $res = evolving_stories_ai_extract_story((int)$story['id'], $perStoryBudget);

    $totals['processed'] += (int)($res['processed'] ?? 0);
    $totals['failed']    += (int)($res['failed']    ?? 0);
    $totals['entities']  += (int)($res['entities']  ?? 0);
    $totals['quotes']    += (int)($res['quotes']    ?? 0);

    echo "   processed={$res['processed']} failed={$res['failed']} "
       . "entities={$res['entities']} quotes={$res['quotes']}\n";

    // Small pause between stories so we don't burst the Anthropic rate
    // limit when several stories all have fresh articles.
    if (!empty($res['processed'])) usleep(500000);
}

$elapsed = round(microtime(true) - $start, 2);
echo "\n=== تم ===\n"
   . "معالجة: {$totals['processed']} | فشل: {$totals['failed']}\n"
   . "كيانات: {$totals['entities']} | اقتباسات: {$totals['quotes']}\n"
   . "الوقت: {$elapsed}s\n";
