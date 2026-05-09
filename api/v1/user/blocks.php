<?php
/**
 * GET    /api/v1/user/blocks               — list users I've blocked
 * POST   /api/v1/user/blocks               — { user_id } — block a user
 * DELETE /api/v1/user/blocks?user_id=      — unblock
 *
 * Required by Apple App Store Guideline 1.2 (UGC). When user A blocks
 * user B, comments by B are hidden from A's GET /user/comments
 * responses (filtered server-side in comments.php).
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET', 'POST', 'DELETE');

$user = api_require_user();
$db   = getDB();
$me   = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    api_rate_limit('user:blocks:read', 120, 60);
    $st = $db->prepare("SELECT b.blocked_id, b.created_at,
                               u.name AS user_name, u.username, u.avatar_letter
                        FROM user_blocks b
                        INNER JOIN users u ON u.id = b.blocked_id
                        WHERE b.blocker_id = ?
                        ORDER BY b.created_at DESC");
    $st->execute([$me]);
    $rows = $st->fetchAll();
    api_ok(array_map(function ($r) {
        return [
            'user_id'       => (int)$r['blocked_id'],
            'name'          => $r['user_name'],
            'username'      => $r['username'],
            'avatar_letter' => $r['avatar_letter'],
            'created_at'    => $r['created_at'],
        ];
    }, $rows));
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    api_rate_limit('user:blocks:write', 60, 600);
    $target = (int)($_GET['user_id'] ?? 0);
    if (!$target) api_err('invalid_input', 'يلزم user_id', 422);
    $db->prepare("DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?")
       ->execute([$me, $target]);
    api_ok(['blocked' => false, 'user_id' => $target]);
}

// POST — block
api_rate_limit('user:blocks:write', 60, 600);
$body = api_body();
$target = (int)($body['user_id'] ?? 0);

if (!$target) api_err('invalid_input', 'يلزم user_id', 422);
if ($target === $me) api_err('invalid_input', 'لا يمكنك حظر نفسك', 422);

// Verify the user exists.
$st = $db->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
$st->execute([$target]);
if (!$st->fetchColumn()) api_err('not_found', 'المستخدم غير موجود', 404);

// INSERT IGNORE — re-blocking the same user is a no-op.
try {
    $db->prepare("INSERT INTO user_blocks (blocker_id, blocked_id, created_at)
                  VALUES (?, ?, NOW())")
       ->execute([$me, $target]);
} catch (PDOException $e) {
    if ((int)$e->errorInfo[1] !== 1062) throw $e;
}

api_ok(['blocked' => true, 'user_id' => $target]);
