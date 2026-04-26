<?php
/**
 * GET /api/v1/content/evolving-story-book?slug=...
 * Long-form "book" view: chapters/sections compiled from the story.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:evolving:book', 60, 60);

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') api_err('invalid_input', 'يلزم slug', 422);

$db = getDB();
$st = $db->prepare("SELECT id, name, slug, description, icon, accent_color, cover_image
                    FROM evolving_stories WHERE slug=? AND is_active=1 LIMIT 1");
$st->execute([$slug]);
$story = $st->fetch();
if (!$story) api_err('not_found', 'القصة غير موجودة', 404);

// Group articles by month — each month is a "chapter".
$sql = articles_select_sql() .
       " INNER JOIN evolving_story_articles esa ON esa.article_id = a.id
         WHERE esa.story_id = ? AND a.status='published'
         ORDER BY a.published_at DESC LIMIT 500";
$ps = $db->prepare($sql);
$ps->execute([(int)$story['id']]);
$rows = $ps->fetchAll();

$chapters = [];
foreach ($rows as $r) {
    $month = substr((string)$r['published_at'], 0, 7);
    if (!isset($chapters[$month])) $chapters[$month] = [];
    $chapters[$month][] = api_format_article($r);
}
$out = [];
foreach ($chapters as $month => $arts) {
    $out[] = ['month' => $month, 'articles' => $arts];
}

api_ok([
    'story' => [
        'id' => (int)$story['id'],
        'name' => $story['name'],
        'slug' => $story['slug'],
        'description' => $story['description'],
        'icon' => $story['icon'],
        'cover_image' => api_image_url($story['cover_image']),
        'accent_color' => $story['accent_color'],
    ],
    'chapters' => $out,
]);
