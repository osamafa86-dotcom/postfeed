<?php
/**
 * نيوزفلو - طبقة المصادقة للمستخدمين (قرّاء)
 * User authentication layer for regular readers (separate from admin session).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/user_migrate.php';

// Ensure migrations run on every request. The function short-circuits
// via a flag file, so it's effectively a single is_file() check after
// the first run.
user_dashboard_migrate();

if (!defined('USER_SESSION_KEY')) define('USER_SESSION_KEY', 'newsflow_user_id');

function user_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Only tune cookie params if the session hasn't been started yet.
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function current_user_id(): ?int {
    user_session_start();
    $id = $_SESSION[USER_SESSION_KEY] ?? null;
    return $id ? (int)$id : null;
}

function current_user(): ?array {
    static $cached = null;
    if ($cached !== null) return $cached ?: null;

    $id = current_user_id();
    if (!$id) { $cached = false; return null; }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, name, username, email, avatar_letter, bio, theme, role, reading_streak, last_read_date, notify_breaking, notify_followed, notify_digest FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        $cached = $u ?: false;
        return $u ?: null;
    } catch (Throwable $e) {
        $cached = false;
        return null;
    }
}

function is_logged_in(): bool {
    return current_user_id() !== null;
}

function require_user_login(string $redirect = null): void {
    if (!is_logged_in()) {
        $target = 'account/login.php';
        if ($redirect) $target .= '?return=' . urlencode($redirect);
        header('Location: ' . $target);
        exit;
    }
}

function user_login_by_id(int $userId): void {
    user_session_start();
    session_regenerate_id(true);
    $_SESSION[USER_SESSION_KEY] = $userId;
    try {
        $db = getDB();
        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$userId]);
    } catch (Throwable $e) {}
}

function user_logout(): void {
    user_session_start();
    unset($_SESSION[USER_SESSION_KEY]);
    // don't nuke the whole session — admin may be sharing it
    session_regenerate_id(true);
}

/**
 * Attempt to register a new reader.
 * Returns [bool $ok, string $errorOrUserId]
 */
function user_register(string $name, string $email, string $password, ?string $username = null): array {
    user_dashboard_migrate();
    $name = trim($name);
    $email = strtolower(trim($email));
    $username = $username !== null ? trim($username) : null;

    if (mb_strlen($name) < 2)  return [false, 'الاسم قصير جداً'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'البريد الإلكتروني غير صالح'];
    if (strlen($password) < 8) return [false, 'كلمة المرور يجب أن تكون 8 أحرف على الأقل'];
    if ($username !== null && $username !== '' && !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        return [false, 'اسم المستخدم يجب أن يتكون من 3 إلى 30 حرف (إنجليزي/أرقام/_)'];
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) return [false, 'هذا البريد مسجل بالفعل'];

        if ($username) {
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn()) return [false, 'اسم المستخدم محجوز'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 11]);
        $avatarLetter = mb_substr($name, 0, 1);
        $stmt = $db->prepare("INSERT INTO users (name, username, email, password, role, avatar_letter, plan, theme, created_at) VALUES (?, ?, ?, ?, 'reader', ?, 'free', 'auto', NOW())");
        $stmt->execute([$name, $username ?: null, $email, $hash, $avatarLetter]);
        $userId = (int)$db->lastInsertId();

        // Welcome notification
        try {
            $db->prepare("INSERT INTO user_notifications (user_id, type, title, body, icon) VALUES (?, 'welcome', ?, ?, '👋')")
               ->execute([$userId, 'مرحباً بك في نيوزفلو', 'اختر اهتماماتك من صفحة المتابعة لتحصل على خلاصة مخصصة.']);
        } catch (Throwable $e) {}

        return [true, (string)$userId];
    } catch (Throwable $e) {
        error_log('user_register: ' . $e->getMessage());
        return [false, 'حدث خطأ أثناء إنشاء الحساب'];
    }
}

/**
 * Attempt to log in by email+password.
 * Returns [bool $ok, string $errorOrEmpty]
 */
function user_login_attempt(string $email, string $password): array {
    user_dashboard_migrate();
    $email = strtolower(trim($email));
    if ($email === '' || $password === '') return [false, 'يرجى إدخال البريد وكلمة المرور'];
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, password, is_active FROM users WHERE email = ? AND role IN ('reader','viewer','editor','admin') LIMIT 1");
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($password, $u['password'])) {
            return [false, 'بيانات الدخول غير صحيحة'];
        }
        if (isset($u['is_active']) && (int)$u['is_active'] === 0) {
            return [false, 'الحساب معطّل، يرجى التواصل مع الإدارة'];
        }
        user_login_by_id((int)$u['id']);
        return [true, ''];
    } catch (Throwable $e) {
        error_log('user_login_attempt: ' . $e->getMessage());
        return [false, 'حدث خطأ في النظام'];
    }
}

/**
 * Resolve the theme the visitor should see.
 * - If logged in, use their preference.
 * - Otherwise fall back to a cookie they can set.
 * Returns 'light' | 'dark' | 'auto'.
 */
function current_theme(): string {
    $u = current_user();
    if ($u && !empty($u['theme'])) return $u['theme'];
    $c = $_COOKIE['nf_theme'] ?? 'auto';
    return in_array($c, ['light', 'dark', 'auto'], true) ? $c : 'auto';
}
