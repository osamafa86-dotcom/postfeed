<?php
/**
 * GET /api/v1/content/evolving-story?slug=...
 * Returns story header + paginated articles linked to it.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:evolving:story', 240, 60);

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') api_err('invalid_input', 'يلزم slug', 422);

$db = getDB();
$st = $db->prepare("SELECT id, name, slug, description, icon, cover_image, accent_color,
                           article_count, last_matched_at
                    FROM evolving_stories WHERE slug=? AND is_active=1 LIMIT 1");
$st->execute([$slug]);
$story = $st->fetch();
if (!$story) api_err('not_found', 'القصة غير موجودة', 404);

[$page, $limit, $offset] = api_pagination(20, 50);

$sql = articles_select_sql() .
       " INNER JOIN evolving_story_articles esa ON esa.article_id = a.id
         WHERE esa.story_id = ? AND a.status='published'
         ORDER BY a.published_at DESC LIMIT $limit OFFSET $offset";
$ps = $db->prepare($sql);
$ps->execute([(int)$story['id']]);
$rows = $ps->fetchAll();
$articles = array_map('api_format_article', $rows);

api_ok([
    'story' => [
        'id' => (int)$story['id'],
        'name' => $story['name'],
        'slug' => $story['slug'],
        'description' => $story['description'],
        'icon' => $story['icon'],
        'cover_image' => api_image_url($story['cover_image']),
        'accent_color' => $story['accent_color'],
        'article_count' => (int)$story['article_count'],
        'last_matched_at' => $story['last_matched_at'],
    ],
    'articles' => $articles,
], [
    'page' => $page, 'limit' => $limit,
    'total' => (int)$story['article_count'],
    'has_more' => ($offset + count($articles)) < (int)$story['article_count'],
]);
