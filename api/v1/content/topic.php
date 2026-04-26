<?php
/**
 * Topic page = category landing with sub-buckets.
 * GET /api/v1/content/topic?slug=...
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:topic', 240, 60);

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') api_err('invalid_input', 'يلزم slug', 422);

$db = getDB();
$st = $db->prepare("SELECT id, name, slug, icon, css_class FROM categories WHERE slug=? AND is_active=1 LIMIT 1");
$st->execute([$slug]);
$cat = $st->fetch();
if (!$cat) api_err('not_found', 'القسم غير موجود', 404);

[$page, $limit, $offset] = api_pagination(20, 50);
$items = fetch_articles(['category_id' => (int)$cat['id']], $limit, $offset);
$total = count_articles(['category' => $slug]);

// Featured: top featured article in this category.
$featured = fetch_articles(['category_id' => (int)$cat['id'], 'featured' => 1], 1, 0);

api_ok([
    'category' => [
        'id' => (int)$cat['id'],
        'name' => $cat['name'],
        'slug' => $cat['slug'],
        'icon' => $cat['icon'],
        'color' => $cat['css_class'],
    ],
    'featured' => $featured[0] ?? null,
    'articles' => $items,
], [
    'page' => $page, 'limit' => $limit, 'total' => $total,
    'has_more' => ($offset + count($items)) < $total,
]);
