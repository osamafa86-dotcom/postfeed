<?php
/**
 * نيوز فيد - API الأكثر تداولاً (Trending Now)
 * ============================================
 * GET /api/trending.php?limit=N
 * Returns the velocity-scored "hottest stories right now" rail.
 *
 * The heavy aggregation is cached for 90s by trending_get_top()
 * itself, so this endpoint is essentially free under burst load —
 * which lets the homepage refresh the strip in-place every couple
 * of minutes without hammering the DB.
 *
 * Response shape:
 *   { ok: true, count, limit, readers_now, items: [...] }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://postfeed.emdatra.org');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Cache-Control: public, max-age=60');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/trending.php';

// Reasonable burst protection — same envelope as /api/articles.php.
rate_limit_enforce_api('trending:' . client_ip(), 120, 60);

try {
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 50)) : 8;
    $rows  = trending_get_top($limit);

    $items = array_map(function($r) {
        $ck = (string)($r['cluster_key'] ?? '');
        $hasCluster = ($ck !== '' && $ck !== '-' && (int)$r['cluster_size'] > 1);
        return [
            'id'              => (int)$r['id'],
            'title'           => $r['title'],
            'image_url'       => $r['image_url'],
            'published_at'    => $r['published_at'],
            'category'        => $r['cat_name'],
            'category_slug'   => $r['cat_slug'],
            'source'          => $r['source_name'],
            'velocity_score'  => (int)$r['velocity_score'],
            'views_last_hour' => (int)$r['views_last_hour'],
            'views_last_6h'   => (int)$r['views_last_6h'],
            'cluster_key'     => $hasCluster ? $ck : null,
            'cluster_size'    => $hasCluster ? (int)$r['cluster_size'] : 1,
            'url'             => $hasCluster
                ? (SITE_URL . '/cluster.php?key=' . urlencode($ck))
                : (SITE_URL . '/' . articleUrl($r)),
        ];
    }, $rows);

    echo json_encode([
        'ok'           => true,
        'count'        => count($items),
        'limit'        => $limit,
        'readers_now'  => trending_active_readers(),
        'generated_at' => date('c'),
        'items'        => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'server_error',
    ], JSON_UNESCAPED_UNICODE);
}
