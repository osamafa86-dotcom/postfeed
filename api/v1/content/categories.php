<?php
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:categories', 240, 60);

$db = getDB();
$rows = $db->query("SELECT id, name, slug, icon, css_class, sort_order
                    FROM categories WHERE is_active=1 ORDER BY sort_order, id")->fetchAll();
$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'   => (int)$r['id'],
        'name' => $r['name'],
        'slug' => $r['slug'],
        'icon' => $r['icon'],
        'color' => $r['css_class'],
        'sort_order' => (int)$r['sort_order'],
    ];
}
api_ok($out);
