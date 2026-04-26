<?php
/**
 * GET  /api/v1/user/notifications        — paginated list
 * POST /api/v1/user/notifications/read   — { id? }   id omitted = mark all
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET', 'POST');
$user = api_require_user();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    [$page, $limit, $offset] = api_pagination(20, 100);
    $st = $db->prepare("SELECT id, type, title, body, icon, link, is_read, created_at
                        FROM user_notifications WHERE user_id=?
                        ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $st->execute([(int)$user['id']]);
    $rows = $st->fetchAll();

    $unreadSt = $db->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id=? AND is_read=0");
    $unreadSt->execute([(int)$user['id']]);
    $unread = (int)$unreadSt->fetchColumn();

    api_ok(array_map(function ($n) {
        return [
            'id' => (int)$n['id'],
            'type' => $n['type'],
            'title' => $n['title'],
            'body' => $n['body'],
            'icon' => $n['icon'],
            'link' => $n['link'] ?? null,
            'is_read' => (bool)$n['is_read'],
            'created_at' => $n['created_at'],
        ];
    }, $rows), [
        'page' => $page, 'limit' => $limit, 'unread' => $unread,
    ]);
}

$body = api_body();
if (!empty($body['id'])) {
    $db->prepare("UPDATE user_notifications SET is_read=1 WHERE user_id=? AND id=?")
       ->execute([(int)$user['id'], (int)$body['id']]);
} else {
    $db->prepare("UPDATE user_notifications SET is_read=1 WHERE user_id=?")
       ->execute([(int)$user['id']]);
}
api_ok(['marked_read' => true]);
