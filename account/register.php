<?php
require_once __DIR__ . '/../includes/user_auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';

user_session_start();
user_dashboard_migrate();

if (is_logged_in()) { header('Location: ../me/'); exit; }

$error = '';
$name = $email = $username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit_check('register:' . client_ip(), 5, 600)) {
        $error = 'عدد محاولات كثيرة، حاول بعد قليل';
    } elseif (!csrf_verify($_POST['_csrf'] ?? '')) {
        $error = 'انتهت الجلسة، أعد المحاولة';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';
        if ($password !== $confirm) {
            $error = 'كلمتا المرور غير متطابقتين';
        } else {
            [$ok, $result] = user_register($name, $email, $password, $username ?: null);
            if ($ok) {
                user_login_by_id((int)$result);
                $return = $_GET['return'] ?? '../me/onboarding.php';
                header('Location: ' . $return);
                exit;
            } else {
                $error = $result;
            }
        }
    }
}

$pageTitle = 'إنشاء حساب';
include __DIR__ . '/_auth_shell.php';
?>
<div class="auth-card">
  <div class="auth-head">
    <div class="auth-logo">N</div>
    <h1>أنشئ حساب نيوزفلو</h1>
    <p>خلاصة أخبار مخصصة لاهتماماتك. مجاني بالكامل.</p>
  </div>
  <?php if ($error): ?><div class="auth-error"><?= e($error) ?></div><?php endif; ?>
  <form method="POST" autocomplete="on">
    <?= csrf_field() ?>
    <label>الاسم الكامل
      <input type="text" name="name" required maxlength="200" value="<?= e($name) ?>">
    </label>
    <label>اسم المستخدم <span class="hint">(اختياري)</span>
      <input type="text" name="username" pattern="[a-zA-Z0-9_]{3,30}" maxlength="30" value="<?= e($username) ?>" placeholder="ahmed_123">
    </label>
    <label>البريد الإلكتروني
      <input type="email" name="email" required maxlength="200" value="<?= e($email) ?>">
    </label>
    <label>كلمة المرور <span class="hint">(8 أحرف على الأقل)</span>
      <input type="password" name="password" required minlength="8">
    </label>
    <label>تأكيد كلمة المرور
      <input type="password" name="confirm" required minlength="8">
    </label>
    <button type="submit" class="auth-btn">إنشاء الحساب</button>
  </form>
  <p class="auth-alt">لديك حساب؟ <a href="login.php">سجّل الدخول</a></p>
  <p class="auth-alt"><a href="../index.php">← العودة للرئيسية</a></p>
</div>
<?php include __DIR__ . '/_auth_shell_footer.php'; ?>
