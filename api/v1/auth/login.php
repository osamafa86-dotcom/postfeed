<?php
/**
 * POST /api/v1/auth/login
 * Body: { "email": "...", "password": "...", "device_name": "...", "app_version": "..." }
 * Returns: { ok, token, expires_at, user }
 */

require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
api_rate_limit('auth.login', 10, 60); // 10 attempts/min per IP

$body = api_body();
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$deviceName = isset($body['device_name']) ? mb_substr((string)$body['device_name'], 0, 120) : null;
$appVersion = isset($body['app_version']) ? mb_substr((string)$body['app_version'], 0, 32) : null;
$platform = strtolower((string)($body['platform'] ?? 'ios'));
if (!in_array($platform, ['ios', 'android', 'web'], true)) $platform = 'ios';

if ($email === '' || $password === '') {
    api_error('invalid_input', 'يرجى إدخال البريد وكلمة المرور', 400);
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, password, is_active, role FROM users WHERE email = ? AND role IN ('reader','viewer','editor','admin') LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($password, $u['password'])) {
        api_error('invalid_credentials', 'بيانات الدخول غير صحيحة', 401);
    }
    if (isset($u['is_active']) && (int)$u['is_active'] === 0) {
        api_error('account_disabled', 'الحساب معطّل', 403);
    }

    $userId = (int)$u['id'];
    $raw = api_token_issue($userId, $platform, $deviceName, $appVersion);

    try {
        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$userId]);
    } catch (Throwable $e) {}

    $stmt2 = $db->prepare("SELECT id, name, username, email, avatar_letter, bio, theme, role, reading_streak, notify_breaking, notify_followed, notify_digest, plan, created_at FROM users WHERE id = ? LIMIT 1");
    $stmt2->execute([$userId]);
    $profile = $stmt2->fetch() ?: [];

    api_json([
        'ok' => true,
        'token' => $raw,
        'token_type' => 'Bearer',
        'expires_in' => 365 * 24 * 3600,
        'user' => [
            'id' => (int)$profile['id'],
            'name' => $profile['name'],
            'username' => $profile['username'],
            'email' => $profile['email'],
            'avatar_letter' => $profile['avatar_letter'],
            'bio' => $profile['bio'],
            'theme' => $profile['theme'] ?? 'auto',
            'role' => $profile['role'] ?? 'reader',
            'reading_streak' => (int)($profile['reading_streak'] ?? 0),
            'notify_breaking' => (bool)($profile['notify_breaking'] ?? true),
            'notify_followed' => (bool)($profile['notify_followed'] ?? true),
            'notify_digest' => (bool)($profile['notify_digest'] ?? false),
            'plan' => $profile['plan'] ?? 'free',
            'created_at' => $profile['created_at'] ?? null,
        ],
    ]);
} catch (Throwable $e) {
    error_log('v1/auth/login: ' . $e->getMessage());
    api_error('server_error', 'حدث خطأ في النظام', 500);
}
