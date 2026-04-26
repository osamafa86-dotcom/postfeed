<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET');
api_rate_limit('content:search', 120, 60);

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) api_err('invalid_input', 'الكلمة المفتاحية قصيرة جداً', 422);

[$page, $limit, $offset] = api_pagination(20, 50);

$items = fetch_articles(['q' => $q], $limit, $offset);
$total = count_articles(['q' => $q]);

api_ok($items, [
    'q'      => $q,
    'page'   => $page,
    'limit'  => $limit,
    'total'  => $total,
    'has_more' => ($offset + count($items)) < $total,
]);
