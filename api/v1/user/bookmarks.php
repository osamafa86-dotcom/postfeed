<?php
/**
 * GET  /api/v1/user/bookmarks            — list
 * POST /api/v1/user/bookmarks            — { article_id }   toggle
 * DELETE /api/v1/user/bookmarks?id=...   — remove
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../_articles_query.php';

api_method('GET', 'POST', 'DELETE');
$user = api_require_user();
$db = getDB();

$method = strtoupper($_SERVER['REQUEST_METHOD']);

if ($method === 'GET') {
    [$page, $limit, $offset] = api_pagination(20, 100);
    $sql = articles_select_sql() . "
        INNER JOIN user_bookmarks ub ON ub.article_id = a.id
        WHERE ub.user_id=? AND a.status='published'
        ORDER BY ub.created_at DESC LIMIT $limit OFFSET $offset";
    $st = $db->prepare($sql);
    $st->execute([(int)$user['id']]);
    $items = array_map('api_format_article', $st->fetchAll());

    $totalSt = $db->prepare("SELECT COUNT(*) FROM user_bookmarks WHERE user_id=?");
    $totalSt->execute([(int)$user['id']]);
    $total = (int)$totalSt->fetchColumn();

    api_ok($items, ['page' => $page, 'limit' => $limit, 'total' => $total]);
}

$body = api_body();
$articleId = (int)($body['article_id'] ?? $_GET['id'] ?? 0);
if (!$articleId) api_err('invalid_input', 'يلزم article_id', 422);

if ($method === 'DELETE') {
    $db->prepare("DELETE FROM user_bookmarks WHERE user_id=? AND article_id=?")
       ->execute([(int)$user['id'], $articleId]);
    api_ok(['removed' => true]);
}

// POST = toggle.
$st = $db->prepare("SELECT 1 FROM user_bookmarks WHERE user_id=? AND article_id=? LIMIT 1");
$st->execute([(int)$user['id'], $articleId]);
if ($st->fetchColumn()) {
    $db->prepare("DELETE FROM user_bookmarks WHERE user_id=? AND article_id=?")
       ->execute([(int)$user['id'], $articleId]);
    api_ok(['bookmarked' => false]);
}

$db->prepare("INSERT INTO user_bookmarks (user_id, article_id, created_at) VALUES (?,?,NOW())")
   ->execute([(int)$user['id'], $articleId]);
api_ok(['bookmarked' => true]);
