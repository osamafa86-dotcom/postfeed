<?php
/**
 * GET /api/v1/categories — list active categories with follow state if logged in.
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET');
api_rate_limit('categories', 60, 60);

try {
    $db = getDB();
    $stmt = $db->query("SELECT id, name, slug, icon, css_class, sort_order FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $rows = $stmt->fetchAll();

    $following = [];
    $uid = api_current_user_id();
    if ($uid) {
        $s = $db->prepare("SELECT category_id FROM user_category_follows WHERE user_id = ?");
        $s->execute([$uid]);
        $following = array_map('intval', array_column($s->fetchAll(), 'category_id'));
    }

    $items = array_map(function($r) use ($following) {
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'slug' => $r['slug'],
            'icon' => $r['icon'],
            'css_class' => $r['css_class'],
            'sort_order' => (int)$r['sort_order'],
            'following' => in_array((int)$r['id'], $following, true),
        ];
    }, $rows);

    api_json(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    error_log('v1/categories: ' . $e->getMessage());
    api_error('server_error', 'تعذّر جلب الفئات', 500);
}
