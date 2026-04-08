<?php
require __DIR__ . '/_json.php';

$userId = require_user_json();
rate_limit_json('notifications_api', 120, 60);

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        $limit = (int)($_GET['limit'] ?? 10);
        $items = user_notifications($userId, $limit);
        json_out([
            'ok'     => true,
            'unread' => user_unread_notifications_count($userId),
            'items'  => $items,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
    require_csrf_json();

    if ($action === 'read') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_out(['ok' => false, 'error' => 'bad_request'], 400);
        user_notification_mark_read($userId, $id);
        json_out(['ok' => true]);
    }
    if ($action === 'read_all') {
        user_notifications_mark_all_read($userId);
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'unknown_action'], 400);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'server'], 500);
}
