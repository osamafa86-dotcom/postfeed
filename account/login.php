<?php
require_once __DIR__ . '/../includes/user_auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';

user_session_start();
user_dashboard_migrate();

if (is_logged_in()) { header('Location: ../me/'); exit; }

$error = '';
$email = '';
$return = $_GET['return'] ?? '../me/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rate_limit_check('user_login:' . client_ip(), 10, 300)) {
        $error = 'محاولات كثيرة، حاول بعد 5 دقائق';
    } elseif (!csrf_verify($_POST['_csrf'] ?? '')) {
        $error = 'انتهت الجلسة، أعد المحاولة';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        [$ok, $err] = user_login_attempt($email, $password);
        if ($ok) {
            header('Location: ' . $return);
            exit;
        }
        $error = $err;
    }
}

$pageTitle = 'تسجيل الدخول';
include __DIR__ . '/_auth_shell.php';
?>
<div class="auth-card">
  <div class="auth-head">
    <div class="auth-logo">N</div>
    <h1>أهلاً بعودتك</h1>
    <p>سجّل الدخول لتكمل قراءة خلاصتك.</p>
  </div>
  <?php if ($error): ?><div class="auth-error"><?= e($error) ?></div><?php endif; ?>
  <form method="POST" autocomplete="on">
    <?= csrf_field() ?>
    <label>البريد الإلكتروني
      <input type="email" name="email" required value="<?= e($email) ?>" autofocus>
    </label>
    <label>كلمة المرور
      <input type="password" name="password" required>
    </label>
    <button type="submit" class="auth-btn">دخول</button>
  </form>
  <p class="auth-alt">ما عندك حساب؟ <a href="register.php">أنشئ حساب جديد</a></p>
  <p class="auth-alt"><a href="../index.php">← العودة للرئيسية</a></p>
</div>
<?php include __DIR__ . '/_auth_shell_footer.php'; ?>
