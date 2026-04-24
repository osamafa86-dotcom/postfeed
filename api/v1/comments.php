<?php
/**
 * GET  /api/v1/comments?article_id=123
 * POST /api/v1/comments  { article_id, body }
 * DELETE /api/v1/comments?id=...  (only the author, or on App Store review:
 *                                   MUST allow users to block/report — see report.php)
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET', 'POST', 'DELETE');
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    api_rate_limit('comments.list', 120, 60);
    $articleId = (int)($_GET['article_id'] ?? 0);
    if ($articleId <= 0) api_error('invalid_input', 'article_id');
    try {
        $stmt = $db->prepare("SELECT c.id, c.article_id, c.user_id, c.body, c.likes, c.created_at,
                                     u.name AS user_name, u.avatar_letter
                              FROM article_comments c
                              LEFT JOIN users u ON u.id = c.user_id
                              WHERE c.article_id = ?
                              ORDER BY c.id DESC LIMIT 100");
        $stmt->execute([$articleId]);
        $rows = $stmt->fetchAll();
        $items = array_map(fn($r) => [
            'id' => (int)$r['id'],
            'article_id' => (int)$r['article_id'],
            'user_id' => (int)$r['user_id'],
            'user_name' => $r['user_name'] ?? 'مستخدم',
            'avatar_letter' => $r['avatar_letter'] ?? 'م',
            'body' => $r['body'],
            'likes' => (int)$r['likes'],
            'created_at' => $r['created_at'],
        ], $rows);
        api_json(['ok' => true, 'count' => count($items), 'items' => $items]);
    } catch (Throwable $e) {
        error_log('v1/comments list: ' . $e->getMessage());
        api_error('server_error', '', 500);
    }
}

$uid = api_require_user();

if ($method === 'POST') {
    api_rate_limit('comments.add', 15, 300);
    $body = api_body();
    $articleId = (int)($body['article_id'] ?? 0);
    $text = trim((string)($body['body'] ?? ''));
    if ($articleId <= 0) api_error('invalid_input', 'article_id');
    if (mb_strlen($text) < 2)  api_error('invalid_input', 'التعليق قصير جداً');
    if (mb_strlen($text) > 1500) $text = mb_substr($text, 0, 1500);

    try {
        $stmt = $db->prepare("INSERT INTO article_comments (article_id, user_id, body) VALUES (?, ?, ?)");
        $stmt->execute([$articleId, $uid, $text]);
        $id = (int)$db->lastInsertId();
        $u = $db->prepare("SELECT name, avatar_letter FROM users WHERE id = ? LIMIT 1");
        $u->execute([$uid]);
        $row = $u->fetch() ?: [];
        try {
            $db->prepare("UPDATE articles SET comments = comments + 1 WHERE id = ?")->execute([$articleId]);
        } catch (Throwable $e) {}
        api_json([
            'ok' => true,
            'comment' => [
                'id' => $id,
                'article_id' => $articleId,
                'user_id' => $uid,
                'user_name' => $row['name'] ?? 'مستخدم',
                'avatar_letter' => $row['avatar_letter'] ?? 'م',
                'body' => $text,
                'likes' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ], 201);
    } catch (Throwable $e) {
        error_log('v1/comments add: ' . $e->getMessage());
        api_error('server_error', '', 500);
    }
}

if ($method === 'DELETE') {
    api_rate_limit('comments.delete', 30, 60);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) api_error('invalid_input', 'id');
    try {
        $stmt = $db->prepare("DELETE FROM article_comments WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $uid]);
        api_json(['ok' => true, 'deleted' => $stmt->rowCount() > 0]);
    } catch (Throwable $e) {
        error_log('v1/comments delete: ' . $e->getMessage());
        api_error('server_error', '', 500);
    }
}
