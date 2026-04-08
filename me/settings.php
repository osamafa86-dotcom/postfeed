<?php
$pageTitle = 'الإعدادات';
$pageSlug  = 'settings';
require __DIR__ . '/_layout.php';

$userId = (int)$me['id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        $error = 'انتهت الجلسة';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            $db = getDB();
            if ($action === 'profile') {
                $name = trim($_POST['name'] ?? '');
                $bio = trim($_POST['bio'] ?? '');
                $theme = in_array($_POST['theme'] ?? '', ['light','dark','auto'], true) ? $_POST['theme'] : 'auto';
                if (mb_strlen($name) < 2) $error = 'الاسم قصير جداً';
                else {
                    $db->prepare("UPDATE users SET name = ?, bio = ?, theme = ? WHERE id = ?")
                       ->execute([$name, $bio, $theme, $userId]);
                    setcookie('nf_theme', $theme, time() + 86400 * 365, '/');
                    $success = 'تم الحفظ';
                }
            } elseif ($action === 'notifications') {
                $nb = !empty($_POST['notify_breaking']) ? 1 : 0;
                $nf = !empty($_POST['notify_followed']) ? 1 : 0;
                $nd = !empty($_POST['notify_digest']) ? 1 : 0;
                $db->prepare("UPDATE users SET notify_breaking = ?, notify_followed = ?, notify_digest = ? WHERE id = ?")
                   ->execute([$nb, $nf, $nd, $userId]);
                $success = 'تم حفظ تفضيلات الإشعارات';
            } elseif ($action === 'password') {
                $current = $_POST['current'] ?? '';
                $new = $_POST['new'] ?? '';
                $confirm = $_POST['confirm'] ?? '';
                $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $hash = $stmt->fetchColumn();
                if (!password_verify($current, $hash)) {
                    $error = 'كلمة المرور الحالية خاطئة';
                } elseif (strlen($new) < 8) {
                    $error = 'كلمة المرور الجديدة يجب 8 أحرف على الأقل';
                } elseif ($new !== $confirm) {
                    $error = 'كلمتا المرور غير متطابقتين';
                } else {
                    $db->prepare("UPDATE users SET password = ? WHERE id = ?")
                       ->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 11]), $userId]);
                    $success = 'تم تغيير كلمة المرور';
                }
            }
            // Refresh user data
            $me = current_user();
        } catch (Throwable $e) {
            $error = 'حدث خطأ في الحفظ';
        }
    }
}
?>
<div class="dash-topbar">
  <h1>⚙️ الإعدادات</h1>
</div>

<?php if ($success): ?><div class="panel-card" style="border-color:var(--accent-3); color:var(--accent-3);">✓ <?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="panel-card" style="border-color:var(--red); color:var(--red);">⚠ <?= e($error) ?></div><?php endif; ?>

<div class="panel-card">
  <div class="panel-head"><h2>👤 الملف الشخصي</h2></div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="profile">
    <label style="display:block; font-size:13px; color:var(--text-2); margin-bottom:14px;">
      الاسم
      <input type="text" name="name" value="<?= e($me['name']) ?>" required maxlength="200" style="width:100%; margin-top:6px; padding:10px 12px; background:var(--surface-2); color:var(--text); border:1px solid var(--border); border-radius:10px; font-family:inherit;">
    </label>
    <label style="display:block; font-size:13px; color:var(--text-2); margin-bottom:14px;">
      نبذة عنك
      <textarea name="bio" maxlength="500" style="width:100%; margin-top:6px; padding:10px 12px; background:var(--surface-2); color:var(--text); border:1px solid var(--border); border-radius:10px; font-family:inherit; resize:vertical; min-height:70px;"><?= e($me['bio'] ?? '') ?></textarea>
    </label>
    <label style="display:block; font-size:13px; color:var(--text-2); margin-bottom:14px;">
      الثيم
      <select name="theme" id="themeSelect" style="width:100%; margin-top:6px; padding:10px 12px; background:var(--surface-2); color:var(--text); border:1px solid var(--border); border-radius:10px; font-family:inherit;">
        <option value="auto" <?= ($me['theme'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>تلقائي (حسب نظامك)</option>
        <option value="light" <?= ($me['theme'] ?? '') === 'light' ? 'selected' : '' ?>>فاتح ☀️</option>
        <option value="dark" <?= ($me['theme'] ?? '') === 'dark' ? 'selected' : '' ?>>داكن 🌙</option>
      </select>
    </label>
    <button type="submit" class="btn primary">حفظ</button>
  </form>
</div>

<div class="panel-card">
  <div class="panel-head"><h2>🔔 الإشعارات</h2></div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="notifications">
    <label style="display:flex; gap:10px; align-items:flex-start; padding:10px 0; border-bottom:1px solid var(--border); cursor:pointer;">
      <input type="checkbox" name="notify_breaking" <?= !empty($me['notify_breaking']) ? 'checked' : '' ?>>
      <div>
        <div style="font-weight:700;">الأخبار العاجلة</div>
        <div style="font-size:12px; color:var(--muted);">احصل على إشعار عند نشر أي خبر عاجل</div>
      </div>
    </label>
    <label style="display:flex; gap:10px; align-items:flex-start; padding:10px 0; border-bottom:1px solid var(--border); cursor:pointer;">
      <input type="checkbox" name="notify_followed" <?= !empty($me['notify_followed']) ? 'checked' : '' ?>>
      <div>
        <div style="font-weight:700;">الأقسام والمصادر المتابعة</div>
        <div style="font-size:12px; color:var(--muted);">إشعارات عند إضافة خبر في متابعاتك</div>
      </div>
    </label>
    <label style="display:flex; gap:10px; align-items:flex-start; padding:10px 0; cursor:pointer;">
      <input type="checkbox" name="notify_digest" <?= !empty($me['notify_digest']) ? 'checked' : '' ?>>
      <div>
        <div style="font-weight:700;">ملخص يومي</div>
        <div style="font-size:12px; color:var(--muted);">ملخص أهم الأخبار مرة باليوم</div>
      </div>
    </label>
    <button type="submit" class="btn primary" style="margin-top:12px;">حفظ</button>
  </form>
</div>

<div class="panel-card">
  <div class="panel-head"><h2>🔐 تغيير كلمة المرور</h2></div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password">
    <label style="display:block; font-size:13px; color:var(--text-2); margin-bottom:14px;">
      كلمة المرور الحالية
      <input type="password" name="current" required style="width:100%; margin-top:6px; padding:10px 12px; background:var(--surface-2); color:var(--text); border:1px solid var(--border); border-radius:10px;">
    </label>
    <label style="display:block; font-size:13px; color:var(--text-2); margin-bottom:14px;">
      الجديدة
      <input type="password" name="new" required minlength="8" style="width:100%; margin-top:6px; padding:10px 12px; background:var(--surface-2); color:var(--text); border:1px solid var(--border); border-radius:10px;">
    </label>
    <label style="display:block; font-size:13px; color:var(--text-2); margin-bottom:14px;">
      تأكيد
      <input type="password" name="confirm" required minlength="8" style="width:100%; margin-top:6px; padding:10px 12px; background:var(--surface-2); color:var(--text); border:1px solid var(--border); border-radius:10px;">
    </label>
    <button type="submit" class="btn primary">تحديث كلمة المرور</button>
  </form>
</div>

<?php require __DIR__ . '/_layout_foot.php'; ?>
