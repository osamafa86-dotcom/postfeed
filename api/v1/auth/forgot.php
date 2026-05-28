<?php
/**
 * POST /api/v1/auth/forgot — { email }
 *
 * Generates a 6-digit reset code, stores it hashed with a 30-minute
 * expiry, and emails it to the user. Always returns 200 even if the
 * account doesn't exist, so this endpoint can't be used as an
 * account-enumeration oracle.
 */
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../includes/mailer.php';

api_method('POST');

$body  = api_body();
$email = trim((string)($body['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_err('invalid_input', 'بريد إلكتروني غير صالح', 422);
}

$db = getDB();

// Lazy-create the table so this works without a migration step.
try {
    $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_expires (user_id, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('password_resets table create failed: ' . $e->getMessage());
}

$st = $db->prepare("SELECT id, name FROM users WHERE email=? AND is_active=1 LIMIT 1");
$st->execute([$email]);
$user = $st->fetch();

if ($user) {
    // Burn any prior outstanding codes for this user so only the
    // latest one works.
    $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id=? AND used_at IS NULL")
       ->execute([(int)$user['id']]);

    $code     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);

    $db->prepare("INSERT INTO password_resets (user_id, code_hash, expires_at)
                  VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))")
       ->execute([(int)$user['id'], $codeHash]);

    $name = htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8');
    $subject = 'إعادة تعيين كلمة المرور — فيد نيوز';
    $html = '<!doctype html><html dir="rtl" lang="ar"><body style="font-family:-apple-system,Segoe UI,Tahoma,sans-serif;background:#f5f1e8;padding:24px;margin:0">'
          . '<div style="background:#fff;border-radius:12px;padding:28px;max-width:480px;margin:0 auto;box-shadow:0 2px 8px rgba(0,0,0,.04)">'
          . '<h2 style="color:#0d7c66;margin:0 0 16px">مرحباً ' . $name . '،</h2>'
          . '<p style="color:#333;line-height:1.7">طلبت إعادة تعيين كلمة المرور لحسابك في فيد نيوز. استخدم الرمز التالي داخل التطبيق:</p>'
          . '<div style="font-size:34px;letter-spacing:10px;font-weight:bold;color:#0d7c66;'
          . 'text-align:center;background:#f0fdf9;padding:18px;border-radius:10px;margin:18px 0">' . $code . '</div>'
          . '<p style="color:#666;font-size:13px;line-height:1.6">الرمز صالح لمدّة 30 دقيقة. إذا لم تطلب إعادة تعيين، تجاهل هذه الرسالة وحسابك آمن.</p>'
          . '</div></body></html>';
    $text = "مرحباً $name،\n\nرمز إعادة تعيين كلمة المرور: $code\n\nالرمز صالح لمدّة 30 دقيقة. إذا لم تطلبه، تجاهل هذه الرسالة.";

    $ok = mailer_send($email, $subject, $html, $text);
    if (!$ok) {
        error_log("[auth/forgot] mail send failed for {$email}: " . mailer_last_error());
    }
}

// Always 200, regardless of whether the account exists or the email
// actually went out — prevents enumeration.
api_ok(['sent' => true]);
