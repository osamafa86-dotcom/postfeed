<?php
/**
 * POST /api/v1/auth/register
 * Body: { "name": "...", "email": "...", "password": "...", "username": "?" }
 */

require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
api_rate_limit('auth.register', 5, 600); // 5 per 10 minutes per IP

$body = api_body();
$name = trim((string)($body['name'] ?? ''));
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$username = isset($body['username']) ? trim((string)$body['username']) : null;
$deviceName = isset($body['device_name']) ? mb_substr((string)$body['device_name'], 0, 120) : null;
$appVersion = isset($body['app_version']) ? mb_substr((string)$body['app_version'], 0, 32) : null;

[$ok, $result] = user_register($name, $email, $password, $username !== '' ? $username : null);
if (!$ok) {
    api_error('register_failed', (string)$result, 400);
}

$userId = (int)$result;
$raw = api_token_issue($userId, 'ios', $deviceName, $appVersion);

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, name, username, email, avatar_letter, bio, theme, role, reading_streak, notify_breaking, notify_followed, notify_digest, plan, created_at FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch() ?: [];
} catch (Throwable $e) {
    $profile = [];
}

api_json([
    'ok' => true,
    'token' => $raw,
    'token_type' => 'Bearer',
    'expires_in' => 365 * 24 * 3600,
    'user' => [
        'id' => (int)($profile['id'] ?? $userId),
        'name' => $profile['name'] ?? $name,
        'username' => $profile['username'] ?? null,
        'email' => $profile['email'] ?? $email,
        'avatar_letter' => $profile['avatar_letter'] ?? mb_substr($name, 0, 1),
        'bio' => $profile['bio'] ?? null,
        'theme' => $profile['theme'] ?? 'auto',
        'role' => $profile['role'] ?? 'reader',
        'reading_streak' => (int)($profile['reading_streak'] ?? 0),
        'notify_breaking' => (bool)($profile['notify_breaking'] ?? true),
        'notify_followed' => (bool)($profile['notify_followed'] ?? true),
        'notify_digest' => (bool)($profile['notify_digest'] ?? false),
        'plan' => $profile['plan'] ?? 'free',
        'created_at' => $profile['created_at'] ?? null,
    ],
], 201);
