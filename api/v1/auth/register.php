<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/user_auth.php';

api_method('POST');
api_rate_limit('auth:register', 10, 600);

$body = api_body();
$name = trim((string)($body['name'] ?? ''));
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
$username = isset($body['username']) ? trim((string)$body['username']) : null;

[$ok, $result] = user_register($name, $email, $password, $username);
if (!$ok) api_err('register_failed', $result, 422);

$uid = (int)$result;
$db = getDB();
$st = $db->prepare("SELECT id, name, username, email, avatar_letter, bio, theme, role, plan, reading_streak, last_read_date, notify_breaking, notify_followed, notify_digest, created_at FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]);
$u = $st->fetch();

api_ok([
    'token' => jwt_issue($uid),
    'user'  => api_user_public($u),
]);
