<?php
/**
 * GET /api/v1/article?id=123
 * Returns full article with content, related articles, and user state
 * (bookmarked, reacted) when authenticated.
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET');
api_rate_limit('article.get', 240, 60);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) api_error('invalid_input', 'معرّف الخبر مطلوب', 400);

try {
    $db = getDB();
    $sql = "SELECT
            a.id, a.title, a.slug, a.excerpt, a.content, a.image_url, a.source_url,
            a.published_at, a.view_count, a.comments, a.is_breaking, a.is_featured,
            c.id  AS category_id,   c.name AS category_name,
            c.slug AS category_slug, c.icon AS category_icon, c.css_class AS category_class,
            s.id  AS source_id,     s.name AS source_name,
            s.logo_letter, s.logo_color, s.logo_bg, s.url AS source_site_url
        FROM articles a
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN sources    s ON a.source_id   = s.id
        WHERE a.id = ? AND a.status = 'published'
        LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) api_error('not_found', 'الخبر غير موجود', 404);

    try {
        $db->prepare("UPDATE articles SET view_count = view_count + 1 WHERE id = ?")->execute([$id]);
    } catch (Throwable $e) {}

    $data = api_article_shape($row, true);

    $userState = null;
    $uid = api_current_user_id();
    if ($uid) {
        try {
            $bm = $db->prepare("SELECT 1 FROM user_bookmarks WHERE user_id = ? AND article_id = ? LIMIT 1");
            $bm->execute([$uid, $id]);
            $rx = $db->prepare("SELECT reaction FROM article_reactions WHERE user_id = ? AND article_id = ? LIMIT 1");
            $rx->execute([$uid, $id]);
            $userState = [
                'bookmarked' => (bool)$bm->fetchColumn(),
                'reaction' => ($rx->fetchColumn() ?: null),
            ];
        } catch (Throwable $e) {}
    }

    $related = [];
    if (!empty($row['category_id'])) {
        try {
            $rel = $db->prepare("SELECT a.id, a.title, a.slug, a.excerpt, a.image_url, a.published_at, a.view_count, a.comments, a.is_breaking, a.is_featured,
                c.id AS category_id, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, c.css_class AS category_class,
                s.id AS source_id, s.name AS source_name, s.logo_letter, s.logo_color, s.logo_bg, s.url AS source_site_url
                FROM articles a
                LEFT JOIN categories c ON a.category_id = c.id
                LEFT JOIN sources s ON a.source_id = s.id
                WHERE a.category_id = ? AND a.id <> ? AND a.status = 'published'
                ORDER BY a.published_at DESC LIMIT 6");
            $rel->execute([(int)$row['category_id'], $id]);
            $related = array_map(fn($r) => api_article_shape($r, false), $rel->fetchAll());
        } catch (Throwable $e) {}
    }

    api_json([
        'ok' => true,
        'article' => $data,
        'related' => $related,
        'user_state' => $userState,
    ]);
} catch (Throwable $e) {
    error_log('v1/article: ' . $e->getMessage());
    api_error('server_error', 'تعذّر جلب الخبر', 500);
}
