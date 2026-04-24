<?php
/**
 * GET  /api/v1/user/me — fetch current user profile
 * POST /api/v1/user/me — update profile (name, bio, theme, notify_*)
 */

require_once __DIR__ . '/../_bootstrap.php';

api_method('GET', 'POST');
$uid = api_require_user();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    api_rate_limit('user.update', 30, 60);
    $body = api_body();
    $fields = [];
    $params = [];

    if (isset($body['name'])) {
        $name = trim((string)$body['name']);
        if (mb_strlen($name) < 2) api_error('invalid_input', 'الاسم قصير جداً');
        $fields[] = 'name = ?'; $params[] = $name;
        $fields[] = 'avatar_letter = ?'; $params[] = mb_substr($name, 0, 1);
    }
    if (isset($body['bio'])) {
        $fields[] = 'bio = ?'; $params[] = mb_substr((string)$body['bio'], 0, 500);
    }
    if (isset($body['theme']) && in_array($body['theme'], ['light','dark','auto'], true)) {
        $fields[] = 'theme = ?'; $params[] = $body['theme'];
    }
    foreach (['notify_breaking','notify_followed','notify_digest'] as $k) {
        if (isset($body[$k])) {
            $fields[] = "$k = ?";
            $params[] = !empty($body[$k]) ? 1 : 0;
        }
    }

    if (!empty($fields)) {
        $params[] = $uid;
        try {
            $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        } catch (Throwable $e) {
            error_log('v1/user/me update: ' . $e->getMessage());
            api_error('server_error', 'تعذّر تحديث الملف', 500);
        }
    }
}

try {
    $stmt = $db->prepare("SELECT id, name, username, email, avatar_letter, bio, theme, role, reading_streak, last_read_date, notify_breaking, notify_followed, notify_digest, plan, created_at FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    if (!$u) api_error('not_found', '', 404);

    $bmCount = 0; $folCatCount = 0; $folSrcCount = 0; $unread = 0;
    try {
        $bmCount = (int)$db->query("SELECT COUNT(*) FROM user_bookmarks WHERE user_id = " . (int)$uid)->fetchColumn();
        $folCatCount = (int)$db->query("SELECT COUNT(*) FROM user_category_follows WHERE user_id = " . (int)$uid)->fetchColumn();
        $folSrcCount = (int)$db->query("SELECT COUNT(*) FROM user_source_follows WHERE user_id = " . (int)$uid)->fetchColumn();
        $unread = (int)$db->query("SELECT COUNT(*) FROM user_notifications WHERE user_id = " . (int)$uid . " AND is_read = 0")->fetchColumn();
    } catch (Throwable $e) {}

    api_json([
        'ok' => true,
        'user' => [
            'id' => (int)$u['id'],
            'name' => $u['name'],
            'username' => $u['username'],
            'email' => $u['email'],
            'avatar_letter' => $u['avatar_letter'],
            'bio' => $u['bio'],
            'theme' => $u['theme'] ?? 'auto',
            'role' => $u['role'] ?? 'reader',
            'reading_streak' => (int)($u['reading_streak'] ?? 0),
            'last_read_date' => $u['last_read_date'] ?? null,
            'notify_breaking' => (bool)($u['notify_breaking'] ?? true),
            'notify_followed' => (bool)($u['notify_followed'] ?? true),
            'notify_digest' => (bool)($u['notify_digest'] ?? false),
            'plan' => $u['plan'] ?? 'free',
            'created_at' => $u['created_at'] ?? null,
        ],
        'stats' => [
            'bookmarks' => $bmCount,
            'followed_categories' => $folCatCount,
            'followed_sources' => $folSrcCount,
            'unread_notifications' => $unread,
        ],
    ]);
} catch (Throwable $e) {
    error_log('v1/user/me: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
