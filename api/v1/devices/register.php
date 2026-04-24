<?php
/**
 * POST   /api/v1/devices/register — register an APNs push token
 * DELETE /api/v1/devices/register — unregister the token (logout from notifications)
 *
 * Body: { push_token, platform: "ios", bundle_id, locale, app_version, os_version, device_model, is_sandbox }
 */

require_once __DIR__ . '/../_bootstrap.php';

api_method('POST', 'DELETE');
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'DELETE') {
    $token = (string)($_GET['push_token'] ?? '');
    if ($token === '') {
        $body = api_body();
        $token = (string)($body['push_token'] ?? '');
    }
    if ($token === '') api_error('invalid_input', 'push_token مطلوب');
    try {
        $db->prepare("DELETE FROM device_tokens WHERE push_token = ?")->execute([$token]);
        api_json(['ok' => true]);
    } catch (Throwable $e) {
        error_log('v1/devices delete: ' . $e->getMessage());
        api_error('server_error', '', 500);
    }
}

api_rate_limit('devices.register', 30, 60);

$body = api_body();
$pushToken = trim((string)($body['push_token'] ?? ''));
$platform = strtolower((string)($body['platform'] ?? 'ios'));
$bundleId = isset($body['bundle_id']) ? mb_substr((string)$body['bundle_id'], 0, 120) : null;
$locale = isset($body['locale']) ? mb_substr((string)$body['locale'], 0, 10) : 'ar';
$appVersion = isset($body['app_version']) ? mb_substr((string)$body['app_version'], 0, 32) : null;
$osVersion = isset($body['os_version']) ? mb_substr((string)$body['os_version'], 0, 32) : null;
$deviceModel = isset($body['device_model']) ? mb_substr((string)$body['device_model'], 0, 60) : null;
$isSandbox = !empty($body['is_sandbox']) ? 1 : 0;

if ($pushToken === '' || mb_strlen($pushToken) > 255) api_error('invalid_input', 'push_token');
if (!in_array($platform, ['ios','android'], true)) api_error('invalid_input', 'platform');

$auth = api_auth_lookup();
$uid = $auth ? (int)$auth['user_id'] : null;
$apiTokenId = $auth ? (int)$auth['token_id'] : null;

try {
    $stmt = $db->prepare("INSERT INTO device_tokens
        (user_id, api_token_id, platform, push_token, bundle_id, locale, app_version, os_version, device_model, is_sandbox)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          user_id = VALUES(user_id),
          api_token_id = VALUES(api_token_id),
          bundle_id = VALUES(bundle_id),
          locale = VALUES(locale),
          app_version = VALUES(app_version),
          os_version = VALUES(os_version),
          device_model = VALUES(device_model),
          is_sandbox = VALUES(is_sandbox),
          last_seen_at = NOW()");
    $stmt->execute([$uid, $apiTokenId, $platform, $pushToken, $bundleId, $locale, $appVersion, $osVersion, $deviceModel, $isSandbox]);
    api_json(['ok' => true, 'registered' => true]);
} catch (Throwable $e) {
    error_log('v1/devices/register: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
