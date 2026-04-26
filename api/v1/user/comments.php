<?php
/**
 * GET  /api/v1/user/comments?article_id=  — list comments for an article
 * POST /api/v1/user/comments              — { article_id, body, parent_id? }
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET', 'POST');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    api_rate_limit('user:comments:read', 240, 60);
    $aid = (int)($_GET['article_id'] ?? 0);
    if (!$aid) api_err('invalid_input', 'يلزم article_id', 422);
    [$page, $limit, $offset] = api_pagination(30, 100);

    $st = $db->prepare("SELECT ac.id, ac.user_id, ac.body, ac.parent_id, ac.likes, ac.created_at,
                              u.name AS user_name, u.username, u.avatar_letter
                       FROM article_comments ac
                       INNER JOIN users u ON u.id = ac.user_id
                       WHERE ac.article_id=? AND ac.is_active=1
                       ORDER BY ac.created_at DESC LIMIT $limit OFFSET $offset");
    $st->execute([$aid]);
    $rows = $st->fetchAll();

    api_ok(array_map(function ($c) {
        return [
            'id' => (int)$c['id'],
            'body' => $c['body'],
            'parent_id' => $c['parent_id'] ? (int)$c['parent_id'] : null,
            'likes' => (int)$c['likes'],
            'created_at' => $c['created_at'],
            'user' => [
                'id' => (int)$c['user_id'],
                'name' => $c['user_name'],
                'username' => $c['username'],
                'avatar_letter' => $c['avatar_letter'],
            ],
        ];
    }, $rows), ['page' => $page, 'limit' => $limit]);
}

api_rate_limit('user:comments:write', 30, 600);
$user = api_require_user();
$body = api_body();
$aid = (int)($body['article_id'] ?? 0);
$text = trim((string)($body['body'] ?? ''));
$parentId = isset($body['parent_id']) ? (int)$body['parent_id'] : null;

if (!$aid) api_err('invalid_input', 'يلزم article_id', 422);
if (mb_strlen($text) < 2) api_err('invalid_input', 'التعليق قصير جداً', 422);
if (mb_strlen($text) > 2000) api_err('invalid_input', 'التعليق طويل جداً', 422);

$db->prepare("INSERT INTO article_comments (article_id, user_id, body, parent_id, is_active, created_at)
              VALUES (?,?,?,?,1,NOW())")
   ->execute([$aid, (int)$user['id'], $text, $parentId]);
$cid = (int)$db->lastInsertId();
try { $db->prepare("UPDATE articles SET comments = comments + 1 WHERE id=?")->execute([$aid]); } catch (Throwable $e) {}

api_ok([
    'id' => $cid,
    'body' => $text,
    'parent_id' => $parentId,
    'likes' => 0,
    'created_at' => date('Y-m-d H:i:s'),
    'user' => [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'username' => $user['username'] ?? null,
        'avatar_letter' => $user['avatar_letter'] ?? mb_substr($user['name'], 0, 1),
    ],
]);
