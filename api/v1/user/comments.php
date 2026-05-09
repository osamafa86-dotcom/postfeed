<?php
/**
 * GET    /api/v1/user/comments?article_id=  — list comments for an article
 * POST   /api/v1/user/comments              — { article_id, body, parent_id? }
 * DELETE /api/v1/user/comments?id=          — delete OWN comment (soft delete)
 *
 * Comments by users blocked by the viewer, hidden by moderators,
 * or soft-deleted by the author are filtered out of GET results.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET', 'POST', 'DELETE');
$db = getDB();

// ─────────────────────────────────────────────────────────────
// GET — list comments
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    api_rate_limit('user:comments:read', 240, 60);
    $aid = (int)($_GET['article_id'] ?? 0);
    if (!$aid) api_err('invalid_input', 'يلزم article_id', 422);
    [$page, $limit, $offset] = api_pagination(30, 100);

    // If a viewer is authenticated, exclude comments by users they've blocked.
    $viewer = api_optional_user();
    $blockClause = '';
    $params = [$aid];
    if ($viewer) {
        $blockClause = " AND ac.user_id NOT IN (SELECT blocked_id FROM user_blocks WHERE blocker_id = ?)";
        $params[] = (int)$viewer['id'];
    }

    $sql = "SELECT ac.id, ac.user_id, ac.body, ac.parent_id, ac.likes, ac.created_at,
                   u.name AS user_name, u.username, u.avatar_letter
            FROM article_comments ac
            INNER JOIN users u ON u.id = ac.user_id
            WHERE ac.article_id = ?
              AND COALESCE(ac.is_hidden, 0) = 0
              AND COALESCE(ac.is_deleted, 0) = 0
              $blockClause
            ORDER BY ac.created_at DESC
            LIMIT $limit OFFSET $offset";

    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    api_ok(array_map(function ($c) {
        return [
            'id'         => (int)$c['id'],
            'body'       => $c['body'],
            'parent_id'  => $c['parent_id'] ? (int)$c['parent_id'] : null,
            'likes'      => (int)$c['likes'],
            'created_at' => $c['created_at'],
            'user_id'    => (int)$c['user_id'],
            'user_name'  => $c['user_name'],
            'user'       => [
                'id'            => (int)$c['user_id'],
                'name'          => $c['user_name'],
                'username'      => $c['username'],
                'avatar_letter' => $c['avatar_letter'],
            ],
        ];
    }, $rows), ['page' => $page, 'limit' => $limit]);
}

// ─────────────────────────────────────────────────────────────
// DELETE — author removes their own comment (soft delete)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    api_rate_limit('user:comments:delete', 60, 600);
    $user = api_require_user();
    $cid = (int)($_GET['id'] ?? 0);
    if (!$cid) api_err('invalid_input', 'يلزم id', 422);

    $st = $db->prepare("SELECT user_id, article_id FROM article_comments WHERE id = ? LIMIT 1");
    $st->execute([$cid]);
    $row = $st->fetch();
    if (!$row) api_err('not_found', 'التعليق غير موجود', 404);
    if ((int)$row['user_id'] !== (int)$user['id']) {
        api_err('forbidden', 'لا تستطيع حذف تعليق غيرك', 403);
    }

    $db->prepare("UPDATE article_comments SET is_deleted = 1, deleted_at = NOW() WHERE id = ?")
       ->execute([$cid]);
    try {
        $db->prepare("UPDATE articles SET comments = GREATEST(0, comments - 1) WHERE id = ?")
           ->execute([(int)$row['article_id']]);
    } catch (Throwable $e) {}

    api_ok(['deleted' => true, 'id' => $cid]);
}

// ─────────────────────────────────────────────────────────────
// POST — add a new comment
// ─────────────────────────────────────────────────────────────
api_rate_limit('user:comments:write', 30, 600);
$user = api_require_user();
$body = api_body();
$aid = (int)($body['article_id'] ?? 0);
$text = trim((string)($body['body'] ?? ''));
$parentId = isset($body['parent_id']) ? (int)$body['parent_id'] : null;

if (!$aid) api_err('invalid_input', 'يلزم article_id', 422);
if (mb_strlen($text) < 2) api_err('invalid_input', 'التعليق قصير جداً', 422);
if (mb_strlen($text) > 2000) api_err('invalid_input', 'التعليق طويل جداً', 422);

$db->prepare("INSERT INTO article_comments (article_id, user_id, body, parent_id, is_hidden, is_deleted, created_at)
              VALUES (?, ?, ?, ?, 0, 0, NOW())")
   ->execute([$aid, (int)$user['id'], $text, $parentId]);
$cid = (int)$db->lastInsertId();
try { $db->prepare("UPDATE articles SET comments = comments + 1 WHERE id = ?")->execute([$aid]); } catch (Throwable $e) {}

api_ok([
    'id'         => $cid,
    'body'       => $text,
    'parent_id'  => $parentId,
    'likes'      => 0,
    'created_at' => date('Y-m-d H:i:s'),
    'user_id'    => (int)$user['id'],
    'user_name'  => $user['name'],
    'user'       => [
        'id'            => (int)$user['id'],
        'name'          => $user['name'],
        'username'      => $user['username'] ?? null,
        'avatar_letter' => $user['avatar_letter'] ?? mb_substr($user['name'], 0, 1),
    ],
]);
