<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
/**
 * نيوزفلو - صفحة تسجيل الدخول للمسؤولين
 */

session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/audit.php';

// إذا كان المستخدم مسجل دخول بالفعل
if (isset($_SESSION[ADMIN_SESSION_NAME]) && $_SESSION[ADMIN_SESSION_NAME] === true) {
    header('Location: index.php');
    exit;
}

$error = '';

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
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'يرجى إدخال البريد الإلكتروني وكلمة المرور';
    } else {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, name, password FROM users WHERE email = ? AND role = 'admin'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION[ADMIN_SESSION_NAME] = true;
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_name'] = $user['name'];
                audit_log('auth.login.success', 'user', $user['id'], ['email' => $email]);
                header('Location: index.php');
                exit;
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
            <h1>نيوزفلو</h1>
            <p>لوحة التحكم</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo e($error); ?></div>
        <?php endif; ?>

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

        <div class="login-footer">
            نيوزفلو &copy; 2026
        </div>
    </div>
</body>
</html>
