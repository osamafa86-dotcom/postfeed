<?php
/**
 * Admin-only diagnostic for the article clustering pipeline.
 *
 * Reports:
 *   - whether the cluster_key column exists
 *   - how many rows are NULL / sentinel '-' / real
 *   - the top 20 clusters with ≥ 2 members (so you can verify the
 *     fuzzy matching is actually grouping anything)
 *   - a sample of titles for the largest cluster
 *
 * URL: /panel/cluster_diag.php (requires editor role)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/article_cluster.php';
requireRole('editor');

header('Content-Type: text/plain; charset=utf-8');
$db = getDB();

echo "=== Cluster Diagnostic ===\n\n";

// 1. Column existence
$col = $db->query("SHOW COLUMNS FROM articles LIKE 'cluster_key'")->fetch();
if (!$col) {
    echo "✗ cluster_key column MISSING — run /migrate.php?key=YOUR_CRON_KEY first.\n";
    exit;
}
echo "✓ cluster_key column exists ({$col['Type']})\n\n";

// 2. Population stats
$total   = (int)$db->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$nullCnt = (int)$db->query("SELECT COUNT(*) FROM articles WHERE cluster_key IS NULL")->fetchColumn();
$dashCnt = (int)$db->query("SELECT COUNT(*) FROM articles WHERE cluster_key = '-'")->fetchColumn();
$realCnt = $total - $nullCnt - $dashCnt;

echo "Total articles      : " . number_format($total) . "\n";
echo "  cluster_key NULL  : " . number_format($nullCnt) . " (need to re-run migrate.php to backfill)\n";
echo "  cluster_key '-'   : " . number_format($dashCnt) . " (titles too short to fingerprint — expected)\n";
echo "  cluster_key real  : " . number_format($realCnt) . "\n\n";

// 3. Top clusters
$top = $db->query("SELECT cluster_key, COUNT(*) AS c
                     FROM articles
                    WHERE status = 'published'
                      AND cluster_key IS NOT NULL
                      AND cluster_key <> '-'
                    GROUP BY cluster_key
                   HAVING c >= 2
                    ORDER BY c DESC
                    LIMIT 20")->fetchAll();

echo "Top clusters with ≥ 2 members:\n";
if (!$top) {
    echo "  (none — every story is currently a single-source cluster)\n";
    echo "  Possible causes:\n";
    echo "    - Backfill hasn't run yet — hit /migrate.php?key=...\n";
    echo "    - Re-cluster needed — hit /migrate.php?key=...&recluster=1\n";
    echo "    - Genuinely no duplicates yet (small DB)\n";
} else {
    foreach ($top as $i => $t) {
        printf("  %2d) %2d articles  %s…\n", $i + 1, (int)$t['c'], substr($t['cluster_key'], 0, 16));
    }
}

// 4. Sample titles for the biggest cluster
if ($top) {
    $bigKey = $top[0]['cluster_key'];
    echo "\nSample titles for the biggest cluster (" . substr($bigKey, 0, 16) . "…):\n";
    $samples = $db->prepare("SELECT a.id, a.title, a.published_at, s.name AS source_name
                               FROM articles a
                          LEFT JOIN sources s ON a.source_id = s.id
                              WHERE a.cluster_key = ?
                              ORDER BY a.published_at ASC
                              LIMIT 10");
    $samples->execute([$bigKey]);
    foreach ($samples->fetchAll() as $s) {
        echo "  · [{$s['source_name']}] {$s['title']}\n";
        echo "    {$s['published_at']}  →  /cluster/{$bigKey}\n";
    }
}

// 5. Sample of what would render on homepage right now
echo "\n--- Homepage sample (first 12 latest published) ---\n";
$latest = $db->query("SELECT id, title, cluster_key FROM articles
                       WHERE status = 'published'
                       ORDER BY published_at DESC
                       LIMIT 12")->fetchAll();
$keys = [];
foreach ($latest as $a) {
    $k = (string)($a['cluster_key'] ?? '');
    if ($k !== '' && $k !== '-') $keys[] = $k;
}
$counts = cluster_counts_for($keys);
foreach ($latest as $a) {
    $k = (string)($a['cluster_key'] ?? '');
    $c = $counts[$k] ?? 0;
    $badge = $c >= 2 ? "📰 $c مصادر" : "          ";
    printf("  %s  %s\n", $badge, mb_substr($a['title'], 0, 70));
}

echo "\nDone.\n";
