<?php
/**
 * GET /api/v1/content/evolving-story-quotes?slug=...
 * Returns notable quotes extracted from the story's articles.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:evolving:quotes', 120, 60);

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') api_err('invalid_input', 'يلزم slug', 422);

$db = getDB();
$st = $db->prepare("SELECT id, name, accent_color FROM evolving_stories WHERE slug=? AND is_active=1 LIMIT 1");
$st->execute([$slug]);
$story = $st->fetch();
if (!$story) api_err('not_found', 'القصة غير موجودة', 404);

[$page, $limit, $offset] = api_pagination(20, 100);

$rows = [];
try {
    $sql = "SELECT q.id, q.quote, q.speaker, q.context, q.article_id, q.created_at,
                   a.title AS article_title, a.slug AS article_slug, a.image_url
            FROM evolving_story_quotes q
            LEFT JOIN articles a ON a.id = q.article_id
            WHERE q.story_id=?
            ORDER BY q.created_at DESC LIMIT $limit OFFSET $offset";
    $ps = $db->prepare($sql);
    $ps->execute([(int)$story['id']]);
    $rows = $ps->fetchAll();
} catch (Throwable $e) {}

$quotes = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'quote' => $r['quote'],
        'speaker' => $r['speaker'],
        'context' => $r['context'] ?? null,
        'created_at' => $r['created_at'],
        'article' => $r['article_id'] ? [
            'id' => (int)$r['article_id'],
            'title' => $r['article_title'],
            'slug' => $r['article_slug'],
            'image_url' => api_image_url($r['image_url']),
        ] : null,
    ];
}, $rows);

api_ok([
    'story' => [
        'id' => (int)$story['id'],
        'name' => $story['name'],
        'slug' => $slug,
        'accent_color' => $story['accent_color'],
    ],
    'quotes' => $quotes,
]);
