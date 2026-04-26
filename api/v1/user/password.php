<?php
/**
 * POST /api/v1/user/password
 * Body: { "current": "...", "new": "..." }
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
api_rate_limit('user:password', 10, 600);

$user = api_require_user();
$body = api_body();
$current = (string)($body['current'] ?? '');
$new     = (string)($body['new'] ?? '');

if (strlen($new) < 8) api_err('invalid_input', 'كلمة المرور الجديدة قصيرة', 422);

$db = getDB();
$st = $db->prepare("SELECT password FROM users WHERE id=? LIMIT 1");
$st->execute([(int)$user['id']]);
$row = $st->fetch();
if (!$row || !password_verify($current, (string)$row['password'])) {
    api_err('invalid_credentials', 'كلمة المرور الحالية غير صحيحة', 401);
}

$hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 11]);
$db->prepare("UPDATE users SET password=? WHERE id=?")->execute([$hash, (int)$user['id']]);

api_ok(['updated' => true]);
