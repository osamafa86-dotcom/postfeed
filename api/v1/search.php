<?php
/**
 * GET /api/v1/search?q=... — full-text-ish search across published articles.
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET');
api_rate_limit('search', 30, 60);

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) api_error('invalid_input', 'أدخل كلمتين على الأقل', 400);
if (mb_strlen($q) > 100) $q = mb_substr($q, 0, 100);

$limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 50)) : 20;

try {
    $db = getDB();
    $sql = "SELECT
            a.id, a.title, a.slug, a.excerpt, a.image_url, a.source_url,
            a.published_at, a.view_count, a.comments, a.is_breaking, a.is_featured,
            c.id AS category_id, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, c.css_class AS category_class,
            s.id AS source_id, s.name AS source_name, s.logo_letter, s.logo_color, s.logo_bg, s.url AS source_site_url
        FROM articles a
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN sources s ON a.source_id = s.id
        WHERE a.status = 'published'
          AND (a.title LIKE ? OR a.excerpt LIKE ?)
        ORDER BY a.published_at DESC
        LIMIT ?";
    $like = '%' . $q . '%';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(1, $like, PDO::PARAM_STR);
    $stmt->bindValue(2, $like, PDO::PARAM_STR);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    api_json([
        'ok' => true,
        'query' => $q,
        'count' => count($rows),
        'items' => array_map(fn($r) => api_article_shape($r, false), $rows),
    ]);
} catch (Throwable $e) {
    error_log('v1/search: ' . $e->getMessage());
    api_error('server_error', 'تعذّر البحث', 500);
}
