<?php
/**
 * GET /api/v1/reels.php — Instagram reels imported into the site.
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET');
api_rate_limit('reels', 120, 60);

$limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 30)) : 12;
$sourceId = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;

try {
    $db = getDB();
    $where = ['r.is_active = 1'];
    $params = [];
    if ($sourceId > 0) { $where[] = 'r.source_id = ?'; $params[] = $sourceId; }

    $items = [];
    try {
        $sql = "SELECT r.id, r.shortcode, r.caption, r.instagram_url, r.created_at,
                       rs.id AS src_id, rs.display_name, rs.username, rs.avatar_url
                FROM reels r
                LEFT JOIN reels_sources rs ON rs.id = r.source_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY r.sort_order ASC, r.id DESC
                LIMIT ?";
        $stmt = $db->prepare($sql);
        $i = 1;
        foreach ($params as $p) $stmt->bindValue($i++, $p);
        $stmt->bindValue($i, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $items = array_map(fn($r) => [
            'id' => (int)$r['id'],
            'shortcode' => $r['shortcode'],
            'caption' => $r['caption'],
            'instagram_url' => $r['instagram_url'] ?: ('https://www.instagram.com/reel/' . $r['shortcode'] . '/'),
            'embed_url' => 'https://www.instagram.com/reel/' . $r['shortcode'] . '/embed/',
            'thumbnail_url' => null, // Instagram doesn't expose a thumbnail without scraping
            'created_at' => $r['created_at'],
            'source' => $r['src_id'] ? [
                'id' => (int)$r['src_id'],
                'name' => $r['display_name'],
                'username' => $r['username'],
                'avatar_url' => $r['avatar_url'],
            ] : null,
        ], $rows);
    } catch (Throwable $e) { /* table not deployed */ }

    api_json(['ok' => true, 'count' => count($items), 'items' => $items]);
} catch (Throwable $e) {
    error_log('v1/reels: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
