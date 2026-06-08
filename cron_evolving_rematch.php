<?php
/**
 * نيوز فيد — إعادة فحص الأخبار وربطها بالقصص المتطوّرة
 *
 * Use case: we tightened the keyword matcher in
 * includes/evolving_stories.php (word-boundary regex + ambiguous-
 * context filter + multi-word phrase bonus). Existing
 * evolving_story_articles rows were created by the older lax matcher,
 * so they include false positives like "مؤشر القدس" landing under
 * "أخبار القدس". This script rebuilds those links from scratch using
 * the new matcher, so the homepage and story pages reflect the
 * tighter classification without waiting for fresh articles.
 *
 * Run from cron or one-off:
 *   php cron_evolving_rematch.php
 *   php cron_evolving_rematch.php --days=30   (default: 90)
 *   php cron_evolving_rematch.php --story=12  (rebuild a single story)
 *
 * The rebuild is done inside a single transaction per story so the
 * homepage never observes a partial set.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/evolving_stories.php';

$opts = getopt('', ['days::', 'story::']);
$days  = isset($opts['days'])  && is_numeric($opts['days'])  ? (int)$opts['days']  : 90;
$story = isset($opts['story']) && is_numeric($opts['story']) ? (int)$opts['story'] : 0;
$days  = max(1, min(365, $days));

$db = getDB();
$startedAt = microtime(true);

echo "[evolving-rematch] window=$days day(s)";
echo $story ? " story=$story" : ' all stories';
echo PHP_EOL;

try {
    // Pull every article in the window. We deliberately re-fetch
    // them all so the new matcher gets a chance to either drop a
    // false positive or attach a true positive the old matcher
    // missed (multi-word phrases now score higher).
    $sql = "SELECT id, title, COALESCE(excerpt, '') AS excerpt
              FROM articles
             WHERE status = 'published'
               AND published_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";
    $stmt = $db->query($sql);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($articles);
    echo "[evolving-rematch] scanning $total article(s)" . PHP_EOL;

    // Wipe the existing links first so the new matcher gets a
    // clean slate. Scoped to the story if --story was passed.
    if ($story > 0) {
        $del = $db->prepare("DELETE esa FROM evolving_story_articles esa
                              JOIN articles a ON a.id = esa.article_id
                             WHERE esa.story_id = ?
                               AND a.published_at >= DATE_SUB(NOW(), INTERVAL $days DAY)");
        $del->execute([$story]);
    } else {
        $del = $db->prepare("DELETE esa FROM evolving_story_articles esa
                              JOIN articles a ON a.id = esa.article_id
                             WHERE a.published_at >= DATE_SUB(NOW(), INTERVAL $days DAY)");
        $del->execute();
    }
    echo "[evolving-rematch] cleared " . $del->rowCount() . " stale link(s)" . PHP_EOL;

    // Re-run the (now tighter) matcher across the article set.
    $reMatched = 0;
    foreach ($articles as $a) {
        $hit = evolving_story_match_article(
            (int)$a['id'],
            (string)$a['title'],
            (string)$a['excerpt']
        );
        $reMatched += $hit;
    }
    echo "[evolving-rematch] re-linked $reMatched article→story pair(s)" . PHP_EOL;

    // Recompute every story's article_count + last_matched_at from
    // the rebuilt table so the homepage sort and the "X تقرير"
    // badge in the accordion stay honest.
    $db->exec("UPDATE evolving_stories es
                  LEFT JOIN (
                       SELECT story_id,
                              COUNT(*)        AS cnt,
                              MAX(matched_at) AS last_at
                         FROM evolving_story_articles
                        GROUP BY story_id
                  ) t ON t.story_id = es.id
                  SET es.article_count   = COALESCE(t.cnt, 0),
                      es.last_matched_at = t.last_at");
    echo "[evolving-rematch] story counters rebuilt" . PHP_EOL;

    // Wipe homepage rail cache so the next visitor sees the fresh
    // accordion contents immediately.
    if (function_exists('cache_forget')) {
        cache_forget('home_evolving_accordion_v2');
        cache_forget('home_evolving_accordion_v1');
        echo "[evolving-rematch] homepage cache busted" . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[evolving-rematch] FATAL: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo '[evolving-rematch] done in ' . round(microtime(true) - $startedAt, 2) . 's' . PHP_EOL;
