<?php
/**
 * GET /api/v1/content/timeline?key=<cluster_key>
 *
 * Single story timeline detail. Previous version returned
 * { timeline: {...}, articles: [...] } (nested) but the Flutter
 * client reads keys at the root and expects `entries`, so the screen
 * always showed empty title / 0 articles / no entries. We also accessed
 * $tl['title'] / $tl['summary'] but the hydrator keys are `headline` /
 * `intro`. Both fixed below.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/story_timeline.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:timeline', 120, 60);

$key = trim((string)($_GET['key'] ?? $_GET['slug'] ?? ''));
if ($key === '') api_err('invalid_input', 'يلزم key', 422);

$tl = story_timeline_get($key);
if (!$tl) api_err('not_found', 'الجدول الزمني غير موجود', 404);

$articles = [];
try {
    $rows = story_timeline_fetch_articles($key, 200);
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    if ($ids) $articles = fetch_articles(['ids' => $ids], count($ids), 0);
} catch (Throwable $e) {
    error_log('timeline articles: ' . $e->getMessage());
}

// Convert article rows into the shape the app's _TimelineEntry expects.
$entries = array_map(function ($a) {
    return [
        'id'           => (int)($a['id'] ?? 0),
        'title'        => (string)($a['title'] ?? ''),
        'source_name'  => $a['source']['name'] ?? ($a['source_name'] ?? null),
        'image_url'    => api_image_url($a['image_url'] ?? null),
        'excerpt'      => $a['excerpt'] ?? null,
        'published_at' => $a['published_at'] ?? null,
    ];
}, $articles);

api_ok([
    'key'           => $key,
    'cluster_key'   => $key,
    'title'         => $tl['headline'] ?? '',
    'summary'       => $tl['intro'] ?? '',
    'narrative'     => $tl['narrative'] ?? null,
    'events'        => $tl['events'] ?? [],
    'topics'        => $tl['topics'] ?? [],
    'entities'      => $tl['entities'] ?? [],
    'article_count' => (int)($tl['article_count'] ?? count($entries)),
    'source_count'  => (int)($tl['source_count'] ?? 0),
    'updated_at'    => $tl['updated_at'] ?? null,
    'entries'       => $entries,
]);
