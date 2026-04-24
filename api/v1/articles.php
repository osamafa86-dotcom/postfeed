<?php
/**
 * GET /api/v1/articles
 *
 * Paginated news feed. Supports cursor-based pagination via `before_id`.
 * Query params: category (slug), source (slug), breaking (0/1),
 *               q (search), limit (1..50, default 20), before_id (int).
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET');
api_rate_limit('articles.list', 240, 60);

try {
    $db = getDB();

    $where = ["a.status = 'published'"];
    $params = [];

    if (!empty($_GET['category'])) {
        $where[] = "c.slug = ?";
        $params[] = (string)$_GET['category'];
    }
    if (!empty($_GET['source'])) {
        $where[] = "s.slug = ?";
        $params[] = (string)$_GET['source'];
    }
    if (!empty($_GET['breaking'])) {
        $where[] = "a.is_breaking = 1";
    }
    if (!empty($_GET['q'])) {
        $q = trim((string)$_GET['q']);
        if (mb_strlen($q) >= 2) {
            $where[] = "(a.title LIKE ? OR a.excerpt LIKE ?)";
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
    }
    if (!empty($_GET['before_id'])) {
        $where[] = "a.id < ?";
        $params[] = (int)$_GET['before_id'];
    }

    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 50)) : 20;
    $params[] = $limit;

    $sql = "SELECT
            a.id, a.title, a.slug, a.excerpt, a.image_url, a.source_url,
            a.published_at, a.view_count, a.comments, a.is_breaking, a.is_featured,
            c.id  AS category_id,   c.name AS category_name,
            c.slug AS category_slug, c.icon AS category_icon, c.css_class AS category_class,
            s.id  AS source_id,     s.name AS source_name,
            s.logo_letter, s.logo_color, s.logo_bg, s.url AS source_site_url
        FROM articles a
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN sources    s ON a.source_id   = s.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.id DESC
        LIMIT ?";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $items = array_map(fn($r) => api_article_shape($r, false), $rows);
    $nextCursor = count($items) === $limit ? (int)end($rows)['id'] : null;

    api_json([
        'ok' => true,
        'count' => count($items),
        'items' => $items,
        'next_cursor' => $nextCursor,
    ]);
} catch (Throwable $e) {
    error_log('v1/articles: ' . $e->getMessage());
    api_error('server_error', 'تعذّر جلب الأخبار', 500);
}
