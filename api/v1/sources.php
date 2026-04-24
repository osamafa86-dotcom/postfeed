<?php
/**
 * GET /api/v1/sources — list active news sources with follow state.
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET');
api_rate_limit('sources', 60, 60);

try {
    $db = getDB();
    $stmt = $db->query("SELECT id, name, slug, logo_letter, logo_color, logo_bg, url, articles_today FROM sources WHERE is_active = 1 ORDER BY articles_today DESC, name ASC");
    $rows = $stmt->fetchAll();

    $following = [];
    $uid = api_current_user_id();
    if ($uid) {
        $s = $db->prepare("SELECT source_id FROM user_source_follows WHERE user_id = ?");
        $s->execute([$uid]);
        $following = array_map('intval', array_column($s->fetchAll(), 'source_id'));
    }

    $items = array_map(function($r) use ($following) {
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'slug' => $r['slug'],
            'logo_letter' => $r['logo_letter'],
            'logo_color' => $r['logo_color'],
            'logo_bg' => $r['logo_bg'],
            'url' => $r['url'],
            'articles_today' => (int)$r['articles_today'],
            'following' => in_array((int)$r['id'], $following, true),
        ];
    }, $rows);

    api_json(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    error_log('v1/sources: ' . $e->getMessage());
    api_error('server_error', 'تعذّر جلب المصادر', 500);
}
