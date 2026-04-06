<?php
/**
 * نيوزفلو - API البحث
 * ===================
 * GET /api/search.php?q=search_term
 * Search articles by title and excerpt
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://postfeed.emdatra.org');
header('Access-Control-Allow-Methods: GET, OPTIONS');

require_once __DIR__ . '/../includes/config.php';

try {
    $db = getDB();

    // Get search query
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';

    if (empty($query) || strlen($query) < 2) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'يجب إدخال كلمة بحث (حد أدنى حرفين)',
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Limit search term length to prevent abuse
    $query = substr($query, 0, 100);

    // Prepare search term with wildcards (safe parameter binding)
    $searchTerm = '%' . trim($query) . '%';

    // Search articles
    $sql = "SELECT
            a.id,
            a.title,
            a.excerpt,
            a.image_url,
            a.published_at,
            a.view_count,
            c.name as category_name,
            c.slug as category_slug,
            s.name as source_name
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            LEFT JOIN sources s ON a.source_id = s.id
            WHERE a.status = 'published' AND (a.title LIKE ? OR a.excerpt LIKE ?)
            ORDER BY a.published_at DESC
            LIMIT 20";

    $stmt = $db->prepare($sql);
    $stmt->execute([$searchTerm, $searchTerm]);
    $articles = $stmt->fetchAll();

    // Format response
    $response = [
        'success' => true,
        'query' => htmlspecialchars($query, ENT_QUOTES, 'UTF-8'),
        'count' => count($articles),
        'data' => array_map(function($article) {
            return [
                'id' => (int)$article['id'],
                'title' => $article['title'],
                'excerpt' => $article['excerpt'],
                'image_url' => $article['image_url'],
                'published_at' => $article['published_at'],
                'view_count' => (int)$article['view_count'],
                'category' => [
                    'name' => $article['category_name'],
                    'slug' => $article['category_slug']
                ],
                'source' => $article['source_name']
            ];
        }, $articles)
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'خطأ في البحث',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
