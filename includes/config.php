<?php
/**
 * نيوزفلو - إعدادات الموقع
 * ========================
 * قم بتعديل هذه القيم حسب إعدادات استضافة GoDaddy
 */

// ============================================
// إعدادات قاعدة البيانات - عدّلها حسب GoDaddy
// ============================================
define('DB_HOST', 'localhost');          // عادة localhost على GoDaddy
define('DB_NAME', 'newsfeed');
define('DB_USER', 'newsfeed');
define('DB_PASS', 'wEwJ9?Huzwas');
define('DB_CHARSET', 'utf8mb4');

// ============================================
// إعدادات عامة
// ============================================
define('SITE_URL', 'http://postfeed.emdatra.org');
define('SITE_NAME', 'نيوزفلو');
define('SITE_TAGLINE', 'مجمع المصادر الإخبارية');
define('TIMEZONE', 'Asia/Amman');

// ============================================
// إعدادات الأمان
// ============================================
define('ADMIN_SESSION_NAME', 'newsflow_admin');
define('SECRET_KEY', 'CHANGE_THIS_TO_RANDOM_STRING_2026');

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
            die('<div style="direction:rtl;text-align:center;padding:50px;font-family:Arial">
                <h2>خطأ في الاتصال بقاعدة البيانات</h2>
                <p>تأكد من إعدادات config.php</p>
                <p style="color:#999;font-size:12px">' . $e->getMessage() . '</p>
            </div>');
        }
    }
    return $pdo;
}
