<?php
/**
 * نيوزفلو - إعداد المصادقة الثنائية (2FA)
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/audit.php';
requireAdmin();

$db = getDB();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$success = '';
$error = '';

// Auto-migrate
try {
    $col = $db->query("SHOW COLUMNS FROM users LIKE 'totp_secret'")->fetch();
    if (!$col) {
        $db->exec("ALTER TABLE users
            ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL,
            ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Exception $e) {}

// Fetch current state
$stmt = $db->prepare("SELECT id, email, name, totp_secret, totp_enabled FROM users WHERE id = ?");
$stmt->execute([$adminId]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        $error = 'انتهت الجلسة، أعد المحاولة';
    } else {
        $op = $_POST['op'] ?? '';

        if ($op === 'start_enroll') {
            $_SESSION['2fa_new_secret'] = totp_generate_secret();
            header('Location: twofa.php');
            exit;
        }

        if ($op === 'confirm_enroll') {
            $secret = $_SESSION['2fa_new_secret'] ?? '';
            $code = trim($_POST['code'] ?? '');
            if ($secret && totp_verify($secret, $code)) {
                $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?")
                   ->execute([$secret, $adminId]);
                unset($_SESSION['2fa_new_secret']);
                audit_log('auth.2fa.enable', 'user', $adminId);
                $success = 'تم تفعيل المصادقة الثنائية بنجاح';
                // refresh user
                $stmt->execute([$adminId]);
                $user = $stmt->fetch();
            } else {
                $error = 'رمز التحقق غير صحيح، أعد المحاولة';
            }
        }

        if ($op === 'disable') {
            $password = $_POST['password'] ?? '';
            $u = $db->prepare("SELECT password FROM users WHERE id = ?");
            $u->execute([$adminId]);
            $hash = (string)$u->fetchColumn();
            if ($hash && password_verify($password, $hash)) {
                $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?")
                   ->execute([$adminId]);
                audit_log('auth.2fa.disable', 'user', $adminId);
                $success = 'تم تعطيل المصادقة الثنائية';
                $stmt->execute([$adminId]);
                $user = $stmt->fetch();
            } else {
                $error = 'كلمة المرور غير صحيحة';
            }
        }
    }
}

$pendingSecret = $_SESSION['2fa_new_secret'] ?? '';
$qrUri = '';
$qrImg = '';
if ($pendingSecret) {
    $qrUri = totp_provisioning_uri($pendingSecret, $user['email'] ?? 'admin', 'NewsFlow');
    $qrImg = totp_qr_image_url($qrUri, 200);
}

$pageTitle  = 'المصادقة الثنائية - نيوزفلو';
$activePage = 'settings';
include __DIR__ . '/includes/panel_layout_head.php';
?>

<div class="content">
    <div class="page-header">
        <div>
            <h2>المصادقة الثنائية (2FA)</h2>
            <p>حماية إضافية لحساب المسؤول</p>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

    <div class="form-card">
        <?php if (!empty($user['totp_enabled'])): ?>
            <h3 style="font-size:16px;margin-bottom:8px;color:var(--success);">✓ المصادقة الثنائية مفعّلة</h3>
            <p style="color:var(--text-muted);font-size:13px;margin-bottom:16px;">
                تحتاج الآن إلى إدخال رمز من تطبيق المصادقة عند كل تسجيل دخول.
            </p>
            <form method="POST" onsubmit="return confirm('هل أنت متأكد من تعطيل 2FA؟');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="op" value="disable">
                <div class="form-group">
                    <label>أدخل كلمة المرور لتعطيل 2FA</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn-danger">تعطيل المصادقة الثنائية</button>
            </form>

        <?php elseif ($pendingSecret): ?>
            <h3 style="font-size:16px;margin-bottom:12px;">خطوة 1: امسح رمز QR</h3>
            <p style="color:var(--text-muted);font-size:13px;margin-bottom:16px;">
                استخدم Google Authenticator / Authy / 1Password لمسح الرمز
            </p>
            <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;margin-bottom:20px;">
                <img src="<?php echo e($qrImg); ?>" alt="QR" style="border:1px solid var(--border);border-radius:12px;">
                <div>
                    <p style="font-size:12px;color:var(--text-muted);margin-bottom:6px;">أو أدخل المفتاح يدوياً:</p>
                    <code style="display:block;background:var(--bg-input);padding:10px 14px;border-radius:8px;font-size:14px;letter-spacing:2px;word-break:break-all;direction:ltr;">
                        <?php echo e($pendingSecret); ?>
                    </code>
                </div>
            </div>

            <h3 style="font-size:16px;margin-bottom:12px;">خطوة 2: أدخل الرمز لتأكيد الإعداد</h3>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="op" value="confirm_enroll">
                <div class="form-group">
                    <label>رمز التحقق (6 أرقام)</label>
                    <input type="text" name="code" class="form-control"
                           inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                           required autofocus
                           style="text-align:center;font-size:22px;letter-spacing:6px;">
                </div>
                <button type="submit" class="btn-primary">تأكيد التفعيل</button>
                <a href="twofa.php?cancel=1" class="btn-outline">إلغاء</a>
            </form>
            <?php
            if (isset($_GET['cancel'])) {
                unset($_SESSION['2fa_new_secret']);
                header('Location: twofa.php'); exit;
            }
            ?>

        <?php else: ?>
            <h3 style="font-size:16px;margin-bottom:8px;">المصادقة الثنائية غير مفعّلة</h3>
            <p style="color:var(--text-muted);font-size:13px;margin-bottom:16px;">
                تفعيل 2FA يضيف طبقة حماية إضافية — حتى لو سُرقت كلمة المرور، لن يستطيع أحد الدخول بدون الرمز من تطبيقك.
            </p>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="op" value="start_enroll">
                <button type="submit" class="btn-primary">تفعيل المصادقة الثنائية</button>
            </form>
        <?php endif; ?>
    </div>
</div>

</main>
</body>
</html>
