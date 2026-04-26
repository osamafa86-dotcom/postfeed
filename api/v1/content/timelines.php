<?php
/**
 * GET /api/v1/content/timelines
 * List of available story timelines (the website's /timelines page).
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/story_timeline.php';

api_method('GET');
api_rate_limit('content:timelines', 120, 60);

$limit = max(1, min((int)($_GET['limit'] ?? 30), 100));
$rows = story_timeline_list($limit);

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'cluster_key' => $r['cluster_key'] ?? null,
        'title' => $r['title'] ?? null,
        'summary' => $r['summary'] ?? null,
        'topics' => $r['topics'] ?? [],
        'entities' => $r['entities'] ?? [],
        'article_count' => (int)($r['article_count'] ?? 0),
        'source_count' => (int)($r['source_count'] ?? 0),
        'updated_at' => $r['updated_at'] ?? null,
    ];
}

api_ok($out);
