<?php
/**
 * POST /api/v1/user/notification-preferences
 * GET  /api/v1/user/notification-preferences
 *
 * Per-channel push notification preferences. The mobile app keeps an
 * authoritative copy locally (SharedPreferences) and syncs to the
 * server so the same toggles follow the user across devices and so
 * cron_notifications knows which channels to address.
 *
 * Channels: breaking, daily, categories, sources, stories, trending,
 *           weekly, comments. Stored as 0/1 in a JSON blob per user.
 */
require_once __DIR__ . '/../_bootstrap.php';

api_method('GET', 'POST');
$user = api_require_user();
$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_notification_prefs (
        user_id INT NOT NULL PRIMARY KEY,
        prefs JSON NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('notif-prefs ensure_table: ' . $e->getMessage());
}

$validChannels = ['breaking','daily','categories','sources','stories','trending','weekly','comments'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $st = $db->prepare("SELECT prefs FROM user_notification_prefs WHERE user_id=?");
    $st->execute([(int)$user['id']]);
    $raw = (string)($st->fetchColumn() ?: '{}');
    $prefs = json_decode($raw, true);
    if (!is_array($prefs)) $prefs = [];
    // Fill missing channels with default ON.
    $out = [];
    foreach ($validChannels as $c) {
        $out[$c] = array_key_exists($c, $prefs) ? (bool)$prefs[$c] : true;
    }
    api_ok($out);
}

// POST — accept any subset of the valid channels; merge with existing
// so partial updates don't wipe other channels.
$body = api_body();
$incoming = [];
foreach ($validChannels as $c) {
    if (array_key_exists($c, $body)) {
        $incoming[$c] = (bool)$body[$c];
    }
}
if (empty($incoming)) {
    api_err('invalid_input', 'لا يوجد ما يُحدّث', 422);
}

$st = $db->prepare("SELECT prefs FROM user_notification_prefs WHERE user_id=?");
$st->execute([(int)$user['id']]);
$existing = json_decode((string)($st->fetchColumn() ?: '{}'), true);
if (!is_array($existing)) $existing = [];

$merged = array_merge($existing, $incoming);
$json = json_encode($merged, JSON_UNESCAPED_UNICODE);

$db->prepare("INSERT INTO user_notification_prefs (user_id, prefs) VALUES (?, ?)
              ON DUPLICATE KEY UPDATE prefs=VALUES(prefs)")
   ->execute([(int)$user['id'], $json]);

$out = [];
foreach ($validChannels as $c) {
    $out[$c] = array_key_exists($c, $merged) ? (bool)$merged[$c] : true;
}
api_ok($out);
