<?php
/**
 * Map feed — GeoJSON endpoint for /map.
 *
 * Returns all located articles within the requested window
 * as a GeoJSON FeatureCollection so Leaflet can consume it
 * directly via L.geoJSON(). Cached briefly because it's hit
 * on every map load.
 *
 * Query params:
 *   days  — 1..90, default 7
 *   limit — 1..2000, default 500
 *   cat   — category slug to filter (optional)
 *   breaking — "1" to restrict to breaking stories only
 */

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/news_map.php';
require_once __DIR__ . '/includes/cache.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120');

$days     = max(1, min(90,   (int)($_GET['days']  ?? 7)));
$limit    = max(1, min(2000, (int)($_GET['limit'] ?? 500)));
$catSlug  = trim((string)($_GET['cat'] ?? ''));
$breaking = !empty($_GET['breaking']);

$cacheKey = 'map_feed_' . md5($days . '|' . $limit . '|' . $catSlug . '|' . ($breaking ? 1 : 0));

$payload = cache_remember($cacheKey, 60, function() use ($days, $limit, $catSlug, $breaking) {
    $rows = nm_recent_locations($days, $limit);

    // Post-filter (keeps the nm_recent_locations query simple and
    // reusable; dataset is small enough that PHP filtering is fine).
    if ($catSlug !== '' || $breaking) {
        $rows = array_values(array_filter($rows, function($r) use ($catSlug, $breaking) {
            if ($breaking && empty($r['is_breaking'])) return false;
            if ($catSlug !== '' && (string)($r['cat_slug'] ?? '') !== $catSlug) return false;
            return true;
        }));
    }

    $features = [];
    foreach ($rows as $r) {
        $article = ['id' => (int)$r['id'], 'slug' => (string)$r['slug']];
        $features[] = [
            'type'       => 'Feature',
            'geometry'   => [
                'type'        => 'Point',
                'coordinates' => [ (float)$r['longitude'], (float)$r['latitude'] ],
            ],
            'properties' => [
                'id'            => (int)$r['id'],
                'title'         => (string)$r['title'],
                'url'           => '/' . articleUrl($article),
                'image_url'     => (string)$r['image_url'],
                'published_at'  => (string)$r['published_at'],
                'time_ago'      => timeAgo($r['published_at']),
                'is_breaking'   => !empty($r['is_breaking']),
                'cat_name'      => (string)($r['cat_name'] ?? ''),
                'cat_slug'      => (string)($r['cat_slug'] ?? ''),
                'source_name'   => (string)($r['source_name'] ?? ''),
                'place_ar'      => (string)$r['place_name_ar'],
                'place_en'      => (string)$r['place_name_en'],
                'country'       => (string)$r['country_code'],
                'region'        => (string)($r['admin_region'] ?? ''),
            ],
        ];
    }

    return [
        'type'     => 'FeatureCollection',
        'features' => $features,
        'meta'     => [
            'generated_at' => date('c'),
            'window_days'  => $days,
            'count'        => count($features),
            'filters'      => ['cat' => $catSlug, 'breaking' => $breaking],
        ],
    ];
});

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
