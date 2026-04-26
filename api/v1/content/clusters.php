<?php
/**
 * GET /api/v1/content/clusters
 * Article clusters — articles grouped by similarity. The website's
 * cluster.php groups articles via the article_cluster includes.
 *
 * We expose a lightweight "clusters today" view: groups of articles
 * sharing the same canonical cluster_id (if present) or the same
 * normalized title key (fallback).
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:clusters', 240, 60);

$db = getDB();
[$page, $limit, $offset] = api_pagination(20, 50);

// Detect whether a cluster_id column exists; if not, fall back.
$hasClusterCol = false;
try {
    $col = $db->query("SHOW COLUMNS FROM articles LIKE 'cluster_id'")->fetch();
    $hasClusterCol = (bool)$col;
} catch (Throwable $e) {}

if ($hasClusterCol) {
    $sql = "SELECT a.cluster_id, COUNT(*) AS cnt, MAX(a.published_at) AS last_at
            FROM articles a
            WHERE a.status='published' AND a.cluster_id IS NOT NULL
            GROUP BY a.cluster_id HAVING cnt >= 2
            ORDER BY last_at DESC LIMIT $limit OFFSET $offset";
    $rows = $db->query($sql)->fetchAll();
    $clusters = [];
    foreach ($rows as $r) {
        $cid = (int)$r['cluster_id'];
        $st = $db->prepare(articles_select_sql() . " WHERE a.cluster_id=? AND a.status='published' ORDER BY a.published_at DESC LIMIT 8");
        $st->execute([$cid]);
        $items = array_map('api_format_article', $st->fetchAll());
        $clusters[] = [
            'id' => $cid,
            'count' => (int)$r['cnt'],
            'last_at' => $r['last_at'],
            'articles' => $items,
        ];
    }
    api_ok($clusters, ['page' => $page, 'limit' => $limit]);
}

// Fallback: group recent articles by normalized title prefix.
$rows = $db->query("SELECT id, title, image_url, published_at FROM articles
                    WHERE status='published' ORDER BY published_at DESC LIMIT 200")->fetchAll();
$buckets = [];
foreach ($rows as $r) {
    $key = mb_substr(preg_replace('/\s+/u', ' ', (string)$r['title']), 0, 25);
    $buckets[$key][] = (int)$r['id'];
}
$clusters = [];
$idx = 0;
foreach ($buckets as $key => $ids) {
    if (count($ids) < 2) continue;
    $idx++;
    $items = fetch_articles(['ids' => $ids], 8, 0);
    if (!$items) continue;
    $clusters[] = [
        'id' => $idx,
        'key' => $key,
        'count' => count($items),
        'last_at' => $items[0]['published_at'] ?? null,
        'articles' => $items,
    ];
    if (count($clusters) >= $limit) break;
}

api_ok($clusters, ['page' => $page, 'limit' => $limit, 'fallback' => true]);
