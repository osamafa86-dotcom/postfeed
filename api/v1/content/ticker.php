<?php
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET');
api_rate_limit('content:ticker', 240, 60);

$db = getDB();
$rows = [];
try {
    $rows = $db->query("SELECT id, text, link, sort_order FROM ticker_items WHERE is_active=1 ORDER BY sort_order, id LIMIT 30")->fetchAll();
} catch (Throwable $e) {}

$out = array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'text' => $r['text'],
        'link' => $r['link'] ?? null,
    ];
}, $rows);

api_ok($out);
