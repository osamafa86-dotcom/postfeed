<?php
/**
 * Newsletter double opt-in confirmation.
 *
 * URL: /newsletter/confirm/{64-hex} → newsletter_confirm.php?token=...
 * (rewritten in .htaccess). Sets confirmed=1 and confirmed_at on the
 * matching row, then renders a friendly thank-you page.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? ''));
$status = 'invalid';
$email  = '';

if (strlen($token) === 48) {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, email, confirmed FROM newsletter_subscribers WHERE confirm_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $email = (string)$row['email'];
            if ((int)$row['confirmed'] === 1) {
                $status = 'already';
            } else {
                $upd = $db->prepare("UPDATE newsletter_subscribers SET confirmed = 1, confirmed_at = NOW() WHERE id = ?");
                $upd->execute([(int)$row['id']]);
                $status = 'ok';
            }
        }
    } catch (Throwable $e) {
        error_log('newsletter_confirm: ' . $e->getMessage());
        $status = 'error';
    }
}

$siteName = e(getSetting('site_name', SITE_NAME));
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تأكيد الاشتراك — <?php echo $siteName; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
body { margin:0;font-family:'Tajawal',Tahoma,sans-serif;background:linear-gradient(135deg,#f1f5f9 0%,#e2e8f0 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;color:#0f172a; }
.card { background:#fff;max-width:520px;width:100%;padding:48px 36px;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.10);text-align:center; }
.icon { font-size:64px;margin-bottom:16px;line-height:1; }
h1 { font-size:24px;margin:0 0 12px;font-weight:800; }
p { color:#64748b;font-size:15px;line-height:1.7;margin:0 0 24px; }
.email { display:inline-block;background:#f1f5f9;padding:6px 14px;border-radius:6px;color:#1a5c5c;font-weight:700;direction:ltr; }
.btn { display:inline-block;background:#1a5c5c;color:#fff;padding:12px 32px;border-radius:8px;text-decoration:none;font-weight:700;transition:transform .15s; }
.btn:hover { transform:translateY(-2px); }
.error { color:#dc2626; }
</style>
</head>
<body>
<div class="card">
<?php if ($status === 'ok'): ?>
    <div class="icon">🎉</div>
    <h1>تم تأكيد اشتراكك!</h1>
    <p>أهلًا بك في النشرة اليومية. سنرسل إليك ملخّصًا بأهم الأخبار كل صباح على عنوان <span class="email"><?php echo e($email); ?></span>.</p>
    <a class="btn" href="<?php echo e(SITE_URL); ?>">العودة إلى الصفحة الرئيسية</a>
<?php elseif ($status === 'already'): ?>
    <div class="icon">✅</div>
    <h1>اشتراكك مفعّل سلفًا</h1>
    <p>عنوان <span class="email"><?php echo e($email); ?></span> مشترك بالفعل في النشرة اليومية. لا حاجة لإجراء آخر.</p>
    <a class="btn" href="<?php echo e(SITE_URL); ?>">العودة إلى الصفحة الرئيسية</a>
<?php elseif ($status === 'invalid'): ?>
    <div class="icon">⚠️</div>
    <h1 class="error">رابط غير صالح</h1>
    <p>هذا الرابط منتهي الصلاحية أو غير صحيح. يمكنك إعادة الاشتراك من أسفل الصفحة الرئيسية.</p>
    <a class="btn" href="<?php echo e(SITE_URL); ?>">إعادة الاشتراك</a>
<?php else: ?>
    <div class="icon">😔</div>
    <h1 class="error">حدث خطأ</h1>
    <p>تعذّر تأكيد اشتراكك مؤقتًا. الرجاء المحاولة بعد قليل.</p>
    <a class="btn" href="<?php echo e(SITE_URL); ?>">العودة إلى الصفحة الرئيسية</a>
<?php endif; ?>
</div>
</body>
</html>
