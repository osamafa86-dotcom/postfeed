<?php
/**
 * GET /api/v1/palestine.php
 * Palestine news rail — keyword-based (mirrors includes/functions.php).
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET');
api_rate_limit('palestine', 120, 60);

$limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 50)) : 20;
$beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : 0;

$keywords = ['فلسطين','غزة','الضفة','القدس','الاحتلال','الفلسطيني','حماس','المقاومة','الأقصى','رفح','خان يونس','جنين','نابلس','طوفان','الشهداء','شهيد'];

try {
    $db = getDB();
    $clauses = [];
    $params = [];
    foreach ($keywords as $kw) {
        $clauses[] = 'a.title LIKE ?';
        $params[] = '%' . $kw . '%';
    }
    $beforeSql = $beforeId > 0 ? " AND a.id < " . $beforeId : '';
    $sql = "SELECT
            a.id, a.title, a.slug, a.excerpt, a.image_url, a.source_url,
            a.published_at, a.view_count, a.comments, a.is_breaking, a.is_featured,
            c.id AS category_id, c.name AS category_name, c.slug AS category_slug, c.icon AS category_icon, c.css_class AS category_class,
            s.id AS source_id, s.name AS source_name, s.logo_letter, s.logo_color, s.logo_bg, s.url AS source_site_url
        FROM articles a
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN sources s ON a.source_id = s.id
        WHERE a.status='published' AND (" . implode(' OR ', $clauses) . ") $beforeSql
        ORDER BY a.id DESC LIMIT ?";
    $stmt = $db->prepare($sql);
    $i = 1;
    foreach ($params as $p) $stmt->bindValue($i++, $p);
    $stmt->bindValue($i, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $items = array_map(fn($r) => api_article_shape($r, false), $rows);
    api_json([
        'ok' => true,
        'count' => count($items),
        'items' => $items,
        'next_cursor' => count($rows) === $limit ? (int)end($rows)['id'] : null,
    ]);
} catch (Throwable $e) {
    error_log('v1/palestine: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
