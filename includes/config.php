<?php
/**
 * نيوزفلو - إعدادات الموقع
 * ========================
 * قم بتعديل هذه القيم حسب إعدادات استضافة GoDaddy
 */

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
define('SECRET_KEY', env('SECRET_KEY', '1ea153d2745ac7980bf961b78cdab5717c0ff88f5420b39ee8eca39ad20f2ebd'));

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
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('<div style="direction:rtl;text-align:center;padding:50px;font-family:Arial"><h2>خطأ في الاتصال بقاعدة البيانات</h2></div>');
        }
    }
    return $pdo;
}
