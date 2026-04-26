<?php
/**
 * GET /api/v1/content/evolving-stories
 * List all active evolving stories (admin-defined long-running topics).
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:evolving', 240, 60);

$db = getDB();
$rows = $db->query("SELECT id, name, slug, description, icon, cover_image, accent_color,
                           article_count, last_matched_at, sort_order
                    FROM evolving_stories WHERE is_active=1
                    ORDER BY sort_order ASC, id DESC LIMIT 50")->fetchAll();

$out = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'slug' => $r['slug'],
        'description' => $r['description'],
        'icon' => $r['icon'],
        'cover_image' => api_image_url($r['cover_image']),
        'accent_color' => $r['accent_color'],
        'article_count' => (int)$r['article_count'],
        'last_matched_at' => $r['last_matched_at'],
    ];
}, $rows);

api_ok($out);
