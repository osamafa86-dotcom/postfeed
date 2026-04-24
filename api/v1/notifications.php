<?php
/**
 * GET  /api/v1/notifications — list notifications (most recent first)
 * POST /api/v1/notifications { action: "read", id?: 1 }  — mark one or all as read
 */

require_once __DIR__ . '/_bootstrap.php';

api_method('GET', 'POST');
$uid = api_require_user();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    api_rate_limit('notifications.write', 60, 60);
    $body = api_body();
    $action = (string)($body['action'] ?? 'read');
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    try {
        if ($action === 'read_all' || $id <= 0) {
            $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$uid]);
        } else {
            $db->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND id = ?")->execute([$uid, $id]);
        }
        api_json(['ok' => true]);
    } catch (Throwable $e) {
        error_log('v1/notifications write: ' . $e->getMessage());
        api_error('server_error', '', 500);
    }
}

// GET
api_rate_limit('notifications.list', 120, 60);
$limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 50)) : 30;
try {
    $stmt = $db->prepare("SELECT id, type, title, body, icon, article_id, is_read, created_at
                          FROM user_notifications
                          WHERE user_id = ?
                          ORDER BY id DESC
                          LIMIT ?");
    $stmt->bindValue(1, $uid, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $unread = (int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = " . (int)$uid . " AND is_read = 0")->fetchColumn();
    $items = array_map(fn($r) => [
        'id' => (int)$r['id'],
        'type' => $r['type'],
        'title' => $r['title'],
        'body' => $r['body'],
        'icon' => $r['icon'],
        'article_id' => $r['article_id'] ? (int)$r['article_id'] : null,
        'is_read' => (bool)(int)$r['is_read'],
        'created_at' => $r['created_at'],
    ], $rows);
    api_json(['ok' => true, 'unread' => $unread, 'items' => $items]);
} catch (Throwable $e) {
    error_log('v1/notifications list: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
