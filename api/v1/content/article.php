<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:article', 240, 60);

$id   = isset($_GET['id'])   ? (int)$_GET['id'] : 0;
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';

if (!$id && $slug === '') api_err('invalid_input', 'يلزم تمرير id أو slug', 422);

$db = getDB();
$where = $id ? 'a.id = ?' : 'a.slug = ?';
$param = $id ?: $slug;

$sql = articles_select_sql() . ", a.content
       FROM articles a
       LEFT JOIN categories c ON c.id = a.category_id
       LEFT JOIN sources    s ON s.id = a.source_id
       WHERE $where AND a.status = 'published' LIMIT 1";

// articles_select_sql() already starts with SELECT ... FROM articles a; rebuild precisely:
$sql = "SELECT
        a.id, a.title, a.slug, a.excerpt, a.content, a.image_url, a.source_url,
        a.is_breaking, a.is_featured, a.is_hero,
        a.view_count, a.comments, a.published_at, a.created_at,
        a.category_id, a.source_id,
        c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, c.css_class,
        s.name AS source_name, s.slug AS source_slug, s.logo_letter, s.logo_color, s.logo_bg, s.url AS source_site
      FROM articles a
      LEFT JOIN categories c ON c.id = a.category_id
      LEFT JOIN sources    s ON s.id = a.source_id
      WHERE $where AND a.status = 'published' LIMIT 1";

$st = $db->prepare($sql);
$st->execute([$param]);
$row = $st->fetch();
if (!$row) api_err('not_found', 'المقال غير موجود', 404);

// Increment view count (best-effort).
try {
    $db->prepare('UPDATE articles SET view_count = view_count + 1 WHERE id=?')->execute([(int)$row['id']]);
} catch (Throwable $e) {}

$article = api_format_article($row);
$article['content'] = $row['content'];

// Related articles by same category, excluding self.
$related = [];
if (!empty($row['category_id'])) {
    $rs = $db->prepare(articles_select_sql() . " WHERE a.status='published' AND a.category_id=? AND a.id<>? ORDER BY a.published_at DESC LIMIT 6");
    $rs->execute([(int)$row['category_id'], (int)$row['id']]);
    $related = array_map('api_format_article', $rs->fetchAll());
}

api_ok([
    'article' => $article,
    'related' => $related,
]);
