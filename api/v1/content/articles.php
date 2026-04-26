<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:articles', 240, 60);

[$page, $limit, $offset] = api_pagination(20, 50);

$filters = [
    'category'    => $_GET['category']    ?? null,
    'category_id' => $_GET['category_id'] ?? null,
    'source'      => $_GET['source']      ?? null,
    'source_id'   => $_GET['source_id']   ?? null,
    'breaking'    => $_GET['breaking']    ?? null,
    'featured'    => $_GET['featured']    ?? null,
    'hero'        => $_GET['hero']        ?? null,
    'since'       => $_GET['since']       ?? null,
    'until'       => $_GET['until']       ?? null,
    'q'           => isset($_GET['q']) ? trim((string)$_GET['q']) : null,
    'order'       => $_GET['order'] ?? 'published_at DESC',
];

$items = fetch_articles($filters, $limit, $offset);
$total = count_articles($filters);

api_ok($items, [
    'page'  => $page,
    'limit' => $limit,
    'total' => $total,
    'has_more' => ($offset + count($items)) < $total,
]);
