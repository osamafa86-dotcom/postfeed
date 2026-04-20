<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * نيوز فيد - صفحة تسجيل الدخول للمسؤولين
 */

session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/totp.php';

// Auto-migrate 2FA columns
try {
    $db = getDB();
    $col = $db->query("SHOW COLUMNS FROM users LIKE 'totp_secret'")->fetch();
    if (!$col) {
        $db->exec("ALTER TABLE users
            ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL,
            ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Exception $e) {}

// إذا كان المستخدم مسجل دخول بالفعل
if (isset($_SESSION[ADMIN_SESSION_NAME]) && $_SESSION[ADMIN_SESSION_NAME] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
$stage = 'password'; // password | totp

// If we're in pending-2FA state from previous step
if (!empty($_SESSION['2fa_pending_user_id'])) {
    $stage = 'totp';
}

// معالجة تسجيل الدخول
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit: 5 login attempts per 5 minutes per IP
    if (!rate_limit_check('login:' . client_ip(), 5, 300)) {
        $error = 'محاولات كثيرة، يرجى المحاولة بعد 5 دقائق';
        goto skip_login;
    }
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        $error = 'انتهت الجلسة، أعد المحاولة';
        goto skip_login;
    }

    // Stage 2: verify TOTP
    if (!empty($_POST['totp_code']) && !empty($_SESSION['2fa_pending_user_id'])) {
        $stage = 'totp';
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, name, role, totp_secret FROM users WHERE id = ?");
            $stmt->execute([(int)$_SESSION['2fa_pending_user_id']]);
            $user = $stmt->fetch();
            if ($user && $user['totp_secret'] && totp_verify($user['totp_secret'], $_POST['totp_code'])) {
                $_SESSION[ADMIN_SESSION_NAME] = true;
                $_SESSION['admin_id']   = $user['id'];
                $_SESSION['admin_name'] = $user['name'];
                $_SESSION['admin_role'] = $user['role'] ?: 'viewer';
                unset($_SESSION['2fa_pending_user_id']);
                audit_log('auth.login.success', 'user', $user['id'], ['method' => '2fa']);
                header('Location: index.php');
                exit;
            } else {
                audit_log('auth.2fa.fail', 'user', $_SESSION['2fa_pending_user_id']);
                $error = 'رمز التحقق غير صحيح';
            }
        } catch (PDOException $e) {
            $error = 'حدث خطأ في النظام';
        }
        goto skip_login;
    }

    // Stage 1: email + password
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'يرجى إدخال البريد الإلكتروني وكلمة المرور';
    } else {
        try {
            $db = getDB();
            // Allow any active panel user (admin, editor, viewer) to
            // sign in. The role is then stored in the session and
            // enforced page-by-page via requireRole().
            $stmt = $db->prepare("SELECT id, name, password, role, totp_enabled, totp_secret
                                    FROM users
                                   WHERE email = ?
                                     AND role IN ('admin','editor','viewer')
                                     AND (is_active IS NULL OR is_active = 1)");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if (!empty($user['totp_enabled']) && !empty($user['totp_secret'])) {
                    // Require second factor
                    $_SESSION['2fa_pending_user_id'] = (int)$user['id'];
                    $stage = 'totp';
                    audit_log('auth.login.pw_ok', 'user', $user['id'], ['email' => $email]);
                } else {
                    $_SESSION[ADMIN_SESSION_NAME] = true;
                    $_SESSION['admin_id']   = $user['id'];
                    $_SESSION['admin_name'] = $user['name'];
                    $_SESSION['admin_role'] = $user['role'] ?: 'viewer';
                    audit_log('auth.login.success', 'user', $user['id'], ['email' => $email, 'role' => $user['role']]);
                    header('Location: index.php');
                    exit;
                }
            } else {
                audit_log('auth.login.fail', 'user', null, ['email' => $email]);
                $error = 'بيانات دخول غير صحيحة';
            }
        } catch (PDOException $e) {
            $error = 'حدث خطأ في النظام';
        }
    }
    skip_login:
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - لوحة التحكم</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-attachment: fixed;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #666;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
            font-family: inherit;
        }

        .form-group input:focus {
            outline: none;
            border-color: #5a85b0;
            box-shadow: 0 0 0 3px rgba(90, 133, 176, 0.1);
        }

        .error-message {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            color: #999;
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 20px;
                padding: 30px;
            }

            .login-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>نيوز فيد</h1>
            <p>لوحة التحكم</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($stage === 'totp'): ?>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <p style="text-align:center;color:#666;font-size:13px;margin-bottom:16px;">
                أدخل الرمز المكوّن من 6 أرقام من تطبيق المصادقة
            </p>
            <div class="form-group">
                <label for="totp_code">رمز التحقق</label>
                <input
                    type="text"
                    id="totp_code"
                    name="totp_code"
                    required
                    autofocus
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    autocomplete="one-time-code"
                    style="text-align:center;font-size:22px;letter-spacing:6px;"
                >
            </div>
            <button type="submit" class="btn-login">تأكيد</button>
            <div style="text-align:center;margin-top:12px;">
                <a href="login.php?cancel=1" style="font-size:12px;color:#888;">إلغاء</a>
            </div>
        </form>
        <?php
            if (isset($_GET['cancel'])) {
                unset($_SESSION['2fa_pending_user_id']);
                header('Location: login.php'); exit;
            }
        ?>
        <?php else: ?>
        <form method="POST">
                <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="email">البريد الإلكتروني</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    required
                    autofocus
                    value="<?php echo e($_POST['email'] ?? ''); ?>"
                >
            </div>

            <div class="form-group">
                <label for="password">كلمة المرور</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                >
            </div>

            <button type="submit" class="btn-login">تسجيل الدخول</button>
        </form>
        <?php endif; ?>

        <div class="login-footer">
            نيوز فيد &copy; 2026
        </div>
    </div>
</body>
</html>
