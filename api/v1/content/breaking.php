<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:breaking', 240, 60);

[$page, $limit, $offset] = api_pagination(30, 50);
$items = fetch_articles(['breaking' => 1], $limit, $offset);
$total = count_articles(['breaking' => 1]);

api_ok($items, [
    'page' => $page, 'limit' => $limit, 'total' => $total,
    'has_more' => ($offset + count($items)) < $total,
]);
