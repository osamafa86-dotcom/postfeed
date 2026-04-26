<?php
/**
 * POST /api/v1/user/devices
 * Register a device for push notifications (FCM token / APNs token).
 *
 * Body: { token, platform: 'ios'|'android', app_version?, locale? }
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
api_rate_limit('user:devices', 30, 600);

$user = api_require_user();
$db = getDB();

$body = api_body();
$token = trim((string)($body['token'] ?? ''));
$platform = strtolower((string)($body['platform'] ?? ''));
$appVersion = (string)($body['app_version'] ?? '');
$locale = (string)($body['locale'] ?? 'ar');

if ($token === '' || strlen($token) > 4096) api_err('invalid_input', 'token غير صالح', 422);
if (!in_array($platform, ['ios', 'android', 'web'], true)) api_err('invalid_input', 'platform غير صالح', 422);

try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(512) NOT NULL,
        platform VARCHAR(20) NOT NULL,
        app_version VARCHAR(40) DEFAULT NULL,
        locale VARCHAR(10) DEFAULT 'ar',
        is_active TINYINT(1) DEFAULT 1,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_token (token),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$db->prepare("INSERT INTO user_devices (user_id, token, platform, app_version, locale)
              VALUES (?,?,?,?,?)
              ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                platform = VALUES(platform),
                app_version = VALUES(app_version),
                locale = VALUES(locale),
                is_active = 1,
                last_seen = NOW()")
   ->execute([(int)$user['id'], $token, $platform, $appVersion, $locale]);

api_ok(['registered' => true]);
