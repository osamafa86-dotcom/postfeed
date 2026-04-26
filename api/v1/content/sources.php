<?php
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:sources', 240, 60);

$db = getDB();
$rows = $db->query("SELECT id, name, slug, logo_letter, logo_color, logo_bg, url, articles_today
                    FROM sources WHERE is_active=1
                    ORDER BY articles_today DESC, name ASC")->fetchAll();

$out = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'slug' => $r['slug'],
        'logo_letter' => $r['logo_letter'],
        'logo_color' => $r['logo_color'],
        'logo_bg' => $r['logo_bg'],
        'url' => $r['url'],
        'articles_today' => (int)$r['articles_today'],
    ];
}, $rows);

api_ok($out);
