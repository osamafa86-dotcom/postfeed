<?php
/**
 * نيوزفلو - API الأخبار
 * ======================
 * GET /api/articles.php?category=slug&breaking=1&limit=N
 * Returns JSON list of articles
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://postfeed.emdatra.org');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: public, max-age=300'); // 5 minute cache

require_once __DIR__ . '/../includes/config.php';

try {
    $db = getDB();

    // Build query based on parameters
    $where = ["a.status = 'published'"];
    $params = [];

    // Filter by category
    if (!empty($_GET['category'])) {
        $where[] = "c.slug = ?";
        $params[] = $_GET['category'];
    }

    // Filter by breaking news
    if (!empty($_GET['breaking'])) {
        $where[] = "a.is_breaking = 1";
    }

    // Set limit
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 100)) : 20;

    // Build SQL
    $sql = "SELECT
            a.id,
            a.title,
            a.excerpt,
            a.content,
            a.image_url,
            a.published_at,
            a.view_count,
            a.is_breaking,
            c.id as category_id,
            c.name as category_name,
            c.slug as category_slug,
            c.css_class as category_class,
            s.id as source_id,
            s.name as source_name,
            s.logo_letter,
            s.logo_color,
            s.logo_bg,
            s.url as source_url
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            LEFT JOIN sources s ON a.source_id = s.id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY a.published_at DESC
            LIMIT ?";

    $params[] = $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $articles = $stmt->fetchAll();

    // Format response
    $response = [
        'success' => true,
        'count' => count($articles),
        'limit' => $limit,
        'data' => array_map(function($article) {
            return [
                'id' => (int)$article['id'],
                'title' => $article['title'],
                'excerpt' => $article['excerpt'],
                'content' => $article['content'],
                'image_url' => $article['image_url'],
                'published_at' => $article['published_at'],
                'view_count' => (int)$article['view_count'],
                'is_breaking' => (bool)$article['is_breaking'],
                'category' => [
                    'id' => $article['category_id'] ? (int)$article['category_id'] : null,
                    'name' => $article['category_name'],
                    'slug' => $article['category_slug'],
                    'class' => $article['category_class']
                ],
                'source' => [
                    'id' => $article['source_id'] ? (int)$article['source_id'] : null,
                    'name' => $article['source_name'],
                    'logo_letter' => $article['logo_letter'],
                    'logo_color' => $article['logo_color'],
                    'logo_bg' => $article['logo_bg'],
                    'url' => $article['source_url']
                ]
            ];
        }, $articles)
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'خطأ في قاعدة البيانات',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
