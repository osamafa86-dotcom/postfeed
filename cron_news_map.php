<?php
/**
 * نيوز فيد — News Map backfiller.
 *
 * For every published article without a location, run the
 * two-stage extractor (gazetteer first, AI only as fallback
 * when explicitly enabled). The gazetteer pass is free, so
 * this cron can run frequently without burning quota.
 *
 * Invocation:
 *   CLI:   php cron_news_map.php [--limit=200] [--ai] [--backfill-days=30]
 *   HTTP:  curl "…/cron_news_map.php?key=CRON_KEY[&limit=200][&ai=1][&days=30]"
 *
 * Recommended schedule:  every 30 minutes, gazetteer-only.
 *   (slash)30 (star) (star) (star) (star)  curl "…/cron_news_map.php?key=KEY"
 *   nightly AI sweep: 15 3 (star) (star) (star)  with ?ai=1&limit=80
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/news_map.php';
require_once __DIR__ . '/includes/news_map_extract.php';

if (PHP_SAPI !== 'cli') {
    $expected = getSetting('cron_key', '');
    if (!$expected || ($_GET['key'] ?? '') !== $expected) {
        http_response_code(403);
        exit('forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit(240);
$start = microtime(true);

// Flags.
$limit = (int)($_GET['limit'] ?? 200);
$days  = (int)($_GET['days']  ?? 30);
$useAi = !empty($_GET['ai']);
if (PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $a) {
        if (strpos($a, '--limit=') === 0) $limit = (int)substr($a, 8);
        if (strpos($a, '--backfill-days=') === 0) $days = (int)substr($a, 16);
        if ($a === '--ai') $useAi = true;
    }
}
$limit = max(1, min(1000, $limit));
$days  = max(1, min(180, $days));

nm_ensure_table();
$db = getDB();

// Pick published articles from the window that don't yet have a row
// in article_locations. LEFT JOIN + WHERE IS NULL is simpler than
// a NOT EXISTS subquery and scales fine here.
$sql = "SELECT a.id, a.title, a.excerpt
          FROM articles a
     LEFT JOIN article_locations l ON l.article_id = a.id
         WHERE a.status = 'published'
           AND a.published_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
           AND l.article_id IS NULL
      ORDER BY a.published_at DESC
         LIMIT :lim";
$stmt = $db->prepare($sql);
$stmt->bindValue(':days', $days, PDO::PARAM_INT);
$stmt->bindValue(':lim',  $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "articles to scan: " . count($rows) . " (AI " . ($useAi ? 'on' : 'off') . ")\n";

$hits = 0; $missed = 0; $byGaz = 0; $byAi = 0;
foreach ($rows as $r) {
    $id   = (int)$r['id'];
    $text = trim($r['title'] . ' ' . strip_tags((string)$r['excerpt']));
    if ($text === '') continue;

    $loc = nm_extract_location($text, $useAi);
    if ($loc) {
        nm_save_location($id, $loc);
        $hits++;
        if (($loc['by'] ?? '') === 'ai') $byAi++;
        else $byGaz++;
    } else {
        $missed++;
    }

    // Polite pacing only when we actually called the AI.
    if ($useAi && $loc && $loc['by'] === 'ai') usleep(200000);
}

$elapsed = round(microtime(true) - $start, 2);
echo "done: matched {$hits} (gazetteer {$byGaz}, AI {$byAi}) | missed {$missed} | {$elapsed}s\n";
