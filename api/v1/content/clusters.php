<?php
/**
 * GET /api/v1/content/clusters
 *
 * Article clusters — articles grouped by similarity. The app reads
 * `cluster_title` for the card headline; it was never sent. We also
 * tried `articles.cluster_id` but the real column is `cluster_key`,
 * so the primary path silently fell back to title-prefix grouping
 * (worse algorithm, also flagged `fallback: true` in meta).
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:clusters', 240, 60);

$db = getDB();
[$page, $limit, $offset] = api_pagination(20, 50);

// Probe which cluster column exists on this install. Prefer cluster_key
// (the current schema) and fall back to cluster_id (older installs).
$clusterCol = null;
try {
    if ($db->query("SHOW COLUMNS FROM articles LIKE 'cluster_key'")->fetch()) {
        $clusterCol = 'cluster_key';
    } elseif ($db->query("SHOW COLUMNS FROM articles LIKE 'cluster_id'")->fetch()) {
        $clusterCol = 'cluster_id';
    }
} catch (Throwable $e) {}

if ($clusterCol) {
    $sql = "SELECT a.{$clusterCol} AS ck, COUNT(*) AS cnt, MAX(a.published_at) AS last_at
            FROM articles a
            WHERE a.status='published' AND a.{$clusterCol} IS NOT NULL AND a.{$clusterCol} <> ''
            GROUP BY a.{$clusterCol} HAVING cnt >= 2
            ORDER BY last_at DESC LIMIT $limit OFFSET $offset";
    $rows = $db->query($sql)->fetchAll();
    $clusters = [];
    foreach ($rows as $r) {
        $ck = $r['ck'];
        $st = $db->prepare(articles_select_sql() . " WHERE a.{$clusterCol}=? AND a.status='published' ORDER BY a.published_at DESC LIMIT 8");
        $st->execute([$ck]);
        $items = array_map('api_format_article', $st->fetchAll());
        $clusters[] = [
            'id'            => is_numeric($ck) ? (int)$ck : 0,
            'key'           => (string)$ck,
            'cluster_title' => $items[0]['title'] ?? '',
            'count'         => (int)$r['cnt'],
            'last_at'       => $r['last_at'],
            'articles'      => $items,
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
        'id'            => $idx,
        'key'           => $key,
        'cluster_title' => $items[0]['title'] ?? $key,
        'count'         => count($items),
        'last_at'       => $items[0]['published_at'] ?? null,
        'articles'      => $items,
    ];
    if (count($clusters) >= $limit) break;
}

api_ok($clusters, ['page' => $page, 'limit' => $limit, 'fallback' => true]);
