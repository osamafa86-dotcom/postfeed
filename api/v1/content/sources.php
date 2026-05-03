<?php
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:sources', 240, 60);

$db = getDB();
$rows = $db->query("SELECT s.id, s.name, s.slug, s.logo_letter, s.logo_color, s.logo_bg, s.url, s.articles_today,
                           IFNULL(fc.cnt, 0) AS followers_count
                    FROM sources s
                    LEFT JOIN (SELECT source_id, COUNT(*) AS cnt FROM user_source_follows GROUP BY source_id) fc
                      ON fc.source_id = s.id
                    WHERE s.is_active=1
                    ORDER BY s.articles_today DESC, s.name ASC")->fetchAll();

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
        'followers_count' => (int)$r['followers_count'],
    ];
}, $rows);

api_ok($out);
