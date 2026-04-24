<?php
/**
 * GET    /api/v1/user/bookmarks — list current user's bookmarks
 * POST   /api/v1/user/bookmarks — toggle a bookmark { article_id }
 * DELETE /api/v1/user/bookmarks?article_id=123
 */

require_once __DIR__ . '/../_bootstrap.php';

api_method('GET', 'POST', 'DELETE');
$uid = api_require_user();
$db = getDB();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' || $method === 'DELETE') {
    api_rate_limit('bookmarks.toggle', 60, 60);
    $body = $method === 'POST' ? api_body() : $_GET;
    $articleId = (int)($body['article_id'] ?? 0);
    if ($articleId <= 0) api_error('invalid_input', 'article_id مطلوب');

    try {
        $exists = $db->prepare("SELECT 1 FROM user_bookmarks WHERE user_id = ? AND article_id = ? LIMIT 1");
        $exists->execute([$uid, $articleId]);
        $has = (bool)$exists->fetchColumn();

        if ($method === 'DELETE' || ($method === 'POST' && $has)) {
            $db->prepare("DELETE FROM user_bookmarks WHERE user_id = ? AND article_id = ?")->execute([$uid, $articleId]);
            $saved = false;
        } else {
            $db->prepare("INSERT IGNORE INTO user_bookmarks (user_id, article_id) VALUES (?, ?)")->execute([$uid, $articleId]);
            $saved = true;
        }
        api_json(['ok' => true, 'saved' => $saved, 'article_id' => $articleId]);
    } catch (Throwable $e) {
        error_log('v1/bookmarks toggle: ' . $e->getMessage());
        api_error('server_error', '', 500);
    }
}

// GET
$limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 50)) : 20;
$beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;

try {
    $sql = "SELECT
            ub.id AS bm_id, ub.created_at AS bm_at,
            a.id, a.title, a.slug, a.excerpt, a.image_url, a.source_url,
            a.published_at, a.view_count, a.comments, a.is_breaking, a.is_featured,
            c.id AS category_id, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, c.css_class AS category_class,
            s.id AS source_id, s.name AS source_name, s.logo_letter, s.logo_color, s.logo_bg, s.url AS source_site_url
        FROM user_bookmarks ub
        JOIN articles a ON a.id = ub.article_id
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN sources s ON a.source_id = s.id
        WHERE ub.user_id = ?
          AND a.status = 'published'
          " . ($beforeId > 0 ? ' AND ub.id < ' . $beforeId : '') . "
        ORDER BY ub.id DESC
        LIMIT ?";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $items = array_map(fn($r) => api_article_shape($r, false), $rows);
    $nextCursor = count($rows) === $limit ? (int)end($rows)['bm_id'] : null;

    api_json([
        'ok' => true,
        'count' => count($items),
        'items' => $items,
        'next_cursor' => $nextCursor,
    ]);
} catch (Throwable $e) {
    error_log('v1/bookmarks list: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
