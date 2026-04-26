<?php
/**
 * GET /api/v1/media/reels?limit=
 * Returns active reels (Instagram embeds curated by editors).
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('media:reels', 240, 60);

[$page, $limit, $offset] = api_pagination(20, 100);

$db = getDB();
$rows = [];
try {
    $stmt = $db->prepare("SELECT r.id, r.instagram_url, r.shortcode, r.caption, r.thumbnail_url,
                                 r.created_at, r.source_id,
                                 s.username, s.display_name, s.avatar_url
                          FROM reels r
                          LEFT JOIN reels_sources s ON s.id = r.source_id
                          WHERE r.is_active=1
                          ORDER BY r.sort_order DESC, r.created_at DESC
                          LIMIT $limit OFFSET $offset");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('reels api: ' . $e->getMessage());
}

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id' => (int)$r['id'],
        'instagram_url' => $r['instagram_url'],
        'shortcode' => $r['shortcode'],
        'caption' => $r['caption'],
        'thumbnail_url' => api_image_url($r['thumbnail_url']),
        'created_at' => $r['created_at'],
        'source' => $r['source_id'] ? [
            'id' => (int)$r['source_id'],
            'username' => $r['username'],
            'display_name' => $r['display_name'],
            'avatar_url' => api_image_url($r['avatar_url']),
        ] : null,
    ];
}

api_ok($out, ['page' => $page, 'limit' => $limit]);
