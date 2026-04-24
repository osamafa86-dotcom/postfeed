<?php
/**
 * POST /api/v1/user/follow
 * Body: { "kind": "category"|"source", "id": 123, "action": "toggle"|"follow"|"unfollow" }
 */

require_once __DIR__ . '/../_bootstrap.php';

api_method('POST');
$uid = api_require_user();
api_rate_limit('follow', 120, 60);

$body = api_body();
$kind = (string)($body['kind'] ?? '');
$id = (int)($body['id'] ?? 0);
$action = (string)($body['action'] ?? 'toggle');

if (!in_array($kind, ['category','source'], true)) api_error('invalid_input', 'kind');
if ($id <= 0) api_error('invalid_input', 'id');
if (!in_array($action, ['toggle','follow','unfollow'], true)) $action = 'toggle';

$table = $kind === 'category' ? 'user_category_follows' : 'user_source_follows';
$col = $kind === 'category' ? 'category_id' : 'source_id';

try {
    $db = getDB();
    $exists = $db->prepare("SELECT 1 FROM `$table` WHERE user_id = ? AND `$col` = ? LIMIT 1");
    $exists->execute([$uid, $id]);
    $has = (bool)$exists->fetchColumn();

    if ($action === 'unfollow' || ($action === 'toggle' && $has)) {
        $db->prepare("DELETE FROM `$table` WHERE user_id = ? AND `$col` = ?")->execute([$uid, $id]);
        api_json(['ok' => true, 'following' => false]);
    }
    $db->prepare("INSERT IGNORE INTO `$table` (user_id, `$col`) VALUES (?, ?)")->execute([$uid, $id]);
    api_json(['ok' => true, 'following' => true]);
} catch (Throwable $e) {
    error_log('v1/follow: ' . $e->getMessage());
    api_error('server_error', '', 500);
}
