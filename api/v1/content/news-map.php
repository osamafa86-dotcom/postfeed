<?php
/**
 * GET /api/v1/content/news-map
 * Geo-tagged articles for the interactive news map.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:newsmap', 120, 60);

$db = getDB();
$points = [];
try {
    // The website stores extracted locations in news_map (or articles_geo).
    // Try the canonical news_map table first.
    $col = $db->query("SHOW TABLES LIKE 'news_map'")->fetch();
    if ($col) {
        $rows = $db->query("
            SELECT nm.id, nm.article_id, nm.place_name, nm.lat, nm.lng, nm.confidence,
                   a.title, a.slug, a.image_url, a.published_at
            FROM news_map nm
            INNER JOIN articles a ON a.id = nm.article_id AND a.status='published'
            WHERE nm.lat IS NOT NULL AND nm.lng IS NOT NULL
            ORDER BY a.published_at DESC LIMIT 500
        ")->fetchAll();
        foreach ($rows as $r) {
            $points[] = [
                'id' => (int)$r['id'],
                'place' => $r['place_name'],
                'lat' => (float)$r['lat'],
                'lng' => (float)$r['lng'],
                'confidence' => (float)($r['confidence'] ?? 0),
                'article' => [
                    'id' => (int)$r['article_id'],
                    'title' => $r['title'],
                    'slug' => $r['slug'],
                    'image_url' => api_image_url($r['image_url']),
                    'published_at' => $r['published_at'],
                ],
            ];
        }
    }
} catch (Throwable $e) {
    error_log('news-map: ' . $e->getMessage());
}

api_ok([
    'points' => $points,
    'count' => count($points),
]);
