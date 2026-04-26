<?php
/**
 * GET    /api/v1/user/profile  — full profile
 * PATCH  /api/v1/user/profile  — update fields
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET', 'PATCH');
$user = api_require_user();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    api_ok(['user' => api_user_public($user)]);
}

$body = api_body();
$db = getDB();

$updates = [];
$params = [];

if (isset($body['name'])) {
    $name = trim((string)$body['name']);
    if (mb_strlen($name) < 2) api_err('invalid_input', 'الاسم قصير جداً', 422);
    $updates[] = 'name = ?'; $params[] = $name;
    $updates[] = 'avatar_letter = ?'; $params[] = mb_substr($name, 0, 1);
}
if (isset($body['username'])) {
    $u = trim((string)$body['username']);
    if ($u !== '' && !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $u)) {
        api_err('invalid_input', 'اسم المستخدم غير صالح', 422);
    }
    if ($u !== '') {
        $st = $db->prepare("SELECT id FROM users WHERE username=? AND id<>?");
        $st->execute([$u, (int)$user['id']]);
        if ($st->fetchColumn()) api_err('username_taken', 'اسم المستخدم محجوز', 422);
    }
    $updates[] = 'username = ?'; $params[] = $u !== '' ? $u : null;
}
if (isset($body['bio'])) {
    $bio = trim((string)$body['bio']);
    if (mb_strlen($bio) > 500) api_err('invalid_input', 'النبذة طويلة جداً', 422);
    $updates[] = 'bio = ?'; $params[] = $bio;
}
if (isset($body['theme'])) {
    $t = (string)$body['theme'];
    if (!in_array($t, ['light', 'dark', 'auto'], true)) api_err('invalid_input', 'سمة غير صالحة', 422);
    $updates[] = 'theme = ?'; $params[] = $t;
}
foreach (['notify_breaking', 'notify_followed', 'notify_digest'] as $k) {
    if (array_key_exists($k, $body)) {
        $updates[] = "$k = ?"; $params[] = (int)(bool)$body[$k];
    }
}

if (!$updates) api_err('invalid_input', 'لا يوجد ما يُحدّث', 422);

$params[] = (int)$user['id'];
$db->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id=?")->execute($params);

$st = $db->prepare("SELECT id, name, username, email, avatar_letter, bio, theme, role, plan, reading_streak, last_read_date, notify_breaking, notify_followed, notify_digest, created_at FROM users WHERE id=?");
$st->execute([(int)$user['id']]);
$fresh = $st->fetch();

api_ok(['user' => api_user_public($fresh)]);
