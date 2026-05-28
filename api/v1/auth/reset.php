<?php
/**
 * POST /api/v1/auth/reset — { email, code, password }
 *
 * Consumes a 6-digit code from /auth/forgot and sets a new password.
 * Codes are single-use; all outstanding codes for the user are burned
 * once any one is successfully consumed.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
// Rate limit: 20 reset attempts per 10 minutes per IP. Combined with
// the 30-minute code expiry + single-use enforcement, this keeps a
// brute force of the 6-digit code at hours-of-work per IP — and the
// attacker has to land it before the code rotates.
api_rate_limit('auth:reset', 20, 600);

$body     = api_body();
$email    = trim((string)($body['email'] ?? ''));
$code     = trim((string)($body['code'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_err('invalid_input', 'بريد إلكتروني غير صالح', 422);
}
if (!preg_match('/^\d{6}$/', $code)) {
    api_err('invalid_input', 'الرمز يجب أن يكون 6 أرقام', 422);
}
if (strlen($password) < 6) {
    api_err('invalid_input', 'كلمة المرور يجب أن تكون 6 أحرف فأكثر', 422);
}

$db = getDB();
$st = $db->prepare("SELECT id FROM users WHERE email=? AND is_active=1 LIMIT 1");
$st->execute([$email]);
$user = $st->fetch();
if (!$user) {
    api_err('invalid_code', 'الرمز غير صالح أو منتهي', 422);
}

// Code is stored hashed, so we have to verify against each unexpired
// candidate. Limit to a handful to keep the verify cost bounded.
$st = $db->prepare("SELECT id, code_hash FROM password_resets
                    WHERE user_id=? AND used_at IS NULL AND expires_at > NOW()
                    ORDER BY id DESC LIMIT 5");
$st->execute([(int)$user['id']]);
$rows = $st->fetchAll();

$matched = false;
foreach ($rows as $r) {
    if (password_verify($code, (string)$r['code_hash'])) {
        $matched = true;
        break;
    }
}
if (!$matched) {
    api_err('invalid_code', 'الرمز غير صالح أو منتهي', 422);
}

$db->beginTransaction();
try {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare("UPDATE users SET password=? WHERE id=?")
       ->execute([$newHash, (int)$user['id']]);
    // Burn every outstanding code so this one can't be replayed and
    // no older one stays valid.
    $db->prepare("UPDATE password_resets SET used_at = NOW()
                  WHERE user_id=? AND used_at IS NULL")
       ->execute([(int)$user['id']]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    error_log('[auth/reset] failed for user ' . (int)$user['id'] . ': ' . $e->getMessage());
    api_err('reset_failed', 'تعذّر إعادة تعيين كلمة المرور، حاول مرة أخرى', 500);
}

api_ok(['reset' => true]);
