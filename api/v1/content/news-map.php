<?php
/**
 * GET /api/v1/content/news-map
 *
 * Geo-tagged articles. Previously queried a `news_map` table that
 * doesn't exist — the real table is `article_locations` with
 * `latitude/longitude/place_name_ar` (see includes/news_map.php).
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:newsmap', 120, 60);

$db = getDB();
$points = [];
try {
    $rows = $db->query("
        SELECT l.id, l.article_id, l.latitude, l.longitude,
               l.place_name_ar, l.place_name_en, l.confidence,
               a.title, a.slug, a.image_url, a.published_at
        FROM article_locations l
        INNER JOIN articles a ON a.id = l.article_id AND a.status='published'
        ORDER BY a.published_at DESC LIMIT 500
    ")->fetchAll();
    foreach ($rows as $r) {
        $points[] = [
            'id'    => (int)$r['id'],
            'place' => $r['place_name_ar'] !== '' ? $r['place_name_ar'] : ($r['place_name_en'] ?? ''),
            'lat'   => (float)$r['latitude'],
            'lng'   => (float)$r['longitude'],
            'confidence' => (float)($r['confidence'] ?? 0),
            'article' => [
                'id'           => (int)$r['article_id'],
                'title'        => $r['title'],
                'slug'         => $r['slug'],
                'image_url'    => api_image_url($r['image_url']),
                'published_at' => $r['published_at'],
            ],
        ];
    }
} catch (Throwable $e) {
    error_log('news-map: ' . $e->getMessage());
}

api_ok([
    'points' => $points,
    'count'  => count($points),
]);
