<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/user_auth.php';

api_method('POST');
api_rate_limit('auth:login', 30, 300);

$body = api_body();
$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');

if ($email === '' || $password === '') {
    api_err('invalid_input', 'يرجى إدخال البريد وكلمة المرور', 422);
}

// Lock after 5 failed attempts within 15 min. Key by email+IP so an
// attacker can't DoS a victim's account by hammering wrong passwords
// from a different IP — each (email, IP) pair has its own bucket.
$failKey = 'auth:login:fail:' . $email . ':' . (string)client_ip();
if (!rate_limit_peek($failKey, 5, 900)) {
    api_err('account_locked', 'تم تعليق محاولات الدخول مؤقتاً لحماية حسابك. حاول بعد 15 دقيقة.', 429);
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, password, is_active FROM users WHERE email=? AND role IN ('reader','viewer','editor','admin') LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password'])) {
        rate_limit_bump($failKey, 900);
        api_err('invalid_credentials', 'بيانات الدخول غير صحيحة', 401);
    }
    if (isset($row['is_active']) && (int)$row['is_active'] === 0) {
        api_err('account_disabled', 'الحساب معطّل، تواصل مع الإدارة', 403);
    }
    $uid = (int)$row['id'];

    try { $db->prepare("UPDATE users SET last_login = NOW() WHERE id=?")->execute([$uid]); } catch (Throwable $e) {}

    $st2 = $db->prepare("SELECT id, name, username, email, avatar_letter, bio, theme, role, plan, reading_streak, last_read_date, notify_breaking, notify_followed, notify_digest, created_at FROM users WHERE id=? LIMIT 1");
    $st2->execute([$uid]);
    $u = $st2->fetch();

    api_ok([
        'token' => jwt_issue($uid),
        'user'  => api_user_public($u),
    ]);
} catch (Throwable $e) {
    error_log('login: ' . $e->getMessage());
    api_err('server_error', 'حدث خطأ في النظام', 500);
}
