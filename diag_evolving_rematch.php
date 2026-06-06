<?php
/**
 * نيوز فيد — إعادة تطبيق الكلمات المفتاحية على القصص المتطوّرة.
 *
 * After a story's keywords or exclude_keywords are edited (e.g. when
 * we renamed "أخبار الأقصى" → "أخبار القدس" and added smart
 * exclusions), the existing junction rows are stale: they reflect the
 * old keywords. This script walks every active story and re-runs the
 * keyword matcher against the last N days of articles so the home
 * rail catches up immediately instead of waiting for cron_rss.php to
 * insert fresh items.
 *
 * Usage (CLI):
 *   php diag_evolving_rematch.php           # last 60 days, all stories
 *   php diag_evolving_rematch.php 30        # last 30 days, all stories
 *   php diag_evolving_rematch.php 30 al-aqsa  # last 30 days, one story
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/evolving_stories.php';

$days = isset($argv[1]) ? max(1, (int)$argv[1]) : 60;
$slug = $argv[2] ?? null;

$stories = evolving_stories_list(true);
if ($slug) {
    $stories = array_filter($stories, fn($s) => $s['slug'] === $slug);
}
if (empty($stories)) {
    fwrite(STDERR, "no active stories matched\n");
    exit(1);
}

echo "Rematching last $days days for " . count($stories) . " stor"
   . (count($stories) === 1 ? "y" : "ies") . "…\n\n";

foreach ($stories as $story) {
    echo "─ {$story['name']} ({$story['slug']})\n";

    // First, purge existing matches that contain any current
    // exclude_keyword — those are stale false positives left over
    // from before the exclusion was added.
    if (!empty($story['exclude_keywords'])) {
        try {
            $db = getDB();
            $purged = 0;
            $del = $db->prepare("DELETE esa FROM evolving_story_articles esa
                                  JOIN articles a ON a.id = esa.article_id
                                  WHERE esa.story_id = ?
                                    AND (a.title LIKE ? OR a.excerpt LIKE ?)");
            foreach ($story['exclude_keywords'] as $ex) {
                $ex = trim((string)$ex);
                if ($ex === '') continue;
                $like = '%' . $ex . '%';
                $del->execute([(int)$story['id'], $like, $like]);
                $purged += $del->rowCount();
            }
            echo "  purged: $purged stale matches\n";
        } catch (Throwable $e) {
            echo "  purge failed: " . $e->getMessage() . "\n";
        }
    }

    // Backfill from the keyword matcher.
    $added = evolving_story_backfill((int)$story['id'], $days);
    $story = evolving_story_get_by_id((int)$story['id']);
    echo "  added : $added new matches\n";
    echo "  total : " . ($story['article_count'] ?? '?') . " linked articles\n\n";
}

echo "Done. Clear the cache for changes to surface:\n";
echo "  rm -f storage/cache/home_evolving_rail_v3* storage/cache/evolving_stories_index_v2* storage/cache/api_home_v5* 2>/dev/null\n";
