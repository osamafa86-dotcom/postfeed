<?php
/**
 * GET /api/v1/content/timeline?key=<cluster_key>
 * Single story timeline detail.
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
} catch (Throwable $e) {}

api_ok([
    'timeline' => [
        'cluster_key' => $key,
        'title' => $tl['title'] ?? null,
        'summary' => $tl['summary'] ?? null,
        'events' => $tl['events'] ?? [],
        'topics' => $tl['topics'] ?? [],
        'entities' => $tl['entities'] ?? [],
        'article_count' => (int)($tl['article_count'] ?? 0),
        'source_count' => (int)($tl['source_count'] ?? 0),
        'updated_at' => $tl['updated_at'] ?? null,
    ],
    'articles' => $articles,
]);
