<?php
/**
 * نيوزفلو - إعدادات الموقع
 * ========================
 * قم بتعديل هذه القيم حسب إعدادات استضافة GoDaddy
 */

// ============================================
// PSR-4 autoloader for NewsFlow\ namespace (no Composer needed in prod)
// ============================================
spl_autoload_register(function ($class) {
    $prefix = 'NewsFlow\\';
    $baseDir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($file)) require $file;
});

// ============================================
// تحميل متغيرات البيئة من .env (إن وجد)
// ============================================
(function() {
    $envFile = __DIR__ . '/../.env';
    if (!is_readable($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
})();

function env($key, $default = '') {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    return $v;
}

// ============================================
// إعدادات قاعدة البيانات
// ============================================
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'newsfeed'));
define('DB_USER', env('DB_USER', 'newsfeed'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// ============================================
// إعدادات عامة
// ============================================
define('SITE_URL', 'https://postfeed.emdatra.org');
define('SITE_NAME', 'نيوزفلو');
define('SITE_TAGLINE', 'مجمع المصادر الإخبارية');
define('TIMEZONE', 'Asia/Amman');

// ============================================
// إعدادات الأمان
// ============================================
define('ADMIN_SESSION_NAME', 'newsflow_admin');
// SECRET_KEY — prefer the .env value, then a file-based secret outside the
// webroot, then a one-time auto-generated fallback. Never ship a hardcoded
// default: anyone reading this source could forge CSRF/session tokens.
define('SECRET_KEY', (function () {
    $fromEnv = env('SECRET_KEY', '');
    if ($fromEnv !== '') return $fromEnv;

    $path = __DIR__ . '/../storage/.secret_key';
    if (is_readable($path)) {
        $k = trim((string)@file_get_contents($path));
        if ($k !== '') return $k;
    }

    // Auto-provision on first run so fresh installs don't break. The file
    // is written under /storage (blocked by .htaccess) with 0600 perms.
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $generated = bin2hex(random_bytes(32));
    if (@file_put_contents($path, $generated, LOCK_EX) !== false) {
        @chmod($path, 0600);
        return $generated;
    }

    // Last-resort per-request key — CSRF tokens won't survive across
    // requests, which is visible enough to prompt an ops fix.
    error_log('SECRET_KEY: could not read or write ' . $path . ' — set SECRET_KEY in .env');
    return bin2hex(random_bytes(32));
})());

// ============================================
// إعدادات رفع الملفات
// ============================================
define('UPLOAD_DIR', __DIR__ . '/../uploads/news/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);

// ============================================
// ضبط المنطقة الزمنية والترميز
// ============================================
date_default_timezone_set(TIMEZONE);
mb_internal_encoding('UTF-8');

// ============================================
// الاتصال بقاعدة البيانات (PDO)
// ============================================
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Don't leak the full PDO message — it can contain DSN fragments
            // (host, database, user) and, depending on the driver, the
            // password. A SQLSTATE code + generic label is enough to debug.
            error_log('DB connection failed: sqlstate=' . $e->getCode());
            http_response_code(500);
            die('<div style="direction:rtl;text-align:center;padding:50px;font-family:Arial"><h2>خطأ في الاتصال بقاعدة البيانات</h2></div>');
        }
    }
    return $pdo;
}
